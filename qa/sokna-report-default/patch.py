from pathlib import Path
import hashlib,os
path=Path(os.environ['RUNNER_TEMP'])/'local/public/reports/index.php'
original=path.read_bytes();assert hashlib.sha256(original).hexdigest()=='8dca39c6a2ea3c3a10fef1ed9bafe25f685e00f65ba324f5614aab39af01fd6f'
old=b"$from=(string)($_GET['from']??'');$to=(string)($_GET['to']??'');"
new=b"$to=(string)(($_GET['to']??'')?:$core->time()->civilDate());$from=(string)(($_GET['from']??'')?:$to);"
assert original.count(old)==1
patched=original.replace(old,new);assert hashlib.sha256(patched).hexdigest()=='cd380dd37b0906aeabf3f6701fa19a6ad9382f33651b9343184ec329acf56cb6'
path.write_bytes(patched)
