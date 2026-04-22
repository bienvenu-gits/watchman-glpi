<?php

namespace GlpiPlugin\Watchman;

use Migration;
use DBConnection;

/**
 * Installation et migration des tables pour le système de CRON
 */
class CronMigration
{
    /**
     * Installation complète des tables nécessaires
     */
    public static function install(Migration $migration, string $version): bool
    {
        global $DB;

        $default_charset = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign = DBConnection::getDefaultPrimaryKeySignOption();

        // Table de mapping des ordinateurs avec l'API externe
        self::createComputerMappingsTable($migration, $default_charset, $default_collation, $default_key_sign);
        
        // Table des logs de synchronisation
        self::createSyncLogsTable($migration, $default_charset, $default_collation, $default_key_sign);
        
        // Table des métriques
        self::createMetricsTable($migration, $default_charset, $default_collation, $default_key_sign);
        
        // Table des logs d'erreurs détaillés
        self::createErrorLogsTable($migration, $default_charset, $default_collation, $default_key_sign);
        
        // Tables pour les alertes (déjà créées par AlertManager mais on s'assure)
        
        return true;
    }

    /**
     * Table de mapping des ordinateurs avec l'API externe
     */
    private static function createComputerMappingsTable($migration, $charset, $collation, $key_sign)
    {
        global $DB;
        
        $table = 'glpi_plugin_watchman_computer_mappings';
        
        if (!$DB->tableExists($table)) {
            $migration->displayMessage("Installing $table");

            $query = "CREATE TABLE `$table` (
                `id` int {$key_sign} NOT NULL AUTO_INCREMENT,
                `computers_id` int {$key_sign} NOT NULL,
                `external_id` varchar(255) COLLATE {$collation},
                `sync_status` varchar(50) COLLATE {$collation} NOT NULL DEFAULT 'pending',
                `last_sync_date` timestamp NULL,
                `last_sync_hash` varchar(64) COLLATE {$collation},
                `retry_count` int NOT NULL DEFAULT 0,
                `error_message` text COLLATE {$collation},
                `metadata` longtext COLLATE {$collation},
                `is_selected` tinyint(1) NOT NULL DEFAULT 0,
                `date_creation` timestamp DEFAULT CURRENT_TIMESTAMP,
                `date_mod` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                PRIMARY KEY (`id`),
                UNIQUE KEY `computers_id` (`computers_id`),
                KEY `external_id` (`external_id`),
                KEY `sync_status` (`sync_status`),
                KEY `last_sync_date` (`last_sync_date`),
                KEY `last_sync_hash` (`last_sync_hash`),
                KEY `sync_status_date` (`sync_status`, `last_sync_date`),
                FOREIGN KEY (`computers_id`) REFERENCES `glpi_computers` (`id`) ON DELETE CASCADE
                
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC";

            $DB->doQuery($query) or die("Error creating $table table");
        }
    }

    /**
     * Table des logs de synchronisation
     */
    private static function createSyncLogsTable($migration, $charset, $collation, $key_sign)
    {
        global $DB;
        
        $table = 'glpi_plugin_watchman_sync_logs';
        
        if (!$DB->tableExists($table)) {
            $migration->displayMessage("Installing $table");

            $query = "CREATE TABLE `$table` (
                `id` int {$key_sign} NOT NULL AUTO_INCREMENT,
                `item_id` int {$key_sign},
                `itemtype` varchar(100) COLLATE {$collation} DEFAULT 'Computer',
                `action` varchar(50) COLLATE {$collation} NOT NULL,
                `status` varchar(20) COLLATE {$collation} NOT NULL,
                `message` text COLLATE {$collation},
                `execution_time` decimal(8,3),
                `memory_usage` int,
                `api_response_code` int,
                `api_response_time` decimal(8,3),
                `batch_id` varchar(50) COLLATE {$collation},
                `error_details` longtext COLLATE {$collation},
                `users_id` int {$key_sign},
                `date_creation` timestamp DEFAULT CURRENT_TIMESTAMP,
                
                PRIMARY KEY (`id`),
                KEY `item_id` (`item_id`),
                KEY `itemtype` (`itemtype`),
                KEY `action` (`action`),
                KEY `status` (`status`),
                KEY `date_creation` (`date_creation`),
                KEY `batch_id` (`batch_id`),
                KEY `users_id` (`users_id`)
                
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC";

            $DB->doQuery($query) or die("Error creating $table table");
        }
    }

