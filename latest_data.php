<?php
declare(strict_types=1);

session_start();
if (empty($_SESSION['zbx_auth_ok'])) {
    if (isset($_GET['action'])) { header('Content-Type: application/json'); echo json_encode(['error'=>'session']); exit; }
    header('Location: login.php'); exit;
}

require_once __DIR__ . '/lib/i18n.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/ZabbixApiFactory.php';

try {
    $api = ZabbixApiFactory::create(ZABBIX_API_URL, $_SESSION['zbx_user'], $_SESSION['zbx_pass'], ['timeout'=>20]);
} catch (Throwable $e) {
    if (isset($_GET['action'])) { header('Content-Type: application/json'); echo json_encode(['error'=>$e->getMessage()]); exit; }
    header('Location: login.php'); exit;
}

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];

// Endpoint para regenerar CSRF token sin recargar la pagina
if (isset($_GET['action']) && $_GET['action'] === 'new_csrf') {
    header('Content-Type: application/json');
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    echo json_encode(['token' => $_SESSION['csrf_token']]);
    exit;
}

// Endpoint para obtener historial de un item (para preview de grafico)
if (isset($_GET['action']) && $_GET['action'] === 'item_history') {
    header('Content-Type: application/json');
    $itemid    = ctype_digit($_GET['itemid'] ?? '') ? $_GET['itemid'] : null;
    $value_type= in_array($_GET['vtype'] ?? '', ['0','1','2','3','4']) ? (int)$_GET['vtype'] : 0;
    $from      = ctype_digit($_GET['from'] ?? '') ? (int)$_GET['from'] : strtotime('-24 hours');
    $till      = ctype_digit($_GET['till'] ?? '') ? (int)$_GET['till'] : time();
    if (!$itemid) { echo json_encode(['error'=>'no itemid']); exit; }
    // Decidir tabla de historial segun value_type
    // 0=float, 3=uint -> trends si rango > 7 dias, history si menor
    $range_days = ($till - $from) / 86400;
    $use_trends = $range_days > 7 && in_array($value_type, [0, 3]);
    if ($use_trends) {
        $data = $api->call('trend.get', [
            'output'    => ['clock','value_avg','value_min','value_max'],
            'itemids'   => [$itemid],
            'time_from' => $from,
            'time_till' => $till,
            'limit'     => 500,
        ]);
        $points = [];
        if (is_array($data)) foreach ($data as $d) {
            $points[] = ['t' => (int)$d['clock'], 'v' => (float)$d['value_avg']];
        }
    } else {
        try {
            $data = $api->call('history.get', [
                'output'    => ['clock','value'],
                'itemids'   => [$itemid],
                'history'   => $value_type,
                'time_from' => $from,
                'time_till' => $till,
                'sortfield' => 'clock',
                'sortorder' => 'ASC',
                'limit'     => 500,
            ]);
        } catch (Throwable $e) {
            echo json_encode(['error' => $e->getMessage(), 'points' => []]);
            exit;
        }
        $points = [];
        if (is_array($data)) foreach ($data as $d) {
            $v = is_numeric($d['value']) ? (float)$d['value'] : $d['value'];
            $points[] = ['t' => (int)$d['clock'], 'v' => $v];
        }
    }
    $result = [
        'points'     => $points,
        'trends'     => $use_trends,
        'count'      => count($points),
        'vtype'      => $value_type,
        'from'       => $from,
        'till'       => $till,
        'range_days' => $range_days,
        'api_error'  => is_array($data) ? null : $data,
    ];
    echo json_encode($result);
    exit;
}

if (!isset($_GET['action'])) {
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; connect-src 'self' https://cdn.jsdelivr.net;");
}

// ── AJAX: refresh de datos ────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'refresh') {
    header('Content-Type: application/json; charset=utf-8');

    $hostids   = array_filter(explode(',', $_GET['hostids']   ?? ''), 'ctype_digit');
    $groupids  = array_filter(explode(',', $_GET['groupids']  ?? ''), 'ctype_digit');
    $name      = trim($_GET['name'] ?? '');
    $sort      = in_array($_GET['sort'] ?? '', ['host','name']) ? $_GET['sort'] : 'name';
    $sortorder = ($_GET['sortorder'] ?? '') === 'DESC' ? 'DESC' : 'ASC';
    $page      = 1;
    $per_page  = 2000; // Traer todos - paginacion en el cliente

    // Resolver hosts
    $host_params = ['output' => ['hostid','name','status'], 'preservekeys' => true];
    if ($hostids)  $host_params['hostids']  = $hostids;
    if ($groupids) $host_params['groupids'] = $groupids;
    if (!$hostids && !$groupids && $name === '') {
        echo json_encode(['items'=>[],'total'=>0,'hosts'=>[]]);
        exit;
    }
    $hosts = $api->call('host.get', $host_params);
    if (!is_array($hosts) || empty($hosts)) {
        echo json_encode(['items'=>[],'total'=>0,'hosts'=>[]]);
        exit;
    }

    // Obtener items
    $item_params = [
        'output'       => ['itemid','hostid','name','key_','lastvalue','lastclock','value_type','units','delay','state'],
        'hostids'      => array_keys($hosts),
        'filter'       => ['status' => 0], // ITEM_STATUS_ACTIVE
        'sortfield'    => $sort === 'host' ? 'itemid' : 'name',
        'sortorder'    => $sortorder,
        'preservekeys' => true,
        'webitems'     => true,
    ];
    if ($name !== '') {
        $item_params['search']                = ['name' => $name];
        $item_params['searchWildcardsEnabled']= true;
    }

    $items = $api->call('item.get', $item_params);
    if (!is_array($items)) $items = [];

    // Obtener ultimo valor de history para items sin lastvalue
    $need_history = [];
    foreach ($items as $iid => $item) {
        if ($item['lastclock'] == 0) $need_history[] = $iid;
    }
    if ($need_history) {
        $hist = $api->call('history.get', [
            'output'   => ['itemid','value','clock'],
            'itemids'  => $need_history,
            'sortfield'=> 'clock',
            'sortorder'=> 'DESC',
            'limit'    => count($need_history),
        ]);
        if (is_array($hist)) {
            $latest = [];
            foreach ($hist as $h) {
                if (!isset($latest[$h['itemid']])) $latest[$h['itemid']] = $h;
            }
            foreach ($latest as $iid => $h) {
                if (isset($items[$iid])) {
                    $items[$iid]['lastvalue'] = $h['value'];
                    $items[$iid]['lastclock'] = $h['clock'];
                }
            }
        }
    }

    // Ordenar por host name si se pide
    if ($sort === 'host') {
        uasort($items, function($a, $b) use ($hosts, $sortorder) {
            $ha = $hosts[$a['hostid']]['name'] ?? '';
            $hb = $hosts[$b['hostid']]['name'] ?? '';
            $c  = strcasecmp($ha, $hb);
            return $sortorder === 'DESC' ? -$c : $c;
        });
    }

    $total = count($items);
    $paged = $items; // Sin paginacion server-side, el cliente pagina

    // Formatear valores
    $result = [];
    foreach ($paged as $item) {
        $host      = $hosts[$item['hostid']] ?? null;
        $val       = $item['lastvalue'];
        $clock     = (int)$item['lastclock'];
        $ago       = $clock > 0 ? formatAge(time() - $clock) : '—';
        $fval      = formatValue($val, (int)$item['value_type'], $item['units']);
        $result[]  = [
            'itemid'    => $item['itemid'],
            'hostid'    => $item['hostid'],
            'host'      => $host ? $host['name'] : '',
            'name'      => $item['name'],
            'key_'      => $item['key_'],
            'lastvalue' => $fval,
            'rawvalue'  => $val,
            'lastclock' => $clock,
            'ago'       => $ago,
            'units'     => $item['units'],
            'state'     => (int)$item['state'],
        ];
    }

    echo json_encode(['items'=>$result,'total'=>$total,'hosts'=>array_values($hosts),'page'=>$page,'per_page'=>$per_page]);
    exit;
}

