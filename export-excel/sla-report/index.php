<?php
declare(strict_types=1);

session_start();
if (empty($_SESSION['zbx_auth_ok'])) { header('Location: ../../login.php'); exit; }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrf_token = $_SESSION['csrf_token'];
require_once __DIR__ . '/../../lib/i18n.php';
require_once __DIR__ . '/../../config.php';

header('Content-Type: text/html; charset=utf-8');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; script-src 'self' 'unsafe-inline';");
?>
<!doctype html>
<html lang="<?= htmlspecialchars($current_lang ?? 'es', ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= t('sla_title') ?></title>
<link rel="stylesheet" href="../assets/sla.css">
</head>
<body class="dark-theme">

<!-- TOPBAR -->
<header class="topbar" style="position:sticky;top:0;z-index:200">
  <a href="../../latest_data.php" class="topbar-brand">
    <?php if (defined('CUSTOM_LOGO_PATH')): ?>
      <img src="<?= htmlspecialchars(CUSTOM_LOGO_PATH, ENT_QUOTES, 'UTF-8') ?>" alt="Logo" class="custom-logo" onerror="this.style.display='none'">
    <?php endif; ?>
    <span class="zabbix-logo">ZABBIX</span>
    <span class="topbar-name">Report</span>
  </a>
  <span class="topbar-sep">|</span>
  <span class="topbar-sub"><?= t('sla_header') ?></span>
  <div class="topbar-spacer"></div>
  <div class="topbar-actions">
    <a href="../excel_export.php" class="btn-top">&#8592; <?= t('excel_export_title') ?></a>
    <button id="theme-toggle" class="btn-top">&#9788; Light</button>
    <a href="../../logout.php" class="btn-top danger">&#8594; <?= t('logout_button','Logout') ?></a>
  </div>
</header>

<div class="wrap">
  <div class="card">

    <div class="card-header">
      <div class="card-title"><?= t('sla_header') ?></div>
      <div class="card-sub"><?= t('sla_logged_in_as') ?> <b><?= htmlspecialchars($_SESSION['zbx_user'] ?? '', ENT_QUOTES, 'UTF-8') ?></b></div>
    </div>

    <div class="card-body">
      <div id="sla-alert" style="display:none;padding:10px 14px;border-radius:11px;margin-bottom:14px;font-size:13px;background:rgba(224,60,60,.1);color:var(--red);border:1px solid rgba(224,60,60,.25)"></div>
      <form method="post" action="generate_sla_report.php" id="form-sla-report" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="client_tz" id="sla-client-tz" value="">

        <!-- SLA Target -->
        <div class="field-group">
          <div class="field-label"><?= t('sla_form_target') ?></div>
          <input type="number" name="sla_target" value="99.9" step="0.01" min="0" max="100" required style="max-width:160px">
        </div>

        <!-- Hosts -->
        <div class="field-group">
          <div class="field-label"><?= t('sla_form_hosts') ?></div>
          <div class="field-row">
            <textarea name="hostnames" id="ta-hosts" rows="2" placeholder="<?= t('sla_form_hosts_placeholder') ?>"></textarea>
            <button type="button" class="btn-select" id="btn-hosts">+ <?= t('modal_select_button') ?></button>
          </div>
          <input type="hidden" name="hostids" id="hid-hosts">
        </div>

        <!-- Grupos -->
        <div class="field-group">
          <div class="field-label"><?= t('sla_form_groups') ?></div>
          <div class="field-row">
            <textarea name="hostgroups" id="ta-groups" rows="2" placeholder="<?= t('sla_form_hosts_placeholder') ?>"></textarea>
            <button type="button" class="btn-select" id="btn-groups">+ <?= t('modal_select_button') ?></button>
          </div>
          <input type="hidden" name="hostgroupids" id="hid-groups">
        </div>

        <!-- Rango de tiempo -->
        <div class="field-group">
          <div class="field-label"><?= t('sla_form_from') ?> / <?= t('sla_form_to') ?></div>
          <div class="time-grid">
            <input type="datetime-local" name="from_dt" id="from_dt">
            <input type="datetime-local" name="to_dt"   id="to_dt">
          </div>
          <div class="quick-btns">
            <button type="button" class="btn-quick" id="p-24h"><?= t('sla_time_24h') ?></button>
            <button type="button" class="btn-quick" id="p-1m"><?= t('sla_time_1m') ?></button>
            <button type="button" class="btn-quick" id="p-6m"><?= t('sla_time_6m') ?></button>
          </div>
        </div>

        <!-- Formato de salida -->
        <div class="field-group">
          <div class="field-label"><?= t('sla_form_output_format') ?></div>
          <div class="format-grid">
            <div class="format-card">
              <input type="radio" id="r-view" name="output_format" value="view" checked>
              <label for="r-view">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                <?= t('sla_output_view') ?>
              </label>
            </div>
            <div class="format-card">
              <input type="radio" id="r-excel" name="output_format" value="excel">
              <label for="r-excel">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg>
                <?= t('sla_output_excel') ?>
              </label>
            </div>
          </div>
        </div>

        <div class="actions">
          <button class="btn btn-primary" type="submit" id="btn-gen">
            &#128200; <?= t('sla_generate_button') ?>
          </button>
        </div>

      </form>
    </div>

    <div class="credit">
