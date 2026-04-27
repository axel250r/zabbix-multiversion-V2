<?php
/**
 * get_items_preview.php
 * Endpoint AJAX para el Data Preview Export.
 * Acciones:
 *   - get_host_items : devuelve la unión de items de los hosts seleccionados
 *   - preview        : devuelve lastvalue de los items seleccionados por host
 */
declare(strict_types=1);

session_start();

if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    http_response_code(403); exit('Forbidden');
}
if (empty($_SESSION['zbx_auth_ok'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Sesión inválida']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']); exit;
}

set_time_limit(30);
ini_set('memory_limit', '256M');

require_once __DIR__ . '/../../lib/i18n.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/ZabbixApiFactory.php';

header('Content-Type: application/json; charset=utf-8');

// ── Helpers ───────────────────────────────────────────────────
function resolveHostIds(object $api): array {
    $hostIdsRaw  = array_filter(explode(',', $_POST['hostids']   ?? ''));
    $groupIdsRaw = array_filter(explode(',', $_POST['groupids']  ?? ''));
    $hostNames   = array_filter(array_map('trim', preg_split('/[,\r\n]+/', $_POST['hostnames']  ?? '')));
    $groupNames  = array_filter(array_map('trim', preg_split('/[,\r\n]+/', $_POST['hostgroups'] ?? '')));

    $fromNames = [];
    if (!empty($hostNames)) {
        $map = $api->getHostsByNames($hostNames);
        $fromNames = array_values($map);
    }

    $groupIdsFromNames = [];
    if (!empty($groupNames)) {
        $grps = $api->call('hostgroup.get', ['output'=>['groupid'],'filter'=>['name'=>$groupNames]]);
        if (is_array($grps)) $groupIdsFromNames = array_column($grps,'groupid');
    }

    $allGroupIds = array_unique(array_merge($groupIdsRaw, $groupIdsFromNames));
    $fromGroups  = [];
    if (!empty($allGroupIds)) {
        $fromGroups = $api->getHostIdsByGroupIds($allGroupIds);
    }

    return array_values(array_unique(array_merge($hostIdsRaw, $fromNames, $fromGroups)));
}

// Limpia nombres de prototipos LLD quitando segmentos con macros {#...}
function cleanLldName(string $name): string {
    $cleaned = preg_replace('/\s*\[(?:[^\[\]]*\{#[^}]+\}[^\[\]]*)+\]/', '', $name);
    $cleaned = preg_replace('/\s{2,}/', ' ', $cleaned ?? '');
    $cleaned = trim($cleaned ?? '', " \t\n\r\0\x0B:");
    if (strlen($cleaned) > 3) return $cleaned;
    $parts = explode(':', $name);
    return count($parts) > 1 ? trim(end($parts)) : $name;
}

