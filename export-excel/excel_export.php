<?php
declare(strict_types=1);

session_start();
if (empty($_SESSION['zbx_auth_ok'])) { header('Location: ../login.php'); exit; }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrf_token = $_SESSION['csrf_token'];
require_once __DIR__ . '/../lib/i18n.php';
require_once __DIR__ . '/../config.php';

header('Content-Type: text/html; charset=utf-8');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; script-src 'self' 'unsafe-inline';");
?>
<!doctype html>
<html lang="<?= htmlspecialchars($current_lang ?? 'es', ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= t('excel_export_title') ?></title>
<link rel="stylesheet" href="assets/excel.css">
</head>
<body class="dark-theme">

<!-- TOPBAR -->
<header class="topbar">
  <a href="../latest_data.php" class="topbar-brand">
    <?php if (defined('CUSTOM_LOGO_PATH')): ?>
      <img src="<?= htmlspecialchars(CUSTOM_LOGO_PATH, ENT_QUOTES, 'UTF-8') ?>" alt="Logo" class="custom-logo" onerror="this.style.display='none'">
    <?php endif; ?>
    <span class="zabbix-logo">ZABBIX</span>
    <span class="topbar-name">Report</span>
  </a>
  <span class="topbar-sep">|</span>
  <span class="topbar-sub"><?= t('excel_export_title') ?></span>
  <div class="topbar-spacer"></div>
  <div class="topbar-actions">
    <a href="../latest_data.php" class="btn-top">&#8592; Latest Data</a>
    <button id="theme-toggle" class="btn-top">&#9788; Light</button>
    <a href="../logout.php" class="btn-top danger">&#8594; <?= t('logout_button', 'Logout') ?></a>
  </div>
</header>

