<?php
namespace GlpiPlugin\Watchman;

use CommonDBTM;
use Glpi\Application\View\TemplateRenderer;
use Session;
use GlpiPlugin\Watchman\WatchmanConfig;

class WatchmanManager extends CommonDBTM
{
    // right management, we'll change this later
    static $rightname = 'watchman_manager';

    /**
     *  Name of the itemtype
     */
    static function getTypeName($nb=0)
    {
        return _n('Watchman', 'Watchman', $nb);
    }

    private static function featureConfig(): array
    {
        return [
            'show_alerts_tab'         => WatchmanConfig::getConfigValue('show_alerts_tab', '0'),
            'sync_alerts_enabled'     => WatchmanConfig::getConfigValue('sync_alerts_enabled', '0'),
            'ticket_creation_enabled' => WatchmanConfig::getConfigValue('ticket_creation_enabled', '0'),
        ];
    }

    function showDashboard()
    {
        global $CFG_GLPI;

        TemplateRenderer::getInstance()->display('@watchman/pages/welcome.html.twig', [
            'item'       => $this,
            'base_url'   => $CFG_GLPI["root_doc"],
            'csrf_token' => Session::getNewCSRFToken(),
            'features'   => self::featureConfig(),
        ]);
        

        return true;
    }


        function showAlerts($alert_id)
    {
        global $CFG_GLPI;
        if ($alert_id) {
            $alert_manager = new AlertManager();
            $alert = $alert_manager->getAlertById($alert_id);
            if ($alert) {
                TemplateRenderer::getInstance()->display('@watchman/pages/alert_detail.html.twig', [
                    'item'         => $this,
                    'base_url'     => $CFG_GLPI["root_doc"],
                    'csrf_token'   => Session::getNewCSRFToken(),
                    'current_page' => 'alertmanager',
                    'features'     => self::featureConfig(),
                ]);
                return true;
            }
        }
        // @myplugin is a shortcut to the **templates** directory of your plugin
        TemplateRenderer::getInstance()->display('@watchman/pages/alerts.html.twig', [
            'item'         => $this,
            'base_url'     => $CFG_GLPI["root_doc"],
            'current_page' => 'alertmanager',
            'csrf_token'   => Session::getNewCSRFToken(),
            'features'     => self::featureConfig(),
        ]);
        

        return true;
    }

    function showLogs()
    {
        global $CFG_GLPI;

        TemplateRenderer::getInstance()->display('@watchman/pages/logs.html.twig', [
            'item'         => $this,
            'base_url'     => $CFG_GLPI["root_doc"],
            'current_page' => 'logmanager',
            'csrf_token'   => Session::getNewCSRFToken(),
            'features'     => self::featureConfig(),
        ]);

        return true;
    }

    function showComputerMappings($computer_id = null)
    {
        global $CFG_GLPI;
        
        if ($computer_id) {
            TemplateRenderer::getInstance()->display('@watchman/pages/computer_detail.html.twig', [
                'item'         => $this,
                'base_url'     => $CFG_GLPI["root_doc"],
                'current_page' => 'computermapping',
                'csrf_token'   => Session::getNewCSRFToken(),
                'features'     => self::featureConfig(),
            ]);
            return true;
        }
        
        // Récupérer tous les mappings d'ordinateurs
        $computer_manager = new ComputerManager();
        $computers = $computer_manager->getComputerMappings(['limit' => 1000]);
        
        TemplateRenderer::getInstance()->display('@watchman/pages/computer_mappings.html.twig', [
            'item'         => $this,
            'computers'    => $computers,
            'base_url'     => $CFG_GLPI["root_doc"],
            'current_page' => 'computermapping',
            'csrf_token'   => Session::getNewCSRFToken(),
            'features'     => self::featureConfig(),
        ]);
        
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
        // Vérifier si l'utilisateur a accès au plugin
        if (!\GlpiPlugin\Watchman\WatchmanProfile::canView()) {
            return [];
        }

        $title  = self::getMenuName(Session::getPluralNumber());
        $search = self::getSearchURL(false);

        // define base menu
        $menu = [
            'title' => __("Watchman", 'watchman'),
            'page'  => $search,
            // define sub-options
            'options' => [
                'watchman' => [
                    'title' => $title,
                    'page'  => $search,
                ]
            ]
        ];

        // Ajouter des sous-menus selon les droits
        if (\GlpiPlugin\Watchman\WatchmanProfile::canView()
            && WatchmanConfig::getConfigValue('show_alerts_tab', '0') === '1') {
            $menu['options']['alerts'] = [
                'title' => __('Alertes', 'watchman'),
                'page'  => '/plugins/watchman/front/alertmanager.php',
                'links' => [
                    'search' => '/plugins/watchman/front/alertmanager.php'
                ]
            ];
        }

        if (\GlpiPlugin\Watchman\WatchmanProfile::canAdmin()) {
            $menu['options']['computers'] = [
                'title' => __('Ordinateurs synchronisés', 'watchman'),
                'page'  => '/plugins/watchman/front/computermapping.php',
                'links' => [
                    'search' => '/plugins/watchman/front/computermapping.php'
                ]
            ];
        }

        if (\GlpiPlugin\Watchman\WatchmanProfile::canAdmin()) {
            $menu['options']['logs'] = [
                'title' => __('Logs', 'watchman'),
                'page'  => '/plugins/watchman/front/logmanager.php',
                'links' => [
                    'search' => '/plugins/watchman/front/logmanager.php'
                ]
            ];
        }

        if (\GlpiPlugin\Watchman\WatchmanProfile::canConfigure()) {
            $menu['options']['config'] = [
                'title' => __('Configuration', 'watchman'),
                'page'  => '/plugins/watchman/front/watchmanconfig.form.php',
                'links' => [
                    'add' => '/plugins/watchman/front/watchmanconfig.form.php'
                ]
            ];
            
            $menu['options']['profiles'] = [
                'title' => __('Gestion des profils', 'watchman'),
                'page'  => '/plugins/watchman/front/watchmanprofile.form.php',
                'links' => [
                    'config' => '/plugins/watchman/front/watchmanprofile.form.php'
                ]
            ];
        }

        return $menu;
    }
}