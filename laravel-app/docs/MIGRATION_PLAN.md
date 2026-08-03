# SDL School Laravel Migration

## Architecture

- Laravel 13 provides API, authentication, authorization, validation, queues and migrations.
- React 19 and TypeScript provide the student and staff interfaces.
- TanStack Query owns server state. Query keys must include user, role, district, active batch and filters where relevant.
- Laravel Policies enforce role, district, teacher group and student ownership. Hiding a menu is never authorization.
- The legacy MySQL database is read-only during characterization and dual-read comparison.

## Safety invariants

1. District resolution is fail-closed. A table without a registered batch and district is unavailable.
2. Physical `db_import_*` names are resolved only through `raw_import_tables`; clients never submit table names as authority.
3. A new import is staged and validated before it becomes the active batch. Failed imports leave the current active batch untouched.
4. PII, grades and submissions are not persisted in browser storage. Query cache is cleared on logout, role change and district switch.
5. Production serves only `public/`. Legacy debug, test, logs, SQL dumps and uploaded source files stay outside the document root.

## Delivery sequence

| Phase | Scope | Exit criteria |
| --- | --- | --- |
| 0 | Inventory and characterization | Role matrix, route map, schema snapshot and business-rule tests approved |
| 1 | Laravel foundation | Sanctum, policies, district context, API envelope, React shell and CI pass |
| 2 | Import registry | Staging, ZIP validation, DBF reader, active-batch switch and rollback tests pass |
| 3 | Student core | Directory, detail, grades, registration and owner/group/district tests match legacy |
| 4 | Reports | KPCH, moral, graduates, transfers, attendance and term variants match legacy |
| 5 | Learning portal | Assignments, submissions, resources, plans, calendar, schedule and scores pass policy tests |
| 6 | Cutover | Dual-read counts and sampled calculations match, old URLs redirect, rollback rehearsal complete |

## Business rules requiring characterization

- Student identity joins by the last 10 characters after trimming.
- Academic terms accept `68/2`, `2568/2`, `2/2568`, Thai digits and Buddhist or Gregorian years.
- Active student status is primarily derived from the latest grade term because `Expsem` can be stale.
- Transfer meaning differs between the transfer report and registration marker. Preserve both named rules until product owners reconcile them.
- Student login currently uses citizen ID plus student code. This is transitional and must not become a permanent plain-text credential design.
- Empty teacher `assigned_groups` currently means all groups in a district. Product approval is required before changing to deny-by-default.

## Production blockers discovered in the legacy system

- Revoke and rotate the API key committed under `config/student_api_keys.php`.
- Remove all debug and test entry points from the production document root.
- Replace GET district switching with a CSRF-protected mutation.
- Store learning uploads outside public storage and validate MIME, signature and size.
- Add ZIP symlink, special-entry and compression-ratio checks before enabling import.
