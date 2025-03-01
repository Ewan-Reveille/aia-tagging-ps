<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class AiaTagging extends Module
{
    protected $config_form = false;

    public function __construct()
    {
        $this->name = 'aiatagging';
        $this->tab = 'seo';
        $this->version = '1.0.0';
        $this->author = 'AIA HANDICAP';
        $this->need_instance = 1;
        $this->ps_versions_compliancy = ['min' => '1.7', 'max' => '8.99.99'];

        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('AIA Tagging PrestaShop');
        $this->description = $this->l('Sends images to an external API for captioning and updates image alt tags.');

        $this->confirmUninstall = $this->l('Are you sure you want to uninstall this extension? In order to stop your subscription, you will have to cancel it on https://www.aia-handicap.com');
    }
    public function install()
    {
        Configuration::updateValue('AIATAGGING_LIVE_MODE', false);
        $sql = "CREATE TABLE IF NOT EXISTS `" . _DB_PREFIX_ . "aiatagging_queue` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `image_id` INT(11) NOT NULL,
            `base_path` VARCHAR(255) NOT NULL,
            `formats` TEXT NOT NULL,
            `api_key` VARCHAR(255) NOT NULL,
            `language` VARCHAR(10) NOT NULL,
            `scheduled_at` DATETIME NOT NULL,
            `status` VARCHAR(50) NOT NULL,
            `error` TEXT DEFAULT NULL,
            `retries` INT(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`)
        ) ENGINE=" . _MYSQL_ENGINE_ . " DEFAULT CHARSET=utf8;";
        Db::getInstance()->execute($sql);
        $columnExists = Db::getInstance()->getValue(
            "SELECT COUNT(*) 
            FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = '" . _DB_PREFIX_ . "aiatagging_queue' 
            AND COLUMN_NAME = 'retries'"
        );
        
        if (!$columnExists) {
            Db::getInstance()->execute(
                "ALTER TABLE `" . _DB_PREFIX_ . "aiatagging_queue` 
                ADD COLUMN `retries` INT(11) NOT NULL DEFAULT 0 AFTER `status`"
            );
        }
        Configuration::updateValue('AIATAGGING_LAST_CRON_RUN', date('Y-m-d H:i:s'));    
        return parent::install() &&
            $this->registerHook('header') &&
            $this->registerHook('displayBackOfficeHeader') &&
            $this->registerHook('actionObjectImageAddAfter') &&
            $this->registerHook('actionDispatcher');
    }

    public function uninstall()
    {
        Configuration::deleteByName('AIATAGGING_LIVE_MODE');

        return parent::uninstall();
    }

    public function getContent()
    {
        if (((bool)Tools::isSubmit('submitAIATAGGINGModule')) == true) {
            $this->postProcess();
        }

        $this->context->smarty->assign('module_dir', $this->_path);

        $output = $this->context->smarty->fetch($this->local_path.'views/templates/admin/configure.tpl');

        return $output.$this->renderForm();
    }

    protected function renderForm()
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitAIATAGGINGModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            .'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormValues(), /* Add values for your inputs */
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($this->getConfigForm()));
    }

    protected function getConfigForm()
    {
        return array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Settings'),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'select',
                        'label' => $this->l('Favorite Language'),
                        'name' => 'AIATAGGING_FAVORITE_LANGUAGE',
                        'options' => array(
                            'query' => array(
                                array('id' => 'en', 'name' => 'English'),
                                array('id' => 'fr', 'name' => 'French'),
                                array('id' => 'es', 'name' => 'Spanish'),
                                array('id' => 'it', 'name' => 'Italian'),
                            ),
                            'id' => 'id',
                            'name' => 'name'
                        ),
                    ),
                    // New field for API Key
                    array(
                        'type' => 'text',
                        'name' => 'AIATAGGING_API_KEY',
                        'label' => $this->l('API Key'),
                        'desc' => $this->l('Enter your API key for the image analysis service'),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    protected function getConfigFormValues()
    {
        return array(
            'AIATAGGING_LIVE_MODE'         => Configuration::get('AIATAGGING_LIVE_MODE', true),
            'AIATAGGING_ACCOUNT_EMAIL'     => Configuration::get('AIATAGGING_ACCOUNT_EMAIL', 'contact@prestashop.com'),
            'AIATAGGING_ACCOUNT_PASSWORD'  => Configuration::get('AIATAGGING_ACCOUNT_PASSWORD', null),
            'AIATAGGING_FAVORITE_LANGUAGE' => Configuration::get('AIATAGGING_FAVORITE_LANGUAGE', 'en'), // Default to English
            'AIATAGGING_API_KEY'           => Configuration::get('AIATAGGING_API_KEY', ''), // Default to an empty string
        );
    }

    protected function postProcess()
    {
        $form_values = $this->getConfigFormValues();

        foreach (array_keys($form_values) as $key) {
            Configuration::updateValue($key, Tools::getValue($key));
        }
    }
    public function hookDisplayBackOfficeHeader()
    {
        if (Tools::getValue('configure') == $this->name) {
            $this->context->controller->addJS($this->_path.'views/js/back.js');
            $this->context->controller->addCSS($this->_path.'views/css/back.css');
        }
    }

    public function hookHeader()
    {
        $this->context->controller->addJS($this->_path.'/views/js/front.js');
        $this->context->controller->addCSS($this->_path.'/views/css/front.css');
    }

    public function hookActionDispatcher($params)
    {
        $delay = 30;
        $lastRun = Configuration::get('AIATAGGING_LAST_CRON_RUN');
        $now = time();
        
        if (!$lastRun || ($now - strtotime($lastRun)) >= $delay) {
            Configuration::updateValue('AIATAGGING_LAST_CRON_RUN', date('Y-m-d H:i:s'));

            $this->processQueue();
        }
    }

    public function processQueue()
    {
        // In processQueue()
        $sql = "SELECT * FROM `" . _DB_PREFIX_ . "aiatagging_queue` 
        WHERE `status` IN ('pending', 'error') 
        AND `retries` < 3 
        AND `scheduled_at` <= NOW()";
        $tasks = Db::getInstance()->executeS($sql);

        if ($tasks) {
            foreach ($tasks as $task) {
                $imageId  = (int)$task['image_id'];
                $basePath = $task['base_path'];
                $formats  = json_decode($task['formats'], true);
                $apiKey   = $task['api_key'];
                $language = $task['language'];

                $imagePath = $basePath;
                foreach ($formats as $format) {
                    $testPath = $basePath . '.' . $format;
                    if (file_exists($testPath)) {
                        $imagePath = $testPath;
                        break;
                    }
                }

                if (!file_exists($imagePath)) {
                    $errorMsg = "Fichier introuvable pour l'image $imageId";
                    Db::getInstance()->execute("UPDATE `" . _DB_PREFIX_ . "aiatagging_queue` 
                        SET `status` = 'error', `error` = '" . pSQL($errorMsg) . "' WHERE `id` = " . (int)$task['id']);
                    PrestaShopLogger::addLog($errorMsg, 3);
                    continue;
                }

                $apiUrl = 'https://choice-amazing-cricket.ngrok-free.app/analyze-image/' . $apiKey . '/?language=' . $language . '&caption_only=1';
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $apiUrl);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, [
                    'file' => new CURLFile($imagePath, 'image/jpeg', 'image.jpg')
                ]);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Accept: application/json',
                    'X-Client: PrestaShop-Module'
                ]);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 120);
                $response = curl_exec($ch);
                $curlError = curl_error($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($curlError || $httpCode !== 200 || empty($response)) {
                    $errorMsg = $curlError ? $curlError : "HTTP Code $httpCode";
                    Db::getInstance()->execute("UPDATE `" . _DB_PREFIX_ . "aiatagging_queue` 
                        SET `status` = 'error', `error` = '" . pSQL($errorMsg) . "' WHERE `id` = " . (int)$task['id']);
                    PrestaShopLogger::addLog("Erreur lors du traitement de l'image $imageId : " . $errorMsg, 3);
                    continue;
                }
                $responseData = json_decode($response, true);
                if (!$responseData || !isset($responseData['caption'])) {
                    $errorMsg = "Invalid API response format";
                    // Log error and update queue status
                    continue;
                }
                $caption = trim($responseData['caption']);
                $image = new Image($imageId);
                if (Validate::isLoadedObject($image)) {
                    $image->legend = array();
                    foreach (Language::getLanguages(false) as $lang) {
                        $image->legend[$lang['id_lang']] = $caption;
                    }
                    $image->legend[Configuration::get('PS_LANG_DEFAULT')] = $caption;
                    if ($image->update()) {
                        Db::getInstance()->execute("UPDATE `" . _DB_PREFIX_ . "aiatagging_queue` 
                            SET `status` = 'processed' WHERE `id` = " . (int)$task['id']);
                        PrestaShopLogger::addLog("Traitement de l'image $imageId réussi", 1);
                    } else {
                        $errorMsg = "Échec de mise à jour de l'image $imageId";
                        Db::getInstance()->execute("UPDATE `" . _DB_PREFIX_ . "aiatagging_queue` 
                            SET `status` = 'error', `error` = '" . pSQL($errorMsg) . "' WHERE `id` = " . (int)$task['id']);
                        PrestaShopLogger::addLog($errorMsg, 3);
                    }
                }
            }
        }
    }

    public function hookActionObjectImageAddAfter($params)
    {
        error_log('[AIA] Hook triggered at ' . date('Y-m-d H:i:s'));
        PrestaShopLogger::addLog('[AIA] processImage triggered', 1);
        PrestaShopLogger::addLog('API Key: ' . Configuration::get('AIATAGGING_API_KEY'), 1);
        PrestaShopLogger::addLog('Language: ' . Configuration::get('AIATAGGING_FAVORITE_LANGUAGE'), 1);
        PrestaShopLogger::addLog('_PS_PROD_IMG_DIR_: ' . _PS_PROD_IMG_DIR_, 1);

        if (!isset($params['object']) || !($params['object'] instanceof Image)) {
            PrestaShopLogger::addLog('Invalid image object', 3);
            PrestaShopLogger::addLog(json_encode($params['object'], 3));
            return;
        }

        $image = $params['object'];
        $imageId = $image->id;
        PrestaShopLogger::addLog('ImgPath: ' . $image->getImgPath(), 1);
        PrestaShopLogger::addLog("Image ID: $imageId", 1);

        $basePath = _PS_PROD_IMG_DIR_ . $image->getImgPath();
        $formats = json_encode(['jpg', 'jpeg', 'png', 'gif', 'webp']);
        $apiKey = Configuration::get('AIATAGGING_API_KEY');
        $language = Configuration::get('AIATAGGING_FAVORITE_LANGUAGE');

        $scheduled_at = date('Y-m-d H:i:s', time() + 600);

        $sql = "INSERT INTO `" . _DB_PREFIX_ . "aiatagging_queue` 
                (`image_id`, `base_path`, `formats`, `api_key`, `language`, `scheduled_at`, `status`)
                VALUES ('" . (int)$imageId . "', '" . pSQL($basePath) . "', '" . pSQL($formats) . "', '" . pSQL($apiKey) . "', '" . pSQL($language) . "', '" . pSQL($scheduled_at) . "', 'pending')";
        
        if (Db::getInstance()->execute($sql)) {
            PrestaShopLogger::addLog("Tâche de traitement pour l'image $imageId insérée pour exécution à $scheduled_at", 1);
        } else {
            PrestaShopLogger::addLog("Erreur lors de l'insertion de la tâche de traitement pour l'image $imageId", 3);
        }
    }
}
