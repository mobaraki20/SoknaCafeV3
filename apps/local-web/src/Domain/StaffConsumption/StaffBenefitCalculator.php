<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\StaffConsumption;

final class StaffBenefitCalculator
{
    public const ALGORITHM_VERSION = 'f1.2-v1';

    /**
     * @param list<array{item_id:int,category_id:int,item_name?:string,unit_price:int,quantity:int}> $lines
     * @param array{evaluation_at:string,profile_present:bool,profile:?array,assigned_policy:?array,default_policy:?array,policy_rules:list<array>,stored_overrides:list<array>,runtime_override:?array,personnel_id:int} $context
     */
    public function calculate(array $lines, array $context): array
    {
        $evaluationAt = $this->evaluationAt((string)($context['evaluation_at'] ?? ''));
        $personnelId = (int)($context['personnel_id'] ?? 0);
        if ($personnelId < 1) {
            throw new StaffConsumptionException('personnel_invalid', 'پرسنل برای محاسبه مزایا معتبر نیست.', 422);
        }
        if ($lines === []) {
            throw new StaffConsumptionException('benefit_lines_empty', 'برای محاسبه مزایا حداقل یک آیتم لازم است.', 422);
        }

        $selection = $this->selectPolicy(
            (bool)($context['profile_present'] ?? false),
            is_array($context['profile'] ?? null) ? $context['profile'] : null,
            is_array($context['assigned_policy'] ?? null) ? $context['assigned_policy'] : null,
            is_array($context['default_policy'] ?? null) ? $context['default_policy'] : null,
        );
        $policyRules = is_array($context['policy_rules'] ?? null) ? array_values($context['policy_rules']) : [];
        $storedOverrides = is_array($context['stored_overrides'] ?? null) ? array_values($context['stored_overrides']) : [];
        $runtimeOverride = is_array($context['runtime_override'] ?? null) ? $context['runtime_override'] : null;

        $menuTotal = 0;
        $benefitTotal = 0;
        $resultLines = [];
        foreach ($lines as $index => $line) {
            if (!is_array($line)) {
                throw new StaffConsumptionException('benefit_line_invalid', 'ساختار آیتم محاسبه مزایا معتبر نیست.', 422);
            }
            $normalized = $this->normalizeLine($line, (int)$index);
            $definition = $this->resolveDefinition($normalized, $runtimeOverride, $storedOverrides, $policyRules, $selection['policy']);
            $benefit = $this->benefitAmount($normalized['menu_line_amount'], $normalized['quantity'], $definition);
            $menuTotal = $this->safeAdd($menuTotal, $normalized['menu_line_amount']);
            $benefitTotal = $this->safeAdd($benefitTotal, $benefit);
            $resultLines[] = [
                'item_id' => $normalized['item_id'],
                'category_id' => $normalized['category_id'],
                'item_name' => $normalized['item_name'],
                'unit_price' => $normalized['unit_price'],
                'quantity' => $normalized['quantity'],
                'menu_line_amount' => $normalized['menu_line_amount'],
                'benefit_amount' => $benefit,
                'discount_amount' => 0,
                'payable_before_discount' => $normalized['menu_line_amount'] - $benefit,
                'benefit_resolution' => $definition,
            ];
        }

        $snapshot = [
            'algorithm_version' => self::ALGORITHM_VERSION,
            'evaluation_at' => $evaluationAt,
            'personnel_id' => $personnelId,
            'profile_resolution' => $selection['profile_resolution'],
            'profile_id' => $selection['profile_id'],
            'policy_id' => $selection['policy_id'],
            'policy_key' => $selection['policy_key'],
            'runtime_override' => $runtimeOverride,
            'menu_value_amount' => $menuTotal,
            'benefit_amount' => $benefitTotal,
            'discount_amount' => 0,
            'payable_before_discount' => $menuTotal - $benefitTotal,
            'lines' => $resultLines,
        ];
        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $snapshot['snapshot_sha256'] = hash('sha256', $json);
        return $snapshot;
    }

