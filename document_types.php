<?php
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit;
}

require_once(__DIR__ . '/config/db.php');
require_once(__DIR__ . '/config/notifications.php');

$success = '';
$error = '';
$unread_notifications_count = get_unread_notifications_count($conn, (int) $_SESSION['user_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_doc_type'])) {
    $code = trim($_POST['code'] ?? '');
    $label = trim($_POST['label'] ?? '');
    if ($code === '' || $label === '') {
        $error = "Code and label are required.";
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO document_types (code, label) VALUES (?, ?)"
        );
        if ($stmt) {
            $stmt->bind_param("ss", $code, $label);
            if ($stmt->execute()) {
                $success = "Document type created.";
            } else {
                $error = "Unable to create document type.";
            }
            $stmt->close();
        } else {
            $error = "Unable to create document type.";
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_doc_type'])) {
    $doc_id = (int) ($_POST['doc_id'] ?? 0);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $stmt = $conn->prepare("UPDATE document_types SET is_active = ? WHERE id = ?");
    $stmt->bind_param("ii", $is_active, $doc_id);
    $stmt->execute();
    $stmt->close();
    $success = "Document type updated.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_mapping'])) {
    $project_type = trim($_POST['project_type_custom'] ?? '');
    $project_type = preg_replace('/\s+/', ' ', $project_type);
    if ($project_type === '') {
        $project_type = trim($_POST['project_type_select'] ?? '');
        $project_type = preg_replace('/\s+/', ' ', $project_type);
    }
    $document_type_id = (int) ($_POST['document_type_id'] ?? 0);
    $is_required = isset($_POST['is_required']) ? 1 : 0;
    if ($project_type === '' || $document_type_id <= 0) {
        $error = "Project type and document type are required.";
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO project_type_documents (project_type, document_type_id, is_required)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE is_required = VALUES(is_required)"
        );
        $stmt->bind_param("sii", $project_type, $document_type_id, $is_required);
        if ($stmt->execute()) {
            $success = "Project type mapping saved.";
        } else {
            $error = "Unable to save mapping.";
        }
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rename_project_type'])) {
    $old_type = trim($_POST['old_project_type'] ?? '');
    $new_type = trim($_POST['new_project_type'] ?? '');
    $new_type = preg_replace('/\s+/', ' ', $new_type);
    if ($old_type === '' || $new_type === '') {
        $error = "Project type name is required.";
    } else {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("UPDATE projects SET project_type = ? WHERE project_type = ?");
            $stmt->bind_param("ss", $new_type, $old_type);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("UPDATE project_type_documents SET project_type = ? WHERE project_type = ?");
            $stmt->bind_param("ss", $new_type, $old_type);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            $success = "Project type renamed.";
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Unable to rename project type.";
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_project_type'])) {
    $project_type = trim($_POST['project_type'] ?? '');
    if ($project_type === '') {
        $error = "Project type is required.";
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM projects WHERE project_type = ?");
        $stmt->bind_param("s", $project_type);
        $stmt->execute();
        $result = $stmt->get_result();
        $in_use = 0;
        if ($result && $result->num_rows === 1) {
            $row = $result->fetch_assoc();
            $in_use = (int) $row['cnt'];
        }
        $stmt->close();

        if ($in_use > 0) {
            $error = "Project type is in use and cannot be deleted.";
        } else {
            $stmt = $conn->prepare("DELETE FROM project_type_documents WHERE project_type = ?");
            $stmt->bind_param("s", $project_type);
            $stmt->execute();
            $stmt->close();
            $success = "Project type deleted.";
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_mapping'])) {
    $mapping_id = (int) ($_POST['mapping_id'] ?? 0);
    $project_type = trim($_POST['mapping_project_type'] ?? '');
    if ($mapping_id <= 0 || $project_type === '') {
        $error = "Mapping not found.";
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM projects WHERE project_type = ?");
        $stmt->bind_param("s", $project_type);
        $stmt->execute();
        $result = $stmt->get_result();
        $in_use = 0;
        if ($result && $result->num_rows === 1) {
            $row = $result->fetch_assoc();
            $in_use = (int) $row['cnt'];
        }
        $stmt->close();

        if ($in_use > 0) {
            $error = "Cannot remove required documents for an active project type.";
        } else {
            $stmt = $conn->prepare("DELETE FROM project_type_documents WHERE id = ?");
            $stmt->bind_param("i", $mapping_id);
            $stmt->execute();
            $stmt->close();
            $success = "Requirement removed.";
        }
    }
}

$document_types = [];
$result = $conn->query("SELECT * FROM document_types ORDER BY label ASC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $document_types[] = $row;
    }
}

$project_types = [];
$result = $conn->query(
    "SELECT DISTINCT project_type FROM projects WHERE project_type IS NOT NULL AND project_type <> ''
     UNION
     SELECT DISTINCT project_type FROM project_type_documents
     ORDER BY project_type ASC"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $project_types[] = $row['project_type'];
    }
}

$project_type_stats = [];
$result = $conn->query(
    "SELECT project_type, COUNT(*) AS cnt
     FROM projects
     WHERE project_type IS NOT NULL AND project_type <> ''
     GROUP BY project_type"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $project_type_stats[$row['project_type']]['projects'] = (int) $row['cnt'];
    }
}
$result = $conn->query(
    "SELECT project_type, COUNT(*) AS cnt
     FROM project_type_documents
     GROUP BY project_type"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $project_type_stats[$row['project_type']]['requirements'] = (int) $row['cnt'];
    }
}

