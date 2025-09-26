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

/**
 * Script to extract translation strings from Watchman plugin
 * Usage: php extract_strings.php
 */

class TranslationExtractor
{
    private $pluginDir;
    private $strings = [];
    private $patterns = [
        '/__\(\s*[\'"]([^\'"]*)[\'"],\s*[\'"]watchman[\'"]/',
        '/_e\(\s*[\'"]([^\'"]*)[\'"],\s*[\'"]watchman[\'"]/',
        '/_x\(\s*[\'"]([^\'"]*)[\'"],\s*[\'"]([^\'"]*)[\'"],\s*[\'"]watchman[\'"]/',
        '/_n\(\s*[\'"]([^\'"]*)[\'"],\s*[\'"]([^\'"]*)[\'"],\s*[^,]+,\s*[\'"]watchman[\'"]/'
    ];

    public function __construct($pluginDir)
    {
        $this->pluginDir = $pluginDir;
    }

    public function extractStrings()
    {
        $this->scanDirectory($this->pluginDir);
        return $this->strings;
    }

    private function scanDirectory($dir)
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && in_array($file->getExtension(), ['php', 'twig'])) {
                $this->extractFromFile($file->getPathname());
            }
        }
    }

    private function extractFromFile($filepath)
    {
        $content = file_get_contents($filepath);
        $relativePath = str_replace($this->pluginDir . DIRECTORY_SEPARATOR, '', $filepath);

        foreach ($this->patterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $string = $match[1];
                    if (!empty($string)) {
                        if (!isset($this->strings[$string])) {
                            $this->strings[$string] = [];
                        }
                        $this->strings[$string][] = $relativePath;
                    }
                }
            }
        }
    }

    public function generatePOT($outputFile)
    {
        $header = '# GLPI Plugin Watchman - Portable Object Template
# Copyright (C) 2025 Global IT Service
# This file is distributed under the same license as the Watchman package.
#
#, fuzzy
msgid ""
msgstr ""
"Project-Id-Version: GLPI Plugin Watchman\\n"
"Report-Msgid-Bugs-To: \\n"
"POT-Creation-Date: ' . date('Y-m-d H:i') . '+0000\\n"
"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\n"
"Last-Translator: FULL NAME <EMAIL@ADDRESS>\\n"
"Language-Team: LANGUAGE <LL@li.org>\\n"
"Language: \\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"

';

        $content = $header;

        foreach ($this->strings as $string => $files) {
            $content .= "\n";
            foreach ($files as $file) {
                $content .= "#: $file\n";
            }
            $content .= 'msgid "' . addslashes($string) . '"' . "\n";
            $content .= 'msgstr ""' . "\n";
        }

        file_put_contents($outputFile, $content);
    }
}

// Utilisation du script
$pluginDir = dirname(__DIR__);
$extractor = new TranslationExtractor($pluginDir);
$strings = $extractor->extractStrings();

echo "Extraction des chaînes de traduction...\n";
echo "Trouvé " . count($strings) . " chaînes uniques\n";

// Génération du fichier POT
$potFile = $pluginDir . '/locales/watchman.pot';
$extractor->generatePOT($potFile);

echo "Fichier POT généré : $potFile\n";

// Affichage des chaînes trouvées
echo "\nChaînes extraites :\n";
foreach ($strings as $string => $files) {
    echo "- \"$string\" (dans : " . implode(', ', $files) . ")\n";
}

echo "\nTerminé !\n";