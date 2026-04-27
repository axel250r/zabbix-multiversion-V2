<?php
declare(strict_types=1);

session_start();
if (empty($_SESSION['zbx_auth_ok'])) {
    if (isset($_GET['action'])) { header('Content-Type: application/json'); echo json_encode(['error'=>'session']); exit; }
    header('Location: ../../login.php'); exit;
}

require_once __DIR__ . '/../../lib/i18n.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/ZabbixApiFactory.php';

try {
    $api = ZabbixApiFactory::create(ZABBIX_API_URL, $_SESSION['zbx_user'], $_SESSION['zbx_pass'], ['timeout'=>20]);
} catch (Throwable $e) {
    if (isset($_GET['action'])) { header('Content-Type: application/json'); echo json_encode(['error'=>$e->getMessage()]); exit; }
    header('Location: ../../login.php'); exit;
}

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];

if (isset($_GET['action']) && $_GET['action'] === 'refresh') {
    header('Content-Type: application/json; charset=utf-8');
    $hostids   = array_filter(explode(',', $_GET['hostids']  ?? ''), 'ctype_digit');
    $groupids  = array_filter(explode(',', $_GET['groupids'] ?? ''), 'ctype_digit');
    $name      = trim($_GET['name'] ?? '');
    $sort      = in_array($_GET['sort'] ?? '', ['host','name']) ? $_GET['sort'] : 'name';
    $sortorder = ($_GET['sortorder'] ?? '') === 'DESC' ? 'DESC' : 'ASC';
    $host_params = ['output' => ['hostid','name','status'], 'preservekeys' => true];
    if ($hostids)  $host_params['hostids']  = $hostids;
    if ($groupids) $host_params['groupids'] = $groupids;
    if (!$hostids && !$groupids && $name === '') { echo json_encode(['items'=>[],'total'=>0,'hosts'=>[]]); exit; }
    $hosts = $api->call('host.get', $host_params);
    if (!is_array($hosts) || empty($hosts)) { echo json_encode(['items'=>[],'total'=>0,'hosts'=>[]]); exit; }
    $item_params = [
        'output'       => ['itemid','hostid','name','key_','lastvalue','lastclock','value_type','units','state'],
        'hostids'      => array_keys($hosts),
        'filter'       => ['status' => 0],
        'sortfield'    => $sort === 'host' ? 'itemid' : 'name',
        'sortorder'    => $sortorder,
        'preservekeys' => true,
        'webitems'     => true,
    ];
    if ($name !== '') { $item_params['search'] = ['name' => $name]; $item_params['searchWildcardsEnabled'] = true; }
    $items = $api->call('item.get', $item_params);
    if (!is_array($items)) $items = [];
    $need_history = [];
    foreach ($items as $iid => $item) { if ($item['lastclock'] == 0) $need_history[] = $iid; }
    if ($need_history) {
        $hist = $api->call('history.get', ['output'=>['itemid','value','clock'],'itemids'=>$need_history,'sortfield'=>'clock','sortorder'=>'DESC','limit'=>count($need_history)]);
        if (is_array($hist)) {
            $latest = [];
            foreach ($hist as $h) { if (!isset($latest[$h['itemid']])) $latest[$h['itemid']] = $h; }
            foreach ($latest as $iid => $h) { if (isset($items[$iid])) { $items[$iid]['lastvalue']=$h['value']; $items[$iid]['lastclock']=$h['clock']; } }
        }
    }
    if ($sort === 'host') {
        uasort($items, function($a,$b) use ($hosts,$sortorder){
            $c=strcasecmp($hosts[$a['hostid']]['name']??'',$hosts[$b['hostid']]['name']??'');
            return $sortorder==='DESC'?-$c:$c;
        });
    }
    $result = [];
    foreach ($items as $item) {
        $host=$hosts[$item['hostid']]??null;
        $val=$item['lastvalue']; $clock=(int)$item['lastclock'];
        $ago=$clock>0?formatAge(time()-$clock):'—';
        $fval=formatValue($val,(int)$item['value_type'],$item['units']);
        $result[]=['itemid'=>$item['itemid'],'hostid'=>$item['hostid'],'host'=>$host?$host['name']:'','name'=>$item['name'],'key_'=>$item['key_'],'lastvalue'=>$fval,'lastclock'=>$clock,'ago'=>$ago,'units'=>$item['units'],'state'=>(int)$item['state'],'value_type'=>(int)$item['value_type']];
    }
    echo json_encode(['items'=>$result,'total'=>count($result),'hosts'=>array_values($hosts)]);
    exit;
}
if (isset($_GET['action']) && $_GET['action'] === 'list_hosts') {
    header('Content-Type: application/json; charset=utf-8');
    $q=trim($_GET['q']??''); $p=['output'=>['hostid','name'],'sortfield'=>'name','limit'=>50];
    if ($q){$p['search']=['name'=>'*'.$q.'*'];$p['searchWildcardsEnabled']=true;}
    echo json_encode(is_array($r=$api->call('host.get',$p))?$r:[]); exit;
}
if (isset($_GET['action']) && $_GET['action'] === 'list_groups') {
    header('Content-Type: application/json; charset=utf-8');
    $q=trim($_GET['q']??''); $p=['output'=>['groupid','name'],'sortfield'=>'name','limit'=>50];
    if ($q){$p['search']=['name'=>'*'.$q.'*'];$p['searchWildcardsEnabled']=true;}
    echo json_encode(is_array($r=$api->call('hostgroup.get',$p))?$r:[]); exit;
}

