# Statistics workspace

Updated 2026-11-15. `/reports/overview` offers ten reports. Reports 6–10 use `GET /api/v1/reports/statistics/{kind}` with the existing authenticated teacher/admin/super-admin district context. Students cannot access these reports. The latest successful district import and exact assigned teacher groups constrain every source query. Neither query-supplied district IDs nor student IDs expand access.

| Report | Source key | Counting rule |
| --- | --- | --- |
| Gender | `gender-statistics` | Unique registered level + student code for the selected term, split by imported gender; missing/unknown values remain visible |
| Age ranges | `age-statistics` | The same registered population, split by imported ITW age and custom inclusive ranges |
| Exam disqualified | `exam-disqualified` | At least one selected-term grade/status exactly `ม` or `มส`; numeric zero and past-term disqualification do not qualify |
| N-NET | `nnet-statistics` | Registered population split into `สอบแล้ว` and `ยังไม่ได้สอบ` using ITW N-NET/E-Exam evidence |
| Credits complete, pending requirements | `credit-pending` | Earned compulsory and elective credits independently complete, but KPCH below 200 hours or no N-NET/E-Exam evidence; split into KPCH missing, N-NET missing, or both |

New/unsaved preferences default to group rows and education-level columns. Saved custom preferences remain available. The format dialog's default button restores group/level axes before saving. New reports append their breakdown dimension to the columns when it is absent from both configured axes. Clicking cells and totals opens the underlying student list with report details; print and Excel use the same cross-tab.

`age_ranges` is comma-, semicolon- or newline-separated text, for example `0-15, 16-24, 25-39`. At most 20 ranges may cover ages 0–120. Reversed, malformed or overlapping ranges return HTTP 422. Ranges include both boundaries. Uncovered ages and unknown ages have separate columns; students are not silently removed. ITW age is an imported snapshot, not a reconstructed age at the historical term.

Credit requirements (compulsory/elective) are 36/12 for primary, 40/16 for lower secondary and 44/32 for upper secondary. Only passed/transfer subjects through the selected term count, once per subject; pending selected-term credits and future grades do not count. KPCH activity hours are summed through the selected term. A missing activity table produces an actionable 422 instead of assuming zero hours.

N-NET/E-Exam evidence includes valid exam terms through the selected term, positive imported scores, explicit taken/pass flags and positive exam-subject grades through the selected term. Known future exam terms/results are excluded. Imported score/status fields without reliable dates describe the latest source snapshot and cannot establish an exact historical completion date. `ยังไม่ได้สอบ` means no qualifying evidence in the inspected source, not an independent confirmation from the testing agency. This report does not join the separate web-entered N-NET registry.

Responses project only report identities, names, group/level and relevant classifications/metrics. They exclude citizen IDs, contact/profile data and raw source exam fields. No schema migration or reimport is required for these report additions when a complete ITW batch already exists. Deploy backend and bundled production assets, then run `php artisan optimize:clear`. Production database changes, live MySQL performance and production UI totals have not been verified during development; SQL/browser checks use synthetic fixtures.
