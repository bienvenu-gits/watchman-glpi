<?php

/**
 * Gestionnaire AJAX pour les détails d'ordinateur Watchman
 */

use GlpiPlugin\Watchman\ComputerManager;

if (!defined('GLPI_ROOT')) {
    define('GLPI_ROOT', dirname(__DIR__, 3));
}
define('GLPI_KEEP_CSRF_TOKEN', true);

include GLPI_ROOT . "/inc/includes.php";

// Vérifier que la requête est en AJAX
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    exit;
}

// Vérifier l'authentification
Session::checkLoginUser();

// Headers pour la réponse JSON
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Récupération de l'action demandée
$action = $_REQUEST['action'] ?? '';

// Instance du gestionnaire d'ordinateurs
$computerManager = new ComputerManager();

// Gestion des différentes actions
switch ($action) {
    
    case 'get_computer_info':
        handleGetComputerInfo($computerManager);
        break;
        
    case 'get_computer_applications':
        handleGetComputerApplications($computerManager);
        break;
        
    case 'get_application_alerts':
        handleGetApplicationAlerts();
        break;
        
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Action non reconnue: ' . $action]);
        exit;
}

/**
 * Récupère les informations de base d'un ordinateur avec les vraies données GLPI
 */
function handleGetComputerInfo($computerManager) {
    try {
        global $DB;
        
        $computer_id = intval($_GET['computer_id'] ?? 0);
        
        if (!$computer_id) {
            throw new Exception('ID d\'ordinateur manquant');
        }
        
        // Récupérer les informations complètes avec jointure sur la table computers
        $query = "
            SELECT 
                cm.*,
                c.name as computer_name,
                c.serial,
                c.otherserial,
                c.uuid,
                c.contact,
                c.contact_num,
                c.users_id_tech,
                c.groups_id_tech,
                c.comment,
                c.date_creation as computer_date_creation,
                c.date_mod as computer_date_mod,
                c.states_id,
                c.locations_id,
                m.name as manufacturer_name,
                ct.name as computer_type_name,
                cm_model.name as computer_model_name,
                loc.completename as location_name,
                state.name as state_name
            FROM glpi_plugin_watchman_computer_mappings cm
            LEFT JOIN glpi_computers c ON cm.computers_id = c.id
            LEFT JOIN glpi_manufacturers m ON c.manufacturers_id = m.id
            LEFT JOIN glpi_computertypes ct ON c.computertypes_id = ct.id
            LEFT JOIN glpi_computermodels cm_model ON c.computermodels_id = cm_model.id
            LEFT JOIN glpi_locations loc ON c.locations_id = loc.id
            LEFT JOIN glpi_states state ON c.states_id = state.id
            WHERE cm.id = " . intval($computer_id);
        
        $result = $DB->query($query);
        
        if (!$result) {
            throw new Exception('Erreur lors de l\'exécution de la requête');
        }
        
        $computer = $DB->fetchAssoc($result);
        
        if (!$computer) {
            throw new Exception('Ordinateur introuvable');
        }
        
        // Récupérer les adresses IP (NetworkPort + NetworkName + IPAddress)
        if ($computer['computers_id']) {
            $ip_query = "
                SELECT DISTINCT ip.name as ip_address, ip.version
                FROM glpi_networkports np
                LEFT JOIN glpi_networknames nn ON nn.items_id = np.id AND nn.itemtype = 'NetworkPort'
                LEFT JOIN glpi_ipaddresses ip ON ip.items_id = nn.id AND ip.itemtype = 'NetworkName'
                WHERE np.items_id = " . intval($computer['computers_id']) . "
                    AND np.itemtype = 'Computer'
                    AND ip.name IS NOT NULL
                    AND ip.name != ''
                    AND ip.name != '0.0.0.0'
                ORDER BY ip.version, ip.name";
            
            $ip_result = $DB->query($ip_query);
            $ip_addresses = [];
            
            if ($ip_result) {
                while ($ip_row = $DB->fetchAssoc($ip_result)) {
                    $ip_addresses[] = $ip_row['ip_address'];
                }
            }
            
            $computer['ip_addresses'] = $ip_addresses;
        }
        
        echo json_encode([
            'success' => true,
            'data' => $computer
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Erreur lors de la récupération des informations',
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * Récupère les applications d'un ordinateur avec comptage des alertes
 * Utilise la même logique que CronSyncComputer::getComputerApplications
 */
function handleGetComputerApplications($computerManager) {
    try {
        global $DB;
        
        $computer_id = intval($_GET['computer_id'] ?? 0);
        $page = max(1, intval($_GET['page'] ?? 1));
        $per_page = max(1, min(100, intval($_GET['per_page'] ?? 20)));
        $start = ($page - 1) * $per_page;
        
        if (!$computer_id) {
            throw new Exception('ID d\'ordinateur manquant');
        }
        
        // Récupérer l'ordinateur pour avoir le computers_id GLPI
        $computer = $computerManager->getComputerMappingById($computer_id);
        if (!$computer || !$computer['computers_id']) {
            throw new Exception('Ordinateur GLPI non lié');
        }
        
        $computers_id = $computer['computers_id'];
        
        // Utiliser la même requête que CronSyncComputer avec SQL pur pour éviter les erreurs
        $query = "
            SELECT DISTINCT 
                s.name,
                sv.name AS version,
                m.name AS vendor,
                COUNT(a.id) as alerts_count
            FROM glpi_items_softwareversions isv
            INNER JOIN glpi_softwareversions sv ON isv.softwareversions_id = sv.id
            INNER JOIN glpi_softwares s ON sv.softwares_id = s.id
            LEFT JOIN glpi_manufacturers m ON s.manufacturers_id = m.id
            LEFT JOIN glpi_plugin_watchman_stacks st ON st.name = s.name AND st.version = sv.name
            LEFT JOIN glpi_plugin_watchman_alerts a ON a.stacks_id = st.id AND a.patched = 0
            WHERE isv.items_id = " . intval($computers_id) . "
                AND isv.itemtype = 'Computer'
                AND isv.is_deleted = 0
            GROUP BY s.name, sv.name, m.name
            ORDER BY s.name
            LIMIT " . intval($start) . ", " . intval($per_page);
        
        $result = $DB->query($query);
        $applications = [];
        
        if ($result) {
            while ($row = $DB->fetchAssoc($result)) {
                $applications[] = [
                    'name' => $row['name'],
                    'version' => $row['version'] ?: 'Non définie',
                    'publisher' => $row['vendor'] ?: 'Non défini',
                    'alerts_count' => intval($row['alerts_count'])
                ];
            }
        }
        
        // Compter le total pour la pagination
        $count_query = "
            SELECT COUNT(DISTINCT CONCAT(s.name, '|', sv.name)) as total
            FROM glpi_items_softwareversions isv
            INNER JOIN glpi_softwareversions sv ON isv.softwareversions_id = sv.id
            INNER JOIN glpi_softwares s ON sv.softwares_id = s.id
            WHERE isv.items_id = " . intval($computers_id) . "
                AND isv.itemtype = 'Computer'
                AND isv.is_deleted = 0";
        
        $count_result = $DB->query($count_query);
        $total = 0;
        if ($count_result) {
            $count_row = $DB->fetchAssoc($count_result);
            $total = intval($count_row['total']);
        }
        
        $total_pages = max(1, ceil($total / $per_page));
        $has_next = $page < $total_pages;
        $has_prev = $page > 1;
        
        echo json_encode([
            'success' => true,
            'data' => $applications,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $per_page,
                'total' => $total,
                'total_pages' => $total_pages,
                'has_next' => $has_next,
                'has_prev' => $has_prev,
                'showing_from' => min($start + 1, $total),
                'showing_to' => min($start + $per_page, $total)
            ]
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Erreur lors de la récupération des applications',
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * Récupère les alertes d'une application spécifique
 */
function handleGetApplicationAlerts() {
    try {
        global $DB;
        
        $app_name = $_GET['app_name'] ?? '';
        $app_version = $_GET['app_version'] ?? '';
        $page = max(1, intval($_GET['page'] ?? 1));
        $per_page = max(1, min(50, intval($_GET['per_page'] ?? 10)));
        $start = ($page - 1) * $per_page;
        
        if (empty($app_name)) {
            throw new Exception('Nom d\'application manquant');
        }
        
        // Construire les conditions pour la recherche du stack
        $where_conditions = ["st.name = '" . $DB->escape($app_name) . "'"];
        if (!empty($app_version)) {
            $where_conditions[] = "st.version = '" . $DB->escape($app_version) . "'";
        }
        
        // Requête pour récupérer les alertes via la table des stacks
        $query = "
            SELECT 
                a.id,
                a.cves_id,
                a.title,
                a.description,
                a.score,
                a.severity,
                a.patched,
                a.date_creation
            FROM glpi_plugin_watchman_stacks st
            INNER JOIN glpi_plugin_watchman_alerts a ON a.stacks_id = st.id
            WHERE " . implode(' AND ', $where_conditions) . "
                AND a.patched = 0
            ORDER BY 
                CASE a.severity 
                    WHEN 'CRITICAL' THEN 1 
                    WHEN 'HIGH' THEN 2 
                    WHEN 'MEDIUM' THEN 3 
                    WHEN 'LOW' THEN 4 
                    ELSE 5 
                END,
                a.score DESC,
                a.date_creation DESC
            LIMIT " . intval($start) . ", " . intval($per_page);
        
        $result = $DB->query($query);
        $alerts = [];
        
        if ($result) {
            while ($row = $DB->fetchAssoc($result)) {
                $alerts[] = [
                    'id' => $row['id'],
                    'cves_id' => $row['cves_id'],
                    'title' => $row['title'],
                    'description' => $row['description'],
                    'score' => floatval($row['score']),
                    'severity' => $row['severity'],
                    'patched' => intval($row['patched']),
                    'date_creation' => $row['date_creation']
                ];
            }
        }
        
        // Compter le total pour la pagination
        $count_query = "
            SELECT COUNT(a.id) as total
            FROM glpi_plugin_watchman_stacks st
            INNER JOIN glpi_plugin_watchman_alerts a ON a.stacks_id = st.id
            WHERE " . implode(' AND ', $where_conditions) . "
                AND a.patched = 0";
        
        $count_result = $DB->query($count_query);
        $total = 0;
        if ($count_result) {
            $count_row = $DB->fetchAssoc($count_result);
            $total = intval($count_row['total']);
        }
        
        $total_pages = max(1, ceil($total / $per_page));
        
        echo json_encode([
            'success' => true,
            'data' => $alerts,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $per_page,
                'total' => $total,
                'total_pages' => $total_pages
            ]
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Erreur lors de la récupération des alertes',
            'message' => $e->getMessage()
        ]);
    }
}

?>