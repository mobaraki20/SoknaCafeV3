#!/usr/bin/env python3
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MIGRATION = ROOT / 'apps/public/database/migrations/0002_m4_guest_public_projection.sql'
AUDIT = ROOT / 'docs/migration/M4_GUEST_PUBLIC_AUDIT_FA.md'


def fail(message: str) -> None:
    raise SystemExit(f'FAIL: {message}')


if not MIGRATION.is_file():
    fail('M4 Public migration file is missing.')

sql = MIGRATION.read_text(encoding='utf-8')
audit = AUDIT.read_text(encoding='utf-8')

expected_tables = {
    'guest_publish_revisions',
    'guest_active_revisions',
    'guest_availability_state',
    'remote_read_models',
}
created_tables = {
    match.lower()
    for match in re.findall(r'CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?([a-zA-Z0-9_]+)`?', sql, flags=re.IGNORECASE)
}
if created_tables != expected_tables:
    fail(f'M4 migration table ownership drifted: {sorted(created_tables)}')

required_fragments = [
    'UNIQUE KEY uq_guest_publish_hash(installation_id,content_hash)',
    'FOREIGN KEY(installation_id,revision_id) REFERENCES guest_publish_revisions(installation_id,revision_id) ON DELETE RESTRICT',
    'FOREIGN KEY(installation_id) REFERENCES installations(installation_id) ON DELETE CASCADE',
    'PRIMARY KEY(installation_id,model_key)',
    'INDEX idx_remote_read_sync(installation_id,last_sync_at)',
]
for fragment in required_fragments:
    if fragment not in sql:
        fail(f'M4 schema invariant missing: {fragment}')

for forbidden in ['orders', 'inventory', 'finance', 'supply', 'preparation']:
    if re.search(rf'CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?{re.escape(forbidden)}\b', sql, flags=re.IGNORECASE):
        fail(f'M4 migration incorrectly creates Local business owner table: {forbidden}')

for table in sorted(expected_tables):
    if table not in audit:
        fail(f'M4 audit does not document owner table {table}.')

if 'Local remains the source of canonical catalog/business data and the publish decision' not in audit:
    fail('M4 audit no longer freezes Local canonical/publish-decision ownership.')
if 'read models never accept business mutations' not in audit:
    fail('M4 audit no longer freezes read-model non-mutation authority.')

print('Public M4 schema ownership contract: OK')
