<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Printing;

use PDO;

final class PrintTemplatePackageService
{
    public const FORMAT='sokna-print-template-package-v1';
    private const KINDS=['preparation','customer'];
    private const TOP_KEYS=['format','template_key','name','version','document_kind','description','template'];
    private const TEMPLATE_KEYS=['base_font_size','title_font_size','table_font_size','line_spacing','margin','show_actor','show_time','show_order_number','show_section_titles','show_prices','footer','design'];
    private const DESIGN_KEYS=['density','header_alignment','separator_style','item_layout','section_order','labels'];
    private const LABEL_KEYS=['adjustment','discount','note','reprint','settlement','subtotal','takeaway','tax','taxable','ticket_title','total'];
    private const PREP_SECTIONS=['status','meta','items','notes','footer'];
    private const CUSTOMER_SECTIONS=['brand','meta','items','summary','settlement','footer'];

    public function __construct(private readonly PDO $pdo){}

    public function snapshot(): array
    {
        $rows=$this->pdo->query(
            "SELECT p.id,p.template_key,p.display_name,p.version,p.document_kind,p.content_sha256,p.description,p.created_at,u.name created_by_name,\n".
            "       IF(a.package_id=p.id,1,0) active,a.activated_at\n".
            "FROM print_template_packages p LEFT JOIN print_template_activations a ON a.package_id=p.id AND a.document_kind=p.document_kind\n".
            "LEFT JOIN users u ON u.id=p.created_by_user_id ORDER BY p.document_kind,p.created_at DESC,p.id DESC"
        )->fetchAll(PDO::FETCH_ASSOC)?:[];
        $active=[];foreach($rows as $row)if((int)$row['active']===1)$active[(string)$row['document_kind']]=$row;
        return ['format'=>self::FORMAT,'packages'=>$rows,'active'=>$active,'contract'=>$this->contract()];
    }

    public function import(string $raw,array $actor): array
    {
        $uid=$this->admin($actor);
        if(strlen($raw)<2||strlen($raw)>65536)throw new PrintException('print_template_package_size','بسته چاپ باید کمتر از ۶۴ کیلوبایت باشد.',413);
        try{$decoded=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new PrintException('print_template_package_json','فایل .soknaprint JSON معتبر نیست.',422);}
        if(!is_array($decoded)||array_is_list($decoded))throw new PrintException('print_template_package_shape','ساختار بسته چاپ معتبر نیست.',422);
        $package=$this->canonicalPackage($decoded);$canonical=self::json($package);$sha=hash('sha256',$canonical);
        $existing=$this->pdo->prepare('SELECT id,content_sha256 FROM print_template_packages WHERE template_key=? AND version=? LIMIT 1');
        $existing->execute([$package['template_key'],$package['version']]);$row=$existing->fetch(PDO::FETCH_ASSOC);
        if(is_array($row)){
            if(!hash_equals((string)$row['content_sha256'],$sha))throw new PrintException('print_template_version_conflict','این نسخه قبلاً با محتوای دیگری ثبت شده است.',409);
            return ['package_id'=>(int)$row['id'],'content_sha256'=>$sha,'deduplicated'=>true];
        }
        $same=$this->pdo->prepare('SELECT id FROM print_template_packages WHERE content_sha256=? LIMIT 1');$same->execute([$sha]);$sameId=$same->fetchColumn();
        if($sameId!==false)return ['package_id'=>(int)$sameId,'content_sha256'=>$sha,'deduplicated'=>true];
        $q=$this->pdo->prepare('INSERT INTO print_template_packages(template_key,display_name,version,document_kind,package_format,package_json,template_json,content_sha256,description,created_by_user_id) VALUES(?,?,?,?,?,?,?,?,?,?)');
        $q->execute([$package['template_key'],$package['name'],$package['version'],$package['document_kind'],self::FORMAT,$canonical,self::json($package['template']),$sha,$package['description']!==''?$package['description']:null,$uid]);
        $id=(int)$this->pdo->lastInsertId();$this->audit($uid,'printing.template_imported','print_template_package',(string)$id,['template_key'=>$package['template_key'],'version'=>$package['version'],'document_kind'=>$package['document_kind'],'sha256'=>$sha]);
        return ['package_id'=>$id,'content_sha256'=>$sha,'deduplicated'=>false];
    }

