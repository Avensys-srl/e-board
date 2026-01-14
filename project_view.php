<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once(__DIR__ . '/config/db.php');
require_once(__DIR__ . '/config/project_logic.php');
require_once(__DIR__ . '/config/workflow.php');
require_once(__DIR__ . '/config/notifications.php');

$project_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($project_id <= 0) {
    header('Location: projects.php');
    exit;
}

$success = '';
$error = '';
$user_id = (int) ($_SESSION['user_id'] ?? 0);

ensure_default_project_workflow($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_options'])) {
    $options = [
        'supplier_involved' => isset($_POST['supplier_involved']) ? '1' : '0',
        'firmware_involved' => isset($_POST['firmware_involved']) ? '1' : '0',
        'testing_enabled' => isset($_POST['testing_enabled']) ? '1' : '0',
        'final_approval_enabled' => isset($_POST['final_approval_enabled']) ? '1' : '0',
    ];

    $ok = true;
    foreach ($options as $key => $value) {
        if (!set_project_option($conn, $project_id, $key, $value)) {
            $ok = false;
            break;
        }
    }

    if ($ok) {
        ensure_requirements_for_options($conn, $project_id, $options);
        $success = "Project options saved. Generated requirements cannot be removed.";
    } else {
        $error = "Unable to save project options.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transition_id'])) {
    $transition_id = (int) $_POST['transition_id'];
    $role = $_SESSION['role'] ?? '';
    $stmt = $conn->prepare(
        "SELECT from_state_id FROM workflow_transitions WHERE id = ? AND scope = 'project'"
    );
    $stmt->bind_param("i", $transition_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows === 1) {
        $row = $result->fetch_assoc();
        $allowed = get_allowed_transitions($conn, (int) $row['from_state_id'], $role);
        $allowed_ids = array_column($allowed, 'id');
        if (in_array($transition_id, $allowed_ids, true)) {
            list($ok, $message) = transition_project_state($conn, $project_id, $transition_id, (int) $_SESSION['user_id']);
            if ($ok) {
                $success = $message;
            } else {
                $error = $message;
            }
        } else {
            $error = "You are not allowed to perform this action.";
        }
    } else {
        $error = "Invalid action.";
    }
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolve_requirement_id'])) {
    $requirement_id = (int) $_POST['resolve_requirement_id'];
    $role = $_SESSION['role'] ?? '';
    if ($role === 'admin' || $role === 'coordinator') {
        $stmt = $conn->prepare(
            "UPDATE project_requirements
             SET status = 'resolved', resolved_at = NOW()
             WHERE id = ? AND project_id = ?"
        );
        $stmt->bind_param("ii", $requirement_id, $project_id);
        $stmt->execute();
        $stmt->close();
        $success = "Requirement marked as resolved.";
    } else {
        $error = "You are not allowed to resolve requirements.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_phase'])) {
    $phase_id = (int) ($_POST['phase_id'] ?? 0);
    $note = trim($_POST['submission_note'] ?? '');
    $allowed = false;
    $phase_owner_role = '';

    $stmt = $conn->prepare(
        "SELECT owner_role FROM project_phases WHERE id = ? AND project_id = ?"
    );
    $stmt->bind_param("ii", $phase_id, $project_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows === 1) {
        $row = $result->fetch_assoc();
        $phase_owner_role = $row['owner_role'];
    }
    $stmt->close();

    $role = $_SESSION['role'] ?? '';
    if ($phase_owner_role !== '' && ($role === $phase_owner_role || $role === 'admin' || $role === 'coordinator')) {
        $allowed = true;
    }

    if (!$allowed) {
        $error = "You are not allowed to submit this phase.";
    } else {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS cnt FROM phase_submissions WHERE phase_id = ? AND status = 'pending'"
        );
        $stmt->bind_param("i", $phase_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $pending = 0;
        if ($result && $result->num_rows === 1) {
            $row = $result->fetch_assoc();
            $pending = (int) $row['cnt'];
        }
        $stmt->close();

        if ($pending > 0) {
            $error = "There is already a pending submission for this phase.";
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO phase_submissions (phase_id, submitted_by, submission_note)
                 VALUES (?, ?, ?)"
            );
            if ($stmt) {
                $stmt->bind_param("iis", $phase_id, $user_id, $note);
                if ($stmt->execute()) {
                    $success = "Phase submission sent.";
                    notify_roles(
                        $conn,
                        ['coordinator', 'admin'],
                        $project_id,
                        $phase_id,
                        'Phase submission',
                        'A phase submission is waiting for review.'
                    );
                } else {
                    $error = "Unable to submit phase.";
                }
                $stmt->close();
            } else {
                $error = "Unable to submit phase.";
            }
        }
    }
}

$project = null;
$stmt = $conn->prepare(
    "SELECT p.*, u.username AS owner_name
     FROM projects p
     LEFT JOIN users u ON u.id = p.owner_id
     WHERE p.id = ?"
);
$stmt->bind_param("i", $project_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $result->num_rows === 1) {
    $project = $result->fetch_assoc();
}
$stmt->close();

if (!$project) {
    header('Location: projects.php');
    exit;
}

$options = get_project_options($conn, $project_id);

if (empty($project['current_state_id'])) {
    $draft_state_id = get_project_state_id($conn, 'draft');
    if ($draft_state_id) {
        $stmt = $conn->prepare("UPDATE projects SET current_state_id = ? WHERE id = ?");
        $stmt->bind_param("ii", $draft_state_id, $project_id);
        $stmt->execute();
        $stmt->close();
        $project['current_state_id'] = $draft_state_id;
    }
}

$current_state_label = null;
if (!empty($project['current_state_id'])) {
    $current_state_label = get_project_state_label($conn, (int) $project['current_state_id']);
}

$role = $_SESSION['role'] ?? '';
$allowed_transitions = [];
if (!empty($project['current_state_id'])) {
    $allowed_transitions = get_allowed_transitions($conn, (int) $project['current_state_id'], $role);
}

if (!empty($allowed_transitions)) {
    $unique = [];
    $filtered = [];
    foreach ($allowed_transitions as $transition) {
        $key = $transition['action'] . ':' . $transition['to_state_id'];
        if (!isset($unique[$key])) {
            $unique[$key] = true;
            $filtered[] = $transition;
        }
    }
    $allowed_transitions = $filtered;
}

$to_state_labels = [];
if (!empty($allowed_transitions)) {
    $ids = array_unique(array_map(function ($t) {
        return (int) $t['to_state_id'];
    }, $allowed_transitions));
    if (!empty($ids)) {
        $id_list = implode(',', $ids);
        $result = $conn->query(
            "SELECT id, label FROM workflow_states WHERE id IN (" . $id_list . ")"
        );
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $to_state_labels[(int) $row['id']] = $row['label'];
            }
        }
    }
}

