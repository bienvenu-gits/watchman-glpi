<?php
namespace GlpiPlugin\Watchman;

use CommonDBTM;
use CronTask;

class WatchmanCronHelper extends CommonDBTM {
    
    private static $tasks = [];
    
    /**
     * Enregistre une tâche cron
     */
    static function register($name, $function, $frequency = HOUR_TIMESTAMP) {
        // Stocker la fonction
        self::$tasks[$name] = $function;
        
        // Enregistrer dans GLPI
        CronTask::register(__CLASS__, $name, $frequency, [
            'comment' => "Tâche: $name",
            'mode' => CronTask::MODE_EXTERNAL
        ]);
    }
    
    /**
     * Exécute automatiquement la bonne fonction selon le nom de la tâche
     */
    static function __callStatic($method, $args) {
        if (strpos($method, 'cron') === 0) {
            $taskName = substr($method, 4); // Enlever 'cron'
            $task = $args[0] ?? null;
            
            if (isset(self::$tasks[$taskName])) {
                $function = self::$tasks[$taskName];
                return call_user_func($function, $task) ? 1 : 0;
            }
        }
        return 0;
    }


    static function registerOnce($name, $function) {
        self::register($name, function($task) use ($function, $name) {
            // Exécuter la fonction
            $result = call_user_func($function, $task);
            
            // Désactiver après exécution
            if ($task) {
                $task->fields['state'] = 0;
                $task->update($task->fields);
                echo "Tâche '$name' désactivée après exécution\n";
            }
            
            return $result;
        }, 10);
    }
}

