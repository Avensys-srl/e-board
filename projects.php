<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once(__DIR__ . '/config/db.php');
require_once(__DIR__ . '/config/workflow.php');
require_once(__DIR__ . '/config/notifications.php');
require_once(__DIR__ . '/config/settings.php');
require_once(__DIR__ . '/config/uploads.php');

$success = '';
$error = '';
$user_id = (int) ($_SESSION['user_id'] ?? 0);
$unread_notifications_count = get_unread_notifications_count($conn, $user_id);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_project'])) {
    $code = strtoupper(trim($_POST['code'] ?? ''));
    $code = preg_replace('/\s+/', '-', $code);
    $version = trim($_POST['version'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $project_type = trim($_POST['project_type_custom'] ?? '');
    $project_type = preg_replace('/\s+/', ' ', $project_type);
    if ($project_type === '') {
        $project_type = trim($_POST['project_type_select'] ?? '');
        $project_type = preg_replace('/\s+/', ' ', $project_type);
    }
    $owner_id = trim($_POST['owner_id'] ?? '');
    $start_date = trim($_POST['start_date'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($name === '' || $code === '' || $version === '') {
        $error = "Project code, version, and name are required.";
    } elseif (!ctype_digit($version)) {
        $error = "Project version must be an integer.";
    } else {
        $version = (int) $version;
        $stmt = $conn->prepare("SELECT id FROM projects WHERE code = ? AND version = ? LIMIT 1");
        $stmt->bind_param("si", $code, $version);
        $stmt->execute();
        $result = $stmt->get_result();
        $exists = ($result && $result->num_rows > 0);
        $stmt->close();

        if ($exists) {
            $error = "Project code and version must be unique.";
        } else {
        ensure_default_project_workflow($conn);
        $draft_state_id = get_project_state_id($conn, 'draft');
        $owner_id = $owner_id !== '' ? (int) $owner_id : null;
        $start_date = $start_date !== '' ? $start_date : null;

        $stmt = $conn->prepare(
            "INSERT INTO projects (code, version, name, project_type, owner_id, start_date, description, created_by, current_state_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if ($stmt) {
            $created_by = (int) $_SESSION['user_id'];
            $stmt->bind_param("sississii", $code, $version, $name, $project_type, $owner_id, $start_date, $description, $created_by, $draft_state_id);
            if ($stmt->execute()) {
                $new_project_id = (int) $stmt->insert_id;
                $inherit = isset($_POST['inherit_previous']) && $_POST['inherit_previous'] === '1';
                if ($inherit) {
                    $stmt->close();
                    $stmt = $conn->prepare(
                        "SELECT id FROM projects
                         WHERE code = ? AND id <> ?
                         ORDER BY created_at DESC
                         LIMIT 1"
                    );
                    $stmt->bind_param("si", $code, $new_project_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $previous = $result && $result->num_rows === 1 ? $result->fetch_assoc() : null;
                    $stmt->close();

                    if ($previous) {
                        $phase_map = [];
                        $phase_result = $conn->query(
                            "SELECT id, required_doc_type
                             FROM project_phases
                             WHERE project_id = " . $new_project_id
                        );
                        if ($phase_result) {
                            while ($row = $phase_result->fetch_assoc()) {
                                if (!empty($row['required_doc_type']) && !isset($phase_map[$row['required_doc_type']])) {
                                    $phase_map[$row['required_doc_type']] = (int) $row['id'];
                                }
                            }
                        }

                        $upload_base_path = get_setting($conn, 'upload_base_path', 'uploads');
                        $upload_base_url = get_setting($conn, 'upload_base_url', '');
                $project_subdir = build_project_subdir($code, (string) $version);
                        $stmt = $conn->prepare(
                            "SELECT doc_type, phase_id, storage_type, storage_path, storage_url, original_name, mime_type, size_bytes
                             FROM attachments
                             WHERE project_id = ? AND is_deleted = 0"
                        );
                        $stmt->bind_param("i", $previous['id']);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        if ($result) {
                            while ($row = $result->fetch_assoc()) {
                                $phase_id = null;
                                if (!empty($row['doc_type']) && isset($phase_map[$row['doc_type']])) {
                                    $phase_id = $phase_map[$row['doc_type']];
                                }
                                if ($row['storage_type'] === 'local' && !empty($row['storage_path'])) {
                                    list($ok, $file_data) = copy_existing_file(
                                        $upload_base_path,
                                        $upload_base_url,
                                        $row['storage_path'],
                                        $project_subdir
                                    );
                                    if ($ok) {
                                        $insert = $conn->prepare(
                                            "INSERT INTO attachments
                                             (project_id, phase_id, uploaded_by, storage_type, storage_path, storage_url, original_name, doc_type, mime_type, size_bytes)
                                             VALUES (?, ?, ?, 'local', ?, ?, ?, ?, ?, ?)"
                                        );
                                        $insert->bind_param(
                                            "iiisssssi",
                                            $new_project_id,
                                            $phase_id,
                                            $created_by,
                                            $file_data['storage_path'],
                                            $file_data['storage_url'],
                                            $file_data['original_name'],
                                            $row['doc_type'],
                                            $file_data['mime_type'],
                                            $file_data['size_bytes']
                                        );
                                        $insert->execute();
                                        $insert->close();
                                    }
                                } else {
                                    $insert = $conn->prepare(
                                        "INSERT INTO attachments
                                         (project_id, phase_id, uploaded_by, storage_type, storage_path, storage_url, original_name, doc_type, mime_type, size_bytes)
                                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                                    );
                                    $insert->bind_param(
                                        "iiissssssi",
                                        $new_project_id,
                                        $phase_id,
                                        $created_by,
                                        $row['storage_type'],
                                        $row['storage_path'],
                                        $row['storage_url'],
                                        $row['original_name'],
                                        $row['doc_type'],
                                        $row['mime_type'],
                                        $row['size_bytes']
                                    );
                                    $insert->execute();
                                    $insert->close();
                                }
                            }
                        }
                        $stmt->close();
                    }
                } else {
                    $stmt->close();
                }
                $success = "Project created.";
            } else {
                $error = "Unable to create project. " . $stmt->error;
                $stmt->close();
            }
        } else {
            $error = "Database error while creating project. " . $conn->error;
        }
        }
    }
}

$users = [];
$user_result = $conn->query("SELECT id, username, role FROM users ORDER BY username ASC");
if ($user_result) {
    while ($row = $user_result->fetch_assoc()) {
        $users[] = $row;
    }
}

$projects = [];
$project_result = $conn->query(
    "SELECT p.*, u.username AS owner_name
     FROM projects p
     LEFT JOIN users u ON u.id = p.owner_id
     ORDER BY p.created_at DESC"
);
if ($project_result) {
    while ($row = $project_result->fetch_assoc()) {
        $projects[] = $row;
    }
}

$project_types = [];
$type_result = $conn->query(
    "SELECT DISTINCT project_type FROM projects WHERE project_type IS NOT NULL AND project_type <> ''
     UNION
     SELECT DISTINCT project_type FROM project_type_documents
     UNION
     SELECT DISTINCT project_type FROM project_type_phase_templates
     UNION
     SELECT DISTINCT project_type FROM project_type_phase_documents
     UNION
     SELECT DISTINCT project_type FROM project_type_phase_dependencies
     ORDER BY project_type ASC"
);
if ($type_result) {
    while ($row = $type_result->fetch_assoc()) {
        $project_types[] = $row['project_type'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Projects</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="page">
    <main class="page-shell">
        <header class="page-header">
            <div>
                <p class="eyebrow">Projects</p>
                <h1>Project list</h1>
                <p class="muted">Create a new project or open an existing one.</p>
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

            <form class="project-form" method="POST" action="projects.php">
                <label for="code">Project code</label>
                <input id="code" type="text" name="code" placeholder="e.g. AELEBDQRK01" required>

                <label for="version">Project version</label>
                <input id="version" type="number" name="version" placeholder="e.g. 10" min="1" step="1" required>

                <label for="name">Project name</label>
                <input id="name" type="text" name="name" placeholder="e.g. quark top board" required>

                <label for="project_type_select">Project type</label>
                <select id="project_type_select" name="project_type_select">
                    <option value="">-- Select project type --</option>
                    <?php foreach ($project_types as $type): ?>
                        <option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($type); ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="project_type_custom">Or add new project type</label>
                <input id="project_type_custom" type="text" name="project_type_custom" placeholder="e.g. Control Board">

                <label for="owner_id">Project owner</label>
                <select id="owner_id" name="owner_id">
                    <option value="">-- Select owner --</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?php echo (int) $user['id']; ?>">
                            <?php echo htmlspecialchars($user['username']); ?>
                            (<?php echo htmlspecialchars($user['role']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="start_date">Start date</label>
                <input id="start_date" type="date" name="start_date">

                <label for="description">Description</label>
                <textarea id="description" name="description" rows="4"></textarea>

                <label class="delete-option">
                    <input type="checkbox" name="inherit_previous" value="1">
                    <span>Inherit files from previous version (same code)</span>
                </label>

                <div class="actions">
                    <button type="submit" class="btn btn-primary" name="create_project">Create project</button>
                </div>
            </form>
        </section>

        <section class="card">
            <h2>Existing projects</h2>
            <?php if (empty($projects)): ?>
                <p class="muted">No projects created yet.</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Version</th>
                            <th>Name</th>
                            <th>Owner</th>
                            <th>Start date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($projects as $project): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($project['code']); ?></td>
                                <td><?php echo htmlspecialchars($project['version'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($project['name']); ?></td>
                                <td><?php echo htmlspecialchars($project['owner_name'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($project['start_date'] ?? ''); ?></td>
                                <td>
                                    <a class="btn btn-secondary" href="project_view.php?id=<?php echo (int) $project['id']; ?>">
                                        Open
                                    </a>
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
