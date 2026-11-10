#!/usr/bin/env python3
"""Exact R152 Local/Public source-built QA candidates from historically pinned packages.
Never grants field/Production authorization: R125 and live host are separate gates.
"""
import io, json, hashlib, pathlib, zipfile, subprocess, shutil, sys, re
sha=lambda b:hashlib.sha256(b).hexdigest()
tree=lambda files:sha(''.join(f'{p}\0{sha(files[p])}\n' for p in sorted(files)).encode())
def check(cond,msg):
 if not cond: raise RuntimeError('R152_EXACT_BUILD: '+msg)
 print('PASS',msg,flush=True)
def read_zip(path):
 with zipfile.ZipFile(path) as z:
  check(z.testzip() is None,'Source ZIP CRC '+str(path))
  names=z.namelist();check(len(names)==len(set(names)),'No duplicate ZIP paths '+str(path))
  for p in names:check(p and not p.startswith(('/', '\\')) and '..' not in pathlib.PurePosixPath(p).parts and '\\' not in p,'Safe ZIP path')
  return {p:z.read(p) for p in names}
def archive(m):
 out=io.BytesIO()
 with zipfile.ZipFile(out,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=6) as z:
  for p,b in sorted(m.items()):
   i=zipfile.ZipInfo(p,(2026,11,10,14,0,0));i.create_system=3;i.external_attr=0o100644<<16;i.compress_type=zipfile.ZIP_DEFLATED
   z.writestr(i,b)
 return out.getvalue()
