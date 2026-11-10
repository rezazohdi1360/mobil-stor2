<?php
/**
 * ماژول ایمیل فروشگاه
 *  - ارسال ایمیل از طریق SMTP (SSL / STARTTLS / بدون رمزنگاری)
 *  - دریافت ایمیل از طریق IMAP (فقط‌خواندنی؛ ایمیل‌ها روی سرور باقی می‌مانند و «خوانده‌شده» نمی‌شوند)
 *  - ذخیره همه‌ی پیام‌ها (ایمیل‌های دریافتی، ارسالی و پیام‌های فرم «تماس با ما») در جدول mail_messages
 * هیچ کتابخانه‌ی خارجی لازم نیست؛ فقط افزونه‌های openssl و mbstring (که در XAMPP فعال‌اند).
 * تنظیمات در جدول site_settings با پیشوند mail_ ذخیره می‌شود (پنل مدیریت ← تنظیمات ایمیل).
 */
require_once __DIR__ . '/../config/site.php';
require_once __DIR__ . '/coupons.php';   // fa_digits / jalali_str

// ════════════════════════════════════════════════════════════
//  جدول و تنظیمات
// ════════════════════════════════════════════════════════════
function mail_ensure(PDO $pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS mail_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        direction ENUM('in','out') NOT NULL DEFAULT 'in',
        source VARCHAR(20) NOT NULL DEFAULT 'email',
        thread_id INT NULL,
        from_name VARCHAR(190) NULL,
        from_email VARCHAR(190) NULL,
        to_email VARCHAR(190) NULL,
        phone VARCHAR(30) NULL,
        product_id INT NULL,
        subject VARCHAR(255) NULL,
        body MEDIUMTEXT NULL,
        attachments INT NOT NULL DEFAULT 0,
        message_id VARCHAR(190) NULL,
        in_reply_to VARCHAR(190) NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        is_trashed TINYINT(1) NOT NULL DEFAULT 0,
        send_status ENUM('none','sent','failed') NOT NULL DEFAULT 'none',
        send_error VARCHAR(500) NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uq_message_id (message_id),
        KEY idx_list (direction, is_trashed, id),
        KEY idx_thread (thread_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci");
}

/** همه‌ی تنظیمات mail_* (مستقیم از دیتابیس، بدون کش قدیمی) */
function mail_settings_all(PDO $pdo, $refresh = false) {
    static $c = null;
    if ($c === null || $refresh) {
        $c = [];
        try {
            foreach ($pdo->query("SELECT skey, svalue FROM site_settings WHERE skey LIKE 'mail%'")->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
                if (strpos($k, 'mail_') === 0) $c[substr($k, 5)] = (string)$v;
            }
        } catch (Throwable $e) {
            site_settings_ensure($pdo);
        }
    }
    return $c;
}
function mail_get(PDO $pdo, $key, $default = '') {
    $a = mail_settings_all($pdo);
    return array_key_exists($key, $a) ? $a[$key] : $default;
}
function mail_set(PDO $pdo, $key, $value) {
    site_settings_ensure($pdo);
    $pdo->prepare("REPLACE INTO site_settings (skey, svalue) VALUES (?, ?)")->execute(['mail_' . $key, (string)$value]);
    mail_settings_all($pdo, true);
}

function mail_conf(PDO $pdo) {
    $g = function ($k, $d = '') use ($pdo) { return trim(mail_get($pdo, $k, $d)); };
    $sec = function ($v) { return in_array($v, ['ssl', 'tls', 'none'], true) ? $v : 'ssl'; };
    // «رمز برنامه»ی Google را با فاصله نشان می‌دهد؛ برای Gmail فاصله‌ها حذف می‌شود
    $pw = function ($host, $pass) { return preg_match('/(^|\.)(gmail|googlemail)\.com$/i', $host) ? preg_replace('/\s+/', '', $pass) : $pass; };
    return [
        'smtp_host'     => $g('smtp_host'),
        'smtp_port'     => (int)($g('smtp_port', '465') ?: 465),
        'smtp_secure'   => $sec($g('smtp_secure', 'ssl')),
        'smtp_user'     => $g('smtp_user'),
        'smtp_pass'     => $pw($g('smtp_host'), mail_get($pdo, 'smtp_pass', '')),
        'from_email'    => $g('from_email'),
        'from_name'     => $g('from_name') ?: (defined('SITE_NAME') ? SITE_NAME : ''),
        'notify_email'  => $g('notify_email'),
        'notify_on'     => $g('notify_on', '1') === '1',
        'verify_ssl'    => $g('verify_ssl', '1') === '1',
        'imap_host'     => $g('imap_host'),
        'imap_port'     => (int)($g('imap_port', '993') ?: 993),
        'imap_secure'   => $sec($g('imap_secure', 'ssl')),
        'imap_user'     => $g('imap_user'),
        'imap_pass'     => $pw($g('imap_host'), mail_get($pdo, 'imap_pass', '')),
        'imap_enabled'  => $g('imap_enabled', '0') === '1',
    ];
}
function mail_smtp_ready(array $c) {
    return $c['smtp_host'] !== '' && filter_var($c['from_email'], FILTER_VALIDATE_EMAIL);
}
function mail_imap_ready(array $c) {
    return $c['imap_host'] !== '' && $c['imap_user'] !== '' && $c['imap_pass'] !== '';
}

// ════════════════════════════════════════════════════════════
//  CSRF برای فرم‌های پنل مدیریت
// ════════════════════════════════════════════════════════════
function mail_csrf() {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['mail_csrf'])) $_SESSION['mail_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['mail_csrf'];
}
function mail_csrf_field() {
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars(mail_csrf(), ENT_QUOTES) . '">';
}
function mail_csrf_ok() {
    return isset($_POST['csrf'], $_SESSION['mail_csrf']) && hash_equals($_SESSION['mail_csrf'], (string)$_POST['csrf']);
}

