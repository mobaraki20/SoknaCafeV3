#!/usr/bin/env python3
import importlib.util
import os
from pathlib import Path

spec=importlib.util.spec_from_file_location('gate',str(Path(__file__).with_name('check_public_emergency_schema_route.py')))
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
root=Path(os.environ.get('SOKNA_FIX_ROOT','/mnt/data/r267'))
pkgs=Path(os.environ.get('SOKNA_INPUT_ROOT','/mnt/data/r267/baselines'))
orig=pkgs/'SOKNA-R249-PUBLIC-35IC-CUMULATIVE-35HK-35HT-GATED.zip'
failed=pkgs/'SOKNA-R262-PUBLIC-35ID-CUMULATIVE-QA-NOT-FOR-INSTALL.zip'
out=root/'output' if (root/'output').is_dir() else root
bridge=out/'SOKNA-R265-PUBLIC-35IF-EMERGENCY-BRIDGE-CODE-ONLY-QA.zip'
final=out/'SOKNA-R266-PUBLIC-35IG-SCHEMA-MOVE-CUMULATIVE-QA.zip'
old_cap={}
new_cap={'emergency_schema_migration_contract':'append_only_exact_sha_v1','emergency_migration_commit_requires_fresh_readback':True}

cases=[
 ('R249-to-R262 must be rejected on old Emergency',orig,failed,old_cap,'BLOCK','emergency_schema_mutation_not_qualified'),
 ('R249-to-R265 bridge allowed for host preflight',orig,bridge,old_cap,'ELIGIBLE_FOR_HOST_PREFLIGHT_ONLY','schema_route_compatible'),
 ('R265-to-R266 blocked if updated Emergency capabilities missing',bridge,final,old_cap,'BLOCK','emergency_schema_mutation_not_qualified'),
 ('R265-to-R266 schema route allowed with authenticated capabilities',bridge,final,new_cap,'ELIGIBLE_FOR_HOST_PREFLIGHT_ONLY','schema_route_compatible'),
 ('R249 cannot skip bridge and apply R266',orig,final,new_cap,'BLOCK','exact_source_not_supported'),
]
for name,source,target,caps,status,reason in cases:
 r=module.check(source,target,caps)
 assert (r['status'],r['reason'],r['production_install_authorized']) == (status,reason,False),(name,r)
 print('PASS',name)
print('PASS all five release route blocks / preflight decisions')
