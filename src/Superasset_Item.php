<?php

namespace GlpiPlugin\Watchman;

use CommonDBTM;
use Glpi\Application\View\TemplateRenderer;

class Superasset_Item extends CommonDBTM
{
    /**
     * Tabs title
     */
    function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        switch ($item->getType()) {
            case Superasset::class:
                $nb = countElementsInTable(self::getTable(),
                    [
                        'plugin_mwatchman_superassets_id' => $item->getID()
                    ]
                );
                return self::createTabEntry(self::getTypeName($nb), $nb);
        }
        return '';
    }

    /**
     * Display tabs content
     */
    static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        switch ($item->getType()) {
            case Superasset::class:
                return self::showForSuperasset($item, $withtemplate);
        }

        return true;
    }

    /**
     * Specific function for display only items of Superasset
     */
    static function showForSuperasset(Superasset $superasset, $withtemplate = 0)
    {
        TemplateRenderer::getInstance()->display('@watchman/superasset_item_.html.twig', [
            'superasset' => $superasset,
        ]);
    }
}