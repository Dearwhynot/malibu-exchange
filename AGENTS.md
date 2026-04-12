# AGENTS.md — Malibu Exchange

## Project overview
- WordPress theme for "Malibu Exchange".
- The entire working project lives inside this theme root, including `project-kit-rich/`.
- `project-kit-rich/` is not an external sibling folder; it is part of the theme tree and is deployable with the same SFTP workflow.
- This is a small backoffice project for a Telegram bot related to currency exchange operations.
- The project is intentionally lightweight.
- It is not a React app and should not be turned into one unless explicitly requested.
- Visual direction: premium beach-inspired backoffice with themes of Thailand, Russia, surfing, sea, beach, sun.
- UI mood: clean, calm, polished, operator-friendly.

## Main product idea
- Small internal operator workspace.
- Several simple pages only.
- Main use cases:
  - dashboard
  - orders / exchange requests
  - rates
  - settings
  - logs / service utilities if needed later
- The system should stay fast, understandable and easy to maintain.

## Stack
- WordPress theme
- PHP
- HTML
- CSS
- JavaScript
- jQuery is allowed and preferred for practical UI behavior and AJAX
- Bootstrap-compatible markup is acceptable
- No React / Vue / TypeScript / build pipeline unless explicitly requested

## Architecture rules
1. Keep the project small and easy to reason about.
2. Prefer plain WordPress PHP templates over abstractions.
3. Prefer reusable template parts only where they genuinely simplify code.
4. Do not introduce heavy frameworks or unnecessary dependencies.
5. Do not overengineer.
6. Keep business logic out of templates when practical.
7. Keep CSS overrides and local JS separate from vendor code.
8. Do not edit third-party vendor files directly unless absolutely necessary.
9. Add code in a way that remains deployable by simple file upload.
10. Preserve readability over cleverness.

## Theme structure intent
- Root page templates may include:
  - `page-login.php`
  - `page-dashboard.php`
  - `page-orders.php`
  - `page-rates.php`
  - `page-settings.php`
- When creating or editing a root page template, always add a comment directly below `Template Name` with the exact WordPress page slug to create.
- Preferred format inside the PHP header comment:
  - `Slug: authorization`
  
## Access control
- This is a backoffice theme, not a public marketing site.
- Logged-out users should be redirected to the login page.
- Backoffice pages should require authentication.
- Role-based restrictions may be added later if needed.

## Data / DB usage
- WordPress may be used with:
  - standard WP pages and options
  - custom AJAX handlers
  - direct `$wpdb` queries for custom tables if needed
- Keep DB access explicit and simple.
- Avoid hiding important queries behind unnecessary abstraction layers.

## Custom database table prefix (IMPORTANT)
- All new custom tables in this project use the prefix `crm_`.
- Do NOT use the standard WordPress prefix (`wp_`) for custom tables.
- Do NOT use any other prefix (e.g. `me_`, `mex_`, `malibu_`) for new tables.
- Examples of correct naming:
  - `crm_user_last_login`
  - `crm_organizations`
  - `crm_orders`
  - `crm_rates`
- When writing SQL for new tables, always use `crm_` prefix.
- When referencing custom tables in PHP via `$wpdb`, use the literal `crm_` prefix (not `$wpdb->prefix`).

## UI / UX principles
- Clean operator interface.
- Premium but restrained visual style.
- Readability first.
- Quick actions matter more than decorative animation.
- Forms, status badges, tables and compact dashboards are the priority.
- Avoid visual clutter.
- Avoid giant enterprise-dashboard complexity.

## JavaScript rules
- Prefer jQuery for:
  - AJAX actions
  - filters
  - forms
  - modals
  - quick UI interactions
- Keep scripts small and page-focused.
- Load page-specific scripts only where needed.
- Do not introduce frontend complexity without a clear reason.

## CSS rules
- Keep selectors understandable.
- Avoid specificity wars.
- Put project styling in local theme files such as:
  - `assets/css/app.css`
  - optional page-level CSS if really needed
- If vendor CSS exists, override it in local override files instead of editing vendor files.

## Deployment workflow
- After each meaningful code change, deploy changed files to the test server immediately.
- Preferred command for changed files:
  - `./nodejs_scripts/sftp-deploy.sh <file1> <file2> ...`
