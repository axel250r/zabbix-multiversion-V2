<?php
declare(strict_types=1);

// ORDEN CORRECTO: Todas las declaraciones y código van DESPUÉS de strict_types.
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; script-src 'self' 'unsafe-inline';");

session_start();
if (empty($_SESSION['zbx_auth_ok'])) { header('Location: login.php'); exit; }

// Generar token CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

require_once __DIR__ . '/lib/i18n.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/ZabbixApiFactory.php';
header('Content-Type: text/html; charset=utf-8');

if (empty($_SESSION['zbx_user']) || empty($_SESSION['zbx_pass'])) {
    header('Location: login.php');
    exit;
}

function getZabbixTemplates() {
    try {
        $api = ZabbixApiFactory::create(
            ZABBIX_API_URL, 
            $_SESSION['zbx_user'], 
            $_SESSION['zbx_pass'],
            [
                'timeout'    => 30,
                'verify_ssl' => (defined('VERIFY_SSL') ? (bool)VERIFY_SSL : false)
            ]
        );
        $response = $api->call('template.get', ['output' => ['templateid', 'name'], 'sortfield' => 'name']);
        return is_array($response) ? $response : [];
    } catch (Throwable $e) {
        return ['error' => 'Error: ' . $e->getMessage()];
    }
}

$zabbixTemplates = getZabbixTemplates();
?>
<!doctype html>
<html lang="<?= htmlspecialchars($current_lang, ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<meta name="author" content="Axel Del Canto">
<title><?= t('export_title') ?></title>
<link rel="stylesheet" href="assets/css/export.css">
<?php if (defined('APPLY_LOGO_BLEND_MODE') && APPLY_LOGO_BLEND_MODE): ?>
<style>
body.dark-theme .custom-logo {
  mix-blend-mode: multiply;
}
</style>
<?php endif; ?>
</head>
<body class="dark-theme">

<header class="topbar">
  <a href="export.php" class="topbar-brand">
    <img src="<?= htmlspecialchars(defined('CUSTOM_LOGO_PATH') ? CUSTOM_LOGO_PATH : 'assets/sonda.png', ENT_QUOTES, 'UTF-8') ?>" alt="Logo" class="custom-logo" />
    <span class="zabbix-logo">ZABBIX</span>
    <span class="topbar-name">Report</span>
  </a>
  <span class="topbar-sep">|</span>
  <span class="topbar-sub"><?= htmlspecialchars($_SESSION['zbx_user'],ENT_QUOTES,'UTF-8') ?> &bull; <?= htmlspecialchars(ZABBIX_URL,ENT_QUOTES,'UTF-8') ?></span>
  <div class="topbar-spacer"></div>
  <div class="topbar-actions">
    <button id="theme-toggle" class="btn-top">&#9788; <?= t('theme_light') ?></button>
    <a href="logout.php" class="btn-top danger">&#8594; <?= t('logout_button','Logout') ?></a>
  </div>
</header>

