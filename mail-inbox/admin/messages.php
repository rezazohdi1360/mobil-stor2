<?php
/**
 * صندوق پیام مدیر: ایمیل‌های دریافتی، پیام‌های فرم تماس، ارسال‌شده‌ها، پاسخ و ارسال ایمیل جدید
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/mail.php';

if (!isAdmin()) redirect('../login.php');
mail_ensure($pdo);
$conf = mail_conf($pdo);

$tab = in_array($_GET['tab'] ?? $_POST['tab'] ?? '', ['inbox', 'sent', 'trash'], true) ? ($_GET['tab'] ?? $_POST['tab']) : 'inbox';
$q   = trim((string)($_GET['q'] ?? ''));

// ───── عملیات (POST) ─────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!mail_csrf_ok()) { setFlash('error', 'نشست شما منقضی شده است؛ دوباره تلاش کنید.'); redirect('messages.php'); }
    $act = $_POST['act'] ?? '';
    $id  = (int)($_POST['id'] ?? 0);
    $back = 'messages.php?tab=' . $tab;

    if ($act === 'fetch') {
        [$ok, $msg] = mail_fetch_new($pdo);
        setFlash($ok ? 'success' : 'error', $msg);
        $back = 'messages.php?tab=inbox';
    } elseif ($act === 'trash' && $id) {
        $pdo->prepare("UPDATE mail_messages SET is_trashed = 1 WHERE id = ?")->execute([$id]);
        setFlash('success', 'پیام به سطل زباله منتقل شد');
    } elseif ($act === 'restore' && $id) {
        $pdo->prepare("UPDATE mail_messages SET is_trashed = 0 WHERE id = ?")->execute([$id]);
        setFlash('success', 'پیام بازیابی شد');
    } elseif ($act === 'purge' && $id) {
        $pdo->prepare("DELETE FROM mail_messages WHERE id = ? AND is_trashed = 1")->execute([$id]);
        setFlash('success', 'پیام برای همیشه حذف شد');
    } elseif ($act === 'empty_trash') {
        $n = $pdo->exec("DELETE FROM mail_messages WHERE is_trashed = 1");
        setFlash('success', "سطل زباله خالی شد ($n پیام)");
    } elseif ($act === 'unread' && $id) {
        $pdo->prepare("UPDATE mail_messages SET is_read = 0 WHERE id = ? AND direction = 'in'")->execute([$id]);
        setFlash('success', 'پیام به‌عنوان «خوانده‌نشده» علامت خورد');
    } elseif ($act === 'resend' && $id) {
        [$ok, $msg] = mail_resend($pdo, $id);
        setFlash($ok ? 'success' : 'error', $msg);
        $back = 'messages.php?id=' . $id;
    } elseif ($act === 'send') {
        $replyTo = (int)($_POST['reply_to'] ?? 0);
        [$ok, $msg, $newId] = mail_send($pdo, $_POST['to'] ?? '', $_POST['subject'] ?? '', $_POST['body'] ?? '', $replyTo);
        if ($ok) setFlash('success', $msg);
        else setFlash('error', 'ارسال نشد: ' . $msg . ($newId ? ' — پیام در «ارسال‌شده‌ها» با وضعیت «ناموفق» ذخیره شد و می‌توانید دوباره ارسال کنید.' : ''));
        $back = $replyTo ? 'messages.php?id=' . $replyTo : 'messages.php?tab=sent';
    }
    redirect($back);
}

// ───── دریافت خودکار ایمیل جدید (حداکثر هر ۵ دقیقه) ─────
$isList = !isset($_GET['id']) && !isset($_GET['compose']);
if ($isList && $tab === 'inbox' && $q === '' && $conf['imap_enabled'] && mail_imap_ready($conf)) {
    $lf = explode('|', mail_get($pdo, 'last_fetch', ''), 3);
    if (!$lf[0] || strtotime($lf[0]) < time() - 300) mail_fetch_new($pdo);
}

// ───── نمایش یک پیام ─────
$view = null; $thread = [];
if (isset($_GET['id'])) {
    $st = $pdo->prepare("SELECT * FROM mail_messages WHERE id = ?");
    $st->execute([(int)$_GET['id']]);
    $view = $st->fetch() ?: null;
    if ($view) {
        if ($view['direction'] === 'in' && !$view['is_read']) {
            $pdo->prepare("UPDATE mail_messages SET is_read = 1 WHERE id = ?")->execute([$view['id']]);
            $view['is_read'] = 1;
        }
        $root = $view['thread_id'] ?: $view['id'];
        $st = $pdo->prepare("SELECT * FROM mail_messages WHERE id = ? OR thread_id = ? ORDER BY created_at, id");
        $st->execute([$root, $root]);
        $thread = $st->fetchAll();
    }
}

// ───── شمارنده‌ها ─────
$cr = $pdo->query("SELECT
    COALESCE(SUM(CASE WHEN direction='in'  AND is_trashed=0 THEN 1 ELSE 0 END),0) AS inbox,
    COALESCE(SUM(CASE WHEN direction='in'  AND is_trashed=0 AND is_read=0 THEN 1 ELSE 0 END),0) AS unread,
    COALESCE(SUM(CASE WHEN direction='out' AND is_trashed=0 THEN 1 ELSE 0 END),0) AS sent,
    COALESCE(SUM(CASE WHEN is_trashed=1 THEN 1 ELSE 0 END),0) AS trash
    FROM mail_messages")->fetch();

// ───── لیست ─────
$rows = []; $total = 0; $pages = 1; $page = 1;
if ($isList) {
    $where = $tab === 'trash' ? 'is_trashed = 1' : 'is_trashed = 0 AND direction = ' . ($tab === 'sent' ? "'out'" : "'in'");
    $params = [];
    if ($q !== '') {
        $where .= " AND (subject LIKE ? OR from_name LIKE ? OR from_email LIKE ? OR to_email LIKE ? OR body LIKE ?)";
        $params = array_fill(0, 5, '%' . $q . '%');
    }
    $st = $pdo->prepare("SELECT COUNT(*) FROM mail_messages WHERE $where");
    $st->execute($params);
    $total = (int)$st->fetchColumn();
    $perPage = 25;
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($pages, max(1, (int)($_GET['page'] ?? 1)));
    $off = ($page - 1) * $perPage;
    $st = $pdo->prepare("SELECT * FROM mail_messages WHERE $where ORDER BY created_at DESC, id DESC LIMIT $perPage OFFSET $off");
    $st->execute($params);
    $rows = $st->fetchAll();
}

$when = function ($dt) { return fa_digits(jalali_str($dt, false) . ' — ' . date('H:i', strtotime($dt))); };
$lf = explode('|', mail_get($pdo, 'last_fetch', ''), 3);
$pageTitle = 'پیام‌ها و ایمیل';
require_once '../includes/header.php';
$tabs = ['inbox' => ['📥 دریافتی', $cr['inbox']], 'sent' => ['📤 ارسال‌شده', $cr['sent']], 'trash' => ['🗑 سطل زباله', $cr['trash']]];
$srcLbl = ['email' => '✉️ ایمیل', 'contact_form' => '📞 فرم تماس', 'admin' => '✍️ مدیر'];
?>
<div class="max-w-6xl mx-auto px-4 py-8">
  <div class="flex justify-between items-center mb-5 flex-wrap gap-3">
    <h1 class="text-2xl font-black text-slate-900 flex items-center gap-2"><span class="w-2 h-7 bg-brand-orange rounded-full"></span>📧 پیام‌ها و ایمیل</h1>
    <div class="flex gap-2 flex-wrap">
      <a href="messages.php?compose=1" class="bg-brand-orange hover:bg-brand-hover text-white font-bold px-4 py-2.5 rounded-xl text-xs">✍️ ایمیل جدید</a>
      <form method="post" class="inline"><?= mail_csrf_field() ?><input type="hidden" name="act" value="fetch">
        <button class="bg-slate-800 hover:bg-slate-900 text-white font-bold px-4 py-2.5 rounded-xl text-xs">🔄 دریافت ایمیل‌های جدید</button></form>
      <a href="mail_settings.php" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold px-4 py-2.5 rounded-xl text-xs">⚙ تنظیمات ایمیل</a>
      <a href="index.php" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold px-4 py-2.5 rounded-xl text-xs">← داشبورد</a>
    </div>
  </div>

  <?php if (!mail_smtp_ready($conf)): ?>
    <div class="bg-amber-50 border border-amber-200 text-amber-900 rounded-2xl p-4 mb-4 text-xs leading-7">
      ⚠️ ارسال ایمیل هنوز راه‌اندازی نشده است. پیام‌های فرم «تماس با ما» ذخیره می‌شوند، اما برای <b>پاسخ‌دادن</b> و <b>ارسال ایمیل</b> ابتدا
      <a href="mail_settings.php" class="underline font-bold">تنظیمات ایمیل</a> را کامل کنید.
    </div>
  <?php endif; ?>

<?php if ($compose = isset($_GET['compose'])): ?>
  <!-- ───── ایمیل جدید ───── -->
  <form method="post" class="bg-white border border-slate-200 rounded-2xl p-5 space-y-4">
    <?= mail_csrf_field() ?><input type="hidden" name="act" value="send">
    <h2 class="font-black text-slate-800">✍️ ارسال ایمیل جدید</h2>
    <div>
      <label class="block text-xs font-bold text-slate-600 mb-2">به (آدرس ایمیل گیرنده)</label>
      <input type="email" name="to" required dir="ltr" value="<?= sanitize($_GET['to'] ?? '') ?>" class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm">
    </div>
    <div>
      <label class="block text-xs font-bold text-slate-600 mb-2">موضوع</label>
      <input type="text" name="subject" maxlength="255" class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm">
    </div>
    <div>
      <label class="block text-xs font-bold text-slate-600 mb-2">متن پیام</label>
      <textarea name="body" rows="10" required class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm leading-7"></textarea>
    </div>
    <div class="flex gap-2">
      <button class="bg-brand-orange hover:bg-brand-hover text-white font-bold px-6 py-2.5 rounded-xl text-xs">📨 ارسال</button>
      <a href="messages.php" class="bg-slate-100 text-slate-700 font-bold px-5 py-2.5 rounded-xl text-xs">انصراف</a>
    </div>
  </form>

<?php elseif (isset($_GET['id'])): ?>
  <!-- ───── مشاهده‌ی گفتگو ───── -->
  <?php if (!$view): ?>
    <div class="bg-white border border-slate-200 rounded-2xl p-10 text-center text-slate-400 text-sm">پیام پیدا نشد. <a href="messages.php" class="text-blue-600 underline">بازگشت</a></div>
  <?php else:
    $replyTo = $view['direction'] === 'in' ? $view['from_email'] : $view['to_email'];
    $subj = (string)$view['subject'];
    $reSubj = preg_match('/^\s*(re|پاسخ)\s*:/iu', $subj) ? $subj : 'Re: ' . $subj;
  ?>
    <a href="messages.php?tab=<?= $view['is_trashed'] ? 'trash' : ($view['direction'] === 'out' ? 'sent' : 'inbox') ?>" class="inline-block text-xs text-blue-600 mb-3">← بازگشت به لیست</a>
    <h2 class="text-lg font-black text-slate-900 mb-4"><?= sanitize($subj !== '' ? $subj : '(بدون موضوع)') ?></h2>

    <div class="space-y-3 mb-5">
    <?php foreach ($thread as $m):
        $mine = $m['direction'] === 'out'; ?>
      <div class="bg-white border <?= (int)$m['id'] === (int)$view['id'] ? 'border-brand-orange' : 'border-slate-200' ?> rounded-2xl p-4 <?= $mine ? 'mr-8' : 'ml-8' ?>">
        <div class="flex items-center gap-2 flex-wrap text-xs mb-3 pb-3 border-b border-slate-100">
          <b class="text-slate-800"><?= sanitize($mine ? 'شما (' . ($m['from_name'] ?: $m['from_email']) . ')' : ($m['from_name'] ?: $m['from_email'] ?: 'ناشناس')) ?></b>
          <?php if ($m['from_email'] && !$mine): ?><span dir="ltr" class="text-slate-500">&lt;<?= sanitize($m['from_email']) ?>&gt;</span><?php endif; ?>
          <?php if ($mine): ?><span class="text-slate-500">← <span dir="ltr"><?= sanitize($m['to_email']) ?></span></span><?php endif; ?>
          <?php if ($m['phone']): ?><a href="tel:<?= sanitize($m['phone']) ?>" dir="ltr" class="text-emerald-700">📱 <?= sanitize($m['phone']) ?></a><?php endif; ?>
          <span class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-600"><?= $srcLbl[$m['source']] ?? sanitize($m['source']) ?></span>
          <?php if ($m['send_status'] === 'failed'): ?><span class="px-2 py-0.5 rounded-full bg-red-100 text-red-700">ارسال ناموفق</span><?php endif; ?>
          <?php if ((int)$m['attachments'] > 0): ?><span class="px-2 py-0.5 rounded-full bg-amber-100 text-amber-700" title="پیوست‌ها در این برنامه ذخیره نمی‌شوند">📎 <?= fa_digits((int)$m['attachments']) ?> پیوست (نمایش داده نمی‌شود)</span><?php endif; ?>
          <span class="text-slate-400 mr-auto"><?= $when($m['created_at']) ?></span>
        </div>
        <div class="text-sm leading-8 text-slate-800 break-words" dir="auto"><?= nl2br(sanitize(mb_substr((string)$m['body'], 0, 30000))) ?></div>
        <?php if ($m['send_status'] === 'failed'): ?>
          <div class="mt-3 text-xs text-red-700 bg-red-50 border border-red-200 rounded-xl p-3"><?= sanitize($m['send_error']) ?>
            <form method="post" class="inline mr-2"><?= mail_csrf_field() ?><input type="hidden" name="act" value="resend"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
              <button class="underline font-bold">ارسال مجدد</button></form></div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    </div>

    <div class="flex gap-2 flex-wrap mb-5">
      <?php if (!$view['is_trashed']): ?>
        <form method="post"><?= mail_csrf_field() ?><input type="hidden" name="act" value="trash"><input type="hidden" name="id" value="<?= (int)$view['id'] ?>">
          <input type="hidden" name="tab" value="<?= $view['direction'] === 'out' ? 'sent' : 'inbox' ?>">
          <button class="bg-red-50 hover:bg-red-100 text-red-700 border border-red-200 font-bold px-4 py-2 rounded-xl text-xs">🗑 انتقال به سطل زباله</button></form>
        <?php if ($view['direction'] === 'in'): ?>
        <form method="post"><?= mail_csrf_field() ?><input type="hidden" name="act" value="unread"><input type="hidden" name="id" value="<?= (int)$view['id'] ?>">
          <button class="bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 font-bold px-4 py-2 rounded-xl text-xs">● علامت «خوانده‌نشده»</button></form>
        <?php endif; ?>
      <?php else: ?>
        <form method="post"><?= mail_csrf_field() ?><input type="hidden" name="act" value="restore"><input type="hidden" name="id" value="<?= (int)$view['id'] ?>"><input type="hidden" name="tab" value="trash">
          <button class="bg-emerald-50 text-emerald-700 border border-emerald-200 font-bold px-4 py-2 rounded-xl text-xs">♻️ بازیابی</button></form>
        <form method="post" onsubmit="return confirm('این پیام برای همیشه حذف شود؟')"><?= mail_csrf_field() ?><input type="hidden" name="act" value="purge"><input type="hidden" name="id" value="<?= (int)$view['id'] ?>"><input type="hidden" name="tab" value="trash">
          <button class="bg-red-600 text-white font-bold px-4 py-2 rounded-xl text-xs">❌ حذف همیشگی</button></form>
      <?php endif; ?>
    </div>

    <form method="post" class="bg-white border border-slate-200 rounded-2xl p-5 space-y-3">
      <?= mail_csrf_field() ?><input type="hidden" name="act" value="send"><input type="hidden" name="reply_to" value="<?= (int)$view['id'] ?>">
      <h3 class="font-black text-slate-800 text-sm">↩️ پاسخ</h3>
      <?php if (!$replyTo): ?>
        <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-xl p-3">این پیام آدرس ایمیل ندارد.
          <?php if ($view['phone']): ?>می‌توانید با شماره <b dir="ltr"><?= sanitize($view['phone']) ?></b> تماس بگیرید یا اگر ایمیل مشتری را دارید در کادر زیر وارد کنید.<?php endif; ?></p>
      <?php endif; ?>
      <input type="email" name="to" required dir="ltr" placeholder="ایمیل گیرنده" value="<?= sanitize($replyTo) ?>" class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm">
      <input type="text" name="subject" maxlength="255" value="<?= sanitize($reSubj) ?>" class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm">
      <textarea name="body" rows="6" required placeholder="متن پاسخ..." class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm leading-7"></textarea>
      <button class="bg-brand-orange hover:bg-brand-hover text-white font-bold px-6 py-2.5 rounded-xl text-xs">📨 ارسال پاسخ</button>
    </form>
  <?php endif; ?>

<?php else: ?>
  <!-- ───── لیست ───── -->
  <div class="flex gap-2 flex-wrap mb-4 items-center">
    <?php foreach ($tabs as $k => [$label, $n]): ?>
      <a href="?tab=<?= $k ?>" class="px-4 py-2 rounded-xl text-xs font-bold <?= $tab === $k ? 'bg-slate-900 text-white' : 'bg-white border border-slate-200 text-slate-700' ?>">
        <?= $label ?> (<?= fa_digits((int)$n) ?>)<?php if ($k === 'inbox' && $cr['unread'] > 0): ?> <span class="bg-red-500 text-white rounded-full px-2 text-[10px]"><?= fa_digits((int)$cr['unread']) ?> جدید</span><?php endif; ?></a>
    <?php endforeach; ?>
    <form method="get" class="mr-auto flex gap-2">
      <input type="hidden" name="tab" value="<?= sanitize($tab) ?>">
      <input type="search" name="q" value="<?= sanitize($q) ?>" placeholder="جستجو در پیام‌ها..." class="px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs w-52">
      <button class="bg-white border border-slate-200 px-3 py-2 rounded-xl text-xs">🔍</button>
    </form>
    <?php if ($tab === 'trash' && $cr['trash'] > 0): ?>
      <form method="post" onsubmit="return confirm('همه‌ی پیام‌های سطل زباله برای همیشه حذف شوند؟')"><?= mail_csrf_field() ?><input type="hidden" name="act" value="empty_trash"><input type="hidden" name="tab" value="trash">
        <button class="bg-red-600 text-white px-4 py-2 rounded-xl text-xs font-bold">🗑 خالی‌کردن سطل</button></form>
    <?php endif; ?>
  </div>

  <?php if ($tab === 'inbox' && $conf['imap_enabled']): ?>
    <div class="text-[11px] text-slate-400 mb-2">
      <?php if (!empty($lf[0])): ?>آخرین دریافت از سرور ایمیل: <?= $when($lf[0]) ?> —
        <?= ($lf[1] ?? '') === 'ok' ? '✅ موفق' : '<span class="text-red-600">❌ ' . sanitize($lf[2] ?? '') . '</span>' ?>
      <?php else: ?>هنوز ایمیلی از سرور دریافت نشده است.<?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (!$rows): ?>
    <div class="bg-white border border-slate-200 rounded-2xl p-10 text-center text-slate-400 text-sm">
      <?= $q !== '' ? 'نتیجه‌ای پیدا نشد.' : 'پیامی در این بخش وجود ندارد.' ?></div>
  <?php else: ?>
  <div class="bg-white border border-slate-200 rounded-2xl divide-y divide-slate-100 overflow-hidden">
    <?php foreach ($rows as $r):
        $unread = $r['direction'] === 'in' && !$r['is_read'] && !$r['is_trashed'];
        $who = $r['direction'] === 'out' ? 'به: ' . $r['to_email'] : ($r['from_name'] ?: $r['from_email'] ?: 'ناشناس');
        $snip = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$r['body'])), 0, 110); ?>
      <a href="messages.php?id=<?= (int)$r['id'] ?>" class="flex items-center gap-3 px-4 py-3 hover:bg-slate-50 <?= $unread ? 'bg-orange-50/50' : '' ?>">
        <span class="w-2 h-2 rounded-full flex-shrink-0 <?= $unread ? 'bg-brand-orange' : 'bg-transparent' ?>"></span>
        <div class="w-40 flex-shrink-0 truncate text-xs <?= $unread ? 'font-black text-slate-900' : 'text-slate-600' ?>" <?= $r['direction'] === 'out' ? 'dir="auto"' : '' ?>><?= sanitize($who) ?></div>
        <div class="flex-1 min-w-0 text-xs truncate">
          <span class="<?= $unread ? 'font-black text-slate-900' : 'font-bold text-slate-700' ?>"><?= sanitize($r['subject'] !== '' ? $r['subject'] : '(بدون موضوع)') ?></span>
          <span class="text-slate-400"> — <?= sanitize($snip) ?></span>
        </div>
        <?php if ($r['send_status'] === 'failed'): ?><span class="text-[10px] px-2 py-0.5 rounded-full bg-red-100 text-red-700 flex-shrink-0">ناموفق</span><?php endif; ?>
        <?php if ((int)$r['attachments'] > 0): ?><span class="flex-shrink-0 text-xs">📎</span><?php endif; ?>
        <span class="hidden sm:inline text-[10px] px-2 py-0.5 rounded-full bg-slate-100 text-slate-500 flex-shrink-0"><?= $srcLbl[$r['source']] ?? '' ?></span>
        <span class="text-[11px] text-slate-400 flex-shrink-0 w-32 text-left"><?= $when($r['created_at']) ?></span>
      </a>
    <?php endforeach; ?>
  </div>

  <?php if ($pages > 1): ?>
    <div class="flex gap-1 justify-center mt-4 flex-wrap">
      <?php for ($i = 1; $i <= $pages; $i++): ?>
        <a href="?tab=<?= $tab ?>&q=<?= urlencode($q) ?>&page=<?= $i ?>" class="px-3 py-1.5 rounded-lg text-xs <?= $i === $page ? 'bg-slate-900 text-white' : 'bg-white border border-slate-200' ?>"><?= fa_digits($i) ?></a>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>
</div>
<?php require_once '../includes/footer.php'; ?>
