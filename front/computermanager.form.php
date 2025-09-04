<?php

use GlpiPlugin\Watchman\ComputerManager;

include ('../../../inc/includes.php');

$computer_manager = new ComputerManager();
if (isset($_POST["cron_start"])) {
    $computer_manager->startCron();
    // \Html::back();

} else {
    // fill id, if missing
    isset($_GET['id'])
        ? $ID = intval($_GET['id'])
        : $ID = 0;

    // display form
    Html::header(
       ComputerManager::getTypeName(),
       $_SERVER['PHP_SELF'],
       "plugins",
       ComputerManager::class,
       "Machines"
    );
    $computer_manager->showCronStartForm();
    Html::footer();
}