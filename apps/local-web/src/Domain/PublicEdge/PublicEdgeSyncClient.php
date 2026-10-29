<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\PublicEdge;

use Sokna\Local\Core\Config;
use Sokna\Local\Core\Observability;
use Throwable;

final class PublicEdgeSyncClient
{
    /** @var null|callable */
    private $transport;

    public function __construct(private readonly Config $config, ?callable $transport = null, private readonly ?Observability $observability = null, private readonly ?PublicAuthorityStateService $authorityState = null)
    {
        $this->transport = $transport;
    }

    public function configured(): bool
    {
        return $this->baseUrl() !== '' && $this->secret() !== '' && $this->installationId() !== '';
    }

    /**
     * Canonicalize a Public Edge base URL.
     *
     * Subfolder installs are supported, but credentials/query/fragment/path traversal are not.
     * Remote HTTP is rejected; loopback HTTP remains available for local qualification.
     */
    public function canonicalBaseUrl(string $url): string
    {
        return $this->normalizeBase($url);
    }

    public function publicBaseUrl(): string
    {
        return $this->baseUrl();
    }

    public function safeOrigin(): string
    {
        $url = $this->baseUrl();
        if ($url === '') return '';
        $parts = parse_url($url);
        if (!is_array($parts)) return '';
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = trim(strtolower((string)($parts['host'] ?? '')), '[]');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') return '';
        return $scheme . '://' . $this->authorityHost($host) . (isset($parts['port']) ? ':' . (int)$parts['port'] : '');
    }

    public function post(string $path, array $payload, int $timeoutSeconds = 10, ?int $deadlineEpochMs = null): array
    {
        $response = $this->postRaw($path, $payload, $timeoutSeconds, $deadlineEpochMs);
        $status = (int)$response['status'];
        $body = $response['body'];
        if ($status < 200 || $status >= 300 || ($body['ok'] ?? false) !== true) {
            $code=(string)($body['error'] ?? 'public_sync_failed');
            throw new PublicEdgeSyncException(
                $code,
                'همگام‌سازی Public Edge پذیرفته نشد.',
                $status ?: 502,
                '',
                (string)($response['failure_class']??$this->classifyFailure($status,$code)),
                (int)($response['retry_after_seconds']??0),
            );
        }
        return $response;
    }

    /** @return array{status:int,body:array} Signed POST that preserves non-2xx application responses for queue polling. */
    public function postRaw(string $path, array $payload, int $timeoutSeconds = 10, ?int $deadlineEpochMs = null, int $maxResponseBytes = 2097152): array
    {
        $timeoutSeconds = max(1, min(30, $timeoutSeconds));
        $maxResponseBytes=max(65536,min(4194304,$maxResponseBytes));
        if (!$this->configured()) {
            throw new PublicEdgeSyncException('public_not_configured', 'Public Edge هنوز در config محلی pair نشده است.', 409);
        }
        if (!preg_match('#^/api/v1/local/[A-Za-z0-9/_-]+$#D', $path)) {
            throw new PublicEdgeSyncException('public_path_invalid', 'مسیر همگام‌سازی Public معتبر نیست.');
        }
        $timeoutMilliseconds=$this->boundedTimeoutMilliseconds($timeoutSeconds,$deadlineEpochMs);
        $this->assertAuthorityPathAllowed($path);
        if($this->authorityState!==null)$payload['authority_epoch']=$this->authorityState->epoch();

        // Preserve fractional number types used by the canonical projection hash.
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        if(strlen($body)>2097152)throw new PublicEdgeSyncException('public_payload_too_large','Payload همگام‌سازی از سقف امن بزرگ‌تر است.',413,'request_payload_limit');
        $timestamp = (string)time();
        $nonce = bin2hex(random_bytes(16));
        $signature = hash_hmac('sha256', $this->signatureBase('POST', $path, $timestamp, $nonce, $body), $this->secret());
        $traceId=$this->singleTraceId($payload);
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Sokna-Installation' => $this->installationId(),
            'X-Sokna-Timestamp' => $timestamp,
            'X-Sokna-Nonce' => $nonce,
            'X-Sokna-Signature' => $signature,
            'X-Correlation-ID' => $traceId!==''?$traceId:('g41-' . bin2hex(random_bytes(8))),
        ];
        if($traceId!=='')$headers['X-Sokna-Trace-ID']=$traceId;

        if (is_callable($this->transport)) {
            $response = ($this->transport)($this->baseUrl() . $path, 'POST', $headers, $body);
            return $this->normalizeRawResponse($this->enforceSignedResponsePolicy($response, $maxResponseBytes));
        }