<div style="text-align:center;padding:28px 20px 20px;font-family:var(--font,system-ui)">
  <div style="font-size:13px;color:var(--text2,#666);margin-bottom:12px"><?= t('common_author_credit') ?></div>
  <div style="display:inline-flex;align-items:center;gap:16px;flex-wrap:wrap;justify-content:center">
    <a href="https://www.linkedin.com/in/axel-del-canto-del-canto-4ba643186/" target="_blank" rel="noopener"
       style="display:inline-flex;align-items:center;gap:7px;text-decoration:none;color:var(--text2,#666);font-size:13px;font-weight:500;padding:7px 14px;border-radius:8px;border:1px solid var(--divider,#ddd);background:var(--card2,#f5f5f5);transition:all .15s"
       onmouseover="this.style.borderColor='#0077b5';this.style.color='#0077b5'"
       onmouseout="this.style.borderColor='var(--divider,#ddd)';this.style.color='var(--text2,#666)'">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>
      LinkedIn
    </a>
    <a href="https://github.com/axel250r" target="_blank" rel="noopener"
       style="display:inline-flex;align-items:center;gap:7px;text-decoration:none;color:var(--text2,#666);font-size:13px;font-weight:500;padding:7px 14px;border-radius:8px;border:1px solid var(--divider,#ddd);background:var(--card2,#f5f5f5);transition:all .15s"
       onmouseover="this.style.borderColor='var(--text,#111)';this.style.color='var(--text,#111)'"
       onmouseout="this.style.borderColor='var(--divider,#ddd)';this.style.color='var(--text2,#666)'">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.374 0 0 5.373 0 12c0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23A11.509 11.509 0 0112 5.803c1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576C20.566 21.797 24 17.3 24 12c0-6.627-5.373-12-12-12z"/></svg>
      GitHub
    </a>
  </div>
</div>

    </div>
  </div>
</div>

<!-- MODAL HOSTS -->
<div id="m-hosts" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <span class="modal-title"><?= t('modal_select_hosts_title') ?></span>
      <button class="modal-close" data-close="m-hosts">&times;</button>
    </div>
    <div class="modal-body">
      <div class="modal-filter-row">
        <input type="text" id="f-hosts" class="modal-filter" placeholder="<?= t('modal_filter_hosts_placeholder') ?>">
        <button type="button" class="btn btn-ghost btn-sm" id="select-all-hosts"><?= t('modal_select_all','Select all') ?></button>
        <button type="button" class="btn btn-ghost btn-sm" id="deselect-all-hosts"><?= t('modal_deselect_all','Deselect') ?></button>
      </div>
      <div id="l-hosts" class="list-box"><div class="list-state"><?= t('modal_loading') ?></div></div>
      <div style="text-align:center"><div id="pagination-hosts" class="pagination-controls"></div></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-ghost" data-close="m-hosts"><?= t('modal_cancel_button') ?></button>
      <button type="button" class="btn btn-primary" id="ok-hosts"><?= t('modal_select_button') ?></button>
    </div>
  </div>
</div>

