<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

set_time_limit(600);
ini_set('memory_limit', '512M');

session_start();

if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) { 
    http_response_code(403); 
    die('Error: Invalid CSRF token.'); 
}
unset($_SESSION['csrf_token']); 

if (empty($_SESSION['zbx_auth_ok'])) { http_response_code(403); die('Invalid session'); }

require_once __DIR__ . '/../../lib/i18n.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/ZabbixApiFactory.php';

// Funciones Helper
function formatDuration(int $seconds): string {
    if ($seconds <= 0) return '0s';
    $parts = [];
    $d = floor($seconds / 86400);
    if ($d > 0) $parts[] = "{$d}d";
    $h = floor(($seconds % 86400) / 3600);
    if ($h > 0) $parts[] = "{$h}h";
    $m = floor(($seconds % 3600) / 60);
    if ($m > 0) $parts[] = "{$m}m";
    $s = $seconds % 60;
    if ($s > 0 && empty($parts)) $parts[] = "{$s}s"; 
    return empty($parts) ? '0s' : implode(' ', $parts);
}

function getSeverityName(int $severity): string {
    global $translations;
    $severities = [0 => 'severity_0', 1 => 'severity_1', 2 => 'severity_2', 3 => 'severity_3', 4 => 'severity_4', 5 => 'severity_5'];
    $key = $severities[$severity] ?? 'severity_0';
    return t($key);
}

function outputCsv(string $filename, array $headers, array $data, array $preHeader = []): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    if (!empty($preHeader)) {
        fputcsv($output, $preHeader, ';');
        fputcsv($output, [], ';');
    }
    fputcsv($output, [t('sla_report_header_csv')], ';');
    fputcsv($output, $headers, ';');

    $host_col  = t('excel_header_host');
    $sla_col2  = t('sla_col_real_sla');
    $down_col2 = t('sla_col_downtime');
    $cum_col   = t('sla_col_complies');
    $prob_col2 = t('export_col_problem');
    $sev_col2  = t('export_col_severity');
    $start_col2= t('sla_col_down_start');
    $end_col2  = t('sla_col_down_end');
    $dur_col2  = t('export_col_duration');

    foreach ($data as $row) {
        $evs = $row['_events'] ?? [];
        if (empty($evs)) {
            $ordered = [];
            foreach ($headers as $h) { $ordered[] = $row[$h] ?? ''; }
            fputcsv($output, $ordered, ';');
        } else {
            foreach ($evs as $ei => $ev) {
                $ordered = [];
                foreach ($headers as $h) {
                    if ($ei === 0) {
                        if ($h === $prob_col2)      $ordered[] = $ev['problem'];
                        elseif ($h === $sev_col2)   $ordered[] = $ev['severity'];
                        elseif ($h === $start_col2) $ordered[] = $ev['start'];
                        elseif ($h === $end_col2)   $ordered[] = $ev['end'];
                        elseif ($h === $dur_col2)   $ordered[] = $ev['duration'];
                        else                        $ordered[] = $row[$h] ?? '';
                    } else {
                        if (in_array($h, [$host_col, $sla_col2, $down_col2, $cum_col]))
                            $ordered[] = '';
                        elseif ($h === $prob_col2)      $ordered[] = $ev['problem'];
                        elseif ($h === $sev_col2)       $ordered[] = $ev['severity'];
                        elseif ($h === $start_col2)     $ordered[] = $ev['start'];
                        elseif ($h === $end_col2)       $ordered[] = $ev['end'];
                        elseif ($h === $dur_col2)       $ordered[] = $ev['duration'];
                        else                            $ordered[] = '';
                    }
                }
                fputcsv($output, $ordered, ';');
            }
        }
    }
    fclose($output);
    exit;
}