<div class="wrap">
  <div class="card">

    <div class="card-header">
      <div class="card-title"><?= t('excel_export_title') ?></div>
      <div class="card-sub"><?= t('export_logged_in_as') ?> <b><?= htmlspecialchars($_SESSION['zbx_user'] ?? '', ENT_QUOTES, 'UTF-8') ?></b></div>
    </div>

    <div class="card-body">
      <div id="excel-alert" style="display:none;padding:10px 14px;border-radius:var(--radius-sm);margin-bottom:14px;font-size:13px;background:rgba(224,60,60,.1);color:var(--red);border:1px solid rgba(224,60,60,.25)"></div>
      <form method="post" action="generate_excel.php" id="form-excel-export" target="download_iframe" novalidate>
        <input type="hidden" name="csrf_token"  value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="client_tz"   id="excel-client-tz" value="">
        <input type="hidden" name="debug_keys"  value="0">

        <!-- TIPO DE REPORTE -->
        <div class="field-group">
          <div class="field-label"><?= t('excel_export_report_type') ?></div>
          <div class="type-grid">

            <div class="type-card">
              <input type="radio" id="r-hostlist" name="report_type" value="host_list" checked>
              <label for="r-hostlist">
                <span class="type-icon">&#128444;</span>
                <div class="type-info">
                  <div class="type-name"><?= t('excel_report_type_hostlist') ?></div>
                  <div class="type-desc"><?= t('excel_hostlist_desc', 'Lista completa de hosts monitoreados') ?></div>
                </div>
              </label>
            </div>

            <div class="type-card">
              <input type="radio" id="r-inv" name="report_type" value="inventory">
              <label for="r-inv">
                <span class="type-icon">&#128203;</span>
                <div class="type-info">
                  <div class="type-name"><?= t('excel_report_type_inventory') ?></div>
                  <div class="type-desc"><?= t('excel_inventory_desc', 'Inventario de hardware y software') ?></div>
                </div>
              </label>
            </div>

            <div class="type-card">
              <input type="radio" id="r-problems" name="report_type" value="problem_report">
              <label for="r-problems">
                <span class="type-icon">&#9888;</span>
                <div class="type-info">
                  <div class="type-name"><?= t('excel_report_type_problems') ?></div>
                  <div class="type-desc"><?= t('excel_problems_desc', 'Problemas y alertas del periodo') ?></div>
                </div>
              </label>
            </div>

            <div class="type-card">
              <input type="radio" id="r-peaks" name="report_type" value="peaks_report">
              <label for="r-peaks">
                <span class="type-icon">&#128200;</span>
                <div class="type-info">
                  <div class="type-name"><?= t('excel_report_type_peaks') ?></div>
                  <div class="type-desc"><?= t('excel_peaks_desc', 'Valores pico de CPU, RAM y disco') ?></div>
                </div>
              </label>
            </div>

          </div>
        </div>

        <!-- OPCIONES INVENTARIO -->
        <div id="inv-options" class="collapse-panel">
          <div class="field-label"><?= t('excel_inventory_columns') ?></div>
          <div class="check-grid">
            <label class="check-item"><input type="checkbox" name="columns[availability]" checked><span><?= t('excel_col_availability') ?></span></label>
            <label class="check-item"><input type="checkbox" name="columns[os]" checked><span><?= t('excel_col_os') ?></span></label>
            <label class="check-item"><input type="checkbox" name="columns[device_type]" checked><span><?= t('excel_col_device_type') ?></span></label>
            <label class="check-item"><input type="checkbox" name="columns[area_responsable]" checked><span><?= t('excel_col_area') ?></span></label>
            <label class="check-item"><input type="checkbox" name="columns[localidad]" checked><span><?= t('excel_col_location') ?></span></label>
            <label class="check-item"><input type="checkbox" name="columns[uptime]" checked><span><?= t('excel_col_uptime') ?></span></label>
            <label class="check-item"><input type="checkbox" name="columns[total_ram]" checked><span><?= t('excel_col_ram_total') ?></span></label>
            <label class="check-item"><input type="checkbox" name="columns[cpu_cores]" checked><span>CPU/VCPU</span></label>
            <label class="check-item"><input type="checkbox" name="columns[cpu_stats]" checked><span><?= t('excel_col_cpu_stats') ?></span></label>
            <label class="check-item"><input type="checkbox" name="columns[mem_stats]" checked><span><?= t('excel_col_mem_stats') ?></span></label>
            <label class="check-item"><input type="checkbox" name="columns[disks]" checked><span><?= t('excel_col_disks') ?></span></label>
          </div>
        </div>

        <!-- OPCIONES PEAKS -->
        <div id="peaks-options" class="collapse-panel">
          <div class="info-box">
            <strong><?= t('excel_peaks_options') ?></strong><br>
            <?= t('excel_peaks_warning', '') ?>
          </div>
        </div>

        <!-- OPCIONES COMUNES (hosts, grupos, rango) -->
        <div id="common-options" class="collapse-panel">

          <div class="field-group">
            <div class="field-label"><?= t('export_hosts_label') ?></div>
            <div class="field-row">
              <textarea name="hostnames" id="ta-hosts" rows="2" placeholder="<?= t('export_hosts_placeholder') ?>"></textarea>
              <button type="button" class="btn-select" id="btn-hosts">+ <?= t('modal_select_button') ?></button>
            </div>
            <input type="hidden" name="hostids" id="hid-hosts">
          </div>

          <div class="field-group">
            <div class="field-label"><?= t('export_groups_label') ?></div>
            <div class="field-row">
              <textarea name="hostgroups" id="ta-groups" rows="2" placeholder="<?= t('export_groups_placeholder') ?>"></textarea>
              <button type="button" class="btn-select" id="btn-groups">+ <?= t('modal_select_button') ?></button>
            </div>
            <input type="hidden" name="hostgroupids" id="hid-groups">
          </div>

          <div class="field-group">
            <div class="field-label"><?= t('export_from_label') ?> / <?= t('export_to_label') ?></div>
            <div class="time-grid">
              <input type="datetime-local" name="from_dt" id="from_dt">
              <input type="datetime-local" name="to_dt"   id="to_dt">
            </div>
            <div class="quick-btns">
              <button type="button" class="btn-quick" id="p-24h"><?= t('export_last_24h') ?></button>
              <button type="button" class="btn-quick" id="p-1m"><?= t('export_last_month', 'Ultimo mes') ?></button>
              <button type="button" class="btn-quick" id="p-6m"><?= t('export_last_6_months', 'Ultimos 6 meses') ?></button>
            </div>
          </div>

        </div>

        <div class="actions">
          <button class="btn btn-primary" type="submit" id="btn-gen">
            &#128202; <?= t('excel_generate_button') ?>
          </button>
        </div>

      </form>
    </div>

<!-- SLA REPORT CARD -->
<div class="card" style="margin-top:16px">
  <div class="card-header">
    <div>
      <div class="card-title">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-2px;margin-right:6px"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
        <?= t('export_sla_report_link', 'SLA Compliance Report') ?>
      </div>
      <div class="card-sub"><?= t('sla_card_desc', 'Genera un reporte de cumplimiento SLA basado en disponibilidad ICMP por host') ?></div>
    </div>
    <a href="sla-report/index.php" class="btn btn-primary">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
      <?= t('sla_go_btn', 'Ir al SLA Report') ?> &#8594;
    </a>
  </div>
