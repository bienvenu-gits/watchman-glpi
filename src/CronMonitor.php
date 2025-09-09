<?php

namespace GlpiPlugin\Watchman;

use CronTask;
use Exception;
use Toolbox;

/**
 * Classe pour surveiller et récupérer les tâches cron bloquées
 * Gère les cas où les tâches s'arrêtent brutalement (ex: dépassement mémoire)
 */
class CronMonitor
{
    const HEARTBEAT_FILE_PREFIX = 'watchman_cron_heartbeat_';
    const MAX_EXECUTION_TIME = 1800; // 30 minutes max
    const HEARTBEAT_INTERVAL = 60; // 1 minute
    
    /**
     * Démarre le monitoring d'une tâche cron
     * Crée un fichier heartbeat pour traquer l'exécution
     */
    public static function startMonitoring($cronTaskName, $taskId = null)
    {
        $heartbeatFile = self::getHeartbeatFile($cronTaskName, $taskId);
        
        $monitorData = [
            'task_name' => $cronTaskName,
            'task_id' => $taskId,
            'start_time' => time(),
            'last_heartbeat' => time(),
            'status' => 'running',
            'pid' => getmypid(),
            'memory_limit' => ini_get('memory_limit'),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        ];
        
        file_put_contents($heartbeatFile, json_encode($monitorData));
        
        // Log du démarrage
        Toolbox::logInFile('watchman_cron_monitor', 
            "Monitoring started for {$cronTaskName} (PID: " . getmypid() . ")");
    }
    
    /**
     * Met à jour le heartbeat pendant l'exécution
     */
    public static function updateHeartbeat($cronTaskName, $taskId = null, $extraData = [])
    {
        $heartbeatFile = self::getHeartbeatFile($cronTaskName, $taskId);
        
        if (!file_exists($heartbeatFile)) {
            return false;
        }
        
        $monitorData = json_decode(file_get_contents($heartbeatFile), true);
        if (!$monitorData) {
            return false;
        }
        
        $monitorData['last_heartbeat'] = time();
        $monitorData['memory_usage'] = memory_get_usage(true);
        $monitorData['peak_memory'] = memory_get_peak_usage(true);
        
        // Ajouter des données supplémentaires
        if (!empty($extraData)) {
            $monitorData = array_merge($monitorData, $extraData);
        }
        
        file_put_contents($heartbeatFile, json_encode($monitorData));
        return true;
    }
    
    /**
     * Termine le monitoring normalement
     */
    public static function stopMonitoring($cronTaskName, $taskId = null, $status = 'completed')
    {
        $heartbeatFile = self::getHeartbeatFile($cronTaskName, $taskId);
        
        if (file_exists($heartbeatFile)) {
            $monitorData = json_decode(file_get_contents($heartbeatFile), true);
            if ($monitorData) {
                $monitorData['status'] = $status;
                $monitorData['end_time'] = time();
                $monitorData['duration'] = time() - $monitorData['start_time'];
                
                file_put_contents($heartbeatFile, json_encode($monitorData));
            }
            
            // Nettoyer après quelques minutes
            self::scheduleCleanup($heartbeatFile, 300); // 5 minutes
        }
        
        Toolbox::logInFile('watchman_cron_monitor', 
            "Monitoring stopped for {$cronTaskName} with status: {$status}");
    }
    
    /**
     * Vérifie les tâches bloquées et les récupère
     */
    public static function checkForStuckTasks()
    {
        $heartbeatDir = self::getHeartbeatDirectory();
        $stuckTasks = [];
        
        if (!is_dir($heartbeatDir)) {
            return $stuckTasks;
        }
        
        $files = glob($heartbeatDir . '/' . self::HEARTBEAT_FILE_PREFIX . '*.json');
        
        foreach ($files as $file) {
            $monitorData = json_decode(file_get_contents($file), true);
            if (!$monitorData) {
                continue;
            }
            
            $timeSinceHeartbeat = time() - $monitorData['last_heartbeat'];
            
            // Vérifier si la tâche est bloquée
            if ($monitorData['status'] === 'running' && $timeSinceHeartbeat > self::MAX_EXECUTION_TIME) {
                $stuckTasks[] = $monitorData;
                
                // Tenter de récupérer la tâche
                self::recoverStuckTask($monitorData, $file);
            }
        }
        
        return $stuckTasks;
    }
    
    /**
     * Récupère une tâche bloquée
     */
    private static function recoverStuckTask($monitorData, $heartbeatFile)
    {
        $taskName = $monitorData['task_name'];
        $taskId = $monitorData['task_id'] ?? null;
        
        Toolbox::logInFile('watchman_cron_monitor', 
            "Recovering stuck task: {$taskName} (PID: {$monitorData['pid']})");
        
        // Marquer la tâche comme ayant échoué
        if ($taskId) {
            $cronTask = new CronTask();
            if ($cronTask->getFromDB($taskId)) {
                // Réinitialiser le statut de la tâche pour permettre une nouvelle exécution
                $cronTask->update([
                    'id' => $taskId,
                    'state' => CronTask::STATE_WAITING,
                    'lastrun' => date('Y-m-d H:i:s'),
                    'log' => 'Tâche récupérée automatiquement après blocage (dépassement mémoire probable)'
                ]);
                
                Toolbox::logInFile('watchman_cron_monitor', 
                    "CronTask {$taskId} status reset to WAITING");
            }
        } else {
            // Si pas d'ID de tâche, essayer de trouver par nom de classe et méthode
            self::resetCronTaskByName($taskName);
        }
        
        // Marquer le heartbeat comme récupéré
        $monitorData['status'] = 'recovered';
        $monitorData['recovery_time'] = time();
        file_put_contents($heartbeatFile, json_encode($monitorData));
        
        // Programmer le nettoyage
        self::scheduleCleanup($heartbeatFile, 60);
    }
    