// ════════════════════════════════════════════════════════════
//  سوکت مشترک SMTP / IMAP
// ════════════════════════════════════════════════════════════
function mail_open_socket($host, $port, $secure, $verify) {
    $ssl = ['verify_peer' => (bool)$verify, 'verify_peer_name' => (bool)$verify, 'allow_self_signed' => !$verify];
    $ca = __DIR__ . '/../config/cacert.pem';     // بسته‌ی گواهی همراه پروژه (برای XAMPP ویندوز)
    if ($verify && is_file($ca)) $ssl['cafile'] = $ca;
    $ctx = stream_context_create(['ssl' => $ssl]);
    $scheme = $secure === 'ssl' ? 'ssl' : 'tcp';
    $fp = @stream_socket_client("$scheme://$host:$port", $errno, $errstr, 12, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        $hint = $secure === 'ssl' ? ' — اگر آدرس و پورت درست است، شاید گواهی امنیتی سرور معتبر نیست؛ گزینه «بررسی گواهی سرور» را خاموش کنید' : '';
        throw new RuntimeException("اتصال به $host:$port برقرار نشد" . ($errstr !== '' ? " ($errstr)" : '') . $hint);
    }
    stream_set_timeout($fp, 20);
    return $fp;
}
function mail_start_tls($fp) {
    if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        throw new RuntimeException('برقراری ارتباط امن (TLS) ناموفق بود. اگر سرور گواهی معتبر ندارد، گزینه «بررسی گواهی سرور» را خاموش کنید.');
    }
}
function mail_fwrite($fp, $data) {
    $len = strlen($data);
    for ($w = 0; $w < $len;) {
        $n = @fwrite($fp, substr($data, $w));
        if ($n === false || $n === 0) throw new RuntimeException('ارسال داده به سرور ناموفق بود (ارتباط قطع شد)');
        $w += $n;
    }
}

// ════════════════════════════════════════════════════════════
//  ساخت پیام MIME
// ════════════════════════════════════════════════════════════
function mail_clean_header($v) { return trim(preg_replace('/[\r\n]+/', ' ', (string)$v)); }

/** کدگذاری هدر غیر ASCII به صورت =?UTF-8?B?...?= (بدون شکستن وسط حرف) */
function mail_encode_header($s) {
    $s = mail_clean_header($s);
    if ($s === '' || !preg_match('/[^\x20-\x7e]/', $s)) return $s;
    $words = []; $cur = '';
    foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        if (strlen($cur) + strlen($ch) > 42) { $words[] = $cur; $cur = ''; }
        $cur .= $ch;
    }
    if ($cur !== '') $words[] = $cur;
    return implode("\r\n ", array_map(function ($w) { return '=?UTF-8?B?' . base64_encode($w) . '?='; }, $words));
}
function mail_format_addr($name, $email) {
    $name = mail_clean_header($name);
    $email = mail_clean_header($email);
    if ($name === '') return $email;
    if (preg_match('/[^\x20-\x7e]/', $name)) return mail_encode_header($name) . " <$email>";
    return '"' . addcslashes($name, '"\\') . "\" <$email>";
}

