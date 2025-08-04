# Architecture Plugin GLPI Watchman

## Structure des dossiers

```
/plugins/watchman/
├── setup.php                          # Point d'entrée et configuration du plugin
├── hook.php                          # Hooks système GLPI
├── watchman.xml                      # Métadonnées du plugin
├── README.md                         # Documentation
├── LICENSE                           # Licence
├── /inc/                            # Classes métier
│   ├── config.class.php             # Configuration et paramètres API
│   ├── computer.class.php           # Extension Computer avec CVE
│   ├── software.class.php           # Extension Software avec CVE
│   ├── cve.class.php               # Entité CVE principale
│   ├── cvealert.class.php          # Alertes CVE
│   ├── cveticket.class.php         # Tickets CVE (extension Ticket)
│   ├── alertticketlink.class.php   # Liaison Alerte-Ticket
│   ├── patchstatus.class.php       # Statuts de patch
│   ├── cvenotification.class.php   # Notifications
│   ├── profile.class.php           # Gestion des droits
│   ├── /api/                       # Couche API
│   │   ├── apiconnector.class.php  # Connecteur API abstrait
│   │   ├── apiclient.class.php     # Client API spécialisé
│   │   └── apiexception.class.php  # Exceptions API
│   ├── /models/                       # Couche Model
│   │   ├── cvemodel.class.php      # Model CVE
│   │   ├── alertmodel.class.php    # Model Alert
│   │   ├── ticketmodel.class.php   # Model Ticket CVE
│   │   ├── computermodel.class.php # Model Computer étendu
│   │   ├── softwaremodel.class.php # Model Software étendu
│   │   └── configmodel.class.php
│   ├── /services/                  # Services métier
│   │   ├── syncservice.class.php   # Synchronisation Computer/Software
│   │   ├── cveservice.class.php    # Logique métier CVE
│   │   ├── alertservice.class.php  # Gestion des alertes
│   │   ├── alertsyncservice.class.php # Synchronisation alertes API
│   │   ├── cacheservice.class.php  # Gestion cache intelligent
│   │   ├── notificationservice.class.php # Notifications temps réel
│   │   ├── ticketservice.class.php # Gestion tickets CVE
│   │   ├── patchservice.class.php  # Logique de patch
│   │   ├── assignmentservice.class.php # Affectation techniciens
│   │   └── reportservice.class.php # Génération de rapports
│   ├── /repositories/              # Couche d'accès aux données
│   │   ├── cverepository.class.php
│   │   ├── computerrepository.class.php
│   │   ├── softwarerepository.class.php
│   │   ├── ticketrepository.class.php
│   │   └── alertrepository.class.php
│   ├── /dto/                       # Data Transfer Objects
│   │   ├── cvedto.class.php
│   │   ├── computerdto.class.php
│   │   ├── softwaredto.class.php
│   │   ├── ticketdto.class.php
│   │   └── alertdto.class.php
│   ├── /validators/                # Validation des données
│   │   ├── apivalidator.class.php
│   │   ├── cvevalidator.class.php
│   │   └── ticketvalidator.class.php
│   └── /utils/                     # Utilitaires
│       ├── logger.class.php        # Logging spécialisé
│       ├── cache.class.php        # Gestion du cache Redis/Local
│       ├── scheduler.class.php    # Tâches programmées (cron)
│       ├── syncmanager.class.php  # Orchestrateur synchronisation
│       ├── fallbackmanager.class.php # Gestion fallback API
│       ├── workflow.class.php     # Gestion workflow tickets
│       └── automation.class.php  # Automatisation patch
├── /front/                         # Interfaces utilisateur
│   ├── config.php                  # Page de configuration
│   ├── config.form.php            # Formulaire de configuration
│   ├── cve.php                    # Liste des CVE
│   ├── cve.form.php              # Détail d'un CVE
│   ├── cvealert.php              # Liste des alertes (cache local)
│   ├── cvealert.form.php         # Détail d'une alerte
│   ├── cveticket.php             # Liste tickets CVE
│   ├── cveticket.form.php        # Formulaire ticket CVE
│   ├── patchstatus.php           # Statuts de patch
│   ├── dashboard.php             # Dashboard général
│   ├── sync.php                  # Interface synchronisation manuelle
│   ├── computer.injector.php      # Injection dans Computer
│   ├── software.injector.php      # Injection dans Software
│   ├── ticket.injector.php        # Injection dans Ticket
│   └── /ajax/                     # Scripts AJAX
│       ├── sync_manual.php        # Synchronisation manuelle
│       ├── sync_status.php        # Statut synchronisation
│       ├── alerts_cached.php      # Chargement alertes cache
│       ├── alerts_refresh.php     # Refresh cache alertes
│       ├── tickets.php           # Gestion tickets
│       ├── assignment.php        # Affectation techniciens
│       ├── patch.php             # Actions de patch
│       ├── notifications.php     # Notifications temps réel
│       └── reports.php           # Génération rapports
├── /templates/                     # Templates Twig (optionnel)
│   ├── config.html.twig
│   ├── cve-list.html.twig
│   ├── alert-list.html.twig
│   ├── ticket-form.html.twig
│   ├── dashboard.html.twig
│   └── alert-widget.html.twig
├── /locales/                      # Internationalisation
│   ├── en_GB.po
│   ├── fr_FR.po
│   └── es_ES.po
├── /sql/                          # Scripts base de données
│   ├── install.sql               # Installation
│   ├── update-1.1.0.sql         # Migrations versionnées
│   └── uninstall.sql             # Désinstallation
├── /js/                          # JavaScript
│   ├── watchman.js              # JS principal
│   ├── alerts.js                # Gestion alertes (cache)
│   ├── sync.js                  # Interface synchronisation
│   ├── realtime.js              # Notifications temps réel
│   ├── tickets.js               # Gestion tickets
│   ├── dashboard.js             # Dashboard interactif
│   ├── assignment.js            # Affectation techniciens
│   └── cache-manager.js         # Gestion cache côté client
├── /css/                         # Styles CSS
│   ├── watchman.css             # Styles principaux
│   ├── alerts.css               # Styles alertes
│   ├── tickets.css              # Styles tickets
│   └── dashboard.css            # Styles dashboard
├── /cron/                        # Tâches automatisées
│   ├── sync_alerts.php          # Sync périodique alertes (10min)
│   ├── sync_critical.php        # Sync alertes critiques (5min)
│   ├── cleanup_cache.php        # Nettoyage cache expiré
│   ├── generate_reports.php     # Rapports automatiques
│   └── health_check.php         # Vérification santé API
├── /migrations/                  # Migrations de données
│   └── migration_1_0_to_1_1.php
├── /tests/                       # Tests unitaires et intégration
│   ├── /unit/
│   ├── /integration/
│   └── bootstrap.php
└── /docs/                        # Documentation technique
    ├── api.md
    ├── installation.md
    └── development.md
```

