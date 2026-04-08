We are preparing the "migrations" module inside project-kit-rich, which lives inside the Malibu theme root.

Current situation:
- there is an archive:
  project-kit-rich/modules/migrations/archives/malibu-migrations-module-2026-03-25.zip

Goal:
- unpack it
- place files correctly into the module structure
- do NOT rewrite logic
- do NOT improve code
- do NOT refactor anything yet

---

## Tasks

1. Unpack archive:
   project-kit-rich/modules/migrations/archives/malibu-migrations-module-2026-03-25.zip

2. Move contents into:
   project-kit-rich/modules/migrations/extracted/

3. Ensure final structure looks like:

project-kit-rich/modules/migrations/extracted/
  inc/
    migration-runner.php
    migrations/
    seeders/

4. If archive contains extra root folder:
   - flatten structure (no nested unnecessary folder)

5. Do NOT:
   - rename files
   - modify PHP code
   - change logic
   - add new abstractions

6. Only allowed:
   - move files
   - create folders if missing
   - delete unnecessary wrapping folders

---

## After that

7. Ensure docs folder exists:

project-kit-rich/modules/migrations/docs/

8. If missing, create empty files:

- extraction-notes.md
- integration-notes.md
- known-pitfalls.md

(leave them empty, do NOT generate content)

---

## Important rules

- This step is ONLY about structure
- Do NOT attempt to integrate into Malibu
- Do NOT change code behavior
- Do NOT optimize anything

---

## Output

Return:
1. final folder structure
2. list of files placed into extracted/
3. confirm no code was modified
