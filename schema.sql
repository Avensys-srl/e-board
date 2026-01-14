CREATE DATABASE IF NOT EXISTS eboard_manager
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE eboard_manager;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(100) NOT NULL,
  email VARCHAR(191) NULL,
  password VARCHAR(255) NOT NULL,
  role VARCHAR(50) NOT NULL DEFAULT 'user',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_settings (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  setting_key VARCHAR(100) NOT NULL,
  setting_value TEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_app_settings_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workflow_states (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  scope VARCHAR(30) NOT NULL,
  code VARCHAR(50) NOT NULL,
  label VARCHAR(100) NOT NULL,
  is_terminal TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_workflow_state_code (scope, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workflow_transitions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  scope VARCHAR(30) NOT NULL,
  from_state_id INT UNSIGNED NOT NULL,
  to_state_id INT UNSIGNED NOT NULL,
  action VARCHAR(100) NOT NULL,
  allowed_roles VARCHAR(255) NOT NULL,
  notify_roles VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_workflow_transitions_scope (scope),
  KEY idx_workflow_transitions_from (from_state_id),
  KEY idx_workflow_transitions_to (to_state_id),
  CONSTRAINT fk_workflow_transitions_from
    FOREIGN KEY (from_state_id) REFERENCES workflow_states (id)
    ON DELETE CASCADE,
  CONSTRAINT fk_workflow_transitions_to
    FOREIGN KEY (to_state_id) REFERENCES workflow_states (id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS projects (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(50) NOT NULL,
  name VARCHAR(150) NOT NULL,
  project_type VARCHAR(100) NULL,
  owner_id INT UNSIGNED NULL,
  start_date DATE NULL,
  description TEXT NULL,
  current_state_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_projects_code (code),
  KEY idx_projects_state (current_state_id),
  KEY idx_projects_owner (owner_id),
  KEY idx_projects_created_by (created_by),
  CONSTRAINT fk_projects_state
    FOREIGN KEY (current_state_id) REFERENCES workflow_states (id)
    ON DELETE SET NULL,
  CONSTRAINT fk_projects_owner
    FOREIGN KEY (owner_id) REFERENCES users (id)
    ON DELETE SET NULL,
  CONSTRAINT fk_projects_created_by
    FOREIGN KEY (created_by) REFERENCES users (id)
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_options (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id INT UNSIGNED NOT NULL,
  option_key VARCHAR(50) NOT NULL,
  option_value VARCHAR(50) NOT NULL DEFAULT '0',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_project_options (project_id, option_key),
  KEY idx_project_options_project (project_id),
  CONSTRAINT fk_project_options_project
    FOREIGN KEY (project_id) REFERENCES projects (id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_requirements (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id INT UNSIGNED NOT NULL,
  requirement_key VARCHAR(80) NOT NULL,
  label VARCHAR(150) NOT NULL,
  source_option_key VARCHAR(50) NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'pending',
  is_mandatory TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_project_requirements (project_id, requirement_key),
  KEY idx_project_requirements_project (project_id),
  CONSTRAINT fk_project_requirements_project
    FOREIGN KEY (project_id) REFERENCES projects (id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_members (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  role VARCHAR(50) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_project_members (project_id, user_id, role),
  KEY idx_project_members_project (project_id),
  KEY idx_project_members_user (user_id),
  CONSTRAINT fk_project_members_project
    FOREIGN KEY (project_id) REFERENCES projects (id)
    ON DELETE CASCADE,
  CONSTRAINT fk_project_members_user
    FOREIGN KEY (user_id) REFERENCES users (id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_phases (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id INT UNSIGNED NOT NULL,
  requirement_id INT UNSIGNED NULL,
  name VARCHAR(150) NOT NULL,
  sequence_order INT UNSIGNED NOT NULL,
  owner_role VARCHAR(50) NOT NULL,
  current_state_id INT UNSIGNED NULL,
  due_date DATE NULL,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  is_mandatory TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_project_phases (project_id, sequence_order),
  KEY idx_project_phases_project (project_id),
  KEY idx_project_phases_requirement (requirement_id),
  KEY idx_project_phases_state (current_state_id),
  CONSTRAINT fk_project_phases_project
    FOREIGN KEY (project_id) REFERENCES projects (id)
    ON DELETE CASCADE,
  CONSTRAINT fk_project_phases_requirement
    FOREIGN KEY (requirement_id) REFERENCES project_requirements (id)
    ON DELETE SET NULL,
  CONSTRAINT fk_project_phases_state
    FOREIGN KEY (current_state_id) REFERENCES workflow_states (id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS phase_submissions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  phase_id INT UNSIGNED NOT NULL,
  submitted_by INT UNSIGNED NOT NULL,
  submission_note TEXT NULL,
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status VARCHAR(30) NOT NULL DEFAULT 'pending',
  reviewed_by INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  review_note TEXT NULL,
  PRIMARY KEY (id),
  KEY idx_phase_submissions_phase (phase_id),
  KEY idx_phase_submissions_submitted_by (submitted_by),
  KEY idx_phase_submissions_reviewed_by (reviewed_by),
  CONSTRAINT fk_phase_submissions_phase
    FOREIGN KEY (phase_id) REFERENCES project_phases (id)
    ON DELETE CASCADE,
  CONSTRAINT fk_phase_submissions_submitted_by
    FOREIGN KEY (submitted_by) REFERENCES users (id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_phase_submissions_reviewed_by
    FOREIGN KEY (reviewed_by) REFERENCES users (id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS approvals (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id INT UNSIGNED NULL,
  phase_id INT UNSIGNED NULL,
  requested_by INT UNSIGNED NOT NULL,
  role_required VARCHAR(50) NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'pending',
  decided_by INT UNSIGNED NULL,
  decided_at DATETIME NULL,
  decision_note TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_approvals_project (project_id),
  KEY idx_approvals_phase (phase_id),
  KEY idx_approvals_requested_by (requested_by),
  KEY idx_approvals_decided_by (decided_by),
  CONSTRAINT fk_approvals_project
    FOREIGN KEY (project_id) REFERENCES projects (id)
    ON DELETE CASCADE,
  CONSTRAINT fk_approvals_phase
    FOREIGN KEY (phase_id) REFERENCES project_phases (id)
    ON DELETE CASCADE,
  CONSTRAINT fk_approvals_requested_by
    FOREIGN KEY (requested_by) REFERENCES users (id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_approvals_decided_by
    FOREIGN KEY (decided_by) REFERENCES users (id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_versions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id INT UNSIGNED NOT NULL,
  parent_version_id INT UNSIGNED NULL,
  version_label VARCHAR(50) NOT NULL,
  description TEXT NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  is_current TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_project_versions (project_id, version_label),
  KEY idx_project_versions_project (project_id),
  KEY idx_project_versions_parent (parent_version_id),
  KEY idx_project_versions_created_by (created_by),
  CONSTRAINT fk_project_versions_project
    FOREIGN KEY (project_id) REFERENCES projects (id)
    ON DELETE CASCADE,
  CONSTRAINT fk_project_versions_parent
    FOREIGN KEY (parent_version_id) REFERENCES project_versions (id)
    ON DELETE SET NULL,
  CONSTRAINT fk_project_versions_created_by
    FOREIGN KEY (created_by) REFERENCES users (id)
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS firmware_versions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id INT UNSIGNED NOT NULL,
  version_label VARCHAR(50) NOT NULL,
  description TEXT NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  checksum VARCHAR(64) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_firmware_versions (project_id, version_label),
  KEY idx_firmware_versions_project (project_id),
  KEY idx_firmware_versions_created_by (created_by),
  CONSTRAINT fk_firmware_versions_project
    FOREIGN KEY (project_id) REFERENCES projects (id)
    ON DELETE CASCADE,
  CONSTRAINT fk_firmware_versions_created_by
    FOREIGN KEY (created_by) REFERENCES users (id)
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attachments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id INT UNSIGNED NULL,
  phase_id INT UNSIGNED NULL,
  project_version_id INT UNSIGNED NULL,
  firmware_version_id INT UNSIGNED NULL,
  uploaded_by INT UNSIGNED NOT NULL,
  storage_type VARCHAR(20) NOT NULL DEFAULT 'local',
  storage_path VARCHAR(255) NULL,
  storage_url VARCHAR(255) NULL,
  original_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(100) NULL,
  size_bytes BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_attachments_project (project_id),
  KEY idx_attachments_phase (phase_id),
  KEY idx_attachments_project_version (project_version_id),
  KEY idx_attachments_firmware_version (firmware_version_id),
  KEY idx_attachments_uploaded_by (uploaded_by),
  CONSTRAINT fk_attachments_project
    FOREIGN KEY (project_id) REFERENCES projects (id)
    ON DELETE CASCADE,
  CONSTRAINT fk_attachments_phase
    FOREIGN KEY (phase_id) REFERENCES project_phases (id)
    ON DELETE CASCADE,
  CONSTRAINT fk_attachments_project_version
    FOREIGN KEY (project_version_id) REFERENCES project_versions (id)
    ON DELETE CASCADE,
  CONSTRAINT fk_attachments_firmware_version
    FOREIGN KEY (firmware_version_id) REFERENCES firmware_versions (id)
    ON DELETE CASCADE,
  CONSTRAINT fk_attachments_uploaded_by
    FOREIGN KEY (uploaded_by) REFERENCES users (id)
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  project_id INT UNSIGNED NULL,
  phase_id INT UNSIGNED NULL,
  type VARCHAR(50) NOT NULL,
  title VARCHAR(150) NOT NULL,
  message TEXT NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notifications_user (user_id),
  KEY idx_notifications_project (project_id),
  KEY idx_notifications_phase (phase_id),
  CONSTRAINT fk_notifications_user
    FOREIGN KEY (user_id) REFERENCES users (id)
    ON DELETE CASCADE,
  CONSTRAINT fk_notifications_project
    FOREIGN KEY (project_id) REFERENCES projects (id)
    ON DELETE SET NULL,
  CONSTRAINT fk_notifications_phase
    FOREIGN KEY (phase_id) REFERENCES project_phases (id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NULL,
  project_id INT UNSIGNED NULL,
  phase_id INT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  details TEXT NULL,
  ip_address VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_logs_user (user_id),
  KEY idx_audit_logs_project (project_id),
  KEY idx_audit_logs_phase (phase_id),
  CONSTRAINT fk_audit_logs_user
    FOREIGN KEY (user_id) REFERENCES users (id)
    ON DELETE SET NULL,
  CONSTRAINT fk_audit_logs_project
    FOREIGN KEY (project_id) REFERENCES projects (id)
    ON DELETE SET NULL,
  CONSTRAINT fk_audit_logs_phase
    FOREIGN KEY (phase_id) REFERENCES project_phases (id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_state_history (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id INT UNSIGNED NOT NULL,
  from_state_id INT UNSIGNED NULL,
  to_state_id INT UNSIGNED NOT NULL,
  action VARCHAR(100) NOT NULL,
  acted_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_project_state_history_project (project_id),
  KEY idx_project_state_history_from (from_state_id),
  KEY idx_project_state_history_to (to_state_id),
  KEY idx_project_state_history_actor (acted_by),
  CONSTRAINT fk_project_state_history_project
    FOREIGN KEY (project_id) REFERENCES projects (id)
    ON DELETE CASCADE,
  CONSTRAINT fk_project_state_history_from
    FOREIGN KEY (from_state_id) REFERENCES workflow_states (id)
    ON DELETE SET NULL,
  CONSTRAINT fk_project_state_history_to
    FOREIGN KEY (to_state_id) REFERENCES workflow_states (id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_project_state_history_actor
    FOREIGN KEY (acted_by) REFERENCES users (id)
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings (setting_key, setting_value)
VALUES
  ('upload_base_path', 'uploads'),
  ('upload_base_url', '')
ON DUPLICATE KEY UPDATE
  setting_value = VALUES(setting_value);
