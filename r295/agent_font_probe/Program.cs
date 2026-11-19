using System;
using System.Collections.Generic;
using System.Drawing;
using System.Drawing.Imaging;
using System.IO;
using System.Reflection;
using System.Security.Cryptography;
using System.Text.Json;

// Exercise real Windows receipt rasterizer without binding any printer or writing a print job.
var renderer=Type.GetType("Sokna.PrintAgent.Worker.ReceiptRenderer, Sokna.PrintAgent.Worker",throwOnError:true)!;
var render=renderer.GetMethod("Render",BindingFlags.Public|BindingFlags.Static)!;
string Digest(bool prep,string key,int? value)
{
    var template=new Dictionary<string,object>{["base_font_size"]=23,["title_font_size"]=30,["table_font_size"]=28,["line_spacing"]=5,["margin"]=9,["show_prices"]=true,["footer"]="سپاس از همراهی شما"};
    if(value.HasValue)template[key]=value.Value;
    object document=prep?new {
        schema="sokna-print-document-v2",document_kind="preparation",title="کافه سکنا",created_at="2026-11-19 09:00:00",order_number="200",table_name="میز ۵",
        sections=new[]{new {title="بار",items=new[]{new {name="قهوه دمی",quantity=2,note="کم شکر و کمی شیر"}}}}, customer_note="اول آب بیاورید",template
    }:(object)new {
        schema="sokna-print-document-v2",document_kind="customer",title="کافه سکنا",created_at="2026-11-19 09:00:00",invoice_number="I-1405-000120",table_name="میز ۵",
        sections=new[]{new {items=new[]{new {name="قهوه دمی",quantity=2,unit_price=100000,line_total=200000}}}},subtotal=200000,discount=10000,tax=9000,total=199000,
        settlement_label="کارتخوان",currency="تومان",template
    };
    using var bitmap=(Bitmap)render.Invoke(null,new object[]{JsonSerializer.Serialize(document),72d,80d,203,203})!;
    using var ms=new MemoryStream();bitmap.Save(ms,ImageFormat.Png);return Convert.ToHexString(SHA256.HashData(ms.ToArray()));
}
foreach(var (field,prep) in new[]{("notes_font_size",true),("footer_font_size",false),("totals_font_size",false),("settlement_font_size",false),("meta_font_size",false)})
{
    var small=Digest(prep,field,14);var big=Digest(prep,field,60);
    if(small==big)throw new Exception("FONT_FIELD_NO_RENDER_EFFECT_"+field);
    Console.WriteLine("FONT_FIELD_EFFECT_PASS "+field+" "+small[..12]+" -> "+big[..12]);
}
var old1=Digest(false,"footer_font_size",null);var old2=Digest(false,"footer_font_size",null);
if(old1!=old2)throw new Exception("LEGACY_RENDER_NOT_DETERMINISTIC");
Console.WriteLine("R295_WINDOWS_PRINT_AGENT_INDEPENDENT_FONT_RENDER_PASS");
