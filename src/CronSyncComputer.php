<?php

namespace GlpiPlugin\Watchman;

use CommonDBTM;
use CronTask;
use Session;
use Toolbox;
use Computer;
use Exception;
use Software;
use DBmysql;
use QueryExpression;

/**
 * Classe principale pour la gestion des tâches CRON de synchronisation
 * 
 * Cette classe orchestre toutes les synchronisations du plugin
 * et peut être appelée par d'autres classes ou manuellement
 */
class CronSyncComputer extends CronManager
{

    // Configuration des tâches
    const DEFAULT_BATCH_SIZE = 50;
    const DEFAULT_MAX_RETRIES = 3;
    const SYNC_COMPUTERS_INTERVAL = 15; // minutes
    const HEALTH_CHECK_INTERVAL = 10; // minutes

    static $rightname = 'plugin_watchman_cron_sync_computer';

    /**
     * Nom du type pour l'affichage
     */
    static function getTypeName($nb = 0)
    {
        return _n('Gestionnaire CRON Computer', 'Gestionnaires CRON', $nb, 'watchman');
    }

    /**
     * Installation des tâches CRON
     */
    static function installCronTasks()
    {
        // Synchronisation des ordinateurs avec leurs applications
        CronTask::register(
            __CLASS__,
            'SyncComputersAndApps',
            self::SYNC_COMPUTERS_INTERVAL * MINUTE_TIMESTAMP,
            [
                'description' => __('Synchronisation des ordinateurs et applications vers API', 'watchman'),
                'parameter' => __('Nombre d\'ordinateurs par lot', 'watchman'),
                'mode' => CronTask::MODE_EXTERNAL,
                'allowmode' => CronTask::MODE_EXTERNAL,
                'logs_lifetime' => 30
            ]
        );
    }

