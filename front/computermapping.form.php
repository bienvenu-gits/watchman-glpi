<?php

use GlpiPlugin\Watchman\ComputerManager;

include ('../../../inc/includes.php');

$computer_manager = new ComputerManager();

// Récupérer l'ID si présent
isset($_GET['id']) ? $ID = intval($_GET['id']) : $ID = 0;

// Affichage de l'en-tête
Html::header(
   'Détail ordinateur synchronisé',
   $_SERVER['PHP_SELF'],
   "plugins",
   ComputerManager::class,
   "ComputerMapping"
);

// Affichage du formulaire de détail
$computer_manager->showComputerMappingDetail($ID);

Html::footer();