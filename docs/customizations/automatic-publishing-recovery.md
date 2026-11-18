# Automatic Instagram publishing recovery

Deployed and accepted on November 18, 2026, from [PR #21](https://github.com/DucatiXInc/mixpost/pull/21). See the [current production record](../current-production-release.md) for exact image, verification and rollback evidence.

Instagram and Instagram Login publishing recover from explicit, pre-container
rejections. Recovery remains within the existing account publishing job; the
destination stays publishing while it waits. Other destinations are not retried.

* Code 110/subcode 2207018 or code 210/subcode 2207066 may remove only the exact
  requested usernames named in Meta's user-facing response. Generic errors and
  unknown usernames never authorize removal. Changes live in an account-specific
  version; caption mentions, media, original version, other invites and other
  destinations are preserved. A later named rejection can remove another invite.
* Code 9004/subcode 2207052 or -2/subcode 2207003 from container creation may recover
  the rejected cloud asset. The URL must match a selected media original or
  conversion, in the same workspace, on a configured S3 disk. Recovery uses the
  authenticated storage adapter rather than downloading an arbitrary error URL.
  It streams bounded bytes, checks recorded size and available checksum, persists
  a local public copy and private receipt, verifies its entire public SHA-256,
  then promotes only that asset's storage location. Source objects are retained.
* Storage waits use durable state and increasing delays, capped at 30 minutes and
  12 source reads. Restarting a worker preserves the deadline. Failed verification
  cannot promote media or authorize publication. A recovered local copy and receipt
  permit resumption after a crash. Five GiB of free storage remains reserved.
* There is no automatic replay after an accepted container, a lost response,
  `media_publish` error, live post ID or live publication marker. Unknown failures
  retain normal failure handling. The existing container and upload safeguards
  remain required.

The finalizer sends one summary to the existing `socials@ducatix.com` recipient.
The email states which invitations were removed, which media were recovered and
whether publishing completed. Unrecovered final failures still include their
normal explanations. Temporary errors do not send intermediate failure emails.
Queue acceptance and actual inbox receipt must be reported separately.

## Update contract

Preserve the registered `InstagramPublishingRecovery.php`,
`PublishingMediaFallback.php`, `PostPublishingRecoveryNotification.php` and their
integration in `AccountPublishPost.php`, `ManagesInstagramResources.php` and
`FinalizePostPublishing.php`. New helpers are baked into the frozen image, not
injected into a running worker. Preserve public media, private receipts, recovery
state in version options, existing queues, mail configuration and both Compose
files. Do not delete recovered media on worker restart or storage reconciliation.

Every exact candidate must pass the frozen `instagram-publishing-recovery` suite
and source fingerprints. The restored-database rehearsal must exercise sequential
collaborator removal, account/workspace isolation, durable storage retry,
verification failures, promotion, restart recovery, terminal publication guards,
successful finalization and summary email rendering/deduplication. Provider HTTP
and mail are faked in rehearsal; no live test post or test email is implied.

Upstream code can replace this implementation only after passing the same
failure/recovery contract. Release notes alone do not supersede it. Follow the
frozen release playbook, register check, rollback and checkpoint acceptance wrapper.
Rollback retains recovered public files and existing recovery evidence; do not
restore a stale database over later real posts.