<div class="wrap">

  <div class="page-header">
    <div class="page-title"><?= t('export_title') ?></div>
    <div class="page-sub"><?= t('export_logged_in_as') ?> <b><?= htmlspecialchars($_SESSION['zbx_user'],ENT_QUOTES,'UTF-8') ?></b></div>
  </div>

  <div class="progress-bar" id="progress-bar">
    <div class="progress-step active" id="ps-1"><div class="step-num">1</div><div class="step-text">Hosts</div></div>
    <div class="step-connector" id="pc-1"></div>
    <div class="progress-step" id="ps-2"><div class="step-num">2</div><div class="step-text"><?= t('export_templates_items_label','Items') ?></div></div>
    <div class="step-connector" id="pc-2"></div>
    <div class="progress-step" id="ps-3"><div class="step-num">3</div><div class="step-text"><?= t('host_items_label','Host Items') ?></div></div>
    <div class="step-connector" id="pc-3"></div>
    <div class="progress-step" id="ps-4"><div class="step-num">4</div><div class="step-text"><?= t('export_from_label','Tiempo') ?></div></div>
    <div class="step-connector" id="pc-4"></div>
    <div class="progress-step" id="ps-5"><div class="step-num">5</div><div class="step-text">Export</div></div>
  </div>

  <form method="post" action="generate.php" target="_blank" id="form-export">
    <input type="hidden" name="csrf_token"       value="<?= htmlspecialchars($csrf_token,ENT_QUOTES,'UTF-8') ?>">
    <input type="hidden" name="item_keys"         id="itemkeys-hidden-input" />
    <input type="hidden" name="lld_proto_ids"     id="lldids-hidden-input" />
    <input type="hidden" name="templateids"       id="templateids-hidden-input" />
    <input type="hidden" name="hostids"           id="hostids-hidden-input" />
    <input type="hidden" name="hostgroupids"      id="hostgroupids-hidden-input" />
    <input type="hidden" name="host_item_ids"     id="host-item-ids-hidden" />
    <input type="hidden" name="client_tz"         id="client_tz">
    <input type="hidden" name="client_offset_min" id="client_offset_min">

    <!-- PASO 1 -->
    <div class="step-section open" id="step-hosts" data-step="1">
      <div class="step-header" onclick="toggleStep('step-hosts')">
        <div class="step-badge"><svg class="badge-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="3"/><path d="M8 12h8M12 8v8"/></svg></div>
        <div class="step-info">
          <div class="step-title"><?= t('export_hosts_label') ?></div>
          <div class="step-desc"><?= t('export_hosts_placeholder') ?></div>
        </div>
        <div class="step-status">
          <span class="status-tag status-empty" id="st-hosts"><?= t('validate_no_hosts') ?></span>
          <span class="step-chevron">&#9660;</span>
        </div>
      </div>
      <div class="step-body">
        <div class="field-label"><?= t('export_hosts_label') ?></div>
        <div class="field-hint"><?= t('export_hosts_placeholder') ?></div>
        <div class="field-row">
          <textarea name="hostnames" id="hostnames-textarea" rows="2" placeholder="<?= t('export_hosts_placeholder') ?>"></textarea>
          <button type="button" class="btn-select" id="open-host-modal"><?= t('modal_select_button') ?></button>
        </div>
        <div style="margin-top:18px">
          <div class="field-label"><?= t('export_groups_label') ?> <span class="label-optional">(opcional)</span></div>
          <div class="field-hint"><?= t('export_groups_placeholder') ?></div>
          <div class="field-row">
            <textarea name="hostgroups" id="hostgroups-textarea" rows="2" placeholder="<?= t('export_groups_placeholder') ?>"></textarea>
            <button type="button" class="btn-select" id="open-hostgroup-modal"><?= t('modal_select_button') ?></button>
          </div>
        </div>
      </div>
    </div>

    <!-- PASO 2: TEMPLATES & ITEMS -->
    <div class="step-section" id="step-items" data-step="2">
      <div class="step-header" onclick="toggleStep('step-items')">
        <div class="step-badge"><svg class="badge-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg></div>
        <div class="step-info">
          <div class="step-title"><?= t('export_templates_items_label') ?></div>
          <div class="step-desc"><?= t('export_templates_items_placeholder') ?></div>
        </div>
        <div class="step-status">
          <span class="status-tag status-empty" id="st-items"><?= t('validate_no_items') ?></span>
          <span class="step-chevron">&#9660;</span>
        </div>
      </div>
      <div class="step-body">
        <div class="field-hint"><?= t('export_templates_items_placeholder') ?></div>
        <div class="field-row" style="margin-top:10px">
          <textarea name="template_and_items_txt" id="templates-and-items-textarea" rows="2" placeholder="<?= t('export_templates_items_placeholder') ?>"></textarea>
          <button type="button" class="btn-select" id="open-template-item-modal"><?= t('modal_select_button') ?></button>
        </div>
      </div>
    </div>

    <!-- PASO 3: ITEMS POR HOST -->
    <div class="step-section" id="step-hostitems" data-step="3">
      <div class="step-header" onclick="toggleStep('step-hostitems')">
        <div class="step-badge"><svg class="badge-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></div>
        <div class="step-info">
          <div class="step-title"><?= t('host_items_label') ?></div>
          <div class="step-desc"><?= t('host_items_subtitle') ?></div>
        </div>
        <div class="step-status">
          <span class="status-tag status-optional" id="st-hostitems"><?= t('label_optional','Optional') ?></span>
          <span class="step-chevron">&#9660;</span>
        </div>
      </div>
      <div class="step-body">
        <div class="field-hint"><?= t('host_items_subtitle') ?></div>
        <div class="field-row" style="margin-top:10px">
          <textarea name="host_item_keys_txt" id="host-items-textarea" rows="2" placeholder="<?= t('host_items_placeholder') ?>"></textarea>
          <button type="button" class="btn-select" id="open-host-item-modal"><?= t('modal_select_button') ?></button>
        </div>
      </div>
    </div>

    <!-- PASO 4: TIEMPO -->
    <div class="step-section" id="step-time" data-step="4">
      <div class="step-header" onclick="toggleStep('step-time')">
        <div class="step-badge"><svg class="badge-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 15"/></svg></div>
        <div class="step-info">
          <div class="step-title"><?= t('export_from_label') ?> / <?= t('export_to_label') ?></div>
          <div class="step-desc"><?= t('export_time_range_note') ?></div>
        </div>
        <div class="step-status">
          <span class="status-tag status-empty" id="st-time">Not set</span>
          <span class="step-chevron">&#9660;</span>
        </div>
      </div>
      <div class="step-body">
        <div class="field-hint"><?= t('export_time_range_note') ?></div>
        <div class="quick-btns">
          <button type="button" class="btn-quick" id="24h-btn"><?= t('export_last_24h') ?></button>
          <button type="button" class="btn-quick" id="month-btn"><?= t('export_last_month','Ultimo mes') ?></button>
          <button type="button" class="btn-quick" id="6month-btn"><?= t('export_last_6_months','Ultimos 6 meses') ?></button>
        </div>
        <div style="margin-top:14px">
          <div class="time-inputs">
            <div><div class="field-label"><?= t('export_from_label') ?></div><input type="datetime-local" name="from_dt" id="from_dt" /></div>
            <div class="time-arrow">&#8594;</div>
            <div><div class="field-label"><?= t('export_to_label') ?></div><input type="datetime-local" name="to_dt" id="to_dt" /></div>
          </div>
        </div>
        <div class="field-note"><?= t('export_time_range_note') ?></div>
      </div>
    </div>

    <!-- PASO 4 -->
    <div class="step-section open" id="step-export" data-step="5">
      <div class="step-header" onclick="toggleStep('step-export')">
        <div class="step-badge"><svg class="badge-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg></div>
        <div class="step-info">
          <div class="step-title"><?= t('export_generate_pdf_button') ?></div>
          <div class="step-desc"><?= t('export_templates_items_placeholder') ?></div>
        </div>
        <div class="step-status"><span class="step-chevron">&#9660;</span></div>
      </div>
      <div class="step-body">
        
        <div class="export-row" style="margin-top:14px">
          <button class="btn-export btn-pdf" type="submit" id="generate-pdf-btn">
            &#128196; <?= t('export_generate_pdf_button') ?>
          </button>
          <a href="export-excel/excel_export.php" class="btn-export btn-excel" target="_blank" rel="noopener">
            &#128202; <?= t('export_to_excel_button') ?>
          </a>
          <a href="maintenances/" class="btn-export btn-maint" target="_blank" rel="noopener">
            &#128295; <?= t('maintenances_button','Mantenciones') ?>
          </a>
        </div>
      </div>
    </div>

  </form>
  <div class="credit"><?= t('common_author_credit') ?></div>
</div>

<div id="host-modal" class="modal">
  <div class="modal-content">
    <span class="close">&times;</span>
    <h2><?= t('modal_select_hosts_title') ?></h2>
    <input type="text" id="host-filter" class="modal-filter" placeholder="<?= t('modal_filter_hosts_placeholder') ?>" />
    <div class="bulk-ops-controls" style="margin-bottom: 10px;">
        <button type="button" class="btn" id="host-select-all" ><?= t('modal_select_page_button') ?></button>
        <button type="button" class="btn" id="host-deselect-all" ><?= t('modal_deselect_page_button') ?></button>
    </div>
    <div id="host-list" class="list-box"></div>
    <div style="text-align: center;">
      <div id="host-pagination" class="pagination-controls"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-primary" id="select-hosts"><?= t('modal_select_button') ?></button>
      <button type="button" class="btn" id="cancel-host-selection" class="btn btn-cancel"><?= t('modal_cancel_button') ?></button>
    </div>
  </div>
