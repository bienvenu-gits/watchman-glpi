<?php

use GlpiPlugin\Watchman\WatchmanManager;
use GlpiPlugin\Watchman\ComputerManager;

include ('../../../inc/includes.php');

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