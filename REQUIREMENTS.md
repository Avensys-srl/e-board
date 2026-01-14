You are developing a software called "EBOARD Manager".

PURPOSE
The system manages electronics design projects with role-based workflows
and approval processes.

ROLES
- Electronics Designer
- Supplier
- Test Lead
- Project Coordinator

Each role has a dedicated dashboard and specific permissions.

PROJECT STRUCTURE
A project is composed of sequential phases.
Each phase:
- belongs to one main role
- requires submission
- may require approval
- changes project state

STATE MACHINE
Implement a state-based workflow.
Each state defines:
- allowed actions
- allowed roles
- triggered notifications

TECH STACK
- PHP 7.2.11 compatible
- MySQL database
- XAMPP environment
- Bootstrap frontend or custom
- Optional React for dashboards only

VERSION CONTROL
Each project must support:
- versioning
- firmware versions
- approval history
- traceability

IMPORTANT EXCLUSION
Slides with yellow background are NOT software requirements.
They are only real-life examples.
Do NOT implement:
- electronics logic
- seasonal logic
- jumper removal examples
- fool-proof mechanics

GOAL
Focus on a clean, extensible project management platform,
not on electronics-specific behavior.
