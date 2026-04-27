<?php

/**
 * Gestionnaire AJAX pour les alertes Watchman
 */

use Glpi\Http\Response;
use GlpiPlugin\Watchman\AlertManager;
use GlpiPlugin\Watchman\WatchmanCronHelper;
use GlpiPlugin\Watchman\WatchmanProfile;


if (!defined('GLPI_ROOT')) {
    define('GLPI_ROOT', dirname(__DIR__, 3));
}
define('GLPI_KEEP_CSRF_TOKEN', true);

include GLPI_ROOT . "/inc/includes.php";

// Vérifier l'accès aux alertes
WatchmanProfile::checkPluginAccess();

// Vérification des droits d'accès
// Session::checkRight("plugin_watchman_alert", READ);

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

// Instance du gestionnaire d'alertes
$alertManager = new AlertManager();

// Gestion des différentes actions avec vérifications de droits
switch ($action) {
    
    case 'get_alerts':
    case 'get_stats':
        // Actions de lecture - nécessite le droit de voir les alertes
        WatchmanProfile::checkRightOr403(WatchmanProfile::RIGHT_WATCHMAN_VIEW, WatchmanProfile::READ);
        if ($action === 'get_alerts') {
            handleGetAlerts($alertManager);
        } else {
            handleGetStats($alertManager);
        }
        break;
        
    case 'mark_as_patched':
    case 'create_ticket':
    case 'bulk_action':
        WatchmanProfile::checkRightOr403(WatchmanProfile::RIGHT_WATCHMAN_MANAGE, WatchmanProfile::UPDATE);
        if ($action === 'mark_as_patched') {
            handleMarkAsPatched($alertManager);
        } elseif ($action === 'create_ticket') {
            if (\GlpiPlugin\Watchman\WatchmanConfig::getConfigValue('ticket_creation_enabled', '1') !== '1') {
                http_response_code(403);
                echo json_encode(['error' => __('La création de tickets est désactivée dans la configuration', 'watchman')]);
                exit;
            }
            handleCreateTicket($alertManager);
        } else {
            handleBulkAction($alertManager);
        }
        break;
        
    case 'delete_alert':
        // Suppression - nécessite des droits admin ou de suppression
        WatchmanProfile::checkRightOr403(WatchmanProfile::RIGHT_WATCHMAN_MANAGE, WatchmanProfile::DELETE);
        handleDeleteAlert($alertManager);
        break;

    case 'start_cron':
        // Gestion des crons - nécessite des droits cron
        WatchmanProfile::checkRightOr403(WatchmanProfile::RIGHT_WATCHMAN_CRON, WatchmanProfile::UPDATE);
        handleStartCron($alertManager);
        break;
        
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Action non reconnue']);
        exit;
}

/**
 * Récupère les alertes avec pagination et filtres
 */
