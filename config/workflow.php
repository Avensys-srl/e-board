<?php
function ensure_default_project_workflow($conn)
{
    $states = [
        ['draft', 'Draft', 0],
        ['submitted', 'Submitted', 0],
        ['under_review', 'Under Review', 0],
        ['test_requested', 'Test Requested', 0],
        ['test_completed', 'Test Completed', 0],
        ['approved', 'Approved', 0],
        ['rejected', 'Rejected', 0],
        ['archived', 'Archived', 1],
    ];

    foreach ($states as $state) {
        $stmt = $conn->prepare(
            "INSERT INTO workflow_states (scope, code, label, is_terminal)
             VALUES ('project', ?, ?, ?)
             ON DUPLICATE KEY UPDATE label = VALUES(label), is_terminal = VALUES(is_terminal)"
        );
        $stmt->bind_param("ssi", $state[0], $state[1], $state[2]);
        $stmt->execute();
        $stmt->close();
    }

    $state_ids = [];
    $result = $conn->query("SELECT id, code FROM workflow_states WHERE scope = 'project'");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $state_ids[$row['code']] = (int) $row['id'];
        }
    }

    $transitions = [
        ['draft', 'submitted', 'submit', 'designer,coordinator,admin', 'coordinator'],
        ['submitted', 'under_review', 'start_review', 'coordinator,admin', 'coordinator'],
        ['under_review', 'approved', 'approve', 'coordinator,admin', 'designer'],
        ['under_review', 'rejected', 'reject', 'coordinator,admin', 'designer'],
        ['under_review', 'test_requested', 'request_test', 'coordinator,admin', 'tester'],
        ['test_requested', 'test_completed', 'complete_test', 'tester,admin', 'coordinator'],
        ['test_completed', 'approved', 'approve_after_test', 'coordinator,admin', 'designer'],
        ['rejected', 'draft', 'revise', 'designer,admin', 'coordinator'],
        ['approved', 'archived', 'archive', 'coordinator,admin', ''],
    ];

    foreach ($transitions as $transition) {
        if (!isset($state_ids[$transition[0]], $state_ids[$transition[1]])) {
            continue;
        }
        $stmt = $conn->prepare(
            "INSERT INTO workflow_transitions
             (scope, from_state_id, to_state_id, action, allowed_roles, notify_roles)
             VALUES ('project', ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE allowed_roles = VALUES(allowed_roles), notify_roles = VALUES(notify_roles)"
        );
        $from_id = $state_ids[$transition[0]];
        $to_id = $state_ids[$transition[1]];
        $stmt->bind_param("iisss", $from_id, $to_id, $transition[2], $transition[3], $transition[4]);
        $stmt->execute();
        $stmt->close();
    }
}

function get_project_state_id($conn, $code)
{
    $stmt = $conn->prepare(
        "SELECT id FROM workflow_states WHERE scope = 'project' AND code = ? LIMIT 1"
    );
    $stmt->bind_param("s", $code);
    $stmt->execute();
    $result = $stmt->get_result();
    $id = null;
    if ($result && $result->num_rows === 1) {
        $row = $result->fetch_assoc();
        $id = (int) $row['id'];
    }
    $stmt->close();
    return $id;
}

function get_project_state_label($conn, $state_id)
{
    $stmt = $conn->prepare(
        "SELECT label FROM workflow_states WHERE id = ? LIMIT 1"
    );
    $stmt->bind_param("i", $state_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $label = null;
    if ($result && $result->num_rows === 1) {
        $row = $result->fetch_assoc();
        $label = $row['label'];
    }
    $stmt->close();
    return $label;
}

function get_allowed_transitions($conn, $from_state_id, $role)
{
    $transitions = [];
    $stmt = $conn->prepare(
        "SELECT id, action, allowed_roles, notify_roles, to_state_id
         FROM workflow_transitions
         WHERE scope = 'project' AND from_state_id = ?"
    );
    $stmt->bind_param("i", $from_state_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $roles = array_map('trim', explode(',', $row['allowed_roles']));
            if (in_array($role, $roles, true) || in_array('admin', $roles, true)) {
                $transitions[] = $row;
            }
        }
    }
    $stmt->close();
    return $transitions;
}

