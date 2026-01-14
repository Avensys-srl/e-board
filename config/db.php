<?php
$host = 'localhost';
$dbname = 'eboard_manager';
$username = 'root';
$password = ''; // Default password vuota in XAMPP

$conn = new mysqli($host, $username, $password, $dbname);
if ($conn->connect_error) {
    die('Database connection failed: ' . $conn->connect_error);
}

$conn->set_charset('utf8mb4');
?>
