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

use GlpiPlugin\Watchman\Superasset;

/**
 * Plugin install process
 *
 * @return boolean
 */
function plugin_watchman_install()
{
    global $DB;

    // Instancier la migration avec une version (ex. : 100)
    $migration = new Migration(100);

    // Créer la table uniquement si elle n'existe pas encore
    if (!$DB->tableExists('glpi_plugin_watchman_configs')) {
        $query = "CREATE TABLE `glpi_plugin_watchman_configs` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `public_key` VARCHAR(255) NOT NULL,
                    `secret_key` VARCHAR(255) NOT NULL,
                    PRIMARY KEY (`id`)
                 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC";
        $DB->queryOrDie($query, $DB->error());
    }
    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $table = Superasset::getTable();
    if (!$DB->tableExists($table)) {
        //table creation query
        $query = "CREATE TABLE `$table` (
                  `id`         int unsigned NOT NULL AUTO_INCREMENT,
                  `is_deleted` TINYINT NOT NULL DEFAULT '0',
                  `name`      VARCHAR(255) NOT NULL,
                  PRIMARY KEY  (`id`)
                 ) ENGINE=InnoDB
                 DEFAULT CHARSET={$default_charset}
                 COLLATE={$default_collation}";
        $DB->queryOrDie($query, $DB->error());
    }

    // Exécuter la migration
    $migration->executeMigration();

    return true;
}


/**
 * Plugin uninstall process
 *
 * @return boolean
 */
function plugin_watchman_uninstall()
{
     global $DB;

   $tables = [
      'configs'
   ];

   foreach ($tables as $table) {
      $tablename = 'glpi_plugin_watchman_' . $table;
      //Create table only if it does not exists yet!
      if ($DB->tableExists($tablename)) {
         $DB->queryOrDie(
            "DROP TABLE `$tablename`",
            $DB->error()
         );
      }
   }
    return true;
}


 function watchman_display_login () {
   echo "That line will appear on the login page!";
}