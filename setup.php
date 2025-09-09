<?php

/**
 * -------------------------------------------------------------------------
 * watchman plugin for GLPI
 * Copyright (C) 2025 by the watchman Development Team.
 * -------------------------------------------------------------------------
 *
 * MIT License
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * --------------------------------------------------------------------------
 */

use GlpiPlugin\Watchman\AlertManager;
use GlpiPlugin\Watchman\ComputerManager;
use GlpiPlugin\Watchman\WatchmanConfig;
use GlpiPlugin\Watchman\Superasset;
use GlpiPlugin\Watchman\WatchmanManager;

define('PLUGIN_WATCHMAN_VERSION', '0.0.1');

// Minimal GLPI version, inclusive
define("PLUGIN_WATCHMAN_MIN_GLPI_VERSION", "10.0.0");
// Maximum GLPI version, exclusive
define("PLUGIN_WATCHMAN_MAX_GLPI_VERSION", "10.0.99");

/**
 * Init hooks of the plugin.
 * REQUIRED
 *
 * @return void
 */
function plugin_init_watchman()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['watchman'] = true;
    // add config name
    $PLUGIN_HOOKS['menu_toadd']['watchman'] = [
        'plugins' => [
            WatchmanManager::class,
            ]
    ];


}


/**
 * Get the name and the version of the plugin
 * REQUIRED
 *
 * @return array
 */
function plugin_version_watchman()
{
    return [
        'name'           => 'Watchman',
        'version'        => PLUGIN_WATCHMAN_VERSION,
        'author'         => '<a href="http://www.gits.bj">Global It Service</a>',
        'license'        => '',
        'homepage'       => '',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_WATCHMAN_MIN_GLPI_VERSION,
                'max' => PLUGIN_WATCHMAN_MAX_GLPI_VERSION,
            ]
        ]
    ];
}

/**
 * Check pre-requisites before install
 * OPTIONNAL, but recommanded
 *
 * @return boolean
 */
function plugin_watchman_check_prerequisites()
{
    return true;
}

/**
 * Check configuration process
 *
 * @param boolean $verbose Whether to display message on failure. Defaults to false
 *
 * @return boolean
 */
function plugin_watchman_check_config($verbose = false)
{
    if (true) { // Your configuration check
        return true;
    }

    if ($verbose) {
        echo __('Installed / not configured', 'watchman');
    }
    return false;
}