## Description détaillée des fichiers

### Fichiers racine

- **setup.php** : Configuration plugin, versions, prérequis, fonctions d'installation/désinstallation
- **hook.php** : Hooks GLPI (ajout onglets, menus, actions sur Computer/Software)
- **cvemanager.xml** : Métadonnées, dépendances, informations plugin

### Classes métier (/inc/)

- **config.class.php** : Configuration API, paramètres globaux, validation clés API
- **computer.class.php** : Extension Computer, méthodes CVE spécifiques, onglets
- **software.class.php** : Extension Software, vulnérabilités logicielles
- **cve.class.php** : Entité CVE complète (CRUD, recherche, classification)
- **cvealert.class.php** : Alertes CVE, notifications, seuils critiques, liaison tickets
- **cveticket.class.php** : Extension Ticket pour CVE, workflow spécialisé
- **alertticketlink.class.php** : Liaison N:N entre alertes et tickets
- **patchstatus.class.php** : États de patch (Non patché, En cours, Patché, Vérifié)
- **cvenotification.class.php** : Système de notifications (email, dashboard)
- **profile.class.php** : Gestion droits et permissions par profil

### Couche API (/inc/api/)

- **apiconnector.class.php** : Interface abstraite, gestion connexions, retry logic
- **apiclient.class.php** : Implémentation concrète API CVE, authentification
- **apiexception.class.php** : Exceptions spécialisées API (timeout, auth, rate limit)

### Services métier (/inc/services/)

