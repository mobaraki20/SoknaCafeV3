(()=>{
  'use strict';
  const FA='۰۱۲۳۴۵۶۷۸۹', AR='٠١٢٣٤٥٦٧٨٩';
  const toLatinDigits=value=>String(value??'')
    .replace(/[۰-۹]/g,ch=>String(FA.indexOf(ch)))
    .replace(/[٠-٩]/g,ch=>String(AR.indexOf(ch)));
  const toPersianDigits=value=>toLatinDigits(value).replace(/\d/g,d=>FA[Number(d)]);
  const normalizeNumber=(value,{decimal=false,signed=false}={})=>{
    let s=toLatinDigits(value).trim().replace(/[\s,٬\u061c\u200e\u200f]/g,'').replace(/−/g,'-').replace(/٫/g,'.');
    const sign=signed&&/^-/.test(s)?'-':'';
    s=s.replace(/[+-]/g,'');
    if(decimal){
      s=s.replace(/[^0-9.]/g,'');
      const i=s.indexOf('.'); if(i>=0)s=s.slice(0,i+1)+s.slice(i+1).replace(/\./g,'');
    }else s=s.replace(/\D/g,'');
    return sign+s;
  };
  const numericValue=(value,options={})=>{
    const n=Number(normalizeNumber(value,options));return Number.isFinite(n)?n:0;
  };
  const nfCache=new Map();
  const formatter=options=>{
    const key=JSON.stringify(options||{});if(!nfCache.has(key))nfCache.set(key,new Intl.NumberFormat('fa-IR-u-nu-arabext',options||{}));return nfCache.get(key);
  };
  const number=(value,options={})=>formatter({maximumFractionDigits:3,...options}).format(numericValue(value,{decimal:true,signed:true}));
  const integer=value=>formatter({maximumFractionDigits:0}).format(numericValue(value,{decimal:true,signed:true}));
  const moneyNumber=value=>integer(value);
  const money=value=>`${moneyNumber(value)} تومان`;
  const percent=value=>`${number(value,{maximumFractionDigits:2})}٪`;
  const humanDigits=value=>toPersianDigits(value);
  const formatMoneyInput=(value,{signed=false}={})=>{
    const normalized=normalizeNumber(value,{decimal:false,signed});
    if(normalized==='')return '';
    const negative=signed&&normalized.startsWith('-'),raw=negative?normalized.slice(1):normalized;
    const compact=raw.replace(/^0+(?=\d)/,'')||'0';
    return `${negative?'-':''}${toPersianDigits(compact.replace(/\B(?=(\d{3})+(?!\d))/g,'٬'))}`;
  };
  const canonical=value=>toLatinDigits(value);
  const phone=value=>toPersianDigits(value);
  const pad=value=>String(value).padStart(2,'0');
  const g2j=(gy,gm,gd)=>{const gdm=[0,31,59,90,120,151,181,212,243,273,304,334],gy2=gm>2?gy+1:gy;let days=355666+365*gy+Math.floor((gy2+3)/4)-Math.floor((gy2+99)/100)+Math.floor((gy2+399)/400)+gd+gdm[gm-1],jy=-1595+33*Math.floor(days/12053);days%=12053;jy+=4*Math.floor(days/1461);days%=1461;if(days>365){jy+=Math.floor((days-1)/365);days=(days-1)%365}if(days<186)return[jy,1+Math.floor(days/31),1+(days%31)];return[jy,7+Math.floor((days-186)/30),1+((days-186)%30)]};
  const parseDateParts=value=>{
    const m=toLatinDigits(value).trim().match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2})(?:\.\d{1,9})?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/i);
    if(!m)return null;
    const p={y:Number(m[1]),mo:Number(m[2]),d:Number(m[3]),h:m[4]===undefined?null:Number(m[4]),mi:m[5]===undefined?null:Number(m[5]),s:m[6]===undefined?0:Number(m[6])};
    const check=new Date(Date.UTC(p.y,p.mo-1,p.d));
    if(check.getUTCFullYear()!==p.y||check.getUTCMonth()+1!==p.mo||check.getUTCDate()!==p.d||p.h!==null&&p.h>23||p.mi!==null&&p.mi>59||p.s>59)return null;
    return p;
  };
  const hasOffset=value=>/(?:Z|[+-]\d{2}:?\d{2})$/i.test(String(value).trim());
  const displayParts=value=>hasOffset(value)?instantParts(value):parseDateParts(value);
  const date=value=>{if(value==null||String(value).trim()==='')return '—';const p=displayParts(value);if(!p)return '—';const j=g2j(Number(p.y),Number(p.mo),Number(p.d));return toPersianDigits(`${j[0]}/${pad(j[1])}/${pad(j[2])}`)};
  const time=value=>{if(value==null||String(value).trim()==='')return '—';const raw=toLatinDigits(value).trim(),m=raw.match(/^(\d{1,2}):(\d{2})(?::\d{2})?$/);if(m){return Number(m[1])<24&&Number(m[2])<60?toPersianDigits(`${pad(m[1])}:${pad(m[2])}`):'—'}const p=displayParts(raw);return p&&p.h!==null?toPersianDigits(`${pad(p.h)}:${pad(p.mi)}`):'—'};
  const dateTime=value=>{if(value==null||String(value).trim()==='')return '—';const p=displayParts(value);return p?(p.h===null?date(value):`${date(value)} · ${time(value)}`):'—'};
  const appTimezone=()=>'Asia/Tehran';
  let canonicalClockAnchor=null;
  const canonicalNow=()=>{
    if(!canonicalClockAnchor){
      const raw=document.body?.dataset?.serverNow||'';
      const parsed=Date.parse(raw);
      canonicalClockAnchor={epoch:Number.isFinite(parsed)?parsed:Date.now(),mono:typeof performance!=='undefined'?performance.now():0};
    }
    const elapsed=typeof performance!=='undefined'?performance.now()-canonicalClockAnchor.mono:0;
    return new Date(canonicalClockAnchor.epoch+Math.max(0,elapsed));
  };
  const nowInstant=()=>canonicalNow().toISOString();
  // Bare values passed to the instant API are UTC by contract, never browser local.
  const parseInstant=value=>{if(value==null)return null;let raw=toLatinDigits(value).trim();const p=parseDateParts(raw);if(!p||p.h===null)return null;raw=raw.replace(' ','T');if(!hasOffset(raw))raw+='Z';const d=new Date(raw);return Number.isNaN(d.getTime())?null:d};
  const localWallInstant=value=>{
    if(hasOffset(value))return parseInstant(value);
    const p=parseDateParts(value);if(!p||p.h===null)return null;
    const wall=Date.UTC(p.y,p.mo-1,p.d,p.h,p.mi,p.s||0);let epoch=wall;
    for(let i=0;i<3;i++){const z=instantParts(new Date(epoch).toISOString());if(!z)return null;const projected=Date.UTC(Number(z.y),Number(z.mo)-1,Number(z.d),Number(z.h),Number(z.mi),Number(z.s));epoch+=wall-projected;}
    return new Date(epoch);
  };
  const instantParts=(value,timeZone=appTimezone())=>{const d=parseInstant(value);if(!d)return null;try{const parts=new Intl.DateTimeFormat('en-CA',{calendar:'gregory',numberingSystem:'latn',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit',hourCycle:'h23',timeZone}).formatToParts(d),m=Object.fromEntries(parts.filter(x=>x.type!=='literal').map(x=>[x.type,x.value]));return{y:m.year,mo:m.month,d:m.day,h:m.hour,mi:m.minute,s:m.second}}catch(_){return null}};
  const instantDateTime=(value,timeZone=appTimezone())=>{const p=instantParts(value,timeZone);if(!p)return value==null||String(value).trim()===''?'—':humanDigits(value);return `${date(`${p.y}-${p.mo}-${p.d}`)} · ${toPersianDigits(`${p.h}:${p.mi}`)}`};
  const instantTime=(value,timeZone=appTimezone())=>{const p=instantParts(value,timeZone);if(!p)return value==null||String(value).trim()===''?'—':humanDigits(value);return toPersianDigits(`${p.h}:${p.mi}`)};
  const civilDateNow=(timeZone=appTimezone())=>{const p=instantParts(nowInstant(),timeZone);return p?`${p.y}-${p.mo}-${p.d}`:''};
  const localDateTimeNow=(timeZone=appTimezone())=>{const p=instantParts(nowInstant(),timeZone);return p?`${p.y}-${p.mo}-${p.d}T${p.h}:${p.mi}`:''};
  const localWallAgeMinutes=value=>{const d=localWallInstant(value);return d?Math.max(0,Math.floor((canonicalNow().getTime()-d.getTime())/60000)):null};
  const localForm=(value,{basis='local'}={})=>{const d=basis==='UTC'?parseInstant(value):localWallInstant(value);if(!d)return '';const p=instantParts(d.toISOString());return p?`${p.y}-${p.mo}-${p.d}T${p.h}:${p.mi}`:''};
  const isSpecialNumericWidget=input=>input.closest?.('.sc-jalali-control,.sc-time-control')||input.classList.contains('sc-jalali-date')||input.classList.contains('sc-jalali-time')||input.classList.contains('sc-time-display');
  const isHumanNumericInput=input=>input instanceof HTMLInputElement&&!isSpecialNumericWidget(input)&&(input.dataset.faNumber==='1'||input.dataset.moneyInput==='1'||['numeric','decimal'].includes(input.inputMode));
  const isMoneyInput=input=>input instanceof HTMLInputElement&&input.dataset.moneyInput==='1';
  const numericInputOptions=input=>({decimal:isMoneyInput(input)?false:input.inputMode==='decimal',signed:isMoneyInput(input)?input.dataset.moneySigned==='1':true});
  const validateHumanNumericInput=input=>{
    if(!isHumanNumericInput(input)||input.disabled)return true;
    const raw=normalizeNumber(input.value,numericInputOptions(input));
    let message='';
    if(raw===''){
      if(input.required)message='این مقدار لازم است.';
    }else{
      const n=Number(raw);
      if(!Number.isFinite(n))message='عدد واردشده معتبر نیست.';
      else{
        const minRaw=input.getAttribute('min'),maxRaw=input.getAttribute('max');
        const min=minRaw===null?null:Number(minRaw),max=maxRaw===null?null:Number(maxRaw);
        if(min!==null&&Number.isFinite(min)&&n<min)message=`کمترین مقدار مجاز ${toPersianDigits(minRaw)} است.`;
        else if(max!==null&&Number.isFinite(max)&&n>max)message=`بیشترین مقدار مجاز ${toPersianDigits(maxRaw)} است.`;
      }
    }
    input.setCustomValidity(message);
    return message==='';
  };
  const normalizeForm=form=>{
    if(!(form instanceof HTMLFormElement))return true;
    let ok=true;
    form.querySelectorAll('input[inputmode="numeric"],input[inputmode="decimal"],input[data-fa-number="1"],input[type="tel"]').forEach(input=>{
      if(input.disabled||input.readOnly)return;
      if(isSpecialNumericWidget(input))return;
      if(input.type==='tel'){input.value=toLatinDigits(input.value);return}
      if(!validateHumanNumericInput(input)){ok=false;return}
      input.value=normalizeNumber(input.value,numericInputOptions(input));
    });
    return ok;
  };
  const humanizeNumericInput=input=>{
    if(!isHumanNumericInput(input)||input.disabled||input.readOnly||input.dataset.canonicalNumeric==='1')return;
    const start=input.selectionStart,end=input.selectionEnd;
    if(isMoneyInput(input)){
      const before=String(input.value??'').slice(0,start??String(input.value??'').length);
      const digitsBefore=normalizeNumber(before,{decimal:false,signed:false}).length;
      const next=formatMoneyInput(input.value,{signed:input.dataset.moneySigned==='1'});if(next===input.value)return;input.value=next;
      if(start!==null){let seen=0,pos=0;for(;pos<next.length;pos++){if(/[۰-۹0-9]/.test(next[pos]))seen++;if(seen>=digitsBefore)break}try{input.setSelectionRange(digitsBefore?pos+1:0,digitsBefore?pos+1:0)}catch{}}
      return;
    }
    const next=toPersianDigits(input.value);if(next===input.value)return;input.value=next;try{input.setSelectionRange(start,end)}catch{}
  };
  const humanizeNumericTree=root=>{
    const scope=root instanceof Element||root instanceof Document||root instanceof DocumentFragment?root:document;
    if(scope instanceof HTMLInputElement){validateHumanNumericInput(scope);humanizeNumericInput(scope);return}
    scope.querySelectorAll?.('input[inputmode="numeric"],input[inputmode="decimal"],input[data-fa-number="1"]').forEach(input=>{validateHumanNumericInput(input);humanizeNumericInput(input)});
  };
  document.addEventListener('input',event=>{validateHumanNumericInput(event.target);humanizeNumericInput(event.target)},true);
  document.addEventListener('focusin',event=>{validateHumanNumericInput(event.target);humanizeNumericInput(event.target)},true);
  document.addEventListener('change',event=>{validateHumanNumericInput(event.target);humanizeNumericInput(event.target)},true);
  document.addEventListener('submit',event=>{
    const form=event.target;if(!(form instanceof HTMLFormElement))return;
    const invalid=[...form.querySelectorAll('input[data-fa-number="1"],input[inputmode="numeric"],input[inputmode="decimal"]')].find(input=>!validateHumanNumericInput(input));
    if(invalid){event.preventDefault();humanizeNumericTree(form);invalid.reportValidity?.();invalid.focus?.({preventScroll:true});return}
    normalizeForm(form);
    queueMicrotask(()=>{if(event.defaultPrevented)humanizeNumericTree(form)});
  },true);
  const startHumanizer=()=>{
    humanizeNumericTree(document);
    if(typeof MutationObserver==='function')new MutationObserver(records=>{for(const record of records){if(record.type==='childList')record.addedNodes.forEach(node=>{if(node.nodeType===1)humanizeNumericTree(node)});else if(record.type==='attributes'&&record.target?.nodeType===1)humanizeNumericTree(record.target)}}).observe(document.documentElement,{subtree:true,childList:true,attributes:true,attributeFilter:['open','hidden']});
  };
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',startHumanizer,{once:true});else startHumanizer();
  window.SoknaLocale=Object.freeze({toLatinDigits,toPersianDigits,humanDigits,formatMoneyInput,canonical,phone,normalizeNumber,numericValue,number,integer,moneyNumber,money,percent,date,time,dateTime,instantDateTime,instantTime,parseInstant,localWallInstant,localForm,nowInstant,civilDateNow,localDateTimeNow,localWallAgeMinutes,normalizeForm,humanizeNumericInput,humanizeNumericTree,validateHumanNumericInput});
})();
