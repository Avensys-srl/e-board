<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

require_once(__DIR__ . '/config/db.php');
require_once(__DIR__ . '/config/settings.php');
require_once(__DIR__ . '/config/notifications.php');

$success = '';
$error = '';
$unread_notifications_count = get_unread_notifications_count($conn, (int) $_SESSION['user_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $upload_base_path = trim($_POST['upload_base_path'] ?? '');
    $upload_base_url = trim($_POST['upload_base_url'] ?? '');

    if ($upload_base_path !== '') {
        $upload_base_path = rtrim($upload_base_path, "/\\");
    }
    if ($upload_base_url !== '') {
        $upload_base_url = rtrim($upload_base_url, "/");
    }

    $ok_path = set_setting($conn, 'upload_base_path', $upload_base_path);
    $ok_url = set_setting($conn, 'upload_base_url', $upload_base_url);
    if ($ok_path && $ok_url) {
        $success = "Settings saved.";
    } else {
        $error = "Unable to save settings.";
    }
}

$upload_base_path = get_setting($conn, 'upload_base_path', 'uploads');
$upload_base_url = get_setting($conn, 'upload_base_url', '');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Settings</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="page">
    <main class="page-shell">
        <header class="page-header">
            <div>
                <p class="eyebrow">Administration</p>
                <h1>Settings</h1>
                <p class="muted">Configure upload storage paths and base URLs.</p>
            </div>
            <div class="page-actions">
                <a class="notif-bell" href="notifications.php" aria-label="Notifications">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M12 2a6 6 0 0 0-6 6v3.2c0 .8-.3 1.5-.9 2.1l-1.1 1.1v1.6h16v-1.6l-1.1-1.1c-.6-.6-.9-1.3-.9-2.1V8a6 6 0 0 0-6-6zm0 20a2.5 2.5 0 0 0 2.4-2h-4.8a2.5 2.5 0 0 0 2.4 2z"/>
                    </svg>
                    <?php if ($unread_notifications_count > 0): ?>
                        <span class="notif-badge"><?php echo (int) $unread_notifications_count; ?></span>
                    <?php endif; ?>
                </a>
                <a class="btn btn-secondary" href="dashboard.php">Back to dashboard</a>
                <a class="btn" href="admin_users.php">User management</a>
                <a class="btn" href="document_types.php">Document types</a>
            </div>
        </header>

        <section class="card">
            <?php if ($success): ?>
                <p class="alert alert-success"><?php echo htmlspecialchars($success); ?></p>
            <?php endif; ?>

            <?php if ($error): ?>
                <p class="alert alert-error"><?php echo htmlspecialchars($error); ?></p>
            <?php endif; ?>

            <form class="settings-form" method="POST" action="settings.php">
                <label for="upload_base_path">Upload base path (local)</label>
                <input
                    id="upload_base_path"
                    type="text"
                    name="upload_base_path"
                    value="<?php echo htmlspecialchars($upload_base_path); ?>"
                    placeholder="uploads"
                >

                <label for="upload_base_url">Upload base URL (optional)</label>
                <input
                    id="upload_base_url"
                    type="text"
                    name="upload_base_url"
                    value="<?php echo htmlspecialchars($upload_base_url); ?>"
                    placeholder="https://files.example.com/uploads"
                >

                <p class="muted">
                    Use a local folder path for uploads. If you also provide a URL,
                    links will use the URL as the public base path.
                </p>

                <div class="actions">
                    <button type="submit" class="btn btn-primary" name="save_settings">Save settings</button>
                </div>
            </form>
        </section>
    </main>
</body>
</html>
