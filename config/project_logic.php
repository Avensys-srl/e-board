<?php
function get_project_options($conn, $project_id)
{
    $options = [];
    $stmt = $conn->prepare(
        "SELECT option_key, option_value
         FROM project_options
         WHERE project_id = ?"
    );
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $options[$row['option_key']] = $row['option_value'];
        }
    }
    $stmt->close();
    return $options;
}

function set_project_option($conn, $project_id, $key, $value)
{
    $stmt = $conn->prepare(
        "INSERT INTO project_options (project_id, option_key, option_value)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("iss", $project_id, $key, $value);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function ensure_requirement($conn, $project_id, $key, $label, $source_option_key, $is_mandatory = 1)
{
    $stmt = $conn->prepare(
        "INSERT INTO project_requirements (project_id, requirement_key, label, source_option_key, is_mandatory)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE label = VALUES(label)"
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("isssi", $project_id, $key, $label, $source_option_key, $is_mandatory);
    $stmt->execute();
    $stmt->close();

    $id = null;
    $stmt = $conn->prepare(
        "SELECT id FROM project_requirements WHERE project_id = ? AND requirement_key = ? LIMIT 1"
    );
    $stmt->bind_param("is", $project_id, $key);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows === 1) {
        $row = $result->fetch_assoc();
        $id = (int) $row['id'];
    }
    $stmt->close();

    return $id;
}

function get_next_phase_sequence($conn, $project_id)
{
    $sequence = 1;
    $stmt = $conn->prepare(
        "SELECT MAX(sequence_order) AS max_seq FROM project_phases WHERE project_id = ?"
    );
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows === 1) {
        $row = $result->fetch_assoc();
        if (!empty($row['max_seq'])) {
            $sequence = (int) $row['max_seq'] + 1;
        }
    }
    $stmt->close();
    return $sequence;
}

function ensure_phase_for_requirement($conn, $project_id, $requirement_id, $name, $owner_role)
{
    $stmt = $conn->prepare(
        "SELECT id FROM project_phases WHERE project_id = ? AND requirement_id = ? LIMIT 1"
    );
    $stmt->bind_param("ii", $project_id, $requirement_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows === 1) {
        $stmt->close();
        return;
    }
    $stmt->close();

    $sequence_order = get_next_phase_sequence($conn, $project_id);
    $stmt = $conn->prepare(
        "INSERT INTO project_phases (project_id, requirement_id, name, sequence_order, owner_role, is_mandatory)
         VALUES (?, ?, ?, ?, ?, 1)"
    );
    if ($stmt) {
        $stmt->bind_param("iisis", $project_id, $requirement_id, $name, $sequence_order, $owner_role);
        $stmt->execute();
        $stmt->close();
    }
}

function ensure_requirements_for_options($conn, $project_id, $options)
{
    if (!empty($options['supplier_involved']) && $options['supplier_involved'] === '1') {
        $req_id = ensure_requirement(
            $conn,
            $project_id,
            'supplier_review',
            'Supplier review required',
            'supplier_involved'
        );
        if ($req_id) {
            ensure_phase_for_requirement($conn, $project_id, $req_id, 'Supplier review', 'supplier');
        }
    }

    if (!empty($options['firmware_involved']) && $options['firmware_involved'] === '1') {
        $req_id = ensure_requirement(
            $conn,
            $project_id,
            'firmware_versioning',
            'Firmware versioning required',
            'firmware_involved'
        );
        $req_id = ensure_requirement(
            $conn,
            $project_id,
            'firmware_approval',
            'Firmware approval required',
            'firmware_involved'
        );
        if ($req_id) {
            ensure_phase_for_requirement($conn, $project_id, $req_id, 'Firmware approval', 'firmware');
        }
    }

    if (!empty($options['testing_enabled']) && $options['testing_enabled'] === '1') {
        $req_id = ensure_requirement(
            $conn,
            $project_id,
            'testing_phase',
            'Testing phase required',
            'testing_enabled'
        );
        if ($req_id) {
            ensure_phase_for_requirement($conn, $project_id, $req_id, 'Testing', 'tester');
        }
    }

    if (!empty($options['final_approval_enabled']) && $options['final_approval_enabled'] === '1') {
        $req_id = ensure_requirement(
            $conn,
            $project_id,
            'final_approval',
            'Final approval required',
            'final_approval_enabled'
        );
        if ($req_id) {
            ensure_phase_for_requirement($conn, $project_id, $req_id, 'Final approval', 'coordinator');
        }
    }
}
