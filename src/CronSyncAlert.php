<?php

namespace GlpiPlugin\Watchman;

use CommonDBTM;
use CronTask;
use Session;
use Toolbox;
use Computer;
use Exception;
use Software;
use Ticket;
use Entity;

/**
 * Classe principale pour la gestion des tâches CRON de synchronisation
 * 
 * Cette classe orchestre toutes les synchronisations du plugin
 * et peut être appelée par d'autres classes ou manuellement
 */
class CronSyncAlert extends CronManager
{

    // Configuration des tâches
    const DEFAULT_BATCH_SIZE = 50;
    const DEFAULT_MAX_RETRIES = 3;
    const SYNC_ALERTS_INTERVAL = 5; // minutes
    const PAGINATION_LIMIT = 10; // Nombre d'alertes par page

    static $rightname = 'plugin_watchman_cron_sync_alert';

    /**
     * Nom du type pour l'affichage
     */
    static function getTypeName($nb = 0)
    {
        return _n('Gestionnaire CRON Alert', 'Gestionnaires CRON Alert', $nb, 'watchman');
    }

    /**
     * Installation des tâches CRON
     */
    static function installCronTasks()
    {
        // Récupération des alertes depuis l'API
        CronTask::register(
            __CLASS__,
            'SyncAlerts',
            self::SYNC_ALERTS_INTERVAL * MINUTE_TIMESTAMP,
            [
                'description' => __('Récupération des alertes depuis l\'API avec pagination', 'watchman'),
                'parameter' => __('Nombre d\'alertes maximum par lot', 'watchman'),
                // 'state' => CronTask::STATE_WAITING,
                'mode' => CronTask::MODE_EXTERNAL,
                'allowmode' => CronTask::MODE_EXTERNAL,
                'logs_lifetime' => 30
            ]
        );
        
        // Monitoring et récupération des tâches bloquées
        CronTask::register(
            __CLASS__,
            'MonitorTasks',
            5 * MINUTE_TIMESTAMP, // Toutes les 5 minutes
            [
                'description' => __('Surveillance et récupération des tâches cron bloquées', 'watchman'),
                'parameter' => __('Intervalle de vérification en minutes', 'watchman'),
                'mode' => CronTask::MODE_EXTERNAL,
                'allowmode' => CronTask::MODE_EXTERNAL,
                'logs_lifetime' => 7
            ]
        );
    }

    /**
     * Désinstallation des tâches CRON
     */
    static function uninstallCronTasks()
    {
        $tasks = ['SyncAlerts', 'MonitorTasks'];

        $cron = new CronTask();
        foreach ($tasks as $task_name) {
            if ($cron->getFromDBbyName(__CLASS__, $task_name)) {
                $cron->delete($cron->fields);
            }
        }
    }

