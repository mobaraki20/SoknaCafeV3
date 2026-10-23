(()=>{
  'use strict';
  try{
    const preference=localStorage.getItem('sokna.theme')||'system';
    const dark=preference==='dark'||(preference==='system'&&matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.dataset.theme=dark?'dark':'light';
    document.documentElement.dataset.themePreference=preference;
  }catch(_e){}
  const meta=name=>document.querySelector(`meta[name="${name}"]`)?.getAttribute('content')||'';
  window.SoknaAssets={
    version:meta('sokna-build')||'dev',
    base:meta('sokna-app-base')||'',
    path(path){
      let r=String(path||'');
      if(!r||/^(?:https?:)?\/\//i.test(r)||/^(?:data|blob):/i.test(r))return r;
      let hash='';const hashAt=r.indexOf('#');if(hashAt>=0){hash=r.slice(hashAt);r=r.slice(0,hashAt)}
      const base=this.base||'';if(r.charAt(0)!=='/')r='/'+r;if(base&&r.indexOf(base+'/')!==0&&r!==base)r=base+r;
      const q=r.indexOf('?')>=0?'&':'?';return r+q+'v='+encodeURIComponent(this.version)+hash;
    }
  };
})();