<!-- MODAL GRUPOS -->
<div id="m-groups" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <span class="modal-title"><?= t('modal_select_groups_title') ?></span>
      <button class="modal-close" data-close="m-groups">&times;</button>
    </div>
    <div class="modal-body">
      <div class="modal-filter-row">
        <input type="text" id="f-groups" class="modal-filter" placeholder="<?= t('modal_filter_groups_placeholder') ?>">
        <button type="button" class="btn btn-ghost btn-sm" id="select-all-groups"><?= t('modal_select_all','Select all') ?></button>
        <button type="button" class="btn btn-ghost btn-sm" id="deselect-all-groups"><?= t('modal_deselect_all','Deselect') ?></button>
      </div>
      <div id="l-groups" class="list-box"><div class="list-state"><?= t('modal_loading') ?></div></div>
      <div style="text-align:center"><div id="pagination-groups" class="pagination-controls"></div></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-ghost" data-close="m-groups"><?= t('modal_cancel_button') ?></button>
      <button type="button" class="btn btn-primary" id="ok-groups"><?= t('modal_select_button') ?></button>
    </div>
  </div>
</div>

<iframe name="download_iframe" style="display:none"></iframe>

<script>
    const T = <?= json_encode($translations) ?>;

    const $ = (s, c = document) => c.querySelector(s);
    const $$ = (s, c = document) => Array.from(c.querySelectorAll(s));
    const fmt = (d) => { const p = n => String(n).padStart(2, '0'); return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`; };
    const noStore = (u) => { const url = new URL(u, location.href); url.searchParams.set('_', Date.now()); return fetch(url.toString(), { cache: 'no-store' }); };
    
    (function (){ const now=new Date(), from=new Date(now.getTime()-24*60*60*1000); if ($('#to_dt')) $('#to_dt').value = fmt(now); if ($('#from_dt')) $('#from_dt').value = fmt(from); })();
    
    $('#p-24h')?.addEventListener('click', () => { const now=new Date(), from=new Date(now.getTime()-24*60*60*1000); $('#from_dt').value=fmt(from); $('#to_dt').value=fmt(now); });
    $('#p-1m')?.addEventListener('click', () => { const now=new Date(), from=new Date(now); from.setMonth(now.getMonth()-1); $('#from_dt').value=fmt(from); $('#to_dt').value=fmt(now); });
    $('#p-6m')?.addEventListener('click', () => { const now=new Date(), from=new Date(now); from.setMonth(now.getMonth()-6); $('#from_dt').value=fmt(from); $('#to_dt').value=fmt(now); });

    // Enviar timezone del browser
    document.getElementById('sla-client-tz').value = Intl.DateTimeFormat().resolvedOptions().timeZone;

    document.getElementById('form-sla-report').addEventListener('submit', (e) => {
        const alertEl = document.getElementById('sla-alert');
        const hosts   = document.getElementById('ta-hosts')?.value.trim();
        const groups  = document.getElementById('ta-groups')?.value.trim();
        if (!hosts && !groups) {
            e.preventDefault();
            alertEl.textContent = T.sla_warn_no_hosts || '<?= t('sla_warn_no_hosts','Debes seleccionar al menos un host o grupo antes de generar el reporte SLA.') ?>';
            alertEl.style.display = 'block';
            alertEl.scrollIntoView({behavior:'smooth', block:'center'});
            return;
        }
        alertEl.style.display = 'none';
        const format = document.querySelector('input[name="output_format"]:checked').value;
        const btn = document.getElementById('btn-gen');

        if (format === 'view') { 
            e.currentTarget.target = '_blank';
        } else { 
            e.currentTarget.target = 'download_iframe';
        }
        
        if (btn) {
            btn.disabled = true;
            btn.textContent = T.sla_generating_button || 'Generating...';
        }

        setTimeout(() => {
            window.location.reload(true);
        }, 1500);
    });

    function PaginatedModalPicker(cfg) {
        const modal = $(`#${cfg.id}`), 
              btnOpen = $(`#${cfg.btn}`), 
              list = $(`#${cfg.list}`), 
              filter = $(`#${cfg.filter}`), 
              btnOk = $(`#${cfg.ok}`), 
              outText = $(`#${cfg.ta}`), 
              outIds = $(`#${cfg.hid}`), 
              closeBtns = $$(`[data-close="${cfg.id}"], .close`, modal),
              selectAllBtn = $(`#select-all-${cfg.type}`),
              deselectAllBtn = $(`#deselect-all-${cfg.type}`),
              paginationDiv = $(`#pagination-${cfg.type}`);
        
        let data = [];
        let filteredData = [];
        let lastFocus = null;
        let currentPage = 1;
        const itemsPerPage = 10;
        
        function attachPaginationEvents() {
            $$('.page-btn', paginationDiv).forEach(btn => {
                btn.removeEventListener('click', handlePageClick);
                btn.addEventListener('click', handlePageClick);
            });
        }
        
        function handlePageClick(e) {
            const btn = e.currentTarget;
            if (btn.disabled) return;
            
            const page = parseInt(btn.dataset.page);
            if (!isNaN(page)) {
                currentPage = page;
                renderPage();
            }
        }
        
        function renderPage(page = currentPage) {
            const q = filter ? filter.value.toLowerCase() : '';
            filteredData = data.filter(it => it.name.toLowerCase().includes(q));
            
            const start = (page - 1) * itemsPerPage;
            const end = start + itemsPerPage;
            const pageItems = filteredData.slice(start, end);
            const totalPages = Math.ceil(filteredData.length / itemsPerPage) || 1;
            
            if (page > totalPages) {
                currentPage = totalPages;
                return renderPage(totalPages);
            }
            
            list.innerHTML = '';
            
            if (!pageItems.length) {
                list.innerHTML = `<div class="state">${T.modal_no_results || 'No results'}</div>`;
                paginationDiv.innerHTML = '';
                return;
            }
            
            const frag = document.createDocumentFragment();
            pageItems.forEach(it => {
                const lab = document.createElement('label');
                lab.className = 'item';
                lab.innerHTML = `<input type="checkbox" value="${it.id}" data-name="${it.name}" ${it.checked ? 'checked' : ''}> ${it.name}`;
                frag.appendChild(lab);
            });
            list.appendChild(frag);
            
            // Render pagination
            renderPagination(totalPages, page);
            attachPaginationEvents();
        }
        
        function renderPagination(totalPages, currentPage) {
            if (totalPages <= 1) {
                paginationDiv.innerHTML = '';
                return;
            }
            
            let html = '';
            
            // Previous button
            html += `<button class="page-btn" data-page="${currentPage - 1}" ${currentPage === 1 ? 'disabled' : ''}>&laquo;</button>`;
            
            // Page numbers
            let startPage = Math.max(1, currentPage - 2);
            let endPage = Math.min(totalPages, startPage + 4);
            
            if (endPage - startPage < 4) {
                startPage = Math.max(1, endPage - 4);
            }
            
            for (let i = startPage; i <= endPage; i++) {
                html += `<button class="page-btn ${i === currentPage ? 'active' : ''}" data-page="${i}">${i}</button>`;
            }
            
            // Next button
            html += `<button class="page-btn" data-page="${currentPage + 1}" ${currentPage === totalPages ? 'disabled' : ''}>&raquo;</button>`;
            
            paginationDiv.innerHTML = html;
        }
        
        function selectAll() {
            $$('input[type="checkbox"]', list).forEach(cb => {
                cb.checked = true;
                // Update data array
                const itemId = cb.value;
                const item = data.find(d => String(d.id) === itemId);
                if (item) item.checked = true;
            });
        }
        
        function deselectAll() {
            $$('input[type="checkbox"]', list).forEach(cb => {
                cb.checked = false;
                // Update data array
                const itemId = cb.value;
                const item = data.find(d => String(d.id) === itemId);
                if (item) item.checked = false;
            });
        }
        
        function updateCheckedStateFromList() {
            const cbs = $$('input[type="checkbox"]', list);
            cbs.forEach(cb => {
                const itemId = cb.value;
                const item = data.find(d => String(d.id) === itemId);
                if (item) item.checked = cb.checked;
            });
        }
        
        function open() {
            lastFocus = document.activeElement;
            modal.style.display = 'flex'; modal.classList.add('open');
            
            if (!data.length) {
                list.innerHTML = `<div class="state">${T.modal_loading || 'Loading...'}</div>`;
                noStore(cfg.url).then(r => r.ok ? r.json() : Promise.reject()).then(arr => {
                    const src = Array.isArray(arr) ? arr : [];
                    data = src.map(x => ({ 
                        id: x[cfg.key], 
                        name: x.name || x.host || String(x[cfg.key]),
                        checked: false
                    })).filter(x => x.id && x.name);
                    
                    // Preserve selected items from hidden input
                    const selectedIds = outIds.value.split(',').filter(id => id);
                    data.forEach(item => {
                        if (selectedIds.includes(String(item.id))) {
                            item.checked = true;
                        }
                    });
                    
                    currentPage = 1;
                    renderPage();
                    setTimeout(() => filter?.focus(), 0);
                }).catch(() => {
                    list.innerHTML = `<div class="state" style="color:#b91c1c">${T.modal_load_error || 'Error loading data'}</div>`;
                    paginationDiv.innerHTML = '';
                });
            } else {
                // Reset checked state from hidden input
                const selectedIds = outIds.value.split(',').filter(id => id);
                data.forEach(item => {
                    item.checked = selectedIds.includes(String(item.id));
                });
                renderPage();
                setTimeout(() => filter?.focus(), 0);
            }
        }
        
        function close() {
            modal.style.display = 'none'; modal.classList.remove('open');
            lastFocus?.focus();
        }
        
        // Event listeners
        btnOpen?.addEventListener('click', open);
        
        filter?.addEventListener('input', () => {
            currentPage = 1;
            renderPage();
            attachPaginationEvents();
        });
        
        selectAllBtn?.addEventListener('click', () => {
            selectAll();
        });
        
        deselectAllBtn?.addEventListener('click', () => {
            deselectAll();
        });
        
        // Update data array when checkboxes change
        list.addEventListener('change', (e) => {
            if (e.target.type === 'checkbox') {
                updateCheckedStateFromList();
            }
        });
        
        btnOk?.addEventListener('click', () => {
            const cbs = $$('input[type="checkbox"]:checked', list);
            outText.value = cbs.map(c => c.dataset.name).join(', ');
            outIds.value = cbs.map(c => c.value).join(',');
            
            // Update checked state in data
            data.forEach(item => {
                item.checked = cbs.some(cb => cb.value === String(item.id));
            });
            
            close();
        });
        
        closeBtns.forEach(b => b.addEventListener('click', close));
        window.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
    }

    // Initialize pickers with pagination
    PaginatedModalPicker({ 
        id: 'm-hosts', 
        btn: 'btn-hosts', 
        list: 'l-hosts', 
        filter: 'f-hosts', 
        ok: 'ok-hosts', 
        ta: 'ta-hosts', 
        hid: 'hid-hosts', 
        url: '../../get_hosts.php', 
        key: 'hostid',
        type: 'hosts'
    });
    
    PaginatedModalPicker({ 
        id: 'm-groups', 
        btn: 'btn-groups', 
        list: 'l-groups', 
        filter: 'f-groups', 
        ok: 'ok-groups', 
        ta: 'ta-groups', 
        hid: 'hid-groups', 
        url: '../../get_host_groups.php', 
        key: 'groupid',
        type: 'groups'
    });