- **syncservice.class.php** : Orchestration synchronisation, mapping données
- **cveservice.class.php** : Logique métier CVE, scoring, classification
- **alertservice.class.php** : Gestion alertes, règles de déclenchement, liaison tickets
- **ticketservice.class.php** : Création tickets CVE, workflow automatisé
- **patchservice.class.php** : Logique de patch, suivi statuts, validation
- **assignmentservice.class.php** : Affectation automatique techniciens par compétence
- **reportservice.class.php** : Génération rapports, statistiques, KPI

### Repositories (/inc/repositories/)

- **cverepository.class.php** : Accès données CVE, requêtes complexes
- **computerrepository.class.php** : Requêtes spécialisées Computer+CVE
- **ticketrepository.class.php** : Requêtes spécialisées tickets CVE
- **alertrepository.class.php** : Requêtes alertes avec statuts patch

### DTO (/inc/dto/)

- **cvedto.class.php** : Objet transfert CVE, sérialisation/désérialisation
- **computerdto.class.php** : DTO Computer enrichi CVE
- **ticketdto.class.php** : DTO Ticket CVE avec métadonnées spécialisées
- **alertdto.class.php** : DTO Alerte avec statut patch et liaison ticket

### Validators (/inc/validators/)

- **apivalidator.class.php** : Validation réponses API, format données
- **ticketvalidator.class.php** : Validation données tickets CVE, règles métier

### Utilitaires (/inc/utils/)

- **logger.class.php** : Logging avancé, rotation logs, niveaux debug
- **cache.class.php** : Cache Redis/Memcached, invalidation intelligente
- **workflow.class.php** : Gestion workflow tickets, transitions d'états
- **syncmanager.class.php** : Orchestrateur sync, gestion intervalles
- **fallbackmanager.class.php** : Basculement API/cache, mode dégradé

### Interfaces (/front/)

- **config.php/.form.php** : Interface configuration API, test connexion
- **cve.php/.form.php** : CRUD CVE, recherche avancée, filtres
- **cvealert.php/.form.php** : CRUD alertes, création tickets, affectation
- **cveticket.php/.form.php** : Gestion tickets CVE, suivi patch
- **patchstatus.php** : Vue d'ensemble statuts patch
- **dashboard.php** : Dashboard alertes/tickets, KPI sécurité
- **ticket.injector.php** : Injection onglet CVE dans tickets standard
- ***.injector.php** : Injection onglets CVE dans Computer/Software

### AJAX (/front/ajax/)

- **sync.php** : Synchronisation asynchrone, progress bar
- **alerts.php** : Chargement temps réel alertes, notifications push
- **tickets.php** : Gestion tickets CVE, changement statuts
- **assignment.php** : Affectation automatique/manuelle techniciens
- **sync_manual.php** : Déclenchement synchronisation manuelle
- **sync_status.php** : Statut temps réel synchronisation
- **alerts_cached.php** : Chargement ultra-rapide depuis cache
- **alerts_refresh.php** : Refresh sélectif du cache
- **notifications.php** : Push notifications WebSocket/SSE

### Base de données (/sql/)

### Tâches CRON (/cron/)

- **sync_alerts.php** : Synchronisation générale (toutes les 10 minutes)
- **sync_critical.php** : Sync prioritaire critiques (toutes les 5 minutes)  
- **cleanup_cache.php** : Nettoyage cache expiré (quotidien)
- **generate_reports.php** : Génération rapports automatiques
- **health_check.php** : Vérification santé API et services
- **update-*.sql** : Migrations versionnées, ALTER TABLE
### Base de données (/sql/)

- **install.sql** : Tables CVE, alertes, cache, tickets, liaisons, statuts patch
- **update-*.sql** : Migrations versionnées, ALTER TABLE
- **uninstall.sql** : Nettoyage complet, suppression données

### Assets (/js/, /css/)

- **watchman.js** : Interface utilisateur, interactions AJAX
- **alerts.js** : Notifications temps réel, WebSocket/SSE
- **tickets.js** : Interface tickets CVE, actions rapides, workflow
- **dashboard.js** : Dashboard interactif, graphiques temps réel
- **sync.js** : Interface synchronisation, progress tracking
- **realtime.js** : WebSocket/SSE pour notifications temps réel
- **cache-manager.js** : Gestion cache côté client, localStorage

## Nouvelles tables base de données

