<?php
/** Table-based RTL email, preserving the approved navy/orange design in email clients. */
$e = [\Fasakhansta\DailyDigest\Report::class, 'escape'];
$money = [\Fasakhansta\DailyDigest\Report::class, 'money'];
$when = (new \DateTimeImmutable($data['captured_at']))->setTimezone(new \DateTimeZone('Africa/Cairo'));
$months = [1=>'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
$dateLabel = $when->format('d').' '.$months[(int)$when->format('n')].' '.$when->format('Y');
$timeLabel = $when->format('H:i');
$palette = ['ok'=>['#176343','#e9f5ee'], 'low'=>['#925209','#fff0d9'], 'zero'=>['#a53232','#fceaea'], 'negative'=>['#a53232','#fceaea']];
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>فسخانستا | تقرير الفروع اليومي</title>
<style>@media only screen and (max-width:480px){.fsk-pad{padding-right:16px!important;padding-left:16px!important}.fsk-money{font-size:29px!important}.fsk-title{font-size:22px!important}.fsk-stock td,.fsk-stock th{padding:9px 6px!important;font-size:11px!important}.fsk-drawer-value{font-size:19px!important}}</style></head>
<body style="margin:0;padding:0;background:#edf2f5;color:#142e3e;font-family:Tahoma,Arial,sans-serif;direction:rtl">
<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden">أرصدة أدراج الفروع ومخزون البضاعة حتى <?= $e($dateLabel) ?> الساعة <?= $e($timeLabel) ?> بتوقيت القاهرة</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;background:#edf2f5"><tr><td align="center" style="padding:22px 8px">
<table role="presentation" width="720" cellpadding="0" cellspacing="0" dir="rtl" style="width:100%;max-width:720px;background:#fff;border:1px solid #e1e7eb;border-radius:14px;overflow:hidden">
<tr><td class="fsk-pad" style="padding:28px 32px 25px;background:#102d40;border-top:5px solid #f47c20;color:#fff">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
    <td style="vertical-align:top;text-align:right"><div style="font-size:26px;font-weight:bold;color:#ff994d;line-height:1.5">فسخانستا</div><div dir="ltr" style="font-size:10px;letter-spacing:2px;color:#d5e2e9;text-align:right">FASAKHANSTA</div></td>
    <td style="text-align:left;vertical-align:top;font-size:12px;line-height:1.9;color:#c6d6df"><?= $e($dateLabel) ?><br><span dir="ltr"><?= $e($timeLabel) ?></span> · القاهرة</td>
  </tr></table>
  <h1 class="fsk-title" style="margin:23px 0 6px;font-size:26px;line-height:1.6;color:#fff">تقرير الفروع اليومي</h1>
  <div style="font-size:13px;line-height:1.8;color:#d1dfe7">مخزون البضاعة وأرصدة الأدراج وقت إصدار التقرير</div>
</td></tr>
<tr><td class="fsk-pad" style="padding:26px 30px 22px">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f6f8;border-radius:10px"><tr>
    <td style="padding:19px 20px;text-align:right"><div style="font-size:13px;color:#516674">إجمالي أرصدة الأدراج</div><div class="fsk-money" style="font-size:36px;font-weight:bold;line-height:1.4;margin-top:6px;color:#142e3e"><span dir="ltr"><?= $e($money($data['total_cents'])) ?></span> <span style="font-size:12px;font-weight:normal;white-space:nowrap">جنيه مصري</span></div></td>
    <td width="62" style="padding:16px 10px;text-align:center"><div style="font-size:30px;font-weight:bold;color:#f47c20;line-height:1.3"><?= count($data['branches']) ?></div><div style="font-size:11px;color:#516674">فروع</div></td>
  </tr></table>
  <h2 style="font-size:17px;color:#142e3e;margin:26px 0 12px">أرصدة الأدراج حسب الفرع</h2>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
  <?php foreach (array_chunk($data['branches'], 2) as $pair): ?>
    <tr><?php foreach ($pair as $index=>$branch): ?>
      <td width="50%" style="width:50%;vertical-align:top;padding:0 <?= $index ? '5px' : '0' ?> 10px <?= $index ? '0' : '5px' ?>">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8ed;border-radius:8px"><tr><td style="padding:12px 14px;text-align:right">
          <div style="font-size:12px;color:#647583;line-height:1.8"><?= $e($branch['name']) ?></div>
          <div class="fsk-drawer-value" style="font-size:22px;font-weight:bold;margin-top:4px;color:#142e3e"><span dir="ltr"><?= $e($money($branch['drawer_cents'])) ?></span> <span style="font-size:11px;font-weight:normal;color:#647583">ج.م</span></div>
        </td></tr></table>
      </td>
    <?php endforeach; ?><?php if (count($pair) === 1): ?><td width="50%"></td><?php endif; ?></tr>
  <?php endforeach; ?>
  </table>
  <h2 style="font-size:17px;color:#142e3e;margin:20px 0 12px">تفاصيل مخزون البضاعة</h2>
  <?php foreach ($data['branches'] as $branch): ?>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:18px"><tr><td style="padding:11px 13px;background:#102d40;border-right:4px solid #f47c20;border-radius:7px 7px 0 0;color:#fff;font-size:14px;font-weight:bold"><?= $e($branch['name']) ?></td><td align="left" style="padding:11px;background:#102d40;color:#d2e0e8;font-size:11px;border-radius:7px 0 0 0">الرصيد المتاح</td></tr></table>
  <table class="fsk-stock" width="100%" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;table-layout:fixed;color:#142e3e;text-align:right">
    <thead><tr><th width="43%" style="background:#f1f5f7;color:#5b6e7c;font-size:11px;font-weight:normal;padding:10px 12px;text-align:right">الصنف</th><th width="18%" style="background:#f1f5f7;color:#5b6e7c;font-size:11px;font-weight:normal;padding:10px 12px;text-align:right">الكمية</th><th width="14%" style="background:#f1f5f7;color:#5b6e7c;font-size:11px;font-weight:normal;padding:10px 12px;text-align:right">الوحدة</th><th width="25%" style="background:#f1f5f7;color:#5b6e7c;font-size:11px;font-weight:normal;padding:10px 12px;text-align:right">الحالة</th></tr></thead>
    <tbody>
    <?php foreach ($branch['items'] as $item): $colors = $palette[$item['status']]; ?>
      <tr style="background:<?= $item['status'] === 'low' ? '#fffaf1' : (in_array($item['status'], ['zero','negative'], true) ? '#fff7f7' : '#fff') ?>">
        <td style="padding:11px 12px;border-bottom:1px solid #e8edf0;font-size:12px;line-height:1.7;overflow-wrap:anywhere"><?= $e($item['name']) ?><?php if ($item['type'] === 'product'): ?><div style="font-size:10px;color:#657885">رصيد صنف مستقل</div><?php endif; ?></td>
        <td style="padding:11px 12px;border-bottom:1px solid #e8edf0;font-size:12px;font-weight:bold;vertical-align:middle"><span dir="ltr"><?= $e($item['quantity']) ?></span></td>
        <td style="padding:11px 12px;border-bottom:1px solid #e8edf0;font-size:12px;color:#647583;vertical-align:middle"><?= $e($item['unit_label']) ?></td>
        <td style="padding:11px 12px;border-bottom:1px solid #e8edf0;vertical-align:middle"><span style="display:inline-block;padding:4px 8px;border-radius:18px;white-space:nowrap;font-size:10px;background:<?= $colors[1] ?>;color:<?= $colors[0] ?>"><?= $e($item['status_label']) ?></span></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$branch['items']): ?><tr><td colspan="4" style="padding:18px 12px;font-size:12px;color:#647583;border-bottom:1px solid #e8edf0">لا توجد أصناف بضاعة مسجلة لهذا الفرع.</td></tr><?php endif; ?>
    </tbody>
  </table>
  <?php endforeach; ?>
  <div style="font-size:11px;color:#657885;line-height:1.9;margin-top:20px">تظهر حالة «منخفض» عند وصول الكمية إلى الحد الأدنى المسجل للصنف في الفرع. أرصدة الأصناف المستقلة معروضة بصفتها أرصدة مسجلة، ولا تُجمع مع مكونات الوصفات.</div>
</td></tr>
<tr><td style="padding:17px 18px 20px;text-align:center;background:#f3f6f8;color:#677b88;border-top:1px solid #e5ebee;font-size:11px;line-height:1.9">
  <div style="font-size:12px;font-weight:bold;color:#17364a;margin-bottom:4px">فسخانستا · تقرير المتابعة اليومي</div>
  <div>الفروع فقط · الإستورز مستبعدة</div><div>إرسال يومي 12 منتصف الليل بتوقيت مصر</div>
  <div dir="ltr" style="overflow-wrap:anywhere"><?= $e($data['recipient']) ?></div>
</td></tr>
</table></td></tr></table>
</body></html>
