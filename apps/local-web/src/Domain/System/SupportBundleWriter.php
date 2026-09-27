<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\System;

use RuntimeException;
use Sokna\Local\Core\Observability;
use Throwable;

final class SupportBundleWriter
{
    private const FORMAT='sokna-support-bundle-v1';
    private const MAX_BUNDLES=5;
    private const MAX_LOG_LINES=200;

    public function __construct(private readonly Observability $observability) {}

    public function create(array $snapshot): array
    {
        $dir=$this->bundleDir();
        if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Support bundle directory is not writable.');
        $id='support-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
        $path=$dir.DIRECTORY_SEPARATOR.$id.'.json.gz';
        $tmp=$path.'.tmp-'.bin2hex(random_bytes(3));
        $bundleSnapshot=$snapshot;unset($bundleSnapshot['recent_logs']);
        $payload=[
            'format'=>self::FORMAT,
            'generated_at'=>gmdate('c'),
            'notice'=>'Operational diagnostics only. Secrets and credentials are intentionally excluded/redacted.',
            'snapshot'=>$this->observability->redact($bundleSnapshot),
            'recent_logs'=>$this->recentLogs(self::MAX_LOG_LINES),
        ];
        $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
        $gz=gzencode($json,6,ZLIB_ENCODING_GZIP);if($gz===false)throw new RuntimeException('Support bundle compression failed.');
        if(@file_put_contents($tmp,$gz,LOCK_EX)===false||!@rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('Support bundle could not be written.');}
        @chmod($path,0600);$size=(int)(filesize($path)?:0);$sha=hash_file('sha256',$path);if(!is_string($sha)||$sha==='')throw new RuntimeException('Support bundle hash failed.');$this->prune($path);
        $this->observability->logEvent('info','system.support_bundle_created',['bundle_id'=>$id,'size_bytes'=>$size]);
        return ['id'=>$id,'file_name'=>basename($path),'size_bytes'=>$size,'sha256'=>$sha,'download'=>'/system/download.php?id='.rawurlencode($id)];
    }

    public function resolve(string $id): string
    {
        if(preg_match('/^support-\d{8}-\d{6}-[a-f0-9]{8}$/D',$id)!==1)throw new RuntimeException('Invalid support bundle identifier.');
        $path=$this->bundleDir().DIRECTORY_SEPARATOR.$id.'.json.gz';
        if(!is_file($path)||!is_readable($path))throw new RuntimeException('Support bundle not found.');
        return $path;
    }

    /** @return list<array<string,mixed>> */
    public function recentLogs(int $limit=80): array
    {
        $limit=max(1,min(self::MAX_LOG_LINES,$limit));$files=glob($this->observability->logDir().DIRECTORY_SEPARATOR.'sokna-*.jsonl')?:[];
        rsort($files,SORT_STRING);$out=[];
        foreach(array_slice($files,0,3) as $file){
            $fh=@fopen($file,'rb');if($fh===false)continue;$ring=[];
            try{
                while(($line=fgets($fh,16385))!==false){
                    if(!str_ends_with($line,"\n")&&!feof($fh)){while(($rest=fgets($fh,16385))!==false&&!str_ends_with($rest,"\n")){}continue;}
                    if(strlen($line)>16384)continue;
                    try{$row=json_decode(trim($line),true,32,JSON_THROW_ON_ERROR);}catch(Throwable){continue;}
                    if(!is_array($row))continue;$ring[]=$this->observability->redact($row);if(count($ring)>$limit)array_shift($ring);
                }
            }finally{fclose($fh);}
            for($i=count($ring)-1;$i>=0&&count($out)<$limit;$i--){$row=$ring[$i];if(is_array($row)){$row['log_file']=basename($file);$out[]=$row;}}
            if(count($out)>=$limit)break;
        }
        return $out;
    }

    private function bundleDir(): string{return $this->observability->dataRoot().DIRECTORY_SEPARATOR.'support-bundles';}

    private function prune(string $keepPath): void
    {
        $files=glob($this->bundleDir().DIRECTORY_SEPARATOR.'support-*.json.gz')?:[];
        usort($files,static function(string $a,string $b) use($keepPath): int {if($a===$keepPath)return -1;if($b===$keepPath)return 1;$am=filemtime($a)?:0;$bm=filemtime($b)?:0;return $bm<=>$am ?: strcmp($b,$a);});
        foreach(array_slice($files,self::MAX_BUNDLES) as $old)if($old!==$keepPath)@unlink($old);
    }
}
