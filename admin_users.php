<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

require_once(__DIR__ . '/config/db.php');
require_once(__DIR__ . '/config/notifications.php');

// Delete user
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $delete_id = (int) $_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $stmt->bind_param("i", $delete_id);
    $stmt->execute();
    header("Location: admin_users.php");
    exit();
}

// Update user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update'])) {
    $user_id = (int) $_POST['user_id'];
    $email = trim($_POST['email']);
    $role = $_POST['role'];

    if (!empty($email) && !empty($role)) {
        $stmt = $conn->prepare("UPDATE users SET email = ?, role = ? WHERE id = ?");
        $stmt->bind_param("ssi", $email, $role, $user_id);
        $stmt->execute();
    }
    header("Location: admin_users.php");
    exit();
}

$query = "SELECT * FROM users ORDER BY role DESC";
$result = mysqli_query($conn, $query);
$unread_notifications_count = get_unread_notifications_count($conn, (int) $_SESSION['user_id']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>User Management</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="page">
    <main class="page-shell">
        <header class="page-header">
            <div>
                <p class="eyebrow">Administration</p>
                <h1>User Management</h1>
                <p class="muted">Edit roles and update information.</p>
            </div>
            <div class="page-actions">
                <a class="btn" href="register.php">Create new user</a>
                <a class="btn btn-secondary" href="settings.php">Settings</a>
                <a class="btn btn-secondary" href="dashboard.php">Back to dashboard</a>
                <a class="notif-bell" href="notifications.php" aria-label="Notifications">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M12 2a6 6 0 0 0-6 6v3.2c0 .8-.3 1.5-.9 2.1l-1.1 1.1v1.6h16v-1.6l-1.1-1.1c-.6-.6-.9-1.3-.9-2.1V8a6 6 0 0 0-6-6zm0 20a2.5 2.5 0 0 0 2.4-2h-4.8a2.5 2.5 0 0 0 2.4 2z"/>
                    </svg>
                    <?php if ($unread_notifications_count > 0): ?>
                        <span class="notif-badge"><?php echo (int) $unread_notifications_count; ?></span>
                    <?php endif; ?>
                </a>
            </div>
        </header>

        <section class="card">
            <table class="table">
                <thead>
                    <tr>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = mysqli_fetch_assoc($result)): ?>
                    <tr>
                        <form method="POST" action="admin_users.php">
                            <td>
                                <input type="email" name="email" value="<?= htmlspecialchars($row['email']) ?>" placeholder="email@company.com" required>
                            </td>
                            <td>
                                <select name="role" required>
                                    <?php
                                    $roles = ['admin', 'designer', 'supplier', 'tester', 'coordinator', 'firmware'];
                                    foreach ($roles as $r) {
                                        $selected = ($row['role'] === $r) ? 'selected' : '';
                                        echo "<option value='$r' $selected>$r</option>";
                                    }
                                    ?>
                                </select>
                            </td>
                            <td><?= htmlspecialchars($row['created_at']) ?></td>
                            <td class="actions">
                                <input type="hidden" name="user_id" value="<?= $row['id'] ?>">
                                <button type="submit" class="btn btn-primary" name="update">Save</button>
                                <a class="btn btn-danger" href="admin_users.php?delete=<?= $row['id'] ?>" onclick="return confirm('Confirm deletion?')">Delete</a>
                            </td>
                        </form>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </section>
    </main>
</body>
</html>
