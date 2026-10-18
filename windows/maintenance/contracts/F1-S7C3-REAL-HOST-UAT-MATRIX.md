# F1-S7C3 — Real-host Local inactive sibling qualification

Status: ENGINEERING / WINDOWS CI. Home real-host UAT NOT RUN. Cafe real-host UAT NOT RUN. PR #25 Draft. No install, Active Web switch, database or Public changes.

## UAT levels

- L0 static/synthetic CI: compile, isolated junction and binding copy, hostile signed requests, no-host signed read-only assessment. These do not prove a real Apache/MariaDB host.
- L1 Home read-only: after installer qualification and exact installed baseline capture, query signed GET /v1/local-updates/inactive-host-readiness. Capture service/process, current build, real HTTP response, active junction, inventory tree, binding/lock equality, persistent overlay and idle lifecycle. Absolutely no mutation call.
- L2 Home inactive-write: only after qualified backups and independent remote restore-drill and an authorized change window, exact signed local_only release for Home, and explicit evidence that the same maintenance source is safely installed. Prepare physically inactive slot; active version remains serving throughout.
- L3 Cafe: only after independent Home evidence qualifies. Separate Cafe backups, installation ID, release identity and maintenance version. Never reuse Home receipts.
- L4 activation release: M9/M10/P6/V3/G8, full failure matrix and separate Public Kernel/pair compatibility must all qualify before any cutover.

## Signed read-only host capability

GET /v1/local-updates/inactive-host-readiness returns a diagnostic object with service discovery/HTTP, active R8 junction, signed installed identity/managed tree, binding, persistent overlay, lifecycle and mutation ledger evidence.
Even with all host_preparation_preconditions_met true, activation_allowed and panel_update_ready are false, and backup_qualified, database_qualified, public_pair_qualified and real_host_uat_performed are false. A host probe is NOT a release qualification.

## 30 negative, positive and compatibility scenarios

| Case | Scenario | Required result |
|---|---|---|
| H01 | SoknaApache missing | Fail closed, no sibling write |
| H02 | Apache service ImagePath malformed or -V hanging | Bounded probe, fail closed |
| H03 | HTTP health/build fails | Fail closed |
| H04 | Stable Web is physical not R8 junction | Fail closed |
| H05 | Junction points outside qualified Web.release namespace | Fail closed |
| H06 | Installed identity does not match active VERSION.txt | Fail closed |
| H07 | Inventory count/hash differs | Fail closed |
| H08 | install.lock/binding installation ID mismatch | Fail closed |
| H09 | Active machine PHP differs from persistent | Fail before copying any target files |
| H10 | Lifecycle staged/activation/recovery still open | Fail closed |
| H11 | Maintenance operation active or recovery_required | Fail closed |
| H12 | Machine lease occupied by another updater/installer | No competing physical write |
| H13 | Signed C1 candidate missing/stale | Reject |
| H14 | Release type paired/public_only or baseline stale | Reject C2 |
| H15 | Existing target sibling has different receipt | Never overwrite |
| H16 | Interrupted incoming folder/receipt/commit marker | Quarantine, no unattended cleanup |
| H17 | Same Stage + same idempotency key | Reattest, return identical receipt |
| H18 | Same Stage + different idempotency key | Reject |
| H19 | ZIP symlink, traversal, overlay, bad file or tree hash | Reject |
| H20 | SQL migration catalog or BrowserSetup PHP status incompatible | Do not commit target or switch |
| H21 | Live Apache health changes during target copy | Abort; active Web stays serving |
| H22 | Source or persistent overlay changes during preparation | Reject drift; no automatic activation |
| H23 | Valid complete sibling on qualified Home host | New inactive slot only; active junction unchanged |
| H24 | Supervisor restart after completed sibling | Receipts verified; no activation |
| H25 | Crash after sibling rename before receipt marker | Recovery required, not success |
| H26 | Backup/restore or MariaDB evidence missing | No Activate |
| H27 | Public offline or incompatible protocol | No paired release commit |
| H28 | Staff PWA Wi-Fi to mobile switch during Public queue | No lost ACK or false success (separate Public UAT) |
| H29 | No-downtime observation during C2 | Active Apache process, HTTP, DB and Public remain unchanged |
| H30 | DB forward boundary and post-DB rollback | Out of C3; block until S7D/S7E qualification |

## Capture and data handling

Before and after: exact Windows, Supervisor, Local and Public versions; Release Set/stage receipt SHA-256; active Web junction target and version; signed installed identity SHA-256; managed-tree/inventory hashes; Apache service/health; lifecycle and recovery state; C1/C2 operation IDs; machine overlay equality as booleans/hash only; backup and real MariaDB recovery drill evidence; timestamped guest/staff end-to-end continuity where feasible.
Never publish web-adapter keys, secrets, installation IDs, database passwords, customer records or full sensitive absolute paths. Public Kernel must be assessed independently.

## Explicit stop conditions

Any uncertain evidence, 409/503, failed probe, unexpected service stop, pointer/DB/Public change, or interrupted write stops the campaign. Preserve durable evidence, mark recovery required; never delete an orphan without manual reconciliation.
Real Home UAT cannot be simulated by GitHub CI. No Home/Cafe/hosting deployment is authorized by this contract.
