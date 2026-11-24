import pathlib,os,json,hashlib,zipfile,shutil
r=pathlib.Path(os.environ['RUNNER_TEMP']);w=pathlib.Path('/var/www/sokna-public-host-gate');H=lambda b:hashlib.sha256(b).hexdigest()
def get(d,n,sha=None):
    p=list((r/d).rglob(n));assert len(p)==1,(d,n,p)
    if sha:assert H(p[0].read_bytes())==sha
    return p[0]
def read(p):
    with zipfile.ZipFile(p) as z:assert z.testzip() is None;return {n:z.read(n) for n in z.namelist()}
def payload(p):
    d=read(p);m=json.loads(d.pop('manifest.json'));return {n[8:]:v for n,v in d.items()},m
new=get('frozen','PUBLIC-FINAL-FROZEN.zip');target,tm=payload(new)
clean=get('frozen','PUBLIC-FINAL-WEB.zip');full=read(clean)
protected={n:full[n] for n in full if n not in target};assert len(protected)==6
old,om=payload(get('published35io','SOKNA-PUBLIC-35IO-UNIVERSAL-UPDATE.zip','6a8f0d024ce88f2dcd1591c04448083e75527979738ed86980bb721b30630a7e'))
latest,lm=payload(get('published35ip','SOKNA-PUBLIC-35IP-UNIVERSAL-UPDATE.zip','5428105ee9b86b9583637552f1a66a3b29501fd8e7b5556a4dbef7fc53ba8765'))
prior,bm=payload(get('baseline','SOKNA-R306-PUBLIC-35IN-EMERGENCY-PREFLIGHT.zip','e2d78030a7246103a1b4ae2b28409093ebf8e61d44abc3739d73d0ac10b9fe51'))
older,mm=payload(get('r304','SOKNA-R304-PUBLIC-35IM-EMERGENCY-CANDIDATE-NOT-FIELD.zip','d7f702322de0890e5483cd987b46860441aba2612d9f4fdc763735b68951721c'))
c2,cm=payload(get('publishedc2','SOKNA-PUBLIC-35IQ-UNIVERSAL-UPDATE.zip','1d93994d3a013f470eba34c769cb5e3ac595a6724c5558f18bc596684bba9c98'))
for name,f,sub in [('root',target,''),('sub',target,'public/menu'),('upgrade',c2,''),('older',older,'public/menu'),('browser306',prior,''),('current',old,'public'),('latest',latest,'public')]:
    root=w/name/'webroot'/sub
    for n,v in (f|protected).items():
        p=root/n;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(v)
shutil.copyfile(new,w/'update-exact-qa.zip')
(w/'contract.json').write_text(json.dumps({'candidate_sha256':H(new.read_bytes()),'clean_sha256':H(clean.read_bytes()),'target':tm['version'],'target_tree':tm['target_managed_tree_sha256'],'c2':cm['version'],'c2_tree':cm['target_managed_tree_sha256'],'baseline':bm['version'],'older':mm['version'],'older_tree':mm['target_managed_tree_sha256'],'current':om['version'],'current_tree':om['target_managed_tree_sha256'],'latest':lm['version'],'latest_tree':lm['target_managed_tree_sha256'],'protected':{n:H(v) for n,v in protected.items()}}))
