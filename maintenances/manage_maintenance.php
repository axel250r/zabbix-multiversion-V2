<?php
declare(strict_types=1);

// ==================== DEBUG ====================
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
$debugFile = TMP_DIR . '/maint_debug.log';
file_put_contents($debugFile, date('Y-m-d H:i:s') . " ===== INICIO manage_maintenance.php =====\n", FILE_APPEND);
file_put_contents($debugFile, date('Y-m-d H:i:s') . " - POST: " . json_encode($_POST) . "\n", FILE_APPEND);
// ==================== FIN DEBUG ====================

set_time_limit(600);
ini_set('memory_limit', '512M');

session_start();

if (empty($_SESSION['zbx_auth_ok'])) {
    file_put_contents($debugFile, date('Y-m-d H:i:s') . " - ERROR: Sesión inválida\n", FILE_APPEND);
    http_response_code(403);
    die('Invalid session');
}

// Obtener tipo de usuario SIEMPRE desde la API
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/ZabbixApiFactory.php';

try {
    $api = ZabbixApiFactory::create(
        ZABBIX_API_URL,
        $_SESSION['zbx_user'],
        $_SESSION['zbx_pass'],
        ['timeout' => 10, 'verify_ssl' => VERIFY_SSL]
    );
    
    $current_user_type = $api->getUserType($_SESSION['zbx_user']);
    
    if (!isset($_SESSION['zbx_user_type']) || $_SESSION['zbx_user_type'] != $current_user_type) {
        $_SESSION['zbx_user_type'] = $current_user_type;
        file_put_contents($debugFile, date('Y-m-d H:i:s') . " - User type actualizado: $current_user_type\n", FILE_APPEND);
    } else {
        file_put_contents($debugFile, date('Y-m-d H:i:s') . " - User type: $current_user_type\n", FILE_APPEND);
    }
    
} catch (Throwable $e) {
    file_put_contents($debugFile, date('Y-m-d H:i:s') . " - ERROR user type: " . $e->getMessage() . "\n", FILE_APPEND);
    $_SESSION['zbx_user_type'] = $_SESSION['zbx_user_type'] ?? 1;
}

// Detectar versión de Zabbix si no está en sesión
if (!isset($_SESSION['zabbix_version'])) {
    try {
        $ch = curl_init(rtrim(ZABBIX_API_URL, '/'));
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'apiinfo.version',
            'params' => [],
            'id' => 1
        ]);
        
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json-rpc'],
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => defined('VERIFY_SSL') ? VERIFY_SSL : false,
            CURLOPT_SSL_VERIFYHOST => defined('VERIFY_SSL') ? (VERIFY_SSL ? 2 : 0) : 0,
            CURLOPT_HTTPAUTH => CURLAUTH_ANY,
            CURLOPT_USERPWD => '',
        ]);
        
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200 && $resp) {
            $data = json_decode($resp, true);
            if (isset($data['result'])) {
                $_SESSION['zabbix_version'] = $data['result'];
            }
        }
    } catch (Throwable $e) {
        error_log("Error detectando versión Zabbix: " . $e->getMessage());
    }
}

$user_type = $_SESSION['zbx_user_type'] ?? 1;
$zabbix_version = $_SESSION['zabbix_version'] ?? '6.0.0';

// Verificar permisos según acción
$action_type = $_POST['action_type'] ?? 'update';
file_put_contents($debugFile, date('Y-m-d H:i:s') . " - action_type: $action_type\n", FILE_APPEND);

