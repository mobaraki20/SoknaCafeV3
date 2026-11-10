#!/usr/bin/env python3
"""Process-level negative matrix for the DB-forward fence (disposable Windows QA)."""
import hashlib, json, os, pathlib, shutil, subprocess, sys, uuid

assert os.environ.get('GITHUB_ACTIONS')=='true' and os.environ.get('RUNNER_OS')=='Windows', 'WINDOWS_GITHUB_QA_ONLY'
TMP=pathlib.Path(os.environ['RUNNER_TEMP']).resolve()
ROOT=TMP/'sokna-r149b-windows-exact-e2e'
ART=ROOT/'artifacts';ART.mkdir(parents=True,exist_ok=True)
SCRIPT=pathlib.Path(__file__).with_name('native-forward-fence-qa.py')
PUBLIC='1131d89c98e05c9597f372ed1d480e5637c3ad1969bc8733d2a837b644e57584'
COUNT=0

def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
def write(p,value):p.parent.mkdir(parents=True,exist_ok=True);p.write_text(json.dumps(value)+'\n',encoding='utf-8')
def read(p):return json.loads(p.read_text(encoding='utf-8'))
def cmd(mode,root,state,backup,proof=None,profile=None,want=0,reason=''):
 global COUNT
 args=[sys.executable,str(SCRIPT),mode,'--root',str(root),'--state',str(state),'--backup',str(backup)]
 if profile:args+=['--profile',profile]
 if proof:args+=['--public-proof',str(proof)]
 p=subprocess.run(args,text=True,capture_output=True,timeout=12)
 assert p.returncode==want,(p.returncode,want,p.stdout,p.stderr)
 result=json.loads(p.stdout)
 if reason:assert result['result']=='BLOCKED' and result['reason']==reason,(result,reason)
 COUNT+=1
 return result

def fixture(profile):
 root=ART/('native-forward-'+profile)
 if root.exists():shutil.rmtree(root)
 lab=ROOT/('synthetic-negative-'+profile)
 if lab.exists():shutil.rmtree(lab)
 state=lab/'Maintenance/local-slots/routine-state.json'
 backup=lab/'Maintenance/local-slots/private-db-backups/before.sql'
 proof=lab/'public-safe.json'
 write(state,{'phase':'COMMITTED','database_mutation_performed':True,'recovery_required':False})
 backup.parent.mkdir(parents=True,exist_ok=True);backup.write_text('SQL_DISPOSABLE_ONLY\n'*50)
 write(proof,{'format':'sokna-public-two-adapter-qa-v1','production_authorized':False,
    'cases':[{'baseline':b,'channel':c,'target_zip_sha256':PUBLIC,'fresh_process_commit':True}
            for b in ('r115','r126') for c in ('routine','emergency')]})
 return root,state,backup,proof

for profile in ('r75','r140'):
 root,state,backup,proof=fixture(profile)
 cmd('local',root,state,backup,profile=profile)
 start=sha(root/'forward-fence.json')
 cmd('resume',root,state,backup,want=24,reason='DB_FORWARD_NEEDS_QUALIFIED_THREE_COMPONENT_RECOVERY')
 assert sha(root/'forward-fence.json')==start
 cmd('local',root,state,backup,profile=profile,want=24,reason='EXISTING_DB_FORWARD_FENCE_REQUIRES_RECONCILIATION')
 cmd('public',root,state,backup,proof=proof)
 finalized=sha(root/'forward-fence.json')
 cmd('resume',root,state,backup,proof=proof,want=24,reason='DB_FORWARD_NEEDS_QUALIFIED_THREE_COMPONENT_RECOVERY')
 assert sha(root/'forward-fence.json')==finalized
 cmd('public',root,state,backup,proof=proof,want=24,reason='PUBLIC_FENCE_ALREADY_RECORDED')
 before=backup.read_bytes();backup.write_bytes(before+b'TAMPER')
 cmd('resume',root,state,backup,proof=proof,want=24,reason='NATIVE_LOCAL_OR_BACKUP_DRIFT_AFTER_DB_FORWARD')
 assert sha(root/'forward-fence.json')==finalized
 backup.write_bytes(before)
 d=read(proof);d['cases'][0]['fresh_process_commit']=False;write(proof,d)
 cmd('resume',root,state,backup,proof=proof,want=24,reason='PUBLIC_COMMIT_NOT_ATTESTED')
 d=read(proof);d['cases'][0]['fresh_process_commit']=True;write(proof,d)
 write(root/'forward-fence.json',{**read(root/'forward-fence.json'),'production_authorized':True})
 cmd('resume',root,state,backup,proof=proof,want=24,reason='FORWARD_FENCE_MALFORMED_OR_UNSAFE')

root,state,backup,proof=fixture('r75')
write(state,{'phase':'RECOVERED_TO_PREVIOUS_SLOT','database_mutation_performed':False,'recovery_required':True})
cmd('local',root,state,backup,profile='r75',want=24,reason='LOCAL_DATABASE_FORWARD_NOT_ATTESTED')
assert not (root/'forward-fence.json').exists()
print(f'PASS NATIVE_DB_FORWARD_FENCE_PROCESS_NEGATIVE_MATRIX count={COUNT} profiles=2')
print('NOT PRODUCTION: no Maintenance EXE global commit, no Public R112 HTTP, no field recovery approval')