/** خروجی: [متن کامل پیام با CRLF, Message-ID] */
function mail_build_message($fromEmail, $fromName, $to, $subject, $body, array $extra = []) {
    $domain = substr(strrchr($fromEmail, '@'), 1) ?: 'localhost';
    $mid = '<' . bin2hex(random_bytes(14)) . '@' . $domain . '>';
    $h = [
        'Date: ' . date('r'),
        'From: ' . mail_format_addr($fromName, $fromEmail),
        'To: ' . mail_clean_header($to),
        'Subject: ' . mail_encode_header($subject),
        'Message-ID: ' . $mid,
        'MIME-Version: 1.0',
        'X-Mailer: MobileStore-Mail',
    ];
    foreach ($extra as $k => $v) $h[] = mail_clean_header($k) . ': ' . mail_clean_header($v);
    $h[] = 'Content-Type: text/plain; charset=UTF-8';
    $h[] = 'Content-Transfer-Encoding: base64';
    $body = str_replace(["\r\n", "\r"], "\n", (string)$body);
    $body = str_replace("\n", "\r\n", $body);
    return [implode("\r\n", $h) . "\r\n\r\n" . chunk_split(base64_encode($body), 76, "\r\n"), $mid];
}

// ════════════════════════════════════════════════════════════
//  کلاینت SMTP
// ════════════════════════════════════════════════════════════
class MailSmtp {
    private $fp = null;
    private $c;

    public function __construct(array $conf) { $this->c = $conf; }

    private function read() {
        $all = '';
        do {
            $line = fgets($this->fp, 2048);
            if ($line === false) throw new RuntimeException('پاسخی از سرور SMTP نیامد (ارتباط قطع شد یا زمان تمام شد)');
            $all .= $line;
        } while (isset($line[3]) && $line[3] === '-');
        return [(int)substr($all, 0, 3), trim($all)];
    }
    private function cmd($line, array $ok) {
        mail_fwrite($this->fp, $line . "\r\n");
        [$code, $resp] = $this->read();
        if (!in_array($code, $ok, true)) throw new RuntimeException('خطای SMTP: ' . $resp);
        return $resp;
    }

    /** ارسال یک ایمیل متنی. خروجی: Message-ID. در صورت خطا RuntimeException */
    public function send($fromEmail, $fromName, $to, $subject, $body, array $extraHeaders = []) {
        $c = $this->c;
        $this->fp = mail_open_socket($c['smtp_host'], $c['smtp_port'], $c['smtp_secure'], $c['verify_ssl']);
        try {
            [$code, $r] = $this->read();
            if ($code !== 220) throw new RuntimeException('خطای SMTP: ' . $r);
            $helo = preg_replace('/[^A-Za-z0-9.\-]/', '', (string)($_SERVER['SERVER_NAME'] ?? gethostname())) ?: 'localhost';
            $ehlo = $this->cmd("EHLO $helo", [250]);
            if ($c['smtp_secure'] === 'tls') {
                $this->cmd('STARTTLS', [220]);
                mail_start_tls($this->fp);
                $ehlo = $this->cmd("EHLO $helo", [250]);
            }
            if ($c['smtp_user'] !== '') {
                if (preg_match('/AUTH[ =].*\bPLAIN\b/i', $ehlo)) {
                    $this->cmd('AUTH PLAIN ' . base64_encode("\0{$c['smtp_user']}\0{$c['smtp_pass']}"), [235]);
                } else {
                    $this->cmd('AUTH LOGIN', [334]);
                    $this->cmd(base64_encode($c['smtp_user']), [334]);
                    $this->cmd(base64_encode($c['smtp_pass']), [235]);
                }
            }
            $this->cmd('MAIL FROM:<' . mail_clean_header($fromEmail) . '>', [250]);
            $this->cmd('RCPT TO:<' . mail_clean_header($to) . '>', [250, 251]);
            $this->cmd('DATA', [354]);
            [$raw, $mid] = mail_build_message($fromEmail, $fromName, $to, $subject, $body, $extraHeaders);
            mail_fwrite($this->fp, preg_replace('/^\./m', '..', $raw) . "\r\n.\r\n");
            [$code, $r] = $this->read();
            if ($code !== 250) throw new RuntimeException('خطای SMTP: ' . $r);
            @fwrite($this->fp, "QUIT\r\n");
            return $mid;
        } finally {
            if (is_resource($this->fp)) fclose($this->fp);
        }
    }
}

// ════════════════════════════════════════════════════════════
//  کلاینت IMAP (فقط‌خواندنی)
// ════════════════════════════════════════════════════════════
class MailImap {
    private $fp = null;
    private $c;
    private $n = 0;

    public function __construct(array $conf) { $this->c = $conf; }

