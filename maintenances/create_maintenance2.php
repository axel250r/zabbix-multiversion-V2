<?php
declare(strict_types=1);

// Debug - valores como string para PHP 8+
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

session_start();

// Verificar autenticación
if (empty($_SESSION['zbx_auth_ok'])) {
    header('Location: ../login.php');
    exit;
}

// Obtener tipo de usuario SIEMPRE desde la API
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/ZabbixApiFactory.php';

try {
    $api = ZabbixApiFactory::create(
        ZABBIX_API_URL,
        $_SESSION['zbx_user'],
        $_SESSION['zbx_pass'],
        ['timeout' => 10, 'verify_ssl' => VERIFY_SSL]
    );
    
    $current_user_type = $api->getUserType($_SESSION['zbx_user']);
    
    if (!isset($_SESSION['zbx_user_type']) || $_SESSION['zbx_user_type'] != $current_user_type) {
        $_SESSION['zbx_user_type'] = $current_user_type;
    }
    
} catch (Throwable $e) {
    error_log("Error obteniendo user type: " . $e->getMessage());
    $_SESSION['zbx_user_type'] = $_SESSION['zbx_user_type'] ?? 1;
}

// Detectar versión de Zabbix si no está en sesión
if (!isset($_SESSION['zabbix_version'])) {
    try {
        $ch = curl_init(rtrim(ZABBIX_API_URL, '/'));
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'apiinfo.version',
            'params' => [],
            'id' => 1
        ]);
        
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json-rpc'],
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => defined('VERIFY_SSL') ? VERIFY_SSL : false,
            CURLOPT_SSL_VERIFYHOST => defined('VERIFY_SSL') ? (VERIFY_SSL ? 2 : 0) : 0,
            CURLOPT_HTTPAUTH => CURLAUTH_ANY,
            CURLOPT_USERPWD => '',
        ]);
        
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200 && $resp) {
            $data = json_decode($resp, true);
            if (isset($data['result'])) {
                $_SESSION['zabbix_version'] = $data['result'];
            }
        }
    } catch (Throwable $e) {
        error_log("Error detectando versión Zabbix: " . $e->getMessage());
    }
}

$user_type = $_SESSION['zbx_user_type'] ?? 1;
$zabbix_version = $_SESSION['zabbix_version'] ?? '6.0.0';

// ============================================================
// LÓGICA DE PERMISOS ADAPTADA A LA VERSIÓN
// ============================================================
if (version_compare($zabbix_version, '6.4', '<')) {
    // Zabbix 6.0: tipo 1 también puede crear
    $can_create = ($user_type == 1 || $user_type >= 2);
} else {
    // Zabbix 6.4+: solo tipos 2 y 3
    $can_create = ($user_type >= 2);
}

if (!$can_create) {
    header('Location: index.php');
    exit;
}
// ============================================================

// Generar token CSRF si no existe
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

require_once __DIR__ . '/../lib/i18n.php';
?>
<!doctype html>
<html lang="<?= htmlspecialchars($current_lang ?? 'es', ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= t('maint_create_title') ?></title>
<link rel="stylesheet" href="../assets/css/maintenances.css">
</head>
<body class="dark-theme">
<header class="topbar">
  <a href="../latest_data.php" class="topbar-brand">
    <?php if (defined('CUSTOM_LOGO_PATH')): ?>
      <img src="<?= htmlspecialchars(CUSTOM_LOGO_PATH, ENT_QUOTES, 'UTF-8') ?>" alt="Logo" class="custom-logo" onerror="this.style.display='none'">
    <?php endif; ?>
    <span class="zabbix-logo">ZABBIX</span>
    <span class="topbar-name">Report</span>
  </a>
  <span class="topbar-sep">|</span>
  <span class="topbar-sub"><?= t('maint_create_title') ?></span>
  <div class="topbar-spacer"></div>
  <div class="topbar-actions">
    <a href="index.php" class="btn-top">&#8592; <?= t('maint_back_button','Volver') ?></a>
    <button id="theme-toggle" class="btn-top">&#9788; Light</button>
    <a href="../logout.php" class="btn-top danger">&#8594; <?= t('logout_button','Logout') ?></a>
  </div>
