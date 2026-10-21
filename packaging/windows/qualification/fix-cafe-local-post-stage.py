#!/usr/bin/env python3
"""Source-only Cafe Local panel patch; NEVER signs/publishes a release."""
import argparse
from pathlib import Path
from zipfile import ZipFile

JS = "public/assets/system-diagnostics.js"
OLD_STAGE = """   verifiedMaintenanceStage=stage.stage_operation_id;
   $('[data-maintenance-activate]').disabled=!maintenanceReady;
   maintenanceUploadMessage('بسته امضاشده بررسی و Stage شد. نصب فقط پس از تأیید جداگانه انجام می‌شود.','info');"""
NEW_STAGE = """   verifiedMaintenanceStage=stage.stage_operation_id;
   // Signed Preflight may have rebound the installed identity.
   // Never activate using capabilities fetched before the signed Stage.
   maintenanceReady=false;
   const fresh=await request(`${api}?section=updates`);
   maintenanceReady=fresh.maintenance_updater?.ready===true&&fresh.maintenance_panel_bound===true;
   maintenanceVerifyReady=fresh.maintenance_updater?.verification_ready===true&&fresh.maintenance_verification_bound===true;
   renderUnifiedUpdaterReadiness({...fresh.maintenance_updater,panel_bound:fresh.maintenance_panel_bound===true});
   $('[data-maintenance-activate]').disabled=!maintenanceReady;
   maintenanceUploadMessage(maintenanceReady
     ?'بستهٔ امضاشده آماده است؛ برای نصب، تأیید نهایی را بزن.'
     :'بسته Stage شد، اما سرویس هنوز اجازه نصب نداده است.','info');"""
OLD_RENDER = "if(!maintenanceReady)$('[data-maintenance-activate]').disabled=true;if(legacyLocalBlocked){"
NEW_RENDER = "if(legacyLocalBlocked){const activate=$('[data-maintenance-activate]');if(activate)activate.disabled=!(maintenanceReady&&/^[a-fA-F0-9]{32}$/.test(verifiedMaintenanceStage));"
OLD_CATCH = """ }catch(error){verifiedMaintenanceStage='';$('[data-maintenance-activate]').disabled=true;
   maintenanceUploadMessage(`فرآیند متوقف شد. هیچ نصب جدیدی تأیید نشده است. ${String(error.message||'')}`,'warning')}"""
NEW_CATCH = """ }catch(error){$('[data-maintenance-activate]').disabled=true;
   // Preserve a proven Stage ID after an uncertain capability readback.
   if(!/^[a-fA-F0-9]{32}$/.test(verifiedMaintenanceStage))verifiedMaintenanceStage='';
   maintenanceReady=false;
   maintenanceUploadMessage(verifiedMaintenanceStage
     ?'Stage ثبت شده اما مجوز نصب خوانده نشد. «تازه‌سازی» را بزن؛ نصب را تکرار نکن.'
     :`فرآیند متوقف شد. هیچ نصب جدیدی تأیید نشده است. ${String(error.message||'')}`,'warning')}"""

def patch(source):
    s=source.decode("utf-8-sig")
    for old,new in ((OLD_STAGE,NEW_STAGE),(OLD_RENDER,NEW_RENDER),(OLD_CATCH,NEW_CATCH)):
        if s.count(old)!=1:
            raise ValueError("BASELINE_MISMATCH_OR_ALREADY_PATCHED")
        s=s.replace(old,new)
    return s.encode("utf-8")

def archive(source,target):
    if source.resolve()==target.resolve():
        raise ValueError("IN_PLACE_MUTATION_FORBIDDEN")
    with ZipFile(source) as z, ZipFile(target,"w") as out:
        infos=[i for i in z.infolist() if not i.is_dir()]
        if len([i for i in infos if i.filename==JS])!=1:
            raise ValueError("EXACT_PANEL_SOURCE_REQUIRED")
        for i in infos:
            if i.filename.startswith("/") or "\\" in i.filename or any(x in ("",".","..") for x in i.filename.split("/")):
                raise ValueError("UNSAFE_WEB_PATH")
        for i in infos:
            b=z.read(i)
            out.writestr(i,patch(b) if i.filename==JS else b)

if __name__=="__main__":
    a=argparse.ArgumentParser()
    a.add_argument("original_web_tree",type=Path)
    a.add_argument("patched_web_tree",type=Path)
    v=a.parse_args()
    archive(v.original_web_tree,v.patched_web_tree)
    print("SOURCE_ONLY_NOT_SIGNED_NOT_INSTALLABLE")
