#!/usr/bin/env python3
"""R260: minimal Public replacement on the immutable pinned R249 -> R259 QA source.

No old Public transfer, new data migration, special tenant URL, deployable ZIP,
Emergency mutation, or installation/printing/pairing service replacement.
Only exposes fresh empty Public owner-issued code, pauses guest intake on
replacement pairing, and activates intake after signed Local initial sync.
"""
from __future__ import annotations
import argparse,hashlib,json,pathlib,subprocess,sys

def sha(path:pathlib.Path)->str:return hashlib.sha256(path.read_bytes()).hexdigest()
def exact_edit(path:pathlib.Path,old:str,new:str)->None:
    s=path.read_text(encoding="utf-8")
    if s.count(old)!=1:raise AssertionError("R260_ANCHOR_NOT_UNIQUE: "+str(path)+" "+old[:70]+" found "+str(s.count(old)))
    path.write_text(s.replace(old,new,1),encoding="utf-8")
p=argparse.ArgumentParser()
for name in ("r249","repo","out"):p.add_argument("--"+name,type=pathlib.Path,required=True)
a=p.parse_args()
subprocess.run([sys.executable,str(a.repo/"r259/integrate_real_public.py"),
    "--r249",str(a.r249),"--repo",str(a.repo),"--out",str(a.out)],check=True)
root=a.out/"qa-public-payload"
before={x.relative_to(root).as_posix():sha(x) for x in root.rglob("*") if x.is_file()}
if len(before)!=242:raise AssertionError("R260_R259_SOURCE_COUNT_CHANGED")

path="src/Security/PublicMoveReplacementGate.php"
from_repo=a.repo/"apps/public"/path
if not from_repo.is_file():raise AssertionError("R260_MISSING_GATE")
to=root/path;to.parent.mkdir(parents=True,exist_ok=True);to.write_bytes(from_repo.read_bytes())
bootstrap=root/"bootstrap.php"
anchor="require_once __DIR__ . '/src/Security/PublicMoveConsentReceiptStore.php';"
exact_edit(bootstrap,anchor,anchor+"\nrequire_once __DIR__ . '/src/Security/PublicMoveReplacementGate.php';")

service=root/"src/Setup/PublicSetupService.php"
anchor="    private function createPairingCode(string $storage): string"
method=r"""
    /** Only called by an already-authenticated Public owner consent route.
     * The old host is NEVER contacted. This site's pairing storage is rotated
     * for a fresh/unpaired database; the existing pair() path consumes it. */
    public function issueOwnerPairingCode(): string
    {
        $state=$this->status();
        if (!$state['installed']||$state['paired'])
            throw new SetupException('destination_not_unpaired','وب عمومی مقصد قبلاً متصل شده است.',409);
        $config=require $this->root.'/config.php';
        if (!is_array($config)) throw new SetupException('config_invalid','پیکربندی مقصد معتبر نیست.',500);
        return $this->createPairingCode((string)$config['app']['storage_dir']);
    }
"""
exact_edit(service,anchor,method+"\n"+anchor)
exact_edit(service,
    "$code=trim((string)($body['pairing_code']??''));",
    "$replacement=(bool)($body['replacement_mode']??false);$code=trim((string)($body['pairing_code']??''));")
exact_edit(service,
    "order_intake_enabled,authority_epoch,authority_state,authority_epoch_required)",
    "order_intake_enabled,authority_epoch,authority_state,authority_epoch_required,public_move_intake_paused)")
exact_edit(service,
    "VALUES(?,?,1,1,1,1,'active',0)",
    "VALUES(?,?,1,1,?,1,'active',0,?)")
exact_edit(service,
    "->execute([$installation,$name]);(new PairingSecretStore",
    "->execute([$installation,$name,$replacement?0:1,$replacement?1:0]);(new PairingSecretStore")
exact_edit(service,
    "return ['success'=>true,'ok'=>true,'paired'=>true,'installation_id'=>$installation",
    "return ['success'=>true,'ok'=>true,'paired'=>true,'replacement_mode'=>$replacement,'guest_intake_enabled'=>!$replacement,'installation_id'=>$installation")