    private static function q($s) {
        return '"' . addcslashes(preg_replace('/[\r\n]+/', '', (string)$s), "\\\"") . '"';
    }

    public function open() {
        $c = $this->c;
        $this->fp = mail_open_socket($c['imap_host'], $c['imap_port'], $c['imap_secure'], $c['verify_ssl']);
        $g = fgets($this->fp, 4096);
        if ($g === false || !preg_match('/^\* (OK|PREAUTH)/i', $g)) throw new RuntimeException('پاسخ نامعتبر از سرور IMAP: ' . trim((string)$g));
        if ($c['imap_secure'] === 'tls') {
            $this->cmd('STARTTLS');
            mail_start_tls($this->fp);
        }
        $this->cmd('LOGIN ' . self::q($c['imap_user']) . ' ' . self::q($c['imap_pass']));
    }

    public function close() {
        if (is_resource($this->fp)) {
            try { @mail_fwrite($this->fp, 'A' . (++$this->n) . " LOGOUT\r\n"); } catch (Throwable $e) {}
            @fclose($this->fp);
        }
        $this->fp = null;
    }

    /** اجرای یک دستور. خروجی: ['lines' => [...], 'literals' => [...]] ؛ در خطا RuntimeException */
    public function cmd($line) {
        $tag = 'A' . (++$this->n);
        mail_fwrite($this->fp, "$tag $line\r\n");
        $lines = []; $lits = [];
        while (true) {
            $l = fgets($this->fp, 65536);
            if ($l === false) throw new RuntimeException('ارتباط با سرور IMAP قطع شد یا زمان تمام شد');
            if (strncmp($l, "$tag ", strlen($tag) + 1) === 0) {
                $status = strtoupper(strtok(substr($l, strlen($tag) + 1), ' '));
                if ($status !== 'OK') throw new RuntimeException('خطای IMAP: ' . trim(substr($l, strlen($tag) + 1)));
                $lines[] = rtrim($l);
                break;
            }
            $lines[] = rtrim($l);
            while (preg_match('/\{(\d+)\}\s*$/', $l, $m)) {
                $len = (int)$m[1]; $buf = '';
                while (strlen($buf) < $len) {
                    $chunk = fread($this->fp, min(65536, $len - strlen($buf)));
                    if ($chunk === false || ($chunk === '' && feof($this->fp))) throw new RuntimeException('دریافت ایمیل ناقص ماند (ارتباط قطع شد)');
                    $buf .= $chunk;
                }
                $lits[] = $buf;
                $l = fgets($this->fp, 65536);
                if ($l === false) throw new RuntimeException('ارتباط با سرور IMAP قطع شد');
                $lines[] = rtrim($l);
            }
        }
        return ['lines' => $lines, 'literals' => $lits];
    }

    /** انتخاب INBOX. خروجی: [تعداد ایمیل‌ها, UIDVALIDITY] */
    public function selectInbox() {
        $r = $this->cmd('SELECT INBOX');
        $all = implode("\n", $r['lines']);
        $exists = preg_match('/\* (\d+) EXISTS/i', $all, $m) ? (int)$m[1] : 0;
        $valid  = preg_match('/UIDVALIDITY (\d+)/i', $all, $m) ? $m[1] : '';
        return [$exists, $valid];
    }

    /**
     * ایمیل‌های جدیدتر از lastUid را می‌آورد. خروجی: [ [uid => متن خام], ... ] به ترتیب قدیمی‌به‌جدید.
     * اگر lastUid صفر باشد فقط $firstRun ایمیل آخر آورده می‌شود.
     */
    public function fetchAfter($lastUid, $limit = 60, $firstRun = 30) {
        $r = $this->cmd($lastUid > 0 ? 'UID SEARCH UID ' . ($lastUid + 1) . ':*' : 'UID SEARCH ALL');
        $uids = [];
        foreach ($r['lines'] as $l) {
            if (preg_match('/^\* SEARCH ?(.*)$/i', $l, $m)) {
                foreach (preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY) as $u) {
                    if ((int)$u > $lastUid) $uids[] = (int)$u;
                }
            }
        }
        sort($uids);
        $uids = $lastUid > 0 ? array_slice($uids, 0, $limit) : array_slice($uids, -$firstRun);
        $out = [];
        foreach ($uids as $uid) {
            // BODY.PEEK = ایمیل «خوانده‌شده» نمی‌شود؛ حداکثر ۱٫۵ مگابایت اول هر ایمیل
            $f = $this->cmd("UID FETCH $uid (BODY.PEEK[]<0.1500000>)");
            $out[$uid] = $f['literals'][0] ?? '';
        }
        return $out;
    }
}

