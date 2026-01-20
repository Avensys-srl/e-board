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
$view = $_GET['view'] ?? 'documents';
$view = in_array($view, ['documents', 'phases', 'projects'], true) ? $view : 'documents';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_phase_template'])) {
    $name = trim($_POST['phase_name'] ?? '');
    $owner_role = trim($_POST['phase_owner_role'] ?? '');
    $phase_type = trim($_POST['phase_type'] ?? 'process');
    $required_doc_type = trim($_POST['phase_required_doc_type'] ?? '');

    if ($name === '' || $owner_role === '') {
        $error = "Phase name and owner role are required.";
    } else {
        if ($required_doc_type !== '') {
            $stmt = $conn->prepare(
                "SELECT id FROM document_types WHERE code = ? AND is_active = 1 LIMIT 1"
            );
            $stmt->bind_param("s", $required_doc_type);
            $stmt->execute();
            $result = $stmt->get_result();
            $valid_doc = ($result && $result->num_rows === 1);
            $stmt->close();
            if (!$valid_doc) {
                $error = "Required document type must be an active document type.";
            }
        }
    }

    if ($error === '') {
        $stmt = $conn->prepare(
            "INSERT INTO phase_templates (name, owner_role, phase_type, required_doc_type)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->bind_param("ssss", $name, $owner_role, $phase_type, $required_doc_type);
        if ($stmt->execute()) {
            $success = "Phase template created.";
        } else {
            $error = "Unable to create phase template.";
        }
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_project_phase'])) {
    $project_type = trim($_POST['setup_project_type_custom'] ?? '');
    $project_type = preg_replace('/\s+/', ' ', $project_type);
    if ($project_type === '') {
        $project_type = trim($_POST['setup_project_type_select'] ?? '');
        $project_type = preg_replace('/\s+/', ' ', $project_type);
    }
    $phase_template_id = (int) ($_POST['phase_template_id'] ?? 0);
    $sequence_order = (int) ($_POST['sequence_order'] ?? 0);
    $is_mandatory = isset($_POST['is_mandatory']) ? 1 : 0;

    if ($project_type === '' || $phase_template_id <= 0 || $sequence_order <= 0) {
        $error = "Project type, phase template, and sequence are required.";
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO project_type_phase_templates (project_type, phase_template_id, sequence_order, is_mandatory)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE sequence_order = VALUES(sequence_order), is_mandatory = VALUES(is_mandatory)"
        );
        $stmt->bind_param("siii", $project_type, $phase_template_id, $sequence_order, $is_mandatory);
        if ($stmt->execute()) {
            $success = "Phase added to project template.";
        } else {
            $error = "Unable to add phase to project template.";
        }
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_project_phase_doc'])) {
    $project_type = trim($_POST['req_project_type_custom'] ?? '');
    $project_type = preg_replace('/\s+/', ' ', $project_type);
    if ($project_type === '') {
        $project_type = trim($_POST['req_project_type_select'] ?? '');
        $project_type = preg_replace('/\s+/', ' ', $project_type);
    }
    $phase_template_id = (int) ($_POST['req_phase_template_id'] ?? 0);
    $document_type_id = (int) ($_POST['req_document_type_id'] ?? 0);
    $is_required = isset($_POST['req_is_required']) ? 1 : 0;

    if ($project_type === '' || $phase_template_id <= 0 || $document_type_id <= 0) {
        $error = "Project type, phase template, and document type are required.";
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO project_type_phase_documents (project_type, phase_template_id, document_type_id, is_required)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE is_required = VALUES(is_required)"
        );
        $stmt->bind_param("siii", $project_type, $phase_template_id, $document_type_id, $is_required);
        if ($stmt->execute()) {
            $success = "Phase document requirement saved.";
        } else {
            $error = "Unable to save phase document requirement.";
        }
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_project_phase_dependency'])) {
    $project_type = trim($_POST['dep_project_type_custom'] ?? '');
    $project_type = preg_replace('/\s+/', ' ', $project_type);
    if ($project_type === '') {
        $project_type = trim($_POST['dep_project_type_select'] ?? '');
        $project_type = preg_replace('/\s+/', ' ', $project_type);
    }
    $phase_template_id = (int) ($_POST['dep_phase_template_id'] ?? 0);
    $depends_on_template_id = (int) ($_POST['depends_on_template_id'] ?? 0);

    if ($project_type === '' || $phase_template_id <= 0 || $depends_on_template_id <= 0) {
        $error = "Project type and two phases are required.";
    } elseif ($phase_template_id === $depends_on_template_id) {
        $error = "Select two different phases.";
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO project_type_phase_dependencies (project_type, phase_template_id, depends_on_template_id)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE phase_template_id = phase_template_id"
        );
        $stmt->bind_param("sii", $project_type, $phase_template_id, $depends_on_template_id);
        if ($stmt->execute()) {
            $success = "Phase dependency added.";
        } else {
            $error = "Unable to add phase dependency.";
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

            $stmt = $conn->prepare("UPDATE project_type_phase_templates SET project_type = ? WHERE project_type = ?");
            $stmt->bind_param("ss", $new_type, $old_type);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("UPDATE project_type_phase_documents SET project_type = ? WHERE project_type = ?");
            $stmt->bind_param("ss", $new_type, $old_type);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("UPDATE project_type_phase_dependencies SET project_type = ? WHERE project_type = ?");
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
            $stmt = $conn->prepare("DELETE FROM project_type_phase_templates WHERE project_type = ?");
            $stmt->bind_param("s", $project_type);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("DELETE FROM project_type_phase_documents WHERE project_type = ?");
            $stmt->bind_param("s", $project_type);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("DELETE FROM project_type_phase_dependencies WHERE project_type = ?");
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
     UNION
     SELECT DISTINCT project_type FROM project_type_phase_templates
     UNION
     SELECT DISTINCT project_type FROM project_type_phase_documents
     UNION
     SELECT DISTINCT project_type FROM project_type_phase_dependencies
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
$result = $conn->query(
    "SELECT project_type, COUNT(*) AS cnt
     FROM project_type_phase_templates
     GROUP BY project_type"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $project_type_stats[$row['project_type']]['phases'] = (int) $row['cnt'];
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

$phase_templates = [];
$result = $conn->query(
    "SELECT * FROM phase_templates ORDER BY name ASC"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $phase_templates[] = $row;
    }
}

$project_type_phase_templates = [];
$result = $conn->query(
    "SELECT ppt.*, pt.name AS phase_name, pt.owner_role, pt.phase_type
     FROM project_type_phase_templates ppt
     JOIN phase_templates pt ON pt.id = ppt.phase_template_id
     ORDER BY ppt.project_type ASC, ppt.sequence_order ASC"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $project_type_phase_templates[] = $row;
    }
}

$project_type_phase_documents = [];
$result = $conn->query(
    "SELECT ptd.*, pt.name AS phase_name, dt.label AS doc_label
     FROM project_type_phase_documents ptd
     JOIN phase_templates pt ON pt.id = ptd.phase_template_id
     JOIN document_types dt ON dt.id = ptd.document_type_id
     ORDER BY ptd.project_type ASC, pt.name ASC, dt.label ASC"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $project_type_phase_documents[] = $row;
    }
}

$project_type_phase_dependencies = [];
$result = $conn->query(
    "SELECT ptd.*, pt.name AS phase_name, dep.name AS depends_on_name
     FROM project_type_phase_dependencies ptd
     JOIN phase_templates pt ON pt.id = ptd.phase_template_id
     JOIN phase_templates dep ON dep.id = ptd.depends_on_template_id
     ORDER BY ptd.project_type ASC, pt.name ASC"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $project_type_phase_dependencies[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Project configuration</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="page">
    <main class="page-shell">
        <header class="page-header">
            <div>
                <p class="eyebrow">Administration</p>
                <h1>Project configuration</h1>
                <p class="muted">Manage document types, phase templates, and project types.</p>
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

        <section class="card card-compact">
            <div class="actions">
                <a class="<?php echo $view === 'documents' ? 'btn' : 'btn btn-secondary'; ?>" href="project_config.php?view=documents">Document management</a>
                <a class="<?php echo $view === 'phases' ? 'btn' : 'btn btn-secondary'; ?>" href="project_config.php?view=phases">Phase management</a>
                <a class="<?php echo $view === 'projects' ? 'btn' : 'btn btn-secondary'; ?>" href="project_config.php?view=projects">Project setup</a>
            </div>
        </section>

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

        <?php if ($view === 'documents'): ?>
            <section class="card">
                <div class="card-header">
                    <h2>Add document type</h2>
                </div>
                <form class="form-grid" method="POST" action="project_config.php">
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
                                        <form class="inline-form" method="POST" action="project_config.php">
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

        <?php endif; ?>

        <?php if ($view === 'projects'): ?>
            <section class="card">
                <div class="card-header">
                    <h2>Project-level documents</h2>
                    <p class="muted">Documents required at the project level (not tied to a specific phase).</p>
                </div>
                <form class="form-grid" method="POST" action="project_config.php?view=projects">
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
                    <h2>Project-level document requirements</h2>
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
                                            <form class="inline-form" method="POST" action="project_config.php?view=projects">
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

            <section class="card">
                <div class="card-header">
                    <h2>Project type phases</h2>
                    <p class="muted">Attach phase templates to project types.</p>
                </div>
                <form class="form-grid" method="POST" action="project_config.php?view=projects">
                    <label for="setup_project_type_select">Project type</label>
                    <select id="setup_project_type_select" name="setup_project_type_select">
                        <option value="">-- Select project type --</option>
                        <?php foreach ($project_types as $type): ?>
                            <option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($type); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label for="setup_project_type_custom">Or add new project type</label>
                    <input id="setup_project_type_custom" type="text" name="setup_project_type_custom" placeholder="e.g. Control Board">

                    <label for="phase_template_id">Phase template</label>
                    <select id="phase_template_id" name="phase_template_id" required>
                        <option value="">-- Select phase template --</option>
                        <?php foreach ($phase_templates as $tpl): ?>
                            <option value="<?php echo (int) $tpl['id']; ?>">
                                <?php echo htmlspecialchars($tpl['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="sequence_order">Sequence order</label>
                    <input id="sequence_order" type="number" name="sequence_order" min="1" step="1" required>

                    <label class="delete-option">
                        <input type="checkbox" name="is_mandatory" checked>
                        <span>Mandatory</span>
                    </label>

                    <div class="actions">
                        <button class="btn btn-primary" type="submit" name="add_project_phase">Add phase</button>
                    </div>
                </form>

                <?php if (empty($project_type_phase_templates)): ?>
                    <p class="muted">No phase templates assigned yet.</p>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Project type</th>
                                <th>Phase</th>
                                <th>Owner role</th>
                                <th>Type</th>
                                <th>Sequence</th>
                                <th>Mandatory</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($project_type_phase_templates as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['project_type']); ?></td>
                                    <td><?php echo htmlspecialchars($row['phase_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['owner_role']); ?></td>
                                    <td><?php echo htmlspecialchars($row['phase_type']); ?></td>
                                    <td><?php echo (int) $row['sequence_order']; ?></td>
                                    <td><?php echo $row['is_mandatory'] ? 'Yes' : 'No'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>

            <section class="card">
                <div class="card-header">
                    <h2>Phase document requirements</h2>
                    <p class="muted">Define which documents unlock a phase in a project type.</p>
                </div>
                <form class="form-grid" method="POST" action="project_config.php?view=projects">
                    <label for="req_project_type_select">Project type</label>
                    <select id="req_project_type_select" name="req_project_type_select">
                        <option value="">-- Select project type --</option>
                        <?php foreach ($project_types as $type): ?>
                            <option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($type); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label for="req_project_type_custom">Or add new project type</label>
                    <input id="req_project_type_custom" type="text" name="req_project_type_custom" placeholder="e.g. Control Board">

                    <label for="req_phase_template_id">Phase template</label>
                    <select id="req_phase_template_id" name="req_phase_template_id" required>
                        <option value="">-- Select phase template --</option>
                        <?php foreach ($phase_templates as $tpl): ?>
                            <option value="<?php echo (int) $tpl['id']; ?>">
                                <?php echo htmlspecialchars($tpl['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="req_document_type_id">Document type</label>
                    <select id="req_document_type_id" name="req_document_type_id" required>
                        <option value="">-- Select document type --</option>
                        <?php foreach ($document_types as $doc): ?>
                            <?php if ((int) ($doc['is_active'] ?? 0) === 1): ?>
                                <option value="<?php echo (int) $doc['id']; ?>">
                                    <?php echo htmlspecialchars($doc['label']); ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>

                    <label class="delete-option">
                        <input type="checkbox" name="req_is_required" checked>
                        <span>Required</span>
                    </label>

                    <div class="actions">
                        <button class="btn btn-primary" type="submit" name="add_project_phase_doc">Save requirement</button>
                    </div>
                </form>

                <?php if (empty($project_type_phase_documents)): ?>
                    <p class="muted">No phase document requirements yet.</p>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Project type</th>
                                <th>Phase</th>
                                <th>Document type</th>
                                <th>Required</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($project_type_phase_documents as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['project_type']); ?></td>
                                    <td><?php echo htmlspecialchars($row['phase_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['doc_label']); ?></td>
                                    <td><?php echo $row['is_required'] ? 'Yes' : 'No'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>

            <section class="card">
                <div class="card-header">
                    <h2>Phase dependencies</h2>
                    <p class="muted">Define which phases must be completed first.</p>
                </div>
                <form class="form-grid" method="POST" action="project_config.php?view=projects">
                    <label for="dep_project_type_select">Project type</label>
                    <select id="dep_project_type_select" name="dep_project_type_select">
                        <option value="">-- Select project type --</option>
                        <?php foreach ($project_types as $type): ?>
                            <option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($type); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label for="dep_project_type_custom">Or add new project type</label>
                    <input id="dep_project_type_custom" type="text" name="dep_project_type_custom" placeholder="e.g. Control Board">

                    <label for="dep_phase_template_id">Phase template</label>
                    <select id="dep_phase_template_id" name="dep_phase_template_id" required>
                        <option value="">-- Select phase template --</option>
                        <?php foreach ($phase_templates as $tpl): ?>
                            <option value="<?php echo (int) $tpl['id']; ?>">
                                <?php echo htmlspecialchars($tpl['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="depends_on_template_id">Depends on</label>
                    <select id="depends_on_template_id" name="depends_on_template_id" required>
                        <option value="">-- Select dependency --</option>
                        <?php foreach ($phase_templates as $tpl): ?>
                            <option value="<?php echo (int) $tpl['id']; ?>">
                                <?php echo htmlspecialchars($tpl['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <div class="actions">
                        <button class="btn btn-primary" type="submit" name="add_project_phase_dependency">Add dependency</button>
                    </div>
                </form>

                <?php if (empty($project_type_phase_dependencies)): ?>
                    <p class="muted">No phase dependencies yet.</p>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Project type</th>
                                <th>Phase</th>
                                <th>Depends on</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($project_type_phase_dependencies as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['project_type']); ?></td>
                                    <td><?php echo htmlspecialchars($row['phase_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['depends_on_name']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
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
                                <th>Phases</th>
                                <th>Rename</th>
                                <th>Delete</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($project_types as $type): ?>
                                <?php
                                $projects_count = $project_type_stats[$type]['projects'] ?? 0;
                                $requirements_count = $project_type_stats[$type]['requirements'] ?? 0;
                                $phases_count = $project_type_stats[$type]['phases'] ?? 0;
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($type); ?></td>
                                    <td><?php echo (int) $projects_count; ?></td>
                                    <td><?php echo (int) $requirements_count; ?></td>
                                    <td><?php echo (int) $phases_count; ?></td>
                                    <td>
                                        <form class="inline-form" method="POST" action="project_config.php?view=projects">
                                            <input type="hidden" name="old_project_type" value="<?php echo htmlspecialchars($type); ?>">
                                            <input type="text" name="new_project_type" placeholder="New name">
                                            <button class="btn btn-secondary" type="submit" name="rename_project_type">Save</button>
                                        </form>
                                    </td>
                                    <td>
                                        <?php if ($projects_count > 0): ?>
                                            <span class="muted">In use</span>
                                        <?php else: ?>
                                            <form class="inline-form" method="POST" action="project_config.php?view=projects">
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
        <?php endif; ?>

        <?php if ($view === 'phases'): ?>
            <section class="card">
                <div class="card-header">
                    <h2>Phase templates</h2>
                    <p class="muted">Define reusable phase templates.</p>
                </div>
                <form class="form-grid" method="POST" action="project_config.php?view=phases">
                    <label for="phase_name">Phase name</label>
                    <input id="phase_name" type="text" name="phase_name" placeholder="e.g. Schematic" required>

                    <label for="phase_owner_role">Owner role</label>
                    <select id="phase_owner_role" name="phase_owner_role" required>
                        <option value="designer">designer</option>
                        <option value="firmware">firmware</option>
                        <option value="tester">tester</option>
                        <option value="supplier">supplier</option>
                        <option value="coordinator">coordinator</option>
                    </select>

                    <label for="phase_type">Phase type</label>
                    <select id="phase_type" name="phase_type">
                        <option value="process">process</option>
                        <option value="document">document</option>
                        <option value="approval">approval</option>
                        <option value="test">test</option>
                    </select>

                    <label for="phase_required_doc_type">Required doc type (optional)</label>
                    <select id="phase_required_doc_type" name="phase_required_doc_type">
                        <option value="">-- None --</option>
                        <?php foreach ($document_types as $doc): ?>
                            <?php if ((int) ($doc['is_active'] ?? 0) === 1): ?>
                                <option value="<?php echo htmlspecialchars($doc['code']); ?>">
                                    <?php echo htmlspecialchars($doc['label']); ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>

                    <div class="actions">
                        <button class="btn btn-primary" type="submit" name="add_phase_template">Add phase template</button>
                    </div>
                </form>

                <?php if (empty($phase_templates)): ?>
                    <p class="muted">No phase templates yet.</p>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Phase</th>
                                <th>Owner role</th>
                                <th>Type</th>
                                <th>Required doc</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($phase_templates as $tpl): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($tpl['name']); ?></td>
                                    <td><?php echo htmlspecialchars($tpl['owner_role']); ?></td>
                                    <td><?php echo htmlspecialchars($tpl['phase_type']); ?></td>
                                    <td><?php echo htmlspecialchars($tpl['required_doc_type'] ?? ''); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>
