# Production runbook

Production runs Mixpost Pro Team in a frozen Docker image. This repository's
Lite package is not the deployed application. See the [current release record](current-production-release.md)
for the verified image and dated acceptance evidence.

## Host and configuration

| Item | Value |
| --- | --- |
| SSH alias | `mixpost-hetzner` (`root@65.109.229.99`) |
| App directory | `/root/mixpost` |
| App container | `mixpost-mixpost-1` |
| Compose files | `docker-compose.yml` and `docker-compose.dashboard-overrides.yml` |
| Public app | `https://mixpost.peachyhq.com/mixpost` |
| Liveness | `https://mixpost.peachyhq.com/api/health` |
| Release identity/evidence | `/root/mixpost/frozen-release.json` |

Preserve both Compose files, all mounted customizations, the startup guard,
MySQL, Redis, credentials, volumes and networks. Never use `docker compose down -v`
or the generic/latest updater. Updates and override changes follow the
[update playbook](mixpost-update-playbook.md) and [frozen release gates](frozen-release-gates.md).
Only the app is recreated after exact-candidate validation and checkpointing.

## Read-only health checks

On the host:

```bash
ssh mixpost-hetzner
cd /root/mixpost
docker compose -f docker-compose.yml -f docker-compose.dashboard-overrides.yml ps
docker compose -f docker-compose.yml -f docker-compose.dashboard-overrides.yml logs --tail=120 mixpost
docker inspect mixpost-mixpost-1 --format '{{.Config.Image}} {{.Image}}'
docker exec mixpost-mixpost-1 sh -lc 'cd /var/www/html && composer show inovector/mixpost-pro-team --no-interaction'
docker exec mixpost-mixpost-1 php artisan horizon:status
docker exec mixpost-mixpost-1 php artisan schedule:list
curl -fsS https://mixpost.peachyhq.com/api/health
```

Compare image identity with the release record. A 200 liveness response does not
prove database, queue, provider, storage or authenticated UI behavior. Review
logs privately; sanitize tokens and personal data before sharing evidence.

Inspect mounted file locations without printing environment values:

```bash
docker inspect mixpost-mixpost-1 --format '{{range .Mounts}}{{println .Source "->" .Destination "writable=" .RW}}{{end}}'
```

The [instance alteration register](mixpost-instance-alterations.md) records intent
and historical mounts. The actual runtime inventory and both effective Compose
files determine what must be preserved. Image-baked application/provider files
also need review; a mount-only inventory is incomplete.

## Queues, broadcasting and scheduler

Required production routing values recorded for this instance:

```dotenv
REVERB_HOST=mixpost.peachyhq.com
REVERB_PORT=443
REVERB_SCHEME=https
REDIS_QUEUE=mixpost-default
```

Port 80 with HTTPS causes broadcasts to reach the wrong router. A missing queue
name can send unnamed jobs to `default` while Horizon consumes `mixpost-default`.
Inspect the effective configuration before diagnosing a stalled queue; do not
clear queues or dispatch publishing commands as a health check.

The current schedule includes:

- Normal publishing scans and the production provider import/metric jobs.
- The low-cost Instagram/Facebook/YouTube analytics callback every 30 minutes.
- Age-tiered X post analytics; review the mounted schedule for current bounds
  and the provider's API usage controls before altering cost-sensitive cadence.
- [Instagram Story media capture](instagram-story-media.md) hourly at minute zero,
  in the background with overlap protection.
- [TTB comment reconciliation](../ops/comment-sync/README.md) every five minutes,
  scoped to account 109/workspace 12, with bounded rotating scans.

The Lite scheduler and older alteration entries are historical references for
production cadence. Use the running `schedule:list` and effective Pro source.
Manual imports, Story capture and reconciliation write data and are operational
actions; use the relevant feature runbook and existing authorization.

## Common investigations

| Symptom | Reference and first check |
| --- | --- |
| Blank page or missing assets | Update playbook: compare served asset paths to the exact image; avoid individual hashed chunk/manifest mounts |
| Interrupted or stuck upload | [Upload recovery](video-upload-recovery.md); inspect startup-gate evidence and current local staging contract |
| Instagram media fetch failure | [Download timeout](instagram-media-download-timeout.md); check public storage URL and stored provider response |
| Missing Story video | [Story capture](instagram-story-media.md); distinguish a saved cover from a missing provider video URL |
| Deleted Instagram comment still visible | [TTB availability](../ops/comment-sync/README.md); saved history persists and fallback can take about an hour |
| Failed Bluesky reply | [Publishing resilience](bluesky-publishing-resilience.md); inspect live IDs before retrying any destination |
| Failure email | [Notifications](post-failure-notifications.md); distinguish relay acceptance from inbox receipt |
| OAuth/account connection | [Instagram OAuth](instagram-standalone-oauth.md); submission history does not prove current Advanced Access |
| Root disk usage/checkpoint lookup | [Checkpoint storage](checkpoint-storage.md); retain current/previous recovery material |

## Google Analytics and media storage

Google Analytics is opt-in through `MIXPOST_GOOGLE_ANALYTICS_ID`. Preserve the
existing configured value and effective layout customization during updates.
The Lite app's analytics implementation is not proof of Pro behavior; verify
rendered tracking and client navigation against the exact deployed application.
Environment changes require configuration regeneration and a reviewed app
recreation; a container restart alone does not apply changed Compose values.

Production's `mixpost:migrate-storage` customization copies database-listed media
paths without S3 prefix listing. Consult [thumbnail storage](mixpost-thumbnail-storage.md)
and review the installed command before a storage migration. Story capture has
its own durable paths/table, which Annex reads; include that contract in any
migration. Retain source objects until target bytes and reader URLs are verified.

## Recovery

Follow the matching release's checkpoint plan and the update playbook. Restore
only reviewed files with their exact image, wrapper and passing gate. Database
migrations may require a matching database restore and reconciliation of later
writes/provider IDs. Never overwrite later successful publishing blindly.
The TTB comment patch needs no schema rollback; preserve all inbox and Story
history. Keep additive Story rows and media when rolling back the capture image.
Archive superseded evidence only through the [verified checkpoint workflow](checkpoint-storage.md).