</header>

<div class="wrap" style="position:relative;z-index:1">
  <div id="maint-alert" style="display:none;padding:10px 14px;border-radius:11px;margin-bottom:14px;font-size:13px;background:rgba(224,60,60,.1);color:var(--red);border:1px solid rgba(224,60,60,.25);position:relative;z-index:1"></div>
  <form method="post" action="manage_maintenance.php" id="form-maint-create" novalidate>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="action_type" value="create">
    <input type="hidden" name="timeperiods_json" id="timeperiods-json-input">
    <input type="hidden" name="active_since_timestamp" id="active_since_timestamp">
    <input type="hidden" name="active_till_timestamp" id="active_till_timestamp">

    <div class="card">
      <div class="tabs-nav">
        <button type="button" class="tab-btn active" data-tab="tab-main"><?= t('maint_tab_main') ?></button>
        <button type="button" class="tab-btn" data-tab="tab-periods"><?= t('maint_tab_periods') ?></button>
      </div>

      <div id="tab-main" class="tab-content active">
        <div class="grid cols-2">
          <div class="form-group">
            <label class="label"><?= t('maint_form_name') ?></label>
            <input type="text" name="maint_name" required>
          </div>
          <div class="form-group">
            <label class="label"><?= t('maint_form_type') ?></label>
            <select name="maintenance_type">
                <option value="1"><?= t('maint_type_no_data') ?></option>
                <option value="0"><?= t('maint_type_with_data') ?></option>
            </select>
          </div>
        </div>
        <div class="grid cols-2">
          <div class="form-group">
            <label class="label"><?= t('maint_form_active_since') ?></label>
            <input type="datetime-local" name="active_since_display" id="active_since" required>
          </div>
          <div class="form-group">
            <label class="label"><?= t('maint_form_active_till') ?></label>
            <input type="datetime-local" name="active_till_display" id="active_till" required>
          </div>
        </div>
        <div class="form-group">
            <label class="label"><?= t('maint_form_hosts') ?></label>
            <div style="position:relative">
              <input type="text" id="maint-host-search" placeholder="<?= t('maint_hosts_search_placeholder','Busca y agrega hosts...') ?>" autocomplete="off" style="margin-bottom:6px">
            </div>
            <div id="maint-host-tags" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px;min-height:10px"></div>
            <textarea name="hostnames" id="maint-hostnames-textarea" placeholder="<?= t('maint_hosts_placeholder') ?>" style="min-height:80px;font-size:12px;color:var(--text3)"></textarea>
            <div style="font-size:11px;color:var(--text3);margin-top:4px;font-family:var(--mono)"><?= t('maint_hosts_hint','Puedes buscar hosts arriba o escribirlos directamente en el campo de texto, uno por línea.') ?></div>
        </div>
        <div class="form-group">
            <label class="label"><?= t('maint_form_description') ?></label>
            <textarea class="grow" name="description" style="min-height: 80px;"></textarea>
        </div>
      </div>

      <div id="tab-periods" class="tab-content">
        <div class="periods-list" id="periods-list-container"></div>
        <div class="actions" style="justify-content: flex-start; margin-top: 0;">
          <button type="button" class="btn btn-blue" id="btn-add-period"><?= t('maint_period_add_new') ?></button>
        </div>
      </div>

      <div style="padding:16px 24px;border-top:1px solid var(--divider);background:var(--card2);display:flex;justify-content:flex-end;gap:8px">
        <a href="index.php" class="btn btn-ghost"><?= t('modal_cancel_button') ?></a>
        <button type="submit" class="btn btn-primary"><?= t('maint_create_btn') ?></button>
      </div>
    </div>
  </form>
</div>

