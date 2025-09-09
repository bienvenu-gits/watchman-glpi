<?php
/**
 * Script d'administration pour le monitoring des tâches cron Watchman
 * 
 * Usage:
 *   php monitor_cron.php status          - Affiche le statut des tâches surveillées
 *   php monitor_cron.php check           - Vérifie et récupère les tâches bloquées
 *   php monitor_cron.php reset [task]    - Remet à zéro une tâche spécifique
 *   php monitor_cron.php cleanup         - Nettoie les anciens fichiers de monitoring
 */

// Configuration de base
if (!defined('GLPI_ROOT')) {
    define('GLPI_ROOT', dirname(__DIR__, 3));
}

include GLPI_ROOT . "/inc/includes.php";

use GlpiPlugin\Watchman\CronMonitor;
use GlpiPlugin\Watchman\CronSyncAlert;

// Vérification des arguments
$action = $argv[1] ?? 'help';

switch ($action) {
    case 'status':
        showMonitoringStatus();
        break;
        
    case 'check':
        checkAndRecoverTasks();
        break;
        
    case 'reset':
        $taskName = $argv[2] ?? null;
        resetTask($taskName);
        break;
        
    case 'cleanup':
        cleanupOldFiles();
        break;
        
    case 'help':
    default:
        showHelp();
        break;
}

/**
 * Affiche l'aide
 */
function showHelp()
{
    echo "Script de monitoring des tâches cron Watchman\n\n";
    echo "Usage:\n";
    echo "  php monitor_cron.php status          - Affiche le statut des tâches surveillées\n";
    echo "  php monitor_cron.php check           - Vérifie et récupère les tâches bloquées\n";
    echo "  php monitor_cron.php reset [task]    - Remet à zéro une tâche spécifique\n";
    echo "  php monitor_cron.php cleanup         - Nettoie les anciens fichiers de monitoring\n";
    echo "  php monitor_cron.php help            - Affiche cette aide\n\n";
}

/**
 * Affiche le statut du monitoring
 */
function showMonitoringStatus()
{
    echo "=== STATUT DU MONITORING WATCHMAN ===\n\n";
    
    $heartbeatDir = GLPI_ROOT . '/files/_tmp/watchman_heartbeats';
    
    if (!is_dir($heartbeatDir)) {
        echo "Aucun fichier de monitoring trouvé.\n";
        return;
    }
    
    $files = glob($heartbeatDir . '/watchman_cron_heartbeat_*.json');
    
    if (empty($files)) {
        echo "Aucune tâche en cours de surveillance.\n";
        return;
    }
    
    foreach ($files as $file) {
        $data = json_decode(file_get_contents($file), true);
        if (!$data) continue;
        
        $taskName = $data['task_name'] ?? 'Inconnu';
        $status = $data['status'] ?? 'Inconnu';
        $startTime = isset($data['start_time']) ? date('Y-m-d H:i:s', $data['start_time']) : 'Inconnu';
        $lastHeartbeat = isset($data['last_heartbeat']) ? date('Y-m-d H:i:s', $data['last_heartbeat']) : 'Inconnu';
        $memoryUsage = isset($data['memory_usage']) ? formatBytes($data['memory_usage']) : 'Inconnu';
        $peakMemory = isset($data['peak_memory']) ? formatBytes($data['peak_memory']) : 'Inconnu';
        
        echo "Tâche: $taskName\n";
        echo "  Statut: $status\n";
        echo "  Démarrage: $startTime\n";
        echo "  Dernier heartbeat: $lastHeartbeat\n";
        echo "  Mémoire actuelle: $memoryUsage\n";
        echo "  Pic mémoire: $peakMemory\n";
        
        // Vérifier si bloquée
        if ($status === 'running') {
            $timeSinceHeartbeat = time() - $data['last_heartbeat'];
            $duration = time() - $data['start_time'];
            
            echo "  Durée d'exécution: " . formatDuration($duration) . "\n";
            echo "  Temps depuis dernier heartbeat: " . formatDuration($timeSinceHeartbeat) . "\n";
            
            if ($timeSinceHeartbeat > 1800) { // 30 minutes
                echo "  ⚠️  TÂCHE POSSIBLEMENT BLOQUÉE\n";
            }
        }
        
        echo "\n";
    }
}

/**
 * Vérifie et récupère les tâches bloquées
 */
