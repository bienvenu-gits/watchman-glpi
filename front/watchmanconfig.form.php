<?php

use GlpiPlugin\Watchman\WatchmanConfig;
use GlpiPlugin\Watchman\WatchmanProfile;
// use Html;

include ('../../../inc/includes.php');

// Vérifier les droits de configuration
WatchmanProfile::checkRightOr403(WatchmanProfile::RIGHT_WATCHMAN_CONFIG, WatchmanProfile::UPDATE);

$config = new WatchmanConfig();
if (isset($_POST["update"])) {
    $config->saveConfig($_POST);
    \Html::back();

} else {
    // fill id, if missing
    isset($_GET['id'])
        ? $ID = intval($_GET['id'])
        : $ID = 0;
    
    if(isset($_GET['client_id']) && isset($_GET['client_secret']) && Session::validateCSRF($_GET)) {
        $inputs=[
            'public_key'     => $_GET['client_id'],
            'secret_key' => $_GET['client_secret']
        ];
        $config->saveConfig($inputs);
    }


    // display form
    Html::header(
       WatchmanConfig::getTypeName(),
       $_SERVER['PHP_SELF'],
       "plugins",
       WatchmanConfig::class,
       "config"
    );
    $config->showConfigForm();
    Html::footer();
}