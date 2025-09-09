<?php

use GlpiPlugin\Watchman\WatchmanManager;
use GlpiPlugin\Watchman\AlertManager;
use GlpiPlugin\Watchman\WatchmanProfile;
// use Search;
// use Html;

include ('../../../inc/includes.php');

// Vérifier l'accès aux alertes (lecture minimum)
WatchmanProfile::checkRightOr403(WatchmanProfile::RIGHT_WATCHMAN_VIEW, WatchmanProfile::READ);
$dashboard_manager = new WatchmanManager();
Html::header(
    WatchmanManager::getTypeName(),
    $_SERVER['PHP_SELF'],
    "plugins",
    WatchmanManager::class,
    "watchmanmanager"
);
// GlpiPlugin\Watchman\AlertManager::startCron();
$alert_id=$_GET['alert_id'] ?? null;
$dashboard_manager->showAlerts($alert_id);
Html::footer();