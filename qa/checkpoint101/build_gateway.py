import pathlib as P,zipfile,io,hashlib,json,base64,copy,sys
H=lambda b:hashlib.sha256(b).hexdigest();J=lambda x:(json.dumps(x,sort_keys=True,separators=(',',':'),ensure_ascii=False)+'\n').encode()
def read(raw):
 with zipfile.ZipFile(io.BytesIO(raw)) as z:
  assert z.testzip() is None and len(z.namelist())==len(set(z.namelist()));return {n:z.read(n) for n in z.namelist()}
def tree(d):return H(''.join(n+'\0'+H(v)+'\n' for n,v in sorted(d.items())).encode())
meta={'resources/release-identity-v1.json','resources/build-provenance.json'}
def canonical(d):return H(''.join(n+'\0'+str(len(v))+'\0'+H(v)+'\n' for n,v in sorted(d.items()) if n not in meta).encode())
GATE='emergency-owner-gateway-cp101.php'
def php_gateway(paths):
 # Unique gateway has never existed on earlier releases. All paths are generated at build time.
 p=',\n    '.join("'"+n+"'" for n in sorted(paths))
 return ('''<?php
declare(strict_types=1);
/** Fresh entrypoint, not a substitute for host-side exclusion of old requests. */
$deny=static function(): never {http_response_code(503);header('Cache-Control: no-store');echo 'Emergency runtime not qualified; no update action performed.';exit;};
$uri=parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
if(!is_string($uri)||basename($uri)!=='emergency.php'){http_response_code(404);exit;}
foreach(get_declared_classes() as $class)if(str_starts_with(strtolower($class),'sokna\\\\publicedge\\\\'))$deny();
foreach(get_included_files() as $file)if($file!==__FILE__&&str_starts_with($file,__DIR__.'/'))$deny();
$files=[
    '''+p+'''
];
// Refuse a restrictive/missing OPcache API rather than assuming a successful refresh.
if(filter_var(ini_get('opcache.enable'),FILTER_VALIDATE_BOOLEAN)){
    if(!function_exists('opcache_get_status')||!function_exists('opcache_invalidate'))$deny();
    $status=@opcache_get_status(false);
    if(!is_array($status)||($status['opcache_enabled']??false)!==true)$deny();
    if(!empty($status['preload_statistics']))$deny();
    foreach($files as $rel){
        $path=__DIR__.'/'.$rel;
        if(!is_file($path)||is_link($path)||realpath($path)!==$path)$deny();
        if(!@opcache_invalidate($path,true))$deny();
    }
}
// Never infer request drain from opcode invalidation. The transition still requires
// the separately trusted host drain provider and all original journal/DB guards.
require __DIR__.'/emergency-next.php';
''').encode()
def build(raw):
 assert H(raw)=='f563b4328616862d352c1fcaa4be8b44c3604f2586c866ff9c2f46ac273cd904';z=read(raw);m=json.loads(z.pop('manifest.json'));d={n[8:]:b for n,b in z.items()};q=json.loads(base64.b64decode(m['provenance']['protected_owner_transition']['plan_b64']));assert H(J(q))=='cd9c57199ffb10029523c72bb0a2f947172a8daf399c175411349263dd1c51cc';orig=dict(d)
 assert GATE not in d
 d[GATE]=php_gateway({n for n in d if n.endswith('.php')}|{n for n in q['stable_before'] if n.endswith('.php')}|{'config.php'})
 a=d['.htaccess'].decode();old='RewriteRule ^emergency\\.php$ emergency-next.php [END]';assert a.count(old)==1
 d['.htaccess']=a.replace(old,'RewriteRule ^emergency-owner-gateway-cp101\\.php(?:/|$) - [F,L,NC]\nRewriteRule ^emergency\\.php$ '+GATE+' [END]').encode()
 i=json.loads(d['resources/release-identity-v1.json']);i['source'].update(managed_file_count=len(d),canonical_file_count=len(d)-2,canonical_tree_sha256=canonical(d));d['resources/release-identity-v1.json']=J(i)
 prov=json.loads(d['resources/build-provenance.json']);prov['operator_warning']='QA only: unique fresh gateway and explicit per-file invalidation; independently trusted preactivation drain still mandatory.';d['resources/build-provenance.json']=J(prov)
 q['source_tree']=tree(d);q['managed_before']={n:H(v) for n,v in d.items()};post=dict(d)
 for n,x in q['patch'].items():
  if n not in meta and n in post:assert H(d[n])==x['before'];post[n]=base64.b64decode(x['bytes_b64'])
 ii=copy.deepcopy(i);ii['source']['canonical_tree_sha256']=canonical(post);ii['qualification']={'tier':'OWNER_TRANSITION_QA_ONLY','claim_rule':'Fresh gateway and exact pending owner transition, laboratory only.'};post['resources/release-identity-v1.json']=J(ii)
 pv=copy.deepcopy(prov);pv['classification']='OWNER_TRANSITION_QA_ONLY';post['resources/build-provenance.json']=J(pv)
 for n in meta:q['patch'][n]={'before':H(d[n]),'after':H(post[n]),'bytes_b64':base64.b64encode(post[n]).decode()}
 q['managed_after']={n:H(v) for n,v in post.items()};q['target_tree']=tree(post);q['target_canonical']=canonical(post);plan=J(q)
 m['target_managed_tree_sha256']=tree(d);m['files']=[{'path':n,'size':len(v),'sha256':H(v)} for n,v in sorted(d.items())];m['provenance']['protected_owner_transition']={'format':q['format'],'plan_b64':base64.b64encode(plan).decode(),'plan_sha256':H(plan)}
 assert m['provenance']['installation_authorized'] is False and q['distribution_authorized'] is False
 assert {n for n in d if orig.get(n)!=d[n]}==meta|{GATE,'.htaccess'}
 return m,d,q

def write(path,d):
 with zipfile.ZipFile(path,'w') as z:
  for n,v in sorted(d.items()):
   i=zipfile.ZipInfo(n,(2026,11,26,0,0,0));i.create_system=3;i.external_attr=0o100644<<16;i.compress_type=zipfile.ZIP_DEFLATED;z.writestr(i,v,compresslevel=9)
 assert read(path.read_bytes())==d
if __name__=='__main__':
 inp,out=map(P.Path,sys.argv[1:]);m,d,q=build(inp.read_bytes());out.mkdir(parents=True,exist_ok=True);p=out/'bridge.zip';write(p,{'manifest.json':J(m)}|{'payload/'+n:v for n,v in d.items()});(out/'plan.json').write_bytes(J(q));(out/GATE).write_bytes(d[GATE]);print(json.dumps({'bridge_sha':H(p.read_bytes()),'bridge_tree':tree(d),'post_tree':q['target_tree'],'plan_sha':H(J(q)),'gateway_sha':H(d[GATE]),'scope':'QA_ONLY'}))
