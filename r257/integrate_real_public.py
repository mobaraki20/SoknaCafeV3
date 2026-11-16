#!/usr/bin/env python3
"""R257 QA-only overlay on the exact, pin-verified R249/35IC Public release.

First executes R256's audited 228-file source integration. Then adds existing
Public owner activation and durable destination-owner receipts. It never
produces an installable ZIP, touches the production host, or rewrites any
of the prior eight Public migrations / stable Emergency protected owners.
"""
from __future__ import annotations
import argparse,hashlib,json,pathlib,subprocess,sys

def sha(b:bytes)->str:return hashlib.sha256(b).hexdigest()
def gate(cond,msg):
 if not cond:raise AssertionError("R257_GATE_"+msg)
def edit(path:pathlib.Path,old:str,new:str):
 s=path.read_text(encoding='utf-8')
 gate(s.count(old)==1,"expected_one_anchor_"+path.name+"_"+str(len(old)))
 path.write_text(s.replace(old,new,1),encoding='utf-8')

p=argparse.ArgumentParser()
p.add_argument('--r249',type=pathlib.Path,required=True)
p.add_argument('--repo',type=pathlib.Path,required=True)
p.add_argument('--out',type=pathlib.Path,required=True)
a=p.parse_args()
subprocess.run([sys.executable,str(a.repo/'r256/integrate_real_public.py'),
    '--r249',str(a.r249),'--repo',str(a.repo),'--out',str(a.out)],check=True)
root=a.out/'qa-public-payload'
gate(root.is_dir(),'r256_source_missing')
pre={f.relative_to(root).as_posix():sha(f.read_bytes()) for f in root.rglob('*') if f.is_file()}
gate(len(pre)==236,'r256_exact_file_count')
for p in ['src/Security/PublicMoveExistingOwnerActivation.php',
          'src/Security/PublicMoveConsentReceiptStore.php']:
 source=a.repo/'apps/public'/p
 gate(source.is_file(),'class_absent_'+p)
 target=root/p;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(source.read_bytes())
migration=a.repo/'r257/overlay/database/migrations/0010_public_move_owner_receipts.sql'
gate(migration.is_file(),'receipt_migration_not_found')
target=root/'database/migrations/0010_public_move_owner_receipts.sql'
target.write_bytes(migration.read_bytes())

bootstrap=root/'bootstrap.php'
anchor="require_once __DIR__ . '/src/Http/PublicMoveOwnerHttpAdapter.php';"
edit(bootstrap,anchor,
    "require_once __DIR__ . '/src/Security/PublicMoveExistingOwnerActivation.php';\n"
    "require_once __DIR__ . '/src/Security/PublicMoveConsentReceiptStore.php';\n"+anchor)

kernel=root/'src/Http/PublicHttpKernel.php'
anchor="            if ($method === 'POST' && preg_match('#^/api/v1/owner/(login|logout|consent_prepare|consent_sign)$#D', $path, $moveMatch) === 1) {"
route="""            if ($method === 'POST' && preg_match('#^/api/v1/owner/(upgrade_preflight|upgrade_activate)$#D', $path, $upgradeMatch) === 1) {
                return $this->publicOwnerUpgradeAction($upgradeMatch[1], $headers, $rawBody);
            }
"""
edit(kernel,anchor,route+anchor)

