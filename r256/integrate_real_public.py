#!/usr/bin/env python3
"""R256 QA ONLY: pin and extend the *actual* R249/35IC Public package.

This script produces an extracted, non-installable QA workspace and a proof.
It DOES NOT produce a deployable ZIP, call production, or change main/CURRENT.
No customer domain, tenant, installation ID, secret, or version is hardcoded
in the new runtime code. The exact R249 hash is a *source acceptance pin*.
"""
from __future__ import annotations
import argparse, hashlib, json, os, pathlib, re, shutil, sys, zipfile

PIN_R249 = '5c887968b6fcec1126e2b1b3123552a9ba0ba2c7672d29774c05ec58fa5cd0e4'
EXPECTED_TREE = '1a5d041fd63ec5425ed9f01f0eff63c86c90c5c76f26530b773784c74884c928'
OLD_MIGRATIONS = [f'{x:04d}' for x in range(1, 9)]
PIN_FILES = {
    'src/Setup/PublicSetupService.php':'e5ee834758ce645a9e0451797d68f1bd263ab8b2c204ea49f899d9a85a82e84c',
    'setup/index.php':'863ed623103e381446a73376f365502ad2011f5ed7aaf8d51578fa3f383fa16a',
    'setup/setup.js':'f8d63486bdadf677f4ac5ceda4bcadaf972d20c46f812951f40fe004246b1add',
    'src/Http/PublicHttpKernel.php':'01b6f446dc520994bd0ed4401c7319fe4876964877adcb9a613f4f1b248cd68c',
    'bootstrap.php':'e6b82473c89f98025740dce1d816d2ae8c7804c42c21ffdae4a8d1980849308a',
    'setup/api.php':'537f29fc443629a0667c7447d3bbca0c7a5f12c372250833b968d15ffabd4ece',
}

def sha(b:bytes)->str: return hashlib.sha256(b).hexdigest()
def req(cond,msg):
    if not cond: raise AssertionError('R256_GATE_'+msg)

def edit_one(files:dict[str,bytes], path:str, old:str, new:str):
    data = files[path].decode('utf-8')
    req(data.count(old)==1,'anchor_'+path.replace('/','_'))
    files[path]=data.replace(old,new,1).encode('utf-8')

