<?php
/**
 * Configuración de la aplicación de informes PDF para Zabbix
 * 
 * Este archivo contiene la configuración necesaria para conectar con la API de Zabbix
 * y personalizar el comportamiento de la aplicación.
 */

// ===== CONFIGURACIÓN OBLIGATORIA =====

// URL del frontend de Zabbix (sin barra final)
// Ejemplo: 'http://zabbix.example.com' o 'https://zabbix.midominio.local/zabbix'
define('ZABBIX_URL', 'http://192.168.48.128/');
define('ZABBIX_TZ', 'America/Santiago');

// URL completa de la API de Zabbix (generalmente [ZABBIX_URL]/api_jsonrpc.php)
define('ZABBIX_API_URL', rtrim(ZABBIX_URL, '/').'/api_jsonrpc.php');

// Credenciales de la API de Zabbix (crea un usuario dedicado con permisos de solo lectura)
define('ZABBIX_API_USER', 'Admin');   // Nombre de usuario de la API
define('ZABBIX_API_PASS', 'zabbix');  // Contraseña del usuario de la API
define('CUSTOM_LOGO_PATH', 'assets/Zabbix_logo.png');
define('APPLY_LOGO_BLEND_MODE', true);

// ===== CONFIGURACIÓN AVANZADA =====

// Motor PDF a utilizar: 'dompdf' (recomendado) o 'wkhtmltopdf'
define('PDF_ENGINE', 'dompdf');

// Verificar certificados SSL (desactivar solo para entornos de prueba con certificados autofirmados)
define('VERIFY_SSL', false);

// Prefijo/sufijo para usuarios (útil en entornos con autenticación LDAP/AD)
define('ZBX_USER_PREFIX', '');  // Ejemplo: 'DOMAIN\' para Active Directory
define('ZBX_USER_SUFFIX', '');  // Ejemplo: '@empresa.local' para correo

// Configuración de directorios
$baseDir = __DIR__;

// Directorio para archivos temporales (debe tener permisos de escritura)
if (!defined('TMP_DIR')) {
    // Intenta usar un directorio dentro de la aplicación primero
    $tmpDir = "{$baseDir}/tmp";
    
    // Si no se puede escribir, usa el directorio temporal del sistema
    if (!is_writable($tmpDir) && !@mkdir($tmpDir, 0777, true)) {
        $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zbx_pdf';
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0777, true);
        }
    }
    
    define('TMP_DIR', $tmpDir);
}

// Directorio para logs (opcional)
if (!defined('LOG_DIR')) {
    $logDir = "{$baseDir}/logs";
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }
    define('LOG_DIR', $logDir);
}

// Configuración de errores
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', LOG_DIR . '/error.log');

// Configuración de zona horaria (usar la misma que Zabbix)
date_default_timezone_set('America/Santiago');

// Incluir el autoloader personalizado
if (file_exists(__DIR__ . '/autoload.php')) {
    require_once __DIR__ . '/autoload.php';
} else {
    // Si no existe el autoload personalizado, intentar cargar directamente las dependencias
    require_once __DIR__ . '/vendor/autoload.php';
}
