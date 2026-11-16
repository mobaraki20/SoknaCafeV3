'use strict';
const fs=require('node:fs');
const assert=require('node:assert/strict');
const path=require('node:path');
const {chromium}=require('playwright');
const root=process.env.R264_SCREEN_ROOT;
if(process.env.GITHUB_ACTIONS!=='true'||!root)throw Error('R264_VISUAL_DISPOSABLE_ONLY');
const folder=path.join(root,'screenshots');fs.mkdirSync(folder,{recursive:true});
const css=fs.readFileSync(path.join(root,'local/public/assets/product-ui.css'),'utf8');
const fixture=fs.readFileSync(path.join(process.cwd(),'r264/correction_modal_fixture.html'),'utf8');
assert(fixture.includes('function renderAccountItemEditor(){'),'Real renderer function missing');
const html=fixture.replace('<style id="source-css"></style>','<style>'+css+'</style>');
const browserTask=async()=>{
 const browser=await chromium.launch({headless:true});
 try{
  for(const size of [{width:390,height:844,key:'mobile'},{width:1365,height:850,key:'desktop'}]){
   const page=await browser.newPage({viewport:{width:size.width,height:size.height},deviceScaleFactor:1});
   const errors=[];page.on('pageerror',e=>errors.push(e.message));
   await page.setContent(html,{waitUntil:'load'});
   const d=page.locator('[data-account-item-edit-dialog]'),save=page.locator('[data-account-item-edit-save]');
   await d.waitFor({state:'visible',timeout:10000});
   assert(await save.isVisible(),'save footer not visible at '+size.key);
   await page.screenshot({path:path.join(folder,'r264-correction-before-'+size.key+'.png')});
   const minus=page.locator('[data-modal-item-delta="-1"]').first();
   await minus.click();
   assert(await save.isVisible(),'save footer disappeared after minus '+size.key);
   const reason=page.locator('[data-modal-adjust-reason]').first();
   if(await reason.count())await reason.click();
   const bounds=await save.boundingBox();
   assert(bounds&&bounds.y>=0&&bounds.y+bounds.height<=size.height,'save footer outside view '+size.key+': '+JSON.stringify(bounds));
   const content=await d.textContent();
   assert(content.includes('ثبت تغییرات')||content.includes('حذف آیتم'),'count correction footer text missing');
   await page.screenshot({path:path.join(folder,'r264-correction-after-'+size.key+'.png')});
   assert.deepEqual(errors,[],'product renderer threw '+size.key);
   console.log('R264_ACTUAL_SOURCE_CORRECTION_VISUAL_PASS',size.key,JSON.stringify(bounds));
   await page.close();
  }
 }finally{await browser.close()}
};
browserTask().catch(e=>{console.error(e);process.exit(1)});
