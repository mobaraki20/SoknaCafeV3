#!/usr/bin/env python3
"""Rebuild exact current Sokna Local/Public source from SHA-pinned R306 + two pinned diff envelopes.
Fail closed on any unexpected source tree. No installer or host changes.
"""
from __future__ import annotations
import argparse,base64,hashlib,io,lzma,pathlib,shutil,subprocess,zipfile,json
SHA=lambda b:hashlib.sha256(b).hexdigest()
PIN={
'R306_LOCAL':'1050c045b3da28f19a726109ef487d6e9940f01120cba35c6a41273b1e709209',
'R306_PUBLIC':'e2d78030a7246103a1b4ae2b28409093ebf8e61d44abc3739d73d0ac10b9fe51',
'PATCH_XZ':'4ff14ba300c0143890cce065dc27d7ba0c259b7337f32e3cd19b9b6a8d15eab5',
'ADDITIONS_XZ':'846eb7c9063f52b8840a5963e98ebae72cfbde6989bbd62183453a42e49778c4',
'PATCH':'714122c3707ee8b40d69c83d8551af9bfd908c9d747addfa9c66a09cb098c89e',
}
EXPECTED={
'local':{'files':619,'tree':'349c8385d8403fc7e453680058364144a50b0dd05f80d4a7f4dd327a7db51df5'},
'public':{'files':251,'tree':'50801867f8d272c22a3a8d717043e31ea646d8ee78c9aae9123db109eedbfc99'},
}
def extract_entries(archive,component):
 with zipfile.ZipFile(archive) as z:
  if z.testzip() is not None:raise RuntimeError('R306_ZIP_BAD')
  if component=='local':
   with zipfile.ZipFile(io.BytesIO(z.read('artifacts/local/web.zip'))) as zz:
    return {n:zz.read(n) for n in zz.namelist() if not n.endswith('/')}
  return {n[8:]:z.read(n) for n in z.namelist() if n.startswith('payload/') and not n.endswith('/')}
def src_tree(d):
 return SHA(''.join(n+'\0'+SHA(b)+'\n' for n,b in sorted(d.items())).encode())
def run(args,cwd):
 r=subprocess.run(args,cwd=cwd,capture_output=True,text=True)
 if r.returncode: raise RuntimeError('COMMAND_FAILURE '+str(args)+'\n'+r.stderr[-3000:])
 return r.stdout
def unpack_patch(parts,prefix,want):
 arr=sorted(parts.glob(prefix+'-*.txt'))
 if len(arr)!=(17 if prefix=='patch' else 2):raise RuntimeError('PATCH_PART_MISSING_'+prefix+':'+str(len(arr)))
 data=base64.b64decode(''.join(p.read_text().strip() for p in arr),validate=True)
 if SHA(data)!=want:raise RuntimeError('PATCH_XZ_SHA_FAILED_'+prefix)
 return lzma.decompress(data)
def zip_source(root,out):
 with zipfile.ZipFile(out,'w') as z:
  for comp in ('local','public'):
   for p in sorted((root/comp).rglob('*')):
    if not p.is_file():continue
    rel='source-working/'+comp+'/'+p.relative_to(root/comp).as_posix()
    b=p.read_bytes();zi=zipfile.ZipInfo(rel,(2026,11,24,0,0,0));zi.compress_type=zipfile.ZIP_DEFLATED;zi.external_attr=0o100644<<16
    z.writestr(zi,b,compress_type=zipfile.ZIP_DEFLATED,compresslevel=9)

def main():
 p=argparse.ArgumentParser()
 p.add_argument('--r306-local',type=pathlib.Path,required=True)
 p.add_argument('--r306-public',type=pathlib.Path,required=True)
 p.add_argument('--parts',type=pathlib.Path,required=True)
 p.add_argument('--out',type=pathlib.Path,required=True)
 args=p.parse_args()
 for key,path in [('R306_LOCAL',args.r306_local),('R306_PUBLIC',args.r306_public)]:
  if SHA(path.read_bytes())!=PIN[key]:raise RuntimeError('R306_BASE_SHA_FAILED_'+key)
 root=args.out/'working'
 if root.exists():shutil.rmtree(root)
 root.mkdir(parents=True)
 for comp,path in [('local',args.r306_local),('public',args.r306_public)]:
  for n,b in extract_entries(path,comp).items():
   f=root/comp/n;f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes(b)
 run(['git','init','-q'],root);run(['git','config','user.name','Sokna Release QA'],root);run(['git','config','user.email','qa@sokna.invalid'],root)
 run(['git','add','.'],root);run(['git','commit','-qm','Exact pinned R306 base'],root)
 patch=unpack_patch(args.parts,'patch',PIN['PATCH_XZ'])
 if SHA(patch)!=PIN['PATCH']:raise RuntimeError('PATCH_RAW_SHA_MISMATCH')
 more=unpack_patch(args.parts,'added',PIN['ADDITIONS_XZ'])
 for name,data in [('patch',patch),('added',more)]:
  pp=args.out/(name+'.patch');pp.write_bytes(data)
  run(['git','apply','--check',str(pp)],root)
  run(['git','apply',str(pp)],root)
 receipt={'schema':'sokna-final-factory-pinned-reconstruction-v1','component':{},'input_r306':{'local':PIN['R306_LOCAL'],'public':PIN['R306_PUBLIC']},'patch_sha':PIN['PATCH'],'new_files_patch_xz':PIN['ADDITIONS_XZ']}
 for comp in ('local','public'):
  d={p.relative_to(root/comp).as_posix():p.read_bytes() for p in (root/comp).rglob('*') if p.is_file()}
  actual={'files':len(d),'tree':src_tree(d)}
  if actual!=EXPECTED[comp]:raise RuntimeError('CURRENT_SOURCE_DRIFT_'+comp+str(actual))
  receipt['component'][comp]=actual
 archive=args.out/'SOKNA-REBUILT-CURRENT-SOURCE-EXACT-TREE.zip'
 zip_source(root,archive)
 receipt['rebuilt_source_archive_sha256']=SHA(archive.read_bytes())
 receipt['reference_library_source_sha256']='b3aad22db33a6217383f43ab7cd1a0aa24ba710650955a37142231988a1f9d84'
 receipt['same_file_tree_as_library']=True
 receipt['operator_install_authorized']=False
 (args.out/'SOKNA-REBUILT-SOURCE-RECEIPT.json').write_text(json.dumps(receipt,ensure_ascii=False,indent=2,sort_keys=True)+'\n')
 print(json.dumps(receipt,ensure_ascii=False,indent=2))
if __name__=='__main__':main()