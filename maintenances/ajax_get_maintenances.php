<?php
declare(strict_types=1);

// Debug (opcional - puedes comentar estas líneas en producción)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

session_start();

if (empty($_SESSION['zbx_auth_ok'])) { 
    http_response_code(403); 
    echo json_encode(['error' => 'Invalid session']); 
    exit; 
}

require_once __DIR__ . '/../lib/i18n.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/ZabbixApiFactory.php';

header('Content-Type: application/json');

try {
    $api = ZabbixApiFactory::create(
        ZABBIX_API_URL, 
        $_SESSION['zbx_user'], 
        $_SESSION['zbx_pass'],
        ['timeout' => 300, 'verify_ssl' => VERIFY_SSL]
    );

    $now = time();
    $maintenances = $api->call('maintenance.get', [
        'output' => ['maintenanceid', 'name', 'active_since', 'active_till'],
        'selectHosts' => ['hostid'],
        'sortfield' => 'active_since',
        'sortorder' => 'DESC'
    ]);

    $result = [];
    foreach ($maintenances as $m) {
        $start = (int)$m['active_since'];
        $end = (int)$m['active_till'];
        
        $status_key = 'maint_status_future';
        $status_class = 'status-future';
        
        if ($now >= $start && $now < $end) {
            $status_key = 'maint_status_active';
            $status_class = 'status-active';
        } elseif ($now >= $end) {
            $status_key = 'maint_status_expired';
            $status_class = 'status-expired';
        }
        $status_text = t($status_key);
        
        $result[] = [
            'maintenanceid' => $m['maintenanceid'],
            'name' => $m['name'],
            'status_text' => $status_text,
            'status_class' => $status_class,
            'start_time' => date('Y-m-d H:i:s', $start),
            'end_time' => date('Y-m-d H:i:s', $end),
            'hosts_count' => count($m['hosts'] ?? [])
        ];
    }

    echo json_encode($result);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}