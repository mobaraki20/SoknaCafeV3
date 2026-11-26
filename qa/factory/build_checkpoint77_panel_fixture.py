"""Disposable native positive admission fixture only. Never distribute the outer ZIP."""
import os,sys,pathlib,zipfile,json,hashlib,io
assert os.environ.get('GITHUB_ACTIONS')=='true' and os.environ.get('RUNNER_OS')=='Windows'
P=pathlib.Path;H=lambda b:hashlib.sha256(b).hexdigest()
J=lambda x:(json.dumps(x,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode()
p,h,o=map(P,sys.argv[1:]);r=P(os.environ['RUNNER_TEMP']).resolve()
assert o.resolve().is_relative_to(r) and not o.exists()
assert H(p.read_bytes())=='14cb9ac28637fcc1e9c83dc34ac40765c8a6d2a13ad37cf8c0d40e8e8b359e31'
assert H(h.read_bytes())=='5cbff0e01129ae2c3bc2d4c3b5d55e5a42157f036923c95bbdeafbcf4424ecda'
with zipfile.ZipFile(p) as z:
 m=json.loads(z.read('release-set.json'));w=z.read('artifacts/local/web.zip')
with zipfile.ZipFile(h) as z: old=json.loads(z.read('release-set.json'))
assert H(w)=='2c14b241a3cddf5f1d436144691555b11a97ccc31b4d08b2ea8f8d92f04e3aa6'
assert m['sequence']==202611260004 and not m['for_install'] and not m['production_pass']
assert m['targets']['local']['managed_tree_sha256']=='bafc41567eb3ed3db042855474bf51fdacc2a926094885f561411ae4dae42fa6'
assert len(m['supported_baselines'])==8 and old['targets']['local'] in [x['local'] for x in m['supported_baselines']]
# Admission predicates require a positive manifest. Historical engine flags are
# copied into this disposable CI fixture ONLY, not asserted for current release.
assert old['for_install'] and old['production_pass']
m.update(for_install=True,production_pass=True,qualification=old['qualification'],status='CONTROLLED_HOME_CANARY_FIELD_ACCEPTANCE_PENDING',qa_notes={'scope':'DISPOSABLE_CI_FIXTURE_ONLY_NOT_RELEASE','distribution_authorized':False,'production_accepted':False,'field_install_verified':False,'source_quarantine_sha256':H(p.read_bytes()),'historical_qualification_fixture_only':True})
with zipfile.ZipFile(o,'w') as z:
 for n,b in sorted({'release-set.json':J(m),'artifacts/local/web.zip':w}.items()):
  i=zipfile.ZipInfo(n,(2026,11,26,0,0,0));i.create_system=3;i.external_attr=0o100644<<16;i.compress_type=zipfile.ZIP_DEFLATED
  z.writestr(i,b,compress_type=zipfile.ZIP_DEFLATED,compresslevel=9)
print('DISPOSABLE_POSITIVE_PANEL_FIXTURE_ONLY',H(o.read_bytes()))
