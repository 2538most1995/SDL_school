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

## Subject and learning-method axes

All ten reports support `subject` (code + name) and `learning_method` on either axis. Learning method means ITW study mode, not attendance at a meeting: GRADE.METHOD takes precedence over nonempty GRADE.LEARNING, with 1 = พบกลุ่ม, 2 = ทางไกล, 3 = ทางไกลพิเศษ. Unknown codes remain visible; missing values are `ไม่ระบุวิธีเรียน`. Subject/method pairs come from the same selected-term registration, using the existing deterministic duplicate-grade selection policy. They are never combined as an unrelated Cartesian product.

The workspace requests `include_registration_dimensions=1` only when either new axis is selected. Existing endpoint responses retain their default shape; the optional flag adds a minimal `registration_dimensions` list to report rows. The registration-statistics workspace uses its student-view response for these axes and retains existing demographic dimensions. Enrichment rechecks district and exact teacher groups. Transfer rows retain only their transferred subject rather than expanding into other selected-term courses. Report students without selected-term registrations remain explicitly categorized as having no registration in that term.

Every cell, row total, column total and grand total counts unique level + student identities independently. A student in several subjects can appear in several cells while counting once in the row/grand total, so cell counts must not be summed to infer unique students. Total-row/column/student-list dialogs deduplicate the same identities. Excel includes the same counts and a counting-method note. Defaults remain group rows × level columns; custom format settings persist both new keys without migration.
