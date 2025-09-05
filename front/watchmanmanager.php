<?php

use GlpiPlugin\Watchman\WatchmanConfig;
use GlpiPlugin\Watchman\WatchmanManager;
// use Search;
// use Html;

include ('../../../inc/includes.php');
$dashboard_manager = new WatchmanManager();
global $CFG_GLPI;
Html::header(
    WatchmanManager::getTypeName(),
    $_SERVER['PHP_SELF'],
    "plugins",
    WatchmanManager::class,
    "watchmanmanager"
);
$config_start=WatchmanConfig::getConfigValue('show_welcome', null);

if ($config_start==null && isset($_GET['start']) && Session::validateCSRF(['_glpi_csrf_token'=>$_GET['start']])) {
    WatchmanConfig::saveConfig(['show_welcome'=> 1]);
    $config_start=1;
}
if($config_start==1){
    $secret_key=WatchmanConfig::getConfigValue('secret_key', null);
    $public_key=WatchmanConfig::getConfigValue('public_key', null);
    if($secret_key==null || $public_key==null){
        $url="watchmanconfig.form.php";
    }else{
        $url="alertmanager.php";
    }
    $url=$CFG_GLPI["root_doc"]."/plugins/watchman/front/".$url;
    Html::redirect($url);
}

$alert_id=$_GET['alert_id'] ?? null;
$dashboard_manager->showDashboard();
Html::footer();