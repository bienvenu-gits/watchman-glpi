<?php

/**
 * -------------------------------------------------------------------------
 * watchman plugin for GLPI
 * Copyright (C) 2025 by the watchman Development Team.
 * -------------------------------------------------------------------------
 *
 * MIT License
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * --------------------------------------------------------------------------
 */

use GlpiPlugin\Watchman\AlertManager;
use GlpiPlugin\Watchman\CronManager;
use GlpiPlugin\Watchman\CronMigration;
use GlpiPlugin\Watchman\CronSyncAlert;
use GlpiPlugin\Watchman\CronSyncComputer;
use GlpiPlugin\Watchman\WatchmanConfig;
use GlpiPlugin\Watchman\WatchmanProfile;

/**
 * Plugin install process
 *
 * @return boolean
 */
function plugin_watchman_install()
{
   global $DB;

   // Instancier la migration avec une version (ex. : 100)
   $migration = new Migration(100);
   $version = false;
   //migrations here 
   WatchmanConfig::install($migration, $version);
   CronMigration::install($migration, $version);
   AlertManager::install($migration, $version);

   // Exécuter la migration
   $migration->executeMigration();

   //taches cron
   CronSyncAlert::installCronTasks();
   CronSyncComputer::installCronTasks();

   // Installation des droits du plugin
   WatchmanProfile::installRights();

   return true;
}


/**
 * Plugin uninstall process
 *
 * @return boolean
 */
function plugin_watchman_uninstall()
{
   global $DB;

   $tables = [
      'watchmanconfigs',
      "alert_logs",
      'sync_logs',
      'error_logs',
      'metrics',
      "computer_mappings",
      "cpes",
      'alerts',
      "cve_impacts",
      "cve_references",
      "cves",
      "stacks",
   ];

   foreach ($tables as $table) {
      $tablename = 'glpi_plugin_watchman_' . $table;
      //Create table only if it does not exists yet!
      if ($DB->tableExists($tablename)) {
         $DB->queryOrDie(
            "DROP TABLE `$tablename`",
            $DB->error()
         );
      }
   }
   CronSyncAlert::uninstallCronTasks();
   CronSyncComputer::uninstallCronTasks();
   CronManager::uninstallCronTasks();
   
   // Désinstallation des droits du plugin
   // WatchmanProfile::uninstallRights();
   
   return true;
}


function watchman_display_login()
{
   // echo "That line will appear on the login page!";
}

function plugin_watchman_item_update($item) {
    // Vérifier si c'est un ticket
    if ($item instanceof Ticket) {
        // Récupérer l'ancien statut depuis la base de données
        $old_ticket = new Ticket();
        $old_ticket->getFromDB($item->getID());
        
        $old_status = $old_ticket->fields['status'];
        $new_status = $item->input['status'] ?? $item->fields['status'];
        
        // Vérifier si le ticket vient d'être résolu
        if ($old_status != CommonITILObject::SOLVED && 
            $new_status == CommonITILObject::SOLVED) {
            
            // Votre action ici
            AlertManager::handleMarkAsPatched($item);
        }
    }
}

function plugin_watchman_ticket_resolu($ticket) {
    // Vos actions quand le ticket est résolu
    // Par exemple :
    error_log("Ticket " . $ticket->getID() . " résolu !");
    
    // Envoyer un email
    // Créer une tâche
    // Mettre à jour d'autres objets
    // etc.
}

function plugin_watchman_add_css() {
    global $CFG_GLPI;
    
    // Pour une page spécifique
    if (strpos($_SERVER['REQUEST_URI'], 'plugins/watchman') !== false) {
      echo '<link rel="stylesheet" type="text/css" href="' . 
             $CFG_GLPI["root_doc"] . '/plugins/watchman/assets/css/icons.css">';
        echo '<link rel="stylesheet" type="text/css" href="' . 
             $CFG_GLPI["root_doc"] . '/plugins/watchman/assets/css/cms_watchman.css">';
    }
}

