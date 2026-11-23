#!/usr/bin/env python3
"""Read-only preparation of byte-pinned Public R306 for disposable MariaDB QA.
Does not touch any production configuration, host or customer database.
"""
import hashlib, json, os, pathlib, sys, zipfile

EXPECTED_ZIP='e2d78030a7246103a1b4ae2b28409093ebf8e61d44abc3739d73d0ac10b9fe51'
EXPECTED_SQL={
 '0009_public_move_owner_setup.sql':'6a9c10259b2f9c9c416f713461b519507bc38322a2066994e5fa802c16fe461e',
 '0010_public_move_owner_receipts.sql':'aed35511863d1e27e3ac1b02ae76c32baf6545061f792c38222497633be960ad',
 '0011_public_move_intake_pause.sql':'1d7482a33f81a228bfce7becfe74c80d659d5e2a43ac225560a8df0da7c0e26d',
}
def main():
    if os.environ.get('GITHUB_ACTIONS')!='true':raise SystemExit('CI_ONLY')
    root=pathlib.Path(os.environ['PASS28_INPUT_DIR']); dest=pathlib.Path(os.environ['PASS28_PUBLIC_ROOT'])
    found=[p for p in root.rglob('*.zip') if hashlib.sha256(p.read_bytes()).hexdigest()==EXPECTED_ZIP]
    if len(found)!=1:raise RuntimeError('EXPECTED_R306_PUBLIC_ZIP_NOT_UNIQUE '+str(len(found)))
    with zipfile.ZipFile(found[0]) as z:
        names=z.namelist(); assert z.testzip() is None
        m=json.loads(z.read('manifest.json'))
        assert m['component']=='public-edge' and len(m['files'])==244
        assert set(names)=={'manifest.json'}|{'payload/'+f['path'] for f in m['files']}
        dest.mkdir(parents=True,exist_ok=False)
        for f in m['files']:
            n=f['path']; assert '\\' not in n and n and not n.startswith('/') and '..' not in n.split('/')
            b=z.read('payload/'+n)
            assert hashlib.sha256(b).hexdigest()==f['sha256'] and len(b)==f['size']
            p=dest/n;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(b)
    for n,sha in EXPECTED_SQL.items():
        b=(dest/'database/migrations'/n).read_bytes()
        assert hashlib.sha256(b).hexdigest()==sha,n
    print(json.dumps({'status':'PASS','sha256':EXPECTED_ZIP,'managed_files':244,'sql_pins':len(EXPECTED_SQL),'source':str(dest)},sort_keys=True))
if __name__=='__main__':main()