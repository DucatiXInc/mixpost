# Repository ownership and CI

Canonical fork: [DucatiXInc/mixpost](https://github.com/DucatiXInc/mixpost),
original repository ID 1111510671, public, main. Parent/source remains
inovector/mixpost (516725401). Upstream Composer identity and history are
preserved. No production release or credentials change with this transfer.

All workflows remain on ubuntu-latest, including every public PR and the
complete six-job PHP/Laravel matrix. The existing organization runner group
disallows public repositories; do not set CI_RUNNER or widen that policy.
Build-assets and changelog jobs remain hosted. The prepared change grants only
those two jobs contents: write; verification jobs explicitly request contents: read.

The test matrix retains every case and uses disposable hosted MySQL/Redis
services with Docker-assigned ports. Run-tests and the frozen release guard
can be invoked manually, with 25/15-minute timeouts. The guard preserves all
customization/register/patch checks; it cannot qualify the licensed frozen
production image or replace the exact-candidate release playbook.

Ownership qualification compares repository ID, public fork/parent, settings,
secret/environment metadata, redirects, existing-credential canonical server
Git access and Infra reads. Production verification compares frozen image,
Pro version, override commit, app/MySQL/Redis IDs/start times and health.
There is no production Git checkout at the registry path. Do not fetch,
rebuild, restart, migrate or run release/checkpoint mutations to verify CI.

## Approved transfer policy exception

The transfer reset default workflow permissions from write/PR-approval-enabled
to read/PR-approval-disabled. GitHub rejected restoration with HTTP 409:
"Write permissions for workflows are disabled by the organization". No
organization policy was changed. Dan explicitly accepted the restricted defaults and narrow hosted job
permissions on 14 November 2026; workflow PR approvals remain disabled. The prepared workflow grants preserve the two existing commit-writing
jobs without broad default write access; unused workflow PR approvals remain
disabled. Post-transfer hosted CI verifies these explicit job permissions separately.