$requirements = [];
$req_result = $conn->query(
    "SELECT * FROM project_requirements WHERE project_id = " . (int) $project_id . " ORDER BY created_at ASC"
);
if ($req_result) {
    while ($row = $req_result->fetch_assoc()) {
        $requirements[] = $row;
    }
}

$phases = [];
$phase_result = $conn->query(
    "SELECT * FROM project_phases WHERE project_id = " . (int) $project_id . " ORDER BY sequence_order ASC"
);
if ($phase_result) {
    while ($row = $phase_result->fetch_assoc()) {
        $phases[] = $row;
    }
}

$phase_submissions = [];
$phase_submission_result = $conn->query(
    "SELECT s.*, p.name AS phase_name, u.username
     FROM phase_submissions s
     JOIN project_phases p ON p.id = s.phase_id
     LEFT JOIN users u ON u.id = s.submitted_by
     WHERE p.project_id = " . (int) $project_id . "
     ORDER BY s.submitted_at DESC"
);
if ($phase_submission_result) {
    while ($row = $phase_submission_result->fetch_assoc()) {
        $phase_submissions[] = $row;
    }
}

$project_notifications = get_project_notifications($conn, $project_id, 8);
$unread_notifications_count = get_unread_notifications_count($conn, $user_id);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Project overview</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="page">
    <main class="page-shell">
        <header class="page-header">
            <div>
                <p class="eyebrow">Project</p>
                <h1><?php echo htmlspecialchars($project['name']); ?></h1>
                <p class="muted">
                    <?php echo htmlspecialchars($project['code']); ?>
                    <?php if (!empty($project['project_type'])): ?>
                        | <?php echo htmlspecialchars($project['project_type']); ?>
                    <?php endif; ?>
                </p>
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
                <a class="btn btn-secondary" href="projects.php">Back to projects</a>
                <a class="btn btn-secondary" href="dashboard.php">Dashboard</a>
            </div>
        </header>

        <?php if ($success || $error): ?>
            <div class="page-messages">
                <?php if ($success): ?>
                    <p class="alert alert-success"><?php echo htmlspecialchars($success); ?></p>
                <?php endif; ?>
                <?php if ($error): ?>
                    <p class="alert alert-error"><?php echo htmlspecialchars($error); ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="project-grid">
            <div class="project-main">
                <section class="card">
                    <h2>Project details</h2>
                    <p class="muted">Owner: <?php echo htmlspecialchars($project['owner_name'] ?? ''); ?></p>
                    <p class="muted">Start date: <?php echo htmlspecialchars($project['start_date'] ?? ''); ?></p>
                    <p class="muted">Current state: <?php echo htmlspecialchars($current_state_label ?? ''); ?></p>
                    <?php if (!empty($project['description'])): ?>
                        <p><?php echo nl2br(htmlspecialchars($project['description'])); ?></p>
                    <?php endif; ?>
                </section>

                <section class="card">
                    <h2>Project decisions</h2>
                    <p class="muted">Enable options to generate mandatory requirements.</p>

                    <form class="options-form" method="POST" action="project_view.php?id=<?php echo (int) $project_id; ?>">
                        <label class="option-item">
                            <input type="checkbox" name="supplier_involved" <?php echo (!empty($options['supplier_involved']) && $options['supplier_involved'] === '1') ? 'checked' : ''; ?>>
                            <span>Supplier involved</span>
                        </label>
                        <label class="option-item">
                            <input type="checkbox" name="firmware_involved" <?php echo (!empty($options['firmware_involved']) && $options['firmware_involved'] === '1') ? 'checked' : ''; ?>>
                            <span>Firmware involved</span>
                        </label>
                        <label class="option-item">
                            <input type="checkbox" name="testing_enabled" <?php echo (!empty($options['testing_enabled']) && $options['testing_enabled'] === '1') ? 'checked' : ''; ?>>
                            <span>Testing enabled</span>
                        </label>
                        <label class="option-item">
                            <input type="checkbox" name="final_approval_enabled" <?php echo (!empty($options['final_approval_enabled']) && $options['final_approval_enabled'] === '1') ? 'checked' : ''; ?>>
                            <span>Final approval required</span>
                        </label>

                        <div class="actions">
                            <button type="submit" class="btn btn-primary" name="save_options">Save decisions</button>
                        </div>
                    </form>
                </section>

                <section class="card">
                    <h2>Generated requirements</h2>
                    <?php if (empty($requirements)): ?>
                        <p class="muted">No requirements generated yet.</p>
                    <?php else: ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Requirement</th>
                                    <th>Status</th>
                                    <th>Source</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($requirements as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['label']); ?></td>
                                        <td><?php echo htmlspecialchars($row['status']); ?></td>
                                        <td><?php echo htmlspecialchars($row['source_option_key'] ?? ''); ?></td>
                                        <td>
                                            <?php if ($row['status'] === 'pending' && (($role ?? '') === 'admin' || ($role ?? '') === 'coordinator')): ?>
                                                <form class="inline-form" method="POST" action="project_view.php?id=<?php echo (int) $project_id; ?>">
                                                    <button class="btn btn-secondary" type="submit" name="resolve_requirement_id" value="<?php echo (int) $row['id']; ?>">
                                                        Mark resolved
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </section>

                <section class="card">
                    <h2>Project phases</h2>
                    <?php if (empty($phases)): ?>
                        <p class="muted">No phases generated yet.</p>
                    <?php else: ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Sequence</th>
                                    <th>Phase</th>
                                    <th>Owner role</th>
                                    <th>Mandatory</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($phases as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['sequence_order']); ?></td>
                                        <td><?php echo htmlspecialchars($row['name']); ?></td>
                                        <td><?php echo htmlspecialchars($row['owner_role']); ?></td>
                                        <td><?php echo $row['is_mandatory'] ? 'Yes' : 'No'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </section>

                <section class="card">
                    <h2>Submit a phase</h2>
                    <form class="phase-form" method="POST" action="project_view.php?id=<?php echo (int) $project_id; ?>">
                        <label for="phase_id">Phase</label>
                        <select id="phase_id" name="phase_id" required>
                            <option value="">-- Select phase --</option>
                            <?php foreach ($phases as $row): ?>
                                <option value="<?php echo (int) $row['id']; ?>">
                                    <?php echo htmlspecialchars($row['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label for="submission_note">Submission note</label>
                        <textarea id="submission_note" name="submission_note" rows="3"></textarea>

                        <div class="actions">
                            <button type="submit" class="btn btn-primary" name="submit_phase">Submit phase</button>
                        </div>
                    </form>
                </section>

                <section class="card">
                    <h2>Phase submissions</h2>
                    <?php if (empty($phase_submissions)): ?>
                        <p class="muted">No phase submissions yet.</p>
                    <?php else: ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Phase</th>
                                    <th>Status</th>
                                    <th>Submitted By</th>
                                    <th>Submitted At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($phase_submissions as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['phase_name']); ?></td>
                                        <td><?php echo htmlspecialchars($row['status']); ?></td>
                                        <td><?php echo htmlspecialchars($row['username'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($row['submitted_at']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </section>
            </div>

            <aside class="project-side">
                <section class="card card-compact">
                    <h2>Workflow actions</h2>
                    <p class="muted">Current state: <?php echo htmlspecialchars($current_state_label ?? ''); ?></p>
                    <?php if (empty($allowed_transitions)): ?>
                        <p class="muted">No actions available for your role.</p>
                    <?php else: ?>
                        <form class="actions" method="POST" action="project_view.php?id=<?php echo (int) $project_id; ?>">
                            <?php foreach ($allowed_transitions as $transition): ?>
                                <button class="btn btn-primary" type="submit" name="transition_id" value="<?php echo (int) $transition['id']; ?>">
                                    <?php
                                    $action_label = ucwords(str_replace('_', ' ', $transition['action']));
                                    $to_label = $to_state_labels[(int) $transition['to_state_id']] ?? '';
                                    echo htmlspecialchars($action_label . ($to_label !== '' ? ' → ' . $to_label : ''));
                                    ?>
                                </button>
                            <?php endforeach; ?>
                        </form>
                    <?php endif; ?>
                </section>

                <section class="card card-compact">
                    <h2>State history</h2>
                    <?php
                    $history = [];
                    $history_result = $conn->query(
                        "SELECT h.*, f.label AS from_label, t.label AS to_label, u.username
                         FROM project_state_history h
                         LEFT JOIN workflow_states f ON f.id = h.from_state_id
                         LEFT JOIN workflow_states t ON t.id = h.to_state_id
                         LEFT JOIN users u ON u.id = h.acted_by
                         WHERE h.project_id = " . (int) $project_id . "
                         ORDER BY h.created_at DESC"
                    );
                    if ($history_result) {
                        while ($row = $history_result->fetch_assoc()) {
                            $history[] = $row;
                        }
                    }
                    ?>
                    <?php if (empty($history)): ?>
                        <p class="muted">No state history yet.</p>
                    <?php else: ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>From</th>
                                    <th>To</th>
                                    <th>At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($history as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['from_label'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($row['to_label'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($row['created_at']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </section>

                <section class="card card-compact">
                    <h2>Project notifications</h2>
                    <?php if (empty($project_notifications)): ?>
                        <p class="muted">No notifications for this project.</p>
                    <?php else: ?>
                        <ul class="activity-list">
                            <?php foreach ($project_notifications as $note): ?>
                                <li>
                                    <div class="activity-title"><?php echo htmlspecialchars($note['title']); ?></div>
                                    <div class="muted"><?php echo htmlspecialchars($note['message']); ?></div>
                                    <div class="activity-meta"><?php echo htmlspecialchars($note['created_at']); ?></div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
            </aside>
        </div>
    </main>
</body>
</html>
