<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once(__DIR__ . '/config/db.php');
require_once(__DIR__ . '/config/workflow.php');
require_once(__DIR__ . '/config/notifications.php');

$success = '';
$error = '';
$user_id = (int) ($_SESSION['user_id'] ?? 0);
$unread_notifications_count = get_unread_notifications_count($conn, $user_id);

function generate_project_code($name)
{
    $slug = preg_replace('/[^A-Za-z0-9]+/', '', strtoupper($name));
    $slug = substr($slug, 0, 4);
    if ($slug === '') {
        $slug = 'PRJ';
    }
    return $slug . '-' . date('Ymd') . '-' . substr(uniqid(), -4);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_project'])) {
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

    if ($name === '') {
        $error = "Project name is required.";
    } else {
        ensure_default_project_workflow($conn);
        $draft_state_id = get_project_state_id($conn, 'draft');
        $code = generate_project_code($name);
        $owner_id = $owner_id !== '' ? (int) $owner_id : null;
        $start_date = $start_date !== '' ? $start_date : null;

        $stmt = $conn->prepare(
            "INSERT INTO projects (code, name, project_type, owner_id, start_date, description, created_by, current_state_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if ($stmt) {
            $created_by = (int) $_SESSION['user_id'];
            $stmt->bind_param("sssissii", $code, $name, $project_type, $owner_id, $start_date, $description, $created_by, $draft_state_id);
            if ($stmt->execute()) {
                $success = "Project created.";
            } else {
                $error = "Unable to create project. " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error = "Database error while creating project. " . $conn->error;
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
                <label for="name">Project name</label>
                <input id="name" type="text" name="name" required>

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