method= r"""
    /** Site-owner creation for already-installed Public is limited to an existing,
     * verified admin of the sole active Local installation in THIS Public DB.
     * There is no anonymous setup-claim endpoint and no Emergency dependency. */
    private function publicOwnerUpgradeAction(string $action,array $headers,string $rawBody): array
    {
        try {
            $record=json_decode((string)@file_get_contents($this->componentRoot.'/install.lock'),true);
            if (!is_array($record) || ($record['format']??'')!=='sokna-public-install-lock-v1')
                throw new \RuntimeException('upgrade_owner_install_lock_required');
            $id=(string)($record['public_instance_id']??'');
            $cfg=$this->core->config();
            $seed=base64_decode($cfg->string('relay.secret_encryption_key_base64'),true);
            if (!is_string($seed)||strlen($seed)<32)throw new \RuntimeException('upgrade_owner_csrf_seed_missing');
            $key=hash_hkdf('sha256',$seed,32,'sokna-public-move-owner-consent-v1');
            $owners=new \Sokna\PublicEdge\Security\PublicMoveOwnerBootstrapService($this->core->database());
            $gate=new \Sokna\PublicEdge\Security\PublicMoveExistingOwnerActivation(
                $this->core->database(),$this->core->publicSessions(),$owners,
                $id,$cfg->string('app.base_url'),$key
            );
            $now=time();
            $staffToken=$this->sessionToken($headers);
            $origin=$this->header($headers,'Origin');
            $fetch=$this->header($headers,'Sec-Fetch-Site');
            if ($action==='upgrade_preflight')
                $result=$gate->preflight($staffToken,$origin,$fetch,$now);
            elseif ($action==='upgrade_activate') {
                $req=json_decode($rawBody,true);
                if (!is_array($req)) throw new \InvalidArgumentException('invalid_json');
                $root=$this->componentRoot;
                $prepare=function()use($root,$id):void {
                    $protected=\Sokna\PublicEdge\Setup\PublicMoveOwnerPrivatePath::prepare(
                        $root,(string)($_SERVER['DOCUMENT_ROOT']??$root)
                    );
                    (new \Sokna\PublicEdge\Security\PublicMoveInstanceKeyStore($protected,$root))->provision($id,true);
                };
                $result=$gate->activate($staffToken,$origin,$fetch,
                    (string)($req['csrf']??''),(string)($req['new_owner_password']??''),$now,$prepare);
            } else throw new \InvalidArgumentException('unknown_action');
            return $this->json(['status'=>200,'body'=>['ok'=>true,'result'=>$result]]);
        }catch(\InvalidArgumentException|\RuntimeException $e){
            return $this->json(SafeErrors::response(403,'owner_upgrade_not_permitted'));
        }catch(\Throwable $e){
            return $this->json(SafeErrors::response(503,'owner_upgrade_not_ready'));
        }
    }

"""
anchor="    /** Server-side only: challenge/owner scope must never be taken from client identity fields. */"
edit(kernel,anchor,method+anchor)
old= r"""        $private=$config->string('security.move_owner_private_dir');
        $csrfB64=$config->string('security.move_owner_csrf_key_base64');
        $csrf=base64_decode($csrfB64,true);
        if ($private==='' || !is_string($csrf) || strlen($csrf)!==32) {
            return $this->json(SafeErrors::response(503,'public_owner_not_initialized'));
        }
        $base=$config->string('app.base_url');
        $owners=new \Sokna\PublicEdge\Security\PublicMoveOwnerBootstrapService($this->core->database());
        $keys=new \Sokna\PublicEdge\Security\PublicMoveInstanceKeyStore($private,$this->componentRoot);
        $bridge=new \Sokna\PublicEdge\Security\PublicMoveIndependentOwnerBridge($owners,$keys,$base,$instanceId,$csrf);
        return (new \Sokna\PublicEdge\Http\PublicMoveOwnerHttpAdapter(
            $owners,$bridge,$instanceId,$base
        ))->handle($action,$headers,$rawBody,time());
"""
new=r"""        $private=$config->string('security.move_owner_private_dir');
        $csrfB64=$config->string('security.move_owner_csrf_key_base64');
        $csrf=base64_decode($csrfB64,true);
        $owners=new \Sokna\PublicEdge\Security\PublicMoveOwnerBootstrapService($this->core->database());
        if (($private==='' || !is_string($csrf) || strlen($csrf)!==32) &&
            $owners->initialized($instanceId)) {
            // Existing pre-R256 installation: no configuration overwrite or
            // forced clean install. The seeded key path was prepared only after
            // authenticated admin activation. Derive a purpose-bound CSRF key
            // from already protected relay encryption material.
            $seed=base64_decode($config->string('relay.secret_encryption_key_base64'),true);
            if (!is_string($seed)||strlen($seed)<32)
                return $this->json(SafeErrors::response(503,'public_owner_not_initialized'));
            $csrf=hash_hkdf('sha256',$seed,32,'sokna-public-move-owner-consent-v1');
            $private=\Sokna\PublicEdge\Setup\PublicMoveOwnerPrivatePath::prepare(
                $this->componentRoot,(string)($_SERVER['DOCUMENT_ROOT']??$this->componentRoot)
            );
        }
        if ($private==='' || !is_string($csrf) || strlen($csrf)!==32)
            return $this->json(SafeErrors::response(503,'public_owner_not_initialized'));
        $base=$config->string('app.base_url');
        $keys=new \Sokna\PublicEdge\Security\PublicMoveInstanceKeyStore($private,$this->componentRoot);
        $bridge=new \Sokna\PublicEdge\Security\PublicMoveIndependentOwnerBridge($owners,$keys,$base,$instanceId,$csrf);
        $result=(new \Sokna\PublicEdge\Http\PublicMoveOwnerHttpAdapter(
            $owners,$bridge,$instanceId,$base
        ))->handle($action,$headers,$rawBody,time());
        if ($action!=='consent_sign'||(int)($result['status']??0)!==200) return $result;
        $req=json_decode($rawBody,true);
        $body=json_decode((string)($result['body']??''),true);
        if (!is_array($req)||!is_array($req['challenge']??null)||!is_array($body)||
            !is_array($body['proof']??null))
            return $this->json(SafeErrors::response(400,'invalid_consent_payload'));
        // Never trust client-provided owner proof. It is produced above by
        // the authenticated, same-origin Public owner HTTP adapter.
        $saved=(new \Sokna\PublicEdge\Security\PublicMoveConsentReceiptStore(
            $this->core->database(),$keys
        ))->create($req['challenge'],$body['proof'],$instanceId,time());
        return $this->json(['status'=>200,'body'=>[
            'ok'=>true,'proof'=>$body['proof'],
            'receipt_envelope'=>$saved['receipt_envelope'],
            'safe_to_cutover'=>false,
        ]]);
"""
edit(kernel,old,new)

