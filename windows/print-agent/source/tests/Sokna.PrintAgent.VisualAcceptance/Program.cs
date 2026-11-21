using System.Drawing;
using System.Drawing.Imaging;
using System.Reflection;
using System.Security.Cryptography;
using System.Text.Json;

var output=Environment.GetEnvironmentVariable("SOKNA_PRINT_QA_OUT")
    ??Path.Combine(Environment.CurrentDirectory,"render-qa");
Directory.CreateDirectory(output);

// Invoke the actual Print Agent's renderer without sending anything to a
// Windows print queue. This tests the very code used for real preview/printing.
var assembly=Assembly.Load("Sokna.PrintAgent.Worker");
var renderer=assembly.GetType("Sokna.PrintAgent.Worker.ReceiptRenderer",throwOnError:true)!;
var render=renderer.GetMethod("Render",BindingFlags.Static|BindingFlags.Public)
    ??throw new InvalidOperationException("Print Agent Render method unavailable.");

var hashes=new Dictionary<string,string>(StringComparer.Ordinal);
var manifest=new List<object>();
void Save(string label,object payload,double printable,double paper)
{
    var json=JsonSerializer.Serialize(payload);
    using var bitmap=(Bitmap)(render.Invoke(null,[json,printable,paper,203,203])
        ??throw new InvalidOperationException("Null Print Agent render result"));
    if(bitmap.Width<200||bitmap.Height<100)
        throw new InvalidOperationException("Unexpected print raster dimensions for "+label);
    var path=Path.Combine(output,label+".png");
    bitmap.Save(path,ImageFormat.Png);
    var hash=Convert.ToHexString(SHA256.HashData(File.ReadAllBytes(path))).ToLowerInvariant();
    hashes[label]=hash;
    manifest.Add(new {file=Path.GetFileName(path),width=bitmap.Width,height=bitmap.Height,
        paper_width_mm=paper,sha256=hash,rendered_with="Sokna.PrintAgent.Worker.ReceiptRenderer"});
}

object[] Sections()=>
[
    new {
        station="kitchen",title="آشپزخانه",items=new object[]{
            new {name="پیتزا مخصوص",quantity=1,note="بدون پیاز",station="kitchen",order_index=0},
            new {name="سیب زمینی",quantity=1,note="",station="kitchen",order_index=2},
        }
    },
    new {
        station="hot_bar",title="بار گرم",items=new object[]{
            new {name="چای ساده",quantity=2,note="کم رنگ",station="hot_bar",order_index=1},
        }
    },
    new {
        station="cold_bar",title="بار سرد",items=new object[]{
            new {name="لیموناد",quantity=1,note="بدون یخ",station="cold_bar",order_index=3},
        }
    },
];
foreach(var width in new[]{58,80})
{
    foreach(var order in new[]{"default","as_ordered","bar_first","kitchen_first"})
    {
        var payload=new
        {
            schema="sokna-print-document-v2",document_kind="preparation",title="سکنا",
            badge="آزمون ترتیب آماده‌سازی",table_name="میز ۷",order_number="23",
            created_at="2026-11-21T08:10:00+03:30",sections=Sections(),
            template=new{
                title_font_size=30,base_font_size=23,table_font_size=28,
                show_section_titles=true,show_time=true,show_order_number=true,
                line_spacing=5,margin=5,
                design=new{item_layout=width==58?"columnar-compact":"columnar-notes",
                    preparation_station_order=order}
            }
        };
        Save($"preparation-{width}mm-{order}",payload,width==58?48:72,width);
    }
}
var customer=new
{
    schema="sokna-print-document-v2",document_kind="customer",
    document_role="account_preview",title="کافه سکنا",
    badge="حساب باز · تسویه‌نشده",invoice_number="23",table_name="میز ۷",
    created_at="2026-11-21T08:10:00+03:30",
    sections=new object[]{
        new{items=new object[]{new{name="چای",quantity=2,unit_price=90000,line_total=180000}}}
    },
    currency="تومان",subtotal=180000,discount=0,tax=0,total=180000,
    template=new{
        title_font_size=30,base_font_size=23,table_font_size=28,
        show_time=true,show_settlement_label=false,footer="",
        design=new{item_layout="columnar",section_order=new[]{"meta","items","summary","footer"}}
    }
};
Save("customer-open-account-80mm-empty-footer",customer,72,80);

if(hashes["preparation-80mm-default"]==hashes["preparation-80mm-as_ordered"])
    throw new Exception("Original order did not change the actual Print Agent raster");
if(hashes["preparation-80mm-bar_first"]==hashes["preparation-80mm-kitchen_first"])
    throw new Exception("Station priority did not change the actual Print Agent raster");

var outputManifest=Path.Combine(output,"render-manifest.json");
File.WriteAllText(outputManifest,JsonSerializer.Serialize(new {
    status="QA_ONLY_RENDER_NO_PRINT_QUEUE",cases=manifest.Count,images=manifest
},new JsonSerializerOptions {WriteIndented=true}));
Console.WriteLine("R321 actual Print Agent raster QA: "+manifest.Count+" PNGs rendered; modes distinct.");
