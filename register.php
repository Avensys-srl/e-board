<?php
session_start();
require_once __DIR__ . '/config/db.php';

// Se l'utente e gia loggato, vai alla dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$success = '';

// Gestione form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username']);
    $password_raw = $_POST['password'];
    $role = $_POST['role'];

    if ($username === '' || $password_raw === '' || $role === '') {
        $error = "All fields are required.";
    } else {

        // Controllo username gia esistente
        $check = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $check->bind_param("s", $username);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error = "Username already exists.";
        } else {

            // Hash password
            $password = password_hash($password_raw, PASSWORD_DEFAULT);

            // Inserimento utente
            $stmt = $conn->prepare(
                "INSERT INTO users (username, password, role, created_at)
                 VALUES (?, ?, ?, NOW())"
            );
            $stmt->bind_param("sss", $username, $password, $role);
            $stmt->execute();

            $success = "User registered successfully. You can now login.";
            $stmt->close();
        }

        $check->close();
    }
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>EBOARD Manager - Register</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="page auth">
    <div class="auth-shell">
        <section class="auth-hero">
            <p class="eyebrow">EBOARD Manager</p>
            <h1>Crea un nuovo utente</h1>
            <p>Assegna il ruolo corretto e condividi le credenziali in modo sicuro.</p>
        </section>

        <main class="auth-card">
            <h2>Register</h2>

            <?php if ($error): ?>
                <p class="alert alert-error"><?php echo htmlspecialchars($error); ?></p>
            <?php endif; ?>

            <?php if ($success): ?>
                <p class="alert alert-success"><?php echo htmlspecialchars($success); ?></p>
            <?php endif; ?>

            <form method="POST" action="register.php">
                <label for="username">Username</label>
                <input id="username" type="text" name="username" required>

                <label for="password">Password</label>
                <input id="password" type="password" name="password" required>

                <label for="role">Role</label>
                <select id="role" name="role" required>
                    <option value="">-- Select role --</option>
                    <option value="admin">Admin</option>
                    <option value="coordinator">Project Coordinator</option>
                    <option value="designer">Electronic Designer</option>
                    <option value="firmware">Firmware Designer</option>
                    <option value="tester">Test Lead (Tester)</option>
                    <option value="supplier">Supplier</option>
                </select>

                <input class="btn btn-primary" type="submit" value="Create User">
            </form>

            <p class="link-row"><a href="login.php">Back to login</a></p>
        </main>
    </div>
</body>
</html>
