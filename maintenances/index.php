<?php
declare(strict_types=1);

session_start();
if (empty($_SESSION['zbx_auth_ok'])) { header('Location: ../login.php'); exit; }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/ZabbixApiFactory.php';

try {
    $api = ZabbixApiFactory::create(ZABBIX_API_URL, $_SESSION['zbx_user'], $_SESSION['zbx_pass'], ['timeout' => 10, 'verify_ssl' => VERIFY_SSL]);
    $current_user_type = $api->getUserType($_SESSION['zbx_user']);
    if (!isset($_SESSION['zbx_user_type']) || $_SESSION['zbx_user_type'] != $current_user_type) {
        $_SESSION['zbx_user_type'] = $current_user_type;
    }
} catch (Throwable $e) {
    error_log("Error user type: " . $e->getMessage());
    $_SESSION['zbx_user_type'] = $_SESSION['zbx_user_type'] ?? 1;
}

if (!isset($_SESSION['zabbix_version'])) {
    try {
        $ch = curl_init(rtrim(ZABBIX_API_URL, '/'));
        $payload = json_encode(['jsonrpc'=>'2.0','method'=>'apiinfo.version','params'=>[],'id'=>1]);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$payload, CURLOPT_HTTPHEADER=>['Content-Type: application/json-rpc'], CURLOPT_TIMEOUT=>5, CURLOPT_SSL_VERIFYPEER=>false, CURLOPT_SSL_VERIFYHOST=>0]);
        $resp = curl_exec($ch); $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($httpCode === 200 && $resp) {
            $data = json_decode($resp, true);
            if (isset($data['result'])) $_SESSION['zabbix_version'] = $data['result'];
        }
    } catch (Throwable $e) { error_log("Error detectando versión: " . $e->getMessage()); }
}

if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrf_token = $_SESSION['csrf_token'];

require_once __DIR__ . '/../lib/i18n.php';

$user_type      = $_SESSION['zbx_user_type'] ?? 1;
$zabbix_version = $_SESSION['zabbix_version'] ?? '6.0.0';

if (version_compare($zabbix_version, '6.4', '<')) {
    $can_create   = ($user_type == 1 || $user_type >= 2);
    $is_super_admin = ($user_type == 1 || $user_type == 3);
} else {
    $can_create   = ($user_type >= 2);
    $is_super_admin = ($user_type == 3);
}

$translations_js = [
    'modal_loading'       => t('modal_loading'),
    'maint_list_empty'    => t('maint_list_empty'),
    'maint_list_error'    => t('maint_list_error'),
    'maint_list_add_hosts'=> t('maint_list_add_hosts'),
    'maint_list_export'   => t('maint_list_export'),
    'maint_status_active' => t('maint_status_active'),
    'maint_status_future' => t('maint_status_future'),
    'maint_status_expired'=> t('maint_status_expired'),
];
?>
<!doctype html>
<html lang="<?= htmlspecialchars($current_lang ?? 'es', ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= t('maint_title') ?></title>
<link rel="stylesheet" href="../assets/css/maintenances.css">
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
  <span class="topbar-sub"><?= t('maint_title_long') ?></span>
  <div class="topbar-spacer"></div>
  <div class="topbar-actions">
    <a href="../latest_data.php" class="btn-top">&#8592; <?= t('maint_back_button','Volver') ?></a>
    <button id="theme-toggle" class="btn-top">&#9788; Light</button>
    <a href="../logout.php" class="btn-top danger">&#8594; <?= t('logout_button','Logout') ?></a>
  </div>
</header>

<div class="wrap">

  <!-- LISTA DE MANTENIMIENTOS -->
  <div class="card">
    <div class="card-header">
      <div>
        <div class="card-title"><?= t('maint_list_all_title') ?></div>
        <div class="card-sub"><?= t('maint_list_desc') ?></div>
      </div>
      <?php if ($can_create): ?>
        <a href="create_maintenance.php" class="btn btn-primary">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          <?= t('maint_create_new_btn') ?>
        </a>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <div class="table-wrap">
        <table id="maint-table">
          <thead>
            <tr>
              <th><?= t('maint_list_name') ?></th>
              <th><?= t('maint_list_status') ?></th>
              <th><?= t('maint_list_start') ?></th>
              <th><?= t('maint_list_end') ?></th>
              <th><?= t('maint_list_hosts') ?></th>
              <th><?= t('maint_list_actions') ?></th>
            </tr>
          </thead>
          <tbody id="maint-table-body">
            <tr><td colspan="6" style="text-align:center;padding:32px;color:var(--text3)"><?= t('modal_loading') ?></td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- CREDIT -->
  <div class="credit">
    <div class="credit-text"><?= t('common_author_credit') ?></div>
    <div class="credit-links">
      <a href="https://www.linkedin.com/in/axel-del-canto-del-canto-4ba643186/" target="_blank" rel="noopener" class="credit-link linkedin">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>
        LinkedIn
      </a>
      <a href="https://github.com/axel250r" target="_blank" rel="noopener" class="credit-link">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.374 0 0 5.373 0 12c0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23A11.509 11.509 0 0112 5.803c1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576C20.566 21.797 24 17.3 24 12c0-6.627-5.373-12-12-12z"/></svg>
        GitHub
      </a>
    </div>
  </div>

