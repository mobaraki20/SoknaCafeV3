from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
issues=[]
def must(path,text,msg):
    data=(ROOT/path).read_text(encoding='utf-8')
    if text not in data: issues.append(msg)
def must_not(path,text,msg):
    data=(ROOT/path).read_text(encoding='utf-8')
    if text in data: issues.append(msg)

must('src/Domain/System/WindowsServicesPairingService.php','pairing_existing_agent_requires_reconnect','duplicate initial pairing must fail closed')
must('src/Domain/System/WindowsServicesPairingService.php','public function retireConnection','confirmed connection retirement missing')
must('src/Domain/System/WindowsServicesPairingService.php',"'retired'",'retired pairing state missing')
must('src/Domain/System/WindowsServicesPairingService.php',"$transient??$confirmed??$latest",'status must prefer transient/confirmed over latest terminal ticket')
must('src/Domain/System/WindowsServicesPairingService.php',"$out['history']=",'pairing history missing')
must('src/Domain/Printing/PrintService.php','public function retireAgentByAdmin','admin retirement missing')
must('src/Domain/Printing/PrintService.php','agent_has_inflight_jobs','retirement must block in-flight/ambiguous jobs')
must('src/Domain/Printing/PrintService.php','printing.destination_fallback_promoted','fallback promotion audit missing')
must('src/Domain/Printing/PrintService.php','printing.destination_disabled_for_retired_agent','destination disable audit missing')
must('src/Domain/Printing/PrintService.php','retired_by_user_id','retirement actor must be persisted')
must('public/integrations/api.php','windows_services_agent_retire','connections retirement endpoint missing')
must('public/integrations/api.php','allow_additional','explicit add-device intent missing')
must('public/printing/api.php','agent_onboarding_moved','legacy raw agent onboarding must be rejected')
must_not('public/printing/api.php',"$core->printing()->createAgent((string)($data['name']",'printing API must not mint raw agent tokens')
must('public/integrations/index.php','data-windows-connections','connection lifecycle UI missing')
must('public/integrations/index.php','data-windows-pairing-history','pairing history UI missing')
must('public/integrations/index.php','data-windows-add-device-disclosure','new device action must be advanced/explicit')
must('public/assets/connections-workspace.js','runtimeLive&&connected.length','live badge must use runtime + heartbeat evidence')
must('public/assets/connections-workspace.js','data-windows-retire-agent','retire action rendering missing')
must('public/assets/connections-workspace.js','allow_additional:!!allowAdditional','UI must distinguish initial from explicit additional device')

if issues:
    print('CONNECTIONS_WINDOWS_LIFECYCLE_CONTRACT_FAIL')
    for i in issues: print('-',i)
    raise SystemExit(1)
print('CONNECTIONS_WINDOWS_LIFECYCLE_CONTRACT_PASS checks=20')
