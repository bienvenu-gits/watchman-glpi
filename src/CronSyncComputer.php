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
class CronSyncComputer extends CronManager {
    
    // Configuration des tâches
    const DEFAULT_BATCH_SIZE = 50;
    const DEFAULT_MAX_RETRIES = 3;
    const SYNC_COMPUTERS_INTERVAL = 15; // minutes
    const HEALTH_CHECK_INTERVAL = 10; // minutes
    
    static $rightname = 'plugin_watchman_cron_sync_computer';
    
    /**
     * Nom du type pour l'affichage
     */
    static function getTypeName($nb = 0) {
        return _n('Gestionnaire CRON Computer', 'Gestionnaires CRON', $nb, 'watchman');
    }
    
    /**
     * Installation des tâches CRON
     */
    static function installCronTasks() {
        // Synchronisation des ordinateurs avec leurs applications
        CronTask::register(
            __CLASS__,
            'SyncComputersAndApps',
            self::SYNC_COMPUTERS_INTERVAL * MINUTE_TIMESTAMP,
            [
                'description' => __('Synchronisation des ordinateurs et applications vers API', 'watchman'),
                'parameter' => __('Nombre d\'ordinateurs par lot', 'watchman'),
                'state' => CronTask::STATE_DISABLE,
                'mode' => CronTask::MODE_EXTERNAL,
                'allowmode' => CronTask::MODE_EXTERNAL,
                'logs_lifetime' => 30
            ]
        );
    }
    
