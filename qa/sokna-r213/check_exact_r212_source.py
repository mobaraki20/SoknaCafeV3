import pathlib,hashlib,json,sys
source=pathlib.Path(sys.argv[1]);proof=json.loads(pathlib.Path(sys.argv[2]).read_text())
files={p.relative_to(source).as_posix():hashlib.sha256(p.read_bytes()).hexdigest() for p in source.rglob('*') if p.is_file()}
expected=proof['files']
assert len(files)==proof['file_count'],(len(files),proof['file_count'])
assert files==expected,{'missing':set(expected)-set(files),'extra':set(files)-set(expected),'changed':[n for n in files.keys()&expected.keys() if files[n]!=expected[n]][:30]}
h=hashlib.sha256()
for n in sorted(files):h.update(n.encode()+b'\0'+bytes.fromhex(files[n])+b'\0')
assert h.hexdigest()==proof['tree_content_digest_sha256']
print('PASS_R213_590_FILES_EXACT_R212_CUMULATIVE_BYTE_IDENTITY')
