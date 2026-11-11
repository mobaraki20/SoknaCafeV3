#!/usr/bin/env python3
"""Independent byte-equal SQL chain gate: R149B tested MariaDB source vs exact R155."""
import pathlib,sys,hashlib
a,b=map(pathlib.Path,sys.argv[1:3])
def catalog(p):
    return {f.name:hashlib.sha256(f.read_bytes()).hexdigest() for f in sorted((p/'database/migrations').glob('*.sql'))}
left,right=catalog(a),catalog(b)
assert len(left)==len(right)==80,(len(left),len(right))
assert left==right,'R155_MIGRATION_DIFFERS_FROM_REAL_MARIADB_TESTED_R149B'
assert all(any(x.startswith(f'{n:04d}_') for x in right) for n in range(77,81))
print('PASS 80/80 EXACT SQL MIGRATIONS, R76->R80 MARIADB GATE USES SAME BYTE IDENTITY')
