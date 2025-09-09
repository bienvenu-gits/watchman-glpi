<?php
namespace GlpiPlugin\Watchman;

use CURLFile;
use Exception;
use Session;
use Toolbox;

/**
 * Client API robuste pour l'envoi de données vers l'API externe
 * 
 * Cette classe gère :
 * - Authentification sécurisée
 * - Retry automatique avec backoff
 * - Circuit breaker
 * - Health check
 * - Logging détaillé
 */
class WatchmanApiClient {
    
    private $api_url;
    private $api_key;
    private $timeout;
    private $max_retries;
    private $circuit_breaker_failures = 0;
    private $circuit_breaker_timeout = 300; // 5 minutes
    private $last_failure_time = 0;
    
    // États du circuit breaker
    const CIRCUIT_CLOSED = 'closed';     // Normal
    const CIRCUIT_OPEN = 'open';         // API down - pas d'appels
    const CIRCUIT_HALF_OPEN = 'half_open'; // Test si API revenue
    
    public function __construct() {
        $this->api_url = WatchmanConfig::getConfigValue('api_url',WatchmanConfig::BASE_URL);
        $this->api_key = WatchmanConfig::getConfigValue('secret_key');
        $this->timeout = WatchmanConfig::getConfigValue('api_timeout', 30);
        $this->max_retries = WatchmanConfig::getConfigValue('max_retries', 30);
        
        // Chargement de l'état du circuit breaker
        $this->loadCircuitBreakerState();
        
        if (empty($this->api_url) || empty($this->api_key)) {
            throw new Exception(__('Configuration API manquante', 'watchman'));
        }
    }
    
    /**
     * Envoie un ordinateur vers l'API
     */
    public function sendComputer($computer_data, $action = 'create') {
        if (!$this->canMakeRequest()) {
            return [
                'success' => false,
                'error' => __('Circuit breaker ouvert - API indisponible', 'watchman'),
                'circuit_breaker' => true
            ];
        }
        
        $endpoint = $this->getEndpoint($action);
        $method = $this->getHttpMethod($action);
        
        return $this->makeRequestWithRetry($endpoint, $method, $computer_data);
    }
    
    
/**
 * Effectue un appel API avec retry automatique - version modifiée pour fichiers
 */
private function makeRequestWithRetry($endpoint, $method, $data = null, $attempt = 1, $custom_headers = null, $is_file = false) {
    try {
        $result = $this->makeHttpRequest($endpoint, $method, $data, $custom_headers, $is_file);
        
        // Succès - réinitialiser le circuit breaker
        $this->onRequestSuccess();
        
        return [
            'success' => true,
            'data' => $result,
            'attempts' => $attempt
        ];
        
    } catch (Exception $e) {
        $this->logError($endpoint, $method, $e->getMessage(), $attempt);
        
        // Vérifier si c'est une erreur temporaire ou permanente
        $is_retryable = $this->isRetryableError($e);
        
        if ($is_retryable && $attempt < $this->max_retries) {
            // Attendre avant retry (backoff exponentiel)
            $delay = pow(2, $attempt) * 1000000; // microsecondes
            usleep($delay);
            
            return $this->makeRequestWithRetry($endpoint, $method, $data, $attempt + 1, $custom_headers, $is_file);
        } else {    
            // Échec définitif
            $this->onRequestFailure($e);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'attempts' => $attempt,
                'retryable' => $is_retryable
            ];
        }
    }
}

/**
 * Effectue l'appel HTTP réel - version modifiée pour gérer les fichiers
 */
