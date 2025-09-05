<?php

namespace GlpiPlugin\Watchman;

use CommonDBTM;
use CommonITILObject;
use DBConnection;
use Exception;
use Session;
use Toolbox;
use NotificationEvent;
use QueuedNotification;
use Glpi\Application\View\TemplateRenderer;
use Html;
use Ticket;

/**
 * Gestionnaire des alertes reçues depuis l'API
 */
class AlertManager extends CommonDBTM
{
    static $rightname = 'plugin_watchman_alert';


    /**
     * Installation des tables nécessaires
     */

    static function getTypeName($nb = 0)
    {
        return _n('Alerte', 'Alertes', $nb);
    }



    function showAlerts($options = [])
    {
        $alerts = $this->getAlerts();
        // @myplugin is a shortcut to the **templates** directory of your plugin
        TemplateRenderer::getInstance()->display('@watchman/alerts.html.twig', [
            'item'   => $this,
            'params' => $options,
            'csrf_token' => \Session::getNewCSRFToken(),
            'alerts' => $alerts
        ]);

        return true;
    }
    static function getMenuName($nb = 0)
    {
        // call class label
        return self::getTypeName($nb);
    }

    /**
     * Define additionnal links used in breacrumbs and sub-menu
     *
     * A default implementation is provided by CommonDBTM
     */
    static function getMenuContent()
    {
        $title  = self::getMenuName(Session::getPluralNumber());

        $search = self::getSearchURL(false);

        // define base menu
        $menu = [
            'title' => __("Alertes", 'watchman'),
            'page'  => $search,
        ];

        return $menu;
    }


    public static function startCron()
    {
        // CronSyncComputer::manualSyncComputers();
        CronSyncAlert::manualSyncAlerts();

        return true;
    }


    /**
     * Récupère les alertes avec leurs informations (version compatible GLPI)
     * 
     * @param array $options Options de filtrage
     * @return array Tableau des alertes enrichies
     */
    public function getAlerts($options = [])
    {
        global $DB;

        // Options par défaut
        $default_options = [
            'limit' => 100,
            'start' => 0,
            'severity' => null,
            'patched' => null,
            'search' => null,
            'order' => 'date_creation',
            'sort' => 'DESC'
        ];

        $options = array_merge($default_options, $options);

        // Requête principale pour les alertes
        $where_conditions = ['is_deleted' => 0];

        // Filtres
        if ($options['severity']) {
            $where_conditions['severity'] = $options['severity'];
        }

        if ($options['patched'] !== null) {
            $where_conditions['patched'] = (int)$options['patched'];
        }

        // Recherche textuelle
        if ($options['search']) {
            $search_term = '%' . $options['search'] . '%';
            $where_conditions[] = [
                'OR' => [
                    'title' => ['LIKE', $search_term],
                    'description' => ['LIKE', $search_term],
                    'cves_id' => ['LIKE', $search_term]
                ]
            ];
        }

        // Ordre
        $order = 'date_creation DESC';
        if (in_array($options['order'], ['date_creation', 'score', 'severity', 'title'])) {
            $sort_dir = strtoupper($options['sort']) === 'ASC' ? 'ASC' : 'DESC';
            $order = $options['order'] . ' ' . $sort_dir;

            if ($options['order'] !== 'date_creation') {
                $order .= ', date_creation DESC';
            }
        }

        // Requête des alertes
        $alert_iterator = $DB->request([
            'FROM' => 'glpi_plugin_watchman_alerts',
            'WHERE' => $where_conditions,
            'ORDER' => $order,
            'LIMIT' => $options['limit'],
            'START' => $options['start']
        ]);

        $alerts = [];
        foreach ($alert_iterator as $alert) {
            // Enrichissement avec les données liées
            $alert = $this->enrichAlertWithRelatedData($alert);
            $alerts[] = $alert;
        }

        return $alerts;
    }

    /**
     * Enrichit une alerte avec les données des tables liées
     * 
     * @param array $alert Données de base de l'alerte
     * @return array Alerte enrichie
     */
    private function enrichAlertWithRelatedData($alert)
    {
        global $DB;

        // Récupération des données CVE
        if ($alert['cves_id']) {
            $cve_iterator = $DB->request([
                'FROM' => 'glpi_plugin_watchman_cves',
                'WHERE' => [
                    'id' => $alert['cves_id'],
                    'is_deleted' => 0
                ]
            ]);

            if (count($cve_iterator)) {
                $cve_data = $cve_iterator->current();
                $alert['cve_assigner'] = $cve_data['assigner'];
                $alert['cve_published_at'] = $cve_data['published_at'];
                $alert['cve_modified_at'] = $cve_data['modified_at'];
            }
        }

        // Récupération des données Stack
        if ($alert['stacks_id']) {
            $stack_iterator = $DB->request([
                'FROM' => 'glpi_plugin_watchman_stacks',
                'WHERE' => [
                    'id' => $alert['stacks_id'],
                    'is_deleted' => 0
                ]
            ]);

            if (count($stack_iterator)) {
                $stack_data = $stack_iterator->current();
                $alert['stack_name'] = $stack_data['name'];
                $alert['stack_version'] = $stack_data['version'];
                $alert['stack_type'] = $stack_data['type'];
            }
        }

        // Récupération des données d'impact CVE
        if ($alert['cves_id']) {
            $impact_iterator = $DB->request([
                'FROM' => 'glpi_plugin_watchman_cve_impacts',
                'WHERE' => [
                    'cves_id' => $alert['cves_id'],
                    'is_deleted' => 0
                ]
            ]);

            if (count($impact_iterator)) {
                $impact_data = $impact_iterator->current();
                $alert['impact_base_score'] = $impact_data['base_score'];
                $alert['impact_base_severity'] = $impact_data['base_severity'];
                $alert['attack_vector'] = $impact_data['attack_vector'];
                $alert['attack_complexity'] = $impact_data['attack_complexity'];
                $alert['exploitability_score'] = $impact_data['exploitability_score'];
                $alert['impact_score'] = $impact_data['impact_score'];
            }
        }

        // Récupération des données de ticket
        if ($alert['tickets_id']) {
            $ticket_iterator = $DB->request([
                'FROM' => 'glpi_tickets',
                'WHERE' => [
                    'id' => $alert['tickets_id']
                ]
            ]);

            if (count($ticket_iterator)) {
                $ticket_data = $ticket_iterator->current();
                $alert['ticket_name'] = $ticket_data['name'];
                $alert['ticket_status'] = $ticket_data['status'];
            }
        }

        // Enrichissement des données formatées
        return $this->formatAlertData($alert);
    }

    /**
     * Formate les données d'une alerte pour l'affichage
     * 
     * @param array $alert Données brutes de l'alerte
     * @return array Données formatées
     */
    private function formatAlertData($alert)
    {
        // Score formaté avec niveau
        if ($alert['score']) {
            $alert['score_formatted'] = number_format((float)$alert['score'], 1);
            $alert['score_level'] = $this->getScoreLevel($alert['score']);
            $alert['score_class'] = $this->getScoreClass($alert['score']);
        }

        // Sévérité traduite
        $alert['severity_translated'] = $this->translateSeverity($alert['severity']);
        $alert['severity_class'] = $this->getSeverityClass($alert['severity']);

        // Statut de correction
        $alert['patched_status'] = [
            'is_patched' => (bool)$alert['patched'],
            'label' => $alert['patched'] ? __('Corrigée', 'watchman') : __('En attente', 'watchman'),
            'class' => $alert['patched'] ? 'success' : 'warning',
            'icon' => $alert['patched'] ? 'fa-check' : 'fa-exclamation'
        ];

        // Dates formatées
        if ($alert['date_creation']) {
            $alert['date_creation_formatted'] = Html::convDateTime($alert['date_creation']);
            $alert['date_creation_relative'] = $this->getRelativeTime($alert['date_creation']);
        }

        if ($alert['patched_at']) {
            $alert['patched_at_formatted'] = Html::convDateTime($alert['patched_at']);
        }

        // Indicateurs de notification
        $alert['notifications'] = [
            'integration' => (bool)$alert['integration_notified'],
            'email' => (bool)$alert['email_notified'],
            'reported' => (bool)$alert['reported']
        ];

        // Informations consolidées sur la stack
        $alert['stack_info'] = [
            'name' => $alert['stack_name'] ?? __('Inconnu', 'watchman'),
            'version' => $alert['stack_version'] ?? '',
            'type' => $alert['stack_type'] ?? 'app_server',
            'display_name' => ($alert['stack_name'] ?? __('Inconnu', 'watchman')) .
                ($alert['stack_version'] ? ' v' . $alert['stack_version'] : '')
        ];

        // Informations CVE
        $alert['cve_info'] = [
            'id' => $alert['cves_id'],
            'assigner' => $alert['cve_assigner'] ?? '',
            'published_at' => isset($alert['cve_published_at']) ? $alert['cve_published_at'] : null,
            'age_days' => (isset($alert['cve_published_at']) ? $alert['cve_published_at'] : null) ?
                round((time() - strtotime($alert['cve_published_at'])) / 86400) : null
        ];

        // Informations ticket
        if ($alert['tickets_id']) {
            $alert['ticket_info'] = [
                'id' => $alert['tickets_id'],
                'name' => $alert['ticket_name'] ?? '',
                'status' => $alert['ticket_status'] ?? 1,
                'url' => $GLOBALS['CFG_GLPI']['root_doc'] . '/front/ticket.form.php?id=' . $alert['tickets_id']
            ];
        }

        return $alert;
    }

