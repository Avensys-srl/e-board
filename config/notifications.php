<?php
function get_unread_notifications_count($conn, $user_id)
{
    $count = 0;
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0"
    );
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows === 1) {
        $row = $result->fetch_assoc();
        $count = (int) $row['cnt'];
    }
    $stmt->close();
    return $count;
}

function get_project_notifications($conn, $project_id, $limit = 10)
{
    $rows = [];
    $stmt = $conn->prepare(
        "SELECT n.*, u.username
         FROM notifications n
         LEFT JOIN users u ON u.id = n.user_id
         WHERE n.project_id = ?
         ORDER BY n.created_at DESC
         LIMIT ?"
    );
    $stmt->bind_param("ii", $project_id, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    $stmt->close();
    return $rows;
}

function notify_roles($conn, $roles, $project_id, $phase_id, $title, $message)
{
    if (empty($roles)) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($roles), '?'));
    $types = str_repeat('s', count($roles));
    $stmt = $conn->prepare(
        "SELECT id FROM users WHERE role IN (" . $placeholders . ")"
    );
    $stmt->bind_param($types, ...$roles);
    $stmt->execute();
    $result = $stmt->get_result();
    $user_ids = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $user_ids[] = (int) $row['id'];
        }
    }
    $stmt->close();

    foreach ($user_ids as $user_id) {
        $stmt = $conn->prepare(
            "INSERT INTO notifications (user_id, project_id, phase_id, type, title, message)
             VALUES (?, ?, ?, 'phase_submission', ?, ?)"
        );
        if ($stmt) {
            $stmt->bind_param("iiiss", $user_id, $project_id, $phase_id, $title, $message);
            $stmt->execute();
            $stmt->close();
        }
    }
}

function notify_user($conn, $user_id, $project_id, $phase_id, $title, $message, $type = 'assignment')
{
    $stmt = $conn->prepare(
        "INSERT INTO notifications (user_id, project_id, phase_id, type, title, message)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    if ($stmt) {
        $stmt->bind_param("iiisss", $user_id, $project_id, $phase_id, $type, $title, $message);
        $stmt->execute();
        $stmt->close();
    }
}
