<?php
declare(strict_types=1);
namespace Sokna\Local\Domain\Reporting;

/**
 * Minimal dependency-free XLSX writer for bounded report exports.
 * Uses ZIP STORE entries, inline strings, raw numeric cells, RTL worksheets,
 * a bold header row, frozen first row and autofilter.
 */
final class XlsxWorkbook
{
    /** @param list<array{name:string,headers:list<string>,rows:list<list<mixed>>}> $sheets */
    public static function build(array $sheets,array $meta=[]): string
    {
        if($sheets===[])throw new \InvalidArgumentException('xlsx_requires_sheet');
        $entries=[];$sheetRefs=[];$sheetIndex=0;
        foreach($sheets as $sheet){
            $sheetIndex++;$name=self::sheetName((string)($sheet['name']??('Sheet'.$sheetIndex)),$sheetIndex);
            $headers=array_values(array_map('strval',$sheet['headers']??[]));$rows=$sheet['rows']??[];
            $entries['xl/worksheets/sheet'.$sheetIndex.'.xml']=self::sheetXml($headers,$rows);
            $sheetRefs[]=['name'=>$name,'id'=>$sheetIndex];
        }
        $created=trim((string)($meta['created_at_utc']??gmdate('Y-m-d\TH:i:s\Z')));if($created==='')$created=gmdate('Y-m-d\TH:i:s\Z');
        $entries['[Content_Types].xml']=self::contentTypes(count($sheetRefs));
        $entries['_rels/.rels']=self::rootRels();
        $entries['docProps/app.xml']=self::appXml(count($sheetRefs));
        $entries['docProps/core.xml']=self::coreXml($created,(string)($meta['title']??'SOKNA Report'));
        $entries['xl/workbook.xml']=self::workbookXml($sheetRefs);
        $entries['xl/_rels/workbook.xml.rels']=self::workbookRels(count($sheetRefs));
        $entries['xl/styles.xml']=self::stylesXml();
        ksort($entries,SORT_STRING);
        return self::zip($entries);
    }

    private static function sheetXml(array $headers,array $rows): string
    {
        if($headers===[])$headers=['value'];
        $columnCount=count($headers);$rowCount=count($rows)+1;$last=self::columnName($columnCount);
        $out='<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $out.='<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $out.='<dimension ref="A1:'.$last.$rowCount.'"/><sheetViews><sheetView workbookViewId="0" rightToLeft="1"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        $out.='<sheetFormatPr defaultRowHeight="15"/><sheetData>';
        $out.='<row r="1">';foreach($headers as $i=>$value)$out.=self::cell($i+1,1,$value,1);$out.='</row>';
        $r=1;foreach($rows as $row){$r++;$out.='<row r="'.$r.'">';for($c=1;$c<=$columnCount;$c++)$out.=self::cell($c,$r,$row[$c-1]??null,0);$out.='</row>';}
        $out.='</sheetData><autoFilter ref="A1:'.$last.$rowCount.'"/></worksheet>';
        return $out;
    }

    private static function cell(int $column,int $row,mixed $value,int $style): string
    {
        $ref=self::columnName($column).$row;$s=$style>0?' s="'.$style.'"':'';
        if($value===null)return '<c r="'.$ref.'"'.$s.'/>';
        if(is_bool($value))return '<c r="'.$ref.'"'.$s.' t="b"><v>'.($value?'1':'0').'</v></c>';
        if((is_int($value)||is_float($value))&&is_finite((float)$value))return '<c r="'.$ref.'"'.$s.'><v>'.self::number($value).'</v></c>';
        $text=self::cleanText((string)$value);return '<c r="'.$ref.'"'.$s.' t="inlineStr"><is><t xml:space="preserve">'.self::xml($text).'</t></is></c>';
    }

    private static function cleanText(string $value): string
    {
        $value=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u','',$value)??'';
        if(strlen($value)>32767){preg_match('/^.{0,32767}/us',$value,$m);$value=(string)($m[0]??'');}
        return $value;
    }
    private static function number(int|float $value): string{return is_int($value)?(string)$value:rtrim(rtrim(sprintf('%.10F',$value),'0'),'.');}
    private static function xml(string $value): string{return htmlspecialchars($value,ENT_QUOTES|ENT_XML1,'UTF-8');}
    private static function columnName(int $n): string{$s='';while($n>0){$n--; $s=chr(65+($n%26)).$s;$n=intdiv($n,26);}return $s;}
    private static function sheetName(string $name,int $fallback): string{$name=trim(preg_replace('~[\\/*?:\[\]]~u','-',$name)??'');if($name==='')$name='Sheet'.$fallback;if(strlen($name)>31){preg_match('/^.{0,31}/us',$name,$m);$name=(string)($m[0]??'Sheet'.$fallback);}return $name;}

    private static function contentTypes(int $count): string
    {
        $s='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
        for($i=1;$i<=$count;$i++)$s.='<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        return $s.'</Types>';
    }
    private static function rootRels(): string{return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>';}
    private static function appXml(int $count): string{return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>SOKNA Cafe V3</Application><AppVersion>3.0</AppVersion><Worksheets>'.$count.'</Worksheets></Properties>';}
    private static function coreXml(string $created,string $title): string{return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>'.self::xml($title).'</dc:title><dc:creator>SOKNA Cafe V3</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">'.self::xml($created).'</dcterms:created></cp:coreProperties>';}
    private static function workbookXml(array $sheets): string{$s='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView/></bookViews><sheets>';foreach($sheets as $x)$s.='<sheet name="'.self::xml($x['name']).'" sheetId="'.$x['id'].'" r:id="rId'.$x['id'].'"/>';return $s.'</sheets></workbook>';}
    private static function workbookRels(int $count): string{$s='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';for($i=1;$i<=$count;$i++)$s.='<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';$s.='<Relationship Id="rId'.($count+1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';return $s;}
    private static function stylesXml(): string{return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Arial"/><family val="2"/></font><font><b/><sz val="11"/><name val="Arial"/><family val="2"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles><dxfs count="0"/><tableStyles count="0" defaultTableStyle="TableStyleMedium2" defaultPivotStyle="PivotStyleLight16"/></styleSheet>'; }

    /** @param array<string,string> $entries */
    private static function zip(array $entries): string
    {
        $body='';$central='';$offset=0;$count=0;$dosTime=0;$dosDate=33;
        foreach($entries as $name=>$data){$name=(string)$name;$data=(string)$data;$nameBytes=$name;$size=strlen($data);$crc=(int)hexdec(hash('crc32b',$data));$flags=0x0800;
            $local=pack('VvvvvvVVVvv',0x04034b50,20,$flags,0,$dosTime,$dosDate,$crc,$size,$size,strlen($nameBytes),0).$nameBytes;
            $body.=$local.$data;
            $central.=pack('VvvvvvvVVVvvvvvVV',0x02014b50,20,20,$flags,0,$dosTime,$dosDate,$crc,$size,$size,strlen($nameBytes),0,0,0,0,0,$offset).$nameBytes;
            $offset+=strlen($local)+$size;$count++;
        }
        $eocd=pack('VvvvvVVv',0x06054b50,0,0,$count,$count,strlen($central),strlen($body),0);
        return $body.$central.$eocd;
    }
}
