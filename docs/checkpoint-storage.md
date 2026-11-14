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
of jitter), independently of Codex/the Mac. A live check on November 14 at
08:14 AM PST (`2026-11-14T16:14:29Z`) reported the timer **active**, with its next
activation at 09:01:53 AM PST. The earlier 07:44 AM pre-ship snapshot had found
it disabled; that older observation is superseded for current scheduling status.
Always inspect systemd for current runtime state. The separate local Codex
schedule remains paused.
The current installed reference is Infra's `docs/CHECKPOINT-ARCHIVES.md`, also
available privately at `/root/mixpost/checkpoint-tools/CHECKPOINT-ARCHIVES.md`.

## Automatic completion gate

Use this as the final read-only verification step for **every deployment and
repair that created checkpoints**, after the operation's required checks and
explicit acceptance. Do not report the task complete until it returns
`state: accepted`:

```bash
python3 /root/mixpost/checkpoint-tools/checkpoint-maintenance.py operation CHECKPOINT \
  --acceptance /root/mixpost/backups/CHECKPOINT/accepted-report.json \
  --verify python3 /root/mixpost/backups/CHECKPOINT/verify-completed-operation.py
```

Replace the examples with the operation's actual checkpoint, private report and
read-only acceptance checker. The checker may write its report; it must return
nonzero for any missing/failed required check. Do not substitute a placeholder
success command, or wrap the production mutation/deployment command: a metadata
retry must never repeat a cutover, account write or provider action. The wrapper
cannot replace rehearsal, authenticated browser/provider evidence or required
user acceptance. It never creates a fresh database dump just for housekeeping.

Successful verification automatically discovers supported full dumps and build
dependencies, pins the acceptance/source/recovery evidence, and writes a durable
completion record under `checkpoint-operations`. Each newer accepted recovery
backup automatically enrolls superseded accepted predecessors. MySQL and Postgres
require their own matching newer recovery proofs; the newest accepted checkpoint
waits for a successor. Current/previous recovery bundles, exact images, row undo,
repair media and referenced tools remain protected. There is no separate manual
registration step for each old backup.

The daily server job retries enrollment from saved accepted records if a lock or
interruption prevented it, without rerunning verification or production actions.
A failure before acceptance is recorded returns nonzero. A failure afterward
keeps the accepted record for metadata-only recovery. Never fix registration by
repeating the deployment or repair mutation.

New untracked checkpoints, or growth/additions inside a legacy untracked folder,
get a 24-hour observation grace period and then appear in `missingRegistration`
in `/root/mixpost/checkpoint-archives/maintenance-status.json`; the service fails
visibly while retaining their data. Failed final checks and operations left
active for seven days also require attention. A one-time legacy inventory keeps
existing unaccepted recovery material separate; it does not accept or delete it
and cannot be reset to silence new missed work. Age or quietness never proves
acceptance or permits deletion.

Before reusing an old checkpoint, add KEEP-LOCAL and resolve its lifecycle record.
The cleanup job still requires a 24-hour quiet period, unchanged evidence, dynamic
recovery/dependency checks, a successful full uncached archive read-back and a
private durable receipt before deleting registered bulk paths. Whole directories,
row undo, media, configuration, startup proofs and image exports stay local.

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
