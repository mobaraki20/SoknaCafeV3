import io,json,pathlib,sys,zipfile,copy
from check_owner_admission import H,read,admit,tree
P=pathlib.Path
c2,prior,new=map(lambda s:P(s).read_bytes(),sys.argv[1:])
cm,cd=read(c2);pm,pd=read(prior);nm,nd=read(new)
assert H(c2)=='1d93994d3a013f470eba34c769cb5e3ac595a6724c5558f18bc596684bba9c98'
assert H(prior)=='e2d78030a7246103a1b4ae2b28409093ebf8e61d44abc3739d73d0ac10b9fe51'
assert H(new)=='cd91fb137074e523ecb05d68518cf3a882ebefb751aba1c7004f8b55f256d9a7'
def ctr(b,src):
 m,d=read(b);x,y=read(src)
 return {'format':'sokna-factory-public-origin-contract-v1','candidate_sha256':H(b),'target_version':m['version'],'origins':{H(src):{'version':x['version'],'tree':tree(y)}}}
def build(m,d):
 m=copy.deepcopy(m);m['files']=[{'path':n,'size':len(v),'sha256':H(v)} for n,v in sorted(d.items())];m['target_managed_tree_sha256']=tree(d)
 buf=io.BytesIO()
 with zipfile.ZipFile(buf,'w',zipfile.ZIP_DEFLATED) as z:
  z.writestr('manifest.json',json.dumps(m));[z.writestr('payload/'+n,v) for n,v in d.items()]
 return buf.getvalue()
def narrow(m,origin):
 m=copy.deepcopy(m);m['compatibility']={'supported_baselines':[{'version':origin['version'],'managed_tree_sha256':origin['target_managed_tree_sha256']}]};return m
r=admit(new,[c2],ctr(new,c2));assert not r['static_routine_gate_pass'] and r['origins'][0]['blockers']==['ORIGINAL_SOURCE_NOT_ADMITTED','PROTECTED_OWNER_TRANSITION_REQUIRED'];assert len(r['origins'][0]['protected_changes'])==2
print('PASS actual CP90 original Cafe archive refused (two protected paths and wrong tree)')
r=admit(c2,[prior],ctr(c2,prior));assert r['origins'][0]['routine_package_eligible'] and not r['static_routine_gate_pass'] and len(r['unverified_allowlist_entries'])==3
print('PASS actual historical update compatible per origin; no claim for three unexamined origins')
positive=build(narrow(cm,pm),cd);r=admit(positive,[prior],ctr(positive,prior));assert r['static_routine_gate_pass'] and not r['installation_authorized']
print('PASS exact historical payload with one claimed original baseline passes static gate (test-only manifest)')
f=build(narrow(nm,cm),nd);r=admit(f,[c2],ctr(f,c2));assert r['origins'][0]['blockers']==['PROTECTED_OWNER_TRANSITION_REQUIRED']
print('PASS changing allowlist to original alone cannot authorize protected replacement')
a=copy.deepcopy(nm);a['provenance']['installation_authorized']=True;f=build(a,nd);r=admit(f,[c2],ctr(f,c2));assert not r['static_routine_gate_pass'] and not r['installation_authorized']
print('PASS forged release flag cannot authorize')
d=copy.deepcopy(cd);del d['recovery.php'];f=build(narrow(cm,pm),d);r=admit(f,[prior],ctr(f,prior));assert any(x['path']=='recovery.php' and x['new'] is None for x in r['origins'][0]['protected_changes']) and not r['static_routine_gate_pass']
print('PASS deletion of protected owner rejected')
d=copy.deepcopy(cd);sql=next(n for n in pd if n.startswith('database/migrations/') and n.endswith('.sql'));d[sql]+=b'\n-- drift';f=build(narrow(cm,pm),d);r=admit(f,[prior],ctr(f,prior));assert 'HISTORICAL_MIGRATION_DRIFT' in r['origins'][0]['blockers']
print('PASS historical migration drift rejected')
for desc,fn in [('wrong origin hash',lambda:admit(positive,[c2],ctr(positive,prior))),('wrong package hash',lambda:admit(new,[c2],ctr(positive,prior))),('missing origin',lambda:admit(positive,[],ctr(positive,prior)))]:
 try:fn()
 except ValueError: print('PASS '+desc+' rejected')
 else:raise AssertionError(desc)
print('PASS 10 distinct checks; no release authorization')
