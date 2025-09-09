<?php

namespace GlpiPlugin\Watchman;

use CommonDBTM;
use Profile;
use ProfileRight;
use Session;

/**
 * Classe pour la gestion des profils et droits du plugin Watchman
 */
class WatchmanProfile extends CommonDBTM
{
    // Définition des droits du plugin
    const RIGHT_WATCHMAN_VIEW = 'plugin_watchman_view';
    const RIGHT_WATCHMAN_MANAGE = 'plugin_watchman_manage';
    const RIGHT_WATCHMAN_ADMIN = 'plugin_watchman_admin';
    const RIGHT_WATCHMAN_CONFIG = 'plugin_watchman_config';
    const RIGHT_WATCHMAN_CRON = 'plugin_watchman_cron';
    
    // Valeurs des droits (utilise les constantes GLPI)
    const NONE = 0;
    const READ = 1;
    const UPDATE = 2;
    const CREATE = 4;
    const DELETE = 8;
    const PURGE = 16;
    const ALLSTANDARDRIGHT = 31; // READ + UPDATE + CREATE + DELETE + PURGE
    
    static $rightname = 'plugin_watchman_profile';

    /**
     * Installation des droits du plugin
     */
    public static function installRights()
    {
        global $DB;
        
        $rights = self::getAllRights();
        
        // Créer les droits pour tous les profils existants
        $profiles = $DB->request('SELECT id FROM glpi_profiles');
        
        foreach ($rights as $rightname => $config) {
            foreach ($profiles as $profile) {
                $profile_id = $profile['id'];
                
                // Vérifier si le droit existe déjà
                $existing = $DB->request([
                    'FROM' => 'glpi_profilerights',
                    'WHERE' => [
                        'profiles_id' => $profile_id,
                        'name' => $rightname
                    ]
                ]);
                
                if (count($existing) == 0) {
                    // Déterminer la valeur par défaut pour ce profil
                    $default_value = $config['default_values'][$profile_id] ?? self::NONE;
                    
                    // Insérer le nouveau droit
                    $DB->insert('glpi_profilerights', [
                        'profiles_id' => $profile_id,
                        'name' => $rightname,
                        'rights' => $default_value
                    ]);
                }
            }
        }
    }
    
    /**
     * Désinstallation des droits du plugin
     */
    public static function uninstallRights()
    {
        global $DB;
        
        $rights = array_keys(self::getAllRights());
        
        foreach ($rights as $rightname) {
            $DB->delete('glpi_profilerights', [
                'name' => $rightname
            ]);
        }
    }
    
    /**
     * Définition de tous les droits du plugin
     */
    public static function getAllRights()
    {
        return [
            self::RIGHT_WATCHMAN_VIEW => [
                'label' => __('Voir les alertes Watchman', 'watchman'),
                'field' => 'plugin_watchman_view',
                'default_values' => [
                    4 => self::READ, // Super-Admin: lecture
                ],
                'description' => __('Permet de voir les alertes et ordinateurs synchronisés', 'watchman')
            ],
            self::RIGHT_WATCHMAN_MANAGE => [
                'label' => __('Gérer les alertes Watchman', 'watchman'),
                'field' => 'plugin_watchman_manage',
                'default_values' => [
                    4 => self::ALLSTANDARDRIGHT, // Super-Admin: tous droits
                ],
                'description' => __('Permet de marquer les alertes comme corrigées, créer des tickets', 'watchman')
            ],
            self::RIGHT_WATCHMAN_ADMIN => [
                'label' => __('Administration Watchman', 'watchman'),
                'field' => 'plugin_watchman_admin',
                'default_values' => [
                    4 => self::ALLSTANDARDRIGHT, // Super-Admin: tous droits
                ],
                'description' => __('Permet de gérer les mappings d\'ordinateurs et la synchronisation', 'watchman')
            ],
            self::RIGHT_WATCHMAN_CONFIG => [
                'label' => __('Configuration Watchman', 'watchman'),
                'field' => 'plugin_watchman_config',
                'default_values' => [
                    4 => self::ALLSTANDARDRIGHT, // Super-Admin: tous droits
                ],
                'description' => __('Permet de modifier la configuration du plugin et l\'API', 'watchman')
            ],
            self::RIGHT_WATCHMAN_CRON => [
                'label' => __('Tâches CRON Watchman', 'watchman'),
                'field' => 'plugin_watchman_cron',
                'default_values' => [
                    4 => self::ALLSTANDARDRIGHT, // Super-Admin: tous droits
                ],
                'description' => __('Permet de lancer et surveiller les tâches de synchronisation', 'watchman')
            ]
        ];
    }
    
