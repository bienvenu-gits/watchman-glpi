<?php

use GlpiPlugin\Watchman\WatchmanProfile;

if (!defined('GLPI_ROOT')) {
    define('GLPI_ROOT', dirname(__DIR__, 3));
}
define('GLPI_KEEP_CSRF_TOKEN', true);

include GLPI_ROOT . "/inc/includes.php";

WatchmanProfile::checkPluginAccess();

if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    exit;
}

Session::checkLoginUser();

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

$action = $_REQUEST['action'] ?? '';

switch ($action) {
    case 'get_error_logs':
        // WatchmanProfile::checkRightOr403(WatchmanProfile::RIGHT_WATCHMAN_ADMIN, WatchmanProfile::READ);
        handleGetErrorLogs();
        break;

    case 'get_sync_logs':
        // WatchmanProfile::checkRightOr403(WatchmanProfile::RIGHT_WATCHMAN_ADMIN, WatchmanProfile::READ);
        handleGetSyncLogs();
        break;

    case 'get_stats':
        // WatchmanProfile::checkRightOr403(WatchmanProfile::RIGHT_WATCHMAN_ADMIN, WatchmanProfile::READ);
        handleGetStats();
        break;

    case 'resolve_error':
        // WatchmanProfile::checkRightOr403(WatchmanProfile::RIGHT_WATCHMAN_ADMIN, WatchmanProfile::UPDATE);
        handleResolveError();
        break;

    case 'purge_logs':
        // WatchmanProfile::checkRightOr403(WatchmanProfile::RIGHT_WATCHMAN_ADMIN, WatchmanProfile::DELETE);
        handlePurgeLogs();
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Action non reconnue']);
        exit;
}

function buildWhere(array $filters): array
{
    global $DB;
    $where = [];

    if (!empty($filters['severity'])) {
        $where['severity'] = $filters['severity'];
    }
    if (!empty($filters['error_type'])) {
        $where['error_type'] = $filters['error_type'];
    }
    if (isset($filters['is_resolved']) && $filters['is_resolved'] !== '') {
        $where['is_resolved'] = (int)$filters['is_resolved'];
    }
    if (!empty($filters['status'])) {
        $where['status'] = $filters['status'];
    }
    if (!empty($filters['filter_action'])) {
        $where['action'] = $filters['filter_action'];
    }
    if (!empty($filters['date_from'])) {
        $where[] = ['date_creation' => ['>=', $filters['date_from'] . ' 00:00:00']];
    }
    if (!empty($filters['date_to'])) {
        $where[] = ['date_creation' => ['<=', $filters['date_to'] . ' 23:59:59']];
    }
    if (!empty($filters['search'])) {
        $search = '%' . $DB->escape($filters['search']) . '%';
        if (!empty($filters['search_fields'])) {
            $or = [];
            foreach ($filters['search_fields'] as $field) {
                $or[] = [$field => ['LIKE', $search]];
            }
            $where['OR'] = $or;
        }
    }

    return $where;
}

function countRows(string $table, array $where): int
{
    global $DB;
    $params = ['FROM' => $table, 'COUNT' => 'cpt'];
    if ($where) {
        $params['WHERE'] = $where;
    }
    $row = $DB->request($params)->current();
    return (int)($row['cpt'] ?? 0);
}

