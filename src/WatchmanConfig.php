<?php

namespace GlpiPlugin\Watchman;

use CommonDBTM;
use DBConnection;
use Glpi\Application\View\TemplateRenderer;
use GLPIKey;
use Migration;
use Session;
use Plugin;
use Toolbox;

class WatchmanConfig extends CommonDBTM
{
    // right management, we'll change this later
    static $rightname = 'plugin_watchman_config';
    static $api_base_url= 'http://localhost:8000/api/v2/';

    // const BASE_URL = "http://localhost:8022/api/v2/";
    const BASE_URL = "https://api.watchman.bj/api/v2/";

    /**
     *  Name of the itemtype
     */
    static function getTypeName($nb = 0)
    {
        return _n('Config', 'config', $nb);
    }
    
    /**
     *  show form rewrite
     */
    function showConfigForm( $options = [])
    {
        global $CFG_GLPI;
        // $this->initForm($ID, $options);
        $config = $this->getConfig();
        $params = [
            'config' => $config,
            'form_action' => $CFG_GLPI['root_doc'] . "/plugins/watchman/front/watchmanconfig.form.php",
            'csrf_token' => Session::getNewCSRFToken(),
            'base_url' => $CFG_GLPI["root_doc"],
        ];
        TemplateRenderer::getInstance()->display('@watchman/pages/config.html.twig', $params);
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
        $title  = "Configurations";
        // $page = Plugin::getWebDir('watchman') . '/front/config.form.php';
        $page   = self::getFormURL(false);

        // define base menu
        $menu = [
            'title' => __("Configuration", 'watchman'),
            'page'  => $page,

            // define sub-options
            // we may have multiple pages under the "Plugin > My type" menu
            'options' => [
                'configs' => [
                    'title' => $title,
                    'page'  => $page,
                ]
            ]
        ];

        return $menu;
    }

    /**
     * 
     * 
     */
    public static function getConfigTable(){
        return  self::$rightname . 's';
    }

    /**
     * Get the config
     * 
     * @return array
     * 
     */
    public function getConfig()
    {
        global $DB;

        $config = [];
        $iterator = $DB->request([
            'FROM' => self::getTable(),
        ]);
        
        foreach ($iterator as $data) {
            if (($data['name'] == 'secret_key' || $data['name'] == 'public_key') && !empty($data['value'])) {
                // Déchiffrement de la clé API
                $glpikey = new GLPIKey();
                $data['value'] = $glpikey->decrypt($data['value']);
            }
            $config[$data['name']] = $data['value'];
        }

        // Valeurs par défaut si elles n'existent pas
        $defaults = $this->getDefaultConfig();
        foreach ($defaults as $key => $value) {
            if (!isset($config[$key])) {
                $config[$key] = $value;
            }
        }

        return $config;
    }

    /**
     * Valeurs de configuration par défaut
     * 
     * @return array
     */
    public function getDefaultConfig()
    {
        return [
            'secret_key' => '',
            'public_key' => '',
            'api_timeout' => '30',
            'is_active' => '1',
            'cron_enabled' => '1',
            'cron_frequency' => '3600',  // 1 heure par défaut
            'cron_last_run' => '',
            'cron_status' => 'stopped',
        ];
    }

    /**
     * Récupère une valeur de configuration spécifique
     */
    public static function getConfigValue($key, $default = null)
    {
        global $DB;

        $iterator = $DB->request([
            'FROM' => self::getTable(),
            'WHERE' => ['name' => $key]
        ]);

        if (count($iterator)) {
            $data = $iterator->current();
            $value = $data['value'];

            // Déchiffrement de la clé API
            if (($key == 'secret_key' || $key == 'public_key') && !empty($value)) {
                $glpikey = new GLPIKey();
                $value = $glpikey->decrypt($value);
            }

            return $value;
        }

        // Retourner la valeur par défaut si elle existe
        $instance = new self();
        $defaults = $instance->getDefaultConfig();
        if (isset($defaults[$key])) {
            return $defaults[$key];
        }

        return $default;
    }

