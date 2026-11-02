'use strict';
// R94 source-baseline guard (not an installer). Always preserve earlier managed code.
const fs=require('node:fs'),crypto=require('node:crypto');
const file=process.argv[2];if(!file)throw Error('usage: node managed-projection-source-guard.cjs <R93-managed-PublicEdgePublisherService.php>');
const code=fs.readFileSync(file,'utf8');
const blob=crypto.createHash('sha1').update('blob '+Buffer.byteLength(code)+'\0'+code).digest('hex');
if(blob!=='3667eb1c6ef473ad978f8248b61400418f84170b')throw Error('Wrong baseline: Github apps/ publisher is NOT installed R93 managed source');
const anchors=[
  'private static function validProjectionModelAck(',
  "if(!is_int($receipt['ack_sequence']??null)||$receipt['ack_sequence']!==$sequence||$sequence<1)return false;",
  'source_version=IF(VALUES(ack_sequence)>ack_sequence',
  'ack_sequence=GREATEST(ack_sequence,VALUES(ack_sequence))',
  'private function preparedProjectionModel('
];
for(const a of anchors)if(!code.includes(a))throw Error('Missing R93 safeguard: '+a);
if(code.indexOf('source_version=IF(VALUES(ack_sequence)>ack_sequence')>=code.indexOf('ack_sequence=GREATEST(ack_sequence,VALUES(ack_sequence))'))throw Error('Cursor/hash order changed');
console.log('PASS: exact R93 managed publisher + monotonic model receipt invariants');