// ════════════════════════════════════════════════════════════
//  تجزیه‌ی ایمیل خام
// ════════════════════════════════════════════════════════════
function mail_to_utf8($s, $charset) {
    $cs = strtolower(trim((string)$charset, " \t\"'"));
    if ($cs === '' || $cs === 'utf-8' || $cs === 'utf8' || $cs === 'us-ascii' || $cs === 'ascii') {
        return function_exists('mb_scrub') ? mb_scrub($s, 'UTF-8') : $s;
    }
    if (function_exists('iconv')) {
        $r = @iconv($cs, 'UTF-8//IGNORE', $s);
        if ($r !== false) return $r;
    }
    try { return mb_convert_encoding($s, 'UTF-8', $cs); } catch (Throwable $e) {}
    return function_exists('mb_scrub') ? mb_scrub($s, 'UTF-8') : $s;
}

function mail_header_decode($v) {
    $v = trim((string)$v);
    if ($v === '' || strpos($v, '=?') === false) return mail_to_utf8($v, 'utf-8');
    if (function_exists('iconv_mime_decode')) {
        $d = @iconv_mime_decode($v, defined('ICONV_MIME_DECODE_CONTINUE_ON_ERROR') ? ICONV_MIME_DECODE_CONTINUE_ON_ERROR : 2, 'UTF-8');
        if ($d !== false) return $d;
    }
    return function_exists('mb_decode_mimeheader') ? mb_decode_mimeheader($v) : $v;
}

/** متن (با LF) → [هدرها (کلید کوچک)، بدنه] */
function mail_split_parts($text) {
    $pos = strpos($text, "\n\n");
    if ($pos === false) { $head = $text; $body = ''; }
    else { $head = substr($text, 0, $pos); $body = substr($text, $pos + 2); }
    $head = preg_replace("/\n[ \t]+/", ' ', $head);
    $h = [];
    foreach (explode("\n", $head) as $line) {
        if (strpos($line, ':') === false) continue;
        [$k, $v] = explode(':', $line, 2);
        $k = strtolower(trim($k));
        if ($k !== '' && !isset($h[$k])) $h[$k] = trim($v);
    }
    return [$h, $body];
}

function mail_parse_ct($v) {
    $parts = explode(';', (string)$v);
    $type = strtolower(trim(array_shift($parts))) ?: 'text/plain';
    $p = [];
    foreach ($parts as $x) {
        if (preg_match('/^\s*([\w\-*]+)\s*=\s*"?([^"]*)"?\s*$/s', $x, $m)) $p[strtolower($m[1])] = trim($m[2]);
    }
    return [$type, $p];
}

function mail_html_to_text($h) {
    $h = preg_replace('#<(style|script|head)\b[^>]*>.*?</\1>#si', '', $h);
    $h = preg_replace('#<br\s*/?>|</(p|div|tr|li|h[1-6])>#i', "\n", $h);
    $t = html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace('/[ \t]+/', ' ', $t);
    return trim(preg_replace("/\n[ \t]*\n[\n \t]*/", "\n\n", $t));
}

/** استخراج متن از یک بخش MIME. خروجی: ['plain' => ..., 'html' => ...] ؛ تعداد پیوست‌ها در $att */
function mail_extract(array $headers, $body, &$att, $depth = 0) {
    [$type, $p] = mail_parse_ct($headers['content-type'] ?? 'text/plain');
    $cte  = strtolower(trim($headers['content-transfer-encoding'] ?? '7bit'));
    $disp = strtolower($headers['content-disposition'] ?? '');
    $res = ['plain' => '', 'html' => ''];

    if (strpos($type, 'multipart/') === 0 && !empty($p['boundary']) && $depth < 6) {
        $segs = preg_split('/^--' . preg_quote($p['boundary'], '/') . '(?:--)?[ \t]*$/m', $body);
        array_shift($segs);                                   // مقدمه‌ی قبل از اولین مرز
        foreach ($segs as $seg) {
            $seg = preg_replace('/^\n/', '', $seg);
            if (trim($seg) === '') continue;
            [$ph, $pb] = mail_split_parts($seg);
            $r = mail_extract($ph, $pb, $att, $depth + 1);
            foreach (['plain', 'html'] as $k) {
                if ($r[$k] !== '') $res[$k] .= ($res[$k] !== '' ? "\n\n" : '') . $r[$k];
            }
        }
        return $res;
    }
    if (strpos($disp, 'attachment') !== false || strpos($type, 'text/') !== 0) {
        $att++;
        return $res;
    }
    if ($cte === 'base64') $body = (string)base64_decode(preg_replace('/\s+/', '', $body));
    elseif ($cte === 'quoted-printable') $body = quoted_printable_decode($body);
    $body = mail_to_utf8($body, $p['charset'] ?? 'utf-8');
    if ($type === 'text/html') $res['html'] = $body;
    else $res['plain'] = $body;
    return $res;
}

