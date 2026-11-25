"""Authenticated real Hall editor, real server and signed Public relay."""
import json,sys,re
from pathlib import Path
from playwright.sync_api import sync_playwright,expect
f=json.loads(Path(sys.argv[1]).read_text());out=Path(sys.argv[2]);out.mkdir(parents=True,exist_ok=True)
results=[]
digits=str.maketrans('۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩','01234567890123456789')
def total(dialog,n):
    s=dialog.locator('.sc-account-item-editor__summary').inner_text().translate(digits)
    assert re.search(rf'(?<!\d){n}\s*عدد ثبت‌شده',s),s
with sync_playwright() as p:
    b=p.chromium.launch(headless=True,args=['--no-sandbox'])
    for host,width in [('local',1280),('public',390)]:
        ctx=b.new_context(viewport={'width':width,'height':844})
        page=ctx.new_page();page.set_default_timeout(20000)
        case=f['bundle_cases'][host];key=host+'-'+str(width)
        try:
            data={'username':f['username'],'password':f['password']}
            if host=='public':data['installation_id']=f['installation_id']
            r=ctx.request.post(f[host]+('/login.php' if host=='local' else '/staff/login'),form=data,max_redirects=0)
            assert r.status in (302,303),r.status
            page.goto(f[host]+('/operator/' if host=='local' else '/staff/operator/'),wait_until='domcontentloaded')
            page.locator(f'[data-open-tables] [data-table-id="{f["table_id"]}"]').click()
            dialog=page.locator('[data-account-item-edit-dialog]')
            def open_line(id,n):
                control=page.locator(f'[data-account-line-edit="{id}"]')
                expect(control).to_be_visible()
                control.click();expect(dialog).to_be_visible();total(dialog,n)
            open_line(case['second'],2)
            page.screenshot(path=str(out/(key+'-independent-choice.png')))
            dialog.locator('[data-account-item-edit-close]').first.click();expect(dialog).not_to_be_visible()
            open_line(case['first'],3)
            page.screenshot(path=str(out/(key+'-before.png')))
            dialog.locator('[data-modal-service-mode="takeaway"][data-modal-service-delta="1"]').click()
            expect(dialog.locator('[data-account-item-edit-save]')).to_be_enabled()
            page.screenshot(path=str(out/(key+'-changed.png')))
            dialog.locator('[data-account-item-edit-save]').click();expect(dialog).not_to_be_visible(timeout=45000)
            open_line(case['second'],2)
            dialog.locator('[data-account-item-edit-close]').first.click()
            page.screenshot(path=str(out/(key+'-after.png')))
            results.append({'host':host,'width':width,'status':'PASS'})
            print('PASS authenticated bundle editor '+key,flush=True)
        except Exception as e:
            results.append({'host':host,'width':width,'status':'FAIL','error':str(e)[:900]})
            page.screenshot(path=str(out/(key+'-FAIL.png')))
            print('FAIL '+key+' '+str(e)[:500],flush=True)
        finally:ctx.close()
    b.close()
(out/'result.json').write_text(json.dumps(results,ensure_ascii=False,indent=2))
assert all(r['status']=='PASS' for r in results)
