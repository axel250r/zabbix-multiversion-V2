<?php
declare(strict_types=1);

// ==================== DEBUG ====================
// Para activar: agregar campo oculto debug_keys=1 al form, o enviar POST manual.
// Muestra las keys reales de disco y OS que Zabbix devuelve para el primer host.
$DEBUG_KEYS = !empty($_POST['debug_keys']);
// ==================== FIN DEBUG ====================

set_time_limit(600);
ini_set('memory_limit', '512M');

session_start();

if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) { 
    http_response_code(403); 
    die('Error: Invalid CSRF token.'); 
}
unset($_SESSION['csrf_token']); 

if (empty($_SESSION['zbx_auth_ok'])) { 
    http_response_code(403); 
    die('Invalid session'); 
}

require_once __DIR__ . '/../lib/i18n.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/ZabbixApiFactory.php';

// Funciones helper
function outputCsv(string $filename, array $headers, array $data, array $preHeader = []): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    if (!empty($preHeader)) {
        fputcsv($output, $preHeader, ';');
        fputcsv($output, [], ';');
    }
    fputcsv($output, $headers, ';');
    foreach ($data as $row) {
        fputcsv($output, $row, ';');
    }
    fclose($output);
    exit;
}

function formatDuration(int $seconds): string { 
    if ($seconds < 0) return 'N/A'; 
    if ($seconds < 60) return $seconds . 's'; 
    $m = floor($seconds / 60); $s = $seconds % 60; 
    if ($m < 60) return "{$m}m {$s}s"; 
    $h = floor($m / 60); $m = $m % 60; 
    if ($h < 24) return "{$h}h {$m}m"; 
    $d = floor($h / 24); $h = $h % 24; 
    return "{$d}d {$h}h"; 
}

function getSeverityName(int $severity): string { 
    global $translations; 
    $severities = [0 => 'severity_0', 1 => 'severity_1', 2 => 'severity_2', 3 => 'severity_3', 4 => 'severity_4', 5 => 'severity_5']; 
    $key = $severities[$severity] ?? 'severity_0'; 
    return t($key); 
}

function formatUptime(int $seconds): string { 
    if ($seconds <= 0) return 'N/A'; 
    $d = floor($seconds / 86400); 
    $h = floor(($seconds % 86400) / 3600); 
    $m = floor(($seconds % 3600) / 60); 
    return "{$d}d {$h}h {$m}m"; 
}

function formatSnmpUptime($value): string { 
    if (empty($value) || !is_numeric($value)) return 'N/A'; 
    $seconds = $value / 100; 
    return formatUptime((int)$seconds); 
}

function formatBytes($bytes, $precision = 2): string { 
    $bytes = (int)$bytes; 
    if ($bytes <= 0) return 'N/A'; 
    $units = ['B', 'KB', 'MB', 'GB', 'TB']; 
    $pow = min(floor(($bytes ? log($bytes) : 0) / log(1024)), count($units) - 1); 
    $bytes /= (1 << (10 * $pow)); 
    return round($bytes, $precision) . ' ' . $units[$pow]; 
}

function parseUnameString(string $uname_string): string { 
    if (preg_match('/(Microsoft\(R\) Windows\(R\) Server \d{4})/', $uname_string, $matches)) { 
        return $matches[1]; 
    } 
    $os_name = strtok($uname_string, ' '); 
    $arch = ''; 
    if (strpos($uname_string, 'x86_64') !== false) $arch = 'x86_64'; 
    elseif (strpos($uname_string, 'amd64') !== false) $arch = 'amd64'; 
    return trim("$os_name $arch"); 
}

function collect_values(array $names): array { 
    $vals = []; 
    foreach ($names as $k) { 
        if (isset($_POST[$k])) { 
            $v = $_POST[$k]; 
        } else { 
            continue; 
        } 
        if (is_string($v)) { 
            $v = trim($v); 
            if ($v === '') continue; 
        } 
        $vals = is_array($v) ? array_merge($vals, $v) : array_merge($vals, [$v]); 
    } 
    $out = []; 
    foreach ($vals as $x) { 
        if (is_string($x)) { 
            $x = trim($x); 
            if ($x === '') continue; 
            if (strpos($x, ',') !== false || strpos($x, "\n") !== false) { 
                $parts = preg_split('/[,\r\n]+/', $x); 
                foreach ($parts as $p) { 
                    $p = trim($p); 
                    if ($p !== '') $out[] = $p; 
                } 
                continue; 
            } 
        } 
        if ($x !== null) $out[] = $x; 
    } 
    return array_map('strval', $out); 
}

function determineDeviceTypeFromTags(array $tags): string {
    $deviceTags = ['Switch', 'Router', 'Firewall', 'Windows', 'Linux', 'UPS', 'Printer', 'Storage', 'ILO'];
    foreach ($tags as $tag) {
        if (empty($tag['value']) && in_array($tag['tag'], $deviceTags, true)) { return $tag['tag']; }
    }
    return t('excel_val_unknown');
}

function findTagValue(array $tags, $tagNames): string {
    $searchNames = is_array($tagNames) ? $tagNames : [$tagNames];
    $searchNames = array_map('strtolower', $searchNames);
    foreach ($tags as $tag) {
        $lowerTagName = strtolower($tag['tag']);
        if (in_array($lowerTagName, $searchNames)) { return $tag['value']; }
    }
    return 'N/A';
}

