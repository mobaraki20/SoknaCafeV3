#!/usr/bin/env python3
"""Strictly GET-only Public field probe. Never takes credentials or writes to the host."""
from __future__ import annotations
import json, os, ssl, urllib.error, urllib.parse, urllib.request
from pathlib import Path
from datetime import datetime, timezone

base = (os.environ.get("SOKNA_FIELD_PUBLIC_URL") or "").strip().rstrip("/")
parsed = urllib.parse.urlsplit(base)
if parsed.scheme != "https" or not parsed.hostname or parsed.username or parsed.password or parsed.query or parsed.fragment:
    raise SystemExit("Only a plain public HTTPS base URL is allowed")
origin = f"{parsed.scheme}://{parsed.netloc}"
prefix = parsed.path.rstrip("/")
allowed = {
    "health": "/health",
    "owner_setup": "/move/owner-setup",
    "staff_login": "/staff/login",
    "guest_menu": "/menu",
    "old_qr": "/Menu/",
}
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None
opener = urllib.request.build_opener(NoRedirect(), urllib.request.HTTPSHandler(context=ssl.create_default_context()))
def probe(path):
    u = origin + prefix + path
    req = urllib.request.Request(u, method="GET", headers={"Accept":"application/json, text/html;q=0.8","User-Agent":"SOKNA-READONLY-R286/1.0"})
    try:
        with opener.open(req, timeout=15) as res:
            data = res.read(262144)
            return {"status":res.status, "bytes_read":len(data), "body":data.decode("utf-8","replace") if path == "/health" else None}
    except urllib.error.HTTPError as ex:
        return {"status":ex.code,"bytes_read":0}
    except Exception as ex:
        return {"status":None,"error_type":type(ex).__name__}
out={"schema":"sokna-r286-readonly-field-probe-v1",
     "checked_at_utc":datetime.now(timezone.utc).isoformat(),
     "base_url":base,"read_only":True,"requests":{}}
for k,p in allowed.items():
    out["requests"][k]=probe(p)
h=out["requests"]["health"]
try:
    raw=json.loads(h.pop("body","") or "{}")
except Exception:
    raw={}
out["public_health"]={k:raw.get(k) for k in ("ok","component","database","version","applied_migrations","pending_migrations")}
update=raw.get("routine_update") or raw.get("update") or {}
out["public_health"]["active_update_version"]=update.get("active_version")
out["public_health"]["staged_update_version"]=update.get("staged_version")
out["public_health"]["pending_update_verification"]=update.get("pending_verification")
out["public_health"]["pending_update_version"]=update.get("pending_version")
version=str(raw.get("version") or "")
owner_status=out["requests"]["owner_setup"]["status"]
if h["status"] == 200 and "35ij" in version and owner_status == 404:
    verdict="BLOCKED__R276_OWNER_SETUP_NOT_DEPLOYED"
elif h["status"] == 200 and "35ik" in version and owner_status == 200:
    verdict="OWNER_ROUTE_AVAILABLE__ADMIN_CONSENT_AND_REAL_SYNC_NOT_PROVEN"
else:
    verdict="INDETERMINATE__REQUIRE_AUTHORIZED_FIELD_READBACK"
out["verdict"]=verdict
out["production_accepted"]=False
out["pending_guest_orders_checked"]=False
out["owner_authenticated"]=False
out["local_synced"]=False
out["qr_order_delivered_once"]=False
Path("r286-readonly-field-probe.json").write_text(json.dumps(out,ensure_ascii=False,indent=2)+"\n")
print(json.dumps(out,ensure_ascii=False,indent=2))
