# Move Mixpost to posting.ducatix.com

Prepared September 30, 2026 (America/Los_Angeles). Status: discovery and migration plan; no production, DNS, or provider settings changed.

The target is `https://posting.ducatix.com`, replacing `https://mixpost.peachyhq.com` on the existing server. This is a domain migration of the existing installation. The database, Redis, media bucket, account records, and scheduled posts can stay in place. Preserve the currently configured `/mixpost` route prefix for this change. The root address can continue serving the existing landing page; making it open the dashboard directly is a separate optional navigation change.

## Verified current state

These findings came from read-only inspection of the running container, host configuration, database counts, public responses, Cloudflare, and Google Cloud. Recheck them immediately before implementation.

| Area | Observed state |
| --- | --- |
| Production | `mixpost-hetzner`, `/root/mixpost/docker-compose.yml`, container `mixpost-mixpost-1` |
| Current image | `peachy/mixpost-pro:7.0.2-4f2ea40`; preserve this exact image/digest during the domain change |
| Current DNS | `mixpost.peachyhq.com` resolves directly to `65.109.229.99`, TTL 300 |
| New DNS | No `posting` record in Cloudflare's `ducatix.com` zone; public A/AAAA/CNAME lookups returned no answer |
| Cloudflare | `ducatix.com` uses Full (strict); active Universal SSL covers `*.ducatix.com` and `ducatix.com` |
| Routing | Traefik v3.7.0, `traefik_proxy` network, app service port 80; current Docker router matches only the old hostname |
| Origin certificates | Traefik's `letsencrypt` resolver uses an HTTP challenge on port 80; global HTTP-to-HTTPS redirect already exists |
| Dynamic configuration | `/opt/traefik/conf` is watched, allowing a new hostname to be routed before the canonical URL changes |
| App URL | Host `.env` has `APP_DOMAIN=mixpost.peachyhq.com` and `APP_URL=https://${APP_DOMAIN}`; running config resolves to the old HTTPS URL |
| Routes | `MIXPOST_CORE_PATH=mixpost`; SSO, social callbacks, inbox webhooks, uninstall callbacks, public pages, API and Bluesky metadata routes exist |
| Sessions | Redis; cookie `mixpost_session`; domain unset (host-only); SameSite lax |
| Health | Old `/api/health` and `/mixpost/login` return 200 |
| Media | All 1,161 media rows use S3, bucket `ducati-mixpost`, endpoint `https://hel1.your-objectstorage.com`; no custom S3 URL configured |
| Stored old URLs | Zero old-host matches in inspected media path/data/conversions, account media/data, post-version content/options, config payloads, and setting payloads |
| Bucket CORS | Read returned `NoSuchCORSConfiguration`; current browser uploads go through the application. Do not introduce direct browser-to-S3 uploads as part of this move |
| Other consumers | Production Annex `.env` contains `MIXPOST_APP_URL='https://mixpost.peachyhq.com'`; Infra registry, backup inventory, dashboard fallback, and Reporting defaults also reference it |
| API usage | One API token record exists; identify its consumer without rotating or exposing the token |
| Outgoing webhooks | Zero `mixpost_webhooks` records; this does not mean incoming provider webhooks are absent |
| Public pages | Published `home`, `terms`, `privacy`, `data-deletion`; host-mounted landing template hardcodes the old terms/privacy URLs |

Connected account counts: X 10; Bluesky 6; Instagram standalone 5; Facebook-linked Instagram 2; YouTube 2; Facebook Page 1; Threads 1. These are record counts, not proof that every connection is currently healthy. TikTok and Adobe Express services are enabled; no TikTok account records were found. Facebook and Instagram service webhook switches are enabled. The Threads configuration has no stored webhook verify token, so confirm its actual portal setup rather than assuming Engagement is active.

## 1. DNS, Cloudflare and HTTPS

Create an A record in the existing Cloudflare zone:

```text
Type: A
Name: posting
Content: 65.109.229.99
```

Recommended sequence: use DNS-only with TTL 300 for initial origin routing and certificate validation, matching the current Mixpost request path. Once the origin certificate and app work, enable Cloudflare proxying if desired, using the existing Full (strict) mode. The active wildcard edge certificate already covers the new subdomain, but it does not replace the origin certificate.

