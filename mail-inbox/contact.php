<?php
/**
 * صفحه تماس با ما + اطلاع‌رسانی موجودی
 */
require_once 'config/database.php';
require_once 'includes/mail.php';

$success = '';
$error = '';

// ============================================================
// پردازش فرم
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $honeypot = trim($_POST['website'] ?? '');   // فیلد مخفی ضد ربات
    $productId = (int)($_POST['product_id'] ?? 0);
    $type = $_POST['type'] ?? 'contact';

    if ($honeypot !== '') {
        $success = '✅ پیام شما با موفقیت ارسال شد.';   // ربات: بی‌صدا نادیده گرفته می‌شود
    } elseif (empty($name) || empty($phone)) {
        $error = 'نام و شماره تماس الزامی است.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'ایمیل واردشده معتبر نیست.';
    } elseif (mb_strlen($message) > 5000 || mb_strlen($name) > 150) {
        $error = 'متن پیام یا نام بیش از حد طولانی است.';
    } elseif ($type !== 'notify' && !empty($_SESSION['contact_last']) && time() - $_SESSION['contact_last'] < 20) {
        $error = 'لطفاً چند ثانیه صبر کنید و دوباره پیام بفرستید.';
    } elseif (!preg_match('/^[0-9+\-\s]{8,20}$/', $phone)) {
        $error = 'شماره تماس معتبر نیست.';
    } else {
        try {
            if ($type === 'notify' && $productId > 0) {
                $stmt = $pdo->prepare("
                    INSERT INTO stock_notifications (product_id, phone) 
                    VALUES (?, ?)
                ");
                $stmt->execute([$productId, $phone]);
                $success = '✅ شماره شما ثبت شد. به‌محض موجود شدن، اطلاع می‌دهیم.';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO contact_messages (name, phone, message, product_id) 
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$name, $phone, $message, $productId ?: null]);
                // ذخیره در صندوق پیام پنل مدیریت (+ اطلاع‌رسانی ایمیلی به مدیر)
                try { mail_receive_contact($pdo, $name, $phone, $email, $message, $productId); } catch (Throwable $e) { /* ذخیره‌ی اصلی انجام شده است */ }
                $_SESSION['contact_last'] = time();
                $success = '✅ پیام شما با موفقیت ارسال شد.';
            }
        } catch (Exception $e) {
            $error = 'خطا در ذخیره‌سازی: ' . $e->getMessage();
        }
    }
}