- Full theme sync is allowed when needed:
  - `./nodejs_scripts/sftp-deploy.sh`
- Deployment config source:
  - `.vscode/sftp.json`
- Assume target folders must already exist on the server unless known otherwise.

## Collaboration protocol
- Work in small, testable steps.
- Do not jump ahead too far.
- Do not start large refactors without need.
- By default, reply to the user in Russian unless the user explicitly asks for another language.
- After each completed change, always provide this exact QA block:

ОБЯЗАТЕЛЬНОЕ ТЕСТИРОВАНИЕ (сделай сразу):
1) Где открыть
2) Что нажать (пошагово)
3) Ожидаемый результат
4) Что считается провалом

- Do not move to the next task until the user confirms the result.
- If the result is negative, make a focused fix immediately.
- No UI improvisation when fixing a specific bug.

## Good first tasks
1. Finalize the login flow and access protection.
2. Build a clean dashboard page shell.
3. Build the orders page with a practical table layout.
4. Build the rates page with manual save logic.
5. Add settings persistence via WordPress options.
6. Add small reusable UI parts for cards, alerts and tables.
7. Add AJAX actions gradually, only where actually needed.

## Avoid
- React migration
- heavy admin frameworks
- unnecessary build systems
- abstract architecture for a small project
- loading all scripts everywhere
- turning a small operator backoffice into a giant platform

## Telegram callback rules
- Telegram bot callback must be publicly accessible without WordPress login.
- Preferred implementation: custom WordPress REST API endpoint.
- Keep the first version minimal:
  - accept request
  - read raw body
  - decode JSON
  - write payload to a safe debug log or temporary diagnostic mechanism
  - return HTTP 200 response
- Do not place Telegram callback logic inside page templates.
- Do not couple callback processing to front-end rendering.
- Keep callback code isolated in a dedicated include or handler.
- The callback route must not be blocked by forced login logic.
- Design the callback so it can later support:
  - secret token validation
  - update type routing
  - command/message handlers
  - service-layer business logic
- Avoid overengineering in the first implementation.

## UI source of truth (VERY IMPORTANT)

- The project includes a folder:
    `theme source html bootstrap demo/condensed`

- This folder contains the original purchased HTML admin template.
- This is the PRIMARY source of UI, layout and components.

Rules:
1. Always use this folder as the main reference for:
   - layout structure
   - CSS classes
   - components
   - markup patterns

2. Before creating any new UI:
   - search for an existing example in the source folder
   - reuse existing markup whenever possible

3. Do NOT invent new UI styles if an equivalent exists in the template.

4. Do NOT replace the design system with a custom one.

5. Adapt the template to WordPress, not redesign it.

6. Extract only necessary parts:
   - do not copy entire demo pages blindly
   - reuse components carefully

7. Keep vendor styles and scripts separated from custom overrides.

8. The goal is:
   use the template as a UI-kit, not as a static HTML site.

## Multi-organization architecture (VERY IMPORTANT)
- The system must be designed from the beginning for multiple isolated organizations.
- This is not a single-company backoffice.
- Every business entity in the project should be treated as belonging to a specific organization.
- Data of one organization must not leak into another organization.

### Core rule
- All important business data must be organization-scoped by default.

### This includes, but is not limited to:
- bot settings
- API credentials
- login/password pairs for external services
- tokens and secrets
- exchange settings
- rates configuration
- templates and organization-specific texts
- operational settings
- logs, if they are organization-specific

### Storage rule
- Organization-specific settings must be stored in the database as settings belonging to that organization.
- Do not assume one global settings set for the whole project if the data logically belongs to an organization.
- Avoid hardcoding credentials or service settings in theme files or global config when they belong to a specific organization.

### Access / isolation rule
- Code must always be written with organization isolation in mind.
- Queries, settings reads and writes, and business operations should always clearly identify the target organization.
- Do not build logic that implicitly assumes only one organization exists.

### Implementation guidance
- Prefer a simple and explicit architecture.
- Do not overengineer multi-tenancy.
- It is acceptable to start with:
  - an organizations table
  - organization_id references in related data
  - organization-scoped settings storage
- Keep the structure understandable and maintainable.

### Credentials rule
- Logins, passwords, tokens, secrets and similar sensitive integration data must be stored per organization in the database if they belong to that organization.
- Do not scatter organization credentials across code, constants, or unrelated settings.
- Make it easy to retrieve all integration settings for one organization in a consistent way.