<!-- Templates -->
<template id="template-period-form">
  <div class="period">
    <div class="period-header">
      <h4><?= t('maint_period_new_title') ?></h4>
      <button type="button" class="btn btn-small btn-ghost btn-remove-period"><?= t('maint_period_remove') ?></button>
    </div>
    <div class="form-group">
      <label class="label"><?= t('maint_period_type') ?></label>
      <select class="period-type-select">
        <option value="0"><?= t('maint_period_type_onetime') ?></option>
        <option value="2"><?= t('maint_period_type_daily') ?></option>
        <option value="3"><?= t('maint_period_type_weekly') ?></option>
        <option value="4"><?= t('maint_period_type_monthly') ?></option>
      </select>
    </div>
    <div class="period-fields"></div>
  </div>
</template>

<template id="template-period-0">
  <div class="grid cols-2">
    <div class="form-group">
      <label class="label"><?= t('maint_period_start_date') ?></label>
      <input type="datetime-local" class="period-field" data-name="start_date">
    </div>
    <div class="form-group">
      <label class="label"><?= t('maint_period_duration') ?></label>
      <input type="text" class="period-field" data-name="period" value="1h" placeholder="Ej: 1h, 30m, 2h 30m">
    </div>
  </div>
</template>

<template id="template-period-2">
  <div class="grid cols-2">
    <div class="form-group">
      <label class="label"><?= t('maint_period_start_time') ?></label>
      <input type="time" class="period-field" data-name="start_time">
    </div>
    <div class="form-group">
      <label class="label"><?= t('maint_period_end_time') ?></label>
      <input type="time" class="period-field" data-name="end_time">
    </div>
  </div>
  <input type="hidden" class="period-field" data-name="every" value="1">
</template>

<template id="template-period-3">
  <div class="grid cols-2">
    <div class="form-group">
      <label class="label"><?= t('maint_period_start_time') ?></label>
      <input type="time" class="period-field" data-name="start_time">
    </div>
    <div class="form-group">
      <label class="label"><?= t('maint_period_end_time') ?></label>
      <input type="time" class="period-field" data-name="end_time">
    </div>
  </div>
  <div class="form-group">
    <label class="label"><?= t('maint_period_days') ?></label>
    <div class="day-of-week">
      <label><input type="checkbox" class="period-field-day" data-name="dayofweek" value="1"> <?= t('maint_day_mon') ?></label>
      <label><input type="checkbox" class="period-field-day" data-name="dayofweek" value="2"> <?= t('maint_day_tue') ?></label>
      <label><input type="checkbox" class="period-field-day" data-name="dayofweek" value="4"> <?= t('maint_day_wed') ?></label>
      <label><input type="checkbox" class="period-field-day" data-name="dayofweek" value="8"> <?= t('maint_day_thu') ?></label>
      <label><input type="checkbox" class="period-field-day" data-name="dayofweek" value="16"> <?= t('maint_day_fri') ?></label>
      <label><input type="checkbox" class="period-field-day" data-name="dayofweek" value="32"> <?= t('maint_day_sat') ?></label>
      <label><input type="checkbox" class="period-field-day" data-name="dayofweek" value="64"> <?= t('maint_day_sun') ?></label>
    </div>
  </div>
  <input type="hidden" class="period-field" data-name="every" value="1">
</template>

