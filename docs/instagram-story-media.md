# Instagram Story media for Annex

Status: deployed November 13, 2026, in [PR #10](https://github.com/dtk79/mixpost/pull/10).
The current TTB comment release preserves capture. See the [release record](current-production-release.md)
for image identities and separate test, cutover, storage and browser evidence.

Mixpost's Story import retained metadata and insights but discarded media URLs.
The independent application command `peachy:capture-instagram-stories` captures
available images, video covers and optional videos into durable public storage.
It runs hourly at minute zero in the background with overlap protection, for
active authorized `instagram` and `instagram_standalone` accounts.

## Reader contract

Table `peachy_ig_story_media` has a unique `(account_id, provider_post_id)` key and
stores owning `workspace_id`, provider timestamp, media type, disk, nullable
`image_path`/`video_path`, image metadata, `captured_at` and sanitized `error`.
Scope reads to the workspace and LEFT JOIN imported Stories on both account and
provider media ID. A missing capture must not remove the imported Story.

Paths are relative to the configured imported-media bucket root:

```text
peachy/stories/<workspace uuid>/<account uuid>/<media id>.<ext>
```

Use `image_path` for an image or a video's saved cover. Render a playable video
only when `video_path` is present; use the cover as its poster. A cover-only
capture remains useful when Meta omits the video URL. Keep the platform fallback
when no captured image is available. Refresh cached display data when a later
capture supplies missing components. Preserve existing Feed/Reels rendering.

Annex's existing `mp_readonly` connection was verified to have SELECT on
`mixpost_db.*`, including this table; no grant change was needed. The Mixpost
release did not implement Annex's display integration.

## Capture and retention

The collector validates downloaded bytes, uses bounded requests/streaming and
size caps, saves covers before optional videos, and preserves completed
components when another component fails. It stores no expiring CDN URL or token.
Completed rows skip media downloads. Incomplete captures retry while the Story
is still live and younger than 24 hours; expired uncaptured media cannot be
recovered by this command. Saved rows/objects persist beyond Story expiry.
No automatic pruning or historical backfill was introduced.

| Setting | Default |
| --- | --- |
| `PEACHY_IG_STORIES_ENABLED` | `true` |
| `PEACHY_IG_STORIES_DOWNLOAD_VIDEOS` | `true` |
| `PEACHY_IG_STORIES_MAX_VIDEO_BYTES` | `104857600` |
| `PEACHY_IG_STORIES_MAX_IMAGE_BYTES` | `20971520` |

Initial capture saved four images/covers and one video. Two videos lacked
provider media URLs, retained their covers, and reported
`missing_media_url;missing_video_url`. A partial capture returns failure status
while preserving successful components; distinguish it from token/permission
failure before changing any connection.

## Upgrade and rollback

Preserve the image-baked application command, collector, configuration and
migration, plus registration in the existing application provider. The frozen
gate requires all 28 Story checks and fingerprints of those five application
files. Retain every other release suite and complete asset comparison. Rehearse
the real migration and database cases against isolated restored MySQL, then
verify schedule, reader access and public object bytes separately after cutover.

The merged Story source and tooling are registered in this repository. The
[full release implementation reference](https://github.com/dtk79/mixpost/blob/773cdb51d9b6edafa4b69f6d1cc1ff5639787b96/docs/customizations/instagram-story-media.md)
contains delivery mappings and test details. Obtain the current reviewed
production-matching source before preparing another release.

Rollback restores a matching image/provider/wrapper/gate together. Retain the
additive table and captured objects for Annex; do not drop them or overwrite
later writes during routine image rollback. Retention and disk migration need
a separate plan covering Annex readers.
