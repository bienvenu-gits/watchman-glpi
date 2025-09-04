<?php

namespace GlpiPlugin\Watchman;

use CommonDBTM;
use Glpi\Application\View\TemplateRenderer;
use Session;

class ComputerManager extends CommonDBTM
{
    // right management, we'll change this later
    static $rightname = 'computer';

    /**
     *  Name of the itemtype
     */
    static function getTypeName($nb = 0)
    {
        return _n('Equipements', 'Super-assets', $nb);
    }

    function showCronStartForm($options = [])
    {
        // @myplugin is a shortcut to the **templates** directory of your plugin
        TemplateRenderer::getInstance()->display('@watchman/computer.form.html.twig', [
            'item'   => $this,
            'params' => $options,
            'csrf_token' => \Session::getNewCSRFToken(),
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

        $form   = self::getFormURL(false);

        // define base menu
        $menu = [
            'title' => __("Machines", 'watchman'),
            'page'  => $form,

            // define sub-options
            // we may have multiple pages under the "Plugin > My type" menu
            'options' => [
                'superasset' => [
                    'title' => $title,
                    'page'  => $form
                ]
            ]
        ];

        return $menu;
    }


    function startCron()
    {
        // CronSyncComputer::manualSyncComputers();

        CronSyncAlert::manualSyncAlerts();
    }
}