</div>
<div id="hostgroup-modal" class="modal">
  <div class="modal-content">
    <span class="close">&times;</span>
    <h2><?= t('modal_select_groups_title') ?></h2>
    <input type="text" id="hostgroup-filter" class="modal-filter" placeholder="<?= t('modal_filter_groups_placeholder') ?>" />
    <div class="bulk-ops-controls" style="margin-bottom: 10px;">
        <button type="button" class="btn" id="hostgroup-select-all" ><?= t('modal_select_page_button') ?></button>
        <button type="button" class="btn" id="hostgroup-deselect-all" ><?= t('modal_deselect_page_button') ?></button>
    </div>
    <div id="hostgroup-list" class="list-box"></div>
    <div style="text-align: center;">
      <div id="hostgroup-pagination" class="pagination-controls"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-primary" id="select-hostgroups"><?= t('modal_select_button') ?></button>
      <button type="button" class="btn" id="cancel-hostgroup-selection" class="btn btn-cancel"><?= t('modal_cancel_button') ?></button>
    </div>
  </div>
</div>
<!-- Modal: host items -->
<div id="host-item-modal" class="modal">
  <div class="modal-content">
    <span class="close">&times;</span>
    <h2 id="host-item-modal-title"><?= t('host_item_modal_title') ?></h2>
    <div id="host-item-step-1">
      <p style="font-size:13px;margin-bottom:8px"><?= t('host_item_step1_desc') ?></p>
      <input type="text" id="hi-host-filter" class="modal-filter" placeholder="<?= t('host_item_filter_hosts') ?>" />
      <div id="hi-host-list" class="list-box"></div>
      <div style="text-align:center"><div id="hi-host-pagination" class="pagination-controls"></div></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" id="hi-next-btn"><?= t('host_item_next_btn') ?> &rarr;</button>
        <button type="button" class="btn btn-cancel" id="hi-cancel-btn"><?= t('modal_cancel_button') ?></button>
      </div>
    </div>
    <div id="host-item-step-2" style="display:none">
      <p style="font-size:13px;margin-bottom:8px" id="hi-host-label"></p>
      <input type="text" id="hi-item-filter" class="modal-filter" placeholder="<?= t('host_item_filter_items') ?>" />
      <div id="hi-item-list" class="list-box"></div>
      <div style="text-align:center"><div id="hi-item-pagination" class="pagination-controls"></div></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" id="hi-select-btn"><?= t('host_item_add_btn') ?></button>
        <button type="button" class="btn btn-cancel" id="hi-back-btn"><?= t('host_item_back_btn') ?></button>
      </div>
    </div>
  </div>
</div>

<div id="template-item-modal" class="modal">
  <div class="modal-content">
    <span class="close">&times;</span>
    <h2 id="modal-title"><?= t('modal_select_templates_title') ?></h2>
    <div id="modal-step-1">
      <input type="text" id="template-filter" class="modal-filter" placeholder="<?= t('modal_filter_templates_placeholder') ?>" />
      <div class="bulk-ops-controls" style="margin-bottom: 10px;">
        <button type="button" class="btn" id="template-select-all" ><?= t('modal_select_page_button') ?></button>
        <button type="button" class="btn" id="template-deselect-all" ><?= t('modal_deselect_page_button') ?></button>
      </div>
      <div id="template-list" class="list-box"></div>
      <div style="text-align: center;">
        <div id="template-pagination" class="pagination-controls"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" id="next-to-items"><?= t('modal_next_button') ?></button>
        <button type="button" class="btn" id="cancel-template-selection" class="btn btn-cancel"><?= t('modal_cancel_button') ?></button>
      </div>
    </div>
    <div id="modal-step-2" style="display:none;">
      <input type="text" id="item-filter" class="modal-filter" placeholder="<?= t('modal_filter_items_placeholder') ?>" />
      <div id="item-list" class="list-box"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" id="select-items"><?= t('modal_add_items_button') ?></button>
        <button type="button" class="btn btn-cancel" id="back-to-templates" style="background-color: #6c757d;"><?= t('modal_back_button') ?></button>
      </div>
    </div>
  </div>
</div>