/** ایمیل خام → آرایه‌ی آماده‌ی ذخیره */
function mail_parse_raw($raw) {
    $raw = str_replace(["\r\n", "\r"], "\n", (string)$raw);
    [$h, $body] = mail_split_parts($raw);

    $addr = function ($v) {
        $v = mail_header_decode($v);
        if (preg_match('/^\s*"?(.*?)"?\s*<([^>]+)>/s', $v, $m)) return [trim($m[1], " \"'"), trim($m[2])];
        if (preg_match('/[^\s<>,;]+@[^\s<>,;]+/', $v, $m)) return ['', $m[0]];
        return ['', trim($v)];
    };
    [$fromName, $fromEmail] = $addr($h['from'] ?? '');
    [, $toEmail] = $addr(explode(',', $h['to'] ?? '')[0]);

    $att = 0;
    $r = mail_extract($h, $body, $att);
    $text = $r['plain'] !== '' ? $r['plain'] : mail_html_to_text($r['html']);
    $text = trim(preg_replace("/\n{4,}/", "\n\n\n", $text));

    $mid = '';
    if (preg_match('/<([^>]+)>/', $h['message-id'] ?? '', $m)) $mid = '<' . trim($m[1]) . '>';
    if ($mid === '') $mid = '<' . sha1($raw) . '@generated.local>';
    if (strlen($mid) > 190) $mid = '<' . sha1($mid) . '@long.local>';
    $irt = preg_match('/<([^>]+)>/', $h['in-reply-to'] ?? '', $m) ? '<' . trim($m[1]) . '>' : '';
    if (strlen($irt) > 190) $irt = '';

    $ts = isset($h['date']) ? strtotime($h['date']) : false;
    if (!$ts || $ts > time() + 86400) $ts = time();

    return [
        'from_name'   => mb_substr($fromName, 0, 190),
        'from_email'  => mb_substr($fromEmail, 0, 190),
        'to_email'    => mb_substr($toEmail, 0, 190),
        'subject'     => mb_substr(mail_header_decode($h['subject'] ?? ''), 0, 255),
        'body'        => mb_substr($text, 0, 100000),
        'attachments' => $att,
        'message_id'  => $mid,
        'in_reply_to' => $irt,
        'created_at'  => date('Y-m-d H:i:s', $ts),
        'is_notify'   => isset($h['x-mobile-store-notify']),
    ];
}