# Existing R249 emergency owner/8 original migrations and R256 owner setup remain
# byte-identical except for this explicit forward-only overlay.
for name,h in pre.items():
 if name.startswith('database/migrations/'):
  gate(sha((root/name).read_bytes())==h,'migration_changed_'+name)
after={f.relative_to(root).as_posix():sha(f.read_bytes()) for f in root.rglob('*') if f.is_file()}
added=sorted(set(after)-set(pre))
changed=sorted(p for p in pre if after[p]!=pre[p])
gate(added==['database/migrations/0010_public_move_owner_receipts.sql',
             'src/Security/PublicMoveConsentReceiptStore.php',
             'src/Security/PublicMoveExistingOwnerActivation.php'],'unexpected_added_files')
gate(changed==['bootstrap.php','src/Http/PublicHttpKernel.php'],'unexpected_modified_files')
proof={
 'format':'sokna-r257-qa-only-source-overlay-v1','base':'pinned-R249-via-R256',
 'baseline_files':236,'candidate_files':len(after),
 'prior_migrations_unchanged':True,'new_migration':'0010_public_move_owner_receipts.sql',
 'added_files':added,'modified_files':changed,'owner_approval_requires_verified_site_and_local_admin':True,
 'production_modified':False,'operator_zip_created':False,'cutover_allowed':False,
}
(a.out/'R257-SOURCE-OVERLAY-QA-PROOF.json').write_text(json.dumps(proof,indent=2)+'\n')
print('R257_REAL_PUBLIC_SOURCE_OVERLAY_QA_PASS files='+str(len(after)))
