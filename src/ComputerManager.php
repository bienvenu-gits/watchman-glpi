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
        return _n('Ordinateurs synchronisés', 'Ordinateurs synchronisés', $nb);
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

    /**
     * Récupère tous les mappings d'ordinateurs avec filtres
     */
    public function getComputerMappings($options = []) {
        global $DB;
        
        try {
            // SELECT ultra basique sans aucune option
            $query = "SELECT * FROM glpi_plugin_watchman_computer_mappings LIMIT 20";
            $result = $DB->query($query);
            
            $computers = [];
            while ($row = $DB->fetchAssoc($result)) {
                $computers[] = $row;
            }
            
            return $computers;
            
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Compte le nombre de mappings d'ordinateurs avec filtres
     */
    public function countComputerMappings($options = []) {
        global $DB;
        
        try {
            // COUNT ultra basique sans options
            $query = "SELECT COUNT(*) as count FROM glpi_plugin_watchman_computer_mappings";
            $result = $DB->query($query);
            $row = $DB->fetchAssoc($result);
            
            return $row['count'];
            
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Récupère un mapping d'ordinateur par son ID
     */
    public function getComputerMappingById($id) {
        global $DB;
        
        $query = "SELECT * FROM glpi_plugin_watchman_computer_mappings WHERE id = " . intval($id);
        $result = $DB->query($query);
        
        if ($result) {
            return $DB->fetchAssoc($result);
        }
        
        return null;
    }

    /**
     * Affiche les détails d'un mapping d'ordinateur
     */
    public function showComputerMappingDetail($id) {
        global $CFG_GLPI;
        
        $computer = $this->getComputerMappingById($id);
        
        if (!$computer) {
            echo "<div class='alert alert-danger'>Ordinateur introuvable</div>";
            return false;
        }
        
        TemplateRenderer::getInstance()->display('@watchman/pages/computer_mapping_detail.html.twig', [
            'item' => $this,
            'computer' => $computer,
            'base_url' => $CFG_GLPI["root_doc"],
            'csrf_token' => Session::getNewCSRFToken(),
        ]);
        
        return true;
    }

    /**
     * Vérifie les alertes pour un ordinateur spécifique
     */
    public function checkAlertsForComputer($computer_id) {
        // À implémenter selon la logique métier
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
            'title' => __("Ordinateurs synchronisés", 'watchman'),
            'page'  => '/plugins/watchman/front/computermapping.php',

            // define sub-options
            // we may have multiple pages under the "Plugin > My type" menu
            'options' => [
                'computermappings' => [
                    'title' => $title,
                    'page'  => '/plugins/watchman/front/computermapping.php'
                ]
            ]
        ];

        return $menu;
    }


    function startCron()
    {
        CronSyncComputer::manualSyncComputers();
    }
}