    /**
     * Tâche CRON - Synchronisation des alertes depuis l'API avec pagination
     * 
     * @param CronTask $task Instance de la tâche CRON
     * @return int 0=échec, 1=succès
     */
    static function cronSyncAlerts($task)
    {
        // Configuration mémoire plus optimisée
        ini_set('memory_limit', '1G');
        ini_set('max_execution_time', 1800); // 30 minutes max
        
        // Démarrer le monitoring
        CronMonitor::startMonitoring(__CLASS__ . '::cronSyncAlerts', $task->getID());
        
        echo 'tache lancée';
        $start_time = microtime(true);
        $total_processed = 0;
        $total_errors = 0;
        $total_tickets_created = 0;

        try {
            if (!self::canSync()) {
                $task->log(__('Plugin non configuré ou désactivé', 'watchman'));
                CronMonitor::stopMonitoring(__CLASS__ . '::cronSyncAlerts', $task->getID(), 'failed');
                return 0;
            }

            $max_alerts_per_batch = $task->fields['param'] ?? self::DEFAULT_BATCH_SIZE;

            $api_client = new WatchmanApiClient();

            $task->log(__('Début de la synchronisation des alertes avec pagination', 'watchman'));
            echo __('Début de la synchronisation des alertes avec pagination', 'watchman');

            // Récupération de la dernière date de mise à jour synchronisée
            $last_updated_at = WatchmanConfig::getConfigValue('last_alert_updated_at', null);
            $task->log(sprintf(__('Récupération des alertes modifiées depuis: %s', 'watchman'), $last_updated_at));

            // Pagination des alertes
            $page = 1;
            $has_more_pages = true;
            $latest_updated_at = $last_updated_at;

            while ($has_more_pages) {
                // Mettre à jour le heartbeat à chaque page
                CronMonitor::updateHeartbeat(__CLASS__ . '::cronSyncAlerts', $task->getID(), [
                    'current_page' => $page,
                    'total_processed' => $total_processed,
                    'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2)
                ]);
                
                $task->log(sprintf(__('Traitement de la page %d', 'watchman'), $page));
                $params=[
                    'page' => $page,
                    'page_size' => self::PAGINATION_LIMIT,
                    'ordering' => 'updated_at' // Tri par date de mise à jour croissante
                ];
                if($last_updated_at!=null){
                    $params['updated_at__gte']=$last_updated_at;
                }

                // Récupération des alertes avec pagination
                $alerts_response = $api_client->getAlerts( $params);
               

                if (!$alerts_response['success']) {
                    $task->log(__('Erreur récupération alertes page ', 'watchman') . $page . ': ' . $alerts_response['error']);
                    break;
                }

                $pagination_data = $alerts_response['data'] ?? [];
                $alerts = $pagination_data['results'] ?? [];
                $next_page = $pagination_data['next'] ?? null;

                echo 'Pagination next'.$next_page;
                // echo json_encode($pagination_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

                if (empty($alerts)) {
                    $task->log(__('Aucune alerte trouvée sur cette page', 'watchman'));
                    break;
                }

                $task->log(sprintf(__('%d alertes reçues sur la page %d', 'watchman'), count($alerts), $page));

                // Traitement des alertes de cette page
                $page_processed = 0;
                $page_errors = 0;
                $page_tickets_created = 0;

                $alert_manager = new AlertManager();

                foreach ($alerts as $alert_data) {
                    try {
                        // Limitation par batch si configurée
                        // if ($total_processed >= $max_alerts_per_batch && $max_alerts_per_batch > 0) {
                        //     $task->log(sprintf(__('Limite de %d alertes atteinte, arrêt du traitement', 'watchman'), $max_alerts_per_batch));
                        //     $has_more_pages = false;
                        //     break;
                        // }

                        // Traitement de l'alerte
                        $result = $alert_manager->processAlert($alert_data);

                        if ($result['success']) {
                            $page_processed++;
                            $total_processed++;

                            // Création d'un ticket pour cette alerte si elle est nouvelle ou critique
                            if ($result['is_new'] || $result['is_critical']) {
                                $ticket_result = self::createTicketForAlert($alert_data, $result['alert_id']);
                                if ($ticket_result['success']) {
                                    $page_tickets_created++;
                                    $total_tickets_created++;

                                    // Mise à jour de l'alerte avec l'ID du ticket
                                    $alert_manager->linkAlertToTicket($result['alert_id'], $ticket_result['ticket_id']);
                                }
                            }

                            $task->addVolume(1);

                            // Mise à jour de la dernière date updated_at
                            if (isset($alert_data['updated_at'])) {
                                WatchmanConfig::saveConfig([
                                    'last_alert_updated_at' => $alert_data['updated_at'],
                                ]);
                            }
                        } else {
                            $page_errors++;
                            $total_errors++;
                            $task->log(sprintf(
                                __('Erreur traitement alerte ID %s: %s', 'watchman'),
                                $alert_data['id'] ?? 'inconnu',
                                $result['error']
                            ));
                        }
                    } catch (Exception $e) {
                        $page_errors++;
                        $total_errors++;
                        $error_msg = sprintf(
                            __('Exception alerte ID %s: %s', 'watchman'),
                            $alert_data['id'] ?? 'inconnu',
                            $e->getMessage()
                        );
                        echo $error_msg;
                        $task->log($error_msg);
                        Toolbox::logInFile('watchman_alerts_error', $error_msg);
                    }
                }

                $task->log(sprintf(
                    __('Page %d terminée - %d alertes traitées, %d tickets créés, %d erreurs', 'watchman'),
                    $page,
                    $page_processed,
                    $page_tickets_created,
                    $page_errors
                ));

                // Vérification s'il y a une page suivante
                // $has_more_pages = ($next_page !== null) && ($total_processed < $max_alerts_per_batch || $max_alerts_per_batch == 0);
                $has_more_pages = $next_page !== null;
                $page++;
                echo 'has more pages '.$has_more_pages.'\n';
                echo 'total_processed'.$total_processed.'\n';
                echo 'max_alerts_per_batch'.$max_alerts_per_batch.'\n';

                // Vérification de la mémoire pour éviter les dépassements
                $memoryUsage = memory_get_usage(true);
                $memoryLimit = self::parseMemoryLimit(ini_get('memory_limit'));
                $memoryPercentage = ($memoryUsage / $memoryLimit) * 100;
                
                if ($memoryPercentage > 80) {
                    $task->log(sprintf(
                        __('Utilisation mémoire élevée (%.1f%%), arrêt préventif après %d alertes', 'watchman'),
                        $memoryPercentage,
                        $total_processed
                    ));
                    break;
                }
                
                // Pause entre les pages pour éviter de surcharger l'API
                if ($has_more_pages) {
                    sleep(1);
                }
            }

            

            // Statistiques finales
            $duration = round(microtime(true) - $start_time, 2);
            $task->log(sprintf(
                __('Synchronisation terminée - %d alertes traitées, %d tickets créés, %d erreurs en %s secondes', 'watchman'),
                $total_processed,
                $total_tickets_created,
                $total_errors,
                $duration
            ));

            // Mise à jour des métriques
            // self::updateSyncMetrics('alerts', $total_processed, $total_errors, $duration);
            WatchmanConfig::saveConfig([
                'alerts_processed' => $total_processed,
                'alerts_errors' => $total_errors,
                'tickets_created' => $total_tickets_created
            ]);

            // Terminer le monitoring avec succès
            CronMonitor::stopMonitoring(__CLASS__ . '::cronSyncAlerts', $task->getID(), 'completed');

            return ($total_errors > 0 && $total_processed == 0) ? 0 : 1;
        } catch (Exception $e) {
            $task->log(__('Erreur critique alertes: ', 'watchman') . $e->getMessage());
            Toolbox::logInFile('watchman_alerts_critical', $e->getMessage() . "\n" . $e->getTraceAsString());
            
            // Terminer le monitoring avec erreur
            CronMonitor::stopMonitoring(__CLASS__ . '::cronSyncAlerts', $task->getID(), 'failed');
            
            return 0;
        }
    }

