<?php
declare(strict_types=1);

namespace Sokna\Local\Search;

final class SearchNormalizer
{
    public static function normalize(string $value): string
    {
        $value=trim($value);
        $value=strtr($value,[
            'ي'=>'ی','ى'=>'ی','ئ'=>'ی','ك'=>'ک','ة'=>'ه','ۀ'=>'ه',
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
            "\u{064B}"=>' ',"\u{064C}"=>' ',"\u{064D}"=>' ',"\u{064E}"=>' ',"\u{064F}"=>' ',"\u{0650}"=>' ',"\u{0651}"=>' ',"\u{0652}"=>' ',
            "\u{200C}"=>' ',"\u{200F}"=>' ',"\u{200E}"=>' ',
        ]);
        $value=preg_replace('/\s+/u',' ',$value)??$value;
        return function_exists('mb_strtolower')?mb_strtolower(trim($value),'UTF-8'):strtolower(trim($value));
    }

    public static function length(string $value): int
    {
        return function_exists('mb_strlen')?mb_strlen($value,'UTF-8'):strlen($value);
    }

    public static function likePrefix(string $value): string
    {
        return strtr($value,['\\'=>'\\\\','%'=>'\\%','_'=>'\\_']).'%';
    }

    public static function likeContains(string $value): string
    {
        return '%'.strtr($value,['\\'=>'\\\\','%'=>'\\%','_'=>'\\_']).'%';
    }

    /** @return list<string> */
    public static function terms(string $value): array
    {
        $value=self::normalize($value);
        return array_values(array_filter(explode(' ',$value),static fn(string $term): bool => $term!==''));
    }

    public static function matches(string $haystack,string $query): bool
    {
        $haystack=self::normalize($haystack);
        foreach(self::terms($query) as $term)if(!str_contains($haystack,$term))return false;
        return true;
    }
}
