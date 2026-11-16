# Data loading feedback

`GlobalQueryLoading` fills missing feedback for active React Query reads, including
filter/semester changes, pagination, cached-data refreshes and dependent options.
It renders one small centered ring after 180 ms; requests start immediately.
Inactive prefetches and disabled queries do not activate it. No API calls, query
timings, permission checks or database behavior are changed.

Existing feedback takes priority. The shared `Spinner` registers its lifetime
automatically. Existing dashboard/news/sidebar skeletons register through
`LocalLoadingIndicator` or `useLocalLoadingIndicator`. The global indicator stays
hidden while any registered local indicator or existing mutation overlay is
active. Import-job polling opts out with `meta: { loadingIndicator: 'silent' }`
because its existing progress panel already reports the operation.

For new screens:

- Keep existing skeletons/spinners; do not add another loading animation for the
  same wait. Wrap a custom skeleton in `LocalLoadingIndicator` only while visible.
- Use `useDataLoading(isWaiting)` for user-triggered async operations outside
  React Query. Existing export/attachment/print-document waits use this hook.
- Use `CenteredDataLoading` for a bounded file/image preview that previously
  showed plain waiting text. It registers as local feedback automatically.
- Reserve `loadingIndicator: 'silent'` for background work with existing feedback;
  ordinary data queries need no extra configuration.

Activity cleanup is idempotent and reference counted, so overlapping waits,
navigation and React StrictMode do not clear another indicator or leave loading
stuck. The shared overlay does not intercept clicks or focus, uses theme colors,
announces loading politely, hides during printing and stops rotation for reduced
motion. It clears on success, final error, cancellation or loss of active observers.

Validation: TypeScript, frontend tests (117 passed) and production build passed.
A synthetic localhost API/browser fixture verified existing initial skeleton
suppression, a single indicator on semester changes, exact viewport centering at
1440 × 1000 and 390 × 844, reduced motion, dark-theme contrast, success/error cleanup and navigation
cancellation. These checks use synthetic students and do not access real data.
