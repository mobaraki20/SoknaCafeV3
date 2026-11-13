from pathlib import Path
L=Path('/mnt/data/sokna_audit/local'); P=Path('/mnt/data/sokna_audit/public')
def change(path,a,b,n=1):
    p=Path(path);x=p.read_text('utf-8');c=x.count(a)
    if c!=n:raise RuntimeError(f'{p} replacement count {c} expected {n}: {a[:60]}')
    p.write_bytes(x.replace(a,b).encode('utf-8'))
    print('patched',p.relative_to(L) if p.is_relative_to(L) else p.relative_to(P))
# Login Local: exact Public policy, any number of accounts, no search
p=L/'public/assets/login.js'
x=p.read_text();start=x.index('// Large-account login stays');end=x.index("form?.addEventListener('submit'",start)
new='''// Same collapsed, search-free staff account selection policy as Public, regardless of count.
// The HTML radio list remains available when JavaScript is disabled.
if(cards.length){
 const fieldset=root.querySelector('[data-login-accounts]'),grid=fieldset?.querySelector('.sc-login-account-grid');
 if(fieldset&&grid){
  const toggle=document.createElement('button');toggle.type='button';toggle.className='sc-button sc-button--secondary sc-login-picker-toggle';toggle.setAttribute('aria-expanded','false');toggle.setAttribute('aria-controls','sc-local-account-list');
  const panel=document.createElement('div');panel.className='sc-login-picker-panel';panel.id='sc-local-account-list';panel.hidden=true;
  const selected=()=>cards.find(c=>c.querySelector('input[type="radio"]')?.checked)||cards[0];
  const selectedLabel=()=>selected()?.querySelector('.sc-login-account__copy strong')?.textContent?.trim()||'انتخاب حساب';
  const label=()=>{toggle.textContent=selectedLabel()+'  ▾'};
  const close=(moveToPassword=false)=>{panel.hidden=true;toggle.setAttribute('aria-expanded','false');label();if(moveToPassword)form?.querySelector('input[name="password"]')?.focus({preventScroll:true});};
  toggle.addEventListener('click',()=>{panel.hidden=!panel.hidden;toggle.setAttribute('aria-expanded',String(!panel.hidden));if(!panel.hidden)selected()?.focus({preventScroll:true});});
  fieldset.insertBefore(toggle,grid);fieldset.insertBefore(panel,toggle.nextSibling);panel.append(grid);
  cards.forEach(card=>{card.addEventListener('click',()=>{choose(card);close(true);});});
  panel.addEventListener('keydown',e=>{if(e.key==='Escape'){e.preventDefault();close();toggle.focus()}});
  label();
 }
}
'''
p.write_bytes((x[:start]+new+x[end:]).encode('utf-8'));print('patched login.js')
# visual parity: hide account role in Local login, like Public (authorization itself unchanged)
change(L/'public/login.php','<small><?=htmlspecialchars(login_role((string)$account[\'role\']),ENT_QUOTES|ENT_SUBSTITUTE,\'UTF-8\')?></small>','')
# remove user-discontinued go-live entry but not the backend data/flow
change(L/'public/system/index.php','<a class="sc-button sc-button--secondary" href="<?= htmlspecialchars(LocalUrl::path(\'/system/go-live.php\'),ENT_QUOTES|ENT_SUBSTITUTE,\'UTF-8\') ?>">شروع فعالیت واقعی</a>','')
# Modal scroller and print PNG only, both Local and Public
for root,csspath in [(L,'public/assets/product-ui.css'),(P,'assets/product-ui.css')]:
 path=root/csspath
 change(path,'@media(min-width:761px){.sc-account-item-edit-dialog .sc-dialog-frame__body{overflow-y:visible!important}}','/* The edit form can exceed 660px after adjustment details expand: retain the internal scroll track. */\n.sc-account-item-edit-dialog .sc-dialog-frame__body{min-block-size:0;overflow-y:auto!important;overscroll-behavior:contain;scrollbar-gutter:stable}')
 # A final scoped override, leaving PDF preview behavior untouched
 with path.open('a',encoding='utf-8',newline='\n') as f:
  f.write('''\n/* R189/190 QA: preparation PNG preview fits 80mm visually; PDF iframe untouched. */\n.sc-print-preview-stage:has(>img.sc-print-preview){overflow-y:auto;overflow-x:hidden;place-items:start center}\n.sc-print-preview-stage>img.sc-print-preview{inline-size:min(100%,340px);block-size:auto;max-block-size:none;object-fit:contain;justify-self:center}\n''')
# preserve modal scroll position across quantity/reason re-renders, all relevant shared JS copies
for path in [L/'assets/staff-app/hall-workspace.js',L/'public/assets/staff-app/hall-workspace.js',P/'assets/staff-app/hall-workspace.js']:
 x=path.read_text('utf-8'); b=x.index('function renderAccountItemEditor(){'); t=x.index('function openAccountItemEditor',b); s=x[b:t]
 if s.count('body.innerHTML=')!=1 or s.count('if(save){')!=1:raise RuntimeError('Unexpected body markup '+str(path))
 s=s.replace('body.innerHTML=','const priorScrollTop=body.scrollTop;\n body.innerHTML=',1)
 s=s.replace('if(save){','body.scrollTop=priorScrollTop;\n if(save){',1)
 path.write_bytes((x[:b]+s+x[t:]).encode('utf-8'));print('patched hall js',path)
print('PATCH_OK')