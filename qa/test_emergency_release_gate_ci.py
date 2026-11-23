#!/usr/bin/env python3
"""GitHub-hosted, zero-secrets offline regression for Sokna emergency release gate.
Creates an ephemeral 6-file fake Public package; never connects to a host.
"""
from __future__ import annotations
import hashlib,io,json,pathlib,sys,tempfile,zipfile,unittest,copy
sys.path.insert(0,str(pathlib.Path(__file__).resolve().parent))
import emergency_release_gate as gate

def sha(b):return hashlib.sha256(b).hexdigest()
def make_fixture():
  version='3.0.0-g3.6-workspace.35zz-r999-emergency-gate-ci-only'
  source={
      '.htaccess':b'RewriteEngine On\nRewriteRule ^(\\.htaccess|\\.htpasswd|\\.env|\\.user.ini|\\.git|\\.svn)(/|$) - [F,L,NC]\n',
      'VERSION.txt':(version+'\n').encode(),
      'bootstrap.php':b'<?php // synthetic fixture, not production code\n',
      'emergency-next.php':b'<?php // synthetic private route\n',
      'src/Update/EmergencyOwnerCandidate.php':b'<?php // synthetic candidate owner\n',
  }
  source['src/Update/emergency-owner-v1.json']=json.dumps({'format':'sokna-emergency-owner-v1','release_version':version,'sha256':sha(source['src/Update/EmergencyOwnerCandidate.php'])}).encode()
  files=[{'path':p,'sha256':sha(data),'size':len(data)} for p,data in sorted(source.items())]
  d=hashlib.sha256()
  for e in files:d.update(e['path'].encode()+b'\0'+e['sha256'].encode()+b'\n')
  manifest={'format':'sokna-component-package-v1','component':'public-edge','version':version,'target_managed_tree_sha256':d.hexdigest(), 'files':files,'compatibility':{'supported_baselines':[{'version':'3.0.0-g3.6-workspace.35zy-r998-previous','managed_tree_sha256':'a'*64}]}}
  return manifest,source

def pack(manifest,source,extra=None):
  data=io.BytesIO()
  with zipfile.ZipFile(data,'w',compression=zipfile.ZIP_DEFLATED) as f:
    f.writestr('manifest.json',json.dumps(manifest))
    for n,b in source.items():f.writestr('payload/'+n,b)
    for n,b in (extra or []):f.writestr(n,b)
  return data.getvalue()

class StrictReleaseGateCI(unittest.TestCase):
  def setUp(self):
    self.temp=tempfile.TemporaryDirectory(prefix='sokna-ci-');self.m,self.src=make_fixture();self.p=pathlib.Path(self.temp.name)/'fake.zip'
  def tearDown(self):self.temp.cleanup()
  def run_gate(self,m=None,source=None,extra=None,raw=None):
    self.p.write_bytes(raw if raw is not None else pack(m if m is not None else self.m,source if source is not None else self.src,extra))
    return gate.validate_release(self.p)
  def test_good_structural_package_is_never_production_authorized(self):
    r=self.run_gate();self.assertEqual(r['result'],'PASS_STATIC_QA_ONLY',r);self.assertFalse(r['production_authorized'])
  def test_modified_bytes_rejected(self):
    src=self.src.copy();src['bootstrap.php']=b'changed';self.assertEqual(self.run_gate(source=src)['result'],'FAIL_CLOSED')
  def test_bad_owner_rejected(self):
    src=self.src.copy();src['src/Update/emergency-owner-v1.json']=b'{}';self.assertEqual(self.run_gate(source=src)['result'],'FAIL_CLOSED')
  def test_protected_file_rejected(self):
    src=self.src.copy();src['emergency.php']=b'x';self.assertEqual(self.run_gate(source=src)['result'],'FAIL_CLOSED')
  def test_unlisted_file_rejected(self):
    self.assertEqual(self.run_gate(extra=[('payload/stolen.php',b'x')])['result'],'FAIL_CLOSED')
  def test_missing_dotfile_policy_rejected(self):
    src=self.src.copy();src['.htaccess']=b'RewriteEngine On';self.assertEqual(self.run_gate(source=src)['result'],'FAIL_CLOSED')
  def test_unsafe_path_rejected(self):
    self.assertEqual(self.run_gate(extra=[('payload/../config.php',b'x')])['result'],'FAIL_CLOSED')
  def test_duplicate_member_rejected(self):
    self.assertEqual(self.run_gate(extra=[('payload/VERSION.txt',b'x')])['result'],'FAIL_CLOSED')
  def test_downgrade_rejected(self):
    m=copy.deepcopy(self.m);m['compatibility']['supported_baselines'][0]['version']=m['version'];self.assertEqual(self.run_gate(m=m)['result'],'FAIL_CLOSED')
  def test_tree_hash_rejected(self):
    m=copy.deepcopy(self.m);m['target_managed_tree_sha256']='b'*64;self.assertEqual(self.run_gate(m=m)['result'],'FAIL_CLOSED')
  def test_corrupt_zip_rejected(self):self.assertEqual(self.run_gate(raw=b'corrupt')['result'],'FAIL_CLOSED')
  def test_missing_version_rejected(self):
    src=self.src.copy();src.pop('VERSION.txt');self.assertEqual(self.run_gate(source=src)['result'],'FAIL_CLOSED')
  def test_double_baseline_rejected(self):
    m=copy.deepcopy(self.m);m['compatibility']['supported_baselines']*=2;self.assertEqual(self.run_gate(m=m)['result'],'FAIL_CLOSED')

if __name__=='__main__':unittest.main(verbosity=2)