        $response=$this->signedHttpRequest(
            $this->baseUrl() . $path,
            $headers,
            $body,
            $timeoutMilliseconds,
            $maxResponseBytes,
        );
        return $this->normalizeRawResponse($response);
    }

    /**
     * Native transport profile for signed Local -> Public requests.
     *
     * Redirects are never followed. Connect establishment has its own bounded budget,
     * while connect + write + read are fenced by one total deadline. TLS peer/name
     * verification is mandatory and response headers/body are bounded.
     *
     * @param array<string,string> $headers
     * @return array{status:int,body:string,headers:array<int,string>}
     */
    private function signedHttpRequest(string $url,array $headers,string $body,int $totalTimeoutMs,int $maxResponseBytes): array
    {
        $parts=parse_url($url);
        if(!is_array($parts))throw new PublicEdgeSyncException('public_url_invalid','نشانی Public معتبر نیست.',422,'transport_url_invalid','compatibility');
        $scheme=strtolower((string)($parts['scheme']??''));
        $host=trim((string)($parts['host']??''),'[]');
        if($host===''||!in_array($scheme,['http','https'],true))throw new PublicEdgeSyncException('public_url_invalid','نشانی Public معتبر نیست.',422,'transport_url_invalid','compatibility');
        $port=(int)($parts['port']??($scheme==='https'?443:80));
        if($port<1||$port>65535)throw new PublicEdgeSyncException('public_url_invalid','درگاه Public معتبر نیست.',422,'transport_port_invalid','compatibility');
        $target=(string)($parts['path']??'/');if($target==='')$target='/';
        if(isset($parts['query'])&&$parts['query']!=='')$target.='?'.$parts['query'];
        $totalTimeoutMs=max(50,$totalTimeoutMs);
        $connectTimeoutMs=$this->connectTimeoutMilliseconds($totalTimeoutMs);
        $started=microtime(true);
        $timing=[
            'profile'=>'streams-http11-close-v1',
            'scheme'=>$scheme,
            'host_hash'=>substr(hash('sha256',strtolower($host)),0,16),
            'port'=>$port,
            'path'=>$target,
            'timeout_ms'=>$totalTimeoutMs,
            'connection_reused'=>false,
            'dns_probe_ms'=>$this->dnsProbeMilliseconds($host),
            'connect_tls_ms'=>null,
            'write_ms'=>null,
            'ttfb_ms'=>null,
            'body_ms'=>null,
            'total_ms'=>null,
            'status'=>0,
            'response_bytes'=>0,
            'outcome'=>'started',
        ];
        $contextOptions=[];
        if($scheme==='https'){
            $contextOptions['ssl']=[
                'verify_peer'=>true,
                'verify_peer_name'=>true,
                'allow_self_signed'=>false,
                'peer_name'=>$host,
                'SNI_enabled'=>true,
                'disable_compression'=>true,
            ];
        }
        $context=stream_context_create($contextOptions);
        $remote=($scheme==='https'?'tls':'tcp').'://'.$this->authorityHost($host).':'.$port;
        error_clear_last();$errno=0;$errstr='';
        $connectStarted=microtime(true);
        $socket=@stream_socket_client($remote,$errno,$errstr,max(0.05,$connectTimeoutMs/1000),STREAM_CLIENT_CONNECT,$context);
        $timing['connect_tls_ms']=(int)round((microtime(true)-$connectStarted)*1000);
        if(!is_resource($socket)){
            $elapsedMs=(int)round((microtime(true)-$started)*1000);
            $detail=$this->transportFailureDetail($elapsedMs,max(1,(int)ceil($connectTimeoutMs/1000)),$errstr);
            if($detail==='transport_timeout'&&$elapsedMs>=max(40,(int)floor($connectTimeoutMs*0.8)))$detail='connect_timeout';
            $timing['total_ms']=$elapsedMs;$timing['outcome']=$detail;$this->recordWanTiming($timing);
            throw new PublicEdgeSyncException('public_unreachable','ارتباط امن با Public Edge برقرار نشد.',503,$detail,'transport');
        }
        stream_set_blocking($socket,true);
        $peer=@stream_socket_get_name($socket,true);
        if(is_string($peer)&&$peer!=='')$timing['peer_endpoint']=$peer;
        try{
            $hostHeader=$this->authorityHost($host);
            if(!(($scheme==='https'&&$port===443)||($scheme==='http'&&$port===80)))$hostHeader.=':'.$port;
            $wireHeaders=['Host'=>$hostHeader,'Connection'=>'close','Content-Length'=>(string)strlen($body),'Accept-Encoding'=>'identity'];
            foreach($headers as $key=>$value){
                $key=trim((string)$key);$value=(string)$value;
                if($key===''||preg_match('/[\r\n:]/',$key)||preg_match('/[\r\n]/',$value))throw new PublicEdgeSyncException('public_header_invalid','هدر درخواست Public معتبر نیست.',500,'signed_header_invalid','compatibility');
                $wireHeaders[$key]=$value;
            }
            $head='POST '.$target." HTTP/1.1\r\n";
            foreach($wireHeaders as $key=>$value)$head.=$key.': '.$value."\r\n";
            $request=$head."\r\n".$body;
            $writeStarted=microtime(true);
            $this->writeSocketAll($socket,$request,$started,$totalTimeoutMs);
            $timing['write_ms']=(int)round((microtime(true)-$writeStarted)*1000);
            $headStarted=microtime(true);
            [$status,$responseHeaders,$prefix]=$this->readResponseHead($socket,$started,$totalTimeoutMs);
            $timing['ttfb_ms']=(int)round((microtime(true)-$headStarted)*1000);
            $timing['status']=$status;
            if($status>=300&&$status<400){
                throw new PublicEdgeSyncException('public_redirect_rejected','Redirect برای درخواست امضاشده Public مجاز نیست.',502,'signed_redirect_rejected','compatibility');
            }
            $bodyStarted=microtime(true);
            $responseBody=$this->readResponseBody($socket,$responseHeaders,$prefix,$started,$totalTimeoutMs,$maxResponseBytes,$status);
            $timing['body_ms']=(int)round((microtime(true)-$bodyStarted)*1000);
            $timing['response_bytes']=strlen($responseBody);
            $timing['total_ms']=(int)round((microtime(true)-$started)*1000);
            $timing['outcome']='response';
            $this->recordWanTiming($timing);
            return ['status'=>$status,'body'=>$responseBody,'headers'=>$responseHeaders];
        }catch(Throwable $e){
            if(($timing['outcome']??'started')==='started'){
                $timing['total_ms']=(int)round((microtime(true)-$started)*1000);
                $timing['outcome']=$e instanceof PublicEdgeSyncException ? $e->detailCode : 'transport_exception';
                $this->recordWanTiming($timing);
            }
            throw $e;
        }finally{
            fclose($socket);
        }
    }


    private function dnsProbeMilliseconds(string $host): ?int
    {
        $plain=trim($host,'[]');
        if(filter_var($plain,FILTER_VALIDATE_IP)!==false)return 0;
        $started=microtime(true);
        try{
            @gethostbyname($plain);
            return (int)round((microtime(true)-$started)*1000);
        }catch(Throwable){
            return null;
        }
    }

    /** @param array<string,mixed> $timing */
    private function recordWanTiming(array $timing): void
    {
        if($this->observability===null)return;
        try{
            $this->observability->logEvent('info','public_edge.wan_exchange',$timing);
        }catch(Throwable){
            // Transport observability must never change business transport semantics.
        }
    }

    private function connectTimeoutMilliseconds(int $totalTimeoutMs): int
    {
        if($totalTimeoutMs<=100)return $totalTimeoutMs;
        return max(100,min($totalTimeoutMs,min(2500,max(500,(int)floor($totalTimeoutMs*0.5)))));
    }

    /** @param resource $socket */
    private function writeSocketAll($socket,string $bytes,float $started,int $totalTimeoutMs): void
    {
        $offset=0;$length=strlen($bytes);
        while($offset<$length){
            $this->applyRemainingSocketTimeout($socket,$started,$totalTimeoutMs);
            $written=@fwrite($socket,substr($bytes,$offset,min(65536,$length-$offset)));
            if(!is_int($written)||$written<=0)$this->throwSocketIoFailure($socket,$started,$totalTimeoutMs,'write');
            $offset+=$written;
        }
    }

    /** @param resource $socket @return array{0:int,1:array<int,string>,2:string} */
    private function readResponseHead($socket,float $started,int $totalTimeoutMs): array
    {
        $buffer='';$limit=65536;
        while(($pos=strpos($buffer,"\r\n\r\n"))===false){
            if(strlen($buffer)>$limit)throw new PublicEdgeSyncException('public_response_headers_too_large','هدر پاسخ Public از سقف امن بزرگ‌تر است.',502,'response_header_limit','compatibility');
            $this->applyRemainingSocketTimeout($socket,$started,$totalTimeoutMs);
            $chunk=@fread($socket,8192);
            if(!is_string($chunk)||$chunk==='')$this->throwSocketIoFailure($socket,$started,$totalTimeoutMs,'read_headers');
            $buffer.=$chunk;
        }
        if($pos>$limit)throw new PublicEdgeSyncException('public_response_headers_too_large','هدر پاسخ Public از سقف امن بزرگ‌تر است.',502,'response_header_limit','compatibility');
        $headerBlock=substr($buffer,0,$pos);$prefix=substr($buffer,$pos+4);
        $lines=explode("\r\n",$headerBlock);$statusLine=array_shift($lines);
        if(!is_string($statusLine)||preg_match('#^HTTP/1\.[01]\s+(\d{3})(?:\s|$)#D',$statusLine,$m)!==1)throw new PublicEdgeSyncException('public_http_response_invalid','پاسخ HTTP Public معتبر نیست.',502,'http_status_invalid','compatibility');
        $status=(int)$m[1];$responseHeaders=[];
        foreach($lines as $line){
            if($line==='')continue;
            if(str_starts_with($line,' ')||str_starts_with($line,"\t")||!str_contains($line,':'))throw new PublicEdgeSyncException('public_http_response_invalid','هدر پاسخ Public معتبر نیست.',502,'http_header_invalid','compatibility');
            $responseHeaders[]=$line;
        }
        return [$status,$responseHeaders,$prefix];
    }

    /** @param resource $socket @param array<int,string> $headers */
    private function readResponseBody($socket,array $headers,string $prefix,float $started,int $totalTimeoutMs,int $maxResponseBytes,int $status): string
    {
        if(($status>=100&&$status<200)||$status===204||$status===304)return '';
        $contentLength=null;$contentLengths=[];$transferCodings=[];
        foreach($headers as $line){
            if(preg_match('/^Content-Length:\s*(\d+)\s*$/iD',trim($line),$m)===1)$contentLengths[]=(int)$m[1];
            if(preg_match('/^Transfer-Encoding:\s*(.+)$/iD',trim($line),$m)===1){
                foreach(explode(',',$m[1]) as $coding){$coding=strtolower(trim($coding));if($coding!=='')$transferCodings[]=$coding;}
            }
        }
        if($contentLengths!==[]){
            if(count(array_unique($contentLengths,SORT_REGULAR))!==1)throw new PublicEdgeSyncException('public_http_response_invalid','Content-Length پاسخ Public ناسازگار است.',502,'http_framing_ambiguous','compatibility');
            $contentLength=$contentLengths[0];
        }
        $chunked=$transferCodings===['chunked'];
        if($transferCodings!==[]&&!$chunked)throw new PublicEdgeSyncException('public_http_response_invalid','Transfer-Encoding پاسخ Public پشتیبانی نمی‌شود.',502,'http_transfer_encoding_unsupported','compatibility');
        if($chunked&&$contentLength!==null)throw new PublicEdgeSyncException('public_http_response_invalid','Framing پاسخ Public مبهم است.',502,'http_framing_ambiguous','compatibility');
        if($chunked)return $this->readChunkedBody($socket,$prefix,$started,$totalTimeoutMs,$maxResponseBytes);
        if($contentLength!==null){
            if($contentLength>$maxResponseBytes)throw new PublicEdgeSyncException('public_response_too_large','پاسخ Public از سقف امن بزرگ‌تر است.',502,'response_payload_limit','compatibility');
            if(strlen($prefix)>$contentLength)$prefix=substr($prefix,0,$contentLength);
            return $this->readExactBody($socket,$prefix,$contentLength,$started,$totalTimeoutMs,$maxResponseBytes);
        }
        return $this->readBodyUntilEof($socket,$prefix,$started,$totalTimeoutMs,$maxResponseBytes);
    }

    /** @param resource $socket */
    private function readExactBody($socket,string $buffer,int $length,float $started,int $totalTimeoutMs,int $maxResponseBytes): string
    {
        if($length>$maxResponseBytes)throw new PublicEdgeSyncException('public_response_too_large','پاسخ Public از سقف امن بزرگ‌تر است.',502,'response_payload_limit','compatibility');
        while(strlen($buffer)<$length){
            $this->applyRemainingSocketTimeout($socket,$started,$totalTimeoutMs);
            $chunk=@fread($socket,min(8192,$length-strlen($buffer)));
            if(!is_string($chunk)||$chunk==='')$this->throwSocketIoFailure($socket,$started,$totalTimeoutMs,'read_body');
            $buffer.=$chunk;
        }
        return substr($buffer,0,$length);
    }

    /** @param resource $socket */
    private function readBodyUntilEof($socket,string $buffer,float $started,int $totalTimeoutMs,int $maxResponseBytes): string
    {
        if(strlen($buffer)>$maxResponseBytes)throw new PublicEdgeSyncException('public_response_too_large','پاسخ Public از سقف امن بزرگ‌تر است.',502,'response_payload_limit','compatibility');
        while(!feof($socket)){
            $this->applyRemainingSocketTimeout($socket,$started,$totalTimeoutMs);
            $chunk=@fread($socket,8192);
            if($chunk===false)$this->throwSocketIoFailure($socket,$started,$totalTimeoutMs,'read_body');
            if($chunk===''){
                $meta=stream_get_meta_data($socket);
                if(($meta['timed_out']??false)===true)$this->throwSocketIoFailure($socket,$started,$totalTimeoutMs,'read_body');
                if(feof($socket))break;
                continue;
            }
            $buffer.=$chunk;
            if(strlen($buffer)>$maxResponseBytes)throw new PublicEdgeSyncException('public_response_too_large','پاسخ Public از سقف امن بزرگ‌تر است.',502,'response_payload_limit','compatibility');
        }
        return $buffer;
    }

    /** @param resource $socket */
    private function readChunkedBody($socket,string $buffer,float $started,int $totalTimeoutMs,int $maxResponseBytes): string
    {
        $decoded='';$trailerBytes=0;
        while(true){
            $line=$this->readBufferedLine($socket,$buffer,$started,$totalTimeoutMs,8192);
            $semi=strpos($line,';');$hex=trim($semi===false?$line:substr($line,0,$semi));
            if($hex===''||preg_match('/^[0-9A-Fa-f]+$/D',$hex)!==1||strlen($hex)>16)throw new PublicEdgeSyncException('public_http_response_invalid','Chunked response Public معتبر نیست.',502,'chunk_size_invalid','compatibility');
            $size=(int)hexdec($hex);
            if($size===0){
                while(true){
                    $trailer=$this->readBufferedLine($socket,$buffer,$started,$totalTimeoutMs,8192);$trailerBytes+=strlen($trailer)+2;
                    if($trailerBytes>65536)throw new PublicEdgeSyncException('public_response_headers_too_large','Trailer پاسخ Public از سقف امن بزرگ‌تر است.',502,'response_trailer_limit','compatibility');
                    if($trailer==='')return $decoded;
                    if(!str_contains($trailer,':'))throw new PublicEdgeSyncException('public_http_response_invalid','Trailer پاسخ Public معتبر نیست.',502,'http_trailer_invalid','compatibility');
                }
            }
            if(strlen($decoded)+$size>$maxResponseBytes)throw new PublicEdgeSyncException('public_response_too_large','پاسخ Public از سقف امن بزرگ‌تر است.',502,'response_payload_limit','compatibility');
            $chunk=$this->readBufferedBytes($socket,$buffer,$size,$started,$totalTimeoutMs);
            $ending=$this->readBufferedBytes($socket,$buffer,2,$started,$totalTimeoutMs);
            if($ending!=="\r\n")throw new PublicEdgeSyncException('public_http_response_invalid','Chunked response Public معتبر نیست.',502,'chunk_terminator_invalid','compatibility');
            $decoded.=$chunk;
        }
    }

    /** @param resource $socket */
    private function readBufferedLine($socket,string &$buffer,float $started,int $totalTimeoutMs,int $lineLimit): string
    {
        while(($pos=strpos($buffer,"\r\n"))===false){
            if(strlen($buffer)>$lineLimit)throw new PublicEdgeSyncException('public_http_response_invalid','خط پاسخ HTTP Public بیش از حد مجاز است.',502,'http_line_limit','compatibility');
            $this->applyRemainingSocketTimeout($socket,$started,$totalTimeoutMs);
            $chunk=@fread($socket,8192);
            if(!is_string($chunk)||$chunk==='')$this->throwSocketIoFailure($socket,$started,$totalTimeoutMs,'read_body');
            $buffer.=$chunk;
        }
        if($pos>$lineLimit)throw new PublicEdgeSyncException('public_http_response_invalid','خط پاسخ HTTP Public بیش از حد مجاز است.',502,'http_line_limit','compatibility');
        $line=substr($buffer,0,$pos);$buffer=substr($buffer,$pos+2);return $line;
    }

    /** @param resource $socket */
    private function readBufferedBytes($socket,string &$buffer,int $length,float $started,int $totalTimeoutMs): string
    {
        while(strlen($buffer)<$length){
            $this->applyRemainingSocketTimeout($socket,$started,$totalTimeoutMs);
            $chunk=@fread($socket,min(8192,max(1,$length-strlen($buffer))));
            if(!is_string($chunk)||$chunk==='')$this->throwSocketIoFailure($socket,$started,$totalTimeoutMs,'read_body');
            $buffer.=$chunk;
        }
        $out=substr($buffer,0,$length);$buffer=substr($buffer,$length);return $out;
    }

    /** @param resource $socket */
    private function applyRemainingSocketTimeout($socket,float $started,int $totalTimeoutMs): int
    {
        $elapsed=(int)floor((microtime(true)-$started)*1000);$remaining=$totalTimeoutMs-$elapsed;
        if($remaining<=0)throw new PublicEdgeSyncException('public_transport_timeout','بودجه زمانی ارتباط با Public Edge تمام شد.',504,'transport_timeout','transport');
        stream_set_timeout($socket,intdiv($remaining,1000),($remaining%1000)*1000);
        return $remaining;
    }

    /** @param resource $socket */
    private function throwSocketIoFailure($socket,float $started,int $totalTimeoutMs,string $phase): never
    {
        $meta=stream_get_meta_data($socket);$elapsed=(int)round((microtime(true)-$started)*1000);
        if(($meta['timed_out']??false)===true||$elapsed>=$totalTimeoutMs)throw new PublicEdgeSyncException('public_transport_timeout','بودجه زمانی ارتباط با Public Edge تمام شد.',504,'transport_timeout','transport');
        throw new PublicEdgeSyncException('public_unreachable','ارتباط با Public Edge ناقص ماند.',503,'transport_'.$phase.'_failed','transport');
    }

    private function enforceSignedResponsePolicy(array $response,int $maxResponseBytes): array
    {
        $status=(int)($response['status']??0);$body=$response['body']??'';
        if($status>=300&&$status<400)throw new PublicEdgeSyncException('public_redirect_rejected','Redirect برای درخواست امضاشده Public مجاز نیست.',502,'signed_redirect_rejected','compatibility');
        if(is_string($body)&&strlen($body)>$maxResponseBytes)throw new PublicEdgeSyncException('public_response_too_large','پاسخ Public از سقف امن بزرگ‌تر است.',502,'response_payload_limit','compatibility');
        return $response;
    }

    private function singleTraceId(array $payload): string
    {
        $ids=[];
        $push=static function(mixed $v) use (&$ids):void{
            $v=trim((string)$v);if(preg_match('/^skt1-[a-f0-9]{32}$/D',$v)===1)$ids[$v]=true;
        };
        $push($payload['trace_id']??'');
        foreach((array)($payload['acks']??[]) as $ack){
            if(!is_array($ack))continue;$result=is_array($ack['result']??null)?$ack['result']:[];
            $trace=is_array($result['_transport_trace']??null)?$result['_transport_trace']:[];
            $push($trace['trace_id']??'');
        }
        return count($ids)===1?(string)array_key_first($ids):'';
    }

    private function boundedTimeoutMilliseconds(int $timeoutSeconds,?int $deadlineEpochMs): int
    {
        $configured=max(1,min(30,$timeoutSeconds))*1000;
        if($deadlineEpochMs===null||$deadlineEpochMs<=0)return $configured;
        $remaining=$deadlineEpochMs-(int)floor(microtime(true)*1000);
        if($remaining<=0)throw new PublicEdgeSyncException('public_deadline_exceeded','بودجه زمانی همگام‌سازی Public تمام شده است.',504,'deadline_exhausted','deadline');
        // Keep a small positive timeout for the stream layer while never granting a child
        // network call more time than remains in the parent trigger budget.
        return max(50,min($configured,$remaining));
    }

    public function diagnostics(int $timeoutSeconds=10): array
    {
        return $this->post('/api/v1/local/diagnostics', [], $timeoutSeconds);
    }

    public function health(int $timeoutSeconds=5): array
    {
        $base = $this->baseUrl();
        if ($base === '') {
            throw new PublicEdgeSyncException('public_not_configured', 'Public Edge هنوز پیکربندی نشده است.', 409);
        }
        if (is_callable($this->transport)) {
            $response = ($this->transport)($base . '/health', 'GET', ['Accept' => 'application/json'], '');
            return $this->normalizeResponse($response);
        }
        $context = stream_context_create([
            'http' => ['method' => 'GET', 'header' => "Accept: application/json\r\n", 'timeout' => max(1,min(30,$timeoutSeconds)), 'ignore_errors' => true],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        error_clear_last();
        $started = microtime(true);
        $raw = @file_get_contents($base . '/health', false, $context);
        $elapsedMs = (int)round((microtime(true) - $started) * 1000);
        $transportDetail = $this->transportFailureDetail($elapsedMs, max(1,min(30,$timeoutSeconds)));
        $status = $this->httpStatus($http_response_header ?? []);
        if ($raw === false && $status === 0) {
            throw new PublicEdgeSyncException('public_unreachable', 'ارتباط با Public Edge برقرار نشد.', 503, $transportDetail, 'transport');
        }
        return $this->normalizeResponse(['status' => $status, 'body' => (string)$raw, 'headers'=>$http_response_header ?? []]);
    }

    public function initialPair(string $baseUrl, string $code, string $newSecret, string $displayName = ''): array
    {
        $base = $this->requireBase($baseUrl, 'نشانی وب عمومی معتبر نیست.');
        $payload = [
            'installation_id' => $this->installationId(),
            'pairing_code' => $code,
            'shared_secret' => $newSecret,
            'display_name' => $displayName,
        ];
        return $this->unsignedPost($base . '/setup/api.php?action=pair', $payload, 'ارتباط با وب عمومی برقرار نشد.');
    }

    public function reenroll(string $baseUrl, string $code, string $newSecret, string $displayName = ''): array
    {
        $base = $this->requireBase($baseUrl, 'نشانی Public معتبر نیست.');
        $payload = [
            'new_installation_id' => $this->installationId(),
            'enrollment_code' => $code,
            'new_shared_secret' => $newSecret,
            'display_name' => $displayName,
        ];
        return $this->unsignedPost($base . '/emergency.php?action=reenroll', $payload, 'ارتباط با Public Edge برقرار نشد.');
    }

    public function reenrollAuthority(string $baseUrl,string $code,string $newSecret,string $displayName=''): array
    {
        $base=$this->requireBase($baseUrl,'نشانی Public معتبر نیست.');
        return $this->unsignedPost($base.'/authority-recovery.php?action=reenroll',['new_installation_id'=>$this->installationId(),'enrollment_code'=>$code,
            'new_shared_secret'=>$newSecret,'display_name'=>$displayName,
        ],'ارتباط با Public Edge برقرار نشد.');
    }

    public function fenceAuthorityAfterRestore(int $timeoutSeconds=5): array
    {
        $epoch=$this->authorityState?->epoch()??1;
        return $this->postRaw('/api/v1/local/authority/fence',['authority_epoch'=>$epoch],$timeoutSeconds);
    }

    public function activateAuthority(int $timeoutSeconds=10): array
    {
        $epoch=$this->authorityState?->epoch()??1;
        return $this->post('/api/v1/local/authority/activate',['authority_epoch'=>$epoch],$timeoutSeconds);
    }

    private function assertAuthorityPathAllowed(string $path): void
    {
        if($this->authorityState===null)return;
        $phase=$this->authorityState->phase();
        if($phase==='active')return;
        if($phase==='reenroll_required'&&$path!=='/api/v1/local/authority/fence')throw new PublicEdgeSyncException('public_authority_reenrollment_required','پس از بازیابی، اتصال Public باید دوباره ثبت شود.',409);
        $safe=[
            '/api/v1/local/installation','/api/v1/local/auth-projections','/api/v1/local/guest/',
            '/api/v1/local/read-model','/api/v1/local/read-models','/api/v1/local/heartbeat','/api/v1/local/authority/activate'
        ];
        foreach($safe as $prefix)if(str_starts_with($path,$prefix))return;
        throw new PublicEdgeSyncException('public_authority_resync_required','تا پایان همگام‌سازی بازیابی، دریافت فرمان Public مسدود است.',409);
    }

    public function installationId(): string
    {
        return trim($this->config->string('installation.id', ''));
    }

    /** Signed request to the base-path-independent deployment binding endpoint. */
    public function deploymentBindingRequest(string $baseUrl,array $payload,int $timeoutSeconds=8): array
    {
        if(!$this->configured())throw new PublicEdgeSyncException('public_not_configured','Public Edge هنوز در config محلی pair نشده است.',409);
        $base=$this->requireBase($baseUrl,'نشانی Public برای rebind معتبر نیست.');$path='/deployment-binding.php';
        $timeoutSeconds=max(1,min(20,$timeoutSeconds));$this->assertAuthorityPathAllowed($path);
        if($this->authorityState!==null)$payload['authority_epoch']=$this->authorityState->epoch();
        $body=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$timestamp=(string)time();$nonce=bin2hex(random_bytes(16));
        $headers=['Content-Type'=>'application/json','Accept'=>'application/json','X-Sokna-Installation'=>$this->installationId(),'X-Sokna-Timestamp'=>$timestamp,'X-Sokna-Nonce'=>$nonce,'X-Sokna-Signature'=>hash_hmac('sha256',$this->signatureBase('POST',$path,$timestamp,$nonce,$body),$this->secret()),'X-Correlation-ID'=>'rebind-'.bin2hex(random_bytes(8))];
        if(is_callable($this->transport))$response=($this->transport)($base.$path,'POST',$headers,$body);else $response=$this->signedHttpRequest($base.$path,$headers,$body,$timeoutSeconds*1000,262144);
        return $this->normalizeResponse($response);
    }

    public function deploymentBindingProbe(string $baseUrl,int $timeoutSeconds=8): array
    {
        $base=$this->requireBase($baseUrl,'نشانی Public برای probe معتبر نیست.');$nonce=rtrim(strtr(base64_encode(random_bytes(24)),'+/','-_'),'=');
        $response=$this->deploymentBindingRequest($base,['action'=>'probe','probe_nonce'=>$nonce,'probe_base_url'=>$base],$timeoutSeconds);$body=(array)$response['body'];
        $instance=trim((string)($body['public_instance_id']??''));$installation=trim((string)($body['installation_id']??''));$proof=strtolower(trim((string)($body['proof']??'')));
        if($instance===''||!hash_equals($this->installationId(),$installation)||preg_match('/^[a-f0-9]{64}$/D',$proof)!==1)throw new PublicEdgeSyncException('deployment_probe_invalid','پاسخ شناسایی Public معتبر نیست.',502);
        $expected=hash_hmac('sha256',implode("\n",['sokna-deployment-probe-v1',$nonce,$instance,$installation,$base]),$this->secret());
        if(!hash_equals($expected,$proof))throw new PublicEdgeSyncException('deployment_probe_proof_invalid','نشانی مقصد هویت cryptographic نصب Public را اثبات نکرد.',409);
        return $body;
    }

    public function deploymentTargetHealth(string $baseUrl,int $timeoutSeconds=8): array
    {
        $base=$this->requireBase($baseUrl,'نشانی Public برای health معتبر نیست.');$url=$base.'/health';
        if(is_callable($this->transport)){$response=($this->transport)($url,'GET',['Accept'=>'application/json'],'');$r=$this->normalizeRawResponse($response);}else{
            $ctx=stream_context_create(['http'=>['method'=>'GET','header'=>"Accept: application/json\r\nConnection: close\r\n",'timeout'=>max(1,min(20,$timeoutSeconds)),'ignore_errors'=>true,'follow_location'=>0,'max_redirects'=>0],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false]]);$raw=@file_get_contents($url,false,$ctx);$status=$this->httpStatus($http_response_header??[]);$r=$this->normalizeRawResponse(['status'=>$status,'body'=>is_string($raw)?$raw:'','headers'=>$http_response_header??[]]);
        }
        $body=(array)($r['body']??[]);if((int)($r['status']??0)!==200||($body['ok']??false)!==true||($body['component']??'')!=='public-edge')throw new PublicEdgeSyncException('deployment_target_health_failed','Health مسیر جدید Public تأیید نشد.',502);
        return $body;
    }

    /** Verify browser-facing Staff login + manifest after Public commit, before Local config commit. */
    public function deploymentTargetStaffSurface(string $baseUrl,string $expectedManifestId='',int $timeoutSeconds=6): array
    {
        $base=$this->requireBase($baseUrl,'نشانی Public برای Staff surface معتبر نیست.');$timeoutSeconds=max(1,min(15,$timeoutSeconds));
        $fetch=function(string $url,string $accept)use($timeoutSeconds):array{
            if(is_callable($this->transport)){$response=($this->transport)($url,'GET',['Accept'=>$accept],'');$status=(int)($response['status']??0);$body=$response['body']??'';if(is_array($body))$body=json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return ['status'=>$status,'body'=>is_string($body)?$body:''];}
            $ctx=stream_context_create(['http'=>['method'=>'GET','header'=>'Accept: '.$accept."\r\nConnection: close\r\n",'timeout'=>$timeoutSeconds,'ignore_errors'=>true,'follow_location'=>0,'max_redirects'=>0],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false]]);$raw=@file_get_contents($url,false,$ctx);return ['status'=>$this->httpStatus($http_response_header??[]),'body'=>is_string($raw)?$raw:''];
        };
        $login=$fetch($base.'/staff/login','text/html');if((int)$login['status']!==200||!str_contains((string)$login['body'],'<!doctype html'))throw new PublicEdgeSyncException('deployment_target_staff_login_failed','صفحه ورود Staff روی آدرس جدید تأیید نشد.',502);
        $manifest=$fetch($base.'/staff/manifest.webmanifest','application/manifest+json');$json=json_decode((string)$manifest['body'],true);if((int)$manifest['status']!==200||!is_array($json))throw new PublicEdgeSyncException('deployment_target_staff_manifest_failed','Manifest Staff روی آدرس جدید تأیید نشد.',502);
        $id=trim((string)($json['id']??''));if($expectedManifestId!==''&&!hash_equals($expectedManifestId,$id))throw new PublicEdgeSyncException('deployment_target_manifest_identity_mismatch','هویت PWA روی آدرس جدید با نصب فعلی یکسان نیست.',409);
        $path=(string)(parse_url($base,PHP_URL_PATH)?:'');$path='/'.trim($path,'/');if($path==='/')$path='';$expectedStart=$path.'/staff/';if(!hash_equals($expectedStart,(string)($json['start_url']??''))||!hash_equals($expectedStart,(string)($json['scope']??'')))throw new PublicEdgeSyncException('deployment_target_manifest_scope_mismatch','Manifest روی آدرس جدید start/scope مورد انتظار را ارائه نمی‌کند.',409);
        return ['ok'=>true,'staff_login'=>true,'manifest'=>true,'manifest_id'=>$id,'start_url'=>$expectedStart];
    }

    /**
     * Mint a short-lived browser credential for the Local LAN Staff gateway.
     * The shared secret never leaves Local; Public only receives the signed bearer token.
     */
    public function mintLanStaffToken(string $projectionId,int $ttlSeconds=21600): array
    {
        $projectionId=trim($projectionId);$installation=$this->installationId();$secret=$this->secret();
        if($installation===''||$secret===''||preg_match('/^user:[1-9][0-9]*$/D',$projectionId)!==1)return ['token'=>'','expires_at'=>''];
        $bucket=14400;$iat=intdiv(time(),$bucket)*$bucket;$ttl=max(900,min(43200,$ttlSeconds));$exp=$iat+$ttl;
        $payload=['format'=>'sokna-lan-staff-token-v1','aud'=>'sokna-local-lan-staff','installation_id'=>$installation,'projection_id'=>$projectionId,'iat'=>$iat,'exp'=>$exp];
        $raw=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $b64=static fn(string $v):string=>rtrim(strtr(base64_encode($v),'+/','-_'),'=');
        $encoded=$b64($raw);$sig=$b64(hash_hmac('sha256','v1.'.$encoded,$secret,true));
        return ['token'=>'v1.'.$encoded.'.'.$sig,'expires_at'=>gmdate('c',$exp)];
    }

    private function unsignedPost(string $url, array $payload, string $unreachableMessage): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (is_callable($this->transport)) {
            $response = ($this->transport)($url, 'POST', ['Content-Type' => 'application/json', 'Accept' => 'application/json'], $body);
            return $this->normalizeResponse($response);
        }
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => $body,
                'timeout' => 10,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        error_clear_last();
        $started = microtime(true);
        $raw = @file_get_contents($url, false, $context);
        $elapsedMs = (int)round((microtime(true) - $started) * 1000);
        $transportDetail = $this->transportFailureDetail($elapsedMs, 10);
        $status = $this->httpStatus($http_response_header ?? []);
        if ($raw === false && $status === 0) {
            throw new PublicEdgeSyncException('public_unreachable', $unreachableMessage, 503, $transportDetail, 'transport');
        }
        return $this->normalizeResponse(['status' => $status, 'body' => (string)$raw, 'headers'=>$http_response_header ?? []]);
    }

    private function transportFailureDetail(int $elapsedMs=0,int $timeoutSeconds=0,string $messageOverride=''): string
    {
        $last = error_get_last();
        $message = strtolower(trim($messageOverride . ' ' . (string)($last['message'] ?? '')));
        if ($message === '') return 'transport_failure';
        if (str_contains($message, 'php_network_getaddresses') || str_contains($message, 'getaddrinfo') || str_contains($message, 'name or service not known') || str_contains($message, 'no such host')) return 'dns_resolution_failed';
        if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) return 'transport_timeout';
        if (str_contains($message, 'connection refused') || str_contains($message, 'actively refused')) return 'connection_refused';
        if (str_contains($message, 'network is unreachable') || str_contains($message, 'no route to host')) return 'network_unreachable';
        if (str_contains($message, 'certificate') || str_contains($message, 'ssl') || str_contains($message, 'tls') || str_contains($message, 'crypto')) return 'tls_failure';
        // PHP streams can collapse a socket read timeout into the generic "HTTP request failed"
        // warning. Elapsed time is safe evidence and avoids exposing raw transport text.
        if ($timeoutSeconds > 0 && $elapsedMs >= max(250, (int)floor($timeoutSeconds * 850))) return 'transport_timeout';
        return 'transport_failure';
    }

    private function baseUrl(): string
    {
        return $this->normalizeBase($this->config->string('public.base_url', ''));
    }

    private function requireBase(string $url, string $message): string
    {
        $base = $this->normalizeBase($url);
        if ($base === '') throw new PublicEdgeSyncException('public_url_invalid', $message, 422);
        return $base;
    }

    private function normalizeBase(string $url): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[\x00-\x20\x7f]/', $url)) return '';
        $parts = parse_url($url);
        if (!is_array($parts)) return '';
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) return '';

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        $host = trim($host, '[]');
        if ($host === '' || !in_array($scheme, ['http', 'https'], true)) return '';
        if ($scheme === 'http' && !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) return '';

        $path = (string)($parts['path'] ?? '');
        if (str_contains($path, '\\')) return '';
        $decodedPath = rawurldecode($path);
        foreach (explode('/', $decodedPath) as $segment) {
            if ($segment === '.' || $segment === '..') return '';
        }
        $path = rtrim($path, '/');
        if ($path === '/') $path = '';

        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        return $scheme . '://' . $this->authorityHost($host) . $port . $path;
    }

    private function authorityHost(string $host): string
    {
        return str_contains($host, ':') ? '[' . trim($host, '[]') . ']' : $host;
    }

    private function secret(): string
    {
        return trim($this->config->string('public.shared_secret', ''));
    }

    private function signatureBase(string $method, string $path, string $timestamp, string $nonce, string $body): string
    {
        return implode("\n", [
            'sokna-relay-v1',
            strtoupper($method),
            '/' . ltrim($path, '/'),
            $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);
    }

    private function normalizeResponse(array $response): array
    {
        $normalized = $this->normalizeRawResponse($response);
        $status = (int)$normalized['status'];
        $body = $normalized['body'];
        if ($status < 200 || $status >= 300 || ($body['ok'] ?? false) !== true) {
            $code=(string)($body['error'] ?? $body['code'] ?? 'public_sync_failed');
            throw new PublicEdgeSyncException(
                $code,
                (string)($body['message'] ?? 'همگام‌سازی Public Edge پذیرفته نشد.'),
                $status ?: 502,
                '',
                (string)($normalized['failure_class']??$this->classifyFailure($status,$code)),
                (int)($normalized['retry_after_seconds']??0),
            );
        }
        return $normalized;
    }

    private function normalizeRawResponse(array $response): array
    {
        $status = (int)($response['status'] ?? 0);
        $body = $response['body'] ?? [];
        if (is_string($body)) {
            $decoded = json_decode($body, true);
            $body = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($body)) $body = [];
        $headers=is_array($response['headers']??null)?$response['headers']:[];
        $code=(string)($body['error']??$body['code']??'');
        return [
            'status'=>$status,'body'=>$body,
            'failure_class'=>$this->classifyFailure($status,$code),
            'retry_after_seconds'=>$this->retryAfterSeconds($headers,$body),
        ];
    }

    private function classifyFailure(int $status,string $code): string
    {
        $code=strtolower(trim($code));
        if($code==='public_unreachable'||str_contains($code,'transport_timeout')||str_contains($code,'tls_failure'))return 'transport';
        if($code==='public_redirect_rejected'||str_contains($code,'redirect_rejected'))return 'compatibility';
        if(in_array($status,[429,502,503,504],true))return 'overload';
        if(in_array($status,[401,403],true))return 'authentication';
        if($status===426||str_contains($code,'unsupported_transport_protocol')||str_contains($code,'contract_upgrade_required'))return 'compatibility';
        if($code==='public_deadline_exceeded'||str_contains($code,'deadline_exhausted'))return 'deadline';
        return 'domain';
    }

    /** @param array<int|string,mixed> $headers */
    private function retryAfterSeconds(array $headers,array $body): int
    {
        $candidate=array_key_exists('retry_after_seconds',$body)?$body['retry_after_seconds']:(array_key_exists('retry_after',$body)?$body['retry_after']:null);
        if($candidate!==null&&is_numeric($candidate))return max(0,min(300,(int)$candidate));
        foreach($headers as $key=>$value){
            $line=is_string($key)?((string)$key.': '.(is_array($value)?implode(',',$value):(string)$value)):(string)$value;
            if(!preg_match('/^Retry-After:\s*(.+)$/i',trim($line),$m))continue;
            $raw=trim($m[1]);
            if(ctype_digit($raw))return max(0,min(300,(int)$raw));
            $at=strtotime($raw);if($at!==false)return max(0,min(300,$at-time()));
        }
        return 0;
    }

    /** @param array<int,string> $headers */
    private function httpStatus(array $headers): int
    {
        foreach ($headers as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match)) return (int)$match[1];
        }
        return 0;
    }
}
