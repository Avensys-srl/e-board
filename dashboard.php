<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Recupero dati dalla sessione
$username = $_SESSION['username'] ?? 'Utente';
$role = $_SESSION['role'] ?? 'user';
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Dashboard</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="page">
    <main class="page-shell">
        <header class="page-header">
            <div>
                <p class="eyebrow">Dashboard</p>
                <h1>Benvenuto, <?php echo htmlspecialchars($username); ?>!</h1>
                <p class="muted">Ruolo: <?php echo htmlspecialchars($role); ?></p>
            </div>
            <div class="page-actions">
                <?php if ($role === 'admin'): ?>
                    <a class="btn" href="admin_users.php">Gestione utenti</a>
                <?php endif; ?>
                <a class="btn btn-secondary" href="logout.php">Logout</a>
            </div>
        </header>

        <section class="card">
            <h2>Panoramica</h2>
            <p>Seleziona una sezione dal menu o usa la gestione utenti per amministrare il sistema.</p>
        </section>
    </main>
</body>
</html>
