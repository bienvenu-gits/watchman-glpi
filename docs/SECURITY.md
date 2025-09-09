# Système de Sécurité et Droits Watchman

## Vue d'ensemble

Le plugin Watchman implémente un système de droits granulaire pour contrôler l'accès aux différentes fonctionnalités. Par défaut, seuls les administrateurs Super-Admin ont accès au plugin.

## Droits disponibles

### 1. **Voir les alertes** (`plugin_watchman_view`)
- **Description** : Permet de consulter les alertes et ordinateurs synchronisés
- **Accès** : Pages d'alertes, consultation des détails
- **Niveau minimum** : Lecture

### 2. **Gérer les alertes** (`plugin_watchman_manage`)
- **Description** : Permet de marquer les alertes comme corrigées, créer des tickets
- **Accès** : Actions sur les alertes, création de tickets, actions groupées
- **Niveau minimum** : Modification

### 3. **Administration** (`plugin_watchman_admin`)
- **Description** : Permet de gérer les mappings d'ordinateurs et la synchronisation
- **Accès** : Gestion des ordinateurs synchronisés, détails des ordinateurs
- **Niveau minimum** : Lecture pour voir, Modification pour gérer

### 4. **Configuration** (`plugin_watchman_config`)
- **Description** : Permet de modifier la configuration du plugin et de l'API
- **Accès** : Configuration API, paramètres du plugin, gestion des profils
- **Niveau minimum** : Modification

### 5. **Tâches CRON** (`plugin_watchman_cron`)
- **Description** : Permet de lancer et surveiller les tâches de synchronisation
- **Accès** : Lancement des synchronisations manuelles
- **Niveau minimum** : Modification

## Niveaux de droits GLPI

- **Aucun (0)** : Pas d'accès
- **Lecture (1)** : Consultation uniquement  
- **Modification (2)** : Consultation + modification
- **Création (4)** : + création d'éléments
- **Suppression (8)** : + suppression d'éléments
- **Purge (16)** : + suppression définitive

## Installation automatique

Lors de l'installation du plugin, les droits sont automatiquement :
1. **Créés** dans la table `glpi_profilerights`
2. **Assignés au profil Super-Admin** avec tous les droits
3. **Disponibles** pour attribution aux autres profils

## Configuration des droits

### Via l'interface web

1. **Accéder à la gestion des profils** :
   - Menu Watchman → Gestion des profils
   - Sélectionner le profil à configurer

2. **Configurer les droits** :
   - Cocher les permissions appropriées pour chaque fonctionnalité
   - Sauvegarder les modifications

### Via GLPI standard

Les droits Watchman apparaissent également dans :
- **Administration → Profils → [Profil] → Autorisation**
- Section dédiée aux plugins

## Sécurisation des accès

### Pages front-end
Chaque page vérifie les droits requis :
```php
// Vérification générale d'accès au plugin
WatchmanProfile::checkPluginAccess();

// Vérification spécifique d'un droit
WatchmanProfile::checkRightOr403(
    WatchmanProfile::RIGHT_WATCHMAN_VIEW, 
    WatchmanProfile::READ
);
```

### Endpoints AJAX
Vérifications par type d'action :
- **Lecture** (`get_alerts`, `get_stats`) → Droit VIEW
- **Modification** (`mark_as_patched`, `bulk_action`) → Droit MANAGE  
- **Suppression** (`delete_alert`) → Droit MANAGE + DELETE
- **Administration** (`computer_*`) → Droit ADMIN
- **CRON** (`start_cron`) → Droit CRON

### Menus contextuels
Le menu s'adapte automatiquement aux droits :
- **Alertes** : Si droit VIEW ou supérieur
- **Ordinateurs** : Si droit ADMIN
- **Configuration** : Si droit CONFIG
- **Profils** : Si droit CONFIG

## Exemples de configuration

### Profil "Responsable Sécurité"
- ✅ **Voir les alertes** : Lecture
- ✅ **Gérer les alertes** : Modification
- ❌ **Administration** : Aucun
- ❌ **Configuration** : Aucun
- ❌ **Tâches CRON** : Aucun

### Profil "Technicien IT"
- ✅ **Voir les alertes** : Lecture  
- ✅ **Gérer les alertes** : Lecture
- ✅ **Administration** : Lecture
- ❌ **Configuration** : Aucun
- ❌ **Tâches CRON** : Aucun

### Profil "Administrateur Watchman"
- ✅ **Voir les alertes** : Modification
- ✅ **Gérer les alertes** : Tous droits
- ✅ **Administration** : Tous droits
- ✅ **Configuration** : Tous droits
- ✅ **Tâches CRON** : Modification

## Messages d'erreur

### Accès refusé
- **Page web** : Redirection vers l'accueil avec message d'erreur
- **AJAX** : Réponse HTTP 403 avec JSON `{"error": "message"}`

### Droits insuffisants
- **Interface** : Message contextuel expliquant le droit requis
- **Log** : Tentatives d'accès non autorisé enregistrées

## Bonnes pratiques

### Attribution des droits
1. **Principe du moindre privilège** : Donner uniquement les droits nécessaires
2. **Séparation des responsabilités** : 
   - Consultation ≠ Administration
   - Gestion courante ≠ Configuration
3. **Révision régulière** : Vérifier périodiquement les accès accordés

### Sécurité opérationnelle
1. **Accès configuration** : Restreindre aux administrateurs système
2. **Droits CRON** : Limiter aux utilisateurs de confiance
3. **Surveillance** : Monitorer les actions sensibles

## Dépannage

### Plugin invisible dans le menu
- Vérifier que l'utilisateur a au moins un droit Watchman
- Contrôler la configuration du profil dans Administration → Profils

### Erreur 403 sur les actions
- Vérifier le niveau de droit requis pour l'action
- S'assurer que l'utilisateur a le bon type de permission (lecture/modification)

### Droits non sauvegardés
- Vérifier les permissions sur la base de données
- Contrôler les logs d'erreur PHP/GLPI

## Migration et mise à jour

Lors des mises à jour du plugin :
1. **Conservation des droits** : Les droits existants sont préservés
2. **Nouveaux droits** : Ajoutés automatiquement avec valeurs par défaut
3. **Compatibilité** : Rétrocompatible avec les versions antérieures

Les administrateurs doivent ensuite attribuer manuellement les nouveaux droits selon leurs besoins.