<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once(__DIR__ . '/config/db.php');
require_once(__DIR__ . '/config/notifications.php');

// Read session data
$user_id = (int) ($_SESSION['user_id'] ?? 0);
$username = $_SESSION['username'] ?? 'User';
$role = $_SESSION['role'] ?? 'user';

$owned_projects_count = 0;
$pending_approvals_count = 0;
$pending_requirements_count = 0;
$recent_notifications = [];
$unread_notifications_count = 0;
$assigned_phases = [];

$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM projects WHERE owner_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $result->num_rows === 1) {
    $row = $result->fetch_assoc();
    $owned_projects_count = (int) $row['cnt'];
}
$stmt->close();

if ($role === 'admin') {
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM approvals WHERE status = 'pending'");
    $stmt->execute();
} else {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS cnt FROM approvals WHERE status = 'pending' AND role_required = ?"
    );
    $stmt->bind_param("s", $role);
    $stmt->execute();
}
$result = $stmt->get_result();
if ($result && $result->num_rows === 1) {
    $row = $result->fetch_assoc();
    $pending_approvals_count = (int) $row['cnt'];
}
$stmt->close();

if ($role === 'admin') {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS cnt
         FROM project_requirements
         WHERE status = 'pending' AND is_mandatory = 1"
    );
    $stmt->execute();
} else {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS cnt
         FROM project_requirements r
         JOIN projects p ON p.id = r.project_id
         WHERE r.status = 'pending' AND r.is_mandatory = 1 AND p.owner_id = ?"
    );
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
}
$result = $stmt->get_result();
if ($result && $result->num_rows === 1) {
    $row = $result->fetch_assoc();
    $pending_requirements_count = (int) $row['cnt'];
}
$stmt->close();

$unread_notifications_count = get_unread_notifications_count($conn, $user_id);

$stmt = $conn->prepare(
    "SELECT p.id AS project_id, p.code, ph.id AS phase_id, ph.name, ph.due_date
     FROM project_phases ph
     JOIN projects p ON p.id = ph.project_id
     WHERE ph.assignee_id = ? AND ph.completed_at IS NULL
     ORDER BY ph.due_date IS NULL, ph.due_date ASC"
);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $assigned_phases[] = $row;
    }
}
$stmt->close();

$stmt = $conn->prepare(
    "SELECT title, message, created_at
     FROM notifications
     WHERE user_id = ?
     ORDER BY created_at DESC
     LIMIT 5"
);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $recent_notifications[] = $row;
    }
}
$stmt->close();
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
                <a class="notif-bell" href="notifications.php" aria-label="Notifications">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M12 2a6 6 0 0 0-6 6v3.2c0 .8-.3 1.5-.9 2.1l-1.1 1.1v1.6h16v-1.6l-1.1-1.1c-.6-.6-.9-1.3-.9-2.1V8a6 6 0 0 0-6-6zm0 20a2.5 2.5 0 0 0 2.4-2h-4.8a2.5 2.5 0 0 0 2.4 2z"/>
                    </svg>
                    <?php if ($unread_notifications_count > 0): ?>
                        <span class="notif-badge"><?php echo (int) $unread_notifications_count; ?></span>
                    <?php endif; ?>
                </a>
                <a class="btn" href="projects.php">Projects</a>
                <a class="btn" href="approvals.php">Approvals</a>
                <?php if ($role === 'admin'): ?>
                    <a class="btn" href="admin_users.php">User management</a>
                    <a class="btn btn-secondary" href="settings.php">Settings</a>
                <?php endif; ?>
                <a class="btn btn-secondary" href="logout.php">Logout</a>
            </div>
        </header>

        <div class="summary-grid">
            <section class="card summary-card">
                <p class="eyebrow">Owned projects</p>
                <div class="summary-value"><?php echo (int) $owned_projects_count; ?></div>
                <p class="muted">Projects you own.</p>
            </section>
            <section class="card summary-card">
                <p class="eyebrow">Pending approvals</p>
                <div class="summary-value"><?php echo (int) $pending_approvals_count; ?></div>
                <p class="muted">Approvals waiting for your role.</p>
            </section>
            <section class="card summary-card">
                <p class="eyebrow">Mandatory requirements</p>
                <div class="summary-value"><?php echo (int) $pending_requirements_count; ?></div>
                <p class="muted">Pending mandatory items.</p>
            </section>
        </div>

        <section class="card">
            <h2>Recent activity</h2>
            <?php if (empty($recent_notifications)): ?>
                <p class="muted">No recent notifications.</p>
            <?php else: ?>
                <ul class="activity-list">
                    <?php foreach ($recent_notifications as $note): ?>
                        <li>
                            <div class="activity-title"><?php echo htmlspecialchars($note['title']); ?></div>
                            <div class="muted"><?php echo htmlspecialchars($note['message']); ?></div>
                            <div class="activity-meta"><?php echo htmlspecialchars($note['created_at']); ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2>Your assigned phases</h2>
            <?php if (empty($assigned_phases)): ?>
                <p class="muted">No phases assigned to you.</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Project</th>
                            <th>Phase</th>
                            <th>Due</th>
                            <th>Open</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assigned_phases as $phase): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($phase['code']); ?></td>
                                <td><?php echo htmlspecialchars($phase['name']); ?></td>
                                <td><?php echo htmlspecialchars($phase['due_date'] ?? ''); ?></td>
                                <td>
                                    <a class="btn btn-secondary" href="project_view.php?id=<?php echo (int) $phase['project_id']; ?>">Open</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
