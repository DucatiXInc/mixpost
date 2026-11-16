# Mixpost Pro 7.5.3 update — November 16, 2026

Status: **deployed and accepted** November 16, 2026, 10:39 AM PST
(`2026-11-16T18:39:25Z`). Production runs 7.5.3. See the
[current runtime snapshot](current-production-release.md) for exact identity.

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

- Exact image: `peachy/mixpost-pro:7.5.3-15eab5c-20261116`, immutable ID
  `sha256:fddbe8204282b56b3e39a752d1538f3bf19d39370304cac50113e8215d073046`.
- All 155 frozen checks and all 66 registered source mappings passed. Thirty
  startup/configuration guard tests, 19 register/audit tests and five Bluesky
  applicator tests passed locally. The first CI fixture omitted TTB files;
  PR #19 fixed the fixture while keeping the actual register check strict.
- All 96 database tables restored; 34 isolated suites plus 21 scheduler checks
  passed. Eight owned upload-integration fixture rows were removed, after which
  all 92 business-table fingerprints matched exactly.
- The portable BIGINT importer test passed five checks: both 32-bit boundaries,
  repeated imports, an unchanged sentinel and complete table rollback. The
  schema/all-91-date checker passed before/after both isolated migration commands,
  before live reopening and in final acceptance. Changed-value/missing-date
  evidence each returned nonzero. Its handler is installed before and after
  Laravel bootstrap. No account-level values are committed to Git.
- Both normal migration commands reported nothing to migrate. App-only cutover
  preserved both Compose files, all 53 mounts, environment/networks and the
  MySQL/Redis containers. Schema and all business-table fingerprints matched
  before reopening. The generic/latest updater was not used.
- All 317 served asset bytes matched. Both live storage recovery probes passed.
  Authenticated normal upload, a deliberate network interruption/retry, playback
  and oversize rejection passed; both synthetic media rows and all six objects'
  versions were removed and the original UI/network state restored.
- One version-deletion acknowledgment timed out. Cleanup resumed by inspecting
  the exact owned keys; every owned object/version was confirmed absent before
  removing its media row. No user media was removed.
- The actual final read-only verifier passed 36 checks through
  `checkpoint-maintenance.py operation v753-upgrade-20261116` and returned
  `state: accepted`. All task-owned isolated resources were removed, the
  deployment lock released and recovery material retained.

Private reports: `accepted-report.json`, `browser-proof.json`,
`browser-fixture-cleanup.json`, `candidate/rehearsal/result.json`,
`candidate/rehearsal/facebook-bigint-migration-gates.json`,
`facebook-bigint-failure-paths.json`, `image-recovery-proof.json` and
`cutover/ROLLBACK.txt` under the upgrade evidence root. The real final verifier
is `verify-completed-operation.py`; it executes the versioned read-only BIGINT
checker with explicit flags and private standard input, rather than trusting
an earlier success marker.

The source is recorded in PRs #16–#19. Guard, application tests and asset build
all passed on source merge `1e6e6a6`; acceptance documentation is a separate
commit. Full CI remained independent of merging and production cutover.

No live social post/comment was sent, no manual TTB reconciliation was run,
and no fresh Google OAuth handshake or natural provider publication was
witnessed. Existing authenticated access and the Google login button passed.
Composer also reports an inherited `symfony/filesystem` advisory in the retained
lock; this package-only update does not claim to remediate it.