function handleGetAlerts($alertManager) {
    try {
        // Paramètres de pagination
        $page = max(1, (int)($_GET['page'] ?? 1));
        $per_page = max(1, min(100, (int)($_GET['per_page'] ?? 20)));
        $start = ($page - 1) * $per_page;
        
        // Paramètres de filtrage
        $filters = [
            'search' => trim($_GET['search'] ?? ''),
            'severity' => $_GET['severity'] ?? null,
            'patched' => isset($_GET['patched']) ? (int)$_GET['patched'] : null,
            'date_from' => $_GET['date_from'] ?? null,
            'date_to' => $_GET['date_to'] ?? null,
            'order' => $_GET['order'] ?? 'date_creation',
            'sort' => $_GET['sort'] ?? 'DESC'
        ];
        
        // Options pour la requête
        $options = array_merge($filters, [
            'start' => $start,
            'limit' => $per_page
        ]);
        
        // Récupération des alertes
        $alerts = $alertManager->getAlerts($options);
        $total = $alertManager->countAlerts($filters);
        
        // Formatage des données pour l'affichage
        $formatted_alerts = [];
        foreach ($alerts as $alert) {
            $formatted_alerts[] = formatAlertForDisplay($alert);
        }
        
        // Calcul des informations de pagination
        $total_pages = max(1, ceil($total / $per_page));
        $has_next = $page < $total_pages;
        $has_prev = $page > 1;
        
        echo json_encode([
            'success' => true,
            'data' => $formatted_alerts,
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
            'error' => 'Erreur lors de la récupération des alertes',
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * Récupère les statistiques des alertes
 */


function handleGetStats($alertManager) {
    try {
        global $DB;
        
        $stats = $alertManager->getAlertsStats();
        
        // Statistiques temporelles
        $today = date('Y-m-d');
        $week_start = date('Y-m-d', strtotime('-7 days'));
        
        // Alertes d'aujourd'hui - Correction: Utilisation de COUNT(*) et récupération correcte
        $today_count = $DB->request([
            'SELECT' => ['COUNT' => '* AS count'], // Modification ici
            'FROM' => 'glpi_plugin_watchman_alerts',
            'WHERE' => [
                'is_deleted' => 0,
                'date_creation' => ['>=', $today . ' 00:00:00']
            ]
        ])->current()['count']; // Modification ici
        
        // Alertes de cette semaine - Correction: Utilisation de COUNT(*) et récupération correcte
        $week_count = $DB->request([
            'SELECT' => ['COUNT' => '* AS count'], // Modification ici
            'FROM' => 'glpi_plugin_watchman_alerts',
            'WHERE' => [
                'is_deleted' => 0,
                'date_creation' => ['>=', $week_start . ' 00:00:00']
            ]
        ])->current()['count']; // Modification ici
        
        $stats['today'] = $today_count;
        $stats['this_week'] = $week_count;
        
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
 * Marque une alerte comme corrigée
 */
function handleMarkAsPatched($alertManager) {
    try {
        $alert_id = $_POST['alert_id'] ?? '';
        $patched = (bool)($_POST['patched'] ?? false);
        
        if (empty($alert_id)) {
            throw new Exception('ID d\'alerte manquant');
        }
        
        global $DB;
        
        $update_data = [
            'patched' => $patched ? 1 : 0,
            'date_mod' => date('Y-m-d H:i:s')
        ];
        
        if ($patched) {
            $update_data['patched_at'] = date('Y-m-d H:i:s');
        } else {
            $update_data['patched_at'] = null;
        }
        
        $result = $DB->update(
            'glpi_plugin_watchman_alerts',
            $update_data,
            ['id' => $alert_id]
        );
        
        if ($result) {
            // Log de l'action
            $DB->insert('glpi_plugin_watchman_alert_logs', [
                'alerts_id' => $alert_id,
                'action' => $patched ? 'marked_patched' : 'marked_unpatched',
                'message' => $patched ? 'Alerte marquée comme corrigée' : 'Alerte marquée comme non corrigée',
                'users_id' => Session::getLoginUserID(),
                'date_creation' => date('Y-m-d H:i:s')
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => $patched ? 'Alerte marquée comme corrigée' : 'Alerte marquée comme non corrigée'
            ]);
        } else {
            throw new Exception('Erreur lors de la mise à jour');
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Erreur lors de la mise à jour du statut',
            'message' => $e->getMessage()
        ]);
    }
}


function handleStartCron($alertManager) {
    try {
        
        // $alertManager->startCron();
        WatchmanCronHelper::registerOnce('manual_cron_start','GlpiPlugin\Watchman\AlertManager::startCron');
        echo json_encode([
            'success' => true,
            'message' => 'Tache cron lancéé avec succès'
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Erreur lors de la mise à jour du statut',
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * Crée un ticket pour une alerte
 */
function handleCreateTicket($alertManager) {
    try {
        $alert_id = $_POST['alert_id'] ?? '';
        
        if (empty($alert_id)) {
            throw new Exception('ID d\'alerte manquant');
        }
        
        global $DB;
        
        // Récupération des données de l'alerte
        $alert_data = $DB->request([
            'FROM' => 'glpi_plugin_watchman_alerts',
            'WHERE' => ['id' => $alert_id]
        ])->current();
        
        if (!$alert_data) {
            throw new Exception('Alerte introuvable');
        }
        
        // Vérifier si un ticket existe déjà
        if ($alert_data['tickets_id']) {
            echo json_encode([
                'success' => false,
                'message' => 'Un ticket existe déjà pour cette alerte',
                'ticket_id' => $alert_data['tickets_id']
            ]);
            return;
        }
        
        // Création du ticket
        $ticket = new Ticket();
        $ticket_data = [
            'name' => 'Vulnérabilité: ' . $alert_data['title'],
            'content' => "Alerte de sécurité détectée:\n\n" .
                        "CVE: " . $alert_data['cves_id'] . "\n" .
                        "Sévérité: " . $alert_data['severity'] . "\n" .
                        "Score: " . $alert_data['score'] . "\n\n" .
                        "Description:\n" . $alert_data['description'],
            'urgency' => getSeverityUrgency($alert_data['severity']),
            'impact' => getSeverityImpact($alert_data['severity']),
            'priority' => getSeverityPriority($alert_data['severity']),
            'status' => CommonITILObject::INCOMING,
            'users_id_recipient' => Session::getLoginUserID(),
            'entities_id' => $_SESSION['glpiactive_entity']
        ];
        
        $ticket_id = $ticket->add($ticket_data);
        
        if ($ticket_id) {
            // Mise à jour de l'alerte avec l'ID du ticket
            $DB->update(
                'glpi_plugin_watchman_alerts',
                [
                    'tickets_id' => $ticket_id,
                    'date_mod' => date('Y-m-d H:i:s')
                ],
                ['id' => $alert_id]
            );
            
            // Log de l'action
            $DB->insert('glpi_plugin_watchman_alert_logs', [
                'alerts_id' => $alert_id,
                'action' => 'ticket_created',
                'message' => "Ticket #$ticket_id créé pour l'alerte",
                'users_id' => Session::getLoginUserID(),
                'date_creation' => date('Y-m-d H:i:s')
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Ticket créé avec succès',
                'ticket_id' => $ticket_id,
                'ticket_url' => $GLOBALS['CFG_GLPI']['root_doc'] . '/front/ticket.form.php?id=' . $ticket_id
            ]);
        } else {
            throw new Exception('Erreur lors de la création du ticket');
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Erreur lors de la création du ticket',
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * Supprime une alerte (soft delete)
 */

function handleDeleteAlert($alertManager) {
    try {
        $alert_id = $_POST['alert_id'] ?? '';
        
        if (empty($alert_id)) {
            throw new Exception('ID d\'alerte manquant');
        }
        
        global $DB;
        
        $result = $DB->update(
            'glpi_plugin_watchman_alerts',
            [
                'is_deleted' => 1,
                'date_mod' => date('Y-m-d H:i:s')
            ],
            ['id' => $alert_id]
        );
        
        if ($result) {
            // Log de l'action
            $DB->insert('glpi_plugin_watchman_alert_logs', [
                'alerts_id' => $alert_id,
                'action' => 'deleted',
                'message' => 'Alerte supprimée',
                'users_id' => Session::getLoginUserID(),
                'date_creation' => date('Y-m-d H:i:s')
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Alerte supprimée avec succès'
            ]);
        } else {
            throw new Exception('Erreur lors de la suppression');
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Erreur lors de la suppression de l\'alerte',
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * Gère les actions en masse
 */
function handleBulkAction($alertManager) {
    try {
        $action = $_POST['bulk_action'] ?? '';
        $alert_ids = $_POST['alert_ids'] ?? [];
        
        if (empty($action) || empty($alert_ids) || !is_array($alert_ids)) {
            throw new Exception('Paramètres manquants pour l\'action en masse');
        }
        
        global $DB;
        $success_count = 0;
        $error_count = 0;
        
        foreach ($alert_ids as $alert_id) {
            try {
                switch ($action) {
                    case 'mark_patched':
                        $result = $DB->update(
                            'glpi_plugin_watchman_alerts',
                            [
                                'patched' => 1,
                                'patched_at' => date('Y-m-d H:i:s'),
                                'date_mod' => date('Y-m-d H:i:s')
                            ],
                            ['id' => $alert_id]
                        );
                        break;
                        
                    case 'mark_unpatched':
                        $result = $DB->update(
                            'glpi_plugin_watchman_alerts',
                            [
                                'patched' => 0,
                                'patched_at' => null,
                                'date_mod' => date('Y-m-d H:i:s')
                            ],
                            ['id' => $alert_id]
                        );
                        break;
                        
                    case 'delete':
                        $result = $DB->update(
                            'glpi_plugin_watchman_alerts',
                            [
                                'is_deleted' => 1,
                                'date_mod' => date('Y-m-d H:i:s')
                            ],
                            ['id' => $alert_id]
                        );
                        break;
                        
                    default:
                        throw new Exception('Action non reconnue');
                }
                
                if ($result) {
                    $success_count++;
                    
                    // Log de l'action
                    $DB->insert('glpi_plugin_watchman_alert_logs', [
                        'alerts_id' => $alert_id,
                        'action' => 'bulk_' . $action,
                        'message' => "Action en masse: $action",
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
            'message' => "$success_count alerte(s) traitée(s) avec succès" . 
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
 * Formate une alerte pour l'affichage JSON
 */
function formatAlertForDisplay($alert) {
    return [
        'id' => $alert['id'],
        'cve_id' => $alert['cves_id'],
        'title' => $alert['title'],
        'description' => $alert['description'],
        'score' => $alert['score_formatted'] ?? $alert['score'],
        'score_class' => $alert['score_class'] ?? 'secondary',
        'severity' => $alert['severity_translated'] ?? $alert['severity'],
        'severity_class' => $alert['severity_class'] ?? 'secondary',
        'patched' => $alert['patched_status'] ?? [
            'is_patched' => (bool)$alert['patched'],
            'label' => $alert['patched'] ? 'Corrigée' : 'En attente',
            'class' => $alert['patched'] ? 'success' : 'warning'
        ],
        'ticket' => $alert['ticket_info'] ?? null,
        'date_creation' => $alert['date_creation_formatted'] ?? $alert['date_creation'],
        'date_relative' => $alert['date_creation_relative'] ?? '',
        'stack_info' => $alert['stack_info'] ?? null,
        'cve_info' => $alert['cve_info'] ?? null
    ];
}

/**
 * Convertit la sévérité en urgence GLPI
 */
function getSeverityUrgency($severity) {
    switch ($severity) {
        case 'CRITICAL': return 5; // Très haute
        case 'HIGH': return 4;     // Haute
        case 'MEDIUM': return 3;   // Moyenne
        case 'LOW': return 2;      // Basse
        default: return 3;         // Moyenne par défaut
    }
}

/**
 * Convertit la sévérité en impact GLPI
 */
function getSeverityImpact($severity) {
    switch ($severity) {
        case 'CRITICAL': return 5; // Très haut
        case 'HIGH': return 4;     // Haut
        case 'MEDIUM': return 3;   // Moyen
        case 'LOW': return 2;      // Bas
        default: return 3;         // Moyen par défaut
    }
}

/**
 * Calcule la priorité basée sur l'urgence et l'impact
 */
function getSeverityPriority($severity) {
    switch ($severity) {
        case 'CRITICAL': return 5; // Très haute
        case 'HIGH': return 4;     // Haute
        case 'MEDIUM': return 3;   // Moyenne
        case 'LOW': return 2;      // Basse
        default: return 3;         // Moyenne par défaut
    }
}

?>