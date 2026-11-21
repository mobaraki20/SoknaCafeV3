<?php
declare(strict_types=1);
namespace Sokna\PublicEdge\Remote;

/** Read-only and bounded: absence outside the projection never means absence in Local. */
final class PublicInvoiceHistoryArchive
{
    public static function validated(array $archive,array $meta): bool
    {
        $range=(array)($archive['range']??[]);
        return ($meta['stale']??true)===false
          &&($archive['format']??'')==='sokna-public-invoice-history-v1'
          &&($archive['status']??'')==='available'
          &&($archive['basis']??'')==='receipt_business_day_for_archive__session_open_business_day_for_sale'
          &&self::day((string)($range['from']??'')) &&self::day((string)($range['to']??''))
          &&is_array($archive['rows']??null) &&is_bool($archive['truncated']??null);
    }
    public static function browse(array $archive,string $q='',string $from='',string $to='',int $page=1): ?array
    {
        $range=(array)$archive['range'];
        if($from!==''&&!self::day($from)||$to!==''&&!self::day($to)||($from!==''&&$to!==''&&$from>$to))return null;
        if(($from!==''&&$from<$range['from'])||($to!==''&&$to>$range['to']))return null;
        $q=self::fold(substr(trim($q),0,400));$filtered=[];
        foreach($archive['rows'] as $r){if(!is_array($r))continue;
            $day=(string)($r['receipt_business_date']??'');
            if($from!==''&&$day<$from||$to!==''&&$day>$to)continue;
            if($q!==''&&!str_contains(self::fold((string)($r['invoice_number']??'').' '.(string)($r['table_name']??'')),$q))continue;
            // Never transfer line items in a list response.
            $filtered[]=array_diff_key($r,['items'=>true]);
        }
        $page=max(1,min(1000,$page));$perPage=20;$total=count($filtered);
        return ['rows'=>array_slice($filtered,($page-1)*$perPage,$perPage),
          'pagination'=>['page'=>$page,'page_size'=>$perPage,'projected_matches'=>$total,'has_more'=>$total>$page*$perPage],
          'coverage'=>['from'=>$range['from'],'to'=>$range['to'],'truncated'=>$archive['truncated'],
          'scope'=>'projected_receipts_only','as_of'=>(string)($archive['generated_at']??'')]];
    }
    public static function detail(array $archive,int $id): ?array
    {
        if($id<1)return null;
        foreach($archive['rows'] as $r)if(is_array($r)&&(int)($r['id']??0)===$id)return $r;
        return null;
    }
    private static function day(string $day):bool
    {return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$day,$m)===1&&checkdate((int)$m[2],(int)$m[3],(int)$m[1]);}
    private static function fold(string $x):string
    {$x=strtr($x,['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','ي'=>'ی','ك'=>'ک']);
      return function_exists('mb_strtolower')?mb_strtolower($x,'UTF-8'):strtolower($x);}
}