// ── AJAX: lista de hosts/grupos para filtros ──────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'list_hosts') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim($_GET['q'] ?? '');
    $params = ['output'=>['hostid','name'],'sortfield'=>'name','limit'=>50];
    if ($q) {
        $params['search'] = ['name' => '*'.$q.'*'];
        $params['searchWildcardsEnabled'] = true;
    }
    $r = $api->call('host.get', $params);
    echo json_encode(is_array($r) ? $r : []);
    exit;
}
if (isset($_GET['action']) && $_GET['action'] === 'list_groups') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim($_GET['q'] ?? '');
    $params = ['output'=>['groupid','name'],'sortfield'=>'name','limit'=>50];
    if ($q) {
        $params['search'] = ['name' => '*'.$q.'*'];
        $params['searchWildcardsEnabled'] = true;
    }
    $r = $api->call('hostgroup.get', $params);
    echo json_encode(is_array($r) ? $r : []);
    exit;
}
if (isset($_GET['action']) && $_GET['action'] === 'list_items') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim($_GET['q'] ?? '');
    if ($q === '') { echo json_encode([]); exit; }
    // Filtrar por hosts/grupos seleccionados si vienen
    $hostids  = array_filter(explode(',', $_GET['hostids']  ?? ''), 'ctype_digit');
    $groupids = array_filter(explode(',', $_GET['groupids'] ?? ''), 'ctype_digit');
    $params = [
        'output'                 => ['itemid','name','key_','hostid'],
        'selectHosts'            => ['hostid','name'],
        'search'                 => ['name' => '*'.$q.'*'],
        'searchWildcardsEnabled' => true,
        'filter'                 => ['status' => 0],
        'sortfield'              => 'name',
        'limit'                  => 20,
    ];
    if ($hostids)  $params['hostids']  = $hostids;
    if ($groupids) $params['groupids'] = $groupids;
    $r = $api->call('item.get', $params);
    if (!is_array($r)) { echo json_encode([]); exit; }
    // Deduplicar por nombre (mismo item en varios hosts)
    $seen = []; $result = [];
    foreach ($r as $item) {
        if (!isset($seen[$item['name']])) {
            $seen[$item['name']] = true;
            $result[] = ['name' => $item['name'], 'key_' => $item['key_']];
        }
    }
    echo json_encode($result);
    exit;
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function formatAge(int $secs): string {
    if ($secs < 0) return 'just now';
    if ($secs < 60) return $secs . 's ago';
    if ($secs < 3600) return floor($secs/60) . 'm ago';
    if ($secs < 86400) return floor($secs/3600) . 'h ago';
    return floor($secs/86400) . 'd ago';
}

