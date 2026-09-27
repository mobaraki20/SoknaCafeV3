#!/usr/bin/env python3
from pathlib import Path
import sys
ROOT=Path(__file__).resolve().parents[1]
def fail(m): print(m,file=sys.stderr); raise SystemExit(1)
def need(path,*terms):
    text=(ROOT/path).read_text(encoding='utf-8')
    for term in terms:
        if term not in text: fail(f'{path}: missing {term}')
    return text
mig=need('apps/local-web/database/migrations/0024_g4_business_extensions.sql','marketing_campaigns','marketing_events','notification_preferences','notification_push_subscriptions','notification_outbox','notification_inbox','notifications.vapid_public_key')
marketing=need('apps/local-web/src/Domain/Marketing/MarketingService.php','saveCampaign','saveEvent','publicFeed','public_visible','audit_log')
reporting=need('apps/local-web/src/Domain/Reporting/ReportingService.php','range_too_large','settlement_records','expenses','top_items','remoteSummary')
notifications=need('apps/local-web/src/Domain/Notifications/NotificationService.php','savePreferences','registerPush','compose','queueForUser','processPending','notification_inbox','notifications.push_bridge_url','https://','remoteRows')
need('apps/local-web/public/marketing/index.php','data-campaign-form','data-event-form','نمایش در منوی عمومی')
need('apps/local-web/public/reports/index.php','data-report-form','روند روزانه','آیتم‌های برتر')
need('apps/local-web/public/notifications/index.php','data-pref-form','data-enable-push','صندوق اعلان')
need('apps/local-web/public/notification-sw.js',"addEventListener('push'","notificationclick",'showNotification')
need('apps/local-web/src/Core/Bootstrap.php',"'notifications.outbox'",'new MarketingService','new ReportingService','new NotificationService')
cap=need('apps/local-web/src/Core/Capabilities.php',"'remote_notifications'")
builder=need('apps/local-web/src/Domain/PublicEdge/PublicProjectionBuilder.php',"'remote_notifications'=>'notifications.read'","$snapshot['marketing']","$this->reporting?->remoteSummary()","$this->notifications?->remoteRows()")
remote=need('apps/public/src/Remote/RemoteReadModelService.php',"'notifications' => 'notifications.read'",'filterNotifications','projection_id')
renderer=need('apps/public/src/Guest/GuestPageRenderer.php','sg-marketing','sg-promo-card',"$snapshot['marketing']")
need('apps/public/src/Remote/RemoteStaffPageRenderer.php',"'notifications'=>['cap'=>'notifications.read'")
for p in ['apps/local-web/tools/setup-machine.php','apps/local-web/src/Setup/BrowserSetupService.php']:
    need(p,"['key'=>'notifications.outbox','intervalSeconds'=>15]")
if "str_starts_with(strtolower($endpoint),'https://')" not in notifications: fail('Push subscription endpoint is not HTTPS-only')
if "!str_starts_with($url,'/')" not in notifications: fail('Notification target URL is not bounded to Local paths')
if "eval(" in marketing or "eval(" in notifications: fail('arbitrary execution introduced')
print('G4.3 Business Extensions contract: PASS')
