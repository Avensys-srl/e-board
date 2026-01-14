EBOARD Manager
==============

Purpose
-------
EBOARD Manager is a project-centric platform for managing electronics design
projects with role-based workflows, approvals, document control, and traceability.
Projects evolve dynamically based on decisions taken during the lifecycle.

Core concepts
-------------
- Project-first model with evolving phases and requirements
- Decisions enable possibilities which generate mandatory requirements
- Role-driven actions and approvals
- Auditability and traceability across states and documents

Key features (current)
----------------------
- Authentication and role-based access (admin, designer, firmware, tester, supplier, coordinator)
- Project creation and project overview
- Dynamic requirements and phases generated from project decisions
- Phase assignments and due dates with status indicators
- Phase submissions and document requirements
- Project and phase document uploads with configurable storage path/URL
- Notifications (project-level and user-level) with read/unread status
- Workflow transitions for project states with history
- Admin settings for upload base path/URL

Tech stack
----------
- Backend: PHP (procedural, no framework), compatible with PHP 7.2.11
- Environment: XAMPP
- Database: MySQL
- Frontend: custom HTML/CSS (no Bootstrap)
- React: not used

Setup (local)
-------------
1) Import `schema.sql` into MySQL (phpMyAdmin).
2) Update DB credentials in `config/db.php`.
3) Ensure web root points to this folder.
4) Login and create users/projects.

Configuration
-------------
- Upload base path and URL are managed in `settings.php`.
- Default upload folder is `uploads/` (ignored by git).

Main pages
----------
- `login.php` / `register.php`
- `dashboard.php`
- `projects.php`
- `project_view.php`
- `approvals.php`
- `notifications.php`
- `admin_users.php`
- `settings.php`

Notes
-----
- Workflow is dynamic: phases and requirements are generated from decisions.
- Mandatory requirements block approvals until resolved.
- Phase submissions require documents before they can be sent.
