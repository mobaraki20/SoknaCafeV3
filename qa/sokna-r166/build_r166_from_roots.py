#!/usr/bin/env python3
"""Independent R166 reproducible source-only source-tree patch for CI after genuine R162→R165 reconstruction."""
import argparse,hashlib,json,pathlib,zipfile
H=lambda b:hashlib.sha256(b).hexdigest()
C={
'local':{'file':'public/assets/scds/guest.js','tree':'1a86354d558f8544349efc65333871ce1a5376ce37de957e523dd2e2c42e8b88','old':'3e169d1b9e508e39bd654720ce840d48c08417e1557ee6cd07a30519adb1505a','new':'b594cd78edd9be1c7315faff8a50a63f01b4d06488435aab921af589e0d9a29c','count':588,'migrations':80,
'before':'if(!lines.length||!unsafeAllowed()||!orderingEnabled||loadPendingSubmission())return;',
'after':'if(!lines.length||!unsafeAllowed()||!orderingEnabled||loadPendingSubmission()||loadPendingMutation())return;'},
'public':{'file':'assets/scds/guest.js','tree':'1ba7d1b2e2795ed6f084877cd88fe2efef919d9d0299a20de4fe51e5c2e14207','old':'bbbd736af1f36fbfde5d957c0b6865b3e8456866e1ab95e8423e69ad06a1d316','new':'ab47a08c5ebd5cf6a56c4413b57c690ffd3c6b3dc6f0c8cfade56b4b0555e818','count':225,'migrations':8,
'before':'if(!orderCommitAllowed()||(!editingOrder&&loadPendingSubmission()))return;',
'after':'if(!orderCommitAllowed()||loadPendingMutation()||(!editingOrder&&loadPendingSubmission()))return;'}}
def tree(d):return H(''.join(f'{n}\0{H(b)}\n' for n,b in sorted(d.items())).encode())
def makezip(p,d):
 with zipfile.ZipFile(p,'w',zipfile.ZIP_DEFLATED,compresslevel=8) as z:
  for n,b in sorted(d.items()):
   i=zipfile.ZipInfo(n,(2026,11,12,0,0,0));i.compress_type=zipfile.ZIP_DEFLATED;i.external_attr=0o644<<16;z.writestr(i,b,compress_type=zipfile.ZIP_DEFLATED,compresslevel=8)
def main():
 p=argparse.ArgumentParser();p.add_argument('--local-root',required=True);p.add_argument('--public-root',required=True);p.add_argument('--output',required=True);a=p.parse_args()
 dest=pathlib.Path(a.output);dest.mkdir(parents=True,exist_ok=True)
 for part in C:
  spec=C[part];root=pathlib.Path(getattr(a,part+'_root'))
  allfiles={f.relative_to(root).as_posix():f.read_bytes() for f in root.rglob('*') if f.is_file()}
  assert len(allfiles)==spec['count'] and tree(allfiles)==spec['tree'],f'{part} parent different from pinned R165 tree'
  old=allfiles[spec['file']];assert H(old)==spec['old'] and old.count(spec['before'].encode())==1
  new=old.replace(spec['before'].encode(),spec['after'].encode());assert H(new)==spec['new']
  changed=dict(allfiles);changed[spec['file']]=new
  assert len([x for x in changed if changed[x]!=allfiles[x]])==1
  migrations=[x for x in allfiles if x.startswith('database/migrations/')];assert len(migrations)==spec['migrations'] and all(allfiles[x]==changed[x] for x in migrations)
  (dest/'original').mkdir(exist_ok=True);(dest/'candidate').mkdir(exist_ok=True)
  (dest/'original'/f'{part}.js').write_bytes(old);(dest/'candidate'/f'{part}.js').write_bytes(new)
  makezip(dest/f'SOKNA-R166-{part.upper()}-SOURCE-QA-NOT-INSTALL.zip',changed)
  print('PASS',part,'old',spec['tree'],'new',tree(changed),'unchanged',len(allfiles)-1,'migrations',len(migrations))
  (dest/f'{part}-tree.json').write_text(json.dumps({'old_tree':spec['tree'],'new_tree':tree(changed),'changed':spec['file'],'migrations_unchanged':len(migrations),'installable':False},indent=2))
if __name__=='__main__':main()
