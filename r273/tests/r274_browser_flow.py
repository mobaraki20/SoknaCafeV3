#!/usr/bin/env python3
"""Chromium two-origin popup contract against exact R274 packaged JS.

Simulates HTTP API responses only; DOES NOT replace the MariaDB/HTTP
positive and negative evidence in the independent R274 cutover gate.
"""
import json, re, os, threading, time, zipfile, io
from pathlib import Path
from http.server import BaseHTTPRequestHandler,ThreadingHTTPServer
from playwright.sync_api import sync_playwright

LOCAL="http://127.0.0.1:18891"; PUBLIC="http://127.0.0.1:18892"
ART=Path(os.environ["R274_BROWSER_ARTIFACT_DIR"])
local_zip=next(ART.rglob("SOKNA-R274-LOCAL-231FK-CAFE-CUTOVER-RESUME-QA-DO-NOT-INSTALL.zip"))
public_zip=next(ART.rglob("SOKNA-R274-PUBLIC-35II-OWNER-RESUME-CODE-ONLY-QA-DO-NOT-INSTALL.zip"))
with zipfile.ZipFile(local_zip) as z:
 with zipfile.ZipFile(io.BytesIO(z.read("artifacts/local/web.zip"))) as w:
  LOCAL_JS=w.read("public/assets/connections-workspace.js").decode()
with zipfile.ZipFile(public_zip) as z: PUBLIC_JS=z.read("payload/assets/sokna-public-move.js").decode()
attrs=set(re.findall(r"\[data-([\w-]+)(?:=[^\]]+)?\]",LOCAL_JS))
special={"connections-workspace","public-move-target","public-move-form","public-move-check","public-move-resume","public-move-cancel","public-move-feedback"}
def other_tag(k):
 if k=="windows-agent":return f'<select data-{k}></select>'
 if k.endswith("-form"):return f'<form data-{k}></form>'
 return f'<div data-{k}></div>'
local_html='''<!doctype html><html><meta charset="utf-8"><body><main data-connections-workspace data-api="/integrations/api.php" data-csrf="qa">'''+''.join(other_tag(k) for k in sorted(attrs-special))+'''<form data-public-move-form><input data-public-move-target value="'''+PUBLIC+'''"><button data-public-move-check type="submit">Start</button></form><button data-public-move-resume>Resume</button><button data-public-move-cancel>Cancel</button><div data-public-move-feedback></div></main><script>window.SoknaUI={confirm:async()=>true,busy:async(_b,cb)=>cb(),toast:()=>{}};window.SoknaLocale={integer:String,instantDateTime:String,dateTime:String}</script><script src="/assets/connections-workspace.js"></script></body></html>'''
public_html='''<!doctype html><html><meta charset="utf-8"><body><main data-move-owner-page data-public-base="'''+PUBLIC+'''"><section data-login-section><input data-password type="password"><button data-owner-login>Login</button></section><section data-approve-section hidden><button data-owner-approve>Approve</button></section><details data-owner-upgrade><button data-upgrade-check>Check</button><div data-upgrade-form hidden><input data-upgrade-password><button data-upgrade-create>Create</button></div></details><div data-status></div></main><script src="/assets/sokna-public-move.js"></script></body></html>'''
CHALLENGE={"contract":"sokna-public-move-attestation-v1","operation_id":"move-012345678901234567890123","local_installation_id":"r274-cafe-demo","source_public_instance_id":"","source_base_url":"https://old-public.example","target_base_url":PUBLIC,"nonce":"ab"*24,"issued_at":0,"expires_at":0}
SNAPSHOT={"success":True,"snapshot":{"accommodation":{},"public_edge":{},"lan_staff":{},"public_origin_transition":{},"windows_services":{},"windows_runtime":{},"print_agents":[],"errors":{}}}
mode="normal"; log=[]
class Handler(BaseHTTPRequestHandler):
 def log_message(self,*a):pass
 def send(self,obj,ctype="application/json",status=200):
  blob=(obj if isinstance(obj,(bytes,str)) else json.dumps(obj)).encode() if not isinstance(obj,bytes) else obj
  self.send_response(status);self.send_header("Content-Type",ctype);self.send_header("Content-Length",len(blob));self.end_headers();self.wfile.write(blob)
 def do_GET(self):
  if self.server.server_port==18891:
   if self.path=="/assets/connections-workspace.js":return self.send(LOCAL_JS,"application/javascript")
   if self.path.startswith("/integrations/api.php"):return self.send(SNAPSHOT)
   return self.send(local_html,"text/html")
  if self.path=="/assets/sokna-public-move.js":return self.send(PUBLIC_JS,"application/javascript")
  if self.path.startswith("/move/approve"):return self.send(public_html,"text/html")
  return self.send({})
 def do_POST(self):
  body=json.loads(self.rfile.read(int(self.headers.get("Content-Length","0"))))
  action=body.get("action",self.path.split("/")[-1])
  log.append((mode,self.server.server_port,action))
  if self.server.server_port==18891:
   if action=="public_move_status":
    response={"success":True,"move":{"status":"none"}} if mode=="normal" else {"success":True,"move":{"status":"awaiting_owner_offer","operation_id":CHALLENGE["operation_id"],"target_base_url":PUBLIC,"minimum_next_epoch":2}}
   elif action=="public_move_inspect":response={"success":True,"public_move":{"route":"awaiting_owner"}}
   elif action=="public_move_begin":
    CHALLENGE["issued_at"]=int(time.time());CHALLENGE["expires_at"]=CHALLENGE["issued_at"]+300
    response={"success":True,"move":{"operation_id":CHALLENGE["operation_id"],"challenge":CHALLENGE,"minimum_next_epoch":2}}
   elif action=="public_move_confirm":response={"success":True,"move":{"status":"awaiting_owner_offer","minimum_next_epoch":2}}
   elif action=="public_move_existing_owner_offer_execute":response={"success":True,"move":{"status":"sync_pending"}}
   elif action=="public_move_finish":response={"success":True,"move":{"completed":True,"status":"completed"}}
   else:response={"success":True}
  else:
   if action=="login":response={"ok":True}
   elif action=="consent_prepare":response={"ok":True,"csrf":"qa-csrf"}
   elif action=="consent_sign":response={"ok":True,"proof":{"attestation":{"proof":"fixture"}},"receipt_envelope":{"receipt":"fixture"},"move_kind":"existing_paired","pairing_code":""}
   elif action=="offer_existing":response={"ok":True,"owner_authority_code":"12345678-12345678-12345678","owner_next_epoch":2}
   else:response={"ok":False}
  return self.send(response)
