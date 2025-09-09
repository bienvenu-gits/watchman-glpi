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