If proxying is part of the final change, inspect inherited Workers routes, redirects, Access policies, cache rules, WAF/challenges, request-size limits and timeouts first. OAuth callbacks, API requests, webhook handshakes/deliveries, media fetches and Bluesky metadata must remain reachable by their intended clients without an interactive challenge. Bypass caching for authenticated pages, login/callback responses and API responses. Verify the current chunked video-upload flow through the proxy. Preserve the existing application authentication and provider signature validation.

Keep the old DNS record pointing to the existing server. DNS cannot perform an HTTP redirect. Both domains need valid HTTPS throughout the transition: the old site currently sends a long-lived HSTS header, so an HTTP-only redirect would fail for returning users.

## 2. Traefik and application configuration

Add a new HTTPS router matching `posting.ducatix.com` to the existing `mixpost@docker` service, with the existing security-header middleware and `letsencrypt` resolver. Use the watched file provider to establish routing first if that avoids an early app restart. Make router priorities explicit when redirect and compatibility rules overlap the existing old-host Docker router.

At cutover, update `/root/mixpost/.env`:

```dotenv
APP_DOMAIN=posting.ducatix.com
APP_URL=https://${APP_DOMAIN}
MIXPOST_CORE_PATH=mixpost
```

Read back the resolved Compose environment and running Laravel config, not just the edited text. Ensure no Compose environment override wins over `.env`. Keep the session domain host-only; do not widen it to `.ducatix.com`. Verify secure cookies on the new HTTPS host.

Back up `.env`, Compose, current image identity, override manifest/files, dynamic router configuration and current release state. Recreate only the `mixpost` service using the pinned current image, without pulling another version or restarting MySQL/Redis. The current image startup clears/rebuilds Laravel configuration and routes, starts workers, and also runs migration/upgrade commands. Preflight those commands against the current release so a domain-only restart does not accidentally become a version upgrade. Verify the queue, scheduler and all existing read-only override mounts afterward.

Update managed routing configuration so a later deploy preserves the new hostname and compatibility rules. A global Traefik restart is not required for a watched dynamic configuration update.

