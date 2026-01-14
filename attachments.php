<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once(__DIR__ . '/config/db.php');
require_once(__DIR__ . '/config/settings.php');

$success = '';
$error = '';

function is_absolute_path($path)
{
    return preg_match('/^(?:[A-Za-z]:\\\\|\\\\\\\\|\\/)/', $path) === 1;
}

function normalize_base_path($path)
{
    $path = trim($path);
    if ($path === '') {
        return 'uploads';
    }
    return rtrim($path, "/\\");
}

function to_int_or_null($value)
{
    if ($value === null) {
        return null;
    }
    $value = trim($value);
    if ($value === '' || !ctype_digit($value)) {
        return null;
    }
    $int = (int) $value;
    return $int > 0 ? $int : null;
}

$upload_base_path = normalize_base_path(get_setting($conn, 'upload_base_path', 'uploads'));
$upload_base_url = trim(get_setting($conn, 'upload_base_url', ''));
$upload_base_url = $upload_base_url === '' ? '' : rtrim($upload_base_url, '/');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_file'])) {
    if (!isset($_FILES['attachment']) || $_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
        $error = "Please select a file to upload.";
    } else {
        $base_fs_path = $upload_base_path;
        if (!is_absolute_path($base_fs_path)) {
            $base_fs_path = __DIR__ . DIRECTORY_SEPARATOR . $base_fs_path;
        }

        if (!is_dir($base_fs_path) && !mkdir($base_fs_path, 0775, true)) {
            $error = "Unable to create upload directory.";
        } else {
            $subdir = date('Y/m');
            $target_dir = $base_fs_path . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $subdir);

            if (!is_dir($target_dir) && !mkdir($target_dir, 0775, true)) {
                $error = "Unable to create upload subdirectory.";
            } else {
                $original_name = basename($_FILES['attachment']['name']);
                $extension = pathinfo($original_name, PATHINFO_EXTENSION);
                $safe_name = uniqid('file_', true);
                if ($extension !== '') {
                    $safe_name .= '.' . strtolower($extension);
                }

                $target_path = $target_dir . DIRECTORY_SEPARATOR . $safe_name;
                if (!move_uploaded_file($_FILES['attachment']['tmp_name'], $target_path)) {
                    $error = "Upload failed. Please try again.";
                } else {
                    $relative_path = $subdir . '/' . $safe_name;
                    $storage_url = $upload_base_url !== ''
                        ? $upload_base_url . '/' . $relative_path
                        : null;

                    $mime_type = null;
                    if (function_exists('finfo_open')) {
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        if ($finfo) {
                            $mime_type = finfo_file($finfo, $target_path);
                            finfo_close($finfo);
                        }
                    }
                    if ($mime_type === null) {
                        $mime_type = $_FILES['attachment']['type'] ?? null;
                    }

                    $project_id = to_int_or_null($_POST['project_id'] ?? null);
                    $phase_id = to_int_or_null($_POST['phase_id'] ?? null);
                    $project_version_id = to_int_or_null($_POST['project_version_id'] ?? null);
                    $firmware_version_id = to_int_or_null($_POST['firmware_version_id'] ?? null);

                    $stmt = $conn->prepare(
                        "INSERT INTO attachments (
                            project_id,
                            phase_id,
                            project_version_id,
                            firmware_version_id,
                            uploaded_by,
                            storage_type,
                            storage_path,
                            storage_url,
                            original_name,
                            mime_type,
                            size_bytes
                         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    );

                    if ($stmt) {
                        $storage_type = 'local';
                        $storage_path = str_replace('\\', '/', $relative_path);
                        $size_bytes = (int) ($_FILES['attachment']['size'] ?? 0);
                        $uploaded_by = (int) $_SESSION['user_id'];

                        $stmt->bind_param(
                            "iiiiisssssi",
                            $project_id,
                            $phase_id,
                            $project_version_id,
                            $firmware_version_id,
                            $uploaded_by,
                            $storage_type,
                            $storage_path,
                            $storage_url,
                            $original_name,
                            $mime_type,
                            $size_bytes
                        );

                        if ($stmt->execute()) {
                            $success = "File uploaded successfully.";
                        } else {
                            $error = "Database insert failed.";
                        }
                        $stmt->close();
                    } else {
                        $error = "Database error during upload.";
                    }
                }
            }
        }
    }
}

$attachments = [];
$result = $conn->query(
    "SELECT a.*, u.username
     FROM attachments a
     LEFT JOIN users u ON u.id = a.uploaded_by
     ORDER BY a.created_at DESC
     LIMIT 20"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $attachments[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Attachments</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="page">
    <main class="page-shell">
        <header class="page-header">
            <div>
                <p class="eyebrow">Files</p>
                <h1>Attachments</h1>
                <p class="muted">Upload files and associate them with projects or phases.</p>
            </div>
            <div class="page-actions">
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

            <form class="upload-form" method="POST" action="attachments.php" enctype="multipart/form-data">
                <label for="attachment">Select file</label>
                <input id="attachment" type="file" name="attachment" required>

                <label for="project_id">Project ID (optional)</label>
                <input id="project_id" type="text" name="project_id" placeholder="e.g. 1">

                <label for="phase_id">Phase ID (optional)</label>
                <input id="phase_id" type="text" name="phase_id" placeholder="e.g. 3">

                <label for="project_version_id">Project version ID (optional)</label>
                <input id="project_version_id" type="text" name="project_version_id" placeholder="e.g. 2">

                <label for="firmware_version_id">Firmware version ID (optional)</label>
                <input id="firmware_version_id" type="text" name="firmware_version_id" placeholder="e.g. 5">

                <p class="muted">
                    Files are stored under the configured base path. If a base URL is set,
                    links will use that URL.
                </p>

                <div class="actions">
                    <button type="submit" class="btn btn-primary" name="upload_file">Upload file</button>
                </div>
            </form>
        </section>

        <section class="card">
            <h2>Recent uploads</h2>
            <?php if (empty($attachments)): ?>
                <p class="muted">No files uploaded yet.</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>File</th>
                            <th>Uploaded By</th>
                            <th>Project</th>
                            <th>Phase</th>
                            <th>Created At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attachments as $row): ?>
                            <?php
                            $public_url = $row['storage_url'];
                            if ($public_url === null || $public_url === '') {
                                $public_url = $upload_base_url !== ''
                                    ? $upload_base_url . '/' . ltrim($row['storage_path'], '/')
                                    : '';
                            }
                            ?>
                            <tr>
                                <td>
                                    <?php if ($public_url !== ''): ?>
                                        <a href="<?php echo htmlspecialchars($public_url); ?>" target="_blank">
                                            <?php echo htmlspecialchars($row['original_name']); ?>
                                        </a>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars($row['original_name']); ?>
                                    <?php endif; ?>
                                    <div class="muted"><?php echo htmlspecialchars($row['storage_path']); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars($row['username'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($row['project_id'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($row['phase_id'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($row['created_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
