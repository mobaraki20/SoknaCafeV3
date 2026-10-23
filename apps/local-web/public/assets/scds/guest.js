(() => {
  'use strict';

  const root = document.querySelector('[data-sg-app]');
  if (!root) return;

  const q = (selector, scope = root) => scope?.querySelector?.(selector) ?? null;
  const qa = (selector, scope = root) => Array.from(scope?.querySelectorAll?.(selector) ?? []);
  const faDigits = (value) => String(value).replace(/[0-9]/g, (digit) => '۰۱۲۳۴۵۶۷۸۹'[Number(digit)]);
  const numberFa = (value) => new Intl.NumberFormat('fa-IR').format(Number(value) || 0);
  const formatMoney = (value) => `${numberFa(Math.max(0, Number(value) || 0))} تومان`;
  const normalize = (value) => String(value || '').trim().toLocaleLowerCase('fa-IR');
  const clampInt = (value, min, max) => Math.max(min, Math.min(max, Math.trunc(Number(value) || 0)));
  const sleep = (ms) => new Promise((resolve) => globalThis.setTimeout(resolve, ms));
  const randomToken = () => (globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random()}-${Math.random()}`).replace(/[^A-Za-z0-9._:-]/g, '');
  const jsonScript = (selector, fallback) => {
    try { return JSON.parse(q(selector)?.textContent || '') ?? fallback; } catch (_) { return fallback; }
  };

  const mediaPlaceholder = (className = 'sg-item-placeholder') => {
    const ph = document.createElement('span');
    ph.className = className;
    ph.textContent = 'س';
    ph.setAttribute('aria-hidden', 'true');
    return ph;
  };
  const replaceBrokenMedia = (img) => {
    if (!img || img.dataset.sgMediaFallback === '1') return;
    img.dataset.sgMediaFallback = '1';
    const isCategory = img.classList.contains('sg-category-media');
    const isFeatured = Boolean(img.closest('.sg-featured-media'));
    const ph = mediaPlaceholder(isCategory ? 'sg-category-fallback' : (isFeatured ? 'sg-featured-placeholder' : 'sg-item-placeholder'));
    img.replaceWith(ph);
  };
  const bindMediaFallbacks = (scope = root) => {
    qa('img.sg-item-media, img.sg-category-media, .sg-featured-media img', scope).forEach((img) => {
      if (img.dataset.sgMediaBound === '1') return;
      img.dataset.sgMediaBound = '1';
      img.addEventListener('error', () => replaceBrokenMedia(img), {once:true});
      if (img.complete && img.naturalWidth === 0) replaceBrokenMedia(img);
    });
  };

  const installationId = root.dataset.installationId || '';
  const transportMode = root.dataset.transportMode || 'public_edge';
  const localLanTransport = transportMode === 'local_lan';
  const tableToken = root.dataset.tableToken || '';
  const endpoints = {
    order: root.dataset.orderEndpoint || '',
    orders: root.dataset.ordersEndpoint || '',
    quote: root.dataset.quoteEndpoint || '',
    status: root.dataset.statusEndpoint || '',
    context: root.dataset.contextEndpoint || '',
    waiter: root.dataset.waiterEndpoint || '',
    changes: root.dataset.changesEndpoint || '',
    metric: root.dataset.metricEndpoint || '',
  };
  const analyticsEnabled = root.dataset.analyticsEnabled === '1';
  const metric = (event, meta = {}) => {
    if (!analyticsEnabled || !endpoints.metric || !event) return;
    postJson(endpoints.metric, {event:String(event),meta}, 5000).catch(() => {});
  };
  let sessionToken = root.dataset.sessionToken || '';
  let orderingEnabled = root.dataset.orderingEnabled === '1';
  let connectivityState = root.dataset.connectivityState || (orderingEnabled ? 'fresh' : 'stale');
  let lastSyncAt = root.dataset.lastSyncAt || '';
  const unsafeAllowed = () => connectivityState === 'fresh';
  const parseSyncEpoch = (value) => {
    const raw=String(value||'').trim(); if(!raw)return 0;
    const normalized=/(?:Z|[+\-]\d{2}:?\d{2})$/i.test(raw)?raw:`${raw.replace(' ','T')}Z`;
    const epoch=Date.parse(normalized); return Number.isFinite(epoch)?epoch:0;
  };
  const syncAgeLabel = (value) => {
    const epoch=parseSyncEpoch(value); if(!epoch)return '';
    const age=Math.max(0,Date.now()-epoch);
    if(age<60000)return 'آخرین همگام‌سازی: کمتر از یک دقیقه پیش';
    if(age<3600000)return `آخرین همگام‌سازی: ${numberFa(Math.max(1,Math.floor(age/60000)))} دقیقه پیش`;
    if(age<86400000)return `آخرین همگام‌سازی: ${numberFa(Math.max(1,Math.floor(age/3600000)))} ساعت پیش`;
    return `آخرین همگام‌سازی: ${numberFa(Math.max(1,Math.floor(age/86400000)))} روز پیش`;
  };
  const catalogRows = jsonScript('[data-sg-items-json]', []);
  const publicTables = jsonScript('[data-sg-public-tables-json]', []);
  const itemMap = new Map(catalogRows.map((item) => [Number(item.id), {...item}]));
  const cart = new Map();
  let editingOrder = null;
  let quoteSeq = 0;
  let currentDetailId = 0;
  let detailSelectedItemId = 0;
  let detailBundleChoiceItemId = 0;
  let detailDraftUnitPrice = 0;
  let detailDraftQty = 1;
  let currentLayer = null;
  let layerTrigger = null;
  let layerCloseHandler = null;
  let layerInertState = [];
  let nestedLayerParent = null;
  let waiterState = null;
  let toastTimer = null;
  let contextTimer = null;
  let orderPollTimer = null;
  let submissionPollTimer = null;
  let submissionPollGeneration = 0;
  let submissionPollInFlight = false;
  let submissionPollFailures = 0;
  let waiterPollTimer = null;

  const stableDeviceToken = () => {
    const key = 'sokna.guest.device.v1';
    try {
      const existing = localStorage.getItem(key);
      if (existing && existing.length >= 16) return existing;
      const generated = randomToken();
      localStorage.setItem(key, generated);
      return generated;
    } catch (_) { return randomToken(); }
  };
  const deviceToken = stableDeviceToken();
  const guestDraftKey=tableToken?`sokna.guest.draft.v1.${tableToken}`:'';
  const guestDraftMaxAgeMs=6*60*60*1000;
  let guestDraftRestoreDone=false;
  const clearGuestDraft=()=>{if(!guestDraftKey)return;try{localStorage.removeItem(guestDraftKey);}catch(_){}try{sessionStorage.removeItem(guestDraftKey);}catch(_){}};
  const saveGuestDraft=()=>{if(!guestDraftKey||!sessionToken)return false;const lines=[...cart.values()].filter(line=>Number(line?.quantity||0)>0).map(line=>({id:Number(line.id||0),quantity:Number(line.quantity||0),takeaway_quantity:Number(line.takeaway_quantity||0),note:String(line.note||''),bundle_choice_item_id:Number(line.bundle_choice_item_id||0),unit_price:Number(line.unit_price||0),snapshot_from_order:line.snapshot_from_order===true,original_quantity:Number(line.original_quantity||line.quantity||0)}));const note=String(customerNote?.value||'');if(!lines.length&&!note.trim()){clearGuestDraft();return true;}const now=Date.now(),value={v:1,table_token:tableToken,session_token:String(sessionToken),device_token:String(deviceToken),updated_at:now,expires_at:now+guestDraftMaxAgeMs,customer_note:note,editing_order:editingOrder?{order_code:String(editingOrder.order_code||''),edit_signature:String(editingOrder.edit_signature||''),order_number:Number(editingOrder.order_number||0)}:null,lines};const encoded=JSON.stringify(value);try{localStorage.setItem(guestDraftKey,encoded);try{sessionStorage.removeItem(guestDraftKey);}catch(_){}return true;}catch(_){try{sessionStorage.setItem(guestDraftKey,encoded);return true;}catch(__){return false;}}};
  const loadGuestDraft=()=>{if(!guestDraftKey||!sessionToken)return null;let value=null;try{value=JSON.parse(localStorage.getItem(guestDraftKey)||'null');if(!value){value=JSON.parse(sessionStorage.getItem(guestDraftKey)||'null');if(value){try{localStorage.setItem(guestDraftKey,JSON.stringify(value));}catch(_){}}}}catch(_){try{value=JSON.parse(sessionStorage.getItem(guestDraftKey)||'null')}catch(__){value=null}}if(!value||typeof value!=='object')return null;const now=Date.now(),valid=value.v===1&&String(value.table_token||'')===String(tableToken)&&String(value.session_token||'')===String(sessionToken)&&String(value.device_token||'')===String(deviceToken)&&Number(value.expires_at||0)>now&&Array.isArray(value.lines);if(!valid){clearGuestDraft();return null;}return value;};
  const restoreGuestDraft=()=>{if(guestDraftRestoreDone||!sessionToken||loadPendingSubmission())return false;const saved=loadGuestDraft();guestDraftRestoreDone=true;if(!saved)return false;cart.clear();for(const row of saved.lines){const id=Number(row?.id||0),item=itemMap.get(id);if(!id||!item||Number(row.quantity||0)<=0)continue;const choice=Number(row.bundle_choice_item_id||0),key=cartLineKey(id,choice),qty=clampInt(row.quantity,1,99);cart.set(key,{id,quantity:qty,takeaway_quantity:clampInt(row.takeaway_quantity||0,0,qty),note:String(row.note||'').slice(0,300),bundle_choice_item_id:choice,unit_price:Number(row.unit_price||item.price||0),snapshot_from_order:row.snapshot_from_order===true,original_quantity:Math.max(0,Number(row.original_quantity||qty))});}if(customerNote)customerNote.value=String(saved.customer_note||'').slice(0,500);const edit=saved.editing_order&&typeof saved.editing_order==='object'?saved.editing_order:null;editingOrder=edit&&String(edit.order_code||'')?{order_code:String(edit.order_code),edit_signature:String(edit.edit_signature||''),order_number:Number(edit.order_number||0),items:[],customer_note:String(saved.customer_note||'')}:null;renderCart();if(cart.size||String(saved.customer_note||'').trim())toast('پیش‌نویس ارسال‌نشده بازیابی شد؛ قبل از ارسال بررسی‌اش کن.');return true;};

  const operationTokenMaxAgeMs=30*60*1000;
  const operationToken = (key, signature) => {
    let saved=null;
    try{saved=JSON.parse(localStorage.getItem(key)||'null');}catch(_){}
    if(!saved){try{saved=JSON.parse(sessionStorage.getItem(key)||'null');if(saved){try{localStorage.setItem(key,JSON.stringify(saved));}catch(_){}}}catch(_){saved=null;}}
    const fresh=saved&&saved.signature===signature&&typeof saved.token==='string'&&saved.token.length>=16&&Date.now()-Number(saved.created_at||0)<=operationTokenMaxAgeMs;
    if(fresh)return saved.token;
    const token=randomToken(),value=JSON.stringify({token,signature,created_at:Date.now()});
    try{localStorage.setItem(key,value);try{sessionStorage.removeItem(key);}catch(_){}return token;}catch(_){try{sessionStorage.setItem(key,value);return token;}catch(__){return token;}}
  };
  const clearOperationToken = (key) => {try{localStorage.removeItem(key);}catch(_){}try{sessionStorage.removeItem(key);}catch(_){} };

  const postJson = async (url, payload, timeoutMs = 15000, options = {}) => {
    if (!url) throw new Error('این قابلیت هنوز به سرویس متصل نشده است.');
    const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
    const externalSignal = options?.signal || null;
    let forwardAbort = null;
    if (controller && externalSignal) {
      forwardAbort = () => controller.abort(externalSignal.reason || 'cancelled');
      if (externalSignal.aborted) forwardAbort(); else externalSignal.addEventListener('abort', forwardAbort, {once:true});
    }
    const timer = controller ? globalThis.setTimeout(() => controller.abort('deadline'), timeoutMs) : null;
    try {
      let response;
      try {
        response = await fetch(url, {
          method: 'POST',
          headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
          credentials: 'same-origin',
          cache: 'no-store',
          body: JSON.stringify({installation_id: installationId, ...payload}),
          ...(controller ? {signal: controller.signal} : {}),
        });
      } catch (error) {
        if (controller?.signal?.aborted) { error.code = 'request_timeout'; error.unknown_outcome = true; }
        else { error.code = error?.code || 'request_failed'; error.unknown_outcome = true; }
        throw error;
      }
      let body = {}, parseError = false;
      try { body = await response.json(); } catch (_) { parseError = true; }
      return {response, body, parseError};
    } finally {
      if (timer) globalThis.clearTimeout(timer);
      if (externalSignal && forwardAbort) externalSignal.removeEventListener('abort', forwardAbort);
    }
  };

  const messageOf = (body, fallback) => typeof body?.message === 'string' && body.message.trim() !== '' ? body.message : fallback;
  const apiOk = ({response, body, parseError = false}, fallback) => {
    if (parseError || !response.ok || body.success !== true) {
      const err = new Error(parseError ? 'پاسخ سرور کامل دریافت نشد؛ نتیجه همان درخواست باید پیگیری شود.' : messageOf(body, fallback));
      err.code = String(parseError ? 'invalid_response' : (body?.code || 'request_failed'));
      err.body = body;
      err.status = response.status;
      err.unknown_outcome = parseError || response.status >= 500 || [408,425,429].includes(response.status);
      throw err;
    }
    return body;
  };
  const definitiveClientFailure = (error) => {
    const status = Number(error?.status || 0);
    return !error?.unknown_outcome && status >= 400 && status < 500 && ![408,425,429].includes(status);
  };

  const toast = (message, bad = false) => {
    const el = q('[data-sg-toast]');
    if (!el || !message) return;
    el.textContent = message;
    el.classList.toggle('is-error', bad);
    el.hidden = false;
    if (toastTimer) globalThis.clearTimeout(toastTimer);
    toastTimer = globalThis.setTimeout(() => { el.hidden = true; }, bad ? 5500 : 3200);
  };

  const backdrop = q('[data-sg-backdrop]');
  const layers = qa('[data-sg-cart-drawer],[data-sg-item-modal],[data-sg-orders-modal],[data-sg-table-modal],[data-sg-events-modal],[data-sg-success-modal],[data-sg-waiter-confirm-modal]');
  const layerFocusable='a[href],button:not([disabled]),input:not([type="hidden"]):not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
  const setLayerBackgroundInert = (layer, active) => {
    if (active) {
      if (layerInertState.length) return;
      let current=layer;
      while(current&&current!==document.body){
        const parent=current.parentElement;if(!parent)break;
        for(const sibling of parent.children){
          if(sibling===current||sibling===backdrop)continue;
          const had=sibling.hasAttribute('inert');layerInertState.push([sibling,had]);if(!had)sibling.setAttribute('inert','');
        }
        current=parent;
      }
      return;
    }
    for(const [node,had] of layerInertState){if(node?.isConnected&&!had)node.removeAttribute('inert')}
    layerInertState=[];
  };
  const visibleLayerFocusables = () => currentLayer ? qa(layerFocusable,currentLayer).filter((node)=>!node.hidden&&node.getAttribute('aria-hidden')!=='true'&&node.getClientRects().length>0) : [];
  const closeLayer = (result = 'dismiss') => {
    if (!currentLayer) return;
    const closing=currentLayer,trigger=layerTrigger,onClose=layerCloseHandler;
    closing.hidden = true;
    closing.setAttribute('aria-hidden', 'true');
    if (nestedLayerParent && nestedLayerParent.child === closing) {
      closing.setAttribute('inert', '');
      const parent=nestedLayerParent;
      nestedLayerParent=null;
      currentLayer=parent.layer;
      layerTrigger=parent.trigger;
      layerCloseHandler=parent.onClose;
      if (!parent.hadInert) parent.layer.removeAttribute('inert');
      parent.layer.hidden=parent.wasHidden;
      parent.layer.setAttribute('aria-hidden', 'false');
      requestAnimationFrame(() => {
        const target=parent.returnFocus?.isConnected?parent.returnFocus:q(layerFocusable,parent.layer)||parent.layer;
        target?.focus?.({preventScroll:true});
      });
      if(typeof onClose==='function')onClose(result);
      return;
    }
    backdrop && (backdrop.hidden = true);
    setLayerBackgroundInert(closing,false);
    document.documentElement.classList.remove('sg-layer-open');
    currentLayer = null;
    layerTrigger = null;
    layerCloseHandler = null;
    if (trigger?.isConnected) trigger.focus?.({preventScroll: true});
    if(typeof onClose==='function')onClose(result);
  };
  const openLayer = (layer, trigger = null, {onClose=null,nested=false} = {}) => {
    if (!layer) return;
    const opener=trigger||document.activeElement;
    if (nested && currentLayer && currentLayer !== layer) {
      nestedLayerParent={layer:currentLayer,trigger:layerTrigger,onClose:layerCloseHandler,returnFocus:opener,hadInert:currentLayer.hasAttribute('inert'),wasHidden:currentLayer.hidden,child:layer};
      currentLayer.setAttribute('aria-hidden','true');
      currentLayer.setAttribute('inert','');
      currentLayer.hidden=true;
      layer.removeAttribute('inert');
    } else if (currentLayer && currentLayer !== layer) {
      closeLayer('replaced');
    }
    currentLayer = layer;
    layerTrigger = opener;
    layerCloseHandler = onClose;
    layer.hidden = false;
    layer.setAttribute('aria-hidden', 'false');
    if (backdrop) backdrop.hidden = false;
    if (!nestedLayerParent) setLayerBackgroundInert(layer,true);
    document.documentElement.classList.add('sg-layer-open');
    requestAnimationFrame(() => q(layerFocusable, layer)?.focus?.({preventScroll: true}));
  };
  const ensureGuestConfirm = () => {
    let layer=q('[data-sg-confirm-modal]');if(layer)return layer;
    layer=document.createElement('section');layer.className='sg-modal sg-confirm-modal';layer.dataset.sgConfirmModal='';layer.setAttribute('role','dialog');layer.setAttribute('aria-modal','true');layer.setAttribute('aria-labelledby','sgConfirmTitle');layer.hidden=true;
    layer.innerHTML='<div class="sg-modal-card sg-confirm-card"><div class="sg-confirm-icon" aria-hidden="true">!</div><h2 id="sgConfirmTitle" data-sg-confirm-title></h2><p data-sg-confirm-message></p><div class="sg-modal-actions"><button type="button" class="sg-button sg-button--danger" data-sg-confirm-ok>تأیید</button><button type="button" class="sg-button sg-button--secondary" data-sg-close-layer>انصراف</button></div></div>';
    root.append(layer);return layer;
  };
  const guestConfirm=(title,message,{confirmLabel='تأیید'}={})=>new Promise((resolve)=>{const layer=ensureGuestConfirm();q('[data-sg-confirm-title]',layer).textContent=String(title||'تأیید');q('[data-sg-confirm-message]',layer).textContent=String(message||'');const ok=q('[data-sg-confirm-ok]',layer);ok.textContent=String(confirmLabel||'تأیید');openLayer(layer,document.activeElement,{nested:true,onClose:(result)=>resolve(result==='confirm')});});
  backdrop?.addEventListener('click', () => closeLayer('backdrop'));
  root.addEventListener('click', (event) => {
    if (event.target.closest('[data-sg-confirm-ok]')) { closeLayer('confirm'); return; }
    if (event.target.closest('[data-sg-close-layer]')) closeLayer('cancel');
  });
  q('[data-sg-open-events]')?.addEventListener('click', (event) => openLayer(q('[data-sg-events-modal]'), event.currentTarget));
  document.addEventListener('keydown', (event) => {
    if (!currentLayer) return;
    if (event.key === 'Escape') { event.preventDefault(); closeLayer('escape'); return; }
    if(event.key==='Tab'){
      const nodes=visibleLayerFocusables();if(!nodes.length){event.preventDefault();currentLayer.focus?.({preventScroll:true});return}
      const first=nodes[0],last=nodes[nodes.length-1],active=document.activeElement;
      if(event.shiftKey&&(active===first||!currentLayer.contains(active))){event.preventDefault();last.focus({preventScroll:true});return}
      if(!event.shiftKey&&(active===last||!currentLayer.contains(active))){event.preventDefault();first.focus({preventScroll:true});return}
    }
  });

  const search = q('[data-sg-search]');
  const searchZone = q('[data-sg-search-zone]');
  const searchResults = q('[data-sg-search-results]');
  const searchList = q('[data-sg-search-result-list]');
  const searchCount = q('[data-sg-search-count]');
  const searchClear = q('[data-sg-search-clear]');
  const noResults = q('[data-sg-no-results]');
  const menuCards = qa('[data-sg-item]');
  const categories = qa('.sg-category');
  const featured = q('.sg-featured');
  const categoryLinks = qa('[data-sg-category-link]');
  const revealCategoryLink = (link) => {
    const nav = link?.closest?.('.sg-category-nav');
    if (!nav) return;
    const navRect = nav.getBoundingClientRect();
    const linkRect = link.getBoundingClientRect();
    const margin = 8;
    let delta = 0;
    if (linkRect.left < navRect.left + margin) delta = linkRect.left - navRect.left - margin;
    else if (linkRect.right > navRect.right - margin) delta = linkRect.right - navRect.right + margin;
    if (Math.abs(delta) > 1) nav.scrollBy({left:delta, behavior:'smooth'});
  };
  const setActiveCategory = (key, reveal = false) => {
    let activeLink = null;
    categoryLinks.forEach((link) => {
      const active = String(link.dataset.sgCategoryLink || '') === String(key || 'all');
      link.classList.toggle('is-active', active);
      if (active) { link.setAttribute('aria-current', 'true'); activeLink = link; } else link.removeAttribute('aria-current');
    });
    if (reveal && activeLink) revealCategoryLink(activeLink);
  };
  categoryLinks.forEach((link) => link.addEventListener('click', () => setActiveCategory(link.dataset.sgCategoryLink || 'all')));
  if ('IntersectionObserver' in globalThis && categories.length) {
    const updateActiveCategoryFromViewport = () => {
      if (normalize(search?.value) !== '' || root.classList.contains('sg-search-active')) return;
      const viewportHeight = Number(globalThis.visualViewport?.height || globalThis.innerHeight || document.documentElement.clientHeight || 0);
      const scrollBottom = Number(globalThis.scrollY || document.documentElement.scrollTop || 0) + viewportHeight;
      const pageHeight = Math.max(document.documentElement.scrollHeight, document.body?.scrollHeight || 0);
      const atPageEnd = pageHeight > 0 && scrollBottom >= pageHeight - 4;
      const focusY = viewportHeight * .28;
      const current = atPageEnd ? categories[categories.length - 1] : categories.find((category) => {
        const rect = category.getBoundingClientRect();
        return rect.top <= focusY && rect.bottom > focusY;
      });
      if (current?.id) setActiveCategory(current.id.replace(/^sg-category-/, ''), true);
    };
    const observer = new IntersectionObserver(() => updateActiveCategoryFromViewport(), {rootMargin: '-28% 0px -58% 0px', threshold: [0, 0.01]});
    categories.forEach((category) => observer.observe(category));
    let categoryScrollFrame = 0;
    globalThis.addEventListener('scroll', () => {
      if (categoryScrollFrame) return;
      categoryScrollFrame = globalThis.requestAnimationFrame(() => {
        categoryScrollFrame = 0;
        updateActiveCategoryFromViewport();
      });
    }, {passive:true});
    const menuStart = q('#sg-menu-start');
    if (menuStart) {
      const topObserver = new IntersectionObserver((entries) => { if (entries.some((entry) => entry.isIntersecting) && normalize(search?.value) === '' && !root.classList.contains('sg-search-active')) setActiveCategory('all', true); }, {rootMargin:'0px 0px -72% 0px'});
      topObserver.observe(menuStart);
    }
  }
  const createSearchResult = (item) => {
    const card=document.createElement('article');card.className='sg-search-result-card'+(!item.available?' is-unavailable':(!item.order_available&&orderingEnabled?' is-service-paused':''));card.dataset.itemId=String(item.id);
    const hit=document.createElement('button');hit.type='button';hit.className='sg-search-result-hit';hit.dataset.sgOpenDetail=String(item.id);hit.setAttribute('aria-label',`دیدن جزئیات ${item.name||'آیتم'}`);card.append(hit);
    const media=document.createElement('div');media.className='sg-search-result-media';if(item.image){const img=document.createElement('img');img.src=item.image;img.alt=item.image_alt||item.name||'';if(item.image_focal){const x=Math.round(Math.max(0,Math.min(1,Number(item.image_focal.x??.5)))*100),y=Math.round(Math.max(0,Math.min(1,Number(item.image_focal.y??.5)))*100);img.classList.add(`sg-focal-x-${x}`,`sg-focal-y-${y}`);}img.addEventListener('error',()=>{if(img.isConnected)img.replaceWith(mediaPlaceholder());},{once:true});media.append(img);}else media.append(mediaPlaceholder());card.append(media);
    const copy=document.createElement('div');copy.className='sg-search-result-copy';const name=document.createElement('strong');name.textContent=item.name||'آیتم منو';const cat=document.createElement('small');cat.textContent=item.category||'';const price=document.createElement('b');price.textContent=formatMoney(item.price);copy.append(name,cat,price);if(!item.available||!item.order_available){const state=document.createElement('span');state.className='sg-search-result-state';state.textContent=!item.available?'فعلاً موجود نیست':(item.unavailable_message||'این بخش فعلاً سفارش تازه نمی‌پذیرد.');copy.append(state);}card.append(copy);
    if(orderingEnabled&&item.available){const controls=document.createElement('div');controls.className='sg-item-order-controls';controls.dataset.sgItemControls=String(item.id);const qty=document.createElement('div');qty.className='sg-quantity';qty.dataset.sgQuantity=String(item.id);const dec=document.createElement('button');dec.type='button';dec.dataset.sgQtyDec=String(item.id);dec.textContent='−';dec.disabled=!item.order_available;const out=document.createElement('output');out.dataset.sgQtyValue=String(item.id);out.textContent=numberFa(lineFor(item.id)?.quantity||0);const inc=document.createElement('button');inc.type='button';inc.dataset.sgQtyInc=String(item.id);inc.innerHTML='<span aria-hidden="true">+</span><span class="sg-add-label">افزودن</span>';inc.disabled=!item.order_available;qty.append(dec,out,inc);controls.append(qty);card.append(controls);}
    return card;
  };
  const renderSearchResults = () => {
    if(!search||!searchList)return;const needle=normalize(search.value);if(searchClear)searchClear.hidden=needle==='';searchList.replaceChildren();
    if(needle===''){if(searchResults)searchResults.hidden=true;if(searchCount)searchCount.textContent='';menuCards.forEach(card=>card.hidden=false);categories.forEach(category=>category.hidden=false);if(featured)featured.hidden=false;return;}
    const matches=Array.from(itemMap.values()).filter(item=>normalize(`${item.name||''} ${item.category||''} ${item.description||''}`).includes(needle));
    menuCards.forEach((card)=>{card.hidden=!matches.some(item=>Number(item.id)===Number(card.dataset.itemId));});categories.forEach((category)=>{category.hidden=category.querySelectorAll('[data-sg-item]:not([hidden])').length===0;});if(featured)featured.hidden=true;
    if(searchResults)searchResults.hidden=false;if(searchCount)searchCount.textContent=`${numberFa(matches.length)} نتیجه`;
    if(matches.length){matches.forEach(item=>searchList.append(createSearchResult(item)));}else{const empty=document.createElement('div');empty.className='sg-search-result-empty';const strong=document.createElement('strong');strong.textContent='نتیجه‌ای پیدا نشد.';const small=document.createElement('small');small.textContent='عبارت دیگری را امتحان کن.';empty.append(strong,small);searchList.append(empty);}
    metric('search_query',{search_term:String(search.value||'').trim(),result_count:matches.length});
  };

  const syncKeyboardViewport = () => {
    const vv = globalThis.visualViewport;
    if (!vv) return;
    const keyboardOpen = Math.max(0, globalThis.innerHeight - vv.height) > 120;
    root.classList.toggle('sg-keyboard-open', keyboardOpen);
    root.style.setProperty('--sg-visual-height', `${Math.round(vv.height)}px`);
  };
  globalThis.visualViewport?.addEventListener?.('resize', syncKeyboardViewport, {passive:true});
  globalThis.visualViewport?.addEventListener?.('scroll', syncKeyboardViewport, {passive:true});
  syncKeyboardViewport();
  const openSearch = () => { if(!searchZone)return;root.classList.add('sg-search-active');searchZone.classList.add('is-engaged');search?.focus?.({preventScroll:true});renderSearchResults(); };
  const closeSearch = () => { root.classList.remove('sg-search-active');searchZone?.classList.remove('is-engaged');if(search)search.value='';if(searchClear)searchClear.hidden=true;if(searchResults)searchResults.hidden=true;searchList?.replaceChildren();menuCards.forEach(card=>card.hidden=false);categories.forEach(category=>category.hidden=false);if(featured)featured.hidden=false;setActiveCategory('all');q('#sg-menu-start')?.scrollIntoView?.({block:'start'}); };
  q('[data-sg-search-open]')?.addEventListener('click',openSearch);
  q('[data-sg-search-close]')?.addEventListener('click',closeSearch);
  q('[data-sg-search-back]')?.addEventListener('click',closeSearch);
  searchClear?.addEventListener('click',()=>{if(search)search.value='';renderSearchResults();search?.focus?.();});
  search?.addEventListener('focus',openSearch);
  search?.addEventListener('input',renderSearchResults);


  const itemScope = (item) => item?.preparation_station === 'kitchen' ? 'kitchen' : (['hot_bar', 'cold_bar'].includes(String(item?.preparation_station || '')) ? 'bar' : 'cafe');
  const setItemAvailability = (acceptance = {}) => {
    const cafeOpen = acceptance.cafe !== false;
    itemMap.forEach((item, id) => {
      const scope = itemScope(item);
      const scopeOpen = acceptance[scope] !== false;
      item.order_available = Boolean(item.available) && cafeOpen && scopeOpen;
      item.blocked_scope = !cafeOpen ? 'cafe' : (!scopeOpen ? scope : '');
      if (item.blocked_scope) {
        item.unavailable_message = item.blocked_scope === 'kitchen' ? 'آشپزخانه فعلاً سفارش تازه نمی‌پذیرد.' : item.blocked_scope === 'bar' ? 'بار فعلاً سفارش تازه نمی‌پذیرد.' : 'سفارش‌گیری فعلاً متوقف است.';
      } else item.unavailable_message = '';
      qa(`[data-item-id="${CSS.escape(String(id))}"]`).forEach((card) => {
        card.classList.toggle('is-service-paused', Boolean(item.available) && !item.order_available);
      });
      qa(`[data-sg-item-controls="${CSS.escape(String(id))}"]`).forEach((controls) => {
        qa('button', controls).forEach((button) => { button.disabled = !unsafeAllowed() || !orderingEnabled || !item.order_available; });
        let state = q(`[data-sg-item-state="${CSS.escape(String(id))}"]`, controls);
        if (!item.order_available) {
          if (!state) {
            state = document.createElement('span');
            state.className = 'sg-item-state';
            state.dataset.sgItemState = String(id);
            controls.append(state);
          }
          state.textContent = item.unavailable_message || 'این بخش فعلاً سفارش تازه نمی‌پذیرد.';
        } else state?.remove();
      });
    });
    if (currentDetailId) renderDetail(currentDetailId, false);
    renderCart();
  };

  const cartLineKey=(id,choice=0)=>`${Number(id)}|${Number(choice||0)}`;
  const rowsForItem=(id)=>Array.from(cart.values()).filter(line=>Number(line.id)===Number(id));
  const lineFor=(id,choice=null)=>{const rows=rowsForItem(id);if(choice===null)return rows[0]||null;return cart.get(cartLineKey(id,choice))||null;};
  const itemQty=(id)=>rowsForItem(id).reduce((sum,line)=>sum+Number(line.quantity||0),0);
  const setCartQty = (id, quantity, options = {}) => {
    id = Number(id);
    const item = itemMap.get(id);
    if (!item) return;
    const choice=Number(options.bundle_choice_item_id ?? 0);
    const key=cartLineKey(id,choice);
    const previous=cart.get(key);
    const maxAllowed = previous?.snapshot_from_order && !item.order_available ? Number(previous.original_quantity || previous.quantity || 0) : 99;
    const qty = clampInt(quantity, 0, maxAllowed);
    if (qty <= 0) { cart.delete(key); saveGuestDraft(); renderCart(); return; }
    if (!item.order_available && (!previous || qty > Number(previous.quantity || 0))) {
      toast(item.unavailable_message || 'این آیتم فعلاً قابل افزایش نیست.', true);
      return;
    }
    const existingTakeaway = Number(previous?.takeaway_quantity || 0);
    cart.set(key, {
      id,
      quantity: qty,
      takeaway_quantity: item.takeaway_allowed ? Math.min(qty, options.takeaway_quantity ?? existingTakeaway) : 0,
      note: options.note ?? previous?.note ?? '',
      snapshot_from_order: options.snapshot_from_order ?? previous?.snapshot_from_order ?? false,
      original_quantity: options.original_quantity ?? previous?.original_quantity ?? qty,
      bundle_choice_item_id: choice,
      unit_price: Number(options.unit_price ?? previous?.unit_price ?? item.price ?? 0),
    });
    saveGuestDraft();renderCart();
  };
  const cartCount = () => Array.from(cart.values()).reduce((sum, line) => sum + Number(line.quantity || 0), 0);
  const cartTotal = () => Array.from(cart.values()).reduce((sum, line) => sum + Number(line.quantity || 0) * Number(line.unit_price ?? itemMap.get(Number(line.id))?.price ?? 0), 0);
  const payloadLines = () => {
    const lines = [];
    cart.forEach((line, key) => {
      const id=Number(line.id);
      const item = itemMap.get(id);
      if (!item || Number(line.quantity) < 1) return;
      const take = item.takeaway_allowed ? clampInt(line.takeaway_quantity, 0, line.quantity) : 0;
      const dine = Number(line.quantity) - take;
      const base = {id: Number(id), expected_price: Number(line.unit_price ?? item.price), note: String(line.note || ''), bundle_choice_item_id: Number(line.bundle_choice_item_id || 0)};
      if (dine > 0) lines.push({...base, quantity: dine, fulfillment_mode: 'dine_in'});
      if (take > 0) lines.push({...base, quantity: take, fulfillment_mode: 'takeaway'});
    });
    return lines;
  };

  const basket = q('[data-sg-basket]');
  const basketTotal = q('[data-sg-basket-total]');
  const basketCount = q('[data-sg-basket-count]');
  const orderMessage = q('[data-sg-order-message]');
  const cartLines = q('[data-sg-cart-lines]');
  const customerNote = q('[data-sg-customer-note]');
  const submit = q('[data-sg-order-submit]');
  const quickSubmit = q('[data-sg-quick-submit]');
  const quantityOutputs = (id) => qa(`[data-sg-qty-value="${CSS.escape(String(id))}"]`);
  const quantityControls = (id) => qa(`[data-sg-quantity="${CSS.escape(String(id))}"]`);

  const renderCart = () => {
    const count = cartCount();
    const total = cartTotal();
    if (basket) basket.hidden = count === 0;
    if (basketTotal) basketTotal.textContent = formatMoney(total);
    if (basketCount) basketCount.textContent = numberFa(count);
    itemMap.forEach((_, id) => {
      const quantity = itemQty(id);
      quantityOutputs(id).forEach((output) => { output.textContent = numberFa(quantity); });
      quantityControls(id).forEach((control) => control.classList.toggle('has-items', quantity > 0));
    });
    if (submit) submit.disabled = submit.dataset.busy === '1' || count === 0 || !unsafeAllowed() || !orderingEnabled || !endpoints.order;
    if (quickSubmit) quickSubmit.disabled = count === 0 || !unsafeAllowed() || !orderingEnabled || !endpoints.order;
    if (root.classList.contains('sg-search-active')) renderSearchResults();
    if (!cartLines) return;
    cartLines.replaceChildren();
    if (count === 0) {
      const empty = document.createElement('p'); empty.className = 'sg-cart-empty'; empty.textContent = 'هنوز چیزی انتخاب نکرده‌ای.'; cartLines.append(empty); return;
    }
    cart.forEach((line, key) => {
      const id=Number(line.id);
      const item = itemMap.get(id); if (!item) return;
      const row = document.createElement('article'); row.className = 'sg-cart-line'; row.dataset.id = String(id); row.dataset.cartKey=String(key);
      const head = document.createElement('div'); head.className = 'sg-cart-line-head';
      const copy = document.createElement('div'); const title = document.createElement('strong'); const choiceName=bundleChoiceName(item,line.bundle_choice_item_id);title.textContent = `${item.name || 'آیتم'}${choiceName?` · ${choiceName}`:''}`;
      const price = document.createElement('small'); price.textContent = formatMoney(Number(line.unit_price ?? item.price) * Number(line.quantity)); copy.append(title, price);
      const qty = document.createElement('div'); qty.className = 'sg-mini-qty';
      const dec = document.createElement('button'); dec.type='button'; dec.dataset.sgCartDelta='-1'; dec.dataset.cartKey=String(key); dec.textContent='−'; dec.setAttribute('aria-label','کم کردن');
      const out = document.createElement('output'); out.textContent=numberFa(line.quantity);
      const inc = document.createElement('button'); inc.type='button'; inc.dataset.sgCartDelta='1'; inc.dataset.cartKey=String(key); inc.textContent='+'; inc.setAttribute('aria-label','افزودن');
      const canIncrease = unsafeAllowed() && orderingEnabled && item.order_available && (!line.snapshot_from_order || Number(line.quantity) < 99);
      inc.disabled=!canIncrease; qty.append(dec,out,inc); head.append(copy,qty); row.append(head);
      const note = document.createElement('label'); note.className='sg-line-note'; const noteLabel=document.createElement('span');noteLabel.textContent='یادداشت'; const input=document.createElement('input');input.maxLength=300;input.value=line.note||'';input.placeholder='مثلاً بدون یخ';input.dataset.sgLineNote=String(key);note.append(noteLabel,input);row.append(note);
      if (item.takeaway_allowed) {
        const fulfillment = document.createElement('div'); fulfillment.className='sg-fulfillment';
        const label=document.createElement('span');label.textContent='بیرون‌بر';
        const controls=document.createElement('div');controls.className='sg-mini-qty';
        const tdec=document.createElement('button');tdec.type='button';tdec.dataset.sgTakeawayDelta='-1';tdec.dataset.cartKey=String(key);tdec.textContent='−';
        const tout=document.createElement('output');tout.textContent=numberFa(line.takeaway_quantity||0);
        const tinc=document.createElement('button');tinc.type='button';tinc.dataset.sgTakeawayDelta='1';tinc.dataset.cartKey=String(key);tinc.textContent='+';tinc.disabled=!unsafeAllowed()||!orderingEnabled||Number(line.takeaway_quantity||0)>=Number(line.quantity||0);
        controls.append(tdec,tout,tinc);fulfillment.append(label,controls);row.append(fulfillment);
      }
      cartLines.append(row);
    });
  };

  root.addEventListener('click', (event) => {
    const inc = event.target.closest('[data-sg-qty-inc]');
    const dec = event.target.closest('[data-sg-qty-dec]');
    if (inc) { const id=Number(inc.dataset.sgQtyInc); const line=lineFor(id,0); setCartQty(id,(line?.quantity||0)+1,{bundle_choice_item_id:0}); return; }
    if (dec) { const id=Number(dec.dataset.sgQtyDec); const line=lineFor(id,0); setCartQty(id,(line?.quantity||0)-1,{bundle_choice_item_id:0}); return; }
    const cartDelta = event.target.closest('[data-sg-cart-delta]');
    if (cartDelta) { const key=String(cartDelta.dataset.cartKey||'');const line=cart.get(key);if(!line)return;setCartQty(line.id,Number(line.quantity||0)+Number(cartDelta.dataset.sgCartDelta||0),{bundle_choice_item_id:Number(line.bundle_choice_item_id||0)});return; }
    const takeawayDelta = event.target.closest('[data-sg-takeaway-delta]');
    if (takeawayDelta) {
      const key=String(takeawayDelta.dataset.cartKey||''), line=cart.get(key); if(!line)return;
      line.takeaway_quantity=clampInt(Number(line.takeaway_quantity||0)+Number(takeawayDelta.dataset.sgTakeawayDelta||0),0,line.quantity);cart.set(key,line);saveGuestDraft();renderCart();refreshQuote();return;
    }
  });
  root.addEventListener('input', (event) => {
    const input = event.target.closest('[data-sg-line-note]');
    if (!input) return;
    const key=String(input.dataset.sgLineNote||''), line=cart.get(key);if(!line)return;line.note=String(input.value||'');cart.set(key,line);saveGuestDraft();
  });

  const cartDrawer = q('[data-sg-cart-drawer]');
  const openCart = (trigger) => { renderCart(); openLayer(cartDrawer, trigger); refreshQuote(); };
  q('[data-sg-open-cart]')?.addEventListener('click', (event) => openCart(event.currentTarget));
  quickSubmit?.addEventListener('click', (event) => openCart(event.currentTarget));

  const quoteEls = {
    subtotal:q('[data-sg-quote-subtotal]'), discount:q('[data-sg-quote-discount]'), discountRow:q('[data-sg-quote-discount-row]'),
    tax:q('[data-sg-quote-tax]'), taxRow:q('[data-sg-quote-tax-row]'), total:q('[data-sg-quote-total]'), state:q('[data-sg-quote-state]'),
  };
  const refreshQuote = async () => {
    const lines=payloadLines(); if(!lines.length)return;
    const localTotal=cartTotal();if(quoteEls.subtotal)quoteEls.subtotal.textContent=formatMoney(localTotal);if(quoteEls.total)quoteEls.total.textContent=formatMoney(localTotal);
    if(!unsafeAllowed()||!orderingEnabled){if(quoteEls.state)quoteEls.state.textContent='برای به‌روزرسانی مبلغ و ثبت سفارش، اتصال دوباره لازم است.';return;}
    if(quoteEls.state)quoteEls.state.textContent=endpoints.quote?'در حال تطبیق مبلغ نهایی…':'مبلغ بر اساس قیمت‌های فعلی منو است.';
    if(!endpoints.quote)return;const seq=++quoteSeq;
    try {
      const payload={quote_mode:editingOrder?'update':'create',table_token:tableToken,session_token:sessionToken,device_token:deviceToken,client_token:'',customer_note:customerNote?.value||'',items:lines};
      if(editingOrder){payload.order_code=editingOrder.order_code;payload.expected_signature=editingOrder.edit_signature;}
      const body=apiOk(await postJson(endpoints.quote,payload,10000),'پیش‌نمایش مبلغ در دسترس نیست.');
      if(seq!==quoteSeq)return;const f=body.financial_preview||{};
      quoteEls.subtotal&&(quoteEls.subtotal.textContent=formatMoney(f.subtotal||0));
      quoteEls.discount&&(quoteEls.discount.textContent=`− ${formatMoney(f.discount||0)}`); if(quoteEls.discountRow)quoteEls.discountRow.hidden=!(Number(f.discount)>0);
      quoteEls.tax&&(quoteEls.tax.textContent=formatMoney(f.tax||0)); if(quoteEls.taxRow)quoteEls.taxRow.hidden=!(Number(f.tax)>0);
      quoteEls.total&&(quoteEls.total.textContent=formatMoney(f.total??cartTotal())); if(quoteEls.state)quoteEls.state.textContent='مبلغ با وضعیت فعلی حساب محاسبه شد.';
    } catch(error) { if(seq!==quoteSeq)return;if(quoteEls.state)quoteEls.state.textContent=error?.message||'پیش‌نمایش مبلغ در دسترس نیست.'; }
  };
  customerNote?.addEventListener('input', () => { saveGuestDraft();globalThis.clearTimeout(customerNote.__quoteTimer); customerNote.__quoteTimer=globalThis.setTimeout(refreshQuote,300); });

  const detailModal=q('[data-sg-item-modal]'), detailMedia=q('[data-sg-detail-media]'), detailTitle=q('[data-sg-detail-title]'), detailPrice=q('[data-sg-detail-price]'), detailDescription=q('[data-sg-detail-description]'), detailState=q('[data-sg-detail-state]'), detailOptions=q('[data-sg-detail-options]'), detailControls=q('[data-sg-detail-controls]'), detailQty=q('[data-sg-detail-qty]'), detailNoteWrap=q('[data-sg-detail-note-wrap]'), detailNote=q('[data-sg-detail-note]'), detailAdd=q('[data-sg-detail-add]'), suggestions=q('[data-sg-suggestions]');
  const familyMembers = (item) => {
    if(!Number(item?.variant_family_id||0))return [];
    const configured=Array.isArray(item.variant_members)?item.variant_members:[];
    return configured.map((m)=>{const row=itemMap.get(Number(m.item_id));return row?{...row,variant_label:String(m.label||row.variant_label||'')}:null}).filter(Boolean);
  };
  const bundleChoices = (item) => (Array.isArray(item?.bundle_components)?item.bundle_components:[]).filter((c)=>String(c.mode||'')==='choice');
  const bundleChoiceName=(item,choice)=>{const row=bundleChoices(item).find(c=>Number(c.item_id)===Number(choice||0));return String(row?.name||'');};
  const fixedBundleDelta=(item)=>(Array.isArray(item?.bundle_components)?item.bundle_components:[]).filter((c)=>String(c.mode||'')==='fixed').reduce((sum,c)=>sum+Number(c.price_delta||0),0);
  const renderSuggestions=(item)=>{
    if(!suggestions)return;const ids=Array.isArray(item?.suggested_item_ids)&&item.suggested_item_ids.length?item.suggested_item_ids:(item?.suggested_item_id?[item.suggested_item_id]:[]);suggestions.replaceChildren();
    ids.slice(0,2).forEach((raw)=>{const suggested=itemMap.get(Number(raw));if(!suggested||!suggested.available)return;const b=document.createElement('button');b.type='button';b.className='sg-suggestion';b.dataset.sgSuggestionItem=String(suggested.id);b.textContent=`کنارش خوبه با ${suggested.name} · ${formatMoney(suggested.price)}`;suggestions.append(b);});suggestions.hidden=!suggestions.childElementCount;
  };
  const renderDetailOptions=(item,resetDraft)=>{
    if(!detailOptions)return;detailOptions.replaceChildren();detailOptions.hidden=true;const members=familyMembers(item);const choices=bundleChoices(item);
    if(resetDraft){detailSelectedItemId=members.length?0:Number(item.id||0);detailBundleChoiceItemId=0;detailDraftUnitPrice=Number(item.price||0);}
    if(members.length){
      detailOptions.hidden=false;const title=document.createElement('strong');title.textContent='اندازه / نوع را انتخاب کن';detailOptions.append(title);const row=document.createElement('div');row.className='sg-detail-option-row';
      members.forEach((member)=>{const b=document.createElement('button');b.type='button';b.className='sg-detail-option';b.disabled=!member.order_available;b.dataset.sgVariantItem=String(member.id);b.innerHTML=`<span>${member.variant_label||member.name}</span><small>${formatMoney(member.price)}</small>`;if(Number(detailSelectedItemId)===Number(member.id))b.classList.add('is-selected');row.append(b);});detailOptions.append(row);
      const selected=itemMap.get(Number(detailSelectedItemId));detailDraftUnitPrice=Number(selected?.price||0);return;
    }
    if(item.is_bundle&&choices.length){
      detailOptions.hidden=false;const title=document.createElement('strong');title.textContent=item.bundle_choice_label||'یک انتخاب';detailOptions.append(title);const row=document.createElement('div');row.className='sg-detail-option-row';
      choices.forEach((choice)=>{const b=document.createElement('button');b.type='button';b.className='sg-detail-option';b.disabled=!choice.available;b.dataset.sgBundleChoice=String(choice.item_id);const final=Number(item.base_price||item.price||0)+fixedBundleDelta(item)+Number(choice.price_delta||0);b.dataset.finalPrice=String(final);b.innerHTML=`<span>${choice.name||'انتخاب'}</span><small>${formatMoney(final)}</small>`;if(Number(detailBundleChoiceItemId)===Number(choice.item_id))b.classList.add('is-selected');row.append(b);});detailOptions.append(row);detailDraftUnitPrice=detailBundleChoiceItemId?Number(q(`[data-sg-bundle-choice="${CSS.escape(String(detailBundleChoiceItemId))}"]`,detailOptions)?.dataset.finalPrice||0):Number(item.price||0);return;
    }
    detailSelectedItemId=Number(item.id||0);detailDraftUnitPrice=Number(item.price||0);
  };
  const renderDetail = (id, resetDraft = true) => {
    const item=itemMap.get(Number(id));if(!item)return;currentDetailId=Number(id);if(resetDraft)detailDraftQty=Math.max(1,Number(lineFor(id)?.quantity||1));renderDetailOptions(item,resetDraft);
    const members=familyMembers(item),selected=members.length?itemMap.get(Number(detailSelectedItemId)):item;const displayName=members.length?(item.variant_family_name||item.name):item.name;const displayDescription=members.length?(item.variant_family_description||item.description):item.description;
    if(detailMedia){detailMedia.replaceChildren();const mediaItem=selected||item;if(mediaItem.image){const img=document.createElement('img');img.src=mediaItem.image;img.alt=mediaItem.image_alt||displayName||'';if(mediaItem.image_focal){const x=Math.round(Math.max(0,Math.min(1,Number(mediaItem.image_focal.x??.5)))*100),y=Math.round(Math.max(0,Math.min(1,Number(mediaItem.image_focal.y??.5)))*100);img.classList.add(`sg-focal-x-${x}`,`sg-focal-y-${y}`);}img.addEventListener('error',()=>{if(img.isConnected)img.replaceWith(mediaPlaceholder());},{once:true});detailMedia.append(img);}else detailMedia.append(mediaPlaceholder());}
    detailTitle&&(detailTitle.textContent=displayName||'آیتم منو');detailPrice&&(detailPrice.textContent=(members.length&&!detailSelectedItemId)?`از ${formatMoney(Math.min(...members.map(m=>Number(m.price||0))))}`:formatMoney(detailDraftUnitPrice||item.price));detailDescription&&(detailDescription.textContent=displayDescription||'');detailQty&&(detailQty.textContent=numberFa(detailDraftQty));
    const selectedItem=members.length?itemMap.get(Number(detailSelectedItemId)):item;const choiceRequired=item.is_bundle&&bundleChoices(item).length>0;const selectionReady=(!members.length||detailSelectedItemId>0)&&(!choiceRequired||detailBundleChoiceItemId>0);const canAdd=unsafeAllowed()&&orderingEnabled&&selectionReady&&Boolean((selectedItem||item).order_available);
    if(detailControls)detailControls.hidden=!canAdd;if(detailAdd){const existingLine=lineFor(detailSelectedItemId||item.id,item.is_bundle?detailBundleChoiceItemId:0);detailAdd.hidden=false;detailAdd.disabled=!canAdd;detailAdd.textContent=members.length&&!detailSelectedItemId?'ابتدا یک گزینه انتخاب کن':(choiceRequired&&!detailBundleChoiceItemId?'ابتدا یک گزینه انتخاب کن':(existingLine?'به‌روزرسانی سبد':'افزودن به سفارش'));}
    if(detailNoteWrap)detailNoteWrap.hidden=!selectionReady;if(detailNote){const existing=lineFor(detailSelectedItemId||item.id,item.is_bundle?detailBundleChoiceItemId:0);detailNote.value=existing?.note||'';}
    if(detailState){detailState.textContent=!item.available?'این آیتم فعلاً ناموجود است.':(!selectionReady?'انتخاب یکی از گزینه‌ها الزامی است.':(!((selectedItem||item).order_available)?((selectedItem||item).unavailable_message||'این بخش فعلاً سفارش تازه نمی‌پذیرد.') : ''));detailState.hidden=detailState.textContent==='';}
    renderSuggestions(selectedItem||item);
  };
  root.addEventListener('click',(event)=>{const opener=event.target.closest('[data-sg-open-detail]');if(!opener)return;event.preventDefault();event.stopPropagation();renderDetail(Number(opener.dataset.sgOpenDetail));openLayer(detailModal,opener);});
  detailOptions?.addEventListener('click',(event)=>{const variant=event.target.closest('[data-sg-variant-item]');if(variant){detailSelectedItemId=Number(variant.dataset.sgVariantItem||0);renderDetail(currentDetailId,false);return;}const choice=event.target.closest('[data-sg-bundle-choice]');if(choice){detailBundleChoiceItemId=Number(choice.dataset.sgBundleChoice||0);detailDraftUnitPrice=Number(choice.dataset.finalPrice||0);renderDetail(currentDetailId,false);}});
  q('[data-sg-detail-inc]')?.addEventListener('click',()=>{detailDraftQty=clampInt(detailDraftQty+1,1,99);detailQty&&(detailQty.textContent=numberFa(detailDraftQty));});
  q('[data-sg-detail-dec]')?.addEventListener('click',()=>{detailDraftQty=clampInt(detailDraftQty-1,1,99);detailQty&&(detailQty.textContent=numberFa(detailDraftQty));});
  detailAdd?.addEventListener('click',()=>{const rootItem=itemMap.get(Number(currentDetailId));if(!rootItem)return;const id=detailSelectedItemId||Number(rootItem.id);if(!id)return;setCartQty(id,detailDraftQty,{note:detailNote?.value||lineFor(id,rootItem.is_bundle?detailBundleChoiceItemId:0)?.note||'',bundle_choice_item_id:rootItem.is_bundle?detailBundleChoiceItemId:0,unit_price:detailDraftUnitPrice||itemMap.get(id)?.price||0});closeLayer();toast('به سفارش اضافه شد.');});
  suggestions?.addEventListener('click',(event)=>{const b=event.target.closest('[data-sg-suggestion-item]');const id=Number(b?.dataset.sgSuggestionItem||0);if(!id)return;setCartQty(id,(lineFor(id)?.quantity||0)+1);toast('پیشنهاد به سفارش اضافه شد.');});

  const trackerKey = tableToken ? `sokna.guest.latest-order.${tableToken}` : '';
  const saveTracker=(value)=>{if(!trackerKey)return;try{value?sessionStorage.setItem(trackerKey,JSON.stringify(value)):sessionStorage.removeItem(trackerKey);}catch(_){}};
  const loadTracker=()=>{if(!trackerKey)return null;try{return JSON.parse(sessionStorage.getItem(trackerKey)||'null')}catch(_){return null}};
  const orderTokenKey = tableToken ? `sokna.guest.pending-order.${tableToken}` : '';
  const pendingSubmissionKey = tableToken ? `sokna.guest.submission.${tableToken}` : '';
  const pendingSubmissionMaxAgeMs = 24*60*60*1000;
  const preparedSubmissionMaxAgeMs = 15*60*1000;
  const savePendingSubmission=(value)=>{if(!pendingSubmissionKey)return false;const encoded=value?JSON.stringify(value):'';try{if(value){localStorage.setItem(pendingSubmissionKey,encoded);}else{localStorage.removeItem(pendingSubmissionKey);}try{sessionStorage.removeItem(pendingSubmissionKey);}catch(_){}return true;}catch(_){try{value?sessionStorage.setItem(pendingSubmissionKey,encoded):sessionStorage.removeItem(pendingSubmissionKey);return true;}catch(__){return false;}}};
  const loadPendingSubmission=()=>{if(!pendingSubmissionKey)return null;let value=null;try{value=JSON.parse(localStorage.getItem(pendingSubmissionKey)||'null');if(!value){value=JSON.parse(sessionStorage.getItem(pendingSubmissionKey)||'null');if(value){try{localStorage.setItem(pendingSubmissionKey,JSON.stringify(value));}catch(_){}}}}catch(_){try{value=JSON.parse(sessionStorage.getItem(pendingSubmissionKey)||'null')}catch(__){value=null}}if(!value||typeof value!=='object')return null;const requestId=String(value.request_id||''),clientToken=String(value.client_token||''),createdAt=Number(value.created_at||0),traceId=/^skt1-[a-f0-9]{32}$/.test(String(value.trace_id||''))?String(value.trace_id):'',stage=String(value.stage||'accepted')==='prepared'?'prepared':'accepted',intent=value.intent&&typeof value.intent==='object'?value.intent:null,replayAttempts=Math.max(0,Math.trunc(Number(value.replay_attempts||0)));const maxAge=stage==='prepared'?preparedSubmissionMaxAgeMs:pendingSubmissionMaxAgeMs;if(!clientToken||!Number.isFinite(createdAt)||createdAt<=0||Date.now()-createdAt>maxAge||(stage==='accepted'&&!requestId)){savePendingSubmission(null);return null;}return {v:2,request_id:requestId,client_token:clientToken,created_at:createdAt,trace_id:traceId,stage,intent,replay_attempts:replayAttempts};};
  const preparePendingSubmission=(clientToken,lines)=>{const intent={session_token:String(sessionToken||''),device_token:String(deviceToken||''),customer_note:String(customerNote?.value||''),items:JSON.parse(JSON.stringify(lines||[]))};const pending={v:2,request_id:'',client_token:String(clientToken||''),created_at:Date.now(),trace_id:'',stage:'prepared',intent,replay_attempts:0};if(!pending.client_token||!savePendingSubmission(pending)){const error=new Error('برای جلوگیری از ثبت تکراری، امکان ذخیره وضعیت ارسال روی این دستگاه وجود ندارد.');error.code='recovery_storage_unavailable';throw error;}return pending;};
  const markPendingAccepted=(pending,body)=>{const requestId=String(body?.request_id||pending?.request_id||''),traceId=/^skt1-[a-f0-9]{32}$/.test(String(body?.trace_id||''))?String(body.trace_id):String(pending?.trace_id||'');if(!requestId)return pending;const next={v:2,request_id:requestId,client_token:String(body?.client_token||pending?.client_token||''),created_at:Number(pending?.created_at||Date.now()),trace_id:traceId,stage:'accepted',intent:null,replay_attempts:Number(pending?.replay_attempts||0)};savePendingSubmission(next);return next;};
  const successModal=q('[data-sg-success-modal]'),successCode=q('[data-sg-success-code]'),successMessage=q('[data-sg-success-message]'),successProgress=q('[data-sg-success-progress]'),successLive=q('[data-sg-order-live-status]');

  const pendingMutationKey=tableToken?`sokna.guest.order-mutation.v1.${tableToken}`:'';
  const pendingMutationMaxAgeMs=2*60*60*1000;
  let pendingMutationRecoveryRunning=false,pendingMutationRecoveryTimer=0;
  const savePendingMutation=(value)=>{if(!pendingMutationKey)return false;const encoded=value?JSON.stringify(value):'';try{value?localStorage.setItem(pendingMutationKey,encoded):localStorage.removeItem(pendingMutationKey);try{sessionStorage.removeItem(pendingMutationKey);}catch(_){}return true;}catch(_){try{value?sessionStorage.setItem(pendingMutationKey,encoded):sessionStorage.removeItem(pendingMutationKey);return true;}catch(__){return false;}}};
  const loadPendingMutation=()=>{if(!pendingMutationKey)return null;let value=null;try{value=JSON.parse(localStorage.getItem(pendingMutationKey)||'null');if(!value){value=JSON.parse(sessionStorage.getItem(pendingMutationKey)||'null');if(value){try{localStorage.setItem(pendingMutationKey,JSON.stringify(value));}catch(_){}}}}catch(_){try{value=JSON.parse(sessionStorage.getItem(pendingMutationKey)||'null')}catch(__){value=null}}if(!value||typeof value!=='object')return null;const valid=value.v===1&&['update','cancel'].includes(String(value.action||''))&&value.payload&&typeof value.payload==='object'&&String(value.table_token||'')===String(tableToken)&&String(value.device_token||'')===String(deviceToken)&&Number(value.expires_at||0)>Date.now();if(!valid){savePendingMutation(null);return null;}return value;};
  const preparePendingMutation=(action,payload)=>{const now=Date.now(),value={v:1,action,table_token:String(tableToken),device_token:String(deviceToken),created_at:now,expires_at:now+pendingMutationMaxAgeMs,payload};if(!savePendingMutation(value))throw new Error('بازیابی امن این تغییر در مرورگر ذخیره نشد؛ دوباره تلاش کن.');return value;};
  const setMutationButtonsDisabled=(disabled)=>{qa('[data-sg-edit-order],[data-sg-cancel-order]').forEach(button=>{button.disabled=disabled||!unsafeAllowed();});};
  const schedulePendingMutationRecovery=(delay=1600)=>{if(pendingMutationRecoveryTimer)globalThis.clearTimeout(pendingMutationRecoveryTimer);pendingMutationRecoveryTimer=globalThis.setTimeout(()=>{pendingMutationRecoveryTimer=0;recoverPendingMutation().catch(()=>{});},delay);};
  const recoverPendingMutation=async()=>{const pending=loadPendingMutation();if(!pending||pendingMutationRecoveryRunning||document.hidden||!unsafeAllowed()||!endpoints.orders)return false;pendingMutationRecoveryRunning=true;setMutationButtonsDisabled(true);try{const body=apiOk(await postJson(endpoints.orders,pending.payload,pending.action==='cancel'?12000:15000),pending.action==='cancel'?'لغو سفارش انجام نشد.':'ذخیره تغییرات سفارش انجام نشد.');savePendingMutation(null);clearGuestDraft();editingOrder=null;cart.clear();if(customerNote)customerNote.value='';renderCart();toast(pending.action==='cancel'?(body.status==='unchanged'?'وضعیت سفارش تازه شد.':'سفارش لغو شد.'):'تغییرات سفارش ثبت شد.');await refreshContext();await refreshOrders(false);return true;}catch(error){if(definitiveClientFailure(error)){savePendingMutation(null);if(error?.code==='order_changed'||error?.code==='pending_order_exists'){clearGuestDraft();editingOrder=null;cart.clear();if(customerNote)customerNote.value='';renderCart();await refreshOrders(false);toast('وضعیت سفارش از کافه تازه شد؛ قبل از تغییر دوباره آن را بررسی کن.');return true;}toast(error?.message||(pending.action==='cancel'?'لغو سفارش انجام نشد.':'ذخیره تغییرات سفارش انجام نشد.'),true);return false;}schedulePendingMutationRecovery(3500);return false;}finally{pendingMutationRecoveryRunning=false;const stillPending=Boolean(loadPendingMutation());setMutationButtonsDisabled(stillPending);if(submit&&!stillPending)submit.dataset.busy='0';renderCart();}};

  const submitOrder = async () => {
    const lines=payloadLines();if(!lines.length||!unsafeAllowed()||!orderingEnabled||loadPendingSubmission())return;
    let keepBusy=false;
    if(submit){submit.dataset.busy='1';submit.disabled=true;} if(orderMessage){orderMessage.classList.remove('is-error');orderMessage.textContent=editingOrder?'در حال ذخیره تغییرات…':'در حال ارسال سفارش…';}
    try {
      let body;
      if(editingOrder){
        const payload={action:'update',table_token:tableToken,session_token:sessionToken,device_token:deviceToken,order_code:editingOrder.order_code,expected_signature:editingOrder.edit_signature,customer_note:customerNote?.value||'',items:lines};
        preparePendingMutation('update',payload);
        try{body=apiOk(await postJson(endpoints.orders,payload,15000),'ذخیره تغییرات سفارش انجام نشد.');savePendingMutation(null);}catch(error){if(definitiveClientFailure(error)){savePendingMutation(null);throw error;}keepBusy=true;closeLayer();if(orderMessage)orderMessage.textContent='نتیجه ذخیره تغییرات در حال بازیابی است؛ تغییر را دوباره نفرست.';toast('وضعیت تغییر سفارش در حال بازیابی است.');schedulePendingMutationRecovery();return;}
      }else{
        const clientToken=randomToken();
        const prepared=preparePendingSubmission(clientToken,lines);
        try{
          body=apiOk(await postJson(endpoints.order,{table_token:tableToken,session_token:prepared.intent.session_token,device_token:prepared.intent.device_token,client_token:clientToken,customer_note:prepared.intent.customer_note,items:prepared.intent.items},5000),'ارسال سفارش انجام نشد.');
        }catch(error){
          if(definitiveClientFailure(error)){savePendingSubmission(null);throw error;}
          keepBusy=true;closeLayer();if(successCode)successCode.textContent='در حال بازیابی';applySuccessStatus('submitting');if(successModal)openLayer(successModal,submit);if(orderMessage)orderMessage.textContent='نتیجه ارسال در حال بازیابی است؛ سفارش را دوباره ارسال نکن.';toast('وضعیت ارسال در حال بررسی است.');startSubmissionTracking();return;
        }
        if(body.terminal===false&&body.request_id&&!isBusinessCommittedResponse(body)){
          keepBusy=true;markPendingAccepted(prepared,body);
          closeLayer();if(successCode)successCode.textContent='در حال ارسال';applySuccessStatus('submitting');if(successModal)openLayer(successModal,submit);if(orderMessage)orderMessage.textContent='سفارش ارسال شد؛ نتیجه در حال ثبت است.';toast('سفارش ارسال شد.');startSubmissionTracking();return;
        }
        if(isBusinessCommittedResponse(body)){
          keepBusy=await finishPendingSubmission(body);
          return;
        }
        savePendingSubmission(null);clearGuestDraft();clearOperationToken(orderTokenKey);
        saveTracker({order_code:body.order_code,client_token:body.client_token||clientToken,order_number:body.order_number||0,status:body.status||'new'});
      }
      const wasEditing=Boolean(editingOrder);clearGuestDraft();const msg=wasEditing?'تغییرات سفارش ذخیره شد.':(body.status==='pending_approval'?'سفارش ثبت شد؛ منتظر تأیید حضور هستیم.':'سفارش با موفقیت ثبت شد.');
      editingOrder=null;cart.clear();if(customerNote)customerNote.value='';renderCart();closeLayer();if(wasEditing)toast(msg);if(orderMessage)orderMessage.textContent=msg;
      if(!wasEditing&&successModal){if(successCode)successCode.textContent=body.order_number?`سفارش ${numberFa(body.order_number)}`:(body.order_code?`کد ${body.order_code}`:'');applySuccessStatus(body.status||'new');openLayer(successModal,submit);metric('order_success',{status:String(body.status||'')});}
      await refreshContext();await refreshOrders(false);startOrderTracking();
    } catch(error) {
      const msg=error?.message||'ثبت سفارش انجام نشد.';if(orderMessage){orderMessage.classList.add('is-error');orderMessage.textContent=msg;}toast(msg,true);
      if(error?.code==='pending_order_exists'){closeLayer();await refreshOrders(true);}
      if(error?.code==='order_changed'){clearGuestDraft();editingOrder=null;cart.clear();if(customerNote)customerNote.value='';renderCart();await refreshOrders(true);}
    } finally {if(submit&&!keepBusy)submit.dataset.busy='0';renderCart();}
  };
  submit?.addEventListener('click',submitOrder);

  const ordersModal=q('[data-sg-orders-modal]'), ordersList=q('[data-sg-orders-list]'), ordersState=q('[data-sg-orders-state]'), ordersBadge=q('[data-sg-orders-badge]'), ordersBar=q('[data-sg-recent-orders]');
  const ordersLiveTitle=q('[data-sg-order-live-title]'), ordersLiveBody=q('[data-sg-order-live-body]'), orderProgressSteps=qa('[data-sg-order-step]'), ordersAction=q('[data-sg-orders-action]'), confirmationState=q('.sg-action-state.is-waiting');
  const terminalOrderStatuses=new Set(['cancelled','completed','session_closed']);
  const activeOrderPresentation=(order)=>{
    const status=String(order?.status||'');
    if(status==='pending_approval')return {stage:1,title:'سفارش در انتظار تأیید',body:'کافه سفارش را بررسی می‌کند.'};
    if(status==='new')return {stage:1,title:'سفارش در انتظار تأیید',body:'کافه سفارش را بررسی می‌کند.'};
    if(status==='accounted')return {stage:3,title:'سفارش تأیید شد',body:'سفارش شما تأیید شده و در حال آماده‌سازی است.'};
    const label=String(order?.status_label||'').trim();
    return {stage:2,title:label?`وضعیت سفارش: ${label}`:'سفارش فعال است',body:'آخرین وضعیت سفارش از کافه دریافت شده است.'};
  };
  const refreshOrders = async (open = false) => {
    if(!tableToken||!endpoints.orders)return [];
    if(ordersState)ordersState.textContent='در حال دریافت سفارش‌ها…';
    try{
      const body=apiOk(await postJson(endpoints.orders,{action:'list',table_token:tableToken,session_token:sessionToken,device_token:deviceToken},10000),'فهرست سفارش‌ها دریافت نشد.');
      const orders=Array.isArray(body.orders)?body.orders:[];renderOrders(orders);if(ordersState)ordersState.textContent=orders.length?'':'هنوز سفارشی از این دستگاه ثبت نشده است.';if(open)openLayer(ordersModal,q('[data-sg-open-orders]'));return orders;
    }catch(error){if(ordersState)ordersState.textContent=error?.message||'فهرست سفارش‌ها دریافت نشد.';if(open)openLayer(ordersModal,q('[data-sg-open-orders]'));return[];}
  };
  const renderOrders=(orders)=>{
    if(!ordersList)return;ordersList.replaceChildren();
    const active=orders.filter((order)=>!terminalOrderStatuses.has(String(order?.status||'')));
    const latestActive=active.length?active[active.length-1]:null;
    const historyOnly=!latestActive&&orders.length>0;
    if(ordersBadge){ordersBadge.hidden=active.length===0;ordersBadge.textContent=`${numberFa(active.length)} سفارش`;}
    if(ordersBar){ordersBar.hidden=orders.length===0;ordersBar.classList.toggle('is-history-only',historyOnly);}
    if(ordersAction)ordersAction.textContent=historyOnly?'مشاهده سفارش‌های قبلی':'مشاهده';
    if(confirmationState)confirmationState.hidden=orders.length>0;
    if(latestActive){
      const presentation=activeOrderPresentation(latestActive);
      if(ordersLiveTitle)ordersLiveTitle.textContent=presentation.title;
      if(ordersLiveBody)ordersLiveBody.textContent=presentation.body;
      orderProgressSteps.forEach((step,index)=>step.classList.toggle('is-active',index<presentation.stage));
    }else{
      orderProgressSteps.forEach((step)=>step.classList.remove('is-active'));
    }
    orders.slice().reverse().forEach((order)=>{
      const card=document.createElement('article');card.className='sg-order-card';card.dataset.orderCode=order.order_code||'';
      const head=document.createElement('header');const title=document.createElement('strong');title.textContent=`سفارش ${order.order_number?numberFa(order.order_number):order.order_code||''}`;const status=document.createElement('span');status.className=`sg-order-status is-${String(order.status||'')}`;status.textContent=order.status_label||order.status||'';head.append(title,status);card.append(head);
      const lines=document.createElement('div');lines.className='sg-order-lines';(order.items||[]).slice(0,5).forEach(line=>{const row=document.createElement('div');const name=document.createElement('span');name.textContent=`${line.name||'آیتم'} × ${numberFa(line.quantity||0)}${line.fulfillment_mode==='takeaway'?' · بیرون‌بر':''}`;const price=document.createElement('strong');price.textContent=formatMoney(line.line_total||Number(line.unit_price||0)*Number(line.quantity||0));row.append(name,price);lines.append(row);});card.append(lines);
      const total=document.createElement('div');total.className='sg-order-total';total.innerHTML='<span>مبلغ سفارش</span>';const totalStrong=document.createElement('strong');totalStrong.textContent=formatMoney(order.final_amount??order.total_amount??0);total.append(totalStrong);card.append(total);
      if(order.customer_note){const note=document.createElement('p');note.textContent=`یادداشت: ${order.customer_note}`;card.append(note);}
      if(order.can_edit||order.can_cancel){const actions=document.createElement('div');actions.className='sg-order-actions';if(order.can_edit){const edit=document.createElement('button');edit.type='button';edit.dataset.sgEditOrder=order.order_code;edit.textContent='ویرایش سفارش';edit.disabled=!unsafeAllowed();actions.append(edit);}if(order.can_cancel){const cancel=document.createElement('button');cancel.type='button';cancel.dataset.sgCancelOrder=order.order_code;cancel.textContent='لغو سفارش';cancel.className='is-danger';cancel.disabled=!unsafeAllowed();actions.append(cancel);}card.append(actions);}
      card.__order=order;ordersList.append(card);
    });
  };
  q('[data-sg-open-orders]')?.addEventListener('click',async(event)=>{await refreshContext();await refreshOrders(true);layerTrigger=event.currentTarget;});
  ordersList?.addEventListener('click',async(event)=>{
    const edit=event.target.closest('[data-sg-edit-order]');const cancel=event.target.closest('[data-sg-cancel-order]');const card=event.target.closest('.sg-order-card');const order=card?.__order;if(!order)return;
    if(edit){clearGuestDraft();guestDraftRestoreDone=true;editingOrder=order;cart.clear();for(const row of order.items||[]){const id=Number(row.id),choice=Number(row.bundle_choice_item_id||0),known=itemMap.get(id);if(!known){itemMap.set(id,{id,name:row.name||'آیتم سفارش',description:'',category:'',price:Number(row.unit_price||0),available:false,order_available:false,takeaway_allowed:true,snapshot_from_order:true});}const key=cartLineKey(id,choice),line=cart.get(key)||{id,quantity:0,takeaway_quantity:0,note:row.note||'',snapshot_from_order:true,original_quantity:0,bundle_choice_item_id:choice,unit_price:Number(row.unit_price||known?.price||0)};line.quantity+=Number(row.quantity||0);line.original_quantity+=Number(row.quantity||0);line.bundle_choice_item_id=choice;line.unit_price=Number(row.unit_price||line.unit_price||known?.price||0);if(row.fulfillment_mode==='takeaway')line.takeaway_quantity+=Number(row.quantity||0);cart.set(key,line);}if(customerNote)customerNote.value=order.customer_note||'';renderCart();closeLayer();openCart(edit);toast(`در حال ویرایش سفارش ${order.order_number?numberFa(order.order_number):''}`);return;}
    if(cancel){if(!await guestConfirm('لغو سفارش','این سفارش لغو شود؟',{confirmLabel:'لغو سفارش'}))return;cancel.disabled=true;const payload={action:'cancel',table_token:tableToken,session_token:sessionToken,device_token:deviceToken,order_code:order.order_code,expected_signature:order.edit_signature,items:[]};try{preparePendingMutation('cancel',payload);const body=apiOk(await postJson(endpoints.orders,payload,12000),'لغو سفارش انجام نشد.');savePendingMutation(null);toast(body.status==='unchanged'?'وضعیت سفارش تازه شد.':'سفارش لغو شد.');await refreshContext();await refreshOrders(false);}catch(error){if(definitiveClientFailure(error)){savePendingMutation(null);toast(error?.message||'لغو سفارش انجام نشد.',true);}else{toast('نتیجه لغو در حال بازیابی است؛ دوباره لغو را نزن.');schedulePendingMutationRecovery();}}finally{cancel.disabled=Boolean(loadPendingMutation())||!unsafeAllowed();}}
  });

  const refreshContext=async()=>{
    if(!tableToken||!endpoints.context)return null;
    try{
      const body=apiOk(await postJson(endpoints.context,{table_token:tableToken,device_token:deviceToken},9000),'وضعیت میز تازه نشد.');
      setConnectivityState(body.degraded===true?'stale':'fresh',body.last_sync_at||lastSyncAt);
      const previousSession=String(sessionToken||''),nextToken=body.session?.token||body.pending_session?.token||'';if(nextToken){sessionToken=String(nextToken);root.dataset.sessionToken=sessionToken;}if(previousSession&&sessionToken&&previousSession!==sessionToken&&(cart.size||String(customerNote?.value||'').trim())){clearGuestDraft();guestDraftRestoreDone=true;editingOrder=null;cart.clear();if(customerNote)customerNote.value='';renderCart();toast('نشست میز تغییر کرده؛ پیش‌نویس قبلی برای جلوگیری از ثبت روی حساب جدید کنار گذاشته شد.',true);}
      orderingEnabled=body.can_order===true&&unsafeAllowed();root.dataset.orderingEnabled=orderingEnabled?'1':'0';setItemAvailability(body.order_acceptance||{});if(sessionToken)restoreGuestDraft();
      if(body.active_call&&!waiterState){waiterState={call_code:body.active_call.public_code||body.active_call.call_code||'',status:body.active_call.status||'new',owned:false,public_table_id:0,client_token:''};renderWaiter();}
      return body;
    }catch(_){setConnectivityState('offline',lastSyncAt);return null;}
  };
  const scheduleContext=()=>{if(contextTimer)globalThis.clearInterval(contextTimer);if(tableToken&&endpoints.context)contextTimer=globalThis.setInterval(()=>refreshContext(),30000);};

  const statusLabel=(status)=>({submitting:'در حال ارسال به کافه',pending_approval:'منتظر تأیید کافه',new:'منتظر تأیید کافه',accounted:'سفارش تأیید شده',completed:'سفارش تسویه شده',cancelled:'سفارش لغو شده',session_closed:'نشست میز پایان یافته'}[status]||status||'در حال دریافت وضعیت سفارش…');
  const successStatusText=(status)=>({submitting:'سفارش ارسال شد؛ نتیجه در حال ثبت در کافه است.',pending_approval:'سفارش ثبت شد و منتظر تأیید کافه است.',new:'سفارش ثبت شد و منتظر تأیید کافه است.',accounted:'سفارش تأیید و به حساب میز اضافه شد.',completed:'حساب میز تسویه و بسته شد.',cancelled:'سفارش لغو شد.',session_closed:'نشست این میز پایان یافته است.'}[status]||'در حال دریافت وضعیت سفارش…');
  const applySuccessStatus=(status)=>{const value=String(status||'new');if(successProgress)successProgress.dataset.status=value;if(successMessage)successMessage.textContent=successStatusText(value);successLive?.classList.toggle('is-confirmed',['accounted','completed'].includes(value));successLive?.classList.toggle('is-cancelled',['cancelled','session_closed'].includes(value));};
  const pollOrderStatus=async()=>{const tracker=loadTracker();if(!tracker||!endpoints.status)return false;try{const body=apiOk(await postJson(endpoints.status,{order_code:tracker.order_code,client_token:tracker.client_token},9000),'');const previous=tracker.status;tracker.status=body.status;saveTracker(tracker);applySuccessStatus(body.status);if(body.status&&body.status!==previous)toast(statusLabel(body.status));if(['completed','cancelled','session_closed'].includes(body.status)){saveTracker(null);return false;}return true;}catch(error){if(error?.code==='order_not_found'){saveTracker(null);return false;}if(successMessage)successMessage.textContent='فعلاً نمی‌تونیم وضعیت رو تازه کنیم؛ سفارشت قبلاً ارسال شده.';return true;}};
  const startOrderTracking=()=>{if(orderPollTimer)globalThis.clearInterval(orderPollTimer);if(!loadTracker())return;pollOrderStatus();orderPollTimer=globalThis.setInterval(async()=>{if(!(await pollOrderStatus())){globalThis.clearInterval(orderPollTimer);orderPollTimer=null;}},12000);};

  const isBusinessCommittedResponse=(body)=>String(body?.state||'')==='committed'&&(body?.business_terminal===true||body?.terminal===true);
  const finishPendingSubmission=async(body)=>{
    const pending=loadPendingSubmission();if(!pending)return false;
    if(isBusinessCommittedResponse(body)){
      const visibility=body.visibility&&typeof body.visibility==='object'?body.visibility:{state:'not_required',visible:true};
      const visibilityPending=String(visibility.state||'')==='pending'||visibility.visible===false;
      const result=body.result&&typeof body.result==='object'?body.result:{};
      savePendingSubmission(null);clearGuestDraft();clearOperationToken(orderTokenKey);if(submit)submit.dataset.busy='0';
      const tracker={order_code:String(result.order_code||''),client_token:String(result.client_token||body.client_token||pending.client_token||''),order_number:Number(result.order_number||0),status:String(result.status||'pending_approval')};
      if(tracker.order_code)saveTracker(tracker);
      editingOrder=null;cart.clear();if(customerNote)customerNote.value='';renderCart();closeLayer();
      if(successCode)successCode.textContent=tracker.order_number?`سفارش ${numberFa(tracker.order_number)}`:(tracker.order_code?`کد ${tracker.order_code}`:'ثبت شد');
      applySuccessStatus(tracker.status);
      if(visibilityPending){
        if(successMessage)successMessage.textContent='سفارش در کافه ثبت شد؛ نمایش در حال تازه‌شدن است.';
        if(orderMessage){orderMessage.classList.remove('is-error');orderMessage.textContent='سفارش در کافه ثبت شد؛ نمایش منو و حساب در پس‌زمینه تازه می‌شود.';}
      }else if(orderMessage){orderMessage.classList.remove('is-error');orderMessage.textContent='سفارش ثبت شد و منتظر تأیید کافه است.';}
      if(successModal)openLayer(successModal,submit);toast(visibilityPending?'سفارش در کافه ثبت شد.':'سفارش ثبت شد.');metric('order_success',{status:tracker.status,visibility:visibilityPending?'pending':'visible'});
      startOrderTracking();
      Promise.resolve().then(async()=>{await refreshContext();await refreshOrders(false);}).catch(()=>{});
      return false;
    }
    if(body.terminal!==true)return true;
    savePendingSubmission(null);if(submit)submit.dataset.busy='0';clearOperationToken(orderTokenKey);const result=body.result&&typeof body.result==='object'?body.result:{};const msg=String(result.message||body.error_code||'سفارش ثبت نشد؛ دوباره بررسی کن.');
    closeLayer();if(orderMessage){orderMessage.classList.add('is-error');orderMessage.textContent=msg;}toast(msg,true);renderCart();return false;
  };
  const submissionPollDelay=(pending,failed=false)=>{const age=Math.max(0,Date.now()-Number(pending?.created_at||Date.now()));if(failed){const step=Math.min(5,submissionPollFailures);return Math.min(8000,750*(2**Math.max(0,step-1)));}if(age<15000)return 450;if(age<60000)return 1000;if(age<5*60000)return 2000;return 5000;};
  const stopSubmissionTracking=()=>{submissionPollGeneration++;if(submissionPollTimer)globalThis.clearTimeout(submissionPollTimer);submissionPollTimer=null;submissionPollFailures=0;};
  const replayPreparedSubmission=async(pending)=>{if(pending?.stage!=='prepared'||!pending.intent||Number(pending.replay_attempts||0)>=2)return null;const next={...pending,replay_attempts:Number(pending.replay_attempts||0)+1};if(!savePendingSubmission(next))return null;try{const result=apiOk(await postJson(endpoints.order,{table_token:tableToken,session_token:String(next.intent.session_token||''),device_token:String(next.intent.device_token||deviceToken),client_token:next.client_token,customer_note:String(next.intent.customer_note||''),items:Array.isArray(next.intent.items)?next.intent.items:[]},5000),'ارسال سفارش انجام نشد.');if(isBusinessCommittedResponse(result))return {terminal_body:result,pending:next};if(result.terminal===false&&result.request_id)return markPendingAccepted(next,result);if(result.terminal===true){return {terminal_body:result,pending:next};}return next;}catch(error){if(definitiveClientFailure(error)){savePendingSubmission(null);throw error;}return next;}};
  const pollSubmissionStatus=async(pending,generation)=>{if(!pending||!endpoints.orders)return false;try{const statusSession=String(pending.intent?.session_token||sessionToken),statusDevice=String(pending.intent?.device_token||deviceToken);const body=apiOk(await postJson(endpoints.orders,{action:'submission_status',table_token:tableToken,session_token:statusSession,device_token:statusDevice,client_token:pending.client_token,request_id:pending.request_id||''},2500),'');if(generation!==submissionPollGeneration)return true;let current=loadPendingSubmission();if(!current||current.client_token!==pending.client_token||(pending.request_id&&current.request_id!==pending.request_id))return false;if(body.request_id&&!current.request_id)current=markPendingAccepted(current,body);const traceId=/^skt1-[a-f0-9]{32}$/.test(String(body.trace_id||''))?String(body.trace_id):current.trace_id||'';if(traceId!==current.trace_id)savePendingSubmission({...current,trace_id:traceId});submissionPollFailures=0;return await finishPendingSubmission(body);}catch(error){if(generation!==submissionPollGeneration)return true;submissionPollFailures++;if(error?.code==='submission_not_found'){const current=loadPendingSubmission();const age=Math.max(0,Date.now()-Number(current?.created_at||Date.now()));if(current?.stage==='prepared'&&current.intent&&age>=1200&&Number(current.replay_attempts||0)<2){try{const replayed=await replayPreparedSubmission(current);if(replayed?.terminal_body){submissionPollFailures=0;return await finishPendingSubmission(replayed.terminal_body);}if(replayed&&orderMessage){orderMessage.classList.remove('is-error');orderMessage.textContent='ارسال با همان شناسه بازیابی شد؛ نتیجه در حال پیگیری است.';}return true;}catch(replayError){if(orderMessage){orderMessage.classList.add('is-error');orderMessage.textContent=replayError?.message||'ارسال سفارش رد شد.';}return false;}}if(orderMessage){orderMessage.classList.remove('is-error');orderMessage.textContent='نتیجه ارسال هنوز پیدا نشده؛ برای جلوگیری از ثبت دوباره، سفارش را دوباره ارسال نکن.';}return true;}if(orderMessage&&!localLanTransport&&navigator.onLine===false){orderMessage.textContent='اینترنت در دسترس نیست؛ وضعیت ارسال پس از اتصال دوباره پیگیری می‌شود.';}return true;}};
  const scheduleSubmissionPoll=(generation,delay)=>{if(generation!==submissionPollGeneration)return;if(submissionPollTimer)globalThis.clearTimeout(submissionPollTimer);submissionPollTimer=globalThis.setTimeout(()=>runSubmissionPoll(generation),Math.max(0,delay));};
  const runSubmissionPoll=async(generation)=>{if(generation!==submissionPollGeneration)return;const pending=loadPendingSubmission();if(!pending){stopSubmissionTracking();return;}if(document.hidden||(!localLanTransport&&navigator.onLine===false)){scheduleSubmissionPoll(generation,1000);return;}if(submissionPollInFlight){scheduleSubmissionPoll(generation,250);return;}submissionPollInFlight=true;let keep=true;try{keep=await pollSubmissionStatus(pending,generation);}finally{submissionPollInFlight=false;}if(generation!==submissionPollGeneration)return;if(!keep){stopSubmissionTracking();return;}const current=loadPendingSubmission();if(!current){stopSubmissionTracking();return;}scheduleSubmissionPoll(generation,submissionPollDelay(current,submissionPollFailures>0));};
  function startSubmissionTracking(){if(submissionPollTimer)globalThis.clearTimeout(submissionPollTimer);const pending=loadPendingSubmission();if(!pending)return;const generation=++submissionPollGeneration;if(submit){submit.dataset.busy='1';submit.disabled=true;}applySuccessStatus('submitting');scheduleSubmissionPoll(generation,0);}
  document.addEventListener('visibilitychange',()=>{if(!document.hidden&&loadPendingSubmission())startSubmissionTracking();});

  const waiter=q('[data-sg-waiter]'),waiterLabel=q('[data-sg-waiter-label]'),tableModal=q('[data-sg-table-modal]'),tableGrid=q('[data-sg-public-table-grid]'),waiterConfirmModal=q('[data-sg-waiter-confirm-modal]'),waiterConfirm=q('[data-sg-waiter-confirm]'),publicTableConfirm=q('[data-sg-public-table-confirm]'),publicTableSelection=q('[data-sg-public-table-selection]');
  const waiterSentLabel=root.dataset.waiterSentLabel||'گارسون خبر شده',waiterAcceptedLabel=root.dataset.waiterAcceptedLabel||'همکارمون در راهه';let selectedPublicTable=0;
  const waiterStoreKey=`sokna.guest.waiter.${tableToken||'public'}`,waiterStoreMaxAgeMs=24*60*60*1000;
  const saveWaiter=()=>{const encoded=waiterState?JSON.stringify({v:1,table_token:String(tableToken),device_token:String(deviceToken),updated_at:Date.now(),expires_at:Date.now()+waiterStoreMaxAgeMs,state:waiterState}):'';try{waiterState?localStorage.setItem(waiterStoreKey,encoded):localStorage.removeItem(waiterStoreKey);try{sessionStorage.removeItem(waiterStoreKey);}catch(_){}return;}catch(_){try{waiterState?sessionStorage.setItem(waiterStoreKey,encoded):sessionStorage.removeItem(waiterStoreKey);}catch(__){}}};
  const loadWaiter=()=>{let value=null;try{value=JSON.parse(localStorage.getItem(waiterStoreKey)||'null');if(!value){value=JSON.parse(sessionStorage.getItem(waiterStoreKey)||'null');if(value){try{localStorage.setItem(waiterStoreKey,JSON.stringify(value));}catch(_){}}}}catch(_){try{value=JSON.parse(sessionStorage.getItem(waiterStoreKey)||'null')}catch(__){value=null}}if(!value)return null;if(value.v===1&&value.state){const valid=String(value.table_token||'')===String(tableToken)&&String(value.device_token||'')===String(deviceToken)&&Number(value.expires_at||0)>Date.now();if(!valid){waiterState=null;saveWaiter();return null;}return value.state;}if(value.call_code){return value;}return null;};
  waiterState=loadWaiter();if(waiterState?.call_code)saveWaiter();
  const renderWaiter=()=>{if(!waiter||!waiterLabel)return;const status=String(waiterState?.status||'none');if(status==='new'){waiterLabel.textContent=waiterState?.owned?'لغو فراخوان گارسون':waiterSentLabel;waiter.disabled=!unsafeAllowed()||!waiterState?.owned;}else if(status==='accepted'){waiterLabel.textContent=waiterAcceptedLabel;waiter.disabled=true;}else{waiterLabel.textContent='فراخوان گارسون';waiter.disabled=!unsafeAllowed();}if(waiterConfirm)waiterConfirm.disabled=!unsafeAllowed();};
  const createWaiter=async(publicTableId=0)=>{const tokenKey=`sokna.guest.pending-waiter.${tableToken||publicTableId}`;const signature=JSON.stringify({table:tableToken,public_table_id:publicTableId,action:'create'});const clientToken=operationToken(tokenKey,signature);const body=apiOk(await postJson(endpoints.waiter,{action:'create',table_token:tableToken,public_table_id:publicTableId,session_token:sessionToken,device_token:deviceToken,client_token:clientToken},15000),'ارسال فراخوان انجام نشد.');clearOperationToken(tokenKey);waiterState={call_code:body.call_code||'',status:body.status||'new',owned:body.owned!==false,public_table_id:publicTableId,client_token:clientToken};saveWaiter();renderWaiter();toast(body.shared?'گارسون از قبل برای این میز فراخوان شده بود.':'گارسون مطلع شد.');startWaiterTracking();};
  const cancelWaiter=async()=>{if(!waiterState?.owned||!waiterState.call_code)return;const body=apiOk(await postJson(endpoints.waiter,{action:'cancel',table_token:tableToken,public_table_id:waiterState.public_table_id||0,session_token:sessionToken,device_token:deviceToken,client_token:waiterState.client_token||'',call_code:waiterState.call_code},10000),'لغو فراخوان انجام نشد.');if(body.status==='cancelled'||body.status==='unchanged'){waiterState=null;saveWaiter();renderWaiter();toast(body.status==='cancelled'?'فراخوان لغو شد.':'وضعیت فراخوان تغییر کرده است.');}};
  const pollWaiter=async()=>{if(!waiterState?.call_code||!endpoints.waiter)return false;try{const body=apiOk(await postJson(endpoints.waiter,{action:'status',table_token:tableToken,public_table_id:waiterState.public_table_id||0,session_token:sessionToken,device_token:deviceToken,client_token:waiterState.client_token||'',call_code:waiterState.call_code},8000),'');if(['none','done','cancelled'].includes(body.status)){waiterState=null;saveWaiter();renderWaiter();return false;}waiterState.status=body.status||waiterState.status;waiterState.owned=body.owned??waiterState.owned;saveWaiter();renderWaiter();return true;}catch(_){return true;}};
  const startWaiterTracking=()=>{if(waiterPollTimer)globalThis.clearInterval(waiterPollTimer);if(!waiterState?.call_code)return;pollWaiter();waiterPollTimer=globalThis.setInterval(async()=>{if(!(await pollWaiter())){globalThis.clearInterval(waiterPollTimer);waiterPollTimer=null;}},12000);};
  if(tableGrid){try{selectedPublicTable=Number(localStorage.getItem('sokna.guest.public-waiter-table')||0);}catch(_){};publicTables.forEach(t=>{const button=document.createElement('button');button.type='button';button.dataset.sgPublicTable=String(t.id);button.textContent=faDigits(t.name||`میز ${t.code||t.id}`);button.classList.toggle('is-selected',Number(t.id)===selectedPublicTable);tableGrid.append(button);});const updatePublicSelection=()=>{const table=publicTables.find(t=>Number(t.id)===Number(selectedPublicTable));if(publicTableSelection)publicTableSelection.textContent=table?faDigits(table.name||`میز ${table.code||table.id}`):'میزت را انتخاب کن';if(publicTableConfirm)publicTableConfirm.disabled=!unsafeAllowed()||!table;qa('[data-sg-public-table]',tableGrid).forEach(b=>b.classList.toggle('is-selected',Number(b.dataset.sgPublicTable)===Number(selectedPublicTable)));};tableGrid.addEventListener('click',(event)=>{const button=event.target.closest('[data-sg-public-table]');if(!button)return;selectedPublicTable=Number(button.dataset.sgPublicTable||0);try{localStorage.setItem('sokna.guest.public-waiter-table',String(selectedPublicTable));}catch(_){}updatePublicSelection();});updatePublicSelection();}
  publicTableConfirm?.addEventListener('click',async()=>{if(!selectedPublicTable)return;publicTableConfirm.disabled=true;try{await createWaiter(selectedPublicTable);closeLayer();}catch(error){toast(error?.message||'ارسال فراخوان انجام نشد.',true);}finally{publicTableConfirm.disabled=false;}});
  waiterConfirm?.addEventListener('click',async()=>{waiterConfirm.disabled=true;try{await createWaiter(0);closeLayer();}catch(error){toast(error?.message||'ارسال فراخوان انجام نشد.',true);}finally{waiterConfirm.disabled=false;renderWaiter();}});
  waiter?.addEventListener('click',async(event)=>{if(!endpoints.waiter)return;if(waiterState?.owned&&waiterState.status==='new'){waiter.disabled=true;try{await cancelWaiter();}catch(error){toast(error?.message||'لغو فراخوان انجام نشد.',true);}finally{renderWaiter();}return;}if(waiterState)return;if(!tableToken){openLayer(tableModal,event.currentTarget);return;}openLayer(waiterConfirmModal,event.currentTarget);});

  const connectivityBanner=q('[data-sg-action-state]'),connectivityTitle=q('[data-sg-action-title]'),connectivityCopy=q('[data-sg-action-copy]'),connectivitySync=q('[data-sg-last-sync]');
  const setConnectivityState=(state,syncAt='')=>{
    const next=['fresh','stale','offline'].includes(String(state))?String(state):'stale';
    connectivityState=next;root.dataset.connectivityState=next;
    if(syncAt){lastSyncAt=String(syncAt);root.dataset.lastSyncAt=lastSyncAt;}
    if(next!=='fresh'){orderingEnabled=false;root.dataset.orderingEnabled='0';}
    if(connectivityBanner){
      connectivityBanner.dataset.state=next;
      connectivityBanner.classList.toggle('is-ready',next==='fresh');
      connectivityBanner.classList.toggle('is-degraded',next!=='fresh');
      connectivityBanner.classList.toggle('is-offline',next==='offline');
      connectivityBanner.hidden=next==='fresh';
    }
    if(connectivityTitle)connectivityTitle.textContent=next==='offline'?'ارتباط موقتاً قطع شده':'منو در حالت فقط‌خواندنی است';
    if(connectivityCopy)connectivityCopy.textContent=next==='offline'?'منو همچنان قابل مشاهده است؛ برای ثبت سفارش منتظر اتصال دوباره باشید.':'اطلاعات ذخیره‌شده منو نمایش داده می‌شود؛ سفارش تازه موقتاً غیرفعال است.';
    if(connectivitySync){const label=syncAgeLabel(lastSyncAt);connectivitySync.textContent=label;connectivitySync.hidden=!label;}
    qa('[data-sg-item-controls] button').forEach(button=>{const controls=button.closest('[data-sg-item-controls]');const id=Number(controls?.dataset.sgItemControls||0),item=itemMap.get(id);button.disabled=!unsafeAllowed()||!orderingEnabled||!item?.order_available;});
    qa('[data-sg-edit-order],[data-sg-cancel-order]').forEach(button=>{button.disabled=!unsafeAllowed()||Boolean(loadPendingMutation());});
    renderCart();renderWaiter();
    if(tableGrid){const table=publicTables.find(t=>Number(t.id)===Number(selectedPublicTable));if(publicTableConfirm)publicTableConfirm.disabled=!unsafeAllowed()||!table;}
    if(currentDetailId)renderDetail(currentDetailId,false);
  };

  root.addEventListener('click',(event)=>{const target=event.target.closest('[data-sg-metric]');if(target)metric(target.dataset.sgMetric||'guest_click',{href:target.getAttribute('href')||''});});

  let projectionCursor='',projectionWatchStopped=false,projectionWatchRunning=false,projectionWatchGeneration=0,projectionWatchAbort=null;
  const refreshProjectedGuestState=async()=>{
    if(document.hidden)return;
    await refreshContext();
    await refreshOrders(false);
    if(loadTracker())await pollOrderStatus();
    if(waiterState?.call_code)await pollWaiter();
  };
  const stopProjectionWatch=(reason='lifecycle')=>{projectionWatchStopped=true;projectionWatchGeneration++;if(projectionWatchAbort){try{projectionWatchAbort.abort(reason);}catch(_){}projectionWatchAbort=null;}};
  const projectionWatch=async()=>{
    if(!endpoints.changes||projectionWatchStopped||projectionWatchRunning||document.hidden)return;
    projectionWatchRunning=true;const generation=projectionWatchGeneration;
    try{
      while(!projectionWatchStopped&&!document.hidden&&generation===projectionWatchGeneration){
        const controller=typeof AbortController!=='undefined'?new AbortController():null;projectionWatchAbort=controller;
        try{
          const body=apiOk(await postJson(endpoints.changes,{cursor:projectionCursor,wait_ms:9000},12000,controller?{signal:controller.signal}:{}),'');
          if(generation!==projectionWatchGeneration||projectionWatchStopped||document.hidden)break;
          const nextState=body.stale===true?'stale':'fresh',recovering=nextState==='fresh'&&connectivityState!=='fresh';
          if(recovering&&tableToken){if(body.last_sync_at){lastSyncAt=String(body.last_sync_at);root.dataset.lastSyncAt=lastSyncAt;}await refreshContext();}else{setConnectivityState(nextState,body.last_sync_at||lastSyncAt);}
          const next=String(body.cursor||'');const changed=body.changed===true&&projectionCursor!=='';
          projectionCursor=next||projectionCursor;
          try{root.dispatchEvent(new CustomEvent('sokna:guest-watch-meta',{detail:body.watch||{}}));}catch(_){}
          if(changed&&tableToken)await refreshProjectedGuestState();
          else{const retry=Math.max(0,Math.min(1500,Number(body.watch?.retry_after_ms||0)));if(retry)await new Promise(resolve=>globalThis.setTimeout(resolve,retry));}
        }catch(error){if(controller?.signal?.aborted||generation!==projectionWatchGeneration||projectionWatchStopped||document.hidden)break;setConnectivityState('offline',lastSyncAt);await new Promise(resolve=>globalThis.setTimeout(resolve,1200));}
        finally{if(projectionWatchAbort===controller)projectionWatchAbort=null;}
      }
    }finally{projectionWatchRunning=false;if(!projectionWatchStopped&&!document.hidden)queueMicrotask(()=>projectionWatch());}
  };
  const resumeProjectionWatch=(refresh=false)=>{projectionWatchStopped=false;projectionWatchGeneration++;if(refresh&&tableToken)refreshProjectedGuestState().catch(()=>{});projectionWatch();};
  document.addEventListener('visibilitychange',()=>{if(document.hidden)stopProjectionWatch('hidden');else resumeProjectionWatch(true);});
  globalThis.addEventListener('offline',()=>{
    // OS/browser "offline" describes Internet reachability, not the cafe LAN.
    // In local_lan mode the Guest API itself decides availability; do not disable
    // ordering merely because WAN disappeared. A failed LAN probe/watch will move
    // connectivity to offline through the normal request error path.
    if(localLanTransport){resumeProjectionWatch(true);if(loadPendingSubmission())startSubmissionTracking();return;}
    setConnectivityState('offline',lastSyncAt);stopProjectionWatch('offline');
  });
  globalThis.addEventListener('online',()=>{setConnectivityState('stale',lastSyncAt);if(loadPendingSubmission())startSubmissionTracking();if(loadPendingMutation())schedulePendingMutationRecovery(250);if(tableToken)refreshContext().catch(()=>{});resumeProjectionWatch(false);});
  globalThis.addEventListener('pagehide',()=>stopProjectionWatch('pagehide'));
  globalThis.addEventListener('pageshow',()=>{resumeProjectionWatch(true);if(loadPendingMutation())schedulePendingMutationRecovery(250);});

  // Restore inline state from catalog and make the initial browser surface useful immediately.
  bindMediaFallbacks();renderCart();renderWaiter();startSubmissionTracking();startOrderTracking();startWaiterTracking();if(loadPendingMutation())schedulePendingMutationRecovery(900);scheduleContext();setConnectivityState(connectivityState,lastSyncAt);
  if(tableToken)refreshContext().then(()=>refreshOrders(false));
  projectionWatch();
})();