function formatValue(?string $val, int $type, string $units): string {
    if ($val === null || $val === '') return '—';
    // Numeric types: 0=float, 3=uint64
    if (in_array($type, [0, 3])) {
        $n = (float)$val;
        $u = trim($units);
        // Convert bytes
        if (in_array($u, ['B','b'])) {
            if ($n >= 1073741824) return round($n/1073741824, 2) . ' GB';
            if ($n >= 1048576)    return round($n/1048576, 2) . ' MB';
            if ($n >= 1024)       return round($n/1024, 2) . ' KB';
            return $n . ' B';
        }
        $formatted = $n == floor($n) ? (int)$n : round($n, 4);
        return $formatted . ($u ? ' '.$u : '');
    }
    // String/text/log: truncate
    return mb_strlen($val) > 80 ? mb_substr($val, 0, 80) . '…' : $val;
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($current_lang, ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Zabbix Report — Export</title>
<link rel="stylesheet" href="assets/css/export.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
<style>
/* ── Export page styles ── */

/* BACKGROUND IMAGE - editar ruta si es necesario */
body {
  background-image: url('assets/background/bg.jpg');
  background-size: cover;
  background-position: center;
  background-attachment: fixed;
}
body::before {
  content: '';
  position: fixed; inset: 0; z-index: 0;
  background: rgba(0,0,0,0.45);
  pointer-events: none;
}
.topbar, .wrap > *, header {
  position: relative; z-index: 1;
}


.ld-toolbar {
  display: flex; align-items: center; gap: 10px;
  padding: 14px 20px;
  background: var(--card2); border-bottom: 1px solid var(--divider);
  flex-wrap: wrap;
}
.ld-search {
  flex: 1; min-width: 180px; max-width: 320px;
  padding: 8px 12px;
  background: var(--input-bg); border: 1.5px solid var(--input-border);
  border-radius: 8px; color: var(--text); font-family: var(--mono);
  font-size: 12.5px; outline: none;
  transition: border-color .15s;
}
.ld-search:focus { border-color: var(--red); }
.ld-search::placeholder { color: var(--text3); font-style: italic; }

.filter-tag {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 4px 10px; border-radius: 99px; font-size: 12px; font-weight: 500;
  background: var(--red-a10); color: var(--red);
  border: 1px solid var(--red-a30); cursor: pointer;
  transition: background .15s;
}
.filter-tag:hover { background: var(--red); color: #fff; }
.filter-tag .x { font-size: 14px; line-height: 1; }

.ld-btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 8px 16px; border-radius: 8px;
  font-family: var(--font); font-size: 13px; font-weight: 600;
  cursor: pointer; border: none; transition: all .15s;
  white-space: nowrap;
}
.ld-btn-primary { background: var(--red); color: #fff; }
.ld-btn-primary:hover { background: var(--red-h); }
.ld-btn-ghost {
  background: var(--card2); color: var(--text2);
  border: 1.5px solid var(--divider);
}
.ld-btn-ghost:hover { border-color: var(--red); color: var(--red); }
.ld-btn-green { background: var(--green); color: #fff; }
.ld-btn-green:hover { filter: brightness(1.1); }
.ld-btn:disabled { opacity: .4; cursor: not-allowed; }

/* Table */
.ld-table-wrap { overflow-x: auto; }
table {
  width: 100%; border-collapse: collapse;
  font-size: 13px;
}
thead th {
  padding: 10px 14px; text-align: left;
  font-size: 11px; font-weight: 700; text-transform: uppercase;
  letter-spacing: .07em; color: var(--text3);
  border-bottom: 2px solid var(--divider);
  background: var(--card2);
  white-space: nowrap; cursor: pointer; user-select: none;
}
thead th:hover { color: var(--text); }
thead th.sorted { color: var(--red); }
thead th .sort-arrow { margin-left: 4px; opacity: .6; }
tbody tr {
  border-bottom: 1px solid var(--divider);
  transition: background .1s;
}
tbody tr:hover { background: var(--step-hover); }
tbody tr.selected { background: var(--red-a10); }
tbody td { padding: 10px 14px; color: var(--text); vertical-align: middle; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 0; }
tbody td.td-host { color: var(--text2); font-weight: 500; }
tbody td.td-name { font-weight: 500; }
tbody td.td-key  { font-family: var(--mono); font-size: 11px; color: var(--text3); }
tbody td.td-val  { font-family: var(--mono); font-weight: 600; }
tbody td.td-val.has-val { color: var(--text); }
tbody td.td-val.no-val  { color: var(--text3); font-style: italic; }
tbody td.td-ago  { font-family: var(--mono); font-size: 11.5px; color: var(--text3); white-space: nowrap; }
tbody td.td-state .state-pill {
  display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 10px; font-weight: 700;
}
.state-ok   { background: rgba(22,163,74,.12); color: var(--green); border: 1px solid rgba(22,163,74,.3); }
.state-err  { background: rgba(224,60,60,.12); color: var(--red);   border: 1px solid rgba(224,60,60,.3); }

/* Checkbox col */
.td-check input { accent-color: var(--red); width: 15px; height: 15px; cursor: pointer; }
thead th.th-check { width: 40px; }

/* Footer bar */
.ld-footer {
  display: flex; align-items: center; justify-content: space-between;
  padding: 12px 20px; border-top: 1px solid var(--divider);
  background: var(--card2); gap: 12px; flex-wrap: wrap;
}
.ld-selection-info { font-size: 13px; color: var(--text2); }
.ld-selection-info b { color: var(--text); }
.ld-pagination { display: flex; gap: 4px; align-items: center; }
.pg-btn {
  padding: 5px 11px; border-radius: 6px; font-size: 12px; font-family: var(--mono);
  cursor: pointer; border: 1px solid var(--divider); background: var(--card2);
  color: var(--text2); transition: all .14s;
}
.pg-btn:hover { border-color: var(--red); color: var(--red); }
.pg-btn.active { background: var(--red); border-color: var(--red); color: #fff; }
.pg-btn:disabled { opacity: .35; cursor: not-allowed; }
.pg-info { font-size: 12px; color: var(--text3); font-family: var(--mono); padding: 0 6px; }

/* Empty / loading */
.ld-empty {
  text-align: center; padding: 60px 20px;
  color: var(--text3); font-size: 14px;
}
.ld-empty svg { margin-bottom: 12px; opacity: .3; }
.ld-spinner {
  display: inline-block; width: 18px; height: 18px;
  border: 2.5px solid var(--divider); border-top-color: var(--red);
  border-radius: 50%; animation: spin .7s linear infinite;
  vertical-align: middle; margin-right: 6px;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* Export modal */
.export-modal-overlay {
  display: none; position: fixed; inset: 0; z-index: 2000;
  background: rgba(0,0,0,.6); backdrop-filter: blur(6px);
  align-items: center; justify-content: center;
}
.export-modal-overlay.open { display: flex; }
.export-modal {
  background: var(--card); border: 1px solid var(--divider);
  border-radius: 14px; padding: 28px; min-width: 340px;
  box-shadow: 0 24px 64px rgba(0,0,0,.5);
  animation: mIn .18s ease;
}
.export-modal h3 { font-size: 16px; font-weight: 700; margin: 0 0 8px; }
.export-modal .em-sub { font-size: 13px; color: var(--text2); margin-bottom: 20px; }
.export-modal .em-row { display: flex; gap: 10px; }
.em-btn {
  flex: 1; padding: 12px; border-radius: 8px; border: none;
  font-family: var(--font); font-weight: 600; font-size: 14px;
  cursor: pointer; text-align: center; text-decoration: none;
  display: flex; align-items: center; justify-content: center; gap: 7px;
  transition: filter .15s, transform .15s;
}
.em-btn:hover { filter: brightness(1.1); transform: translateY(-1px); }
.em-pdf   { background: var(--red); color: #fff; }
.em-excel { background: var(--green); color: #fff; }
.em-cancel { background: var(--card2); color: var(--text2); border: 1.5px solid var(--divider); flex: 0; padding: 10px 18px; }
.em-cancel:hover { border-color: var(--red); color: var(--red); filter: none; transform: none; }

/* Time range in toolbar */
.ld-range { display: flex; align-items: center; gap: 6px; }
.ld-range input {
  padding: 7px 10px; font-size: 12px; font-family: var(--mono);
  background: var(--input-bg); border: 1.5px solid var(--input-border);
  border-radius: 8px; color: var(--text); outline: none; width: 170px;
}
.ld-range input:focus { border-color: var(--red); }
.ld-range-sep { color: var(--text3); font-size: 14px; }

/* Tag pill unsupported */
.pill-unsupported { background: rgba(224,60,60,.1); color: var(--red); border-radius: 4px; padding: 1px 6px; font-size: 11px; }

@media (max-width: 768px) {
  .ld-toolbar { flex-direction: column; align-items: stretch; }
  .ld-search { max-width: 100%; }
  .ld-range { flex-wrap: wrap; }
}

/* Preview modal */
.preview-overlay {
  display:none; position:fixed; inset:0; z-index:3000;
  background:rgba(0,0,0,.65); backdrop-filter:blur(8px);
  align-items:center; justify-content:center;
}
.preview-overlay.open { display:flex; }
.preview-modal {
  background:var(--card); border:1px solid var(--divider);
  border-radius:16px; padding:24px;
  width:90%; max-width:820px;
  box-shadow:0 24px 64px rgba(0,0,0,.5);
  animation: mIn .18s ease;
}
.preview-header { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:16px; gap:12px; }
.preview-title { font-size:15px; font-weight:700; color:var(--text); }
.preview-host  { font-size:12px; color:var(--text2); margin-top:2px; font-family:var(--mono); }
.chart-type-bar {
  display:flex; gap:6px; flex-wrap:wrap; margin-bottom:14px;
}
.chart-type-btn {
  display:inline-flex; align-items:center; gap:5px;
  padding:6px 12px; border-radius:8px; cursor:pointer;
  font-size:12px; font-weight:600; font-family:var(--font);
  border:1.5px solid var(--divider); background:var(--card2); color:var(--text2);
  transition:all .15s;
}
.chart-type-btn:hover { border-color:var(--red); color:var(--red); }
.chart-type-btn.active { background:var(--red); border-color:var(--red); color:#fff; }
.chart-canvas-wrap {
  position:relative; height:320px;
  background:var(--card2); border-radius:10px; padding:12px;
  border:1px solid var(--divider);
}
.preview-range {
  display:flex; align-items:center; gap:8px; margin-bottom:12px; flex-wrap:wrap;
}
.preview-range input {
  padding:6px 10px; font-size:12px; font-family:var(--mono);
  background:var(--input-bg); border:1.5px solid var(--input-border);
  border-radius:8px; color:var(--text); outline:none; width:175px;
}
.preview-range input:focus { border-color:var(--red); }
.preview-range-btn {
  padding:5px 11px; border-radius:7px; font-size:12px; font-weight:600;
  font-family:var(--font); cursor:pointer;
  border:1.5px solid var(--divider); background:var(--card2); color:var(--text2);
  transition:all .15s;
}
.preview-range-btn:hover { border-color:var(--red); color:var(--red); }
.preview-footer { display:flex; gap:8px; justify-content:flex-end; margin-top:16px; }
.preview-loading {
  position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
  background:var(--card2); border-radius:10px; z-index:10;
}
.preview-nodata {
  position:absolute; inset:0; display:flex; flex-direction:column;
  align-items:center; justify-content:center;
  color:var(--text3); font-size:13px; gap:8px;
}
</style>
</head>
<body class="dark-theme">

<!-- TOPBAR -->
<header class="topbar">
  <a href="latest_data.php" class="topbar-brand">
    <?php if (defined('CUSTOM_LOGO_PATH')): ?><img src="<?= htmlspecialchars(CUSTOM_LOGO_PATH,ENT_QUOTES,'UTF-8') ?>" alt="Logo" class="custom-logo" onerror="this.style.display='none'">><?php endif; ?>
    <span class="zabbix-logo">ZABBIX</span>
    <span class="topbar-name">Report</span>
  </a>
  <span class="topbar-sep">|</span>
  <span class="topbar-sub">Export</span>
  <a href="export-excel/excel_export.php" class="btn-top" style="background:var(--green,#16a34a);color:#fff;border-color:var(--green,#16a34a);text-decoration:none" target="_blank" rel="noopener">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg>
    Excel
  </a>
  <a href="maintenances/" class="btn-top" style="background:#d97706;color:#fff;border-color:#d97706;text-decoration:none" target="_blank" rel="noopener">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg>
    <?= t('maintenances_button', 'Mantenciones') ?>
  </a>
  <div class="topbar-spacer"></div>
  <div class="topbar-actions">
    <button id="theme-toggle" class="btn-top">&#9788; Light</button>
    <a href="logout.php" class="btn-top danger">&#8594; <?= t('logout_button','Logout') ?></a>
  </div>
</header>

<div style="max-width:1400px;margin:0 auto;padding:24px 20px 60px;position:relative;z-index:1">

  <!-- FILTER BAR -->
  <div style="background:var(--card);border:1px solid var(--divider);border-radius:14px;margin-bottom:16px;position:relative">
    <div style="padding:16px 20px;border-bottom:1px solid var(--divider);display:flex;align-items:center;gap:10px;flex-wrap:wrap">
      <span style="font-size:13px;font-weight:700;color:var(--text);margin-right:4px"><?= t('ld_filters') ?></span>

      <!-- Hosts multiselect -->
      <div style="position:relative;flex:1;min-width:200px;max-width:280px">
        <input type="text" id="host-search" class="ld-search" placeholder="<?= t('ld_search_hosts') ?>" autocomplete="off">
        <div id="host-dropdown" style="display:none;position:fixed;z-index:9999;background:var(--card,#fff);border:1px solid var(--divider,#ddd);border-radius:8px;max-height:240px;overflow-y:auto;box-shadow:0 4px 16px rgba(0,0,0,.35);min-width:220px;padding:4px 0"></div>
      </div>

      <!-- Groups multiselect -->
      <div style="position:relative;flex:1;min-width:200px;max-width:280px">
        <input type="text" id="group-search" class="ld-search" placeholder="<?= t('ld_search_groups') ?>" autocomplete="off">
        <div id="group-dropdown" style="display:none;position:fixed;z-index:9999;background:var(--card,#fff);border:1px solid var(--divider,#ddd);border-radius:8px;max-height:240px;overflow-y:auto;box-shadow:0 4px 16px rgba(0,0,0,.35);min-width:220px;padding:4px 0"></div>
      </div>

      <!-- Item name search -->
      <div style="position:relative;flex:1;min-width:160px;max-width:220px">
        <input type="text" id="name-search" class="ld-search" placeholder="<?= t('ld_filter_items') ?>" autocomplete="off" style="width:100%">
        <div id="item-dropdown" style="display:none;position:fixed;z-index:9999;background:var(--card,#fff);border:1px solid var(--divider,#ddd);border-radius:8px;max-height:240px;overflow-y:auto;box-shadow:0 4px 16px rgba(0,0,0,.35);min-width:220px;padding:4px 0"></div>
      </div>

      <button class="ld-btn ld-btn-primary" id="apply-filter-btn">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <?= t('ld_apply') ?>
      </button>
      <button class="ld-btn ld-btn-ghost" id="clear-filter-btn"><?= t('ld_clear') ?></button>
    </div>

    <!-- Active filter tags -->
    <div id="active-filters" style="display:none;padding:8px 20px;display:flex;gap:6px;flex-wrap:wrap;align-items:center">
      <span style="font-size:11px;color:var(--text3);font-weight:600;text-transform:uppercase;letter-spacing:.06em"><?= t('ld_active') ?></span>
    </div>
  </div>

  <!-- MAIN TABLE CARD -->
  <div style="background:var(--card);border:1px solid var(--divider);border-radius:14px;overflow:hidden">

    <!-- TABLE TOOLBAR -->
    <div class="ld-toolbar">
      <div id="selected-info" style="font-size:13px;color:var(--text2)">
        <span id="selected-count" style="font-weight:700;color:var(--text)">0</span> <?= t('ld_selected_items') ?>
      </div>
      <div style="flex:1"></div>

      <!-- Time range -->
      <div class="ld-range">
        <input type="datetime-local" id="range-from" title="<?= t('ld_from') ?>">
        <span class="ld-range-sep">&#8594;</span>
        <input type="datetime-local" id="range-to" title="<?= t('ld_to') ?>">
        <button class="ld-btn ld-btn-ghost" id="btn-24h" style="font-size:12px;padding:7px 12px">24h</button>
      </div>

      <button class="ld-btn ld-btn-primary" id="export-selected-btn" disabled>
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg>
        Export PDF
      </button>
      <button class="ld-btn ld-btn-ghost" id="refresh-btn" title="Refrescar">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/></svg>
      </button>
    </div>

    <!-- TABLE -->
    <div class="ld-table-wrap" style="overflow-x:auto">
      <table id="latest-table" style="min-width:1000px;width:100%;table-layout:fixed">
        <colgroup>
          <col style="width:40px">
          <col style="width:130px">
          <col style="width:180px">
          <col style="width:170px">
          <col style="width:200px">
          <col style="width:90px">
          <col style="width:70px">
          <col style="width:55px">
        </colgroup>
        <thead>
          <tr>
            <th class="th-check"><input type="checkbox" id="check-all" title="Seleccionar todo"></th>
            <th data-sort="host" class="sorted-col"><?= t('ld_col_host') ?> <span class="sort-arrow" id="arrow-host"></span></th>
            <th data-sort="name"><?= t('ld_col_item') ?> <span class="sort-arrow" id="arrow-name">&#9650;</span></th>
            <th><?= t('ld_col_key') ?></th>
            <th><?= t('ld_col_value') ?></th>
            <th><?= t('ld_col_ago') ?></th>
            <th><?= t('ld_col_state') ?></th>
            <th style="min-width:60px;width:60px;text-align:center;white-space:nowrap"><?= t('ld_col_graph') ?></th>
          </tr>
        </thead>
        <tbody id="table-body">
          <tr><td colspan="8" class="ld-empty">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <?= t('ld_no_filter', 'Apply a filter to see data') ?>
          </td></tr>
        </tbody>
      </table>
    </div>

    <!-- FOOTER -->
    <div class="ld-footer">
      <div class="ld-selection-info">
        <?= t('ld_total') ?> <b id="total-count">—</b> items
      </div>
      <div class="ld-pagination" id="pagination"></div>
      <div id="last-refresh" style="font-size:11px;color:var(--text3);font-family:var(--mono)"></div>
    </div>
  </div>
</div>

<!-- PREVIEW MODAL -->
<div class="preview-overlay" id="preview-modal">
  <div class="preview-modal">
    <div class="preview-header">
      <div>
        <div class="preview-title" id="preview-item-name">Item</div>
        <div class="preview-host"  id="preview-item-host"></div>
      </div>
      <button onclick="closePreview()" style="background:none;border:none;cursor:pointer;color:var(--text3);font-size:22px;line-height:1;padding:0">&times;</button>
    </div>

    <!-- Rango de tiempo -->
    <div class="preview-range">
      <input type="datetime-local" id="preview-from">
      <span style="color:var(--text3)">&#8594;</span>
      <input type="datetime-local" id="preview-to">
      <button class="preview-range-btn" onclick="setPreviewRange(1)">24h</button>
      <button class="preview-range-btn" onclick="setPreviewRange(7)">7d</button>
      <button class="preview-range-btn" onclick="setPreviewRange(30)">30d</button>
      <button class="preview-range-btn" onclick="reloadChart()" style="background:var(--red-a10);color:var(--red);border-color:var(--red-a30)">&#8635; Aplicar</button>
    </div>

    <!-- Selector de tipo de grafico -->
    <div class="chart-type-bar">
      <button class="chart-type-btn active" data-type="line">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 8 14 12 10 6 6 10 2 6"/></svg> Linea
      </button>
      <button class="chart-type-btn" data-type="area">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 20 L6 10 L10 14 L14 6 L18 10 L22 4 L22 20 Z"/></svg> Area
      </button>
      <button class="chart-type-btn" data-type="bar">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="10" width="4" height="10"/><rect x="10" y="6" width="4" height="14"/><rect x="18" y="2" width="4" height="18"/></svg> Barras
      </button>
      <button class="chart-type-btn" data-type="spline">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 18 C6 18 6 6 12 6 S18 12 22 8"/></svg> Spline
      </button>
      <button class="chart-type-btn" data-type="step">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="2 18 8 18 8 10 14 10 14 6 20 6"/></svg> Escalonado
      </button>
      <button class="chart-type-btn" data-type="scatter">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="5" cy="18" r="2"/><circle cx="10" cy="10" r="2"/><circle cx="16" cy="14" r="2"/><circle cx="20" cy="6" r="2"/></svg> Puntos
      </button>
    </div>

    <!-- Canvas -->
    <div class="chart-canvas-wrap">
      <div class="preview-loading" id="preview-loading"><span class="ld-spinner"></span></div>
      <div class="preview-nodata" id="preview-nodata" style="display:none">
        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 3l18 18M9 9v6m6-6v6M3 9h18"/></svg>
        <?= t('ld_no_data_range') ?>
      </div>
      <canvas id="preview-canvas"></canvas>
    </div>

    <div class="preview-footer">
      <button class="ld-btn ld-btn-ghost" onclick="closePreview()"><?= t('ld_close') ?></button>
      <button class="ld-btn ld-btn-green" onclick="exportFromPreview()">
        &#128196; <?= t('ld_export_pdf') ?></button>
    </div>
  </div>
</div>

<!-- EXPORT MODAL -->
<div class="export-modal-overlay" id="export-modal">
  <div class="export-modal">
    <h3><?= t('ld_export_title') ?></h3>
    <div class="em-sub" id="export-modal-desc"><?= t('ld_export_desc') ?> <b id="export-count">0</b> <?= t('ld_graphs_selected') ?></div>
    <form method="post" action="generate.php" target="_blank" id="export-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token,ENT_QUOTES,'UTF-8') ?>">
      <input type="hidden" name="host_item_ids" id="export-item-ids">
      <input type="hidden" name="from_dt"        id="export-from">
      <input type="hidden" name="to_dt"          id="export-to">
      <input type="hidden" name="client_tz"      id="export-tz">
      <div class="em-row" style="margin-bottom:10px">
        <button type="submit" class="em-btn em-pdf"><?= t('ld_generate_pdf') ?></button>
      </div>
      <div class="em-row">
        <button type="button" class="em-btn em-cancel" id="export-modal-close"><?= t('ld_cancel') ?></button>
      </div>
    </form>
  </div>
</div>

<script>
  // Variables globales para filtro local
  var _allLoadedItems = [];
  var _filteredItems  = [];
  var _filterPage     = 1;
  var _perPage        = 50;

const T = <?= json_encode($translations) ?>;

// ── Estado ────────────────────────────────────────────────────────────────────
let state = {
  hostids:   [],  // [{id, name}]
  groupids:  [],  // [{id, name}]
  name:      '',
  sort:      'name',
  sortorder: 'ASC',
  page:      1,
  total:     0,
  selected:  new Map(), // itemid -> {hostid, name, host}
  loading:   false,
};

// ── DOM refs ──────────────────────────────────────────────────────────────────
const tbody        = document.getElementById('table-body');
const totalCount   = document.getElementById('total-count');
const selectedCount= document.getElementById('selected-count');
const exportBtn    = document.getElementById('export-selected-btn');
const checkAll     = document.getElementById('check-all');
const pagination   = document.getElementById('pagination');
const lastRefresh  = document.getElementById('last-refresh');
const exportModal  = document.getElementById('export-modal');

// ── Tema ──────────────────────────────────────────────────────────────────────
const body = document.body;
(function(){
  var t = localStorage.getItem('zbx-theme') || 'dark';
  body.className = t === 'light' ? 'light-theme' : 'dark-theme';
  document.getElementById('theme-toggle').textContent = t === 'light' ? '🌙 Dark' : '☀ Light';
})();
document.getElementById('theme-toggle').addEventListener('click', function(){
  var cur = localStorage.getItem('zbx-theme') || 'dark';
  var next = cur === 'dark' ? 'light' : 'dark';
  localStorage.setItem('zbx-theme', next);
  body.className = next === 'light' ? 'light-theme' : 'dark-theme';
  this.textContent = next === 'light' ? '🌙 Dark' : '☀ Light';
});

// ── Quick range ───────────────────────────────────────────────────────────────
function fmt(d){
  return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0')+'T'+String(d.getHours()).padStart(2,'0')+':'+String(d.getMinutes()).padStart(2,'0');
}
document.getElementById('btn-24h').addEventListener('click', function(){
  var now=new Date(), from=new Date(now-86400000);
  document.getElementById('range-from').value = fmt(from);
  document.getElementById('range-to').value   = fmt(now);
});

// ── Autocomplete hosts/groups ─────────────────────────────────────────────────
function setupAutocomplete(inputId, dropdownId, type, stateKey) {
  const input    = document.getElementById(inputId);
  const dropdown = document.getElementById(dropdownId);
  let timer;

  function fetchSuggestions(q) {
    clearTimeout(timer);
    timer = setTimeout(() => {
      fetch('latest_data.php?action=list_'+type+'&q='+encodeURIComponent(q))
        .then(r=>{ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
        .then(data => {
          dropdown.innerHTML = '';
          if (!data.length) { dropdown.style.display='none'; return; }
          data.forEach(item => {
            const id   = type==='hosts' ? item.hostid : item.groupid;
            const name = item.name;
            if (state[stateKey].find(x=>x.id===id)) return;
            const div = document.createElement('div');
            div.style.cssText = 'padding:6px 10px;cursor:pointer;font-size:13px;font-family:var(--mono);transition:background .1s;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--text,#111);';
            // Resaltar la parte que coincide con lo buscado
            if (q) {
              const idx = name.toLowerCase().indexOf(q.toLowerCase());
              if (idx >= 0) {
                div.innerHTML = escH(name.slice(0, idx))
                  + '<strong style="color:var(--amber,#f59e0b);font-weight:700">' + escH(name.slice(idx, idx+q.length)) + '</strong>'
                  + escH(name.slice(idx+q.length));
              } else { div.textContent = name; }
            } else { div.textContent = name; }
            div.onmouseenter = ()=>div.style.background='var(--step-hover)';
            div.onmouseleave = ()=>div.style.background='';
            div.onclick = () => {
              state[stateKey].push({id, name});
              input.value = '';
              dropdown.style.display = 'none';
              renderFilterTags();
            };
            dropdown.appendChild(div);
          });
          dropdown.style.display = dropdown.children.length ? 'block' : 'none';
        }).catch(err => {
          dropdown.innerHTML = '<div style="padding:8px 10px;font-size:12px;color:var(--red)">Error: '+err.message+'</div>';
          dropdown.style.display = 'block';
        });
    }, q === '' ? 0 : 250);
  }

  // Posicionar dropdown bajo el input usando coordenadas reales (position:fixed)
  function positionDropdown() {
    var rect = input.getBoundingClientRect();
    dropdown.style.top   = (rect.bottom + 4) + 'px';
    dropdown.style.left  = rect.left + 'px';
    dropdown.style.width = rect.width + 'px';
  }
  // Solo buscar cuando el usuario escribe, no al hacer focus
  input.addEventListener('input', function() {
    const q = this.value.trim();
    if (!q) { dropdown.style.display = 'none'; return; }
    positionDropdown();
    fetchSuggestions(q);
  });
  window.addEventListener('resize', function() {
    if (dropdown.style.display !== 'none') positionDropdown();
  });

  document.addEventListener('click', e => {
    if (!input.contains(e.target) && !dropdown.contains(e.target))
      dropdown.style.display = 'none';
  });
}
setupAutocomplete('host-search',  'host-dropdown',  'hosts',  'hostids');
setupAutocomplete('group-search', 'group-dropdown', 'groups', 'groupids');



// ── Filter tags ───────────────────────────────────────────────────────────────
function renderFilterTags() {
  const container = document.getElementById('active-filters');
  // Remove old tags (keep the label)
  Array.from(container.querySelectorAll('.filter-tag')).forEach(t=>t.remove());

  state.hostids.forEach(h => {
    const tag = document.createElement('span');
    tag.className = 'filter-tag';
    tag.innerHTML = '&#128444; '+escH(h.name)+' <span class="x" title="Quitar">&#215;</span>';
    tag.querySelector('.x').onclick = () => { state.hostids = state.hostids.filter(x=>x.id!==h.id); renderFilterTags(); };
    container.appendChild(tag);
  });
  state.groupids.forEach(g => {
    const tag = document.createElement('span');
    tag.className = 'filter-tag';
    tag.innerHTML = '&#9776; '+escH(g.name)+' <span class="x" title="Quitar">&#215;</span>';
    tag.querySelector('.x').onclick = () => { state.groupids = state.groupids.filter(x=>x.id!==g.id); renderFilterTags(); };
    container.appendChild(tag);
  });

  const hasFilters = state.hostids.length || state.groupids.length || state.name;
  container.style.display = hasFilters ? 'flex' : 'none';
}

// ── Load data ─────────────────────────────────────────────────────────────────
// Filtro en vivo sobre los items YA cargados (despues de Apply)

function applyLocalFilter(page) {
  _filterPage = page || 1;
  var q = document.getElementById('name-search').value.trim().toLowerCase();
  // Filtrar: el query debe aparecer al INICIO de una palabra en el nombre o clave
  // Ej: 'FS' matchea 'FS [/boot]' pero NO 'Checksum' (que tiene 'fs' en medio)
  _filteredItems = q
    ? _allLoadedItems.filter(function(i) {
        var name = i.name.toLowerCase();
        var key  = (i.key_||'').toLowerCase();
        // Coincide si el nombre empieza con q, o si una 'palabra' del nombre empieza con q
        var wordMatch = function(str) {
          if (str.startsWith(q)) return true;
          // Dividir por espacios, [ , : para buscar inicio de palabra
          var words = str.split(/[\s\[\],.:_-]+/);
          return words.some(function(w){ return w.startsWith(q); });
        };
        return wordMatch(name) || wordMatch(key);
      })
    : _allLoadedItems.slice();
  var start   = (_filterPage - 1) * _perPage;
  var pageItems = _filteredItems.slice(start, start + _perPage);
  renderTable(pageItems);
  // Paginacion que usa applyLocalFilter en vez de loadData
  renderLocalPagination(_filteredItems.length, _perPage, _filterPage);
  totalCount.textContent = _filteredItems.length;
}

function renderLocalPagination(total, perPage, current) {
  var pages = Math.ceil(total / perPage);
  pagination.innerHTML = '';
  if (pages <= 1) return;
  var mkBtn = function(label, page, active, disabled) {
    var btn = document.createElement('button');
    btn.className = 'pg-btn' + (active?' active':'');
    btn.textContent = label; btn.disabled = disabled;
    if (!disabled && !active) btn.onclick = function(){ applyLocalFilter(page); };
    pagination.appendChild(btn);
  };
  mkBtn('<<', current-1, false, current<=1);
  var start = Math.max(1, current-2), end = Math.min(pages, start+4);
  for (var p=start; p<=end; p++) mkBtn(p, p, p===current, false);
  mkBtn('>>', current+1, false, current>=pages);
  var info = document.createElement('span');
  info.className = 'pg-info';
  info.textContent = (((current-1)*perPage)+1)+'-'+Math.min(current*perPage,total)+' / '+total;
  pagination.appendChild(info);
}

document.getElementById('name-search').addEventListener('input', function() {
  if (!_allLoadedItems.length) return;
  applyLocalFilter(1);
});

function loadData(page) {
  if (state.loading) return;
  state.loading = true; state.page = page || state.page;

  // Show spinner
  tbody.innerHTML = '<tr><td colspan="8" class="ld-empty"><span class="ld-spinner"></span> '+(T.ld_loading||'Loading...')+'</td></tr>';

  const params = new URLSearchParams({
    action:    'refresh',
    hostids:   state.hostids.map(h=>h.id).join(','),
    groupids:  state.groupids.map(g=>g.id).join(','),
    sort:      state.sort,
    sortorder: state.sortorder,
  });

  fetch('latest_data.php?' + params)
    .then(r=>r.json())
    .then(data => {
      state.loading = false;
      lastRefresh.textContent = (T.ld_updated||'Updated:') + ' ' + new Date().toLocaleTimeString();
      _allLoadedItems = data.items;
      // No limpiar el campo - el usuario puede haber escrito antes del Apply
      applyLocalFilter(1);
    })
    .catch(() => {
      state.loading = false;
      tbody.innerHTML = '<tr><td colspan="8" class="ld-empty" style="color:var(--red)">Error al cargar datos</td></tr>';
    });
}

// ── Render table ──────────────────────────────────────────────────────────────
function renderTable(items) {
  if (!items.length) {
    tbody.innerHTML = '<tr><td colspan="8" class="ld-empty"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>'+(T.ld_no_results||'No items found')+'</td></tr>';
    return;
  }

  tbody.innerHTML = '';
  items.forEach(item => {
    const tr   = document.createElement('tr');
    const isSel= state.selected.has(item.itemid);
    if (isSel) tr.classList.add('selected');

    const stateClass = item.state === 1 ? 'state-err' : 'state-ok';
    const stateText  = item.state === 1 ? 'Error' : 'OK';
    const valClass   = item.lastvalue === '—' ? 'no-val' : 'has-val';

    tr.innerHTML = `
      <td class="td-check"><input type="checkbox" data-id="${item.itemid}" data-host="${escA(item.host)}" data-name="${escA(item.name)}" ${isSel?'checked':''}></td>
      <td class="td-host">${escH(item.host)}</td>
      <td class="td-name">${escH(item.name)}</td>
      <td class="td-key">${escH(item.key_)}</td>
      <td class="td-val ${valClass}">${escH(item.lastvalue)}</td>
      <td class="td-ago">${item.ago}</td>
      <td class="td-state"><span class="state-pill ${stateClass}">${stateText}</span></td>
      <td style="text-align:center">
        <button class="ld-btn ld-btn-primary" style="padding:5px 10px;font-size:11px"
          onclick="openPreview('${item.itemid}','${escA(item.name)}','${escA(item.host)}',${item.value_type||0},'${escA(item.units||'')}')">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="22 12 18 8 14 12 10 6 6 10 2 6"/></svg>
        </button>
      </td>`;

    // Checkbox listener
    tr.querySelector('input[type=checkbox]').addEventListener('change', function() {
      if (this.checked) {
        state.selected.set(item.itemid, {hostid: item.hostid, name: item.name, host: item.host});
        tr.classList.add('selected');
      } else {
        state.selected.delete(item.itemid);
        tr.classList.remove('selected');
      }
      updateSelectionUI();
    });

    tbody.appendChild(tr);
  });
  updateSelectionUI();
}

// ── Pagination ────────────────────────────────────────────────────────────────
function renderPagination(total, perPage, current) {
  const pages = Math.ceil(total / perPage);
  pagination.innerHTML = '';
  if (pages <= 1) return;

  const mkBtn = (label, page, active, disabled) => {
    const btn = document.createElement('button');
    btn.className = 'pg-btn' + (active?' active':'');
    btn.textContent = label; btn.disabled = disabled;
    if (!disabled && !active) btn.onclick = () => loadData(page);
    pagination.appendChild(btn);
  };

  mkBtn('«', current-1, false, current<=1);
  const start = Math.max(1, current-2), end = Math.min(pages, start+4);
  for (let p=start; p<=end; p++) mkBtn(p, p, p===current, false);
  mkBtn('»', current+1, false, current>=pages);

  const info = document.createElement('span');
  info.className = 'pg-info';
  info.textContent = `${((current-1)*perPage)+1}–${Math.min(current*perPage,total)} de ${total}`;
  pagination.appendChild(info);
}

// ── Selection UI ──────────────────────────────────────────────────────────────
function updateSelectionUI() {
  const n = state.selected.size;
  selectedCount.textContent = n;
  exportBtn.disabled = n === 0;
  checkAll.indeterminate = n > 0 && n < tbody.querySelectorAll('input[type=checkbox]').length;
  checkAll.checked = n > 0 && tbody.querySelectorAll('input[type=checkbox]:not(:checked)').length === 0;
}

checkAll.addEventListener('change', function() {
  tbody.querySelectorAll('input[type=checkbox]').forEach(cb => {
    cb.checked = this.checked;
    const tr = cb.closest('tr');
    const id = cb.dataset.id;
    if (this.checked) {
      state.selected.set(id, {name: cb.dataset.name, host: cb.dataset.host});
      tr.classList.add('selected');
    } else {
      state.selected.delete(id);
      tr.classList.remove('selected');
    }
  });
  updateSelectionUI();
});

// ── Sort ──────────────────────────────────────────────────────────────────────
document.querySelectorAll('thead th[data-sort]').forEach(th => {
  th.addEventListener('click', function() {
    const field = this.dataset.sort;
    if (state.sort === field) {
      state.sortorder = state.sortorder === 'ASC' ? 'DESC' : 'ASC';
    } else {
      state.sort = field; state.sortorder = 'ASC';
    }
    document.querySelectorAll('thead th[data-sort]').forEach(t => {
      t.classList.remove('sorted');
      const a = t.querySelector('.sort-arrow');
      if (a) a.textContent = '';
    });
    this.classList.add('sorted');
    const arrow = this.querySelector('.sort-arrow');
    if (arrow) arrow.textContent = state.sortorder === 'ASC' ? '▲' : '▼';
    loadData(1);
  });
});

// ── Export selected ───────────────────────────────────────────────────────────
exportBtn.addEventListener('click', openExportModal);

function openExportModal() {
  const ids = [...state.selected.keys()].join(',');
  document.getElementById('export-count').textContent = state.selected.size;
  document.getElementById('export-item-ids').value = ids;
  document.getElementById('export-from').value     = document.getElementById('range-from').value;
  document.getElementById('export-to').value       = document.getElementById('range-to').value;
  document.getElementById('export-tz').value       = Intl.DateTimeFormat().resolvedOptions().timeZone;
  exportModal.classList.add('open');
}

function exportSingle(itemid, label) {
  document.getElementById('export-count').textContent = '1 item: ' + label;
  document.getElementById('export-item-ids').value = itemid;
  document.getElementById('export-from').value     = document.getElementById('range-from').value;
  document.getElementById('export-to').value       = document.getElementById('range-to').value;
  document.getElementById('export-tz').value       = Intl.DateTimeFormat().resolvedOptions().timeZone;
  exportModal.classList.add('open');
}

document.getElementById('export-modal-close').onclick = () => exportModal.classList.remove('open');
exportModal.addEventListener('click', e => { if (e.target === exportModal) exportModal.classList.remove('open'); });

document.getElementById('export-form').addEventListener('submit', function() {
  exportModal.classList.remove('open');
  // Regenerar CSRF via AJAX - no recarga la pagina, no interrumpe el PDF en otra pestana
  setTimeout(function() {
    fetch('latest_data.php?action=new_csrf')
      .then(function(r){ return r.json(); })
      .then(function(data) {
        if (data.token) {
          document.querySelector('#export-form input[name=csrf_token]').value = data.token;
        }
      })
      .catch(function(){});
  }, 300);
});

// ── Apply / clear filter ──────────────────────────────────────────────────────
document.getElementById('apply-filter-btn').addEventListener('click', () => {
  state.name = document.getElementById('name-search').value.trim();
  loadData(1);
});


// Enter en name-search manejado por applyLocalFilter
document.getElementById('clear-filter-btn').addEventListener('click', () => {
  state.hostids = []; state.groupids = []; state.name = '';
  document.getElementById('host-search').value  = '';
  document.getElementById('group-search').value = '';
  document.getElementById('name-search').value  = '';
  renderFilterTags();
  tbody.innerHTML = '<tr><td colspan="8" class="ld-empty"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>'+(T.ld_no_filter||'Apply a filter to see data')+'</td></tr>';
  totalCount.textContent = '—';
  pagination.innerHTML = '';
});
document.getElementById('refresh-btn').addEventListener('click', () => loadData(state.page));

// ── Helpers ───────────────────────────────────────────────────────────────────
function escH(s) { return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function escA(s) { return String(s??'').replace(/"/g,'&quot;'); }

// ── PREVIEW DE GRAFICO ────────────────────────────────────────────────────────
var _previewChart  = null;
var _previewItem   = null; // {itemid, name, host, vtype}
var _previewType   = 'line';

var CHART_COLORS = {
  line:   'rgb(224,60,60)',
  fill:   'rgba(224,60,60,.15)',
  grid:   'rgba(128,128,128,.15)',
  text:   getComputedStyle(document.body).getPropertyValue('--text') || '#e8ecf6',
};

function fmtDate(d) {
  return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+
         String(d.getDate()).padStart(2,'0')+'T'+
         String(d.getHours()).padStart(2,'0')+':'+
         String(d.getMinutes()).padStart(2,'0');
}

function setPreviewRange(days) {
  var now  = new Date();
  var from = new Date(now - days * 86400000);
  document.getElementById('preview-from').value = fmtDate(from);
  document.getElementById('preview-to').value   = fmtDate(now);
  reloadChart();
}

function openPreview(itemid, name, host, vtype, units) {
  _previewItem = {itemid: itemid, name: name, host: host, vtype: vtype || 0, units: units || ''};
  document.getElementById('preview-item-name').textContent = name;
  document.getElementById('preview-item-host').textContent = host;

  // Sincronizar con el rango global si hay uno seteado
  var gFrom = document.getElementById('range-from').value;
  var gTo   = document.getElementById('range-to').value;
  if (gFrom && gTo) {
    document.getElementById('preview-from').value = gFrom;
    document.getElementById('preview-to').value   = gTo;
  } else {
    setPreviewRange(1); // default 24h sin reloadChart (lo hacemos despues)
    // setPreviewRange ya llama reloadChart, salir
    document.getElementById('preview-modal').classList.add('open');
    return;
  }

  document.getElementById('preview-modal').classList.add('open');
  reloadChart();
}

function closePreview() {
  document.getElementById('preview-modal').classList.remove('open');
  if (_previewChart) { _previewChart.destroy(); _previewChart = null; }
}

document.getElementById('preview-modal').addEventListener('click', function(e) {
  if (e.target === this) closePreview();
});

// Botones de tipo de grafico
document.querySelectorAll('.chart-type-btn').forEach(function(btn) {
  btn.addEventListener('click', function() {
    document.querySelectorAll('.chart-type-btn').forEach(function(b){ b.classList.remove('active'); });
    this.classList.add('active');
    _previewType = this.dataset.type;
    if (_previewChart) renderChart(_previewChart._cachedData);
  });
});

function reloadChart() {
  if (!_previewItem) return;
  var fromEl = document.getElementById('preview-from');
  var toEl   = document.getElementById('preview-to');
  var from   = fromEl.value ? Math.floor(new Date(fromEl.value).getTime()/1000) : Math.floor(Date.now()/1000) - 86400;
  var till   = toEl.value   ? Math.floor(new Date(toEl.value).getTime()/1000)   : Math.floor(Date.now()/1000);

  document.getElementById('preview-loading').style.display = 'flex';
  document.getElementById('preview-nodata').style.display  = 'none';
  var canvas = document.getElementById('preview-canvas');
  canvas.style.display = 'none';

  fetch('latest_data.php?action=item_history'
    + '&itemid=' + _previewItem.itemid
    + '&vtype='  + _previewItem.vtype
    + '&from='   + from
    + '&till='   + till)
    .then(function(r){ return r.json(); })
    .then(function(data) {
      console.log('Preview data:', JSON.stringify(data).slice(0,300));
      document.getElementById('preview-loading').style.display = 'none';
      if (data.error) {
        var nd = document.getElementById('preview-nodata');
        nd.style.display = 'flex';
        nd.innerHTML = '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12" y2="16"/></svg><span style="font-size:12px;max-width:300px;text-align:center">' + data.error + '</span>';
        return;
      }
      if (!data.points || !data.points.length) {
        document.getElementById('preview-nodata').style.display = 'flex';
        return;
      }
      canvas.style.display = 'block';
      renderChart(data.points);
    })
    .catch(function(err) {
      console.error('Preview fetch error:', err);
      document.getElementById('preview-loading').style.display = 'none';
      document.getElementById('preview-nodata').style.display  = 'flex';
    });
}

function fmtVal(v, units) {
  if (v === null || v === undefined) return '—';
  var u = (units || '').trim();
  // Convertir bytes
  if (u === 'B' || u === 'b') {
    if (v >= 1073741824) return (v/1073741824).toFixed(2) + ' GB';
    if (v >= 1048576)    return (v/1048576).toFixed(2) + ' MB';
    if (v >= 1024)       return (v/1024).toFixed(2) + ' KB';
    return v + ' B';
  }
  // Bps
  if (u === 'bps' || u === 'Bps') {
    if (v >= 1000000) return (v/1000000).toFixed(2) + ' M' + u;
    if (v >= 1000)    return (v/1000).toFixed(2) + ' K' + u;
    return v.toFixed(2) + ' ' + u;
  }
  // Porcentaje
  if (u === '%') return v.toFixed(2) + '%';
  // Generico
  var s = v.toFixed(4).replace(/\.?0+$/, '');
  return u ? s + ' ' + u : s;
}

function renderChart(points) {
  if (!points || !points.length) return;
  var canvas = document.getElementById('preview-canvas');
  if (_previewChart) { _previewChart.destroy(); _previewChart = null; }

  // Preparar datos segun tipo
  var isNumeric = points.length > 0 && typeof points[0].v === 'number';
  var labels = points.map(function(p){ return new Date(p.t * 1000); });
  var values = points.map(function(p){ return isNumeric ? p.v : null; });

  var type    = _previewType;
  var tension = 0;
  var stepped = false;
  var chartType = 'line';
  var fill    = false;
  var pointRadius = 0;
  var pointHover  = 4;

  if (type === 'line')    { tension = 0;    fill = false; pointRadius = 0; }
  if (type === 'area')    { tension = 0;    fill = true;  pointRadius = 0; }
  if (type === 'spline')  { tension = 0.4;  fill = false; pointRadius = 0; }
  if (type === 'step')    { tension = 0;    fill = false; stepped = 'before'; pointRadius = 0; }
  if (type === 'scatter') { chartType = 'scatter'; pointRadius = 4; pointHover = 6; }
  if (type === 'bar')     { chartType = 'bar'; }

  var isDark   = document.body.classList.contains('dark-theme');
  var gridColor= isDark ? 'rgba(255,255,255,.08)' : 'rgba(0,0,0,.08)';
  var textColor= isDark ? '#8892b0' : '#5c6878';
  var lineColor= '#e03c3c';
  var fillColor= 'rgba(224,60,60,.12)';

  var dataset = {
    data: chartType === 'scatter'
      ? points.map(function(p,i){ return {x: labels[i], y: typeof p.v === 'number' ? p.v : null}; })
      : values,
    borderColor:           lineColor,
    backgroundColor:       type === 'bar' ? 'rgba(224,60,60,.7)' : (fill ? fillColor : 'transparent'),
    borderWidth:           type === 'bar' ? 0 : 2,
    tension:               tension,
    stepped:               stepped,
    pointRadius:           pointRadius,
    pointHoverRadius:      pointHover,
    pointBackgroundColor:  lineColor,
    fill:                  fill,
  };

  var config = {
    type: chartType,
    data: {
      labels: chartType === 'scatter' ? undefined : labels,
      datasets: [dataset]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      animation: { duration: 250 },
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: isDark ? '#1c2030' : '#fff',
          borderColor:     isDark ? '#2a3050' : '#e2e6ef',
          borderWidth:     1,
          titleColor:      textColor,
          bodyColor:       isDark ? '#e8ecf6' : '#0f1117',
          callbacks: {
            title: function(items) {
              return new Date(items[0].parsed.x).toLocaleString();
            },
            label: function(item) {
              return fmtVal(item.parsed.y, _previewItem ? _previewItem.units : '');
            }
          }
        }
      },
      scales: {
        x: {
          type: 'time',
          time: { tooltipFormat: 'dd/MM HH:mm' },
          grid:  { color: gridColor },
          ticks: { color: textColor, maxTicksLimit: 8, maxRotation: 0 }
        },
        y: {
          grid:  { color: gridColor },
          ticks: {
            color: textColor,
            callback: function(v) { return fmtVal(v, _previewItem ? _previewItem.units : ''); }
          }
        }
      }
    }
  };

  // Para bar chart ajustar x como category
  if (chartType === 'bar') {
    config.options.scales.x.type = 'time';
  }

  _previewChart = new Chart(canvas, config);
  _previewChart._cachedData = points;
}

function exportFromPreview() {
  if (!_previewItem || !_previewChart) return;
  var btn = document.querySelector('.preview-footer .ld-btn-green');
  var origText = btn.innerHTML;
  btn.innerHTML = '<span class="ld-spinner" style="border-top-color:#fff"></span>';
  btn.disabled = true;

  setTimeout(function() {
    try {
      var canvas  = document.getElementById('preview-canvas');
      var imgData = canvas.toDataURL('image/png', 1.0);

      // Tamano A4 landscape
      var pdf     = new window.jspdf.jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
      var pW      = pdf.internal.pageSize.getWidth();
      var pH      = pdf.internal.pageSize.getHeight();

      // Header
      pdf.setFillColor(224, 60, 60);
      pdf.rect(0, 0, pW, 14, 'F');
      pdf.setTextColor(255, 255, 255);
      pdf.setFontSize(11);
      pdf.setFont('helvetica', 'bold');
      pdf.text('Zabbix Report', 8, 9);
      pdf.setFont('helvetica', 'normal');
      pdf.setFontSize(9);
      pdf.text(new Date().toLocaleString(), pW - 8, 9, { align: 'right' });

      // Item info
      pdf.setTextColor(30, 30, 30);
      pdf.setFontSize(14);
      pdf.setFont('helvetica', 'bold');
      pdf.text(_previewItem.name, 8, 24);
      pdf.setFontSize(9);
      pdf.setFont('helvetica', 'normal');
      pdf.setTextColor(100, 100, 100);
      pdf.text(_previewItem.host, 8, 30);

      // Rango de tiempo
      var fromEl = document.getElementById('preview-from');
      var toEl   = document.getElementById('preview-to');
      if (fromEl.value && toEl.value) {
        var rangeStr = fromEl.value.replace('T',' ') + '  →  ' + toEl.value.replace('T',' ');
        pdf.text(rangeStr, 8, 35);
      }

      // Grafico - calcular proporciones manteniendo aspect ratio del canvas
      var cW    = canvas.width;
      var cH    = canvas.height;
      var ratio = cH / cW;
      var imgW  = pW - 16;
      var imgH  = imgW * ratio;
      var maxH  = pH - 44;
      if (imgH > maxH) { imgH = maxH; imgW = imgH / ratio; }
      var imgX  = (pW - imgW) / 2;
      pdf.addImage(imgData, 'PNG', imgX, 40, imgW, imgH);

      // Guardar
      var safeName = _previewItem.name.replace(/[^a-zA-Z0-9_-]/g, '_').slice(0, 40);
      pdf.save(safeName + '_' + _previewItem.host + '.pdf');
    } catch(e) {
      console.error('PDF export error:', e);
      alert('Error generating PDF: ' + e.message);
    }

    btn.innerHTML = origText;
    btn.disabled  = false;
  }, 100);
}

</script>

<div style="text-align:center;padding:28px 20px 20px;font-family:var(--font)">
  <div style="font-size:13px;color:var(--text2);margin-bottom:12px"><?= t('common_author_credit') ?></div>
  <div style="display:inline-flex;align-items:center;gap:16px">
    <!-- LinkedIn -->
    <a href="https://www.linkedin.com/in/axel-del-canto-del-canto-4ba643186/" target="_blank" rel="noopener"
       style="display:inline-flex;align-items:center;gap:7px;text-decoration:none;color:var(--text2);font-size:13px;font-weight:500;padding:7px 14px;border-radius:8px;border:1px solid var(--divider);background:var(--card2);transition:all .15s"
       onmouseover="this.style.borderColor='#0077b5';this.style.color='#0077b5'"
       onmouseout="this.style.borderColor='var(--divider)';this.style.color='var(--text2)'">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>
      LinkedIn
    </a>
    <!-- GitHub -->
    <a href="https://github.com/axel250r" target="_blank" rel="noopener"
       style="display:inline-flex;align-items:center;gap:7px;text-decoration:none;color:var(--text2);font-size:13px;font-weight:500;padding:7px 14px;border-radius:8px;border:1px solid var(--divider);background:var(--card2);transition:all .15s"
       onmouseover="this.style.borderColor='var(--text)';this.style.color='var(--text)'"
       onmouseout="this.style.borderColor='var(--divider)';this.style.color='var(--text2)'">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.374 0 0 5.373 0 12c0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23A11.509 11.509 0 0112 5.803c1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576C20.566 21.797 24 17.3 24 12c0-6.627-5.373-12-12-12z"/></svg>
      GitHub
    </a>
  </div>
</div>

</body>
</html>
