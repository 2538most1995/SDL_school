# Student directory history and SQL pagination

Updated 2026-11-16. The main `/students` API uses `PaginatedStudentRepository` when supported by the imported-data repository. Existing general roster and integration interfaces remain available and keep their previous defaults.

The selected district's latest successful batch supplies available terms, newest first. Historical membership requires a GRADE registration in the selected term. The existing inclusion of the two newest student codes is preserved only for the current term. Duplicate physical identities fail closed; the same code at different levels is identified by level + code. Teacher groups match exact current imported codes/names, admins remain in their selected district and students see their own unambiguous identity. A level/term filter cannot bypass an ambiguous student account.

List queries apply role/district/term/search/group/level filters before SQL `LIMIT/OFFSET`. The returned page hydrates only its visible students, with academic and activity reads batched by level. Address/contact/memo/profile fields are deferred to a bounded single-student lookup. Group/level filter options remain available independently of a search or empty result. Sorts and KPCH filters run on SQL metrics before pagination; summary cards cover the entire filtered roster, not just the visible page.

Summary/option caches are separated by source batch, selected term, role, student identity, exact assigned groups and relevant filters. Academic revision keys include imported-score edit versions and are rechecked for every directory call. Terms/options do not depend on the current page. Caches honor `system_data.cache_seconds`; setting it to zero bypasses persistent caching. No student/profile objects or contact data are stored in these new caches.

Academic metrics use only terms through the selected term. Earned credits count each passed/transferred subject once, and pending selected-term subjects contribute to current credits only when that subject has no earned credit. GPAX preserves the established weighting of passing numeric grade rows, with future rows excluded. Activity hours are summed through the selected term. Personal details, learning groups and registration status remain values from the latest imported STUDENT table; they are not reconstructed historical snapshots. The interface explicitly shows this limitation. Exact historical addresses/names/groups require retained source snapshots.

The list keeps term, filters, search and page in its URL. Profile links carry term and level; the back link restores the list context and rejects arbitrary external return URLs. The profile displays cumulative metrics through the selected term and can open that term's grades/KPCH. A term-specific grade dialog labels its average GPA, while the profile/card cumulative metric remains GPAX. Search waits 250 ms before fetching and stale request signals are cancelled. Cache/placeholder keys include district/role so a district change does not display another scope's previous roster.

The main profile API validates optional `term` and `level`. The standard grade/KPCH controllers explicitly opt into the directory lookup; integration academic calls retain the previous lookup and empty-term response contract. Existing imports and schema are not rewritten. Deploy source and production assets together, run `php artisan optimize:clear`, and refresh. No migration or reimport is required solely for this change; historical identities must already exist in the active import.

## Performance evidence

The opt-in test `SDL_DIRECTORY_BENCHMARK=1 php artisan test --filter=test_synthetic_benchmark_when_explicitly_enabled` creates only isolated synthetic SQLite data: 1,500 students, 120,000 grade rows, 25 students per page. See [recorded results](benchmarks/student-directory-2026-11-16.json). The old repository path hydrates the entire roster before slicing; the new normal page hydrates only 25. New summary computation adds a cold-load cost; warm page requests reuse summaries. These workloads also differ in temporal filtering/credit deduplication, so the measurements are not a claim that every query is faster.

SQLite EXPLAIN confirms indexed identity and term-registration lookups; the page still scans/order-merges candidate student identities. No speculative indexes were added. Measurements exclude network/production MySQL effects; native MySQL EXPLAIN, production latency, load capacity and exact production roster counts remain Not verified. Functional tests exercise actual SQL over synthetic imports, including cache invalidation, future results, duplicate subjects/levels, scope rejection and historical detail navigation.

## ITW G identifiers (2026-11-16)

ITW `CARDID` / `_perf_cardid` can contain `G` followed by 12 digits, as well as a
13-digit numeric citizen ID. Previously numeric-only hydration discarded the
prefix and produced `null`, so the paginated list displayed a dash and full
identifiers were unavailable in profiles.
Hydration now preserves valid G identifiers, including leading zeros, and
normalizes a lowercase prefix to uppercase. Invalid G-prefixed values are not
coerced into numeric IDs. Formatted numeric IDs retain their existing behavior.

Authorized directory/profile resources keep the identifier at
`demographics.citizen_id`; masked outputs remain masked, for example
`G-xxxx-xxxxx-xx-2`. Teacher-group/district access checks and numeric-only login
validation retain their existing rules. No SQL, index, schema or real import
data is changed; no reimport is needed. Deploy the backend, clear Laravel/OPcache
as appropriate for the server and refresh the browser.

The registration print payload also preserves `G` in the first of its 13 ID boxes;
numeric IDs retain the same box values. This prevents the shared read-model fix
from passing G codes to a digits-only printer that would discard the prefix.

The user-supplied ZIP was inspected read-only: its primary STUDENT table contains
15 G-prefixed values, all 13 characters (`G` plus 12 digits). No source identities
were logged or committed. Synthetic SQL/API regression tests cover current and
historical lists, search, bounded profiles, non-paginated rosters, masking,
cross-group/district rejection and unchanged numeric login behavior. Production
database output and latency remain Not verified.

Validation: PHP 632 passed with 2 existing skips (634 total), frontend 117 passed,
TypeScript/build and changed-file syntax checks passed. Synthetic browser checks
confirmed G-code display in current/historical lists, profiles and mobile cards.
Full-project Pint retains its existing formatting findings in 9 files; repository
and regression-test formatting checks pass. No formatting cleanup is included.