    /** @return array{profile_resolution:string,profile_id:?int,policy_id:?int,policy_key:?string,policy:?array} */
    public function selectPolicy(bool $profilePresent, ?array $profile, ?array $assignedPolicy, ?array $defaultPolicy): array
    {
        if ($profilePresent) {
            $profileId = (int)($profile['id'] ?? 0);
            $policyId = (int)($profile['policy_id'] ?? 0);
            if ($policyId < 1) {
                return ['profile_resolution'=>'profile_explicit_no_benefit','profile_id'=>$profileId > 0 ? $profileId : null,'policy_id'=>null,'policy_key'=>null,'policy'=>null];
            }
            if ($assignedPolicy === null || (int)($assignedPolicy['active'] ?? 0) !== 1 || (int)($assignedPolicy['id'] ?? 0) !== $policyId) {
                return ['profile_resolution'=>'profile_policy_unavailable','profile_id'=>$profileId > 0 ? $profileId : null,'policy_id'=>null,'policy_key'=>null,'policy'=>null];
            }
            return [
                'profile_resolution'=>'assigned_policy',
                'profile_id'=>$profileId > 0 ? $profileId : null,
                'policy_id'=>$policyId,
                'policy_key'=>(string)($assignedPolicy['policy_key'] ?? ''),
                'policy'=>$assignedPolicy,
            ];
        }
        if ($defaultPolicy !== null && (int)($defaultPolicy['active'] ?? 0) === 1) {
            return [
                'profile_resolution'=>'default_policy',
                'profile_id'=>null,
                'policy_id'=>(int)$defaultPolicy['id'],
                'policy_key'=>(string)($defaultPolicy['policy_key'] ?? ''),
                'policy'=>$defaultPolicy,
            ];
        }
        return ['profile_resolution'=>'no_policy','profile_id'=>null,'policy_id'=>null,'policy_key'=>null,'policy'=>null];
    }

    /** @return array{source:string,source_id:?int,scope_type:string,scope_id:?int,benefit_type:string,percent_bps:?int,fixed_amount:?int,fixed_basis:?string,reason:?string,actor_user_id:?int} */
    private function resolveDefinition(array $line, ?array $runtimeOverride, array $storedOverrides, array $policyRules, ?array $selectedPolicy): array
    {
        if ($runtimeOverride !== null && $this->matches($runtimeOverride, $line)) {
            $definition = $this->normalizeDefinition($runtimeOverride, 'runtime_override');
            $definition['actor_user_id'] = (int)($runtimeOverride['actor_user_id'] ?? 0) ?: null;
            $definition['reason'] = trim((string)($runtimeOverride['reason'] ?? '')) ?: null;
            return $definition;
        }

        $stored = $this->bestMatch($storedOverrides, $line, false);
        if ($stored !== null) {
            $definition = $this->normalizeDefinition($stored, 'personnel_override');
            $definition['actor_user_id'] = null;
            $definition['reason'] = trim((string)($stored['reason'] ?? '')) ?: null;
            return $definition;
        }

        if ($selectedPolicy !== null) {
            $rule = $this->bestMatch($policyRules, $line, true);
            if ($rule !== null) {
                $definition = $this->normalizeDefinition($rule, 'policy_rule');
                $definition['actor_user_id'] = null;
                $definition['reason'] = null;
                return $definition;
            }
        }

        return [
            'source'=>'none','source_id'=>null,'scope_type'=>'all','scope_id'=>null,
            'benefit_type'=>'none','percent_bps'=>null,'fixed_amount'=>null,'fixed_basis'=>null,
            'reason'=>null,'actor_user_id'=>null,
        ];
    }

    private function normalizeDefinition(array $row, string $source): array
    {
        $type = (string)($row['benefit_type'] ?? 'none');
        if (!in_array($type, ['none','free','percent','fixed'], true)) {
            throw new StaffConsumptionException('benefit_type_invalid', 'نوع مزیت معتبر نیست.', 422);
        }
        $scope = (string)($row['scope_type'] ?? 'all');
        if (!in_array($scope, ['all','category','item'], true)) {
            throw new StaffConsumptionException('benefit_scope_invalid', 'محدوده مزیت معتبر نیست.', 422);
        }
        $scopeId = isset($row['scope_id']) && $row['scope_id'] !== null ? (int)$row['scope_id'] : null;
        $percent = isset($row['percent_bps']) && $row['percent_bps'] !== null ? (int)$row['percent_bps'] : null;
        $fixed = isset($row['fixed_amount']) && $row['fixed_amount'] !== null ? (int)$row['fixed_amount'] : null;
        if ($type === 'percent' && ($percent === null || $percent < 0 || $percent > 10000)) {
            throw new StaffConsumptionException('benefit_value_invalid', 'درصد مزیت معتبر نیست.', 422);
        }
        if ($type === 'fixed' && ($fixed === null || $fixed < 0)) {
            throw new StaffConsumptionException('benefit_value_invalid', 'مبلغ ثابت مزیت معتبر نیست.', 422);
        }
        return [
            'source'=>$source,
            'source_id'=>(int)($row['id'] ?? 0) ?: null,
            'scope_type'=>$scope,
            'scope_id'=>$scopeId,
            'benefit_type'=>$type,
            'percent_bps'=>$type === 'percent' ? $percent : null,
            'fixed_amount'=>$type === 'fixed' ? $fixed : null,
            'fixed_basis'=>$type === 'fixed' ? 'per_unit' : null,
            'reason'=>null,
            'actor_user_id'=>null,
        ];
    }