    /**
     * Save config value
     * @param  $input
     * @return void
     */
  public static  function saveConfig($input,$is_form=true)
    {
        global $DB;

        $config_fields = [
            'secret_key', 
            'public_key', 
            'api_timeout', 
            'is_active',
            'cron_enabled',
            'cron_frequency',
            'cron_last_run',
            'cron_status',
            "last_alert_updated_at",
            "last_alert_sync_date",
            "alerts_processed",
            "alerts_errors",
            "tickets_created",
            "api_status",
            "api_last_check",
            "circuit_breaker_state",
            "circuit_breaker_failures",
            "last_failure_time",
            "last_alerts_sync_date",
            'show_welcome'
        ];

        foreach ($config_fields as $field) {
            if (isset($input[$field])) {
                $value = $input[$field];

                // Chiffrement de la clé API
                if (($field == 'secret_key' || $field == 'public_key') && !empty($value)) {
                    $glpikey = new GLPIKey();
                    $value = $glpikey->encrypt($value);
                }

                // Validation des valeurs pour le cron
                if ($field == 'cron_frequency') {
                    $value = max(60, intval($value)); // Minimum 1 minute
                }

                if ($field == 'cron_enabled') {
                    $value = in_array($value, ['0', '1']) ? $value : '0';
                }

                $DB->updateOrInsert(
                    self::getTable(),
                    [
                        'name' => $field,
                        'value' => $value
                    ],
                    [
                        'name' => $field
                    ]
                );
            }
        }

        if($is_form){
        Session::addMessageAfterRedirect(__('Configuration sauvegardée', 'watchman'));
        }

    }

    /**
     * Met à jour le statut et la dernière exécution du cron
     */
    public static function updateCronStatus($status, $last_run = null)
    {
        global $DB;
        
        if ($last_run === null) {
            $last_run = date('Y-m-d H:i:s');
        }

        $DB->updateOrInsert(
            self::getTable(),
            ['name' => 'cron_status', 'value' => $status],
            ['name' => 'cron_status']
        );

        $DB->updateOrInsert(
            self::getTable(),
            ['name' => 'cron_last_run', 'value' => $last_run],
            ['name' => 'cron_last_run']
        );
    }

    /**
     * Vérifie si le cron doit être exécuté
     */
    public static function shouldRunCron()
    {
        $enabled = self::getConfigValue('cron_enabled', '1');
        if ($enabled != '1') {
            return false;
        }

        $frequency = intval(self::getConfigValue('cron_frequency', 3600));
        $last_run = self::getConfigValue('cron_last_run', '');
        
        if (empty($last_run)) {
            return true;
        }

        $last_run_timestamp = strtotime($last_run);
        $next_run = $last_run_timestamp + $frequency;
        
        return time() >= $next_run;
    }

    /**
     * Install migration
     *
     * @param \Migration $migration
     * @param string $version
     */
    static function install(Migration $migration, string $version): bool
    {
        global $DB;

        $default_charset = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign = DBConnection::getDefaultPrimaryKeySignOption();
        $table = self::getTable();

        if (!$DB->tableExists($table)) {
            $migration->displayMessage("Installing $table");

            $query = "CREATE TABLE IF NOT EXISTS `$table` (
                     `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                     `name` varchar(255) COLLATE {$default_collation} NOT NULL,
                     `value` text COLLATE {$default_collation} NOT NULL,
                     
                     `date_mod` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                     `date_creation` timestamp DEFAULT CURRENT_TIMESTAMP,
                     
                     PRIMARY KEY (`id`),
                     UNIQUE KEY `name` (`name`)
                     
                  ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
            $DB->doQuery($query) or die("Error creating $table table");

            // Insérer les valeurs par défaut
            $instance = new self();
            $defaults = $instance->getDefaultConfig();
            
            foreach ($defaults as $name => $value) {
                if (!empty($value)) {
                    $DB->insert($table, [
                        'name' => $name,
                        'value' => $value
                    ]);
                }
            }

            $migration->updateDisplayPrefs(
                [
                    self::class => [1, 10, 11, 3, 6, 4, 8, 9, 7]
                ],
            );
        } else {
            // Migration pour ajouter les nouveaux champs si la table existe déjà
            $migration->displayMessage("Updating $table for cron configuration");
            
            $instance = new self();
            $defaults = $instance->getDefaultConfig();
            $cron_fields = ['cron_enabled', 'cron_frequency', 'cron_last_run', 'cron_status'];
            
            foreach ($cron_fields as $field) {
                $existing = $DB->request([
                    'FROM' => $table,
                    'WHERE' => ['name' => $field]
                ]);
                
                if (count($existing) == 0 && isset($defaults[$field])) {
                    $DB->insert($table, [
                        'name' => $field,
                        'value' => $defaults[$field]
                    ]);
                }
            }
            
            // Modifier le type de colonne value en TEXT pour permettre de plus longues valeurs
            $DB->doQuery("ALTER TABLE `$table` MODIFY `value` TEXT COLLATE {$default_collation} NOT NULL");
        }

        return true;
    }
}