    /**
     * Création d'un ticket GLPI pour une alerte
     * 
     * @param array $alert_data Données de l'alerte
     * @param string $alert_id ID de l'alerte dans Watchman
     * @return array Résultat de la création
     */
    public static function createTicketForAlert($alert_data, $alert_id)
    {
        global $DB;
        try {
            $ticket = new Ticket();

            // Détermination de la priorité selon la sévérité
            $priority = self::mapSeverityToPriority($alert_data['severity'] ?? 'medium');
            $urgency = self::mapSeverityToUrgency($alert_data['severity'] ?? 'medium');

            // Construction du titre du ticket
            $title = sprintf(
                __('[WATCHMAN] %s - %s', 'watchman'),
                $alert_data['cve']['id'] ?? 'CVE inconnu',
                $alert_data['title'] ?? 'Alerte de sécurité'
            );

            // Construction de la description
            $description = self::buildTicketDescription($alert_data);

            // Données du ticket
            $ticket_data = [
                'name' => $DB->escape($title),
                'content' => $DB->escape($description),
                'status' => 2, // Nouveau
                'priority' => $priority,
                'urgency' => $urgency,
                'impact' => self::mapSeverityToImpact($alert_data['severity'] ?? 'medium'),
                'type' => 2, // Incident
                'category' => 0, // À déterminer
                'entities_id' => $_SESSION['glpiactive_entity'] ?? 0,
                'users_id_recipient' => 0,
                'requesttypes_id' => 6, // Monitoring automatique
                'date' => date('Y-m-d H:i:s'),
                'date_creation' => date('Y-m-d H:i:s'),
            ];

            // Création du ticket
            $ticket_id = $ticket->add($ticket_data);

            if ($ticket_id) {
                // Log de l'activité
                self::logAlertActivity($alert_id, 'ticket_created', "Ticket #{$ticket_id} créé automatiquement");

                return [
                    'success' => true,
                    'ticket_id' => $ticket_id,
                    'message' => sprintf(__('Ticket #%d créé avec succès', 'watchman'), $ticket_id)
                ];
            } else {
                return [
                    'success' => false,
                    'error' => __('Échec de la création du ticket', 'watchman')
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Construction de la description détaillée du ticket
     */
    private static function buildTicketDescription($alert_data)
    {
        $description = [];

        $description[] = "=== ALERTE DE SÉCURITÉ WATCHMAN ===\n";

        // Informations générales
        $description[] = "CVE: " . ($alert_data['cve']['id'] ?? 'Non spécifié');
        $description[] = "Sévérité: " . ucfirst($alert_data['severity'] ?? 'Inconnue');
        $description[] = "Score CVSS: " . ($alert_data['score'] ?? 'Non calculé');
        $description[] = "Stack affecté: " . ($alert_data['stack']['name'] ?? 'Non spécifié') .
            " v" . ($alert_data['stack']['version'] ?? '');

        $description[] = "\nDescription:";
        $description[] = $alert_data['description'] ?? 'Aucune description disponible';

        // Informations CVE
        if (isset($alert_data['cve'])) {
            $description[] = "\n=== DÉTAILS CVE ===";
            $description[] = "Publié le: " . ($alert_data['cve']['published_at'] ?? 'Non spécifié');
            $description[] = "Modifié le: " . ($alert_data['cve']['modified_at'] ?? 'Non spécifié');
            $description[] = "Attribué par: " . ($alert_data['cve']['assigner'] ?? 'Non spécifié');
        }

        // Impact
        if (isset($alert_data['cve']['impact'])) {
            $impact = $alert_data['cve']['impact'];
            $description[] = "\n=== ÉVALUATION D'IMPACT ===";
            $description[] = "Vecteur d'attaque: " . ($impact['attack_vector'] ?? 'Non spécifié');
            $description[] = "Complexité: " . ($impact['attack_complexity'] ?? 'Non spécifié');
            $description[] = "Privilèges requis: " . ($impact['privileges_required'] ?? 'Non spécifié');
            $description[] = "Interaction utilisateur: " . ($impact['user_interaction'] ?? 'Non spécifié');
            $description[] = "Impact confidentialité: " . ($impact['confidentiality_impact'] ?? 'Non spécifié');
            $description[] = "Impact intégrité: " . ($impact['integrity_impact'] ?? 'Non spécifié');
            $description[] = "Impact disponibilité: " . ($impact['availability_impact'] ?? 'Non spécifié');
        }

        // Références
        if (isset($alert_data['cve']['references']) && is_array($alert_data['cve']['references'])) {
            $description[] = "\n=== RÉFÉRENCES ===";
            foreach ($alert_data['cve']['references'] as $ref) {
                $description[] = "- " . ($ref['name'] ?? 'Référence') . ": " . ($ref['url'] ?? '');
            }
        }

        $description[] = "\n=== ACTIONS RECOMMANDÉES ===";
        $description[] = "1. Évaluer l'impact sur l'infrastructure";
        $description[] = "2. Vérifier la présence de correctifs de sécurité";
        $description[] = "3. Planifier la mise à jour si nécessaire";
        $description[] = "4. Marquer l'alerte comme traitée dans Watchman";

        $description[] = "\n---";
        $description[] = "Ticket créé automatiquement par le plugin Watchman";
        $description[] = "ID Alerte: " . ($alert_data['id'] ?? 'Non spécifié');
        $description[] = "Date création: " . date('Y-m-d H:i:s');

        return implode("\n", $description);
    }

    /**
     * Mapping sévérité -> priorité GLPI
     */
    private static function mapSeverityToPriority($severity)
    {
        switch (strtolower($severity)) {
            case 'critical':
                return 5; // Très haute
            case 'high':
                return 4; // Haute
            case 'medium':
                return 3; // Moyenne
            case 'low':
                return 2; // Basse
            default:
                return 3; // Moyenne par défaut
        }
    }

    /**
     * Mapping sévérité -> urgence GLPI
     */
    private static function mapSeverityToUrgency($severity)
    {
        switch (strtolower($severity)) {
            case 'critical':
                return 5; // Très haute
            case 'high':
                return 4; // Haute
            case 'medium':
                return 3; // Moyenne
            case 'low':
                return 2; // Basse
            default:
                return 3; // Moyenne par défaut
        }
    }

    /**
     * Mapping sévérité -> impact GLPI
     */
    private static function mapSeverityToImpact($severity)
    {
        switch (strtolower($severity)) {
            case 'critical':
                return 5; // Très haut
            case 'high':
                return 4; // Haut
            case 'medium':
                return 3; // Moyen
            case 'low':
                return 2; // Bas
            default:
                return 3; // Moyen par défaut
        }
    }

    /**
     * Log d'activité pour une alerte
     */
    private static function logAlertActivity($alert_id, $action, $message)
    {
        // global $DB;

        // try {
        //     $DB->insert('glpi_plugin_watchman_alert_logs', [
        //         'alerts_id' => $alert_id,
        //         'action' => $DB->escape($action),
        //         'message' => $DB->escape($message),
        //         'users_id' => Session::getLoginUserID(),
        //         'date_creation' => date('Y-m-d H:i:s')
        //     ]);
        // } catch (Exception $e) {
        //     Toolbox::logInFile('watchman_log_error', "Erreur log activité: " . $e->getMessage());
        // }
    }

    /**
     * Parse la limite mémoire PHP en octets
     */
    private static function parseMemoryLimit($memoryLimit)
    {
        $memoryLimit = strtoupper(trim($memoryLimit));
        
        if ($memoryLimit == '-1') {
            return PHP_INT_MAX; // Pas de limite
        }
        
        $value = (int) $memoryLimit;
        $unit = substr($memoryLimit, -1);
        
        switch ($unit) {
            case 'G':
                $value *= 1024;
            case 'M':
                $value *= 1024;
            case 'K':
                $value *= 1024;
        }
        
        return $value;
    }

    /**
     * Tâche CRON - Nettoyage des logs
     */
    static function cronCleanupLogs($task)
    {
        global $DB;

        try {
            $cleanup_date = date('Y-m-d H:i:s', strtotime('-30 days'));
            $total_deleted = 0;

            // Nettoyage logs de synchronisation
            $tables_to_clean = [
                'glpi_plugin_watchman_alert_logs',
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
     * Méthode publique pour synchronisation manuelle des alertes
     */
    public static function manualSyncAlerts($max_alerts = 100)
    {

        if (!self::canSync()) {
            return [
                'success' => false,
                'message' => __('Plugin non configuré ou API indisponible', 'watchman')
            ];
        }

        try {
            $api_client = new WatchmanApiClient();
            $alert_manager = new AlertManager();

            $last_updated_at = WatchmanConfig::getConfigValue('last_alert_updated_at', null);
            $processed = 0;
            $errors = 0;
            $tickets_created = 0;
            $latest_updated_at = $last_updated_at;

            // Configuration initiale de la pagination
            $page = 1;
            $has_more = true;

            while ($has_more) {
                // Construire les paramètres de requête
                $request_params = [
                    'page' => $page,
                    'page_size' => min($max_alerts, self::PAGINATION_LIMIT),
                    'ordering' => 'updated_at'
                ];

                if ($last_updated_at !== null) {
                    $request_params['updated_at__gte'] = $last_updated_at;
                }

                var_dump("Fetching page $page with params: " . json_encode($request_params));
                $alerts_response = $api_client->getAlerts($request_params);

                if (!$alerts_response['success']) {
                    return [
                        'success' => false,
                        'message' => $alerts_response['error']
                    ];
                }

                $pagination_data = $alerts_response['data'] ?? [];
                $alerts = $pagination_data['results'] ?? [];
                $count = count($alerts);

                var_dump("Found $count alerts on page $page");

                // Traiter chaque alerte de la page
                foreach ($alerts as $alert_data) {
                    $result = $alert_manager->processAlert($alert_data);

                    if ($result['success']) {
                        $processed++;

                        // Création de ticket si nécessaire
                        if ($result['is_new']) {
                            $ticket_result = self::createTicketForAlert($alert_data, $result['alert_id']);
                            if ($ticket_result['success']) {
                                $tickets_created++;
                                $alert_manager->linkAlertToTicket($result['alert_id'], $ticket_result['ticket_id']);
                            }
                        }

                        // Mettre à jour la dernière date de mise à jour
                        if (isset($alert_data['updated_at'])) {
                            // $latest_updated_at = $alert_data['updated_at'];
                            WatchmanConfig::saveConfig(['last_alert_updated_at' => $alert_data['updated_at']], false);
                        }
                    } else {
                        $errors++;
                    }
                }

                // Vérifier s'il y a une page suivante
                $next_page = $pagination_data['next'] ?? null;
                if ($next_page) {
                    $page++;
                } else {
                    $has_more = false;
                }
            }

            // Mettre à jour la configuration avec la dernière date
            WatchmanConfig::saveConfig([
                'last_alerts_sync_date' => date('Y-m-d H:i:s')
            ], false);

            return [
                'success' => true,
                'message' => sprintf(
                    __('%d alertes traitées, %d tickets créés, %d erreurs', 'watchman'),
                    $processed,
                    $tickets_created,
                    $errors
                ),
                'processed' => $processed,
                'tickets_created' => $tickets_created,
                'errors' => $errors
            ];
        } catch (Exception $e) {
            var_dump('exception');
            var_dump($e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Obtient le statut global de synchronisation
     */
    public static function getSyncStatus()
    {
        $alerts_last_sync = WatchmanConfig::getConfigValue('last_alert_sync_date');
        $alerts_processed = WatchmanConfig::getConfigValue('alerts_processed', 0);
        $alert_errors = WatchmanConfig::getConfigValue('alerts_errors', 0);
        $tickets_created = WatchmanConfig::getConfigValue('tickets_created', 0);

        $api_status = WatchmanConfig::getConfigValue('api_status', 'unknown');
        $api_last_check = WatchmanConfig::getConfigValue('api_last_check');

        return [
            'alerts' => [
                'last_sync' => $alerts_last_sync,
                'processed_today' => $alerts_processed,
                'tickets_created_today' => $tickets_created,
                'recent_errors' => $alert_errors
            ],
            'api_status' => $api_status,
            'api_last_check' => $api_last_check,
            'can_sync' => self::canSync() && $api_status === 'healthy'
        ];
    }

    /**
     * Tâche CRON - Monitoring des tâches bloquées
     * 
     * @param CronTask $task Instance de la tâche CRON
     * @return int 0=échec, 1=succès
     */
    static function cronMonitorTasks($task)
    {
        try {
            $task->log(__('Début du monitoring des tâches cron', 'watchman'));
            
            // Lancer la vérification des tâches bloquées
            CronMonitor::runMonitoringCheck();
            
            $task->log(__('Monitoring des tâches terminé avec succès', 'watchman'));
            $task->addVolume(1);
            
            return 1;
            
        } catch (Exception $e) {
            $task->log(__('Erreur dans le monitoring: ', 'watchman') . $e->getMessage());
            Toolbox::logInFile('watchman_monitor_error', $e->getMessage() . "\n" . $e->getTraceAsString());
            return 0;
        }
    }
}
