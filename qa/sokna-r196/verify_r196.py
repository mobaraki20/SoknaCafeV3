#!/usr/bin/env python3
"""Fail closed: exact byte identity of all 590 files from R195 plus four allowed files."""
import pathlib,hashlib,json,sys,subprocess
root=pathlib.Path(sys.argv[1]); expected=json.loads(pathlib.Path(__file__).with_name('R196-expected-managed-sha.json').read_text())
files={p.relative_to(root).as_posix():hashlib.sha256(p.read_bytes()).hexdigest() for p in root.rglob('*') if p.is_file()}
if len(files)!=590 or files!=expected:
 diff=[k for k in sorted(set(files)|set(expected)) if files.get(k)!=expected.get(k)]
 raise SystemExit('R196_SOURCE_DRIFT '+str(diff[:25]))
php=[p for p in root.rglob('*.php') if p.is_file()]; js=[p for p in root.rglob('*.js') if p.is_file()]
for p in php:
 x=subprocess.run(['php','-l',str(p)],stdout=subprocess.PIPE,stderr=subprocess.STDOUT)
 if x.returncode:raise SystemExit('R196_PHP_SYNTAX '+str(p)+' '+x.stdout.decode(errors='replace'))
for p in js:
 x=subprocess.run(['node','--check',str(p)],stdout=subprocess.PIPE,stderr=subprocess.STDOUT)
 if x.returncode:raise SystemExit('R196_JS_SYNTAX '+str(p)+' '+x.stdout.decode(errors='replace'))
s=(root/'public/assets/staff-app/hall-workspace.js').read_text()
api=(root/'public/finance/api.php').read_text()
fin=(root/'src/Domain/Finance/SettlementService.php').read_text()
printing=(root/'src/Domain/Printing/PrintService.php').read_text()
assert 'data-account-print-preview' in s and 'printOpenAccountFromMenu' in s and 'clientRequestId(\'account-preview\')' in s
assert "if($action==='account_preview_print')" in api
assert 'public function printOpenAccount(' in fin and "'account_preview'" in printing
assert printing.count('enqueueSettlementTx(')==1
assert "'invoice_number'=>''" in printing
assert "'account:preview:session:'" in fin and "'account:preview:session:'" in printing
print(f'PASS_R196_SOURCE_590_BYTE_EXACT_PHP_{len(php)}_JS_{len(js)}_API_UI_NON_FISCAL')
