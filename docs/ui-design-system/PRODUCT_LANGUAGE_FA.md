# SOKNA Cafe V3 — زبان محصول فارسی

**Design Authority:** `SCDS-CANONICAL-2026-R1`

نام‌های فنی backend/API/schema می‌توانند برای compatibility انگلیسی/legacy باقی بمانند؛ متن قابل‌مشاهده کاربر باید از واژگان canonical فارسی استفاده کند.

## واژگان قطعی شروع V3

| مفهوم فنی | UI canonical | ممنوع در UI همان مفهوم |
|---|---|---|
| subscriber / cafe credit customer | مشتری / مشتریان / حساب مشتری | مشترک / مشترکین / حساب مشترک |
| direct settlement | تسویه | تسویه مستقیم |
| shared draft / team-shared | پیش‌نویس مشترک | —؛ «مشترک» اینجا معنای shared دارد و مجاز است |
| shared inventory department | مشترک | —؛ این واژه entity مشتری نیست |
| shared cryptographic secret | کلید مشترک، فقط در متن فنی لازم | نباید به customer تبدیل شود |

## قواعد

- تغییر vocabulary به معنی rename schema/API نیست؛ نام‌هایی مثل `subscriber` و `subscriber_id` تا وقتی قرارداد migration/compatibility چیز دیگری نگفته حفظ می‌شوند.
- «مشترک» فقط وقتی معنای واقعی shared/common دارد مجاز است.
- Search placeholder، audit presentation، receipt/print label، user-facing error و Help باید vocabulary واحد مصرف کنند.
- اصطلاح فنی انگلیسی در UI عادی فقط با whitelist و دلیل مجاز است.
- واژگان جدید مشترک باید هم‌زمان با تغییر Design System/registry ثبت و تست شوند؛ صفحه حق ساخت واژه موازی ندارد.