    /**
     * Table des métriques de performance
     */
    private static function createMetricsTable($migration, $charset, $collation, $key_sign)
    {
        global $DB;
        
        $table = 'glpi_plugin_watchman_metrics';
        
        if (!$DB->tableExists($table)) {
            $migration->displayMessage("Installing $table");

            $query = "CREATE TABLE `$table` (
                `id` int {$key_sign} NOT NULL AUTO_INCREMENT,
                `metric_type` varchar(50) COLLATE {$collation} NOT NULL,
                `metric_name` varchar(100) COLLATE {$collation} NOT NULL,
                `metric_value` decimal(15,4),
                `metric_unit` varchar(20) COLLATE {$collation},
                `context` varchar(100) COLLATE {$collation},
                `tags` longtext COLLATE {$collation},
                `period_start` timestamp NULL,
                `period_end` timestamp NULL,
                `date_creation` timestamp DEFAULT CURRENT_TIMESTAMP,
                
                PRIMARY KEY (`id`),
                KEY `metric_type` (`metric_type`),
                KEY `metric_name` (`metric_name`),
                KEY `context` (`context`),
                KEY `date_creation` (`date_creation`),
                KEY `period_start` (`period_start`)
                
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC";

            $DB->doQuery($query) or die("Error creating $table table");
        }
    }

    /**
     * Table des logs d'erreurs détaillés
     */
    private static function createErrorLogsTable($migration, $charset, $collation, $key_sign)
    {
        global $DB;
        
        $table = 'glpi_plugin_watchman_error_logs';
        
        if (!$DB->tableExists($table)) {
            $migration->displayMessage("Installing $table");

            $query = "CREATE TABLE `$table` (
                `id` int {$key_sign} NOT NULL AUTO_INCREMENT,
                `error_type` varchar(50) COLLATE {$collation} NOT NULL,
                `error_code` varchar(20) COLLATE {$collation},
                `error_message` text COLLATE {$collation},
                `context` longtext COLLATE {$collation},
                `stack_trace` longtext COLLATE {$collation},
                `related_item_type` varchar(100) COLLATE {$collation},
                `related_item_id` int {$key_sign},
                `severity` varchar(20) COLLATE {$collation} NOT NULL DEFAULT 'error',
                `is_resolved` tinyint NOT NULL DEFAULT 0,
                `resolved_date` timestamp NULL,
                `resolved_by` int {$key_sign},
                `users_id` int {$key_sign},
                `date_creation` timestamp DEFAULT CURRENT_TIMESTAMP,
                
                PRIMARY KEY (`id`),
                KEY `error_type` (`error_type`),
                KEY `error_code` (`error_code`),
                KEY `severity` (`severity`),
                KEY `is_resolved` (`is_resolved`),
                KEY `date_creation` (`date_creation`),
                KEY `related_item` (`related_item_type`, `related_item_id`)
                
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC";

            $DB->doQuery($query) or die("Error creating $table table");
        }
    }

    /**
     * Désinstallation des tables
     */
    public static function uninstall(): bool
    {
        global $DB;

        $tables = [
            'glpi_plugin_watchman_error_logs',
            'glpi_plugin_watchman_alert_logs',
            'glpi_plugin_watchman_alerts',
            'glpi_plugin_watchman_metrics',
            'glpi_plugin_watchman_sync_logs',
            'glpi_plugin_watchman_computer_mappings'
        ];

        foreach ($tables as $table) {
            if ($DB->tableExists($table)) {
                $DB->query("DROP TABLE `$table`");
            }
        }

        return true;
    }
}