<?php

use GlpiPlugin\Watchman\AlertManager;
use GlpiPlugin\Watchman\CronSyncAlert;

/**
 * Gestionnaire AJAX pour Watchman
 * Gère toutes les actions AJAX pour l'interface des alertes de sécurité
 */

// Vérifications de sécurité de base
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

try {
    // Récupérer les données de la requête
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception(__('Données invalides', 'watchman'));
    }
    
    // Vérifier le token CSRF
    if (!isset($input['_glpi_csrf_token']) || !Session::validateCSRF($input)) {
        throw new Exception(__('Token CSRF invalide'.$input['_glpi_csrf_token'], 'watchman'));
    }
    
    $action = $input['action'] ?? '';
    $response = ['success' => false, 'message' => ''];
    
    switch ($action) {
        case 'start_cron':
            $response = handleStartCron();
            break;
            
        case 'create_ticket':
            $response = handleCreateTicket($input);
            break;
            
        case 'mark_patched':
            
            $response = handleMarkAsPatched($input);
            break;
            
        case 'toggle_false_positive':
            $response = handleToggleFalsePositive($input);
            break;
            
        case 'delete_alert':
            $response = handleDeleteAlert($input);
            break;
            
        case 'bulk_action':
            $response = handleBulkAction($input);
            break;
            
        case 'get_alert_details':
            $response = handleGetAlertDetails($input);
            break;
            
        default:
            throw new Exception(__('Action non reconnue', 'watchman'));
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'input' => $input ?? null
    ]);
}

/**
 * Démarre la synchronisation des alertes via cron
 */
