# Instagram media download timeout

Incident: October 10, 2026, post `2012` / UUID
`4d175ae6-5e57-455f-a9d6-3fde2678f8aa`, Instagram standalone account
`105` (`ducaticollabs`), media `1474`, post-account row `3426`.

## Confirmed cause and limits of the evidence

Instagram rejected media-container creation with code `-2`, subcode `2207003`
(timeout downloading media). This occurred on the scheduled attempt and the
first manual retry. The response arrived before Mixpost called `media_publish`.
The account connection worked, and the 462,832-byte, 1080 × 1620 JPEG downloaded
intact from the production host and an external client. This was not a video
conversion problem, an expired credential, or an exhausted publishing job.

Production had no explicit public storage URL. Laravel generated:

```text
https://hel1.your-objectstorage.com/ducati-mixpost/<object-key>
```

The authorized recovery used the identical bytes at:

```text
https://ducati-mixpost.hel1.your-objectstorage.com/<object-key>
```

Instagram published ID `17907799041483298`, shortcode `DeUOieNAHys`:
https://www.instagram.com/p/DeUOieNAHys/

The two hosts resolved to the same IPv4 and IPv6 addresses. Later unpublished
media-container probes accepted both URL forms. Therefore the evidence confirms
an intermittent Instagram/storage download failure, but does not establish that
path-style addressing alone caused it or identify the exact network hop.
Hetzner documents the bucket-hostname format for public downloads:
https://docs.hetzner.com/storage/object-storage/overview/

The actionable application defect was treating this specific pre-publication
download rejection as final after one attempt, with no bounded recovery.

## Repair

Set the public URL in `/root/mixpost/.env`:

```dotenv
STORAGE_URL=https://ducati-mixpost.hel1.your-objectstorage.com
```

Keep the existing S3 API endpoint and path-style setting for authenticated
storage operations. This changes generated original/thumbnail/video download
URLs; it does not move objects or change keys or visibility.

`ops/production-patches/instagram-media-download-retry-7.0.4.patch` modifies the
live Pro Team 7.0.4 Instagram resources trait. Media-container creation gets at
most three attempts, with two- and four-second delays, only when Meta returns
code `-2` / subcode `2207003` and no container ID. It covers single posts,
Stories, Reels, and carousel children. It preserves optimized video selection,
collaborators, accepted carousel children, and the existing processing guards.

It does not retry connection exceptions with uncertain outcomes, other provider
errors, accepted containers, carousel parent creation, or `media_publish`.
Retries log only the account ID, attempt, hostname, error subcode, and provider
trace ID. Credentials, captions, object paths, and signed URL queries are omitted.

Apply the patch to a copy of the exact deployed package, not an older local
override. Preserve the existing read-only `ManagesInstagramResources.php` mount.
For future upgrades, rebase this patch against the new upstream trait and retain
`STORAGE_URL` in the production environment.

## Verification and rollback

The 12 HTTP-faked checks in
`ops/production-overrides/tests/InstagramMediaDownloadRetryTest.php` validate
bounded recovery/exhaustion, unchanged payloads, sanitized diagnostics, no
replay of ambiguous outcomes or final publication, and Story/Reel/carousel
behavior including resumed children. The unpatched deployed trait fails the
timeout-recovery check; the candidate passes all checks. Run against the Pro
runtime:

```sh
INSTAGRAM_RESOURCES_PATH=/path/to/candidate/ManagesInstagramResources.php \
  php /path/to/InstagramMediaDownloadRetryTest.php
```

Backup: `/root/mixpost/backups/instagram-media-timeout-20261010/` contains the
pre-change environment, both Compose files, mounted trait, frozen release record, runtime
mount manifest, and database dump. No pending migrations were present. Install
the tested mounted trait and recreate only the application using the unchanged
frozen image. Verify the generated original and conversion URLs, public HTTP
downloads, Horizon, login, health, and mount hashes. A media-preparation probe
may verify Instagram access without publishing another post.

The production runtime uses both `docker-compose.yml` and
`docker-compose.dashboard-overrides.yml`. The base file alone omits 11 existing
mounts. During verification the first recreation exposed this mismatch; the
complete original mount set was restored. Production `.env` now also sets
`COMPOSE_FILE=docker-compose.yml:docker-compose.dashboard-overrides.yml` so the
default Compose command retains those customizations. Compare the full effective
mount set with the original runtime before any later recreation.

Live checks confirmed the unchanged frozen image, all 52 original mounts, the
tested override hash, eight successful original/thumbnail/video HTTP checks,
and an accepted Instagram media-preparation container. The live regression suite
passed all 12 cases. Login, health, and Horizon passed after recreation.

Baseline trait SHA-256:
`9f42301136577301d42cdf0fa4e17ca07d032c1929bbf092499f1a02e95dca29`.
Deployed trait SHA-256:
`c01bcfa7bf8e8ababb7653e72eb8c7a545a131b13dd1e3ddff62db1d4c312484`.

Rollback restores the saved `.env` and trait and recreates only the application.
Explicitly pass both `-f docker-compose.yml -f docker-compose.dashboard-overrides.yml`
when restoring the earlier environment, which did not include `COMPOSE_FILE`.
This repair does not require a database restore because it changes no schema or
stored media/post records. The already published recovery remains live.
