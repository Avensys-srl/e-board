<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once(__DIR__ . '/config/db.php');

$success = '';
$error = '';

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
    $project_type = trim($_POST['project_type'] ?? '');
    $owner_id = trim($_POST['owner_id'] ?? '');
    $start_date = trim($_POST['start_date'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($name === '') {
        $error = "Project name is required.";
    } else {
        $code = generate_project_code($name);
        $owner_id = $owner_id !== '' ? (int) $owner_id : null;
        $start_date = $start_date !== '' ? $start_date : null;

        $stmt = $conn->prepare(
            "INSERT INTO projects (code, name, project_type, owner_id, start_date, description, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        if ($stmt) {
            $created_by = (int) $_SESSION['user_id'];
            $stmt->bind_param("sssissi", $code, $name, $project_type, $owner_id, $start_date, $description, $created_by);
            if ($stmt->execute()) {
                $success = "Project created.";
            } else {
                $error = "Unable to create project.";
            }
            $stmt->close();
        } else {
            $error = "Database error while creating project.";
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

                <label for="project_type">Project type</label>
                <input id="project_type" type="text" name="project_type" placeholder="e.g. Control Board">

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
