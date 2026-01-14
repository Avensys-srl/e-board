<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once(__DIR__ . '/config/db.php');
require_once(__DIR__ . '/config/notifications.php');

$user_id = (int) $_SESSION['user_id'];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read'])) {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->close();
    $success = "All notifications marked as read.";
}

$notifications = [];
$stmt = $conn->prepare(
    "SELECT n.*, p.code AS project_code
     FROM notifications n
     LEFT JOIN projects p ON p.id = n.project_id
     WHERE n.user_id = ?
     ORDER BY n.created_at DESC
     LIMIT 50"
);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
    }
}
$stmt->close();

$unread_notifications_count = get_unread_notifications_count($conn, $user_id);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Notifications</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="page">
    <main class="page-shell">
        <header class="page-header">
            <div>
                <p class="eyebrow">Notifications</p>
                <h1>All notifications</h1>
                <p class="muted">Latest updates related to your work.</p>
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
            </div>
        </header>

        <section class="card">
            <?php if ($success): ?>
                <p class="alert alert-success"><?php echo htmlspecialchars($success); ?></p>
            <?php endif; ?>

            <form class="actions" method="POST" action="notifications.php">
                <button class="btn btn-secondary" type="submit" name="mark_all_read">Mark all as read</button>
            </form>
        </section>

        <section class="card">
            <?php if (empty($notifications)): ?>
                <p class="muted">No notifications yet.</p>
            <?php else: ?>
                <ul class="activity-list">
                    <?php foreach ($notifications as $note): ?>
                        <li>
                            <div class="activity-title">
                                <?php echo htmlspecialchars($note['title']); ?>
                                <?php if (!empty($note['project_code'])): ?>
                                    <strong>(<?php echo htmlspecialchars($note['project_code']); ?>)</strong>
                                <?php endif; ?>
                            </div>
                            <div class="muted"><?php echo htmlspecialchars($note['message']); ?></div>
                            <div class="activity-meta"><?php echo htmlspecialchars($note['created_at']); ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
