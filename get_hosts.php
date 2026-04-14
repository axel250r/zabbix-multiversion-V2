<?php
declare(strict_types=1);

// === INICIO DE SESIÓN SEGURO ===
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verificar autenticación
if (empty($_SESSION['zbx_auth_ok'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Sesión inválida - Por favor inicie sesión nuevamente']);
    exit;
}

// Verificar que las credenciales existen
if (empty($_SESSION['zbx_user']) || empty($_SESSION['zbx_pass'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Credenciales no disponibles en sesión']);
    exit;
}

require_once __DIR__ . '/lib/i18n.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/ZabbixApiFactory.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $api = ZabbixApiFactory::create(
        ZABBIX_API_URL, 
        $_SESSION['zbx_user'], 
        $_SESSION['zbx_pass'],
        [
            'timeout' => 10,
            'verify_ssl' => defined('VERIFY_SSL') ? VERIFY_SSL : false
        ]
    );
    
    $hosts = $api->call('host.get', [
        'output' => ['hostid', 'name']
    ]);
    
    if (!is_array($hosts)) {
        echo json_encode(['error' => 'La API no devolvió un array', 'data' => $hosts]);
        exit;
    }
    
    echo json_encode($hosts);
    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Error en get_hosts.php',
        'message' => $e->getMessage()
    ]);
}