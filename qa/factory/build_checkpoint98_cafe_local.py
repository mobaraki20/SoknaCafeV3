"""Factory scope transition, controlled Cafe operator only. Exact CP84 payload; no product/engine change."""
import hashlib,json,zipfile,pathlib,sys,os
from build_checkpoint84_controlled_local import obj,get
P=pathlib.Path;H=lambda b:hashlib.sha256(b).hexdigest()
def need(ok,why):
 if not ok:raise RuntimeError(why)
old,out=map(P,sys.argv[1:])
need(os.environ.get('GITHUB_ACTIONS')=='true' and os.environ.get('RUNNER_OS')=='Windows','CI_ONLY')
need(H(old.read_bytes())=='298a6784ce4a00e0d0519c8a65c06307b8b86ee12550379ed56795ae2740ceb4','EXACT_CP84_OUTER')
run=obj('/actions/runs/45735326205');need(run['head_sha']=='74f2952ea62011b0c15349e7213b23da32243dcf' and run['conclusion']=='success' and run['status']=='completed','CP84_RUN')
job=obj('/actions/jobs/139627642949');need(job['run_id']==45735326205 and job['conclusion']=='success' and job['name'].startswith('native (C2'),'CP84_C2')
log=get('/actions/jobs/139627642949/logs').decode('utf-8-sig')
for x in ['298a6784ce4a00e0d0519c8a65c06307b8b86ee12550379ed56795ae2740ceb4','984f685043172eb3ccfe9dbe6e9c2178bc2ad1e69eaf1c9da7007cba03769d35','bafc41567eb3ed3db042855474bf51fdacc2a926094885f561411ae4dae42fa6','COMMITTED']:
 need(x in log,'CP84_C2_RECEIPT_PIN')
with zipfile.ZipFile(old) as z:
 need(z.testzip() is None and set(z.namelist())=={'release-set.json','artifacts/local/web.zip'},'SHAPE')
 m=json.loads(z.read('release-set.json'));w=z.read('artifacts/local/web.zip')
need(m['for_install'] is True and m['production_pass'] is True and m['status']=='CONTROLLED_HOME_CANARY_FIELD_ACCEPTANCE_PENDING' and m['qa_notes']['cafe_production_approved'] is False and m['qa_notes']['field_install_verified'] is False,'PREDECESSOR_SCOPE')
need(H(w)==m['artifacts']['local-web']['sha256']=='2c14b241a3cddf5f1d436144691555b11a97ccc31b4d08b2ea8f8d92f04e3aa6','PAYLOAD_PIN')
# New sequence, not a collision with previously staged Home candidate. Same component ID and target.
m['sequence']=202611260005
m['status']='CONTROLLED_CAFE_OPERATOR_FIELD_ACCEPTANCE_PENDING'
m['qa_notes'].update(scope='CONTROLLED_CAFE_OPERATOR_ONLY_FIELD_ACCEPTANCE_PENDING',cafe_production_approved=False,field_install_verified=False,operator_install_allowed=True,qualification_scope='Owner requested controlled Cafe rollout; same exact CP84 payload and qualified Maintenance Core. New outer must itself pass actual C2 panel admission before handoff. No production field acceptance claim.',prior_exact_outer_sha256=H(old.read_bytes()),prior_c2_job=139627642949,installation='Explicit operator only after separate delivery receipt, exact Maintenance preflight, verified backups and no open operations; never bypass compatibility or recovery gate.')
with zipfile.ZipFile(out,'w') as z:
 for n,b in sorted({'release-set.json':(json.dumps(m,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode(),'artifacts/local/web.zip':w}.items()):
  i=zipfile.ZipInfo(n,(2026,11,26,0,0,0));i.create_system=3;i.external_attr=0o100644<<16;i.compress_type=zipfile.ZIP_DEFLATED;z.writestr(i,b,compresslevel=9)
print('CP98_CONTROLLED_CAFE_CANDIDATE',H(out.read_bytes()))