public function makeHttpRequest($endpoint, $method, $data = null, $custom_headers = null, $is_file = false) {
    $url = rtrim($this->api_url, '/') . '/' . ltrim($endpoint, '/');
    
    $ch = curl_init();        
    
    // Configuration de base
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_CONNECTTIMEOUT => 100,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_USERAGENT => 'GLPI-Plugin-watchman/1.0'
    ]);
    
    // Gestion des en-têtes selon si c'est un fichier ou non
    if ($is_file) {
        // Pour l'envoi de fichiers, ne pas définir Content-Type (curl le fera automatiquement)
        $headers = $custom_headers != null ? $custom_headers : [
            'Authorization: Bearer ' . $this->api_key,
            'Accept: application/json',
            'X-Requested-With: XMLHttpRequest'
        ];
        
        // Retirer Content-Type des en-têtes personnalisés si présent
        $headers = array_filter($headers, function($header) {
            return stripos($header, 'Content-Type:') === false;
        });
    } else {
        // Pour les données JSON normales
        $headers = $custom_headers != null ? $custom_headers : [
            'Authorization: Bearer ' . $this->api_key,
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Requested-With: XMLHttpRequest'
        ];
    }
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    // Configuration selon la méthode HTTP
    switch (strtoupper($method)) {
        case 'POST':
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                if ($is_file) {
                    // Envoyer le fichier
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                } else {
                    // Envoyer les données JSON normales
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
            }
            break;
            
        case 'PUT':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($data) {
                if ($is_file) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                } else {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
            }
            break;
            
        case 'PATCH':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            if ($data) {
                if ($is_file) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                } else {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
            }
            break;
            
        case 'DELETE':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            break;
    }
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    
    // Métriques de performance
    $total_time = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    $connect_time = curl_getinfo($ch, CURLINFO_CONNECT_TIME);
    
    curl_close($ch);
    
    // Gestion des erreurs cURL
    if ($curl_error) {
        throw new Exception("Erreur cURL: " . $curl_error);
    }
    
    // Log des métriques
    $this->logMetrics($url, $method, $http_code, $total_time, $connect_time);
    
    // Gestion des codes de réponse HTTP
    if ($http_code >= 400) {
        $error_details = $this->parseErrorResponse($response, $http_code);
        throw new Exception($error_details);
    }
    
    // Parsing de la réponse JSON
    $decoded = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Réponse JSON invalide: " . json_last_error_msg());
    }
    
    return $decoded;
}

/**
 * Fonction spécialisée pour envoyer un fichier multipart
 */
