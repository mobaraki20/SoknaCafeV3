using System;
using System.Globalization;
using System.Text.RegularExpressions;

namespace Sokna.Shared;

/// <summary>Presentation only. Protocol timestamps, leases and journals stay UTC instants.</summary>
public static class IranPresentation
{
    public const string TimeZoneId = "Asia/Tehran";
    private static readonly TimeZoneInfo IranZone = ResolveZone();
    private static TimeZoneInfo ResolveZone()
    {
        foreach (var id in new[] { TimeZoneId, "Iran Standard Time" })
        {
            try { return TimeZoneInfo.FindSystemTimeZoneById(id); }
            catch (TimeZoneNotFoundException) { }
            catch (InvalidTimeZoneException) { }
        }
        return TimeZoneInfo.CreateCustomTimeZone(TimeZoneId, TimeSpan.FromMinutes(210), "Tehran", "Tehran");
    }
    public static DateTimeOffset InIran(DateTimeOffset instant) => TimeZoneInfo.ConvertTime(instant, IranZone);
    public static string Digits(string value)
    {
        var chars = value.ToCharArray();
        for (var i = 0; i < chars.Length; i++)
        {
            if (chars[i] >= '0' && chars[i] <= '9') chars[i] = "۰۱۲۳۴۵۶۷۸۹"[chars[i] - '0'];
            else if (chars[i] == ',') chars[i] = '٬';
            else if (chars[i] == '.') chars[i] = '٫';
        }
        return new string(chars);
    }
    public static string Clock(DateTimeOffset instant) => Digits(InIran(instant).ToString("HH:mm", CultureInfo.InvariantCulture));
    public static string Display(DateTimeOffset instant)
    {
        var local = InIran(instant).DateTime;
        var calendar = new PersianCalendar();
        return Digits(string.Format(CultureInfo.InvariantCulture, "{0:0000}/{1:00}/{2:00} · {3:HH:mm}",
            calendar.GetYear(local), calendar.GetMonth(local), calendar.GetDayOfMonth(local), local));
    }
    public static string ReceiptDate(string value)
    {
        var raw = value.Trim();
        if (raw.Length == 0) return "";
        if (Regex.IsMatch(raw, @"^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,7})?)?(?:Z|[+-]\d{2}:?\d{2})$", RegexOptions.IgnoreCase)
            && DateTimeOffset.TryParse(raw, CultureInfo.InvariantCulture, DateTimeStyles.None, out var instant))
            return Display(instant);
        // v2 legacy Local payloads use a bare Tehran wall clock. Never use the host zone.
        if (DateTime.TryParseExact(raw, new[] { "yyyy-MM-dd HH:mm:ss", "yyyy-MM-dd'T'HH:mm:ss", "yyyy-MM-dd'T'HH:mm" },
            CultureInfo.InvariantCulture, DateTimeStyles.None, out var wall))
            return Display(new DateTimeOffset(wall, IranZone.GetUtcOffset(wall)));
        // Legacy display_date is already human text; it is never used for comparisons.
        return Digits(raw);
    }
    public static string Number(decimal value, int decimals = 0) => Digits(value.ToString("N" + decimals, CultureInfo.InvariantCulture));
}