    /**
     * Ajoute des droits à un profil spécifique
     */
    public static function addRightsToProfile($profile_id, $rights, $value = self::ALLSTANDARDRIGHT)
    {
        $profile = new Profile();
        if (!$profile->getFromDB($profile_id)) {
            return false;
        }
        
        foreach ($rights as $rightname) {
            $profileRight = new ProfileRight();
            $profileRight->updateProfileRights($profile_id, [$rightname => $value]);
        }
        
        return true;
    }
    
    /**
     * Vérifie si l'utilisateur est super-administrateur
     */
    public static function isSuperAdmin()
    {
        return isset($_SESSION['glpiactiveprofile']['id']) && $_SESSION['glpiactiveprofile']['id'] == 4;
    }

    /**
     * Vérifie si l'utilisateur actuel a un droit spécifique
     */
    public static function haveRight($rightname, $right = self::READ)
    {
        // Si l'utilisateur est super-admin, il a tous les droits
        if (self::isSuperAdmin()) {
            return true;
        }
        
        return Session::haveRight($rightname, $right);
    }
    
    /**
     * Vérifie si l'utilisateur peut voir le plugin
     */
    public static function canView()
    {
        // Si l'utilisateur est super-admin, il a accès à tout
        if (self::isSuperAdmin()) {
            return true;
        }
        
        return self::haveRight(self::RIGHT_WATCHMAN_VIEW, self::READ) ||
               self::haveRight(self::RIGHT_WATCHMAN_MANAGE, self::READ) ||
               self::haveRight(self::RIGHT_WATCHMAN_ADMIN, self::READ) ||
               self::haveRight(self::RIGHT_WATCHMAN_CONFIG, self::READ) ||
               self::haveRight(self::RIGHT_WATCHMAN_CRON, self::READ);
    }
    
    /**
     * Vérifie si l'utilisateur peut gérer les alertes
     */
    public static function canManageAlerts()
    {
        // Si l'utilisateur est super-admin, il a accès à tout
        if (self::isSuperAdmin()) {
            return true;
        }
        
        return self::haveRight(self::RIGHT_WATCHMAN_MANAGE, self::UPDATE);
    }
    
    /**
     * Vérifie si l'utilisateur peut administrer
     */
    public static function canAdmin()
    {
        // Si l'utilisateur est super-admin, il a accès à tout
        if (self::isSuperAdmin()) {
            return true;
        }
        
        return self::haveRight(self::RIGHT_WATCHMAN_ADMIN, self::UPDATE);
    }
    
    /**
     * Vérifie si l'utilisateur peut configurer
     */
    public static function canConfigure()
    {
        // Si l'utilisateur est super-admin, il a accès à tout
        if (self::isSuperAdmin()) {
            return true;
        }
        
        return self::haveRight(self::RIGHT_WATCHMAN_CONFIG, self::UPDATE);
    }
    
    /**
     * Vérifie si l'utilisateur peut gérer les crons
     */
    public static function canManageCron()
    {
        // Si l'utilisateur est super-admin, il a accès à tout
        if (self::isSuperAdmin()) {
            return true;
        }
        
        return self::haveRight(self::RIGHT_WATCHMAN_CRON, self::UPDATE);
    }
    