try {
    $apiOptions = ['timeout' => 500, 'verify_ssl' => (defined('VERIFY_SSL') ? (bool)VERIFY_SSL : false)];
    
    $api = ZabbixApiFactory::create(
        ZABBIX_API_URL, 
        $_SESSION['zbx_user'], 
        $_SESSION['zbx_pass'], 
        $apiOptions
    );
    
    $reportType = $_POST['report_type'] ?? 'host_list';

    $from_dt_str = $_POST['from_dt'] ?? '';
    $to_dt_str = $_POST['to_dt'] ?? '';
    $zbx_tz   = defined('ZABBIX_TZ') ? ZABBIX_TZ : 'UTC';
    $client_tz = trim($_POST['client_tz'] ?? '');
    $parse_tz  = !empty($client_tz) ? $client_tz : $zbx_tz;
    try { $tz_obj = new DateTimeZone($parse_tz); } catch (Exception $e) {
        try { $tz_obj = new DateTimeZone($zbx_tz); } catch (Exception $e2) { $tz_obj = new DateTimeZone('UTC'); }
    }
    $from_ts = ($from_dt_str && ($d = DateTime::createFromFormat('Y-m-d\TH:i', $from_dt_str, $tz_obj))) ? $d->getTimestamp() : time() - 86400;
    $to_ts   = ($to_dt_str   && ($d = DateTime::createFromFormat('Y-m-d\TH:i', $to_dt_str,   $tz_obj))) ? $d->getTimestamp() : time();
    $preHeader = [t('export_time_range'), t('export_from_label') . ": " . date('Y-m-d H:i', $from_ts), t('export_to_label') . ": " . date('Y-m-d H:i', $to_ts)];

    switch ($reportType) {
        case 'host_list':
            $hosts = $api->call('host.get', [ 
                'output' => ['host', 'name', 'status'], 
                'selectInterfaces' => ['ip'], 
                'selectGroups' => ['name'], 
                'sortfield' => 'name' 
            ]);
            
            $headers = [t('export_col_host_name'), t('export_col_visible_name'), t('export_col_status'), t('export_col_ip_address'), t('export_col_groups')];
            $data = [];
            foreach ($hosts as $host) { 
                $data[] = [ 
                    $host['host'], 
                    $host['name'], 
                    ($host['status'] == 0) ? t('export_status_enabled') : t('export_status_disabled'), 
                    $host['interfaces'][0]['ip'] ?? 'N/A', 
                    implode(', ', array_column($host['groups'] ?? [], 'name')) 
                ]; 
            }
            outputCsv('zabbix_hosts_' . date('Ymd') . '.csv', $headers, $data);
            break;

        case 'inventory':
            $columns = $_POST['columns'] ?? [];
            if (empty($columns)) { die(t('excel_err_no_columns')); }
            
            // Obtener IDs de hosts
            $hostIdsFromModal = isset($_POST['hostids']) ? array_filter(explode(',', $_POST['hostids'])) : [];
            $manualHostNames = collect_values(['hostnames']);
            $hostGroupIdsFromModal = isset($_POST['hostgroupids']) ? array_filter(explode(',', $_POST['hostgroupids'])) : [];
            $manualGroupNames = collect_values(['hostgroups']);
            
            // Resolver nombres a IDs
            $hostIdsFromNames = []; 
            if (!empty($manualHostNames)) { 
                $hostMap = $api->getHostsByNames($manualHostNames); 
                $hostIdsFromNames = array_values($hostMap);
            }
            
            // Resolver grupos a IDs
            $groupIdsFromNames = [];
            if (!empty($manualGroupNames)) {
                $groups = $api->call('hostgroup.get', ['output' => ['groupid'], 'filter' => ['name' => $manualGroupNames]]);
                if (is_array($groups)) $groupIdsFromNames = array_column($groups, 'groupid');
            }
            
            $finalGroupIds = array_unique(array_merge($hostGroupIdsFromModal, $groupIdsFromNames));
            
            $hostIdsFromGroups = []; 
            if (!empty($finalGroupIds)) { 
                $hostsFromGroups = $api->getHostIdsByGroupIds($finalGroupIds);
                $hostIdsFromGroups = $hostsFromGroups;
            }
            
            $hostIds = array_unique(array_merge($hostIdsFromModal, $hostIdsFromNames, $hostIdsFromGroups));
            
            if (empty($hostIds)) { die(t('excel_err_no_hosts')); }
            
            // Construir headers
            $headers = [t('excel_header_host')];
            if(isset($columns['availability'])) $headers[]=t('excel_col_availability'); 
            if(isset($columns['os'])) $headers[]=t('excel_col_os'); 
            if(isset($columns['device_type'])) $headers[]=t('excel_col_device_type');
            if(isset($columns['area_responsable'])) $headers[]=t('excel_col_area'); 
            if(isset($columns['uptime'])) $headers[]=t('excel_col_uptime'); 
            if(isset($columns['total_ram'])) $headers[]=t('excel_col_ram_total'); 
            if(isset($columns['cpu_cores'])) $headers[]='CPU/VCPU'; 
            if(isset($columns['cpu_stats'])) $headers=array_merge($headers,[t('excel_col_cpu_stats') . ' (Min)', t('excel_col_cpu_stats') . ' (Avg)', t('excel_col_cpu_stats') . ' (Peak)']); 
            if(isset($columns['mem_stats'])) $headers=array_merge($headers,[t('excel_col_mem_stats') . ' (Min)', t('excel_col_mem_stats') . ' (Avg)', t('excel_col_mem_stats') . ' (Peak)']); 
            if(isset($columns['disks'])) $headers[]=t('excel_col_disks') . ' (Total, Used)';
            if(isset($columns['localidad'])) $headers[]=t('excel_col_location');
            
            // Keys para buscar
            // OS: se busca por substring del nombre base de la key
            $os_key_priority = [
                'system.sw.os',          // Linux: system.sw.os o system.sw.os[name]
                'system.sw.os[name]',
                'system.hw.model',
            ];
            // Para WMI (Windows) se busca por substring 'Caption' en una llamada separada
            $cpu_key_priority = [
                'system.cpu.util',
                'system.cpu.util[,user]',
                'system.cpu.load[all,avg1]',
                'perf_counter["\\Processor(_Total)\\% Processor Time"]'
            ];
            $cpu_idle_keys      = ['system.cpu.util[,idle]']; // fallback 6.4: 100-idle
            $mem_used_keys      = ['vm.memory.utilization', 'vm.memory.size[pused]'];
            $mem_available_keys = ['vm.memory.size[pavailable]', 'vm.memory.size[available]'];
            $metrics_keys=['system.uptime','vm.memory.size[total]','icmpping']; 
            $cpu_cores_keys = ['system.cpu.num', 'vmware.hv.hw.cpu.num'];

            // Llamada principal con búsqueda por substring (funciona para la mayoría)
            $keys_to_search = array_unique(array_merge(
                ['system.sw.os', 'system.hw.model', 'system.uptime', 'vm.memory.size', 'icmpping',
                 'system.cpu.util', 'system.cpu.num', 'vmware.hv.hw.cpu.num',
                 'vm.memory.utilization', 'perf_counter']
            ));
            $items = $api->call('item.get', [
                'output' => ['itemid', 'key_', 'lastvalue', 'hostid', 'value_type'],
                'hostids' => $hostIds,
                'webitems' => true,
                'search' => ['key_' => $keys_to_search],
                'searchByAny' => true
            ]);
            $items = is_array($items) ? $items : [];

            // Buscar WMI items (Windows OS y CPU cores) por substring 'wmi.get'
            $wmi_items = $api->call('item.get', [
                'output' => ['itemid', 'key_', 'lastvalue', 'hostid', 'value_type'],
                'hostids' => $hostIds,
                'webitems' => true,
                'search' => ['key_' => ['wmi.get']],
                'searchByAny' => true
            ]);
            if(is_array($wmi_items)) $items = array_merge($items, $wmi_items);

            // Buscar keys específicas de Zabbix 6.4 (items DEPENDENT con params)
            $v64_items = $api->call('item.get', [
                'output'   => ['itemid', 'key_', 'lastvalue', 'hostid', 'value_type'],
                'hostids'  => $hostIds,
                'webitems' => true,
                'filter'   => ['key_' => [
                    'system.cpu.util[,idle]',
                    'system.cpu.util[,user]',
                    'vm.memory.size[available]',
                    'vm.memory.size[pavailable]',
                ]]
            ]);
            if(is_array($v64_items)) $items = array_merge($items, $v64_items);

            // Buscar items de disco por substring — cubre todas las versiones de Zabbix:
            // < 6.4 : vfs.fs.size[/mount,total|used]
            // ≥ 6.4 : vfs.fs.dependent.size[/mount,total|used]
            // VMware: vmware.vm.vfs.fs.size[url,vm,/mount,total|used]
            $disk_searches = ['vfs.fs.size', 'vfs.fs.dependent.size', 'vmware.vm.vfs.fs.size'];
            foreach($disk_searches as $ds){
                $disk_items_raw = $api->call('item.get', [
                    'output'    => ['itemid', 'key_', 'lastvalue', 'hostid', 'value_type'],
                    'hostids'   => $hostIds,
                    'webitems'  => true,
                    'search'    => ['key_' => $ds],
                    'searchByAny' => true
                ]);
                if(is_array($disk_items_raw)) $items = array_merge($items, $disk_items_raw);
            }
            
            $items_by_host=[]; 
            $trendable_item_ids=[]; 
            // Deduplicar por hostid+key_ (puede haber duplicados por los merges)
            $seen_items = [];
            foreach($items as $item){
                $dedup_key = $item['hostid'] . '|' . $item['key_'];
                if(isset($seen_items[$dedup_key])) continue;
                $seen_items[$dedup_key] = true;
                $items_by_host[$item['hostid']][$item['key_']]=$item; 
                if(in_array($item['key_'], $cpu_key_priority) || in_array($item['key_'], $cpu_idle_keys) || in_array($item['key_'], $mem_used_keys) || in_array($item['key_'], $mem_available_keys)) {
                    $trendable_item_ids[]=$item['itemid']; 
                }
            }

            // ---- DEBUG: mostrar keys reales del primer host ----
            if($DEBUG_KEYS && !empty($items_by_host)){
                $first_hid = array_key_first($items_by_host);
                $all_keys  = array_keys($items_by_host[$first_hid]);
                $disk_keys = array_filter($all_keys, fn($k) => strpos($k,'vfs.fs') !== false);
                $os_keys   = array_filter($all_keys, fn($k) => strpos($k,'system.sw') !== false || strpos($k,'wmi.get') !== false || strpos($k,'system.hw') !== false);
                header('Content-Type: text/plain; charset=utf-8');
                echo "=== DEBUG: Keys del host ID {$first_hid} ===\n\n";
                echo "--- DISCO (vfs.fs*) ---\n" . implode("\n", $disk_keys) . "\n\n";
                echo "--- OS (system.sw / wmi.get / system.hw) ---\n" . implode("\n", $os_keys) . "\n\n";
                echo "--- TODAS LAS KEYS ---\n" . implode("\n", $all_keys) . "\n";
                exit;
            }
            // ---- FIN DEBUG ----
            
            // --- Disponibilidad por ICMP ping (basada en eventos/triggers igual que SLA report) ---
            // Método: calcula downtime real desde triggers ICMP y lo resta al período total
            // Esto garantiza que coincida con el SLA Compliance Report
            $ping_avail_map = []; // hostid => '99.50 %'
            if(isset($columns['availability'])){
                $total_period_sec = $to_ts - $from_ts;
                $zbx_ver_excel = $_SESSION['zabbix_version'] ?? '7.0.0';
                $is_v60_excel  = version_compare($zbx_ver_excel, '6.2', '<');

                // Obtener triggers ICMP de los hosts
                $ping_item_ids_avail = [];
                foreach($hostIds as $hid){
                    if(isset($items_by_host[$hid]['icmpping'])){
                        $ping_item_ids_avail[] = $items_by_host[$hid]['icmpping']['itemid'];
                    }
                }

                $avail_trigger_map = []; // triggerid => hostid
                if(!empty($ping_item_ids_avail)){
                    $avail_triggers = $api->call('trigger.get', [
                        'output'      => ['triggerid'],
                        'itemids'     => $ping_item_ids_avail,
                        'selectHosts' => ['hostid']
                    ]);
                    if(is_array($avail_triggers)){
                        foreach($avail_triggers as $tr){
                            $hid = $tr['hosts'][0]['hostid'] ?? null;
                            if($hid) $avail_trigger_map[$tr['triggerid']] = $hid;
                        }
                    }
                }

                $downtime_map = []; // hostid => seconds of downtime
                if(!empty($avail_trigger_map)){
                    $avail_tids = array_keys($avail_trigger_map);

                    if($is_v60_excel){
                        // Zabbix 6.0: usar problem.get como fuente principal
                        $avail_probs = $api->call('problem.get', [
                            'output'    => ['eventid','objectid','clock','r_eventid'],
                            'objectids' => $avail_tids,
                            'time_from' => $from_ts - (30 * 86400),
                            'time_till' => $to_ts,
                            'source'    => 0, 'object' => 0
                        ]);
                        $avail_active = $api->call('problem.get', [
                            'output'    => ['eventid','objectid','clock','r_eventid'],
                            'objectids' => $avail_tids,
                            'source'    => 0, 'object' => 0
                        ]);
                        $avail_evraw = $api->call('event.get', [
                            'output'    => ['eventid','objectid','clock','r_eventid'],
                            'objectids' => $avail_tids,
                            'time_from' => $from_ts - (30 * 86400),
                            'source'    => 0, 'object' => 0, 'value' => 1,
                            'sortfield' => ['clock'], 'sortorder' => 'ASC'
                        ]);
                        $avail_evactive = $api->call('event.get', [
                            'output'    => ['eventid','objectid','clock','r_eventid'],
                            'objectids' => $avail_tids,
                            'time_from' => $from_ts - (30 * 86400),
                            'source'    => 0, 'object' => 0, 'value' => 1,
                            'sortfield' => ['clock'], 'sortorder' => 'ASC'
                        ]);
                        $avail_all = [];
                        $avail_seen = [];
                        foreach(array_merge(
                            is_array($avail_probs)    ? $avail_probs    : [],
                            is_array($avail_active)   ? $avail_active   : [],
                            is_array($avail_evraw)    ? $avail_evraw    : [],
                            is_array($avail_evactive) ? $avail_evactive : []
                        ) as $p){
                            if(!isset($avail_seen[$p['eventid']])){
                                $avail_all[] = $p;
                                $avail_seen[$p['eventid']] = true;
                            }
                        }
                    } else {
                        // Zabbix 6.2+: event.get con rango
                        $avail_all = $api->call('event.get', [
                            'output'     => ['eventid','objectid','clock','r_eventid'],
                            'objectids'  => $avail_tids,
                            'time_from'  => $from_ts - (30 * 86400),
                            'time_till'  => $to_ts,
                            'source'     => 0, 'object' => 0, 'value' => 1,
                            'suppressed' => false,
                            'sortfield'  => ['clock'], 'sortorder' => 'ASC'
                        ]);
                        $avail_all = is_array($avail_all) ? $avail_all : [];
                        // También traer eventos activos ahora (sin time_till)
                        $avail_active2 = $api->call('event.get', [
                            'output'     => ['eventid','objectid','clock','r_eventid'],
                            'objectids'  => $avail_tids,
                            'time_from'  => $from_ts - (30 * 86400),
                            'source'     => 0, 'object' => 0, 'value' => 1,
                            'suppressed' => false,
                            'sortfield'  => ['clock'], 'sortorder' => 'ASC'
                        ]);
                        $avail_seen = [];
                        $merged = [];
                        foreach(array_merge($avail_all, is_array($avail_active2) ? $avail_active2 : []) as $ev){
                            if(!isset($avail_seen[$ev['eventid']])){
                                $merged[] = $ev;
                                $avail_seen[$ev['eventid']] = true;
                            }
                        }
                        $avail_all = $merged;
                    }

                    // Obtener timestamps de recovery
                    $avail_recovery = [];
                    $r_eids = array_filter(array_unique(array_column($avail_all, 'r_eventid')));
                    if(!empty($r_eids)){
                        $r_data = $api->call('event.get', [
                            'output'   => ['eventid','clock'],
                            'eventids' => array_values($r_eids)
                        ]);
                        if(is_array($r_data)){
                            foreach($r_data as $re) $avail_recovery[$re['eventid']] = (int)$re['clock'];
                        }
                    }

                    // Calcular downtime por host
                    foreach($avail_all as $ev){
                        $hid = $avail_trigger_map[$ev['objectid']] ?? null;
                        if(!$hid) continue;
                        $start = (int)$ev['clock'];
                        $r_eid = (int)($ev['r_eventid'] ?? 0);
                        $end   = $r_eid > 0 ? ($avail_recovery[$r_eid] ?? time()) : time();
                        $is_active = ($r_eid === 0);
                        if($start <= $to_ts || $is_active){
                            $overlap_start = max($start, $from_ts);
                            $overlap_end   = min($end, $to_ts);
                            if($overlap_end > $overlap_start){
                                $downtime_map[$hid] = ($downtime_map[$hid] ?? 0) + ($overlap_end - $overlap_start);
                            }
                        }
                    }
                }

                // Calcular % disponibilidad
                foreach($hostIds as $hid){
                    $downtime = $downtime_map[$hid] ?? 0;
                    $uptime   = max(0, $total_period_sec - $downtime);
                    $pct      = $total_period_sec > 0 ? round(($uptime / $total_period_sec) * 100, 2) : 100;
                    $ping_avail_map[$hid] = $pct . ' %';
                }
            }
            // trend.get solo retorna datos con granularidad de 1 hora y necesita
            // al menos ~1h completa en el pasado; para rangos ≤25h se usa history.get.
            $final_trends=[];
            $range_seconds = $to_ts - $from_ts;
            $use_history   = ($range_seconds <= 90000); // ≤ 25 horas → history
            $use_both      = ($range_seconds <= 90000 && $range_seconds > 3600); // intentar ambos para 1-25h

            if(!empty($trendable_item_ids)){
                $unique_ids = array_unique($trendable_item_ids);

                if($use_history){
                    // --- history.get para rangos cortos (≤25h) ---
                    // Tipos numéricos: 0 = float, 3 = uint
                    foreach([0, 3] as $hist_type){
                        $hist_data = $api->call('history.get', [
                            'output'    => ['itemid','value','clock'],
                            'itemids'   => $unique_ids,
                            'history'   => $hist_type,
                            'time_from' => $from_ts,
                            'time_till' => $to_ts,
                            'sortfield' => 'clock',
                            'sortorder' => 'ASC',
                            'limit'     => 100000
                        ]);
                        if(!is_array($hist_data)) continue;
                        $grouped=[];
                        foreach($hist_data as $row){ $grouped[$row['itemid']][]=(float)$row['value']; }
                        foreach($grouped as $iid=>$vals){
                            if(empty($vals)) continue;
                            $min_v=min($vals); $max_v=max($vals);
                            $avg_v=array_sum($vals)/count($vals);
                            // Merge: si ya existía por otro tipo, tomamos el más extremo
                            if(!isset($final_trends[$iid])){
                                $final_trends[$iid]=['min'=>$min_v,'avg'=>$avg_v,'max'=>$max_v,'sum_avg'=>array_sum($vals)];
                            } else {
                                $prev=&$final_trends[$iid];
                                $prev['min']=min($prev['min'],$min_v);
                                $prev['max']=max($prev['max'],$max_v);
                                $all_vals=array_merge([$prev['sum_avg']], $vals);
                                $prev['avg']=array_sum($vals)/count($vals);
                                $prev['sum_avg']=array_sum($vals);
                            }
                        }
                    }
                } else {
                    // --- trend.get para rangos largos (>25h) ---
                    $trends_data=$api->call('trend.get', [
                        'output'    => ['itemid','value_min','value_avg','value_max'],
                        'itemids'   => $unique_ids,
                        'time_from' => $from_ts,
                        'time_till' => $to_ts
                    ]);
                    $trends_data=is_array($trends_data)?$trends_data:[];
                    $grouped_trends=[];
                    foreach($trends_data as $trend){ $grouped_trends[$trend['itemid']][]=$trend; }
                    foreach($grouped_trends as $itemid=>$hourly_data){
                        if(empty($hourly_data)) continue;
                        $min_vals=array_column($hourly_data,'value_min');
                        $avg_vals=array_column($hourly_data,'value_avg');
                        $max_vals=array_column($hourly_data,'value_max');
                        $final_trends[$itemid]=[
                            'min'     => min($min_vals)?:0,
                            'avg'     => count($avg_vals)>0?array_sum($avg_vals)/count($avg_vals):0,
                            'max'     => max($max_vals)?:0,
                            'sum_avg' => array_sum($avg_vals)
                        ];
                    }
                }
            }
            
            // Obtener info de hosts
            $hosts_info = $api->call('host.get', [
                'hostids' => $hostIds, 
                'output' => ['name', 'hostid'], 
                'selectTags' => 'extend'
            ]); 
            $host_map=[]; 
            foreach($hosts_info as $h){
                $host_map[$h['hostid']]=$h;
            }
            
            $data = [];
            
            foreach ($hostIds as $hid) {
                if(!isset($host_map[$hid])) continue;
                $host_data=$host_map[$hid]; 
                $host_items_map=$items_by_host[$hid]??[];
                $row_data=[]; 
                $row_data[t('excel_header_host')]=$host_data['name'];
                $host_tags = $host_data['tags'] ?? [];
                
                if(isset($columns['availability'])){
                    $row_data[t('excel_col_availability')] = $ping_avail_map[$hid] ?? 'N/A';
                }
                
                if(isset($columns['os'])){
                    $os='N/A';
                    // Buscar por prioridad usando regex sobre todas las keys del host
                    foreach($items_by_host[$hid] ?? [] as $k => $i){
                        // Linux/Unix: system.sw.os o system.sw.os[name]
                        if(preg_match('/^system\.sw\.os/', $k)){
                            $os = $i['lastvalue']; break;
                        }
                    }
                    if($os === 'N/A'){
                        foreach($items_by_host[$hid] ?? [] as $k => $i){
                            // Windows WMI: wmi.get con Caption
                            if(strpos($k,'wmi.get') !== false && stripos($k,'Caption') !== false){
                                $os = $i['lastvalue']; break;
                            }
                        }
                    }
                    if($os === 'N/A'){
                        foreach($items_by_host[$hid] ?? [] as $k => $i){
                            // Fallback: system.hw.model (SNMP devices)
                            if(preg_match('/^system\.hw\.model/', $k)){
                                $os = $i['lastvalue']; break;
                            }
                        }
                    }
                    $row_data[t('excel_col_os')]=$os;
                }
                
                if(isset($columns['device_type'])){ 
                    $row_data[t('excel_col_device_type')] = determineDeviceTypeFromTags($host_tags); 
                }
                
                if(isset($columns['area_responsable'])){ 
                    $row_data[t('excel_col_area')] = findTagValue($host_tags, ['area_responsable', 'area responsable']); 
                }
                
                if(isset($columns['localidad'])){ 
                    $row_data[t('excel_col_location')] = findTagValue($host_tags, 'localidad'); 
                }
                
                if(isset($columns['uptime'])){
                    $uptime='N/A'; 
                    if(isset($host_items_map['system.uptime'])){
                        $uptime=formatUptime((int)$host_items_map['system.uptime']['lastvalue']);
                    } 
                    $row_data[t('excel_col_uptime')]=$uptime;
                }
                
                if(isset($columns['total_ram'])){
                    $ram='N/A'; 
                    if(isset($host_items_map['vm.memory.size[total]'])){
                        $ram=formatBytes($host_items_map['vm.memory.size[total]']['lastvalue']);
                    } 
                    $row_data[t('excel_col_ram_total')]=$ram;
                }

                if(isset($columns['cpu_cores'])){
                    $cores='N/A';
                    // Claves exactas primero
                    foreach(['system.cpu.num','vmware.hv.hw.cpu.num'] as $k){
                        if(isset($host_items_map[$k]) && !empty($host_items_map[$k]['lastvalue'])){
                            $cores=(int)$host_items_map[$k]['lastvalue']; break;
                        }
                    }
                    // WMI con regex (NumberOfLogicalProcessors)
                    if($cores === 'N/A'){
                        foreach($host_items_map as $k=>$i){
                            if(strpos($k,'wmi.get') !== false && stripos($k,'NumberOfLogicalProcessors') !== false && !empty($i['lastvalue'])){
                                $cores=(int)$i['lastvalue']; break;
                            }
                        }
                    }
                    $row_data['CPU/VCPU']=$cores;
                }

                if(isset($columns['cpu_stats'])){
                    $cpu=['min'=>'N/A','avg'=>'N/A','max'=>'N/A']; 
                    foreach($cpu_key_priority as $k){
                        if(isset($host_items_map[$k])){ 
                            $id=$host_items_map[$k]['itemid']; 
                            if(isset($final_trends[$id])){
                                $t=$final_trends[$id];
                                $u=($k==='system.cpu.load[all,avg1]')?'':' %';
                                $cpu=['min'=>round((float)$t['min'],2).$u,'avg'=>round((float)$t['avg'],2).$u,'max'=>round((float)$t['max'],2).$u];
                                break;
                            } 
                        }
                    }
                    // Fallback Zabbix 6.4: system.cpu.util es DEPENDENT sin trends propios
                    // Usar system.cpu.util[,idle] invertido (100 - idle = used)
                    if($cpu['min']==='N/A'){
                        foreach($cpu_idle_keys as $k){
                            if(isset($host_items_map[$k])){
                                $id=$host_items_map[$k]['itemid'];
                                if(isset($final_trends[$id])){
                                    $t=$final_trends[$id];
                                    $cpu=[
                                        'min' =>round(max(0,100-(float)$t['max']),2).' %',
                                        'avg' =>round(max(0,100-(float)$t['avg']),2).' %',
                                        'max' =>round(max(0,100-(float)$t['min']),2).' %'
                                    ];
                                    break;
                                }
                            }
                        }
                    } 
                    $row_data[t('excel_col_cpu_stats') . ' (Min)']=$cpu['min'];
                    $row_data[t('excel_col_cpu_stats') . ' (Avg)']=$cpu['avg'];
                    $row_data[t('excel_col_cpu_stats') . ' (Peak)']=$cpu['max'];
                }
                
                if(isset($columns['mem_stats'])){
                    $mem=['min'=>'N/A','avg'=>'N/A','max'=>'N/A'];
                    $found_mem_item = null;
                    $invert_mem_metric = false;
                    foreach ($mem_used_keys as $k) {
                        if (isset($host_items_map[$k])) { $found_mem_item = $host_items_map[$k]; break; }
                    }
                    if (!$found_mem_item) {
                        foreach ($mem_available_keys as $k) {
                            if (isset($host_items_map[$k])) { $found_mem_item = $host_items_map[$k]; $invert_mem_metric = true; break; }
                        }
                    }
                    if ($found_mem_item) {
                        $id = $found_mem_item['itemid'];
                        if (isset($final_trends[$id])) {
                            $t = $final_trends[$id];
                            if ($invert_mem_metric) {
                                $min_val = 100.0 - (float)$t['max'];
                                $max_val = 100.0 - (float)$t['min'];
                                $avg_val = 100.0 - (float)$t['avg'];
                                $mem=['min'=>round($min_val,2).'%','avg'=>round($avg_val,2).'%','max'=>round($max_val,2).'%'];
                            } else {
                                $mem=['min'=>round((float)$t['min'],2).'%','avg'=>round((float)$t['avg'],2).'%','max'=>round((float)$t['max'],2).'%'];
                            }
                        }
                    }
                    $row_data[t('excel_col_mem_stats') . ' (Min)']=$mem['min'];
                    $row_data[t('excel_col_mem_stats') . ' (Avg)']=$mem['avg'];
                    $row_data[t('excel_col_mem_stats') . ' (Peak)']=$mem['max'];
                }

                $disks=[];
                if(isset($columns['disks'])){
                    // Regex único que captura el filesystem y el sufijo para los tres patrones:
                    //   vfs.fs.size[/mount,total]
                    //   vfs.fs.dependent.size[/mount,total]
                    //   vmware.vm.vfs.fs.size[url,vm,/mount,total]  ← último parámetro es el sufijo
                    $total_pattern = '/^(?:vmware\.vm\.)?vfs\.fs(?:\.dependent)?\.size\[(.+),total\]$/';
                    foreach($host_items_map as $k=>$i){
                        if(!preg_match($total_pattern, $k, $m)) continue;
                        $full_param = $m[1]; // ej: "/", "/boot", "url,vm,/"
                        $total = (int)$i['lastvalue'];
                        if($total <= 0) continue;

                        // Construir la key de "used" reemplazando solo el sufijo final
                        $used_k = preg_replace('/,total\]$/', ',used]', $k);
                        $used   = isset($host_items_map[$used_k]) ? (int)$host_items_map[$used_k]['lastvalue'] : 0;

                        // Mostrar solo el mount point (último segmento del parámetro)
                        $parts = explode(',', $full_param);
                        $fs    = end($parts); // "/" o "/boot" etc.

                        $disks[] = "{$fs} (Total: ".formatBytes($total).", Used: ".formatBytes($used).")";
                    }
                }
                
                if (isset($columns['disks']) && !empty($disks)) { 
                    foreach ($disks as $disk_info) { 
                        $row_data[t('excel_col_disks') . ' (Total, Used)'] = $disk_info; 
                        $final_row = []; 
                        foreach ($headers as $header) { 
                            $final_row[] = $row_data[$header] ?? 'N/A'; 
                        } 
                        $data[] = $final_row; 
                    } 
                } else { 
                    $row_data[t('excel_col_disks') . ' (Total, Used)'] = 'N/A'; 
                    $final_row = []; 
                    foreach ($headers as $header) { 
                        $final_row[] = $row_data[$header] ?? 'N/A'; 
                    } 
                    $data[] = $final_row; 
                }
            }
            outputCsv('zabbix_inventory_custom_' . date('Ymd') . '.csv', $headers, $data, $preHeader);
            break;

        case 'problem_report':
            $hostIdsFromModal = isset($_POST['hostids']) ? array_filter(explode(',', $_POST['hostids'])) : [];
            $manualHostNames = collect_values(['hostnames']);
            $hostGroupIdsFromModal = isset($_POST['hostgroupids']) ? array_filter(explode(',', $_POST['hostgroupids'])) : [];
            $manualGroupNames = collect_values(['hostgroups']);
            
            $hostIdsFromNames = []; 
            if (!empty($manualHostNames)) { 
                $hostMap = $api->getHostsByNames($manualHostNames); 
                $hostIdsFromNames = array_values($hostMap); 
            }
            
            $groupIdsFromNames = []; 
            if (!empty($manualGroupNames)) { 
                $groups = $api->call('hostgroup.get', ['output' => ['groupid'], 'filter' => ['name' => $manualGroupNames]]); 
                if (is_array($groups)) { $groupIdsFromNames = array_column($groups, 'groupid'); } 
            }
            
            $finalGroupIds = array_unique(array_merge($hostGroupIdsFromModal, $groupIdsFromNames));
            
            $hostIdsFromGroups = []; 
            if (!empty($finalGroupIds)) { 
                $hostsFromGroups = $api->call('host.get', [ 'output' => ['hostid'], 'groupids' => $finalGroupIds ]); 
                if (is_array($hostsFromGroups)) { $hostIdsFromGroups = array_column($hostsFromGroups, 'hostid'); } 
            }
            
            $hostIds = array_unique(array_merge($hostIdsFromModal, $hostIdsFromNames, $hostIdsFromGroups));
            if (empty($hostIds)) { die(t('excel_err_no_hosts')); }
            
            $events = $api->call('event.get', [ 
                'output' => 'extend', 
                'selectHosts' => ['name'], 
                'hostids' => $hostIds, 
                'time_from' => $from_ts, 
                'time_till' => $to_ts, 
                'source' => 0, 
                'object' => 0, 
                'value' => 1 
            ]);
            $events = is_array($events) ? $events : [];
            
            $r_eventids = array_filter(array_unique(array_column($events, 'r_eventid')));
            $recoveryEvents = []; 
            if (!empty($r_eventids)) {
                $r_events_data = $api->call('event.get', [ 'output' => ['eventid', 'clock'], 'eventids' => $r_eventids ]);
                if(is_array($r_events_data)) {
                    foreach ($r_events_data as $r_event) { $recoveryEvents[$r_event['eventid']] = $r_event; }
                }
            }
            
            usort($events, function($a, $b) { return (int)$b['clock'] <=> (int)$a['clock']; });
            
            $data = [];
            foreach ($events as $event) {
                if (empty($event['hosts'])) continue;
                $recoveryTime = 'N/A';
                $duration = t('excel_problem_status_open');
                $status = t('excel_problem_status_problem');
                if (!empty($event['r_eventid']) && isset($recoveryEvents[$event['r_eventid']])) {
                    $r_event = $recoveryEvents[$event['r_eventid']];
                    $recoveryTime = date('Y-m-d H:i:s', (int)$r_event['clock']);
                    $duration = formatDuration((int)$r_event['clock'] - (int)$event['clock']);
                    $status = t('excel_problem_status_resolved');
                }
                $data[] = [ 
                    date('Y-m-d H:i:s', (int)$event['clock']), 
                    getSeverityName((int)$event['severity']), 
                    $recoveryTime, 
                    $status, 
                    $event['hosts'][0]['name'], 
                    $event['name'], 
                    $duration 
                ];
            }
            
            $headers = [t('sla_col_down_start'), t('export_col_severity'), t('sla_col_down_end'), t('export_col_status'), t('excel_header_host'), t('export_col_problem'), t('export_col_duration')];
            outputCsv('zabbix_problem_report_' . date('Ymd') . '.csv', $headers, $data, $preHeader);
            break;

        case 'peaks_report':
            $peak_threshold = 90.0;
            
            $hostIdsFromModal = isset($_POST['hostids']) ? array_filter(explode(',', $_POST['hostids'])) : [];
            $manualHostNames = collect_values(['hostnames']);
            $hostGroupIdsFromModal = isset($_POST['hostgroupids']) ? array_filter(explode(',', $_POST['hostgroupids'])) : [];
            $manualGroupNames = collect_values(['hostgroups']);
            
            $hostIdsFromNames = []; 
            if (!empty($manualHostNames)) { 
                $hostMap = $api->getHostsByNames($manualHostNames); 
                $hostIdsFromNames = array_values($hostMap); 
            }
            
            $groupIdsFromNames = []; 
            if (!empty($manualGroupNames)) { 
                $groups = $api->call('hostgroup.get', ['output' => ['groupid'], 'filter' => ['name' => $manualGroupNames]]); 
                if (is_array($groups)) { $groupIdsFromNames = array_column($groups, 'groupid'); } 
            }
            
            $finalGroupIds = array_unique(array_merge($hostGroupIdsFromModal, $groupIdsFromNames));
            
            $hostIdsFromGroups = []; 
            if (!empty($finalGroupIds)) { 
                $hostsFromGroups = $api->call('host.get', [ 'output' => ['hostid'], 'groupids' => $finalGroupIds ]); 
                if (is_array($hostsFromGroups)) { $hostIdsFromGroups = array_column($hostsFromGroups, 'hostid'); } 
            }
            
            $hostIds = array_unique(array_merge($hostIdsFromModal, $hostIdsFromNames, $hostIdsFromGroups));
            if (empty($hostIds)) { die(t('excel_err_no_hosts')); }
            
            $headers = [t('excel_header_host'), t('excel_header_metric'), t('excel_header_date'), t('excel_header_peak_value'), t('excel_header_peak_time')];
            $data = [];
            
            $cpu_key_priority = ['system.cpu.util', 'perf_counter["\\Processor(_Total)\\% Processor Time"]'];
            $mem_used_keys = ['vm.memory.utilization', 'vm.memory.size[pused]'];
            $mem_available_keys = ['vm.memory.size[pavailable]'];
            $keys_to_search = array_unique(array_merge($cpu_key_priority, $mem_used_keys, $mem_available_keys));
            
            $hosts_info = $api->call('host.get', ['hostids' => $hostIds, 'output' => ['name', 'hostid']]);
            $host_map = []; foreach ($hosts_info as $h) { $host_map[$h['hostid']] = $h; }
            
            $items = $api->call('item.get', [ 
                'output' => ['itemid', 'key_', 'hostid', 'value_type', 'units'], 
                'hostids' => $hostIds, 
                'webitems' => true, 
                'search' => ['key_' => $keys_to_search], 
                'searchByAny' => true 
            ]);
            $items_by_host = []; foreach ($items as $item) { $items_by_host[$item['hostid']][$item['key_']] = $item; }
            
            $getDailyPeaks = function(array $item, int $from_ts, int $to_ts, bool $invert_value = false) use ($api): array {
                $history_type = (int)$item['value_type'];
                if ($history_type !== 0 && $history_type !== 3) return [];
                try {
                    $params = [ 
                        'output' => 'extend', 
                        'history' => $history_type, 
                        'itemids' => [(string)$item['itemid']], 
                        'time_from' => (int)$from_ts, 
                        'time_till' => (int)$to_ts, 
                        'sortfield' => 'clock', 
                        'sortorder' => 'ASC' 
                    ];
                    $history = $api->call('history.get', $params);
                    if (empty($history) || !is_array($history)) return [];
                    $daily_peaks = [];
                    foreach ($history as $entry) {
                        $day = date('Y-m-d', (int)$entry['clock']);
                        $value = (float)$entry['value'];
                        if ($invert_value) { $value = 100.0 - $value; }
                        if (!isset($daily_peaks[$day]) || $value > $daily_peaks[$day]['value']) {
                            $daily_peaks[$day] = [ 'value' => $value, 'clock' => (int)$entry['clock'], 'units' => $item['units'] ];
                        }
                    }
                    return $daily_peaks;
                } catch (Exception $e) {
                    error_log("Zabbix Daily Peak Report Error: " . $e->getMessage());
                    return [];
                }
            };
            
            foreach ($hostIds as $hid) {
                if (!isset($host_map[$hid])) continue;
                $host_name = $host_map[$hid]['name'];
                $host_items = $items_by_host[$hid] ?? [];
                
                // CPU
                $found_cpu_item = null;
                foreach ($cpu_key_priority as $k) {
                    if (isset($host_items[$k])) { $found_cpu_item = $host_items[$k]; break; }
                }
                $metric_name_cpu = t('excel_metric_cpu');
                if ($found_cpu_item) {
                    $all_daily_peaks = $getDailyPeaks($found_cpu_item, $from_ts, $to_ts, false);
                    $critical_peaks = [];
                    if ($found_cpu_item['units'] === '%') {
                        $critical_peaks = array_filter($all_daily_peaks, function($p) use ($peak_threshold) {
                            return $p['value'] >= $peak_threshold;
                        });
                    }
                    if (!empty($critical_peaks)) {
                        foreach ($critical_peaks as $day => $peak) {
                            $data[] = [ $host_name, $metric_name_cpu, $day, round($peak['value'], 2) . ' ' . $peak['units'], date('H:i:s', $peak['clock']) ];
                        }
                    } elseif (!empty($all_daily_peaks)) {
                        $single_max_peak = array_reduce($all_daily_peaks, function($a, $b) {
                            return $a['value'] > $b['value'] ? $a : $b;
                        });
                        $data[] = [ $host_name, $metric_name_cpu, date('Y-m-d', $single_max_peak['clock']), round($single_max_peak['value'], 2) . ' ' . $single_max_peak['units'], date('H:i:s', $single_max_peak['clock']) ];
                    } else {
                        $data[] = [$host_name, $metric_name_cpu, "N/A", t('excel_val_no_data'), "N/A"];
                    }
                } else {
                    $data[] = [$host_name, $metric_name_cpu, "N/A", t('excel_val_no_item'), "N/A"];
                }
                
                // Memoria
                $metric_name_mem = t('excel_metric_mem');
                $found_mem_item = null;
                $invert_mem_metric = false;
                foreach ($mem_used_keys as $k) {
                    if (isset($host_items[$k])) { $found_mem_item = $host_items[$k]; break; }
                }
                if (!$found_mem_item) {
                    foreach ($mem_available_keys as $k) {
                        if (isset($host_items[$k])) { $found_mem_item = $host_items[$k]; $invert_mem_metric = true; break; }
                    }
                }
                if ($found_mem_item) {
                    $all_daily_peaks = $getDailyPeaks($found_mem_item, $from_ts, $to_ts, $invert_mem_metric);
                    $critical_peaks = [];
                    if ($found_mem_item['units'] === '%') {
                        $critical_peaks = array_filter($all_daily_peaks, function($p) use ($peak_threshold) {
                            return $p['value'] >= $peak_threshold;
                        });
                    }
                    if (!empty($critical_peaks)) {
                        foreach ($critical_peaks as $day => $peak) {
                            $data[] = [ $host_name, $metric_name_mem, $day, round($peak['value'], 2) . ' %', date('H:i:s', $peak['clock']) ];
                        }
                    } elseif (!empty($all_daily_peaks)) {
                        $single_max_peak = array_reduce($all_daily_peaks, function($a, $b) {
                            return $a['value'] > $b['value'] ? $a : $b;
                        });
                        $data[] = [ $host_name, $metric_name_mem, date('Y-m-d', $single_max_peak['clock']), round($single_max_peak['value'], 2) . ' %', date('H:i:s', $single_max_peak['clock']) ];
                    } else {
                        $data[] = [$host_name, $metric_name_mem, "N/A", t('excel_val_no_data'), "N/A"];
                    }
                } else {
                    $data[] = [$host_name, $metric_name_mem, "N/A", t('excel_val_no_item'), "N/A"];
                }
            }
            outputCsv('zabbix_smart_peaks_report_' . date('Ymd') . '.csv', $headers, $data, $preHeader);
            break;
            
        default:
            throw new RuntimeException("Tipo de reporte no válido: $reportType");
    }
    
} catch (Throwable $e) {
    error_log("Zabbix Report Error: " . $e->getMessage() . " on line " . $e->getLine() . " in " . $e->getFile());
    http_response_code(500);
    $errorMessage = $e->getMessage();
    if (strpos($errorMessage, 'Operation timed out') !== false || strpos($errorMessage, 'curl error 28') !== false) {
        $errorMessage = t('excel_err_timeout');
    }
    die("<h3>" . t('excel_err_critical') . "</h3><p>" . t('excel_err_failed') . ": " . htmlspecialchars($errorMessage) . "</p>");
}
