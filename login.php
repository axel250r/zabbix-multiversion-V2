<?php
declare(strict_types=1);

header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; script-src 'self' 'unsafe-inline';");

session_start();
require_once __DIR__ . '/lib/i18n.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/ZabbixApiFactory.php';  // <-- IMPORTANTE

if (!defined('ZBX_USER_PREFIX')) define('ZBX_USER_PREFIX','');
if (!defined('ZBX_USER_SUFFIX')) define('ZBX_USER_SUFFIX','');

// Función para obtener el tipo de usuario usando la fábrica
function get_user_type(string $user, string $pass): int {
    try {
        $api = ZabbixApiFactory::create(
            ZABBIX_API_URL,
            $user,
            $pass,
            ['timeout' => 10, 'verify_ssl' => defined('VERIFY_SSL') ? VERIFY_SSL : false]
        );
        
        return $api->getUserType($user);
        
    } catch (Throwable $e) {
        error_log("Error en get_user_type: " . $e->getMessage());
    }
    return 1;
}

// Función para validar login vía API (AHORA USA LA FÁBRICA)
function api_validate_login(string $user, string $pass, ?string &$err = null): bool {
    try {
        // ESTA ES LA LÍNEA CLAVE - USAR LA FÁBRICA
        $api = ZabbixApiFactory::create(
            ZABBIX_API_URL,
            $user,
            $pass,
            ['timeout' => 10, 'verify_ssl' => defined('VERIFY_SSL') ? VERIFY_SSL : false]
        );
        return true;
    } catch (Throwable $e) {
        $err = $e->getMessage();
        return false;
    }
}

function web_login(string $user, string $pass, string $cookieJar, ?string &$err=null): bool {
    $base = rtrim(ZABBIX_URL,'/');
    @file_put_contents($cookieJar,'');
    @chmod($cookieJar,0600);
    $loginUser = ZBX_USER_PREFIX.$user.ZBX_USER_SUFFIX;

    $opt=[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>true,
        CURLOPT_MAXREDIRS=>5,
        CURLOPT_COOKIEFILE=>$cookieJar,
        CURLOPT_COOKIEJAR=>$cookieJar,
        CURLOPT_USERAGENT=>'Mozilla/5.0',
        CURLOPT_CONNECTTIMEOUT=>10,
        CURLOPT_TIMEOUT=>30
    ];

    if (stripos($base,'https://')===0 && defined('VERIFY_SSL') && !VERIFY_SSL){
        $opt[CURLOPT_SSL_VERIFYPEER]=0;
        $opt[CURLOPT_SSL_VERIFYHOST]=0;
    }

    $post=['name'=>$loginUser,'password'=>$pass,'autologin'=>1,'enter'=>'Sign in'];
    $postUrl = $base.'/index.php';

    $ch=curl_init($postUrl);
    curl_setopt_array($ch,$opt+[
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>http_build_query($post),
        CURLOPT_REFERER=>$postUrl,
        CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded']
    ]);

    $resp=curl_exec($ch);
    curl_close($ch);

    $ch=curl_init($base.'/zabbix.php?action=dashboard.view');
    curl_setopt_array($ch,$opt);
    $dash=curl_exec($ch);
    $eff=curl_getinfo($ch,CURLINFO_EFFECTIVE_URL);
    $hc3=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($dash===false || $hc3===401 || stripos((string)$eff,'index.php')!==false){
        $err='Sin acceso al dashboard (posible redirect a login)';
        return false;
    }

    return true;
}

$msg='';
if ($_SERVER['REQUEST_METHOD']==='POST'){
    $u=trim($_POST['user']??'');
    $p=trim($_POST['pass']??'');

    if ($u==='' || $p===''){
        $msg=t('login_error_invalid_form');
    } else {
        if (!api_validate_login($u,$p,$apiErr)){
            $msg = t('login_error_invalid_credentials') . " (Debug: " . htmlspecialchars($apiErr ?? 'Error API desconocido', ENT_QUOTES, 'UTF-8') . ")";
        } else {
            $cookieJar = TMP_DIR.DIRECTORY_SEPARATOR.'cj_'.bin2hex(random_bytes(6)).'.txt';

            if (web_login($u,$p,$cookieJar,$webErr)){
                $user_type = get_user_type($u, $p);

                $_SESSION['zbx_user'] = $u;
                $_SESSION['zbx_pass'] = $p;
                $_SESSION['zbx_cookiejar'] = $cookieJar;
                $_SESSION['zbx_auth_ok'] = true;
                $_SESSION['zbx_user_type'] = $user_type;

                header('Location: latest_data.php');
                exit;
            } else {
                $msg = t('login_error_frontend_rejected') . " (Debug: " . htmlspecialchars($webErr ?? 'Error web desconocido', ENT_QUOTES, 'UTF-8') . ")";
            }
        }
    }
}
?>
<!doctype html>
<html lang="<?= htmlspecialchars($current_lang, ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="author" content="Axel Del Canto">
<title><?= t('login_title') ?></title>
<link rel="stylesheet" href="assets/css/login.css">
<?php if (defined('APPLY_LOGO_BLEND_MODE') && APPLY_LOGO_BLEND_MODE): ?>
<style>body.dark-theme .custom-logo { mix-blend-mode: multiply; }</style>
<?php endif; ?>
</head>
<body class="dark-theme">