    public function activate(int $packageId,array $actor): array
    {
        $uid=$this->admin($actor);$row=$this->packageRow($packageId);$kind=(string)$row['document_kind'];
        $q=$this->pdo->prepare('INSERT INTO print_template_activations(document_kind,package_id,activated_by_user_id,activated_at) VALUES(?,?,?,NOW()) ON DUPLICATE KEY UPDATE package_id=VALUES(package_id),activated_by_user_id=VALUES(activated_by_user_id),activated_at=NOW()');
        $q->execute([$kind,$packageId,$uid]);$this->audit($uid,'printing.template_activated','print_template_package',(string)$packageId,['document_kind'=>$kind,'version'=>$row['version'],'sha256'=>$row['content_sha256']]);
        return ['package_id'=>$packageId,'document_kind'=>$kind,'version'=>(string)$row['version']];
    }

    public function applyActive(array $payload): array
    {
        $kind=$this->payloadKind($payload);$template=$this->activeTemplate($kind);if($template===null)return $payload;$payload['schema']='sokna-print-document-v2';$payload['template']=$template;return $payload;
    }

    public function applyPackage(array $payload,int $packageId): array
    {
        $row=$this->packageRow($packageId);$kind=$this->payloadKind($payload);if((string)$row['document_kind']!==$kind)throw new PrintException('print_template_kind_mismatch','این قالب برای نوع سند انتخاب‌شده نیست.',422);
        $payload['schema']='sokna-print-document-v2';$payload['template']=$this->decodeTemplate((string)$row['template_json']);return $payload;
    }

    public function preview(int $packageId,string $destinationKey,array $actor): array
    {
        $this->admin($actor);$row=$this->packageRow($packageId);$destination=$this->destination($destinationKey);$kind=(string)$row['document_kind'];
        if($this->destinationKind((string)$destination['destination_type'])!==$kind)throw new PrintException('print_template_kind_mismatch','قالب و مقصد چاپ هم‌نوع نیستند.',422);
        $payload=$this->applyPackage($this->fixture($kind),$packageId);$agentId=(int)($destination['agent_id']??0);
        if($agentId<1)throw new PrintException('print_preview_agent_missing','برای Preview باید مقصد به Print Agent متصل باشد.',409);
        $a=$this->pdo->prepare('SELECT id,name,bridge_protocol_version,bridge_port,bridge_pairing_id,bridge_origin,last_heartbeat_at FROM print_agents WHERE id=? AND active=1 AND retired_at IS NULL LIMIT 1');$a->execute([$agentId]);$agent=$a->fetch(PDO::FETCH_ASSOC);
        if(!is_array($agent)||(int)$agent['bridge_protocol_version']!==1||(int)$agent['bridge_port']<1||trim((string)($agent['bridge_pairing_id']??''))==='')throw new PrintException('print_preview_bridge_unavailable','Local Preview Bridge این Agent آماده نیست.',409);
        return [
            'package_id'=>$packageId,'destination_key'=>$destinationKey,'bridge'=>['agent_id'=>$agentId,'agent_name'=>(string)$agent['name'],'protocol_version'=>1,'port'=>(int)$agent['bridge_port'],'pairing_id'=>(string)$agent['bridge_pairing_id'],'origin'=>(string)($agent['bridge_origin']??'')],
            'preview'=>['type'=>'print.preview','protocol_version'=>1,'revision'=>1,'session_id'=>'tpl-'.bin2hex(random_bytes(8)),'payload_json'=>self::json($payload),'paper_width_mm'=>(float)$destination['paper_width_mm'],'printable_width_mm'=>(float)$destination['printable_width_mm'],'dpi'=>203],
        ];
    }

    public function packageKind(int $packageId): string{return (string)$this->packageRow($packageId)['document_kind'];}
    public function templateById(int $packageId): array{return $this->decodeTemplate((string)$this->packageRow($packageId)['template_json']);}

    public function contract(): array
    {
        return ['document_kinds'=>self::KINDS,'max_package_bytes'=>65536,'template_fields'=>self::TEMPLATE_KEYS,'design_fields'=>self::DESIGN_KEYS,'executable_content'=>false];
    }

