<?php
/**
 * Autoloader personalizado para cargar las dependencias sin depender de Composer
 */

// Evitar que se cargue múltiples veces
if (defined('AUTOLOAD_INITIALIZED')) {
    return;
}

define('AUTOLOAD_INITIALIZED', true);

// Establecer la zona horaria si no está definida
if (!ini_get('date.timezone')) {
    date_default_timezone_set('UTC');
}

// Directorio base de las dependencias
$vendorDir = __DIR__ . '/vendor';

// Cargar manualmente las clases necesarias de dompdf
$dompdfFiles = [
    '/dompdf/dompdf/src/Dompdf.php',
    '/dompdf/dompdf/lib/Cpdf.php',
    '/dompdf/dompdf/src/Options.php',
    '/dompdf/dompdf/src/Canvas.php',
    '/dompdf/dompdf/src/Exception.php',
    // FontLib
    '/dompdf/php-font-lib/src/FontLib/Font.php',
    '/dompdf/php-font-lib/src/FontLib/BinaryStream.php',
    // CSS Parser
    '/sabberworm/php-css-parser/src/Parser.php',
    '/sabberworm/php-css-parser/src/Settings.php',
];

foreach ($dompdfFiles as $file) {
    $path = $vendorDir . $file;
    if (file_exists($path)) {
        require_once $path;
    }
}

// Registrar el autoloader para el resto de clases
spl_autoload_register(function ($class) use ($vendorDir) {
    // Mapeo de prefijos de namespace a directorios
    $prefixes = [
        'Dompdf\\' => $vendorDir . '/dompdf/dompdf/src/',
        'FontLib\\' => $vendorDir . '/dompdf/php-font-lib/src/FontLib/',
        'Svg\\' => $vendorDir . '/dompdf/php-svg-lib/src/Svg/',
        'Sabberworm\\CSS\\' => $vendorDir . '/sabberworm/php-css-parser/src/'
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) === 0) {
            $relativeClass = substr($class, $len);
            $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
            
            if (file_exists($file)) {
                require $file;
                return true;
            }
        }
    }
    
    return false;
});

// Configuración de dompdf
if (!defined('DOMPDF_ENABLE_AUTOLOAD')) {
    define('DOMPDF_ENABLE_AUTOLOAD', false);
}

if (!defined('DOMPDF_CHROOT')) {
    define('DOMPDF_CHROOT', $vendorDir . '/dompdf/dompdf');
}

if (!defined('DOMPDF_FONT_DIR')) {
    define('DOMPDF_FONT_DIR', $vendorDir . '/dompdf/dompdf/lib/fonts/');
}

if (!defined('DOMPDF_FONT_CACHE')) {
    define('DOMPDF_FONT_CACHE', DOMPDF_FONT_DIR);
}

if (!defined('DOMPDF_TEMP_DIR')) {
    define('DOMPDF_TEMP_DIR', sys_get_temp_dir());
}

// Inicializar dompdf si está disponible
if (class_exists('Dompdf\Dompdf')) {
    // Configuración adicional de dompdf si es necesario
}