    /**
     * Désinstallation des tâches CRON
     */
    static function uninstallCronTasks()
    {
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
    static function cronSyncComputersAndApps($task)
    {
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
            $assets_cleaned = self::cleanAssetsStrict($assets_payload);
            if (empty($assets_cleaned['assets'])) {
                $task->log(__('Aucune donnée valide à synchroniser', 'watchman'));
                return 1;
            }

            // Envoyer tout le lot à l'API
            try {
                $response = $api_client->syncAssets($assets_cleaned);

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
                    // foreach ($computers_to_sync as $computer_data) {
                    //     self::updateComputerSyncStatus($computer_data['id'], 'error', $response['error']);
                    // }
                }
            } catch (Exception $e) {
                $errors = count($computers_to_sync);
                $error_msg = __('Exception lors de la synchronisation: ', 'watchman') . $e->getMessage();
                $task->log($error_msg);
                Toolbox::logInFile('watchman_cron_error', $error_msg);

                // Marquer tous comme en erreur
                // foreach ($computers_to_sync as $computer_data) {
                //     self::updateComputerSyncStatus($computer_data['id'], 'error', $e->getMessage());
                // }
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
    private static function prepareAssetsPayload($computers_data)
    {
        global $DB;

        $assets = [];

        foreach ($computers_data as $computer_data) {
            try {
                $asset = self::formatComputerForApi($computer_data);
                if ($asset !== null) {
                    $assets[] = $asset;
                }
            } catch (Exception $e) {
                Toolbox::logInFile(
                    'watchman_format_error',
                    sprintf('Erreur formatage ordinateur ID %d: %s', $computer_data['id'], $e->getMessage())
                );
            }
        }

        return ['assets' => $assets];
    }

    /**
     * Formate les données d'un ordinateur au format API
     */
    private static function formatComputerForApi($computer_data)
    {
        global $DB;

        // Récupérer les informations réseau de l'ordinateur
        $network_info = self::getComputerNetworkInfo($computer_data['id']);

        // Récupérer les informations système
        $system_info = self::getComputerSystemInfo($computer_data['id']);

        // Préparer l'asset de base
        $asset = [
            'computer_glpi_id' => $computer_data['id'] ?? '',
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
        // $applications = self::getComputerApplications($computer_data['id']);
        $applications = self::getComputerApps($computer_data['id']);

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
     * CORRIGÉ: Utilisation correcte des jointures GLPI
     */
    private static function getComputerNetworkInfo($computer_id)
    {
        global $DB;

        $network_info = [
            'ip' => '',
            'mac' => ''
        ];

        // Requête corrigée pour GLPI
        $iterator = $DB->request([
            'SELECT' => [
                'glpi_networkports.mac',
                'glpi_ipaddresses.name AS ip'
            ],
            'FROM' => 'glpi_networkports',
            'LEFT JOIN' => [
                'glpi_networknames' => [
                    'FKEY' => [
                        'glpi_networknames' => 'items_id',
                        'glpi_networkports' => 'id',
                        ['AND' => ['glpi_networknames.itemtype' => 'NetworkPort']]
                    ]
                ],
                'glpi_ipaddresses' => [
                    'FKEY' => [
                        'glpi_ipaddresses' => 'items_id',
                        'glpi_networknames' => 'id',
                        ['AND' => ['glpi_ipaddresses.itemtype' => 'NetworkName']]
                    ]
                ]
            ],
            'WHERE' => [
                'glpi_networkports.items_id' => $computer_id,
                'glpi_networkports.itemtype' => 'Computer',
                'glpi_networkports.is_deleted' => 0
            ],
            'ORDER' => 'glpi_networkports.logical_number ASC',
            'LIMIT' => 1
        ]);

        if (count($iterator) > 0) {
            $network = $iterator->current();
            $network_info['ip'] = $network['ip'] ?? '';
            $network_info['mac'] = $network['mac'] ?? '';
        }

        return $network_info;
    }

    /**
     * Récupère les informations système d'un ordinateur
     * CORRIGÉ: Utilisation de la table Item_OperatingSystem
     */
    private static function getComputerSystemInfo($computer_id)
    {
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

        // Requête corrigée pour utiliser Item_OperatingSystem
        $iterator = $DB->request([
            'SELECT' => [
                'glpi_operatingsystems.name AS os_name',
                'glpi_operatingsystemversions.name AS os_version',
                'glpi_operatingsystemarchitectures.name AS os_architecture',
                'glpi_operatingsystemservicepacks.name AS os_servicepack'
            ],
            'FROM' => 'glpi_items_operatingsystems',
            'LEFT JOIN' => [
                'glpi_operatingsystems' => [
                    'FKEY' => [
                        'glpi_items_operatingsystems' => 'operatingsystems_id',
                        'glpi_operatingsystems' => 'id'
                    ]
                ],
                'glpi_operatingsystemversions' => [
                    'FKEY' => [
                        'glpi_items_operatingsystems' => 'operatingsystemversions_id',
                        'glpi_operatingsystemversions' => 'id'
                    ]
                ],
                'glpi_operatingsystemarchitectures' => [
                    'FKEY' => [
                        'glpi_items_operatingsystems' => 'operatingsystemarchitectures_id',
                        'glpi_operatingsystemarchitectures' => 'id'
                    ]
                ],
                'glpi_operatingsystemservicepacks' => [
                    'FKEY' => [
                        'glpi_items_operatingsystems' => 'operatingsystemservicepacks_id',
                        'glpi_operatingsystemservicepacks' => 'id'
                    ]
                ]
            ],
            'WHERE' => [
                'glpi_items_operatingsystems.items_id' => $computer_id,
                'glpi_items_operatingsystems.itemtype' => 'Computer'
            ]
        ]);

        if (count($iterator) > 0) {
            $os_data = $iterator->current();
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

        // Détecter si c'est une VM et obtenir les infos de l'hôte
        $system_info['host_machine'] = self::detectHostMachine($computer_id);

        return $system_info;
    }

    /**
     * Détecte si la machine est virtualisée et récupère les infos de l'hôte
     * CORRIGÉ: Jointures correctes
     */
    private static function detectHostMachine($computer_id)
    {
        global $DB;

        // Requête corrigée
        $iterator = $DB->request([
            'SELECT' => [
                'glpi_computermodels.name AS model_name',
                'glpi_manufacturers.name AS manufacturer_name',
                'glpi_computertypes.name AS type_name'
            ],
            'FROM' => 'glpi_computers',
            'LEFT JOIN' => [
                'glpi_computermodels' => [
                    'FKEY' => [
                        'glpi_computers' => 'computermodels_id',
                        'glpi_computermodels' => 'id'
                    ]
                ],
                'glpi_manufacturers' => [
                    'FKEY' => [
                        'glpi_computers' => 'manufacturers_id',
                        'glpi_manufacturers' => 'id'
                    ]
                ],
                'glpi_computertypes' => [
                    'FKEY' => [
                        'glpi_computers' => 'computertypes_id',
                        'glpi_computertypes' => 'id'
                    ]
                ]
            ],
            'WHERE' => ['glpi_computers.id' => $computer_id]
        ]);

        if (count($iterator) > 0) {
            $data = $iterator->current();
            $model = strtolower($data['model_name'] ?? '');
            $manufacturer = strtolower($data['manufacturer_name'] ?? '');
            $type = strtolower($data['type_name'] ?? '');

            // Détecter les machines virtuelles communes
            $vm_indicators = ['vmware', 'virtualbox', 'hyper-v', 'xen', 'kvm', 'qemu', 'virtual', 'vm'];

            foreach ($vm_indicators as $indicator) {
                if (
                    strpos($model, $indicator) !== false ||
                    strpos($manufacturer, $indicator) !== false ||
                    strpos($type, $indicator) !== false
                ) {
                    return $indicator;
                }
            }
        }

        return '';
    }

    /**
     * Récupère les applications installées sur un ordinateur
     * CORRIGÉ: Utilisation de la table items_softwareversions
     */
    private static function getComputerApplications($computer_id)
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT DISTINCT' => [
                'glpi_softwares.name',
                'glpi_softwareversions.name AS version',
                'glpi_manufacturers.name AS vendor'
            ],
            'FROM' => 'glpi_items_softwareversions',
            'INNER JOIN' => [
                'glpi_softwareversions' => [
                    'FKEY' => [
                        'glpi_items_softwareversions' => 'softwareversions_id',
                        'glpi_softwareversions' => 'id'
                    ]
                ],
                'glpi_softwares' => [
                    'FKEY' => [
                        'glpi_softwareversions' => 'softwares_id',
                        'glpi_softwares' => 'id'
                    ]
                ]
            ],
            'LEFT JOIN' => [
                'glpi_manufacturers' => [
                    'FKEY' => [
                        'glpi_softwares' => 'manufacturers_id',
                        'glpi_manufacturers' => 'id'
                    ]
                ]
            ],
            'WHERE' => [
                'glpi_items_softwareversions.items_id' => $computer_id,
                'glpi_items_softwareversions.itemtype' => 'Computer',
                'glpi_items_softwareversions.is_deleted' => 0
            ],
            'ORDER' => 'glpi_softwares.name'
        ]);

        $applications = [];
        foreach ($iterator as $app) {
            $applications[] = [
                'name' => $app['name'],
                'version' => $app['version'] ?? '',
                'vendor' => $app['vendor'] ?? $app['name']
            ];
        }

        return $applications;
    }

    /**
     * Récupère les ordinateurs avec leurs applications à synchroniser
     * CORRIGÉ: Utilisation correcte de QueryExpression
     */
    private static function getComputersWithAppsToSync($limit = 50)
    {
        global $DB;

        // D'abord vérifier si la table de mapping existe
        $table_exists = $DB->tableExists('glpi_plugin_watchman_computer_mappings');

        if ($table_exists) {
            // Construction de la requête avec QueryExpression pour les conditions complexes
            $or_condition = new QueryExpression(
                "(glpi_plugin_watchman_computer_mappings.id IS NULL " .
                    "OR glpi_computers.date_mod > COALESCE(glpi_plugin_watchman_computer_mappings.last_sync_date, '1970-01-01') " .
                    "OR (glpi_plugin_watchman_computer_mappings.sync_status = 'error' AND glpi_plugin_watchman_computer_mappings.retry_count < " . self::DEFAULT_MAX_RETRIES . "))"
            );

            $iterator = $DB->request([
                'SELECT' => [
                    'glpi_computers.*',
                    'glpi_plugin_watchman_computer_mappings.external_id',
                    'glpi_plugin_watchman_computer_mappings.last_sync_date',
                    'glpi_plugin_watchman_computer_mappings.sync_status',
                    'glpi_plugin_watchman_computer_mappings.retry_count',
                    new QueryExpression("
                        CASE 
                            WHEN glpi_plugin_watchman_computer_mappings.id IS NULL THEN 'new'
                            WHEN glpi_computers.date_mod > glpi_plugin_watchman_computer_mappings.last_sync_date THEN 'modified' 
                            WHEN glpi_plugin_watchman_computer_mappings.sync_status = 'error' AND glpi_plugin_watchman_computer_mappings.retry_count < " . self::DEFAULT_MAX_RETRIES . " THEN 'retry'
                            ELSE 'unchanged'
                        END AS sync_reason
                    ")
                ],
                'FROM' => 'glpi_computers',
                'LEFT JOIN' => [
                    'glpi_plugin_watchman_computer_mappings' => [
                        'FKEY' => [
                            'glpi_computers' => 'id',
                            'glpi_plugin_watchman_computer_mappings' => 'computers_id'
                        ]
                    ]
                ],
                'WHERE' => [
                    'glpi_computers.is_deleted' => 0,
                    'glpi_computers.is_template' => 0,
                    $or_condition
                ],
                'ORDER' => [
                    new QueryExpression("CASE glpi_plugin_watchman_computer_mappings.sync_status WHEN 'error' THEN 1 ELSE 2 END"),
                    'glpi_computers.date_mod DESC'
                ],
                'LIMIT' => $limit
            ]);
        } else {
            // Requête simple sans la table de mapping
            $iterator = $DB->request([
                'SELECT' => ['*'],
                'FROM' => 'glpi_computers',
                'WHERE' => [
                    'is_deleted' => 0,
                    'is_template' => 0
                ],
                'ORDER' => 'date_mod DESC',
                'LIMIT' => $limit
            ]);
        }

        return iterator_to_array($iterator);
    }

    /**
     * Met à jour le statut de synchronisation d'un ordinateur
     * CORRIGÉ: Gestion correcte des tables optionnelles
     */
    private static function updateComputerSyncStatus($computer_id, $status, $error_message = null)
    {
        global $DB;

        // Vérifier si la table existe
        if (!$DB->tableExists('glpi_plugin_watchman_computer_mappings')) {
            // Si la table n'existe pas, on ne fait rien ou on log
            Toolbox::logInFile(
                'watchman_sync',
                "Table glpi_plugin_watchman_computer_mappings n'existe pas - statut non enregistré"
            );
            return;
        }

        $data = [
            'computers_id' => $computer_id,
            'sync_status' => $status,
            'last_sync_date' => date('Y-m-d H:i:s'),
            'error_message' => $error_message
        ];

        // Vérifier si un enregistrement existe déjà
        $existing = $DB->request([
            'FROM' => 'glpi_plugin_watchman_computer_mappings',
            'WHERE' => ['computers_id' => $computer_id]
        ]);

        if (count($existing) > 0) {
            $current = $existing->current();

            if ($status === 'error') {
                $data['retry_count'] = ($current['retry_count'] ?? 0) + 1;
            } else {
                $data['retry_count'] = 0;
                $data['error_message'] = null;
            }

            // Update
            $DB->update(
                'glpi_plugin_watchman_computer_mappings',
                $data,
                ['id' => $current['id']]
            );
        } else {
            // Insert
            $data['retry_count'] = ($status === 'error') ? 1 : 0;
            $DB->insert('glpi_plugin_watchman_computer_mappings', $data);
        }
    }

    /**
     * Méthode publique pour synchronisation manuelle des machines
     */
    public static function manualSyncComputers($batch_size = null, $force = false)
    {

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

            // var_dump($computers);

            // Préparer le payload au format API
            $assets_payload = self::prepareAssetsPayload($computers);
            $assets_cleaned = self::cleanAssetsStrict($assets_payload);

            // Envoyer à l'API
            $response = $api_client->syncAssets($assets_cleaned);

            $processed = 0;
            $errors = 0;

            // var_dump($response);

            if ($response['success']) {
                // var_dump('success');
                $processed = count($assets_cleaned['assets']);
                // Mettre à jour le statut de tous les ordinateurs
                foreach ($assets_cleaned['assets'] as $asset_cleaned) {
                    self::updateComputerSyncStatus($asset_cleaned['computer_glpi_id'],'success');
                }
            } else {
                $errors = count($computers);
                // var_dump('errors');


                // Marquer tous comme en erreur
                // foreach ($computers as $computer_data) {
                //     self::updateComputerSyncStatus($computer_data['id'], 'error', $response['error'] ?? 'Erreur inconnue');
                // }
            }

            return [
                'success' => $processed > 0,
                'message' => sprintf(
                    __('%d ordinateurs traités, %d erreurs', 'watchman'),
                    $processed,
                    $errors
                ),
                'processed' => $processed,
                'errors' => $errors
            ];
        } catch (Exception $e) {
            Toolbox::logInFile(
                'watchman_error',
                "Erreur manualSyncComputers: " . $e->getMessage()
            );

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
        // À implémenter selon vos besoins
        return [
            'computers' => [
                'last_sync' => date('Y-m-d H:i:s'),
                'processed_today' => 0,
                'recent_errors' => 0
            ],
            'api_status' => 'healthy',
            'api_last_check' => date('Y-m-d H:i:s'),
            'can_sync' => true
        ];
    }

    /**
     * Force l'exécution d'une tâche CRON spécifique
     */
    public static function forceCronTask($task_name)
    {
        $cron = new CronTask();
        if ($cron->getFromDBbyName(__CLASS__, $task_name)) {
            return CronTask::launch(CronTask::MODE_EXTERNAL, 1, $cron->getID());
        }
        return false;
    }


    /**
     * Fonction de test simplifiée pour récupérer les applications d'un ordinateur
     */
    public static function getComputerApps($computer_id)
    {
        global $DB;


        $computer = $DB->request([
            'FROM' => 'glpi_computers',
            'WHERE' => ['id' => $computer_id]
        ]);

        if (count($computer) == 0) {
            echo "❌ Ordinateur ID $computer_id non trouvé<br>";
            return [];
        }
        $comp_data = $computer->current();
        $links = $DB->request([
            'FROM' => 'glpi_items_softwareversions',
            'WHERE' => [
                'items_id' => $computer_id,
                'itemtype' => 'Computer'
            ]
        ]);

        $link_count = count($links);

        if ($link_count == 0) {
            return [];
        }

        // 3. Vérifier les liens actifs seulement
        $active_links = $DB->request([
            'FROM' => 'glpi_items_softwareversions',
            'WHERE' => [
                'items_id' => $computer_id,
                'itemtype' => 'Computer',
                'is_deleted' => 0
            ]
        ]);

        $active_count = count($active_links);

        if ($active_count == 0) {
            return [];
        }

        // 4. Tester la récupération des applications avec une requête simple
        $applications = [];

        foreach ($active_links as $link) {
            $version_id = $link['softwareversions_id'];

            // Récupérer les infos de version
            $version = $DB->request([
                'FROM' => 'glpi_softwareversions',
                'WHERE' => ['id' => $version_id]
            ]);

            if (count($version) > 0) {
                $ver_data = $version->current();
                $software_id = $ver_data['softwares_id'];

                // Récupérer les infos du software
                $software = $DB->request([
                    'FROM' => 'glpi_softwares',
                    'WHERE' => [
                        'id' => $software_id,
                        'is_deleted' => 0,
                        'is_template' => 0
                    ]
                ]);

                if (count($software) > 0) {
                    $soft_data = $software->current();

                    // Récupérer le fabricant si disponible
                    $vendor = '';
                    if (!empty($soft_data['manufacturers_id'])) {
                        $manufacturer = $DB->request([
                            'FROM' => 'glpi_manufacturers',
                            'WHERE' => ['id' => $soft_data['manufacturers_id']]
                        ]);

                        if (count($manufacturer) > 0) {
                            $vendor = $manufacturer->current()['name'];
                        }
                    }

                    $app = [
                        'name' => $soft_data['name'],
                        'version' => $ver_data['name'] ?? '',
                        'vendor' => $vendor ?: $soft_data['name']
                    ];

                    $applications[] = $app;
                } else {
                    echo "Software ID $software_id non trouvé ou supprimé<br>";
                }
            } else {
                echo "Version ID $version_id non trouvée<br>";
            }
        }
        return $applications;
    }


    /**
     * Nettoie le payload en supprimant les machines selon différents critères
     * 
     * @param array $assets_payload Le payload original
     * @param array $options Options de nettoyage
     * @return array Le payload nettoyé avec statistiques
     */
    private static function cleanAssetsAfterSend($assets_payload, $options = [])
    {
        // Options par défaut
        $default_options = [
            'require_ip' => false,           // Exiger une IP
            'require_mac' => false,          // Exiger une MAC
            'require_ip_or_mac' => true,     // Exiger au moins IP ou MAC
            'min_applications' => 0,         // Nombre minimum d'applications
            'require_hostname' => false,     // Exiger un hostname
            'allow_empty_vendor' => true,    // Permettre vendor vide
            'log_removed' => true            // Logger les machines supprimées
        ];

        $options = array_merge($default_options, $options);

        if (!isset($assets_payload['assets']) || !is_array($assets_payload['assets'])) {
            return $assets_payload;
        }

        $cleaned_assets = [];
        $removal_stats = [
            'no_ip' => 0,
            'no_mac' => 0,
            'no_ip_nor_mac' => 0,
            'no_hostname' => 0,
            'insufficient_apps' => 0,
            'total_removed' => 0
        ];

        foreach ($assets_payload['assets'] as $asset) {
            $should_remove = false;
            $removal_reasons = [];

            $has_ip = !empty(trim($asset['ip'] ?? ''));
            $has_mac = !empty(trim($asset['mac'] ?? ''));
            $has_hostname = !empty(trim($asset['hostname'] ?? ''));
            $app_count = count($asset['applications'] ?? []);

            // Vérification IP
            if ($options['require_ip'] && !$has_ip) {
                $should_remove = true;
                $removal_reasons[] = 'no_ip';
                $removal_stats['no_ip']++;
            }

            // Vérification MAC
            if ($options['require_mac'] && !$has_mac) {
                $should_remove = true;
                $removal_reasons[] = 'no_mac';
                $removal_stats['no_mac']++;
            }

            // Vérification IP OU MAC
            if ($options['require_ip_or_mac'] && !$has_ip && !$has_mac) {
                $should_remove = true;
                $removal_reasons[] = 'no_ip_nor_mac';
                $removal_stats['no_ip_nor_mac']++;
            }

            // Vérification hostname
            if ($options['require_hostname'] && !$has_hostname) {
                $should_remove = true;
                $removal_reasons[] = 'no_hostname';
                $removal_stats['no_hostname']++;
            }

            // Vérification nombre minimum d'applications
            if ($options['min_applications'] > 0 && $app_count < $options['min_applications']) {
                $should_remove = true;
                $removal_reasons[] = 'insufficient_apps';
                $removal_stats['insufficient_apps']++;
            }

            if ($should_remove) {
                $removal_stats['total_removed']++;

                // Log de debug pour les machines supprimées
                if ($options['log_removed']) {
                    $hostname = $asset['hostname'] ?? 'N/A';
                    $ip = $asset['ip'] ?? 'N/A';
                    $mac = $asset['mac'] ?? 'N/A';

                    Toolbox::logInFile('watchman_debug', sprintf(
                        "Machine supprimée: %s (IP:%s, MAC:%s) - Raisons: %s",
                        $hostname,
                        $ip,
                        $mac,
                        implode(', ', $removal_reasons)
                    ));
                }
            } else {
                $cleaned_assets[] = $asset;
            }
        }
        // Log du résultat du nettoyage
        $original_count = count($assets_payload['assets']);
        $cleaned_count = count($cleaned_assets);

        Toolbox::logInFile('watchman_debug', sprintf(
            "Nettoyage des assets: %d originaux -> %d nettoyés (%d supprimés)",
            $original_count,
            $cleaned_count,
            $removal_stats['total_removed']
        ));

        // Log détaillé des statistiques
        if ($removal_stats['total_removed'] > 0) {
            $stats_details = [];
            foreach ($removal_stats as $reason => $count) {
                if ($count > 0 && $reason !== 'total_removed') {
                    $stats_details[] = "$reason: $count";
                }
            }

            if (!empty($stats_details)) {
                Toolbox::logInFile(
                    'watchman_debug',
                    "Détails suppressions: " . implode(', ', $stats_details)
                );
            }
        }

        return [
            'assets' => $cleaned_assets,
            'removal_stats' => $removal_stats,
            'original_count' => $original_count,
            'cleaned_count' => $cleaned_count
        ];
    }

    /**
     * Version simplifiée pour supprimer seulement les machines sans IP ni MAC
     */
    private static function cleanAssetsSimple($assets_payload)
    {
        return self::cleanAssetsAfterSend($assets_payload, [
            'require_ip_or_mac' => true,
            'log_removed' => true
        ]);
    }

    /**
     * Version stricte qui exige IP ET MAC
     */
    private static function cleanAssetsStrict($assets_payload)
    {
        return self::cleanAssetsAfterSend($assets_payload, [
            'require_ip' => true,
            'require_mac' => true,
            'require_hostname' => true,
            'min_applications' => 1,
            'log_removed' => true
        ]);
    }
}
