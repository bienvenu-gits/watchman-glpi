# Système de Monitoring des Tâches Cron Watchman

## Problème résolu

Le cron `CronSyncAlerts` peut consommer beaucoup de mémoire lors de la synchronisation des alertes et s'arrêter brutalement (dépassement mémoire, timeout, etc.) sans pouvoir mettre à jour son statut dans GLPI. Cela bloque les prochaines exécutions car GLPI pense que la tâche est toujours en cours.

## Solution implémentée

Un système de monitoring automatique qui :

1. **Surveille les tâches en temps réel** avec des fichiers "heartbeat"
2. **Détecte automatiquement les blocages** (absence de heartbeat > 30 minutes)
3. **Récupère automatiquement les tâches bloquées** en réinitialisant leur statut
4. **Optimise l'utilisation mémoire** avec arrêt préventif à 80% de la limite

## Composants

### 1. CronMonitor (src/CronMonitor.php)

Classe principale de monitoring qui :
- Crée des fichiers heartbeat pour tracker l'exécution
- Vérifie régulièrement les tâches bloquées
- Réinitialise automatiquement les tâches GLPI
- Nettoie les anciens fichiers

### 2. CronSyncAlert modifié

Intégration du monitoring dans la tâche principale :
- Démarrage du monitoring au début
- Heartbeats réguliers pendant l'exécution
- Vérification mémoire préventive (arrêt à 80%)
- Arrêt propre du monitoring à la fin

### 3. Tâche cron de monitoring

Nouvelle tâche cron `MonitorTasks` qui :
- S'exécute toutes les 5 minutes
- Vérifie les tâches bloquées automatiquement
- Récupère les tâches si nécessaire

### 4. Script d'administration (scripts/monitor_cron.php)

Outil en ligne de commande pour :
- Voir le statut des tâches surveillées
- Forcer la récupération des tâches bloquées
- Réinitialiser une tâche spécifique
- Nettoyer les anciens fichiers

## Utilisation

### Installation automatique

Lors de la mise à jour du plugin, les nouvelles tâches cron sont automatiquement installées :
- `SyncAlerts` : Synchronisation des alertes (existante)
- `MonitorTasks` : Surveillance des tâches (nouvelle)

### Utilisation du script d'administration

```bash
# Voir le statut des tâches
php plugins/watchman/scripts/monitor_cron.php status

# Vérifier et récupérer les tâches bloquées
php plugins/watchman/scripts/monitor_cron.php check

# Réinitialiser une tâche spécifique
php plugins/watchman/scripts/monitor_cron.php reset "GlpiPlugin\\Watchman\\CronSyncAlert::cronSyncAlerts"

# Nettoyer les anciens fichiers
php plugins/watchman/scripts/monitor_cron.php cleanup
```

### Surveillance manuelle

Les fichiers heartbeat sont stockés dans `GLPI_TMP_DIR/watchman_heartbeats/` :

```json
{
  "task_name": "GlpiPlugin\\Watchman\\CronSyncAlert::cronSyncAlerts",
  "start_time": 1704067200,
  "last_heartbeat": 1704067260,
  "status": "running",
  "memory_usage": 134217728,
  "current_page": 3,
  "total_processed": 150
}
```

## Optimisations mémoire

### 1. Limite mémoire augmentée
- Passage de 500M à 1G pour éviter les dépassements

### 2. Surveillance continue
- Vérification du pourcentage mémoire utilisé
- Arrêt préventif à 80% de la limite

### 3. Heartbeats informatifs
- Suivi de l'utilisation mémoire en temps réel
- Information sur la page en cours et nombre traité

## Logs et debugging

### Fichiers de log
- `watchman_cron_monitor.log` : Événements de monitoring
- `watchman_monitor_error.log` : Erreurs du système de monitoring
- `watchman_alerts_critical.log` : Erreurs critiques des alertes (existant)

### Informations de debugging
Le système log automatiquement :
- Démarrage et arrêt des tâches surveillées
- Détection de tâches bloquées
- Récupération automatique
- Nettoyage des fichiers anciens

## Configuration

### Paramètres par défaut
- **Temps maximum d'exécution** : 30 minutes (1800 secondes)
- **Intervalle heartbeat** : 1 minute (60 secondes)
- **Seuil mémoire critique** : 80% de la limite PHP
- **Intervalle de monitoring** : 5 minutes

### Personnalisation
Les constantes peuvent être modifiées dans `CronMonitor.php` :
```php
const MAX_EXECUTION_TIME = 1800;    // 30 minutes
const HEARTBEAT_INTERVAL = 60;      // 1 minute
```

## Dépannage

### Tâche toujours bloquée
1. Vérifier les logs : `watchman_cron_monitor.log`
2. Utiliser le script : `php monitor_cron.php status`
3. Réinitialiser manuellement : `php monitor_cron.php reset [nom_tache]`

### Consommation mémoire excessive
1. Vérifier les heartbeats pour voir l'évolution mémoire
2. Réduire `PAGINATION_LIMIT` dans CronSyncAlert
3. Augmenter la limite PHP si nécessaire

### Fichiers heartbeat qui s'accumulent
1. La tâche `MonitorTasks` nettoie automatiquement
2. Nettoyage manuel : `php monitor_cron.php cleanup`
3. Vérifier les permissions sur `GLPI_TMP_DIR`

## Exemple de récupération automatique

```
[2024-01-01 14:30:00] Monitoring started for CronSyncAlert::cronSyncAlerts (PID: 12345)
[2024-01-01 14:45:00] Heartbeat updated - Page 15, Memory: 450MB
[2024-01-01 15:15:00] Found 1 stuck tasks
[2024-01-01 15:15:00] Recovering stuck task: CronSyncAlert::cronSyncAlerts (PID: 12345)
[2024-01-01 15:15:01] CronTask 123 status reset to WAITING
```

Ce système garantit que les tâches cron ne restent jamais définitivement bloquées et peuvent toujours être relancées automatiquement.