</div>

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
        <button type="button" class="btn btn-ghost btn-sm" id="btn-hosts-select-page"><?= t('modal_select_page_button') ?></button>
        <button type="button" class="btn btn-ghost btn-sm" id="btn-hosts-deselect-page"><?= t('modal_deselect_page_button') ?></button>
      </div>
      <div id="l-hosts" class="list-box"><div class="list-state"><?= t('modal_loading') ?></div></div>
      <div style="text-align:center"><div id="p-hosts" class="pagination-controls"></div></div>
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
        <button type="button" class="btn btn-ghost btn-sm" id="btn-groups-select-page"><?= t('modal_select_page_button') ?></button>
        <button type="button" class="btn btn-ghost btn-sm" id="btn-groups-deselect-page"><?= t('modal_deselect_page_button') ?></button>
      </div>
      <div id="l-groups" class="list-box"><div class="list-state"><?= t('modal_loading') ?></div></div>
      <div style="text-align:center"><div id="p-groups" class="pagination-controls"></div></div>
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
const $ = (s,c=document) => c.querySelector(s);
const $$ = (s,c=document) => Array.from(c.querySelectorAll(s));
const fmt = d => { const p=n=>String(n).padStart(2,'0'); return `${d.getFullYear()}-${p(d.getMonth()+1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`; };
const itemsPerPage = 10;