</script>

<script>
// ── Tema ──────────────────────────────────────────────────────────────────────
(function(){
  const toggle = document.getElementById('theme-toggle');
  const body   = document.body;
  function setTheme(t) {
    body.classList.toggle('dark-theme',  t==='dark');
    body.classList.toggle('light-theme', t!=='dark');
    toggle.textContent = t==='dark' ? '\u2600 Light' : '\ud83c\udf19 Dark';
    localStorage.setItem('zbx-theme', t);
  }
  toggle.addEventListener('click', () => setTheme(body.classList.contains('dark-theme') ? 'light' : 'dark'));
  setTheme(localStorage.getItem('zbx-theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));
})();

// ── Quick time buttons ────────────────────────────────────────────────────────
const fmt2 = d => { const p=n=>String(n).padStart(2,'0'); return `${d.getFullYear()}-${p(d.getMonth()+1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`; };
document.getElementById('p-24h')?.addEventListener('click', () => { const n=new Date(); document.getElementById('from_dt').value=fmt2(new Date(n-86400000)); document.getElementById('to_dt').value=fmt2(n); });
document.getElementById('p-1m')?.addEventListener('click',  () => { const n=new Date(); document.getElementById('from_dt').value=fmt2(new Date(n-2592000000)); document.getElementById('to_dt').value=fmt2(n); });
document.getElementById('p-6m')?.addEventListener('click',  () => { const n=new Date(); document.getElementById('from_dt').value=fmt2(new Date(n-15552000000)); document.getElementById('to_dt').value=fmt2(n); });

// ── Modal open/close fix para nuevo CSS (usa clase .open) ─────────────────────
document.querySelectorAll('[data-close]').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById(btn.dataset.close)?.classList.remove('open');
  });
});
</script>
</body>
</html>