function checkAndRecoverTasks()
{
    echo "=== VÉRIFICATION DES TÂCHES BLOQUÉES ===\n\n";
    
    $stuckTasks = CronMonitor::checkForStuckTasks();
    
    if (empty($stuckTasks)) {
        echo "Aucune tâche bloquée détectée.\n";
        return;
    }
    
    echo "Tâches bloquées trouvées: " . count($stuckTasks) . "\n\n";
    
    foreach ($stuckTasks as $task) {
        $taskName = $task['task_name'] ?? 'Inconnu';
        $duration = time() - $task['start_time'];
        $timeSinceHeartbeat = time() - $task['last_heartbeat'];
        
        echo "Tâche bloquée: $taskName\n";
        echo "  Durée d'exécution: " . formatDuration($duration) . "\n";
        echo "  Temps depuis dernier heartbeat: " . formatDuration($timeSinceHeartbeat) . "\n";
        echo "  → Récupération automatique en cours...\n\n";
    }
    
    echo "Récupération terminée.\n";
}

/**
 * Remet à zéro une tâche spécifique
 */
function resetTask($taskName)
{
    if (!$taskName) {
        echo "Erreur: Nom de tâche manquant.\n";
        echo "Usage: php monitor_cron.php reset [nom_de_tache]\n";
        return;
    }
    
    echo "=== RÉINITIALISATION DE TÂCHE ===\n\n";
    echo "Réinitialisation de la tâche: $taskName\n";
    
    // Chercher et supprimer le fichier heartbeat
    $heartbeatDir =  GLPI_ROOT . '/files/_tmp/watchman_heartbeats';
    $pattern = $heartbeatDir . '/watchman_cron_heartbeat_*' . str_replace(['\\', ':', ' '], '_', $taskName) . '*.json';
    $files = glob($pattern);
    
    $found = false;
    foreach ($files as $file) {
        echo "Suppression du fichier de monitoring: " . basename($file) . "\n";
        unlink($file);
        $found = true;
    }
    
    if (!$found) {
        echo "Aucun fichier de monitoring trouvé pour cette tâche.\n";
    }
    
    // Tenter de réinitialiser la tâche cron dans GLPI
    global $DB;
    
    $parts = explode('::', $taskName);
    if (count($parts) === 2) {
        $className = $parts[0];
        $methodName = $parts[1];
        
        $query = "SELECT id FROM glpi_crontasks 
                  WHERE itemtype = '" . $DB->escape($className) . "' 
                  AND name = '" . $DB->escape($methodName) . "'";
        
        $result = $DB->query($query);
        if ($result && $DB->numrows($result) > 0) {
            while ($row = $DB->fetchAssoc($result)) {
                $cronTask = new CronTask();
                if ($cronTask->getFromDB($row['id'])) {
                    $cronTask->update([
                        'id' => $row['id'],
                        'state' => CronTask::STATE_WAITING,
                        'lastrun' => date('Y-m-d H:i:s'),
                        'log' => 'Tâche réinitialisée manuellement via script monitoring'
                    ]);
                    
                    echo "Tâche cron réinitialisée: ID {$row['id']}\n";
                }
            }
        } else {
            echo "Aucune tâche cron correspondante trouvée.\n";
        }
    }
    
    echo "\nRéinitialisation terminée.\n";
}

/**
 * Nettoie les anciens fichiers
 */
function cleanupOldFiles()
{
    echo "=== NETTOYAGE DES ANCIENS FICHIERS ===\n\n";
    
    CronMonitor::cleanupOldHeartbeats(86400); // 24h
    
    echo "Nettoyage terminé.\n";
}

/**
 * Formate les octets en format lisible
 */
function formatBytes($size, $precision = 2)
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    
    for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
        $size /= 1024;
    }
    
    return round($size, $precision) . ' ' . $units[$i];
}

/**
 * Formate une durée en format lisible
 */
function formatDuration($seconds)
{
    $units = [
        'j' => 86400,
        'h' => 3600,
        'min' => 60,
        's' => 1
    ];
    
    $result = [];
    
    foreach ($units as $unit => $value) {
        if ($seconds >= $value) {
            $amount = floor($seconds / $value);
            $result[] = $amount . $unit;
            $seconds %= $value;
        }
    }
    
    return empty($result) ? '0s' : implode(' ', $result);
}