// ── Tema ──────────────────────────────────────────────────────────────────────
(function(){
  const toggle = document.getElementById('theme-toggle');
  const body   = document.body;
  function setTheme(t) {
    body.classList.toggle('dark-theme',  t==='dark');
    body.classList.toggle('light-theme', t!=='dark');
    toggle.textContent = t==='dark' ? '☀ Light' : '🌙 Dark';
    localStorage.setItem('zbx-theme', t);
  }
  toggle.addEventListener('click', () => setTheme(body.classList.contains('dark-theme') ? 'light' : 'dark'));
  setTheme(localStorage.getItem('zbx-theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));
})();

// ── Tipo de reporte → mostrar paneles ─────────────────────────────────────────
const SHOW_INV    = ['inventory'];
const SHOW_PEAKS  = ['peaks_report'];
const SHOW_COMMON = ['inventory','problem_report','peaks_report'];

function updatePanels() {
  const val = $('input[name=report_type]:checked')?.value || 'host_list';
  $('#inv-options').classList.toggle('open',    SHOW_INV.includes(val));
  $('#peaks-options').classList.toggle('open',  SHOW_PEAKS.includes(val));
  $('#common-options').classList.toggle('open', SHOW_COMMON.includes(val));
}
$$('input[name=report_type]').forEach(r => r.addEventListener('change', updatePanels));

// ── Quick time buttons ────────────────────────────────────────────────────────
function setRange(hours) {
  const now=new Date(), from=new Date(now-hours*3600000);
  $('#from_dt').value = fmt(from);
  $('#to_dt').value   = fmt(now);
}
$('#p-24h')?.addEventListener('click', () => setRange(24));
$('#p-1m')?.addEventListener('click',  () => setRange(720));
$('#p-6m')?.addEventListener('click',  () => setRange(4320));

// ── Paginacion ────────────────────────────────────────────────────────────────
function renderPagination(container, currentPage, totalItems, onPageClick) {
  container.innerHTML = '';
  const pages = Math.ceil(totalItems / itemsPerPage);
  if (pages <= 1) return;
  const mkBtn = (label, page) => {
    const btn = document.createElement('button');
    btn.innerHTML = label;
    if (page) { btn.dataset.page = page; if (page===currentPage) btn.classList.add('active'); btn.addEventListener('click', e=>{e.preventDefault();onPageClick(page);}); }
    else btn.disabled = true;
    return btn;
  };
  const mkDot = () => { const s=document.createElement('span'); s.className='ellipsis'; s.textContent='...'; return s; };
  container.appendChild(mkBtn('&laquo;', currentPage>1 ? currentPage-1 : null));
  const set = new Set([1, totalPages, currentPage, currentPage-1, currentPage+1].filter(p=>p>=1&&p<=pages));
  const sorted = [...set].sort((a,b)=>a-b);
  let last=0;
  sorted.forEach(p=>{ if(p>last+1) container.appendChild(mkDot()); container.appendChild(mkBtn(String(p),p)); last=p; });
  container.appendChild(mkBtn('&raquo;', currentPage<pages ? currentPage+1 : null));
}

// ── Modal picker ──────────────────────────────────────────────────────────────
function modalPicker({id, btn, list, filter, ok, ta, hid, url, key, pagination, selectPage, deselectPage}) {
  const modal      = document.getElementById(id);
  const openBtn    = document.getElementById(btn);
  const listEl     = document.getElementById(list);
  const filterEl   = document.getElementById(filter);
  const okBtn      = document.getElementById(ok);
  const taEl       = document.getElementById(ta);
  const hidEl      = document.getElementById(hid);
  const pagEl      = document.getElementById(pagination);
  const btnSelPage = document.getElementById(selectPage);
  const btnDeselPage=document.getElementById(deselectPage);
  const closeBtns  = $$('[data-close="'+id+'"]');

  let allData=[], currentPage=1;
  const sel = new Map();

  const close = () => modal.classList.remove('open');
  const open  = () => {
    modal.classList.add('open');
    if (!allData.length) {
      listEl.innerHTML = '<div class="list-state">'+T.modal_loading+'</div>';
      fetch(url+'?_='+Date.now(),{cache:'no-store'})
        .then(r=>r.json()).then(d=>{ allData=Array.isArray(d)?d:[]; currentPage=1; render(); })
        .catch(()=>{ listEl.innerHTML='<div class="list-state">Error</div>'; });
    } else { currentPage=1; render(filterEl.value); }
  };

  function render(q='') {
    const filtered = q ? allData.filter(i=>i.name.toLowerCase().includes(q.toLowerCase())) : allData;
    listEl.innerHTML='';
    const page = filtered.slice((currentPage-1)*itemsPerPage, currentPage*itemsPerPage);
    if (!page.length) { listEl.innerHTML='<div class="list-state">'+T.modal_no_results+'</div>'; }
    page.forEach(item => {
      const id_ = item[key], name=item.name;
      const lbl = document.createElement('label');
      lbl.className='list-item';
      const cb=document.createElement('input');
      cb.type='checkbox'; cb.value=id_; cb.dataset.name=name;
      cb.checked=sel.has(id_);
      cb.addEventListener('change',()=>{ cb.checked ? sel.set(id_,name) : sel.delete(id_); });
      lbl.appendChild(cb);
      lbl.appendChild(document.createTextNode(' '+name));
      listEl.appendChild(lbl);
    });
    renderPagination(pagEl, currentPage, filtered.length, p=>{ currentPage=p; render(filterEl.value); });
  }

  openBtn?.addEventListener('click', open);
  filterEl?.addEventListener('input', ()=>{ currentPage=1; render(filterEl.value); });
  okBtn?.addEventListener('click', ()=>{
    hidEl.value = [...sel.keys()].join(',');
    taEl.value  = [...sel.values()].join(', ');
    close();
  });
  closeBtns.forEach(b=>b.addEventListener('click', close));
  btnSelPage?.addEventListener('click', ()=> $$('input[type=checkbox]',listEl).forEach(cb=>{ cb.checked=true; sel.set(cb.value,cb.dataset.name); }));
  btnDeselPage?.addEventListener('click',()=> $$('input[type=checkbox]',listEl).forEach(cb=>{ cb.checked=false; sel.delete(cb.value); }));
  window.addEventListener('keydown', e=>{ if(e.key==='Escape') close(); });
  window.addEventListener('click',   e=>{ if(e.target===modal) close(); });
}

modalPicker({id:'m-hosts', btn:'btn-hosts', list:'l-hosts', filter:'f-hosts', ok:'ok-hosts', ta:'ta-hosts', hid:'hid-hosts', url:'../get_hosts.php', key:'hostid', pagination:'p-hosts', selectPage:'btn-hosts-select-page', deselectPage:'btn-hosts-deselect-page'});
modalPicker({id:'m-groups', btn:'btn-groups', list:'l-groups', filter:'f-groups', ok:'ok-groups', ta:'ta-groups', hid:'hid-groups', url:'../get_host_groups.php', key:'groupid', pagination:'p-groups', selectPage:'btn-groups-select-page', deselectPage:'btn-groups-deselect-page'});

// ── Submit ────────────────────────────────────────────────────────────────────
document.getElementById('excel-client-tz').value = Intl.DateTimeFormat().resolvedOptions().timeZone;
$('#form-excel-export')?.addEventListener('submit', (e) => {
  const alertEl  = document.getElementById('excel-alert');
  const reportType = document.querySelector('input[name=report_type]:checked')?.value || 'host_list';
  const needsHosts = ['inventory','problem_report','peaks_report'].includes(reportType);
  const hosts  = $('#ta-hosts')?.value.trim();
  const groups = $('#ta-groups')?.value.trim();
  if (needsHosts && !hosts && !groups) {
    e.preventDefault();
    alertEl.textContent = T.excel_warn_no_hosts || '<?= t('excel_warn_no_hosts','Debes seleccionar al menos un host o grupo de hosts antes de generar el reporte.') ?>';
    alertEl.style.display = 'block';
    alertEl.scrollIntoView({behavior:'smooth', block:'center'});
    return;
  }
  alertEl.style.display = 'none';
  const btn = $('#btn-gen');
  if (btn) { btn.disabled=true; btn.textContent=T.excel_generating_button||'Generating...'; }
  setTimeout(()=>window.location.reload(true), 1500);
});
</script>
</body>
</html>
