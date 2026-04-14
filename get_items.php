<?php
declare(strict_types=1);

session_start();
if (empty($_SESSION['zbx_auth_ok'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Session invalid']);
    exit;
}

require_once __DIR__ . '/lib/i18n.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/ZabbixApiFactory.php';

header('Content-Type: application/json; charset=utf-8');

$input       = json_decode(file_get_contents('php://input'), true) ?? [];
$action      = $input['action']      ?? 'get_items';
$templateids = isset($input['templateids']) ? array_values(array_filter((array)$input['templateids'])) : [];

// Limpia nombres de prototipos LLD quitando segmentos con macros {#...}
function cleanLldName(string $name): string {
    // Quitar bloques [... {#MACRO} ...] del nombre
    $cleaned = preg_replace('/\s*\[(?:[^\[\]]*\{#[^}]+\}[^\[\]]*)+\]/', '', $name);
    $cleaned = preg_replace('/\s{2,}/', ' ', $cleaned);
    $cleaned = trim($cleaned, " \t\n\r\0\x0B:");
    if (strlen($cleaned) > 3) return $cleaned;
    // Fallback: tomar la parte despues del ultimo ':'
    $parts = explode(':', $name);
    return count($parts) > 1 ? trim(end($parts)) : $name;
}

try {
    $api = ZabbixApiFactory::create(
        ZABBIX_API_URL,
        $_SESSION['zbx_user'],
        $_SESSION['zbx_pass'],
        ['timeout' => 20, 'verify_ssl' => defined('VERIFY_SSL') ? VERIFY_SSL : false]
    );

    // ── list_templates ────────────────────────────────────────────────────────
    if ($action === 'list_templates') {
        $templates = $api->call('template.get', [
            'output'    => ['templateid', 'name'],
            'sortfield' => 'name',
            'sortorder' => 'ASC',
        ]);
        echo json_encode(is_array($templates) ? $templates : []);
        exit;
    }

    // ── get_host_items: items reales de un host (regulares + LLD discovered) ──
    // Devuelve nombres concretos: 'FS [/boot]: Space: Available', sin macros.
    if ($action === 'get_host_items') {
        $hostid = trim((string)($input['hostid'] ?? ''));
        if (!ctype_digit($hostid)) { echo json_encode([]); exit; }

        $all = []; $seen = [];

        // Items regulares del host (flags=0)
        $reg = $api->call('item.get', [
            'output'    => ['itemid', 'name', 'key_'],
            'hostids'   => $hostid,
            'filter'    => ['flags' => 0],
            'sortfield' => 'name',
        ]);
        if (is_array($reg)) {
            foreach ($reg as $i) {
                if (isset($seen[$i['itemid']])) continue;
                $seen[$i['itemid']] = true;
                $all[] = [
                    'itemid'       => $i['itemid'],
                    'name'         => $i['name'],
                    'key_'         => $i['key_'],
                    'is_discovered'=> false,
                ];
            }
        }

        // Items discovered LLD (flags=4) — nombres reales con valores concretos
        $disc = $api->call('item.get', [
            'output'    => ['itemid', 'name', 'key_'],
            'hostids'   => $hostid,
            'filter'    => ['flags' => 4],
            'sortfield' => 'name',
        ]);
        if (is_array($disc)) {
            foreach ($disc as $i) {
                if (isset($seen[$i['itemid']])) continue;
                $seen[$i['itemid']] = true;
                $all[] = [
                    'itemid'       => $i['itemid'],
                    'name'         => $i['name'],
                    'key_'         => $i['key_'],
                    'is_discovered'=> true,
                ];
            }
        }

        usort($all, fn($a, $b) => strcmp($a['name'], $b['name']));
        echo json_encode($all);
        exit;
    }

    // ── get_items: items regulares + prototipos LLD del template ─────────────
    if ($action === 'get_items') {
        if (empty($templateids)) { echo json_encode([]); exit; }

        $result = []; $seen = [];

        // Items regulares del template
        $items = $api->call('item.get', [
            'output'      => ['itemid', 'name', 'key_'],
            'templateids' => $templateids,
            'sortfield'   => 'name',
        ]);
        if (is_array($items)) {
            foreach ($items as $item) {
                if (isset($seen[$item['itemid']])) continue;
                $seen[$item['itemid']] = true;
                $result[] = [
                    'itemid'       => $item['itemid'],
                    'name'         => $item['name'],
                    'key_'         => $item['key_'],
                    'is_lld'       => false,
                    'display_name' => $item['name'],
                ];
            }
        }

        // Prototipos LLD del template
        $protos = $api->call('itemprototype.get', [
            'output'      => ['itemid', 'name', 'key_'],
            'templateids' => $templateids,
            'sortfield'   => 'name',
        ]);
        if (is_array($protos)) {
            foreach ($protos as $proto) {
                if (isset($seen[$proto['itemid']])) continue;
                $seen[$proto['itemid']] = true;
                $result[] = [
                    'itemid'       => $proto['itemid'],
                    'name'         => $proto['name'],
                    'key_'         => $proto['key_'],
                    'is_lld'       => true,
                    'display_name' => cleanLldName($proto['name']),
                ];
            }
        }

        usort($result, fn($a, $b) => strcmp($a['display_name'], $b['display_name']));
        echo json_encode($result);
        exit;
    }

    // Accion desconocida
    echo json_encode([]);

} catch (Throwable $e) {
    error_log("get_items.php error: " . $e->getMessage());
    echo json_encode(['error' => t('error_server_error')]);
}
