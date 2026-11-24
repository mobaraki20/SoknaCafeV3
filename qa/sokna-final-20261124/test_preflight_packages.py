#!/usr/bin/env python3
"""Independent fail-closed checks for consolidated structural packages."""
import copy,hashlib,io,json,os,pathlib,re,zipfile
ROOT=pathlib.Path(os.environ.get('SOKNA_RELEASE_BUILD_DIR', '/mnt/data/sokna_release_build'))/'preflight'
H=lambda b:hashlib.sha256(b).hexdigest()

def zread(b):
 with zipfile.ZipFile(io.BytesIO(b)) as z:
  assert z.testzip() is None
  n=z.namelist();assert len(n)==len(set(n)) and not any('../' in x for x in n)
  return {x:z.read(x) for x in n}

def structural_local(blob,require_installable=False):
 outer=zread(blob)
 assert set(outer)=={'release-set.json','artifacts/local/web.zip'},'bad-package-shape'
 m=json.loads(outer['release-set.json']);web=outer['artifacts/local/web.zip']
 assert m['format']=='sokna-maintenance-release-set-v2.1' and m['schema_version']=='2.1'
 assert m['release_type']=='local_only'
 assert m['artifacts']['local-web']['path']=='artifacts/local/web.zip'
 assert m['artifacts']['local-web']['sha256']==H(web),'web-sha-mismatch'
 assert m['artifacts']['local-web']['size_bytes']==len(web)
 assert [(x['phase'],x['mutation']) for x in m['install_plan']]==[('preflight',False),('stage',False),('activate',True)]
 assert m['pre_update_backup_policy']['required'] and m['pre_update_backup_policy']['system_recovery_snapshot_required']
 assert m['pre_update_backup_policy']['on_failure']=='reject_pre_mutation'
 assert m['database']['local']['rollback_after_boundary']=='forbidden'
 assert len(m['supported_baselines'])==3
 assert len({(x['local']['version'],x['local']['managed_tree_sha256']) for x in m['supported_baselines']})==3
 files=zread(web);assert len(files)==m['targets']['local']['managed_files']==619
 assert files['VERSION.txt'].decode().strip()==m['targets']['local']['version']
 assert H(''.join(n+'\0'+H(v)+'\n' for n,v in sorted(files.items())).encode())==m['targets']['local']['managed_tree_sha256']
 assert set(json.loads(files['resources/local-managed-files-v1.json'])['files'])==set(files)
 assert len([n for n in files if re.match(r'database/migrations/\d{4}_.*\.sql$',n)])==88
 assert m['database']['local']['migration_dag'][-5:]==[
  '0084_remaining_account_discount_revisions','0085_whole_order_cancellations',
  '0086_order_cancellation_batches','0087_partial_order_payment_reversals',
  '0088_historical_variant_order_snapshots']
 if require_installable:
  assert m['for_install'] is True and m['production_pass'] is True,'NOT_INSTALLABLE_NOT_QUALIFIED'
 return True

def structural_public(blob,require_installable=False):
 outer=zread(blob)
 m=json.loads(outer.pop('manifest.json'))
 assert m['format']=='sokna-component-package-v1' and m['schema_version']==1
 assert m['component']=='public-edge'
 assert len(m['compatibility']['supported_baselines'])==2
 assert len({(x['version'],x['managed_tree_sha256']) for x in m['compatibility']['supported_baselines']})==2
 files={n[8:]:b for n,b in outer.items() if n.startswith('payload/')}
 assert len(files)==251 and len(outer)==251
 assert files['VERSION.txt'].decode().strip()==m['version']
 assert m['target_managed_tree_sha256']==H(''.join(n+'\0'+H(b)+'\n' for n,b in sorted(files.items())).encode())
 assert len(m['files'])==len(files)
 assert {x['path'] for x in m['files']}==set(files)
 assert all(H(files[x['path']])==x['sha256'] and len(files[x['path']])==x['size'] for x in m['files'])
 assert json.loads(files['src/Update/emergency-owner-v1.json'])['sha256']==H(files['src/Update/EmergencyOwnerCandidate.php'])
 if require_installable:
  assert m['provenance'].get('installation_authorized') is True,'PUBLIC_NOT_QUALIFIED'
 return True

lp=ROOT/'SOKNA-LOCAL-CONSOLIDATED-MAINTENANCE-PREFLIGHT-DO-NOT-INSTALL.zip'
pp=ROOT/'SOKNA-PUBLIC-CONSOLIDATED-ROUTINE-EMERGENCY-PREFLIGHT-DO-NOT-INSTALL.zip'
assert structural_local(lp.read_bytes())
assert structural_public(pp.read_bytes())
results={'local_structure':'PASS','public_structure':'PASS','failed_negative_checks':[]}

def fail_expected(name,f):
 try:f()
 except (AssertionError,ValueError,KeyError,zipfile.BadZipFile) as e:
  results['failed_negative_checks'].append({'name':name,'result':'REJECTED_AS_REQUIRED','reason':str(e)[:120]})
 else:raise RuntimeError('FAIL_OPEN_NEGATIVE:'+name)

fail_expected('local-install-not-qualified',lambda:structural_local(lp.read_bytes(),True))
fail_expected('public-install-not-qualified',lambda:structural_public(pp.read_bytes(),True))
outer=zread(lp.read_bytes());m=json.loads(outer['release-set.json']);m['artifacts']['local-web']['sha256']='0'*64
bad=json.dumps(m,ensure_ascii=False).encode()
def rezip(d):
 b=io.BytesIO()
 with zipfile.ZipFile(b,'w') as z:
  for k,v in d.items():z.writestr(k,v)
 return b.getvalue()
fail_expected('local-payload-sha-mismatch',lambda:structural_local(rezip({**outer,'release-set.json':bad})))
outer=zread(pp.read_bytes());m=json.loads(outer['manifest.json']);m['files'][0]['sha256']='0'*64
fail_expected('public-payload-sha-mismatch',lambda:structural_public(rezip({**outer,'manifest.json':json.dumps(m).encode()})))
assert len(results['failed_negative_checks'])==4
results.update({'status':'PASS_STRUCTURAL_AND_FAIL_CLOSED','operator_install_authorized':False})
(ROOT/'SOKNA-PREFLIGHT-INDEPENDENT-GATES-20261124.json').write_text(json.dumps(results,ensure_ascii=False,indent=2)+'\n')
print(json.dumps(results,ensure_ascii=False,indent=2))