function handleGetErrorLogs()
{
    global $DB;
    try {
        $page     = max(1, (int)($_GET['page'] ?? 1));
        $per_page = max(1, min(100, (int)($_GET['per_page'] ?? 25)));
        $start    = ($page - 1) * $per_page;

        $where = buildWhere([
            'severity'     => $_GET['severity'] ?? '',
            'error_type'   => $_GET['error_type'] ?? '',
            'is_resolved'  => $_GET['is_resolved'] ?? '',
            'date_from'    => $_GET['date_from'] ?? '',
            'date_to'      => $_GET['date_to'] ?? '',
            'search'       => $_GET['search'] ?? '',
            'search_fields'=> ['error_message', 'error_type', 'error_code'],
        ]);

        $query = ['FROM' => 'glpi_plugin_watchman_error_logs', 'ORDER' => 'date_creation DESC', 'START' => $start, 'LIMIT' => $per_page];
        if ($where) $query['WHERE'] = $where;

        $rows  = $DB->request($query);
        $total = countRows('glpi_plugin_watchman_error_logs', $where);

        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                'id'                => $row['id'],
                'error_type'        => $row['error_type'],
                'error_code'        => $row['error_code'],
                'error_message'     => $row['error_message'],
                'severity'          => $row['severity'],
                'severity_class'    => severityClass($row['severity']),
                'related_item_type' => $row['related_item_type'],
                'related_item_id'   => $row['related_item_id'],
                'is_resolved'       => (bool)$row['is_resolved'],
                'resolved_date'     => $row['resolved_date'],
                'date_creation'     => $row['date_creation'],
                'date_relative'     => dateRelative($row['date_creation']),
            ];
        }

        echo json_encode([
            'success'    => true,
            'data'       => $data,
            'pagination' => pagination($page, $per_page, $total),
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function handleGetSyncLogs()
{
    global $DB;
    try {
        $page     = max(1, (int)($_GET['page'] ?? 1));
        $per_page = max(1, min(100, (int)($_GET['per_page'] ?? 25)));
        $start    = ($page - 1) * $per_page;

        $where = buildWhere([
            'status'        => $_GET['status'] ?? '',
            'filter_action' => $_GET['filter_action'] ?? '',
            'date_from'     => $_GET['date_from'] ?? '',
            'date_to'       => $_GET['date_to'] ?? '',
            'search'        => $_GET['search'] ?? '',
            'search_fields' => ['message', 'action'],
        ]);

        $query = ['FROM' => 'glpi_plugin_watchman_sync_logs', 'ORDER' => 'date_creation DESC', 'START' => $start, 'LIMIT' => $per_page];
        if ($where) $query['WHERE'] = $where;

        $rows  = $DB->request($query);
        $total = countRows('glpi_plugin_watchman_sync_logs', $where);

        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                'id'                => $row['id'],
                'item_id'           => $row['item_id'],
                'itemtype'          => $row['itemtype'],
                'action'            => $row['action'],
                'status'            => $row['status'],
                'status_class'      => syncStatusClass($row['status']),
                'message'           => $row['message'],
                'execution_time'    => $row['execution_time'],
                'api_response_code' => $row['api_response_code'],
                'api_response_time' => $row['api_response_time'],
                'batch_id'          => $row['batch_id'],
                'date_creation'     => $row['date_creation'],
                'date_relative'     => dateRelative($row['date_creation']),
            ];
        }

        echo json_encode([
            'success'    => true,
            'data'       => $data,
            'pagination' => pagination($page, $per_page, $total),
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function handleGetStats()
{
    global $DB;
    try {
        $stats = [
            'error_total'    => countRows('glpi_plugin_watchman_error_logs', []),
            'error_open'     => countRows('glpi_plugin_watchman_error_logs', ['is_resolved' => 0]),
            'error_critical' => countRows('glpi_plugin_watchman_error_logs', ['severity' => 'critical', 'is_resolved' => 0]),
            'sync_total'     => countRows('glpi_plugin_watchman_sync_logs', []),
            'sync_error'     => countRows('glpi_plugin_watchman_sync_logs', ['status' => 'error']),
            'sync_success'   => countRows('glpi_plugin_watchman_sync_logs', ['status' => 'success']),
        ];

        echo json_encode(['success' => true, 'data' => $stats]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function handleResolveError()
{
    global $DB;
    try {
        $csrf_token = $_POST['_glpi_csrf_token'] ?? '';
        if (!Session::validateCSRF(['_glpi_csrf_token' => $csrf_token])) {
            http_response_code(403);
            echo json_encode(['error' => 'Token CSRF invalide']);
            return;
        }

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            throw new Exception('ID manquant');
        }

        $DB->update('glpi_plugin_watchman_error_logs', [
            'is_resolved'   => 1,
            'resolved_date' => date('Y-m-d H:i:s'),
            'resolved_by'   => Session::getLoginUserID(),
        ], ['id' => $id]);

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function handlePurgeLogs()
{
    global $DB;
    try {
        $csrf_token = $_POST['_glpi_csrf_token'] ?? '';
        if (!Session::validateCSRF(['_glpi_csrf_token' => $csrf_token])) {
            http_response_code(403);
            echo json_encode(['error' => 'Token CSRF invalide']);
            return;
        }

        $type   = $_POST['type'] ?? '';
        $days   = max(1, (int)($_POST['days'] ?? 30));
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        if ($type === 'errors') {
            $DB->delete('glpi_plugin_watchman_error_logs', [
                ['date_creation' => ['<', $cutoff]],
                'is_resolved'   => 1,
            ]);
        } elseif ($type === 'sync') {
            $DB->delete('glpi_plugin_watchman_sync_logs', [
                ['date_creation' => ['<', $cutoff]],
            ]);
        } else {
            throw new Exception('Type de log invalide');
        }

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function pagination(int $page, int $per_page, int $total): array
{
    $total_pages = max(1, ceil($total / $per_page));
    return [
        'current_page' => $page,
        'per_page'     => $per_page,
        'total'        => $total,
        'total_pages'  => $total_pages,
        'has_next'     => $page < $total_pages,
        'has_prev'     => $page > 1,
    ];
}

function severityClass(string $severity): string
{
    switch (strtolower($severity)) {
        case 'critical': return 'danger';
        case 'error':    return 'danger';
        case 'warning':  return 'warning';
        case 'info':     return 'info';
        default:         return 'secondary';
    }
}

function syncStatusClass(string $status): string
{
    switch (strtolower($status)) {
        case 'success': return 'success';
        case 'error':   return 'danger';
        case 'pending': return 'warning';
        case 'skipped': return 'secondary';
        default:        return 'secondary';
    }
}

function dateRelative(?string $date): string
{
    if (!$date) return '';
    $diff = time() - strtotime($date);
    if ($diff < 60)     return 'à l\'instant';
    if ($diff < 3600)   return floor($diff / 60) . ' min';
    if ($diff < 86400)  return floor($diff / 3600) . 'h';
    if ($diff < 604800) return floor($diff / 86400) . 'j';
    return date('d/m/Y', strtotime($date));
}
