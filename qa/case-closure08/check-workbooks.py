import sys, pathlib,json,zipfile,xml.etree.ElementTree as ET
p=pathlib.Path(sys.argv[1]); f=json.loads((p/'closure08-fixture.json').read_text())['export']; n={'m':'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
def read(path):
 with zipfile.ZipFile(path) as z:
  assert z.testzip() is None
  sheets=ET.fromstring(z.read('xl/workbook.xml')).findall('m:sheets/m:sheet',n)
  out={}
  for s in sheets:
   root=ET.fromstring(z.read('xl/worksheets/sheet'+s.attrib['sheetId']+'.xml'))
   out[s.attrib['name']]=[[(''.join(c.itertext()) if c.get('t')=='inlineStr' else c.findtext('m:v',default='',namespaces=n)) for c in r.findall('m:c',n)] for r in root.findall('m:sheetData/m:row',n)]
  alltext=''.join(z.read(k).decode('utf-8',errors='ignore') for k in z.namelist() if k.endswith('.xml'))
  assert not any(v in alltext for v in ['qa-secret-marker','qa-private-agent','192.0.2.1'])
  return out
for name in ['cc08-admin.xlsx','cc08-nonaudit.xlsx','cc08-http.xlsx']:
 w=read(p/name); overview={r[0]:r[2] for r in w['Overview'][1:]}; assert int(overview['net_sales'])==f['net_sales']; assert dict(w['README'][1:])['from']==dict(w['README'][1:])['to']==f['day']; print('PASS range workbook exact net sales and date',name)
 if 'nonaudit' in name: assert 'Audit_Activity' not in w;print('PASS non-audit workbook hides activity')
 else:
  rows=w['Audit_Activity'][1:];assert [int(r[0]) for r in rows]==f['ids'];assert all(int(r[1])==f['actor'] and r[3]=='qa_case08.export' and r[6].startswith('literal%_') for r in rows);print('PASS complete filtered 105 activity rows, literal wildcard and no privacy fields')
print('GROUP PASS REP-L278 workbook exact range and scope')
