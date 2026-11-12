# سکنا — تصحیح ریدایرکت QR قدیمی (R173)
تاریخ: ۱۲ نوامبر ۲۰۲۶

## تصحیح نهایی مقصد توسط کاربر
- **مبدأ:** `https://www.soknaspace.ir/Menu/`
- **مقصد قطعی فعلی:** `https://www.soknaspace.ir/menu`
- مقصد پیشنهادی پیشین `https://www.soknagroup.ir/public/menu` **لغو شده و نباید استفاده شود**.
- هیچ تغییر یا نصب واقعی در Local/Public صورت نگرفته است. پرونده نصب R171 جداگانه در حال عیب‌یابی است.

## قاعده مستقل Apache/DirectAdmin
قانون در `hosting/legacy-qr/.htaccess.fragment` فقط `Menu` با M بزرگ را می‌گیرد، نه `menu` با m کوچک؛ گزینهٔ `[NC]` برای RewriteRule عمداً حذف شد، چون با مقصد جدید روی همان دامنه به حلقه ریدایرکت منجر می‌شد.

```apache
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteCond %{HTTP_HOST} ^(www\.)?soknaspace\.ir(:[0-9]+)?$ [NC]
RewriteRule ^Menu/?$ https://www.soknaspace.ir/menu [R=302,L]
</IfModule>
```

- در یک آزمایش Apache 2.4 روی loopback، 302 و 301 برای `/Menu/` و `/Menu`، www/بدون www و حفظ پارامترهای QR تأیید شد.
- `/menu`، `/menu/` و مسیرهای دیگر redirect نمی‌شوند؛ بنابراین loop رخ نمی‌دهد.
- **هاست واقعی تست و تغییر داده نشده است**. پیش از اعمال قانون باید معلوم شود `soknaspace.ir/menu` خودش صفحهٔ معتبر منو را ارائه می‌کند؛ ریدایرکت صفحه ایجاد نمی‌کند.
- در DirectAdmin فقط وقتی root دامنه مستقل از فایل‌های مدیریت‌شده سکنا است از `.htaccess` استفاده شود. در صورت مشترک‌بودن با Public managed root، قانون باید به سطح پیکربندی هاست/VirtualHost منتقل شود و فایل نسخه‌بندی‌شده محصول دست نخورَد.
- ابتدا 302، بعد از تست QR چاپی، در صورت تصمیم قطعی 301؛ تبدیل 301 ممکن است در مرورگر cache شود.

## رابطه با نصب ناموفق
گزارش پشتیبانی ارسالی Local را روی R160/231ek و Public را روی R159/35hp نشان می‌دهد؛ Local Preflight و Stage را پشت سر گذاشته ولی در Candidate با `LOCAL_PREPARE_CANDIDATE_FAILED` متوقف شده است. این مسئله از قانون ریدایرکت جداست و با تغییر `.htaccess` حل نمی‌شود.