function outputHtml(string $title, array $headers, array $data, array $preHeader = []): void {
    $sla_col   = t('sla_col_complies');
    $down_col  = t('sla_col_downtime');
    $na        = t('sla_val_na');
    $nd        = t('sla_val_nd');
    $yes       = t('sla_val_yes');
    $total     = count($data);
    $passing = 0;
    foreach ($data as $_r) { if (($_r[$sla_col] ?? '') === $yes) $passing++; }
    $failing   = $total - $passing;

    echo '<!DOCTYPE html><html lang="' . htmlspecialchars(t('lang_code')) . '">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>' . htmlspecialchars($title) . '</title>
<style>
@import url("https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap");
:root{--red:#e03c3c;--green:#16a34a;--amber:#d97706;--font:"Inter",system-ui,sans-serif;--mono:"Courier New",monospace;}
body.dark{--bg:#0f1117;--card:#1c2030;--card2:#171b28;--border:#2a3050;--text:#e8ecf6;--text2:#8892b0;--text3:#444e68;--input:#171b28;}
body.light{--bg:#eef0f5;--card:#ffffff;--card2:#f0f2f8;--border:#e2e6ef;--text:#0f1117;--text2:#4a5468;--text3:#8892a8;--input:#ffffff;}
body{
  background-color:var(--bg);
  background-image:url("../assets/background/bg.jpg");
  background-size:cover;
  background-position:center;
  background-attachment:fixed;
  color:var(--text);
}
body::before{
  content:"";
  position:fixed;inset:0;z-index:0;
  background:rgba(0,0,0,0.45);
  pointer-events:none;
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);font-size:14px;line-height:1.55;min-height:100vh;padding:0 0 48px}
.topbar,.wrap{position:relative;z-index:1;}
.topbar{background:var(--card2);border-bottom:1px solid var(--border);padding:0 28px;height:54px;display:flex;align-items:center;gap:14px;position:sticky;top:0;z-index:100;backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px)}
.zbx-badge{background:var(--red);color:#fff;padding:4px 10px;border-radius:6px;font-weight:700;font-size:13px}
.topbar-title{font-weight:600;font-size:15px;color:var(--text)}
.topbar-sep{color:#444e68;font-size:18px}
.topbar-sub{font-size:12px;color:var(--text2);font-family:var(--mono)}
.topbar-space{flex:1}
.btn-back{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:7px;background:var(--card2);color:var(--text2);border:1px solid var(--border);text-decoration:none;font-size:12px;font-weight:600;transition:all .15s}
.btn-back:hover{border-color:var(--red);color:var(--red)}
.wrap{max-width:1200px;margin:0 auto;padding:28px 20px}
/* title/sub siempre blancos sobre el background */
.page-title{font-size:20px;font-weight:700;margin-bottom:4px;color:#fff;text-shadow:0 2px 8px rgba(0,0,0,.6)}
.page-sub{font-size:12px;color:rgba(255,255,255,.75);font-family:var(--mono);margin-bottom:24px;text-shadow:0 1px 4px rgba(0,0,0,.5)}
.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:24px}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:18px 20px;backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px)}
.stat-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text2);margin-bottom:6px}
.stat-value{font-size:26px;font-weight:700}
.stat-value.total{color:var(--text)}
.stat-value.pass{color:var(--green)}
.stat-value.fail{color:var(--red)}
.table-wrap{background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden;backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px)}
.table-header{padding:16px 20px;border-bottom:1px solid var(--border);background:var(--card2);display:flex;align-items:center;justify-content:space-between}
.table-title{font-size:13px;font-weight:700;color:var(--text)}
.table-count{font-size:12px;color:var(--text2);font-family:var(--mono)}
table{width:100%;border-collapse:collapse}
thead th{padding:10px 14px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text2);border-bottom:1px solid var(--border);background:var(--card2);white-space:nowrap}
tbody tr{border-bottom:1px solid var(--border);transition:background .1s}
tbody tr:last-child{border-bottom:none}
tbody tr:hover{background:var(--card2)}
tbody td{padding:10px 14px;font-size:13px;color:var(--text);vertical-align:top}
td.val-pass{color:var(--green);font-weight:600}
td.val-fail{color:var(--red);font-weight:600}
td.val-warn{color:var(--amber);font-weight:600}
td.mono{font-family:var(--mono);font-size:12px;color:var(--text2)}
.badge{display:inline-flex;align-items:center;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:700;font-family:var(--mono)}
.badge-pass{background:rgba(22,163,74,.12);color:var(--green);border:1px solid rgba(22,163,74,.3)}
.badge-fail{background:rgba(224,60,60,.12);color:var(--red);border:1px solid rgba(224,60,60,.3)}
.badge-na{background:rgba(136,146,176,.1);color:#8892b0;border:1px solid rgba(136,146,176,.2)}
.badge-active{background:rgba(217,119,6,.12);color:var(--amber);border:1px solid rgba(217,119,6,.3)}
.empty-state{text-align:center;padding:48px 20px;color:var(--text2)}
@media print{
  body{background:#fff;color:#111}
  .topbar{display:none}
  .wrap{padding:0}
  .stats{break-inside:avoid}
  .stat-card{background:#f5f5f5;border-color:#ddd;color:#111}
  .stat-value.total{color:#111}
  .stat-value.pass{color:#15803d}
  .stat-value.fail{color:#c42e2e}
  .table-wrap{border-color:#ddd;break-inside:auto}
  .table-header{background:#f5f5f5;color:#111}
  thead{display:table-header-group}
  thead th{background:#f5f5f5;color:#555}
  tbody tr{break-inside:avoid;border-bottom-color:#eee}
  tbody tr:hover{background:transparent}
  tbody td{color:#111}
  td.val-pass{color:#15803d}
  td.val-fail{color:#c42e2e}
  td.mono{color:#555}
  .badge-pass{background:#dcfce7;color:#15803d;border-color:#86efac}
  .badge-fail{background:#fee2e2;color:#c42e2e;border-color:#fca5a5}
  .badge-na{background:#f3f4f6;color:#555;border-color:#d1d5db}
  .badge-active{background:#fef3c7;color:#92400e;border-color:#fcd34d}
  .btn-back{display:none}
}
</style>
</head>
<body>
<div class="topbar">
  <span class="zbx-badge">ZABBIX</span>
  <span class="topbar-sep">|</span>
  <span class="topbar-title">Report</span>
  <span class="topbar-sep">|</span>
  <span class="topbar-sub">' . htmlspecialchars($title) . '</span>
  <div class="topbar-space"></div>
  <a href="index.php" class="btn-back">&#8592; ' . t('sla_back_link') . '</a>
  <button id="theme-btn" onclick="toggleTheme()" style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:7px;border:1px solid var(--border);background:var(--card2);color:var(--text2);font-size:12px;font-weight:600;cursor:pointer;font-family:var(--font)">&#9788; Light</button>
</div>
<div class="wrap">
  <div class="page-title">' . htmlspecialchars($title) . '</div>';

    if (!empty($preHeader)) {
        echo '<div class="page-sub">' . htmlspecialchars($preHeader[1]) . ' &nbsp;&#8594;&nbsp; ' . htmlspecialchars($preHeader[2]) . '</div>';
    }

    echo '<div class="stats">
    <div class="stat-card"><div class="stat-label">' . t('sla_stat_total', 'Total Hosts') . '</div><div class="stat-value total">' . $total . '</div></div>
    <div class="stat-card"><div class="stat-label">' . t('sla_stat_pass', 'Complying') . '</div><div class="stat-value pass">' . $passing . '</div></div>
    <div class="stat-card"><div class="stat-label">' . t('sla_stat_fail', 'Not complying') . '</div><div class="stat-value fail">' . $failing . '</div></div>
  </div>
  <div class="table-wrap">
    <div class="table-header">
      <span class="table-title">' . t('sla_html_title_icmp') . '</span>
      <span class="table-count">' . $total . ' ' . t('sla_stat_total', 'hosts') . '</span>
    </div>
    <table><thead><tr>';

    foreach ($headers as $h) echo '<th>' . htmlspecialchars($h) . '</th>';
    echo '</tr></thead><tbody>';

    if (empty($data)) {
        echo '<tr><td colspan="' . count($headers) . '" class="empty-state">' . t('sla_report_no_hosts') . '</td></tr>';
    }

    $sla_real_col = t('sla_col_real_sla');
    $prob_col     = t('export_col_problem');
    $sev_col      = t('export_col_severity');
    $start_col    = t('sla_col_down_start');
    $end_col      = t('sla_col_down_end');
    $dur_col      = t('export_col_duration');
    $ncols        = count($headers);
    $sla_target_v = (float)($_POST['sla_target'] ?? 99.9);

    $host_col = t('excel_header_host');

    foreach ($data as $row) {
        $events    = $row['_events'] ?? [];
        $hasEvents = !empty($events);
        $hostName  = (string)($row[$host_col] ?? '');

        // ── Calcular valores ────────────────────────────────────────────────
        $slaVal = (string)($row[$sla_real_col] ?? '');
        $slaNum = (float)str_replace([',','%'], ['.',''], $slaVal);
        $slaCls = $slaNum >= $sla_target_v ? ' val-pass' : ' val-fail';
        $dtVal  = (string)($row[$down_col] ?? '');
        $dtCls  = ($dtVal !== '0s' && $dtVal !== $na && $dtVal !== $nd) ? ' val-fail' : '';
        $cumVal = (string)($row[$sla_col] ?? '');

        if (!$hasEvents) {
            // Host sin incidentes: fila simple
            echo '<tr class="host-row">';
            echo '<td style="font-weight:600">' . htmlspecialchars($hostName) . '</td>';
            echo '<td class="mono' . $slaCls . '">' . htmlspecialchars($slaVal) . '</td>';
            echo '<td class="mono' . $dtCls . '">' . htmlspecialchars($dtVal) . '</td>';
            if ($cumVal === $yes)                    echo '<td><span class="badge badge-pass">' . htmlspecialchars($cumVal) . '</span></td>';
            elseif ($cumVal === $na || $cumVal === $nd) echo '<td><span class="badge badge-na">' . htmlspecialchars($cumVal) . '</span></td>';
            else                                      echo '<td><span class="badge badge-fail">' . htmlspecialchars($cumVal) . '</span></td>';
            echo '<td></td><td></td><td></td><td></td><td></td>';
            echo '</tr>';
        }

        // ── Eventos en la misma fila (primero) o filas siguientes ──────────
        // Reescribir la fila del host para incluir el primer evento inline
        // y agregar filas adicionales para los siguientes eventos
        if ($hasEvents) {
            // Cerrar el </tr> ya puesto y rehacer con evento inline
            // En realidad ya cerramos el tr arriba, hacer filas extra con colspan para host
            foreach ($events as $idx => $ev) {
                echo '<tr style="background:var(--card2)">';
                if ($idx === 0) {
                    // Primera fila: repetir host con rowspan si hay varios eventos
                    $rc = count($events);
                    echo '<td style="font-weight:600;vertical-align:top;padding-top:10px" rowspan="' . $rc . '">' . htmlspecialchars($hostName) . '</td>';
                    echo '<td class="mono' . $slaCls . '" style="vertical-align:top;padding-top:10px" rowspan="' . $rc . '">' . htmlspecialchars($slaVal) . '</td>';
                    echo '<td class="mono' . $dtCls . '" style="vertical-align:top;padding-top:10px" rowspan="' . $rc . '">' . htmlspecialchars($dtVal) . '</td>';
                    echo '<td style="vertical-align:top;padding-top:8px" rowspan="' . $rc . '">';
                    if ($cumVal === $yes)                      echo '<span class="badge badge-pass">' . htmlspecialchars($cumVal) . '</span>';
                    elseif ($cumVal === $na || $cumVal === $nd) echo '<span class="badge badge-na">' . htmlspecialchars($cumVal) . '</span>';
                    else                                       echo '<span class="badge badge-fail">' . htmlspecialchars($cumVal) . '</span>';
                    echo '</td>';
                }
                // Columnas del evento
                echo '<td style="padding:8px 14px;color:var(--text);font-size:13px">' . htmlspecialchars($ev['problem']) . '</td>';
                echo '<td style="padding:8px 14px;color:var(--text2);font-size:12px">' . htmlspecialchars($ev['severity']) . '</td>';
                echo '<td style="padding:8px 14px;font-family:var(--mono);font-size:12px;color:var(--text2)">' . htmlspecialchars($ev['start']) . '</td>';
                if ($ev['is_active'])
                    echo '<td style="padding:8px 14px"><span class="badge badge-active">' . htmlspecialchars($ev['end']) . '</span></td>';
                else
                    echo '<td style="padding:8px 14px;font-family:var(--mono);font-size:12px;color:var(--text2)">' . htmlspecialchars($ev['end']) . '</td>';
                echo '<td style="padding:8px 14px;font-family:var(--mono);font-size:12px;color:var(--red);font-weight:600">' . htmlspecialchars($ev['duration']) . '</td>';
                echo '</tr>';
            }
        }
    }
    echo '</tbody></table></div></div>';
echo '<script>';
echo 'function toggleTheme(){';
echo '  var b=document.body,btn=document.getElementById("theme-btn");';
echo '  if(b.classList.contains("dark")){b.classList.replace("dark","light");btn.textContent="Dark";localStorage.setItem("sla-theme","light");}';
echo '  else{b.classList.replace("light","dark");btn.textContent="Light";localStorage.setItem("sla-theme","dark");}';
echo '}';
echo '(function(){';
echo '  var saved=localStorage.getItem("sla-theme")||"dark";';
echo '  document.body.className=saved;';
echo '  var tbtn=document.getElementById("theme-btn");';
echo '  if(tbtn) tbtn.textContent=saved==="dark"?"Light":"Dark";';
echo '})();';
echo '</script>';
echo '</body></html>';
    exit;
}

function collect_values(array $names): array {
    $vals = [];
    foreach ($names as $n) {
        if (isset($_POST[$n])) $vals[] = $_POST[$n];
    }
    $flat = [];
    foreach ($vals as $v) {
        if (is_array($v)) { $flat = array_merge($flat, $v); }
        else { $flat[] = $v; }
    }
    $out = [];
    foreach ($flat as $x) {
        $x = is_string($x) ? trim($x) : null;
        if ($x !== null && $x !== '') $out[] = $x;
    }
    return array_map('strval', $out);
}

try {
    $apiOptions = ['timeout' => 290, 'verify_ssl' => (defined('VERIFY_SSL') ? (bool)VERIFY_SSL : false)];
    
    $api = ZabbixApiFactory::create(
        ZABBIX_API_URL, 
        $_SESSION['zbx_user'], 
        $_SESSION['zbx_pass'], 
        $apiOptions
    );

    $sla_target = isset($_POST['sla_target']) ? (float)$_POST['sla_target'] : 99.9;
    $output_format = $_POST['output_format'] ?? 'view';
    $from_dt_str = $_POST['from_dt'] ?? '';
    $to_dt_str = $_POST['to_dt'] ?? '';

    // Parsear fechas usando la timezone del browser (client_tz)
    // Si no viene, usar ZABBIX_TZ como fallback
    $client_tz = trim($_POST['client_tz'] ?? '');
    $zbx_tz    = defined('ZABBIX_TZ') ? ZABBIX_TZ : 'UTC';
    $parse_tz  = !empty($client_tz) ? $client_tz : $zbx_tz;
    try { $tz_obj = new DateTimeZone($parse_tz); } catch (Exception $e) {
        try { $tz_obj = new DateTimeZone($zbx_tz); } catch (Exception $e2) { $tz_obj = new DateTimeZone('UTC'); }
    }
    $from_ts = ($from_dt_str && ($d = DateTime::createFromFormat('Y-m-d\TH:i', $from_dt_str, $tz_obj))) ? $d->getTimestamp() : (time() - 86400);
    $to_ts   = ($to_dt_str   && ($d = DateTime::createFromFormat('Y-m-d\TH:i', $to_dt_str,   $tz_obj))) ? $d->getTimestamp() : time();
    if ($to_ts <= $from_ts) { throw new RuntimeException(t('sla_err_invalid_range')); }

    $total_period_sec = $to_ts - $from_ts;
    $preHeader = [t('export_time_range'), t('sla_form_from') . ": " . date('Y-m-d H:i', $from_ts), t('sla_form_to') . ": " . date('Y-m-d H:i', $to_ts)];

    // Obtener Host IDs
    $hostIdsFromModal = isset($_POST['hostids']) ? array_filter(explode(',', (string)$_POST['hostids'])) : [];
    $manualHostNames  = collect_values(['hostnames']);
    $hostGroupIdsFromModal = isset($_POST['hostgroupids']) ? array_filter(explode(',', (string)$_POST['hostgroupids'])) : [];
    $manualGroupNames = collect_values(['hostgroups']);
    $hostIdsFromNames = [];
    
    if (!empty($manualHostNames)) {
        $hostMap = $api->getHostsByNames($manualHostNames);
        $hostIdsFromNames = array_values($hostMap);
    }
    
    $groupIdsFromNames = [];
    if (!empty($manualGroupNames)) {
        $groups = $api->call('hostgroup.get', ['output' => ['groupid'],'filter' => ['name' => $manualGroupNames]]);
        if (is_array($groups)) $groupIdsFromNames = array_column($groups, 'groupid');
    }
    $finalGroupIds = array_unique(array_merge($hostGroupIdsFromModal, $groupIdsFromNames));
    $hostIdsFromGroups = [];
    if (!empty($finalGroupIds)) {
        $hostsFromGroups = $api->getHostIdsByGroupIds($finalGroupIds);
        $hostIdsFromGroups = $hostsFromGroups;
    }
    $hostIds = array_values(array_unique(array_merge($hostIdsFromModal, $hostIdsFromNames, $hostIdsFromGroups)));
    if (empty($hostIds)) { throw new RuntimeException(t('excel_err_no_hosts')); }
    
    $hosts_info = $api->call('host.get', ['output' => ['hostid','name'], 'hostids' => $hostIds]);
    $host_map = [];
    if (is_array($hosts_info)) {
        foreach ($hosts_info as $h) { $host_map[$h['hostid']] = $h['name'] ?? $h['hostid']; }
    }

    // Detectar versión de Zabbix para compatibilidad
    $zbx_ver = $_SESSION['zabbix_version'] ?? '6.0.0';
    $is_v60  = version_compare($zbx_ver, '6.2', '<');

    // Obtener Triggers ICMP de los hosts
    $ping_items = $api->call('item.get', [
        'output'  => ['itemid'],
        'hostids' => $hostIds,
        'filter'  => ['key_' => 'icmpping'],
    ]);
    $ping_item_ids = is_array($ping_items) ? array_column($ping_items, 'itemid') : [];

    $triggers = [];
    if (!empty($ping_item_ids)) {
        $triggers = $api->call('trigger.get', [
            'output'       => ['triggerid', 'description', 'priority'],
            'itemids'      => $ping_item_ids,
            'selectHosts'  => ['hostid', 'name']
        ]);
    }

    $trigger_map = [];
    $trigger_ids = [];
    if (is_array($triggers)) {
        foreach ($triggers as $t) {
            $hostId = $t['hosts'][0]['hostid'] ?? 0;
            if ($hostId == 0) continue;
            $trigger_map[$t['triggerid']] = [
                'hostid'    => $hostId,
                'host_name' => $t['hosts'][0]['name'],
                'problem'   => $t['description'],
                'severity'  => (int)$t['priority']
            ];
            $trigger_ids[] = $t['triggerid'];
        }
    }

    $events       = [];
    $recoveryEvents = [];


    if (!empty($trigger_ids)) {
        if ($is_v60) {
            // ── Zabbix 6.0: combinar problem.get + event.get ───────────────
            // problem.get puede no retornar todos los eventos según el tipo de host

            // 1. problem.get — problemas activos y resueltos dentro del rango
            $problems_raw = $api->call('problem.get', [
                'output'    => ['eventid', 'objectid', 'clock', 'r_eventid'],
                'objectids' => $trigger_ids,
                'time_from' => $from_ts - (30 * 86400),
                'time_till' => $to_ts,
                'source'    => 0,
                'object'    => 0
            ]);

            // 2. problem.get sin filtro de tiempo — problemas actualmente activos
            $active_problems = $api->call('problem.get', [
                'output'    => ['eventid', 'objectid', 'clock', 'r_eventid'],
                'objectids' => $trigger_ids,
                'source'    => 0,
                'object'    => 0
            ]);

            // 3. event.get con rango — eventos resueltos del período
            $events_raw = $api->call('event.get', [
                'output'    => ['eventid', 'objectid', 'clock', 'r_eventid'],
                'objectids' => $trigger_ids,
                'time_from' => $from_ts - (30 * 86400),
                'time_till' => $to_ts,
                'source'    => 0,
                'object'    => 0,
                'value'     => 1,
                'sortfield' => ['clock'],
                'sortorder' => 'ASC'
            ]);

            // 4. event.get sin time_till — eventos activos ahora (no tienen r_eventid)
            $events_active = $api->call('event.get', [
                'output'    => ['eventid', 'objectid', 'clock', 'r_eventid'],
                'objectids' => $trigger_ids,
                'time_from' => $from_ts - (30 * 86400),
                'source'    => 0,
                'object'    => 0,
                'value'     => 1,
                'sortfield' => ['clock'],
                'sortorder' => 'ASC'
            ]);

            // Unir y deduplicar todas las fuentes
            $all_problems = [];
            $seen_eids = [];
            foreach (array_merge(
                is_array($problems_raw)    ? $problems_raw    : [],
                is_array($active_problems) ? $active_problems : [],
                is_array($events_raw)      ? $events_raw      : [],
                is_array($events_active)   ? $events_active   : []
            ) as $p) {
                if (!isset($seen_eids[$p['eventid']])) {
                    $all_problems[]           = $p;
                    $seen_eids[$p['eventid']] = true;
                }
            }

            if (is_array($events_active)) foreach ($events_active as $ev)
                if (is_array($events_raw)) foreach ($events_raw as $ev)
                if (is_array($problems_raw)) foreach ($problems_raw as $p)
    
            // Construir mapa eventid->r_eventid desde problem.get (más fiable en 6.0)
            $prob_recovery_map = [];
            foreach (array_merge(
                is_array($problems_raw)    ? $problems_raw    : [],
                is_array($active_problems) ? $active_problems : []
            ) as $p) {
                $reid = (int)($p['r_eventid'] ?? 0);
                if ($reid > 0) {
                    $prob_recovery_map[$p['eventid']] = $reid;
                }
            }

            // Convertir a formato de events, rellenando r_eventid faltante
            foreach ($all_problems as $p) {
                $ev_start = (int)$p['clock'];
                // r_eventid: usar el de problem.get si event.get no lo trajo
                $r_eid = (int)($p['r_eventid'] ?? 0);
                if ($r_eid === 0 && isset($prob_recovery_map[$p['eventid']])) {
                    $r_eid = $prob_recovery_map[$p['eventid']];
                }
                // Incluir si:
                // - Empezó antes del fin del período (caso normal), O
                // - Está activo ahora (r_eid=0) y empezó antes de now (cubre eventos muy recientes)
                $is_active_now = ($r_eid === 0);
                if ($ev_start <= $to_ts || ($is_active_now && $ev_start <= time())) {
                    $events[] = [
                        'eventid'   => $p['eventid'],
                        'objectid'  => $p['objectid'],
                        'clock'     => $p['clock'],
                        'r_eventid' => $r_eid
                    ];
                }
            }

            // Obtener timestamps de recovery
            $r_eids = array_filter(array_unique(array_column($events, 'r_eventid')));
            if (!empty($r_eids)) {
                $r_data = $api->call('event.get', [
                    'output'   => ['eventid', 'clock'],
                    'eventids' => array_values($r_eids)
                ]);
                if (is_array($r_data)) {
                    foreach ($r_data as $re) {
                        $recoveryEvents[$re['eventid']] = (int)$re['clock'];
                    }
                }
            }
        } else {
            // ── Zabbix 6.2+ : flujo original con event.get ─────────────────
            $event_params = [
                'output'     => ['eventid', 'objectid', 'clock', 'r_eventid'],
                'objectids'  => $trigger_ids,
                'time_from'  => $from_ts - (30 * 86400),
                'time_till'  => $to_ts,
                'source'     => 0,
                'object'     => 0,
                'value'      => 1,
                'suppressed' => false,
                'sortfield'  => ['clock'],
                'sortorder'  => 'ASC'
            ];
            $events = $api->call('event.get', $event_params);
            $events = is_array($events) ? $events : [];

            $r_eventids = array_filter(array_unique(array_column($events, 'r_eventid')));
            if (!empty($r_eventids)) {
                $r_data = $api->call('event.get', [
                    'output'   => ['eventid', 'clock'],
                    'eventids' => array_values($r_eventids)
                ]);
                if (is_array($r_data)) {
                    foreach ($r_data as $re) {
                        $recoveryEvents[$re['eventid']] = (int)$re['clock'];
                    }
                }
            }
        }
    }
    
    foreach ($events as $ev) {
        $meta = $trigger_map[$ev['objectid']] ?? null;
        }

    // Procesar Eventos y Calcular SLA por Host
    $downtime_by_hostid = [];
    $events_by_hostid = [];

    foreach ($events as $event) {
        $triggerid = $event['objectid'];
        $meta = $trigger_map[$triggerid] ?? null;
        if (!$meta) continue;

        $hostId = $meta['hostid'];
        $startTime = (int)$event['clock'];
        
        // Determinar el tiempo de fin
        if (isset($event['r_eventid']) && $event['r_eventid'] != 0) {
            // Tiene recovery
            $endTime = $recoveryEvents[$event['r_eventid']] ?? time();
        } else {
            // Aún activo
            $endTime = time();
        }

        // Calcular la parte del evento que cae dentro del período
        $overlapStart = max($startTime, $from_ts);
        $overlapEnd = min($endTime, $to_ts);
            
        // Solo procesar si hay overlap con el período
        if ($overlapEnd > $overlapStart) {
            $duration = $overlapEnd - $overlapStart;
            $downtime_by_hostid[$hostId] = ($downtime_by_hostid[$hostId] ?? 0) + $duration;

            // Solo guardar el evento si el overlap es significativo o si el evento está activo
            if ($duration > 0) {
                $events_by_hostid[$hostId][] = [
                    'problem'   => $meta['problem'],
                    'severity'  => $meta['severity'],
                    'start'     => $startTime,
                    'end'       => (isset($event['r_eventid']) && $event['r_eventid'] != 0) ? ($recoveryEvents[$event['r_eventid']] ?? null) : null,
                    'duration'  => $duration,
                    'overlap_start' => $overlapStart,
                    'overlap_end' => $overlapEnd,
                    'is_active' => !isset($event['r_eventid']) || $event['r_eventid'] == 0
                ];
            }
        }
    }
    
    // Construir tabla final
    $headers = [
        t('excel_header_host'), 
        t('sla_col_real_sla'), 
        t('sla_col_downtime'), 
        t('sla_col_complies'), 
        t('export_col_problem'), 
        t('export_col_severity'), 
        t('sla_col_down_start'), 
        t('sla_col_down_end'), 
        t('export_col_duration')
    ];
    $final_report_data = [];

    foreach ($host_map as $hid => $hostName) {
        
        $total_downtime = $downtime_by_hostid[$hid] ?? 0;
        $uptime_sec = $total_period_sec - $total_downtime;
        $sla_percent = $total_period_sec > 0 ? max(0, ($uptime_sec / $total_period_sec) * 100.0) : 100.0;
            
        $sla_str = number_format($sla_percent, 4, ',', '.') . '%';
        $downtime_str = formatDuration($total_downtime);
        $cumple = ($sla_percent >= $sla_target) ? t('sla_val_yes') : t('sla_val_no');
    
        $hostEvents = $events_by_hostid[$hid] ?? [];

        if (empty($hostEvents)) {
            // Host sin problemas en el período - 1 fila
            $final_report_data[] = [
                t('excel_header_host') => $hostName,
                t('sla_col_real_sla') => $sla_str,
                t('sla_col_downtime') => $downtime_str,
                t('sla_col_complies') => $cumple,
                t('export_col_problem') => '',
                t('export_col_severity') => '',
                t('sla_col_down_start') => '',
                t('sla_col_down_end') => '',
                t('export_col_duration') => '',
                '_events' => []
            ];
        } else {
            // Host con problemas - 1 fila resumen + eventos como sub-detalle
            $eventDetails = [];
            foreach ($hostEvents as $ev) {
                $displayEnd = $ev['is_active']
                    ? t('sla_problem_status_active')
                    : ($ev['end'] ? date('Y-m-d H:i:s', $ev['end']) : '');
                $eventDetails[] = [
                    'problem'   => $ev['problem'],
                    'severity'  => getSeverityName($ev['severity']),
                    'start'     => date('Y-m-d H:i:s', max($ev['start'], $from_ts)),
                    'end'       => $displayEnd,
                    'duration'  => formatDuration($ev['duration']),
                    'is_active' => $ev['is_active'],
                ];
            }
            $final_report_data[] = [
                t('excel_header_host') => $hostName,
                t('sla_col_real_sla') => $sla_str,
                t('sla_col_downtime') => $downtime_str,
                t('sla_col_complies') => $cumple,
                t('export_col_problem') => count($hostEvents) . ' ' . t('sla_incidents', 'incident(s)'),
                t('export_col_severity') => '',
                t('sla_col_down_start') => '',
                t('sla_col_down_end') => '',
                t('export_col_duration') => '',
                '_events' => $eventDetails
            ];
        }
    }
    
    // Enviar Salida
    if ($output_format === 'excel') {
        outputCsv(
            'zabbix_sla_report_icmp_' . date('Ymd_His') . '.csv', 
            $headers, 
            $final_report_data,
            $preHeader
        );
    } else {
        outputHtml(
            t('sla_html_title_icmp'), 
            $headers, 
            $final_report_data,
            $preHeader
        );
    }

} catch (Throwable $e) {
    http_response_code(500);
    $errorMessage = $e->getMessage();
    if (strpos($errorMessage, 'Operation timed out') !== false || strpos($errorMessage, 'curl error 28') !== false) {
        $errorMessage = t('excel_err_timeout');
    }
    echo '<h3>' . t('sla_err_generic') . '</h3><pre>' . htmlspecialchars($errorMessage) . '</pre>';
    exit;
}