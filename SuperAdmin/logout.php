<?php
session_start();

// === DESTROY ALL SESSION DATA ===
$_SESSION = [];

// === CLEAR SESSION COOKIE ===
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// === DESTROY SESSION ===
session_destroy();

// === REDIRECT TO LOGIN ===
header('Location: ../Common/login.php');
exit;