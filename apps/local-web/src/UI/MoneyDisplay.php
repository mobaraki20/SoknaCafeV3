<?php
declare(strict_types=1);

namespace Sokna\Local\UI;

/** Canonical human-facing money/number formatting for server-rendered Persian UI. */
final class MoneyDisplay
{
    public static function digits(string|int|float $value): string
    {
        return strtr((string)$value,[
            '٠'=>'۰','١'=>'۱','٢'=>'۲','٣'=>'۳','٤'=>'۴','٥'=>'۵','٦'=>'۶','٧'=>'۷','٨'=>'۸','٩'=>'۹',
            '0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹',
        ]);
    }

    public static function number(int|float $value,int $decimals=0): string
    {
        $formatted=number_format($value,$decimals,'٫','٬');
        return self::digits($formatted);
    }

    public static function money(int|float $value): string
    {
        return self::number($value,0).' تومان';
    }

    public static function unitCost(int|float $value,string $unit,int|float $multiplier=1): string
    {
        return self::money($value*$multiplier).'/'.$unit;
    }
}