if ($action_type === 'create') {
    if (version_compare($zabbix_version, '6.4', '<')) {
        $can_create = ($user_type == 1 || $user_type >= 2);
    } else {
        $can_create = ($user_type >= 2);
    }
    
    if (!$can_create) {
        file_put_contents($debugFile, date('Y-m-d H:i:s') . " - ERROR: Permiso denegado para create\n", FILE_APPEND);
        http_response_code(403);
        require_once __DIR__ . '/../lib/i18n.php';
        $msg = t('maint_err_permission');
        die('<h3>' . htmlspecialchars($msg) . '</h3><a href="index.php">' . t('maint_back_button') . '</a>');
    }
} elseif ($action_type === 'update') {
    if (version_compare($zabbix_version, '6.4', '<')) {
        $can_update = ($user_type == 1 || $user_type == 3);
    } else {
        $can_update = ($user_type == 3);
    }
    
    if (!$can_update) {
        file_put_contents($debugFile, date('Y-m-d H:i:s') . " - ERROR: Permiso denegado para update\n", FILE_APPEND);
        http_response_code(403);
        require_once __DIR__ . '/../lib/i18n.php';
        $msg = t('maint_err_permission');
        die('<h3>' . htmlspecialchars($msg) . '</h3><a href="index.php">' . t('maint_back_button') . '</a>');
    }
} else {
    file_put_contents($debugFile, date('Y-m-d H:i:s') . " - ERROR: Acción no válida\n", FILE_APPEND);
    http_response_code(400);
    die('Acción no válida');
}

// Validar CSRF
if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    file_put_contents($debugFile, date('Y-m-d H:i:s') . " - ERROR: CSRF token inválido\n", FILE_APPEND);
    http_response_code(403);
    die('Error: Invalid CSRF token.');
}

require_once __DIR__ . '/../lib/i18n.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/ZabbixApiFactory.php';

// ============================================================
// FUNCIÓN UNIVERSAL - Convierte usando la zona horaria del servidor
// ============================================================
function convertToServerTimestamp($isoDateString) {
    if (empty($isoDateString)) {
        return time();
    }
    
    try {
        $tz = new DateTimeZone(ZABBIX_TZ);
        $date = DateTime::createFromFormat('Y-m-d\TH:i', $isoDateString, $tz);
        
        if ($date === false) {
            $date = new DateTime($isoDateString, $tz);
        }
        
        return $date->getTimestamp();
    } catch (Exception $e) {
        error_log("Error convirtiendo fecha: " . $e->getMessage());
        return time();
    }
}
// ============================================================

// Funciones de resolución de hosts
function name_base(string $raw): string {
    $s = rtrim(trim($raw), '.');
    $parts = explode('.', $s, 2);
    return strtoupper($parts[0]);
}

function load_hosts_index($api): array {
    $res = $api->call('host.get', ['output' => ['hostid', 'host', 'name'], 'limit' => 200000]);
    $by_id = [];
    $by_base = [];
    foreach ($res as $h) {
        $hid = $h['hostid'];
        $host = $h['host'] ?? '';
        $nm = $h['name'] ?? $host;
        $by_id[$hid] = ['host' => $host, 'name' => $nm];
        $base = name_base($host);
        if (!isset($by_base[$base])) $by_base[$base] = [];
        $by_base[$base][$hid] = $hid;
    }
    return [$by_id, $by_base];
}

function resolve_one($api, string $raw): ?array {
    $out = ['hostid', 'host', 'name'];
    $res = $api->call('host.get', ['filter' => ['host' => [$raw]], 'output' => $out, 'limit' => 1]);
    if ($res) return $res[0];
    $res = $api->call('host.get', ['filter' => ['name' => [$raw]], 'output' => $out, 'limit' => 1]);
    if ($res) return $res[0];
    $res = $api->call('host.get', ['search' => ['name' => $raw], 'searchWildcardsEnabled' => true, 'output' => $out, 'limit' => 1]);
    if ($res) return $res[0];
    return null;
}

// 🔥 CORRECCIÓN: Eliminada la inclusión automática de siblings
function resolve_all_and_include_siblings($api, array $lines, array $by_id, array $by_base): array {
    $hostids_final = [];
    $unresolved = [];
    $log = [];
    foreach ($lines as $raw) {
        $raw = trim($raw);
        if (empty($raw)) continue;
        $resolved_host = resolve_one($api, $raw);
        if (!$resolved_host) {
            $unresolved[] = $raw;
            $log[] = "[SKIP] $raw (no resuelto)";
            continue;
        }
        $hid = $resolved_host['hostid'];
        $disp = $resolved_host['name'] . ' (' . $resolved_host['host'] . ')';
        
        if (!in_array($hid, $hostids_final)) {
            $hostids_final[] = $hid;
            $log[] = "[OK] $raw -> hostid $hid [$disp]";
        }
    }
    return [array_values(array_unique($hostids_final)), $unresolved, $log];
}