</div>

<!-- MODAL ADD HOSTS -->
<?php if ($is_super_admin): ?>
<div id="m-add-hosts" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <span class="modal-title"><?= t('maint_modal_add_title') ?></span>
      <button class="modal-close" data-close="m-add-hosts">&times;</button>
    </div>
    <form method="post" action="manage_maintenance.php" id="form-maint-update">
      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action_type" value="update">
        <input type="hidden" name="maintenanceid" id="modal-maintenanceid">
        <div class="field-group">
          <label class="field-label"><?= t('maint_modal_add_desc') ?>: <strong id="modal-maint-name"></strong></label>
          <textarea name="hostnames" placeholder="<?= t('maint_hosts_placeholder') ?>" required style="min-height:120px"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" data-close="m-add-hosts"><?= t('modal_cancel_button') ?></button>
        <button type="submit" class="btn btn-primary"><?= t('maint_modal_add_btn') ?></button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
const T    = <?= json_encode($translations_js) ?>;
const CSRF = <?= json_encode($csrf_token) ?>;
const canCreate    = <?= json_encode($can_create) ?>;
const isSuperAdmin = <?= json_encode($is_super_admin) ?>;

// ── Tema ──────────────────────────────────────────────────────────────────────
(function(){
  const btn = document.getElementById('theme-toggle');
  const body = document.body;
  function setTheme(t) {
    body.classList.toggle('dark-theme',  t==='dark');
    body.classList.toggle('light-theme', t!=='dark');
    btn.textContent = t==='dark' ? '☀ Light' : '🌙 Dark';
    localStorage.setItem('zbx-theme', t);
  }
  btn.addEventListener('click', () => setTheme(body.classList.contains('dark-theme') ? 'light' : 'dark'));
  setTheme(localStorage.getItem('zbx-theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));
})();

// ── Modales ───────────────────────────────────────────────────────────────────
document.querySelectorAll('[data-close]').forEach(btn => {
  btn.addEventListener('click', () => {
    const m = document.getElementById(btn.dataset.close);
    if (m) { m.style.display = 'none'; m.classList.remove('open'); }
  });
});
window.addEventListener('click', e => {
  if (e.target.classList.contains('modal')) {
    e.target.style.display = 'none'; e.target.classList.remove('open');
  }
});

// ── Cargar tabla ──────────────────────────────────────────────────────────────
function loadMaintenanceTable() {
  const tbody = document.getElementById('maint-table-body');
  tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:32px;color:var(--text3)">' + (T.modal_loading||'Loading...') + '</td></tr>';
  fetch('ajax_get_maintenances.php?_t=' + Date.now())
    .then(r => r.ok ? r.json() : Promise.reject(r.statusText))
    .then(data => {
      tbody.innerHTML = '';
      if (!data || !data.length) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:32px;color:var(--text3)">' + (T.maint_list_empty||'No maintenance found.') + '</td></tr>';
        return;
      }
      data.forEach(m => {
        const tr = document.createElement('tr');
        const addHostsBtn = isSuperAdmin
          ? `<button class="btn btn-sm btn-blue btn-add-hosts" data-id="${m.maintenanceid}" data-name="${escHtml(m.name)}">${T.maint_list_add_hosts||'Add Hosts'}</button>`
          : '';
        tr.innerHTML = `
          <td style="font-weight:600">${escHtml(m.name)}</td>
          <td><span class="badge badge-${m.status_class.replace('status-','')}">${escHtml(m.status_text)}</span></td>
          <td class="td-mono">${escHtml(m.start_time)}</td>
          <td class="td-mono">${escHtml(m.end_time)}</td>
          <td>${m.hosts_count}</td>
          <td>
            <div class="td-actions">
              ${addHostsBtn}
              <form method="post" action="export_maintenance_hosts.php" target="_blank" style="margin:0">
                <input type="hidden" name="csrf_token" value="${escHtml(CSRF)}">
                <input type="hidden" name="maintenanceid" value="${m.maintenanceid}">
                <input type="hidden" name="maintenancename" value="${escHtml(m.name)}">
                <button type="submit" class="btn btn-sm btn-green">${T.maint_list_export||'Export'}</button>
              </form>
            </div>
          </td>`;
        tbody.appendChild(tr);
      });
    })
    .catch(err => {
      tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:32px;color:var(--red)">' + (T.maint_list_error||'Error') + ' (' + err + ')</td></tr>';
    });
}

function escHtml(s) {
  const d = document.createElement('div'); d.textContent = String(s); return d.innerHTML;
}

// ── Modal Add Hosts ───────────────────────────────────────────────────────────
if (isSuperAdmin) {
  const modal = document.getElementById('m-add-hosts');
  if (modal) {
    document.addEventListener('click', e => {
      if (e.target.matches('.btn-add-hosts')) {
        document.getElementById('modal-maintenanceid').value = e.target.dataset.id;
        document.getElementById('modal-maint-name').textContent = e.target.dataset.name;
        modal.style.display = 'flex'; modal.classList.add('open');
      }
    });
  }
}


document.addEventListener('DOMContentLoaded', loadMaintenanceTable);
</script>
</body>
</html>
