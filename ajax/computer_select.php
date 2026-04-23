<?php
use GlpiPlugin\Watchman\WatchmanProfile;
include('../../../inc/includes.php');
WatchmanProfile::checkRightOr403(WatchmanProfile::RIGHT_WATCHMAN_ADMIN, WatchmanProfile::READ);

header('Content-Type: application/json');

global $DB;
$action = $_REQUEST['action'] ?? '';

switch ($action) {

    // Récupérer tous les ordinateurs GLPI avec leur statut de sélection
    case 'get_all_computers':
        $iterator = $DB->request([
            'SELECT' => [
                'glpi_computers.id',
                'glpi_computers.name',
                'glpi_computers.date_mod',
                'glpi_plugin_watchman_computer_mappings.is_selected',
                'glpi_plugin_watchman_computer_mappings.sync_status',
                'glpi_plugin_watchman_computer_mappings.last_sync_date',
            ],
            'FROM' => 'glpi_computers',
            'LEFT JOIN' => [
                'glpi_plugin_watchman_computer_mappings' => [
                    'FKEY' => [
                        'glpi_computers' => 'id',
                        'glpi_plugin_watchman_computer_mappings' => 'computers_id'
                    ]
                ]
            ],
            'WHERE' => [
                'glpi_computers.is_deleted' => 0,
                'glpi_computers.is_template' => 0,
            ],
            'ORDER' => 'glpi_computers.name ASC'
        ]);

        $computers = [];
        foreach ($iterator as $row) {
            $computers[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'is_selected' => (int)($row['is_selected'] ?? 0),
                'sync_status' => $row['sync_status'] ?? 'never',
                'last_sync_date' => $row['last_sync_date'],
            ];
        }

        echo json_encode(['success' => true, 'computers' => $computers]);
        break;

    // Sauvegarder la sélection
    case 'save_selection':
        $selected_ids = json_decode(file_get_contents('php://input'), true)['selected_ids'] ?? [];

        // Reset all to 0
        $DB->doQuery("UPDATE glpi_plugin_watchman_computer_mappings SET is_selected = 0");

        // Insert or update selected ones
        foreach ($selected_ids as $computer_id) {
            $computer_id = (int)$computer_id;
            $existing = $DB->request([
                'FROM' => 'glpi_plugin_watchman_computer_mappings',
                'WHERE' => ['computers_id' => $computer_id]
            ]);

            if (count($existing) > 0) {
                $DB->update(
                    'glpi_plugin_watchman_computer_mappings',
                    ['is_selected' => 1],
                    ['computers_id' => $computer_id]
                );
            } else {
                $DB->insert('glpi_plugin_watchman_computer_mappings', [
                    'computers_id' => $computer_id,
                    'is_selected' => 1,
                    'sync_status' => 'pending',
                    'date_creation' => date('Y-m-d H:i:s'),
                ]);
            }
        }

        echo json_encode(['success' => true, 'message' => count($selected_ids) . ' machine(s) sélectionnée(s)']);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Action inconnue']);
}