    private function bestMatch(array $rows, array $line, bool $withPriority): ?array
    {
        $matches = [];
        foreach ($rows as $row) {
            if (!is_array($row) || (int)($row['active'] ?? 1) !== 1 || !$this->matches($row, $line)) continue;
            $matches[] = $row;
        }
        if ($matches === []) return null;
        usort($matches, function(array $a, array $b) use ($withPriority): int {
            $sa = $this->specificity((string)($a['scope_type'] ?? 'all'));
            $sb = $this->specificity((string)($b['scope_type'] ?? 'all'));
            if ($sa !== $sb) return $sb <=> $sa;
            if ($withPriority) {
                $pa = (int)($a['priority'] ?? 100); $pb = (int)($b['priority'] ?? 100);
                if ($pa !== $pb) return $pa <=> $pb;
                return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
            }
            $va = (string)($a['valid_from'] ?? ''); $vb = (string)($b['valid_from'] ?? '');
            if ($va !== $vb) return strcmp($vb, $va);
            return (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0);
        });
        return $matches[0];
    }

    private function matches(array $definition, array $line): bool
    {
        $scope = (string)($definition['scope_type'] ?? 'all');
        $scopeId = isset($definition['scope_id']) && $definition['scope_id'] !== null ? (int)$definition['scope_id'] : null;
        return match ($scope) {
            'all' => true,
            'category' => $scopeId !== null && $scopeId === $line['category_id'],
            'item' => $scopeId !== null && $scopeId === $line['item_id'],
            default => false,
        };
    }

    private function benefitAmount(int $lineAmount, int $quantity, array $definition): int
    {
        return match ($definition['benefit_type']) {
            'none' => 0,
            'free' => $lineAmount,
            'percent' => min($lineAmount, $this->basisPoints($lineAmount, (int)$definition['percent_bps'])),
            'fixed' => min($lineAmount, $this->safeMultiply((int)$definition['fixed_amount'], $quantity)),
            default => 0,
        };
    }

    private function basisPoints(int $amount, int $bps): int
    {
        $whole = intdiv($amount, 10000) * $bps;
        $rem = ($amount % 10000) * $bps;
        return $this->safeAdd($whole, intdiv($rem, 10000));
    }

    private function normalizeLine(array $line, int $index): array
    {
        $itemId=(int)($line['item_id']??0);$categoryId=(int)($line['category_id']??0);$unit=(int)($line['unit_price']??-1);$qty=(int)($line['quantity']??0);
        if($itemId<1||$categoryId<1||$unit<0||$qty<1)throw new StaffConsumptionException('benefit_line_invalid','آیتم شماره '.($index+1).' برای محاسبه مزایا معتبر نیست.',422);
        return [
            'item_id'=>$itemId,'category_id'=>$categoryId,'item_name'=>trim((string)($line['item_name']??'')),
            'unit_price'=>$unit,'quantity'=>$qty,'menu_line_amount'=>$this->safeMultiply($unit,$qty),
        ];
    }

    private function evaluationAt(string $value): string
    {
        $value=trim($value);
        $dt=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value);
        if($dt===false||$dt->format('Y-m-d H:i:s')!==$value)throw new StaffConsumptionException('evaluation_at_invalid','زمان محاسبه مزایا معتبر نیست.',422);
        return $value;
    }

    private function specificity(string $scope): int { return match($scope){'item'=>3,'category'=>2,'all'=>1,default=>0}; }
    private function safeMultiply(int $a,int $b): int { if($a<0||$b<0||($a!==0&&$b>intdiv(PHP_INT_MAX,$a)))throw new StaffConsumptionException('benefit_amount_overflow','مبلغ محاسبه مزایا بیش از حد مجاز است.',422);return $a*$b; }
    private function safeAdd(int $a,int $b): int { if($a<0||$b<0||$a>PHP_INT_MAX-$b)throw new StaffConsumptionException('benefit_amount_overflow','مبلغ محاسبه مزایا بیش از حد مجاز است.',422);return $a+$b; }
}