    /**
     * Vérifie les droits et redirige si nécessaire
     */
    public static function checkRightOr403($rightname, $right = self::READ, $message = null)
    {
        if (!self::haveRight($rightname, $right)) {
            $message = $message ?: __('Vous n\'avez pas les droits suffisants pour accéder à cette fonctionnalité.', 'watchman');
            
            // En mode AJAX, retourner une erreur JSON
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json');
                http_response_code(403);
                echo json_encode(['error' => $message]);
                exit;
            }
            
            // Sinon, afficher le message d'erreur et rediriger
            Session::addMessageAfterRedirect($message, false, ERROR);
            \Html::redirect($GLOBALS['CFG_GLPI']['root_doc'] . '/front/central.php');
        }
    }
    
    /**
     * Vérifie l'accès général au plugin
     */
    public static function checkPluginAccess($message = null)
    {
        if (!self::canView()) {
            $message = $message ?: __('Vous n\'avez pas accès au plugin Watchman.', 'watchman');
            
            // En mode AJAX, retourner une erreur JSON
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json');
                http_response_code(403);
                echo json_encode(['error' => $message]);
                exit;
            }
            
            // Rediriger vers l'accueil GLPI avec message d'erreur
            Session::addMessageAfterRedirect($message, false, ERROR);
            \Html::redirect($GLOBALS['CFG_GLPI']['root_doc'] . '/front/central.php');
        }
    }
    
    /**
     * Retourne la liste des profils ayant accès au plugin
     */
    public static function getProfilesWithAccess()
    {
        global $DB;
        
        $profiles = [];
        $rights = array_keys(self::getAllRights());
        
        foreach ($rights as $rightname) {
            $query = "SELECT DISTINCT p.id, p.name, pr.rights 
                      FROM glpi_profiles p
                      LEFT JOIN glpi_profilerights pr ON p.id = pr.profiles_id 
                      WHERE pr.name = '" . $DB->escape($rightname) . "' 
                      AND pr.rights > 0
                      ORDER BY p.name";
            
            $result = $DB->query($query);
            if ($result) {
                while ($row = $DB->fetchAssoc($result)) {
                    if (!isset($profiles[$row['id']])) {
                        $profiles[$row['id']] = [
                            'name' => $row['name'],
                            'rights' => []
                        ];
                    }
                    $profiles[$row['id']]['rights'][$rightname] = $row['rights'];
                }
            }
        }
        
        return $profiles;
    }
    
    /**
     * Affiche un tableau de gestion des droits pour un profil
     */
    public static function displayRightsForProfile($profiles_id)
    {
        $rights = self::getAllRights();
        $profile = new Profile();
        
        if (!$profile->getFromDB($profiles_id)) {
            return false;
        }
        
        echo "<div class='spaced'>";
        echo "<table class='tab_cadre_fixehov'>";
        echo "<tr><th colspan='2'>" . sprintf(__('Droits Watchman pour le profil %s', 'watchman'), $profile->getName()) . "</th></tr>";
        
        foreach ($rights as $rightname => $config) {
            $current_rights = ProfileRight::getProfileRights($profiles_id, [$rightname]);
            $current_value = $current_rights[$rightname] ?? 0;
            
            echo "<tr class='tab_bg_1'>";
            echo "<td>" . $config['label'];
            if (isset($config['description'])) {
                echo "<br><small style='color: #666;'>" . $config['description'] . "</small>";
            }
            echo "</td>";
            echo "<td>";
            
            // Checkboxes pour les différents droits
            $checkbox_options = [
                self::READ => __('Lire'),
                self::UPDATE => __('Modifier'),
                self::CREATE => __('Créer'),
                self::DELETE => __('Supprimer')
            ];
            
            foreach ($checkbox_options as $right_value => $right_label) {
                $checked = ($current_value & $right_value) ? 'checked' : '';
                echo "<label style='margin-right: 15px;'>";
                echo "<input type='checkbox' name='{$rightname}[{$right_value}]' value='{$right_value}' {$checked}> ";
                echo $right_label;
                echo "</label>";
            }
            
            echo "</td></tr>";
        }
        
        echo "</table>";
        echo "</div>";
    }
}