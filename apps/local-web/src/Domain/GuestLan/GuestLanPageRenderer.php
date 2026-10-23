<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\GuestLan;

final class GuestLanPageRenderer
{
    public function __construct(private readonly GuestLanRuntimeService $runtime,private readonly string $basePath='')
    {
    }

    /** @return array{status:int,headers:array<string,string>,body:string} */
    public function manifest(string $installationId,array $endpoints=[]): array
    {
        $bundle=$this->runtime->bundle(trim($installationId));$snapshot=is_array($bundle['snapshot']??null)?$bundle['snapshot']:[];$manifest=is_array($bundle['media_manifest']??null)?$bundle['media_manifest']:[];$mediaBase=$this->safeAssetUrl((string)($endpoints['media_base']??''));
        $name=trim((string)($snapshot['cafe_name']??$snapshot['name']??$bundle['display_name']??'سکنا'));if($name==='')$name='سکنا';$brand=$this->identityAssets($installationId,$snapshot,$manifest,$mediaBase);
        $doc=['id'=>$this->u('/menu'),'name'=>$name.' — منو','short_name'=>$name,'lang'=>'fa','dir'=>'rtl','start_url'=>$this->u('/menu').'?installation='.rawurlencode($installationId),'scope'=>$this->u('/'),'display'=>'standalone','background_color'=>'#f6f5f0','theme_color'=>'#0f6b66','icons'=>[['src'=>$brand['icon-192'],'sizes'=>'192x192','type'=>'image/png','purpose'=>'any'],['src'=>$brand['icon-512'],'sizes'=>'512x512','type'=>'image/png','purpose'=>'any']]];
        $body=json_encode($doc,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if(!is_string($body))$body='{}';return ['status'=>200,'headers'=>['Content-Type'=>'application/manifest+json; charset=utf-8','Cache-Control'=>'no-cache, max-age=0'],'body'=>$body];
    }


    /** @return array{status:int,headers:array<string,string>,body:string} */
    public function about(string $installationId, array $query = [], array $endpoints = []): array
    {
        $installationId=trim($installationId);$bundle=$this->runtime->bundle($installationId);$snapshot=is_array($bundle['snapshot']??null)?$bundle['snapshot']:[];
        if($snapshot===[])return $this->statePage(503,'منوی عمومی هنوز منتشر نشده','لطفاً کمی بعد دوباره امتحان کنید.',false,$endpoints);
        $presentation=is_array($snapshot['presentation']??null)?$snapshot['presentation']:[];$settings=is_array($presentation['settings']??null)?$presentation['settings']:[];
        if(empty($settings['public_about_enabled']))return $this->statePage(404,'صفحه درباره سکنا فعال نیست','این صفحه در حال حاضر از منوی عمومی کنار گذاشته شده است.',true,$endpoints);
        $tableToken=trim((string)($query['table']??''));$table=$tableToken!==''?$this->findTable($snapshot,$tableToken):null;$invalidTable=$tableToken!==''&&$table===null;
        $menuKey=trim((string)($query['menu']??''));if(preg_match('/^[A-Za-z0-9._-]{1,80}$/D',$menuKey)!==1)$menuKey='';
        $returnParams=['installation'=>$installationId];if($table!==null)$returnParams['table']=$tableToken;if($menuKey!=='')$returnParams['menu']=$menuKey;$menuHref=$this->u('/menu').'?'.http_build_query($returnParams,'','&',PHP_QUERY_RFC3986);
        $displayName=trim((string)($snapshot['cafe_name']??$snapshot['name']??$bundle['display_name']??'سکنا'));if($displayName==='')$displayName='سکنا';
        $cssUrl=$this->safeAssetUrl((string)($endpoints['css']??'/assets/scds/guest.css'));$themeCssUrl=$this->safeAssetUrl((string)($endpoints['theme_css']??''));$mediaBase=$this->safeAssetUrl((string)($endpoints['media_base']??''));
        $manifest=is_array($bundle['media_manifest']??null)?$bundle['media_manifest']:[];$brand=$this->identityAssets($installationId,$snapshot,$manifest,$mediaBase);
        $title=$this->plainSetting($settings,'about_title','درباره سکنا',100);$intro=$this->plainSetting($settings,'about_intro','سکنا جایی برای قهوه، گفت‌وگو و تجربه‌های نزدیکه.',700);$description=$this->plainSetting($settings,'seo_description',$intro,320);$address=$this->plainSetting($settings,'public_address','',320);$phone=$this->plainSetting($settings,'public_phone','',60);
        $instagramCafe=$this->safeExternalUrl((string)($settings['instagram_cafe_url']??''));$instagramHouse=$this->safeExternalUrl((string)($settings['instagram_house_url']??''));
        $site=$this->safeExternalUrl((string)($settings['accommodation_site_url']??''));$rooms=$this->safeExternalUrl((string)($settings['accommodation_rooms_url']??''));$tours=$this->safeExternalUrl((string)($settings['accommodation_tours_url']??''));$accommodationEnabled=!array_key_exists('accommodation_enabled',$settings)||(bool)$settings['accommodation_enabled'];
        $whatsapp='';if(!empty($settings['whatsapp_enabled'])){$digits=str_replace(['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'],['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'],(string)($settings['whatsapp_number']??''));$digits=preg_replace('/\D+/','',$digits)??'';if($digits!==''){$whatsapp='https://wa.me/'.$digits;$msg=$this->plainSetting($settings,'whatsapp_message','',500);if($msg!=='')$whatsapp.='?text='.rawurlencode($msg);}}
        $html='<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'.($description!==''?'<meta name="description" content="'.self::e($description).'">':'').'<title>'.self::e($title.' | '.$displayName).'</title><link rel="icon" type="image/png" sizes="32x32" href="'.self::e((string)$brand['icon-32']).'"><link rel="apple-touch-icon" sizes="180x180" href="'.self::e((string)$brand['icon-180']).'"><link rel="stylesheet" href="'.self::e($cssUrl).'">'.($themeCssUrl!==''?'<link rel="stylesheet" href="'.self::e($themeCssUrl).'">':'').'</head><body class="sg-body sg-public-about"><main class="sg-public-shell">';
        if($invalidTable)$html.='<div class="sg-public-warning" role="alert"><strong>لینک میز معتبر نیست</strong><p>برای ادامه سفارش، QR میز فعلی را دوباره اسکن کن.</p></div>';
        $html.='<header class="sg-public-hero"><a class="sg-public-brand" href="'.self::e($menuHref).'"><img src="'.self::e((string)$brand['logo-256']).'" alt=""><strong>'.self::e($displayName).'</strong></a><div><span>آشنایی بیشتر</span><h1>'.self::e($title).'</h1><p>'.nl2br(self::e($intro)).'</p></div></header><section class="sg-public-info">';
        if($address!=='')$html.='<article><span>نشانی</span><p>'.nl2br(self::e($address)).'</p></article>';if($phone!==''){$tel=preg_replace('/[^0-9+]/','',str_replace(['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'],['0','1','2','3','4','5','6','7','8','9'],$phone))??'';$html.='<article><span>تماس</span><a href="tel:'.self::e($tel).'" dir="ltr">'.self::e($phone).'</a></article>';}
        $html.='<article><span>'.($table!==null?'منوی میز':'منوی کافه').'</span><a href="'.self::e($menuHref).'">'.($table!==null?'بازگشت به '.self::e((string)($table['name']??'میز')):'دیدن منوی عمومی').'</a></article></section>';
        if($accommodationEnabled&&$site!==''){$html.='<section class="sg-public-accommodation"><div><span>بیشتر از سکنا</span><h2>'.self::e($this->plainSetting($settings,'accommodation_card_title','خانه سکنا رو هم می‌شناسی؟',100)).'</h2><p>'.self::e($this->plainSetting($settings,'accommodation_card_text','اقامت، گشت‌وگذار و تجربه غرب هرمزگان',300)).'</p></div><div class="sg-public-actions"><a class="sg-button sg-button--primary" target="_blank" rel="noopener" href="'.self::e($site).'">سایت خانه سکنا</a>';if($rooms!=='')$html.='<a class="sg-button sg-button--secondary" target="_blank" rel="noopener" href="'.self::e($rooms).'">اتاق‌ها</a>';if($tours!=='')$html.='<a class="sg-button sg-button--secondary" target="_blank" rel="noopener" href="'.self::e($tours).'">گشت‌ها</a>';$html.='</div></section>';}
        $social=[];if($instagramCafe!=='')$social[]=['اینستاگرام کافه',$instagramCafe];if($instagramHouse!=='')$social[]=['اینستاگرام اقامتگاه',$instagramHouse];if($whatsapp!=='')$social[]=['واتس‌اپ',$whatsapp];if($social!==[]){$html.='<section class="sg-public-social"><span>راه‌های ارتباط</span><div>';foreach($social as [$label,$url])$html.='<a target="_blank" rel="noopener" href="'.self::e($url).'">'.self::e($label).'</a>';$html.='</div></section>';}
        $html.='<footer class="sg-public-footer"><a href="'.self::e($menuHref).'">بازگشت به منو</a>'.($site!==''?'<a target="_blank" rel="noopener" href="'.self::e($site).'">خانه سکنا</a>':'').'</footer></main></body></html>';
        return ['status'=>200,'headers'=>['Content-Type'=>'text/html; charset=utf-8','Cache-Control'=>'no-store, max-age=0'],'body'=>$html];
    }

    /**
     * Render the Public-owned Guest surface from immutable publish + availability projections.
     * No Local business rule is reimplemented here: write eligibility comes only from actionState()
     * and projected acceptance/session state.
     *
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    public function render(string $installationId, array $query = [], array $endpoints = []): array
    {
        $installationId = trim($installationId);
        $bundle = $this->runtime->bundle($installationId);
        $snapshot = is_array($bundle['snapshot'] ?? null) ? $bundle['snapshot'] : [];
        if ($snapshot === []) {
            return $this->statePage(
                503,
                'منوی عمومی هنوز منتشر نشده',
                'لطفاً کمی بعد دوباره امتحان کنید.',
                false,
                $endpoints,
            );
        }

        $tableToken = trim((string)($query['table'] ?? ''));
        $table = $tableToken !== '' ? $this->findTable($snapshot, $tableToken) : null;
        if ($tableToken !== '' && $table === null) {
            return $this->statePage(
                404,
                'این QR معتبر نیست',
                'این QR ممکن است قدیمی یا مربوط به میزی غیرفعال باشد. QR موجود روی میز را دوباره اسکن کنید یا از همکاران کافه کمک بگیرید.',
                true,
                $endpoints,
            );
        }

        $actionState = $this->runtime->actionState($bundle);
        $availability = is_array($bundle['availability'] ?? null) ? $bundle['availability'] : [];
        $catalog = $this->catalog($snapshot, trim((string)($query['menu'] ?? '')));
        $categories = $this->categoriesWithItems($catalog, $availability);
        $featuredItems = [];
        foreach ($categories as $category) {
            foreach ((array)($category['items'] ?? []) as $item) {
                if (is_array($item) && !empty($item['featured']) && !empty($item['available'])) $featuredItems[] = $item;
                if (count($featuredItems) >= 5) break 2;
            }
        }
        $menus = $this->menus($snapshot);
        $selectedMenuKey = (string)($catalog['menu_key'] ?? '');
        $acceptance = $this->orderAcceptance($availability);
        $session = $table !== null ? $this->projectedSession($availability, (int)($table['id'] ?? 0)) : null;
        $sessionsEnabled = !empty($snapshot['features']['table_sessions_enabled']);
        // A guest may submit the first order before an active table session exists.
        // Local is the owner of that transition and creates a pending session that staff approve.
        $requiresOperatorConfirmation = $sessionsEnabled && ($session === null || (string)($session['status'] ?? '') !== 'active');
        $canOrder = $table !== null
            && ($actionState['enabled'] ?? false) === true
            && $acceptance;
        $publicWaiterTables = $table === null ? array_values(array_filter((array)($snapshot['tables'] ?? []), 'is_array')) : [];
        $orderUiEligible = $table !== null && $acceptance;
        $waiterUiEligible = (
            ($table !== null && !empty($availability['waiter_enabled_table']))
            || ($table === null && !empty($availability['waiter_enabled_public']) && $publicWaiterTables !== [])
        );
        $waiterEnabled = ($actionState['enabled'] ?? false) === true && $waiterUiEligible;
        $connectivityState = ($actionState['enabled'] ?? false) === true ? 'fresh' : 'stale';
        $lastSyncAt = trim((string)($actionState['last_sync_at'] ?? ''));
        $lastSyncLabel = self::syncAgeLabel($lastSyncAt);

        $displayName = trim((string)($snapshot['cafe_name'] ?? $snapshot['name'] ?? $bundle['display_name'] ?? 'سکنا'));
        if ($displayName === '') $displayName = 'سکنا';
        $cssUrl = $this->safeAssetUrl((string)($endpoints['css'] ?? '/assets/scds/guest.css'));
        $jsUrl = $this->safeAssetUrl((string)($endpoints['js'] ?? '/assets/scds/guest.js'));
        $orderEndpoint = $this->safeAssetUrl((string)($endpoints['create_order'] ?? ''));
        $ordersEndpoint = $this->safeAssetUrl((string)($endpoints['orders'] ?? ''));
        $quoteEndpoint = $this->safeAssetUrl((string)($endpoints['order_quote'] ?? ''));
        $statusEndpoint = $this->safeAssetUrl((string)($endpoints['order_status'] ?? ''));
        $contextEndpoint = $this->safeAssetUrl((string)($endpoints['table_context'] ?? ''));
        $waiterEndpoint = $this->safeAssetUrl((string)($endpoints['waiter_call'] ?? ''));
        $changesEndpoint = $this->safeAssetUrl((string)($endpoints['changes'] ?? ''));
        $metricEndpoint = $this->safeAssetUrl((string)($endpoints['metric'] ?? ''));
        $transportMode = trim((string)($endpoints['transport_mode'] ?? 'public_edge'));
        if (!in_array($transportMode, ['public_edge','local_lan'], true)) $transportMode = 'public_edge';
        $mediaBase = $this->safeAssetUrl((string)($endpoints['media_base'] ?? ''));
        $manifest = is_array($bundle['media_manifest'] ?? null) ? $bundle['media_manifest'] : [];
        $brand = $this->identityAssets($installationId,$snapshot,$manifest,$mediaBase);
        $presentation = is_array($snapshot['presentation'] ?? null) ? $snapshot['presentation'] : [];
        $copy = is_array($presentation['copy'] ?? null) ? $presentation['copy'] : [];
        $settings = is_array($presentation['settings'] ?? null) ? $presentation['settings'] : [];
        $composition = $this->composition($presentation);
        $themeBodyClasses = $this->themeBodyClasses($presentation);
        $themeCssUrl = $this->safeAssetUrl((string)($endpoints['theme_css'] ?? ''));
        $marketing = is_array($snapshot['marketing'] ?? null) ? $snapshot['marketing'] : [];
        $campaigns = is_array($marketing['campaigns'] ?? null) ? $marketing['campaigns'] : [];
        $events = is_array($marketing['events'] ?? null) ? $marketing['events'] : [];
        $acceptanceMap = is_array($availability['order_acceptance'] ?? null) ? $availability['order_acceptance'] : ['cafe' => $acceptance, 'kitchen' => $acceptance, 'bar' => $acceptance];
        $acceptanceMessages = is_array($availability['order_acceptance_messages'] ?? null) ? $availability['order_acceptance_messages'] : [];
        $clientItems = [];
        $catalogRows = array_values(array_filter((array)($catalog['items'] ?? []),'is_array'));
        $catalogById=[];foreach($catalogRows as $row){$id=(int)($row['id']??0);if($id>0)$catalogById[$id]=$row;}
        $availabilityItems=is_array($availability['items']??null)?$availability['items']:[];
        foreach ($catalogRows as $item) {
            $id=(int)($item['id']??0);if($id<1)continue;$source=(string)($item['image_path']??'');$station=(string)($item['preparation_station']??'other');
            $live=is_array($availabilityItems[(string)$id]??null)?$availabilityItems[(string)$id]:[];$available=array_key_exists('available',$live)?(bool)$live['available']:!empty($item['available']);
            $scope=$station==='kitchen'?'kitchen':(in_array($station,['hot_bar','cold_bar'],true)?'bar':'cafe');
            $scopeOpen=!array_key_exists($scope,$acceptanceMap)||(bool)$acceptanceMap[$scope];$cafeOpen=!array_key_exists('cafe',$acceptanceMap)||(bool)$acceptanceMap['cafe'];
            $bundle=is_array($item['bundle']??null)?$item['bundle']:null;$bundleComponents=[];$fixedDelta=0;$bundleAvailable=true;$choiceConfigured=false;$choiceAvailable=false;
            if($bundle!==null){
                foreach((array)($bundle['components']??[]) as $component){if(!is_array($component))continue;$componentId=(int)($component['item_id']??0);$cr=is_array($catalogById[$componentId]??null)?$catalogById[$componentId]:[];$clive=is_array($availabilityItems[(string)$componentId]??null)?$availabilityItems[(string)$componentId]:[];$ca=array_key_exists('available',$clive)?(bool)$clive['available']:!empty($cr['available']);$cs=(string)($cr['preparation_station']??'none');$cScope=$cs==='kitchen'?'kitchen':(in_array($cs,['hot_bar','cold_bar'],true)?'bar':'cafe');$cOpen=!array_key_exists($cScope,$acceptanceMap)||(bool)$acceptanceMap[$cScope];$mode=(string)($component['mode']??'fixed');$ok=$ca&&$cOpen&&$cafeOpen&&$cr!==[];$entry=['item_id'=>$componentId,'name'=>(string)($cr['name']??''),'mode'=>$mode,'quantity'=>max(1,(int)($component['quantity']??1)),'price_delta'=>(int)($component['price_delta']??0),'available'=>$ok,'preparation_station'=>$cs];$bundleComponents[]=$entry;if($mode==='fixed'){if(!$ok)$bundleAvailable=false;$fixedDelta+=(int)$entry['price_delta'];}else{$choiceConfigured=true;if($ok)$choiceAvailable=true;}}
                if($choiceConfigured&&!$choiceAvailable)$bundleAvailable=false;
            }
            $effectivePrice=max(0,(int)($item['price']??0)+$fixedDelta);$available=$available&&$bundleAvailable;
            $orderAvailable=$available&&$cafeOpen&&($bundle!==null?true:$scopeOpen);$blockedScope=!$cafeOpen?'cafe':(!$scopeOpen&&$bundle===null?$scope:'');
            $clientItems[(string)$id]=[
                'id'=>$id,'name'=>(string)($item['name']??''),'description'=>(string)($item['description']??''),'category'=>(string)($item['category_name']??''),
                'price'=>$effectivePrice,'base_price'=>max(0,(int)($item['price']??0)),'available'=>$available,'order_available'=>$orderAvailable,'blocked_scope'=>$blockedScope,
                'unavailable_message'=>$blockedScope!==''?(string)($acceptanceMessages[$blockedScope]??'این بخش فعلاً سفارش تازه نمی‌پذیرد.'):(!$available?'این ترکیب یا یکی از اجزای آن فعلاً قابل سفارش نیست.':''),
                'takeaway_allowed'=>!array_key_exists('takeaway_allowed',$item)||(bool)$item['takeaway_allowed'],'preparation_station'=>$station,
                'suggested_item_id'=>isset($item['suggested_item_id'])&&$item['suggested_item_id']!==null?(int)$item['suggested_item_id']:null,'suggested_item_ids'=>array_values(array_map('intval',(array)($item['suggested_item_ids']??[]))),
                'variant_family_id'=>(int)($item['variant_family_id']??0),'variant_family_name'=>(string)($item['variant_family_name']??''),'variant_family_description'=>(string)($item['variant_family_description']??''),'variant_label'=>(string)($item['variant_label']??''),'variant_members'=>array_values((array)($item['variant_members']??[])),
                'is_bundle'=>$bundle!==null,'bundle_choice_label'=>$bundle!==null?(string)($bundle['choice_label']??''):null,'bundle_components'=>$bundleComponents,
                'image'=>$this->mediaUrl($installationId,$source,$manifest,$mediaBase),'image_alt'=>$this->mediaAlt($source,$manifest,(string)($item['name']??'')),'image_focal'=>$this->mediaFocal($source,$manifest),
            ];
        }
        $pwaEnabled = !array_key_exists('pwa_enabled',$settings) || (bool)$settings['pwa_enabled'];
        $analyticsEnabled = !array_key_exists('analytics_enabled',$settings) || (bool)$settings['analytics_enabled'];
        $canonicalUrl = $this->safeExternalUrl((string)($settings['app_canonical_url'] ?? ''));
        $seoDescription = $this->plainSetting($settings,'seo_description','',320);
        $instagramCafe = $this->safeExternalUrl((string)($settings['instagram_cafe_url'] ?? ''));
        $instagramHouse = $this->safeExternalUrl((string)($settings['instagram_house_url'] ?? ''));
        $accommodationSite = $this->safeExternalUrl((string)($settings['accommodation_site_url'] ?? ''));
        $accommodationRooms = $this->safeExternalUrl((string)($settings['accommodation_rooms_url'] ?? ''));
        $accommodationTours = $this->safeExternalUrl((string)($settings['accommodation_tours_url'] ?? ''));
        $whatsappUrl = '';
        if (!empty($settings['whatsapp_enabled'])) {
            $digits = str_replace(['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'],['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'],(string)($settings['whatsapp_number'] ?? ''));
            $digits = preg_replace('/\D+/','',$digits) ?? '';
            if ($digits !== '') {
                $whatsappUrl='https://wa.me/'.$digits;
                $waMessage=$this->plainSetting($settings,'whatsapp_message','',500);
                if($waMessage!=='')$whatsappUrl.='?text='.rawurlencode($waMessage);
            }
        }
        $clientJson=json_encode(array_values($clientItems),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);
        $publicTablesJson=json_encode(array_map(static fn(array $t):array=>['id'=>(int)($t['id']??0),'name'=>(string)($t['name']??'میز'),'code'=>(string)($t['code']??'')],$publicWaiterTables),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);

        $html = '<!doctype html><html lang="fa" dir="rtl"><head>'
            . '<meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
            . ($tableToken !== '' ? '<meta name="robots" content="noindex,nofollow,noarchive">' : '')
            . '<title>' . self::e($displayName) . ' | منو</title>'
            . ($seoDescription!==''?'<meta name="description" content="'.self::e($seoDescription).'">':'')
            . ($canonicalUrl!==''?'<link rel="canonical" href="'.self::e($canonicalUrl).'">':'')
            . ($pwaEnabled?'<link rel="manifest" href="' . self::e($this->u('/menu/manifest.webmanifest').'?installation='.rawurlencode($installationId).'&iv='.rawurlencode((string)$brand['version'])) . '">':'')
            . '<link rel="icon" type="image/png" sizes="32x32" href="' . self::e((string)$brand['icon-32']) . '">'
            . '<link rel="apple-touch-icon" sizes="180x180" href="' . self::e((string)$brand['icon-180']) . '">'
            . '<link rel="stylesheet" href="' . self::e($cssUrl) . '">'
            . ($themeCssUrl !== '' ? '<link rel="stylesheet" href="' . self::e($themeCssUrl) . '">' : '')
            . '<script defer src="' . self::e($jsUrl) . '"></script>'
            . '</head><body class="sg-body ' . ($table !== null ? 'sg-mode-table' : 'sg-mode-public') . ($themeBodyClasses!==''?' '.self::e($themeBodyClasses):'') . '">'
            . '<div class="sg-app" data-sg-app'
            . ' data-installation-id="' . self::e($installationId) . '"'
            . ' data-transport-mode="' . self::e($transportMode) . '"'
            . ' data-table-token="' . self::e($tableToken) . '"'
            . ' data-session-token="' . self::e((string)($session['token'] ?? '')) . '"'
            . ' data-requires-operator-confirmation="' . ($requiresOperatorConfirmation ? '1' : '0') . '"'
            . ' data-order-endpoint="' . self::e($orderEndpoint) . '"'
            . ' data-orders-endpoint="' . self::e($ordersEndpoint) . '"'
            . ' data-quote-endpoint="' . self::e($quoteEndpoint) . '"'
            . ' data-status-endpoint="' . self::e($statusEndpoint) . '"'
            . ' data-context-endpoint="' . self::e($contextEndpoint) . '"'
            . ' data-waiter-endpoint="' . self::e($waiterEndpoint) . '"'
            . ' data-changes-endpoint="' . self::e($changesEndpoint) . '"'
            . ' data-metric-endpoint="' . self::e($metricEndpoint) . '"'
            . ' data-analytics-enabled="' . ($analyticsEnabled ? '1' : '0') . '"'
            . ' data-waiter-sent-label="' . self::e($this->copy($copy,'waiter_button_sent','گارسون خبر شده')) . '"'
            . ' data-waiter-accepted-label="' . self::e($this->copy($copy,'waiter_button_accepted','همکارمون در راهه')) . '"'
            . ' data-connectivity-state="' . self::e($connectivityState) . '"'
            . ' data-last-sync-at="' . self::e($lastSyncAt) . '"'
            . ' data-ordering-enabled="' . ($canOrder ? '1' : '0') . '">'
            . '<script type="application/json" data-sg-items-json>' . $clientJson . '</script>'
            . '<script type="application/json" data-sg-public-tables-json>' . $publicTablesJson . '</script>';

        $html .= '<header class="sg-header"><div class="sg-header-inner"><div class="sg-brand">'
            . '<span class="sg-brand-mark" aria-hidden="true"><img src="' . self::e((string)$brand['logo-256']) . '" alt=""></span>'
            . '<div><strong>' . self::e($displayName) . '</strong><span>' . self::e($this->copy($copy,'menu_subtitle','منوی عمومی')) . '</span></div></div>';
        if ($events !== [] || $table !== null) {
            $html .= '<div class="sg-header-actions">';
            if ($events !== []) $html .= '<button type="button" class="sg-header-events" data-sg-open-events aria-label="دیدن رویدادهای سکنا"><span aria-hidden="true">▣</span><b>رویدادها</b></button>';
            if ($table !== null) {
                $tableName = trim((string)($table['name'] ?? ''));
                $html .= '<span class="sg-table-badge">' . self::e($tableName !== '' ? $tableName : 'میز') . '</span>';
            }
            $html .= '</div>';
        }
        $html .= '</div></header>';

        $html .= '<div class="sg-action-state ' . (($actionState['enabled'] ?? false) === true ? 'is-ready' : 'is-degraded') . '" data-sg-action-state data-state="' . self::e($connectivityState) . '" role="status"' . (($actionState['enabled'] ?? false) === true ? ' hidden' : '') . '>'
            . '<strong data-sg-action-title>' . self::e(($actionState['enabled'] ?? false) === true ? $this->copy($copy,'ready_title','ارتباط زنده برقرار است') : $this->copy($copy,'degraded_title','منو در حالت فقط‌خواندنی است')) . '</strong>'
            . '<span data-sg-action-copy>' . self::e(($actionState['enabled'] ?? false) === true ? $this->copy($copy,'ready_body','وضعیت سفارش‌گیری از کافه به‌روز است.') : $this->copy($copy,'degraded_body','ارتباط زنده با کافه موقتاً در دسترس نیست؛ مشاهده منو ادامه دارد.')) . '</span>'
            . '<small data-sg-last-sync' . ($lastSyncLabel === '' ? ' hidden' : '') . '>' . self::e($lastSyncLabel) . '</small>'
            . '</div>';

        if ($table !== null) {
            // Dev26 uses one compact order-history/status affordance near the top. Detailed
            // progress remains in the order modal; the menu itself stays visually quiet.
            $html .= '<section class="sg-recent-orders" data-sg-recent-orders hidden aria-label="سفارش‌های این دستگاه">'
                . '<button type="button" data-sg-open-orders><span data-sg-orders-action>مشاهده سفارش‌ها</span><b data-sg-orders-badge>۰</b></button>'
                . '</section>';
        }

        $html .= '<main class="sg-main">';
        $searchIcon='<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6"></circle><path d="m16 16 4 4"></path></svg>';
        $closeIcon='<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"></path></svg>';
        $html .= '<section class="sg-search" data-sg-search-zone aria-label="جست‌وجوی منو"><div class="sg-search-panel">'
            . '<div class="sg-search-input-wrap"><button type="button" class="sg-search-open" data-sg-search-open aria-label="شروع جست‌وجو">'.$searchIcon.'</button><input id="sgMenuSearch" data-sg-search type="search" inputmode="search" enterkeyhint="search" autocomplete="off" aria-label="' . self::e($this->copy($copy,'search_label','جست‌وجوی منو')) . '" placeholder="' . self::e($this->copy($copy,'search_placeholder','نام نوشیدنی یا غذا را بنویسید')) . '"><button type="button" class="sg-search-clear" data-sg-search-clear aria-label="پاک‌کردن جست‌وجو" hidden>'.$closeIcon.'</button></div>'
            . '<div class="sg-search-results" data-sg-search-results hidden><div class="sg-search-results-head"><div><strong>نتایج جست‌وجو</strong><small data-sg-search-count></small></div><button type="button" data-sg-search-back>بازگشت به منو</button></div><div class="sg-search-result-list" data-sg-search-result-list></div></div>'
            . '</div></section>';

        if (count($menus) > 1) {
            $html .= '<nav class="sg-menu-switcher" aria-label="انتخاب منو">';
            foreach ($menus as $menu) {
                $key = (string)($menu['menu_key'] ?? '');
                if ($key === '') continue;
                $params = ['menu' => $key];
                if ($tableToken !== '') $params['table'] = $tableToken;
                $href = '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
                $selected = hash_equals($selectedMenuKey, $key);
                $html .= '<a class="sg-menu-link' . ($selected ? ' is-active' : '') . '" href="' . self::e($href) . '"'
                    . ($selected ? ' aria-current="page"' : '') . '>' . self::e((string)($menu['name'] ?? 'منو')) . '</a>';
            }
            $html .= '</nav>';
        }

        if ($categories !== []) {
            $html .= '<span id="sg-menu-start"></span><div class="sg-category-rail"><nav class="sg-category-nav" data-sg-rail="category" aria-label="دسته‌بندی‌های منو"><a class="is-active" href="#sg-menu-start" data-sg-category-link="all" aria-current="true">' . $this->categoryIconSvg('list') . '<span>همه</span></a>';
            foreach ($categories as $category) {
                $id = (int)($category['id'] ?? 0);
                $icon = self::categoryIcon((string)($category['icon_key'] ?? 'list'));
                $html .= '<a href="#sg-category-' . $id . '" data-sg-category-link="' . $id . '">' . $this->categoryIconSvg($icon) . '<span>' . self::e((string)($category['name'] ?? 'دسته')) . '</span></a>';
            }
            $html .= '</nav></div>';
        }

        $campaignHtml='';$featuredHtml='';$catalogHtml='';
        if ($campaigns !== []) {
            $campaignHtml .= '<section class="sg-marketing" aria-label="پیشنهاد این روزها">';
            foreach ($campaigns as $campaign) {
                if (!is_array($campaign)) continue;
                $headline = trim((string)($campaign['headline'] ?? $campaign['title'] ?? ''));
                if ($headline === '') continue;
                $source=trim((string)($campaign['cover_image_path']??''));$image=$this->mediaUrl($installationId,$source,$manifest,$mediaBase);$alt=$this->mediaAlt($source,$manifest,$headline);
                $campaignHtml .= '<article class="sg-promo-card'.($image!==''?' has-media':'').'">';
                if($image!=='')$campaignHtml.='<div class="sg-promo-media"><img class="'.self::e($this->mediaPositionClasses($source,$manifest)).'" src="'.self::e($image).'" loading="lazy" decoding="async" alt="'.self::e($alt).'"></div>';
                $campaignHtml.='<div class="sg-promo-copy"><span>پیشنهاد این روزها</span><h2>' . self::e($headline) . '</h2>';
                $body = trim((string)($campaign['body'] ?? ''));
                if ($body !== '') $campaignHtml .= '<p>' . self::e($body) . '</p>';
                $campaignHtml .= '</div></article>';
            }
            $campaignHtml .= '</section>';
        }

        if ($featuredItems !== []) {
            $featuredHtml .= '<section class="sg-featured" aria-labelledby="sgFeaturedTitle"><header><span>' . self::e($this->copy($copy,'featured_subtitle','انتخاب‌های ویژه کافه')) . '</span><strong id="sgFeaturedTitle">' . self::e($this->copy($copy,'featured_title','پیشنهاد امروز')) . '</strong></header><div class="sg-featured-rail-shell"><div class="sg-featured-rail" data-sg-rail="featured">';
            foreach ($featuredItems as $item) {
                $id=(int)($item['id']??0);$name=trim((string)($item['name']??''));$description=trim((string)($item['description']??''));$price=max(0,(int)($item['price']??0));$source=(string)($item['image_path']??'');$image=$this->mediaUrl($installationId,$source,$manifest,$mediaBase);$alt=$this->mediaAlt($source,$manifest,$name);$itemState=$clientItems[(string)$id]??[];$itemOrderAvailable=!empty($itemState['order_available']);$choiceComponents=array_values(array_filter((array)($itemState['bundle_components']??[]),static fn(array $c):bool=>(string)($c['mode']??'')==='choice'));$needsChoice=!empty($item['display_family'])||(!empty($itemState['is_bundle'])&&$choiceComponents!==[]);
                $featuredHtml .= '<article class="sg-featured-card' . (!empty($item['available']) ? '' : ' is-unavailable') . '" data-sg-featured-item data-item-id="' . $id . '"><button type="button" class="sg-featured-hit" data-sg-open-detail="' . $id . '" aria-label="دیدن جزئیات ' . self::e($name) . '"></button><div class="sg-featured-media">';
                if($image!=='')$featuredHtml.='<img class="'.self::e($this->mediaPositionClasses($source,$manifest)).'" src="'.self::e($image).'" loading="lazy" decoding="async" alt="'.self::e($alt).'">';else $featuredHtml.='<span class="sg-featured-placeholder" aria-hidden="true">س</span>';
                $featuredHtml .= '<span class="sg-featured-badge">' . self::e($this->copy($copy,'featured_badge','پیشنهاد کافه')) . '</span></div><div class="sg-featured-copy"><small>' . self::e((string)($item['category_name']??'')) . '</small><h3>'.self::e($name!==''?$name:'آیتم منو').'</h3>';
                if($description!==''&&self::lower($description)!==self::lower($name))$featuredHtml.='<p>'.self::e($description).'</p>';
                $featuredHtml.='<div class="sg-featured-meta"><strong>'.(!empty($item['display_family'])?'از '.self::money($price):self::money($price)).'</strong>';if(!empty($itemState['is_bundle']))$featuredHtml.='<span class="sg-bundle-badge">ترکیب</span>';
                if($orderUiEligible&&!empty($item['available'])){
                    $disabled=($canOrder&&$itemOrderAvailable)?'':' disabled';
                    if($needsChoice)$featuredHtml.='<button type="button" class="sg-choice-cta" data-sg-open-detail="'.$id.'"'.$disabled.'>انتخاب</button>';else $featuredHtml.='<div class="sg-item-order-controls" data-sg-item-controls="'.$id.'"><div class="sg-quantity" data-sg-quantity="'.$id.'"><button type="button" data-sg-qty-dec="'.$id.'" aria-label="کم کردن"'.$disabled.'>−</button><output data-sg-qty-value="'.$id.'">۰</output><button type="button" data-sg-qty-inc="'.$id.'" aria-label="افزودن"'.$disabled.'><span aria-hidden="true">+</span><span class="sg-add-label">افزودن</span></button></div></div>';
                }
                $featuredHtml.='</div></div></article>';
            }
            $featuredHtml .= '</div></div></section>';
        }

        if ($categories === []) {
            $catalogHtml .= '<section class="sg-empty" role="status"><h1>' . self::e($this->copy($copy,'empty_title','منو هنوز آیتمی ندارد')) . '</h1><p>' . self::e($this->copy($copy,'empty_body','لطفاً کمی بعد دوباره بررسی کنید.')) . '</p></section>';
        } else {
            $catalogHtml .= '<div class="sg-catalog" data-sg-catalog>';

            foreach ($categories as $category) {
                $categoryId = (int)($category['id'] ?? 0);
                $categoryName = (string)($category['name'] ?? 'دسته');
                $categorySource = (string)($category['image_path'] ?? '');
                $categoryImage = $this->mediaUrl($installationId,$categorySource,$manifest,$mediaBase);
                $categoryVisual = $categoryImage !== ''
                    ? '<img class="sg-category-media '.self::e($this->mediaPositionClasses($categorySource,$manifest)).'" src="'.self::e($categoryImage).'" loading="lazy" decoding="async" alt="">'
                    : $this->categoryIconSvg(self::categoryIcon((string)($category['icon_key'] ?? 'list')),'sg-category-heading-icon');
                $catalogHtml .= '<section class="sg-category" id="sg-category-' . $categoryId . '"><header><div class="sg-category-heading">'.$categoryVisual.'<h2>'
                    . self::e($categoryName) . '</h2></div><span>'
                    . self::faDigits((string)count((array)($category['items'] ?? []))) . ' انتخاب</span></header>';
                foreach ((array)($category['items'] ?? []) as $item) {
                    if (!is_array($item)) continue;
                    $id = (int)($item['id'] ?? 0);
                    $name = trim((string)($item['name'] ?? ''));
                    $description = trim((string)($item['description'] ?? ''));
                    if ($description !== '' && self::lower($description) === self::lower($name)) $description = '';
                    $price = max(0, (int)($item['price'] ?? 0));
                    $available = !empty($item['available']);
                    $search = trim($name . ' ' . (string)($category['name'] ?? '') . ' ' . $description);
                    $source = (string)($item['image_path'] ?? '');
                    $image = $this->mediaUrl($installationId, $source, $manifest, $mediaBase);
                    $imageAlt = $this->mediaAlt($source, $manifest, $name);

                    $itemState=$clientItems[(string)$id]??[];$itemOrderAvailable=!empty($itemState['order_available']);$choiceComponents=array_values(array_filter((array)($itemState['bundle_components']??[]),static fn(array $c):bool=>(string)($c['mode']??'')==='choice'));$needsChoice=!empty($item['display_family'])||(!empty($itemState['is_bundle'])&&$choiceComponents!==[]);
                    $catalogHtml .= '<article id="sg-item-' . $id . '" class="sg-item' . (!$available ? ' is-unavailable' : (!$itemOrderAvailable && $orderUiEligible ? ' is-service-paused' : '')) . '" data-sg-item data-item-id="' . $id . '" data-search="' . self::e(self::lower($search)) . '"><button type="button" class="sg-item-hit" data-sg-open-detail="' . $id . '" aria-label="دیدن جزئیات ' . self::e($name) . '"></button>';
                    if ($image !== '') {
                        $catalogHtml .= '<img class="sg-item-media ' . self::e($this->mediaPositionClasses($source,$manifest)) . '" src="' . self::e($image) . '" loading="lazy" decoding="async" alt="' . self::e($imageAlt) . '">';
                    } else {
                        $catalogHtml .= '<div class="sg-item-media sg-item-placeholder" aria-hidden="true">س</div>';
                    }
                    $catalogHtml .= '<div class="sg-item-copy"><div><h3>' . self::e($name !== '' ? $name : 'آیتم منو') . '</h3>';
                    if ($description !== '') $catalogHtml .= '<p>' . self::e($description) . '</p>';
                    $catalogHtml .= '</div><div class="sg-item-meta"><strong>' . (!empty($item['display_family'])?'از '.self::money($price):self::money($price)) . '</strong>';if(!empty($itemState['is_bundle']))$catalogHtml.='<span class="sg-bundle-badge">ترکیب</span>';

                    if ($orderUiEligible && $available) {
                        $disabled=($canOrder&&$itemOrderAvailable)?'':' disabled';
                        if($needsChoice)$catalogHtml .= '<div class="sg-item-order-controls" data-sg-item-controls="' . $id . '"><button type="button" class="sg-choice-cta" data-sg-open-detail="' . $id . '"' . $disabled . '>انتخاب</button>';else $catalogHtml .= '<div class="sg-item-order-controls" data-sg-item-controls="' . $id . '"><div class="sg-quantity" data-sg-quantity="' . $id . '" aria-label="تعداد ' . self::e($name) . '">'
                            . '<button type="button" data-sg-qty-dec="' . $id . '" aria-label="کم کردن"' . $disabled . '>−</button>'
                            . '<output data-sg-qty-value="' . $id . '">۰</output>'
                            . '<button type="button" data-sg-qty-inc="' . $id . '" data-item-price="' . $price . '" data-item-name="' . self::e($name) . '" aria-label="افزودن"' . $disabled . '><span aria-hidden="true">+</span><span class="sg-add-label">افزودن</span></button>'
                            . '</div>';
                        if(!$itemOrderAvailable)$catalogHtml.='<span class="sg-item-state" data-sg-item-state="' . $id . '">' . self::e((string)($itemState['unavailable_message']??$this->copy($copy,'ordering_disabled','این بخش فعلاً سفارش تازه نمی‌پذیرد'))) . '</span>';
                        $catalogHtml.='</div>';
                    } else {
                        if (!$available) {
                            $catalogHtml .= '<span class="sg-item-state is-unavailable-state" data-sg-item-state="' . $id . '">' . self::e($this->copy($copy,'unavailable','ناموجود')) . '</span>';
                        } elseif ($table !== null) {
                            $catalogHtml .= '<span class="sg-item-state" data-sg-item-state="' . $id . '">' . self::e($this->copy($copy,'ordering_disabled','سفارش‌گیری موقتاً غیرفعال است')) . '</span>';
                        }
                    }
                    $catalogHtml .= '</div></div></article>';
                }
                $catalogHtml .= '</section>';
            }
            $catalogHtml .= '</div>';
        }

        $moreHtml='';
        $accommodationEnabled=!array_key_exists('accommodation_enabled',$settings)||(bool)$settings['accommodation_enabled'];
        $accommodationHref=$accommodationSite!==''?$accommodationSite:($accommodationRooms!==''?$accommodationRooms:$accommodationTours);
        $aboutParams=['installation'=>$installationId];if($tableToken!=='')$aboutParams['table']=$tableToken;if($selectedMenuKey!=='')$aboutParams['menu']=$selectedMenuKey;$aboutHref=$this->u('/menu/about').'?'.http_build_query($aboutParams,'','&',PHP_QUERY_RFC3986);
        $hasFooterLinks=!empty($settings['public_about_enabled'])||(!empty($settings['social_footer_enabled'])&&($instagramCafe!==''||$instagramHouse!==''||$whatsappUrl!==''));
        if(($accommodationEnabled&&$accommodationHref!=='')||$hasFooterLinks){
            $moreHtml.='<section class="sg-more" aria-labelledby="sgMoreTitle"><header><h2 id="sgMoreTitle">'.self::e($this->copy($copy,'more_sokna_title','بیشتر از سکنا')).'</h2></header>';
            if($accommodationEnabled&&$accommodationHref!=='')$moreHtml.='<a class="sg-accommodation-card" href="'.self::e($accommodationHref).'" target="_blank" rel="noopener" data-sg-metric="accommodation_click"><span aria-hidden="true">⌂</span><div><strong>'.self::e($this->plainSetting($settings,'accommodation_card_title','خانه سکنا رو هم می‌شناسی؟',100)).'</strong><small>'.self::e($this->plainSetting($settings,'accommodation_card_text','اقامت، گشت‌وگذار و تجربه غرب هرمزگان',300)).'</small></div><b>دیدن سایت</b></a>';
            if($hasFooterLinks){$moreHtml.='<nav class="sg-menu-footer-links" aria-label="راه‌های ارتباط با سکنا">';if(!empty($settings['public_about_enabled']))$moreHtml.='<a href="'.self::e($aboutHref).'">درباره سکنا</a>';if(!empty($settings['social_footer_enabled'])){if($instagramCafe!=='')$moreHtml.='<a href="'.self::e($instagramCafe).'" target="_blank" rel="noopener" data-sg-metric="instagram_cafe_click">اینستاگرام کافه</a>';if($instagramHouse!=='')$moreHtml.='<a href="'.self::e($instagramHouse).'" target="_blank" rel="noopener" data-sg-metric="instagram_house_click">اینستاگرام اقامتگاه</a>';if($whatsappUrl!=='')$moreHtml.='<a href="'.self::e($whatsappUrl).'" target="_blank" rel="noopener" data-sg-metric="whatsapp_click">واتس‌اپ</a>';}$moreHtml.='</nav>';}$moreHtml.='</section>';
        }
        $sectionHtml=['campaigns'=>$campaignHtml,'featured'=>$featuredHtml,'catalog'=>$catalogHtml,'more'=>$moreHtml];
        foreach($composition as $slot)$html.=(string)($sectionHtml[$slot]??'');
        if($moreHtml!==''&&!in_array('more',$composition,true))$html.=$moreHtml;

        if($table===null)$html.='<p class="sg-scan-table">'.self::e($this->copy($copy,'scan_table','برای سفارش QR میز را اسکن کنید')).'</p>';
        $html .= '<div class="sg-no-results" data-sg-no-results hidden>' . self::e($this->copy($copy,'no_results','نتیجه‌ای پیدا نشد.')) . '</div></main>';

        if ($orderUiEligible || $waiterUiEligible) $html .= '<div class="sg-action-dock" aria-label="اقدام‌های سریع منو">';
        if ($waiterUiEligible) {
            $html .= '<button class="sg-waiter" type="button" data-sg-waiter' . (($waiterEndpoint !== '' && $waiterEnabled) ? '' : ' disabled') . ' aria-label="' . self::e($this->copy($copy,'call_waiter','فراخوان گارسون')) . '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg><span class="sg-visually-hidden" data-sg-waiter-label>' . self::e($this->copy($copy,'call_waiter','فراخوان گارسون')) . '</span></button>';
        }
        if ($orderUiEligible) {
            $endpointReady = $orderEndpoint !== '';
            $html .= '<aside class="sg-basket" aria-label="سبد سفارش" data-sg-basket hidden>'
                . '<button type="button" class="sg-basket-open" data-sg-open-cart><span><b data-sg-basket-count>۰</b> قلم</span><span class="sg-basket-total-wrap"><small>' . self::e($this->copy($copy,'basket_total','جمع سفارش')) . '</small><strong data-sg-basket-total>۰ تومان</strong></span></button>'
                . '<button type="button" data-sg-quick-submit' . (($endpointReady && $canOrder) ? '' : ' disabled') . '>' . self::e($this->copy($copy,'review_order','بررسی سفارش')) . '</button>'
                . '<p class="sg-inline-message" data-sg-order-message role="status" aria-live="polite"></p>'
                . '</aside>';
        }
        if ($orderUiEligible || $waiterUiEligible) $html .= '</div>';

        $html .= '<div class="sg-layer-backdrop" data-sg-backdrop hidden></div>';
        if ($orderUiEligible) {
            $html .= '<section class="sg-drawer" data-sg-cart-drawer role="dialog" aria-modal="true" aria-labelledby="sgCartTitle" hidden>'
                . '<header><div><small>سبد سفارش</small><h2 id="sgCartTitle">' . self::e($this->copy($copy,'cart_title','مرور سفارش')) . '</h2></div><button type="button" class="sg-icon-button" data-sg-close-layer aria-label="بستن">×</button></header>'
                . '<div class="sg-drawer-body"><div data-sg-cart-lines class="sg-cart-lines"></div>'
                . '<label class="sg-field"><span>یادداشت برای سفارش</span><textarea rows="2" maxlength="1000" data-sg-customer-note placeholder="' . self::e($this->copy($copy,'order_note_placeholder','چیزی هست که دوست داری بدونیم؟')) . '"></textarea></label>'
                . '<section class="sg-quote" data-sg-quote><div><span>جمع آیتم‌ها</span><strong data-sg-quote-subtotal>—</strong></div><div data-sg-quote-discount-row hidden><span>تخفیف</span><strong data-sg-quote-discount>—</strong></div><div data-sg-quote-tax-row hidden><span>مالیات</span><strong data-sg-quote-tax>—</strong></div><div class="sg-quote-total"><span>مبلغ نهایی</span><strong data-sg-quote-total>—</strong></div><small data-sg-quote-state>برای دیدن مبلغ نهایی، سبد را بررسی می‌کنیم.</small></section>'
                . '</div><footer><button type="button" class="sg-button sg-button--secondary" data-sg-close-layer>ادامه انتخاب</button><button type="button" class="sg-button sg-button--primary" data-sg-order-submit' . (($endpointReady && $canOrder) ? '' : ' disabled') . '>' . self::e($this->copy($copy,'submit_order','ثبت سفارش')) . '</button></footer></section>';
        }
        $html .= '<section class="sg-modal sg-item-detail-modal" data-sg-item-modal role="dialog" aria-modal="true" aria-labelledby="sgItemTitle" hidden><div class="sg-modal-card sg-item-detail-card"><span class="sg-item-detail-grip" aria-hidden="true"></span><button type="button" class="sg-icon-button sg-item-detail-close" data-sg-close-layer aria-label="بستن">×</button><div class="sg-item-detail-scroll"><div class="sg-item-detail-media" data-sg-detail-media></div><div class="sg-item-detail-copy"><h2 id="sgItemTitle" data-sg-detail-title></h2><strong data-sg-detail-price></strong><p data-sg-detail-description></p><div data-sg-detail-state class="sg-detail-state"></div><div class="sg-detail-options" data-sg-detail-options hidden></div><label class="sg-field" data-sg-detail-note-wrap><span>یادداشت این آیتم</span><input maxlength="300" data-sg-detail-note placeholder="' . self::e($this->copy($copy,'item_note_placeholder','مثلاً بدون شکر یا یخ کمتر')) . '"></label><div class="sg-suggestions" data-sg-suggestions hidden></div></div></div><footer class="sg-item-detail-footer"><div class="sg-detail-controls" data-sg-detail-controls><button type="button" data-sg-detail-dec>−</button><output data-sg-detail-qty>۱</output><button type="button" data-sg-detail-inc>+</button></div><button type="button" class="sg-button sg-button--primary sg-item-detail-primary" data-sg-detail-add>افزودن به سفارش</button></footer></div></section>';
        if ($table !== null) {
            $html .= '<section class="sg-modal" data-sg-orders-modal role="dialog" aria-modal="true" aria-labelledby="sgOrdersTitle" hidden><div class="sg-modal-card sg-orders-card"><header><div><small>این دستگاه</small><h2 id="sgOrdersTitle">سفارش‌های من</h2></div><button type="button" class="sg-icon-button" data-sg-close-layer aria-label="بستن">×</button></header><div class="sg-orders-state" data-sg-orders-state></div><div class="sg-orders-list" data-sg-orders-list></div></div></section>';
        }
        if ($table !== null && $waiterUiEligible) {
            $html .= '<section class="sg-modal sg-waiter-confirm-modal" data-sg-waiter-confirm-modal role="dialog" aria-modal="true" aria-labelledby="sgWaiterConfirmTitle" hidden><div class="sg-modal-card sg-waiter-confirm-card"><div class="sg-success-icon" aria-hidden="true">⌁</div><h2 id="sgWaiterConfirmTitle">'.self::e($this->copy($copy,'waiter_question','گارسون رو صدا کنیم؟')).'</h2><p>'.self::e($this->copy($copy,'waiter_explainer','فقط درخواست حضور گارسون فرستاده می‌شه.')).'</p><div class="sg-modal-actions"><button type="button" class="sg-button sg-button--primary" data-sg-waiter-confirm' . ($waiterEnabled ? '' : ' disabled') . '>آره، خبرش کن</button><button type="button" class="sg-button sg-button--secondary" data-sg-close-layer>نه، فعلاً</button></div></div></section>';
        }
        if ($table === null && $waiterUiEligible) {
            $html .= '<section class="sg-modal" data-sg-table-modal role="dialog" aria-modal="true" aria-labelledby="sgTableTitle" hidden><div class="sg-modal-card sg-public-table-card"><header><div><small>فراخوان گارسون</small><h2 id="sgTableTitle">'.self::e($this->copy($copy,'waiter_question','گارسون رو صدا کنیم؟')).'</h2><p>'.self::e($this->copy($copy,'waiter_explainer','فقط درخواست حضور گارسون فرستاده می‌شه.')).'</p></div><button type="button" class="sg-icon-button" data-sg-close-layer aria-label="بستن">×</button></header><div class="sg-public-table-grid" data-sg-public-table-grid></div><footer class="sg-public-table-actions"><span data-sg-public-table-selection>میزت را انتخاب کن</span><button type="button" class="sg-button sg-button--primary" data-sg-public-table-confirm disabled>خبرش کن</button></footer></div></section>';
        }
        if ($events !== []) {
            $html .= '<section class="sg-modal sg-events-modal" data-sg-events-modal role="dialog" aria-modal="true" aria-labelledby="sgEventsTitle" hidden><div class="sg-modal-card sg-events-card"><header><div><small>سکنا</small><h2 id="sgEventsTitle">رویدادها</h2></div><button type="button" class="sg-icon-button" data-sg-close-layer aria-label="بستن">×</button></header><div class="sg-events-list">';
            foreach ($events as $event) {
                if (!is_array($event)) continue;
                $title = trim((string)($event['title'] ?? ''));
                if ($title === '') continue;
                $desc = trim((string)($event['description'] ?? $event['short_description'] ?? ''));
                $starts = trim((string)($event['starts_at'] ?? ''));
                $ends = trim((string)($event['ends_at'] ?? ''));
                $location = trim((string)($event['location_label'] ?? $event['venue'] ?? ''));$capacity=max(0,(int)($event['capacity']??0));$source=trim((string)($event['cover_image_path']??''));$image=$this->mediaUrl($installationId,$source,$manifest,$mediaBase);$alt=$this->mediaAlt($source,$manifest,$title);
                $html .= '<article class="sg-event-entry'.($image!==''?' has-media':'').'">';
                if($image!=='')$html.='<div class="sg-event-media"><img class="'.self::e($this->mediaPositionClasses($source,$manifest)).'" src="'.self::e($image).'" loading="lazy" decoding="async" alt="'.self::e($alt).'"></div>';
                $html.='<div class="sg-event-copy"><span>رویداد</span><h3>' . self::e($title) . '</h3>';
                if ($desc !== '') $html .= '<p>' . self::e($desc) . '</p>';
                $when = self::eventWindowLabel($starts,$ends);$capacityLabel=$capacity>0?'ظرفیت: '.self::faDigits((string)$capacity).' نفر':'';
                $meta = array_values(array_filter([$when,$location,$capacityLabel], static fn(string $v): bool => $v !== ''));
                if ($meta !== []) $html .= '<small>' . self::e(implode(' · ', $meta)) . '</small>';
                $html .= '</div></article>';
            }
            $html .= '</div></div></section>';
        }
        if($canOrder){
            $postInstagram=!empty($settings['post_order_instagram_enabled'])&&$instagramCafe!=='';
            $html.='<section class="sg-modal sg-success-modal" data-sg-success-modal role="dialog" aria-modal="true" aria-labelledby="sgSuccessTitle" hidden><div class="sg-modal-card sg-success-card"><div class="sg-success-icon" aria-hidden="true">✓</div><small data-sg-success-code></small><h2 id="sgSuccessTitle">'.self::e($this->copy($copy,'order_received_title','سفارشت رسید 👌')).'</h2><div class="sg-success-progress" data-sg-success-progress data-status="new" aria-hidden="true"><i></i><i></i></div><div class="sg-order-live-status" data-sg-order-live-status aria-live="polite"><span class="sg-status-spinner" aria-hidden="true"></span><span data-sg-success-message></span></div>';
            if($postInstagram)$html.='<a class="sg-post-order-social" href="'.self::e($instagramCafe).'" target="_blank" rel="noopener" data-sg-metric="instagram_post_order_click">'.self::e($this->copy($copy,'post_order_social_cta','حال‌وهوای سکنا رو استوری کن و پیجمون رو منشن کن')).'</a>';
            $html.='<button type="button" class="sg-button sg-button--primary" data-sg-close-layer>ادامه دیدن منو</button></div></section>';
        }
        $html .= '<div class="sg-toast" data-sg-toast role="status" aria-live="polite" hidden></div>';

        $html .= '</div></body></html>';

        return [
            'status' => 200,
            'headers' => [
                'Content-Type' => 'text/html; charset=utf-8',
                'Cache-Control' => 'no-store, max-age=0',
            ],
            'body' => $html,
        ];
    }

    /** @return list<string> */
    private function composition(array $presentation): array
    {
        $theme=is_array($presentation['theme']??null)?$presentation['theme']:[];$format=(string)($theme['format']??'sokna-guest-theme-v1');
        $fallback=$format==='sokna-guest-theme-v2'?['campaigns','featured','catalog','more']:['campaigns','featured','catalog'];
        $raw=is_array($theme['composition']??null)?$theme['composition']:$fallback;$allowed=$format==='sokna-guest-theme-v2'?['campaigns','featured','catalog','more']:['campaigns','featured','catalog'];$out=[];
        foreach($raw as $slot){if(is_string($slot)&&in_array($slot,$allowed,true)&&!in_array($slot,$out,true))$out[]=$slot;}
        if($format==='sokna-guest-theme-v2'){foreach($allowed as $required)if(!in_array($required,$out,true))$out[]=$required;}elseif(!in_array('catalog',$out,true))$out[]='catalog';
        return $out;
    }

    private function themeBodyClasses(array $presentation): string
    {
        $theme=is_array($presentation['theme']??null)?$presentation['theme']:[];if((string)($theme['format']??'')!=='sokna-guest-theme-v2'||(string)($theme['engine_contract']??'')!=='sokna-guest-theme-engine-v2'||(int)($theme['contract_revision']??0)<1)return '';
        $variants=is_array($theme['variants']??null)?$theme['variants']:[];
        $allowed=[
            'header'=>['compact','brand-forward'],'category_nav'=>['pills','tabs'],'catalog'=>['adaptive','list','cards'],'item_media'=>['leading','top','hidden'],
            'featured'=>['rail','grid'],'campaigns'=>['cards','banner'],'surfaces'=>['outlined','soft','elevated'],'density'=>['compact','comfortable','airy'],
            'detail'=>['sheet','editorial'],'action_dock'=>['floating','attached'],'content_width'=>['narrow','standard','wide'],'typography'=>['compact','standard','large'],'motion'=>['subtle','none'],
        ];
        $classes=['sg-theme-v2'];foreach($allowed as $key=>$values){$value=trim((string)($variants[$key]??''));if(in_array($value,$values,true))$classes[]='sg-v-'.str_replace('_','-',$key).'-'.$value;}
        return implode(' ',$classes);
    }

    private function menus(array $snapshot): array
    {
        $rawCatalogs = is_array($snapshot['catalogs'] ?? null) ? $snapshot['catalogs'] : [];
        $catalogs = array_filter($rawCatalogs, 'is_array');
        $menus = is_array($snapshot['menus'] ?? null) ? $snapshot['menus'] : [];
        if ($menus !== []) {
            $result = [];
            foreach ($menus as $menu) {
                if (!is_array($menu)) continue;
                $key = trim((string)($menu['menu_key'] ?? ''));
                if ($key === '') continue;
                // When catalog projections exist, a stale menu metadata row must not create
                // a selectable route that resolves to an empty catalog. Keep legacy/root-only
                // snapshots compatible by filtering only when a catalog map is actually present.
                if ($catalogs !== [] && !array_key_exists($key, $catalogs)) continue;
                $result[] = $menu;
            }
            if ($result !== [] || $catalogs === []) return array_values($result);
        }

        $result = [];
        foreach ($catalogs as $key => $catalog) {
            $menu = is_array($catalog['menu'] ?? null) ? $catalog['menu'] : [];
            $result[] = [
                'menu_key' => (string)($menu['menu_key'] ?? $key),
                'name' => (string)($menu['name'] ?? 'منو'),
                'sort_order' => (int)($menu['sort_order'] ?? 0),
            ];
        }
        return $result;
    }

    private function catalog(array $snapshot, string $requested): array
    {
        $rawCatalogs = is_array($snapshot['catalogs'] ?? null) ? $snapshot['catalogs'] : [];
        $catalogs = array_filter($rawCatalogs, 'is_array');
        if ($catalogs !== []) {
            // Query selection is advisory. Resolve it only to a catalog that really exists;
            // otherwise fall back to the first valid projected menu/catalog instead of
            // rendering a false zero-item state from stale menu metadata.
            $key = '';
            if ($requested !== '' && array_key_exists($requested, $catalogs)) $key = $requested;
            if ($key === '') {
                foreach ($this->menus($snapshot) as $menu) {
                    $candidate = trim((string)($menu['menu_key'] ?? ''));
                    if ($candidate !== '' && array_key_exists($candidate, $catalogs)) {
                        $key = $candidate;
                        break;
                    }
                }
            }
            if ($key === '') $key = (string)(array_key_first($catalogs) ?? '');
            $catalog = is_array($catalogs[$key] ?? null) ? $catalogs[$key] : [];
            $catalog['menu_key'] = $key;
            return $catalog;
        }

        return [
            'menu_key' => 'main',
            'categories' => is_array($snapshot['categories'] ?? null) ? $snapshot['categories'] : [],
            'items' => is_array($snapshot['items'] ?? null) ? $snapshot['items'] : [],
        ];
    }

    private function categoriesWithItems(array $catalog, array $availability): array
    {
        $availabilityItems = is_array($availability['items'] ?? null) ? $availability['items'] : [];
        $categories = [];
        foreach ((array)($catalog['categories'] ?? []) as $raw) {
            if (!is_array($raw)) continue;$id=(int)($raw['id']??0);$raw['id']=$id;$raw['items']=[];$categories[$id]=$raw;
        }
        $all=array_values(array_filter((array)($catalog['items']??[]),'is_array'));$byId=[];foreach($all as $r){$id=(int)($r['id']??0);if($id>0)$byId[$id]=$r;}
        $itemAvailable=static function(array $row)use($availabilityItems,$byId):bool{
            $id=(int)($row['id']??0);$live=is_array($availabilityItems[(string)$id]??null)?$availabilityItems[(string)$id]:[];$available=array_key_exists('available',$live)?(bool)$live['available']:!empty($row['available']);if(!$available)return false;
            $bundle=is_array($row['bundle']??null)?$row['bundle']:null;if($bundle===null)return true;$hasChoice=false;$choiceAvailable=false;
            foreach((array)($bundle['components']??[]) as $component){if(!is_array($component))continue;$cid=(int)($component['item_id']??0);$cr=is_array($byId[$cid]??null)?$byId[$cid]:[];$clive=is_array($availabilityItems[(string)$cid]??null)?$availabilityItems[(string)$cid]:[];$ok=$cr!==[]&&(array_key_exists('available',$clive)?(bool)$clive['available']:!empty($cr['available']));if((string)($component['mode']??'fixed')==='choice'){$hasChoice=true;if($ok)$choiceAvailable=true;}elseif(!$ok)return false;}
            return !$hasChoice||$choiceAvailable;
        };
        $seenFamilies=[];
        foreach($all as $raw){
            $id=(int)($raw['id']??0);$categoryId=(int)($raw['category_id']??0);$raw['available']=$itemAvailable($raw);$raw['id']=$id;$raw['category_id']=$categoryId;
            $familyId=(int)($raw['variant_family_id']??0);
            if($familyId>0){
                if(isset($seenFamilies[$familyId]))continue;$seenFamilies[$familyId]=true;$members=[];$prices=[];$familyAvailable=false;$labels=[];
                foreach((array)($raw['variant_members']??[]) as $m){if(!is_array($m))continue;$mid=(int)($m['item_id']??0);$member=is_array($byId[$mid]??null)?$byId[$mid]:[];if($member===[])continue;$ma=$itemAvailable($member);$members[]=['item_id'=>$mid,'label'=>(string)($m['label']??$member['variant_label']??''),'available'=>$ma,'price'=>(int)($member['price']??0)];$labels[]=(string)($m['label']??'');$prices[]=(int)($member['price']??0);if($ma)$familyAvailable=true;}
                if($members===[])continue;$raw['name']=(string)($raw['variant_family_name']??$raw['name']??'');$fd=trim((string)($raw['variant_family_description']??''));if($fd!=='')$raw['description']=$fd;$raw['price']=$prices===[]?(int)($raw['price']??0):min($prices);$raw['available']=$familyAvailable;$raw['display_family']=true;$raw['family_members']=$members;$raw['family_labels']=array_values(array_filter($labels,static fn(string $v):bool=>$v!==''));$raw['featured']=!empty($raw['variant_family_featured'])||!empty($raw['featured']);
            }
            if(!isset($categories[$categoryId]))$categories[$categoryId]=['id'=>$categoryId,'name'=>(string)($raw['category_name']??'سایر'),'items'=>[]];$categories[$categoryId]['items'][]=$raw;
        }
        return array_values(array_filter($categories,static fn(array $c):bool=>(array)($c['items']??[])!==[]));
    }

    private function orderAcceptance(array $availability): bool
    {
        $acceptance = $availability['order_acceptance'] ?? null;
        if (is_bool($acceptance)) return $acceptance;
        if (!is_array($acceptance)) return false;
        if (array_key_exists('cafe', $acceptance)) return (bool)$acceptance['cafe'];
        if (array_key_exists('enabled', $acceptance)) return (bool)$acceptance['enabled'];
        return false;
    }

    private function projectedSession(array $availability, int $tableId): ?array
    {
        $tables = is_array($availability['tables'] ?? null) ? $availability['tables'] : [];
        $row = is_array($tables[(string)$tableId] ?? null) ? $tables[(string)$tableId] : [];
        $session = $row['session'] ?? null;
        return is_array($session) ? $session : null;
    }

    private function findTable(array $snapshot, string $token): ?array
    {
        foreach ((array)($snapshot['tables'] ?? []) as $table) {
            if (is_array($table) && isset($table['token']) && hash_equals((string)$table['token'], $token)) return $table;
        }
        return null;
    }

    /** @return array<string,string> */
    private function identityAssets(string $installationId,array $snapshot,array $manifest,string $mediaBase): array
    {
        $identity=is_array($snapshot['identity']??null)?$snapshot['identity']:[];$active=!empty($identity['active']);$version=$active?trim((string)($identity['version']??'')):'fallback';if($version==='')$version='fallback';$variants=is_array($identity['variants']??null)?$identity['variants']:[];
        $fallback=['logo-256'=>$this->u('/assets/brand/Sokna-AppIcon-256.png'),'icon-16'=>$this->u('/assets/favicon-16.png'),'icon-32'=>$this->u('/assets/favicon-32.png'),'icon-48'=>$this->u('/assets/favicon-32.png'),'icon-180'=>$this->u('/assets/favicon-180.png'),'icon-192'=>$this->u('/assets/favicon-192.png'),'icon-512'=>$this->u('/assets/brand/Sokna-AppIcon-512.png')];
        if(!$active||$mediaBase==='')return $fallback+['version'=>'fallback'];$out=$fallback;
        foreach(array_keys($fallback) as $key){$meta=is_array($variants[$key]??null)?$variants[$key]:[];$source=trim((string)($meta['source']??('identity:'.$key)));$url=$this->mediaUrl($installationId,$source,$manifest,$mediaBase);if($url!=='')$out[$key]=$url;}
        $out['version']=$version;return $out;
    }

    /** @return array{x:float,y:float} */
    private function mediaFocal(string $source,array $manifest): array
    {
        $meta=is_array($manifest[$source]??null)?$manifest[$source]:[];$x=(float)($meta['focal_x']??0.5);$y=(float)($meta['focal_y']??0.5);if(!is_finite($x))$x=0.5;if(!is_finite($y))$y=0.5;return ['x'=>max(0.0,min(1.0,$x)),'y'=>max(0.0,min(1.0,$y))];
    }

    private function mediaPositionClasses(string $source,array $manifest): string
    {
        $f=$this->mediaFocal($source,$manifest);$x=(int)round($f['x']*100);$y=(int)round($f['y']*100);return 'sg-focal-x-'.$x.' sg-focal-y-'.$y;
    }

    private function mediaAlt(string $source,array $manifest,string $fallback): string
    {
        $meta=is_array($manifest[$source]??null)?$manifest[$source]:[];
        $alt=trim((string)($meta['alt_text']??''));
        if($alt===''||str_contains($alt,'<')||str_contains($alt,'>'))return $fallback;
        return function_exists('mb_substr')?mb_substr($alt,0,180,'UTF-8'):substr($alt,0,180);
    }

    private function mediaUrl(string $installationId, string $source, array $manifest, string $mediaBase): string
    {
        if ($source === '' || $mediaBase === '') return '';
        $meta = is_array($manifest[$source] ?? null) ? $manifest[$source] : [];
        $sha = strtolower((string)($meta['sha256'] ?? ''));
        $ext = strtolower((string)($meta['extension'] ?? ''));
        if (preg_match('/^[a-f0-9]{64}$/', $sha) !== 1 || preg_match('/^(jpg|png|webp|gif)$/', $ext) !== 1) return '';
        return rtrim($mediaBase, '/') . '/' . rawurlencode($installationId) . '/' . $sha . '.' . $ext;
    }

    private function statePage(int $status, string $title, string $message, bool $noIndex, array $endpoints): array
    {
        $cssUrl = $this->safeAssetUrl((string)($endpoints['css'] ?? '/assets/scds/guest.css'));
        $html = '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
            . ($noIndex ? '<meta name="robots" content="noindex,nofollow,noarchive">' : '')
            . '<title>' . self::e($title) . ' | سکنا</title>'
            . '<link rel="icon" type="image/png" sizes="32x32" href="' . self::e($this->u('/assets/favicon-32.png')) . '">'
            . '<link rel="apple-touch-icon" sizes="180x180" href="' . self::e($this->u('/assets/favicon-180.png')) . '">'
            . '<link rel="stylesheet" href="' . self::e($cssUrl) . '"></head><body class="sg-body">'
            . '<main class="sg-system-state"><span class="sg-system-code">' . self::faDigits((string)$status) . '</span>'
            . '<h1>' . self::e($title) . '</h1><p>' . self::e($message) . '</p></main></body></html>';
        return [
            'status' => $status,
            'headers' => [
                'Content-Type' => 'text/html; charset=utf-8',
                'Cache-Control' => 'no-store, max-age=0',
            ],
            'body' => $html,
        ];
    }

    private function copy(array $copy,string $key,string $fallback,int $max=220): string
    {
        $value=trim((string)($copy[$key]??''));
        if($value===''||str_contains($value,'<')||str_contains($value,'>'))return $fallback;
        return function_exists('mb_substr')?mb_substr($value,0,$max,'UTF-8'):substr($value,0,$max);
    }

    private function plainSetting(array $settings,string $key,string $fallback,int $max=500): string
    {
        $value=trim((string)($settings[$key]??''));if($value==='')return $fallback;if(str_contains($value,'<')||str_contains($value,'>'))return $fallback;return function_exists('mb_substr')?mb_substr($value,0,$max,'UTF-8'):substr($value,0,$max);
    }

    private function safeExternalUrl(string $value): string
    {
        $value=trim($value);if($value===''||preg_match('#^https?://#i',$value)!==1)return '';return filter_var($value,FILTER_VALIDATE_URL)!==false?$value:'';
    }

    private function safeAssetUrl(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';
        if (str_starts_with($value, '/') || preg_match('#^https://#i', $value) === 1) return $value;
        return '';
    }

    private static function categoryIcon(string $key): string
    {
        $key=trim($key);
        return preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/D',$key)===1?$key:'list';
    }

    private function categoryIconSvg(string $key,string $class='sg-category-icon'): string
    {
        $key=self::categoryIcon($key);
        return '<svg class="'.self::e($class).'" viewBox="0 0 24 24" aria-hidden="true"><use href="'.self::e($this->u('/assets/category-icons.svg')).'#icon-'.self::e($key).'"></use></svg>';
    }

    private function base(): string
    {
        $b='/' . trim(str_replace('\\','/',$this->basePath),'/');return $b==='/'?'':rtrim($b,'/');
    }

    private function u(string $path): string
    {
        return $this->base().'/'.ltrim($path,'/');
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private static function money(int $amount): string
    {
        return self::faDigits(str_replace(',', '٬', number_format($amount))) . ' تومان';
    }

    private static function eventWindowLabel(string $starts,string $ends=''): string
    {
        $start=self::compactEventDateTime($starts);if($start==='')return '';
        $end=self::compactEventDateTime($ends);if($end==='')return $start;
        $startDate=self::compactEventDate($starts);$endDate=self::compactEventDate($ends);
        if($startDate!==''&&$startDate===$endDate){
            $endTime=self::compactEventTime($ends);return $endTime!==''?$start.' تا '.$endTime:$start;
        }
        return $start.' تا '.$end;
    }

    private static function compactEventDateTime(string $value): string
    {return trim($value)===''?'':\Sokna\Shared\IranTime::display($value,'UTC');}
    private static function compactEventDate(string $value): string
    {try{return \Sokna\Shared\IranTime::parse($value,'UTC')->setTimezone(new \DateTimeZone('Asia/Tehran'))->format('Y-m-d');}catch(\Throwable){return '';}}
    private static function compactEventTime(string $value): string
    {try{return self::faDigits(\Sokna\Shared\IranTime::parse($value,'UTC')->setTimezone(new \DateTimeZone('Asia/Tehran'))->format('H:i'));}catch(\Throwable){return '';}}

    private static function syncAgeLabel(string $value): string
    {
        $value=trim($value);
        if($value==='')return '';
        $epoch=preg_match('/(?:Z|[+\-]\d{2}:?\d{2})$/i',$value)===1?(strtotime($value)?:0):(\Sokna\Shared\IranTime::epoch($value,'UTC')?:0);
        if($epoch<=0)return '';
        $age=max(0,time()-$epoch);
        if($age<60)return 'آخرین همگام‌سازی: کمتر از یک دقیقه پیش';
        if($age<3600)return 'آخرین همگام‌سازی: '.self::faDigits((string)max(1,(int)floor($age/60))).' دقیقه پیش';
        if($age<86400)return 'آخرین همگام‌سازی: '.self::faDigits((string)max(1,(int)floor($age/3600))).' ساعت پیش';
        return 'آخرین همگام‌سازی: '.self::faDigits((string)max(1,(int)floor($age/86400))).' روز پیش';
    }

    private static function faDigits(string $value): string
    {
        return strtr($value, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
    }
}
