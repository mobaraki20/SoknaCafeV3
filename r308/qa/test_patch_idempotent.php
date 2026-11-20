<?php
declare(strict_types=1);
// At minimum, ensure the changed guard is after the no-op path in both edit modes.
$p=getenv('SOKNA_SOURCE_ROOT').'/src/Domain/Orders/OrderStaffActionService.php';
$s=file_get_contents($p);
if(!$s)throw new RuntimeException('SOURCE_NOT_FOUND');
foreach(['$quantityChanged||$distributionChanged','$quantityChanged||$modeChanged'] as $mode){
  $needle='$this->assertCorrectionReceiptTotalsSafe($allocations,'.$mode.');';
  if(substr_count($s,$needle)!==1)throw new RuntimeException('GUARD_POSITION_INVALID '.$mode);
}
if(substr_count($s,'$this->assertCorrectionReceiptTotalsSafe($allocations);')!==0)
 throw new RuntimeException('OLD_BLANKET_GUARD_PRESENT');
echo "PASS_SAFE_GUARDS_AFTER_NOOP\n";