def server(port):
 s=ThreadingHTTPServer(("127.0.0.1",port),Handler);s.server_port=port
 threading.Thread(target=s.serve_forever,daemon=True).start();return s
def exercise(browser,variant):
 global mode
 mode=variant;start=len(log)
 context=browser.new_context()
 page=context.new_page()
 errors=[];page.on("pageerror",lambda e:errors.append(str(e)))
 page.goto(LOCAL)
 # Let startup public_move_status restore the operation before submitting.
 page.wait_for_timeout(500)
 with page.expect_popup(timeout=8000) as popup_evt:
  page.locator("[data-public-move-check]").click()
 popup=popup_evt.value
 popup.on("pageerror",lambda e:errors.append(str(e)))
 popup.wait_for_load_state("domcontentloaded")
 popup.locator("[data-password]").fill("owner-qa")
 popup.locator("[data-owner-login]").click()
 if variant=="normal":
  popup.locator("[data-owner-approve]").click()
 page.wait_for_function('document.querySelector("[data-public-move-feedback]")?.textContent?.includes("متصل شد")',timeout=15000)
 actions=[a for m,p,a in log[start:]]
 if actions.count("offer_existing")!=1 or actions.count("public_move_existing_owner_offer_execute")!=1 or actions.count("public_move_finish")!=1:
  raise RuntimeError("POPUP_HANDSHAKE_OR_FINISH_NOT_ONCE "+str(actions))
 if variant=="resume" and ("consent_sign" in actions or "public_move_confirm" in actions):
  raise RuntimeError("RESUME_INCORRECTLY_REISSUED_CONSENT "+str(actions))
 if variant=="normal" and ("consent_sign" not in actions or "public_move_confirm" not in actions):
  raise RuntimeError("INITIAL_APPROVAL_MISSING "+str(actions))
 if errors:raise RuntimeError("JS_PAGE_ERROR "+str(errors))
 print("R274_BROWSER_POPUP_"+variant.upper()+"_PASS",actions)
 context.close()
if __name__=="__main__":
 servers=[server(18891),server(18892)]
 try:
  with sync_playwright() as p:
   browser=p.chromium.launch(headless=True,args=["--no-sandbox"])
   for variant in ["normal","resume"]:exercise(browser,variant)
   browser.close()
 finally:
  for s in servers:s.shutdown()