<template id="template-period-4">
  <!-- * Month — siempre visible en ambos modos -->
  <div class="form-group" style="margin-bottom:14px">
    <label class="label"><?= t('maint_period_month_day','* Month') ?></label>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:4px 16px;margin-top:6px">
      <?php
      $months_list = ['January'=>1,'February'=>2,'March'=>4,'April'=>8,'May'=>16,'June'=>32,'July'=>64,'August'=>128,'September'=>256,'October'=>512,'November'=>1024,'December'=>2048];
      foreach($months_list as $mname=>$mbit): ?>
      <label style="display:flex;align-items:center;gap:6px;padding:4px 0;cursor:pointer;font-size:13px">
        <input type="checkbox" class="period-month-cb" value="<?= $mbit ?>" style="width:auto;accent-color:var(--red)"> <?= $mname ?>
      </label>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Date toggle -->
  <div class="form-group" style="margin-bottom:14px">
    <label class="label">Date</label>
    <div style="display:inline-flex;border:1.5px solid var(--input-border);border-radius:var(--radius-sm);overflow:hidden">
      <button type="button" class="monthly-date-btn" data-mode="dom" data-active="1"
        style="padding:7px 16px;font-family:var(--font);font-size:13px;font-weight:600;border:none;cursor:pointer;background:var(--red);color:#fff;transition:all .14s">
        <?= t('maint_period_dom','Day of month') ?>
      </button>
      <button type="button" class="monthly-date-btn" data-mode="dow"
        style="padding:7px 16px;font-family:var(--font);font-size:13px;font-weight:600;border:none;cursor:pointer;background:var(--inner-bg);color:var(--text2);transition:all .14s">
        <?= t('maint_period_dow','Day of week') ?>
      </button>
    </div>
  </div>

  <!-- Day of month -->
  <div class="monthly-dom-fields">
    <div class="form-group" style="margin-bottom:14px">
      <label class="label"><?= t('maint_period_day_of_month','* Day of month') ?></label>
      <input type="number" class="period-field dom-only" data-name="day" min="1" max="31" value="1" style="max-width:80px">
    </div>
  </div>

  <!-- Day of week -->
  <div class="monthly-dow-fields" style="display:none">
    <div style="display:grid;grid-template-columns:auto 1fr;gap:14px;align-items:start;margin-bottom:14px">
      <div class="form-group">
        <label class="label"><?= t('maint_period_week_of_month','* Day of week') ?></label>
        <select class="period-field dow-only" data-name="every" style="max-width:140px">
          <option value="1"><?= t('maint_week_first','first') ?></option>
          <option value="2"><?= t('maint_week_second','second') ?></option>
          <option value="3"><?= t('maint_week_third','third') ?></option>
          <option value="4"><?= t('maint_week_fourth','fourth') ?></option>
          <option value="5"><?= t('maint_week_last','last') ?></option>
        </select>
      </div>
      <div class="form-group">
        <label class="label">&nbsp;</label>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;grid-auto-flow:column;grid-template-rows:repeat(3,auto);gap:4px 16px;margin-top:6px">
          <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer"><input type="checkbox" class="period-field-day" data-name="dayofweek" value="1"  style="width:auto;accent-color:var(--red)"> <?= t('maint_day_mon','Monday') ?></label>
          <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer"><input type="checkbox" class="period-field-day" data-name="dayofweek" value="2"  style="width:auto;accent-color:var(--red)"> <?= t('maint_day_tue','Tuesday') ?></label>
          <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer"><input type="checkbox" class="period-field-day" data-name="dayofweek" value="4"  style="width:auto;accent-color:var(--red)"> <?= t('maint_day_wed','Wednesday') ?></label>
          <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer"><input type="checkbox" class="period-field-day" data-name="dayofweek" value="8"  style="width:auto;accent-color:var(--red)"> <?= t('maint_day_thu','Thursday') ?></label>
          <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer"><input type="checkbox" class="period-field-day" data-name="dayofweek" value="16" style="width:auto;accent-color:var(--red)"> <?= t('maint_day_fri','Friday') ?></label>
          <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer"><input type="checkbox" class="period-field-day" data-name="dayofweek" value="32" style="width:auto;accent-color:var(--red)"> <?= t('maint_day_sat','Saturday') ?></label>
          <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer"><input type="checkbox" class="period-field-day" data-name="dayofweek" value="64" style="width:auto;accent-color:var(--red)"> <?= t('maint_day_sun','Sunday') ?></label>
        </div>
      </div>
    </div>
  </div>

  <!-- At (hour:minute) y duración -->
  <div style="display:grid;grid-template-columns:auto auto auto;gap:14px;align-items:end;margin-top:4px">
    <div class="form-group">
      <label class="label"><?= t('maint_period_start_time','At (hour:minute)') ?></label>
      <input type="time" class="period-field" data-name="start_time" value="00:00" style="max-width:130px">
    </div>
    <div class="form-group">
      <label class="label"><?= t('maint_period_duration_days','* Maintenance period length') ?></label>
      <div style="display:flex;align-items:center;gap:8px">
        <input type="number" class="period-dur-d" min="0" value="0" style="max-width:70px">
        <span style="font-size:13px;color:var(--text2)">Days</span>
        <select class="period-dur-h" style="max-width:80px">
          <?php for($i=0;$i<24;$i++): ?><option value="<?=$i?>"<?=$i==1?' selected':''?>><?=$i?></option><?php endfor; ?>
        </select>
        <span style="font-size:13px;color:var(--text2)">Hours</span>
        <select class="period-dur-m" style="max-width:80px">
          <?php for($i=0;$i<60;$i+=5): ?><option value="<?=$i?>"><?=$i?></option><?php endfor; ?>
        </select>
        <span style="font-size:13px;color:var(--text2)">Minutes</span>
      </div>
    </div>
  </div>
  <input type="hidden" class="period-field month-hidden" data-name="month" value="4095">