function formatAge(int $secs): string {
    if($secs<0) return 'just now';
    if($secs<60) return $secs.'s ago';
    if($secs<3600) return floor($secs/60).'m ago';
    if($secs<86400) return floor($secs/3600).'h ago';
    return floor($secs/86400).'d ago';
}
function formatValue(?string $val, int $type, string $units): string {
    if($val===null||$val==='') return '—';
    if(in_array($type,[0,3])){
        $n=(float)$val; $u=trim($units);
        if(in_array($u,['B','b'])){
            if($n>=1073741824) return round($n/1073741824,2).' GB';
            if($n>=1048576)    return round($n/1048576,2).' MB';
            if($n>=1024)       return round($n/1024,2).' KB';
            return $n.' B';
        }
        $f=$n==floor($n)?(int)$n:round($n,2);
        return $f.($u?' '.$u:'');
    }
    return mb_strlen($val)>80?mb_substr($val,0,80).'…':$val;
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($current_lang, ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Zabbix — Detailed Host Inventory</title>
<link rel="stylesheet" href="../../assets/css/export.css">
<style>
body { background-image:url('../../assets/background/bg.jpg'); background-size:cover; background-position:center; background-attachment:fixed; }
body::before { content:''; position:fixed; inset:0; z-index:0; background:rgba(0,0,0,0.45); pointer-events:none; }
.topbar,.page-root { position:relative; z-index:1; }

.page-root { display:flex; flex-direction:column; max-width:1700px; margin:0 auto; padding:18px 20px 60px; gap:14px; }
.filter-card { background:var(--card); border:1px solid var(--divider); border-radius:14px; overflow:hidden; }
.filter-row { padding:13px 18px; border-bottom:1px solid var(--divider); display:flex; align-items:center; gap:9px; flex-wrap:wrap; }
.split-panels { display:grid; grid-template-columns:1fr 1fr; gap:14px; align-items:start; }
@media(max-width:1100px){.split-panels{grid-template-columns:1fr;}}
.panel-card { background:var(--card); border:1px solid var(--divider); border-radius:14px; overflow:hidden; }
.panel-header { display:flex; align-items:center; justify-content:space-between; padding:11px 16px; border-bottom:1px solid var(--divider); background:var(--card2); flex-wrap:wrap; gap:7px; }
.panel-title { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:var(--text3); display:flex; align-items:center; gap:6px; }
.panel-badge { background:var(--red-a10); color:var(--red); border:1px solid var(--red-a30); border-radius:99px; padding:1px 7px; font-size:11px; font-weight:700; font-family:var(--mono); min-width:24px; text-align:center; }
.panel-badge.green { background:rgba(22,163,74,.12); color:#16a34a; border-color:rgba(22,163,74,.3); }

.items-table-wrap { overflow-x:auto; max-height:540px; overflow-y:auto; }
.items-table { width:100%; border-collapse:collapse; table-layout:fixed; min-width:400px; }
.items-table th { position:sticky; top:0; z-index:2; padding:7px 10px; font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--text3); background:var(--card2); border-bottom:1px solid var(--divider); text-align:left; cursor:pointer; user-select:none; white-space:nowrap; }
.items-table th:hover { color:var(--text); }
.items-table th.sorted-col { color:var(--red); }
.items-table td { padding:7px 10px; border-bottom:1px solid var(--divider); font-size:12.5px; vertical-align:middle; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.items-table tbody tr:hover { background:var(--step-hover); }
.items-table tbody tr.row-selected { background:var(--red-a10); }
.td-check { width:36px; text-align:center; }
.td-host { font-family:var(--mono); font-size:11.5px; color:var(--text2); font-weight:600; }
.td-name { color:var(--text); }
.td-val { font-family:var(--mono); font-size:12px; font-weight:600; }
.td-val.has-val { color:var(--text); }
.td-val.no-val { color:var(--text3); }
.sort-arrow { font-size:9px; margin-left:2px; }

.pivot-wrap { overflow:auto; max-height:540px; }
.pivot-table { border-collapse:collapse; font-size:12px; white-space:nowrap; }
.pivot-table th { position:sticky; top:0; z-index:2; padding:7px 11px; background:var(--card2); border:1px solid var(--divider); font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--text3); text-align:left; }
.pivot-table th:first-child { position:sticky; left:0; z-index:3; min-width:140px; }
.pivot-table td { padding:5px 11px; border:1px solid var(--divider); font-family:var(--mono); font-size:12px; color:var(--text); max-width:200px; overflow:hidden; text-overflow:ellipsis; }
.pivot-table td:first-child { position:sticky; left:0; z-index:1; background:var(--card2); font-family:var(--font); font-size:12px; font-weight:600; color:var(--text2); white-space:nowrap; }
.pivot-table tbody tr:nth-child(even) td { background:rgba(0,0,0,.04); }
.pivot-table tbody tr:nth-child(even) td:first-child { background:var(--card2); }
.pivot-table tbody tr:hover td,.pivot-table tbody tr:hover td:first-child { background:var(--step-hover); }
.pivot-cell-empty { color:var(--text3) !important; }
.col-remove { display:inline-flex; align-items:center; justify-content:center; width:13px; height:13px; border-radius:50%; background:rgba(224,60,60,.15); color:var(--red); font-size:11px; line-height:1; cursor:pointer; margin-left:4px; flex-shrink:0; transition:background .12s; vertical-align:middle; }
.col-remove:hover { background:var(--red); color:#fff; }
.pivot-empty { display:flex; flex-direction:column; align-items:center; justify-content:center; min-height:240px; color:var(--text3); font-size:13px; gap:10px; padding:30px; text-align:center; }
.pivot-empty svg { opacity:.2; }

.ld-search { flex:1; min-width:150px; max-width:260px; padding:7px 11px; background:var(--input-bg); border:1.5px solid var(--input-border); border-radius:8px; color:var(--text); font-family:var(--mono); font-size:12.5px; outline:none; transition:border-color .15s; }
.ld-search:focus { border-color:var(--red); }
.ld-search::placeholder { color:var(--text3); font-style:italic; }
.filter-tag { display:inline-flex; align-items:center; gap:5px; padding:3px 9px; border-radius:99px; font-size:12px; font-weight:500; background:var(--red-a10); color:var(--red); border:1px solid var(--red-a30); cursor:pointer; transition:background .15s; }
.filter-tag:hover { background:var(--red); color:#fff; }
.filter-tag .x { font-size:13px; line-height:1; }
.ld-btn { display:inline-flex; align-items:center; gap:6px; padding:7px 14px; border-radius:8px; font-family:var(--font); font-size:12.5px; font-weight:600; cursor:pointer; border:none; transition:all .15s; white-space:nowrap; }
.ld-btn-primary { background:var(--red); color:#fff; }
.ld-btn-primary:hover { background:var(--red-h); }
.ld-btn-ghost { background:var(--card2); color:var(--text2); border:1.5px solid var(--divider); }
.ld-btn-ghost:hover { border-color:var(--red); color:var(--red); }
.ld-btn-green { background:var(--green); color:#fff; }
.ld-btn-green:hover { filter:brightness(1.1); }
.ld-btn:disabled { opacity:.4; cursor:not-allowed; }
.ld-spinner { display:inline-block; width:16px; height:16px; border:2.5px solid var(--divider); border-top-color:var(--red); border-radius:50%; animation:spin .7s linear infinite; vertical-align:middle; }
@keyframes spin{to{transform:rotate(360deg);}}
.ld-empty { text-align:center; padding:48px 20px; color:var(--text3); font-size:13px; }
.ld-empty svg { display:block; margin:0 auto 10px; opacity:.2; }
.panel-footer { display:flex; align-items:center; justify-content:space-between; padding:8px 16px; background:var(--card2); border-top:1px solid var(--divider); flex-wrap:wrap; gap:8px; }
.ld-pagination { display:flex; align-items:center; gap:3px; flex-wrap:wrap; }
.pg-btn { min-width:26px; height:26px; padding:0 5px; border-radius:5px; border:1.5px solid var(--divider); background:var(--card2); color:var(--text2); font-family:var(--font); font-size:11px; font-weight:600; cursor:pointer; transition:all .15s; }
.pg-btn:hover { border-color:var(--red); color:var(--red); }
.pg-btn.active { background:var(--red); border-color:var(--red); color:#fff; }
.pg-btn:disabled { opacity:.35; cursor:not-allowed; }
.pg-info { font-size:10.5px; color:var(--text3); font-family:var(--mono); padding:0 3px; }
.ld-range { display:flex; align-items:center; gap:5px; }
.ld-range input { padding:5px 8px; font-size:11.5px; font-family:var(--mono); background:var(--input-bg); border:1.5px solid var(--input-border); border-radius:7px; color:var(--text); outline:none; width:148px; }
.ld-range input:focus { border-color:var(--red); }
.ld-range-sep { color:var(--text3); font-size:12px; }
</style>
</head>
<body class="dark-theme">

<header class="topbar">
  <a href="../../latest_data.php" class="topbar-brand">
    <?php if(defined('CUSTOM_LOGO_PATH')):?><img src="<?=htmlspecialchars(CUSTOM_LOGO_PATH,ENT_QUOTES,'UTF-8')?>" alt="Logo" class="custom-logo" onerror="this.style.display='none'"><?php endif;?>
    <span class="zabbix-logo">ZABBIX</span><span class="topbar-name">Report</span>
  </a>
  <span class="topbar-sep">|</span>
  <span class="topbar-sub">Detailed Host Inventory</span>
  <a href="../excel_export.php" class="btn-top" style="background:var(--green);color:#fff;border-color:var(--green);text-decoration:none">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>Excel Export
  </a>
  <a href="../../maintenances/" class="btn-top" style="background:#d97706;color:#fff;border-color:#d97706;text-decoration:none">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg>
    <?=t('maintenances_button','Mantenciones')?>
  </a>
  <div class="topbar-spacer"></div>
  <div class="topbar-actions">
    <button id="theme-toggle" class="btn-top">&#9788; Light</button>
    <a href="../../logout.php" class="btn-top danger">&#8594; <?=t('logout_button','Logout')?></a>
  </div>
</header>

<div class="page-root">

  <!-- FILTER BAR -->
  <div class="filter-card">
    <div class="filter-row">
      <span style="font-size:12px;font-weight:700;color:var(--text);margin-right:2px"><?=t('ld_filters','Filters')?></span>
      <div style="position:relative;flex:1;min-width:170px;max-width:250px">
        <input type="text" id="host-search" class="ld-search" placeholder="<?=t('ld_search_hosts','Search hosts...')?>" autocomplete="off">
        <div id="host-dropdown" style="display:none;position:fixed;z-index:9999;background:var(--card);border:1px solid var(--divider);border-radius:8px;max-height:220px;overflow-y:auto;box-shadow:0 4px 20px rgba(0,0,0,.4);min-width:200px;padding:4px 0"></div>
      </div>
      <div style="position:relative;flex:1;min-width:170px;max-width:250px">
        <input type="text" id="group-search" class="ld-search" placeholder="<?=t('ld_search_groups','Search groups...')?>" autocomplete="off">
        <div id="group-dropdown" style="display:none;position:fixed;z-index:9999;background:var(--card);border:1px solid var(--divider);border-radius:8px;max-height:220px;overflow-y:auto;box-shadow:0 4px 20px rgba(0,0,0,.4);min-width:200px;padding:4px 0"></div>
      </div>
      <div style="position:relative;flex:1;min-width:130px;max-width:190px">
        <input type="text" id="name-search" class="ld-search" placeholder="<?=t('ld_filter_items','Filter items...')?>" autocomplete="off" style="width:100%">
      </div>
      <button class="ld-btn ld-btn-primary" id="apply-filter-btn">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <?=t('ld_apply','Apply')?>
      </button>
      <button class="ld-btn ld-btn-ghost" id="clear-filter-btn"><?=t('ld_clear','Clear')?></button>
    </div>
    <div id="active-filters" style="display:none;padding:7px 16px;display:flex;gap:6px;flex-wrap:wrap;align-items:center">
      <span style="font-size:10.5px;color:var(--text3);font-weight:700;text-transform:uppercase;letter-spacing:.07em"><?=t('ld_active','ACTIVE:')?></span>
    </div>
  </div>

  <!-- WARNING: selección grande sin filtro de nombre -->
  <div id="dp-large-warn" style="display:none;align-items:flex-start;gap:10px;padding:11px 16px;margin-bottom:12px;background:rgba(234,179,8,.08);border:1px solid rgba(234,179,8,.35);border-radius:10px;font-size:13px;color:#ca8a04">
    <svg style="flex-shrink:0;margin-top:1px" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    <div>
      <strong>Large selection detected</strong> —
      Loading many groups without a keyword filter may be slow. For best results, type a keyword in <strong>"Filter items..."</strong> before clicking Apply (e.g. "CPU", "memory", "ping").
    </div>
    <button onclick="document.getElementById('dp-large-warn').style.display='none'" style="margin-left:auto;flex-shrink:0;background:none;border:none;cursor:pointer;color:#ca8a04;font-size:16px;line-height:1;padding:0">&#x2715;</button>
  </div>

  <!-- SPLIT PANELS -->
  <div class="split-panels">

    <!-- LEFT: Items table -->
    <div class="panel-card">
      <div class="panel-header">
        <div class="panel-title">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>
          Items <span id="badge-total" class="panel-badge">0</span>
        </div>
        <div style="display:flex;align-items:center;gap:7px;flex-wrap:wrap">
          <div class="ld-range">
            <input type="datetime-local" id="range-from" title="From">
            <span class="ld-range-sep">&#8594;</span>
            <input type="datetime-local" id="range-to" title="To">
            <button class="ld-btn ld-btn-ghost" id="btn-24h" style="font-size:11.5px;padding:5px 10px">24h</button>
          </div>
          <button class="ld-btn ld-btn-ghost" id="refresh-btn" style="padding:5px 9px" title="Refresh">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/></svg>
          </button>
        </div>
      </div>
      <div class="items-table-wrap">
        <table class="items-table">
          <colgroup><col style="width:36px"><col style="width:22%"><col style="width:48%"><col style="width:26%"></colgroup>
          <thead>
            <tr>
              <th class="td-check"><input type="checkbox" id="check-all" title="Select all"></th>
              <th data-sort="host" class="sorted-col"><?=t('ld_col_host','HOST')?> <span class="sort-arrow" id="arrow-host"></span></th>
              <th data-sort="name"><?=t('ld_col_item','ITEM')?> <span class="sort-arrow" id="arrow-name">&#9650;</span></th>
              <th><?=t('ld_col_value','LAST VALUE')?></th>
            </tr>
          </thead>
          <tbody id="table-body">
            <tr><td colspan="4" class="ld-empty">
              <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <?=t('ld_no_filter','Apply a filter to see data')?>
            </td></tr>
          </tbody>
        </table>
      </div>
      <div class="panel-footer">
        <span style="font-size:11px;color:var(--text3);font-family:var(--mono)">
          Total: <b id="total-count">&#8212;</b> &nbsp;|&nbsp; <span id="selected-count" style="color:var(--red);font-weight:700">0</span> selected
        </span>
        <div class="ld-pagination" id="pagination"></div>
        <span id="last-refresh" style="font-size:10.5px;color:var(--text3);font-family:var(--mono)"></span>
      </div>
    </div>

    <!-- RIGHT: Pivot preview -->
    <div class="panel-card" style="display:flex;flex-direction:column">
      <div class="panel-header">
        <div class="panel-title">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
          CSV Preview <span id="badge-cols" class="panel-badge green">0 cols</span>
        </div>
        <div style="display:flex;gap:7px;align-items:center">
          <button class="ld-btn ld-btn-ghost" id="btn-clear-pivot" style="font-size:11.5px;padding:5px 10px">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg> Clear
          </button>
          <button class="ld-btn ld-btn-green" id="btn-download-csv" disabled>
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> Download XLS
          </button>
        </div>
      </div>
      <div id="pivot-container" style="flex:1;overflow:hidden;display:flex;flex-direction:column">
        <div id="pivot-empty" class="pivot-empty">
          <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
          <div style="font-weight:600;color:var(--text2)">No columns yet</div>
          <div style="font-size:12px;max-width:240px;line-height:1.6">Load items on the left, then <strong>check</strong> the rows you want.<br>Each unique item name becomes one column.</div>
        </div>
        <div id="pivot-wrap" class="pivot-wrap" style="display:none;flex:1">
          <table class="pivot-table"><thead><tr id="pivot-thead-row"></tr></thead><tbody id="pivot-tbody"></tbody></table>
        </div>
      </div>
      <div class="panel-footer">
        <span style="font-size:11px;color:var(--text3);font-family:var(--mono)" id="pivot-stats">&#8212; hosts · &#8212; columns</span>
      </div>
    </div>

  </div>
</div>

<div style="text-align:center;padding:22px 20px 18px;font-family:var(--font);position:relative;z-index:1">
  <div style="font-size:12px;color:var(--text2);margin-bottom:10px"><?=t('common_author_credit')?></div>
</div>

<script>
var T = <?= json_encode($translations ?: (object)[]) ?>;

var _all=[], _filt=[], _fpage=1, _pp=50;
var _cols=[], _data={}, _hord=[];
var state={hostids:[],groupids:[],name:'',sort:'name',sortorder:'ASC',loading:false};

var tbody=document.getElementById('table-body');
var tcount=document.getElementById('total-count');
var pag=document.getElementById('pagination');
var lref=document.getElementById('last-refresh');
var chkall=document.getElementById('check-all');

// theme
(function(){
  var t=localStorage.getItem('zbx-theme')||'dark';
  document.body.className=t==='light'?'light-theme':'dark-theme';
  document.getElementById('theme-toggle').textContent=t==='light'?'\uD83C\uDF19 Dark':'\u2600 Light';
})();
document.getElementById('theme-toggle').addEventListener('click',function(){
  var cur=localStorage.getItem('zbx-theme')||'dark',nxt=cur==='dark'?'light':'dark';
  localStorage.setItem('zbx-theme',nxt);
  document.body.className=nxt==='light'?'light-theme':'dark-theme';
  this.textContent=nxt==='light'?'\uD83C\uDF19 Dark':'\u2600 Light';
});

function esc(s){return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function pad(n){return String(n).padStart(2,'0');}
function fmt(d){return d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate())+'T'+pad(d.getHours())+':'+pad(d.getMinutes());}

document.getElementById('btn-24h').addEventListener('click',function(){
  var n=new Date(),f=new Date(n-86400000);
  document.getElementById('range-from').value=fmt(f);
  document.getElementById('range-to').value=fmt(n);
});

// autocomplete
function setupAC(inpId,ddId,type,key){
  var inp=document.getElementById(inpId),dd=document.getElementById(ddId),tmr;
  function pos(){var r=inp.getBoundingClientRect();dd.style.top=(r.bottom+4)+'px';dd.style.left=r.left+'px';dd.style.width=r.width+'px';}
  function go(q){
    clearTimeout(tmr);
    tmr=setTimeout(function(){
      fetch('index.php?action=list_'+type+'&q='+encodeURIComponent(q))
        .then(function(r){return r.json();})
        .then(function(data){
          dd.innerHTML='';
          if(!data||!data.length){dd.style.display='none';return;}
          data.forEach(function(item){
            var id=type==='hosts'?item.hostid:item.groupid,name=item.name;
            if(state[key].find(function(x){return x.id===id;}))return;
            var div=document.createElement('div');
            div.style.cssText='padding:6px 10px;cursor:pointer;font-size:12.5px;font-family:var(--mono);color:var(--text);transition:background .1s;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;';
            div.textContent=name;
            div.onmouseenter=function(){div.style.background='var(--step-hover)';};
            div.onmouseleave=function(){div.style.background='';};
            div.onclick=function(){state[key].push({id:id,name:name});inp.value='';dd.style.display='none';renderTags();};
            dd.appendChild(div);
          });
          dd.style.display=dd.children.length?'block':'none';
        }).catch(function(){dd.style.display='none';});
    },q===''?0:300);
  }
  inp.addEventListener('input',function(){if(!this.value.trim()){dd.style.display='none';return;}pos();go(this.value.trim());});
  window.addEventListener('resize',function(){if(dd.style.display!=='none')pos();});
  document.addEventListener('click',function(e){if(!inp.contains(e.target)&&!dd.contains(e.target))dd.style.display='none';});
}
setupAC('host-search','host-dropdown','hosts','hostids');
setupAC('group-search','group-dropdown','groups','groupids');

function renderTags(){
  var c=document.getElementById('active-filters');
  Array.from(c.querySelectorAll('.filter-tag')).forEach(function(t){t.remove();});
  state.hostids.forEach(function(h){
    var tag=document.createElement('span');tag.className='filter-tag';
    tag.innerHTML='&#128444; '+esc(h.name)+' <span class="x">&#215;</span>';
    tag.querySelector('.x').onclick=function(){state.hostids=state.hostids.filter(function(x){return x.id!==h.id;});renderTags();};
    c.appendChild(tag);
  });
  state.groupids.forEach(function(g){
    var tag=document.createElement('span');tag.className='filter-tag';
    tag.innerHTML='&#9776; '+esc(g.name)+' <span class="x">&#215;</span>';
    tag.querySelector('.x').onclick=function(){state.groupids=state.groupids.filter(function(x){return x.id!==g.id;});renderTags();};
    c.appendChild(tag);
  });
  c.style.display=(state.hostids.length||state.groupids.length)?'flex':'none';
}

function loadData(){
  if(state.loading)return;
  state.loading=true;
  tbody.innerHTML='<tr><td colspan="4" class="ld-empty"><span class="ld-spinner"></span> Loading...</td></tr>';
  var p=new URLSearchParams({action:'refresh',hostids:state.hostids.map(function(h){return h.id;}).join(','),groupids:state.groupids.map(function(g){return g.id;}).join(','),sort:state.sort,sortorder:state.sortorder});
  fetch('index.php?'+p)
    .then(function(r){return r.json();})
    .then(function(data){
      state.loading=false;
      lref.textContent='Updated: '+new Date().toLocaleTimeString();
      _all=data.items||[];
      localFilter(1);
    })
    .catch(function(){
      state.loading=false;
      tbody.innerHTML='<tr><td colspan="4" class="ld-empty" style="color:var(--red)">Error loading data</td></tr>';
    });
}

function localFilter(page){
  _fpage=page||1;
  var q=document.getElementById('name-search').value.trim().toLowerCase();
  _filt=q?_all.filter(function(i){
    function wm(s){if(s.startsWith(q))return true;return s.split(/[\s\[\],.:_-]+/).some(function(w){return w.startsWith(q);});}
    return wm(i.name.toLowerCase())||wm((i.key_||'').toLowerCase());
  }):_all.slice();
  var start=(_fpage-1)*_pp;
  renderTable(_filt.slice(start,start+_pp));
  renderPag(_filt.length,_pp,_fpage);
  tcount.textContent=_filt.length;
  document.getElementById('badge-total').textContent=_filt.length;
}
document.getElementById('name-search').addEventListener('input',function(){if(_all.length)localFilter(1);});

// ── NORMALIZE / EXTRACT ──
function normName(n){var m=n.match(/^(?:.*\]:\s*)(.+)$/);return m?m[1].trim():n.trim();}
function extLabel(n){var m=n.match(/\[([^\]]+)\]:\s*.+$/);return m?m[1].trim():'';}
function cellDisp(e){return e?e.label?e.label+': '+e.value:e.value:'';}

// ── PIVOT ──
function addCell(host,raw,val){
  var col=normName(raw),lbl=extLabel(raw);
  if(_cols.indexOf(col)<0)_cols.push(col);
  if(!_data[host]){_data[host]={};_hord.push(host);}
  if(!_data[host][col])_data[host][col]=[];
  var dup=_data[host][col].some(function(e){return e.label===lbl&&e.value===val;});
  if(!dup)_data[host][col].push({label:lbl,value:val,raw:raw});
  renderPivot();
}
function remCell(host,raw){
  var col=normName(raw),lbl=extLabel(raw),val=null;
  tbody.querySelectorAll('input[type=checkbox]').forEach(function(cb){if(cb.dataset.host===host&&cb.dataset.name===raw)val=cb.dataset.value;});
  if(!_data[host]||!_data[host][col])return;
  _data[host][col]=_data[host][col].filter(function(e){return!(e.label===lbl&&e.value===val&&e.raw===raw);});
  if(!_data[host][col].length)delete _data[host][col];
  if(!Object.keys(_data[host]).length){delete _data[host];_hord=_hord.filter(function(h){return h!==host;});}
}
function syncCol(raw){
  var col=normName(raw);
  if(!Object.values(_data).some(function(c){return c.hasOwnProperty(col);}))
    _cols=_cols.filter(function(c){return c!==col;});
  renderPivot();
}
function remCol(col){
  _cols=_cols.filter(function(c){return c!==col;});
  Object.keys(_data).forEach(function(h){
    delete _data[h][col];
    if(!Object.keys(_data[h]).length){delete _data[h];_hord=_hord.filter(function(x){return x!==h;});}
  });
  tbody.querySelectorAll('input[type=checkbox]').forEach(function(cb){
    if(normName(cb.dataset.name)===col){cb.checked=false;cb.closest('tr').classList.remove('row-selected');}
  });
  renderPivot();updSel();
}

function buildRows(hosts,cols){
  var rows=[];
  hosts.forEach(function(host){
    var d=_data[host]||{},max=1;
    cols.forEach(function(c){var a=d[c];if(a&&a.length>max)max=a.length;});
    for(var r=0;r<max;r++){
      var cells={};
      cols.forEach(function(c){var a=d[c]||[];cells[c]=a[r]?cellDisp(a[r]):'';});
      rows.push({host:r===0?host:'',first:r===0,cells:cells});
    }
  });
  return rows;
}

function renderPivot(){
  var empty=document.getElementById('pivot-empty'),wrap=document.getElementById('pivot-wrap');
  var dl=document.getElementById('btn-download-csv'),badge=document.getElementById('badge-cols'),stats=document.getElementById('pivot-stats');
  var sh=_hord.slice().sort(function(a,b){return a.localeCompare(b,undefined,{sensitivity:'base'});});
  var sc=_cols.slice().sort(function(a,b){return a.localeCompare(b,undefined,{sensitivity:'base'});});
  if(!sc.length||!sh.length){
    empty.style.display='flex';wrap.style.display='none';dl.disabled=true;
    badge.textContent='0 cols';stats.textContent='\u2014 hosts \u00b7 \u2014 columns';return;
  }
  empty.style.display='none';wrap.style.display='block';dl.disabled=false;
  badge.textContent=sc.length+' col'+(sc.length>1?'s':'');
  stats.textContent=sh.length+' host'+(sh.length>1?'s':'')+' \u00b7 '+sc.length+' col'+(sc.length>1?'s':'');
  // header
  var thead=document.getElementById('pivot-thead-row');thead.innerHTML='';
  var th0=document.createElement('th');th0.textContent='Host';thead.appendChild(th0);
  sc.forEach(function(col){
    var th=document.createElement('th');
    var sp=document.createElement('span');sp.textContent=col;sp.title=col;sp.style.cssText='max-width:130px;overflow:hidden;text-overflow:ellipsis;display:inline-block;vertical-align:middle;';
    var rm=document.createElement('span');rm.className='col-remove';rm.textContent='\u00d7';rm.title='Remove';
    rm.onclick=function(){remCol(col);};
    th.appendChild(sp);th.appendChild(rm);thead.appendChild(th);
  });
  // body
  var tb=document.getElementById('pivot-tbody');tb.innerHTML='';
  buildRows(sh,sc).forEach(function(row,idx){
    var tr=document.createElement('tr');
    var td0=document.createElement('td');
    if(row.first){td0.textContent=row.host;td0.title=row.host;if(idx>0)tr.style.borderTop='2px solid var(--red-a18,rgba(224,60,60,.18))';}
    else{td0.innerHTML='<span style="color:var(--text3);font-size:11px;padding-left:10px">\u2514</span>';}
    tr.appendChild(td0);
    sc.forEach(function(col){
      var td=document.createElement('td');var v=row.cells[col];
      if(!v||v==='—'){td.textContent='\u2014';td.classList.add('pivot-cell-empty');}
      else{td.textContent=v;td.title=v;}
      tr.appendChild(td);
    });
    tb.appendChild(tr);
  });
}

function renderTable(items){
  if(!items.length){
    tbody.innerHTML='<tr><td colspan="4" class="ld-empty"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>No items found</td></tr>';
    updSel();return;
  }
  tbody.innerHTML='';
  items.forEach(function(item){
    var tr=document.createElement('tr');
    var col=normName(item.name);
    var isSel=!!(_data[item.host]&&_data[item.host][col]&&_data[item.host][col].some(function(e){return e.raw===item.name;}));
    if(isSel)tr.classList.add('row-selected');
    var vc=item.lastvalue==='—'?'no-val':'has-val';
    tr.innerHTML='<td class="td-check"><input type="checkbox" data-host="'+esc(item.host)+'" data-name="'+esc(item.name)+'" data-value="'+esc(item.lastvalue)+'" '+(isSel?'checked':'')+'/></td>'+
      '<td class="td-host" title="'+esc(item.host)+'">'+esc(item.host)+'</td>'+
      '<td class="td-name" title="'+esc(item.name)+'">'+esc(item.name)+'</td>'+
      '<td class="td-val '+vc+'">'+esc(item.lastvalue)+'</td>';
    tr.querySelector('input').addEventListener('change',function(){
      if(this.checked){addCell(item.host,item.name,item.lastvalue);tr.classList.add('row-selected');}
      else{remCell(item.host,item.name);tr.classList.remove('row-selected');syncCol(item.name);}
      updSel();
    });
    tbody.appendChild(tr);
  });
  updSel();
}

function renderPag(total,pp,cur){
  var pages=Math.ceil(total/pp);pag.innerHTML='';if(pages<=1)return;
  function btn(lbl,pg,act,dis){var b=document.createElement('button');b.className='pg-btn'+(act?' active':'');b.textContent=lbl;b.disabled=dis;if(!dis&&!act)b.onclick=function(){localFilter(pg);};pag.appendChild(b);}
  btn('\u00ab',cur-1,false,cur<=1);
  var s=Math.max(1,cur-2),e=Math.min(pages,s+4);
  for(var p=s;p<=e;p++)btn(p,p,p===cur,false);
  btn('\u00bb',cur+1,false,cur>=pages);
  var info=document.createElement('span');info.className='pg-info';info.textContent=(((cur-1)*pp)+1)+'-'+Math.min(cur*pp,total)+'/'+total;pag.appendChild(info);
}

function updSel(){
  var count=0;Object.values(_data).forEach(function(c){Object.values(c).forEach(function(a){count+=a.length;});});
  document.getElementById('selected-count').textContent=count;
  var cbs=Array.from(tbody.querySelectorAll('input[type=checkbox]'));
  var chk=cbs.filter(function(c){return c.checked;}).length;
  chkall.indeterminate=chk>0&&chk<cbs.length;
  chkall.checked=cbs.length>0&&chk===cbs.length;
}

chkall.addEventListener('change',function(){
  var checked=this.checked;
  tbody.querySelectorAll('input[type=checkbox]').forEach(function(cb){
    if(cb.checked!==checked){
      cb.checked=checked;var tr=cb.closest('tr');
      if(checked){addCell(cb.dataset.host,cb.dataset.name,cb.dataset.value);tr.classList.add('row-selected');}
      else{remCell(cb.dataset.host,cb.dataset.name);tr.classList.remove('row-selected');syncCol(cb.dataset.name);}
    }
  });
  updSel();
});

document.querySelectorAll('.items-table thead th[data-sort]').forEach(function(th){
  th.addEventListener('click',function(){
    var f=this.dataset.sort;
    if(state.sort===f){state.sortorder=state.sortorder==='ASC'?'DESC':'ASC';}else{state.sort=f;state.sortorder='ASC';}
    document.querySelectorAll('.items-table thead th[data-sort]').forEach(function(t){t.classList.remove('sorted-col');var a=t.querySelector('.sort-arrow');if(a)a.textContent='';});
    this.classList.add('sorted-col');var a=this.querySelector('.sort-arrow');if(a)a.textContent=state.sortorder==='ASC'?'\u25b2':'\u25bc';
    loadData();
  });
});

document.getElementById('apply-filter-btn').addEventListener('click',function(){
  state.name=document.getElementById('name-search').value.trim();

  // Warning si hay 3+ grupos o 50+ hosts sin filtro de nombre
  var groupCount=state.groupids.length;
  var hostCount=state.hostids.length;
  var hasName=state.name.length>0;
  var warnEl=document.getElementById('dp-large-warn');
  if(!hasName&&(groupCount>=3||hostCount>=50)){
    if(warnEl){warnEl.style.display='flex';setTimeout(function(){warnEl.style.display='none';},8000);}
  } else {
    if(warnEl)warnEl.style.display='none';
  }

  loadData();
});
document.getElementById('clear-filter-btn').addEventListener('click',function(){
  state.hostids=[];state.groupids=[];state.name='';
  ['host-search','group-search','name-search'].forEach(function(id){document.getElementById(id).value='';});
  _all=[];_filt=[];renderTags();
  tbody.innerHTML='<tr><td colspan="4" class="ld-empty"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>Apply a filter to see data</td></tr>';
  tcount.textContent='\u2014';pag.innerHTML='';
  document.getElementById('badge-total').textContent='0';updSel();
});
document.getElementById('refresh-btn').addEventListener('click',function(){loadData();});

document.getElementById('btn-clear-pivot').addEventListener('click',function(){
  _cols=[];_data={};_hord=[];
  tbody.querySelectorAll('input[type=checkbox]').forEach(function(cb){cb.checked=false;cb.closest('tr').classList.remove('row-selected');});
  chkall.checked=false;chkall.indeterminate=false;
  renderPivot();updSel();
});

// Styled Excel download (HTML → .xls, Excel opens with full formatting)
document.getElementById('btn-download-csv').addEventListener('click',function(){
  if(!_cols.length||!_hord.length)return;

  var sh=_hord.slice().sort(function(a,b){return a.localeCompare(b,undefined,{sensitivity:'base'});});
  var sc=_cols.slice().sort(function(a,b){return a.localeCompare(b,undefined,{sensitivity:'base'});});
  var now=new Date();
  var from=(document.getElementById('range-from').value||'').replace('T',' ');
  var to=(document.getElementById('range-to').value||'').replace('T',' ');
  var ts=now.toLocaleDateString()+' '+now.toLocaleTimeString();

  function h(s){return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

  var totalCols=sc.length+1; // Host + data cols

  var html='<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
  html+='<head><meta charset="UTF-8">';
  html+='<!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>';
  html+='<x:Name>Inventario</x:Name><x:WorksheetOptions><x:FreezePanes/><x:FrozenNoSplit/>';
  html+='<x:SplitHorizontal>4</x:SplitHorizontal><x:TopRowBottomPane>4</x:TopRowBottomPane>';
  html+='<x:ActivePane>2</x:ActivePane></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->';
  html+='<style>';
  html+='body{font-family:Calibri,Arial,sans-serif;font-size:10pt;}';
  html+='table{border-collapse:collapse;width:100%;}';
  html+='.t{background:#1F3864;color:#fff;font-size:13pt;font-weight:bold;padding:8px 12px;}';
  html+='.m{background:#ECF0F1;color:#6C757D;font-size:9pt;font-style:italic;padding:4px 12px;}';
  html+='.sp{background:#fff;padding:3px;}';
  html+='.h{background:#C0392B;color:#fff;font-weight:bold;font-size:10pt;padding:6px 10px;border:1px solid #922B21;}';
  html+='.r1{background:#FADBD8;font-weight:bold;color:#7B241C;padding:5px 10px;border-top:2px solid #C0392B;border:1px solid #D5D8DC;vertical-align:middle;}';
  html+='.r2{background:#F2F3F4;color:#6C757D;font-style:italic;padding:5px 10px;border:1px solid #D5D8DC;vertical-align:middle;}';
  html+='.d1{background:#FADBD8;padding:5px 10px;border-top:2px solid #C0392B;border:1px solid #D5D8DC;vertical-align:middle;}';
  html+='.d2{background:#F2F3F4;padding:5px 10px;border:1px solid #D5D8DC;vertical-align:middle;}';
  html+='.em{color:#999;font-style:italic;}';
  html+='</style></head><body><table>';

  // Title row
  html+='<tr><td class="t" colspan="'+totalCols+'">Reporte Data Preview &mdash; Zabbix Report</td></tr>';

  // Metadata row
  var metaTxt='Generado: '+h(ts)+(from?'&nbsp;&nbsp;&nbsp;&nbsp;Rango: '+h(from)+' &rarr; '+h(to):'');
  html+='<tr><td class="m" colspan="'+totalCols+'">'+metaTxt+'</td></tr>';

  // Spacer
  html+='<tr><td class="sp" colspan="'+totalCols+'">&nbsp;</td></tr>';

  // Header row
  html+='<tr><td class="h">Host</td>';
  sc.forEach(function(col){html+='<td class="h">'+h(col)+'</td>';});
  html+='</tr>';

  // Data rows with host merging
  var rows=buildRows(sh,sc);

  // Pre-calculate rowspan for each host group
  var hostSpan={};
  rows.forEach(function(row){
    if(row.first)hostSpan[row.host]=0;
    hostSpan[row.host]++;
  });
  var hostEmitted={};

  rows.forEach(function(row){
    var isFirst=row.first;
    html+='<tr>';
    // Host cell — only emit on first row with rowspan
    if(isFirst){
      var span=hostSpan[row.host];
      html+='<td class="r1" rowspan="'+span+'" style="border-top:2px solid #C0392B">'+h(row.host)+'</td>';
      hostEmitted[row.host]=true;
    }
    // Data cells
    sc.forEach(function(col){
      var v=row.cells[col];
      if(!v||v==='\u2014'||v==='—'){
        html+='<td class="'+(isFirst?'d1':'d2')+'" style="color:#bbb">&mdash;</td>';
      } else {
        html+='<td class="'+(isFirst?'d1':'d2')+'">'+h(v)+'</td>';
      }
    });
    html+='</tr>';
  });

  html+='</table></body></html>';

  var ds=now.getFullYear()+''+pad(now.getMonth()+1)+''+pad(now.getDate());
  var blob=new Blob([html],{type:'application/vnd.ms-excel;charset=utf-8'});
  var url=URL.createObjectURL(blob),a=document.createElement('a');
  a.href=url;a.download='zabbix_inventory_'+ds+'.xls';
  document.body.appendChild(a);a.click();document.body.removeChild(a);URL.revokeObjectURL(url);
});

function ce(v){var s=String(v==null?'':v);if(/[";,\r\n%]/.test(s)||/[a-zA-Z]/.test(s))return '"'+s.replace(/"/g,'""')+'"';return s;}
</script>
</body>
</html>
