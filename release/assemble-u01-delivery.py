#!/usr/bin/env python3
"""Freeze the qualified U01 release assets into one Home download."""
import argparse, hashlib, io, json, zipfile
from pathlib import Path
def digest(data): return hashlib.sha256(data).hexdigest()
def load(path): return json.loads(path.read_text(encoding="utf-8-sig"))
def verify_hashes(root, name, expected):
    found=set()
    for line in (root/name).read_text(encoding="utf-8-sig").splitlines():
        if not line.strip(): continue
        sha, filename=line.split(None,1); filename=filename.strip().lstrip("*")
        assert filename in expected and filename not in found, (name,filename)
        assert digest((root/filename).read_bytes())==sha.lower(), filename
        found.add(filename)
    assert found==set(expected), (name,found)
def main():
    p=argparse.ArgumentParser();p.add_argument("--assets",type=Path,required=True);p.add_argument("--output",type=Path,required=True);p.add_argument("--source-sha",required=True);p.add_argument("--run-id",required=True);a=p.parse_args()
    root=a.assets;out=a.output;out.mkdir(parents=True,exist_ok=True)
    setup="SOKNA-Windows-Services-Setup-1.1.23.exe"
    update="SOKNA-Home-231au-to-231bf-UPDATE-UAT.exe"
    preflight="SOKNA-Home-231au-to-231bf-PREFLIGHT.exe"
    panel="SOKNA-Local-231bf-U01-Panel-Update.zip"
    verify_hashes(root,"SHA256SUMS.txt",[setup]);verify_hashes(root,"SHA256SUMS-HOME.txt",[update,preflight]);verify_hashes(root,"SHA256SUMS-LOCAL.txt",[panel])
    target=load(root/"target.json");assert target["managed_files"]==522 and target["migration_head"]=="0074_iran_time_notification_boundaries"
    assert target["managed_tree_sha256"]=="80fd968a9e4f1642c3f1519f66d7ab6a2fb1bb64f1a8c0a26444d0d8da1712be"
    cycles=load(root/"U01-WINDOWS-CYCLES.json");assert len(cycles)==24 and all(c["status"]=="PASS" for c in cycles)
    roots={c["root"] for c in cycles};faults={"none","APACHE_STOPPED","DATABASE_BACKUP_VERIFIED","DATABASE_FORWARD_INTENT","POINTER_SWITCHED","COMMITTED"}
    assert len(roots)==4 and any(any(ord(ch)>127 for ch in r) for r in roots)
    assert {(c["root"],c["fault"]) for c in cycles}=={(r,f) for r in roots for f in faults}
    forward={"none","DATABASE_FORWARD_INTENT","POINTER_SWITCHED","COMMITTED"}
    assert all(c["database_mutation"]==(c["fault"] in forward) for c in cycles)
    tests=[json.loads(l) for l in (root/"HOME-WINDOWS-TESTS.jsonl").read_text(encoding="utf-8-sig").splitlines() if l.strip().startswith("{")]
    assert not any(e.get("Action")=="fail" for e in tests)
    passed={e["Test"] for e in tests if e.get("Action")=="pass" and "Test" in e}
    assert {"TestDatabaseForwardMariaDB","TestNativeJunctionIdentityAndWrongTarget","TestExact231auToU01Payload"}<=passed
    with zipfile.ZipFile(root/panel) as z: web=z.read("artifacts/local/web.zip")
    with zipfile.ZipFile(io.BytesIO(web)) as z:
        names=sorted(z.namelist());assert len(names)==522
        tree=digest("".join(n+"\0"+digest(z.read(n))+"\n" for n in names).encode())
        assert tree==target["managed_tree_sha256"]
        assert len([n for n in names if n.startswith("database/migrations/") and n.endswith(".sql")])==75
    source=out/"SOKNA-Local-231bf-Full-Source.zip";source.write_bytes(web)
    repo="https://github.com/mobaraki20/SoknaCafeV3"
    evidence={"format":"sokna-u01-qualified-delivery-v1","date":"2026-10-23","source_commit":a.source_sha,"qualification_run":repo+"/actions/runs/"+a.run_id,"windows_services":"1.1.23","maintenance":"0.3.12","target":target,"windows_cycles":cycles,"home_windows_tests_passed":sorted(passed),"home_real_host_install_verified":False,"public_install_performed":False,"database_policy":"verified private backup; pre-boundary slot rollback; post-boundary forward recovery","artifacts":{n:{"bytes":(root/n).stat().st_size,"sha256":digest((root/n).read_bytes())} for n in [setup,update,preflight,panel]},"source_zip_sha256":digest(web)}
    (out/"QUALIFICATION-U01.json").write_text(json.dumps(evidence,ensure_ascii=False,indent=2)+"\n",encoding="utf-8")
    guide="""# سکنا — بسته تجمیعی خانه U01
تاریخ: ۲۳ اکتبر ۲۰۲۶

مقصد: Local 231bf با ۵۲۲ فایل و ۷۵ مهاجرت؛ Windows Services 1.1.23 و Maintenance 0.3.12.

## اجرا روی خانه
۱. ZIP را کامل استخراج کن و SOKNA-Windows-Services-Setup-1.1.23.exe را اجرا کن.
۲. پس از پایان نصب سرویس‌ها، SOKNA-Home-231au-to-231bf-UPDATE-UAT.exe را اجرا کن.
۳. پس از پیام موفقیت، پنل Local را باز کن و آزمایش ارتباط Maintenance را اجرا کن. به‌روزرسانی‌های بعدی از پنل انجام می‌شوند.

EXE خانه پیش‌بررسی را خودش انجام می‌دهد و از نسخه دقیق 231au با ۷۱ مهاجرت، مستقیماً به 231bf با ۷۵ مهاجرت می‌رود. M06 اصلاح‌شده، موتور تم R2، CSS اصلاحی، زمان ایران و قالب اعداد T01 و اصلاح موتور نصب در این مقصد تجمیع شده‌اند.
تنظیمات، داده، رسانه و هویت نصب حفظ می‌شوند. بکاپ SQL خصوصی پیش از قطعی و دوباره پس از توقف Apache تهیه می‌شود. ابزار بررسی بدون نصب در پوشه tools اختیاری است.

## ZIP پنلی
فایل داخل پوشه panel برای Local دقیق 231be با ۷۴ مهاجرت و Maintenance 0.3.12 است. همان مقصد EXE خانه را نصب می‌کند؛ پس از موفقیت EXE خانه، آن را برای نصب دوباره همین نسخه استفاده نکن.

## دامنه آزمون
۲۴ چرخه واقعی نصب، قطع فرایند و بازیابی روی Windows Server 2022، Apache/PHP و MariaDB موفق شده‌اند؛ مسیر C، D، دارای فاصله و فارسی پوشش داده شده است. آزمون‌های واقعی PHP/Go مربوط به مهاجرت ۷۱ به ۷۵، حفظ داده و شناسه NTFS نیز عبور کرده‌اند.
نصب روی رایانه واقعی خانه، آزمون نهایی کاربر است. Public انتشار مستقلی دارد و با این بسته نصب نمی‌شود. موارد Dev26 Guest/Waiter که در T01 صرفاً شناسایی شده بودند، در این بسته اتمام‌شده معرفی نمی‌شوند.

## اگر اجرا متوقف شد
گزارش JSON همان اجرا را نگه دار. پس از شروع مهاجرت دیتابیس، اجرای دوباره EXE یا بازگرداندن کد قدیمی مسیر بازیابی نیست؛ ادامه بازیابی رو به جلو از شواهد همان عملیات انجام می‌شود.
LOCAL_RUNNING_PANEL_BLOCKED یعنی Local بالا آمده ولی آمادگی پنل تأیید نشده است و موفقیت کامل محسوب نمی‌شود.
"""
    (out/"README-U01-FA.md").write_text(guide,encoding="utf-8")
    files={setup:root/setup,update:root/update,"tools/"+preflight:root/preflight,"panel/"+panel:root/panel,"verification/target.json":root/"target.json","verification/U01-WINDOWS-CYCLES.json":root/"U01-WINDOWS-CYCLES.json","verification/HOME-WINDOWS-TESTS.jsonl":root/"HOME-WINDOWS-TESTS.jsonl","verification/SHA256SUMS.txt":root/"SHA256SUMS.txt","verification/SHA256SUMS-HOME.txt":root/"SHA256SUMS-HOME.txt","verification/SHA256SUMS-LOCAL.txt":root/"SHA256SUMS-LOCAL.txt","verification/QUALIFICATION-U01.json":out/"QUALIFICATION-U01.json","README-U01-FA.md":out/"README-U01-FA.md","source/"+source.name:source}
    bundle=out/"SOKNA-HOME-FINAL-U01-20261023.zip"
    with zipfile.ZipFile(bundle,"w",zipfile.ZIP_STORED) as z:
        for name,path in sorted(files.items()):
            entry=zipfile.ZipInfo(name,(2026,10,23,0,0,0));entry.external_attr=0o100644<<16;z.writestr(entry,path.read_bytes())
    (out/"SHA256SUMS-U01-DELIVERY.txt").write_text("".join(digest(p.read_bytes())+"  "+p.name+"\n" for p in sorted(out.iterdir()) if p.is_file() and p.name!="SHA256SUMS-U01-DELIVERY.txt"),encoding="ascii")
    print(json.dumps({"bundle":bundle.name,"bytes":bundle.stat().st_size,"sha256":digest(bundle.read_bytes()),"windows_cycles":24,"home_tests":len(passed),"source_commit":a.source_sha}))
if __name__=="__main__":main()
