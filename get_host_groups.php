<?php
declare(strict_types=1);

session_start();
if (empty($_SESSION['zbx_auth_ok'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Sesión inválida']);
    exit;
}

require_once __DIR__ . '/lib/i18n.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/ZabbixApiFactory.php';  // <-- CAMBIADO

header('Content-Type: application/json; charset=utf-8');

try {
    // AHORA USA LA FÁBRICA
    $api = ZabbixApiFactory::create(
        ZABBIX_API_URL, 
        $_SESSION['zbx_user'], 
        $_SESSION['zbx_pass'],
        [
            'timeout' => 10,
            'verify_ssl' => defined('VERIFY_SSL') ? VERIFY_SSL : false
        ]
    );
    
    $hostGroups = $api->call('hostgroup.get', [
        'output' => ['groupid', 'name'],
        'sortfield' => 'name'
    ]);
    
    if (!is_array($hostGroups)) {
        echo json_encode(['error' => t('get_groups_error')]);
        exit;
    }
    
    echo json_encode($hostGroups);
} catch (Throwable $e) {
    error_log("Zabbix PDF Report - API Error en get_host_groups.php: " . $e->getMessage());
    echo json_encode(['error' => t('error_server_error')]);
}