<?php
/**
 * دریافت ایمیل‌های جدید برای کرون‌جاب
 *  خط فرمان:  php cron_fetch_mail.php
 *  آدرس وب:   cron_fetch_mail.php?key=کلید (کلید در پنل مدیریت ← تنظیمات ایمیل نمایش داده می‌شود)
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/mail.php';

if (PHP_SAPI !== 'cli') {
    $key = mail_get($pdo, 'cron_key', '');
    if ($key === '' || !hash_equals($key, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}
[$ok, $msg, $n] = mail_fetch_new($pdo);
echo ($ok ? 'OK' : 'ERROR') . ' - ' . $msg . "\n";
