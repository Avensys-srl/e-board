<?php
session_start();

// Distruggi tutte le variabili di sessione
$_SESSION = [];

// Elimina il cookie di sessione (se esiste)
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

// Termina la sessione
session_destroy();

// Reindirizza alla pagina di login
header('Location: login.php');
exit;
?>