    /**
     * Compte le nombre total d'alertes selon les filtres
     * 
     * @param array $filters Filtres à appliquer
     * @return int Nombre total
     */
    public function countAlerts($filters = [])
    {
        global $DB;

        $where_conditions = ['is_deleted' => 0];

        if ($filters['severity'] ?? false) {
            $where_conditions['severity'] = $filters['severity'];
        }

        if (isset($filters['patched'])) {
            $where_conditions['patched'] = (int)$filters['patched'];
        }

        if ($filters['search'] ?? false) {
            $search_term = '%' . $filters['search'] . '%';
            $where_conditions[] = [
                'OR' => [
                    'title' => ['LIKE', $search_term],
                    'description' => ['LIKE', $search_term],
                    'cves_id' => ['LIKE', $search_term]
                ]
            ];
        }

        $result = $DB->request([
            'SELECT' => ['id', 'COUNT' => '* AS total'],
            'FROM' => 'glpi_plugin_watchman_alerts',
            'WHERE' => $where_conditions
        ]);

        return $result->current()['total'] ?? 0;
    }

    /**
     * Obtient les statistiques des alertes
     * 
     * @return array Statistiques
     */
    public function getAlertsStats()
    {
        global $DB;

        $stats = [
            'total' => 0,
            'critical' => 0,
            'high' => 0,
            'medium' => 0,
            'low' => 0,
            'unpatched' => 0,
            'patched' => 0
        ];

        // Comptage par sévérité - Correction: COUNT(*) au lieu de COUNT(total)
        $severity_result = $DB->request([
            'SELECT' => ['severity', 'COUNT' => '* AS total'], // Modification ici
            'FROM' => 'glpi_plugin_watchman_alerts',
            'WHERE' => ['is_deleted' => 0],
            'GROUPBY' => 'severity'
        ]);

        foreach ($severity_result as $row) {
            $severity = strtolower($row['severity'] ?? '');
            if (isset($stats[$severity])) {
                $stats[$severity] = $row['total'];
            }
            $stats['total'] += $row['total'];
        }

        // Comptage par statut de correction - Correction: COUNT(*) au lieu de COUNT(total)
        $patched_result = $DB->request([
            'SELECT' => ['patched', 'COUNT' => '* AS total'], // Modification ici
            'FROM' => 'glpi_plugin_watchman_alerts',
            'WHERE' => ['is_deleted' => 0],
            'GROUPBY' => 'patched'
        ]);

        foreach ($patched_result as $row) {
            if ($row['patched']) {
                $stats['patched'] = $row['total'];
            } else {
                $stats['unpatched'] = $row['total'];
            }
        }

        return $stats;
    }

    // Méthodes utilitaires (identiques à la version précédente)
    private function getScoreLevel($score)
    {
        if ($score >= 9.0) return 'CRITICAL';
        if ($score >= 7.0) return 'HIGH';
        if ($score >= 4.0) return 'MEDIUM';
        return 'LOW';
    }

    private function getScoreClass($score)
    {
        if ($score >= 9.0) return 'danger';
        if ($score >= 7.0) return 'warning';
        if ($score >= 4.0) return 'info';
        return 'success';
    }

    private function translateSeverity($severity)
    {
        $translations = [
            'CRITICAL' => __('Critique', 'watchman'),
            'HIGH' => __('Élevée', 'watchman'),
            'MEDIUM' => __('Moyenne', 'watchman'),
            'LOW' => __('Faible', 'watchman')
        ];

        return $translations[$severity] ?? $severity;
    }

    private function getSeverityClass($severity)
    {
        $classes = [
            'CRITICAL' => 'danger',
            'HIGH' => 'warning',
            'MEDIUM' => 'info',
            'LOW' => 'success'
        ];

        return $classes[$severity] ?? 'secondary';
    }

