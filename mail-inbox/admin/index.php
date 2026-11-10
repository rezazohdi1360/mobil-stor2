<?php
/**
 * داشبورد مدیریت (طراحی کلاسیک)
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/reviews.php';
require_once '../includes/mail.php';
$pendingReviews = reviews_pending_count($pdo);
$unreadMail = mail_unread_count($pdo);

if (!isAdmin()) redirect('../login.php');

if (!function_exists('ms_fa_digits')) {
    function ms_fa_digits($value) {
        return strtr((string)$value, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
    }
}
if (!function_exists('ms_money')) {
    function ms_money($n) { return ms_fa_digits(number_format((float)$n)); }
}

$stats = [
    'products' => $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn(),
    'orders'   => $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn(),
    'users'    => $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'revenue'  => $pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status='delivered'")->fetchColumn(),
];

// موجودی کالاها (همان شرط نمایش در سایت)
$inStockCount = $pdo->query("
    SELECT COUNT(*) FROM products
    WHERE status = 1 AND stock_status = 'in_stock' AND stock > 0 AND price > 0
")->fetchColumn();
$outStockCount = max(0, (int)$stats['products'] - (int)$inStockCount);

try {
    $mailTotal = (int)$pdo->query("SELECT COUNT(*) FROM mail_messages WHERE direction='in' AND is_trashed=0")->fetchColumn();
    $recentMail = $pdo->query("SELECT id, from_name, from_email, subject, is_read, created_at FROM mail_messages WHERE direction='in' AND is_trashed=0 ORDER BY created_at DESC, id DESC LIMIT 5")->fetchAll();
} catch (Throwable $e) { $mailTotal = 0; $recentMail = []; }

$recentOrders   = $pdo->query("SELECT o.*, u.name AS user_name FROM orders o LEFT JOIN users u ON o.user_id=u.id ORDER BY o.id DESC LIMIT 5")->fetchAll();
$recentProducts = $pdo->query("SELECT * FROM products ORDER BY id DESC LIMIT 5")->fetchAll();

$adminName = $_SESSION['name'] ?? 'مدیر';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>داشبورد مدیریت | <?= htmlspecialchars(SITE_NAME) ?></title>
<link rel="preconnect" href="https://cdn.jsdelivr.net">
<link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet">
<style>
:root{
    --navy:#1b2a41;--navy-deep:#111c2d;--navy-soft:#2a3d5c;
    --gold:#b8923a;--gold-light:#d9b968;--gold-pale:#f4ead2;
    --burgundy:#7a1f2b;--green:#2f6b4f;--cream:#faf7f2;--paper:#fff;
    --ink:#22262e;--muted:#6b7280;--line:#e6dfd0;
    --shadow:0 2px 10px rgba(27,42,65,.07);
    --shadow-hover:0 14px 30px -10px rgba(27,42,65,.28);
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Vazirmatn',tahoma,sans-serif;background:var(--cream);color:var(--ink);line-height:1.7;-webkit-font-smoothing:antialiased}
a{text-decoration:none;color:inherit;transition:color .2s,background .2s,border-color .2s,transform .2s,box-shadow .2s}
img{max-width:100%;display:block}
.container{max-width:1280px;margin:0 auto;padding:0 24px}

/* هدر */
.top-bar{background:var(--navy-deep);color:#cfd6e2;font-size:12.5px;padding:8px 0}
.top-bar .container{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap}
.top-bar b{color:var(--gold-light);font-weight:600}
.site-header{background:var(--paper);border-bottom:1px solid var(--line);position:sticky;top:0;z-index:100;box-shadow:0 2px 12px rgba(27,42,65,.05)}
.header-inner{display:flex;align-items:center;justify-content:space-between;padding:14px 0;gap:24px}
.logo{display:flex;align-items:center;gap:12px;white-space:nowrap}
.logo-mark{width:44px;height:44px;border-radius:50%;background:var(--navy);color:var(--gold-light);display:grid;place-items:center;font-size:21px;border:2px solid var(--gold);box-shadow:0 0 0 3px var(--paper),0 0 0 4px var(--gold-light)}
.logo-text{display:flex;flex-direction:column;line-height:1.25}
.logo-text strong{font-size:20px;font-weight:800;color:var(--navy)}
.logo-text small{font-size:10.5px;color:var(--gold);letter-spacing:.5px;font-weight:600}
.header-actions{display:flex;gap:6px;flex-wrap:wrap}
.header-actions a{color:var(--navy);font-size:13px;font-weight:600;display:flex;align-items:center;gap:6px;padding:9px 14px;border-radius:4px;border:1px solid transparent}
.header-actions a:hover{border-color:var(--gold);color:var(--gold)}
.header-actions .logout{background:var(--navy);color:var(--gold-light);border-color:var(--navy)}
.header-actions .logout:hover{background:var(--gold);color:#fff;border-color:var(--gold)}

.admin-nav{background:var(--navy);overflow-x:auto;scrollbar-width:none}
.admin-nav::-webkit-scrollbar{display:none}
.admin-nav-inner{display:flex;white-space:nowrap}
.admin-nav a{color:#d6dbe6;font-size:13.5px;font-weight:500;padding:13px 20px;border-bottom:3px solid transparent}
.admin-nav a:hover,.admin-nav a.active{color:var(--gold-light);border-bottom-color:var(--gold)}

/* خوشامد */
.welcome{
    margin:30px 0 26px;
    background:radial-gradient(circle at 15% 20%,rgba(217,185,104,.18),transparent 45%),linear-gradient(135deg,var(--navy-deep),var(--navy) 60%,var(--navy-soft));
    color:#fff;border-radius:6px;padding:30px 36px;border:1px solid var(--gold);position:relative;
    box-shadow:0 18px 36px -18px rgba(17,28,45,.55);
    display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap;
}
.welcome::before{content:'';position:absolute;inset:7px;border:1px solid rgba(217,185,104,.4);border-radius:3px;pointer-events:none}
.welcome h1{font-size:24px;font-weight:800;position:relative}
.welcome h1 em{font-style:normal;color:var(--gold-light)}
.welcome p{font-size:13.5px;color:#c3cad8;position:relative;margin-top:2px}
.welcome .date{position:relative;font-size:13px;color:var(--gold-light);border:1px solid rgba(217,185,104,.5);padding:6px 18px;border-radius:3px}

/* آمار */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:18px;margin-bottom:26px}
.stat-card{background:var(--paper);border:1px solid var(--line);border-radius:6px;padding:22px;box-shadow:var(--shadow);position:relative;display:block}
a.stat-card:hover{transform:translateY(-5px);box-shadow:var(--shadow-hover);border-color:var(--gold-light)}
.stat-card::after{content:'';position:absolute;bottom:0;right:0;left:0;height:3px;background:linear-gradient(90deg,var(--gold),var(--gold-light));opacity:.0;transition:opacity .3s}
a.stat-card:hover::after{opacity:1}
.stat-icon{width:46px;height:46px;border-radius:50%;display:grid;place-items:center;font-size:20px;background:var(--gold-pale);border:1px solid var(--gold-light);margin-bottom:12px}
.stat-num{font-size:26px;font-weight:800;color:var(--navy);line-height:1.3}
.stat-num.small{font-size:20px}
.stat-label{font-size:12.5px;color:var(--muted);margin-top:2px}
.stat-sub{font-size:11.5px;margin-top:8px;padding-top:8px;border-top:1px dashed var(--line);color:var(--muted);display:flex;gap:12px}
.stat-sub .ok{color:var(--green);font-weight:700}
.stat-sub .no{color:var(--burgundy);font-weight:700}

/* پنل‌ها */
.panel{background:var(--paper);border:1px solid var(--line);border-radius:6px;padding:24px;box-shadow:var(--shadow)}
.panel-title{font-size:16px;font-weight:800;color:var(--navy);margin-bottom:16px;padding-bottom:10px;border-bottom:2px solid var(--gold);display:inline-block}
.quick-actions{display:flex;flex-wrap:wrap;gap:10px}
.qbtn{padding:11px 20px;border-radius:3px;font-size:13px;font-weight:700;border:1px solid var(--navy);color:var(--navy);background:var(--paper)}
.qbtn:hover{background:var(--navy);color:var(--gold-light)}
.qbtn.primary{background:var(--navy);color:var(--gold-light)}
.qbtn.primary:hover{background:var(--gold);border-color:var(--gold);color:#fff}

.two-col{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-top:24px}
.list-row{display:flex;justify-content:space-between;align-items:center;padding:13px 4px;border-bottom:1px solid var(--line);gap:12px}
.list-row:last-of-type{border-bottom:none}
.order-id{font-size:13px;font-weight:800;color:var(--navy)}
.order-user{font-size:12.5px;color:var(--muted);margin-right:8px}
.order-total{font-size:13px;font-weight:800;color:var(--navy);white-space:nowrap}
.order-total small{font-size:11px;color:var(--muted);font-weight:500}
.p-row{display:flex;align-items:center;gap:14px;padding:12px 4px;border-bottom:1px solid var(--line)}
.p-row:last-of-type{border-bottom:none}
.p-row img{width:46px;height:46px;object-fit:contain;background:#fcfbf8;border:1px solid var(--line);border-radius:4px;padding:4px;flex-shrink:0}
.p-info{flex:1;min-width:0}
.p-name{font-size:13px;font-weight:600;color:var(--navy);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.p-price{font-size:12.5px;font-weight:800;color:var(--gold);margin-top:1px}
.p-price small{color:var(--muted);font-weight:500;font-size:11px}
.view-all{display:block;text-align:center;margin-top:16px;padding:10px;border:1.5px solid var(--navy);border-radius:3px;color:var(--navy);font-weight:700;font-size:13px}
.view-all:hover{background:var(--navy);color:var(--gold-light)}
.empty{text-align:center;color:var(--muted);font-size:13px;padding:36px 0}

/* فوتر */
.site-footer{background:var(--navy-deep);color:#97a2b6;margin-top:60px;border-top:4px solid var(--gold);text-align:center;padding:26px 0;font-size:13px}

@media(max-width:900px){.two-col{grid-template-columns:1fr}}
@media(max-width:768px){
    .container{padding:0 16px}
    .top-bar .container{justify-content:center}
    .header-inner{flex-wrap:wrap}
    .welcome{padding:22px 20px}
    .welcome h1{font-size:20px}
    .stats-grid{grid-template-columns:repeat(2,1fr);gap:12px}
    .stat-card{padding:16px}
    .stat-num{font-size:22px}
}
@media(max-width:400px){.stats-grid{grid-template-columns:1fr}}
</style>
</head>
<body>

<div class="top-bar">
<div class="container">
    <span>🔐 پنل مدیریت &nbsp;|&nbsp; <b><?= htmlspecialchars(SITE_NAME) ?></b></span>
    <span>📅 <?= ms_fa_digits(date('Y/m/d')) ?></span>
</div>
</div>

<header class="site-header">
<div class="container">
<div class="header-inner">
    <a href="index.php" class="logo">
        <span class="logo-mark">📱</span>
        <span class="logo-text"><strong><?= htmlspecialchars(SITE_NAME) ?></strong><small>ADMIN PANEL</small></span>
    </a>
    <div class="header-actions">
        <a href="../index.php" target="_blank">🌐 مشاهده سایت</a>
        <a href="../logout.php" class="logout">🚪 خروج</a>
    </div>
</div>
</div>

<nav class="admin-nav">
<div class="container">
<div class="admin-nav-inner">
    <a href="index.php" class="active">📊 داشبورد</a>
    <a href="products.php">📦 محصولات</a>
    <a href="sample_products.php">🧪 محصولات نمونه</a>
    <a href="featured.php">⭐ محصولات منتخب</a>
    <a href="price_update.php">💰 به‌روزرسانی قیمت</a>
    <a href="messages.php">📧 پیام‌ها<?php if (!empty($unreadMail)): ?> <span style="background:#ef4444;color:#fff;border-radius:99px;padding:0 7px;font-size:11px"><?= (int)$unreadMail ?></span><?php endif; ?></a>
    <a href="reviews.php">💬 نظرات<?php if (!empty($pendingReviews)): ?> <span style="background:#ef4444;color:#fff;border-radius:99px;padding:0 7px;font-size:11px"><?= (int)$pendingReviews ?></span><?php endif; ?></a>
    <a href="coupons.php">🎟 کد تخفیف</a>
    <a href="orders.php">🛒 سفارشات</a>
    <a href="categories.php">📁 دسته‌بندی‌ها</a>
    <a href="users.php">👥 کاربران</a>
    <a href="shipping.php">🚚 تنظیمات ارسال</a>
    <a href="settings.php">⚙ اطلاعات تماس</a>
    <a href="telegram.php">✈️ تلگرام</a>
</div>
</div>
</nav>
</header>

<div class="container">

    <section class="welcome">
        <div>
            <h1>خوش آمدید، <em><?= sanitize($adminName) ?></em></h1>
            <p>نمای کلی فروشگاه و دسترسی سریع به بخش‌های مدیریت</p>
        </div>
        <div class="date"><?= ms_fa_digits(date('Y/m/d')) ?></div>
    </section>

    <!-- آمار -->
    <section class="stats-grid">
        <a href="products.php" class="stat-card">
            <div class="stat-icon">📦</div>
            <div class="stat-num"><?= ms_money($stats['products']) ?></div>
            <div class="stat-label">محصول</div>
            <div class="stat-sub">
                <span class="ok">موجود: <?= ms_money($inStockCount) ?></span>
                <span class="no">ناموجود: <?= ms_money($outStockCount) ?></span>
            </div>
        </a>
        <a href="orders.php" class="stat-card">
            <div class="stat-icon">🛒</div>
            <div class="stat-num"><?= ms_money($stats['orders']) ?></div>
            <div class="stat-label">سفارش</div>
        </a>
        <a href="users.php" class="stat-card">
            <div class="stat-icon">👥</div>
            <div class="stat-num"><?= ms_money($stats['users']) ?></div>
            <div class="stat-label">کاربر</div>
        </a>
        <a href="messages.php" class="stat-card">
            <div class="stat-icon">📧</div>
            <div class="stat-num"><?= ms_money($mailTotal) ?></div>
            <div class="stat-label">پیام دریافتی</div>
            <div class="stat-sub">
                <span class="<?= $unreadMail ? 'no' : 'ok' ?>">خوانده‌نشده: <?= ms_money($unreadMail) ?></span>
            </div>
        </a>
        <div class="stat-card">
            <div class="stat-icon">💰</div>
            <div class="stat-num small"><?= ms_money($stats['revenue']) ?></div>
            <div class="stat-label">درآمد (تومان)</div>
        </div>
    </section>

    <!-- دسترسی سریع -->
    <section class="panel">
        <h2 class="panel-title">دسترسی سریع</h2>
        <div class="quick-actions">
            <a href="products.php" class="qbtn">📦 مدیریت محصولات</a>
            <a href="product_add.php" class="qbtn primary">➕ افزودن محصول</a>
            <a href="sample_products.php" class="qbtn">🧪 محصولات نمونه</a>
            <a href="featured.php" class="qbtn">⭐ محصولات منتخب</a>
            <a href="price_update.php" class="qbtn primary">💰 به‌روزرسانی قیمت از دی‌جی‌کالا</a>
            <a href="messages.php" class="qbtn<?= !empty($unreadMail) ? ' primary' : '' ?>">📧 پیام‌ها و ایمیل<?= !empty($unreadMail) ? ' (' . (int)$unreadMail . ' جدید)' : '' ?></a>
            <a href="mail_settings.php" class="qbtn">⚙ تنظیمات ایمیل</a>
            <a href="reviews.php" class="qbtn<?= !empty($pendingReviews) ? ' primary' : '' ?>">💬 نظرات کاربران<?= !empty($pendingReviews) ? ' (' . (int)$pendingReviews . ' جدید)' : '' ?></a>
            <a href="coupons.php" class="qbtn">🎟 کد تخفیف</a>
            <a href="orders.php" class="qbtn">🛒 سفارشات</a>
            <a href="categories.php" class="qbtn">📁 دسته‌بندی‌ها</a>
            <a href="users.php" class="qbtn">👥 کاربران</a>
            <a href="shipping.php" class="qbtn">🚚 تنظیمات ارسال</a>
            <a href="settings.php" class="qbtn">⚙ اطلاعات تماس</a>
            <a href="telegram.php" class="qbtn">✈️ تلگرام</a>
        </div>
    </section>

    <div class="two-col">

        <!-- آخرین سفارشات -->
        <section class="panel">
            <h3 class="panel-title">🛒 آخرین سفارشات</h3>
            <?php if (empty($recentOrders)): ?>
                <p class="empty">هنوز سفارشی ثبت نشده است</p>
            <?php else: ?>
                <?php foreach ($recentOrders as $o): ?>
                <div class="list-row">
                    <div>
                        <span class="order-id">#<?= ms_fa_digits($o['id']) ?></span>
                        <span class="order-user"><?= sanitize($o['user_name'] ?? $o['customer_name'] ?? 'مهمان') ?></span>
                    </div>
                    <span class="order-total"><?= ms_money($o['total']) ?> <small>تومان</small></span>
                </div>
                <?php endforeach; ?>
                <a href="orders.php" class="view-all">مشاهده همه سفارشات</a>
            <?php endif; ?>
        </section>

        <!-- آخرین محصولات -->
        <section class="panel">
            <h3 class="panel-title">📦 آخرین محصولات</h3>
            <?php if (empty($recentProducts)): ?>
                <p class="empty">هنوز محصولی اضافه نشده است</p>
            <?php else: ?>
                <?php foreach ($recentProducts as $p):
                    // 🖼 اصلاح مسیر عکس برای پنل ادمین
                    $imgSrc = $p['main_image'] ?? '';
                    if (empty($imgSrc)) {
                        $imgSrc = 'https://placehold.co/100x100/f1f5f9/1e293b?text=No+Img';
                    } elseif (strpos($imgSrc, 'http') !== 0) {
                        $imgSrc = '../' . $imgSrc;
                    }
                ?>
                <div class="p-row">
                    <img src="<?= sanitize($imgSrc) ?>" alt=""
                         onerror="this.onerror=null;this.src='https://placehold.co/100x100/f1f5f9/1e293b?text=P'">
                    <div class="p-info">
                        <div class="p-name"><?= sanitize($p['name']) ?></div>
                        <div class="p-price"><?= ms_money($p['sale_price'] ?: $p['price']) ?> <small>تومان</small></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <a href="products.php" class="view-all">مشاهده همه محصولات</a>
            <?php endif; ?>
        </section>

    </div>

    <!-- آخرین پیام‌ها -->
    <section class="panel" style="margin-top:24px">
        <h3 class="panel-title">📧 آخرین پیام‌های دریافتی</h3>
        <?php if (empty($recentMail)): ?>
            <p class="empty">هنوز پیامی دریافت نشده است</p>
        <?php else: ?>
            <?php foreach ($recentMail as $m): ?>
            <a href="messages.php?id=<?= (int)$m['id'] ?>" class="list-row" style="<?= $m['is_read'] ? '' : 'font-weight:800' ?>">
                <div style="min-width:0;flex:1">
                    <span class="order-id"><?= $m['is_read'] ? '' : '● ' ?><?= sanitize($m['from_name'] ?: $m['from_email'] ?: 'ناشناس') ?></span>
                    <span class="order-user"><?= sanitize(mb_substr((string)$m['subject'], 0, 70)) ?></span>
                </div>
                <span class="order-total"><small><?= ms_fa_digits(date('Y/m/d H:i', strtotime($m['created_at']))) ?></small></span>
            </a>
            <?php endforeach; ?>
            <a href="messages.php" class="view-all">مشاهده همه پیام‌ها</a>
        <?php endif; ?>
    </section>
</div>

<footer class="site-footer">
    © <?= ms_fa_digits(date('Y')) ?> <?= htmlspecialchars(SITE_NAME) ?> — پنل مدیریت
</footer>

</body>
</html>
