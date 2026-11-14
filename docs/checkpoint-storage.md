# Deployment and repair checkpoint storage

Use `/root/mixpost/backups` as private working space while a deployment, repair,
rehearsal or acceptance check is active. Nightly backups already use encrypted
Hetzner S3/restic; completed deployment checkpoints have a separate lifecycle.

## Preserve local recovery material

Keep active repairs, current and immediately previous recovery bundles, startup
evidence, referenced validators, configuration, and exact rollback images
locally. Check release records, incoming symlinks and container mounts before
considering removal. Directory age alone does not establish that it is unused.

Never prune an unused frozen rollback image without first preserving its exact
image archive and verifying recovery. A Compose snapshot and source commit do
not reconstruct a missing frozen image. See the [current release](current-production-release.md)
for the two image identities verified during this documentation refresh.

## Archive after acceptance and supersession

Use Infra's reviewed `backups/archive-checkpoint.py` and current
`docs/CHECKPOINT-ARCHIVES.md`. The checkpoint tools and runbook are installed
privately under `/root/mixpost/checkpoint-tools`; source is maintained on Infra
branch `codex/mixpost-checkpoint-archive`. Use the reviewed current revision,
including the maintenance workflow authorized November 14, rather than the
initial archive-only commit.

The helper streams the archive to the existing encrypted restic repository,
retrieves the entire archive, verifies byte count and SHA-256, and writes a
private receipt under `/root/mixpost/checkpoint-archives`. It refuses changing
sources and external symlink dependencies. A failed upload, readback, checksum
or receipt leaves the source intact. Keep backup credentials out of docs/logs.

Archives use `checkpoint-archive` and `checkpoint-app-mixpost` tags, plus a
checkpoint-specific tag. Never use `target-mixpost` or another `target-*` tag:
the normal seven-snapshot rotation must not expire these archives. Object-storage
archives have no automatic expiry.

Dan authorized the dedicated server-side maintenance timer on November 14, 2026.
Its configured schedule is daily at 09:00 America/Los_Angeles (up to five minutes
of jitter), independently of Codex/the Mac. At the read-only pre-ship check on
November 14 at 07:44 AM PST (`2026-11-14T15:44:38Z`), systemd reported the timer
loaded but **disabled and inactive**, with no next activation. Authorization and
installed configuration do not establish that scheduled maintenance is running.
This docs release does not enable it. The Infra runbook records the prior local
Codex schedule as paused; that separate automation was not rechecked here.
See Infra's current `docs/CHECKPOINT-ARCHIVES.md`, also installed privately as
`/root/mixpost/checkpoint-tools/CHECKPOINT-ARCHIVES.md`, for the completion command:

```bash
python3 /root/mixpost/checkpoint-tools/checkpoint-maintenance.py complete OLD-CHECKPOINT \
  --acceptance /root/mixpost/backups/OLD-CHECKPOINT/accepted-report.json \
  --replacement /root/mixpost/backups/NEWER-RECOVERY-CHECKPOINT/database.sql \
  --remove database.sql \
  --remove candidate-package/node_modules
```

Replace the example names with reviewed exact paths. The completion command
records eligibility; it does not establish backup consistency or perform a
restore rehearsal.

After acceptance and supersession, the deployment/repair operator must register
exact eligible bulk paths, acceptance evidence and a newer verified full recovery
dump. Unregistered and active work stays local. A 24-hour quiet period, unchanged
source/evidence, dynamic recovery protections and full uncached archive read-back
are required before deletion. The job cannot remove whole directories, row undo
files, repair media or image exports. Current/previous recovery bundles and exact
images remain local. Before reusing a registered checkpoint, add a KEEP-LOCAL
marker to hold it and remove or resolve its eligible completion record before
reactivating the source. Results are in
`/root/mixpost/checkpoint-archives/maintenance-status.json` and the systemd journal;
unknown or failed eligibility checks retain the local data.

The archive helper itself never deletes local files. The separately authorized
maintenance job may remove only the eligible registered bulk paths after its
verification and dependency checks. Other manual removal still needs review and
authorization for the exact local copy. Preserve private receipts and removal
records. This authorization covers only the dedicated checkpoint job, not other
timers, whole-directory cleanup or application deployments.

## Locate and recover older evidence

A historical runbook may name a checkpoint that has since been archived. Check
its private receipt for the exact snapshot, archive path, checksum and any local
removal record. Do not infer snapshot identity from directory age or a partial ID.

Retrieve into private staging with sufficient free space, compare the complete
archive checksum with the receipt, and inspect archive members before extraction.
Follow Infra's retrieval instructions; never extract over production. A verified
archive is storage evidence, not an application/database restore rehearsal.
Database recovery still needs the matching release plan and protection of later
user writes and already-published provider IDs.
