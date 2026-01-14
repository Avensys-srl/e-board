<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Read session data
$username = $_SESSION['username'] ?? 'User';
$role = $_SESSION['role'] ?? 'user';
?>

<!DOCTYPE html>
<html lang="en">
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
                <h1>Welcome, <?php echo htmlspecialchars($username); ?>!</h1>
                <p class="muted">Role: <?php echo htmlspecialchars($role); ?></p>
            </div>
            <div class="page-actions">
                <a class="btn" href="attachments.php">Attachments</a>
                <a class="btn" href="projects.php">Projects</a>
                <?php if ($role === 'admin'): ?>
                    <a class="btn" href="admin_users.php">User management</a>
                    <a class="btn btn-secondary" href="settings.php">Settings</a>
                <?php endif; ?>
                <a class="btn btn-secondary" href="logout.php">Logout</a>
            </div>
        </header>

        <section class="card">
            <h2>Overview</h2>
            <p>Select a section from the menu or use user management to administer the system.</p>
        </section>
    </main>
</body>
</html>
