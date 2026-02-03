# EBOARD Manager - Usage, Constraints, and Relationships

This document explains how EBOARD Manager is intended to be used, with a focus on
constraints and how entities relate to each other.

## Core model

- A **Project** is identified by `code` + `version` (unique pair).
- A Project evolves through **phases** and **approvals**.
- **Documents** are uploaded and linked to phases; projects can also define
  optional project-level document requirements.

## Templates and configuration

Configuration is split into three areas:

1) **Document management**
   - Define document types (code + label).
   - Deactivate document types instead of deleting when in use.

2) **Phase management**
   - Define **phase templates** (name, owner role, type).
   - Associate **one or more document types** to a phase template.

3) **Project setup**
   - Define **project types** and their phase sequences.
   - Assign **phase templates** to a project type (order + mandatory flag).
   - Define **phase dependencies** for a project type (only among phases assigned
     to that same project type).
   - Optionally add **project-level document requirements**.

## Project creation and versioning

- Users must enter **Project code** and **Version** explicitly.
- Version is an integer to simplify comparisons.
- Creating a new version can optionally **inherit files** from the previous
  project version (same code).

## Phase rules

- A phase may require **multiple documents** (e.g. Schematic + PCB + BOM).
- Phase completion is blocked until:
  - All **required documents** for that phase are uploaded.
  - All **dependent phases** are completed.
- Document requirements are copied from templates to the project instance when
  phases are generated.

## Dependency rules

- Dependencies are defined at project template level.
- The system prevents:
  - Duplicated dependencies
  - Circular dependencies

## Upload and file handling

- Uploads are project-based and stored under:
  `uploads/projects/{CODE}/{VERSION}/YYYY/MM`.
- Files are soft-deleted into a trash area.
- Only admin can permanently delete files.

## Notifications

- Notifications are generated when phases are assigned or submitted.
- Read/unread status is tracked; unread counts appear on the bell.

## Role-based permissions (summary)

- **Admin**: full access to configuration, users, and project edits.
- **Coordinator**: manage phases, dependencies, and approvals.
- **Other roles**: act on assigned phases and upload required documents.

## Common flows

- **Create templates**: document types -> phase templates -> project type setup.
- **Create project**: define code + version + metadata -> generate phases.
- **Work phases**: upload documents -> submit phase -> approve/reject.
