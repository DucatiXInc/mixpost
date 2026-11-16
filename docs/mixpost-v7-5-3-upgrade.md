# Mixpost Pro 7.5.3 update — November 16, 2026

Status: exact candidate prepared; isolated rehearsal and production cutover pending.
Production remains on 7.5.1 until the acceptance section below is updated.

## Upstream target

The [official Pro changelog](https://mixpost.app/releases/pro) lists 7.5.3,
released November 14, as the latest release checked November 16. The intervening
7.5.2 changes improve analytics charts, demographic sorting and translations.
7.5.3 adds MCP video-cover input and account capability descriptions, validates
REST cover/media membership, and fixes video-cover format and Instagram video
publishing issues. These are upstream claims; release notes do not prove our
customized runtime passes them.

The licensed package resolved to source
`1f67267fc3b222ed31f3d4999e49dee1856b7e58`. Only Mixpost changes in the final
Composer lock; Laravel and other locked dependencies are retained. Lock SHA-256:
`06864cdce0f652b10122c113b1867d200e7c7c21d7575445e9451d521fe1479b`.

Follow the [preservation playbook](mixpost-update-playbook.md) and the vendor's
[update guidance](https://docs.mixpost.app/pro/updating). Our frozen procedure
retains both Compose files and replaces only the app.

## Customization review

Complete pristine-7.5.1/effective-live/pristine-7.5.3 comparison found **82 custom
source files**: 80 retain their existing behavior unchanged; two rebase cleanly.
`InstagramAnalytics.php` incorporates upstream age sorting while preserving our
analytics logic. `PostFormRequest.php` incorporates upstream video-cover rules
while preserving content normalization and scheduling safeguards. No patch was
retired on an upstream-equivalence assumption.

TTB comment sync, Story capture, local upload/recovery, Bluesky publishing,
X media cost controls, the three-hour first-72-hours X refresh, provider retries,
thumbnail reuse, failure notifications, public pages, SSO and storage settings
remain required. The TTB applicator/support classes/tests are now versioned,
the effective scheduler is reconciled, and the startup contract expands from
115 to **155 checks**, fingerprinting all 13 TTB source files.

A first asset mismatch was rejected. The rebuilt complete bundle then passed
byte-identical comparison. Candidate failures remain private evidence; they are
not accepted releases. The guard and regression tests retain all failure cases.

## Concurrent repair and recovery

The separate queue investigation completed the Facebook insights `BIGINT` repair
before this update's fresh backup. Its recovery root is
`/root/mixpost/backups/facebook-insights-bigint-20261116/`. Preserve its corrected
history and widened field. This update's fresh backup and restored rehearsal
start from the accepted post-repair state.

Private upgrade evidence is under
`/root/mixpost/backups/v753-upgrade-20261116/`. Keep the exact old image, wrapper,
mount/configuration snapshots, old gate and database-aware recovery plan locally.
Do not reverse a database repair by restoring an older unrelated checkpoint.

## Acceptance

Source and guard checks, exact-image gate, isolated restore/migrations/provider
tests, locked app-only cutover, storage readback/cleanup, authenticated browser
checks, and checkpoint completion are recorded separately here after execution.
No live social post or comment will be created to test this update.
