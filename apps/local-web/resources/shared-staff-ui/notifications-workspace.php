<?php
declare(strict_types=1);
/** Shared Notifications workspace markup. */
?>
<section class="sc-workspace" data-notifications data-api="<?=htmlspecialchars($workspaceApi,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>" data-csrf="<?= htmlspecialchars($csrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>" data-admin="<?= $isAdmin?'1':'0' ?>" data-can-mark-read="<?= ($canMarkRead??true)?'1':'0' ?>">
  <div class="sc-alert" role="status" aria-live="polite" data-status hidden></div>
  <section class="sc-data-panel sc-notifications-inbox">
    <div class="sc-section-head"><div><h2>صندوق اعلان</h2><p>فقط پیام‌ها و رویدادهایی که برای کار روزانه نیاز به توجه دارند.</p></div><div class="sc-actions"><span class="sc-badge" data-unread>۰ خوانده‌نشده</span><?php if(($notificationSettingsUrl??'')!==''): ?><a class="sc-button sc-button--secondary" href="<?=htmlspecialchars($notificationSettingsUrl,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>">تنظیم دریافت</a><?php endif; ?><?php if($isAdmin&&($notificationAdminUrl??'')!==''): ?><a class="sc-button sc-button--secondary" href="<?=htmlspecialchars($notificationAdminUrl,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>">ارسال اعلان مدیریتی</a><?php endif; ?></div></div>
    <div class="sc-notifications-list" data-inbox></div>
  </section>
</section>
<script src="<?=htmlspecialchars($workspaceAsset('assets/staff-app/data-panels.js'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>" defer></script>
<script src="<?=htmlspecialchars($workspaceAsset('assets/notifications-workspace.js'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>" defer></script>
