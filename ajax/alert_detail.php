<?php

/**
 * Gestionnaire AJAX pour les détails d'une alerte Watchman
 */

use Glpi\Http\Response;
use GlpiPlugin\Watchman\AlertManager;

if (!defined('GLPI_ROOT')) {
    define('GLPI_ROOT', dirname(__DIR__, 3));
}

include GLPI_ROOT . "/inc/includes.php";
// Vérification des droits d'accès
// Session::checkRight("plugin_watchman_alert", READ);
define('GLPI_KEEP_CSRF_TOKEN', true);

// Vérification du token CSRF pour les opérations sensibles
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    exit;
}

// Vérifier l'authentification
Session::checkLoginUser();

// Vérification du token CSRF pour les opérations sensibles
$csrf_token = $_POST['_glpi_csrf_token'] ?? '';
if (!empty($_POST) && !Session::validateCSRF(['_glpi_csrf_token'=>$csrf_token])) {
    http_response_code(403);
    echo json_encode(['error' => 'Token CSRF invalide']);
    exit;
}

// Récupération de l'action demandée
$action = $_REQUEST['action'] ?? '';

// Instance du gestionnaire d'alertes
$alertManager = new AlertManager();

// Gestion des différentes actions
switch ($action) {
    
    case 'get_alert_detail':
        handleGetAlertDetail($alertManager);
        break;
        
    case 'patch_alert':
        handlePatchAlert($alertManager);
        break;
        
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Action non reconnue']);
        exit;
}

/**
 * Récupère les détails complets d'une alerte
 */
