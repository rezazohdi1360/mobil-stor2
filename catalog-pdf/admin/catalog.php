<?php
/**
 * دانلود کاتالوگ PDF محصولات (هر محصول یک صفحه)
 *  - از «مدیریت محصولات»: محصولات تیک‌خورده (POST، فیلد sel[]) یا «انتخاب همه» (all_mode=1 همراه جستجوی فعلی)
 *  - کاتالوگ همه‌ی محصولات: catalog.php?all=1[&q=جستجو]
 *  گزینه‌ها: cat_price (نمایش قیمت)، cat_toc (فهرست)، cat_gallery (تصاویر بیشتر)  — برای GET با price=0 / toc=0 / gallery=0 خاموش می‌شوند.
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/catalog.php';

if (!isAdmin()) redirect('../login.php');

const CAT_MAX_PRODUCTS = 600;
@set_time_limit(300);
if ((int)ini_get('memory_limit') > 0 && (int)ini_get('memory_limit') < 512) @ini_set('memory_limit', '512M');

function cat_message($title, $text) {
    http_response_code(200);
    echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title>'
        . '<body style="font-family:Tahoma,sans-serif;background:#f8fafc;padding:40px"><div style="max-width:560px;margin:auto;background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:28px;line-height:2">'
        . '<h2 style="margin:0 0 8px">' . htmlspecialchars($title) . '</h2><p style="color:#475569">' . $text . '</p>'
        . '<a href="products.php" style="display:inline-block;margin-top:12px;background:#f97316;color:#fff;padding:10px 22px;border-radius:12px;text-decoration:none;font-weight:bold">← بازگشت به مدیریت محصولات</a></div></body></html>';
    exit;
}

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$src = $isPost ? $_POST : $_GET;
$flag = function ($post, $get) use ($isPost) { return $isPost ? !empty($_POST[$post]) : (($_GET[$get] ?? '1') !== '0'); };
$opts = ['price' => $flag('cat_price', 'price'), 'toc' => $flag('cat_toc', 'toc'), 'gallery' => $flag('cat_gallery', 'gallery')];

$allMode = !empty($src['all_mode']) || !empty($src['all']);
$q = sanitize((string)($src['q'] ?? ''));
$sel = "SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON c.id = p.category_id";

if ($allMode) {
    $where = $q !== '' ? " WHERE p.name LIKE ?" : '';
    $par = $q !== '' ? ["%$q%"] : [];
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM products p$where"); $cnt->execute($par);
    $n = (int)$cnt->fetchColumn();
    if ($n === 0) cat_message('محصولی پیدا نشد', 'هیچ محصولی برای ساخت کاتالوگ وجود ندارد.');
    if ($n > CAT_MAX_PRODUCTS) cat_message('تعداد محصولات زیاد است', 'کاتالوگ یکجا حداکثر ' . CAT_MAX_PRODUCTS . ' محصول می‌پذیرد، ولی ' . $n . ' محصول انتخاب شده است. با جستجو یا تیک‌زدن، محصولات را کمتر کنید و دوباره امتحان کنید.');
    $st = $pdo->prepare("$sel$where ORDER BY p.id DESC"); $st->execute($par);
    $products = $st->fetchAll();
} else {
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($src['sel'] ?? [])), fn($v) => $v > 0)));
    if (!$ids) cat_message('محصولی انتخاب نشده', 'ابتدا در جدول مدیریت محصولات، محصولات مورد نظر را تیک بزنید و دکمه‌ی «کاتالوگ PDF» را بزنید.');
    if (count($ids) > CAT_MAX_PRODUCTS) cat_message('تعداد محصولات زیاد است', 'کاتالوگ یکجا حداکثر ' . CAT_MAX_PRODUCTS . ' محصول می‌پذیرد، ولی ' . count($ids) . ' محصول انتخاب شده است.');
    $products = [];
    foreach (array_chunk($ids, 200) as $chunk) {
        $st = $pdo->prepare("$sel WHERE p.id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")");
        $st->execute($chunk);
        $products = array_merge($products, $st->fetchAll());
    }
    usort($products, fn($a, $b) => $b['id'] <=> $a['id']);          // همان ترتیب جدول مدیریت (جدیدترین اول)
}
if (!$products) cat_message('محصولی پیدا نشد', 'محصولات انتخاب‌شده در دیتابیس وجود ندارند.');

// مشخصات فنی همه‌ی محصولات
$specsByPid = [];
foreach (array_chunk(array_column($products, 'id'), 300) as $chunk) {
    $st = $pdo->prepare("SELECT * FROM product_specs WHERE product_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ") ORDER BY product_id, sort_order, id");
    $st->execute($chunk);
    foreach ($st->fetchAll() as $r) $specsByPid[$r['product_id']][] = $r;
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$opts['base_url'] = isset($_SERVER['HTTP_HOST']) ? $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\') : '';

try {
    $pdf = catalog_build_pdf($products, $specsByPid, $opts);
    while (ob_get_level() > 0) ob_end_clean();
    $pdf->Output('catalog-' . date('Ymd-His') . '.pdf', isset($_GET['inline']) ? 'I' : 'D');
} catch (Throwable $e) {
    while (ob_get_level() > 0) ob_end_clean();
    cat_message('ساخت کاتالوگ ناموفق بود', htmlspecialchars($e->getMessage()));
}
