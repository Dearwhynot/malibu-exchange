# Logging Policy

## Purpose

Logging is mandatory for all business-critical and admin-visible functionality.
The goal is to provide:
- auditability
- debugging
- operational visibility
- security traceability
- history of important user and system actions

Logging must be designed together with the feature, not added as an afterthought.

---

## Core Rule

Every new module, page, AJAX action, API endpoint, cron task, import, export, callback, integration, or admin action must include logging.

If functionality changes system state, money-related data, user access, permissions, statuses, settings, or external communication, it must be logged.

A task is not considered complete if important actions are not logged.

---

## Where to check errors during 500/502/AJAX failures

For fast diagnostics of PHP, WordPress, database and `admin-ajax.php` failures, check the WordPress debug log first.

- Server log file: `wp-content/debug.log` (`WP_CONTENT_DIR . '/debug.log'`).
- WP admin viewer: `/wp-admin/admin.php?page=debug-log`.
- Viewer implementation: `includes/debug-log-2.php`.
- Viewer menu label: `Debug Log`.
- The viewer supports refresh, search, clearing the file, pagination and emoji severity/source prefixes.
- CRM audit log is separate: `/logs/` and table `crm_audit_log`. It records business actions, but it does not replace `debug.log` for PHP fatal errors.

For AJAX errors, reproduce the action once, open the debug log immediately, and search by:
- `admin-ajax.php`
- the AJAX action name, for example `me_kanyon_rate_check`
- local markers, for example `[rates.ajax]`, `Kanyon`, `[FINTECH]`
- fatal/database markers: `PHP Fatal error`, `Uncaught`, `WordPress database error`, `MySQL`
- the timestamp close to the browser console error

When reporting a 500/502, include the newest relevant log lines with timestamp. Do not paste secrets, tokens, passwords, private keys, cookies or full provider payloads.

---

## What must be logged

### 1. Authentication and access
Always log:
- login success
- login failure
- logout
- password change
- password reset request
- password reset completion
- blocked access
- permission denied
- nonce verification failure
- suspicious auth-related activity

### 2. User management
Always log:
- user creation
- user update
- user deletion
- role change
- status change
- password set/reset by admin
- invitation sent
- invitation accepted
- invitation expired or failed

### 3. Business operations
Always log:
- entity creation
- entity update
- entity deletion
- status changes
- amount changes
- manual overrides
- approval/rejection actions
- assignment/reassignment
- payment-related actions
- payout-related actions
- cancellation
- expiration
- retry actions

### 4. Settings and configuration
Always log:
- settings changes
- system toggles
- provider configuration changes
- feature flag changes
- important template/config edits

### 5. Integrations and callbacks
Always log:
- incoming callback/webhook receipt
- outgoing API request start
- outgoing API result
- provider error
- invalid provider response
- retry attempts
- mapping/parsing failures

### 6. Background and technical jobs
Always log:
- cron start
- cron finish
- import start
- import finish
- export start
- export finish
- sync start
- sync finish
- migration start
- migration finish
- migration failure

### 7. Errors and warnings
Always log:
- handled exceptions
- validation failures
- unexpected states
- external dependency failures
- database write failures
- business rule violations
- partial completion situations

---

## Log levels

Use these levels consistently:

- `debug` — technical details for development or deep diagnostics
- `info` — normal successful important actions
- `warning` — abnormal but non-fatal situations
- `error` — operation failed
- `critical` — serious failure affecting security, money, data integrity, or access

Default recommendation:
- successful business action → `info`
- validation or suspicious issue → `warning`
- failed operation → `error`
- security or critical infrastructure failure → `critical`

---

## Required fields for each log entry

Each log entry should include as many of these fields as applicable:

- `created_at`
- `level`
- `category`
- `event_code`
- `message`
- `actor_user_id`
- `actor_role`
- `target_type`
- `target_id`
- `request_url`
- `request_method`
- `source` (admin, ajax, cron, api, webhook, cli, import, etc.)
- `ip_address` if appropriate
- `entity_snapshot` or short context summary if useful
- `result` or `status`
- `extra` as JSON for structured context

The message should be readable by an admin.
The structured context should help developers diagnose issues.

---

## Category rules

Use stable categories so log filtering stays useful.

Recommended categories:
- `auth`
- `users`
- `orders`
- `payments`
- `payouts`
- `settings`
- `integrations`
- `callbacks`
- `cron`
- `imports`
- `exports`
- `security`
- `system`

Do not invent many overlapping category names.
Prefer consistency over creativity.

---

## Event code rules

Each important action should have a stable event code.

Examples:
- `login_success`
- `login_failed`
- `password_changed`
- `user_created`
- `user_role_changed`
- `order_created`
- `order_status_changed`
- `payment_link_generated`
- `provider_callback_received`
- `provider_callback_failed`
- `cron_sync_started`
- `cron_sync_finished`
- `permission_denied`

Use lowercase snake_case.
Do not rename old event codes without a strong reason.

---

## Sensitive data policy

Never log:
- raw passwords
- password reset tokens
- API secrets
- private keys
- session values
- cookies
- full card/bank credentials
- full passport/document data
- full unmasked personal sensitive data

If a value is sensitive, mask it.
If unsure, do not log it in raw form.

Examples:
- token → masked
- phone → partially masked if needed
- email → may be masked depending on context
- request payload → keep only safe summary fields

---

## Logging design requirement during development

Before implementing any new feature, define:

1. What successful actions should be logged
2. What failures should be logged
3. What category they belong to
4. What target entity is affected
5. What admin filters/searches should expose them

This should be part of task planning, not a post-release fix.

---

## Admin log page requirements

The admin log page should support:

- search
- filters by level
- filters by category
- filters by actor
- filters by target type / target ID
- date range filter
- pagination
- readable detail view for each record

If new categories or event types are added, they should remain compatible with filtering and search.

---

## Module delivery checklist

Before finishing work on any feature, confirm:

- logging added for success cases
- logging added for failure cases
- sensitive data masked
- category and event_code chosen consistently
- records visible in admin log page
- filters/search work with the new records

If this checklist is not satisfied, the task is incomplete.

---

## Minimum expectation for every new feature

At absolute minimum, every new feature must log:
- start or attempt of critical action
- successful completion
- failure with reason
- actor
- affected entity

No business-critical feature should exist without this minimum audit trail.
