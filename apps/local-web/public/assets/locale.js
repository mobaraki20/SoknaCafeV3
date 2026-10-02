(()=>{
  'use strict';
  const FA='۰۱۲۳۴۵۶۷۸۹', AR='٠١٢٣٤٥٦٧٨٩';
  const toLatinDigits=value=>String(value??'')
    .replace(/[۰-۹]/g,ch=>String(FA.indexOf(ch)))
    .replace(/[٠-٩]/g,ch=>String(AR.indexOf(ch)));
  const toPersianDigits=value=>toLatinDigits(value).replace(/\d/g,d=>FA[Number(d)]);
  const normalizeNumber=(value,{decimal=false,signed=false}={})=>{
    let s=toLatinDigits(value).trim().replace(/[\s,٬]/g,'').replace(/٫/g,'.');
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
  const number=(value,options={})=>formatter({maximumFractionDigits:3,...options}).format(Number(value||0));
  const integer=value=>formatter({maximumFractionDigits:0}).format(Number(value||0));
  const money=value=>`${integer(value)} تومان`;
  const percent=value=>`${number(value,{maximumFractionDigits:2})}٪`;
  const humanDigits=value=>toPersianDigits(value);
  const canonical=value=>toLatinDigits(value);
  const phone=value=>toPersianDigits(value);
  const pad=value=>String(value).padStart(2,'0');
  const g2j=(gy,gm,gd)=>{const gdm=[0,31,59,90,120,151,181,212,243,273,304,334],gy2=gm>2?gy+1:gy;let days=355666+365*gy+Math.floor((gy2+3)/4)-Math.floor((gy2+99)/100)+Math.floor((gy2+399)/400)+gd+gdm[gm-1],jy=-1595+33*Math.floor(days/12053);days%=12053;jy+=4*Math.floor(days/1461);days%=1461;if(days>365){jy+=Math.floor((days-1)/365);days=(days-1)%365}if(days<186)return[jy,1+Math.floor(days/31),1+(days%31)];return[jy,7+Math.floor((days-186)/30),1+((days-186)%30)]};
  const parseDateParts=value=>{const m=toLatinDigits(value).trim().match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?/);if(!m)return null;const parts={y:Number(m[1]),mo:Number(m[2]),d:Number(m[3]),h:m[4]===undefined?null:Number(m[4]),mi:m[5]===undefined?null:Number(m[5]),s:m[6]===undefined?null:Number(m[6])};if(parts.mo<1||parts.mo>12||parts.d<1||parts.d>31||parts.h!==null&&(parts.h<0||parts.h>23)||parts.mi!==null&&(parts.mi<0||parts.mi>59))return null;return parts};
  const date=value=>{if(value==null||String(value).trim()==='')return '—';const p=parseDateParts(value);if(!p)return humanDigits(value);const j=g2j(p.y,p.mo,p.d);return toPersianDigits(`${j[0]}/${pad(j[1])}/${pad(j[2])}`)};
  const time=value=>{if(value==null||String(value).trim()==='')return '—';const raw=toLatinDigits(value).trim(),m=raw.match(/(?:^|[ T])(\d{1,2}):(\d{2})(?::\d{2})?(?:Z|[+-]\d{2}:?\d{2})?$/)||raw.match(/^(\d{1,2}):(\d{2})(?::\d{2})?$/);if(!m)return humanDigits(value);const h=Number(m[1]),mi=Number(m[2]);if(h>23||mi>59)return humanDigits(value);return toPersianDigits(`${pad(h)}:${pad(mi)}`)};
  const dateTime=value=>{if(value==null||String(value).trim()==='')return '—';const p=parseDateParts(value);if(!p)return humanDigits(value);const d=date(value);return p.h===null?d:`${d} · ${toPersianDigits(`${pad(p.h)}:${pad(p.mi||0)}`)}`};
  const normalizeForm=form=>{
    if(!(form instanceof HTMLFormElement))return;
    form.querySelectorAll('input[inputmode="numeric"],input[inputmode="decimal"],input[type="tel"]').forEach(input=>{
      if(input.disabled||input.readOnly)return;
      if(input.type==='tel')input.value=toLatinDigits(input.value);
      else input.value=normalizeNumber(input.value,{decimal:input.inputMode==='decimal',signed:true});
    });
  };
  document.addEventListener('submit',event=>normalizeForm(event.target),true);
  window.SoknaLocale=Object.freeze({toLatinDigits,toPersianDigits,humanDigits,canonical,phone,normalizeNumber,numericValue,number,integer,money,percent,date,time,dateTime,normalizeForm});
})();
