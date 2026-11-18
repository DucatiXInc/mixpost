# Mixpost Pro 7.6.0 update — November 18, 2026

Status: **deployed and accepted**. App-only cutover reopened at
`2026-11-18T14:45:55Z` (8:45 AM CST). The actual final verifier passed 38 checks
and the checkpoint wrapper returned `state: accepted`. Exact runtime identity
and recovery instructions are in the [production snapshot](current-production-release.md).

## Target and preservation

The [official Pro changelog](https://mixpost.app/releases/pro) lists 7.6.0 on
November 16, adding frame-based video covers and media visibility/parent fields.
The licensed package resolved to `19b755928f2550f57a2992876a9303bbb884297c`.
Only the Pro package changed in Composer's lock; other dependencies stayed locked.

The baseline was the automatic-publishing-recovery 7.5.3 release deployed earlier
on November 18, rather than the older November 16 image. Complete pristine-old,
effective-live and pristine-target comparison covered 85 customized source files:
82 retained unchanged, three rebased without conflicts. A separate manifest audit
included all 73 bind/image/infrastructure entries and found no removed targets.

The rebased publishing action forwards video covers to subsequent provider
requests. The post request preserves zero timestamps and the new optional-image
and duration rules while retaining our content/recovery behavior. The scheduler
adds upstream frame pruning without dropping custom events. All 69 registered
sources and the unchanged startup wrapper match the final image-bound gate.
No customization was retired on an upstream-adoption assumption.

The frozen image is `peachy/mixpost-pro:7.6.0-4ae6b9b-20261118`, immutable ID
`sha256:e476773d60d442a02aa3409ca0887631737f9dbc0488cdc010e2829bc6554079`.
Its packaged release metadata matches the actual version/source/lock and
customization commit. [PR #23](https://github.com/DucatiXInc/mixpost/pull/23)
merged source and preservation guidance; its three CI workflows passed.

## Validation and cutover

- The first complete build failed asset comparison and was rejected. The final
  rebuilt frontend passed all 177 frozen checks and exact byte comparison.
- A fresh full checkpoint restored all 96 tables into isolated MySQL/Redis on an
  internal-only network, without production storage, cron, Horizon or external
  provider/mail traffic. All 36 regression suites and 21 scheduler checks passed.
- The new compatibility suite checks timestamp bounds/zero, hidden-frame library
  behavior, default visibility and the self-reference deletion contract.
  Automatic recovery, Story/MySQL, TTB, thumbnails, provider retries, content
  normalization, local staging, X read costs/cadence and other suites passed.
- Eight identified integration fixture rows were removed; all 92 original
  business-table fingerprints then matched. Only the two new media columns are
  excluded from that original-column hash; their values were checked separately.
  Schema review permits only those columns, their parent index and self FK.
- The signed BIGINT/all-91-date check passed before and after both migration
  commands on the final exact candidate. Its actual importer passed both 32-bit
  boundaries, repeated imports, an unchanged sentinel and complete rollback.
- Publishing was idle with no post due within 15 minutes. The app entered
  maintenance, cron paused, workers drained and a fresh consistent database and
  complete configuration/mount/gate checkpoint was taken. Only the app changed.
- The candidate stayed inert during initialization/migration. All original data,
  media visibility, approved schema, BIGINT/history, source hashes and protected
  service/configuration state passed before reopening. Both Compose files,
  53 mounts, environment, credentials, networks, MySQL and Redis were preserved.
- All 318 served assets matched. Both app-user live storage probes verified bytes
  and cleanup. Authenticated upload, interrupted transfer/retry and playback
  passed. A 501 MiB selection received HTTP 422 at initiation and transferred no
  video chunks against the 500 MiB limit.

## Cleanup and acceptance

Both 12 MiB originals were byte-verified. The two synthetic rows, six cloud
objects/all versions, exact upload sessions and leases were removed. A strict
integer/string session-ID comparison stopped the first cleanup after object/row
removal. Bounded recovery freshly verified all six exact keys and row absence,
then removed only the matching sessions/leases. The library confirmed no matching
files and browser network conditions were restored. No user media was removed.

All 38 final read-only checks passed. Checkpoint registration initially rejected
an audit symlink into the previous source checkpoint. A self-contained source
copy fixed that metadata dependency; only the read-only final checker and
registration were retried. No second cutover or application data mutation was
performed. The wrapper returned `state: accepted`; task-owned isolated services
were removed and the deployment lock released. Recovery material remains local.

Private evidence root: `/root/mixpost/backups/v760-upgrade-20261118/`.
Key reports are `preservation-audit.json`, `registered-override-audit.json`,
`validated-reviewed/upload-gate.json`, `rehearsal/result.json`,
`bigint-rehearsal/result.json`, `cutover/activation-result.json`,
`browser-proof.json`, `browser-fixture-cleanup.json`, `accepted-report.json`,
`image-recovery-proof.json` and `cutover/ROLLBACK.txt`.

No live social test post/comment/email or fresh Google OAuth handshake was
performed; those were not claimed passed. Existing authenticated sign-in survived.
Current/previous bundles, exact images, database-aware recovery and startup
proofs remain protected under the [checkpoint policy](checkpoint-storage.md).