    private function activeTemplate(string $kind): ?array
    {
        $q=$this->pdo->prepare('SELECT p.template_json FROM print_template_activations a JOIN print_template_packages p ON p.id=a.package_id WHERE a.document_kind=? LIMIT 1');$q->execute([$kind]);$json=$q->fetchColumn();return is_string($json)?$this->decodeTemplate($json):null;
    }
    private function canonicalPackage(array $in): array
    {
        $this->keys($in,self::TOP_KEYS,'package');
        if((string)($in['format']??'')!==self::FORMAT)throw new PrintException('print_template_format','فرمت بسته چاپ پشتیبانی نمی‌شود.',422);
        $key=trim((string)($in['template_key']??''));if(!preg_match('/^[a-z][a-z0-9_-]{1,63}$/D',$key))throw new PrintException('print_template_key','کلید قالب معتبر نیست.',422);
        $name=$this->text($in['name']??'',120,'نام قالب');$version=trim((string)($in['version']??''));if(!preg_match('/^\d+\.\d+\.\d+(?:-[a-z0-9.-]+)?$/D',$version))throw new PrintException('print_template_version','نسخه قالب معتبر نیست.',422);
        $kind=(string)($in['document_kind']??'');if(!in_array($kind,self::KINDS,true))throw new PrintException('print_template_kind','نوع سند قالب معتبر نیست.',422);
        $description=$this->optional($in['description']??'',500);$template=$this->validateTemplate($in['template']??null,$kind);
        return ['format'=>self::FORMAT,'template_key'=>$key,'name'=>$name,'version'=>$version,'document_kind'=>$kind,'description'=>$description,'template'=>$template];
    }
    private function validateTemplate(mixed $value,string $kind): array
    {
        if(!is_array($value)||array_is_list($value))throw new PrintException('print_template_shape','بدنه قالب معتبر نیست.',422);$this->keys($value,self::TEMPLATE_KEYS,'template');$out=[];
        foreach(['base_font_size'=>[18,42],'title_font_size'=>[22,60],'table_font_size'=>[24,72],'line_spacing'=>[2,20],'margin'=>[2,30]] as $key=>$range)if(array_key_exists($key,$value)){$n=filter_var($value[$key],FILTER_VALIDATE_INT);if($n===false||$n<$range[0]||$n>$range[1])throw new PrintException('print_template_range','مقدار '.$key.' خارج از محدوده امن است.',422);$out[$key]=(int)$n;}
        foreach(['show_actor','show_time','show_order_number','show_section_titles','show_prices'] as $key)if(array_key_exists($key,$value)){if(!is_bool($value[$key]))throw new PrintException('print_template_boolean','مقدار '.$key.' باید true/false باشد.',422);$out[$key]=$value[$key];}
        if(array_key_exists('footer',$value))$out['footer']=$this->optional($value['footer'],300);
        if(array_key_exists('design',$value))$out['design']=$this->validateDesign($value['design'],$kind);
        if($out===[])throw new PrintException('print_template_empty','قالب چاپ خالی است.',422);return $out;
    }
    private function validateDesign(mixed $value,string $kind): array
    {
        if(!is_array($value)||array_is_list($value))throw new PrintException('print_design_shape','تنظیمات طراحی معتبر نیست.',422);$this->keys($value,self::DESIGN_KEYS,'design');$out=[];
        $choices=['density'=>['compact','comfortable'],'header_alignment'=>['center','right'],'separator_style'=>['solid','dashed','minimal'],'item_layout'=>['columnar','columnar-compact','two-line','responsive-receipt']];
        foreach($choices as $key=>$allowed)if(array_key_exists($key,$value)){if(!is_string($value[$key])||!in_array($value[$key],$allowed,true))throw new PrintException('print_design_choice','مقدار '.$key.' معتبر نیست.',422);$out[$key]=$value[$key];}
        if(array_key_exists('section_order',$value)){
            if(!is_array($value['section_order'])||!array_is_list($value['section_order']))throw new PrintException('print_section_order','ترتیب بخش‌ها معتبر نیست.',422);$allowed=$kind==='preparation'?self::PREP_SECTIONS:self::CUSTOMER_SECTIONS;$sections=[];
            foreach($value['section_order'] as $section){if(!is_string($section)||!in_array($section,$allowed,true)||in_array($section,$sections,true))throw new PrintException('print_section_order','ترتیب بخش‌ها شامل مقدار نامعتبر یا تکراری است.',422);$sections[]=$section;}if($sections===[])throw new PrintException('print_section_order','حداقل یک بخش لازم است.',422);$out['section_order']=$sections;
        }
        if(array_key_exists('labels',$value)){
            if(!is_array($value['labels'])||array_is_list($value['labels']))throw new PrintException('print_labels','برچسب‌های قالب معتبر نیستند.',422);$this->keys($value['labels'],self::LABEL_KEYS,'labels');$labels=[];foreach($value['labels'] as $key=>$label)$labels[(string)$key]=$this->text($label,80,'برچسب');$out['labels']=$labels;
        }
        return $out;
    }
    private function fixture(string $kind): array
    {
        if($kind==='preparation')return ['schema'=>'sokna-print-document-v2','document_kind'=>'preparation','title'=>'کافه سکنا','badge'=>'پیش‌نمایش قالب','table_name'=>'میز ۲','order_number'=>'2048','created_at'=>date('Y-m-d H:i:s'),'sections'=>[['title'=>'بار گرم','items'=>[['name'=>'قهوه دمی','quantity'=>2,'note'=>'بدون شکر'],['name'=>'کیک روز','quantity'=>1,'fulfillment_mode'=>'takeaway']]]],'customer_note'=>'مهمان عجله دارد'];
        return ['schema'=>'sokna-print-document-v2','document_kind'=>'customer','title'=>'کافه سکنا','invoice_number'=>'INV-2048','table_name'=>'میز ۲','created_at'=>date('Y-m-d H:i:s'),'currency'=>'تومان','sections'=>[['items'=>[['name'=>'قهوه دمی','quantity'=>2,'unit_price'=>180000,'line_total'=>360000],['name'=>'کیک روز','quantity'=>1,'unit_price'=>220000,'line_total'=>220000]]]],'subtotal'=>580000,'discount'=>0,'tax'=>0,'total'=>580000,'settlement_label'=>'کارتخوان'];
    }
    private function payloadKind(array $payload): string{$kind=(string)($payload['document_kind']??'');return $kind==='preparation'?'preparation':'customer';}
    private function destinationKind(string $type): string{return $type==='preparation'?'preparation':'customer';}
    private function destination(string $key): array{$q=$this->pdo->prepare('SELECT * FROM print_destinations WHERE destination_key=? LIMIT 1');$q->execute([$key]);$r=$q->fetch(PDO::FETCH_ASSOC);if(!is_array($r))throw new PrintException('destination_not_found','مقصد چاپ پیدا نشد.',404);return $r;}
    private function packageRow(int $id): array{$q=$this->pdo->prepare('SELECT * FROM print_template_packages WHERE id=? LIMIT 1');$q->execute([$id]);$r=$q->fetch(PDO::FETCH_ASSOC);if(!is_array($r))throw new PrintException('print_template_not_found','قالب چاپ پیدا نشد.',404);return $r;}
    private function decodeTemplate(string $json): array{$v=json_decode($json,true);if(!is_array($v))throw new PrintException('print_template_corrupt','نسخه ذخیره‌شده قالب معتبر نیست.',500);return $v;}
    private function keys(array $value,array $allowed,string $scope): void{foreach(array_keys($value) as $key)if(!in_array((string)$key,$allowed,true))throw new PrintException('print_template_unknown_field','فیلد '.$scope.'.'.$key.' در contract قالب چاپ مجاز نیست.',422);}
    private function admin(array $actor): int{if((string)($actor['role']??'')!=='admin'||(int)($actor['id']??0)<1)throw new PrintException('forbidden','این عملیات فقط برای مدیر فعال است.',403);return (int)$actor['id'];}
    private function text(mixed $value,int $max,string $label): string{$v=trim((string)$value);if($v===''||strlen($v)>$max*4||preg_match('/[<>]/u',$v))throw new PrintException('print_template_text',$label.' معتبر نیست.',422);return $v;}
    private function optional(mixed $value,int $max): string{$v=trim((string)$value);if($v==='')return '';if(strlen($v)>$max*4||preg_match('/[<>]/u',$v))throw new PrintException('print_template_text','متن قالب معتبر نیست.',422);return $v;}
    private function audit(int $uid,string $action,string $type,string $id,array $details): void{$q=$this->pdo->prepare('INSERT INTO audit_log(actor_user_id,action,entity_type,entity_id,details_json) VALUES(?,?,?,?,?)');$q->execute([$uid,$action,$type,$id,self::json($details)]);}
    private static function json(array $value): string{return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
}