function handleGetAlertDetail($alertManager) {
    try {
        $alert_id = $_GET['alert_id'] ?? '';
        
        if (empty($alert_id)) {
            throw new Exception('ID d\'alerte manquant');
        }
        
        global $DB;
        
        // Requête principale pour récupérer l'alerte
        $alert_iterator = $DB->request([
            'FROM' => 'glpi_plugin_watchman_alerts',
            'WHERE' => [
                'id' => $alert_id,
                'is_deleted' => 0
            ]
        ]);
        
        if (!count($alert_iterator)) {
            throw new Exception('Alerte introuvable');
        }
        
        $alert = $alert_iterator->current();
        
        // Enrichissement avec les données CVE
        $cve_data = null;
        if ($alert['cves_id']) {
            $cve_iterator = $DB->request([
                'FROM' => 'glpi_plugin_watchman_cves',
                'WHERE' => [
                    'id' => $alert['cves_id'],
                    'is_deleted' => 0
                ]
            ]);
            
            if (count($cve_iterator)) {
                $cve_data = $cve_iterator->current();
            }
        }
        
        // Récupération des données d'impact CVE
        $impact_data = null;
        if ($alert['cves_id']) {
            $impact_iterator = $DB->request([
                'FROM' => 'glpi_plugin_watchman_cve_impacts',
                'WHERE' => [
                    'cves_id' => $alert['cves_id'],
                    'is_deleted' => 0
                ]
            ]);
            
            if (count($impact_iterator)) {
                $impact_data = $impact_iterator->current();
            }
        }
        
        // Récupération des données Stack
        $stack_data = null;
        if ($alert['stacks_id']) {
            $stack_iterator = $DB->request([
                'FROM' => 'glpi_plugin_watchman_stacks',
                'WHERE' => [
                    'id' => $alert['stacks_id'],
                    'is_deleted' => 0
                ]
            ]);
            
            if (count($stack_iterator)) {
                $stack_data = $stack_iterator->current();
            }
        }
        
        // Récupération des références CVE
        $references = [];
        if ($alert['cves_id']) {
            $ref_iterator = $DB->request([
                'FROM' => 'glpi_plugin_watchman_cve_references',
                'WHERE' => [
                    'cves_id' => $alert['cves_id'],
                    'is_deleted' => 0
                ],
                'ORDER' => 'name ASC'
            ]);
            
            foreach ($ref_iterator as $ref) {
                $references[] = [
                    'name' => $ref['name'],
                    'url' => $ref['url']
                ];
            }
        }
        
        // Récupération des CPE (produits vulnérables)
        $products = [];
        if ($alert['cves_id']) {
            $cpe_iterator = $DB->request([
                'FROM' => 'glpi_plugin_watchman_cpes',
                'WHERE' => [
                    'cves_id' => $alert['cves_id'],
                    'vulnerable' => 1,
                    'is_deleted' => 0
                ],
                'ORDER' => 'vendor ASC, name ASC'
            ]);
            
            foreach ($cpe_iterator as $cpe) {
                $products[] = [
                    'vendor' => $cpe['vendor'] ?: 'N/A',
                    'name' => $cpe['name'] ?: 'N/A',
                    'version' => $cpe['version'] ?: 'N/A'
                ];
            }
        }

        // tickets
        $ticket_url=null;
        if(isset($alert['tickets_id'])) {
           $ticket_url=$GLOBALS['CFG_GLPI']['root_doc']. '/front/ticket.form.php?id=' . $alert['tickets_id'];
        }

        // Formatage des données pour l'affichage
        $alert_detail = formatAlertDetailForDisplay($alert, $cve_data, $impact_data, $stack_data, $references, $products,$ticket_url);
        
        echo json_encode([
            'success' => true,
            'data' => $alert_detail
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
 * Marque une alerte comme patchée/non patchée
 */
function handlePatchAlert($alertManager) {
        $input = json_decode(file_get_contents('php://input'), true);
        $alert_id = $input['alert_id'] ?? '';
        $patched = (bool)($_POST['patched'] ?? false);
        echo json_encode($alertManager->handleMarkAlertAsPatched( $alert_id));
}

/**
 * Formate les données détaillées d'une alerte pour l'affichage
 */
function formatAlertDetailForDisplay($alert, $cve_data, $impact_data, $stack_data, $references, $products,$ticket_url) {
    // Formatage des dates
    $published_at = null;
    $modified_at = null;
    
    if ($cve_data) {
        $published_at = $cve_data['published_at'] ? date('d/m/Y H:i', strtotime($cve_data['published_at'])) : null;
        $modified_at = $cve_data['modified_at'] ? date('d/m/Y H:i', strtotime($cve_data['modified_at'])) : null;
    }
    
    // Calcul des niveaux de progression pour les barres de progression
    $progress_levels = calculateProgressLevels($impact_data);
    
    return [
        'id' => $alert['id'],
        'cve_id' => $alert['cves_id'],
        'title' => $alert['title'],
        'description' => $alert['description'],
        'score' => $alert['score'] ? number_format((float)$alert['score'], 1) : 'N/A',
        'severity' => translateSeverity($alert['severity']),
        'severity_class' => getSeverityClass($alert['severity']),
        'patched' => (bool)$alert['patched'],
        'patched_at' => $alert['patched_at'] ? date('d/m/Y H:i', strtotime($alert['patched_at'])) : null,
        'date_creation' => date('d/m/Y H:i', strtotime($alert['date_creation'])),
        
        // Données CVE
        'cve_assigner' => $cve_data['assigner'] ?? 'N/A',
        'published_at' => $published_at,
        'modified_at' => $modified_at,
        
        // Données d'impact
        'attack_vector' => $impact_data['attack_vector'] ?? 'N/A',
        'attack_complexity' => $impact_data['attack_complexity'] ?? 'N/A',
        'privileges_required' => $impact_data['privileges_required'] ?? 'N/A',
        'user_interaction' => $impact_data['user_interaction'] ?? 'N/A',
        'confidentiality_impact' => $impact_data['confidentiality_impact'] ?? 'N/A',
        'integrity_impact' => $impact_data['integrity_impact'] ?? 'N/A',
        'availability_impact' => $impact_data['availability_impact'] ?? 'N/A',
        'base_score' => $impact_data['base_score'] ?? 'N/A',
        'base_severity' => $impact_data['base_severity'] ?? 'N/A',
        
        // Stack vulnérable
        'stack' => $stack_data ? [
            'name' => $stack_data['name'],
            'version' => $stack_data['version'],
            'type' => $stack_data['type']
        ] : null,
        
        // Références et produits
        'references' => $references,
        'products' => $products,
        
        // Niveaux de progression pour les barres
        'progress_levels' => $progress_levels,
        'ticket_url'=>$ticket_url
    ];
}

/**
 * Calcule les niveaux de progression pour les barres de progression
 */
function calculateProgressLevels($impact_data) {
    if (!$impact_data) {
        return [
            'severity' => ['level' => 0, 'label' => 'N/A'],
            'attack_complexity' => ['level' => 0, 'label' => 'N/A'],
            'privileges_required' => ['level' => 0, 'label' => 'N/A'],
            'user_interaction' => ['level' => 0, 'label' => 'N/A'],
            'confidentiality_impact' => ['level' => 0, 'label' => 'N/A'],
            'integrity_impact' => ['level' => 0, 'label' => 'N/A'],
            'availability_impact' => ['level' => 0, 'label' => 'N/A']
        ];
    }
    
    return [
        'severity' => calculateSeverityLevel($impact_data['base_severity']),
        'attack_complexity' => calculateComplexityLevel($impact_data['attack_complexity']),
        'privileges_required' => calculatePrivilegesLevel($impact_data['privileges_required']),
        'user_interaction' => calculateInteractionLevel($impact_data['user_interaction']),
        'confidentiality_impact' => calculateImpactLevel($impact_data['confidentiality_impact']),
        'integrity_impact' => calculateImpactLevel($impact_data['integrity_impact']),
        'availability_impact' => calculateImpactLevel($impact_data['availability_impact'])
    ];
}

/**
 * Calcule le niveau de sévérité (0-100)
 */
function calculateSeverityLevel($severity) {
    switch (strtoupper($severity)) {
        case 'CRITICAL': return ['level' => 100, 'label' => 'Critique'];
        case 'HIGH': return ['level' => 75, 'label' => 'Élevée'];
        case 'MEDIUM': return ['level' => 50, 'label' => 'Moyenne'];
        case 'LOW': return ['level' => 25, 'label' => 'Faible'];
        default: return ['level' => 0, 'label' => 'N/A'];
    }
}

/**
 * Calcule le niveau de complexité d'attaque
 */
function calculateComplexityLevel($complexity) {
    switch (strtoupper($complexity)) {
        case 'LOW': return ['level' => 100, 'label' => 'Faible'];
        case 'HIGH': return ['level' => 25, 'label' => 'Élevée'];
        default: return ['level' => 0, 'label' => 'N/A'];
    }
}

/**
 * Calcule le niveau de privilèges requis
 */
function calculatePrivilegesLevel($privileges) {
    switch (strtoupper($privileges)) {
        case 'NONE': return ['level' => 100, 'label' => 'Aucun'];
        case 'LOW': return ['level' => 75, 'label' => 'Faibles'];
        case 'HIGH': return ['level' => 25, 'label' => 'Élevés'];
        default: return ['level' => 0, 'label' => 'N/A'];
    }
}

/**
 * Calcule le niveau d'interaction utilisateur
 */
function calculateInteractionLevel($interaction) {
    switch (strtoupper($interaction)) {
        case 'NONE': return ['level' => 100, 'label' => 'Aucune'];
        case 'REQUIRED': return ['level' => 25, 'label' => 'Requise'];
        default: return ['level' => 0, 'label' => 'N/A'];
    }
}

/**
 * Calcule le niveau d'impact
 */
function calculateImpactLevel($impact) {
    switch (strtoupper($impact)) {
        case 'HIGH': return ['level' => 100, 'label' => 'Élevé'];
        case 'LOW': return ['level' => 50, 'label' => 'Faible'];
        case 'NONE': return ['level' => 0, 'label' => 'Aucun'];
        default: return ['level' => 0, 'label' => 'N/A'];
    }
}

/**
 * Traduit la sévérité
 */
function translateSeverity($severity) {
    $translations = [
        'CRITICAL' => 'Critique',
        'HIGH' => 'Élevée',
        'MEDIUM' => 'Moyenne',
        'LOW' => 'Faible'
    ];
    
    return $translations[$severity] ?? $severity;
}

/**
 * Obtient la classe CSS pour la sévérité
 */
function getSeverityClass($severity) {
    $classes = [
        'CRITICAL' => 'danger',
        'HIGH' => 'warning',
        'MEDIUM' => 'info',
        'LOW' => 'success'
    ];
    
    return $classes[$severity] ?? 'secondary';
}

?>