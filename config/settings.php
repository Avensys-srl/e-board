<?php
function get_setting($conn, $key, $default = '')
{
    $stmt = $conn->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $result = $stmt->get_result();
    $value = $default;
    if ($result && $result->num_rows === 1) {
        $row = $result->fetch_assoc();
        $value = $row['setting_value'];
    }
    $stmt->close();
    return $value;
}

function set_setting($conn, $key, $value)
{
    $stmt = $conn->prepare(
        "INSERT INTO app_settings (setting_key, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("ss", $key, $value);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}
