#!/usr/bin/env python3
"""Reproducibly build a fail-closed, host-local R247 stable Emergency recovery kit.
No production write performed by this builder.
"""
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED
from hashlib import sha256
import json, gzip, base64
R=Path('/mnt/data/sokna_emergency'); OUT=R/'R247-OWNER-RECOVERY';OUT.mkdir(exist_ok=True)
with ZipFile(R/'SOKNA-R171-PUBLIC-35HT-FROM-35HK-PANEL-UPDATE.zip') as z:
    gui=z.read('payload/emergency-next.php').decode('utf-8')
    old_meta=json.loads(z.read('payload/src/Update/emergency-owner-v1.json'))
    old_version=z.read('payload/VERSION.txt').decode().strip()
    expected_tree=json.loads(z.read('manifest.json'))['target_managed_tree_sha256']
    sha={x:sha256(z.read('payload/'+x)).hexdigest() for x in ['emergency-next.php','src/Update/RoutinePublicUpdateService.php','src/Update/IndependentEmergencyRoutineBridge.php','src/Update/EmergencyOwnerCandidate.php','src/Update/emergency-owner-v1.json']}
with ZipFile('/mnt/data/SOKNA-Public-35es-UNIFIED-SOURCE-QUALIFICATION-20261024.zip') as z:
    modern_access=z.read('source/public/src/Emergency/EmergencyAccessService.php')