# The normal installation projection MUST NOT reopen guest ordering before
# signed move activation, even though Local normally publishes 'accepting'.
projection=root/"src/Remote/InstallationProjectionService.php"
exact_edit(projection,
    "order_intake_enabled=IF(authority_state='active',VALUES(order_intake_enabled),0)",
    "order_intake_enabled=IF(authority_state='active' AND public_move_intake_paused=0,VALUES(order_intake_enabled),0)")

kernel=root/"src/Http/PublicHttpKernel.php"
anchor="            '/api/v1/local/installation'=>(new InstallationProjectionHttpAdapter($this->core))->sync"
exact_edit(kernel,anchor,
    "'/api/v1/local/move/activate'=>(new \\Sokna\\PublicEdge\\Security\\PublicMoveReplacementGate($this->core->database()))->activateAfterVerifiedSync($this->core,$installationId,'POST',$path,$timestamp,$nonce,$signature,$rawBody),\n            "+anchor)
# Consent still works for an EXISTING Public, but code is issued only for a
# genuinely fresh, unbound empty Public after owner consent has succeeded.
old=r"""        return $this->json(['status'=>200,'body'=>[
            'ok'=>true,'proof'=>$body['proof'],
            'receipt_envelope'=>$saved['receipt_envelope'],
            'safe_to_cutover'=>false,
        ]]);"""
new=r"""        $setup=new \Sokna\PublicEdge\Setup\PublicSetupService($this->componentRoot);
        $pairingCode='';
        if (($setup->status()['paired']??true)===false) {
            (new \Sokna\PublicEdge\Security\PublicMoveReplacementGate(
                $this->core->database()
            ))->requireEmpty();
            $pairingCode=$setup->issueOwnerPairingCode();
        }
        return $this->json(['status'=>200,'body'=>[
            'ok'=>true,'proof'=>$body['proof'],
            'receipt_envelope'=>$saved['receipt_envelope'],
            'pairing_code'=>$pairingCode,
            'safe_to_cutover'=>false,
        ]]);"""
exact_edit(kernel,old,new)
migration=a.repo/"r260/overlay/database/migrations/0011_public_move_intake_pause.sql"
if not migration.is_file():raise AssertionError("R260_MIGRATION_MISSING")
dest=root/"database/migrations"/migration.name
dest.write_bytes(migration.read_bytes())
after={x.relative_to(root).as_posix():sha(x) for x in root.rglob("*") if x.is_file()}
added=sorted(set(after)-set(before))
changed=sorted(p for p in before if after[p]!=before[p])
if added!=["database/migrations/0011_public_move_intake_pause.sql",path] or changed!=["bootstrap.php","src/Http/PublicHttpKernel.php","src/Remote/InstallationProjectionService.php","src/Setup/PublicSetupService.php"]:
    raise AssertionError("R260_UNEXPECTED_SOURCE_CHANGES "+str(added)+" "+str(changed))
for name in (n for n in before if n.startswith("database/migrations/")):
    if before[name]!=after[name]:raise AssertionError("R260_MIGRATION_CHANGED "+name)
proof={"format":"sokna-r260-minimal-public-replacement-qa-v1","source":"pinned-R249-via-R259",
  "prior_files":len(before),"qa_files":len(after),"source_migrations_unchanged":True,
  "added":added,"changed":changed,"old_public_contact_required":False,
  "old_public_data_transfer":False,"new_database_migrations":["0011_public_move_intake_pause.sql"],
  "guest_intake_paused_until_local_signed_sync":True,"normal_sync_cannot_bypass_gate":True,
  "installable_package":False,"production_modified":False}
(a.out/"R260-REAL-PUBLIC-QA-PROOF.json").write_text(json.dumps(proof,indent=2)+"\n",encoding="utf-8")
print("R260_MINIMAL_REAL_PUBLIC_OVERLAY_PASS files="+str(len(after)))
