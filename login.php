<?php
session_start();
require_once __DIR__ . '/config/db.php';

// Se l'utente e gia loggato, lo reindirizziamo alla dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (!empty($username) && !empty($password)) {
        $stmt = $conn->prepare("SELECT id, password, role FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows === 1) {
            $user = $result->fetch_assoc();

            if (password_verify($password, $user['password'])) {
                // Login riuscito
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $username;
                $_SESSION['role'] = $user['role'];
                header('Location: dashboard.php');
                exit;
            } else {
                $error = "Password errata.";
            }
        } else {
            $error = "Utente non trovato.";
        }

        $stmt->close();
    } else {
        $error = "Inserisci username e password.";
    }
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Login</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="page auth">
    <div class="auth-shell">
        <section class="auth-hero">
            <p class="eyebrow">EBOARD Manager</p>
            <h1>Area riservata</h1>
            <p>Accedi per gestire utenti, ruoli e configurazioni.</p>
        </section>

        <main class="auth-card">
            <h2>Login</h2>

            <?php if ($error): ?>
                <p class="alert alert-error"><?php echo htmlspecialchars($error); ?></p>
            <?php endif; ?>

            <form method="POST" action="login.php">
                <label for="username">Username</label>
                <input id="username" type="text" name="username" required>

                <label for="password">Password</label>
                <input id="password" type="password" name="password" required>

                <input class="btn btn-primary" type="submit" value="Login">
            </form>

            <p class="link-row">Non hai un account? <a href="register.php">Registrati</a></p>
        </main>
    </div>
</body>
</html>
