from pathlib import Path
import hashlib,sys
root=Path(sys.argv[1])
local=(root/'local/public/assets/operations-daily.css').read_text()
p=root/'public/assets/product-ui.css';q=root/'public/assets/operations-workspace.js'
h=lambda b:hashlib.sha256(b).hexdigest()
s=p.read_text();a=s.index('/* R104 inventory mobile: scoped to inventory workspace; desktop behavior is untouched. */');b=local.index('/* R104 inventory mobile: scoped to inventory workspace; desktop behavior is untouched. */')
marker=' body[data-page="operations"] [data-operations-workspace] .sc-ops-summary{'
old=s[a:s.index(marker,a)];new=local[b:local.index(marker,b)]
assert old.endswith('@media(max-width:640px){\n')
s=s[:a]+new+s[a+len(old):]
marker='/* R104 matching Local/Public seven-step inventory mobile navigation. */'
a=s.index(marker);b=local.index(marker);end='@media(min-width:641px)'
old=s[a:s.index(end,a)];new=local[b:local.index(end,b)]
assert '[open]' not in old and '[open]' in new
s=s[:a]+new+s[a+len(old):]
v=q.read_text();before="$$=(s,c=root)=>[...c.querySelectorAll(s)];let snap="
after="$$=(s,c=root)=>[...c.querySelectorAll(s)];if(window.matchMedia?.('(max-width:640px)').matches){const m=root.querySelector('.sc-ops-more');if(m)m.open=true;}let snap="
assert v.count(before)==1;v=v.replace(before,after)
for path,raw in [(p,s.encode()),(q,v.encode())]:
 print('PATCH_PIN',path.relative_to(root),h(path.read_bytes()),h(raw));path.write_bytes(raw)
