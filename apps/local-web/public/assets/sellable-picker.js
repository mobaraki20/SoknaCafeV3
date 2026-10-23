(()=>{'use strict';
const L=window.SoknaLocale||{integer:v=>new Intl.NumberFormat('fa-IR').format(Number(v||0)),money:v=>`${new Intl.NumberFormat('fa-IR').format(Number(v||0))} تومان`};
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const normalizeText=v=>String(v||'').toLocaleLowerCase('fa').replace(/[يى]/g,'ی').replace(/ك/g,'ک').replace(/[أإ]/g,'ا').replace(/ة/g,'ه').replace(/\u200c/g,' ').replace(/[\s\-_/]+/g,' ').trim();
const assetPath=v=>(window.SoknaAssets?.path?window.SoknaAssets.path(v):(window.SoknaURL?.path?window.SoknaURL.path(v):v));
const searchIcon=()=>`<svg class="sc-ui-icon" aria-hidden="true"><use href="${assetPath('/assets/ui-sprite.svg')}#icon-search"></use></svg>`;
const categoryIcon=key=>key?`<svg class="sc-ui-icon" aria-hidden="true"><use href="${assetPath('/assets/category-icons.svg')}#icon-${esc(key)}"></use></svg>`:'';
const groups=raw=>Array.isArray(raw)?raw:[];
const categories=(rawGroups,groupId)=>{const g=groups(rawGroups).find(x=>Number(x.id)===Number(groupId));return Array.isArray(g?.categories)?g.categories.map(c=>({id:Number(c.id),name:String(c.name||''),icon_key:String(c.icon_key||''),item_ids:Array.isArray(c.item_ids)?c.item_ids.map(Number):[]})):[]};
function normalizeSelection(rawGroups,state,resetCategory=false){
  const gs=groups(rawGroups);if(!state||typeof state!=='object')throw new Error('Sellable picker state is required.');
  if(!gs.length){state.groupId=0;state.category='all';return null}
  if(!gs.some(g=>Number(g.id)===Number(state.groupId))){state.groupId=Number(gs[0].id);resetCategory=true}
  const cats=categories(gs,state.groupId);
  if(resetCategory||(state.category!=='all'&&!cats.some(c=>String(c.id)===String(state.category))))state.category=cats.length?String(cats[0].id):'all';
  return gs.find(g=>Number(g.id)===Number(state.groupId))||null;
}
function visibleRows(catalog,rawGroups,state){
  const group=normalizeSelection(rawGroups,state,false);if(!group)return [];
  const cats=categories(rawGroups,state.groupId),ids=new Set(cats.flatMap(c=>c.item_ids));
  const terms=normalizeText(state.query).split(' ').filter(Boolean);
  return (Array.isArray(catalog)?catalog:[]).filter(i=>ids.has(Number(i.id))&&((terms.length)||(state.category==='all'||String(i.category_id)===String(state.category)))&&(!terms.length||terms.every(t=>normalizeText(`${i.name} ${i.category_name}`).includes(t))));
}
function renderProduct(item,state,options){
  const qty=Math.max(0,Number(options.quantity?.(item)||0)),showCategory=Boolean(state.query)||state.category==='all',prefix=options.prefix||'sellable',showAddControl=options.showAddControl!==false;
  const price=(options.money||L.money)(item.price),verb=options.addVerb||'افزودن',target=options.targetLabel||'انتخاب';
  return `<button type="button" class="sc-order-product ${qty?'is-selected':''}" data-${prefix}-add="${Number(item.id)}" aria-label="${esc(verb)} ${esc(item.name)} به ${esc(target)}"><span class="sc-order-product__copy"><strong>${esc(item.name)}</strong>${showCategory?`<small>${esc(item.category_name)}</small>`:''}</span><span class="sc-order-product__footer"><span class="sc-order-product__price">${price}</span>${showAddControl?'<span class="sc-order-product__add" aria-hidden="true">+</span>':''}</span>${qty?`<b class="sc-order-product__count">${L.integer(qty)}</b>`:''}</button>`;
}
function render(options){
  const state=options.state||{groupId:0,category:'all',query:''},prefix=options.prefix||'sellable',gs=groups(options.groups),group=normalizeSelection(gs,state,Boolean(options.resetCategory)),cats=categories(gs,state.groupId),visible=visibleRows(options.catalog,gs,state);
  const emptyMenus=options.emptyMenus||'منوی قابل سفارش فعالی وجود ندارد',emptyHint=options.emptyHint||'وضعیت منو و بازه سرو را در کاتالوگ بررسی کنید.';
  const groupNav=!gs.length?`<div class="sc-order-group-empty" data-${prefix}-group-empty><strong>${esc(emptyMenus)}</strong><small>${esc(emptyHint)}</small></div>`:gs.length===1?`<div class="sc-order-group-context" data-${prefix}-group-context><span>منوی فعال</span><strong>${esc(group?.name||'')}</strong></div>`:`<div class="sc-order-groups" role="tablist" aria-label="گروه اصلی منو">${gs.map(g=>`<button type="button" role="tab" aria-selected="${Number(g.id)===Number(state.groupId)?'true':'false'}" class="${Number(g.id)===Number(state.groupId)?'is-active':''}" data-${prefix}-group="${Number(g.id)}">${esc(g.name)}</button>`).join('')}</div>`;
  if(!group)return {html:groupNav,visible,group:null,categories:cats};
  const title=state.query?'نتایج جستجو':state.category==='all'?`همه ${group.name}`:(cats.find(c=>String(c.id)===String(state.category))?.name||'');
  const itemLabel=options.itemLabel||'آیتم قابل سفارش',placeholder=options.placeholder||`جستجو در ${group.name}…`;
  const showCategoryLabel=options.showCategoryLabel!==false,showCategoryIcons=Boolean(options.categoryIcons),searching=Boolean(String(state.query||'').trim());
  const toolbar=`<div class="sc-order-catalog__toolbar${searching?' is-searching':''}"><div class="sc-order-search-context"><strong>${esc(title)}</strong><small>${L.integer(visible.length)} ${esc(itemLabel)}</small></div><label class="sc-order-search"><span class="sc-order-search__icon" aria-hidden="true">${searchIcon()}</span><input type="search" value="${esc(state.query||'')}" autocomplete="off" data-${prefix}-search placeholder="${esc(placeholder)}" aria-label="${esc(placeholder)}"></label></div>`;
  const leading=String(options.leadingHtml||'');const top=options.integratedHeader?`<div class="sc-order-catalog-top"><div class="sc-order-catalog-nav">${leading}${groupNav}</div>${toolbar}</div>`:`${leading}${groupNav}${toolbar}`;
  const html=`${top}<div class="sc-order-category-strip${showCategoryLabel?'':' is-label-free'}" data-${prefix}-category-strip>${showCategoryLabel?'<span class="sc-order-category-strip__label">دسته‌ها</span>':''}<div class="sc-order-categories"><button type="button" class="${!searching&&state.category==='all'?'is-active':''}" data-${prefix}-category="all">${showCategoryIcons?'<span class="sc-order-category-icon">'+categoryIcon('list')+'</span>':''}<span>همه</span></button>${cats.map(c=>`<button type="button" class="${!searching&&String(state.category)===String(c.id)?'is-active':''}" data-${prefix}-category="${esc(c.id)}">${showCategoryIcons?`<span class="sc-order-category-icon">${categoryIcon(c.icon_key||'list')}</span>`:''}<span>${esc(c.name)}</span></button>`).join('')}</div></div><div class="sc-order-products" data-${prefix}-products>${visible.length?visible.map(i=>renderProduct(i,state,options)).join(''):`<div class="sc-empty">${esc(options.emptyItems||'آیتمی پیدا نشد.')}</div>`}</div>`;
  return {html,visible,group,categories:cats};
}
window.SoknaSellablePicker={normalizeText,groups,categories,normalizeSelection,visibleRows,render};
})();