    /**
     * Désinstallation des tâches CRON
     */
    static function uninstallCronTasks() {
        $tasks = ['SyncComputersAndApps'];
        
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
    static function cronSyncComputersAndApps($task) {
        global $DB;
        
        $start_time = microtime(true);
        $processed = 0;
        $errors = 0;
        
        try {
            // Vérification que le plugin est configuré et activé
            if (!self::canSync()) {
                $task->log(__('Plugin non configuré ou désactivé', 'watchman'));
                return 0;
            }
            
            $batch_size = $task->fields['param'] ?? self::DEFAULT_BATCH_SIZE;
            
            // Vérification de la santé de l'API
            $api_client = new WatchmanApiClient();
            if (!$api_client->isHealthy()) {
                $task->log(__('API non disponible - synchronisation annulée', 'watchman'));
                return 0;
            }
            
            $task->log(sprintf(__('Début de synchronisation machines+apps - lot de %d éléments', 'watchman'), $batch_size));
            
            // Récupération des ordinateurs à synchroniser avec leurs applications
            $computers_to_sync = self::getComputersWithAppsToSync($batch_size);
            
            if (empty($computers_to_sync)) {
                $task->log(__('Aucun ordinateur à synchroniser', 'watchman'));
                return 1;
            }
            
            $task->log(sprintf(__('%d ordinateurs trouvés pour synchronisation', 'watchman'), count($computers_to_sync)));
            
            // Préparer le payload avec toutes les machines
            $assets_payload = self::prepareAssetsPayload($computers_to_sync);
            
            if (empty($assets_payload['assets'])) {
                $task->log(__('Aucune donnée valide à synchroniser', 'watchman'));
                return 1;
            }
            
            // Envoyer tout le lot à l'API
            try {
                $response = $api_client->syncAssets($assets_payload);
                
                if ($response['success']) {
                    $processed = count($assets_payload['assets']);
                    $task->addVolume($processed);
                    
                    // Mettre à jour le statut de synchronisation pour tous les ordinateurs traités
                    foreach ($computers_to_sync as $computer_data) {
                        self::updateComputerSyncStatus($computer_data['id'], 'success');
                    }
                    
                    $task->log(sprintf(__('%d ordinateurs synchronisés avec succès', 'watchman'), $processed));
                } else {
                    $errors = count($computers_to_sync);
                    $task->log(__('Erreur lors de la synchronisation: ', 'watchman') . $response['error']);
                    
                    // Marquer tous comme en erreur
                    foreach ($computers_to_sync as $computer_data) {
                        self::updateComputerSyncStatus($computer_data['id'], 'error', $response['error']);
                    }
                }
                
            } catch (Exception $e) {
                $errors = count($computers_to_sync);
                $error_msg = __('Exception lors de la synchronisation: ', 'watchman') . $e->getMessage();
                $task->log($error_msg);
                Toolbox::logInFile('watchman_cron_error', $error_msg);
                
                // Marquer tous comme en erreur
                foreach ($computers_to_sync as $computer_data) {
                    self::updateComputerSyncStatus($computer_data['id'], 'error', $e->getMessage());
                }
            }
            
            // Statistiques finales
            $duration = round(microtime(true) - $start_time, 2);
            $task->log(sprintf(
                __('Synchronisation terminée - %d traités, %d erreurs en %s secondes', 'watchman'),
                $processed,
                $errors, 
                $duration
            ));
            
            // Mise à jour des métriques
            self::updateSyncMetrics('computers', $processed, $errors, $duration);
            
            return ($errors > 0 && $processed == 0) ? 0 : 1;
            
        } catch (Exception $e) {
            $task->log(__('Erreur critique: ', 'watchman') . $e->getMessage());
            Toolbox::logInFile('watchman_cron_critical', $e->getMessage() . "\n" . $e->getTraceAsString());
            return 0;
        }
    }
    
    /**
     * Prépare le payload au format attendu par l'API
     */
    private static function prepareAssetsPayload($computers_data) {
        global $DB;
        
        $assets = [];
        
        foreach ($computers_data as $computer_data) {
            try {
                $asset = self::formatComputerForApi($computer_data);
                if ($asset !== null) {
                    $assets[] = $asset;
                }
            } catch (Exception $e) {
                Toolbox::logInFile('watchman_format_error', 
                    sprintf('Erreur formatage ordinateur ID %d: %s', $computer_data['id'], $e->getMessage()));
            }
        }
        
        return ['assets' => $assets];
    }
    
    /**
     * Formate les données d'un ordinateur au format API
     */
    private static function formatComputerForApi($computer_data) {
        global $DB;
        
        // Récupérer les informations réseau de l'ordinateur
        $network_info = self::getComputerNetworkInfo($computer_data['id']);
        
        // Récupérer les informations système
        $system_info = self::getComputerSystemInfo($computer_data['id']);
        
        // Préparer l'asset de base
        $asset = [
            'ip' => $network_info['ip'] ?? '',
            'mac' => $network_info['mac'] ?? '',
            'architecture' => $system_info['architecture'] ?? '',
            'os' => $system_info['os'] ?? '',
            'hostname' => $computer_data['name'] ?? '',
            'host_machine' => $system_info['host_machine'] ?? '',
            'host_machine_hostname' => $system_info['host_machine_hostname'] ?? '',
            'host_machine_os' => $system_info['host_machine_os'] ?? '',
            'host_machine_architecture' => $system_info['host_machine_architecture'] ?? '',
            'host_machine_mac' => $system_info['host_machine_mac'] ?? '',
            'applications' => []
        ];
        
        // Ajouter l'OS comme première application
        if (!empty($system_info['os'])) {
            $asset['applications'][] = [
                'name' => $system_info['os_name'] ?? $system_info['os'],
                'version' => $system_info['os_version'] ?? '',
                'vendor' => $system_info['os_vendor'] ?? $system_info['os'],
                'type' => 'os'
            ];
        }
        
        // Récupérer et ajouter les applications installées
        $applications = self::getComputerApplications($computer_data['id']);
        foreach ($applications as $app) {
            $asset['applications'][] = [
                'name' => $app['name'],
                'version' => $app['version'] ?? '',
                'vendor' => $app['vendor'] ?? $app['name'],
                'type' => 'application'
            ];
        }
        
        return $asset;
    }
    
    /**
     * Récupère les informations réseau d'un ordinateur
     */
    private static function getComputerNetworkInfo($computer_id) {
        global $DB;
        
        $network_info = [
            'ip' => '',
            'mac' => ''
        ];
        
        // Récupérer la première interface réseau avec IP
        $network_query = "
            SELECT np.ip, np.mac
            FROM glpi_networkports np
            LEFT JOIN glpi_networknames nn ON np.id = nn.items_id AND nn.itemtype = 'NetworkPort'
            LEFT JOIN glpi_ipaddresses ip ON nn.id = ip.items_id AND ip.itemtype = 'NetworkName'
            WHERE np.items_id = $computer_id 
              AND np.itemtype = 'Computer'
              AND np.is_deleted = 0
              AND (ip.name IS NOT NULL OR np.ip IS NOT NULL)
            ORDER BY np.logical_number ASC
            LIMIT 1
        ";
        
        $result = $DB->request($network_query);
        if (count($result) > 0) {
            $network = $result->current();
            $network_info['ip'] = $network['ip'] ?? '';
            $network_info['mac'] = $network['mac'] ?? '';
        }
        
        return $network_info;
    }
    
    /**
     * Récupère les informations système d'un ordinateur
     */
    private static function getComputerSystemInfo($computer_id) {
        global $DB;
        
        $system_info = [
            'architecture' => '',
            'os' => '',
            'os_name' => '',
            'os_version' => '',
            'os_vendor' => '',
            'host_machine' => '',
            'host_machine_hostname' => '',
            'host_machine_os' => '',
            'host_machine_architecture' => '',
            'host_machine_mac' => ''
        ];
        
        // Récupérer les informations OS
        $os_query = "
            SELECT os.name as os_name, osv.name as os_version, 
                   osa.name as os_architecture, ossp.name as os_servicepack
            FROM glpi_computers c
            LEFT JOIN glpi_operatingsystems os ON c.operatingsystems_id = os.id
            LEFT JOIN glpi_operatingsystemversions osv ON c.operatingsystemversions_id = osv.id
            LEFT JOIN glpi_operatingsystemarchitectures osa ON c.operatingsystemarchitectures_id = osa.id  
            LEFT JOIN glpi_operatingsystemservicepacks ossp ON c.operatingsystemservicepacks_id = ossp.id
            WHERE c.id = $computer_id
        ";
        
        $os_result = $DB->request($os_query);
        if (count($os_result) > 0) {
            $os_data = $os_result->current();
            $system_info['os_name'] = $os_data['os_name'] ?? '';
            $system_info['os_version'] = $os_data['os_version'] ?? '';
            $system_info['architecture'] = $os_data['os_architecture'] ?? '';
            
            // Construire le nom complet de l'OS
            $os_full = trim($system_info['os_name']);
            if (!empty($system_info['os_version'])) {
                $os_full .= ' ' . $system_info['os_version'];
            }
            if (!empty($os_data['os_servicepack'])) {
                $os_full .= ' ' . $os_data['os_servicepack'];
            }
            $system_info['os'] = $os_full;
            $system_info['os_vendor'] = $system_info['os_name'];
        }
        
        // Pour l'instant, host_machine reste vide sauf si on détecte des VM
        // Tu peux étendre cette logique selon tes besoins
        $system_info['host_machine'] = self::detectHostMachine($computer_id);
        
        return $system_info;
    }
    
    /**
     * Détecte si la machine est virtualisée et récupère les infos de l'hôte
     */
    private static function detectHostMachine($computer_id) {
        global $DB;
        
        // Vérifier si c'est une VM en regardant le modèle ou le fabricant
        $vm_query = "
            SELECT cm.name as model_name, m.name as manufacturer_name
            FROM glpi_computers c
            LEFT JOIN glpi_computermodels cm ON c.computermodels_id = cm.id
            LEFT JOIN glpi_manufacturers m ON c.manufacturers_id = m.id
            WHERE c.id = $computer_id
        ";
        
        $result = $DB->request($vm_query);
        if (count($result) > 0) {
            $data = $result->current();
            $model = strtolower($data['model_name'] ?? '');
            $manufacturer = strtolower($data['manufacturer_name'] ?? '');
            
            // Détecter les machines virtuelles communes
            $vm_indicators = ['vmware', 'virtualbox', 'hyper-v', 'xen', 'kvm', 'qemu', 'virtual', 'vm'];
            
            foreach ($vm_indicators as $indicator) {
                if (strpos($model, $indicator) !== false || strpos($manufacturer, $indicator) !== false) {
                    return $indicator;
                }
            }
        }
        
        return '';
    }
    
    /**
     * Récupère les applications installées sur un ordinateur
     */
    private static function getComputerApplications($computer_id) {
        global $DB;
        
        $apps_query = "
            SELECT DISTINCT s.name, sv.name as version, p.name as vendor
            FROM glpi_computers_softwareversions csv
            JOIN glpi_softwareversions sv ON csv.softwareversions_id = sv.id
            JOIN glpi_softwares s ON sv.softwares_id = s.id
            LEFT JOIN glpi_manufacturers p ON s.manufacturers_id = p.id
            WHERE csv.computers_id = $computer_id
              AND csv.is_deleted = 0
            ORDER BY s.name
        ";
        
        $apps_result = $DB->request($apps_query);
        $applications = [];
        
        foreach ($apps_result as $app) {
            $applications[] = [
                'name' => $app['name'],
                'version' => $app['version'] ?? '',
                'vendor' => $app['vendor'] ?? $app['name']
            ];
        }
        
        return $applications;
    }
    
    /**
     * Tâche CRON - Nettoyage des logs
     */
    static function cronCleanupLogs($task) {
        global $DB;
        
        try {
            $cleanup_date = date('Y-m-d H:i:s', strtotime('-30 days'));
            $total_deleted = 0;
            
            // Nettoyage logs de synchronisation
            $tables_to_clean = [
                'glpi_plugin_watchman_sync_logs',
            ];
            
            foreach ($tables_to_clean as $table) {
                if ($DB->tableExists($table)) {
                    $result = $DB->delete($table, ['date_creation' => ['<', $cleanup_date]]);
                    if ($result) {
                        $deleted = $DB->affectedRows();
                        $total_deleted += $deleted;
                        $task->log(sprintf(__('%d entrées supprimées de %s', 'watchman'), $deleted, $table));
                    }
                }
            }
            
            // Nettoyage des anciennes métriques
            $metrics_cleanup_date = date('Y-m-d H:i:s', strtotime('-90 days'));
            $result = $DB->delete(
                'glpi_plugin_watchman_metrics',
                ['date_creation' => ['<', $metrics_cleanup_date]]
            );
            
            if ($result) {
                $total_deleted += $DB->affectedRows();
            }
            
            $task->log(sprintf(__('Nettoyage terminé - %d entrées supprimées au total', 'watchman'), $total_deleted));
            
            return 1;
        } catch (Exception $e) {
            $task->log(__('Erreur nettoyage: ', 'watchman') . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Récupère les ordinateurs avec leurs applications à synchroniser
     */
    private static function getComputersWithAppsToSync($limit = 50) {
        global $DB;
        
        $query = "
            SELECT c.*, 
                   m.external_id, 
                   m.last_sync_date,
                   m.sync_status,
                   m.retry_count,
                   CASE 
                       WHEN m.id IS NULL THEN 'new'
                       WHEN c.date_mod > m.last_sync_date THEN 'modified' 
                       WHEN m.sync_status = 'error' AND m.retry_count < " . self::DEFAULT_MAX_RETRIES . " THEN 'retry'
                       ELSE 'unchanged'
                   END as sync_reason
            FROM glpi_computers c
            LEFT JOIN glpi_plugin_watchman_computer_mappings m ON c.id = m.computers_id
            WHERE c.is_deleted = 0 
              AND c.is_template = 0
              AND (
                  m.id IS NULL 
                  OR c.date_mod > COALESCE(m.last_sync_date, '1970-01-01')
                  OR (m.sync_status = 'error' AND m.retry_count < " . self::DEFAULT_MAX_RETRIES . ")
              )
            ORDER BY 
              CASE m.sync_status 
                  WHEN 'error' THEN 1 
                  ELSE 2 
              END,
              c.date_mod DESC
            LIMIT $limit
        ";
        
        $iterator = $DB->request($query);
        return iterator_to_array($iterator);
    }
    
    /**
     * Met à jour le statut de synchronisation d'un ordinateur
     */
    private static function updateComputerSyncStatus($computer_id, $status, $error_message = null) {
        global $DB;
        
        $data = [
            'computers_id' => $computer_id,
            'sync_status' => $status,
            'last_sync_date' => date('Y-m-d H:i:s'),
            'error_message' => $error_message
        ];
        
        if ($status === 'error') {
            // Incrémenter le compteur de tentatives
            $existing = $DB->request([
                'FROM' => 'glpi_plugin_watchman_computer_mappings',
                'WHERE' => ['computers_id' => $computer_id]
            ]);
            
            if (count($existing) > 0) {
                $current = $existing->current();
                $data['retry_count'] = ($current['retry_count'] ?? 0) + 1;
            } else {
                $data['retry_count'] = 1;
            }
        } else {
            $data['retry_count'] = 0;
            $data['error_message'] = null;
        }
        
        $DB->updateOrInsert(
            'glpi_plugin_watchman_computer_mappings',
            $data,
            ['computers_id' => $computer_id]
        );
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
    public static function manualSyncComputers($batch_size = null, $force = false) {
        if (!$force && !self::canSync()) {
            return [
                'success' => false,
                'message' => __('Plugin non configuré ou API indisponible', 'watchman')
            ];
        }
        
        $batch_size = $batch_size ?? self::DEFAULT_BATCH_SIZE;
        
        try {
            $api_client = new WatchmanApiClient();
            $computers = self::getComputersWithAppsToSync($batch_size);
            
            if (empty($computers)) {
                return [
                    'success' => true,
                    'message' => __('Aucun ordinateur à synchroniser', 'watchman'),
                    'processed' => 0,
                    'errors' => 0
                ];
            }
            
            // Préparer le payload au format API
            $assets_payload = self::prepareAssetsPayload($computers);


            var_dump($assets_payload);



            return;
            
            // Envoyer à l'API
            $response = $api_client->syncAssets($assets_payload);
            
            $processed = 0;
            $errors = 0;
            
            if ($response['success']) {
                $processed = count($assets_payload['assets']);
                
                // Mettre à jour le statut de tous les ordinateurs
                foreach ($computers as $computer_data) {
                    self::updateComputerSyncStatus($computer_data['id'], 'success');
                }
            } else {
                $errors = count($computers);
                
                // Marquer tous comme en erreur
                foreach ($computers as $computer_data) {
                    self::updateComputerSyncStatus($computer_data['id'], 'error', $response['error']);
                }
            }
            
            return [
                'success' => $processed > 0,
                'message' => sprintf(
                    __('%d ordinateurs traités, %d erreurs', 'watchman'), 
                    $processed, 
                    $errors
                ),
                'processed' => $processed,
                'errors' => $errors,
                'payload' => $assets_payload // Pour debug
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
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