new_version='3.0.0-g3.6-workspace.35ib-r246-emergency-bridge'
old_owner=old_meta['release_version']
# Pin the SINGLE historical R171 mismatch, with both owner code and bridge pinned.
prefix="""/**
 * R247 read-only source reconciliation for one proven historical R171 baseline.
 * No metadata or Routine state is rewritten; all writes remain with Routine v2.
 */
function g39_r247_legacy_owner_exception(string $root, string $current, string $rel, string $sha): bool {
    if(!hash_equals('__OLD_VERSION__', $current) ||
       !hash_equals('__OLD_OWNER__', $rel) ||
       !hash_equals('__OWNER_SHA__', $sha))return false;
    $pins=[
        'emergency-next.php'=>'__GUI_SHA__',
        'src/Update/RoutinePublicUpdateService.php'=>'__ROUTINE_SHA__',
        'src/Update/IndependentEmergencyRoutineBridge.php'=>'__BRIDGE_SHA__',
        'src/Update/EmergencyOwnerCandidate.php'=>'__OWNER_SHA__',
        'src/Update/emergency-owner-v1.json'=>'__META_SHA__',
    ];
    foreach($pins as $p=>$h){
        $file=$root.'/'.$p;
        if(!is_file($file)||!is_readable($file)||!hash_equals($h,(string)hash_file('sha256',$file)))return false;
    }
    return true;
}

"""
repl={'__OLD_VERSION__':old_version,'__OLD_OWNER__':old_owner,'__OWNER_SHA__':sha['src/Update/EmergencyOwnerCandidate.php'],'__GUI_SHA__':sha['emergency-next.php'],'__ROUTINE_SHA__':sha['src/Update/RoutinePublicUpdateService.php'],'__BRIDGE_SHA__':sha['src/Update/IndependentEmergencyRoutineBridge.php'],'__META_SHA__':sha['src/Update/emergency-owner-v1.json']}
for k,v in repl.items():prefix=prefix.replace(k,v)
needle='function g39_load_owner(string $root,string $storage): array\n'
assert gui.count(needle)==1
gui=gui.replace(needle,prefix+needle)
needle="||!preg_match('/^[a-f0-9]{64}$/D',$sha)||$rel===''||!hash_equals($current,$rel)"
assert gui.count(needle)==1
gui=gui.replace(needle,"||!preg_match('/^[a-f0-9]{64}$/D',$sha)||$rel===''||(!hash_equals($current,$rel)&&!g39_r247_legacy_owner_exception($root,$current,$rel,$sha))")
# Distinguish this version for serviceability.
gui=gui.replace('<!-- SOKNA G39', '<!-- SOKNA G39')
assert b'IndependentEmergencyRoutineBridge' in gui.encode()
ui_bytes=gui.encode('utf-8')
(OUT/'emergency.php').write_bytes(ui_bytes)
(OUT/'EmergencyAccessService.php').write_bytes(modern_access)
print(json.dumps({'original_r171':old_version,'from_metadata':old_owner,'r247_emergency_sha256':sha256(ui_bytes).hexdigest(),'modern_access_sha256':sha256(modern_access).hexdigest(),'r171_tree':expected_tree,'pins':sha},indent=2))
# Build a single-browser-file installer, credential protected and fail closed.
manifest={'release':'R247 emergency stable-owner recovery', 'source_baseline':old_version,'target_managed_release':new_version,'source_tree_sha256':expected_tree,'source_gui_sha256':sha['emergency-next.php'],'source_owner_sha256':sha['src/Update/EmergencyOwnerCandidate.php'],'new_emergency_sha256':sha256(ui_bytes).hexdigest(),'new_access_sha256':sha256(modern_access).hexdigest(),'legacy_emergency_git_blob_sha1':'85b028d832788a06d5d6ba9f8255b8094f50faee','legacy_access_git_blob_sha1':'54e2ed8fcd8100863ca3686a22789d5a1b46766e'}
(OUT/'R247-PINS.json').write_text(json.dumps(manifest,ensure_ascii=False,indent=2)+'\n')
# PHP installer source is separately authored, with placeholders for a frozen payload.
installer_src=(R/'r247_installer_template.php').read_text()
assert installer_src.count('__EMERGENCY_BYTES_GZ_BASE64__')==1 and installer_src.count('__ACCESS_BYTES_GZ_BASE64__')==1
installer_src=installer_src.replace('__EMERGENCY_BYTES_GZ_BASE64__',base64.b64encode(gzip.compress(ui_bytes,compresslevel=9,mtime=0)).decode())
installer_src=installer_src.replace('__ACCESS_BYTES_GZ_BASE64__',base64.b64encode(gzip.compress(modern_access,compresslevel=9,mtime=0)).decode())
for k,v in {'__R171_VERSION__':old_version,'__R171_TREE_SHA__':expected_tree,'__R171_GUI_SHA__':sha['emergency-next.php'],'__R171_ROUTINE_SHA__':sha['src/Update/RoutinePublicUpdateService.php'],'__R171_BRIDGE_SHA__':sha['src/Update/IndependentEmergencyRoutineBridge.php'],'__R171_OWNER_SHA__':sha['src/Update/EmergencyOwnerCandidate.php'],'__R171_META_SHA__':sha['src/Update/emergency-owner-v1.json'],'__R171_OWNER_RELEASE__':old_owner,'__LEGACY_EMERGENCY_GIT_SHA1__':'85b028d832788a06d5d6ba9f8255b8094f50faee','__LEGACY_ACCESS_GIT_SHA1__':'54e2ed8fcd8100863ca3686a22789d5a1b46766e','__MODERN_ACCESS_SHA__':sha256(modern_access).hexdigest(),'__R247_GUI_SHA__':sha256(ui_bytes).hexdigest()}.items():
 assert installer_src.count(k)>0,k
 installer_src=installer_src.replace(k,v)
assert not any(x in installer_src for x in ['__EMERGENCY_BYTES_GZ_BASE64__','__ACCESS_BYTES_GZ_BASE64__','__R171_VERSION__'])
(OUT/'sokna-emergency-r247-safe-installer.php').write_text(installer_src)
with ZipFile(OUT/'SOKNA-R247-EMERGENCY-SAFE-OWNER-REPAIR-OPERATOR-KIT.zip','w',ZIP_DEFLATED,compresslevel=9) as z:
 for p in ['sokna-emergency-r247-safe-installer.php','R247-PINS.json']:
  z.write(OUT/p,'tools/'+p)
print('R247 kit built at',OUT)
