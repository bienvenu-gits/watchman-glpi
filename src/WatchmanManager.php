<?php
namespace GlpiPlugin\Watchman;

use CommonDBTM;
use Glpi\Application\View\TemplateRenderer;
use Session;

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

    function showDashboard()
    {
        global $CFG_GLPI;
        
        TemplateRenderer::getInstance()->display('@watchman/pages/welcome.html.twig', [
            'item'   => $this,
            'base_url' => $CFG_GLPI["root_doc"],
            'csrf_token' => Session::getNewCSRFToken(),  // if you need CSRF token
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
                    'item'   => $this,
                    'base_url' => $CFG_GLPI["root_doc"],
                    'csrf_token' => Session::getNewCSRFToken(),  // if you need CSRF token
                ]);
                return true;
            }
        }
        // @myplugin is a shortcut to the **templates** directory of your plugin
        TemplateRenderer::getInstance()->display('@watchman/pages/alerts.html.twig', [
            'item'   => $this,
            'base_url' => $CFG_GLPI["root_doc"],
            'csrf_token' => Session::getNewCSRFToken(),  // if you need CSRF token
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

        return $menu;
    }
}