<?php
/**
 * -------------------------------------------------------------------------
 * watchman plugin for GLPI
 * Copyright (C) 2025 by the watchman Development Team.
 * -------------------------------------------------------------------------
 *
 * Script to compile .po files to .mo files for GLPI
 * Usage: php compile_translations.php
 */

class PoCompiler
{
    private $localesDir;

    public function __construct($localesDir)
    {
        $this->localesDir = $localesDir;
    }

    public function compileAll()
    {
        $poFiles = glob($this->localesDir . '/*.po');

        foreach ($poFiles as $poFile) {
            $this->compilePo($poFile);
        }
    }

    private function compilePo($poFile)
    {
        $moFile = str_replace('.po', '.mo', $poFile);

        echo "Compilation de " . basename($poFile) . " vers " . basename($moFile) . "...\n";

        // Lire le fichier .po
        $entries = $this->parsePo($poFile);

        // Créer le fichier .mo
        $this->createMo($moFile, $entries);

        echo "✓ " . basename($moFile) . " créé avec succès\n";
    }

    private function parsePo($poFile)
    {
        $content = file_get_contents($poFile);
        $entries = [];

        // Pattern pour extraire msgid et msgstr
        preg_match_all('/msgid\s+"([^"]*)"\s*msgstr\s+"([^"]*)"/', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $msgid = $match[1];
            $msgstr = $match[2];

            if (!empty($msgid) && !empty($msgstr)) {
                $entries[$msgid] = $msgstr;
            }
        }

        return $entries;
    }

    private function createMo($moFile, $entries)
    {
        $keys = array_keys($entries);
        $values = array_values($entries);

        // En-tête du fichier MO
        $keyOffsets = [];
        $valueOffsets = [];
        $kOffsets = [];
        $vOffsets = [];

        // Calculer les offsets
        $keyOffset = 7 * 4 + 16 * count($entries);
        $valueOffset = $keyOffset;

        foreach ($keys as $key) {
            $keyOffsets[] = strlen($key);
            $kOffsets[] = $valueOffset;
            $valueOffset += strlen($key) + 1;
        }

        foreach ($values as $value) {
            $valueOffsets[] = strlen($value);
            $vOffsets[] = $valueOffset;
            $valueOffset += strlen($value) + 1;
        }

        // Construire le fichier MO
        $mo = pack('Iiiiiii', 0x950412de, 0, count($entries), 7 * 4, 7 * 4 + count($entries) * 8, 0, 0);

        foreach ($keyOffsets as $i => $length) {
            $mo .= pack('ii', $length, $kOffsets[$i]);
        }

        foreach ($valueOffsets as $i => $length) {
            $mo .= pack('ii', $length, $vOffsets[$i]);
        }

        foreach ($keys as $key) {
            $mo .= $key . "\0";
        }

        foreach ($values as $value) {
            $mo .= $value . "\0";
        }

        file_put_contents($moFile, $mo);
    }
}

// Utilisation
$localesDir = dirname(__DIR__) . '/locales';
$compiler = new PoCompiler($localesDir);

echo "Compilation des fichiers de traduction...\n";
$compiler->compileAll();
echo "Terminé !\n";