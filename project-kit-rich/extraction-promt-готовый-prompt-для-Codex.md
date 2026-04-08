We are creating a new reusable module inside the Malibu project kit.

New module name:
`error-log`

Important:
The project root is the WordPress theme root.
`project-kit-rich/` is inside the theme and is part of the project.

We need to organize the extracted error-log functionality into the standard module structure used in project-kit-rich.

## Goal

Prepare the reusable `error-log` module in a clean, structured way.

This step is ONLY about organizing the module.
Do NOT integrate it into Malibu runtime yet.
Do NOT rewrite architecture.
Do NOT improve logic unless absolutely required for packaging.
Do NOT change current Doverka runtime.

## Required target structure

Create and/or fill this structure:

project-kit-rich/modules/error-log/
├── source/
├── extracted/
├── docs/
│   ├── README.md
│   ├── extraction-notes.md
│   ├── integration-notes.md
│   └── known-pitfalls.md
├── archives/
└── AGENT.md

## Tasks

### 1. Create the module folder structure
If missing, create:
- `project-kit-rich/modules/error-log/source/`
- `project-kit-rich/modules/error-log/extracted/`
- `project-kit-rich/modules/error-log/docs/`
- `project-kit-rich/modules/error-log/archives/`

### 2. Package the extracted reusable module
Take the reusable files you already prepared:
- `inc/error-log.php`
- `viewer-v1.php`
- `viewer-v2.php`
- `README.md`

Package them into a zip archive named:

`error-log-module-YYYY-MM-DD.zip`

Place that archive into:

`project-kit-rich/modules/error-log/archives/`

Use the current date in the filename.

### 3. Unpack the same module into extracted
Place the reusable module contents into:

`project-kit-rich/modules/error-log/extracted/`

Use a clean structure.

Preferred extracted structure:

project-kit-rich/modules/error-log/extracted/
├── inc/
│   ├── error-log.php
│   └── error-log/
│       ├── viewer-v1.php
│       └── viewer-v2.php
└── README.md

If your current prepared files need a minimal folder adjustment to fit this clean structure, do it carefully.
Do NOT redesign the module.

### 4. Create docs skeleton
If missing, create these files in:

`project-kit-rich/modules/error-log/docs/`

- `README.md`
- `extraction-notes.md`
- `integration-notes.md`
- `known-pitfalls.md`

At this step:
- it is OK to put short placeholder content
- do NOT write long essays
- do NOT invent future architecture

### 5. Create module-specific AGENT.md
Create:

`project-kit-rich/modules/error-log/AGENT.md`

It should briefly state:
- this module is responsible only for reusable error-log/debug-log viewing functionality
- do not mix it with business logs
- do not tie it to Doverka-specific pages or CRM tables
- keep it reusable for Malibu and future projects

### 6. Do NOT
- integrate into Malibu theme runtime yet
- require it from functions.php yet
- change Doverka behavior
- mix this with migrations module
- introduce extra frameworks
- invent new UI

## Output

Return:
1. final folder structure of `project-kit-rich/modules/error-log/`
2. archive filename created
3. list of files placed into `extracted/`
4. list of docs files created
5. confirmation that Malibu runtime was NOT modified
6. confirmation that Doverka runtime was NOT modified

After that, provide the mandatory QA block.