$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
. ./packaging/windows/scripts/Get-SoknaMaintenanceUpgradePlan.ps1
$cases=@(
 @{supervisor='0.3.22';engine='0.3.22';stages='0326,0327'},
 @{supervisor='0.3.23';engine='0.3.23';stages='0326,0327'},
 @{supervisor='0.3.24';engine='0.3.24';stages='0326,0327'},
 @{supervisor='0.3.25';engine='0.3.25';stages='0326,0327'},
 @{supervisor='0.3.26';engine='0.3.26';stages='0327'},
 @{supervisor='0.3.27';engine='0.3.27';stages='0327'}
)
foreach($case in $cases){
 $plan=Get-SoknaMaintenanceUpgradePlan -SupervisorVersion $case.supervisor -EngineVersion $case.engine
 if(($plan.stages -join ',')-ne$case.stages -or $plan.target-ne'0.3.27'){throw ('ROUTER_WRONG_'+$case.supervisor)}
 Write-Host ('PASS ROUTER '+$case.supervisor+' => '+($plan.stages -join ' => '))
}
foreach($bad in @(@('0.3.22','0.3.26'),@('0.3.21','0.3.21'),@('0.3.28','0.3.28'),@('unknown','unknown'))){
 $rejected=$false
 try{[void](Get-SoknaMaintenanceUpgradePlan -SupervisorVersion $bad[0] -EngineVersion $bad[1])}catch{$rejected=$true}
 if(-not$rejected){throw ('ROUTER_ACCEPTED_INVALID_'+($bad -join '_'))}
}
Write-Host 'PASS six supported baselines + four rejection cases'