</template>

<script>
    const $ = (s, c = document) => c.querySelector(s);
    const $$ = (s, c = document) => Array.from(c.querySelectorAll(s));

    const tabsContainer = $('.tabs-nav');
    tabsContainer.addEventListener('click', e => {
        if (!e.target.matches('.tab-btn')) return;
        $$('.tab-btn', tabsContainer).forEach(btn => btn.classList.remove('active'));
        e.target.classList.add('active');
        const tabId = e.target.dataset.tab;
        $$('.tab-content').forEach(content => content.classList.remove('active'));
        $(`#${tabId}`).classList.add('active');
    });

    function parsePeriodToSeconds(periodStr) {
        let totalSeconds = 0;
        if (!periodStr) return 0;
        const matches = periodStr.match(/(\d+)\s*(h|m|d)/g);
        if (!matches) {
            return parseInt(periodStr) || 0;
        }
        matches.forEach(match => {
            const parts = match.match(/(\d+)\s*(h|m|d)/);
            if (parts) {
                const value = parseInt(parts[1]);
                const unit = parts[2];
                if (unit === 'd') totalSeconds += value * 86400;
                if (unit === 'h') totalSeconds += value * 3600;
                if (unit === 'm') totalSeconds += value * 60;
            }
        });
        return totalSeconds;
    }

    function syncMainWindowToFirstPeriod() {
        const sinceInput = $('#active_since');
        const tillInput = $('#active_till');
        const firstPeriod = $('.period[data-id="1"]');
        if (!firstPeriod) return;
        const periodTypeSelect = firstPeriod.querySelector('.period-type-select');
        if (periodTypeSelect.value !== '0') return;
        const sinceValue = sinceInput.value;
        const tillValue = tillInput.value;
        if (!sinceValue || !tillValue) return;
        const periodStartDateInput = firstPeriod.querySelector('.period-field[data-name="start_date"]');
        if (periodStartDateInput) {
            periodStartDateInput.value = sinceValue;
        }
        
        const sinceDate = new Date(sinceValue);
        const tillDate = new Date(tillValue);
        const diffMs = tillDate - sinceDate;
        const diffSeconds = Math.floor(diffMs / 1000);
        
        if (diffSeconds <= 0) {
            const periodDurationInput = firstPeriod.querySelector('.period-field[data-name="period"]');
            if(periodDurationInput) periodDurationInput.value = "0m";
            return;
        }
        
        const days = Math.floor(diffSeconds / 86400);
        let remaining = diffSeconds % 86400;
        const hours = Math.floor(remaining / 3600);
        remaining %= 3600;
        const minutes = Math.floor(remaining / 60);
        
        let durationStr = '';
        if (days > 0) durationStr += `${days}d `;
        if (hours > 0) durationStr += `${hours}h `;
        if (minutes > 0) durationStr += `${minutes}m`;
        durationStr = durationStr.trim();
        if (durationStr === '') durationStr = '0m';
        
        const periodDurationInput = firstPeriod.querySelector('.period-field[data-name="period"]');
        if (periodDurationInput) {
            periodDurationInput.value = durationStr;
        }
    }

    const sinceInput = $('#active_since');
    const tillInput = $('#active_till');
    if (sinceInput && tillInput) {
        sinceInput.addEventListener('input', syncMainWindowToFirstPeriod);
        tillInput.addEventListener('input', syncMainWindowToFirstPeriod);
    }

    const periodsList = $('#periods-list-container');
    const addPeriodBtn = $('#btn-add-period');
    const periodTemplate = $('#template-period-form');
    let periodCounter = 0;
    
    function addPeriod() {
        periodCounter++;
        const newPeriod = periodTemplate.content.cloneNode(true).firstElementChild;
        newPeriod.dataset.id = periodCounter;
        updatePeriodFields(newPeriod, '0');
        newPeriod.querySelector('.period-type-select').addEventListener('change', e => {
            updatePeriodFields(newPeriod, e.target.value);
            if (newPeriod.dataset.id === '1') {
                syncMainWindowToFirstPeriod();
            }
        });
        newPeriod.querySelector('.btn-remove-period').addEventListener('click', () => {
            newPeriod.remove();
        });
        periodsList.appendChild(newPeriod);
        if (periodCounter === 1) {
            syncMainWindowToFirstPeriod();
        }
    }
    
    function updatePeriodFields(periodElement, type) {
        const fieldsContainer = periodElement.querySelector('.period-fields');
        const templateId = `template-period-${type}`;
        const fieldsTemplate = $(`#${templateId}`);
        if (fieldsTemplate) {
            fieldsContainer.innerHTML = '';
            fieldsContainer.appendChild(fieldsTemplate.content.cloneNode(true));
        }
    }
    
    addPeriodBtn.addEventListener('click', addPeriod);
    addPeriod();

    // ── Monthly DOM/DOW toggle ─────────────────────────────────────────────
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.monthly-date-btn');
        if (!btn) return;
        const period = btn.closest('.period');
        const mode   = btn.dataset.mode;
        period.querySelectorAll('.monthly-date-btn').forEach(b => {
            b.style.background = 'var(--inner-bg)';
            b.style.color      = 'var(--text2)';
            b.removeAttribute('data-active');
        });
        btn.style.background = 'var(--red)';
        btn.style.color      = '#fff';
        btn.setAttribute('data-active', '1');
        period.querySelector('.monthly-dom-fields').style.display = mode === 'dom' ? '' : 'none';
        period.querySelector('.monthly-dow-fields').style.display = mode === 'dow' ? '' : 'none';
    });


    function getUTCTimestamp(dateTimeLocalString) {
        if (!dateTimeLocalString) {
            return Math.floor(Date.now() / 1000);
        }
        const localDate = new Date(dateTimeLocalString + ':00');
        return Math.floor(localDate.getTime() / 1000);
    }

    const form = $('#form-maint-create');
    const jsonInput = $('#timeperiods-json-input');

    // ── Autocomplete hosts en create maintenance ──────────────────────────
    (function(){
        const searchInput = document.getElementById('maint-host-search');
        const tagsDiv     = document.getElementById('maint-host-tags');
        const textarea    = document.getElementById('maint-hostnames-textarea');
        if (!searchInput) return;
        const selected = new Set();
        let timer;

        // Dropdown
        const dd = document.createElement('div');
        dd.style.cssText = 'display:none;position:fixed;z-index:9999;background:var(--card,#1c2030);border:1px solid var(--divider,#2a3050);border-radius:10px;max-height:220px;overflow-y:auto;box-shadow:0 4px 20px rgba(0,0,0,.5);min-width:260px;padding:4px 0';
        document.body.appendChild(dd);

        function pos() {
            const r = searchInput.getBoundingClientRect();
            dd.style.top   = (r.bottom + 4) + 'px';
            dd.style.left  = r.left + 'px';
            dd.style.width = r.width + 'px';
        }

        function renderTags() {
            tagsDiv.innerHTML = '';
            selected.forEach(name => {
                const tag = document.createElement('span');
                tag.style.cssText = 'display:inline-flex;align-items:center;gap:5px;padding:3px 10px;background:var(--red-a12,rgba(224,60,60,.12));color:var(--red,#e03c3c);border:1px solid rgba(224,60,60,.3);border-radius:99px;font-size:12px;font-family:var(--mono)';
                tag.innerHTML = escH(name) + ' <button type="button" style="background:none;border:none;cursor:pointer;color:inherit;font-size:14px;padding:0;line-height:1" data-name="' + escH(name) + '">&times;</button>';
                tag.querySelector('button').onclick = () => { selected.delete(name); renderTags(); syncTextarea(); };
                tagsDiv.appendChild(tag);
            });
        }

        function syncTextarea() {
            textarea.value = [...selected].join('\n');
        }

        function escH(s) { const d=document.createElement('div');d.textContent=s;return d.innerHTML; }

        searchInput.addEventListener('input', function() {
            const q = this.value.trim();
            if (!q) { dd.style.display='none'; return; }
            clearTimeout(timer);
            pos();
            timer = setTimeout(() => {
                fetch('../latest_data.php?action=list_hosts&q=' + encodeURIComponent(q))
                    .then(r => r.json())
                    .then(data => {
                        dd.innerHTML = '';
                        if (!data.length) { dd.style.display='none'; return; }
                        data.forEach(item => {
                            const div = document.createElement('div');
                            div.style.cssText = 'padding:8px 12px;cursor:pointer;font-size:13px;font-family:var(--mono,monospace);color:var(--text,#e8ecf6);transition:background .1s;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:flex;align-items:center;gap:8px';
                            if (selected.has(item.name)) div.style.opacity = '0.4';
                            const idx = item.name.toLowerCase().indexOf(q.toLowerCase());
                            let html = '';
                            if (idx >= 0) {
                                html = escH(item.name.slice(0,idx)) + '<strong style="color:var(--amber,#d97706)">' + escH(item.name.slice(idx,idx+q.length)) + '</strong>' + escH(item.name.slice(idx+q.length));
                            } else { html = escH(item.name); }
                            if (selected.has(item.name)) html += ' <span style="font-size:10px;color:var(--green,#16a34a)">✓</span>';
                            div.innerHTML = html;
                            div.onmouseenter = () => div.style.background='var(--table-hover,#1f2438)';
                            div.onmouseleave = () => div.style.background='';
                            div.onclick = () => {
                                if (!selected.has(item.name)) {
                                    selected.add(item.name);
                                    renderTags();
                                    syncTextarea();
                                }
                                searchInput.value = '';
                                dd.style.display = 'none';
                                searchInput.focus();
                            };
                            dd.appendChild(div);
                        });
                        dd.style.display = 'block';
                    }).catch(() => { dd.style.display='none'; });
            }, 250);
        });
        window.addEventListener('resize', () => { if (dd.style.display!=='none') pos(); });
        document.addEventListener('click', e => { if (!searchInput.contains(e.target) && !dd.contains(e.target)) dd.style.display='none'; });
    })();

    // ── Validación al enviar ───────────────────────────────────────────────
    form.addEventListener('submit', e => {
        const alertEl = document.getElementById('maint-alert');
        const hosts   = document.getElementById('maint-hostnames-textarea')?.value.trim();
        if (!hosts) {
            e.preventDefault();
            alertEl.textContent = '<?= t('maint_warn_no_hosts','Debes agregar al menos un host antes de crear el mantenimiento.') ?>';
            alertEl.style.display = 'block';
            alertEl.scrollIntoView({behavior:'smooth', block:'center'});
            return;
        }
        alertEl.style.display = 'none';
        $('#active_since_timestamp').value = $('#active_since').value;
        $('#active_till_timestamp').value = $('#active_till').value;
        
        const timeperiods = [];
        $$('.period', periodsList).forEach(periodEl => {
            const periodType = periodEl.querySelector('.period-type-select').value;
            const periodObj = { timeperiod_type: parseInt(periodType) };
            
            periodEl.querySelectorAll('.period-field:not(.month-hidden):not(.dow-only):not(.dom-only)').forEach(field => {
                const name = field.dataset.name;
                let value = field.value;
                
                if (name === 'start_date') {
                    periodObj[name] = value;
                }
                else if (name === 'period') {
                    value = parsePeriodToSeconds(value);
                    periodObj[name] = value;
                }
                else if (name === 'start_time') {
                    const parts = value.split(':');
                    value = (parseInt(parts[0] || 0) * 3600) + (parseInt(parts[1] || 0) * 60);
                    periodObj[name] = value;
                }
                else if (name === 'end_time') {
                    const parts = value.split(':');
                    value = (parseInt(parts[0] || 0) * 3600) + (parseInt(parts[1] || 0) * 60);
                    periodObj[name] = value;
                }
                else {
                    periodObj[name] = value;
                }
            });

            if (periodType === '2' || periodType === '3') {
                const startTimeSec = parseInt(periodObj['start_time']) || 0;
                const endTimeSec = parseInt(periodObj['end_time']) || 0;
                let durationSec = 0;
                if (endTimeSec <= startTimeSec) {
                    durationSec = (86400 - startTimeSec) + endTimeSec;
                } else {
                    durationSec = endTimeSec - startTimeSec;
                }
                periodObj['period'] = durationSec;
                delete periodObj['end_time'];
            }

            if (periodType === '3') {
                let dayofweek = 0;
                periodEl.querySelectorAll('.period-field-day:checked').forEach(day => {
                    dayofweek += parseInt(day.value);
                });
                periodObj['dayofweek'] = dayofweek === 0 ? 127 : dayofweek;
            }
            if (periodType === '4') {
                // Limpiar lo que puso el loop genérico
                delete periodObj['start_time'];
                delete periodObj['dayofweek'];
                delete periodObj['day'];
                delete periodObj['month'];
                delete periodObj['every'];

                periodObj['timeperiod_type'] = 4;

                // start_time desde el input de tiempo
                const stInput = periodEl.querySelector('.period-field[data-name="start_time"]');
                if (stInput) {
                    const parts = stInput.value.split(':');
                    periodObj['start_time'] = (parseInt(parts[0]||0)*3600) + (parseInt(parts[1]||0)*60);
                }

                // Duración desde días+horas
                const durD = parseInt(periodEl.querySelector('.period-dur-d')?.value || 0);
                const durH = parseInt(periodEl.querySelector('.period-dur-h')?.value || 1);
                const durM = parseInt(periodEl.querySelector('.period-dur-m')?.value || 0);
                periodObj['period'] = (durD * 86400) + (durH * 3600) + (durM * 60);

                // Detectar modo por data-active en el botón (más fiable que style)
                const activeBtn = periodEl.querySelector('.monthly-date-btn[data-active="1"]');
                const isDow = activeBtn?.dataset.mode === 'dow';

                // Meses seleccionados — compartido entre DOM y DOW
                let month = 0;
                periodEl.querySelectorAll('.period-month-cb:checked').forEach(cb => { month += parseInt(cb.value); });
                periodObj['month'] = month > 0 ? month : 4095;

                if (isDow) {
                    periodObj['every'] = parseInt(periodEl.querySelector('.dow-only[data-name="every"]')?.value || 1);
                    let dow = 0;
                    periodEl.querySelectorAll('.monthly-dow-fields .period-field-day:checked').forEach(cb => { dow += parseInt(cb.value); });
                    periodObj['dayofweek'] = dow > 0 ? dow : 2;
                } else {
                    periodObj['day'] = parseInt(periodEl.querySelector('.dom-only[data-name="day"]')?.value || 1);
                }
            }
            
            timeperiods.push(periodObj);
        });
        
        jsonInput.value = JSON.stringify(timeperiods);
    });
</script>
<script>
(function(){
  var btn=document.getElementById("theme-toggle");
  var body=document.body;
  function setTheme(t){
    body.classList.toggle("dark-theme",t==="dark");
    body.classList.toggle("light-theme",t!=="dark");
    btn.textContent=t==="dark"?"\u2600 Light":"\uD83C\uDF19 Dark";
    localStorage.setItem("zbx-theme",t);
  }
  btn.addEventListener("click",function(){setTheme(body.classList.contains("dark-theme")?"light":"dark");});
  setTheme(localStorage.getItem("zbx-theme")||(window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light"));
})();
</script>
</body>
</html>