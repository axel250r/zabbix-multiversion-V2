<?php
declare(strict_types=1);

// Debug
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

set_time_limit(300);
ini_set('memory_limit', '256M');

session_start();

if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) { 
    http_response_code(403); 
    die('Error: Invalid CSRF token.'); 
}

if (empty($_SESSION['zbx_auth_ok'])) { 
    http_response_code(403); 
    die('Invalid session'); 
}

require_once __DIR__ . '/../lib/i18n.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/ZabbixApiFactory.php';

try {
    $maintenanceid = $_POST['maintenanceid'] ?? null;
    $maintenancename = $_POST['maintenancename'] ?? 'maintenance';
    if (empty($maintenanceid)) {
        throw new RuntimeException(t('maint_err_no_id'));
    }

    $api = ZabbixApiFactory::create(
        ZABBIX_API_URL, 
        $_SESSION['zbx_user'], 
        $_SESSION['zbx_pass'],
        ['timeout' => 290, 'verify_ssl' => VERIFY_SSL]
    );

    $maint_data = $api->call('maintenance.get', [
        'output' => ['name'],
        'selectHosts' => ['hostid', 'host', 'name'],
        'maintenanceids' => [$maintenanceid],
        'limit' => 1
    ]);

    if (empty($maint_data) || empty($maint_data[0]['hosts'])) {
        throw new RuntimeException(t('maint_export_empty'));
    }

    $hosts = $maint_data[0]['hosts'];
    $headers = [t('export_col_host_name'), t('export_col_visible_name'), 'HostID'];
    $data = [];
    foreach ($hosts as $h) {
        $data[] = [
            $h['host'],
            $h['name'],
            $h['hostid']
        ];
    }
    
    $filename = 'hosts_maint_' . preg_replace('/[^a-z0-9]/i', '_', $maintenancename) . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, $headers, ';');
    foreach ($data as $row) {
        fputcsv($output, $row, ';');
    }
    fclose($output);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo '<h3>' . t('excel_err_critical') . '</h3><pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
}