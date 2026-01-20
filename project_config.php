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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_doc_type'])) {
    $doc_id = (int) ($_POST['doc_id'] ?? 0);
    $label = trim($_POST['label'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    if ($doc_id <= 0 || $label === '') {
        $error = "Document type label is required.";
    } else {
        $stmt = $conn->prepare("UPDATE document_types SET label = ?, is_active = ? WHERE id = ?");
        $stmt->bind_param("sii", $label, $is_active, $doc_id);
        if ($stmt->execute()) {
            $success = "Document type updated.";
        } else {
            $error = "Unable to update document type.";
        }
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_doc_type'])) {
    $doc_id = (int) ($_POST['doc_id'] ?? 0);
    if ($doc_id <= 0) {
        $error = "Document type not found.";
    } else {
        $stmt = $conn->prepare("SELECT code FROM document_types WHERE id = ?");
        $stmt->bind_param("i", $doc_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result && $result->num_rows === 1 ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            $error = "Document type not found.";
        } else {
            $code = $row['code'];
            $in_use = 0;

            $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM project_type_documents WHERE document_type_id = ?");
            $stmt->bind_param("i", $doc_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows === 1) {
                $in_use += (int) $result->fetch_assoc()['cnt'];
            }
            $stmt->close();

            $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM phase_template_documents WHERE document_type_id = ?");
            $stmt->bind_param("i", $doc_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows === 1) {
                $in_use += (int) $result->fetch_assoc()['cnt'];
            }
            $stmt->close();

            $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM attachments WHERE doc_type = ?");
            $stmt->bind_param("s", $code);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows === 1) {
                $in_use += (int) $result->fetch_assoc()['cnt'];
            }
            $stmt->close();

            if ($in_use > 0) {
                $error = "Document type is in use and cannot be deleted.";
            } else {
                $stmt = $conn->prepare("DELETE FROM document_types WHERE id = ?");
                $stmt->bind_param("i", $doc_id);
                if ($stmt->execute()) {
                    $success = "Document type deleted.";
                } else {
                    $error = "Unable to delete document type.";
                }
                $stmt->close();
            }
        }
    }
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
    if ($project_type === '') {
        $error = "Project type is required.";
    } elseif ($document_type_id <= 0) {
        $success = "No project-level document selected.";
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

    if ($name === '' || $owner_role === '') {
        $error = "Phase name and owner role are required.";
    }

    if ($error === '') {
        $stmt = $conn->prepare(
            "INSERT INTO phase_templates (name, owner_role, phase_type)
             VALUES (?, ?, ?)"
        );
        $stmt->bind_param("sss", $name, $owner_role, $phase_type);
        if ($stmt->execute()) {
            $success = "Phase template created.";
        } else {
            $error = "Unable to create phase template.";
        }
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_phase_template'])) {
    $template_id = (int) ($_POST['phase_template_id'] ?? 0);
    $name = trim($_POST['phase_name'] ?? '');
    $owner_role = trim($_POST['phase_owner_role'] ?? '');
    $phase_type = trim($_POST['phase_type'] ?? 'process');

    if ($template_id <= 0 || $name === '' || $owner_role === '') {
        $error = "Phase name and owner role are required.";
    } else {
        $stmt = $conn->prepare("SELECT id FROM phase_templates WHERE name = ? AND id <> ? LIMIT 1");
        $stmt->bind_param("si", $name, $template_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $conflict = $result && $result->num_rows > 0;
        $stmt->close();

        if ($conflict) {
            $error = "Phase template name must be unique.";
        }
    }

    if ($error === '') {
        $stmt = $conn->prepare(
            "UPDATE phase_templates
             SET name = ?, owner_role = ?, phase_type = ?
             WHERE id = ?"
        );
        $stmt->bind_param("sssi", $name, $owner_role, $phase_type, $template_id);
        if ($stmt->execute()) {
            $success = "Phase template updated.";
        } else {
            $error = "Unable to update phase template.";
        }
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_phase_template'])) {
    $template_id = (int) ($_POST['phase_template_id'] ?? 0);
    if ($template_id <= 0) {
        $error = "Phase template not found.";
    } else {
        $in_use = 0;
        $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM project_type_phase_templates WHERE phase_template_id = ?");
        $stmt->bind_param("i", $template_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows === 1) {
            $in_use += (int) $result->fetch_assoc()['cnt'];
        }
        $stmt->close();

        $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM project_type_phase_documents WHERE phase_template_id = ?");
        $stmt->bind_param("i", $template_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows === 1) {
            $in_use += (int) $result->fetch_assoc()['cnt'];
        }
        $stmt->close();

        $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM project_type_phase_dependencies WHERE phase_template_id = ? OR depends_on_template_id = ?");
        $stmt->bind_param("ii", $template_id, $template_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows === 1) {
            $in_use += (int) $result->fetch_assoc()['cnt'];
        }
        $stmt->close();

        $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM project_phases WHERE phase_template_id = ?");
        $stmt->bind_param("i", $template_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows === 1) {
            $in_use += (int) $result->fetch_assoc()['cnt'];
        }
        $stmt->close();

        if ($in_use > 0) {
            $error = "Phase template is in use and cannot be deleted.";
        } else {
            $stmt = $conn->prepare("DELETE FROM phase_templates WHERE id = ?");
            $stmt->bind_param("i", $template_id);
            if ($stmt->execute()) {
                $success = "Phase template deleted.";
            } else {
                $error = "Unable to delete phase template.";
            }
            $stmt->close();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_project_phase'])) {
    $project_type = trim($_POST['setup_project_type_select'] ?? '');
    $project_type = preg_replace('/\s+/', ' ', $project_type);
    $phase_template_id = (int) ($_POST['phase_template_id'] ?? 0);
    $sequence_raw = trim($_POST['sequence_order'] ?? '');
    $sequence_order = $sequence_raw !== '' ? (int) $sequence_raw : 0;
    $is_mandatory = isset($_POST['is_mandatory']) ? 1 : 0;

    if ($project_type === '' || $phase_template_id <= 0) {
        $error = "Project type and phase template are required.";
    } else {
        if ($sequence_order <= 0) {
            $stmt = $conn->prepare(
                "SELECT COALESCE(MAX(sequence_order), 0) AS max_seq
                 FROM project_type_phase_templates
                 WHERE project_type = ?"
            );
            $stmt->bind_param("s", $project_type);
            $stmt->execute();
            $result = $stmt->get_result();
            $max_seq = 0;
            if ($result && $result->num_rows === 1) {
                $row = $result->fetch_assoc();
                $max_seq = (int) $row['max_seq'];
            }
            $stmt->close();
            $sequence_order = $max_seq + 1;
        }

        $stmt = $conn->prepare(
            "SELECT id FROM project_type_phase_templates
             WHERE project_type = ? AND phase_template_id = ?"
        );
        $stmt->bind_param("si", $project_type, $phase_template_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $existing = $result && $result->num_rows === 1 ? (int) ($result->fetch_assoc()['id'] ?? 0) : 0;
        $stmt->close();

        $stmt = $conn->prepare(
            "INSERT INTO project_type_phase_templates (project_type, phase_template_id, sequence_order, is_mandatory)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE sequence_order = VALUES(sequence_order), is_mandatory = VALUES(is_mandatory)"
        );
        $stmt->bind_param("siii", $project_type, $phase_template_id, $sequence_order, $is_mandatory);
        if ($stmt->execute()) {
            $success = $existing > 0 ? "Phase updated in project template." : "Phase added to project template.";
        } else {
            $error = "Unable to add phase to project template.";
        }
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_project_phase'])) {
    $mapping_id = (int) ($_POST['mapping_id'] ?? 0);
    $sequence_order = (int) ($_POST['sequence_order'] ?? 0);
    $is_mandatory = isset($_POST['is_mandatory']) ? 1 : 0;
    if ($mapping_id <= 0 || $sequence_order <= 0) {
        $error = "Sequence is required.";
    } else {
        $stmt = $conn->prepare(
            "UPDATE project_type_phase_templates
             SET sequence_order = ?, is_mandatory = ?
             WHERE id = ?"
        );
        $stmt->bind_param("iii", $sequence_order, $is_mandatory, $mapping_id);
        if ($stmt->execute()) {
            $success = "Project phase updated.";
        } else {
            $error = "Unable to update project phase.";
        }
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_project_phase'])) {
    $mapping_id = (int) ($_POST['mapping_id'] ?? 0);
    $project_type = trim($_POST['mapping_project_type'] ?? '');
    if ($mapping_id <= 0 || $project_type === '') {
        $error = "Phase mapping not found.";
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM projects WHERE project_type = ?");
        $stmt->bind_param("s", $project_type);
        $stmt->execute();
        $result = $stmt->get_result();
        $in_use = 0;
        if ($result && $result->num_rows === 1) {
            $in_use = (int) $result->fetch_assoc()['cnt'];
        }
        $stmt->close();

        if ($in_use > 0) {
            $error = "Cannot remove phases for an active project type.";
        } else {
            $stmt = $conn->prepare("DELETE FROM project_type_phase_templates WHERE id = ?");
            $stmt->bind_param("i", $mapping_id);
            if ($stmt->execute()) {
                $success = "Project phase removed.";
            } else {
                $error = "Unable to remove project phase.";
            }
            $stmt->close();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_project_phase_doc'])) {
    $phase_template_id = (int) ($_POST['req_phase_template_id'] ?? 0);
    $document_type_id = (int) ($_POST['req_document_type_id'] ?? 0);
    $is_required = isset($_POST['req_is_required']) ? 1 : 0;

    if ($phase_template_id <= 0 || $document_type_id <= 0) {
        $error = "Phase template and document type are required.";
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO phase_template_documents (phase_template_id, document_type_id, is_required)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE is_required = VALUES(is_required)"
        );
        $stmt->bind_param("iii", $phase_template_id, $document_type_id, $is_required);
        if ($stmt->execute()) {
            $success = "Phase document requirement saved.";
        } else {
            $error = "Unable to save phase document requirement.";
        }
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_project_phase_doc'])) {
    $mapping_id = (int) ($_POST['mapping_id'] ?? 0);
    $is_required = isset($_POST['is_required']) ? 1 : 0;
    if ($mapping_id <= 0) {
        $error = "Phase document requirement not found.";
    } else {
        $stmt = $conn->prepare(
            "UPDATE phase_template_documents
             SET is_required = ?
             WHERE id = ?"
        );
        $stmt->bind_param("ii", $is_required, $mapping_id);
        if ($stmt->execute()) {
            $success = "Phase document requirement updated.";
        } else {
            $error = "Unable to update phase document requirement.";
        }
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_project_phase_doc'])) {
    $mapping_id = (int) ($_POST['mapping_id'] ?? 0);
    if ($mapping_id <= 0) {
        $error = "Phase document requirement not found.";
    } else {
        $stmt = $conn->prepare("DELETE FROM phase_template_documents WHERE id = ?");
        $stmt->bind_param("i", $mapping_id);
        if ($stmt->execute()) {
            $success = "Phase document requirement removed.";
        } else {
            $error = "Unable to remove phase document requirement.";
        }
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_project_phase_dependency'])) {
    $project_type = trim($_POST['dep_project_type_select'] ?? '');
    $project_type = preg_replace('/\s+/', ' ', $project_type);
    $phase_template_id = (int) ($_POST['dep_phase_template_id'] ?? 0);
    $depends_on_template_id = (int) ($_POST['depends_on_template_id'] ?? 0);

    if ($project_type === '' || $phase_template_id <= 0 || $depends_on_template_id <= 0) {
        $error = "Project type and two phases are required.";
    } elseif ($phase_template_id === $depends_on_template_id) {
        $error = "Select two different phases.";
    } else {
        $stmt = $conn->prepare(
            "SELECT phase_template_id
             FROM project_type_phase_templates
             WHERE project_type = ? AND phase_template_id IN (?, ?)"
        );
        $stmt->bind_param("sii", $project_type, $phase_template_id, $depends_on_template_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $found = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $found[] = (int) $row['phase_template_id'];
            }
        }
        $stmt->close();

        if (!in_array($phase_template_id, $found, true) || !in_array($depends_on_template_id, $found, true)) {
            $error = "Select phases already assigned to this project type.";
        } else {
            $stmt = $conn->prepare(
                "SELECT id FROM project_type_phase_dependencies
                 WHERE project_type = ? AND phase_template_id = ? AND depends_on_template_id = ?"
            );
            $stmt->bind_param("sii", $project_type, $phase_template_id, $depends_on_template_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $exists = $result && $result->num_rows > 0;
            $stmt->close();

            if ($exists) {
                $error = "Dependency already exists.";
            } else {
                $graph = [];
                $stmt = $conn->prepare(
                    "SELECT phase_template_id, depends_on_template_id
                     FROM project_type_phase_dependencies
                     WHERE project_type = ?"
                );
                $stmt->bind_param("s", $project_type);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $from = (int) $row['phase_template_id'];
                        $to = (int) $row['depends_on_template_id'];
                        $graph[$from][] = $to;
                    }
                }
                $stmt->close();

                $stack = [$depends_on_template_id];
                $visited = [];
                $creates_cycle = false;
                while (!empty($stack)) {
                    $current = array_pop($stack);
                    if (isset($visited[$current])) {
                        continue;
                    }
                    $visited[$current] = true;
                    if ($current === $phase_template_id) {
                        $creates_cycle = true;
                        break;
                    }
                    if (!empty($graph[$current])) {
                        foreach ($graph[$current] as $next) {
                            $stack[] = $next;
                        }
                    }
                }

                if ($creates_cycle) {
                    $error = "Dependency would create a circular loop.";
                } else {
                    $stmt = $conn->prepare(
                        "INSERT INTO project_type_phase_dependencies (project_type, phase_template_id, depends_on_template_id)
                         VALUES (?, ?, ?)"
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
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_project_phase_dependency'])) {
    $dependency_id = (int) ($_POST['dependency_id'] ?? 0);
    $project_type = trim($_POST['dependency_project_type'] ?? '');
    if ($dependency_id <= 0 || $project_type === '') {
        $error = "Phase dependency not found.";
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM projects WHERE project_type = ?");
        $stmt->bind_param("s", $project_type);
        $stmt->execute();
        $result = $stmt->get_result();
        $in_use = 0;
        if ($result && $result->num_rows === 1) {
            $in_use = (int) $result->fetch_assoc()['cnt'];
        }
        $stmt->close();

        if ($in_use > 0) {
            $error = "Cannot remove dependencies for an active project type.";
        } else {
            $stmt = $conn->prepare("DELETE FROM project_type_phase_dependencies WHERE id = ?");
            $stmt->bind_param("i", $dependency_id);
            if ($stmt->execute()) {
                $success = "Phase dependency removed.";
            } else {
                $error = "Unable to remove phase dependency.";
            }
            $stmt->close();
        }
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

$doc_usage_by_id = [];
$doc_usage_by_code = [];
$result = $conn->query(
    "SELECT document_type_id, COUNT(*) AS cnt
     FROM project_type_documents
     GROUP BY document_type_id"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $doc_usage_by_id[(int) $row['document_type_id']] = (int) $row['cnt'];
    }
}
$result = $conn->query(
    "SELECT document_type_id, COUNT(*) AS cnt
     FROM phase_template_documents
     GROUP BY document_type_id"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $doc_usage_by_id[(int) $row['document_type_id']] =
            ($doc_usage_by_id[(int) $row['document_type_id']] ?? 0) + (int) $row['cnt'];
    }
}
$result = $conn->query(
    "SELECT doc_type, COUNT(*) AS cnt
     FROM attachments
     WHERE doc_type IS NOT NULL AND doc_type <> ''
     GROUP BY doc_type"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $doc_usage_by_code[$row['doc_type']] =
            ($doc_usage_by_code[$row['doc_type']] ?? 0) + (int) $row['cnt'];
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
     SELECT DISTINCT project_type FROM project_type_phase_dependencies
     ORDER BY project_type ASC"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $project_types[] = $row['project_type'];
    }
}

$project_type_context = trim($_GET['project_type'] ?? '');
if ($project_type_context !== '' && !in_array($project_type_context, $project_types, true)) {
    $project_type_context = '';
}

$phase_templates_by_project_type = [];
$result = $conn->query(
    "SELECT ppt.project_type, pt.id, pt.name
     FROM project_type_phase_templates ppt
     JOIN phase_templates pt ON pt.id = ppt.phase_template_id
     ORDER BY ppt.project_type ASC, ppt.sequence_order ASC, pt.name ASC"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $phase_templates_by_project_type[$row['project_type']][] = $row;
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_mapping'])) {
    $mapping_id = (int) ($_POST['mapping_id'] ?? 0);
    $is_required = isset($_POST['is_required']) ? 1 : 0;
    if ($mapping_id <= 0) {
        $error = "Mapping not found.";
    } else {
        $stmt = $conn->prepare("UPDATE project_type_documents SET is_required = ? WHERE id = ?");
        $stmt->bind_param("ii", $is_required, $mapping_id);
        if ($stmt->execute()) {
            $success = "Requirement updated.";
        } else {
            $error = "Unable to update requirement.";
        }
        $stmt->close();
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

$phase_template_usage = [];
$result = $conn->query(
    "SELECT phase_template_id, COUNT(*) AS cnt
     FROM project_type_phase_templates
     GROUP BY phase_template_id"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $phase_template_usage[(int) $row['phase_template_id']] = (int) $row['cnt'];
    }
}
$result = $conn->query(
    "SELECT phase_template_id, COUNT(*) AS cnt
     FROM phase_template_documents
     GROUP BY phase_template_id"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $phase_template_usage[(int) $row['phase_template_id']] =
            ($phase_template_usage[(int) $row['phase_template_id']] ?? 0) + (int) $row['cnt'];
    }
}
$result = $conn->query(
    "SELECT phase_template_id, COUNT(*) AS cnt
     FROM project_type_phase_dependencies
     GROUP BY phase_template_id"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $phase_template_usage[(int) $row['phase_template_id']] =
            ($phase_template_usage[(int) $row['phase_template_id']] ?? 0) + (int) $row['cnt'];
    }
}
$result = $conn->query(
    "SELECT depends_on_template_id, COUNT(*) AS cnt
     FROM project_type_phase_dependencies
     GROUP BY depends_on_template_id"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $phase_template_usage[(int) $row['depends_on_template_id']] =
            ($phase_template_usage[(int) $row['depends_on_template_id']] ?? 0) + (int) $row['cnt'];
    }
}
$result = $conn->query(
    "SELECT phase_template_id, COUNT(*) AS cnt
     FROM project_phases
     WHERE phase_template_id IS NOT NULL
     GROUP BY phase_template_id"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $phase_template_usage[(int) $row['phase_template_id']] =
            ($phase_template_usage[(int) $row['phase_template_id']] ?? 0) + (int) $row['cnt'];
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
     FROM phase_template_documents ptd
     JOIN phase_templates pt ON pt.id = ptd.phase_template_id
     JOIN document_types dt ON dt.id = ptd.document_type_id
     ORDER BY pt.name ASC, dt.label ASC"
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
                <form class="form-grid" method="POST" action="project_config.php?view=documents">
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
                                <th>Usage</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($document_types as $doc): ?>
                                <?php
                                $usage = ($doc_usage_by_id[(int) $doc['id']] ?? 0)
                                    + ($doc_usage_by_code[$doc['code']] ?? 0);
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($doc['code']); ?></td>
                                    <td>
                                        <input type="text" name="label" form="doc-form-<?php echo (int) $doc['id']; ?>" value="<?php echo htmlspecialchars($doc['label']); ?>">
                                    </td>
                                    <td><?php echo $doc['is_active'] ? 'Yes' : 'No'; ?></td>
                                    <td>
                                        <?php echo $usage > 0 ? 'In use' : 'Free'; ?>
                                    </td>
                                    <td>
                                        <form class="inline-form" method="POST" action="project_config.php?view=documents" id="doc-form-<?php echo (int) $doc['id']; ?>">
                                            <input type="hidden" name="doc_id" value="<?php echo (int) $doc['id']; ?>">
                                            <label class="delete-option">
                                                <input type="checkbox" name="is_active" form="doc-form-<?php echo (int) $doc['id']; ?>" <?php echo $doc['is_active'] ? 'checked' : ''; ?>>
                                                <span>Active</span>
                                            </label>
                                            <button class="btn btn-secondary" type="submit" name="update_doc_type">Save</button>
                                        </form>
                                        <?php if ($usage > 0): ?>
                                            <span class="muted">Locked</span>
                                        <?php else: ?>
                                            <form class="inline-form" method="POST" action="project_config.php?view=documents">
                                                <input type="hidden" name="doc_id" value="<?php echo (int) $doc['id']; ?>">
                                                <button class="btn btn-danger" type="submit" name="delete_doc_type" onclick="return confirm('Delete this document type?');">Delete</button>
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

        <?php if ($view === 'projects'): ?>
            <section class="card card-compact">
                <form class="actions" method="GET" action="project_config.php">
                    <input type="hidden" name="view" value="projects">
                    <label class="delete-option">
                        <span>Project type filter</span>
                    </label>
                    <select name="project_type" onchange="this.form.submit()">
                        <option value="">-- All project types --</option>
                        <?php foreach ($project_types as $type): ?>
                            <option value="<?php echo htmlspecialchars($type); ?>" <?php echo $project_type_context === $type ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($type); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </section>

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
                    <select id="document_type_id" name="document_type_id">
                        <option value="">-- Select type --</option>
                        <?php foreach ($document_types as $doc): ?>
                            <option value="<?php echo (int) $doc['id']; ?>">
                                <?php echo htmlspecialchars($doc['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="muted">Optional: leave empty if all required documents are attached to phases.</p>

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
                                        <form class="inline-form" method="POST" action="project_config.php?view=projects">
                                            <input type="hidden" name="mapping_id" value="<?php echo (int) $map['id']; ?>">
                                            <label class="delete-option">
                                                <input type="checkbox" name="is_required" <?php echo $map['is_required'] ? 'checked' : ''; ?>>
                                                <span>Required</span>
                                            </label>
                                            <button class="btn btn-secondary" type="submit" name="update_mapping">Save</button>
                                        </form>
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
                            <option value="<?php echo htmlspecialchars($type); ?>" <?php echo $project_type_context === $type ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($type); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

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
                    <input id="sequence_order" type="number" name="sequence_order" min="1" step="1" placeholder="Leave blank for next">

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
                                <th>Actions</th>
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
                                    <td>
                                        <form class="inline-form" method="POST" action="project_config.php?view=projects">
                                            <input type="hidden" name="mapping_id" value="<?php echo (int) $row['id']; ?>">
                                            <input type="number" name="sequence_order" min="1" step="1" value="<?php echo (int) $row['sequence_order']; ?>">
                                            <label class="delete-option">
                                                <input type="checkbox" name="is_mandatory" <?php echo $row['is_mandatory'] ? 'checked' : ''; ?>>
                                                <span>Mandatory</span>
                                            </label>
                                            <button class="btn btn-secondary" type="submit" name="update_project_phase">Save</button>
                                        </form>
                                        <?php if (($project_type_stats[$row['project_type']]['projects'] ?? 0) > 0): ?>
                                            <span class="muted">Locked</span>
                                        <?php else: ?>
                                            <form class="inline-form" method="POST" action="project_config.php?view=projects">
                                                <input type="hidden" name="mapping_id" value="<?php echo (int) $row['id']; ?>">
                                                <input type="hidden" name="mapping_project_type" value="<?php echo htmlspecialchars($row['project_type']); ?>">
                                                <button class="btn btn-danger" type="submit" name="delete_project_phase">Remove</button>
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
                    <h2>Phase dependencies</h2>
                    <p class="muted">Define which phases must be completed first.</p>
                </div>
                <form class="form-grid" method="POST" action="project_config.php?view=projects">
                    <label for="dep_project_type_select">Project type</label>
                    <select id="dep_project_type_select" name="dep_project_type_select">
                        <option value="">-- Select project type --</option>
                        <?php foreach ($project_types as $type): ?>
                            <option value="<?php echo htmlspecialchars($type); ?>" <?php echo $project_type_context === $type ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($type); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="dep_phase_template_id">Phase template</label>
                    <select id="dep_phase_template_id" name="dep_phase_template_id" required>
                        <option value="">-- Select phase template --</option>
                        <?php if ($project_type_context !== '' && !empty($phase_templates_by_project_type[$project_type_context])): ?>
                            <?php foreach ($phase_templates_by_project_type[$project_type_context] as $tpl): ?>
                                <option value="<?php echo (int) $tpl['id']; ?>">
                                    <?php echo htmlspecialchars($tpl['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>

                    <label for="depends_on_template_id">Depends on</label>
                    <select id="depends_on_template_id" name="depends_on_template_id" required>
                        <option value="">-- Select dependency --</option>
                        <?php if ($project_type_context !== '' && !empty($phase_templates_by_project_type[$project_type_context])): ?>
                            <?php foreach ($phase_templates_by_project_type[$project_type_context] as $tpl): ?>
                                <option value="<?php echo (int) $tpl['id']; ?>">
                                    <?php echo htmlspecialchars($tpl['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <?php if ($project_type_context === ''): ?>
                        <p class="muted">Select a project type to load available phases.</p>
                    <?php endif; ?>

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
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($project_type_phase_dependencies as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['project_type']); ?></td>
                                    <td><?php echo htmlspecialchars($row['phase_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['depends_on_name']); ?></td>
                                    <td>
                                        <?php if (($project_type_stats[$row['project_type']]['projects'] ?? 0) > 0): ?>
                                            <span class="muted">Locked</span>
                                        <?php else: ?>
                                            <form class="inline-form" method="POST" action="project_config.php?view=projects">
                                                <input type="hidden" name="dependency_id" value="<?php echo (int) $row['id']; ?>">
                                                <input type="hidden" name="dependency_project_type" value="<?php echo htmlspecialchars($row['project_type']); ?>">
                                                <button class="btn btn-danger" type="submit" name="delete_project_phase_dependency">Remove</button>
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
                                <th>Usage</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($phase_templates as $tpl): ?>
                                <?php $usage = $phase_template_usage[(int) $tpl['id']] ?? 0; ?>
                                <tr>
                                    <td>
                                        <input type="text" name="phase_name" form="phase-form-<?php echo (int) $tpl['id']; ?>" value="<?php echo htmlspecialchars($tpl['name']); ?>">
                                    </td>
                                    <td>
                                        <select name="phase_owner_role" form="phase-form-<?php echo (int) $tpl['id']; ?>">
                                            <option value="designer" <?php echo $tpl['owner_role'] === 'designer' ? 'selected' : ''; ?>>designer</option>
                                            <option value="firmware" <?php echo $tpl['owner_role'] === 'firmware' ? 'selected' : ''; ?>>firmware</option>
                                            <option value="tester" <?php echo $tpl['owner_role'] === 'tester' ? 'selected' : ''; ?>>tester</option>
                                            <option value="supplier" <?php echo $tpl['owner_role'] === 'supplier' ? 'selected' : ''; ?>>supplier</option>
                                            <option value="coordinator" <?php echo $tpl['owner_role'] === 'coordinator' ? 'selected' : ''; ?>>coordinator</option>
                                        </select>
                                    </td>
                                    <td>
                                        <select name="phase_type" form="phase-form-<?php echo (int) $tpl['id']; ?>">
                                            <option value="process" <?php echo $tpl['phase_type'] === 'process' ? 'selected' : ''; ?>>process</option>
                                            <option value="document" <?php echo $tpl['phase_type'] === 'document' ? 'selected' : ''; ?>>document</option>
                                            <option value="approval" <?php echo $tpl['phase_type'] === 'approval' ? 'selected' : ''; ?>>approval</option>
                                            <option value="test" <?php echo $tpl['phase_type'] === 'test' ? 'selected' : ''; ?>>test</option>
                                        </select>
                                    </td>
                                    <td><?php echo $usage > 0 ? 'In use' : 'Free'; ?></td>
                                    <td>
                                        <form class="inline-form" method="POST" action="project_config.php?view=phases" id="phase-form-<?php echo (int) $tpl['id']; ?>">
                                            <input type="hidden" name="phase_template_id" value="<?php echo (int) $tpl['id']; ?>">
                                            <button class="btn btn-secondary" type="submit" name="update_phase_template">Save</button>
                                        </form>
                                        <?php if ($usage > 0): ?>
                                            <span class="muted">Locked</span>
                                        <?php else: ?>
                                            <form class="inline-form" method="POST" action="project_config.php?view=phases">
                                                <input type="hidden" name="phase_template_id" value="<?php echo (int) $tpl['id']; ?>">
                                                <button class="btn btn-danger" type="submit" name="delete_phase_template" onclick="return confirm('Delete this phase template?');">Delete</button>
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
                    <h2>Phase document requirements</h2>
                    <p class="muted">Associate documents required for a phase template.</p>
                </div>
                <form class="form-grid" method="POST" action="project_config.php?view=phases">
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
                                <th>Phase</th>
                                <th>Document type</th>
                                <th>Required</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($project_type_phase_documents as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['phase_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['doc_label']); ?></td>
                                    <td><?php echo $row['is_required'] ? 'Yes' : 'No'; ?></td>
                                    <td>
                                        <form class="inline-form" method="POST" action="project_config.php?view=phases">
                                            <input type="hidden" name="mapping_id" value="<?php echo (int) $row['id']; ?>">
                                            <label class="delete-option">
                                                <input type="checkbox" name="is_required" <?php echo $row['is_required'] ? 'checked' : ''; ?>>
                                                <span>Required</span>
                                            </label>
                                            <button class="btn btn-secondary" type="submit" name="update_project_phase_doc">Save</button>
                                        </form>
                                        <form class="inline-form" method="POST" action="project_config.php?view=phases">
                                            <input type="hidden" name="mapping_id" value="<?php echo (int) $row['id']; ?>">
                                            <button class="btn btn-danger" type="submit" name="delete_project_phase_doc">Remove</button>
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
                    <h2>Phase requirement overview</h2>
                    <p class="muted">Quick view of documents required per phase template.</p>
                </div>
                <?php
                $phase_doc_overview = [];
                foreach ($project_type_phase_documents as $row) {
                    $phase = $row['phase_name'];
                    if (!isset($phase_doc_overview[$phase])) {
                        $phase_doc_overview[$phase] = [];
                    }
                    $label = $row['doc_label'];
                    if ((int) $row['is_required'] === 1) {
                        $label .= ' (required)';
                    } else {
                        $label .= ' (optional)';
                    }
                    $phase_doc_overview[$phase][] = $label;
                }
                ?>
                <?php if (empty($phase_doc_overview)): ?>
                    <p class="muted">No phase document requirements yet.</p>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Phase</th>
                                <th>Documents</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($phase_doc_overview as $phase => $docs): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($phase); ?></td>
                                    <td><?php echo htmlspecialchars(implode(', ', $docs)); ?></td>
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