### glpi_plugin_watchman_alert_cache
- id, alert_id, api_data (JSON), cached_date, ttl
- hash_key, is_expired, severity, last_sync

### glpi_plugin_watchman_sync_log
- id, sync_type, start_time, end_time, status
- alerts_count, errors_count, api_response_time

### glpi_plugin_watchman_config_sync
- id, param_name, param_value
- sync_interval_normal, sync_interval_critical
- cache_ttl, max_retries, api_timeout

### glpi_plugin_watchman_cve_alerts
- id, cve_id, computer_id, software_id, severity, status
- patch_status (not_patched, in_progress, patched, verified)
- created_date, detected_date, patched_date
- assigned_user_id, assigned_group_id

### glpi_plugin_watchman_alert_ticket_links  
- id, alert_id, ticket_id, link_type
- created_date, created_by

### glpi_plugin_watchman_patch_status
- id, alert_id, status, changed_date, changed_by
- verification_date, verification_method
- comments

### glpi_plugin_watchman_ticket_templates
- id, name, title_template, content_template
- category_id, priority, severity_mapping

## Workflow automatisé

1. **Détection CVE** → Création alerte automatique
2. **Alerte critique** → Création ticket automatique + affectation
3. **Ticket résolu** → Marquage alerte "patché" automatique  
4. **Validation patch** → Statut "vérifié" + fermeture cycle

## Règles d'affectation

- **Par criticité** : CRITICAL → Équipe sécurité senior
- **Par type logiciel** : OS → Admins système, Apps → Dev
- **Par disponibilité** : Répartition charge techniciens
## Architecture de synchronisation hybride

```
┌─────────────────┐    ┌──────────────────┐    ┌─────────────────┐
│   API Externe   │───▶│  AlertSyncService│───▶│  Cache Local    │
│   (CVE/Alertes) │    │  (Cron 5-10min)  │    │  (Ultra rapide) │
└─────────────────┘    └──────────────────┘    └─────────────────┘
        │                        │                        │
        │ (Fallback direct)      │ (Notifications)        │ (Interface)
        ▼                        ▼                        ▼
┌─────────────────┐    ┌──────────────────┐    ┌─────────────────┐
│ FallbackManager │    │ NotificationSvc  │    │  Interface Web  │
│ (Mode dégradé)  │    │ (Push temps réel)│    │  (Cache + RT)   │
└─────────────────┘    └──────────────────┘    └─────────────────┘
```

## Stratégie de cache intelligente

### Niveaux de cache :
1. **Cache L1** : Redis/Memcached (TTL: 15min)
2. **Cache L2** : Base données locale (TTL: 1h)  
3. **Cache L3** : localStorage client (TTL: 5min)

### Invalidation :
- **Smart refresh** : Seules les alertes modifiées
- **Cascade invalidation** : L1 → L2 → L3
- **Event-driven** : Invalidation sur changement statut

## Configuration synchronisation

```php
// Intervalles configurables
SYNC_INTERVAL_NORMAL = 600;     // 10 minutes
SYNC_INTERVAL_CRITICAL = 300;   // 5 minutes urgences
SYNC_INTERVAL_PEAK = 120;       // 2 minutes heures pointe

// Cache TTL
CACHE_TTL_NORMAL = 900;         // 15 minutes
CACHE_TTL_CRITICAL = 300;       // 5 minutes critiques
CACHE_TTL_CLIENT = 300;         // 5 minutes côté client

// Fallback
API_TIMEOUT = 30;               // 30 secondes max
MAX_RETRIES = 3;                // 3 tentatives
FALLBACK_MODE_DURATION = 1800;  // 30 min mode dégradé
```

1. **Séparation des responsabilités** : API, Services, Repositories
2. **Injection de dépendances** : Services configurables
3. **Pattern Repository** : Abstraction accès données
4. **DTO Pattern** : Transfert données sécurisé
5. **Exception handling** : Gestion erreurs centralisée
6. **Caching strategy** : Performance optimisée
7. **Event-driven** : Hooks et notifications
8. **Workflow automation** : Gestion cycle de vie tickets
9. **Testabilité** : Architecture modulaire testable

L'architecture sépare clairement les couches (API, Services, Repositories) et utilise des patterns éprouvés pour garantir un code robuste et évolutif avec une gestion complète du cycle de vie des vulnérabilités via les tickets GLPI.