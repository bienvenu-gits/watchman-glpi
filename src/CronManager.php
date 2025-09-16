<?php

namespace GlpiPlugin\Watchman;

use CommonDBTM;
use CronTask;
use Session;
use Toolbox;
use Computer;
use Exception;
use Software;

/**
 * Classe principale pour la gestion des tâches CRON de synchronisation
 * 
 * Cette classe orchestre toutes les synchronisations du plugin
 * et peut être appelée par d'autres classes ou manuellement
 */
class CronManager extends CommonDBTM {
    
    // Configuration des tâches
    const DEFAULT_BATCH_SIZE = 50;
    const DEFAULT_MAX_RETRIES = 3;
    const SYNC_COMPUTERS_INTERVAL = 15; // minutes
    const SYNC_ALERTS_INTERVAL = 5; // minutes
    const HEALTH_CHECK_INTERVAL = 10; // minutes
    
    static $rightname = 'plugin_watchman_cron';
    
    /**
     * Nom du type pour l'affichage
     */
    static function getTypeName($nb = 0) {
        return _n('Gestionnaire CRON', 'Gestionnaires CRON', $nb, 'watchman');
    }
    
    /**
     * Installation des tâches CRON
     */
    static function installCronTasks() {
       
        // Nettoyage des logs
        CronTask::register(
            __CLASS__,
            'CleanupLogs',
            DAY_TIMESTAMP,
            [
                'description' => __('Nettoyage des logs de synchronisation', 'watchman'),
                'state' => CronTask::STATE_WAITING,
                'mode' => CronTask::MODE_EXTERNAL,
                'logs_lifetime' => 30
            ]
        );
        
       
    }
    
    /**
     * 
     * Désinstallation des tâches CRON
     */
    static function uninstallCronTasks() {
        $tasks = ['SyncComputersAndApps', 'SyncAlerts', 'CleanupLogs', 'HealthCheck'];
        
        $cron = new CronTask();
        foreach ($tasks as $task_name) {
            if ($cron->getFromDBbyName(__CLASS__, $task_name)) {
                $cron->delete($cron->fields);
            }
        }
    }
    
    /**
     * Tâche CRON - Synchronisation des ordinateurs et leurs applications
     * 
     * @param CronTask $task Instance de la tâche CRON
     * @return int 0=échec, 1=succès
     */
    
    
    /**
     * Tâche CRON - Synchronisation des alertes depuis l'API
     * 
     * @param CronTask $task Instance de la tâche CRON
     * @return int 0=échec, 1=succès
     */
    
    
    /**
     * Tâche CRON - Nettoyage des logs
     */
   
    
    /**
     * Tâche CRON - Vérification santé API
     */
    static function cronHealthCheck($task) {
        try {
            $api_client = new WatchmanApiClient();
            $health = $api_client->performHealthCheck();
            
            if ($health['status'] === 'healthy') {
                $task->log(sprintf(
                    __('API opérationnelle - Latence: %dms', 'watchman'), 
                    $health['latency']
                ));
                
                // Mettre à jour le statut
                WatchmanConfig::saveConfig(['api_status' => 'healthy', 'api_last_check' => date('Y-m-d H:i:s')]);
                return 1;
            } else {
                $task->log(sprintf(
                    __('API en panne: %s', 'watchman'), 
                    $health['error']
                ));
                
                WatchmanConfig::saveConfig(['api_status' => 'down', 'api_last_check' => date('Y-m-d H:i:s')]);
                
                // Notification admin si nécessaire
                self::notifyApiDown($health['error']);
                return 0;
            }
        } catch (Exception $e) {
            $task->log(__('Erreur health check: ', 'watchman') . $e->getMessage());
            WatchmanConfig::saveConfig(['api_status' => 'error', 'api_last_check' => date('Y-m-d H:i:s')]);
            return 0;
        }
    }
    
