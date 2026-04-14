<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ZabbixApi.php';

$zbx_api = null;
function getZabbixApiInstance() {
    global $zbx_api;
    if ($zbx_api === null) {
        $zbx_api = new ZabbixApi(ZABBIX_API_URL, ZABBIX_API_USER, ZABBIX_API_PASS);
    }
    return $zbx_api;
}

function getHostsByGroupName(string $groupName): array {
    $api = getZabbixApiInstance();
    $r = $api->call('host.get', [
        'output' => ['host'],
        'group' => ['name' => [$groupName]],
    ]);
    $hostnames = [];
    if (is_array($r)) {
        foreach ($r as $host) {
            $hostnames[] = $host['host'];
        }
    }
    return $hostnames;
}

function getHostIdByName(string $host): ?string {
    $api = getZabbixApiInstance();
    $r = $api->hostGetByNames([$host]);
    return $r[$host] ?? null;
}

function getHostNameById(string $hostid): ?string {
    $api = getZabbixApiInstance();
    $r = $api->call('host.get', ['output' => ['name'], 'hostids' => [$hostid]]);
    return $r[0]['name'] ?? null;
}

function getGraphItemIds(string $graphid): array {
    $api = getZabbixApiInstance();
    $res = $api->call('graphitem.get', [
        'output' => ['itemid','sortorder','gitemid'],
        'graphids' => [$graphid],
    ]);
    if ($res === null) { return []; }

    if (is_array($res) && isset($res[0]['sortorder'])) {
        usort($res, function($a, $b){
            $sa = (int)($a['sortorder'] ?? 0);
            $sb = (int)($b['sortorder'] ?? 0);
            return $sa <=> $sb;
        });
    }

    $ids=[]; foreach ($res as $gi) if (!empty($gi['itemid'])) $ids[]=(string)$gi['itemid'];
    return $ids;
}

function findItemByKeys(string $hostid, array $keys): ?array {
    $api = getZabbixApiInstance();
    $items = $api->call('item.get', [
        'output'  => ['itemid','key_','name'],
        'hostids' => [$hostid],
        'search'  => ['key_' => $keys],
    ]);
    if (!is_array($items)) { return null; }

    foreach ($keys as $k) {
        foreach ($items as $row) {
            $key = (string)($row['key_'] ?? '');
            if ($key === $k || str_starts_with($key, $k.'[')) return $row;
        }
    }
    return null;
}

function findItemByNameLike(string $hostid, array $namePieces): ?array {
    $api = getZabbixApiInstance();
    $items = $api->call('item.get', [
        'output'  => ['itemid','key_','name'],
        'hostids' => [$hostid],
        'search'  => ['name' => $namePieces],
    ]);
    if (!is_array($items)) { return null; }
    
    foreach ($items as $row) {
        $name = mb_strtolower((string)$row['name'],'UTF-8');
        $ok = true;
        foreach ($namePieces as $p) {
            if (mb_strpos($name, mb_strtolower($p,'UTF-8')) === false) { $ok=false; break; }
        }
        if ($ok) return $row;
    }
    return $items[0] ?? null;
}