def jbytes(v):return (json.dumps(v,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode()
def patch_contents(src,scope,temp):
 d=temp/scope;d.mkdir(parents=True,exist_ok=True)
 for p,b in src.items():
  f=d/p;f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes(b)
 patch=pathlib.Path(__file__).parent/f'{scope}-r152-exact-overlay.patch'
 cmd=['patch','-p1','--batch','--forward','-d',str(d),'-i',str(patch)]
 a=subprocess.run(cmd,capture_output=True,text=True)
 check(a.returncode==0,'Apply exact R152 '+scope+' patch: '+a.stderr[:300])
 return {p.relative_to(d).as_posix():p.read_bytes() for p in d.rglob('*') if p.is_file()}
def main():
 if len(sys.argv)!=4:raise SystemExit('build_r152_from_exact_r149.py EXACT_R149B_LOCAL.zip EXACT_35HI_PUBLIC.zip output_dir')
 a,b,out=(pathlib.Path(x).resolve() for x in sys.argv[1:]);out.mkdir(parents=True,exist_ok=True)
 check(sha(a.read_bytes())=='968e11771ec85c02fe4284498ba8d857d2c740958b9103bc45d6f727e20f04d4','Exact audited R149B Local source archive')
 check(sha(b.read_bytes())=='1131d89c98e05c9597f372ed1d480e5637c3ad1969bc8733d2a837b644e57584','Exact audited 35hi Public source archive')
 lm=read_zip(a);pm=read_zip(b);local_base=json.loads(lm['release-set.json']);pub_base=json.loads(pm['manifest.json'])
 old_web=read_zip(io.BytesIO(lm['artifacts/local/web.zip']))
 old_public={p[8:]:v for p,v in pm.items() if p.startswith('payload/')}
 check(len(old_web)==581 and tree(old_web)=='c86988823625e1443c6d4e4071aab05d8f89bffc9753f8ea434f3848d8b2ded1','R149B 581-file exact baseline tree')
 check(len(old_public)==222 and tree(old_public)=='f91aecbf83c2672a4be6527e474f70f0452b8affa8da43c9590886b893a50082','35hi 222-file exact baseline tree')
 temp=out/'_build';local=patch_contents(old_web,'local',temp);pub=patch_contents(old_public,'public',temp)
 check(len(local)==583 and tree(local)=='c3337da171dbceee479e6857e5b65af6bc1082be6f0dd8d4d202a8d3e73106c7','Exact Local R152 source matches Library R152 target')
 check(len(pub)==222 and tree(pub)=='ea1773ad5727e1cc2908d1f666346216b4a4051c36b8605b3fbccf3a41f2fed1','Exact Public R152 source matches Library R152 target')
 changed_local={p for p in set(local)|set(old_web) if local.get(p)!=old_web.get(p)}
 changed_pub={p for p in set(pub)|set(old_public) if pub.get(p)!=old_public.get(p)}
 check(changed_local=={'VERSION.txt','public/assets/scds/guest.css','public/assets/scds/guest.js','resources/guest-themes/sokna-coastal-clarity/theme.json','resources/guest-themes/sokna-modern/theme.json','src/Domain/GuestLan/GuestLanPageRenderer.php'},'R152 only audited six Local source changes')
 check(changed_pub=={'VERSION.txt','assets/scds/guest.css','assets/scds/guest.js','src/Guest/GuestPageRenderer.php'},'R152 only audited four Public source changes')
 # R152's source-only ZIP mistakenly left the previous 581-file inventory.
 inv=json.loads(local['resources/local-managed-files-v1.json']);check(len(inv['files'])==581,'Found known R152 inventory omissions')
 inv['files']=sorted(local)
 local['resources/local-managed-files-v1.json']=jbytes(inv)
 lt=tree(local);check(lt=='7b3d73b98a3876dd345d801417b6c045bd9fed8c84d2349a5cb79be840594c9e','Corrected 583-file target fingerprint')
 check(set(inv['files'])==set(local),'Inventory lists all 583 files')
 sql={p.split('/')[-1][:-4]:sha(v) for p,v in local.items() if re.fullmatch('database/migrations/[0-9]{4}_[a-z0-9_]+\\.sql',p)}
 fp=lambda xs:'migration-ledger-sha256-v1:'+sha(''.join(f'{n}\0{xs[n]}\n' for n in sorted(xs)).encode())
 check(len(sql)==80 and fp(sql)==local_base['targets']['local']['schema_fingerprint'],'80 unchanged migrations and fingerprint')
 check(len(local_base['supported_baselines'])==43,'43 pinned historical Local baselines')
 bases=local_base['supported_baselines']+[{'local':local_base['targets']['local'],'public':None}]
 for v in bases:
  info=v['local'];head=info['migration_head']
  check(head in sql and info['schema_fingerprint']==fp({n:h for n,h in sql.items() if n<=head}),'Exact baseline SQL prefix '+info['version'])
 lm_new=dict(local_base)
 lm_new['supported_baselines']=bases
 lm_new['targets']={'local':{**local_base['targets']['local'],'version':local['VERSION.txt'].decode().strip(),'managed_files':len(local),'managed_tree_sha256':lt,'release_id':'sokna-r152-44base-corrected-source-qa-20261110'}}
 lm_new['release_set_id']=lm_new['targets']['local']['release_id']
 lm_new['sequence']=20261110153
 lm_new['for_install']=False;lm_new['production_pass']=False;lm_new['install_plan']=[];lm_new['signature']=None
 lm_new['status']='R152_44BASE_INVENTORY_CORRECTED_QA_NOT_INSTALL'
 lm_new['qa_notes']={**local_base['qa_notes'],'source':'R149B exact 581 files + six R152 UX changes + corrected inventory','not_install':'R125 database restore and Windows host qualification unresolved','real_cafe_backup_restored':False}
 lm_new['qualification']={**local_base['qualification'],'canary':False,'real_windows':False,'real_mariadb':False,'business_backup_consistency_real_mariadb':False}
 web=archive(local)
 lm_new['artifacts']['local-web']={'component':'local-web','kind':'web-tree-zip-v1','path':'artifacts/local/web.zip','sha256':sha(web),'size_bytes':len(web)}
 lfile=out/'SOKNA-R152-LOCAL-231EC-44BASE-QA-DO-NOT-INSTALL.zip'
 lfile.write_bytes(archive({'release-set.json':jbytes(lm_new),'artifacts/local/web.zip':web}))
 # Emergency and Routine both share this exact Public full format. The live
 # cafe 35hh is a strict source, while 35hi is the previous owner-corrected QA.
 pm_new=dict(pub_base)
 pm_new['version']=pub['VERSION.txt'].decode().strip()
 pm_new['target_managed_tree_sha256']=tree(pub)
 pm_new['compatibility']={'supported_baselines':pub_base['compatibility']['supported_baselines']+[{'version':pub_base['version'],'managed_tree_sha256':pub_base['target_managed_tree_sha256']}]}
 check(len(pm_new['compatibility']['supported_baselines'])==31,'31 exact Public historical sources')
 pm_new['files']=[{'path':p,'size':len(v),'sha256':sha(v)} for p,v in sorted(pub.items())]
 pm_new['provenance']={**pub_base['provenance'],'production_status':'QA_ONLY_NOT_FOR_PRODUCTION','ready_for_production':False,'r152_packaging':'from exact R149B and 35hi source with audited patches','r152_source_change_count':4,'r152_baselines_inherited_unverified':31,'r152_windows_stage':'PENDING','r152_live_public_host':'NOT_RUN','compatible_baselines':31}
 pfile=out/'SOKNA-R152-PUBLIC-35HJ-31BASE-CI-QA-NOT-FIELD-APPROVED.zip'
 pfile.write_bytes(archive({'manifest.json':jbytes(pm_new),**{'payload/'+p:v for p,v in pub.items()}}))
 check(sha(read_zip(pfile)['manifest.json'])==sha(jbytes(pm_new)),'Public final ZIP readback')
 check(sha(read_zip(lfile)['artifacts/local/web.zip'])==lm_new['artifacts']['local-web']['sha256'],'Local final ZIP readback')
 proof={'target_source':'R152 231ec and 35hj, source-only QA from 2026-11-10','local':{'version':lm_new['targets']['local']['version'],'target_tree_sha256':lt,'baselines':44,'installer_ready':False,'sha256':sha(lfile.read_bytes()),'size_bytes':lfile.stat().st_size},'public':{'version':pm_new['version'],'target_tree_sha256':pm_new['target_managed_tree_sha256'],'baselines':31,'physical_cafe_ready':False,'sha256':sha(pfile.read_bytes()),'size_bytes':pfile.stat().st_size},'r125_resolved':False,'field_install_authorized':False,'public_host_installed':False}
 (out/'QA-BUILD-EVIDENCE.json').write_bytes(jbytes(proof))
 (out/'SHA256SUMS.txt').write_text(''.join(f'{sha(p.read_bytes())}  {p.name}\n' for p in (lfile,pfile)))
 print(json.dumps(proof,ensure_ascii=False,sort_keys=True,indent=2))
if __name__=='__main__':main()