function handleStartCron() {
    try {
        // Vérifier les droits d'administration
        if (!Session::haveRight('config', UPDATE)) {
            throw new Exception(__('Droits insuffisants', 'watchman'));
        }
        
        // Instancier le gestionnaire d'alertes
        $alertManager = new AlertManager();
        
        // Démarrer la synchronisation
          $alertManager->startCron();
        
            return [
                'success' => true,
                'message' => __('Synchronisation démarrée avec succès', 'watchman')
            ];
        
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Crée un ticket GLPI à partir d'une alerte
 */
function handleCreateTicket($input) {
    global $DB;
     
    try {
        $alertId = (int) ($input['alert_id'] ?? 0);
        
        if (!$alertId) {
            throw new Exception(__('ID d\'alerte invalide', 'watchman'));
        }

       
        
        // Récupérer les détails de l'alerte
        // $query = "SELECT * FROM glpi_plugin_watchman_alerts WHERE id = ?";
        // $result = $DB->request($query, [$alertId]);
        
        // if (count($result) === 0) {
        //     throw new Exception(__('Alerte non trouvée', 'watchman'));
        // }
        $alertManager = new AlertManager();
        
        $alert = $alertManager->getAlertById($alertId);
        
        // Vérifier qu'un ticket n'existe pas déjà
        if ($alert['tickets_id']) {
            throw new Exception(__('Un ticket existe déjà pour cette alerte', 'watchman'));
        }

    


        
        // Créer le ticket

        $ticketId= $alertManager->createTicketFromAlert($alertId);
        
        return [
            'success' => true,
            'message' => sprintf(__('Ticket #%d créé avec succès', 'watchman'), $ticketId),
            'ticket_id' => $ticketId
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Marque une alerte comme corrigée
 */
function handleMarkAsPatched(array $input,$resolve_ticket = true) {
    global $DB;

    try {
        

        // Validation des paramètres
        $alertId =  $input['alert_id'] ?? 0;
        if ($alertId == 0) {
            throw new Exception(__('ID d\'alerte invalide', 'watchman'));
        }

        // Vérifier que l’alerte existe
        $alert = $DB->request([
            'SELECT' => ['id','tickets_id'],
            'FROM'   => 'glpi_plugin_watchman_alerts',
            'WHERE'  => ['id' => $alertId]
        ])->current();

        if (!$alert) {
            throw new Exception(__('Alerte introuvable', 'watchman'));
        }

        // Mise à jour sécurisée
        $updated = $DB->update(
            'glpi_plugin_watchman_alerts',
            [
                'patched'    => 1,
                'patched_at' => new \QueryExpression('NOW()'),
                'date_mod'   => new \QueryExpression('NOW()')
            ],
            ['id' => $alertId]
        );

        if ($updated === false) {
            throw new Exception(__('Erreur lors de la mise à jour', 'watchman'));
        }
        if(isset($alert['tickets_id'])){
            resolveTicket($alert['tickets_id']);
        }
        

        return [
            'success' => true,
            'message' => __('Alerte marquée comme corrigée'.$alert['tickets_id'], 'watchman')
        ];

    } catch (Exception $e) {
        // Log technique (pour debug interne)
        Toolbox::logDebug("handleMarkAsPatched error: ".$e->getMessage());

        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Resoudre le ticket correspondant à l'alerte
 */

function resolveTicket($ticketId) {
    global $DB;
    

    try {

        $ticketId = (int)$ticketId;
        if ($ticketId <= 0) {
            throw new Exception(__('ID de ticket invalide', 'watchman'));
        }

        $ticket = new Ticket();
        if (!$ticket->getFromDB($ticketId)) {
            throw new Exception(__('Ticket introuvable', 'watchman'));
        }

        // Mettre à jour le statut du ticket
        $ok = $ticket->update([
            'id'       => $ticketId,
            'status'   => CommonITILObject::SOLVED,
            'solvedate'=> date("Y-m-d H:i:s"),
            'date_mod' => date("Y-m-d H:i:s"),
        ]);

        if (!$ok) {
            throw new Exception(__('Erreur lors de la mise à jour du ticket', 'watchman'));
        }

        return true;

    } catch (Exception $e) {
        Toolbox::logDebug("resolveTicket error: ".$e->getMessage());
        return false;
    }
}


/**
 * Bascule le statut de faux positif d'une alerte
 */
function handleToggleFalsePositive($input) {
    global $DB;
    
    try {
        $alertId = (int) ($input['alert_id'] ?? 0);
        $isFalsePositive = (bool) ($input['is_false_positive'] ?? false);
        
        if (!$alertId) {
            throw new Exception(__('ID d\'alerte invalide', 'watchman'));
        }
        
        $query = "UPDATE glpi_plugin_watchman_alerts 
                  SET possible_false = ?, date_mod = NOW() 
                  WHERE id = ?";
        
        $result = $DB->query($query, [$isFalsePositive ? 1 : 0, $alertId]);
        
        if (!$result) {
            throw new Exception(__('Erreur lors de la mise à jour', 'watchman'));
        }
        
        $message = $isFalsePositive ? 
            __('Alerte marquée comme faux positif', 'watchman') :
            __('Alerte retirée des faux positifs', 'watchman');
        
        // Log de l'action
        Log::history($alertId, 'PluginWatchmanAlert', [
            0, '', sprintf('%s par %s', $message, getUserName(Session::getLoginUserID()))
        ]);
        
        return [
            'success' => true,
            'message' => $message
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Supprime une alerte
 */
function handleDeleteAlert($input) {
    global $DB;
    
    try {
        $alertId = (int) ($input['alert_id'] ?? 0);
        
        if (!$alertId) {
            throw new Exception(__('ID d\'alerte invalide', 'watchman'));
        }
        
        // Vérifier les droits de suppression
        if (!Session::haveRight('config', PURGE)) {
            throw new Exception(__('Droits insuffisants pour supprimer', 'watchman'));
        }
        
        $query = "DELETE FROM glpi_plugin_watchman_alerts WHERE id = ?";
        $result = $DB->query($query, [$alertId]);
        
        if (!$result) {
            throw new Exception(__('Erreur lors de la suppression', 'watchman'));
        }
        
        return [
            'success' => true,
            'message' => __('Alerte supprimée avec succès', 'watchman')
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Gère les actions groupées sur plusieurs alertes
 */
function handleBulkAction($input) {
    global $DB;
    
    try {
        $action = $input['bulk_action'] ?? '';
        $alertIds = $input['alert_ids'] ?? [];
        
        if (empty($alertIds) || !is_array($alertIds)) {
            throw new Exception(__('Aucune alerte sélectionnée', 'watchman'));
        }
        
        // Sécuriser les IDs
        $alertIds = array_map('intval', $alertIds);
        $alertIds = array_filter($alertIds, function($id) { return $id > 0; });
        
        if (empty($alertIds)) {
            throw new Exception(__('IDs d\'alertes invalides', 'watchman'));
        }
        
        $placeholders = str_repeat('?,', count($alertIds) - 1) . '?';
        $results = [];
        
        switch ($action) {
            case 'mark-patched':
                // $query = "UPDATE glpi_plugin_watchman_alerts 
                //           SET patched = 1, patched_at = NOW(), date_mod = NOW() 
                //           WHERE id IN ($placeholders)";
                // $result = $DB->query($query, $alertIds);
                foreach ($alertIds as $alertId) {
                    handleMarkAsPatched(['alert_id' => $alertId]);
                }
                $message = sprintf(__('%d alerte(s) marquée(s) comme corrigée(s)', 'watchman'), count($alertIds));
                break;
                
            // case 'false-positive':
            //     $query = "UPDATE glpi_plugin_watchman_alerts 
            //               SET possible_false = 1, date_mod = NOW() 
            //               WHERE id IN ($placeholders)";
            //     $result = $DB->query($query, $alertIds);
            //     $message = sprintf(__('%d alerte(s) marquée(s) comme faux positifs', 'watchman'), count($alertIds));
            //     break;
                
            case 'create-tickets':
                $results = bulkCreateTickets($alertIds);
                $message = sprintf(__('%d ticket(s) créé(s)', 'watchman'), count($results['success']));
                break;
                
            // case 'delete':
            //     if (!Session::haveRight('config', PURGE)) {
            //         throw new Exception(__('Droits insuffisants pour supprimer', 'watchman'));
            //     }
            //     $query = "DELETE FROM glpi_plugin_watchman_alerts WHERE id IN ($placeholders)";
            //     $result = $DB->query($query, $alertIds);
            //     $message = sprintf(__('%d alerte(s) supprimée(s)', 'watchman'), count($alertIds));
            //     break;
                
            default:
                throw new Exception(__('Action groupée non reconnue', 'watchman'));
        }
        
        return [
            'success' => true,
            'message' => $message,
            'results' => $results
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Récupère les détails d'une alerte pour affichage modal
 */
function handleGetAlertDetails($input) {
    global $DB;
    
    try {
        $alertId = (int) ($input['alert_id'] ?? 0);
        
        if (!$alertId) {
            throw new Exception(__('ID d\'alerte invalide', 'watchman'));
        }
        
        $query = "SELECT * FROM glpi_plugin_watchman_alerts WHERE id = ?";
        $result = $DB->request($query, [$alertId]);
        
        if (count($result) === 0) {
            throw new Exception(__('Alerte non trouvée', 'watchman'));
        }
        
        $alert = $result->current();
        
        return [
            'success' => true,
            'alert' => $alert,
            'formatted' => [
                'severity_label' => getSeverityLabel($alert['severity']),
                'severity_class' => getSeverityClass($alert['severity']),
                'status_label' => $alert['patched'] ? __('Corrigée', 'watchman') : __('En attente', 'watchman'),
                'status_class' => $alert['patched'] ? 'success' : 'warning',
                'formatted_date' => Html::convDateTime($alert['date_creation']),
                'formatted_score' => formatScore($alert['score'])
            ]
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Fonctions utilitaires
 */

function buildTicketContent($alert) {
    $content = sprintf(__("Une alerte de sécurité a été détectée:\n\n", 'watchman'));
    $content .= sprintf(__("CVE: %s\n", 'watchman'), $alert['cves_id']);
    $content .= sprintf(__("Titre: %s\n", 'watchman'), $alert['title']);
    $content .= sprintf(__("Description: %s\n\n", 'watchman'), $alert['description']);
    $content .= sprintf(__("Sévérité: %s\n", 'watchman'), getSeverityLabel($alert['severity']));
    
    if ($alert['score']) {
        $content .= sprintf(__("Score CVSS: %s\n", 'watchman'), $alert['score']);
    }
    
    $content .= sprintf(__("Date de détection: %s\n\n", 'watchman'), $alert['date_creation']);
    $content .= __("Veuillez vérifier et appliquer les correctifs nécessaires.", 'watchman');
    
    return $content;
}

function getSeverityUrgency($severity) {
    switch ($severity) {
        case 'CRITICAL': return 5; // Très haute
        case 'HIGH': return 4; // Haute
        case 'MEDIUM': return 3; // Moyenne
        case 'LOW': return 2; // Basse
        default: return 3;
    }
}

function getSeverityImpact($severity) {
    switch ($severity) {
        case 'CRITICAL': return 5; // Très haut
        case 'HIGH': return 4; // Haut
        case 'MEDIUM': return 3; // Moyen
        case 'LOW': return 2; // Bas
        default: return 3;
    }
}

function getSeverityPriority($severity) {
    switch ($severity) {
        case 'CRITICAL': return 5; // Très haute
        case 'HIGH': return 4; // Haute
        case 'MEDIUM': return 3; // Moyenne
        case 'LOW': return 2; // Basse
        default: return 3;
    }
}

function getSeverityLabel($severity) {
    switch ($severity) {
        case 'CRITICAL': return __('Critique', 'watchman');
        case 'HIGH': return __('Élevée', 'watchman');
        case 'MEDIUM': return __('Moyenne', 'watchman');
        case 'LOW': return __('Faible', 'watchman');
        default: return $severity;
    }
}

function getSeverityClass($severity) {
    switch ($severity) {
        case 'CRITICAL': return 'danger';
        case 'HIGH': return 'warning';
        case 'MEDIUM': return 'info';
        case 'LOW': return 'success';
        default: return 'secondary';
    }
}

function formatScore($score) {
    if (!$score) return '-';
    
    $class = 'success';
    if ($score >= 7.0) {
        $class = 'danger';
    } elseif ($score >= 4.0) {
        $class = 'warning';
    }
    
    return ['value' => $score, 'class' => $class];
}

function getSecurityCategoryId() {
    global $DB;
    
    // Essayer de trouver une catégorie "Sécurité" existante
    $query = "SELECT id FROM glpi_itilcategories 
              WHERE name LIKE '%sécurité%' OR name LIKE '%security%' 
              LIMIT 1";
    $result = $DB->request($query);
    
    if (count($result) > 0) {
        return $result->current()['id'];
    }
    
    return 0; // Aucune catégorie par défaut
}

function bulkCreateTickets($alertIds) {
    global $DB;
    
    $success = [];
    $errors = [];
    
    $query = "SELECT * FROM glpi_plugin_watchman_alerts 
              WHERE id IN (" . str_repeat('?,', count($alertIds) - 1) . "?) 
              AND tickets_id IS NULL";
    
    $result = $DB->request($query, $alertIds);
    
    foreach ($result as $alert) {
        try {
            $ticket = new Ticket();
            
            $ticketData = [
                'name' => sprintf(__('Alerte sécurité: %s', 'watchman'), $alert['title']),
                'content' => buildTicketContent($alert),
                'urgency' => getSeverityUrgency($alert['severity']),
                'impact' => getSeverityImpact($alert['severity']),
                'priority' => getSeverityPriority($alert['severity']),
                'type' => Ticket::INCIDENT_TYPE,
                'status' => CommonITILObject::INCOMING,
                'entities_id' => $_SESSION['glpiactive_entity'],
                'users_id_recipient' => Session::getLoginUserID(),
                'itilcategories_id' => getSecurityCategoryId()
            ];
            
            $ticketId = $ticket->add($ticketData);
            
            if ($ticketId) {
                // Mettre à jour l'alerte
                $updateQuery = "UPDATE glpi_plugin_watchman_alerts SET tickets_id = ? WHERE id = ?";
                $DB->query($updateQuery, [$ticketId, $alert['id']]);
                
                $success[] = [
                    'alert_id' => $alert['id'],
                    'ticket_id' => $ticketId
                ];
            } else {
                $errors[] = [
                    'alert_id' => $alert['id'],
                    'error' => __('Échec de création du ticket', 'watchman')
                ];
            }
        } catch (Exception $e) {
            $errors[] = [
                'alert_id' => $alert['id'],
                'error' => $e->getMessage()
            ];
        }
    }
    
    return [
        'success' => $success,
        'errors' => $errors
    ];
}
?>