"""Factory controlled Local candidate; delivery is a separate exact-byte gate.
Qualification is inherited only from the already admitted Home canary on the
identical pinned Maintenance Core, and is not a new production assertion.
No product files, trust policy, engine, migrations or signing keys are modified.
"""
import sys,os,io,zipfile,json,pathlib,hashlib,urllib.request,re
P=pathlib.Path
H=lambda b:hashlib.sha256(b).hexdigest()
J=lambda x:(json.dumps(x,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode()
Q='14cb9ac28637fcc1e9c83dc34ac40765c8a6d2a13ad37cf8c0d40e8e8b359e31'
W='2c14b241a3cddf5f1d436144691555b11a97ccc31b4d08b2ea8f8d92f04e3aa6'
OLD='5cbff0e01129ae2c3bc2d4c3b5d55e5a42157f036923c95bbdeafbcf4424ecda'
F='07e243bc95b569a992a2d9d2d7f6d69ffe6d20f8b3c33fc8f09c3c4622e9a730'
T='bafc41567eb3ed3db042855474bf51fdacc2a926094885f561411ae4dae42fa6'
CORE='f38c20083068e540d593876a2c527c51e0157f92'
PINS={45718662312:'325849ac8e18178d0fab964838d05f7e2fb9140d',45729045732:'bebbb500fbdb96dec4470ce72f2cad1918cc4924',45733926535:'5302dd71c8d0f604ab79501b44b274361909b1d9',45730164810:'c956e986a77db767fc06436efc707ba132328862',45716869600:'a88c84678195f0cbce9f22b60e5f89b22cff3263'}
ART={15076585132:'9492b109aa60d06c135fb7149c70bc7f3c4f731ad7d03e4d6b5f7e8a35284ba6',15076580040:'ddecbf62de89bfb8dbfd36eb82e04e5346ad657608c83a419030021847ed4e13',15076286246:'1f20fbf79362d75b04552a545c196a703e6012f4df4574272a2d071acd6e4803',15076106915:'2ed08fafbdf156befcfa7939c84954a408fe5ff15c342f7bce160c4071e722a6',15076022481:'04e71cd835a8eddd7dbdf328894c684c4c4d8a9c3efad1d7036918ff5c16d4ce',15075912981:'fe1cf5a1121615178aee151c48c3a8fc52b4e0867793abcfd83533eecdc43880',15075553600:'1a9f1b342267d2d9cb788038208ad53b97d9a1f16fa5a8425deca433cd6',15075363007:'6f84c66149f489f5c00732edaa14f5c008a0b73a54a4c5890b5fb5a83a4bc8ba'}
ART[15075363007]='6f84c66149f489f5c00732edaa14f5caa2b79419c74544aee62821fa04f9b53f'
FAULT={139622460151:('APACHE_STOPPED','RECOVERED_TO_PREVIOUS_SLOT',91),139622460204:('DATABASE_BACKUP_VERIFIED','RECOVERED_TO_PREVIOUS_SLOT',91),139622459715:('DATABASE_FORWARD_INTENT','COMMITTED',93),139622460184:('POINTER_SWITCHED','COMMITTED',93),139622460196:('COMMITTED','COMMITTED',93)}
ORIGINS={'GF','R301','R304','R306','R307','R3','C2','GE'}
def need(ok,why):
 if not ok: raise RuntimeError(why)
def get(path):
 token=os.environ['GH_TOKEN']; url='https://api.github.com/repos/'+os.environ['GITHUB_REPOSITORY']+path
 # urllib's default redirect may forward auth; strip on cross-origin redirect.
 class Safe(urllib.request.HTTPRedirectHandler):
  def redirect_request(self,req,fp,code,msg,headers,newurl):
   next=super().redirect_request(req,fp,code,msg,headers,newurl)
   if next and urllib.parse.urlsplit(newurl).netloc!=urllib.parse.urlsplit(req.full_url).netloc:next.remove_header('Authorization')
   return next
 import urllib.parse
 req=urllib.request.Request(url,headers={'Authorization':'Bearer '+token,'Accept':'application/vnd.github+json','User-Agent':'sokna-factory-cp84'})
 with urllib.request.build_opener(Safe()).open(req,timeout=90) as resp:return resp.read()
def obj(path):return json.loads(get(path))
def artifact(id,digest):
 raw=get('/actions/artifacts/'+str(id)+'/zip');need(H(raw)==digest,'ARTIFACT_PIN_'+str(id))
 z=zipfile.ZipFile(io.BytesIO(raw));need(z.testzip() is None,'ARTIFACT_CRC');return z
def valid_row(row,sha,tree):
 return row['status']=='PASS' and row['candidate_sha256']==sha and row['target_tree_sha256']==tree and row['independent_restore'] is True and row['package_admission_tested'] is True and row['actual_field_host'] is False and row['distribution_authorized'] is False
def build(qpath,oldpath,out):
 need(os.environ.get('GITHUB_ACTIONS')=='true' and os.environ.get('RUNNER_OS')=='Windows','CI_ONLY')
 need(out.resolve().is_relative_to(P(os.environ['RUNNER_TEMP']).resolve()) and not out.exists(),'OUTPUT_SCOPE')
 need(H(qpath.read_bytes())==Q and H(oldpath.read_bytes())==OLD,'ARCHIVE_PIN')
 for rid,sha in PINS.items():
  row=obj('/actions/runs/'+str(rid));need(row['head_sha']==sha and row['status']=='completed' and row['conclusion']=='success','RUN_PIN_'+str(rid))
 # Same Maintenance Core reference, before considering historical engine qualification.
 for rid in (45718662312,45729045732):
  # prior delivery does not check out core; its native precursor does.
  rr=45718226938 if rid==45718662312 else rid
  row=obj('/actions/runs/'+str(rr))
  code=get('/contents/.github/workflows/'+('sokna-factory-home-canary.yml' if rr==45718226938 else 'sokna-checkpoint77-native-panel.yml')+'?ref='+row['head_sha'])
  import base64
  need(('ref: '+CORE).encode() in base64.b64decode(json.loads(code)['content']),'CORE_PIN')
 with zipfile.ZipFile(qpath) as z:
  need(z.testzip() is None and set(z.namelist())=={'release-set.json','artifacts/local/web.zip'},'QUARANTINE_SHAPE')
  m=json.loads(z.read('release-set.json')); w=z.read('artifacts/local/web.zip')
 with zipfile.ZipFile(oldpath) as z:
  old=json.loads(z.read('release-set.json')); ow=z.read('artifacts/local/web.zip')
 need(H(w)==W and m['artifacts']['local-web']['sha256']==W and m['targets']['local']['managed_tree_sha256']==T,'TARGET_PIN')
 need(m['sequence']==202611260004 and not m['for_install'] and not m['production_pass'] and m['release_type']=='local_only','QUARANTINE')
 need(old['status']=='CONTROLLED_HOME_CANARY_FIELD_ACCEPTANCE_PENDING' and old['for_install'] and old['production_pass'] and old['release_type']=='local_only' and old['requirements']==m['requirements'],'HISTORICAL_SCOPE')
 need(old['targets']['local'] in [x['local'] for x in m['supported_baselines']] and len(m['supported_baselines'])==8,'BASELINE')
 with zipfile.ZipFile(io.BytesIO(w)) as a,zipfile.ZipFile(io.BytesIO(ow)) as b:
  files=lambda z:{n:H(z.read(n)) for n in z.namelist() if n.startswith('database/migrations/') and n.endswith('.sql')}
  need(len(files(a))==93 and files(a)==files(b),'MIGRATION_DRIFT')
 historical=artifact(15069744615,'fe9ce68351791e4a675d29635ae2782b649cd1eb92f4be58c5ceafb74b1b891b')
 need(H(historical.read('SOKNA-LOCAL-231GF-CONTROLLED-HOME-CANARY.zip'))==OLD,'HISTORICAL_BYTES')
 h=json.loads(historical.read('home-canary-receipt.json'))
 need(h['status']=='CONTROLLED_HOME_CANARY_READY' and h['package_sha256']==OLD and h['scope']=='HOME_ONLY' and not h['cafe_production_approved'] and len(h['native_panel_cases'])==8,'HISTORICAL_DELIVERY')
 need(all(valid_row(x,OLD,old['targets']['local']['managed_tree_sha256']) and x['phase']=='COMMITTED' and x['panel_activation_tested'] and x['migrations']==93 for x in h['native_panel_cases']),'HISTORICAL_RECEIPT')
 rows=[]
 for aid,digest in ART.items():
  z=artifact(aid,digest)
  need(z.namelist()==['matrix-receipt.json'],'PANEL_RECEIPT_SHAPE')
  rows.append(json.loads(z.read('matrix-receipt.json')))
 need({x['origin'] for x in rows}==ORIGINS and len(rows)==8,'PANEL_ORIGINS')
 need({x['origin_tree_sha256'] for x in rows}=={x['local']['managed_tree_sha256'] for x in m['supported_baselines']},'PANEL_ORIGIN_TREES')
 need(all(valid_row(x,F,T) and x['scope']=='DISPOSABLE_CI_FIXTURE_ONLY_NOT_RELEASE' and x['phase']=='COMMITTED' and x['panel_activation_tested'] and x['migrations']==93 for x in rows),'PANEL_RECEIPTS')
 for jid,(fault,phase,n) in FAULT.items():
  job=obj('/actions/jobs/'+str(jid));need(job['run_id']==45733926535 and job['conclusion']=='success','FAULT_JOB')
  log=get('/actions/jobs/'+str(jid)+'/logs').decode('utf-8-sig')
  lines=[re.sub(r'^\S+Z ','',s).strip() for s in log.splitlines()]
  ix=max(i for i,s in enumerate(lines) if '"status": "PASS"' in s)
  lo=ix;hi=ix
  while lines[lo]!='{':lo-=1
  while lines[hi]!='}':hi+=1
  x=json.loads('\n'.join(lines[lo:hi+1]))
  need(valid_row(x,F,T) and x['origin']=='C2' and x['fault']==fault and x['phase']==phase and x['migrations']==n and x['scope']=='DISPOSABLE_CI_FIXTURE_ONLY_NOT_RELEASE','FAULT_RECEIPT')
 q=old['qualification']
 need(all(q[k] is True for k in ('real_windows','real_mariadb','recovery_console_web_down','business_backup_consistency_real_mariadb','canary')) and q['failure_matrix_cases_required']>=80 and q['failure_matrix_id'],'INHERITED_ENGINE_QUALIFICATION')
 need(all(q[k] is False for k in ('real_public_host','remote_vault_real_host','clean_machine_remote_restore')),'NO_PUBLIC_CLAIM')
 # Same controlled Home policy as the admitted prior release. New outer must
 # itself pass the standard exact admission and panel gate before delivery.
 m.update(for_install=old['for_install'],production_pass=old['production_pass'],qualification=q,status=old['status'],qa_notes={
 'scope':'CONTROLLED_HOME_CANARY_ONLY_NOT_CAFE_PRODUCTION_APPROVAL',
 'operator_install_allowed':True,'cafe_production_approved':False,'field_install_verified':False,
 'qualification_scope':'Historical qualified Maintenance Core inherited only for controlled Home admission; exact current component and five interruption receipts verified; new outer gate mandatory. No field or Cafe production claim.',
 'maintenance_core_ref':CORE,'historical_home_package_sha256':OLD,'original_quarantine_sha256':Q,'current_web_sha256':W,
 'evidence_runs':list(PINS),'fixture_outer_sha256':F,'public_update':'Independent; historical fence not covered.',
 'installation':'Explicit operator upload after independent exact outer gate; no automatic deployment; exact preflight and backup required.'})
 with zipfile.ZipFile(out,'w') as z:
  for n,data in sorted({'release-set.json':J(m),'artifacts/local/web.zip':w}.items()):
   i=zipfile.ZipInfo(n,(2026,11,26,0,0,0));i.create_system=3;i.external_attr=0o100644<<16;i.compress_type=zipfile.ZIP_DEFLATED
   z.writestr(i,data,compress_type=zipfile.ZIP_DEFLATED,compresslevel=9)
 print('CONTROLLED_HOME_CANDIDATE_PENDING_EXACT_OUTER_GATE',H(out.read_bytes()))
if __name__=='__main__':build(*map(P,sys.argv[1:]))
