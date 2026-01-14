<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once(__DIR__ . '/config/db.php');
require_once(__DIR__ . '/config/workflow.php');
require_once(__DIR__ . '/config/notifications.php');

$role = $_SESSION['role'] ?? '';
$user_id = (int) $_SESSION['user_id'];
$success = '';
$error = '';
$unread_notifications_count = get_unread_notifications_count($conn, $user_id);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approval_id'], $_POST['decision'])) {
    $approval_id = (int) $_POST['approval_id'];
    $decision = $_POST['decision'];
    $decision_note = trim($_POST['decision_note'] ?? '');

    $stmt = $conn->prepare(
        "SELECT a.*, p.current_state_id
         FROM approvals a
         JOIN projects p ON p.id = a.project_id
         WHERE a.id = ? AND a.status = 'pending'"
    );
    $stmt->bind_param("i", $approval_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $approval = null;
    if ($result && $result->num_rows === 1) {
        $approval = $result->fetch_assoc();
    }
    $stmt->close();

    if (!$approval) {
        $error = "Approval not found.";
    } elseif ($role !== 'admin' && $approval['role_required'] !== $role) {
        $error = "You are not allowed to decide this approval.";
    } else {
        $action = null;
        $current_state_id = (int) $approval['current_state_id'];

        if ($approval['role_required'] === 'coordinator') {
            if ($decision === 'approve') {
                $action = get_transition_id($conn, $current_state_id, 'approve_after_test');
                if (!$action) {
                    $action = get_transition_id($conn, $current_state_id, 'approve');
                }
            } elseif ($decision === 'reject') {
                $action = get_transition_id($conn, $current_state_id, 'reject');
            }
        } elseif ($approval['role_required'] === 'tester') {
            if ($decision === 'complete_test') {
                $action = get_transition_id($conn, $current_state_id, 'complete_test');
            }
        }

        if (!$action) {
            $error = "No valid action for the current project state.";
        } else {
            list($ok, $message) = transition_project_state($conn, (int) $approval['project_id'], (int) $action, $user_id);
            if ($ok) {
                $stmt = $conn->prepare(
                    "UPDATE approvals
                     SET status = ?, decided_by = ?, decided_at = NOW(), decision_note = ?
                     WHERE id = ?"
                );
                $status = $decision === 'reject' ? 'rejected' : 'approved';
                if ($decision === 'complete_test') {
                    $status = 'approved';
                }
                $stmt->bind_param("sisi", $status, $user_id, $decision_note, $approval_id);
                $stmt->execute();
                $stmt->close();
                $success = "Approval updated.";
            } else {
                $error = $message;
            }
        }
    }
}

$approvals = [];
if ($role === 'admin') {
    $stmt = $conn->prepare(
        "SELECT a.*, p.code, p.name, u.username AS requested_by_name
         FROM approvals a
         JOIN projects p ON p.id = a.project_id
         LEFT JOIN users u ON u.id = a.requested_by
         WHERE a.status = 'pending'
         ORDER BY a.created_at ASC"
    );
    $stmt->execute();
} else {
    $stmt = $conn->prepare(
        "SELECT a.*, p.code, p.name, u.username AS requested_by_name
         FROM approvals a
         JOIN projects p ON p.id = a.project_id
         LEFT JOIN users u ON u.id = a.requested_by
         WHERE a.status = 'pending' AND a.role_required = ?
         ORDER BY a.created_at ASC"
    );
    $stmt->bind_param("s", $role);
    $stmt->execute();
}

$result = $stmt->get_result();
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $approvals[] = $row;
    }
}
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Approvals</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="page">
    <main class="page-shell">
        <header class="page-header">
            <div>
                <p class="eyebrow">Approvals</p>
                <h1>Pending approvals</h1>
                <p class="muted">Review and decide pending actions.</p>
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

            <?php if ($error): ?>
                <p class="alert alert-error"><?php echo htmlspecialchars($error); ?></p>
            <?php endif; ?>

            <?php if (empty($approvals)): ?>
                <p class="muted">No approvals pending.</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Project</th>
                            <th>Requested By</th>
                            <th>Role Required</th>
                            <th>Requested At</th>
                            <th>Decision</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($approvals as $approval): ?>
                            <tr>
                                <td>
                                    <?php echo htmlspecialchars($approval['code']); ?>
                                    <div class="muted"><?php echo htmlspecialchars($approval['name']); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars($approval['requested_by_name'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($approval['role_required']); ?></td>
                                <td><?php echo htmlspecialchars($approval['created_at']); ?></td>
                                <td>
                                    <form class="approval-form" method="POST" action="approvals.php">
                                        <input type="hidden" name="approval_id" value="<?php echo (int) $approval['id']; ?>">
                                        <input type="text" name="decision_note" placeholder="Decision note (optional)">
                                        <div class="actions">
                                            <?php if ($approval['role_required'] === 'coordinator' || $role === 'admin'): ?>
                                                <button class="btn btn-primary" type="submit" name="decision" value="approve">Approve</button>
                                                <button class="btn btn-danger" type="submit" name="decision" value="reject">Reject</button>
                                            <?php elseif ($approval['role_required'] === 'tester'): ?>
                                                <button class="btn btn-primary" type="submit" name="decision" value="complete_test">Complete test</button>
                                            <?php else: ?>
                                                <span class="muted">No actions available</span>
                                            <?php endif; ?>
                                        </div>
                                    </form>
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
