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
        $started=microtime(true);$dir=$this->bundleDir();
        if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Support bundle directory is not writable.');
        $id='support-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
        $path=$dir.DIRECTORY_SEPARATOR.$id.'.json.gz';
        $tmp=$path.'.tmp-'.bin2hex(random_bytes(3));
        $bundleSnapshot=$snapshot;unset($bundleSnapshot['recent_logs']);
        $logStarted=microtime(true);$recentLogs=$this->recentLogs(self::MAX_LOG_LINES);$logElapsed=(int)round((microtime(true)-$logStarted)*1000);
        $payload=[
            'format'=>self::FORMAT,
            'generated_at'=>gmdate('c'),
            'notice'=>'Operational diagnostics only. Secrets and credentials are intentionally excluded/redacted.',
            'snapshot'=>$this->observability->redact($bundleSnapshot),
            'known_state'=>$this->knownState(),
            'local_source_identity'=>$this->localSourceIdentity(),
            'recent_logs'=>$recentLogs,
            'bundle_profile'=>['log_tail_elapsed_ms'=>$logElapsed,'log_rows'=>count($recentLogs)],
        ];
        $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
        $gz=gzencode($json,6,ZLIB_ENCODING_GZIP);if($gz===false)throw new RuntimeException('Support bundle compression failed.');
        if(@file_put_contents($tmp,$gz,LOCK_EX)===false||!@rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('Support bundle could not be written.');}
        @chmod($path,0600);clearstatcache(true,$path);$size=(int)(@filesize($path)?:0);if($size<=0){@unlink($path);throw new RuntimeException('Support bundle file is empty.');}$sha=hash_file('sha256',$path);if(!is_string($sha)||$sha==='')throw new RuntimeException('Support bundle hash failed.');$this->prune($path);
        $elapsed=(int)round((microtime(true)-$started)*1000);
        $this->observability->logEvent('info','system.support_bundle_created',['bundle_id'=>$id,'size_bytes'=>$size,'generation_ms'=>$elapsed,'log_tail_ms'=>$logElapsed]);
        return ['id'=>$id,'file_name'=>basename($path),'size_bytes'=>$size,'sha256'=>$sha,'generation_ms'=>$elapsed,'download'=>'/system/download.php?id='.rawurlencode($id)];
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
            $need=$limit-count($out);if($need<=0)break;
            foreach($this->tailJsonRows($file,$need) as $row){$row['log_file']=basename($file);$out[]=$row;if(count($out)>=$limit)break 2;}
        }
        return $out;
    }

    /** Read newest valid JSONL records without scanning the complete log file. */
    private function tailJsonRows(string $file,int $limit): array
    {
        $limit=max(1,min(self::MAX_LOG_LINES,$limit));$fh=@fopen($file,'rb');if($fh===false)return [];
        $rows=[];$buffer='';
        try{
            if(fseek($fh,0,SEEK_END)!==0)return [];$pos=ftell($fh);if(!is_int($pos)||$pos<0)return [];
            while($pos>0&&count($rows)<$limit){
                $read=min(65536,$pos);$pos-=$read;if(fseek($fh,$pos,SEEK_SET)!==0)break;$chunk=fread($fh,$read);if(!is_string($chunk)||$chunk==='')break;
                $buffer=$chunk.$buffer;$parts=explode("\n",$buffer);$buffer=(string)array_shift($parts);
                for($i=count($parts)-1;$i>=0&&count($rows)<$limit;$i--){
                    $line=trim((string)$parts[$i]);if($line===''||strlen($line)>16384)continue;
                    try{$row=json_decode($line,true,32,JSON_THROW_ON_ERROR);}catch(Throwable){continue;}
                    if(is_array($row))$rows[]=$this->observability->redact($row);
                }
            }
            if($pos===0&&count($rows)<$limit){$line=trim($buffer);if($line!==''&&strlen($line)<=16384){try{$row=json_decode($line,true,32,JSON_THROW_ON_ERROR);if(is_array($row))$rows[]=$this->observability->redact($row);}catch(Throwable){}}}
        }finally{fclose($fh);}
        return $rows;
    }


    private function knownState(): array
    {
        $out=[];$errors=[];
        $targets=[
            'local_update_state'=>$this->observability->dataRoot().DIRECTORY_SEPARATOR.'lifecycle'.DIRECTORY_SEPARATOR.'local'.DIRECTORY_SEPARATOR.'state.json',
            'local_managed_files'=>$this->observability->dataRoot().DIRECTORY_SEPARATOR.'lifecycle'.DIRECTORY_SEPARATOR.'local'.DIRECTORY_SEPARATOR.'managed-files.json',
        ];
        foreach($targets as $key=>$path){
            try{
                $raw=@file_get_contents($path);if(!is_string($raw)||trim($raw)==='')continue;
                $decoded=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
                if(is_array($decoded))$out[$key]=$this->observability->redact($decoded);
            }catch(Throwable $e){$errors[]=['key'=>$key,'class'=>get_class($e),'fingerprint'=>substr(hash('sha256',get_class($e).'|'.$e->getMessage()),0,20)];}
        }
        if($errors!==[])$out['_errors']=$errors;
        return $out;
    }


    /**
     * Installed Local truth is ALWAYS captured by the ordinary support export,
     * never a one-off host probe. No file bodies, credentials or machine paths.
     */
    private function localSourceIdentity(): array
    {
        $root=dirname(__DIR__,3);
        $source=$root.'/src/Domain/Update/LocalUpdateService.php';
        $versionFile=$root.'/VERSION.txt';
        $sha=is_file($source)&&is_readable($source)?@hash_file('sha256',$source):false;
        $valid=is_string($sha)&&preg_match('/^[a-f0-9]{64}$/D',$sha)===1;
        $version='unknown';
        if(is_file($versionFile)&&is_readable($versionFile)){
            $raw=@file_get_contents($versionFile);
            if(is_string($raw)&&strlen($raw)<=160){
                $candidate=trim($raw);
                if(preg_match('/^[A-Za-z0-9_.+\-]{1,128}$/D',$candidate)===1)$version=$candidate;
            }
        }
        $embeddedVersion='unknown';
        $release=$root.'/resources/release-identity-v1.json';
        if(is_file($release)&&is_readable($release)){
            $size=@filesize($release);
            if(is_int($size)&&$size>0&&$size<=65536){
                try{
                    $data=json_decode((string)file_get_contents($release),true,32,JSON_THROW_ON_ERROR);
                    if(is_array($data)){
                        $candidate=$data['version']??null;
                        if(is_string($candidate)&&preg_match('/^[A-Za-z0-9_.+\-]{1,128}$/D',$candidate)===1)
                            $embeddedVersion=$candidate;
                    }
                }catch(Throwable){}
            }
        }
        $coherence=$version==='unknown'||$embeddedVersion==='unknown'?'unknown':
            ($version===$embeddedVersion?'match':'mismatch');
        return [
            'format'=>'sokna-local-updater-source-fingerprint-v1',
            'status'=>$valid?'verified':'unknown',
            'path'=>'src/Domain/Update/LocalUpdateService.php',
            'sha256'=>$valid?$sha:null,
            'version'=>$version,
            'embedded_release_version'=>$embeddedVersion,
            'version_coherence'=>$coherence,
            'file_contents_collected'=>false,
            'machine_identity_collected'=>false
        ];
    }

    private function bundleDir(): string{return $this->observability->dataRoot().DIRECTORY_SEPARATOR.'support-bundles';}

    private function prune(string $keepPath): void
    {
        $files=glob($this->bundleDir().DIRECTORY_SEPARATOR.'support-*.json.gz')?:[];
        usort($files,static function(string $a,string $b) use($keepPath): int {if($a===$keepPath)return -1;if($b===$keepPath)return 1;$am=filemtime($a)?:0;$bm=filemtime($b)?:0;return $bm<=>$am ?: strcmp($b,$a);});
        foreach(array_slice($files,self::MAX_BUNDLES) as $old)if($old!==$keepPath)@unlink($old);
    }
}