def main():
    p=argparse.ArgumentParser()
    p.add_argument('--r249',type=pathlib.Path,required=True)
    p.add_argument('--repo',type=pathlib.Path,help='R256 GitHub checkout containing R255 classes')
    p.add_argument('--out',type=pathlib.Path,required=True)
    p.add_argument('--audit-only',action='store_true')
    a=p.parse_args()
    req(a.r249.is_file(),'baseline_missing')
    req(sha(a.r249.read_bytes())==PIN_R249,'baseline_bytes_changed')
    with zipfile.ZipFile(a.r249) as z:
        req(z.testzip() is None,'baseline_zip_corrupt')
        manifest=json.loads(z.read('manifest.json'))
        files={f.filename[8:]:z.read(f) for f in z.infolist() if f.filename.startswith('payload/') and not f.is_dir()}
    req(len(files)==228,'baseline_file_count')
    req(manifest['target_managed_tree_sha256']==EXPECTED_TREE,'baseline_tree_pin')
    for name,pinned in PIN_FILES.items():req(name in files and sha(files[name])==pinned,'baseline_'+name.replace('/','_'))
    existing={name:b for name,b in files.items() if name.startswith('database/migrations/')}
    req(len(existing)==8 and sorted(p.split('/')[-1][:4] for p in existing)==OLD_MIGRATIONS,'baseline_eight_migrations')
    req(not any('..' in pathlib.PurePosixPath(n).parts or n.startswith('/') for n in files),'zip_slip')
    proof={'format':'sokna-r256-real-baseline-qa-v1','baseline_sha256':PIN_R249,
           'baseline_tree_sha256':EXPECTED_TREE,'baseline_files':len(files),'baseline_migrations':8,
           'customer_specific_defaults':False,'main_changed':False,'production_modified':False,
           'installable':False,'cutover_allowed':False,'candidate_generated':False}
    if a.audit_only:
        a.out.mkdir(parents=True,exist_ok=True)
        (a.out/'R256-BASELINE-AUDIT.json').write_text(json.dumps(proof,indent=2)+'\n')
        print('R256_PINNED_R249_REAL_BASELINE_AUDIT_PASS')
        return
    req(a.repo is not None and a.repo.is_dir(),'repo_checkout_required')
    original_hashes={name:sha(data) for name,data in files.items()}
    src=[
        'Security/PublicMoveOwnerBootstrapService.php',
        'Security/PublicMoveIndependentOwnerBridge.php',
        'Security/PublicMoveOwnerConsentGuard.php',
        'Security/PublicMoveInstanceKeyStore.php',
        'Security/PublicMoveAttestationIssuer.php',
        'Http/PublicMoveOwnerHttpAdapter.php',
        'Setup/PublicMoveOwnerPrivatePath.php',
    ]
    for short in src:
        source=a.repo/'apps/public/src'/short
        req(source.is_file(),'missing_qa_code_'+short.replace('/','_'))
        files['src/'+short]=source.read_bytes()

    migration=a.repo/'r256/overlay/database/migrations/0009_public_move_owner_setup.sql'
    req(migration.is_file(),'missing_public_owner_migration')
    files['database/migrations/0009_public_move_owner_setup.sql']=migration.read_bytes()
    original_setup=files['src/Setup/PublicSetupService.php'].decode('utf-8')
    req('private readonly string $root' in original_setup,'setup_structure_changed')

    # Wire classes into *real* R249 bootstrap, never replace with old apps/public/bootstrap.php.
    anchor="require_once __DIR__ . '/src/Setup/PublicSetupService.php';"
    edit_one(files,'bootstrap.php',anchor,
        "require_once __DIR__ . '/src/Setup/PublicMoveOwnerPrivatePath.php';\n"+anchor)
    anchor="require_once __DIR__ . '/src/Security/SignedLocalRequestVerifier.php';"
    extra='\n'.join("require_once __DIR__ . '/src/"+short+"';" for short in src if short.startswith('Security/'))
    edit_one(files,'bootstrap.php',anchor,anchor+'\n'+extra)
    anchor="require_once __DIR__ . '/src/Http/PublicHttpKernel.php';"
    edit_one(files,'bootstrap.php',anchor,
        "require_once __DIR__ . '/src/Http/PublicMoveOwnerHttpAdapter.php';\n"+anchor)

    # Add owner password in same existing installation form: no extra wizard or advanced options.
    edit_one(files,'setup/index.php',
        '<label>رمز دسترسی اضطراری<input data-emergency type="password" autocomplete="new-password"></label>',
        '<label>رمز مدیریت وب عمومی<input data-owner type="password" autocomplete="new-password" minlength="12"></label>'
        '<p class="hint">برای تأیید انتقال‌های آینده؛ این رمز را نزد خود نگه دارید.</p>'
        '<label>رمز دسترسی اضطراری<input data-emergency type="password" autocomplete="new-password"></label>')
    edit_one(files,'setup/setup.js',
        "emergency_password:$('[data-emergency]').value,routing_probe_ok:true",
        "emergency_password:$('[data-emergency]').value,owner_password:$('[data-owner]').value,routing_probe_ok:true")

    # No additional network/installer option; owner creation is bound to successful
    # DB-credential authenticated setup, before install.lock becomes visible.
    edit_one(files,'src/Setup/PublicSetupService.php',
        "use PDO;use Sokna\\PublicEdge\\Core\\Config;",
        "use Sokna\\PublicEdge\\Security\\PublicMoveOwnerBootstrapService;"
        "use Sokna\\PublicEdge\\Security\\PublicMoveInstanceKeyStore;"
        "use PDO;use Sokna\\PublicEdge\\Core\\Config;")
    edit_one(files,'src/Setup/PublicSetupService.php',
        "$emergency=(string)($body['emergency_password']??'');if(strlen($emergency)<10)",
        "$ownerPassword=(string)($body['owner_password']??'');"
        "if(strlen($ownerPassword)<12||strlen($ownerPassword)>256)"
        "throw new SetupException('owner_password_invalid','رمز مدیریت باید حداقل ۱۲ نویسه باشد.',422);"
        "$emergency=(string)($body['emergency_password']??'');if(strlen($emergency)<10)")
    edit_one(files,'src/Setup/PublicSetupService.php',
        "if(!is_dir($storage)&&!@mkdir($storage,0700,true)&&!is_dir($storage))",
        "$privateOwnerDir=PublicMoveOwnerPrivatePath::prepare($this->root,(string)($_SERVER['DOCUMENT_ROOT']??$this->root));"
        "if(!is_dir($storage)&&!@mkdir($storage,0700,true)&&!is_dir($storage))")
    edit_one(files,'src/Setup/PublicSetupService.php',
        "'auth'=>['session_ttl_seconds'=>28800,'failure_limit'=>5,'failure_window_seconds'=>900,'block_seconds'=>900]];",
        "'auth'=>['session_ttl_seconds'=>28800,'failure_limit'=>5,'failure_window_seconds'=>900,'block_seconds'=>900],"
        "'security'=>['move_owner_private_dir'=>$privateOwnerDir,"
        "'move_owner_csrf_key_base64'=>base64_encode(random_bytes(32))]];")
    edit_one(files,'src/Setup/PublicSetupService.php',
        "$code=$this->createPairingCode($storage);$this->writeJson($this->root.'/install.lock'",
        "$code=$this->createPairingCode($storage);$publicOwnerId='public-'.bin2hex(random_bytes(12));"
        "$now=time();$owner=new PublicMoveOwnerBootstrapService($pdo);"
        "$claim=$owner->stageClaim($publicOwnerId,$now);"
        "$owner->initialize($publicOwnerId,$claim['setup_token'],$ownerPassword,$now);"
        "(new PublicMoveInstanceKeyStore($privateOwnerDir,$this->root))->provision($publicOwnerId,true);"
        "$this->writeJson($this->root.'/install.lock'")
    edit_one(files,'src/Setup/PublicSetupService.php',
        "'public_instance_id'=>'public-'.bin2hex(random_bytes(12))",
        "'public_instance_id'=>$publicOwnerId")
    edit_one(files,'src/Setup/PublicSetupService.php',
        "'next'=>'pair'];}","'next'=>'pair','owner_initialized'=>true];}")

    # Expose only authenticated owner actions. Never expose claim generation.
    route="""            if ($method === 'POST' && preg_match('#^/api/v1/owner/(login|logout|consent_prepare|consent_sign)$#D', $path, $moveMatch) === 1) {
                return $this->publicOwnerAction($moveMatch[1], $headers, $rawBody);
            }
"""
    anchor="            if ($method === 'GET' && $path === '/health') {"
    edit_one(files,'src/Http/PublicHttpKernel.php',anchor,route+anchor)
    method="""
    /** Server-side only: challenge/owner scope must never be taken from client identity fields. */
    private function publicOwnerAction(string $action, array $headers, string $rawBody): array
    {
        $lock=$this->componentRoot.'/install.lock';
        $record=json_decode((string)@file_get_contents($lock),true);
        if (!is_array($record) || ($record['format']??'')!=='sokna-public-install-lock-v1') {
            return $this->json(SafeErrors::response(503,'public_owner_not_initialized'));
        }
        $instanceId=(string)($record['public_instance_id']??'');
        $config=$this->core->config();
        $private=$config->string('security.move_owner_private_dir');
        $csrfB64=$config->string('security.move_owner_csrf_key_base64');
        $csrf=base64_decode($csrfB64,true);
        if ($private==='' || !is_string($csrf) || strlen($csrf)!==32) {
            return $this->json(SafeErrors::response(503,'public_owner_not_initialized'));
        }
        $base=$config->string('app.base_url');
        $owners=new \\Sokna\\PublicEdge\\Security\\PublicMoveOwnerBootstrapService($this->core->database());
        $keys=new \\Sokna\\PublicEdge\\Security\\PublicMoveInstanceKeyStore($private,$this->componentRoot);
        $bridge=new \\Sokna\\PublicEdge\\Security\\PublicMoveIndependentOwnerBridge($owners,$keys,$base,$instanceId,$csrf);
        return (new \\Sokna\\PublicEdge\\Http\\PublicMoveOwnerHttpAdapter(
            $owners,$bridge,$instanceId,$base
        ))->handle($action,$headers,$rawBody,time());
    }

"""
    anchor="    private function sessionToken(array $headers): string"
    req(files['src/Http/PublicHttpKernel.php'].decode().count(anchor)==1,'kernel_helper_anchor')
    edit_one(files,'src/Http/PublicHttpKernel.php',anchor,method+anchor)

    req(all(sha(files[p])==h for p,h in original_hashes.items() if p.startswith('database/migrations/')),'existing_migrations_changed')
    changed=[p for p in original_hashes if sha(files[p])!=original_hashes[p]]
    new=sorted(set(files)-set(original_hashes))
    req(len(changed)==5 and len(new)==8,'unexpected_file_change_count')
    target=a.out/'qa-public-payload'
    if target.exists():shutil.rmtree(target)
    for p,b in files.items():
        dst=target/p
        dst.parent.mkdir(parents=True,exist_ok=True)
        dst.write_bytes(b)
    proof.update({'candidate_generated':True,'candidate_files':len(files),
                  'candidate_migrations':9,'changed_files':changed,'added_files':new,
                  'old_migrations_byte_identical':True,
                  'qa_root':str(target),'new_runtime_has_no_example_domain':True})
    a.out.mkdir(parents=True,exist_ok=True)
    (a.out/'R256-REAL-R249-INTEGRATION-QA-PROOF.json').write_text(json.dumps(proof,ensure_ascii=False,indent=2)+'\n')
    print('R256_REAL_R249_OWNER_SETUP_OVERLAY_QA_ONLY_PASS files='+str(len(files)))
if __name__=='__main__': main()
