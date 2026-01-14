<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once(__DIR__ . '/config/db.php');
require_once(__DIR__ . '/config/project_logic.php');

$project_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($project_id <= 0) {
    header('Location: projects.php');
    exit;
}

$success = '';
$error = '';

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
                <a class="btn btn-secondary" href="projects.php">Back to projects</a>
                <a class="btn btn-secondary" href="dashboard.php">Dashboard</a>
            </div>
        </header>

        <section class="card">
            <h2>Project details</h2>
            <p class="muted">Owner: <?php echo htmlspecialchars($project['owner_name'] ?? ''); ?></p>
            <p class="muted">Start date: <?php echo htmlspecialchars($project['start_date'] ?? ''); ?></p>
            <?php if (!empty($project['description'])): ?>
                <p><?php echo nl2br(htmlspecialchars($project['description'])); ?></p>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2>Project decisions</h2>
            <p class="muted">Enable options to generate mandatory requirements.</p>

            <?php if ($success): ?>
                <p class="alert alert-success"><?php echo htmlspecialchars($success); ?></p>
            <?php endif; ?>

            <?php if ($error): ?>
                <p class="alert alert-error"><?php echo htmlspecialchars($error); ?></p>
            <?php endif; ?>

            <form class="options-form" method="POST" action="project_view.php?id=<?php echo (int) $project_id; ?>">
                <label>
                    <input type="checkbox" name="supplier_involved" <?php echo (!empty($options['supplier_involved']) && $options['supplier_involved'] === '1') ? 'checked' : ''; ?>>
                    Supplier involved
                </label>
                <label>
                    <input type="checkbox" name="firmware_involved" <?php echo (!empty($options['firmware_involved']) && $options['firmware_involved'] === '1') ? 'checked' : ''; ?>>
                    Firmware involved
                </label>
                <label>
                    <input type="checkbox" name="testing_enabled" <?php echo (!empty($options['testing_enabled']) && $options['testing_enabled'] === '1') ? 'checked' : ''; ?>>
                    Testing enabled
                </label>
                <label>
                    <input type="checkbox" name="final_approval_enabled" <?php echo (!empty($options['final_approval_enabled']) && $options['final_approval_enabled'] === '1') ? 'checked' : ''; ?>>
                    Final approval required
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
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requirements as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['label']); ?></td>
                                <td><?php echo htmlspecialchars($row['status']); ?></td>
                                <td><?php echo htmlspecialchars($row['source_option_key'] ?? ''); ?></td>
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
    </main>
</body>
</html>
