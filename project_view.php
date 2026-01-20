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
require_once(__DIR__ . '/config/settings.php');
require_once(__DIR__ . '/config/uploads.php');

$project_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($project_id <= 0) {
    header('Location: projects.php');
    exit;
}

$success = '';
$error = '';
$user_id = (int) ($_SESSION['user_id'] ?? 0);
$is_admin = (($_SESSION['role'] ?? '') === 'admin');
$project_type_for_requirements = '';
$project_code = '';
$project_version = '';

ensure_default_project_workflow($conn);

$stmt = $conn->prepare("SELECT project_type, code, version FROM projects WHERE id = ?");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $result->num_rows === 1) {
    $row = $result->fetch_assoc();
    $project_type_for_requirements = $row['project_type'] ?? '';
    $project_code = $row['code'] ?? '';
    $project_version = $row['version'] ?? '';
}
$stmt->close();

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_project'])) {
    if (!$is_admin) {
        $error = "You are not allowed to update this project.";
    } else {
        $name = trim($_POST['project_name'] ?? '');
        $code = strtoupper(trim($_POST['project_code'] ?? ''));
        $code = preg_replace('/\s+/', '-', $code);
        $version = trim($_POST['project_version'] ?? '');
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
            $error = "Project name, code, and version are required.";
        } elseif (!ctype_digit($version)) {
            $error = "Project version must be an integer.";
        } else {
            $version = (int) $version;
            $stmt = $conn->prepare(
                "SELECT id FROM projects WHERE code = ? AND version = ? AND id <> ? LIMIT 1"
            );
            $stmt->bind_param("sii", $code, $version, $project_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $conflict = ($result && $result->num_rows > 0);
            $stmt->close();

            if ($conflict) {
                $error = "Project code and version must be unique.";
            } else {
                $owner_id = $owner_id !== '' ? (int) $owner_id : null;
                $start_date = $start_date !== '' ? $start_date : null;
                $stmt = $conn->prepare(
                    "UPDATE projects
                 SET code = ?, version = ?, name = ?, project_type = ?, owner_id = ?, start_date = ?, description = ?
                 WHERE id = ?"
                );
                $stmt->bind_param("sississi", $code, $version, $name, $project_type, $owner_id, $start_date, $description, $project_id);
                if ($stmt->execute()) {
                    $success = "Project updated.";
                    $project_code = $code;
                    $project_version = (string) $version;
                    $project_type_for_requirements = $project_type;
                } else {
                    $error = "Unable to update project. " . $stmt->error;
                }
                $stmt->close();
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_project'])) {
    if (!$is_admin) {
        $error = "You are not allowed to delete this project.";
    } else {
        $delete_files = isset($_POST['delete_files']) && $_POST['delete_files'] === '1';
        if ($delete_files) {
            $upload_base_path = get_setting($conn, 'upload_base_path', 'uploads');
            $base_fs_path = resolve_upload_base_path($upload_base_path);
            $stmt = $conn->prepare(
                "SELECT storage_path, trash_path
                 FROM attachments
                 WHERE project_id = ? AND storage_type = 'local'"
            );
            if ($stmt) {
                $stmt->bind_param("i", $project_id);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        if (!empty($row['storage_path'])) {
                            $relative = str_replace('/', DIRECTORY_SEPARATOR, $row['storage_path']);
                            $file_path = $base_fs_path . DIRECTORY_SEPARATOR . $relative;
                            if (is_file($file_path)) {
                                @unlink($file_path);
                            }
                        }
                        if (!empty($row['trash_path'])) {
                            $relative = str_replace('/', DIRECTORY_SEPARATOR, $row['trash_path']);
                            $file_path = $base_fs_path . DIRECTORY_SEPARATOR . $relative;
                            if (is_file($file_path)) {
                                @unlink($file_path);
                            }
                        }
                    }
                }
                $stmt->close();
            }
        }

        $stmt = $conn->prepare("DELETE FROM projects WHERE id = ?");
        $stmt->bind_param("i", $project_id);
        if ($stmt->execute()) {
            $stmt->close();
            header('Location: projects.php');
            exit;
        }
        $stmt->close();
        $error = "Unable to delete project.";
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
    $required_doc_type = '';

    $stmt = $conn->prepare(
        "SELECT owner_role, required_doc_type FROM project_phases WHERE id = ? AND project_id = ?"
    );
    $stmt->bind_param("ii", $phase_id, $project_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows === 1) {
        $row = $result->fetch_assoc();
        $phase_owner_role = $row['owner_role'];
        $required_doc_type = $row['required_doc_type'] ?? '';
    }
    $stmt->close();

    $role = $_SESSION['role'] ?? '';
    if ($phase_owner_role !== '' && ($role === $phase_owner_role || $role === 'admin' || $role === 'coordinator')) {
        $allowed = true;
    }

    if (!$allowed) {
        $error = "You are not allowed to submit this phase.";
    } else {
        $dependencies_ok = true;
        $stmt = $conn->prepare(
            "SELECT d.depends_on_phase_id, p.completed_at
             FROM phase_dependencies d
             JOIN project_phases p ON p.id = d.depends_on_phase_id
             WHERE d.phase_id = ?"
        );
        $stmt->bind_param("i", $phase_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                if (empty($row['completed_at'])) {
                    $dependencies_ok = false;
                    break;
                }
            }
        }
        $stmt->close();

        if (!$dependencies_ok) {
            $error = "Complete required phases before submitting this phase.";
        } else {
            $required_docs = [];
            $stmt = $conn->prepare(
                "SELECT dt.code
                 FROM phase_required_documents prd
                 JOIN document_types dt ON dt.id = prd.document_type_id
                 WHERE prd.phase_id = ? AND prd.is_required = 1 AND dt.is_active = 1"
            );
            $stmt->bind_param("i", $phase_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $required_docs[] = $row['code'];
                }
            }
            $stmt->close();

            if (empty($required_docs) && $required_doc_type !== '') {
                $required_docs = [$required_doc_type];
            }

            $missing_docs = [];
            foreach ($required_docs as $doc_code) {
                $stmt = $conn->prepare(
                    "SELECT COUNT(*) AS cnt
                     FROM attachments
                     WHERE phase_id = ? AND doc_type = ? AND is_deleted = 0"
                );
                $stmt->bind_param("is", $phase_id, $doc_code);
                $stmt->execute();
                $result = $stmt->get_result();
                $count = 0;
                if ($result && $result->num_rows === 1) {
                    $row = $result->fetch_assoc();
                    $count = (int) $row['cnt'];
                }
                $stmt->close();
                if ($count === 0) {
                    $missing_docs[] = $doc_code;
                }
            }

            if (!empty($required_docs) && !empty($missing_docs)) {
                $error = "Upload required documents before submitting this phase.";
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
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_document'])) {
    $doc_type = trim($_POST['doc_type'] ?? '');
    $phase_id = trim($_POST['document_phase_id'] ?? '');
    $phase_id = $phase_id !== '' ? (int) $phase_id : null;

    $upload_base_path = get_setting($conn, 'upload_base_path', 'uploads');
    $upload_base_url = get_setting($conn, 'upload_base_url', '');

    $project_upload_subdir = build_project_subdir($project_code, $project_version);
    list($ok, $file_data, $upload_error) = store_uploaded_file(
        $_FILES['document_file'] ?? null,
        $upload_base_path,
        $upload_base_url,
        $project_upload_subdir
    );
    if (!$ok) {
        $error = $upload_error;
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO attachments
             (project_id, phase_id, uploaded_by, storage_type, storage_path, storage_url, original_name, doc_type, mime_type, size_bytes)
             VALUES (?, ?, ?, 'local', ?, ?, ?, ?, ?, ?)"
        );
        if ($stmt) {
            $stmt->bind_param(
                "iiisssssi",
                $project_id,
                $phase_id,
                $user_id,
                $file_data['storage_path'],
                $file_data['storage_url'],
                $file_data['original_name'],
                $doc_type,
                $file_data['mime_type'],
                $file_data['size_bytes']
            );
            if ($stmt->execute()) {
                $success = "Document uploaded.";
            } else {
                $error = "Unable to save document.";
            }
            $stmt->close();
        } else {
            $error = "Unable to save document.";
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_phase_document'])) {
    $doc_type = trim($_POST['phase_doc_type'] ?? '');
    $phase_id = (int) ($_POST['phase_doc_phase_id'] ?? 0);

    if ($phase_id <= 0) {
        $error = "Select a phase to upload documents.";
    } else {
        $upload_base_path = get_setting($conn, 'upload_base_path', 'uploads');
        $upload_base_url = get_setting($conn, 'upload_base_url', '');

        $project_upload_subdir = build_project_subdir($project_code, $project_version);
        list($ok, $file_data, $upload_error) = store_uploaded_file(
            $_FILES['phase_document_file'] ?? null,
            $upload_base_path,
            $upload_base_url,
            $project_upload_subdir
        );
        if (!$ok) {
            $error = $upload_error;
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO attachments
                 (project_id, phase_id, uploaded_by, storage_type, storage_path, storage_url, original_name, doc_type, mime_type, size_bytes)
                 VALUES (?, ?, ?, 'local', ?, ?, ?, ?, ?, ?)"
            );
            if ($stmt) {
                $stmt->bind_param(
                    "iiisssssi",
                    $project_id,
                    $phase_id,
                    $user_id,
                    $file_data['storage_path'],
                    $file_data['storage_url'],
                    $file_data['original_name'],
                    $doc_type,
                    $file_data['mime_type'],
                    $file_data['size_bytes']
                );
                if ($stmt->execute()) {
                    $success = "Phase document uploaded.";
                    notify_roles(
                        $conn,
                        ['coordinator', 'admin'],
                        $project_id,
                        $phase_id,
                        'Phase document uploaded',
                        'A document has been uploaded for a project phase.'
                    );
                } else {
                    $error = "Unable to save phase document.";
                }
                $stmt->close();
            } else {
                $error = "Unable to save phase document.";
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_phase_due'])) {
    $phase_id = (int) ($_POST['phase_id'] ?? 0);
    $due_date = trim($_POST['due_date'] ?? '');
    $role = $_SESSION['role'] ?? '';
    if ($role === 'admin' || $role === 'coordinator') {
        $due_date = $due_date !== '' ? $due_date : null;
        $assignee_id = trim($_POST['assignee_id'] ?? '');
        $assignee_id = $assignee_id !== '' ? (int) $assignee_id : null;
        $owner_role = trim($_POST['owner_role'] ?? '');

        $prev_assignee_id = null;
        $phase_name = '';
        $stmt = $conn->prepare(
            "SELECT assignee_id, name FROM project_phases WHERE id = ? AND project_id = ?"
        );
        $stmt->bind_param("ii", $phase_id, $project_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows === 1) {
            $row = $result->fetch_assoc();
            $prev_assignee_id = $row['assignee_id'] !== null ? (int) $row['assignee_id'] : null;
            $phase_name = $row['name'];
        }
        $stmt->close();

        $stmt = $conn->prepare(
            "UPDATE project_phases
             SET due_date = ?, assignee_id = ?, owner_role = ?
             WHERE id = ? AND project_id = ?"
        );
        $stmt->bind_param("sisii", $due_date, $assignee_id, $owner_role, $phase_id, $project_id);
        if ($stmt->execute()) {
            $success = "Phase updated.";
            if ($assignee_id !== null && $assignee_id !== $prev_assignee_id) {
                notify_user(
                    $conn,
                    $assignee_id,
                    $project_id,
                    $phase_id,
                    'Phase assignment',
                    'You have been assigned to phase: ' . $phase_name,
                    'assignment'
                );
            }
        } else {
            $error = "Unable to update phase. " . $stmt->error;
        }
        $stmt->close();
    } else {
        $error = "You are not allowed to edit phases.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_phase_complete'])) {
    $phase_id = (int) ($_POST['phase_id'] ?? 0);
    $role = $_SESSION['role'] ?? '';
    $allowed = false;
    $required_doc_type = '';

    $stmt = $conn->prepare(
        "SELECT owner_role, assignee_id, required_doc_type
         FROM project_phases
         WHERE id = ? AND project_id = ?"
    );
    $stmt->bind_param("ii", $phase_id, $project_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows === 1) {
        $row = $result->fetch_assoc();
        $required_doc_type = $row['required_doc_type'] ?? '';
        if ($role === 'admin' || $role === 'coordinator') {
            $allowed = true;
        } elseif (!empty($row['assignee_id']) && (int) $row['assignee_id'] === $user_id) {
            $allowed = true;
        } elseif ($row['owner_role'] === $role) {
            $allowed = true;
        }
    }
    $stmt->close();

    if (!$allowed) {
        $error = "You are not allowed to complete this phase.";
    } else {
        $dependencies_ok = true;
        $stmt = $conn->prepare(
            "SELECT d.depends_on_phase_id, p.completed_at
             FROM phase_dependencies d
             JOIN project_phases p ON p.id = d.depends_on_phase_id
             WHERE d.phase_id = ?"
        );
        $stmt->bind_param("i", $phase_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                if (empty($row['completed_at'])) {
                    $dependencies_ok = false;
                    break;
                }
            }
        }
        $stmt->close();

        if (!$dependencies_ok) {
            $error = "Complete required phases before completing this phase.";
        } else {
            $required_docs = [];
            $stmt = $conn->prepare(
                "SELECT dt.code
                 FROM phase_required_documents prd
                 JOIN document_types dt ON dt.id = prd.document_type_id
                 WHERE prd.phase_id = ? AND prd.is_required = 1 AND dt.is_active = 1"
            );
            $stmt->bind_param("i", $phase_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $required_docs[] = $row['code'];
                }
            }
            $stmt->close();

            if (empty($required_docs) && $required_doc_type !== '') {
                $required_docs = [$required_doc_type];
            }

            $missing_docs = [];
            foreach ($required_docs as $doc_code) {
                $stmt = $conn->prepare(
                    "SELECT COUNT(*) AS cnt
                     FROM attachments
                     WHERE phase_id = ? AND doc_type = ? AND is_deleted = 0"
                );
                $stmt->bind_param("is", $phase_id, $doc_code);
                $stmt->execute();
                $result = $stmt->get_result();
                $count = 0;
                if ($result && $result->num_rows === 1) {
                    $row = $result->fetch_assoc();
                    $count = (int) $row['cnt'];
                }
                $stmt->close();
                if ($count === 0) {
                    $missing_docs[] = $doc_code;
                }
            }

            if (!empty($required_docs) && !empty($missing_docs)) {
                $error = "Upload required documents before completing this phase.";
            } else {
        $stmt = $conn->prepare(
            "UPDATE project_phases
             SET completed_at = NOW()
             WHERE id = ? AND project_id = ?"
        );
            $stmt->bind_param("ii", $phase_id, $project_id);
            $stmt->execute();
            $stmt->close();
            $success = "Phase marked as complete.";
        }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_phase_requirement'])) {
    if (!$is_admin) {
        $error = "You are not allowed to edit requirements.";
    } else {
        $phase_id = (int) ($_POST['req_phase_id'] ?? 0);
        $document_type_id = (int) ($_POST['req_document_type_id'] ?? 0);
        if ($phase_id <= 0 || $document_type_id <= 0) {
            $error = "Phase and document type are required.";
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO phase_required_documents (phase_id, document_type_id, is_required)
                 VALUES (?, ?, 1)
                 ON DUPLICATE KEY UPDATE is_required = 1"
            );
            $stmt->bind_param("ii", $phase_id, $document_type_id);
            if ($stmt->execute()) {
                $success = "Phase requirement added.";
            } else {
                $error = "Unable to add requirement.";
            }
            $stmt->close();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_phase_dependency'])) {
    if (!$is_admin) {
        $error = "You are not allowed to edit dependencies.";
    } else {
        $phase_id = (int) ($_POST['dep_phase_id'] ?? 0);
        $depends_on = (int) ($_POST['depends_on_phase_id'] ?? 0);
        if ($phase_id <= 0 || $depends_on <= 0 || $phase_id === $depends_on) {
            $error = "Select two different phases.";
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO phase_dependencies (phase_id, depends_on_phase_id)
                 VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE phase_id = phase_id"
            );
            $stmt->bind_param("ii", $phase_id, $depends_on);
            if ($stmt->execute()) {
                $success = "Phase dependency added.";
            } else {
                $error = "Unable to add dependency.";
            }
            $stmt->close();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_phases'])) {
    if (!$is_admin) {
        $error = "You are not allowed to generate phases.";
    } else {
        $project_type = trim($project_type_for_requirements ?? '');
        if ($project_type === '') {
            $error = "Project type is required to generate phases.";
        } else {
            $stmt = $conn->prepare(
                "SELECT ppt.phase_template_id, ppt.sequence_order, ppt.is_mandatory,
                        pt.name, pt.owner_role, pt.phase_type, pt.required_doc_type
                 FROM project_type_phase_templates ppt
                 JOIN phase_templates pt ON pt.id = ppt.phase_template_id
                 WHERE ppt.project_type = ?
                 ORDER BY ppt.sequence_order ASC, pt.name ASC"
            );
            $stmt->bind_param("s", $project_type);
            $stmt->execute();
            $result = $stmt->get_result();
            $templates = [];
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $templates[] = $row;
                }
            }
            $stmt->close();

            if (empty($templates)) {
                $error = "No phase templates for this project type.";
            } else {
                $phase_map = [];
                $existing_stmt = $conn->prepare(
                    "SELECT phase_template_id, id
                     FROM project_phases
                     WHERE project_id = ? AND phase_template_id IS NOT NULL"
                );
                $existing_stmt->bind_param("i", $project_id);
                $existing_stmt->execute();
                $existing_result = $existing_stmt->get_result();
                if ($existing_result) {
                    while ($row = $existing_result->fetch_assoc()) {
                        $phase_map[(int) $row['phase_template_id']] = (int) $row['id'];
                    }
                }
                $existing_stmt->close();
                foreach ($templates as $tpl) {
                    $stmt = $conn->prepare(
                        "SELECT COUNT(*) AS cnt
                         FROM project_phases
                         WHERE project_id = ? AND phase_template_id = ?"
                    );
                    $stmt->bind_param("ii", $project_id, $tpl['phase_template_id']);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $exists = 0;
                    if ($result && $result->num_rows === 1) {
                        $row = $result->fetch_assoc();
                        $exists = (int) $row['cnt'];
                    }
                    $stmt->close();
                    if ($exists > 0) {
                        continue;
                    }
                    $sequence_order = (int) $tpl['sequence_order'];
                    $stmt = $conn->prepare(
                        "SELECT COUNT(*) AS cnt FROM project_phases WHERE project_id = ? AND sequence_order = ?"
                    );
                    $stmt->bind_param("ii", $project_id, $sequence_order);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $sequence_in_use = 0;
                    if ($result && $result->num_rows === 1) {
                        $row = $result->fetch_assoc();
                        $sequence_in_use = (int) $row['cnt'];
                    }
                    $stmt->close();
                    if ($sequence_in_use > 0) {
                        $sequence_order = get_next_phase_sequence($conn, $project_id);
                    }
                    $stmt = $conn->prepare(
                        "INSERT INTO project_phases
                         (project_id, phase_template_id, name, sequence_order, owner_role, phase_type, required_doc_type, is_mandatory)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                    );
                    $stmt->bind_param(
                        "iisisisi",
                        $project_id,
                        $tpl['phase_template_id'],
                        $tpl['name'],
                        $sequence_order,
                        $tpl['owner_role'],
                        $tpl['phase_type'],
                        $tpl['required_doc_type'],
                        $tpl['is_mandatory']
                    );
                    $stmt->execute();
                    $new_phase_id = (int) $stmt->insert_id;
                    $stmt->close();
                    if ($new_phase_id > 0) {
                        $phase_map[(int) $tpl['phase_template_id']] = $new_phase_id;
                    }
                }
                if (!empty($phase_map)) {
                    $stmt = $conn->prepare(
                        "SELECT phase_template_id, document_type_id, is_required
                         FROM project_type_phase_documents
                         WHERE project_type = ?"
                    );
                    $stmt->bind_param("s", $project_type);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($result) {
                        while ($row = $result->fetch_assoc()) {
                            $template_id = (int) $row['phase_template_id'];
                            if (!isset($phase_map[$template_id])) {
                                continue;
                            }
                            $phase_id = $phase_map[$template_id];
                            $insert = $conn->prepare(
                                "INSERT INTO phase_required_documents (phase_id, document_type_id, is_required)
                                 VALUES (?, ?, ?)
                                 ON DUPLICATE KEY UPDATE is_required = VALUES(is_required)"
                            );
                            $insert->bind_param("iii", $phase_id, $row['document_type_id'], $row['is_required']);
                            $insert->execute();
                            $insert->close();
                        }
                    }
                    $stmt->close();

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
                            $phase_template_id = (int) $row['phase_template_id'];
                            $depends_on_template_id = (int) $row['depends_on_template_id'];
                            if (!isset($phase_map[$phase_template_id]) || !isset($phase_map[$depends_on_template_id])) {
                                continue;
                            }
                            $phase_id = $phase_map[$phase_template_id];
                            $depends_id = $phase_map[$depends_on_template_id];
                            $insert = $conn->prepare(
                                "INSERT INTO phase_dependencies (phase_id, depends_on_phase_id)
                                 VALUES (?, ?)
                                 ON DUPLICATE KEY UPDATE phase_id = phase_id"
                            );
                            $insert->bind_param("ii", $phase_id, $depends_id);
                            $insert->execute();
                            $insert->close();
                        }
                    }
                    $stmt->close();
                }
                $success = "Phase templates generated.";
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_document_phase'])) {
    if (!$is_admin) {
        $error = "You are not allowed to create phases.";
    } else {
        $doc_type = trim($_POST['doc_phase_type'] ?? '');
        $owner_role = trim($_POST['doc_phase_role'] ?? '');
        $assignee_id = trim($_POST['doc_phase_assignee'] ?? '');
        $assignee_id = $assignee_id !== '' ? (int) $assignee_id : null;
        $name = trim($_POST['doc_phase_name'] ?? '');

        if ($doc_type === '' || $owner_role === '') {
            $error = "Document type and role are required.";
        } else {
            if ($name === '') {
                $name = strtoupper($doc_type) . " Document";
            }
            $sequence_order = get_next_phase_sequence($conn, $project_id);
            $stmt = $conn->prepare(
                "INSERT INTO project_phases
                 (project_id, name, sequence_order, owner_role, assignee_id, phase_type, required_doc_type)
                 VALUES (?, ?, ?, ?, ?, 'document', ?)"
            );
            $stmt->bind_param("isisis", $project_id, $name, $sequence_order, $owner_role, $assignee_id, $doc_type);
            if ($stmt->execute()) {
                $success = "Document phase created.";
            } else {
                $error = "Unable to create document phase.";
            }
            $stmt->close();
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
    "SELECT p.*, u.username AS assignee_name
     FROM project_phases p
     LEFT JOIN users u ON u.id = p.assignee_id
     WHERE p.project_id = " . (int) $project_id . "
     ORDER BY p.sequence_order ASC"
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

$document_types = [];
$doc_type_result = $conn->query(
    "SELECT id, code, label FROM document_types WHERE is_active = 1 ORDER BY label ASC"
);
if ($doc_type_result) {
    while ($row = $doc_type_result->fetch_assoc()) {
        $document_types[] = $row;
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

$project_documents = [];
$docs_result = $conn->query(
    "SELECT * FROM attachments
     WHERE project_id = " . (int) $project_id . " AND phase_id IS NULL AND is_deleted = 0
     ORDER BY created_at DESC"
);
if ($docs_result) {
    while ($row = $docs_result->fetch_assoc()) {
        $project_documents[] = $row;
    }
}

$phase_documents = [];
$phase_docs_result = $conn->query(
    "SELECT a.*, p.name AS phase_name
     FROM attachments a
     JOIN project_phases p ON p.id = a.phase_id
     WHERE p.project_id = " . (int) $project_id . " AND a.is_deleted = 0
     ORDER BY a.created_at DESC"
);
if ($phase_docs_result) {
    while ($row = $phase_docs_result->fetch_assoc()) {
        $phase_documents[] = $row;
    }
}

$required_doc_types = [];
$project_type = trim($project['project_type'] ?? '');
if ($project_type !== '') {
    $stmt = $conn->prepare(
        "SELECT dt.code
         FROM project_type_documents ptd
         JOIN document_types dt ON dt.id = ptd.document_type_id
         WHERE ptd.project_type = ? AND ptd.is_required = 1 AND dt.is_active = 1"
    );
    $stmt->bind_param("s", $project_type);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $required_doc_types[] = $row['code'];
        }
    }
    $stmt->close();
}
$required_docs_status = [];
foreach ($required_doc_types as $doc_type) {
    $required_docs_status[$doc_type] = false;
}
foreach ($project_documents as $doc) {
    if (!empty($doc['doc_type']) && isset($required_docs_status[$doc['doc_type']])) {
        $required_docs_status[$doc['doc_type']] = true;
    }
}

$phase_requirements = [];
$req_result = $conn->query(
    "SELECT prd.phase_id, dt.label, dt.code
     FROM phase_required_documents prd
     JOIN document_types dt ON dt.id = prd.document_type_id
     WHERE prd.is_required = 1"
);
if ($req_result) {
    while ($row = $req_result->fetch_assoc()) {
        $phase_requirements[$row['phase_id']][] = $row;
    }
}

$phase_dependencies = [];
$dep_result = $conn->query(
    "SELECT d.phase_id, d.depends_on_phase_id, p.name AS depends_on_name
     FROM phase_dependencies d
     JOIN project_phases p ON p.id = d.depends_on_phase_id
     WHERE p.project_id = " . (int) $project_id
);
if ($dep_result) {
    while ($row = $dep_result->fetch_assoc()) {
        $phase_dependencies[$row['phase_id']][] = $row;
    }
}

$users = [];
$user_result = $conn->query("SELECT id, username, role FROM users ORDER BY username ASC");
if ($user_result) {
    while ($row = $user_result->fetch_assoc()) {
        $users[] = $row;
    }
}

$roles = ['admin', 'designer', 'firmware', 'tester', 'supplier', 'coordinator'];
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
                    <?php echo htmlspecialchars($project['code']); ?> v<?php echo htmlspecialchars($project['version'] ?? ''); ?>
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
                <?php if ($is_admin): ?>
                    <form class="inline-form" method="POST" action="project_view.php?id=<?php echo (int) $project_id; ?>">
                        <label class="delete-option">
                            <input type="checkbox" name="delete_files" value="1">
                            <span>Delete files</span>
                        </label>
                        <button class="btn btn-danger" type="submit" name="delete_project" onclick="return confirm('Delete this project? This cannot be undone.');">
                            Delete project
                        </button>
                    </form>
                <?php endif; ?>
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
            <div class="project-main section-stack">
                <section class="card">
                    <div class="card-header">
                        <div>
                            <h2>Project details</h2>
                            <p class="muted">Current state: <?php echo htmlspecialchars($current_state_label ?? ''); ?></p>
                        </div>
                    </div>
                    <?php if ($is_admin): ?>
                        <form class="form-grid" method="POST" action="project_view.php?id=<?php echo (int) $project_id; ?>">
                            <label for="project_code">Project code</label>
                            <input id="project_code" type="text" name="project_code" value="<?php echo htmlspecialchars($project['code']); ?>" required>

                            <label for="project_version">Project version</label>
                            <input id="project_version" type="number" name="project_version" value="<?php echo htmlspecialchars($project['version'] ?? ''); ?>" min="1" step="1" required>

                            <label for="project_name">Project name</label>
                            <input id="project_name" type="text" name="project_name" value="<?php echo htmlspecialchars($project['name']); ?>" required>

                            <label for="project_type_select">Project type</label>
                            <select id="project_type_select" name="project_type_select">
                                <option value="">-- Select project type --</option>
                                <?php foreach ($project_types as $type): ?>
                                    <option value="<?php echo htmlspecialchars($type); ?>" <?php echo (($project['project_type'] ?? '') === $type) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($type); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <label for="project_type_custom">Or add new project type</label>
                            <input id="project_type_custom" type="text" name="project_type_custom" placeholder="Enter new type">

                            <label for="owner_id">Project owner</label>
                            <select id="owner_id" name="owner_id">
                                <option value="">-- Select owner --</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?php echo (int) $user['id']; ?>" <?php echo ((int) $project['owner_id'] === (int) $user['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($user['username']); ?> (<?php echo htmlspecialchars($user['role']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <label for="start_date">Start date</label>
                            <input id="start_date" type="date" name="start_date" value="<?php echo htmlspecialchars($project['start_date'] ?? ''); ?>">

                            <label for="description">Description</label>
                            <textarea id="description" name="description" rows="4"><?php echo htmlspecialchars($project['description'] ?? ''); ?></textarea>

                            <div class="actions">
                                <button type="submit" class="btn btn-primary" name="update_project">Save project</button>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="info-grid">
                            <div>
                                <div class="info-label">Project code</div>
                                <div class="info-value"><?php echo htmlspecialchars($project['code']); ?></div>
                            </div>
                            <div>
                                <div class="info-label">Version</div>
                                <div class="info-value"><?php echo htmlspecialchars($project['version'] ?? ''); ?></div>
                            </div>
                            <div>
                                <div class="info-label">Owner</div>
                                <div class="info-value"><?php echo htmlspecialchars($project['owner_name'] ?? ''); ?></div>
                            </div>
                            <div>
                                <div class="info-label">Start date</div>
                                <div class="info-value"><?php echo htmlspecialchars($project['start_date'] ?? ''); ?></div>
                            </div>
                            <div>
                                <div class="info-label">Project type</div>
                                <div class="info-value"><?php echo htmlspecialchars($project['project_type'] ?? ''); ?></div>
                            </div>
                        </div>
                        <?php if (!empty($project['description'])): ?>
                            <p><?php echo nl2br(htmlspecialchars($project['description'])); ?></p>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>

                <section class="card">
                    <div class="card-header">
                        <div>
                            <h2>Project decisions</h2>
                            <p class="muted">Enable options to generate mandatory requirements.</p>
                        </div>
                    </div>

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
                    <div class="card-header">
                        <h2>Generated requirements</h2>
                    </div>
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
                    <div class="card-header">
                        <div>
                            <h2>Project phases</h2>
                            <p class="muted">Track deadlines and phase status.</p>
                        </div>
                        <?php if ($is_admin): ?>
                            <form method="POST" action="project_view.php?id=<?php echo (int) $project_id; ?>">
                                <button class="btn btn-secondary" type="submit" name="generate_phases">Generate phases</button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <?php if (empty($phases)): ?>
                        <p class="muted">No phases generated yet.</p>
                    <?php else: ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Sequence</th>
                                    <th>Phase</th>
                                    <th>Owner role</th>
                                    <th>Assignee</th>
                                    <th>Type</th>
                                    <th>Required doc</th>
                                    <th>Due</th>
                                    <th>Status</th>
                                    <th>Mandatory</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($phases as $row): ?>
                                    <?php
                                    $due_status = 'unknown';
                                    $due_label = 'No date';
                                    if (!empty($row['due_date'])) {
                                        $today = new DateTime('today');
                                        $due_date = new DateTime($row['due_date']);
                                        $diff = (int) $today->diff($due_date)->format('%r%a');
                                        if ($diff < 0) {
                                            $due_status = 'overdue';
                                            $due_label = 'Overdue';
                                        } elseif ($diff <= 7) {
                                            $due_status = 'due-soon';
                                            $due_label = 'Due soon';
                                        } else {
                                            $due_status = 'on-track';
                                            $due_label = 'On track';
                                        }
                                    }
                                    ?>
                                    <?php
                                    $is_completed = !empty($row['completed_at']);
                                    $can_complete = $is_admin || $role === 'coordinator';
                                    if (!$can_complete && !empty($row['assignee_id']) && (int) $row['assignee_id'] === $user_id) {
                                        $can_complete = true;
                                    }
                                    if (!$can_complete && $row['owner_role'] === $role) {
                                        $can_complete = true;
                                    }
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['sequence_order']); ?></td>
                                        <td><?php echo htmlspecialchars($row['name']); ?></td>
                                        <td><?php echo htmlspecialchars($row['owner_role']); ?></td>
                                        <td><?php echo htmlspecialchars($row['assignee_name'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($row['phase_type']); ?></td>
                                        <td>
                                            <?php if (!empty($phase_requirements[$row['id']])): ?>
                                                <?php
                                                $labels = array_map(function ($r) {
                                                    return $r['label'];
                                                }, $phase_requirements[$row['id']]);
                                                echo htmlspecialchars(implode(', ', $labels));
                                                ?>
                                            <?php else: ?>
                                                <?php echo htmlspecialchars($row['required_doc_type'] ?? ''); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (($role ?? '') === 'admin' || ($role ?? '') === 'coordinator'): ?>
                                                <form class="inline-form" method="POST" action="project_view.php?id=<?php echo (int) $project_id; ?>">
                                                    <input type="hidden" name="phase_id" value="<?php echo (int) $row['id']; ?>">
                                                    <input type="date" name="due_date" value="<?php echo htmlspecialchars($row['due_date'] ?? ''); ?>">
                                                    <select name="assignee_id">
                                                        <option value="">Unassigned</option>
                                                        <?php foreach ($users as $user): ?>
                                                            <option value="<?php echo (int) $user['id']; ?>" <?php echo ((int) $row['assignee_id'] === (int) $user['id']) ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($user['username']); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <select name="owner_role">
                                                        <?php foreach ($roles as $role_name): ?>
                                                            <option value="<?php echo htmlspecialchars($role_name); ?>" <?php echo ($row['owner_role'] === $role_name) ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($role_name); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button class="btn btn-secondary" type="submit" name="update_phase_due">Save</button>
                                                </form>
                                            <?php else: ?>
                                                <?php echo htmlspecialchars($row['due_date'] ?? ''); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-dot status-<?php echo htmlspecialchars($due_status); ?>"></span>
                                            <?php echo $is_completed ? 'Completed' : htmlspecialchars($due_label); ?>
                                        </td>
                                        <td><?php echo $row['is_mandatory'] ? 'Yes' : 'No'; ?></td>
                                        <td>
                                            <?php if (!$is_completed && $can_complete): ?>
                                                <form class="inline-form" method="POST" action="project_view.php?id=<?php echo (int) $project_id; ?>">
                                                    <input type="hidden" name="phase_id" value="<?php echo (int) $row['id']; ?>">
                                                    <button class="btn btn-primary" type="submit" name="mark_phase_complete">Mark complete</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="muted"><?php echo $is_completed ? 'Done' : '-'; ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </section>

                <?php if ($is_admin): ?>
                <section class="card">
                    <div class="card-header">
                        <div>
                            <h2>Phase requirements</h2>
                            <p class="muted">Require specific document types per phase.</p>
                        </div>
                    </div>
                    <form class="form-grid" method="POST" action="project_view.php?id=<?php echo (int) $project_id; ?>">
                        <label for="req_phase_id">Phase</label>
                        <select id="req_phase_id" name="req_phase_id" required>
                            <option value="">-- Select phase --</option>
                            <?php foreach ($phases as $row): ?>
                                <option value="<?php echo (int) $row['id']; ?>">
                                    <?php echo htmlspecialchars($row['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label for="req_document_type_id">Document type</label>
                        <select id="req_document_type_id" name="req_document_type_id" required>
                            <option value="">-- Select type --</option>
                            <?php foreach ($document_types as $doc_type): ?>
                                <option value="<?php echo (int) $doc_type['id']; ?>">
                                    <?php echo htmlspecialchars($doc_type['label']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <div class="actions">
                            <button class="btn btn-primary" type="submit" name="add_phase_requirement">Add requirement</button>
                        </div>
                    </form>
                </section>

                <section class="card">
                    <div class="card-header">
                        <div>
                            <h2>Phase dependencies</h2>
                            <p class="muted">Enforce completion order across phases.</p>
                        </div>
                    </div>
                    <form class="form-grid" method="POST" action="project_view.php?id=<?php echo (int) $project_id; ?>">
                        <label for="dep_phase_id">Phase</label>
                        <select id="dep_phase_id" name="dep_phase_id" required>
                            <option value="">-- Select phase --</option>
                            <?php foreach ($phases as $row): ?>
                                <option value="<?php echo (int) $row['id']; ?>">
                                    <?php echo htmlspecialchars($row['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label for="depends_on_phase_id">Depends on</label>
                        <select id="depends_on_phase_id" name="depends_on_phase_id" required>
                            <option value="">-- Select dependency --</option>
                            <?php foreach ($phases as $row): ?>
                                <option value="<?php echo (int) $row['id']; ?>">
                                    <?php echo htmlspecialchars($row['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <div class="actions">
                            <button class="btn btn-primary" type="submit" name="add_phase_dependency">Add dependency</button>
                        </div>
                    </form>
                </section>
                <?php endif; ?>

                <?php if ($is_admin): ?>
                <section class="card">
                    <div class="card-header">
                        <div>
                            <h2>Document phases</h2>
                            <p class="muted">Assign document deliverables to specific roles.</p>
                        </div>
                    </div>
                    <form class="form-grid" method="POST" action="project_view.php?id=<?php echo (int) $project_id; ?>">
                        <label for="doc_phase_name">Phase name</label>
                        <input id="doc_phase_name" type="text" name="doc_phase_name" placeholder="e.g. Schematic package">

                        <label for="doc_phase_type">Document type</label>
                        <select id="doc_phase_type" name="doc_phase_type" required>
                            <option value="">-- Select type --</option>
                            <option value="schematic">Schematic</option>
                            <option value="pcb">PCB</option>
                            <option value="bom">BOM</option>
                            <option value="firmware">Firmware</option>
                            <option value="report">Test report</option>
                            <option value="note">Technical note</option>
                            <option value="other">Other</option>
                        </select>

                        <label for="doc_phase_role">Owner role</label>
                        <select id="doc_phase_role" name="doc_phase_role" required>
                            <?php foreach ($roles as $role_name): ?>
                                <?php if ($role_name !== 'admin'): ?>
                                    <option value="<?php echo htmlspecialchars($role_name); ?>">
                                        <?php echo htmlspecialchars($role_name); ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>

                        <label for="doc_phase_assignee">Assignee</label>
                        <select id="doc_phase_assignee" name="doc_phase_assignee">
                            <option value="">Unassigned</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo (int) $user['id']; ?>">
                                    <?php echo htmlspecialchars($user['username']); ?> (<?php echo htmlspecialchars($user['role']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <div class="actions">
                            <button type="submit" class="btn btn-primary" name="create_document_phase">Create document phase</button>
                        </div>
                    </form>
                </section>
                <?php endif; ?>

                <section class="card">
                    <div class="card-header">
                        <div>
                            <h2>Project documents</h2>
                            <p class="muted">Upload required documents before enabling other phases.</p>
                        </div>
                        <a class="btn btn-secondary" href="file_manager.php?project_id=<?php echo (int) $project_id; ?>">File manager</a>
                    </div>
                        <div class="doc-status">
                            <?php if (empty($required_docs_status)): ?>
                                <p class="muted">No required document types configured for this project type.</p>
                            <?php else: ?>
                                <?php foreach ($required_docs_status as $doc_type => $is_done): ?>
                                    <div class="doc-status-item">
                                        <span class="status-dot status-<?php echo $is_done ? 'on-track' : 'overdue'; ?>"></span>
                                        <?php echo strtoupper($doc_type); ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                    <form class="document-form" method="POST" action="project_view.php?id=<?php echo (int) $project_id; ?>" enctype="multipart/form-data">
                        <label for="doc_type">Document type</label>
                        <select id="doc_type" name="doc_type" required>
                            <option value="">-- Select type --</option>
                            <?php foreach ($document_types as $doc_type): ?>
                                <option value="<?php echo htmlspecialchars($doc_type['code']); ?>">
                                    <?php echo htmlspecialchars($doc_type['label']); ?>
                                </option>
                            <?php endforeach; ?>
                            <?php if (empty($document_types)): ?>
                                <option value="other">Other</option>
                            <?php endif; ?>
                        </select>

                        <label for="document_phase_id">Attach to phase (optional)</label>
                        <select id="document_phase_id" name="document_phase_id">
                            <option value="">Project level</option>
                            <?php foreach ($phases as $row): ?>
                                <option value="<?php echo (int) $row['id']; ?>">
                                    <?php echo htmlspecialchars($row['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label for="document_file">File</label>
                        <input id="document_file" type="file" name="document_file" required>

                        <div class="actions">
                            <button type="submit" class="btn btn-primary" name="upload_document">Upload document</button>
                        </div>
                    </form>

                    <?php if (empty($project_documents)): ?>
                        <p class="muted">No project documents uploaded yet.</p>
                    <?php else: ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Document</th>
                                    <th>Type</th>
                                    <th>Uploaded</th>
                                    <th>Link</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($project_documents as $doc): ?>
                                    <?php
                                    $doc_url = $doc['storage_url'];
                                    if ($doc_url === null || $doc_url === '') {
                                        $doc_url = '';
                                    }
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($doc['original_name']); ?></td>
                                        <td><?php echo htmlspecialchars($doc['doc_type'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($doc['created_at']); ?></td>
                                        <td>
                                            <?php if ($doc_url !== ''): ?>
                                                <a href="<?php echo htmlspecialchars($doc_url); ?>" target="_blank">Open</a>
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
                    <div class="card-header">
                        <div>
                            <h2>Phase documents</h2>
                            <p class="muted">Attach documents to a specific phase before submitting it.</p>
                        </div>
                    </div>
                    <form class="document-form" method="POST" action="project_view.php?id=<?php echo (int) $project_id; ?>" enctype="multipart/form-data">
                        <label for="phase_doc_phase_id">Phase</label>
                        <select id="phase_doc_phase_id" name="phase_doc_phase_id" required>
                            <option value="">-- Select phase --</option>
                            <?php foreach ($phases as $row): ?>
                                <option value="<?php echo (int) $row['id']; ?>">
                                    <?php echo htmlspecialchars($row['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label for="phase_doc_type">Document type</label>
                        <select id="phase_doc_type" name="phase_doc_type">
                            <?php foreach ($document_types as $doc_type): ?>
                                <option value="<?php echo htmlspecialchars($doc_type['code']); ?>">
                                    <?php echo htmlspecialchars($doc_type['label']); ?>
                                </option>
                            <?php endforeach; ?>
                            <?php if (empty($document_types)): ?>
                                <option value="other">Other</option>
                            <?php endif; ?>
                        </select>

                        <label for="phase_document_file">File</label>
                        <input id="phase_document_file" type="file" name="phase_document_file" required>

                        <div class="actions">
                            <button type="submit" class="btn btn-primary" name="upload_phase_document">Upload phase document</button>
                        </div>
                    </form>

                    <?php if (empty($phase_documents)): ?>
                        <p class="muted">No phase documents uploaded yet.</p>
                    <?php else: ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Phase</th>
                                    <th>Document</th>
                                    <th>Type</th>
                                    <th>Uploaded</th>
                                    <th>Link</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($phase_documents as $doc): ?>
                                    <?php
                                    $doc_url = $doc['storage_url'];
                                    if ($doc_url === null || $doc_url === '') {
                                        $doc_url = '';
                                    }
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($doc['phase_name']); ?></td>
                                        <td><?php echo htmlspecialchars($doc['original_name']); ?></td>
                                        <td><?php echo htmlspecialchars($doc['doc_type'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($doc['created_at']); ?></td>
                                        <td>
                                            <?php if ($doc_url !== ''): ?>
                                                <a href="<?php echo htmlspecialchars($doc_url); ?>" target="_blank">Open</a>
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
                    <div class="card-header">
                        <h2>Submit a phase</h2>
                    </div>
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
                    <div class="card-header">
                        <h2>Phase submissions</h2>
                    </div>
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

            <aside class="project-side section-stack">
                <section class="card card-compact">
                    <div class="card-header">
                        <div>
                            <h2>Workflow actions</h2>
                            <p class="muted">Current state: <?php echo htmlspecialchars($current_state_label ?? ''); ?></p>
                        </div>
                    </div>
                    <?php if (empty($allowed_transitions)): ?>
                        <p class="muted">No actions available for your role.</p>
                    <?php else: ?>
                        <form class="actions" method="POST" action="project_view.php?id=<?php echo (int) $project_id; ?>">
                            <?php foreach ($allowed_transitions as $transition): ?>
                                <button class="btn btn-primary" type="submit" name="transition_id" value="<?php echo (int) $transition['id']; ?>">
                                    <?php
                                    $action_label = ucwords(str_replace('_', ' ', $transition['action']));
                                    $to_label = $to_state_labels[(int) $transition['to_state_id']] ?? '';
                                    echo htmlspecialchars($action_label . ($to_label !== '' ? ' -> ' . $to_label : ''));
                                    ?>
                                </button>
                            <?php endforeach; ?>
                        </form>
                    <?php endif; ?>
                </section>

                <section class="card card-compact">
                    <div class="card-header">
                        <h2>State history</h2>
                    </div>
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
                    <div class="card-header">
                        <h2>Project notifications</h2>
                    </div>
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
