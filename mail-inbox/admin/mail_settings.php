<?php
/**
 * تنظیمات ایمیل: ارسال (SMTP) و دریافت (IMAP) + تست اتصال
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/mail.php';

if (!isAdmin()) redirect('../login.php');
mail_ensure($pdo);

$msg = ''; $msgOk = true;
$hostOk = function ($h) { return $h === '' || preg_match('/^[A-Za-z0-9.\-]{1,190}$/', $h); };
// آدرس سرور را «هوشمند» پاک می‌کند: imap://host:993/ → host  (پورت جدا برمی‌گردد)
$hostClean = function ($raw) {
    $h = trim((string)$raw);
    $h = preg_replace('#^[A-Za-z][A-Za-z0-9+.\-]*://#', '', $h);   // پروتکل
    $h = preg_replace('#[/?\#].*$#', '', $h);                       // مسیر
    $port = null;
    if (preg_match('/^(.*):(\d{1,5})$/', $h, $m)) { $h = $m[1]; $port = (int)$m[2]; }
    $h = trim(str_replace(["\u{200c}", "\u{200e}", "\u{200f}", ' ', "\t"], '', $h), '. ');
    return [$h, $port];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!mail_csrf_ok()) {
        $msg = 'نشست شما منقضی شده است؛ صفحه را دوباره باز کنید.'; $msgOk = false;
    } else {
        $saved = false;
        // دکمه‌های تست هم ابتدا فرم را ذخیره می‌کنند
        if (isset($_POST['save_mail']) || isset($_POST['test_smtp']) || isset($_POST['test_imap'])) {
            $errs = [];
            [$smtpHost, $smtpPortIn] = $hostClean($_POST['smtp_host'] ?? '');
            [$imapHost, $imapPortIn] = $hostClean($_POST['imap_host'] ?? '');
            if ($smtpPortIn) $_POST['smtp_port'] = $smtpPortIn;
            if ($imapPortIn) $_POST['imap_port'] = $imapPortIn;
            if (!$hostOk($smtpHost)) $errs[] = 'آدرس سرور SMTP نامعتبر است («' . ($_POST['smtp_host'] ?? '') . '»). فقط آدرس سرور را بنویسید، مثل smtp.gmail.com';
            if (!$hostOk($imapHost)) $errs[] = 'آدرس سرور IMAP نامعتبر است («' . ($_POST['imap_host'] ?? '') . '»). فقط آدرس سرور را بنویسید، مثل imap.gmail.com';
            $from = trim($_POST['from_email'] ?? ''); $notify = trim($_POST['notify_email'] ?? '');
            if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) $errs[] = 'ایمیل فرستنده معتبر نیست.';
            if ($notify !== '' && !filter_var($notify, FILTER_VALIDATE_EMAIL)) $errs[] = 'ایمیل دریافت اطلاع‌رسانی معتبر نیست.';
            if ($errs) { $msg = implode(' ', $errs); $msgOk = false; }
            else {
                $port = function ($k, $d) { $p = (int)($_POST[$k] ?? $d); return ($p >= 1 && $p <= 65535) ? $p : $d; };
                $sec  = function ($k) { return in_array($_POST[$k] ?? '', ['ssl', 'tls', 'none'], true) ? $_POST[$k] : 'ssl'; };
                mail_set($pdo, 'smtp_host', $smtpHost);
                mail_set($pdo, 'smtp_port', $port('smtp_port', 465));
                mail_set($pdo, 'smtp_secure', $sec('smtp_secure'));
                mail_set($pdo, 'smtp_user', trim($_POST['smtp_user'] ?? ''));
                if (($_POST['smtp_pass'] ?? '') !== '') mail_set($pdo, 'smtp_pass', $_POST['smtp_pass']);   // خالی = بدون تغییر
                mail_set($pdo, 'from_email', $from);
                mail_set($pdo, 'from_name', trim($_POST['from_name'] ?? ''));
                mail_set($pdo, 'notify_email', $notify);
                mail_set($pdo, 'notify_on', isset($_POST['notify_on']) ? '1' : '0');
                mail_set($pdo, 'verify_ssl', isset($_POST['verify_ssl']) ? '1' : '0');
                mail_set($pdo, 'imap_host', $imapHost);
                mail_set($pdo, 'imap_port', $port('imap_port', 993));
                mail_set($pdo, 'imap_secure', $sec('imap_secure'));
                mail_set($pdo, 'imap_user', trim($_POST['imap_user'] ?? ''));
                if (($_POST['imap_pass'] ?? '') !== '') mail_set($pdo, 'imap_pass', $_POST['imap_pass']);
                mail_set($pdo, 'imap_enabled', isset($_POST['imap_enabled']) ? '1' : '0');
                if (mail_get($pdo, 'cron_key', '') === '') mail_set($pdo, 'cron_key', bin2hex(random_bytes(16)));
                $msg = 'تنظیمات ذخیره شد.'; $saved = true;
            }
        }
        if (isset($_POST['test_smtp']) && $saved) {
            $c = mail_conf($pdo);
            $to = trim($_POST['test_to'] ?? '');
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { $msg = 'برای تست، یک ایمیل معتبر به‌عنوان گیرنده وارد کنید.'; $msgOk = false; }
            elseif (!mail_smtp_ready($c)) { $msg = 'ابتدا تنظیمات SMTP و ایمیل فرستنده را ذخیره کنید.'; $msgOk = false; }
            else {
                try {
                    (new MailSmtp($c))->send($c['from_email'], $c['from_name'], $to, 'تست ارسال ایمیل — ' . SITE_NAME,
                        "این یک ایمیل آزمایشی است.\nاگر آن را می‌بینید، ارسال ایمیل فروشگاه درست کار می‌کند ✅", ['X-Mobile-Store-Notify' => '1']);
                    $msg = "ایمیل آزمایشی به $to ارسال شد ✅ (پوشه‌ی هرزنامه را هم بررسی کنید)";
                } catch (Throwable $e) { $msg = $e->getMessage(); $msgOk = false; }
            }
        }
        if (isset($_POST['test_imap']) && $saved) {
            $c = mail_conf($pdo);
            if (!mail_imap_ready($c)) { $msg = 'ابتدا تنظیمات IMAP (سرور، نام کاربری و رمز) را ذخیره کنید.'; $msgOk = false; }
            else {
                $imap = new MailImap($c);
                try { $imap->open(); [$n] = $imap->selectInbox(); $imap->close(); $msg = 'اتصال به صندوق ایمیل موفق بود ✅ — تعداد ایمیل‌های صندوق: ' . fa_digits($n); }
                catch (Throwable $e) { $imap->close(); $msg = $e->getMessage(); $msgOk = false; }
            }
        }
        if (isset($_POST['reset_cursor'])) {
            mail_set($pdo, 'imap_last_uid', '0');
            $msg = 'دفعه‌ی بعد ۳۰ ایمیل آخر صندوق دوباره بررسی می‌شود (ایمیل‌های تکراری ذخیره نمی‌شوند).';
        }
    }
}

$c = mail_conf($pdo);
$cronKey = mail_get($pdo, 'cron_key', '');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$siteBase = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
$pageTitle = 'تنظیمات ایمیل';
require_once '../includes/header.php';

$inp = 'w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm';
$secSel = function ($name, $cur) {
    $o = ['ssl' => 'SSL/TLS (پیشنهادی)', 'tls' => 'STARTTLS', 'none' => 'بدون رمزنگاری (ناامن)'];
    $h = '<select name="' . $name . '" class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm">';
    foreach ($o as $k => $l) $h .= '<option value="' . $k . '"' . ($cur === $k ? ' selected' : '') . '>' . $l . '</option>';
    return $h . '</select>';
};
?>
<div class="max-w-4xl mx-auto px-4 py-8">
  <div class="flex justify-between items-center mb-6 flex-wrap gap-3">
    <div>
      <h1 class="text-2xl font-black text-slate-900 flex items-center gap-2"><span class="w-2 h-7 bg-brand-orange rounded-full"></span>⚙ تنظیمات ایمیل</h1>
      <p class="text-xs text-slate-500 mt-1">اتصال برنامه به صندوق ایمیل: ارسال با SMTP و دریافت با IMAP. همه‌ی پیام‌ها داخل همین برنامه ذخیره و در «پیام‌ها» مدیریت می‌شوند.</p>
    </div>
    <div class="flex gap-2">
      <a href="messages.php" class="bg-slate-800 text-white font-bold px-4 py-2.5 rounded-xl text-xs">📧 پیام‌ها</a>
      <a href="index.php" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold px-4 py-2.5 rounded-xl text-xs">← داشبورد</a>
    </div>
  </div>

  <?php if ($msg): ?>
    <div class="<?= $msgOk ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-red-50 border-red-200 text-red-700' ?> border rounded-2xl p-4 mb-4 text-xs leading-7"><?= sanitize($msg) ?></div>
  <?php endif; ?>

  <form method="post" class="space-y-5">
    <?= mail_csrf_field() ?>

    <section class="bg-white border border-slate-200 rounded-2xl p-5">
      <h2 class="font-black text-slate-800 mb-4">📤 ارسال ایمیل (SMTP)</h2>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div><label class="block text-xs font-bold text-slate-600 mb-2">سرور SMTP</label>
          <input name="smtp_host" dir="ltr" value="<?= sanitize($c['smtp_host']) ?>" placeholder="smtp.gmail.com" class="<?= $inp ?>"></div>
        <div class="grid grid-cols-2 gap-3">
          <div><label class="block text-xs font-bold text-slate-600 mb-2">پورت</label>
            <input name="smtp_port" type="number" dir="ltr" value="<?= (int)$c['smtp_port'] ?>" class="<?= $inp ?>"></div>
          <div><label class="block text-xs font-bold text-slate-600 mb-2">رمزنگاری</label><?= $secSel('smtp_secure', $c['smtp_secure']) ?></div>
        </div>
        <div><label class="block text-xs font-bold text-slate-600 mb-2">نام کاربری</label>
          <input name="smtp_user" dir="ltr" autocomplete="off" value="<?= sanitize($c['smtp_user']) ?>" placeholder="info@example.com" class="<?= $inp ?>"></div>
        <div><label class="block text-xs font-bold text-slate-600 mb-2">رمز عبور</label>
          <input name="smtp_pass" type="password" dir="ltr" autocomplete="new-password" placeholder="<?= $c['smtp_pass'] !== '' ? '•••••• ذخیره شده (برای تغییر، رمز جدید را بنویسید)' : '' ?>" class="<?= $inp ?>"></div>
        <div><label class="block text-xs font-bold text-slate-600 mb-2">ایمیل فرستنده (From)</label>
          <input name="from_email" type="email" dir="ltr" value="<?= sanitize($c['from_email']) ?>" placeholder="info@example.com" class="<?= $inp ?>"></div>
        <div><label class="block text-xs font-bold text-slate-600 mb-2">نام فرستنده</label>
          <input name="from_name" value="<?= sanitize($c['from_name']) ?>" class="<?= $inp ?>"></div>
        <div class="md:col-span-2"><label class="block text-xs font-bold text-slate-600 mb-2">ایمیل مدیر برای اطلاع از پیام‌های جدید سایت</label>
          <input name="notify_email" type="email" dir="ltr" value="<?= sanitize($c['notify_email']) ?>" placeholder="خالی = ایمیل ثبت‌شده در «اطلاعات تماس»" class="<?= $inp ?>"></div>
      </div>
      <label class="flex items-center gap-2 text-xs font-bold mt-4 cursor-pointer"><input type="checkbox" name="notify_on" value="1" <?= $c['notify_on'] ? 'checked' : '' ?> class="w-4 h-4">
        وقتی مشتری از فرم «تماس با ما» پیام می‌فرستد، یک ایمیل اطلاع‌رسانی برای مدیر ارسال شود</label>
    </section>

    <section class="bg-white border border-slate-200 rounded-2xl p-5">
      <h2 class="font-black text-slate-800 mb-4">📥 دریافت ایمیل (IMAP)</h2>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div><label class="block text-xs font-bold text-slate-600 mb-2">سرور IMAP</label>
          <input name="imap_host" dir="ltr" value="<?= sanitize($c['imap_host']) ?>" placeholder="imap.gmail.com" class="<?= $inp ?>"></div>
        <div class="grid grid-cols-2 gap-3">
          <div><label class="block text-xs font-bold text-slate-600 mb-2">پورت</label>
            <input name="imap_port" type="number" dir="ltr" value="<?= (int)$c['imap_port'] ?>" class="<?= $inp ?>"></div>
          <div><label class="block text-xs font-bold text-slate-600 mb-2">رمزنگاری</label><?= $secSel('imap_secure', $c['imap_secure']) ?></div>
        </div>
        <div><label class="block text-xs font-bold text-slate-600 mb-2">نام کاربری</label>
          <input name="imap_user" dir="ltr" autocomplete="off" value="<?= sanitize($c['imap_user']) ?>" class="<?= $inp ?>"></div>
        <div><label class="block text-xs font-bold text-slate-600 mb-2">رمز عبور</label>
          <input name="imap_pass" type="password" dir="ltr" autocomplete="new-password" placeholder="<?= $c['imap_pass'] !== '' ? '•••••• ذخیره شده (برای تغییر، رمز جدید را بنویسید)' : '' ?>" class="<?= $inp ?>"></div>
      </div>
      <label class="flex items-center gap-2 text-xs font-bold mt-4 cursor-pointer"><input type="checkbox" name="imap_enabled" value="1" <?= $c['imap_enabled'] ? 'checked' : '' ?> class="w-4 h-4">
        دریافت خودکار ایمیل‌ها فعال باشد (هنگام باز کردن «پیام‌ها» حداکثر هر ۵ دقیقه، و با کرون)</label>
      <label class="flex items-center gap-2 text-xs font-bold mt-2 cursor-pointer"><input type="checkbox" name="verify_ssl" value="1" <?= $c['verify_ssl'] ? 'checked' : '' ?> class="w-4 h-4">
        بررسی گواهی امنیتی سرور (فقط اگر سرور گواهی نامعتبر/خودامضا دارد خاموش کنید)</label>
    </section>

    <div class="flex gap-2 flex-wrap">
      <button name="save_mail" value="1" class="bg-brand-orange hover:bg-brand-hover text-white font-bold px-6 py-2.5 rounded-xl text-xs">💾 ذخیره تنظیمات</button>
    </div>

    <section class="bg-white border border-slate-200 rounded-2xl p-5">
      <h2 class="font-black text-slate-800 mb-3">🧪 آزمایش اتصال <span class="text-[11px] font-normal text-slate-400">(فرم قبل از تست خودکار ذخیره می‌شود)</span></h2>
      <div class="flex gap-2 flex-wrap items-center">
        <input name="test_to" type="email" dir="ltr" placeholder="ایمیل گیرنده‌ی آزمایشی" value="<?= sanitize($c['notify_email'] ?: $c['from_email']) ?>" class="px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm w-64">
        <button name="test_smtp" value="1" class="bg-slate-800 text-white font-bold px-5 py-2.5 rounded-xl text-xs">📨 ارسال ایمیل آزمایشی</button>
        <button name="test_imap" value="1" class="bg-slate-800 text-white font-bold px-5 py-2.5 rounded-xl text-xs">📥 تست اتصال به صندوق</button>
        <button name="reset_cursor" value="1" class="bg-white border border-slate-200 text-slate-600 font-bold px-4 py-2.5 rounded-xl text-xs" onclick="return confirm('۳۰ ایمیل آخر صندوق دوباره بررسی شود؟')">↺ بررسی دوباره‌ی ایمیل‌های اخیر</button>
      </div>
    </section>
  </form>

  <section class="bg-white border border-slate-200 rounded-2xl p-5 mt-5 text-xs leading-8">
    <h2 class="font-black text-slate-800 mb-2 text-sm">⏱ دریافت خودکار با کرون (اختیاری)</h2>
    <?php if ($cronKey !== ''): ?>
      <div>روی هاست، یک کرون‌جاب هر ۵ تا ۱۰ دقیقه یکی از این دو را اجرا کند:</div>
      <div dir="ltr" class="bg-slate-50 border border-slate-200 rounded-xl p-3 my-2 break-all text-left">php <?= sanitize(realpath(__DIR__ . '/../cron_fetch_mail.php') ?: 'cron_fetch_mail.php') ?></div>
      <div dir="ltr" class="bg-slate-50 border border-slate-200 rounded-xl p-3 break-all text-left"><?= sanitize($siteBase . '/cron_fetch_mail.php?key=' . $cronKey) ?></div>
    <?php else: ?>
      <div class="text-slate-500">بعد از ذخیره‌ی تنظیمات، آدرس کرون اینجا نمایش داده می‌شود.</div>
    <?php endif; ?>
  </section>

  <div class="bg-amber-50 border border-amber-200 rounded-2xl p-5 text-xs leading-8 text-amber-900 mt-5">
    <div class="font-black mb-1">📘 راهنما</div>
    • <b>Gmail:</b> SMTP ‎<b dir="ltr">smtp.gmail.com</b> پورت ۴۶۵ (SSL) — IMAP ‎<b dir="ltr">imap.gmail.com</b> پورت ۹۹۳ (SSL). به‌جای رمز اصلی باید «رمز برنامه (App Password)» بسازید (نیازمند فعال‌بودن تأیید دومرحله‌ای).<br>
    • <b>هاست (cPanel و مشابه):</b> معمولاً سرور <b dir="ltr">mail.دامنه‌شما.com</b>، پورت SMTP ‏۴۶۵ و IMAP ‏۹۹۳ با SSL، نام کاربری = آدرس کامل ایمیل.<br>
    • دریافت ایمیل <b>فقط‌خواندنی</b> است: ایمیل‌ها روی سرور می‌مانند و «خوانده‌شده» نمی‌شوند؛ فقط متن ایمیل ذخیره می‌شود و پیوست‌ها ذخیره نمی‌شوند.<br>
    • برای جلوگیری از هرزنامه‌شدن ایمیل‌های ارسالی، ایمیل فرستنده باید همان حساب (یا دامنه‌ی) سرور SMTP باشد.<br>
    • رمزها مثل توکن تلگرام در جدول تنظیمات دیتابیس ذخیره می‌شوند؛ برای ایمیل فروشگاه از یک حساب اختصاصی استفاده کنید.
  </div>
</div>
<?php require_once '../includes/footer.php'; ?>
