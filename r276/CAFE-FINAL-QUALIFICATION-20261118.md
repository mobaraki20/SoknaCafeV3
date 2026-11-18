# R276 — Cafe connection acceptance ledger, 2026-11-18

**Objective:** Existing Cafe Local 231fl/R275 -> existing Public 35ij/R275, independently verified Public owner, Local admin confirmation, full Local-to-Public projection/media and exactly-once guest order. No clean install, tenant URL hardcode or manual SQL.

## Proven
- R276 SHA-pinned Public 35ik package: effbe0b41011807341c794b616c4a70c3a999bde730384d6fbee76680f1e2c8d
- Exact source tree for the 35ij baseline: 91d27d4ddb0fdd0640227b8efed461cde94c7efc3c578d52623056dcb859d57c
- Target tree: 254e74b23fe6f614909b23a3b937480723c030bfac07179cce3b2207bfc91b04
- Only five Public managed files differ; 239 unchanged, no SQL or Local changes. Protected Emergency remains outside this release.
- Real HTTPS / MariaDB root and subfolder, independent owner and Local admin, full projection and guest exactly once: https://github.com/mobaraki20/SoknaCafeV3/actions/runs/44240941807
- Real Public /staff/login HTTP POST with server-issued admin cookie, then owner upgrade preflight 200; unauthenticated 403. Both root and subfolder paths and complete E2E: https://github.com/mobaraki20/SoknaCafeV3/actions/runs/44243934697
- Protected Emergency Stage, Activate, Commit DIRECT FROM installed 35ij to candidate 35ik, real MariaDB 11 preserved: https://github.com/mobaraki20/SoknaCafeV3/actions/runs/44242310527
- Live Public read-only GET on 2026-11-18: version 35ij, DB ready, 11 migrations, 0 pending; routine active 35ij, no staged or pending; 7 account cards at /staff/login; QR redirects old Menu/ to target /menu; /move/owner-setup currently 404 because candidate R276 is not installed: https://github.com/mobaraki20/SoknaCafeV3/actions/runs/44244338609 and https://github.com/mobaraki20/SoknaCafeV3/actions/runs/44243506612

## Remaining FIELD gates (cannot be performed without current authorized cafe operator access)
1. Actual protected Public 35ij -> 35ik host Stage/Activate/Commit with active version readback. CI does not access or mutate cafe hosting.
2. Authenticate real authorized Public Staff admin, initialize independent Public owner password if missing, then approve owner consent. No credential is provided to automation.
3. Approve operation from Cafe Local admin; verify authority and full Sync/ACK for catalog, menu, prices, images and any Local projection events, without clearing Local pending events.
4. Real QR guest test order -> Local exactly once.

**Decision:** R276 software candidate CI PASS. LIVE CAFE CUTOVER NOT COMPLETED. Do not assert production readiness or auto-install based solely on CI. Do not discard Local data, manually edit DB/epoch/secret, or bypass owner consent.

Complete verbose Persian report in Library at:
`/Cafe/SOKNA-Workspace/WORKING/20261118-R276-OWNER-SETUP-QA/SOKNA-R276-CAFE-CONNECTION-FINAL-QA-20261118-FA.md`
