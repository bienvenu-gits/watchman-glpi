<?php

/**
 * Gestionnaire AJAX pour les mappings d'ordinateurs Watchman
 */

use Glpi\Http\Response;
use GlpiPlugin\Watchman\ComputerManager;
use GlpiPlugin\Watchman\WatchmanCronHelper;

if (!defined('GLPI_ROOT')) {
    define('GLPI_ROOT', dirname(__DIR__, 3));
}
define('GLPI_KEEP_CSRF_TOKEN', true);

include GLPI_ROOT . "/inc/includes.php";

// Vérification des droits d'accès
// Session::checkRight("plugin_watchman_computer", READ);

// Vérifier que la requête est en AJAX
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    exit;
}

// Vérifier l'authentification
Session::checkLoginUser();

// Vérification du token CSRF pour les opérations sensibles
$csrf_token = $_REQUEST['_glpi_csrf_token'] ?? '';
if (!empty($_POST) && !Session::validateCSRF(['_glpi_csrf_token'=>$csrf_token])) {
    http_response_code(403);
    echo json_encode(['error' => 'Token CSRF invalide']);
    exit;
}

// Headers pour la réponse JSON
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Récupération de l'action demandée
$action = $_REQUEST['action'] ?? '';

// Instance du gestionnaire d'ordinateurs
$computerManager = new ComputerManager();

// Gestion des différentes actions
switch ($action) {
    
    case 'get_computers':
        handleGetComputers($computerManager);
        break;
        
    case 'get_computer_stats':
        handleGetComputerStats($computerManager);
        break;
        
    case 'sync_computer':
        handleSyncComputer($computerManager);
        break;
        
    case 'get_sync_error':
        handleGetSyncError($computerManager);
        break;
        
    case 'remove_computer_mapping':
        handleRemoveComputerMapping($computerManager);
        break;
        
    case 'bulk_computer_action':
        handleBulkComputerAction($computerManager);
        break;

    case 'start_computer_sync':
        handleStartComputerSync($computerManager);
        break;
        
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Action non reconnue'.$action]);
        exit;
}

/**
 * Récupère les ordinateurs avec pagination et filtres
 */
