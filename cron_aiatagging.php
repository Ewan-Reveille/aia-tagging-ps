<?php
// cron_aiatagging.php
include(dirname(__FILE__).'/../../config/config.inc.php');
include(dirname(__FILE__).'/../../init.php');

$sql = "SELECT * FROM `" . _DB_PREFIX_ . "aiatagging_queue` 
        WHERE `status` = 'pending' AND `scheduled_at` <= NOW()";
$tasks = Db::getInstance()->executeS($sql);

if ($tasks) {
    foreach ($tasks as $task) {
        $imageId  = (int)$task['image_id'];
        $basePath = $task['base_path'];
        $formats  = json_decode($task['formats'], true);
        $apiKey   = $task['api_key'];
        $language = $task['language'];

        // Construction du chemin complet avec extension
        $imagePath = $basePath;
        foreach ($formats as $format) {
            $testPath = $basePath . '.' . $format;
            if (file_exists($testPath)) {
                $imagePath = $testPath;
                break;
            }
        }

        // Si le fichier n'existe pas, on met à jour le statut de la tâche avec une erreur
        if (!file_exists($imagePath)) {
            $errorMsg = "Fichier introuvable pour l'image $imageId";
            Db::getInstance()->execute("UPDATE `" . _DB_PREFIX_ . "aiatagging_queue` 
                SET `status` = 'error', `error` = '" . pSQL($errorMsg) . "' WHERE `id` = " . (int)$task['id']);
            PrestaShopLogger::addLog($errorMsg, 3);
            continue;
        }

        // Préparation de la requête vers l'API
        $apiUrl = 'https://choice-amazing-cricket.ngrok-free.app/analyze-image/' . $apiKey . '/?language=' . $language;
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
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
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
        
        // Traitement de la réponse de l'API (par exemple, récupérer la légende)
        $caption = trim($response);

        // Mettre à jour l'image (mettre à jour la légende)
        $image = new Image($imageId);
        if (Validate::isLoadedObject($image)) {
            foreach (Language::getLanguages(false) as $lang) {
                if (!isset($image->legend[$lang['id_lang']])) {
                    $image->legend[$lang['id_lang']] = '';
                }
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