function transition_project_state($conn, $project_id, $transition_id, $user_id)
{
    $stmt = $conn->prepare(
        "SELECT t.action, t.from_state_id, t.to_state_id, t.notify_roles, p.current_state_id
         FROM workflow_transitions t
         JOIN projects p ON p.id = ?
         WHERE t.id = ? AND t.scope = 'project'"
    );
    $stmt->bind_param("ii", $project_id, $transition_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!$result || $result->num_rows !== 1) {
        $stmt->close();
        return [false, "Invalid transition."];
    }
    $transition = $result->fetch_assoc();
    $stmt->close();

    if ((int) $transition['current_state_id'] !== (int) $transition['from_state_id']) {
        return [false, "State changed. Refresh and try again."];
    }

    $block_actions = ['approve', 'approve_after_test', 'archive'];
    if (in_array($transition['action'], $block_actions, true)) {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS pending_count
             FROM project_requirements
             WHERE project_id = ? AND status = 'pending' AND is_mandatory = 1"
        );
        $stmt->bind_param("i", $project_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $pending_count = 0;
        if ($result && $result->num_rows === 1) {
            $row = $result->fetch_assoc();
            $pending_count = (int) $row['pending_count'];
        }
        $stmt->close();

        if ($pending_count > 0) {
            return [false, "Resolve mandatory requirements before approval."];
        }
    }

    $stmt = $conn->prepare("UPDATE projects SET current_state_id = ? WHERE id = ?");
    $stmt->bind_param("ii", $transition['to_state_id'], $project_id);
    if (!$stmt->execute()) {
        $stmt->close();
        return [false, "Unable to update project state."];
    }
    $stmt->close();

    $stmt = $conn->prepare(
        "INSERT INTO project_state_history
         (project_id, from_state_id, to_state_id, action, acted_by)
         VALUES (?, ?, ?, ?, ?)"
    );
    if ($stmt) {
        $stmt->bind_param(
            "iiisi",
            $project_id,
            $transition['from_state_id'],
            $transition['to_state_id'],
            $transition['action'],
            $user_id
        );
        $stmt->execute();
        $stmt->close();
    }

    $stmt = $conn->prepare(
        "INSERT INTO audit_logs (user_id, project_id, action, details)
         VALUES (?, ?, ?, ?)"
    );
    if ($stmt) {
        $details = 'Transition: ' . $transition['action'];
        $stmt->bind_param("iiss", $user_id, $project_id, $transition['action'], $details);
        $stmt->execute();
        $stmt->close();
    }

    if ($transition['action'] === 'submit') {
        $stmt = $conn->prepare(
            "INSERT INTO approvals (project_id, requested_by, role_required, status)
             VALUES (?, ?, 'coordinator', 'pending')"
        );
        if ($stmt) {
            $stmt->bind_param("ii", $project_id, $user_id);
            $stmt->execute();
            $stmt->close();
        }
    }

    if ($transition['action'] === 'request_test') {
        $stmt = $conn->prepare(
            "INSERT INTO approvals (project_id, requested_by, role_required, status)
             VALUES (?, ?, 'tester', 'pending')"
        );
        if ($stmt) {
            $stmt->bind_param("ii", $project_id, $user_id);
            $stmt->execute();
            $stmt->close();
        }
    }

    if (!empty($transition['notify_roles'])) {
        $roles = array_filter(array_map('trim', explode(',', $transition['notify_roles'])));
        if (!empty($roles)) {
            $placeholders = implode(',', array_fill(0, count($roles), '?'));
            $types = str_repeat('s', count($roles));
            $stmt = $conn->prepare(
                "SELECT id FROM users WHERE role IN ($placeholders)"
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

            foreach ($user_ids as $notify_user_id) {
                $stmt = $conn->prepare(
                    "INSERT INTO notifications (user_id, project_id, type, title, message)
                     VALUES (?, ?, 'state_change', 'Project state update', ?)"
                );
                if ($stmt) {
                    $message = 'Project updated via action: ' . $transition['action'];
                    $stmt->bind_param("iis", $notify_user_id, $project_id, $message);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }
    }

    return [true, "Project state updated."];
}

function get_transition_id($conn, $from_state_id, $action)
{
    $stmt = $conn->prepare(
        "SELECT id FROM workflow_transitions
         WHERE scope = 'project' AND from_state_id = ? AND action = ? LIMIT 1"
    );
    $stmt->bind_param("is", $from_state_id, $action);
    $stmt->execute();
    $result = $stmt->get_result();
    $id = null;
    if ($result && $result->num_rows === 1) {
        $row = $result->fetch_assoc();
        $id = (int) $row['id'];
    }
    $stmt->close();
    return $id;
}
