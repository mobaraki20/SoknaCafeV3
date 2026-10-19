# SOKNA Windows Services — Canary Pre-release Publication

This is distribution infrastructure, not a new update engine. The Windows Services updater remains independent from Local Web and Public Web, and every installation (Home, Cafe, customer) uses the same installer.

## Supported ways to request an immutable, user-downloadable Pre-release

1. In GitHub Actions, manually run `Publish SOKNA Windows Services Canary Pre-release` on `main` with the exact source commit SHA, a fresh `windows-services-vX.Y.Z-rc.N` tag, and the literal confirmation `PUBLISH_CANARY_PRERELEASE`.
2. For connector-controlled automation, create **one new JSON file per request** under `release/canary-requests/<unique-name>.json` on `main`. A push to that path starts the exact same guarded workflow; the connected agent does not need a direct GitHub Release API action.

Example JSON **schema only; do not commit this example as .json**:

```json
{
  "format": "sokna-windows-canary-request-v1",
  "scope": "windows-services-only",
  "source_sha": "<exact 40-digit lowercase Git SHA>",
  "release_tag": "windows-services-v1.1.12-rc.1",
  "confirmation": "PUBLISH_CANARY_PRERELEASE"
}
```

The workflow fails closed unless it can confirm **all six** expected GitHub CI suites passed for that exact source SHA. It checks out the pinned SHA, rebuilds through `release/build-windows-release.ps1`, verifies the installer against `artifact-index.json`, binds the tag to the package version, and publishes three assets: the actual `.exe`, `artifact-index.json`, and `SHA256SUMS.txt`.

The resulting link is `https://github.com/mobaraki20/SoknaCafeV3/releases/tag/<release_tag>`; downloads come from **GitHub Releases**, not expiring GitHub Actions artifact archives or ChatGPT attachments.

## Safety / operator boundary

- This pipeline is a **download channel**, not approval to install. Pre-release assets are canary candidates, never automatic host upgrades.
- Do not submit a request until the actual installed baseline and rollback/recovery/backup/health evidence permit supervised real-host UAT.
- Home is only the first independent Canary host. Do not create Home-specific source, versions, paths, or installer.
- Do not update Cafe or Public as a side effect.
- Do not merge the Windows Services Draft PR solely to make a download available; this workflow is installed independently on `main`.
- As of 2026-10-19, no Canary release request has been submitted and no Home/Cafe installation has been performed by this workflow.
