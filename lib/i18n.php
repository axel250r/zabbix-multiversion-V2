<?php
// lib/i18n.php
declare(strict_types=1);

// --- Configuración ---
const DEFAULT_LANG = 'es';
const SUPPORTED_LANGS = ['es', 'en'];
// ---------------------

// Variable global para almacenar las traducciones
global $translations;
$translations = [];

// Función para obtener una traducción
function t(string $key): string {
    global $translations;
    return $translations[$key] ?? $key; // Devuelve la clave si no se encuentra la traducción
}

// Lógica para determinar el idioma
function get_language(): string {
    // 1. Prioridad: Parámetro GET (ej: ?lang=en)
    if (isset($_GET['lang']) && in_array($_GET['lang'], SUPPORTED_LANGS)) {
        return $_GET['lang'];
    }
    // 2. Prioridad: Sesión del usuario
    if (isset($_SESSION['lang']) && in_array($_SESSION['lang'], SUPPORTED_LANGS)) {
        return $_SESSION['lang'];
    }
    // 3. Detección del navegador
    if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        $browser_lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
        if (in_array($browser_lang, SUPPORTED_LANGS)) {
            return $browser_lang;
        }
    }
    // 4. Idioma por defecto
    return DEFAULT_LANG;
}

// Determinar y guardar el idioma en la sesión
$current_lang = get_language();
$_SESSION['lang'] = $current_lang;

// Cargar el archivo de idioma correspondiente
$lang_file = __DIR__ . "/../lang/{$current_lang}.php";

if (file_exists($lang_file)) {
    $translations = require $lang_file;
} else {
    // Fallback al idioma por defecto si el archivo no existe
    $fallback_file = __DIR__ . "/../lang/" . DEFAULT_LANG . ".php";
    if (file_exists($fallback_file)) {
        $translations = require $fallback_file;
    }
}