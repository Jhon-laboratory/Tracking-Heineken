<?php
// ============================================
// ACTIVAR DEBUG (opcional, puedes quitarlo en producción)
// ============================================
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ============================================
// INICIAR SESIÓN Y DESTRUIRLA
// ============================================
session_start();

// Limpiar todas las variables de sesión
$_SESSION = array();

// Si se usa cookies de sesión, eliminarla
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destruir la sesión completamente
session_destroy();

// ============================================
// REDIRECCIONAR AL LOGIN
// ============================================
header('Location: login.php');
exit;
?>