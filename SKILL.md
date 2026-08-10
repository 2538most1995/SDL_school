---
name: maintain-sdl-school-laravel
description: Workflow for Laravel, MySQL, legacy DBF import, security and performance work in SDL School.
---

# SDL School Laravel/MySQL workflow

## Workflow

```text
Understand → Read docs → Discover → Trace data flow → Analyze
→ Plan minimal change → Implement → Test → Document → Report
```

## Before changing code

1. Read `AGENTS.md`, `CONTEXT.md`, `DATABASE.md`, `PERFORMANCE.md` and the relevant `laravel-app/README.md`.
2. Inspect `composer.json`, PHP/Laravel version, routes, middleware, controllers, requests/resources, services, domain models, migrations and tests.
3. Check git status and preserve unrelated user changes.
4. Identify affected roles, districts, API contracts, tables and real-data risk.

## Laravel checklist

- Use validation and authorization already defined by routes/middleware.
- Trace controller → service/repository → query → resource/view before refactoring.
- Avoid N+1, query-in-loop, `all()`/`get()` for unbounded data and `SELECT *` when only a subset is needed.
- Prefer `exists`, database aggregates, `withCount`/`withExists`, pagination or chunking when the contract permits.
- Do not add eager loading without proving the relationship is consumed.
- Preserve API payloads, pagination semantics and student business rules.

## MySQL checklist

- Treat migrations and current source as schema truth; never guess a table/column.
- Validate dynamic identifiers before interpolation; bind all values.
- Review `WHERE`, `JOIN`, `ORDER BY`, `GROUP BY`, foreign keys and composite index order together.
- Inspect existing indexes before proposing a new one.
- Use `EXPLAIN`/`EXPLAIN ANALYZE` on sanitized representative data when available.
- Record read/write trade-offs of every index; live plans are `Not verified` if no database is available.

## Legacy import/data checklist

- Keep all reads and writes on the deployment-owned default database; do not add a connection to the former database.
- Preserve district + batch scoping and latest-successful-batch rules.
- Protect ZIP extraction from Zip Slip, symlinks, excessive entries, decompression bombs and oversized files.
- Keep DBF encoding/memo handling and dynamic table identifier validation intact.
- Never run destructive cleanup or migration commands against real data without explicit authorization.

## Security checklist

- Authentication, active-user and role middleware remain in place.
- Check district scope and IDOR for every student/admin write/read.
- Validate request input and uploads; retain CSRF/auth protections.
- Escape HTML, avoid raw user-controlled SQL, and mask sensitive student data by default.
- Do not expose passwords, citizen IDs, phone numbers, addresses, secrets or internal exception details.

## Verification and documentation

Run the narrowest relevant checks first, then:

```bash
cd laravel-app
php artisan test
npm run typecheck
npm run build
./vendor/bin/pint --test app routes config database/migrations database/seeders tests bootstrap/app.php
```

After changes, update the applicable docs. Architecture/business rules → `CONTEXT.md`; schema/migration/relationship → `DATABASE.md`; query/index/cache/performance → `PERFORMANCE.md`; installation/deployment → `README.md`; AI rules → `AGENTS.md`; workflow → `SKILL.md`.

Every report must state problem, root cause, solution, impact, risk, files, tests, benchmark evidence, limitations and next steps. Use `Not verified` instead of guessing.
