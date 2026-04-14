<?php
/**
 * Verificación de requisitos del sistema
 * 
 * Este script verifica que el servidor cumpla con los requisitos mínimos
 * para ejecutar la aplicación de generación de informes PDF de Zabbix.
 */

// Versión mínima de PHP requerida
define('MIN_PHP_VERSION', '7.2.0');

// Extensiones PHP requeridas
$requiredExtensions = [
    'curl', 'gd', 'json', 'mbstring', 'xml', 'zip', 'zlib', 'fileinfo'
];

// Verificar versión de PHP
$phpVersion = phpversion();
$phpVersionOk = version_compare($phpVersion, MIN_PHP_VERSION, '>=');

// Verificar extensiones
$missingExtensions = [];
foreach ($requiredExtensions as $ext) {
    if (!extension_loaded($ext)) {
        $missingExtensions[] = $ext;
    }
}

// Verificar permisos de escritura
$writableDirs = [
    __DIR__ . '/tmp' => 'Directorio temporal',
    __DIR__ . '/logs' => 'Directorio de logs'
];

$permissionIssues = [];
foreach ($writableDirs as $dir => $description) {
    if (!is_writable($dir) && !@mkdir($dir, 0777, true)) {
        $permissionIssues[] = "$description ($dir) no tiene permisos de escritura";
    }
}

// Mostrar resultados
header('Content-Type: text/plain; charset=utf-8');
echo "=== Verificación de Requisitos del Sistema ===\n\n";

echo "Versión de PHP: $phpVersion " . ($phpVersionOk ? "✓" : "✗ (Se requiere " . MIN_PHP_VERSION . " o superior)") . "\n";

if (!empty($missingExtensions)) {
    echo "\n✗ Extensiones PHP faltantes: " . implode(', ', $missingExtensions) . "\n";
} else {
    echo "✓ Todas las extensiones PHP requeridas están instaladas\n";
}

if (!empty($permissionIssues)) {
    echo "\n✗ Problemas de permisos:\n  " . implode("\n  ", $permissionIssues) . "\n";
} else {
    echo "✓ Permisos de escritura correctos\n";
}

// Verificar dependencias de Composer
$vendorAutoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($vendorAutoload)) {
    echo "\n✗ Las dependencias de Composer no están instaladas.\n";
    echo "  Ejecuta 'composer install --no-dev --optimize-autoloader'\n";
} else {
    echo "✓ Dependencias de Composer instaladas\n";
}

// Resumen
$hasErrors = !$phpVersionOk || !empty($missingExtensions) || !empty($permissionIssues) || !file_exists($vendorAutoload);

if ($hasErrors) {
    echo "\n❌ Se encontraron problemas que deben resolverse antes de continuar.\n";
    exit(1);
} else {
    echo "\n✅ ¡Todos los requisitos se cumplen correctamente!\n";
    echo "Puedes ejecutar la aplicación sin problemas.\n";
    exit(0);
}
