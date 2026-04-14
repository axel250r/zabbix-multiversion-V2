<?php
declare(strict_types=1);

session_start();

// Limpiar el archivo de cookie jar temporal
$cookie = $_SESSION['zbx_cookiejar'] ?? '';
if ($cookie && is_file($cookie)) {
    @unlink($cookie);
}

// Limpiar todas las variables de la sesión
$_SESSION = [];

// Borrar la cookie de sesión del navegador
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destruir la sesión
session_destroy();

// Redirigir al login
header('Location: login.php');
exit();