Environment roles are documented in [Mixpost's environment reference](https://docs.mixpost.app/pro/configuration/environment-variables/). Current production's explicit `/mixpost` prefix is authoritative even though newer installations can default to root routes.

## 3. Google login: separate from YouTube

The live SSO form uses Generic OIDC, issuer `https://accounts.google.com`, scopes `openid profile email`, password login enabled, verified email required and existing-users-only provisioning. Keep those settings and existing credentials during the move.

The Google Cloud project is `gen-lang-client-0534053021` (display name Mixpost), with client **Peachy Mixpost SSO**:

```text
10001406011-7bstdlpkmvkdp8dqqh3aje44b8v6crvq.apps.googleusercontent.com
```

Its only current redirect URI is the old SSO address, JavaScript origins are empty, and its only authorized domain is `peachyhq.com`. Add `ducatix.com` and this exact URI before switching the app:

```text
https://posting.ducatix.com/mixpost/auth/sso/callback
```

Retain the old redirect URI for rollback/in-flight flows. There is no need to invent a JavaScript origin for this server-side OIDC flow; add the new origin only if another actual browser flow requires it. Google requires exact redirect URI matching. [Google OAuth documentation](https://developers.google.com/identity/protocols/oauth2/web-server), [Mixpost SSO documentation](https://docs.mixpost.app/services/sso/).

**Audience check:** the SSO project is External / Testing, with only `dan@ducatix.com` listed as a test user. Its homepage/privacy/terms fields are empty and Publish is disabled. However, Google explicitly exempts requests limited to basic name/email/profile/OpenID scopes from Testing's trusted-user-list and seven-day-expiry restrictions. The configured `openid profile email` scopes fit that exception; Testing alone is therefore not a proven team-login blocker. Verify the actual requested scopes, Workspace policy and another existing Mixpost user's full sign-in. Keep this identity-only SSO client separate from YouTube's broader API scopes. Avoid changing provisioning, roles or permissions incidentally. [Google audience rules](https://support.google.com/cloud/answer/15549945).

Users will need a fresh sign-in on the new domain because cookies cannot carry from `peachyhq.com` to `ducatix.com`. OAuth flows started on the old host must finish there; forwarding their callback to the new host loses the originating session/state. Keep password login available while testing Google sign-in, then test a complete fresh Google callback through to the authenticated dashboard. A connection/discovery test alone is insufficient.

## 4. Social-provider callback and portal changes

Reuse the current apps/credentials. Add the new addresses while retaining old addresses wherever each portal permits it. Do not rotate secrets or bulk-reconnect accounts merely because the hostname changes.

| Integration | Current app/client identification | New OAuth redirect URI |
| --- | --- | --- |
| Google login | Peachy Mixpost SSO, client above | `https://posting.ducatix.com/mixpost/auth/sso/callback` |
| YouTube | Google client `616207052582-raoborupfmgre8bucir4kuuq1qvjc3vt.apps.googleusercontent.com` | `https://posting.ducatix.com/mixpost/callback/youtube` |
| Facebook Page | Meta app `25227226140295903` | `https://posting.ducatix.com/mixpost/callback/facebook_page` |
| Instagram through Facebook | Same Facebook app | `https://posting.ducatix.com/mixpost/callback/instagram` |
| Instagram standalone | Instagram app `1224023842978103` | `https://posting.ducatix.com/mixpost/callback/instagram_standalone` |
| Threads | Threads app `1080206754179678` | `https://posting.ducatix.com/mixpost/callback/threads` |
| X | Match the existing X service to its developer app | `https://posting.ducatix.com/mixpost/callback/twitter` |
| TikTok, enabled but unconnected | Match the current service's client key in TikTok Developer Portal | `https://posting.ducatix.com/mixpost/callback/tiktok` |
| Bluesky | URL-based OAuth client identity; see next section | `https://posting.ducatix.com/mixpost/callback/bluesky` |

The YouTube Google client is **different from SSO**. Its owning project is `mixpost-497918` (number `616207052582`), accessible as `dan@ducatix.com` after reauthentication. The client is named **Mixpost**, with only `https://mixpost.peachyhq.com` as its JavaScript origin and `https://mixpost.peachyhq.com/mixpost/callback/youtube` as its redirect URI. Add the new redirect URI while retaining the old one, and review the new JavaScript origin because this client already has an origin registered. Retaining the same client and refresh tokens is the default plan. [YouTube integration reference](https://docs.mixpost.app/services/social/google/youtube/).

The YouTube consent app is **Peachy Posting**, External / In production. Its homepage is the old app root, privacy/terms are the old `/pages/privacy` and `/pages/terms`, and its only authorized domain is `peachyhq.com`. The console's overall branding status says verified, but data-access verification remains under review. Its detailed progress has a July 29 branding error: consent-screen app name does not match the homepage; privacy/functionality/data-access/minimum-scope checks remain in progress. Treat this as an existing review issue, not a migration regression. Ensure the final homepage and consent identity agree and account for Google's review of changed domains/branding before resubmitting. No reviewer message or submission was sent.

For Meta apps, update allowed domains, valid OAuth redirects, website URL, privacy/terms URLs, data deletion/deauthorization URLs and configured webhook URLs. Check the active standalone Instagram app, not a deprecated parallel app. Use these public pages:

```text
https://posting.ducatix.com/pages/privacy
https://posting.ducatix.com/pages/terms
https://posting.ducatix.com/pages/data-deletion
```

Incoming webhook candidates follow the live route pattern:

```text
https://posting.ducatix.com/mixpost/inbox-webhook/facebook_page
https://posting.ducatix.com/mixpost/inbox-webhook/instagram
https://posting.ducatix.com/mixpost/inbox-webhook/instagram_standalone
https://posting.ducatix.com/mixpost/inbox-webhook/threads
```

Confirm which endpoints each app actually registers; the route's existence does not prove a provider uses it. Facebook service-save hooks can manage subscriptions automatically, while standalone Instagram/Threads have portal configuration. Inspect before saving so a URL edit does not unintentionally replace subscriptions. Keep existing verify tokens and app secrets; verify the new endpoint handshake and an actual signed event. Preserve `/mixpost/uninstall-callback/{provider}` for existing deauthorization registrations. [Facebook/Instagram reference](https://docs.mixpost.app/services/social/facebook/), [Threads reference](https://docs.mixpost.app/services/social/threads/).

For X, update callback and website URLs in the existing app while keeping its existing OAuth credentials. [X integration reference](https://docs.mixpost.app/services/social/x/).

For TikTok, update Login Kit redirect, website and legal-page URLs; check whether the new domain needs DNS/file verification or review. If photo uploads are enabled, inspect the actual media URL's verified prefix; changing the app domain does not change the S3 media domain automatically. No TikTok reconnection is currently needed because no accounts are connected. [TikTok reference](https://docs.mixpost.app/services/social/tik-tok/).

For Adobe Express, add `posting.ducatix.com` to the existing Embed SDK credential's allowed domains and retain the old domain during transition. Verify the editor opens and returns media to Mixpost. [Adobe reference](https://docs.mixpost.app/services/media/adobe-express/).

Browser access follow-up: Cloudflare, both Google projects, the active Meta app, X and TikTok developer consoles are accessible. No provider settings were saved. Adobe's terms gate has been completed by the user, but the signed-in personal account has no projects and only its personal organization; the existing Express integration's owner remains unconfirmed. The user corrected the Mixpost purchase email and opened the correct authenticated account. Its active purchase is confirmed to match production; see the license section. No account/profile changes are needed.

Observed rollback values in the active Meta parent app: app domain `mixpost.peachyhq.com`; website `https://mixpost.peachyhq.com/`; privacy `https://www.peachyhq.com/privacy`; terms `https://www.peachyhq.com/terms`; data-deletion instructions `https://mixpost.peachyhq.com/pages/data-deletion`. Facebook Login for Business lists only the old `/mixpost/callback/instagram` and `/mixpost/callback/facebook_page` redirect URIs. Its deauthorization and data-deletion request callback fields are empty. The parent app contains Threads app `1080206754179678`; do not treat it as a separate unrelated Meta project. The dashboard reports no required action items.

Threads settings list the old `/mixpost/callback/threads` OAuth URI, with both Uninstall and Delete Callback URLs pointing to `https://mixpost.peachyhq.com/mixpost/uninstall-callback/threads`. Standalone Instagram settings confirm app `1224023842978103`, name **Peachy MixPost - IG**, the old `/mixpost/callback/instagram_standalone` URI and empty deauthorization/data-deletion request fields. Its registered webhook is `https://mixpost.peachyhq.com/mixpost/inbox-webhook/instagram_standalone`, with a masked existing verify token. Preserve these subscribed v25.0 fields during a URL change: `comments`, `live_comments`, `message_edit`, `message_reactions`, `messages`, `messaging_postbacks`, `messaging_referral`, `messaging_seen`. `messaging_handover`, `messaging_optins`, and `standby` are unsubscribed (v26.0). Portal registration is confirmed; successful actual event delivery still needs verification.

X app **PeachyMixPost**, ID `32134364`, is accessible in the existing developer account. Its callback is `https://mixpost.peachyhq.com/mixpost/callback/twitter`, website is `https://mixpost.peachyhq.com/mixpost/`, and its UI supports adding another callback. Existing permissions are read/write/direct messages, confidential web app, and email requests disabled. Preserve them. Organization name/URL still say Peachy / `https://www.peachyhq.com`; privacy and terms fields are empty. Branding changes can be reviewed separately from the hostname/callback move.

Provider edits, remaining access and any review requirements must be resolved before declaring cutover ready. Enabled AI/stock-media/Bitly services normally keep their current credentials; inspect any origin/referrer restrictions rather than changing their keys.

TikTok app **Peachy Posting**, ID `7645333415289882642`, is accessible. Its production revision is **Not approved**, rejected June 23; the visible rejection asks to update the Website URL. Its website, privacy/terms and Login Kit callback all use the old domain, and `mixpost.peachyhq.com` is its only verified domain property. The portal offers DNS-based Domain verification or signature-file URL-prefix verification. Prepare the new domain/legal links and an appropriate demo before revising/resubmitting; preserve the existing app and do not treat Return to Draft as a read-only action. Because no TikTok accounts are currently connected, restoring TikTok approval can be a separately tracked integration task; do not claim TikTok publishing works after the domain move without approval and a real flow test.

## 5. Bluesky requires an explicit transition decision

All six Bluesky accounts have OAuth refresh tokens and DPoP keys. The running provider manager derives `client_id` from `/bluesky/oauth/client-metadata.json`; refresh requests and signed client assertions use that identity. Metadata advertises the matching client ID, JWKS and callback URLs. Changing the app domain therefore changes the identity used by background token refresh, not just the login callback.

The [AT Protocol OAuth specification](https://atproto.com/specs/oauth) requires the metadata client ID to match its fetch URL. A redirect from the old metadata URL to a new identity is not sufficient compatibility. This is a code-backed risk; no production token-refresh experiment was performed during discovery.

Choose and verify one strategy before cutover:

1. **Keep the old Bluesky identity temporarily.** Implement and rehearse a narrow compatibility mechanism that consistently pins the provider's client ID/assertions and old metadata document to the old URL, preserves the existing signing key, and uses a callback supported by that document. This requires implementation/testing; a Traefik exception alone does not fix background workers generating the new client ID. Leave old metadata/JWKS available directly. Plan eventual reconnections to retire the old identity.
2. **Move Bluesky identity and reconnect all six accounts.** Arrange account-owner access and a publishing window, establish the new metadata/JWKS endpoints, and reconnect existing account records before affected scheduled posts are due. Confirm workspace/account IDs and scheduled post associations remain intact. Keep signing keys; do not regenerate them as a domain-migration step.

Whichever strategy is selected, verify token refresh as well as account connectivity. A successful initial request with a still-valid access token does not prove the next refresh works. Include the strategy in rollback: reconnecting under the new identity is not automatically reversed by restoring `APP_URL`.

## 6. Redirects and compatibility

Recommended policy:

| Old-host traffic | Behavior during transition |
| --- | --- |
| Normal full-page GET/HEAD visits | Redirect to the same path/query on `https://posting.ducatix.com` |
| OAuth callbacks | Serve directly on old host for in-flight flows; do not forward codes/state across domains |
| Incoming inbox/uninstall webhooks | Serve directly, preserving body, method, host and signature-validation behavior |
| `/mixpost/api/…` and `/api/health` | Serve directly while consumers/monitors migrate |
| `/bluesky/oauth/client-metadata.json`, `/bluesky/oauth/jwks` | Serve directly according to the selected Bluesky identity strategy |
| `/storage/…`, packaged assets, manifest | Serve directly while old pages/external references remain in use |
| Existing-page Inertia/AJAX requests and non-GET operations/uploads | Keep direct service during the initial transition to avoid cross-origin XHR or interrupted form/upload behavior |

Match only the old hostname, so the rule cannot loop on the new one. Preserve encoded paths and query strings. Start with temporary redirects and low/no redirect caching, test, then use permanent redirects for ordinary page navigation after acceptance. Traefik RedirectRegex supports the domain replacement; Compose labels require doubled dollar signs in replacement captures (`$${1}`). [Traefik redirect reference](https://doc.traefik.io/traefik/reference/routing-configuration/http/middlewares/redirectregex/).

Do not install a catch-all permanent redirect at Cloudflare or Traefik that supersedes these compatibility routes. HTTP redirects are not a reliable migration mechanism for signed POST webhooks, API authorization headers, OAuth state, or Bluesky client identity. New users should begin authentication/account connections on the new hostname.

Keep the old DNS and HTTPS redirect for the long term. Remove technical compatibility routes only after provider registrations, API consumers and logs show they are no longer needed. If Bluesky identity is pinned to the old URL, its metadata/JWKS remain dependencies until that identity is retired.

## 7. Related configuration and documents

Update these sources and their deployed copies as appropriate:

- Infra `deployments.json`: Mixpost health and login URLs; regenerate deploy runbooks, including Mixpost's generated `DEPLOY.md`.
- Infra `backups/backup-targets.json`: health/restore checks, primary URL and hostname inventory. Keep the same app/backup IDs and history.
- Infra `app/business/social.js`: fallback Mixpost link; inspect deployed `INFRA_DASHBOARD_MIXPOST_URL` and override if present.
- Infra deployment and health tests that assert the current literal URL; restore/deploy/email documentation where it describes the current hostname.
- Production Annex `MIXPOST_APP_URL`; inspect the deployment source, both relevant instances and any cache before updating Social links.
- Reporting `MIXPOST_APP_URL` configuration/defaults and `src/lib/reporting/mixpost-media.ts`; inspect active service environment. SQL reporting access remains to the same database.
- Mixpost's managed `resources/views/home.blade.php`: use new or relative terms/privacy links; deploy the scoped mounted template through its managed override path.
- Mixpost production/update/health/Instagram runbooks. Keep historical incident evidence identified as historical rather than rewriting every old URL indiscriminately.
- The one API token's owner/consumer, bookmarks, staff app links, password-manager entries, browser-installed app shortcuts, and any analytics stream URL if configured.

All media rows use the unchanged bucket; the inspected stored content had no old-host literals requiring a mass database rewrite. New local-disk URLs would follow the new app URL, so still verify image/video rendering and external fetches. SMTP can stay on the same existing sender/relay because this move does not change the sending domain or IP. Check newly generated password reset/invitation/notification links, without rotating mail credentials.

## 8. License and readiness gates

Mixpost's [published terms](https://mixpost.app/terms-of-use) tie a license to one domain/subdomain. The correct authenticated account contains a **Mixpost Pro** purchase dated December 19, 2025, with an **Active** license expiring December 19, 2026. Its license key matches the running production container's `LICENSE_KEY`, verified with fingerprints without storing the key in this document. The portal says **No domain set** and offers an inline Domain editor with Save/Cancel. The editor was inspected and canceled; no binding was saved.

The portal therefore exposes a self-service domain field, and there is no recorded old domain binding to remove there. At the coordinated cutover, set the field to `posting.ducatix.com` and verify its saved value and continued application operation. Keep this as the same installation and license; there is no evidence here that another purchase is required for this move. The inspected form contained no transfer instructions or statement about temporary old-host compatibility; if saving introduces a warning or approval requirement, resolve that concrete requirement before proceeding. No vendor message or purchase was made during discovery.

Required before cutover: license transition resolved; access to the actual YouTube and social provider apps; new callback/allowed-domain registrations accepted; Bluesky strategy implemented or reconnection access ready; origin certificate valid; fresh backups and exact pinned image available; a window clear of active uploads/publishing jobs. Verify another existing user's Google sign-in if broader team login is part of acceptance.

## 9. Ordered execution and verification

1. Recheck live state and choose the Bluesky/license transition. Inventory next scheduled posts, current publishing jobs and active uploads. Record the actual provider app settings for rollback.
2. Add provider callback/domain allowlists while retaining old entries. Allow Google changes time to propagate; its console notes five minutes to a few hours. Establish public legal pages on the new host before changing provider branding links that require them.
3. Add DNS, new Traefik router and HTTPS certificate. Route to the same running app. Do not invite users to connect accounts on the new hostname before corresponding provider callbacks are ready.
4. Back up configuration/release state and update managed landing-page links. Set the new canonical domain, then recreate only the existing app using the current pinned image. Verify configuration and workers.
5. Apply temporary page redirects with higher-priority compatibility routes. Update consumers/monitoring to the new address.
6. Verify fresh Google sign-in to the authenticated dashboard and password fallback; legal pages; new-host assets; workspace navigation; image/video upload/preview; notification links; API authentication with the existing token.
7. Verify social-account authorization/reconnection on the new host where needed, token refresh, inbox webhook verification and actual event delivery. Separate existing integration failures from migration regressions. Check an actual already-authorized scheduled publication and its provider result; new test posts require their own publishing authorization.
8. Verify old deep links/path/query handling, old direct webhook/API/media endpoints, old OAuth flow behavior, new/old HTTPS, HTTP upgrade, no loops, and no cross-origin Inertia failures. Check that database/Redis container identities and media/account counts remain intact.
9. Monitor next token-refresh and scheduled-publishing cycles plus health/login/error logs. After acceptance, make ordinary-page redirects permanent and document remaining old-host dependencies.

Expected user impact: a fresh login on the new domain and a brief app restart. DNS/provider changes can be prepared first; elapsed time is governed by provider access, propagation/review, license transfer and any Bluesky reconnections. A precise downtime estimate should follow the preflight rather than assume an instantaneous cutover.

## 10. Rollback

Keep old DNS, certificate and provider redirect registrations. Disable the old-to-new page redirect, restore the prior `.env`/routing/template configuration and recreate only Mixpost with the same image and overrides. Restore consumer URLs if necessary and verify login, API, workers and publishing. Revert webhook registrations to their captured old values if the new host will no longer serve them. Keep the new DNS/router until any in-flight new-host OAuth flows and API consumers have drained.

The database does not need to be restored for a pure domain rollback; restoring it could discard intervening posts or refreshed tokens. Reconcile any Bluesky accounts reauthorized under the new identity and reverse license binding as required by the chosen vendor process. Permanent browser-cached redirects complicate rollback, which is why they belong after verification.

## Evidence boundaries

Confirmed: infrastructure/configuration above, live routes/source behavior, current service/account/media counts, Cloudflare DNS/TLS state, Google client/audience/review settings, inspected Meta/X/TikTok portal settings, the matching active license and its editable unset domain, and old public health/login responses. Not yet proven: new-host operation, provider portal changes or approval requirements, a saved license domain and its runtime effect, successful new-domain Google callback, token refresh under a changed Bluesky identity, actual new-host webhook delivery or publishing. Adobe integration ownership remains unconfirmed. This document is the implementation scope and acceptance checklist, not a claim that the move has happened.
