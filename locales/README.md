# Gestion des Traductions - Plugin Watchman

Ce dossier contient tous les fichiers de traduction pour le plugin GLPI Watchman.

## Structure des Fichiers

```
locales/
├── README.md           # Ce fichier
├── watchman.pot       # Template de traduction (source)
├── fr_FR.po           # Traduction française
├── en_GB.po           # Traduction anglaise
├── fr_FR.mo           # Fichier compilé français (généré automatiquement)
└── en_GB.mo           # Fichier compilé anglais (généré automatiquement)
```

## Types de Fichiers

### .pot (Portable Object Template)
- Fichier template contenant toutes les chaînes à traduire
- Sert de base pour créer de nouveaux fichiers de traduction
- Généré automatiquement à partir du code source

### .po (Portable Object)
- Fichiers de traduction pour chaque langue
- Format texte modifiable par les traducteurs
- Contient les chaînes originales et leurs traductions

### .mo (Machine Object)
- Version compilée des fichiers .po
- Format binaire utilisé par GLPI pour afficher les traductions
- Généré automatiquement à partir des fichiers .po

## Commandes Disponibles

### Extraction des Chaînes de Traduction
```bash
# Extraire toutes les chaînes du code source
php tools/extract_strings.php

# Ou avec Make
make extract
```

### Compilation des Traductions
```bash
# Compiler tous les fichiers .po en .mo
php tools/compile_translations.php

# Ou avec Make
make compile
```

### Processus Complet
```bash
# Extraction + Compilation
make translations
```

## Ajouter une Nouvelle Langue

### Méthode Manuelle
1. Copier le fichier `watchman.pot` vers `[code_langue].po`
2. Modifier l'en-tête du fichier
3. Traduire les chaînes
4. Compiler avec `make compile`

### Méthode Automatique
```bash
# Créer un fichier pour l'espagnol par exemple
make new-lang LANG=es_ES
```

## Format des Fichiers de Traduction

### En-tête Standard
```
"Project-Id-Version: GLPI Plugin Watchman\n"
"Language: fr_FR\n"
"MIME-Version: 1.0\n"
"Content-Type: text/plain; charset=UTF-8\n"
"Content-Transfer-Encoding: 8bit\n"
```

### Exemple d'Entrée
```
#: templates/config.form.html.twig
msgid "Configuration"
msgstr "Configuration"
```

## Conventions de Traduction

### Utilisation dans le Code PHP
```php
// Traduction simple
echo __('Configuration', 'watchman');

// Traduction avec échappement HTML
_e('Configuration', 'watchman');

// Traduction avec contexte
echo _x('Configuration', 'menu item', 'watchman');

// Traduction avec pluriel
echo _n('1 élément', '%d éléments', $count, 'watchman');
```

### Utilisation dans les Templates Twig
```twig
{# Traduction simple #}
{{ __('Configuration', 'watchman') }}

{# Dans les attributs #}
<input placeholder="{{ __('Saisissez votre texte', 'watchman') }}">
```

## Bonnes Pratiques

### 1. Cohérence des Termes
- Utilisez toujours les mêmes termes pour les concepts identiques
- Maintenez un glossaire des termes techniques

### 2. Contexte
- Ajoutez des commentaires pour clarifier le contexte
- Utilisez `_x()` pour les termes ambigus

### 3. Variables
- Préservez les placeholders (`%s`, `%d`, `%1$s`, etc.)
- Respectez l'ordre des variables dans les traductions

### 4. Ponctuation
- Adaptez la ponctuation aux conventions de chaque langue
- Attention aux espaces insécables en français

## Vérification des Traductions

### Validation Automatique
```bash
# Vérifier tous les fichiers .po
make check
```

### Tests Manuels
1. Activer la langue dans GLPI
2. Naviguer dans l'interface du plugin
3. Vérifier que toutes les chaînes sont traduites

## Maintenance

### Mise à Jour des Traductions
1. Après modification du code source :
   ```bash
   make update
   ```
2. Traduire les nouvelles chaînes dans les fichiers .po
3. Compiler les traductions :
   ```bash
   make compile
   ```

### Nettoyage
```bash
# Supprimer les fichiers temporaires
make clean
```

## Support des Langues

### Langues Actuellement Supportées
- **Français (fr_FR)** - Langue principale ✅
- **Anglais (en_GB)** - Traduction complète ✅

### Langues Prévues
- Espagnol (es_ES)
- Allemand (de_DE)
- Italien (it_IT)

## Dépannage

### Problèmes Courants

#### Les traductions ne s'affichent pas
1. Vérifier que les fichiers .mo sont présents
2. Vider le cache de GLPI
3. Vérifier les permissions des fichiers

#### Chaînes non traduites
1. Vérifier que la chaîne utilise le bon domaine ('watchman')
2. Régénérer le fichier .pot
3. Mettre à jour les fichiers .po

#### Erreurs de compilation
1. Vérifier la syntaxe des fichiers .po
2. Utiliser `make check` pour détecter les erreurs
3. Corriger les caractères spéciaux mal échappés

## Contact

Pour toute question sur les traductions :
- Équipe de développement : Global IT Service
- Rapporter un problème : Créer une issue sur le dépôt du projet