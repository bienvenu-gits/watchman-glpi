<?php

use GlpiPlugin\Watchman\WatchmanManager;
use GlpiPlugin\Watchman\WatchmanProfile;

include('../../../inc/includes.php');

WatchmanProfile::checkRightOr403(WatchmanProfile::RIGHT_WATCHMAN_ADMIN, WatchmanProfile::READ);

$manager = new WatchmanManager();

Html::header(
    __('Logs Watchman', 'watchman'),
    $_SERVER['PHP_SELF'],
    "plugins",
    WatchmanManager::class,
    "watchmanmanager"
);

$manager->showLogs();

Html::footer();