    private function getRelativeTime($datetime)
    {
        $time = strtotime($datetime);
        $diff = time() - $time;

        if ($diff < 3600) {
            $minutes = floor($diff / 60);
            return sprintf(__('Il y a %d minute(s)', 'watchman'), max(1, $minutes));
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return sprintf(__('Il y a %d heure(s)', 'watchman'), $hours);
        } else {
            $days = floor($diff / 86400);
            return sprintf(__('Il y a %d jour(s)', 'watchman'), $days);
        }
    }
    /**
     * Installation des tables nécessaires
     */
    public static function install($migration, $version)
    {
        global $DB;

        $default_charset = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign = DBConnection::getDefaultPrimaryKeySignOption();

        // Table des CVE
        $cves_table = 'glpi_plugin_watchman_cves';
        if (!$DB->tableExists($cves_table)) {
            $query = "CREATE TABLE `$cves_table` (
                `id` varchar(30) NOT NULL,
                `assigner` varchar(255) NOT NULL,
                `published_at` timestamp NULL,
                `modified_at` timestamp NULL,
                `date_creation` timestamp DEFAULT CURRENT_TIMESTAMP,
                `date_mod` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `is_deleted` tinyint(1) DEFAULT 0,
                
                PRIMARY KEY (`id`),
                KEY `assigner` (`assigner`),
                KEY `published_at` (`published_at`),
                KEY `date_creation` (`date_creation`),
                KEY `is_deleted` (`is_deleted`)
                
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";

            $DB->doQuery($query) or die("Erreur création table $cves_table");
        }

        // Table des Stacks
        $stacks_table = 'glpi_plugin_watchman_stacks';
        if (!$DB->tableExists($stacks_table)) {
            $query = "CREATE TABLE `$stacks_table` (
                `id` char(36) NOT NULL,
                `name` varchar(150) NOT NULL,
                `type` varchar(10) DEFAULT 'app_server',
                `version` varchar(100) NOT NULL,
                `has_vulnerability` tinyint(1) DEFAULT 0,
                `added_by_agent` tinyint(1) DEFAULT 0,
                `date_creation` timestamp DEFAULT CURRENT_TIMESTAMP,
                `date_mod` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `is_deleted` tinyint(1) DEFAULT 0,
                
                PRIMARY KEY (`id`),
                KEY `name` (`name`),
                KEY `type` (`type`),
                KEY `has_vulnerability` (`has_vulnerability`),
                KEY `date_creation` (`date_creation`),
                KEY `is_deleted` (`is_deleted`)
                
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";

            $DB->doQuery($query) or die("Erreur création table $stacks_table");
        }

        // Table des Alertes
        $alerts_table = 'glpi_plugin_watchman_alerts';
        if (!$DB->tableExists($alerts_table)) {
            $query = "CREATE TABLE `$alerts_table` (
                `id` char(36) NOT NULL,
                `cve_hash` varchar(32),
                `stacks_id` char(36) NOT NULL,
                `cves_id` varchar(30) NOT NULL,
                `tickets_id` int {$default_key_sign} DEFAULT NULL,
                `title` varchar(255) NOT NULL,
                `description` text NOT NULL,
                `score` decimal(3,1) DEFAULT NULL,
                `severity` varchar(10) DEFAULT NULL,
                `patched` tinyint(1) DEFAULT 0,
                `possible_false` tinyint(1) DEFAULT 0,
                `integration_notified` tinyint(1) DEFAULT 0,
                `email_notified` tinyint(1) DEFAULT 0,
                `reported` tinyint(1) DEFAULT 0,
                `patched_at` timestamp NULL,
                `date_creation` timestamp DEFAULT CURRENT_TIMESTAMP,
                `date_mod` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `is_deleted` tinyint(1) DEFAULT 0,
                
                PRIMARY KEY (`id`),
                KEY `stacks_id` (`stacks_id`),
                KEY `cves_id` (`cves_id`),
                KEY `tickets_id` (`tickets_id`),
                KEY `score` (`score`),
                KEY `severity` (`severity`),
                KEY `patched` (`patched`),
                KEY `date_creation` (`date_creation`),
                KEY `is_deleted` (`is_deleted`)
                
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";

            $DB->doQuery($query) or die("Erreur création table $alerts_table");
        }

        // Table des impacts CVE
        $impacts_table = 'glpi_plugin_watchman_cve_impacts';
        if (!$DB->tableExists($impacts_table)) {
            $query = "CREATE TABLE `$impacts_table` (
                `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `cves_id` varchar(30) NOT NULL,
                `exploitability_score` decimal(3,1) DEFAULT NULL,
                `impact_score` decimal(3,1) DEFAULT NULL,
                `attack_vector` varchar(160) NOT NULL,
                `attack_complexity` varchar(160) NOT NULL,
                `privileges_required` varchar(160) NOT NULL,
                `user_interaction` varchar(160) NOT NULL,
                `confidentiality_impact` varchar(160) NOT NULL,
                `availability_impact` varchar(160) NOT NULL,
                `integrity_impact` varchar(160) NOT NULL,
                `base_severity` varchar(160) NOT NULL,
                `base_score` varchar(160) NOT NULL,
                `date_creation` timestamp DEFAULT CURRENT_TIMESTAMP,
                `date_mod` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `is_deleted` tinyint(1) DEFAULT 0,
                
                PRIMARY KEY (`id`),
                UNIQUE KEY `cves_id` (`cves_id`),
                KEY `base_severity` (`base_severity`),
                KEY `base_score` (`base_score`),
                KEY `date_creation` (`date_creation`),
                KEY `is_deleted` (`is_deleted`)
                
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";

            $DB->doQuery($query) or die("Erreur création table $impacts_table");
        }

        // Table des CPE
        $cpes_table = 'glpi_plugin_watchman_cpes';
        if (!$DB->tableExists($cpes_table)) {
            $query = "CREATE TABLE `$cpes_table` (
                `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `cve_hash` varchar(32) UNIQUE,
                `cves_id` varchar(30) NOT NULL,
                `vulnerable` tinyint(1) DEFAULT 0,
                `name` varchar(256) DEFAULT NULL,
                `version` varchar(100) DEFAULT NULL,
                `vendor` varchar(256) DEFAULT NULL,
                `hardware` tinyint(1) DEFAULT 0,
                `application` tinyint(1) DEFAULT 0,
                `system` tinyint(1) DEFAULT 0,
                `vulnerable_from` varchar(100) DEFAULT NULL,
                `vulnerable_to` varchar(100) DEFAULT NULL,
                `invulnerable_from` varchar(100) DEFAULT NULL,
                `invulnerable_to` varchar(100) DEFAULT NULL,
                `date_creation` timestamp DEFAULT CURRENT_TIMESTAMP,
                `date_mod` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `is_deleted` tinyint(1) DEFAULT 0,
                
                PRIMARY KEY (`id`),
                KEY `cves_id` (`cves_id`),
                KEY `vulnerable` (`vulnerable`),
                KEY `name` (`name`),
                KEY `vendor` (`vendor`),
                KEY `date_creation` (`date_creation`),
                KEY `is_deleted` (`is_deleted`)
                
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";

            $DB->doQuery($query) or die("Erreur création table $cpes_table");
        }

        // Table des références CVE
        $references_table = 'glpi_plugin_watchman_cve_references';
        if (!$DB->tableExists($references_table)) {
            $query = "CREATE TABLE `$references_table` (
                `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `cves_id` varchar(30) NOT NULL,
                `url` varchar(900) NOT NULL,
                `name` varchar(900) NOT NULL,
                `date_creation` timestamp DEFAULT CURRENT_TIMESTAMP,
                `date_mod` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `is_deleted` tinyint(1) DEFAULT 0,
                
                PRIMARY KEY (`id`),
                KEY `cves_id` (`cves_id`),
                KEY `name` (`name`(255)),
                KEY `date_creation` (`date_creation`),
                KEY `is_deleted` (`is_deleted`)
                
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";

            $DB->doQuery($query) or die("Erreur création table $references_table");
        }

        // Table des logs d'activité des alertes
        $logs_table = 'glpi_plugin_watchman_alert_logs';
        if (!$DB->tableExists($logs_table)) {
            $query = "CREATE TABLE `$logs_table` (
                `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `alerts_id` char(36) NOT NULL,
                `action` varchar(50) NOT NULL,
                `message` text,
                `users_id` int {$default_key_sign} DEFAULT NULL,
                `date_creation` timestamp DEFAULT CURRENT_TIMESTAMP,
                
                PRIMARY KEY (`id`),
                KEY `alerts_id` (`alerts_id`),
                KEY `action` (`action`),
                KEY `users_id` (`users_id`),
                KEY `date_creation` (`date_creation`)
                
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";

            $DB->doQuery($query) or die("Erreur création table $logs_table");
        }

        // Ajout des contraintes de clés étrangères après création de toutes les tables
        // Note: Les contraintes FK sont ajoutées après pour éviter les problèmes de dépendances

        // FK pour alerts -> stacks
        if ($DB->tableExists($alerts_table) && $DB->tableExists($stacks_table)) {
            $fk_exists = $DB->query("SELECT * FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                                   WHERE TABLE_NAME = '$alerts_table' 
                                   AND COLUMN_NAME = 'stacks_id' 
                                   AND CONSTRAINT_NAME LIKE 'FK_%'");
            if (!$fk_exists || $fk_exists->num_rows == 0) {
                $DB->doQuery("ALTER TABLE `$alerts_table` 
                            ADD CONSTRAINT `FK_alerts_stacks` 
                            FOREIGN KEY (`stacks_id`) REFERENCES `$stacks_table` (`id`) ON DELETE CASCADE");
            }
        }

        // FK pour alerts -> cves
        if ($DB->tableExists($alerts_table) && $DB->tableExists($cves_table)) {
            $fk_exists = $DB->query("SELECT * FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                                   WHERE TABLE_NAME = '$alerts_table' 
                                   AND COLUMN_NAME = 'cves_id' 
                                   AND CONSTRAINT_NAME LIKE 'FK_%'");
            if (!$fk_exists || $fk_exists->num_rows == 0) {
                $DB->doQuery("ALTER TABLE `$alerts_table` 
                            ADD CONSTRAINT `FK_alerts_cves` 
                            FOREIGN KEY (`cves_id`) REFERENCES `$cves_table` (`id`) ON DELETE CASCADE");
            }
        }

        // FK pour alerts -> tickets (si table tickets existe)
        if ($DB->tableExists($alerts_table) && $DB->tableExists('glpi_tickets')) {
            $fk_exists = $DB->query("SELECT * FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                                   WHERE TABLE_NAME = '$alerts_table' 
                                   AND COLUMN_NAME = 'tickets_id' 
                                   AND CONSTRAINT_NAME LIKE 'FK_%'");
            if (!$fk_exists || $fk_exists->num_rows == 0) {
                $DB->doQuery("ALTER TABLE `$alerts_table` 
                            ADD CONSTRAINT `FK_alerts_tickets` 
                            FOREIGN KEY (`tickets_id`) REFERENCES `glpi_tickets` (`id`) ON DELETE SET NULL");
            }
        }

        // FK pour cve_impacts -> cves
        if ($DB->tableExists($impacts_table) && $DB->tableExists($cves_table)) {
            $fk_exists = $DB->query("SELECT * FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                                   WHERE TABLE_NAME = '$impacts_table' 
                                   AND COLUMN_NAME = 'cves_id' 
                                   AND CONSTRAINT_NAME LIKE 'FK_%'");
            if (!$fk_exists || $fk_exists->num_rows == 0) {
                $DB->doQuery("ALTER TABLE `$impacts_table` 
                            ADD CONSTRAINT `FK_impacts_cves` 
                            FOREIGN KEY (`cves_id`) REFERENCES `$cves_table` (`id`) ON DELETE CASCADE");
            }
        }

        // FK pour cpes -> cves
        if ($DB->tableExists($cpes_table) && $DB->tableExists($cves_table)) {
            $fk_exists = $DB->query("SELECT * FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                                   WHERE TABLE_NAME = '$cpes_table' 
                                   AND COLUMN_NAME = 'cves_id' 
                                   AND CONSTRAINT_NAME LIKE 'FK_%'");
            if (!$fk_exists || $fk_exists->num_rows == 0) {
                $DB->doQuery("ALTER TABLE `$cpes_table` 
                            ADD CONSTRAINT `FK_cpes_cves` 
                            FOREIGN KEY (`cves_id`) REFERENCES `$cves_table` (`id`) ON DELETE CASCADE");
            }
        }

        // FK pour references -> cves
        if ($DB->tableExists($references_table) && $DB->tableExists($cves_table)) {
            $fk_exists = $DB->query("SELECT * FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                                   WHERE TABLE_NAME = '$references_table' 
                                   AND COLUMN_NAME = 'cves_id' 
                                   AND CONSTRAINT_NAME LIKE 'FK_%'");
            if (!$fk_exists || $fk_exists->num_rows == 0) {
                $DB->doQuery("ALTER TABLE `$references_table` 
                            ADD CONSTRAINT `FK_references_cves` 
                            FOREIGN KEY (`cves_id`) REFERENCES `$cves_table` (`id`) ON DELETE CASCADE");
            }
        }

        // FK pour logs -> alerts
        if ($DB->tableExists($logs_table) && $DB->tableExists($alerts_table)) {
            $fk_exists = $DB->query("SELECT * FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                                   WHERE TABLE_NAME = '$logs_table' 
                                   AND COLUMN_NAME = 'alerts_id' 
                                   AND CONSTRAINT_NAME LIKE 'FK_%'");
            if (!$fk_exists || $fk_exists->num_rows == 0) {
                $DB->doQuery("ALTER TABLE `$logs_table` 
                            ADD CONSTRAINT `FK_logs_alerts` 
                            FOREIGN KEY (`alerts_id`) REFERENCES `$alerts_table` (`id`) ON DELETE CASCADE");
            }
        }

        // FK pour logs -> users (si table users existe)
        if ($DB->tableExists($logs_table) && $DB->tableExists('glpi_users')) {
            $fk_exists = $DB->query("SELECT * FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                                   WHERE TABLE_NAME = '$logs_table' 
                                   AND COLUMN_NAME = 'users_id' 
                                   AND CONSTRAINT_NAME LIKE 'FK_%'");
            if (!$fk_exists || $fk_exists->num_rows == 0) {
                $DB->doQuery("ALTER TABLE `$logs_table` 
                            ADD CONSTRAINT `FK_logs_users` 
                            FOREIGN KEY (`users_id`) REFERENCES `glpi_users` (`id`) ON DELETE SET NULL");
            }
        }

        return true;
    }

    /**
     * Affiche le détail d'une alerte
     */
    public function showDetail($id, $options = [])
    {
        $alert = $this->getAlertById($id);

        if (!$alert) {
            echo "<div class='alert alert-danger'>";
            echo __('Alerte non trouvée', 'watchman');
            echo "</div>";
            return false;
        }

        // Enrichissement des données
        $alert = $this->enrichAlertWithRelatedData($alert);
        $alert = $this->formatAlertData($alert);

        // Récupération des données additionnelles
        $cve_details = $this->getCVEDetails($alert['cves_id']);
        $stack_details = $this->getStackDetails($alert['stacks_id']);
        $alert_logs = $this->getAlertLogs($id);
        $related_alerts = $this->getRelatedAlerts($alert['cves_id'], $id);

        TemplateRenderer::getInstance()->display('@watchman/alert_detail.html.twig', [
            'item' => $this,
            'alert' => $alert,
            'cve_details' => $cve_details,
            'stack_details' => $stack_details,
            'alert_logs' => $alert_logs,
            'related_alerts' => $related_alerts,
            'params' => $options,
            'csrf_token' => Session::getNewCSRFToken()
        ]);

        return true;
    }

    /**
     * Récupère une alerte par son ID
     */
    public function getAlertById($id)
    {
        global $DB;

        $iterator = $DB->request([
            'FROM' => 'glpi_plugin_watchman_alerts',
            'WHERE' => [
                'id' => $id,
                'is_deleted' => 0
            ]
        ]);

        if (count($iterator)) {
            return $iterator->current();
        }

        return false;
    }

    /**
     * Récupère les détails d'une CVE
     */
    private function getCVEDetails($cve_id)
    {
        global $DB;

        $cve_data = [];

        // Données CVE de base
        $cve_iterator = $DB->request([
            'FROM' => 'glpi_plugin_watchman_cves',
            'WHERE' => [
                'id' => $cve_id,
                'is_deleted' => 0
            ]
        ]);

        if (count($cve_iterator)) {
            $cve_data = $cve_iterator->current();
        }

        // Références CVE
        $references_iterator = $DB->request([
            'FROM' => 'glpi_plugin_watchman_cve_references',
            'WHERE' => [
                'cves_id' => $cve_id,
                'is_deleted' => 0
            ],
            'ORDER' => 'name ASC'
        ]);

        $cve_data['references'] = [];
        foreach ($references_iterator as $ref) {
            $cve_data['references'][] = $ref;
        }

        // CPE affectés
        $cpes_iterator = $DB->request([
            'FROM' => 'glpi_plugin_watchman_cpes',
            'WHERE' => [
                'cves_id' => $cve_id,
                'vulnerable' => 1,
                'is_deleted' => 0
            ],
            'ORDER' => 'vendor ASC, name ASC'
        ]);

        $cve_data['affected_products'] = [];
        foreach ($cpes_iterator as $cpe) {
            $cve_data['affected_products'][] = $cpe;
        }

        return $cve_data;
    }

    /**
     * Récupère les détails d'une stack
     */
    private function getStackDetails($stack_id)
    {
        global $DB;

        $iterator = $DB->request([
            'FROM' => 'glpi_plugin_watchman_stacks',
            'WHERE' => [
                'id' => $stack_id,
                'is_deleted' => 0
            ]
        ]);

        if (count($iterator)) {
            return $iterator->current();
        }

        return [];
    }

    /**
     * Récupère l'historique des actions sur une alerte
     */
    private function getAlertLogs($alert_id)
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT' => [
                'glpi_plugin_watchman_alert_logs.*',
                'glpi_users.firstname',
                'glpi_users.realname'
            ],
            'FROM' => 'glpi_plugin_watchman_alert_logs',
            'LEFT JOIN' => [
                'glpi_users' => [
                    'ON' => [
                        'glpi_plugin_watchman_alert_logs' => 'users_id',
                        'glpi_users' => 'id'
                    ]
                ]
            ],
            'WHERE' => [
                'glpi_plugin_watchman_alert_logs.alerts_id' => $alert_id
            ],
            'ORDER' => 'glpi_plugin_watchman_alert_logs.date_creation DESC'
        ]);

        $logs = [];
        foreach ($iterator as $log) {
            $log['user_name'] = '';
            if ($log['firstname'] && $log['realname']) {
                $log['user_name'] = $log['firstname'] . ' ' . $log['realname'];
            } elseif ($log['users_id']) {
                $log['user_name'] = __('Utilisateur', 'watchman') . ' #' . $log['users_id'];
            } else {
                $log['user_name'] = __('Système', 'watchman');
            }

            $log['date_formatted'] = Html::convDateTime($log['date_creation']);
            $logs[] = $log;
        }

        return $logs;
    }

    /**
     * Récupère les alertes liées à la même CVE
     */
    private function getRelatedAlerts($cve_id, $current_alert_id)
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT' => [
                'glpi_plugin_watchman_alerts.*',
                'glpi_plugin_watchman_stacks.name as stack_name',
                'glpi_plugin_watchman_stacks.version as stack_version'
            ],
            'FROM' => 'glpi_plugin_watchman_alerts',
            'LEFT JOIN' => [
                'glpi_plugin_watchman_stacks' => [
                    'ON' => [
                        'glpi_plugin_watchman_alerts' => 'stacks_id',
                        'glpi_plugin_watchman_stacks' => 'id'
                    ]
                ]
            ],
            'WHERE' => [
                'glpi_plugin_watchman_alerts.cves_id' => $cve_id,
                'glpi_plugin_watchman_alerts.id' => ['!=', $current_alert_id],
                'glpi_plugin_watchman_alerts.is_deleted' => 0
            ],
            'ORDER' => 'glpi_plugin_watchman_alerts.date_creation DESC',
            'LIMIT' => 10
        ]);

        $alerts = [];
        foreach ($iterator as $alert) {
            $alert = $this->formatAlertData($alert);
            $alerts[] = $alert;
        }

        return $alerts;
    }

    /**
     * Marque une alerte comme corrigée
     */
    public function markAsPatched($alert_id, $user_id = 0)
    {
        global $DB;

        $result = $DB->update(
            'glpi_plugin_watchman_alerts',
            [
                'patched' => 1,
                'patched_at' => date('Y-m-d H:i:s'),
                'date_mod' => date('Y-m-d H:i:s')
            ],
            [
                'id' => $alert_id
            ]
        );

        if ($result) {
            // $this->addLog($alert_id, 'marked_patched', __('Alerte marquée comme corrigée', 'watchman'), $user_id);
        }

        return $result;
    }

    /**
     * Ajoute une entrée dans le log des alertes
     */
    private function addLog($alert_id, $action, $message, $user_id = 0)
    {
        global $DB;

        $DB->insert('glpi_plugin_watchman_alert_logs', [
            'alerts_id' => $alert_id,
            'action' => $action,
            'message' => $message,
            'users_id' => $user_id ?: Session::getLoginUserID(),
            'date_creation' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Recherche avancée dans les alertes
     */
    public function searchAlerts($search_params)
    {
        global $DB;

        $where_conditions = ['glpi_plugin_watchman_alerts.is_deleted' => 0];
        $joins = [];

        // Filtres de base
        if (!empty($search_params['severity'])) {
            $where_conditions['glpi_plugin_watchman_alerts.severity'] = $search_params['severity'];
        }

        if (isset($search_params['patched']) && $search_params['patched'] !== '') {
            $where_conditions['glpi_plugin_watchman_alerts.patched'] = (int)$search_params['patched'];
        }

        // Recherche par score
        if (!empty($search_params['score_min'])) {
            $where_conditions[] = ['glpi_plugin_watchman_alerts.score' => ['>=', (float)$search_params['score_min']]];
        }

        if (!empty($search_params['score_max'])) {
            $where_conditions[] = ['glpi_plugin_watchman_alerts.score' => ['<=', (float)$search_params['score_max']]];
        }

        // Recherche par dates
        if (!empty($search_params['date_from'])) {
            $where_conditions[] = ['glpi_plugin_watchman_alerts.date_creation' => ['>=', $search_params['date_from'] . ' 00:00:00']];
        }

        if (!empty($search_params['date_to'])) {
            $where_conditions[] = ['glpi_plugin_watchman_alerts.date_creation' => ['<=', $search_params['date_to'] . ' 23:59:59']];
        }

        // Recherche textuelle avancée
        if (!empty($search_params['search'])) {
            $search_term = '%' . $search_params['search'] . '%';

            // Ajout des jointures pour la recherche dans les tables liées
            $joins['glpi_plugin_watchman_stacks'] = [
                'ON' => [
                    'glpi_plugin_watchman_alerts' => 'stacks_id',
                    'glpi_plugin_watchman_stacks' => 'id'
                ]
            ];

            $where_conditions[] = [
                'OR' => [
                    'glpi_plugin_watchman_alerts.title' => ['LIKE', $search_term],
                    'glpi_plugin_watchman_alerts.description' => ['LIKE', $search_term],
                    'glpi_plugin_watchman_alerts.cves_id' => ['LIKE', $search_term],
                    'glpi_plugin_watchman_stacks.name' => ['LIKE', $search_term],
                    'glpi_plugin_watchman_stacks.version' => ['LIKE', $search_term]
                ]
            ];
        }

        // Recherche par type de stack
        if (!empty($search_params['stack_type'])) {
            if (empty($joins['glpi_plugin_watchman_stacks'])) {
                $joins['glpi_plugin_watchman_stacks'] = [
                    'ON' => [
                        'glpi_plugin_watchman_alerts' => 'stacks_id',
                        'glpi_plugin_watchman_stacks' => 'id'
                    ]
                ];
            }
            $where_conditions['glpi_plugin_watchman_stacks.type'] = $search_params['stack_type'];
        }

        // Construction de la requête
        $query_params = [
            'SELECT' => [
                'glpi_plugin_watchman_alerts.*',
                'glpi_plugin_watchman_stacks.name as stack_name',
                'glpi_plugin_watchman_stacks.version as stack_version',
                'glpi_plugin_watchman_stacks.type as stack_type'
            ],
            'FROM' => 'glpi_plugin_watchman_alerts',
            'WHERE' => $where_conditions
        ];

        if (!empty($joins)) {
            $query_params['LEFT JOIN'] = $joins;
        }

        // Ordre et pagination
        $order = 'glpi_plugin_watchman_alerts.date_creation DESC';
        if (!empty($search_params['order']) && in_array($search_params['order'], ['date_creation', 'score', 'severity', 'title'])) {
            $sort_dir = (!empty($search_params['sort']) && strtoupper($search_params['sort']) === 'ASC') ? 'ASC' : 'DESC';
            $order = 'glpi_plugin_watchman_alerts.' . $search_params['order'] . ' ' . $sort_dir;

            if ($search_params['order'] !== 'date_creation') {
                $order .= ', glpi_plugin_watchman_alerts.date_creation DESC';
            }
        }

        $query_params['ORDER'] = $order;

        if (!empty($search_params['limit'])) {
            $query_params['LIMIT'] = (int)$search_params['limit'];

            if (!empty($search_params['start'])) {
                $query_params['START'] = (int)$search_params['start'];
            }
        }

        $iterator = $DB->request($query_params);

        $alerts = [];
        foreach ($iterator as $alert) {
            $alert = $this->enrichAlertWithRelatedData($alert);
            $alert = $this->formatAlertData($alert);
            $alerts[] = $alert;
        }

        return $alerts;
    }

    /**
     * Marque/démarque une alerte comme faux positif
     */
    public function toggleFalsePositive($alert_id, $is_false_positive, $user_id = 0)
    {
        global $DB;

        $result = $DB->update(
            'glpi_plugin_watchman_alerts',
            [
                'possible_false' => $is_false_positive ? 1 : 0,
                'date_mod' => date('Y-m-d H:i:s')
            ],
            [
                'id' => $alert_id
            ]
        );

        if ($result) {
            $message = $is_false_positive ?
                __('Alerte marquée comme faux positif', 'watchman') :
                __('Faux positif retiré', 'watchman');

            // $this->addLog($alert_id, 'false_positive_toggle', $message, $user_id);
        }

        return $result;
    }

    /**
     * Crée un ticket GLPI à partir d'une alerte
     */
    public function createTicketFromAlert($alert_id, $user_id = 0)
    {
        $alert = $this->getAlertById($alert_id);
        if (!$alert) {
            return false;
        }

        // Enrichissement des données
        $alert = $this->enrichAlertWithRelatedData($alert);

        $ticket = new Ticket();

        // Titre du ticket
        $title = sprintf(
            __('Vulnérabilité %s - %s', 'watchman'),
            $alert['cves_id'],
            $alert['stack_name'] ?? __('Application', 'watchman')
        );

        // Description du ticket
        $description = $this->generateTicketDescription($alert);

        $ticket_data = [
            'name' => $title,
            'content' => $description,
            'urgency' => $this->getTicketUrgency($alert['severity']),
            'impact' => $this->getTicketImpact($alert['score']),
            'priority' => CommonITILObject::computePriority(
                $this->getTicketUrgency($alert['severity']),
                $this->getTicketImpact($alert['score'])
            ),
            'status' => CommonITILObject::INCOMING,
            'type' => Ticket::INCIDENT_TYPE,
            'category' => 0, // À adapter selon votre configuration
            'users_id_requester' => $user_id ?: Session::getLoginUserID()
        ];

        $ticket_id = $ticket->add($ticket_data);

        if ($ticket_id) {
            // Mise à jour de l'alerte avec l'ID du ticket
            global $DB;
            $DB->update(
                'glpi_plugin_watchman_alerts',
                [
                    'tickets_id' => $ticket_id,
                    'date_mod' => date('Y-m-d H:i:s')
                ],
                [
                    'id' => $alert_id
                ]
            );

            // $this->addLog($alert_id, 'ticket_created', 
            // sprintf(__('Ticket #%d créé', 'watchman'), $ticket_id), $user_id);

            return $ticket_id;
        }

        return false;
    }

    /**
     * Génère la description du ticket à partir des données de l'alerte
     */
    private function generateTicketDescription($alert)
    {
        $description = "";

        $description .= "=== " . __('Informations sur la vulnérabilité', 'watchman') . " ===\n";
        $description .= __('CVE ID', 'watchman') . ": " . $alert['cves_id'] . "\n";
        $description .= __('Titre', 'watchman') . ": " . $alert['title'] . "\n";
        $description .= __('Score CVSS', 'watchman') . ": " . ($alert['score'] ?? 'N/A') . "\n";
        $description .= __('Sévérité', 'watchman') . ": " . $this->translateSeverity($alert['severity']) . "\n";
        $description .= __('Date de publication', 'watchman') . ": " . ($alert['cve_published_at'] ? Html::convDate($alert['cve_published_at']) : 'N/A') . "\n\n";

        $description .= "=== " . __('Application concernée', 'watchman') . " ===\n";
        $description .= __('Nom', 'watchman') . ": " . ($alert['stack_name'] ?? 'N/A') . "\n";
        $description .= __('Version', 'watchman') . ": " . ($alert['stack_version'] ?? 'N/A') . "\n";
        $description .= __('Type', 'watchman') . ": " . ($alert['stack_type'] ?? 'N/A') . "\n\n";

        $description .= "=== " . __('Description', 'watchman') . " ===\n";
        $description .= strip_tags($alert['description']) . "\n\n";

        if ($alert['attack_vector']) {
            $description .= "=== " . __('Détails techniques', 'watchman') . " ===\n";
            $description .= __('Vecteur d\'attaque', 'watchman') . ": " . $alert['attack_vector'] . "\n";
            $description .= __('Complexité', 'watchman') . ": " . $alert['attack_complexity'] . "\n";
            if ($alert['exploitability_score']) {
                $description .= __('Score d\'exploitabilité', 'watchman') . ": " . $alert['exploitability_score'] . "\n";
            }
            if ($alert['impact_score']) {
                $description .= __('Score d\'impact', 'watchman') . ": " . $alert['impact_score'] . "\n";
            }
        }

        return $description;
    }

    /**
     * Détermine l'urgence du ticket selon la sévérité
     */
    private function getTicketUrgency($severity)
    {
        switch (strtoupper($severity)) {
            case 'CRITICAL':
                return 5; // Très haute
            case 'HIGH':
                return 4; // Haute
            case 'MEDIUM':
                return 3; // Moyenne
            case 'LOW':
                return 2; // Faible
            default:
                return 3; // Moyenne par défaut
        }
    }

    /**
     * Détermine l'impact du ticket selon le score CVSS
     */
    private function getTicketImpact($score)
    {
        if ($score >= 9.0) {
            return 5; // Très haut
        } elseif ($score >= 7.0) {
            return 4; // Haut
        } elseif ($score >= 4.0) {
            return 3; // Moyen
        } else {
            return 2; // Faible
        }
    }

    /**
     * Supprime logiquement une alerte
     */
    public function deleteAlert($alert_id, $user_id = 0)
    {
        global $DB;

        $result = $DB->update(
            'glpi_plugin_watchman_alerts',
            [
                'is_deleted' => 1,
                'date_mod' => date('Y-m-d H:i:s')
            ],
            [
                'id' => $alert_id
            ]
        );

        if ($result) {
            // $this->addLog($alert_id, 'deleted', __('Alerte supprimée', 'watchman'), $user_id);
        }

        return $result;
    }

    /**
     * Actions groupées sur les alertes
     */
    public function bulkAction($action, $alert_ids, $user_id = 0)
    {
        $results = [];

        foreach ($alert_ids as $alert_id) {
            switch ($action) {
                case 'mark-patched':
                    $results[$alert_id] = $this->markAsPatched($alert_id, $user_id);
                    break;

                case 'false-positive':
                    $results[$alert_id] = $this->toggleFalsePositive($alert_id, true, $user_id);
                    break;

                case 'create-tickets':
                    $ticket_id = $this->createTicketFromAlert($alert_id, $user_id);
                    $results[$alert_id] = $ticket_id !== false;
                    break;

                case 'delete':
                    $results[$alert_id] = $this->deleteAlert($alert_id, $user_id);
                    break;
            }
        }

        return $results;
    }

    public function processAlert($alert_data)
    {
        global $DB;

        try {
            // Validation des données requises
            if (!$this->validateAlertData($alert_data)) {
                return [
                    'success' => false,
                    'error' => __('Données d\'alerte invalides', 'watchman')
                ];
            }

            $alert_id = $alert_data['id'];
            $cve_hash = $alert_data['cve_hash'] ?? null;

            // Vérification si l'alerte existe déjà
            $existing_alert = $this->getAlertByCveHash($cve_hash) ?: $this->getAlertById($alert_id);

            if ($existing_alert) {
                // Mise à jour de l'alerte existante
                $result = $this->updateExistingAlert($existing_alert['id'], $alert_data);
                $is_new = false;
            } else {
                // Création d'une nouvelle alerte
                $result = $this->createNewAlert($alert_data);
                $is_new = true;
            }

            if (!$result['success']) {
                return $result;
            }

            $stored_alert_id = $result['alert_id'];

            // Traitement des données associées (CVE, Stack, etc.)
            $this->processCveData($alert_data['cve'] ?? []);
            $this->processStackData($alert_data['stack'] ?? []);

            // Détermination si l'alerte est critique
            $is_critical = $this->isAlertCritical($alert_data);

            // Log de l'activité
            $action = $is_new ? 'created' : 'updated';
            

            return [
                'success' => true,
                'alert_id' => $stored_alert_id,
                'is_new' => $is_new,
                'is_critical' => $is_critical,
                'message' => sprintf(
                    __('Alerte %s avec succès', 'watchman'),
                    $is_new ? 'créée' : 'mise à jour'
                )
            ];
        } catch (Exception $e) {
            Toolbox::logInFile(
                'watchman_process_alert_error',
                sprintf('Erreur traitement alerte ID %s: %s', $alert_data['id'] ?? 'unknown', $e->getMessage())
            );

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Lie une alerte à un ticket GLPI
     * 
     * @param string $alert_id ID de l'alerte Watchman
     * @param int $ticket_id ID du ticket GLPI
     * @return array Résultat de la liaison
     */
    public function linkAlertToTicket($alert_id, $ticket_id)
    {
        global $DB;

        try {
            // Mise à jour de l'alerte avec l'ID du ticket
            $result = $DB->update(
                'glpi_plugin_watchman_alerts',
                ['tickets_id' => $ticket_id],
                ['id' => $alert_id]
            );

            if ($result) {
                // Log de l'activité
               

                return [
                    'success' => true,
                    'message' => sprintf(__('Alerte liée au ticket #%d avec succès', 'watchman'), $ticket_id)
                ];
            } else {
                return [
                    'success' => false,
                    'error' => __('Échec de la liaison avec le ticket', 'watchman')
                ];
            }
        } catch (Exception $e) {
            Toolbox::logInFile(
                'watchman_link_ticket_error',
                sprintf('Erreur liaison alerte %s au ticket %d: %s', $alert_id, $ticket_id, $e->getMessage())
            );

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Valide les données d'une alerte
     * 
     * @param array $alert_data
     * @return bool
     */
    private function validateAlertData($alert_data)
    {
        $required_fields = ['id', 'title', 'description'];

        foreach ($required_fields as $field) {
            if (!isset($alert_data[$field]) || empty($alert_data[$field])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Récupère une alerte par son cve_hash
     * 
     * @param string $cve_hash
     * @return array|null
     */
    private function getAlertByCveHash($cve_hash)
    {
        global $DB;

        if (empty($cve_hash)) {
            return null;
        }

        $iterator = $DB->request([
            'FROM' => 'glpi_plugin_watchman_alerts',
            'WHERE' => [
                'cve_hash' => $cve_hash,
                'is_deleted' => 0
            ],
            'LIMIT' => 1
        ]);

        return $iterator->count() > 0 ? $iterator->current() : null;
    }

    /**
     * Récupère une alerte par son ID
     * 
     * @param string $alert_id
     * @return array|null
     */
    // private function getAlertById($alert_id)
    // {
    //     global $DB;

    //     $iterator = $DB->request([
    //         'FROM' => 'glpi_plugin_watchman_alerts',
    //         'WHERE' => [
    //             'id' => $alert_id,
    //             'is_deleted' => 0
    //         ],
    //         'LIMIT' => 1
    //     ]);

    //     return $iterator->count() > 0 ? $iterator->current() : null;
    // }

    /**
     * Met à jour une alerte existante
     * 
     * @param string $existing_id ID de l'alerte existante
     * @param array $alert_data Nouvelles données
     * @return array
     */
    private function updateExistingAlert($existing_id, $alert_data)
    {
        global $DB;

        try {
            $update_data = [
                'title' => $DB->escape($alert_data['title']),
                'description' => $DB->escape($alert_data['description']),
                'score' => $alert_data['score'] ?? null,
                'severity' => $alert_data['severity'] ?? null,
                'patched' => $alert_data['patched'] ?? false,
                'possible_false' => $alert_data['possible_false'] ?? false,
                'integration_notified' => $alert_data['integration_notified'] ?? false,
                'email_notified' => $alert_data['email_notified'] ?? false,
                'reported' => $alert_data['reported'] ?? false,
                'date_mod' => date('Y-m-d H:i:s')
            ];

            // Mise à jour de patched_at si l'alerte est marquée comme corrigée
            if ($alert_data['patched'] ?? false) {
                $update_data['patched_at'] = $alert_data['patched_at'] ?? date('Y-m-d H:i:s');
            }

            $result = $DB->update('glpi_plugin_watchman_alerts', $update_data, ['id' => $existing_id]);

            if ($result) {
                return [
                    'success' => true,
                    'alert_id' => $existing_id
                ];
            } else {
                return [
                    'success' => false,
                    'error' => __('Échec de la mise à jour de l\'alerte', 'watchman')
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
     * Crée une nouvelle alerte
     * 
     * @param array $alert_data
     * @return array
     */
    private function createNewAlert($alert_data)
    {
        global $DB;

        try {
            // Traitement des dépendances (CVE et Stack d'abord)
            $cve_id = $this->ensureCveExists($alert_data['cve'] ?? []);
            $stack_id = $this->ensureStackExists($alert_data['stack'] ?? []);

            if (!$cve_id || !$stack_id) {
                return [
                    'success' => false,
                    'error' => __('Impossible de créer les dépendances CVE ou Stack', 'watchman')
                ];
            }

            $insert_data = [
                'id' => $alert_data['id'],
                'cve_hash' => $alert_data['cve_hash'] ?? $this->generateCveHash($alert_data),
                'stacks_id' => $stack_id,
                'cves_id' => $cve_id,
                'title' => $DB->escape($alert_data['title']),
                'description' => $DB->escape($alert_data['description']),
                'score' => $alert_data['score'] ?? null,
                'severity' => $alert_data['severity'] ?? null,
                'patched' => $alert_data['patched'] ?? false,
                'possible_false' => $alert_data['possible_false'] ?? false,
                'integration_notified' => $alert_data['integration_notified'] ?? false,
                'email_notified' => $alert_data['email_notified'] ?? false,
                'reported' => $alert_data['reported'] ?? false,
                'date_creation' => date('Y-m-d H:i:s'),
                'date_mod' => date('Y-m-d H:i:s'),
                'is_deleted' => 0
            ];

            // Ajout de patched_at si nécessaire
            if ($alert_data['patched'] ?? false) {
                $insert_data['patched_at'] = $alert_data['patched_at'] ?? date('Y-m-d H:i:s');
            }

            $result = $DB->insert('glpi_plugin_watchman_alerts', $insert_data);

            if ($result) {
                return [
                    'success' => true,
                    'alert_id' => $alert_data['id']
                ];
            } else {
                return [
                    'success' => false,
                    'error' => __('Échec de la création de l\'alerte', 'watchman')
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
     * S'assure qu'une CVE existe dans la base
     * 
     * @param array $cve_data
     * @return string|null ID de la CVE
     */
    private function ensureCveExists($cve_data)
    {
        global $DB;

        if (empty($cve_data['id'])) {
            return null;
        }

        $cve_id = $cve_data['id'];

        // Vérification si la CVE existe
        $existing = $DB->request([
            'FROM' => 'glpi_plugin_watchman_cves',
            'WHERE' => ['id' => $cve_id],
            'LIMIT' => 1
        ]);

        if ($existing->count() > 0) {
            return $cve_id;
        }

        // Création de la CVE
        $insert_data = [
            'id' => $cve_id,
            'assigner' => $cve_data['assigner'] ?? 'Unknown',
            'published_at' => $cve_data['published_at'] ?? null,
            'modified_at' => $cve_data['modified_at'] ?? null,
            'date_creation' => date('Y-m-d H:i:s'),
            'date_mod' => date('Y-m-d H:i:s'),
            'is_deleted' => 0
        ];

        $result = $DB->insert('glpi_plugin_watchman_cves', $insert_data);

        return $result ? $cve_id : null;
    }

    /**
     * S'assure qu'un Stack existe dans la base
     * 
     * @param array $stack_data
     * @return string|null ID du Stack
     */
    private function ensureStackExists($stack_data)
    {
        global $DB;

        if (empty($stack_data['id'])) {
            return null;
        }

        $stack_id = $stack_data['id'];

        // Vérification si le Stack existe
        $existing = $DB->request([
            'FROM' => 'glpi_plugin_watchman_stacks',
            'WHERE' => ['id' => $stack_id],
            'LIMIT' => 1
        ]);

        if ($existing->count() > 0) {
            return $stack_id;
        }

        // Création du Stack
        $insert_data = [
            'id' => $stack_id,
            'name' => $stack_data['name'] ?? 'Unknown Stack',
            'type' => $stack_data['type'] ?? 'app_server',
            'version' => $stack_data['version'] ?? '1.0.0',
            'has_vulnerability' => true,
            'added_by_agent' => true,
            'date_creation' => date('Y-m-d H:i:s'),
            'date_mod' => date('Y-m-d H:i:s'),
            'is_deleted' => 0
        ];

        $result = $DB->insert('glpi_plugin_watchman_stacks', $insert_data);

        return $result ? $stack_id : null;
    }

    /**
     * Traite les données CVE (impact, références, etc.)
     * 
     * @param array $cve_data
     */
    private function processCveData($cve_data)
    {
        if (empty($cve_data['id'])) {
            return;
        }

        // Traitement de l'impact CVE
        if (isset($cve_data['impact'])) {
            $this->processCveImpact($cve_data['id'], $cve_data['impact']);
        }

        // Traitement des références CVE
        if (isset($cve_data['references']) && is_array($cve_data['references'])) {
            $this->processCveReferences($cve_data['id'], $cve_data['references']);
        }

        // Traitement des CPE
        if (isset($cve_data['configs']) && is_array($cve_data['configs'])) {
            $this->processCveConfigs($cve_data['id'], $cve_data['configs']);
        }
    }

    /**
     * Traite les données Stack
     * 
     * @param array $stack_data
     */
    private function processStackData($stack_data)
    {
        // Mise à jour du statut de vulnérabilité du stack si nécessaire
        if (!empty($stack_data['id'])) {
            global $DB;
            $DB->update(
                'glpi_plugin_watchman_stacks',
                ['has_vulnerability' => true, 'date_mod' => date('Y-m-d H:i:s')],
                ['id' => $stack_data['id']]
            );
        }
    }

    /**
     * Détermine si une alerte est critique
     * 
     * @param array $alert_data
     * @return bool
     */
    private function isAlertCritical($alert_data)
    {
        // Critères de criticité
        $severity = strtolower($alert_data['severity'] ?? '');
        $score = floatval($alert_data['score'] ?? 0);

        return ($severity === 'critical') ||
            ($severity === 'high' && $score >= 8.0) ||
            ($score >= 9.0);
    }

    /**
     * Génère un hash CVE si non fourni
     * 
     * @param array $alert_data
     * @return string
     */
    private function generateCveHash($alert_data)
    {
        $stack_id = $alert_data['stack']['id'] ?? 'unknown';
        $description = $alert_data['description'] ?? '';

        return md5($stack_id . ':' . $description);
    }


    /**
     * Traitement de l'impact CVE
     */
    private function processCveImpact($cve_id, $impact_data)
    {
        global $DB;

        try {
            // Vérification si l'impact existe déjà
            $existing = $DB->request([
                'FROM' => 'glpi_plugin_watchman_cve_impacts',
                'WHERE' => ['cves_id' => $cve_id],
                'LIMIT' => 1
            ]);

            $data = [
                'cves_id' => $cve_id,
                'exploitability_score' => $impact_data['exploitability_score'] ?? null,
                'impact_score' => $impact_data['impact_score'] ?? null,
                'attack_vector' => $impact_data['attack_vector'] ?? '',
                'attack_complexity' => $impact_data['attack_complexity'] ?? '',
                'privileges_required' => $impact_data['privileges_required'] ?? '',
                'user_interaction' => $impact_data['user_interaction'] ?? '',
                'confidentiality_impact' => $impact_data['confidentiality_impact'] ?? '',
                'availability_impact' => $impact_data['availability_impact'] ?? '',
                'integrity_impact' => $impact_data['integrity_impact'] ?? '',
                'base_severity' => $impact_data['base_severity'] ?? '',
                'base_score' => $impact_data['base_score'] ?? '',
                'date_mod' => date('Y-m-d H:i:s')
            ];

            if ($existing->count() > 0) {
                $DB->update('glpi_plugin_watchman_cve_impacts', $data, ['cves_id' => $cve_id]);
            } else {
                $data['date_creation'] = date('Y-m-d H:i:s');
                $data['is_deleted'] = 0;
                $DB->insert('glpi_plugin_watchman_cve_impacts', $data);
            }
        } catch (Exception $e) {
            Toolbox::logInFile('watchman_cve_impact_error', "Erreur traitement impact CVE: " . $e->getMessage());
        }
    }

    /**
     * Traitement des références CVE
     */
    private function processCveReferences($cve_id, $references)
    {
        global $DB;

        foreach ($references as $ref) {
            if (empty($ref['url'])) continue;

            try {
                // Vérification si la référence existe déjà
                $existing = $DB->request([
                    'FROM' => 'glpi_plugin_watchman_cve_references',
                    'WHERE' => ['url' => $ref['url']],
                    'LIMIT' => 1
                ]);

                if ($existing->count() == 0) {
                    $DB->insert('glpi_plugin_watchman_cve_references', [
                        'cves_id' => $cve_id,
                        'url' => $ref['url'],
                        'name' => $ref['name'] ?? $ref['url'],
                        'date_creation' => date('Y-m-d H:i:s'),
                        'date_mod' => date('Y-m-d H:i:s'),
                        'is_deleted' => 0
                    ]);
                }
            } catch (Exception $e) {
                Toolbox::logInFile('watchman_cve_ref_error', "Erreur traitement référence CVE: " . $e->getMessage());
            }
        }
    }

    /**
     * Traitement des configurations CPE
     */
    private function processCveConfigs($cve_id, $configs)
    {
        global $DB;

        foreach ($configs as $config) {
            if (empty($config['name'])) continue;

            try {
                $cve_hash = md5($cve_id . '-' . $config['name']);

                // Vérification si la config existe déjà
                $existing = $DB->request([
                    'FROM' => 'glpi_plugin_watchman_cpes',
                    'WHERE' => ['cve_hash' => $cve_hash],
                    'LIMIT' => 1
                ]);

                if ($existing->count() == 0) {
                    $DB->insert('glpi_plugin_watchman_cpes', [
                        'cve_hash' => $cve_hash,
                        'cves_id' => $cve_id,
                        'vulnerable' => $config['vulnerable'] ?? false,
                        'name' => $config['name'],
                        'version' => $config['version'] ?? null,
                        'vendor' => $config['vendor'] ?? null,
                        'hardware' => $config['hardware'] ?? false,
                        'application' => $config['application'] ?? false,
                        'system' => $config['system'] ?? false,
                        'vulnerable_from' => $config['vulnerable_from'] ?? null,
                        'vulnerable_to' => $config['vulnerable_to'] ?? null,
                        'invulnerable_from' => $config['invulnerable_from'] ?? null,
                        'invulnerable_to' => $config['invulnerable_to'] ?? null,
                        'date_creation' => date('Y-m-d H:i:s'),
                        'date_mod' => date('Y-m-d H:i:s'),
                        'is_deleted' => 0
                    ]);
                }
            } catch (Exception $e) {
                Toolbox::logInFile('watchman_cpe_error', "Erreur traitement CPE: " . $e->getMessage());
            }
        }
    }


    public static function handleMarkAlertAsPatched($alert_id)
    {
        global $DB;
        try {
            // Validation des paramètres
            $alertId = $alert_id ?? 0;

            if ($alertId == 0) {
                throw new Exception(__('ID d\'alerte invalide', 'watchman'));
            }

            // Vérifier que l’alerte existe
            $alert = $DB->request([
                'SELECT' => ['id', 'tickets_id'],
                'FROM'   => 'glpi_plugin_watchman_alerts',
                'WHERE'  => ['id' => $alertId]
            ])->current();

            if (!$alert) {
                throw new Exception(__('Alerte introuvable', 'watchman'));
            }

            // Mise à jour sécurisée
            $updated = $DB->update(
                'glpi_plugin_watchman_alerts',
                [
                    'patched'    => 1,
                    'patched_at' => new \QueryExpression('NOW()'),
                    'date_mod'   => new \QueryExpression('NOW()')
                ],
                ['id' => $alertId]
            );

            if ($updated === false) {
                throw new Exception(__('Erreur lors de la mise à jour', 'watchman'));
            }
            if (isset($alert['tickets_id'])) {
                self::resolveTicket($alert['tickets_id']);
            }

            $patched = true;

            try {
                self::handleMarkAsPatchedAlertAPI($alertId);
            } catch (Exception $e) {
                //throw $th;

                echo $e;

            }




            return [
                'success' => true,
                'message' => $patched ? 'Alerte marquée comme corrigée' : 'Alerte marquée comme non corrigée',
                'patched' => $patched,
                'patched_at' => $patched ? date('Y-m-d H:i:s') : null
            ];
        } catch (Exception $e) {
            // Log technique (pour debug interne)
            Toolbox::logDebug("handleMarkAsPatched error: " . $e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }


    public static function resolveTicket($ticketId)
    {
        global $DB;


        try {

            $ticketId = (int)$ticketId;
            if ($ticketId <= 0) {
                throw new Exception(__('ID de ticket invalide', 'watchman'));
            }

            $ticket = new Ticket();
            if (!$ticket->getFromDB($ticketId)) {
                throw new Exception(__('Ticket introuvable', 'watchman'));
            }

            // Mettre à jour le statut du ticket
            $ok = $ticket->update([
                'id'       => $ticketId,
                'status'   => CommonITILObject::SOLVED,
                'solvedate' => date("Y-m-d H:i:s"),
                'date_mod' => date("Y-m-d H:i:s"),
            ]);

            if (!$ok) {
                throw new Exception(__('Erreur lors de la mise à jour du ticket', 'watchman'));
            }

            

            return true;
        } catch (Exception $e) {
            Toolbox::logDebug("resolveTicket error: " . $e->getMessage());
            return false;
        }
    }

    public static function handleMarkAsPatched($ticket)
    {
        global $DB;

        $ticketId = $ticket->getID();

        try {


            // Validation des paramètres
            if ($ticketId == 0) {
                throw new Exception(__('ID du ticket est requis', 'watchman'));
            }

            
            // Vérifier que l’alerte existe
            $ticket = $DB->request([
                'SELECT' => ['id', 'tickets_id'],
                'FROM'   => 'glpi_plugin_watchman_alerts',
                'WHERE'  => ['tickets_id' => $ticketId]
            ])->current();

            if (!$ticket) {
                throw new Exception(__('Alerte introuvable', 'watchman'));
            }

            // Mise à jour sécurisée
            $updated = $DB->update(
                'glpi_plugin_watchman_alerts',
                [
                    'patched'    => 1,
                    'patched_at' => new \QueryExpression('NOW()'),
                    'date_mod'   => new \QueryExpression('NOW()')
                ],
                ['tickets_id' => $ticketId]
            );

            if ($updated === false) {
                throw new Exception(__('Erreur lors de la mise à jour', 'watchman'));
            }

            self::handleMarkAsPatchedAlertAPI($ticket['id']);



            return [
                'success' => true,
                'message' => __('Alerte marquée comme corrigée', 'watchman')
            ];
        } catch (Exception $e) {
            // Log technique (pour debug interne)
            Toolbox::logDebug("handleMarkAsPatched error: " . $e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }


    public static function handleMarkAsPatchedAlertAPI($alert_id)
    {
        global $DB;
        $api_client = new WatchmanApiClient();
        
        try {
            $result=$api_client->patchAlerts($alert_id);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}
