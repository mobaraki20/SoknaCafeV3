<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;use Sokna\Local\UI\ProductShell;
$core=require dirname(__DIR__).'/_app.php';$user=LocalPage::requireAny($core,['cashier_accounts','shift_supervision','staff_consumption_reports']);
ProductShell::start($core,$user,'گزارش و تحلیل','reports','فروش، عملکرد و روندهای مجموعه را در یک نمای مدیریتی بررسی کن.');
$historyId=(int)($_GET['order_id']??0);$historyAllowed=(string)($user['role']??'')==='admin'||$core->auth()->hasCapability('cashier_accounts',$user)||$core->auth()->hasCapability('shift_supervision',$user);$historyOrder=null;$historyError=null;
if($historyId>0&&$historyAllowed){try{$historyOrder=$core->reporting()->historicalOrder($historyId);}catch(\Throwable $e){$historyError=($e instanceof \Sokna\Local\Domain\Reporting\ReportingException)?$e->getMessage():'جزئیات سفارش فعلاً در دسترس نیست.';}}
elseif($historyId>0){
    $esc=static fn($v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $fmt=static fn($v):string=>\Sokna\Local\UI\MoneyDisplay::money((int)$v);
    $digits=static fn($v):string=>\Sokna\Local\UI\MoneyDisplay::digits((string)$v);
    echo '<section id="order-history" class="sc-card sc-history-order" aria-labelledby="sc-history-order-title"><div class="sc-card__body">';
    if($historyError!==null){echo '<div class="sc-alert sc-alert--warning">'.$esc($historyError).'</div>';}
    elseif(is_array($historyOrder)){
        $o=$historyOrder['order'];$id=(int)$o['id'];
        echo '<header class="sc-section-head sc-history-order__header"><div><p class="sc-history-order__eyebrow">سوابق حساب و سفارش</p><h2 id="sc-history-order-title">جزئیات سفارش شماره '.$digits($o['business_order_number']??$id).'</h2><p>نمای فقط‌خواندنی؛ هیچ اطلاعات مالی از این صفحه تغییر نمی‌کند.</p></div><a class="sc-button sc-button--secondary" href="/reports/">بازگشت به گزارش‌ها</a></header>';
        echo '<dl class="sc-history-order__facts">';
        foreach([
          'میز'=>(string)($o['table_name']??'—'),
          'روز کاری'=>(string)($o['business_date']??'—'),
          'وضعیت سفارش'=>(string)($o['status']??'—'),
          'شناسه حساب'=>(string)($o['session_id']??'—'),
        ] as $key=>$value)echo '<div><dt>'.$esc($key).'</dt><dd>'.$esc($digits($value)).'</dd></div>';
        echo '</dl><div class="sc-section-head sc-history-order__section-head"><div><h3>اقلام سفارش</h3><p>قیمت‌ها به تومان و مطابق سند تاریخی‌اند.</p></div></div>';
        echo '<div class="sc-table-wrap sc-history-order__table-wrap"><table class="sc-table sc-history-order__table"><thead><tr><th>کالا</th><th>تعداد</th><th>قیمت واحد</th><th>جمع ردیف</th></tr></thead><tbody>';
        foreach($historyOrder['items'] as $item){
            echo '<tr><td data-label="کالا"><strong>'.$esc($item['item_name']).'</strong>'.(!empty($item['item_note'])?'<small class="sc-history-order__note">'.$esc($item['item_note']).'</small>':'').'</td>';
            echo '<td data-label="تعداد">'.$digits((int)$item['quantity']).'</td><td data-label="قیمت واحد">'.$esc($fmt($item['unit_price'])).'</td><td data-label="جمع ردیف">'.$esc($fmt($item['line_total'])).'</td></tr>';
        }
        if(!$historyOrder['items'])echo '<tr><td colspan="4">قلمی برای این سفارش ثبت نشده است.</td></tr>';
        echo '</tbody></table></div>';
        if(!empty($o['customer_note']))echo '<p class="sc-history-order__customer-note"><strong>یادداشت مهمان:</strong> '.$esc($o['customer_note']).'</p>';
        echo '<div class="sc-section-head sc-history-order__section-head"><div><h3>اسناد پرداخت مرتبط</h3><p>هر پرداخت مستقل ثبت شده و فقط قابل مشاهده است.</p></div></div>';
        if(!empty($historyOrder['invoices'])){
            echo '<div class="sc-history-order__invoices">';
            foreach($historyOrder['invoices'] as $invoice){
                $href='/finance/?tab=receipts&amp;invoice_id='.(int)$invoice['id'];
                echo '<a class="sc-history-order__invoice" href="'.$href.'"><span><strong>'.$esc($digits($invoice['invoice_number'])).'</strong><small>'.$esc($digits((string)($invoice['settled_at']??''))).'</small></span><span class="sc-history-order__invoice-state">'.$esc((string)$invoice['status']).'</span></a>';
            }
            echo '</div>';
        }else echo '<p class="sc-empty">هنوز سند پرداخت مرتبطی ثبت نشده است.</p>';
    }
    echo '</div></section>';
}
require dirname(__DIR__,2).'/resources/shared-staff-ui/reports-workspace.php';
ProductShell::end();