<script>
  const T = <?= json_encode($translations) ?>;
  
  // ==================== LÓGICA DE PAGINACIÓN ====================
  const itemsPerPage = 10;

  function renderPagination(container, currentPage, totalItems, onPageClick) {
    container.innerHTML = '';
    const totalPages = Math.ceil(totalItems / itemsPerPage);
    if (totalPages <= 1) return;

    const createButton = (text, page) => {
        const btn = document.createElement('button');
        btn.type = 'button'; // evitar submit del form al paginar
        btn.innerHTML = text;
        if (page) {
            btn.dataset.page = page;
            if (page === currentPage) btn.classList.add('active');
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                onPageClick(page);
            });
        } else {
            btn.disabled = true;
        }
        return btn;
    };
    
    const createEllipsis = () => {
        const span = document.createElement('span');
        span.className = 'ellipsis';
        span.textContent = '...';
        return span;
    };

    container.appendChild(createButton('&laquo;', currentPage > 1 ? currentPage - 1 : null));

    const pagesToShow = new Set();
    pagesToShow.add(1);
    if (totalPages > 1) pagesToShow.add(totalPages);
    pagesToShow.add(currentPage);
    if (currentPage > 1) pagesToShow.add(currentPage - 1);
    if (currentPage < totalPages) pagesToShow.add(currentPage + 1);

    const sortedPages = Array.from(pagesToShow).sort((a,b) => a - b);
    let lastPage = 0;

    sortedPages.forEach(page => {
        if (page > lastPage + 1) {
            container.appendChild(createEllipsis());
        }
        container.appendChild(createButton(String(page), page));
        lastPage = page;
    });

    container.appendChild(createButton('&raquo;', currentPage < totalPages ? currentPage + 1 : null));
  }
  
  // ==================== MODAL DE HOSTS ====================
  (() => {
    const modal = document.getElementById('host-modal');
    const openBtn = document.getElementById('open-host-modal');
    const closeBtn = modal.querySelector('.close');
    const cancelBtn = document.getElementById('cancel-host-selection');
    const selectBtn = document.getElementById('select-hosts');
    const filterInput = document.getElementById('host-filter');
    const listContainer = document.getElementById('host-list');
    const paginationContainer = document.getElementById('host-pagination');
    const selectAllBtn = document.getElementById('host-select-all');
    const deselectAllBtn = document.getElementById('host-deselect-all');
    const textarea = document.getElementById('hostnames-textarea');
    const hiddenInput = document.getElementById('hostids-hidden-input');
    
    let allData = [];
    let currentPage = 1;
    const checked = new Map(); // id -> name, persiste entre paginas

    const populate = (filter = '') => {
        const filtered = allData.filter(item => item.name.toLowerCase().includes(filter.toLowerCase()));
        listContainer.innerHTML = '';
        const startIndex = (currentPage - 1) * itemsPerPage;
        const pageData = filtered.slice(startIndex, startIndex + itemsPerPage);
        if (pageData.length === 0) { listContainer.innerHTML = `<p>${T.modal_no_results}</p>`; }
        pageData.forEach(item => {
            const label = document.createElement('label');
            label.className = 'chk';
            const cb = document.createElement('input');
            cb.type = 'checkbox'; cb.value = item.hostid; cb.dataset.name = item.name;
            cb.checked = checked.has(item.hostid);
            cb.addEventListener('change', function() {
                this.checked ? checked.set(this.value, this.dataset.name) : checked.delete(this.value);
            });
            label.appendChild(cb); label.appendChild(document.createTextNode(' ' + item.name));
            listContainer.appendChild(label);
        });
        renderPagination(paginationContainer, currentPage, filtered.length, page => {
            currentPage = page; populate(filterInput.value);
        });
    };

    openBtn.onclick = () => {
        modal.style.display = 'block';
        if (allData.length === 0) {
            listContainer.innerHTML = `<p>${T.modal_loading}</p>`;
            fetch('get_hosts.php').then(res => res.json()).then(data => {
                allData = data.error ? [] : data; currentPage = 1; populate();
            });
        } else { currentPage = 1; populate(filterInput.value = ''); }
    };

    filterInput.onkeyup = () => { currentPage = 1; populate(filterInput.value); };
    selectAllBtn.onclick = () => listContainer.querySelectorAll('input').forEach(cb => {
        cb.checked = true; checked.set(cb.value, cb.dataset.name);
    });
    deselectAllBtn.onclick = () => listContainer.querySelectorAll('input').forEach(cb => {
        cb.checked = false; checked.delete(cb.value);
    });
    closeBtn.onclick = () => modal.style.display = 'none';
    cancelBtn.onclick = () => modal.style.display = 'none';

    selectBtn.onclick = () => {
        // Usar el Map en lugar de los checkboxes visibles
        if (!checked.size) { modal.style.display = 'none'; return; }
        let currentNames = textarea.value.trim() ? textarea.value.split(', ').filter(Boolean) : [];
        let currentIds = hiddenInput.value.trim() ? hiddenInput.value.split(',') : [];
        checked.forEach((name, id) => { if (!currentIds.includes(id)) { currentIds.push(id); currentNames.push(name); } });
        textarea.value = [...new Set(currentNames)].join(', ');
        hiddenInput.value = [...new Set(currentIds)].join(',');
        modal.style.display = 'none';
        if (typeof updateProgress === 'function') updateProgress();
    };
  })();

  // ==================== MODAL DE GRUPOS ====================
  (() => {
    const modal = document.getElementById('hostgroup-modal');
    const openBtn = document.getElementById('open-hostgroup-modal');
    const closeBtn = modal.querySelector('.close');
    const cancelBtn = document.getElementById('cancel-hostgroup-selection');
    const selectBtn = document.getElementById('select-hostgroups');
    const filterInput = document.getElementById('hostgroup-filter');
    const listContainer = document.getElementById('hostgroup-list');
    const paginationContainer = document.getElementById('hostgroup-pagination');
    const selectAllBtn = document.getElementById('hostgroup-select-all');
    const deselectAllBtn = document.getElementById('hostgroup-deselect-all');
    const textarea = document.getElementById('hostgroups-textarea');
    const hiddenInput = document.getElementById('hostgroupids-hidden-input');

    let allData = [];
    let currentPage = 1;
    const checked = new Map();

    const populate = (filter = '') => {
        const filtered = allData.filter(item => item.name.toLowerCase().includes(filter.toLowerCase()));
        listContainer.innerHTML = '';
        const startIndex = (currentPage - 1) * itemsPerPage;
        const pageData = filtered.slice(startIndex, startIndex + itemsPerPage);
        if (pageData.length === 0) { listContainer.innerHTML = `<p>${T.modal_no_results}</p>`; }
        pageData.forEach(item => {
            const label = document.createElement('label');
            label.className = 'chk';
            const cb = document.createElement('input');
            cb.type = 'checkbox'; cb.value = item.groupid; cb.dataset.name = item.name;
            cb.checked = checked.has(item.groupid);
            cb.addEventListener('change', function() {
                this.checked ? checked.set(this.value, this.dataset.name) : checked.delete(this.value);
            });
            label.appendChild(cb); label.appendChild(document.createTextNode(' ' + item.name));
            listContainer.appendChild(label);
        });
        renderPagination(paginationContainer, currentPage, filtered.length, page => {
            currentPage = page; populate(filterInput.value);
        });
    };

    openBtn.onclick = () => {
        modal.style.display = 'block';
        if (allData.length === 0) {
            listContainer.innerHTML = `<p>${T.modal_loading}</p>`;
            fetch('get_host_groups.php').then(res => res.json()).then(data => {
                allData = data.error ? [] : data; currentPage = 1; populate();
            });
        } else { currentPage = 1; populate(filterInput.value = ''); }
    };

    filterInput.onkeyup = () => { currentPage = 1; populate(filterInput.value); };
    selectAllBtn.onclick = () => listContainer.querySelectorAll('input').forEach(cb => {
        cb.checked = true; checked.set(cb.value, cb.dataset.name);
    });
    deselectAllBtn.onclick = () => listContainer.querySelectorAll('input').forEach(cb => {
        cb.checked = false; checked.delete(cb.value);
    });
    closeBtn.onclick = () => modal.style.display = 'none';
    cancelBtn.onclick = () => modal.style.display = 'none';

    selectBtn.onclick = () => {
        if (!checked.size) { modal.style.display = 'none'; return; }
        let currentNames = textarea.value.trim() ? textarea.value.split(', ').filter(Boolean) : [];
        let currentIds = hiddenInput.value.trim() ? hiddenInput.value.split(',') : [];
        checked.forEach((name, id) => { if (!currentIds.includes(id)) { currentIds.push(id); currentNames.push(name); } });
        textarea.value = [...new Set(currentNames)].join(', ');
        hiddenInput.value = [...new Set(currentIds)].join(',');
        modal.style.display = 'none';
        if (typeof updateProgress === 'function') updateProgress();
    };
  })();

  // ==================== MODAL DE PLANTILLAS E ITEMS ====================
  (() => {
    const modal = document.getElementById('template-item-modal');
    const openBtn = document.getElementById('open-template-item-modal');
    const closeBtn = modal.querySelector('.close');
    const cancelBtn = document.getElementById('cancel-template-selection');
    
    const step1 = document.getElementById('modal-step-1');
    const filterInput1 = document.getElementById('template-filter');
    const listContainer1 = document.getElementById('template-list');
    const paginationContainer1 = document.getElementById('template-pagination');
    const selectAllBtn1 = document.getElementById('template-select-all');
    const deselectAllBtn1 = document.getElementById('template-deselect-all');
    const nextBtn = document.getElementById('next-to-items');
    
    const step2 = document.getElementById('modal-step-2');
    const itemFilter = document.getElementById('item-filter');
    const itemList = document.getElementById('item-list');
    const selectItemsBtn = document.getElementById('select-items');
    const backBtn = document.getElementById('back-to-templates');
    const modalTitle = document.getElementById('modal-title');
    
    const allTemplates = <?php echo json_encode($zabbixTemplates); ?>;
    let allItems = [];
    let currentPage = 1;
    // Mapa de seleccion persistente entre paginas: {templateid -> name}
    const tmplChecked = new Map();

    const populateTemplates = (filter = '') => {
        const filtered = allTemplates.filter(item => item.name.toLowerCase().includes(filter.toLowerCase()));
        listContainer1.innerHTML = '';
        const startIndex = (currentPage - 1) * itemsPerPage;
        const pageData = filtered.slice(startIndex, startIndex + itemsPerPage);

        if (pageData.length === 0) { listContainer1.innerHTML = `<p>${T.modal_no_results}</p>`; }
        pageData.forEach(item => {
            const label = document.createElement('label');
            label.className = 'chk';
            // Crear checkbox via DOM para poder setear .checked como propiedad JS
            // (usar atributo HTML 'checked' como string puede fallar en algunos browsers)
            const cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.value = item.templateid;
            cb.dataset.name = item.name;
            cb.checked = tmplChecked.has(item.templateid); // propiedad JS, no atributo
            cb.addEventListener('change', function() {
                this.checked ? tmplChecked.set(this.value, this.dataset.name) : tmplChecked.delete(this.value);
                updateTmplBadge();
            });
            label.appendChild(cb);
            label.appendChild(document.createTextNode(' ' + item.name));
            listContainer1.appendChild(label);
        });

        renderPagination(paginationContainer1, currentPage, filtered.length, page => {
            currentPage = page;
            populateTemplates(filterInput1.value);
        });
        // Actualizar badge con seleccion actual cada vez que se renderiza
        updateTmplBadge();
    };

    // Muestra cuantos templates hay seleccionados en el boton siguiente
    function updateTmplBadge() {
        const n = tmplChecked.size;
        nextBtn.textContent = n > 0 ? `${T.modal_next_button} (${n})` : T.modal_next_button;
    }

    openBtn.onclick = () => {
        modal.style.display = 'block';
        step1.style.display = 'block';
        step2.style.display = 'none';
        modalTitle.textContent = T.modal_select_templates_title;
        currentPage = 1;
        populateTemplates(filterInput1.value = '');
    };
    
    filterInput1.onkeyup = () => { currentPage = 1; populateTemplates(filterInput1.value); };
    selectAllBtn1.onclick = () => listContainer1.querySelectorAll('input').forEach(cb => {
        cb.checked = true; tmplChecked.set(cb.value, cb.dataset.name); updateTmplBadge();
    });
    deselectAllBtn1.onclick = () => listContainer1.querySelectorAll('input').forEach(cb => {
        cb.checked = false; tmplChecked.delete(cb.value); updateTmplBadge();
    });
    closeBtn.onclick = () => modal.style.display = 'none';
    cancelBtn.onclick = () => modal.style.display = 'none';
    backBtn.onclick = () => {
        step1.style.display = 'block';
        step2.style.display = 'none';
        modalTitle.textContent = T.modal_select_templates_title;
    };
    
    nextBtn.onclick = () => {
        // Usar tmplChecked (persiste entre paginas) en lugar de los checkboxes visibles
        if (tmplChecked.size === 0) {
            alert(T.alert_select_template);
            return;
        }
        const selectedTemplateIds = [...tmplChecked.keys()];

        modalTitle.textContent = T.modal_select_items_title;
        step1.style.display = 'none';
        step2.style.display = 'block';
        itemList.innerHTML = `<p>${T.modal_loading}</p>`;

        const selectedHostIds = (document.getElementById('hostids-hidden-input').value || '')
            .split(',').filter(Boolean);

        fetch('get_items.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'get_items', templateids: selectedTemplateIds, hostids: selectedHostIds })
        }).then(res => res.json()).then(items => {
            allItems = Array.isArray(items) ? items : [];
            tmplResolvedHostIds = [...new Set(allItems.filter(i => i.hostid).map(i => i.hostid))];
            populateItems();
        });
    };
    let tmplResolvedHostIds = [];
    
    let itemCurrentPage = 1;
    const itemChecked = new Map(); // itemid -> {name, key, isLld, displayName}
    const populateItems = (filter = '') => {
        const q = filter.toLowerCase();
        const filtered = allItems.filter(item =>
            item.name.toLowerCase().includes(q) || item.key_.toLowerCase().includes(q)
        );
        itemList.innerHTML = '';
        let pagCont = document.getElementById('item-pagination');
        if (!pagCont) {
            pagCont = document.createElement('div');
            pagCont.id = 'item-pagination';
            pagCont.className = 'pagination-controls';
            pagCont.style.cssText = 'display:block;text-align:center;margin-top:8px;';
            itemList.parentNode.insertBefore(pagCont, itemList.nextSibling);
        }
        if (filtered.length === 0) {
            itemList.innerHTML = `<p>${T.modal_no_results}</p>`;
            pagCont.innerHTML = ''; return;
        }
        const pageData = filtered.slice((itemCurrentPage-1)*itemsPerPage, itemCurrentPage*itemsPerPage);
        pageData.forEach(item => {
            const label = document.createElement('label');
            label.className = 'chk';
            const displayName = item.display_name || item.name;
            const cb = document.createElement('input');
            cb.type = 'checkbox'; cb.value = item.itemid;
            cb.dataset.name = item.name; cb.dataset.key = item.key_;
            cb.dataset.islld = item.is_lld ? '1' : '0';
            cb.dataset.displayname = displayName;
            cb.checked = itemChecked.has(item.itemid);
            cb.addEventListener('change', function() {
                this.checked
                    ? itemChecked.set(this.value, {name: item.name, key: item.key_, isLld: item.is_lld, displayName})
                    : itemChecked.delete(this.value);
            });
            const badge = item.is_lld ? ` <span class="badge" title="LLD Prototype">LLD</span>` : '';
            label.appendChild(cb);
            label.insertAdjacentHTML('beforeend', ` ${displayName}${badge} <small>${item.key_}</small>`);
            itemList.appendChild(label);
        });
        renderPagination(pagCont, itemCurrentPage, filtered.length, p => {
            itemCurrentPage = p; populateItems(filter);
        });
    };
    itemFilter.onkeyup = () => { itemCurrentPage = 1; populateItems(itemFilter.value); };
    
    selectItemsBtn.onclick = () => {
        if (itemChecked.size === 0) { alert(T.alert_select_item); return; }
        const templatesAndItemsTextarea = document.getElementById('templates-and-items-textarea');
        const itemkeysHiddenInput = document.getElementById('itemkeys-hidden-input');
        const lldIdsInput = document.getElementById('lldids-hidden-input');
        const curExactKeys = itemkeysHiddenInput.value.split(',').filter(Boolean);
        const curLldIds    = lldIdsInput.value.split(',').filter(Boolean);
        let currentText = templatesAndItemsTextarea.value;
        let currentItemNames = [];
        let currentTemplateNamesStr = '';
        if (currentText.includes('| Items:')) {
            let parts = currentText.split('| Items:');
            currentTemplateNamesStr = parts[0];
            currentItemNames = parts[1].split(', ').filter(Boolean);
        } else { currentTemplateNamesStr = currentText; }
        const newExactKeys = [], newLldIds = [], newNames = [];
        itemChecked.forEach((v, id) => {
            v.isLld ? newLldIds.push(id) : newExactKeys.push(v.key);
            newNames.push(v.displayName);
        });
        itemkeysHiddenInput.value = [...new Set([...curExactKeys, ...newExactKeys])].join(',');
        lldIdsInput.value         = [...new Set([...curLldIds,    ...newLldIds])].join(',');
        const allNames = [...new Set([...currentItemNames, ...newNames])];
        templatesAndItemsTextarea.value = `${currentTemplateNamesStr} | Items: ${allNames.join(', ')}`;
        if (typeof updateProgress === 'function') updateProgress();

        if (tmplResolvedHostIds.length > 0) {
            const hInput = document.getElementById('hostids-hidden-input');
            const existing = hInput.value.split(',').filter(Boolean);
            hInput.value = [...new Set([...existing, ...tmplResolvedHostIds])].join(',');
        }

        modal.style.display = 'none';
    };
  })();
  
  // ==================== CÓDIGO GENERAL ====================
  const themeToggle = document.getElementById('theme-toggle');
  const body = document.body;

  function setTheme(theme) {
    if (theme === 'dark') {
      body.classList.remove('light-theme');
      body.classList.add('dark-theme');
      themeToggle.textContent = T.theme_light;
    } else {
      body.classList.remove('dark-theme');
      body.classList.add('light-theme');
      themeToggle.textContent = T.theme_dark;
    }
    localStorage.setItem('zbx-theme', theme);
  }

  themeToggle.addEventListener('click', () => {
    const cur = body.classList.contains('dark-theme') ? 'dark' : 'light';
    setTheme(cur === 'dark' ? 'light' : 'dark');
  });

  const savedTheme = localStorage.getItem('zbx-theme');
  if (savedTheme) {
    setTheme(savedTheme);
  } else {
    setTheme(window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
  }

  (function(){
    const tz = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
    const offMin = - new Date().getTimezoneOffset();
    document.getElementById('client_tz').value = tz;
    document.getElementById('client_offset_min').value = offMin;
  })();
  
  window.onclick = function(event) {
    if (event.target.matches('.modal')) {
        event.target.style.display = 'none';
    }
  }
  
  // Formatear fecha para datetime-local
  function formatDate(date) {
    const yyyy = date.getFullYear();
    const mm   = (date.getMonth() + 1).toString().padStart(2, '0');
    const dd   = date.getDate().toString().padStart(2, '0');
    const hh   = date.getHours().toString().padStart(2, '0');
    const min  = date.getMinutes().toString().padStart(2, '0');
    return `${yyyy}-${mm}-${dd}T${hh}:${min}`;
  }

  function setRange(hours) {
    const now  = new Date();
    const from = new Date(now.getTime() - hours * 3600 * 1000);
    document.getElementById('from_dt').value = formatDate(from);
    document.getElementById('to_dt').value   = formatDate(now);
  }

  document.getElementById('24h-btn').addEventListener('click',    () => setRange(24));
  document.getElementById('month-btn').addEventListener('click',  () => setRange(720));
  document.getElementById('6month-btn').addEventListener('click', () => setRange(4320));

  // ==================== MODAL HOST-ITEMS ====================
  (() => {
    const modal      = document.getElementById('host-item-modal');
    const openBtn    = document.getElementById('open-host-item-modal');
    const cancelBtn  = document.getElementById('hi-cancel-btn');
    const nextBtn2   = document.getElementById('hi-next-btn');
    const backBtn2   = document.getElementById('hi-back-btn');
    const selectBtn2 = document.getElementById('hi-select-btn');
    const step1      = document.getElementById('host-item-step-1');
    const step2      = document.getElementById('host-item-step-2');
    const hostFilter = document.getElementById('hi-host-filter');
    const hostList   = document.getElementById('hi-host-list');
    const hostPag    = document.getElementById('hi-host-pagination');
    const itemFilter2= document.getElementById('hi-item-filter');
    const itemList2  = document.getElementById('hi-item-list');
    const itemPag2   = document.getElementById('hi-item-pagination');
    const hostLabel  = document.getElementById('hi-host-label');

    let allHosts2 = [], hostPage2 = 1, selHost = null;
    let allItems2 = [], itemPage2 = 1;

    openBtn.onclick = () => {
        modal.style.display = 'block';
        step1.style.display = 'block'; step2.style.display = 'none';
        if (!allHosts2.length) {
            hostList.innerHTML = `<p>${T.modal_loading}</p>`;
            fetch('get_hosts.php').then(r => r.json()).then(data => {
                allHosts2 = Array.isArray(data) ? data : [];
                renderHosts2();
            });
        } else { hostFilter.value = ''; hostPage2 = 1; renderHosts2(); }
    };
    const closeBtn2 = modal.querySelector('.close');
    if (closeBtn2) closeBtn2.onclick = () => modal.style.display = 'none';
    cancelBtn.onclick = () => modal.style.display = 'none';
    window.addEventListener('click', e => { if (e.target === modal) modal.style.display = 'none'; });

    function renderHosts2() {
        const q = hostFilter.value.toLowerCase();
        const filtered = allHosts2.filter(h => h.name.toLowerCase().includes(q));
        hostList.innerHTML = '';
        if (!filtered.length) { hostList.innerHTML = `<p>${T.modal_no_results}</p>`; return; }
        filtered.slice((hostPage2-1)*itemsPerPage, hostPage2*itemsPerPage).forEach(h => {
            const lbl = document.createElement('label'); lbl.className = 'chk';
            const chk = selHost && selHost.hostid === h.hostid ? 'checked' : '';
            lbl.innerHTML = `<input type="radio" name="hi_host" value="${h.hostid}" data-name="${h.name.replace(/"/g,'&quot;')}" ${chk}> ${h.name}`;
            hostList.appendChild(lbl);
        });
        renderPagination(hostPag, hostPage2, filtered.length, p => { hostPage2 = p; renderHosts2(); });
    }
    hostFilter.onkeyup = () => { hostPage2 = 1; renderHosts2(); };

    nextBtn2.onclick = () => {
        const radio = hostList.querySelector('input[type=radio]:checked');
        if (!radio) { alert(T.host_items_select_host_first); return; }
        selHost = { hostid: radio.value, name: radio.dataset.name };
        hostLabel.textContent = T.host_item_label_prefix + ': ' + selHost.name + ' (' + T.host_item_label_suffix + ')';
        step1.style.display = 'none'; step2.style.display = 'block';
        itemList2.innerHTML = `<p>${T.modal_loading}</p>`;
        fetch('get_items.php', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ action: 'get_host_items', hostid: selHost.hostid })
        }).then(r => r.json()).then(data => {
            allItems2 = Array.isArray(data) ? data : [];
            itemPage2 = 1; itemFilter2.value = ''; renderItems2();
        }).catch(() => { itemList2.innerHTML = `<p style="color:red">${T.modal_no_results}</p>`; });
    };
    backBtn2.onclick = () => { step1.style.display='block'; step2.style.display='none'; };
    itemFilter2.onkeyup = () => { itemPage2 = 1; renderItems2(); };

    const hostItemChecked = new Map(); // itemid -> name

    // Limpiar seleccion al cambiar de host
    const origNextBtn2 = nextBtn2.onclick;

    function renderItems2() {
        const q = itemFilter2.value.toLowerCase();
        const filtered = allItems2.filter(i =>
            i.name.toLowerCase().includes(q) || (i.key_||'').toLowerCase().includes(q)
        );
        itemList2.innerHTML = '';
        if (!filtered.length) {
            itemList2.innerHTML = `<p>${T.modal_no_results}</p>`;
            itemPag2.innerHTML = ''; return;
        }
        filtered.slice((itemPage2-1)*itemsPerPage, itemPage2*itemsPerPage).forEach(item => {
            const lbl = document.createElement('label'); lbl.className = 'chk';
            const badge = item.is_discovered ? ' <span class="badge">LLD</span>' : '';
            const cb = document.createElement('input');
            cb.type = 'checkbox'; cb.value = item.itemid;
            cb.dataset.name = item.name; cb.dataset.key = item.key_ || '';
            cb.checked = hostItemChecked.has(item.itemid);
            cb.addEventListener('change', function() {
                this.checked ? hostItemChecked.set(this.value, item.name) : hostItemChecked.delete(this.value);
            });
            lbl.appendChild(cb);
            lbl.insertAdjacentHTML('beforeend', ` ${item.name}${badge} <small>${item.key_||''}</small>`);
            itemList2.appendChild(lbl);
        });
        renderPagination(itemPag2, itemPage2, filtered.length, p => { itemPage2=p; renderItems2(); });
    }

    selectBtn2.onclick = () => {
        if (!hostItemChecked.size) { alert(T.alert_select_item); return; }
        const idsInput = document.getElementById('host-item-ids-hidden');
        const txtArea  = document.getElementById('host-items-textarea');
        const curIds   = idsInput.value.split(',').filter(Boolean);
        const curNames = txtArea.value ? txtArea.value.split(', ').filter(Boolean) : [];
        hostItemChecked.forEach((name, id) => {
            if (!curIds.includes(id)) { curIds.push(id); curNames.push(selHost.name + ': ' + name); }
        });
        const newVal = [...new Set(curIds)].join(',');
        // Setear en TODAS las formas posibles
        idsInput.value = newVal;
        idsInput.setAttribute('value', newVal);
        idsInput.dispatchEvent(new Event('change'));
        txtArea.value  = [...new Set(curNames)].join(', ');
        modal.style.display = 'none';
        // Forzar actualizacion del step visual directamente
        var sec = document.getElementById('step-hostitems');
        var st  = document.getElementById('st-hostitems');
        var ps  = document.getElementById('ps-3');
        var pc  = document.getElementById('pc-3');
        if (newVal && sec)  { sec.classList.add('is-filled'); }
        if (newVal && st)   { st.textContent = 'Selected'; st.className = 'status-tag status-filled'; }
        if (newVal && ps)   { ps.className = 'progress-step done'; }
        if (newVal && pc)   { pc.style.background = 'var(--green)'; }
        if (typeof updateProgress === 'function') updateProgress();
    };
  })();

  // ==================== VALIDACIONES AL SUBMIT ====================
  document.getElementById('form-export').addEventListener('submit', function(e) {
      const pdfBtn = document.getElementById('generate-pdf-btn');

      const hasHostItems = document.getElementById('host-item-ids-hidden').value.trim();
      const hasHosts = document.getElementById('hostids-hidden-input').value.trim() ||
                       document.getElementById('hostnames-textarea').value.trim() ||
                       document.getElementById('hostgroupids-hidden-input').value.trim() ||
                       hasHostItems;
      const hasItems = document.getElementById('itemkeys-hidden-input').value.trim() ||
                       document.getElementById('lldids-hidden-input').value.trim() ||
                       hasHostItems;
      const hasTime = document.getElementById('from_dt').value && document.getElementById('to_dt').value;

      const warns = [];
      if (!hasHosts) warns.push(T.validate_no_hosts);
      if (!hasItems) warns.push(T.validate_no_items);
      if (!hasTime)  warns.push(T.validate_no_time);

      if (!hasHosts || !hasItems) {
          e.preventDefault();
          alert(warns.join('\n'));
          return;
      }
      if (!hasTime) {
          const ok = confirm(T.validate_no_time_confirm);
          if (!ok) { e.preventDefault(); return; }
      }

      if (pdfBtn) { pdfBtn.disabled = true; pdfBtn.textContent = 'Generando PDF...'; }
      setTimeout(function() { window.location.reload(); }, 1500);
  });


  // STEP TOGGLE
  function toggleStep(id) {
    var sec = document.getElementById(id);
    if (!sec) return;
    sec.classList.toggle('open');
  }

  // PROGRESS TRACKER
  function v(id) {
    var el = document.getElementById(id);
    if (!el) return '';
    return (el.value || el.getAttribute('value') || el.defaultValue || '').trim();
  }

  function updateProgress() {
    var hasHosts     = v('hostids-hidden-input') || v('hostnames-textarea') || v('hostgroupids-hidden-input') || v('host-item-ids-hidden');
    var hasHostItems = v('host-item-ids-hidden');
    var hasItems     = v('itemkeys-hidden-input') || v('lldids-hidden-input') || hasHostItems;
    var hasTime      = v('from_dt') && v('to_dt');

    setStepState('ps-1','pc-1','st-hosts',    'step-hosts',    hasHosts,     'Selected','Not selected');
    setStepState('ps-2','pc-2','st-items',    'step-items',    hasItems,     'Selected','Not selected');
    setStepState('ps-3','pc-3','st-hostitems','step-hostitems',hasHostItems, 'Selected','Optional');
    setStepState('ps-4','pc-4','st-time',     'step-time',     hasTime,      'Defined', 'Not set');

    var p5 = document.getElementById('ps-5');
    if (p5) p5.className = 'progress-step active';
  }

  function setStepState(psId, pcId, stId, secId, isDone, doneLabel, emptyLabel) {
    var ps  = document.getElementById(psId);
    var pc  = document.getElementById(pcId);
    var st  = document.getElementById(stId);
    var sec = document.getElementById(secId);
    if (!ps) return;
    ps.className = 'progress-step ' + (isDone ? 'done' : '');
    if (pc) pc.style.background = isDone ? 'var(--green)' : '';
    if (st) {
      st.textContent = isDone ? doneLabel : emptyLabel;
      st.className   = 'status-tag ' + (isDone ? 'status-filled' : 'status-empty');
    }
    if (sec) {
      if (isDone) sec.classList.add('is-filled');
      else        sec.classList.remove('is-filled');
    }
  }

  // Watchers para campos de texto
  ['hostnames-textarea','hostgroups-textarea','from_dt','to_dt'].forEach(function(id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('input', updateProgress);
  });

  // Botones rapidos
  ['24h-btn','month-btn','6month-btn'].forEach(function(id) {
    var btn = document.getElementById(id);
    if (!btn) return;
    btn.addEventListener('click', function() {
      document.querySelectorAll('.btn-quick').forEach(function(b){ b.classList.remove('active'); });
      this.classList.add('active');
      setTimeout(updateProgress, 50);
    });
  });

  // Polling cada 200ms - lee directamente del DOM via defaultValue fallback
  var _prev = '';
  function pollHiddens() {
    var ids = ['hostids-hidden-input','hostgroupids-hidden-input',
               'itemkeys-hidden-input','lldids-hidden-input','host-item-ids-hidden'];
    var cur = ids.map(function(id) {
      var el = document.getElementById(id);
      if (!el) return '';
      // Leer de todas las fuentes posibles
      return el.value || el.getAttribute('value') || el.defaultValue || '';
    }).join('|');
    if (cur !== _prev) { _prev = cur; updateProgress(); }
    setTimeout(pollHiddens, 200);
  }
  pollHiddens();

  updateProgress();

</script>
</body>
</html>