try {
    $api = ZabbixApiFactory::create(
        ZABBIX_API_URL,
        $_SESSION['zbx_user'],
        $_SESSION['zbx_pass'],
        ['timeout' => 45, 'verify_ssl' => defined('VERIFY_SSL') ? VERIFY_SSL : false]
    );

    $action = $_POST['action'] ?? 'get_host_items';

    // ══════════════════════════════════════════════════════════
    // ACTION: get_host_items
    // Devuelve la UNIÓN de items de todos los hosts seleccionados.
    // Resultado: [{itemid, name, key_, display_name, is_lld}]
    // ══════════════════════════════════════════════════════════
    if ($action === 'get_host_items') {
        $hostIds = resolveHostIds($api);
        if (empty($hostIds)) {
            echo json_encode(['error' => 'No se encontraron hosts con los criterios indicados.']);
            exit;
        }

        // Obtener items en lotes de 20 hosts — evita timeout en instalaciones grandes
        $batch_size  = 20;
        $host_chunks = array_chunk($hostIds, $batch_size);
        $reg_all     = [];
        $disc_all    = [];

        foreach ($host_chunks as $chunk) {
            // Items regulares (flags=0)
            $reg_batch = $api->call('item.get', [
                'output'    => ['itemid', 'name', 'key_', 'flags'],
                'hostids'   => $chunk,
                'filter'    => ['flags' => 0, 'status' => 0],
                'sortfield' => 'name',
                'sortorder' => 'ASC',
            ]);
            if (is_array($reg_batch)) $reg_all = array_merge($reg_all, $reg_batch);

            // Items discovered LLD (flags=4)
            $disc_batch = $api->call('item.get', [
                'output'    => ['itemid', 'name', 'key_', 'flags'],
                'hostids'   => $chunk,
                'filter'    => ['flags' => 4, 'status' => 0],
                'sortfield' => 'name',
                'sortorder' => 'ASC',
            ]);
            if (is_array($disc_batch)) $disc_all = array_merge($disc_all, $disc_batch);
        }

        // Deduplicar por key_ — cada key única aparece una vez en la lista.
        // La fusión por display_name ocurre solo al generar el CSV.
        $seen_keys = [];
        $result    = [];

        foreach (array_merge($reg_all, $disc_all) as $item) {
            $is_lld = isset($item['flags']) ? $item['flags'] == 4 : false;
            if (isset($seen_keys[$item['key_']])) continue;
            $seen_keys[$item['key_']] = true;
            $display_name = $is_lld ? cleanLldName($item['name']) : $item['name'];
            $result[] = [
                'itemid'       => $item['itemid'],
                'name'         => $item['name'],
                'key_'         => $item['key_'],
                'display_name' => $display_name,
                'is_lld'       => $is_lld,
            ];
        }

        // Ordenar por display_name
        usort($result, fn($a,$b) => strcmp($a['display_name'], $b['display_name']));

        echo json_encode($result);
        exit;
    }

    // ══════════════════════════════════════════════════════════
    // ACTION: preview
    // Devuelve lastvalue de los items seleccionados por host.
    // Resultado: {headers, rows, is_lld, total_hosts}
    // ══════════════════════════════════════════════════════════
    if ($action === 'preview') {
        $hostIds = resolveHostIds($api);
        if (empty($hostIds)) {
            echo json_encode(['error' => 'No se encontraron hosts.']); exit;
        }

        // Parsear item_data[] — pares "itemid|label"
        $itemData = array_filter(array_map('trim', (array)($_POST['item_data'] ?? [])));
        if (empty($itemData)) { echo json_encode(['error' => 'No se seleccionaron items.']); exit; }
        $selectedItemids = []; $itemNames = [];
        foreach ($itemData as $pair) {
            $pos = strpos($pair, '|'); if ($pos === false) continue;
            $selectedItemids[] = trim(substr($pair, 0, $pos));
            $itemNames[]       = trim(substr($pair, $pos + 1));
        }

        $previewLimit   = min((int)($_POST['preview_limit'] ?? 10), 20);
        $previewHostIds = array_slice($hostIds, 0, $previewLimit);

        $hostsInfo = $api->call('host.get', ['hostids'=>$previewHostIds,'output'=>['hostid','name'],'sortfield'=>'name']);
        $hostMap = [];
        foreach ((array)$hostsInfo as $h) $hostMap[$h['hostid']] = $h['name'];

        // Keys de referencia por itemid
        $refItems = $api->call('item.get', ['output'=>['itemid','key_'],'itemids'=>array_values($selectedItemids)]);
        $refMap = [];
        foreach ((array)$refItems as $i) $refMap[$i['itemid']] = $i['key_'];

        // Agrupar por display_name → una columna por nombre, varias keys posibles
        $colGroups = [];
        foreach ($selectedItemids as $idx => $iid) {
            $label = $itemNames[$idx] ?? $iid;
            $key   = $refMap[$iid] ?? null;
            if ($key === null) continue;
            if (!isset($colGroups[$label])) $colGroups[$label] = [];
            if (!in_array($key, $colGroups[$label], true)) $colGroups[$label][] = $key;
        }

        // Buscar valores
        $allKeys = array_unique(array_merge(...array_values($colGroups)));
        $items = $api->call('item.get', ['output'=>['key_','lastvalue','hostid'],'hostids'=>$previewHostIds,'filter'=>['key_'=>$allKeys,'status'=>0]]);
        $valMap = [];
        foreach ((array)$items as $item) $valMap[$item['hostid']][$item['key_']] = $item['lastvalue'];

        // Headers — una por grupo
        $headers = ['Host']; $isLldMap = [];
        foreach (array_keys($colGroups) as $i => $label) { $headers[] = $label; $isLldMap[$i+1] = false; }

        // Filas — primer valor no-N/A del grupo
        $rows = [];
        foreach ($previewHostIds as $hid) {
            if (!isset($hostMap[$hid])) continue;
            $row = [$hostMap[$hid]];
            foreach ($colGroups as $keys) {
                $val = 'N/A';
                foreach ($keys as $key) {
                    $v = $valMap[$hid][$key] ?? null;
                    if ($v !== null && $v !== '') { $val = strlen($v)>60 ? substr($v,0,60).'…' : $v; break; }
                }
                $row[] = $val;
            }
            $rows[] = $row;
        }

        echo json_encode([
            'headers'     => $headers,
            'rows'        => $rows,
            'is_lld'      => $isLldMap,
            'total_hosts' => count($hostIds),
        ]);
        exit;
    }

    echo json_encode(['error' => 'Acción no reconocida: '.htmlspecialchars($action)]);

} catch (Throwable $e) {
    error_log('get_items_preview.php error: '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Error Zabbix API: '.htmlspecialchars($e->getMessage())]);
}
