#!/usr/bin/env python3
from pathlib import Path
import re
R=Path(__file__).resolve().parents[1]
def need(path,*tokens):
    s=(R/path).read_text(encoding='utf-8')
    for t in tokens:
        if t not in s: raise SystemExit(f'FAIL {path} missing {t}')
    return s
m=need('apps/local-web/database/migrations/0025_g4_print_template_packages.sql','print_template_packages','print_template_activations','content_sha256','UNIQUE KEY uq_print_template_key_version')
s=need('apps/local-web/src/Domain/Printing/PrintTemplatePackageService.php','sokna-print-template-package-v1','print_template_unknown_field','applyActive','preview(')
for forbidden in ['eval(','include(','require(','shell_exec(','exec(','passthru(']:
    if forbidden in s: raise SystemExit('FAIL template owner contains executable package path: '+forbidden)
p=need('apps/local-web/src/Domain/Printing/PrintService.php','applyActive($payload)','applyPackage($payload,$packageId)',"'schema'=>'sokna-print-document-v2'")
a=need('apps/local-web/public/integrations/api.php','print_template_import','print_template_activate','print_template_preview','package_id')
j=need('apps/local-web/public/assets/integrations-workspace.js','Import قالب چاپ','/v1/preview','X-Sokna-Bridge-Pairing','Test Print','activatePrintTemplate')
h=need('apps/local-web/public/integrations/index.php','Import .soknaprint','data-print-template-list','sc-print-preview')
r=need('windows/print-agent/source/src/Sokna.PrintAgent.Worker/ReceiptRenderer.cs','base_font_size','title_font_size','table_font_size','section_order','responsive-receipt')
if 'style=' in h: raise SystemExit('FAIL integrations page introduced inline style owner')
if 'u.display_name created_by_name' not in s: raise SystemExit('FAIL print template snapshot must join users.display_name')
print('PASS G4.4 print template/package source contract')