// Lógica Principal
try {
    file_put_contents($debugFile, date('Y-m-d H:i:s') . " - Creando API factory\n", FILE_APPEND);
    
    $api = ZabbixApiFactory::create(
        ZABBIX_API_URL,
        $_SESSION['zbx_user'],
        $_SESSION['zbx_pass'],
        ['timeout' => 590, 'verify_ssl' => VERIFY_SSL]
    );
    
    file_put_contents($debugFile, date('Y-m-d H:i:s') . " - API creada: " . get_class($api) . "\n", FILE_APPEND);

    $action_type = $_POST['action_type'] ?? 'update';
    $hostnames_raw = $_POST['hostnames'] ?? '';
    $host_lines = explode("\n", str_replace("\r", "", $hostnames_raw));

    file_put_contents($debugFile, date('Y-m-d H:i:s') . " - Resolviendo hosts\n", FILE_APPEND);
    
    list($by_id, $by_base) = load_hosts_index($api);
    // 🔥 Ahora usa la función corregida
    list($hostids_final, $unresolved, $log) = resolve_all_and_include_siblings($api, $host_lines, $by_id, $by_base);

    file_put_contents($debugFile, date('Y-m-d H:i:s') . " - Hosts resueltos: " . json_encode($hostids_final) . "\n", FILE_APPEND);

    if (empty($hostids_final)) {
        throw new RuntimeException(t('maint_err_no_hosts') . "\n\n" . implode("\n", $log));
    }

    $result_message = "";
    $maintenanceids = [];

    if ($action_type === 'create') {
        file_put_contents($debugFile, date('Y-m-d H:i:s') . " - Creando mantenimiento\n", FILE_APPEND);
        
        $active_since = convertToServerTimestamp($_POST['active_since_timestamp'] ?? '');
        $active_till = convertToServerTimestamp($_POST['active_till_timestamp'] ?? '');
        
        $params = [
            "name" => $_POST['maint_name'] ?? 'Mantenimiento',
            "maintenance_type" => (int)($_POST['maintenance_type'] ?? 1),
            "description" => $_POST['description'] ?? '',
            "active_since" => $active_since,
            "active_till" => $active_till,
            "timeperiods" => []
        ];

        file_put_contents($debugFile, date('Y-m-d H:i:s') . " - Params base: " . json_encode($params) . "\n", FILE_APPEND);

        $timeperiods_json = $_POST['timeperiods_json'] ?? '[]';
        $timeperiods = json_decode($timeperiods_json, true);

        file_put_contents($debugFile, date('Y-m-d H:i:s') . " - Timeperiods: " . json_encode($timeperiods) . "\n", FILE_APPEND);

        if (empty($timeperiods) || !is_array($timeperiods)) {
            throw new RuntimeException(t('maint_err_no_periods'));
        }

        foreach ($timeperiods as &$period) {
            if (!isset($period['timeperiod_type'])) {
                $period['timeperiod_type'] = 0;
            }
            
            if (isset($period['start_date'])) {
                $period['start_date'] = convertToServerTimestamp($period['start_date']);
            }
            
            if (($period['timeperiod_type'] == 2 || $period['timeperiod_type'] == 3) && !isset($period['every'])) {
                $period['every'] = 1;
            }
            if ($period['timeperiod_type'] == 3 && isset($period['dayofweek'])) {
                $period['dayofweek'] = (int)$period['dayofweek'];
            }
        }
        
        $params['timeperiods'] = $timeperiods;

        file_put_contents($debugFile, date('Y-m-d H:i:s') . " - Llamando a createMaintenance con hostids: " . json_encode($hostids_final) . "\n", FILE_APPEND);
        
        $res = $api->createMaintenance($params, $hostids_final);
        
        file_put_contents($debugFile, date('Y-m-d H:i:s') . " - Respuesta: " . json_encode($res) . "\n", FILE_APPEND);
        
        $maintenanceids = $res['maintenanceids'] ?? [];
        $result_message = t('maint_success_created') . " (ID: " . implode(', ', $maintenanceids) . ")";

    } elseif ($action_type === 'update') {
        $maintenanceid = $_POST['maintenanceid'] ?? null;
        if (empty($maintenanceid)) {
            throw new RuntimeException(t('maint_err_no_id'));
        }

        $existing = $api->call('maintenance.get', [
            'maintenanceids' => [$maintenanceid],
            'selectHosts' => ['hostid']
        ]);
        
        $existing_hostids = [];
        if (!empty($existing[0]['hosts'])) {
            $existing_hostids = array_column($existing[0]['hosts'], 'hostid');
        }

        $all_hostids = array_values(array_unique(array_merge($existing_hostids, $hostids_final)));
        
        file_put_contents($debugFile, date('Y-m-d H:i:s') . " - Llamando a updateMaintenance con hostids: " . json_encode($all_hostids) . "\n", FILE_APPEND);
        
        $res = $api->updateMaintenance($maintenanceid, $all_hostids);
        
        file_put_contents($debugFile, date('Y-m-d H:i:s') . " - Respuesta: " . json_encode($res) . "\n", FILE_APPEND);
        
        $maintenanceids = $res['maintenanceids'] ?? [];
        $result_message = t('maint_success_updated') . " (ID: " . implode(', ', $maintenanceids) . ")";

    } else {
        throw new RuntimeException("Acción no válida: $action_type.");
    }

    file_put_contents($debugFile, date('Y-m-d H:i:s') . " - Éxito! Mostrando página de resultado\n", FILE_APPEND);

    // Página de éxito
    echo '<!DOCTYPE html><html><head><title>' . t('maint_success_title') . '</title>';
    echo '<style>body{font-family: system-ui; padding: 20px; background: #eef2f6;} ';
    echo 'pre{background: #f4f4f4; border: 1px solid #ccc; padding: 10px; border-radius: 5px;} ';
    echo '.btn{display: inline-block; padding: 10px 16px; background: #d9534f; color: #fff; text-decoration: none; border-radius: 8px;}</style></head><body>';
    echo '<div style="max-width: 800px; margin: 0 auto; background: #fff; padding: 20px; border-radius: 8px;">';
    echo '<h1 style="color: #28a745;">✅ ' . htmlspecialchars($result_message) . '</h1>';
    echo '<h3>' . t('maint_log_title') . '</h3>';
    echo '<pre>' . htmlspecialchars(implode("\n", $log)) . '</pre>';
    if (!empty($unresolved)) {
        echo '<h3 style="color: #d9534f;">' . t('maint_unresolved_title') . '</h3>';
        echo '<pre style="color: red;">' . htmlspecialchars(implode("\n", $unresolved)) . '</pre>';
    }
    echo '<br><a href="index.php" class="btn">' . t('maint_back_button') . '</a>';
    echo '</div></body></html>';

} catch (Throwable $e) {
    file_put_contents($debugFile, date('Y-m-d H:i:s') . " - EXCEPCIÓN: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n", FILE_APPEND);
    
    http_response_code(500);
    echo '<!DOCTYPE html><html><head><title>Error</title>';
    echo '<style>body{font-family: system-ui; padding: 20px; background: #eef2f6;}</style></head><body>';
    echo '<div style="max-width: 800px; margin: 0 auto; background: #fff; padding: 20px; border-radius: 8px;">';
    echo '<h3 style="color: #d9534f;">❌ Error Crítico</h3>';
    echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
    echo '<br><a href="index.php" style="display: inline-block; padding: 10px 16px; background: #d9534f; color: #fff; text-decoration: none; border-radius: 8px;">' . t('maint_back_button') . '</a>';
    echo '</div></body></html>';
    exit;
}
