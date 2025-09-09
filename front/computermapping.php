<?php

use GlpiPlugin\Watchman\WatchmanManager;
use GlpiPlugin\Watchman\ComputerManager;
use GlpiPlugin\Watchman\WatchmanProfile;

include ('../../../inc/includes.php');

// Vérifier les droits d'administration pour les ordinateurs
WatchmanProfile::checkRightOr403(WatchmanProfile::RIGHT_WATCHMAN_ADMIN, WatchmanProfile::READ);

$dashboard_manager = new WatchmanManager();
Html::header(
    'Ordinateurs synchronisés',
    $_SERVER['PHP_SELF'],
    "plugins",
    ComputerManager::class,
    "computermapping"
);

$computer_id = $_GET['computer_id'] ?? null;
$dashboard_manager->showComputerMappings($computer_id);

Html::footer();