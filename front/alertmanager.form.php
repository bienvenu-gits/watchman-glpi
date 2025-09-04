<?php

use GlpiPlugin\Watchman\AlertManager;

include ('../../../inc/includes.php');

$alert_manager = new AlertManager();
if (isset($_POST["cron_start"])) {
    $alert_manager->startCron();
    // \Html::back();

} else {
    // fill id, if missing
    isset($_GET['id'])
        ? $ID = intval($_GET['id'])
        : $ID = 0;

    // display form
    Html::header(
       AlertManager::getTypeName(),
       $_SERVER['PHP_SELF'],
       "plugins",
       AlertManager::class,
       "Machines"
    );
    $alert_manager->startCron();
    Html::footer();
}