### Future-safe rule
- Even if the first release starts with one organization, the code must be written so adding more organizations does not require rewriting the whole architecture.   

## UI source of truth (VERY IMPORTANT)
- The primary UI source is the purchased HTML template in:
  `theme source html bootstrap demo/condensed`
- Always use this folder as the first reference for layout, components, markup and styling decisions.
- Before inventing any new UI, check whether the same component already exists in the source template.
- Adapt the template to WordPress instead of redesigning it.
- Do not mix multiple template variants unless explicitly requested.

## Settings architecture rule
- Do not store organization-specific settings as one global project-wide settings set.
- If a setting belongs to a specific organization, it must be stored and accessed as organization-specific data.

## Sensitive data note
- Organization-specific credentials may need protected storage and careful handling.
- The first implementation may store them in the database in an organization-scoped manner, but the architecture should allow later strengthening of security practices without major rewrites.

## Database migrations rule (IMPORTANT)
- All changes to the database schema (new tables, columns, indexes) must be done via the migration runner, NOT via raw SQL in phpMyAdmin.
- Migration runner: `inc/migration-runner.php` — must be included in `functions.php` (already enabled).
- Migrations live in: `inc/migrations/*.php` — each file returns a PHP array with `key`, `title`, `callback`.
- Naming convention: `NNNN_description.php` (e.g. `0001_create_crm_settings.php`). Use incremental 4-digit prefix.
- The callback runs once automatically on the next page load after deploy. Already-applied migrations are skipped (tracked in `wp_malibu_migration_history`).
- The `inc/sql/` folder is kept for documentation/reference only. The actual schema is always applied through migrations.
- When creating a new table:
  1. Write a migration file in `inc/migrations/`.
  2. Deploy it to the server.
  3. Open any page — migration runs automatically.
  4. Verify in phpMyAdmin that the table exists.

## Settings storage rule (IMPORTANT)
- All persistent system settings must be stored in the `crm_settings` table.
- Do NOT use WordPress `wp_options` for project-specific settings.
- Do NOT hardcode tokens, credentials, or configurable values in theme files or constants.
- Use `crm_get_setting( $key )` to read and `crm_set_setting( $key, $value )` to write.
- Every new setting that an operator may need to configure must be:
  1. Added to `inc/sql/settings.sql` as an `INSERT IGNORE` seed row.
  2. Exposed on the Settings page (`page-settings.php`) in the appropriate section.
  3. Saved via the AJAX handler in `inc/ajax/settings.php`.
- Settings are always scoped to an `org_id`. Use `CRM_DEFAULT_ORG_ID` (= 1) until multi-org is active.

## Page creation rule (IMPORTANT)
- All new backoffice pages must be created by copying an existing page template.
- Preferred base: `page-users.php` (the most complete and up-to-date pattern).
- Fallback base: `page-default.php` for purely blank shells.
- Copy → rename → adjust Template Name, Slug and content only. Do not invent new layout patterns.
- Every page template must have:
  - `Template Name:` and `Slug:` in the PHP header comment.
  - `malibu_exchange_require_login()` guard at the top.
  - Permission check via `crm_user_has_permission()` before rendering any content.
  - The standard header block (logo, profile dropdown, quickview toggle) copied verbatim from the base page.
  - The standard jumbotron breadcrumb block.
  - `get_template_part('template-parts/quickview')` and `get_template_part('template-parts/overlay')` before `get_footer()`.

## Custom tables created so far
| Table           | Purpose                                  | SQL file              |
|-----------------|------------------------------------------|-----------------------|
| `crm_user_last_login`   | Login history per WP user        | inc/users.php SQL comment |
| `crm_roles`             | CRM roles                        | inc/sql/rbac.sql      |
| `crm_permissions`       | CRM permissions                  | inc/sql/rbac.sql      |
| `crm_role_permissions`  | Role → permission mapping        | inc/sql/rbac.sql      |
| `crm_user_roles`        | User → role assignment           | inc/sql/rbac.sql      |
| `crm_user_accounts`     | Extended user profile & status   | inc/sql/rbac.sql      |
| `crm_settings`          | Organization-scoped settings     | inc/sql/settings.sql  |