    /**
     * Synchronise un ordinateur avec ses applications vers l'API
     */
   
    
    /**
     * Récupère les ordinateurs avec leurs applications à synchroniser
     */
    
    
    /**
     * Met à jour le statut de synchronisation d'un ordinateur
     */
    
    
    /**
     * Vérifie si la synchronisation peut s'exécuter
     */
    protected static function canSync() {
        // Vérification configuration
        
        
        // Vérification clés API
        $secret_key = WatchmanConfig::getConfigValue('secret_key');
        $public_key = WatchmanConfig::getConfigValue('public_key');
        
        if (empty($secret_key) || empty($public_key)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Met à jour les métriques de synchronisation
     */
    protected static function updateSyncMetrics($sync_type, $processed, $errors, $duration) {
        global $DB;
        
        $metrics = [
            "last_{$sync_type}_sync_date" => date('Y-m-d H:i:s'),
            "{$sync_type}_processed" => $processed,
            "{$sync_type}_errors" => $errors,
            "{$sync_type}_sync_duration" => $duration,
        ];
        
        foreach ($metrics as $name => $value) {
            $DB->updateOrInsert(
                'glpi_plugin_watchman_watchmanconfigs',
                ['name' => $name, 'value' => $value],
                ['name' => $name]
            );
        }
    }
    
    /**
     * Log une activité de synchronisation
     */
    protected static function logSyncActivity($item_id, $action, $status, $message = null) {
        global $DB;
        
        $DB->insert('glpi_plugin_watchman_sync_logs', [
            'item_id' => $item_id,
            'action' => $action,
            'status' => $status,
            'message' => $message,
            'date_creation' => date('Y-m-d H:i:s'),
            'users_id' => Session::getLoginUserID()
        ]);
    }
    
    /**
     * Notification admin en cas de panne API
     */
    private static function notifyApiDown($error) {
        Toolbox::logInFile('watchman_api_down', "API DOWN: " . $error);
        
        // Vérifier si on a déjà notifié récemment pour éviter le spam
        $last_notification = WatchmanConfig::getConfigValue('last_api_down_notification');
        if ($last_notification && strtotime($last_notification) > strtotime('-1 hour')) {
            return; // Ne pas notifier si déjà fait dans la dernière heure
        }
        
        WatchmanConfig::saveConfig(['last_api_down_notification' => date('Y-m-d H:i:s')]);
        
        // Ici tu peux ajouter l'envoi d'email, Slack, etc.
    }
    
    /**
     * Méthode publique pour synchronisation manuelle des machines
     */
    
    
    /**
     * Obtient le statut global de synchronisation
     */
    public static function getSyncStatus() {
        $computer_last_sync = WatchmanConfig::getConfigValue('last_computers_sync_date');
        $alerts_last_sync = WatchmanConfig::getConfigValue('last_alerts_sync_date');
        $computers_processed = WatchmanConfig::getConfigValue('computers_processed', 0);
        $alerts_processed = WatchmanConfig::getConfigValue('alerts_processed', 0);
        $computer_errors = WatchmanConfig::getConfigValue('computers_errors', 0);
        $alert_errors = WatchmanConfig::getConfigValue('alerts_errors', 0);
        
        $api_status = WatchmanConfig::getConfigValue('api_status', 'unknown');
        $api_last_check = WatchmanConfig::getConfigValue('api_last_check');
        
        return [
            'computers' => [
                'last_sync' => $computer_last_sync,
                'processed_today' => $computers_processed,
                'recent_errors' => $computer_errors
            ],
            'alerts' => [
                'last_sync' => $alerts_last_sync,
                'processed_today' => $alerts_processed,
                'recent_errors' => $alert_errors
            ],
            'api_status' => $api_status,
            'api_last_check' => $api_last_check,
            'can_sync' => self::canSync() && $api_status === 'healthy'
        ];
    }
    
    /**
     * Force l'exécution d'une tâche CRON spécifique
     */
    public static function forceCronTask($task_name) {
        $cron = new CronTask();
        if ($cron->getFromDBbyName(__CLASS__, $task_name)) {
            return CronTask::launch(CronTask::MODE_EXTERNAL, 1, $cron->getID());
        }
        return false;
    }
}