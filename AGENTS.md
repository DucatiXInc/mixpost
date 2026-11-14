<!-- BEGIN:shared-release-flow -->
## Release flow

Read the [shared release policy](https://github.com/dtk79/Infra/blob/main/docs/release-flow.md) before shipping. It is the single source of truth for every person and agent, including Codex and Claude, and supersedes older release/CI-gating instructions in this repo.

- **Ship it:** run focused local checks for the changed area, then merge to main immediately as a merge commit and delete the head branch. Rebase a PR more than a day behind main first. Do not wait for GitHub Actions.
- **Deploy now / ship and deploy:** start the explicitly authorized production deploy immediately. CI is never a merge or deploy gate; full CI continues on main in the background.
- A merge or push alone never authorizes production. No push-triggered or scheduled deploys. Build/runtime checks, migrations, backups and deployment locks still apply.
- If a CI alert names your change, investigate, fix forward or propose an explicitly authorized rollback, and tell Dan which. Migrations are not reversed by code rollback.
<!-- END:shared-release-flow -->

# Code discovery

Prefer codebase-memory-mcp graph discovery and snippets. Index the repository
first when it is not indexed. Use text/file searches for config, shell scripts,
error strings, or when the graph is insufficient.

# Frozen production releases

Use `docs/customizations/README.md` as the modification/hardening entry point.
Keep its register, deployment manifest, implementation, and acceptance tests in
sync. Local acceptance, restart recovery, verified cloud delivery, and publishing
readiness are required behavior contracts; include registered image-baked
files in upgrade audits (`--manifest` and `--image-source`), not just mounts; no upstream-adoption waiver applies.

After candidate validation, run `check-customization-register.py --release-gate`
against its evidence; every registered source and startup wrapper must match.

Follow `docs/mixpost-update-playbook.md` and `docs/frozen-release-gates.md`.
Production Pro source differs from this repository's Lite package. Test the
exact frozen candidate and its compiled assets using the release validator.
Do not waive required behavior tests, remove the mounted startup guard, revive
the archived 6.2.0 image, use the generic/latest updater, or mark a safeguard
superseded based only on release notes. An upstream implementation must pass
the same failure/recovery contract on each candidate.

Preserve both effective Compose files, mounted customizations, MySQL, Redis,
credentials, volumes, and networks. Rehearse against isolated restored data;
checkpoint before cutover and retain database-aware rollback evidence. Recreate
only the app. Never use `docker compose down -v`. Report source, CI, rehearsal,
cutover, storage verification, and authenticated browser proof separately.

# Checkpoint storage

Use `/root/mixpost/backups` as temporary working space for deployment and repair
checkpoints. After acceptance and after the checkpoint is superseded, archive it
to the existing encrypted Hetzner S3 restic repository using Infra's reviewed
`backups/archive-checkpoint.py` workflow and checkpoint-archive runbook. The
canonical source is Infra `main` (the initial host job shipped in PR #258).
Require full read-back checksum verification and a private receipt under
`/root/mixpost/checkpoint-archives` before removing an authorized local copy.
Archives use `checkpoint-archive`/`checkpoint-app-mixpost` tags, never
`target-mixpost`, so the nightly seven-snapshot rotation does not expire them.
Keep active repairs, current/previous recovery bundles, startup evidence,
referenced validators and exact rollback images locally. Check incoming symlinks
and mounts before removal; age alone is not proof that a checkpoint is unused.
Never prune an unused frozen rollback image without first preserving its exact
image archive and verifying recovery.

Dan authorized the dedicated Hetzner `mixpost-checkpoint-maintenance.timer` on
2026-11-14. It archives and verifies accepted, superseded checkpoints before
removing only explicitly registered full dumps/build dependencies. At operation
completion, run the actual final read-only acceptance checker through the host's
`/root/mixpost/checkpoint-tools/checkpoint-maintenance.py operation CHECKPOINT
--acceptance REPORT --verify CHECKER ...` wrapper, following Infra's
`docs/CHECKPOINT-ARCHIVES.md` and `docs/checkpoint-storage.md`. Do not report a
deployment/repair complete until it returns `state: accepted`. It automatically
records completed backups and enrolls superseded accepted predecessors; there is
no separate per-backup registration step. The daily job recovers missed queue
writes and flags unregistered checkpoints. If registration fails after acceptance,
repair metadata only; never repeat a cutover or data mutation to fix registration.
Unfinished/unregistered work stays local. Before reactivating old repair work,
add KEEP-LOCAL and resolve any eligible record. Keep current/previous bundles,
exact images/exports, targeted undo files and referenced tools local.
This authorizes only the dedicated checkpoint job, not other timers or app deploys.
