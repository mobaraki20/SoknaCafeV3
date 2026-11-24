"""Exact QA source patch: shared saved payment default and explicit split controls."""
import hashlib, pathlib, sys
H=lambda b:hashlib.sha256(b).hexdigest()
pins={
 'local/src/Domain/Orders/OrderWorkspaceService.php':('2513199e1228073845f334dc90be41d5eb49331c4afe1f0bdccb5b4e140b4b82','74c1f0ea7d38ec30138ed2200ad7efff53d4f464e9f174042dbb8d93be2f3025'),
 'public/assets/settlement-tender.js':('5ab7a9b1a5cb5c88ba84d8c31bad57418ce5af0137f0ed897d581e7d115d0b70','06b5adabadd826fe781c51a3e777ad67cae04cdf4acb033105405ddf9e57ae6c'),
 'public/assets/hall-settlement-v32.js':('76afc8a5b6853b8e246bcda93607393a7b9ea451866cc0e0d6df28c2ea1e1c70','d6552a4b446f89a2ef5aed61c3881185bf102f87e295dec39b0402af4380deb2'),
 'public/assets/staff-app/hall-workspace.js':('da963be85e8af431bb728cf25c3c6d959e7f358ec7e34ec9ad9681f6ac3c0203','3b90dda7cab1cafd3cad4615b396cec1d183d65184fa46c2bea76d9967c4f138')
}
def apply(files):
 for name,(before,after) in pins.items():assert H(files[name])==before,name
 assert H(files['local/public/assets/hall-settlement-v32.js'])=='d83f6c7562a974b584a53e55a3fb66f19247e2ca9d4ce509f43d42e0df0869c6'
 assert H(files['local/public/assets/settlement-tender.js'])=='06b5adabadd826fe781c51a3e777ad67cae04cdf4acb033105405ddf9e57ae6c'
 name='local/src/Domain/Orders/OrderWorkspaceService.php';s=files[name].decode();a='        $tables=$this->tableRows();\n'
 s=s.replace(a,"        $defaultTender=$this->pdo->query(\"SELECT setting_value FROM settings WHERE setting_key='payment.default_tender' LIMIT 1\")->fetchColumn();\n        $defaultTender=$defaultTender===false?'pos':trim((string)$defaultTender);\n"+a,1)
 s=s.replace("            'final_amount_rial'=>FinalAmountDisplayPolicy::enabled($this->pdo),","            'final_amount_rial'=>FinalAmountDisplayPolicy::enabled($this->pdo),\n            'default_tender'=>in_array($defaultTender,['pos','cash','bank_transfer','none'],true)?$defaultTender:'none',",1);files[name]=s.encode()
 name='public/assets/staff-app/hall-workspace.js';s=files[name].decode();a="        root.dataset.finalAmountRial=snap.final_amount_rial===true?'1':'0';";assert s.count(a)==1
 files[name]=s.replace(a,a+"\n        root.dataset.defaultTender=['pos','cash','bank_transfer','none'].includes(String(snap.default_tender))?String(snap.default_tender):'none';").encode()
 files['public/assets/settlement-tender.js']=files['local/public/assets/settlement-tender.js']
 name='public/assets/hall-settlement-v32.js';s=files[name].decode();l=files['local/public/assets/hall-settlement-v32.js'].decode()
 a='const T=window.SoknaSettlementTender;if(!T)return;';i=l.index('const settlementDefaultTender=');j=l.index('\n',i);assert s.count(a)==1;s=s.replace(a,a+'\n'+l[i:j])
 s=s.replace('tender:T.fresh(),','tender:T.fresh(settlementDefaultTender()),tenderMixed:false,').replace('state.tender=T.fresh();state.review=null;','state.tender=T.fresh(settlementDefaultTender());state.tenderMixed=false;state.review=null;')
 start=s.index('function tenderBox(');end=s.index('\n',start);i=l.index('function tenderBox(');j=l.index('\n',i);s=s[:start]+l[i:j]+s[end:]
 start=s.index("const tender=e.target.closest('[data-tender-type]')");end=s.index("const plus=e.target.closest('[data-settle-plus]')",start);i=l.index("const mixedToggle=e.target.closest('[data-tender-mixed]')");j=l.index("const plus=e.target.closest('[data-settle-plus]')",i);files[name]=(s[:start]+l[i:j]+s[end:]).encode()
 for name,(before,after) in pins.items():assert H(files[name])==after,name
 return files
if __name__=='__main__':
 r=pathlib.Path(sys.argv[1]);names=list(pins)+['local/public/assets/hall-settlement-v32.js','local/public/assets/settlement-tender.js']
 files=apply({n:(r/n).read_bytes() for n in names})
 for name in pins:(r/name).write_bytes(files[name])
 print('PASS exact four-file tender patch')