// ════════════════════════════════════════════════════════════
//  ذخیره‌سازی
// ════════════════════════════════════════════════════════════
/** ذخیره‌ی یک پیام. خروجی: شناسه‌ی جدید، یا 0 اگر قبلاً ذخیره شده بود (Message-ID تکراری) */
function mail_store(PDO $pdo, array $m) {
    mail_ensure($pdo);
    $m += [
        'direction' => 'in', 'source' => 'email', 'thread_id' => null, 'from_name' => null, 'from_email' => null,
        'to_email' => null, 'phone' => null, 'product_id' => null, 'subject' => '', 'body' => '', 'attachments' => 0,
        'message_id' => null, 'in_reply_to' => null, 'is_read' => 0, 'send_status' => 'none', 'send_error' => null,
        'created_at' => date('Y-m-d H:i:s'),
    ];
    if (empty($m['thread_id']) && !empty($m['in_reply_to'])) {      // وصل‌کردن به گفتگوی قبلی
        $st = $pdo->prepare("SELECT id, thread_id FROM mail_messages WHERE message_id = ? LIMIT 1");
        $st->execute([$m['in_reply_to']]);
        if ($p = $st->fetch()) $m['thread_id'] = $p['thread_id'] ?: $p['id'];
    }
    try {
        $pdo->prepare("INSERT INTO mail_messages
            (direction, source, thread_id, from_name, from_email, to_email, phone, product_id, subject, body, attachments,
             message_id, in_reply_to, is_read, send_status, send_error, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([
                $m['direction'], $m['source'], $m['thread_id'], $m['from_name'], $m['from_email'], $m['to_email'],
                $m['phone'], $m['product_id'], $m['subject'], $m['body'], (int)$m['attachments'],
                $m['message_id'], $m['in_reply_to'], (int)$m['is_read'], $m['send_status'],
                $m['send_error'] !== null ? mb_substr($m['send_error'], 0, 500) : null, $m['created_at'],
            ]);
        return (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000') return 0;
        throw $e;
    }
}

function mail_unread_count(PDO $pdo) {
    try {
        mail_ensure($pdo);
        return (int)$pdo->query("SELECT COUNT(*) FROM mail_messages WHERE direction='in' AND is_read=0 AND is_trashed=0")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

// ════════════════════════════════════════════════════════════
//  دریافت ایمیل جدید (IMAP)
// ════════════════════════════════════════════════════════════
/** خروجی: [موفق؟, پیام, تعداد ایمیل جدید] */
function mail_fetch_new(PDO $pdo) {
    mail_ensure($pdo);
    $c = mail_conf($pdo);
    if (!mail_imap_ready($c)) return [false, 'تنظیمات دریافت ایمیل (IMAP) کامل نیست', 0];
    @set_time_limit(120);
    $new = 0;
    $imap = new MailImap($c);
    try {
        $imap->open();
        [, $valid] = $imap->selectInbox();
        $last = (int)mail_get($pdo, 'imap_last_uid', '0');
        if ($valid !== '' && $valid !== mail_get($pdo, 'imap_uidvalidity', '')) $last = 0;   // صندوق بازسازی شده
        $mails = $imap->fetchAfter($last);
        foreach ($mails as $uid => $raw) {
            if ($raw !== '') {
                $m = mail_parse_raw($raw);
                if (!$m['is_notify'] && mail_store($pdo, ['direction' => 'in', 'source' => 'email'] + $m)) $new++;
            }
            $last = max($last, $uid);
        }
        $imap->close();
        mail_set($pdo, 'imap_last_uid', $last);
        mail_set($pdo, 'imap_uidvalidity', $valid);
        mail_set($pdo, 'last_fetch', date('Y-m-d H:i:s') . '|ok|' . $new);
        return [true, $new > 0 ? "$new ایمیل جدید دریافت شد" : 'ایمیل جدیدی وجود ندارد', $new];
    } catch (Throwable $e) {
        $imap->close();
        mail_set($pdo, 'last_fetch', date('Y-m-d H:i:s') . '|err|' . mb_substr($e->getMessage(), 0, 200));
        return [false, $e->getMessage(), $new];
    }
}

// ════════════════════════════════════════════════════════════
//  ارسال ایمیل (SMTP) + ذخیره در «ارسال‌شده‌ها»
// ════════════════════════════════════════════════════════════
/** خروجی: [موفق؟, پیام, شناسه‌ی پیام ذخیره‌شده] */
function mail_send(PDO $pdo, $to, $subject, $body, $replyToId = 0) {
    mail_ensure($pdo);
    $c = mail_conf($pdo);
    $to = trim((string)$to);
    $subject = mb_substr(mail_clean_header($subject), 0, 255);
    $body = trim((string)$body);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return [false, 'آدرس ایمیل گیرنده معتبر نیست', 0];
    if ($body === '') return [false, 'متن پیام خالی است', 0];
    if (mb_strlen($body) > 50000) return [false, 'متن پیام بیش از حد طولانی است', 0];
    if (!mail_smtp_ready($c)) return [false, 'ابتدا تنظیمات ارسال ایمیل (SMTP) را کامل کنید', 0];

    $reply = null;
    if ($replyToId > 0) {
        $st = $pdo->prepare("SELECT id, thread_id, message_id FROM mail_messages WHERE id = ?");
        $st->execute([(int)$replyToId]);
        $reply = $st->fetch() ?: null;
    }
    $hdr = [];
    if ($reply && $reply['message_id']) { $hdr['In-Reply-To'] = $reply['message_id']; $hdr['References'] = $reply['message_id']; }

    $mid = null; $err = '';
    try {
        $mid = (new MailSmtp($c))->send($c['from_email'], $c['from_name'], $to, $subject !== '' ? $subject : '(بدون موضوع)', $body, $hdr);
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
    $id = mail_store($pdo, [
        'direction' => 'out', 'source' => 'admin',
        'thread_id' => $reply ? ($reply['thread_id'] ?: $reply['id']) : null,
        'from_name' => $c['from_name'], 'from_email' => $c['from_email'], 'to_email' => $to,
        'subject' => $subject, 'body' => $body, 'message_id' => $mid,
        'in_reply_to' => $reply['message_id'] ?? null, 'is_read' => 1,
        'send_status' => $mid ? 'sent' : 'failed', 'send_error' => $err ?: null,
    ]);
    return [(bool)$mid, $mid ? 'ایمیل ارسال شد و در «ارسال‌شده‌ها» ذخیره شد' : $err, $id];
}

/** ارسال مجدد پیامی که ارسالش شکست خورده بود */
function mail_resend(PDO $pdo, $id) {
    $c = mail_conf($pdo);
    $st = $pdo->prepare("SELECT * FROM mail_messages WHERE id = ? AND direction = 'out' AND send_status = 'failed'");
    $st->execute([(int)$id]);
    $m = $st->fetch();
    if (!$m) return [false, 'پیام پیدا نشد'];
    if (!mail_smtp_ready($c)) return [false, 'ابتدا تنظیمات ارسال ایمیل (SMTP) را کامل کنید'];
    $hdr = $m['in_reply_to'] ? ['In-Reply-To' => $m['in_reply_to'], 'References' => $m['in_reply_to']] : [];
    try {
        $mid = (new MailSmtp($c))->send($c['from_email'], $c['from_name'], $m['to_email'], $m['subject'] ?: '(بدون موضوع)', $m['body'], $hdr);
        $pdo->prepare("UPDATE mail_messages SET send_status='sent', send_error=NULL, message_id=?, created_at=? WHERE id=?")
            ->execute([$mid, date('Y-m-d H:i:s'), $m['id']]);
        return [true, 'ایمیل ارسال شد'];
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE mail_messages SET send_error=? WHERE id=?")->execute([mb_substr($e->getMessage(), 0, 500), $m['id']]);
        return [false, $e->getMessage()];
    }
}

// ════════════════════════════════════════════════════════════
//  پیام فرم «تماس با ما» + اطلاع‌رسانی به مدیر
// ════════════════════════════════════════════════════════════
/** ذخیره‌ی پیام فرم تماس در صندوق پیام و (در صورت فعال بودن) اطلاع به ایمیل مدیر */
function mail_receive_contact(PDO $pdo, $name, $phone, $email, $message, $productId = 0) {
    $id = mail_store($pdo, [
        'direction' => 'in', 'source' => 'contact_form',
        'from_name' => mb_substr($name, 0, 190), 'from_email' => $email !== '' ? mb_substr($email, 0, 190) : null,
        'phone' => mb_substr($phone, 0, 30), 'product_id' => $productId ?: null,
        'subject' => 'پیام از فرم «تماس با ما» — ' . mb_substr($name, 0, 60),
        'body' => $message, 'message_id' => '<' . bin2hex(random_bytes(12)) . '@contact-form.local>',
    ]);
    if ($id) mail_defer(function () use ($pdo, $id) { mail_notify_admin($pdo, $id); });
    return $id;
}

function mail_notify_admin(PDO $pdo, $id) {
    $c = mail_conf($pdo);
    $to = $c['notify_email'] ?: (defined('SITE_EMAIL') ? SITE_EMAIL : '');
    if (!$c['notify_on'] || !mail_smtp_ready($c) || !filter_var($to, FILTER_VALIDATE_EMAIL)) return;
    $st = $pdo->prepare("SELECT * FROM mail_messages WHERE id = ?");
    $st->execute([(int)$id]);
    if (!($m = $st->fetch())) return;
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base = isset($_SERVER['HTTP_HOST']) ? $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') : '';
    $body = "پیام جدیدی در فروشگاه ثبت شد:\n\n"
          . 'نام: ' . $m['from_name'] . "\n"
          . ($m['phone'] ? 'تلفن: ' . $m['phone'] . "\n" : '')
          . ($m['from_email'] ? 'ایمیل: ' . $m['from_email'] . "\n" : '')
          . "\n" . $m['body'] . "\n\n"
          . ($base ? 'مشاهده و پاسخ در پنل مدیریت: ' . $base . '/admin/messages.php?id=' . $m['id'] : '');
    try {
        (new MailSmtp($c))->send($c['from_email'], $c['from_name'], $to, 'پیام جدید از سایت — ' . $m['from_name'], $body, ['X-Mobile-Store-Notify' => '1']);
    } catch (Throwable $e) { /* بی‌صدا؛ پیام در هر حال ذخیره شده است */ }
}

/** اجرای کار بعد از بستن اتصال مشتری تا منتظر SMTP نماند */
function mail_defer(callable $fn) {
    register_shutdown_function(function () use ($fn) {
        ignore_user_abort(true);
        @set_time_limit(60);
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            while (ob_get_level() > 0) @ob_end_flush();
            flush();
        }
        try { $fn(); } catch (Throwable $e) {}
    });
}