    /**
     * Réinitialise une tâche cron par nom de classe
     */
    private static function resetCronTaskByName($taskName)
    {
        global $DB;
        
        // Extraire le nom de la classe et méthode
        $parts = explode('::', $taskName);
        if (count($parts) !== 2) {
            return false;
        }
        
        $className = $parts[0];
        $methodName = $parts[1];
        
        // Trouver la tâche cron correspondante
        $query = "SELECT id FROM glpi_crontasks 
                  WHERE itemtype = '" . $DB->escape($className) . "' 
                  AND name = '" . $DB->escape($methodName) . "'
                  AND state != " . CronTask::STATE_WAITING;
        
        $result = $DB->query($query);
        if ($result && $DB->numrows($result) > 0) {
            while ($row = $DB->fetchAssoc($result)) {
                $cronTask = new CronTask();
                if ($cronTask->getFromDB($row['id'])) {
                    $cronTask->update([
                        'id' => $row['id'],
                        'state' => CronTask::STATE_WAITING,
                        'lastrun' => date('Y-m-d H:i:s'),
                        'log' => 'Tâche récupérée automatiquement après blocage détecté'
                    ]);
                    
                    Toolbox::logInFile('watchman_cron_monitor', 
                        "Reset CronTask {$row['id']} ({$className}::{$methodName})");
                }
            }
            return true;
        }
        
        return false;
    }
    
    /**
     * Nettoie les anciens fichiers de monitoring
     */
    public static function cleanupOldHeartbeats($olderThanSeconds = 86400) // 24h
    {
        $heartbeatDir = self::getHeartbeatDirectory();
        
        if (!is_dir($heartbeatDir)) {
            return;
        }
        
        $files = glob($heartbeatDir . '/' . self::HEARTBEAT_FILE_PREFIX . '*.json');
        $cleaned = 0;
        
        foreach ($files as $file) {
            if (filemtime($file) < (time() - $olderThanSeconds)) {
                unlink($file);
                $cleaned++;
            }
        }
        
        if ($cleaned > 0) {
            Toolbox::logInFile('watchman_cron_monitor', 
                "Cleaned up {$cleaned} old heartbeat files");
        }
    }
    
    /**
     * Récupère le chemin du fichier heartbeat
     */
    private static function getHeartbeatFile($cronTaskName, $taskId = null)
    {
        $heartbeatDir = self::getHeartbeatDirectory();
        
        if (!is_dir($heartbeatDir)) {
            mkdir($heartbeatDir, 0755, true);
        }
        
        $filename = self::HEARTBEAT_FILE_PREFIX . 
                   str_replace(['\\', ':', ' '], '_', $cronTaskName);
        
        if ($taskId) {
            $filename .= '_' . $taskId;
        }
        
        return $heartbeatDir . '/' . $filename . '.json';
    }
    
    /**
     * Récupère le répertoire des fichiers heartbeat
     */
    private static function getHeartbeatDirectory()
    {
        return GLPI_ROOT . '/files/_tmp/watchman_heartbeats';
    }
    
    /**
     * Programme le nettoyage d'un fichier
     */
    private static function scheduleCleanup($file, $delaySeconds)
    {
        // Simple méthode : on programme via un fichier temporaire
        // Dans un vrai environnement, on pourrait utiliser un système de queue
        $cleanupFile = $file . '.cleanup';
        file_put_contents($cleanupFile, time() + $delaySeconds);
    }
    
    /**
     * Méthode principale de monitoring à appeler régulièrement
     */
    public static function runMonitoringCheck()
    {
        try {
            // Vérifier les tâches bloquées
            $stuckTasks = self::checkForStuckTasks();
            
            if (!empty($stuckTasks)) {
                Toolbox::logInFile('watchman_cron_monitor', 
                    "Found " . count($stuckTasks) . " stuck tasks");
            }
            
            // Nettoyer les anciens heartbeats
            self::cleanupOldHeartbeats();
            
            // Nettoyer les fichiers de cleanup
            self::processCleanupFiles();
            
        } catch (Exception $e) {
            Toolbox::logInFile('watchman_cron_monitor_error', 
                "Error in monitoring check: " . $e->getMessage());
        }
    }
    
    /**
     * Traite les fichiers de nettoyage programmé
     */
    private static function processCleanupFiles()
    {
        $heartbeatDir = self::getHeartbeatDirectory();
        
        if (!is_dir($heartbeatDir)) {
            return;
        }
        
        $cleanupFiles = glob($heartbeatDir . '/*.cleanup');
        
        foreach ($cleanupFiles as $cleanupFile) {
            $cleanupTime = (int)file_get_contents($cleanupFile);
            
            if (time() >= $cleanupTime) {
                $originalFile = str_replace('.cleanup', '', $cleanupFile);
                
                if (file_exists($originalFile)) {
                    unlink($originalFile);
                }
                unlink($cleanupFile);
            }
        }
    }
}