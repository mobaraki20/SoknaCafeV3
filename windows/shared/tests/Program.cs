using System;
using Sokna.Shared;
public static class Program
{
 public static void Main()
 {
  var instant = DateTimeOffset.Parse("2026-10-22T21:05:00Z", System.Globalization.CultureInfo.InvariantCulture);
  var n=0;
  void Eq(string actual,string expected){if(actual!=expected)throw new Exception(actual+" != "+expected);n++;}
  Eq(IranPresentation.Display(instant),"۱۴۰۵/۰۸/۰۱ · ۰۰:۳۵");
  Eq(IranPresentation.Clock(instant),"۰۰:۳۵");
  Eq(IranPresentation.ReceiptDate("2026-10-22T21:05:00Z"),"۱۴۰۵/۰۸/۰۱ · ۰۰:۳۵");
  Eq(IranPresentation.ReceiptDate("2026-10-23 00:35:00"),"۱۴۰۵/۰۸/۰۱ · ۰۰:۳۵");
  Eq(IranPresentation.ReceiptDate("2026-10-23T00:35:00+03:30"),"۱۴۰۵/۰۸/۰۱ · ۰۰:۳۵");
  Eq(IranPresentation.Number(1234567),"۱٬۲۳۴٬۵۶۷");
  Eq(IranPresentation.Number(1234.5m,1),"۱٬۲۳۴٫۵");
  Console.WriteLine("{\"suite\":\"iran-dotnet-presentation\",\"assertions\":"+n+",\"status\":\"PASS\"}");
 }
}