public function makeFileUploadRequest($endpoint, $filepath, $custom_headers = null, $additional_fields = []) {
    if (!file_exists($filepath)) {
        throw new Exception("Fichier non trouvé: " . $filepath);
    }
    
    $url = rtrim($this->api_url, '/') . '/' . ltrim($endpoint, '/');
    $ch = curl_init();
    
    // Préparer les données multipart
    $postfields = array_merge($additional_fields, [
        'file' => new CURLFile($filepath, 'application/json', basename($filepath))
    ]);
    
    // En-têtes (sans Content-Type pour multipart)
    $headers = $custom_headers != null ? $custom_headers : [
        'Authorization: Bearer ' . $this->api_key,
        'Accept: application/json',
        'X-Requested-With: XMLHttpRequest'
    ];
    
    // Retirer Content-Type si présent (curl le gère automatiquement pour multipart)
    $headers = array_filter($headers, function($header) {
        return stripos($header, 'Content-Type:') === false;
    });
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postfields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_CONNECTTIMEOUT => 100,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_USERAGENT => 'GLPI-Plugin-watchman/1.0',
        CURLOPT_HTTPHEADER => $headers
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    
    curl_close($ch);
    
    if ($curl_error) {
        throw new Exception("Erreur cURL: " . $curl_error);
    }
    
    if ($http_code >= 400) {
        $error_details = $this->parseErrorResponse($response, $http_code);
        throw new Exception($error_details);
    }
    
    $decoded = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Réponse JSON invalide: " . json_last_error_msg());
    }
    
    return $decoded;
}
    
    /**
     * Vérification de santé de l'API
     */
    public function isHealthy() {
        try {
            $health = $this->performHealthCheck();
            return $health['status'] === 'healthy';
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Health check détaillé
     */
    public function performHealthCheck() {
        $start_time = microtime(true);
        
        try {
            $result = $this->makeHttpRequest('/health', 'GET');
            $latency = round((microtime(true) - $start_time) * 1000); // en ms
            
            return [
                'status' => 'healthy',
                'latency' => $latency,
                'response' => $result
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
                'latency' => round((microtime(true) - $start_time) * 1000)
            ];
        }
    }

    /**
     * Gestion du circuit breaker
     * @param mixed $
     * @return array
     */
    public function getAlerts($params = []) {
    // Vérification du circuit breaker
    // if (!$this->canMakeRequest()) {
    //     return [
    //         'success' => false,
    //         'error' => __('Circuit breaker ouvert - API indisponible', 'watchman'),
    //         'circuit_breaker' => true
    //     ];
    // }

    // Vérification de la configuration agent
    $agent_id = WatchmanConfig::getConfigValue('public_key');
    $agent_secret = WatchmanConfig::getConfigValue('secret_key');
    
    if (empty($agent_id) || empty($agent_secret)) {
        return [
            'success' => false,
            'error' => __('Configuration agent manquante pour les alertes', 'watchman')
        ];
    }

    // Construction de l'endpoint avec paramètres
    $endpoint = 'agent/alerts';
    if (!empty($params)) {
        $endpoint .= '?' . http_build_query($params);
    }

    // En-têtes personnalisés pour l'agent
    $custom_headers = [
        'AGENT-ID: ' . $agent_id,
        'AGENT-SECRET: ' . $agent_secret,
        'Content-Type: application/json',
        'Accept: application/json'
    ];

    return $this->makeRequestWithRetry($endpoint, 'GET', null,1, $custom_headers);
}
    
    /**
     * Gestion du circuit breaker
     */
    private function canMakeRequest() {
        $state = $this->getCircuitState();
        
        switch ($state) {
            case self::CIRCUIT_CLOSED:
                return true;
                
            case self::CIRCUIT_OPEN:
                // Vérifier si on peut passer en half-open
                if (time() - $this->last_failure_time > $this->circuit_breaker_timeout) {
                    $this->setCircuitState(self::CIRCUIT_HALF_OPEN);
                    return true;
                }
                return false;
                
            case self::CIRCUIT_HALF_OPEN:
                return true;
                
            default:
                return true;
        }
    }
    
    /**
     * Succès d'une requête
     */
    private function onRequestSuccess() {
        $this->circuit_breaker_failures = 0;
        $this->setCircuitState(self::CIRCUIT_CLOSED);
    }
    
    /**
     * Échec d'une requête
     */
    private function onRequestFailure($exception) {
        $this->circuit_breaker_failures++;
        $this->last_failure_time = time();
        
        // Ouvrir le circuit après 5 échecs consécutifs
        if ($this->circuit_breaker_failures >= 5) {
            $this->setCircuitState(self::CIRCUIT_OPEN);
        }
        
        // $this->saveCircuitBreakerState();
    }
    
    /**
     * Détermine si une erreur est retry-able
     */
    private function isRetryableError($exception) {
        $message = $exception->getMessage();
        
        // Erreurs temporaires (retry possible)
        $retryable_patterns = [
            '/timeout/i',
            '/connection/i',
            '/network/i',
            '/502/',
            '/503/',
            '/504/',
            '/429/' // Rate limiting
        ];
        
        foreach ($retryable_patterns as $pattern) {
            if (preg_match($pattern, $message)) {
                return true;
            }
        }
        
        // Erreurs permanentes (pas de retry)
        $non_retryable_patterns = [
            '/401/', // Unauthorized
            '/403/', // Forbidden
            '/404/', // Not found
            '/400/'  // Bad request
        ];
        
        foreach ($non_retryable_patterns as $pattern) {
            if (preg_match($pattern, $message)) {
                return false;
            }
        }
        
        // Par défaut, on considère que c'est retry-able
        return true;
    }
    
    /**
     * Obtient l'endpoint selon l'action
     */
    private function getEndpoint($action) {
        $endpoints = [
            'create' => '/computers',
            'update' => '/computers/{id}',
            'delete' => '/computers/{id}',
            'get' => '/computers/{id}'
        ];
        
        return $endpoints[$action] ?? '/computers';
    }
    
    /**
     * Obtient la méthode HTTP selon l'action
     */
    private function getHttpMethod($action) {
        $methods = [
            'create' => 'POST',
            'update' => 'PUT',
            'delete' => 'DELETE',
            'get' => 'GET'
        ];
        
        return $methods[$action] ?? 'POST';
    }
    
    /**
     * Parse les erreurs de l'API
     */
    private function parseErrorResponse($response, $http_code) {
        $decoded = json_decode($response, true);
        
        if ($decoded && isset($decoded['error'])) {
            return "API Error ($http_code): " . $decoded['error'];
        }
        
        if ($decoded && isset($decoded['message'])) {
            return "API Error ($http_code): " . $decoded['message'];
        }
        
        return "HTTP Error $http_code: " . substr($response, 0, 200);
    }
    
    /**
     * Logging des erreurs
     */
    private function logError($endpoint, $method, $error, $attempt) {
        $log_entry = sprintf(
            "[%s] %s %s - Tentative %d - Erreur: %s",
            date('Y-m-d H:i:s'),
            $method,
            $endpoint,
            $attempt,
            $error
        );
        
        Toolbox::logInFile('watchman_api_errors', $log_entry);
    }
    
    /**
     * Logging des métriques
     */
    private function logMetrics($url, $method, $http_code, $total_time, $connect_time) {
        // En production, tu peux envoyer ça vers un système de monitoring
        if ($_SESSION['glpi_use_mode'] == Session::DEBUG_MODE) {
            $log_entry = sprintf(
                "[METRICS] %s %s - Code: %d - Total: %.3fs - Connect: %.3fs",
                $method,
                parse_url($url, PHP_URL_PATH),
                $http_code,
                $total_time,
                $connect_time
            );
            
            Toolbox::logInFile('watchman_api_metrics', $log_entry);
        }
    }
    
    /**
     * État du circuit breaker
     */
    private function getCircuitState() {
        return WatchmanConfig::getConfigValue('circuit_breaker_state', self::CIRCUIT_CLOSED);
    }
    
    private function setCircuitState($state) {
        global $DB;
        
        $DB->updateOrInsert(
            'glpi_plugin_watchman_watchmanconfigs',
            ['name' => 'circuit_breaker_state', 'value' => $state],
            ['name' => 'circuit_breaker_state']
        );
    }
    
    private function loadCircuitBreakerState() {
        $this->circuit_breaker_failures = (int) WatchmanConfig::getConfigValue('circuit_breaker_failures', 0);
        $this->last_failure_time = (int) WatchmanConfig::getConfigValue('last_failure_time', 0);
    }
    /**
 * Synchronise un lot d'assets vers l'API
 * 
 * @param array $assets_payload Payload contenant les assets au format API
 * @return array Résultat de la synchronisation
 */
public function syncAssets($assets_payload) {
    ini_set('memory_limit', '500M');
    $agent_id = WatchmanConfig::getConfigValue('public_key');
    $agent_secret = WatchmanConfig::getConfigValue('secret_key');
    
    if (empty($agent_id) || empty($agent_secret)) {
        return [
            'success' => false,
            'error' => __('Configuration agent manquante pour les alertes', 'watchman')
        ];
    }
    
    // Validation du payload
    if (!isset($assets_payload['assets']) || !is_array($assets_payload['assets'])) {
        return [
            'success' => false,
            'error' => __('Format de payload invalide - clé "assets" manquante', 'watchman')
        ];
    }
    
    if (empty($assets_payload['assets'])) {
        return [
            'success' => false,
            'error' => __('Aucun asset à synchroniser', 'watchman')
        ];
    }
    
    // Créer le dossier pour les fichiers JSON s'il n'existe pas
    $json_dir = GLPI_ROOT . '/files/_tmp/watchman_sync/';
    if (!is_dir($json_dir)) {
        if (!mkdir($json_dir, 0755, true)) {
            return [
                'success' => false,
                'error' => __('Impossible de créer le dossier pour les fichiers JSON', 'watchman')
            ];
        }
    }
    
    // Générer un nom de fichier unique
    $timestamp = date('Y-m-d_H-i-s');
    $unique_id = uniqid();
    $filename = "assets_sync_{$timestamp}_{$unique_id}.json";
    $filepath = $json_dir . $filename;
    
    try {
        // Créer le fichier JSON avec le payload
        $json_content = json_encode($assets_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        if ($json_content === false) {
            return [
                'success' => false,
                'error' => __('Erreur lors de l\'encodage JSON: ', 'watchman') . json_last_error_msg()
            ];
        }
        
        // Écrire le fichier
        $bytes_written = file_put_contents($filepath, $json_content);
        
        if ($bytes_written === false) {
            return [
                'success' => false,
                'error' => __('Impossible d\'écrire le fichier JSON', 'watchman')
            ];
        }
        
        
        // Endpoint pour la synchronisation des assets
        $endpoint = '/agent/webhook_v2/';
        
        // En-têtes personnalisés pour l'agent
        $custom_headers = [
            'AGENT-ID: ' . $agent_id,
            'AGENT-SECRET: ' . $agent_secret,
            'Accept: application/json',
            'X-Sync-File: ' . $filename,
            'X-Assets-Count: ' . count($assets_payload['assets'])
        ];
        
        // OPTION 1: Envoyer le contenu du fichier comme données JSON
        // $result = $this->makeRequestWithRetry($endpoint, 'POST', $json_content, 1, $custom_headers, true);
       
        // OPTION 2: Alternative - Upload multipart du fichier (décommentez si nécessaire)
        $result = $this->makeFileUploadRequestWithRetry($endpoint, $filepath, $custom_headers);
        
        // Gérer le résultat et le fichier selon le succès/échec
        if ($result['success']) {
            // Succès : archiver le fichier ou le supprimer selon la configuration
            $this->handleSuccessfulSyncFile($filepath, $filename, $result);
        } else {
            // Échec : conserver le fichier pour investigation
            $this->handleFailedSyncFile($filepath, $filename, $result);
        }
        
        return $result;
        
    } catch (Exception $e) {
        // En cas d'exception, supprimer le fichier temporaire s'il existe
        if (file_exists($filepath)) {
            unlink($filepath);
        }
        
        return [
            'success' => false,
            'error' => __('Exception lors de la synchronisation: ', 'watchman') . $e->getMessage()
        ];
    }
}

/**
 * Version avec retry pour l'upload de fichiers multipart
 */
private function makeFileUploadRequestWithRetry($endpoint, $filepath, $custom_headers = null, $attempt = 1) {
    try {
        $result = $this->makeFileUploadRequest($endpoint, $filepath, $custom_headers);
        
        $this->onRequestSuccess();
        
        return [
            'success' => true,
            'data' => $result,
            'attempts' => $attempt
        ];
        
    } catch (Exception $e) {
        $this->logError($endpoint, 'FILE_UPLOAD', $e->getMessage(), $attempt);
        
        $is_retryable = $this->isRetryableError($e);
        
        if ($is_retryable && $attempt < $this->max_retries) {
            $delay = pow(2, $attempt) * 1000000;
            usleep($delay);
            
            return $this->makeFileUploadRequestWithRetry($endpoint, $filepath, $custom_headers, $attempt + 1);
        } else {
            $this->onRequestFailure($e);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'attempts' => $attempt,
                'retryable' => $is_retryable
            ];
        }
    }
}

/**
 * Gère le fichier JSON après une synchronisation réussie
 */
private function handleSuccessfulSyncFile($filepath, $filename, $result) {
    $config_keep_files = WatchmanConfig::getConfigValue('keep_sync_files', false);
    
    if ($config_keep_files) {
        // Archiver le fichier
        $archive_dir = dirname($filepath) . '/success/';
        if (!is_dir($archive_dir)) {
            mkdir($archive_dir, 0755, true);
        }
        
        $archive_path = $archive_dir . $filename;
        if (rename($filepath, $archive_path)) {
            Toolbox::logInFile('watchman_sync', 
                "Fichier archivé avec succès: $filename");
        }
    } else {
        // Supprimer le fichier
        if (unlink($filepath)) {
            Toolbox::logInFile('watchman_sync', 
                "Fichier supprimé après succès: $filename");
        }
    }
}

/**
 * Gère le fichier JSON après une synchronisation échouée
 */
private function handleFailedSyncFile($filepath, $filename, $result) {
    // Toujours conserver les fichiers d'échec pour investigation
    $error_dir = dirname($filepath) . '/errors/';
    if (!is_dir($error_dir)) {
        mkdir($error_dir, 0755, true);
    }
    
    $error_path = $error_dir . $filename;
    if (rename($filepath, $error_path)) {
        Toolbox::logInFile('watchman_sync', 
            "Fichier conservé pour investigation: $filename - Erreur: " . ($result['error'] ?? 'Inconnue'));
    }
}

/**
 * Nettoie les anciens fichiers JSON (à appeler périodiquement)
 */
public function cleanupOldSyncFiles($days_to_keep = 7) {
    $base_dir = GLPI_ROOT . '/files/_tmp/watchman_sync/';
    $directories = ['success', 'errors'];
    $cleaned_count = 0;
    
    foreach ($directories as $dir_name) {
        $dir_path = $base_dir . $dir_name . '/';
        
        if (!is_dir($dir_path)) {
            continue;
        }
        
        $files = glob($dir_path . 'assets_sync_*.json');
        $cutoff_time = time() - ($days_to_keep * 24 * 60 * 60);
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoff_time) {
                if (unlink($file)) {
                    $cleaned_count++;
                }
            }
        }
    }
    
    if ($cleaned_count > 0) {
        Toolbox::logInFile('watchman_sync', 
            "Nettoyage: $cleaned_count fichiers anciens supprimés");
    }
    
    return $cleaned_count;
}


public function patchAlerts($alert_id) {
    $agent_id = WatchmanConfig::getConfigValue('public_key');
    $agent_secret = WatchmanConfig::getConfigValue('secret_key');
    
    if (empty($agent_id) || empty($agent_secret)) {
        return [
            'success' => false,
            'error' => __('Configuration agent manquante pour les alertes', 'watchman')
        ];
    }

    // Construction de l'endpoint avec paramètres
    $endpoint = 'agent/patch_alerts/';
    if (!empty($params)) {
        $endpoint .= '?' . http_build_query($params);
    }

    // En-têtes personnalisés pour l'agent
    $custom_headers = [
        'AGENT-ID: ' . $agent_id,
        'AGENT-SECRET: ' . $agent_secret,
        'Content-Type: application/json',
        'Accept: application/json'
    ];

    return $this->makeRequestWithRetry($endpoint, 'POST', ['alert_id'=>$alert_id],1, $custom_headers);
}
}