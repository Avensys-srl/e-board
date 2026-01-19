<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once(__DIR__ . '/config/db.php');
require_once(__DIR__ . '/config/settings.php');
require_once(__DIR__ . '/config/uploads.php');
require_once(__DIR__ . '/config/notifications.php');

$user_id = (int) $_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';
$is_admin = ($role === 'admin');
$is_manager = ($role === 'admin' || $role === 'coordinator');

$project_id = isset($_GET['project_id']) ? (int) $_GET['project_id'] : 0;
if ($project_id <= 0) {
    header('Location: projects.php');
    exit;
}

$success = '';
$error = '';

$project = null;
$stmt = $conn->prepare(
    "SELECT id, code, name FROM projects WHERE id = ?"
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

function move_file_to_trash($base_path, $relative_path)
{
    $base_fs_path = resolve_upload_base_path($base_path);
    $relative_path = str_replace('/', DIRECTORY_SEPARATOR, $relative_path);
    $source = $base_fs_path . DIRECTORY_SEPARATOR . $relative_path;
    if (!is_file($source)) {
        return [true, null];
    }

    $trash_dir = $base_fs_path . DIRECTORY_SEPARATOR . '_trash';
    if (!is_dir($trash_dir) && !mkdir($trash_dir, 0775, true)) {
        return [false, null];
    }
    $target = $trash_dir . DIRECTORY_SEPARATOR . $relative_path;
    $target_dir = dirname($target);
    if (!is_dir($target_dir) && !mkdir($target_dir, 0775, true)) {
        return [false, null];
    }

    if (!rename($source, $target)) {
        return [false, null];
    }

    $trash_relative = '_trash/' . str_replace('\\', '/', $relative_path);
    return [true, $trash_relative];
}

function restore_file_from_trash($base_path, $trash_relative)
{
    $base_fs_path = resolve_upload_base_path($base_path);
    $trash_relative = str_replace('/', DIRECTORY_SEPARATOR, $trash_relative);
    $source = $base_fs_path . DIRECTORY_SEPARATOR . $trash_relative;
    if (!is_file($source)) {
        return [true, null];
    }

    $relative = preg_replace('#^_trash[\\\\/]#', '', $trash_relative);
    $target = $base_fs_path . DIRECTORY_SEPARATOR . $relative;
    $target_dir = dirname($target);
    if (!is_dir($target_dir) && !mkdir($target_dir, 0775, true)) {
        return [false, null];
    }

    if (!rename($source, $target)) {
        return [false, null];
    }

    return [true, str_replace('\\', '/', $relative)];
}

function delete_file_permanently($base_path, $relative_path)
{
    $base_fs_path = resolve_upload_base_path($base_path);
    $relative_path = str_replace('/', DIRECTORY_SEPARATOR, $relative_path);
    $file_path = $base_fs_path . DIRECTORY_SEPARATOR . $relative_path;
    if (is_file($file_path)) {
        @unlink($file_path);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['trash_attachment'])) {
    if (!$is_manager) {
        $error = "You are not allowed to move files to trash.";
    } else {
        $attachment_id = (int) ($_POST['attachment_id'] ?? 0);
        $stmt = $conn->prepare(
            "SELECT storage_path, storage_type
             FROM attachments
             WHERE id = ? AND project_id = ? AND is_deleted = 0"
        );
        $stmt->bind_param("ii", $attachment_id, $project_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result && $result->num_rows === 1 ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            $error = "File not found.";
        } else {
            $trash_path = null;
            if ($row['storage_type'] === 'local' && !empty($row['storage_path'])) {
                $upload_base_path = get_setting($conn, 'upload_base_path', 'uploads');
                list($ok, $trash_relative) = move_file_to_trash($upload_base_path, $row['storage_path']);
                if (!$ok) {
                    $error = "Unable to move file to trash.";
                } else {
                    $trash_path = $trash_relative;
                }
            }
            if ($error === '') {
                $stmt = $conn->prepare(
                    "UPDATE attachments
                     SET is_deleted = 1, deleted_at = NOW(), deleted_by = ?, trash_path = ?
                     WHERE id = ?"
                );
                $stmt->bind_param("isi", $user_id, $trash_path, $attachment_id);
                $stmt->execute();
                $stmt->close();
                $success = "File moved to trash.";
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_attachment'])) {
    if (!$is_manager) {
        $error = "You are not allowed to restore files.";
    } else {
        $attachment_id = (int) ($_POST['attachment_id'] ?? 0);
        $stmt = $conn->prepare(
            "SELECT trash_path, storage_type
             FROM attachments
             WHERE id = ? AND project_id = ? AND is_deleted = 1"
        );
        $stmt->bind_param("ii", $attachment_id, $project_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result && $result->num_rows === 1 ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            $error = "File not found.";
        } else {
            $storage_path = null;
            if ($row['storage_type'] === 'local' && !empty($row['trash_path'])) {
                $upload_base_path = get_setting($conn, 'upload_base_path', 'uploads');
                list($ok, $restored_path) = restore_file_from_trash($upload_base_path, $row['trash_path']);
                if (!$ok) {
                    $error = "Unable to restore file.";
                } else {
                    $storage_path = $restored_path;
                }
            }
            if ($error === '') {
                $stmt = $conn->prepare(
                    "UPDATE attachments
                     SET is_deleted = 0, deleted_at = NULL, deleted_by = NULL, trash_path = NULL, storage_path = COALESCE(?, storage_path)
                     WHERE id = ?"
                );
                $stmt->bind_param("si", $storage_path, $attachment_id);
                $stmt->execute();
                $stmt->close();
                $success = "File restored.";
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_attachment'])) {
    if (!$is_admin) {
        $error = "Only admin can delete files permanently.";
    } else {
        $attachment_id = (int) ($_POST['attachment_id'] ?? 0);
        $stmt = $conn->prepare(
            "SELECT storage_path, trash_path, storage_type
             FROM attachments
             WHERE id = ? AND project_id = ?"
        );
        $stmt->bind_param("ii", $attachment_id, $project_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result && $result->num_rows === 1 ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            $error = "File not found.";
        } else {
            if ($row['storage_type'] === 'local') {
                $upload_base_path = get_setting($conn, 'upload_base_path', 'uploads');
                if (!empty($row['storage_path'])) {
                    delete_file_permanently($upload_base_path, $row['storage_path']);
                }
                if (!empty($row['trash_path'])) {
                    delete_file_permanently($upload_base_path, $row['trash_path']);
                }
            }
            $stmt = $conn->prepare("DELETE FROM attachments WHERE id = ?");
            $stmt->bind_param("i", $attachment_id);
            $stmt->execute();
            $stmt->close();
            $success = "File deleted permanently.";
        }
    }
}

$active_files = [];
$stmt = $conn->prepare(
    "SELECT a.*, u.username, p.name AS phase_name
     FROM attachments a
     LEFT JOIN users u ON u.id = a.uploaded_by
     LEFT JOIN project_phases p ON p.id = a.phase_id
     WHERE a.project_id = ? AND a.is_deleted = 0
     ORDER BY a.created_at DESC"
);
$stmt->bind_param("i", $project_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $active_files[] = $row;
    }
}
$stmt->close();

$trashed_files = [];
$stmt = $conn->prepare(
    "SELECT a.*, u.username, p.name AS phase_name
     FROM attachments a
     LEFT JOIN users u ON u.id = a.uploaded_by
     LEFT JOIN project_phases p ON p.id = a.phase_id
     WHERE a.project_id = ? AND a.is_deleted = 1
     ORDER BY a.deleted_at DESC"
);
$stmt->bind_param("i", $project_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $trashed_files[] = $row;
    }
}
$stmt->close();

$unread_notifications_count = get_unread_notifications_count($conn, $user_id);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>File manager</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="page">
    <main class="page-shell">
        <header class="page-header">
            <div>
                <p class="eyebrow">Files</p>
                <h1><?php echo htmlspecialchars($project['name']); ?></h1>
                <p class="muted">Project file manager and trash.</p>
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
                <a class="btn btn-secondary" href="project_view.php?id=<?php echo (int) $project_id; ?>">Back to project</a>
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

        <section class="card">
            <div class="card-header">
                <h2>Active files</h2>
            </div>
            <?php if (empty($active_files)): ?>
                <p class="muted">No files yet.</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>File</th>
                            <th>Type</th>
                            <th>Phase</th>
                            <th>Uploaded By</th>
                            <th>Uploaded</th>
                            <th>Link</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($active_files as $file): ?>
                            <?php
                            $file_url = $file['storage_url'];
                            if ($file_url === null || $file_url === '') {
                                $file_url = '';
                            }
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($file['original_name']); ?></td>
                                <td><?php echo htmlspecialchars($file['doc_type'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($file['phase_name'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($file['username'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($file['created_at']); ?></td>
                                <td>
                                    <?php if ($file_url !== ''): ?>
                                        <a href="<?php echo htmlspecialchars($file_url); ?>" target="_blank">Open</a>
                                    <?php else: ?>
                                        <span class="muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($is_manager): ?>
                                        <form class="inline-form" method="POST" action="file_manager.php?project_id=<?php echo (int) $project_id; ?>">
                                            <input type="hidden" name="attachment_id" value="<?php echo (int) $file['id']; ?>">
                                            <button class="btn btn-secondary" type="submit" name="trash_attachment">Move to trash</button>
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
                <h2>Trash</h2>
                <p class="muted">Files kept forever until admin deletion.</p>
            </div>
            <?php if (empty($trashed_files)): ?>
                <p class="muted">Trash is empty.</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>File</th>
                            <th>Type</th>
                            <th>Phase</th>
                            <th>Deleted At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($trashed_files as $file): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($file['original_name']); ?></td>
                                <td><?php echo htmlspecialchars($file['doc_type'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($file['phase_name'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($file['deleted_at']); ?></td>
                                <td>
                                    <?php if ($is_manager): ?>
                                        <form class="inline-form" method="POST" action="file_manager.php?project_id=<?php echo (int) $project_id; ?>">
                                            <input type="hidden" name="attachment_id" value="<?php echo (int) $file['id']; ?>">
                                            <button class="btn btn-secondary" type="submit" name="restore_attachment">Restore</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($is_admin): ?>
                                        <form class="inline-form" method="POST" action="file_manager.php?project_id=<?php echo (int) $project_id; ?>">
                                            <input type="hidden" name="attachment_id" value="<?php echo (int) $file['id']; ?>">
                                            <button class="btn btn-danger" type="submit" name="delete_attachment" onclick="return confirm('Delete this file permanently?');">Delete</button>
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