// ============================================================
// اگر از صفحه محصول آمده (اطلاع‌رسانی موجودی)
// ============================================================
$productId = (int)($_GET['product_id'] ?? 0);
$product = null;
if ($productId > 0) {
    $stmt = $pdo->prepare("SELECT id, name, main_image FROM products WHERE id = ? LIMIT 1");
    $stmt->execute([$productId]);
    $product = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تماس با ما | <?= htmlspecialchars(SITE_NAME) ?></title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        * { box-sizing: border-box; }
        body { font-family: tahoma, sans-serif; background: #f8fafc; margin: 0; padding: 0; }
        .container { max-width: 900px; margin: 0 auto; padding: 20px; }

        .breadcrumb { color: #6b7280; font-size: 14px; margin-bottom: 20px; }
        .breadcrumb a { color: #2563eb; text-decoration: none; }

        h1.page-title { font-size: 24px; color: #111; margin: 0 0 25px 0; }

        .layout { display: grid; grid-template-columns: 1fr 1fr; gap: 25px; }

        .card {
            background: #fff;
            padding: 25px;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        }

        .card h2 {
            font-size: 18px;
            color: #111;
            margin: 0 0 18px 0;
            border-bottom: 2px solid #2563eb;
            padding-bottom: 12px;
        }

        .form-group { margin-bottom: 15px; }
        .form-group label {
            display: block;
            margin-bottom: 6px;
            color: #374151;
            font-size: 13px;
            font-weight: bold;
        }
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            font-family: tahoma, sans-serif;
            font-size: 14px;
            transition: border 0.2s;
        }
        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #2563eb;
        }
        .form-group textarea { min-height: 100px; resize: vertical; }

        .btn {
            background: #2563eb;
            color: #fff;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            font-size: 15px;
            font-weight: bold;
            cursor: pointer;
            width: 100%;
            transition: background 0.2s;
        }
        .btn:hover { background: #1d4ed8; }

        .alert {
            padding: 14px;
            border-radius: 10px;
            margin-bottom: 18px;
            font-size: 14px;
            font-weight: bold;
        }
        .alert.success { background: #dcfce7; color: #16a34a; border: 1px solid #86efac; }
        .alert.error   { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }

        .product-preview {
            display: flex;
            gap: 15px;
            padding: 15px;
            background: #f9fafb;
            border-radius: 12px;
            margin-bottom: 20px;
            align-items: center;
        }
        .product-preview img {
            width: 70px;
            height: 70px;
            object-fit: contain;
            background: #fff;
            border-radius: 8px;
            padding: 5px;
        }
        .product-preview .name {
            font-size: 13px;
            color: #111;
            line-height: 1.7;
        }

        .contact-info { padding: 10px 0; }
        .contact-info .item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px dashed #e5e7eb;
            color: #374151;
            font-size: 14px;
        }
        .contact-info .item:last-child { border-bottom: none; }
        .contact-info .icon { font-size: 20px; }

        @media (max-width: 700px) {
            .layout { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<div class="container">

    <div class="breadcrumb">
        <a href="index.php">🏠 خانه</a> › <span>تماس با ما</span>
    </div>

    <h1 class="page-title">
        <?php if ($product && $productId): ?>
            🔔 اطلاع‌رسانی موجود شدن محصول
        <?php else: ?>
            📞 تماس با ما
        <?php endif; ?>
    </h1>

    <div class="layout">

        <!-- فرم -->
        <div class="card">
            <h2><?= $product ? 'ثبت درخواست اطلاع‌رسانی' : 'ارسال پیام' ?></h2>

            <?php if ($success): ?>
                <div class="alert success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($product): ?>
                <div class="product-preview">
                    <img src="<?= htmlspecialchars($product['main_image'] ?: 'icon/placeholder/no-image.png') ?>" 
                         alt=""
                         onerror="this.src='icon/placeholder/no-image.png'">
                    <div class="name">
                        <?= htmlspecialchars(mb_substr($product['name'], 0, 80)) ?>
                    </div>
                </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="type" value="<?= $product ? 'notify' : 'contact' ?>">
                <input type="hidden" name="product_id" value="<?= $productId ?>">

                <div class="form-group">
                    <label>نام و نام خانوادگی *</label>
                    <input type="text" name="name" required 
                           value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                </div>

                <div style="position:absolute;left:-9999px;top:-9999px" aria-hidden="true">
                    <label>وب‌سایت</label><input type="text" name="website" tabindex="-1" autocomplete="off">
                </div>

                <div class="form-group">
                    <label>شماره تماس *</label>
                    <input type="tel" name="phone" required 
                           placeholder="09xxxxxxxxx"
                           value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                </div>

                <?php if (!$product): ?>
                    <div class="form-group">
                        <label>ایمیل (اختیاری — برای پاسخ از طریق ایمیل)</label>
                        <input type="email" name="email" dir="ltr" placeholder="name@example.com"
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>پیام شما</label>
                        <textarea name="message" 
                                  placeholder="متن پیام..."><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>
                    </div>
                <?php endif; ?>

                <button type="submit" class="btn">
                    <?= $product ? '🔔 ثبت درخواست' : '📤 ارسال پیام' ?>
                </button>
            </form>
        </div>

        <!-- اطلاعات تماس -->
        <div class="card">
            <h2>📋 اطلاعات تماس</h2>
            <div class="contact-info">
                <?php if (SITE_PHONE): ?><div class="item"><span class="icon">📱</span><span dir="ltr"><?= htmlspecialchars(SITE_PHONE) ?></span></div><?php endif; ?>
                <?php if (SITE_PHONE2): ?><div class="item"><span class="icon">📞</span><span dir="ltr"><?= htmlspecialchars(SITE_PHONE2) ?></span></div><?php endif; ?>
                <?php if (SITE_OWNER): ?><div class="item"><span class="icon">👤</span><span>مدیر فروشگاه: <?= htmlspecialchars(SITE_OWNER) ?></span></div><?php endif; ?>
                <?php if (SITE_EMAIL): ?><div class="item"><span class="icon">✉️</span><span dir="ltr"><?= htmlspecialchars(SITE_EMAIL) ?></span></div><?php endif; ?>
                <?php if (SITE_ADDRESS): ?><div class="item"><span class="icon">📍</span><span><?= htmlspecialchars(SITE_ADDRESS) ?></span></div><?php endif; ?>
                <?php if (SITE_HOURS): ?><div class="item"><span class="icon">🕐</span><span><?= htmlspecialchars(SITE_HOURS) ?></span></div><?php endif; ?>
                <?php if (SITE_INSTAGRAM): ?><div class="item"><span class="icon">📷</span><a href="<?= htmlspecialchars(SITE_INSTAGRAM) ?>" target="_blank" rel="noopener">اینستاگرام</a></div><?php endif; ?>
                <?php if (SITE_TELEGRAM): ?><div class="item"><span class="icon">✈️</span><a href="<?= htmlspecialchars(SITE_TELEGRAM) ?>" target="_blank" rel="noopener">تلگرام</a></div><?php endif; ?>
            </div>
        </div>

    </div>
</div>

<?php require __DIR__ . '/includes/site_footer.php'; ?>
</body>
</html>