$mappings = [];
$result = $conn->query(
    "SELECT ptd.*, dt.code, dt.label
     FROM project_type_documents ptd
     JOIN document_types dt ON dt.id = ptd.document_type_id
     ORDER BY ptd.project_type ASC, dt.label ASC"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $mappings[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Document types</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="page">
    <main class="page-shell">
        <header class="page-header">
            <div>
                <p class="eyebrow">Administration</p>
                <h1>Document types</h1>
                <p class="muted">Manage document types and project requirements.</p>
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
                <a class="btn btn-secondary" href="settings.php">Settings</a>
                <a class="btn btn-secondary" href="dashboard.php">Back to dashboard</a>
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

        <section class="card">
            <div class="card-header">
                <h2>Add document type</h2>
            </div>
            <form class="form-grid" method="POST" action="document_types.php">
                <label for="code">Code</label>
                <input id="code" type="text" name="code" placeholder="schematic" required>

                <label for="label">Label</label>
                <input id="label" type="text" name="label" placeholder="Schematic" required>

                <div class="actions">
                    <button class="btn btn-primary" type="submit" name="add_doc_type">Create</button>
                </div>
            </form>
        </section>

        <section class="card">
            <div class="card-header">
                <h2>Document types</h2>
            </div>
            <?php if (empty($document_types)): ?>
                <p class="muted">No document types yet.</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Label</th>
                            <th>Active</th>
                            <th>Update</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($document_types as $doc): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($doc['code']); ?></td>
                                <td><?php echo htmlspecialchars($doc['label']); ?></td>
                                <td><?php echo $doc['is_active'] ? 'Yes' : 'No'; ?></td>
                                <td>
                                    <form class="inline-form" method="POST" action="document_types.php">
                                        <input type="hidden" name="doc_id" value="<?php echo (int) $doc['id']; ?>">
                                        <label class="delete-option">
                                            <input type="checkbox" name="is_active" <?php echo $doc['is_active'] ? 'checked' : ''; ?>>
                                            <span>Active</span>
                                        </label>
                                        <button class="btn btn-secondary" type="submit" name="toggle_doc_type">Save</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <section class="card">
            <div class="card-header">
                <h2>Project type requirements</h2>
                <p class="muted">Assign required document types per project type.</p>
            </div>
            <form class="form-grid" method="POST" action="document_types.php">
                <label for="project_type_select">Project type</label>
                <select id="project_type_select" name="project_type_select">
                    <option value="">-- Select project type --</option>
                    <?php foreach ($project_types as $type): ?>
                        <option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($type); ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="project_type_custom">Or add new project type</label>
                <input id="project_type_custom" type="text" name="project_type_custom" placeholder="e.g. Control Board">

                <label for="document_type_id">Document type</label>
                <select id="document_type_id" name="document_type_id" required>
                    <option value="">-- Select type --</option>
                    <?php foreach ($document_types as $doc): ?>
                        <option value="<?php echo (int) $doc['id']; ?>">
                            <?php echo htmlspecialchars($doc['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label class="delete-option">
                    <input type="checkbox" name="is_required" checked>
                    <span>Required</span>
                </label>

                <div class="actions">
                    <button class="btn btn-primary" type="submit" name="add_mapping">Save requirement</button>
                </div>
            </form>
        </section>

        <section class="card">
            <div class="card-header">
                <h2>Project types</h2>
                <p class="muted">Rename or remove project types that are not in use.</p>
            </div>
            <?php if (empty($project_types)): ?>
                <p class="muted">No project types available.</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Project type</th>
                            <th>Projects</th>
                            <th>Requirements</th>
                            <th>Rename</th>
                            <th>Delete</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($project_types as $type): ?>
                            <?php
                            $projects_count = $project_type_stats[$type]['projects'] ?? 0;
                            $requirements_count = $project_type_stats[$type]['requirements'] ?? 0;
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($type); ?></td>
                                <td><?php echo (int) $projects_count; ?></td>
                                <td><?php echo (int) $requirements_count; ?></td>
                                <td>
                                    <form class="inline-form" method="POST" action="document_types.php">
                                        <input type="hidden" name="old_project_type" value="<?php echo htmlspecialchars($type); ?>">
                                        <input type="text" name="new_project_type" placeholder="New name">
                                        <button class="btn btn-secondary" type="submit" name="rename_project_type">Save</button>
                                    </form>
                                </td>
                                <td>
                                    <?php if ($projects_count > 0): ?>
                                        <span class="muted">In use</span>
                                    <?php else: ?>
                                        <form class="inline-form" method="POST" action="document_types.php">
                                            <input type="hidden" name="project_type" value="<?php echo htmlspecialchars($type); ?>">
                                            <button class="btn btn-danger" type="submit" name="delete_project_type" onclick="return confirm('Delete this project type?');">Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <section class="card">
            <div class="card-header">
                <h2>Current requirements</h2>
            </div>
            <?php if (empty($mappings)): ?>
                <p class="muted">No requirements configured.</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Project type</th>
                            <th>Document type</th>
                            <th>Required</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($mappings as $map): ?>
                            <?php
                            $projects_count = $project_type_stats[$map['project_type']]['projects'] ?? 0;
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($map['project_type']); ?></td>
                                <td><?php echo htmlspecialchars($map['label']); ?></td>
                                <td><?php echo $map['is_required'] ? 'Yes' : 'No'; ?></td>
                                <td>
                                    <?php if ($projects_count > 0): ?>
                                        <span class="muted">Locked</span>
                                    <?php else: ?>
                                        <form class="inline-form" method="POST" action="document_types.php">
                                            <input type="hidden" name="mapping_id" value="<?php echo (int) $map['id']; ?>">
                                            <input type="hidden" name="mapping_project_type" value="<?php echo htmlspecialchars($map['project_type']); ?>">
                                            <button class="btn btn-danger" type="submit" name="delete_mapping">Remove</button>
                                        </form>
                                    <?php endif; ?>
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