<div class="bg-deco"></div>

<!-- TOP BAR -->
<div class="top-bar">
  <div class="lang-switcher">
    <a href="?lang=es"<?= ($current_lang==='es') ? ' class="active"' : '' ?>>ES</a>
    <a href="?lang=en"<?= ($current_lang==='en') ? ' class="active"' : '' ?>>EN</a>
  </div>
  <button id="theme-toggle" class="theme-btn">&#9788; <?= t('theme_light') ?></button>
</div>

<div class="wrap">
  <div class="login-card">

    <div class="logo-row">
      <img src="<?= htmlspecialchars(defined('CUSTOM_LOGO_PATH') ? CUSTOM_LOGO_PATH : 'assets/sonda.png', ENT_QUOTES, 'UTF-8') ?>" alt="Logo" class="custom-logo" onerror="this.style.display='none'">
      <div class="logo-divider"></div>
      <span class="zabbix-badge">ZABBIX</span>
    </div>

    <div class="login-heading"><?= t('login_heading') ?></div>
    <div class="login-sub"><?= t('login_subheading') ?></div>

    <?php if ($msg): ?>
    <div class="login-error"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="post" action="login.php" autocomplete="off">
      <div class="field">
        <label><?= t('login_user_label') ?></label>
        <input type="text" name="user" required placeholder="username" autofocus>
      </div>
      <div class="field">
        <label><?= t('login_pass_label') ?></label>
        <input type="password" name="pass" required placeholder="••••••••">
      </div>
      <button type="submit" class="btn-login"><?= t('login_button') ?></button>
    </form>

    <div class="login-server">
      Zabbix: <span><?= htmlspecialchars(ZABBIX_URL, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <div class="credit" style="border-top:1px solid var(--border);padding-top:14px;margin-top:14px">
      <div style="font-size:12px;color:var(--text3);margin-bottom:10px"><?= t('common_author_credit') ?></div>
      <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
        <a href="https://www.linkedin.com/in/axel-del-canto-del-canto-4ba643186/" target="_blank" rel="noopener"
           style="display:inline-flex;align-items:center;gap:6px;text-decoration:none;color:var(--text3);font-size:12px;padding:5px 10px;border-radius:7px;border:1px solid var(--border);background:var(--card2);transition:all .14s"
           onmouseover="this.style.borderColor='#0077b5';this.style.color='#0077b5'"
           onmouseout="this.style.borderColor='var(--border)';this.style.color='var(--text3)'">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>
          LinkedIn
        </a>
        <a href="https://github.com/axel250r" target="_blank" rel="noopener"
           style="display:inline-flex;align-items:center;gap:6px;text-decoration:none;color:var(--text3);font-size:12px;padding:5px 10px;border-radius:7px;border:1px solid var(--border);background:var(--card2);transition:all .14s"
           onmouseover="this.style.borderColor='var(--text)';this.style.color='var(--text)'"
           onmouseout="this.style.borderColor='var(--border)';this.style.color='var(--text3)'">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.374 0 0 5.373 0 12c0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23A11.509 11.509 0 0112 5.803c1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576C20.566 21.797 24 17.3 24 12c0-6.627-5.373-12-12-12z"/></svg>
          GitHub
        </a>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const btn  = document.getElementById('theme-toggle');
  const body = document.body;
  function setTheme(t) {
    body.classList.toggle('dark-theme',  t==='dark');
    body.classList.toggle('light-theme', t!=='dark');
    btn.textContent = t==='dark' ? '\u2600 <?= t('theme_light') ?>' : '\ud83c\udf19 <?= t('theme_dark') ?>';
    localStorage.setItem('zbx-theme', t);
  }
  btn.addEventListener('click', () => setTheme(body.classList.contains('dark-theme') ? 'light' : 'dark'));
  const saved = localStorage.getItem('zbx-theme');
  setTheme(saved || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));
})();
</script>
</body>
</html>