function handleGetComputers($computerManager) {
    try {
        // Capturer les debug en buffer pour éviter qu'ils interfèrent avec JSON
        $debug = [];
        $debug[] = "handleGetComputers called with GET params: " . json_encode($_GET);
        
        // Paramètres de pagination
        $page = max(1, (int)($_GET['page'] ?? 1));
        $per_page = max(1, min(100, (int)($_GET['per_page'] ?? 20)));
        $start = ($page - 1) * $per_page;
        
        // Paramètres de filtrage
        $filters = [
            'search' => trim($_GET['search'] ?? ''),
            'status' => $_GET['status'] ?? null,
            'date_from' => $_GET['date_from'] ?? null,
            'date_to' => $_GET['date_to'] ?? null,
            'order' => $_GET['order'] ?? 'external_id',
            'sort' => $_GET['sort'] ?? 'ASC'
        ];
        
        $debug[] = "Filters: " . json_encode($filters);
        
        // Options pour la requête
        $options = array_merge($filters, [
            'start' => $start,
            'limit' => $per_page
        ]);
        
        $debug[] = "Options: " . json_encode($options);

        // Test basique : vérifier que la table existe
        global $DB;
        if (!$DB->tableExists('glpi_plugin_watchman_computer_mappings')) {
            $debug[] = "Table does not exist";
            throw new Exception('Table glpi_plugin_watchman_computer_mappings does not exist');
        }
        
        $debug[] = "Table exists";
        
        // Test ultra simple : compter toutes les lignes
        $query = "SELECT COUNT(*) as count FROM glpi_plugin_watchman_computer_mappings";
        $result = $DB->query($query);
        $simple_count = $DB->fetchAssoc($result)['count'];
        
        $debug[] = "Simple count result: " . $simple_count;

        // Récupération des ordinateurs
        $debug[] = "Calling getComputerMappings...";
        $computers = $computerManager->getComputerMappings([]);  // Aucune option
        $debug[] = "getComputerMappings returned " . count($computers) . " computers";

        

        $debug[] = "Calling countComputerMappings...";
        $total = $computerManager->countComputerMappings([]);  // Aucune option
        $debug[] = "countComputerMappings returned: " . $total;
        
        // Formatage des données pour l'affichage
        $formatted_computers = [];
        foreach ($computers as $computer) {
            $formatted_computers[] = formatComputerForDisplay($computer);
        }

        
        // Calcul des informations de pagination
        $total_pages = max(1, ceil($total / $per_page));
        $has_next = $page < $total_pages;
        $has_prev = $page > 1;
        
        echo json_encode([
            'success' => true,
            'data' => $formatted_computers,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $per_page,
                'total' => $total,
                'total_pages' => $total_pages,
                'has_next' => $has_next,
                'has_prev' => $has_prev,
                'showing_from' => min($start + 1, $total),
                'showing_to' => min($start + $per_page, $total)
            ],
            'debug' => $debug  // Pour le debug temporaire
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Erreur lors de la récupération des ordinateurs',
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * Récupère les statistiques des ordinateurs
 */
function handleGetComputerStats($computerManager) {
    try {
        global $DB;
        
        // Juste le nombre total d'ordinateurs synchronisés avec SQL pur
        $query = "SELECT COUNT(*) as count FROM glpi_plugin_watchman_computer_mappings";
        $result = $DB->query($query);
        $total = $DB->fetchAssoc($result)['count'];
        
        $stats = [
            'total' => $total
        ];
        
        echo json_encode([
            'success' => true,
            'data' => $stats
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Erreur lors de la récupération des statistiques',
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * Synchronise un ordinateur spécifique
 */
function handleSyncComputer($computerManager) {
    try {
        $computer_id = $_POST['computer_id'] ?? '';
        
        if (empty($computer_id)) {
            throw new Exception('ID d\'ordinateur manquant');
        }
        
        global $DB;
        
        // Récupérer les informations de l'ordinateur
        $computer_data = $DB->request([
            'FROM' => 'glpi_plugin_watchman_computer_mappings',
            'WHERE' => [
                'id' => $computer_id
            ]
        ])->current();
        
        if (!$computer_data) {
            throw new Exception('Ordinateur introuvable');
        }
        
        // Mettre à jour le statut en "pending"
        $DB->update(
            'glpi_plugin_watchman_computer_mappings',
            [
                'sync_status' => 'pending',
                'error_message' => null,
                'date_mod' => date('Y-m-d H:i:s')
            ],
            ['id' => $computer_id]
        );
        
        // Lancer la synchronisation via le système cron
        WatchmanCronHelper::registerOnce(
            'computer_sync_' . $computer_id,
            'GlpiPlugin\Watchman\ComputerManager::syncSingleComputer',
            ['computer_id' => $computer_id]
        );
        
        echo json_encode([
            'success' => true,
            'message' => 'Synchronisation de l\'ordinateur lancée avec succès'
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Erreur lors de la synchronisation',
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * Récupère les détails d'une erreur de synchronisation
 */
function handleGetSyncError($computerManager) {
    try {
        $computer_id = $_GET['computer_id'] ?? '';
        
        if (empty($computer_id)) {
            throw new Exception('ID d\'ordinateur manquant');
        }
        
        global $DB;
        
        // Récupérer les informations de l'erreur
        $computer_data = $DB->request([
            'FROM' => 'glpi_plugin_watchman_computer_mappings',
            'WHERE' => [
                'id' => $computer_id,
                'sync_status' => 'error'
            ]
        ])->current();
        
        if (!$computer_data) {
            throw new Exception('Ordinateur introuvable ou sans erreur');
        }
        
        // Récupérer les logs d'erreur les plus récents
        $error_logs = $DB->request([
            'FROM' => 'glpi_plugin_watchman_sync_logs',
            'WHERE' => [
                'computer_mapping_id' => $computer_id,
                'status' => 'error'
            ],
            'ORDER' => 'date_creation DESC',
            'LIMIT' => 1
        ])->current();
        
        $error_data = [
            'error_message' => $computer_data['error_message'] ?? 'Erreur inconnue',
            'error_date' => $computer_data['date_mod'] ? date('d/m/Y H:i', strtotime($computer_data['date_mod'])) : 'N/A',
            'error_details' => $error_logs['error_details'] ?? null,
            'retry_count' => $computer_data['retry_count'] ?? 0,
            'last_retry' => $computer_data['last_sync_date'] ? date('d/m/Y H:i', strtotime($computer_data['last_sync_date'])) : null
        ];
        
        echo json_encode([
            'success' => true,
            'data' => $error_data
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Erreur lors de la récupération des détails',
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * Retire le mapping d'un ordinateur
 */
function handleRemoveComputerMapping($computerManager) {
    try {
        $computer_id = $_POST['computer_id'] ?? '';
        
        if (empty($computer_id)) {
            throw new Exception('ID d\'ordinateur manquant');
        }
        
        global $DB;
        
        // Au lieu de soft delete, nous supprimons complètement le mapping
        $result = $DB->delete(
            'glpi_plugin_watchman_computer_mappings',
            ['id' => $computer_id]
        );
        
        if ($result) {
            // Log de l'action
            $DB->insert('glpi_plugin_watchman_sync_logs', [
                'computer_mapping_id' => $computer_id,
                'action' => 'mapping_removed',
                'message' => 'Mapping d\'ordinateur retiré',
                'status' => 'success',
                'users_id' => Session::getLoginUserID(),
                'date_creation' => date('Y-m-d H:i:s')
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Mapping d\'ordinateur retiré avec succès'
            ]);
        } else {
            throw new Exception('Erreur lors de la suppression du mapping');
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Erreur lors de la suppression',
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * Démarre la synchronisation globale des ordinateurs
 */
function handleStartComputerSync($computerManager) {
    try {
        // Lancer la synchronisation via le système cron
        $computerManager->startCron();
        
        echo json_encode([
            'success' => true,
            'message' => 'Synchronisation des ordinateurs lancée avec succès'
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Erreur lors du lancement de la synchronisation',
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * Gère les actions en masse sur les ordinateurs
 */
function handleBulkComputerAction($computerManager) {
    try {
        $action = $_POST['bulk_action'] ?? '';
        $computer_ids = $_POST['computer_ids'] ?? [];
        
        if (empty($action) || empty($computer_ids) || !is_array($computer_ids)) {
            throw new Exception('Paramètres manquants pour l\'action en masse');
        }
        
        global $DB;
        $success_count = 0;
        $error_count = 0;
        
        foreach ($computer_ids as $computer_id) {
            try {
                switch ($action) {
                    case 'sync':
                        $result = $DB->update(
                            'glpi_plugin_watchman_computer_mappings',
                            [
                                'sync_status' => 'pending',
                                'error_message' => null,
                                'date_mod' => date('Y-m-d H:i:s')
                            ],
                            ['id' => $computer_id]
                        );
                        
                        if ($result) {
                            // Lancer la synchronisation
                            WatchmanCronHelper::registerOnce(
                                'bulk_computer_sync_' . $computer_id,
                                'GlpiPlugin\Watchman\ComputerManager::syncSingleComputer',
                                ['computer_id' => $computer_id]
                            );
                        }
                        break;
                        
                    case 'remove':
                        $result = $DB->delete(
                            'glpi_plugin_watchman_computer_mappings',
                            ['id' => $computer_id]
                        );
                        break;
                        
                    case 'check-alerts':
                        // Vérifier les alertes pour cet ordinateur
                        $result = $computerManager->checkAlertsForComputer($computer_id);
                        break;
                        
                    default:
                        throw new Exception('Action non reconnue');
                }
                
                if ($result) {
                    $success_count++;
                    
                    // Log de l'action
                    $DB->insert('glpi_plugin_watchman_sync_logs', [
                        'computer_mapping_id' => $computer_id,
                        'action' => 'bulk_' . $action,
                        'message' => "Action en masse: $action",
                        'status' => 'success',
                        'users_id' => Session::getLoginUserID(),
                        'date_creation' => date('Y-m-d H:i:s')
                    ]);
                } else {
                    $error_count++;
                }
                
            } catch (Exception $e) {
                $error_count++;
            }
        }
        
        echo json_encode([
            'success' => true,
            'message' => "$success_count ordinateur(s) traité(s) avec succès" . 
                        ($error_count > 0 ? ", $error_count erreur(s)" : ""),
            'success_count' => $success_count,
            'error_count' => $error_count
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Erreur lors de l\'action en masse',
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * Formate un ordinateur pour l'affichage JSON
 */
function formatComputerForDisplay($computer) {
    global $DB;
    
    // Compter les alertes pour cet ordinateur
    $alerts_count = 0;
    $critical_alerts = 0;
    $high_alerts = 0;
    
    // Pour l'instant, pas de comptage des alertes pour éviter les erreurs de schéma
    // TODO: Implémenter le comptage des alertes quand la relation sera claire
    
    return [
        'id' => $computer['id'],
        'name' => $computer['external_id'] ?? 'Computer #' . $computer['id'], // Pas de colonne name, utiliser external_id
        'ip' => null, // Pas de colonne IP dans le schéma actuel
        'external_id' => $computer['external_id'],
        'computers_id' => $computer['computers_id'],
        'sync_status' => $computer['sync_status'] ?? 'pending',
        'error_message' => $computer['error_message'],
        'last_sync_date' => $computer['last_sync_date'],
        'date_creation' => $computer['date_creation'],
        'description' => null, // Pas de colonne description
        'alerts_count' => $alerts_count,
        'critical_alerts' => $critical_alerts,
        'high_alerts' => $high_alerts
    ];
}

?>