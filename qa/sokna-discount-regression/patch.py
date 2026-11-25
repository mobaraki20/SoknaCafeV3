import pathlib,sys
r=pathlib.Path(sys.argv[1]);p=r/'local/src/Domain/Finance/SettlementService.php';s=p.read_text()
a="        if($this->hasActiveItemizedTx($sessionId))\n            throw new SettlementStateConflict('itemized_locked'"
assert s.count(a)==1
s=s.replace(a,"        $existingGrant=$this->pdo->prepare('SELECT 1 FROM remaining_account_discount_grants WHERE session_id=? LIMIT 1 FOR UPDATE');\n        $existingGrant->execute([$sessionId]);\n        if($existingGrant->fetchColumn()!==false)\n            throw new SettlementStateConflict('remaining_discount_exists','تخفیف مانده قبلاً ثبت شده است؛ اصلاح آن از مسیر مسئول مالی انجام می‌شود.',409);\n"+a)
a="            $account=$this->accountTx($sessionId);\n            $type=$type===null||trim($type)===''?null:trim($type);"
assert s.count(a)==1
s=s.replace(a,"            $account=$this->accountTx($sessionId);\n            if(!empty($account['session']['remaining_discount_grant']))\n                throw new SettlementStateConflict('remaining_discount_exists','تخفیف مانده قبلاً ثبت شده است؛ اصلاح آن از مسیر مسئول مالی انجام می‌شود.',409);\n            $type=$type===null||trim($type)===''?null:trim($type);")
a="                if((int)$line['paid_quantity']<$paidFloor&&!is_array($rev)){"
assert s.count(a)==1
s=s.replace(a,"                $originalAllocation=null;foreach($originalGrantRows as $oldAllocation)if((int)$oldAllocation['order_item_id']===$id){$originalAllocation=$oldAllocation;break;}\n                $sameOriginalCohort=!is_array($rev)||(is_array($originalAllocation)&&$fixedRevision===null&&!(int)($alloc['paid_grant_discount']??0)&&$paidFloor===(int)$originalAllocation['paid_quantity_at_grant']&&(int)$alloc['quantity_snapshot']===(int)$originalAllocation['quantity_snapshot']&&(int)$alloc['original_discount_snapshot']===(int)$originalAllocation['original_discount_snapshot']);\n                if((int)$line['paid_quantity']<$paidFloor&&$sameOriginalCohort){")
p.write_text(s)
print('PASS guarded existing grant and unchanged-cohort revision patch')
