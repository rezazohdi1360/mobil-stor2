<?php
/**
 * کاتالوگ PDF محصولات (فارسی، راست‌به‌چپ) با TCPDF — هر محصول یک صفحه‌ی A4
 * صفحه‌ی اول: جلد، سپس فهرست (اختیاری)، سپس یک صفحه برای هر محصول با عکس، مشخصات کلی و جدول مشخصات فنی.
 * از همان کتابخانه و فونت فاکتور (includes/invoice.php) استفاده می‌کند.
 */
require_once __DIR__ . '/invoice.php';

const CAT_TOC_ROWS = 38;      // تعداد ردیف فهرست در هر صفحه
/*
 * مختصات در حالت راست‌به‌چپ TCPDF (صفحه‌ی A4 = ۲۱۰ میلی‌متر):
 *  - Rect / Line / Link: فاصله از چپ (معمولی)
 *  - writeHTMLCell: x = فاصله‌ی لبه‌ی «راست» کادر از راست صفحه
 *  - Image: x = موقعیت لبه‌ی «راست» عکس از چپ صفحه
 */

/** متن HTML/خام → متن ساده‌ی کوتاه‌شده */
function cat_clean($s, $max) {
    $s = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>'], ' ', (string)$s)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)) . '…' : $s;
}
/** ارقام در متن فارسی به فارسی تبدیل می‌شود؛ متن لاتین (مثل نام مدل) دست‌نخورده می‌ماند */
function cat_txt($s) { $s = (string)$s; return preg_match('/\p{Arabic}/u', $s) ? inv_fa(inv_h($s)) : inv_h($s); }

/** عکس → فایل JPEG کش‌شده با کیفیت مناسب چاپ؛ در نبود عکس null */
function cat_image($src, $box = 700) {
    $root = dirname(__DIR__);
    $src = trim((string)$src);
    if ($src === '') return null;
    $cacheDir = $root . '/uploads/cache/catalog';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
    $out = $cacheDir . '/' . md5($src . '|' . $box) . '.jpg';
    if (is_file($out) && filesize($out) > 200) return $out;

    $data = null;
    if (preg_match('#^https?://#i', $src)) {
        if (!function_exists('curl_init')) return null;
        $ch = curl_init($src);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_USERAGENT => 'Mozilla/5.0', CURLOPT_ENCODING => '']);
        if (is_file($root . '/config/cacert.pem')) curl_setopt($ch, CURLOPT_CAINFO, $root . '/config/cacert.pem');
        $d = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($d !== false && $code === 200) $data = $d;
    } else {
        foreach ([$root . '/' . ltrim($src, '/'), $root . '/' . ltrim(rawurldecode($src), '/')] as $f) {
            if (is_file($f)) { $data = file_get_contents($f); break; }
        }
    }
    if (!$data) return null;
    if (!function_exists('imagecreatefromstring')) {              // بدون GD: فقط JPEG خام
        if (substr($data, 0, 2) === "\xFF\xD8" && strlen($data) < 900000) { file_put_contents($out, $data); return $out; }
        return null;
    }
    $im = @imagecreatefromstring($data);
    if (!$im) return null;
    $w = imagesx($im); $h = imagesy($im); $sc = min($box / max($w, 1), $box / max($h, 1), 1);
    $nw = max(1, (int)round($w * $sc)); $nh = max(1, (int)round($h * $sc));
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagejpeg($dst, $out, 85);
    imagedestroy($im); imagedestroy($dst);
    return is_file($out) ? $out : null;
}

/** نوار بالای صفحه */
function cat_header($pdf, $title, $pageNo, $total) {
    $pdf->SetFillColor(27, 42, 65);
    $pdf->Rect(0, 0, 210, 14, 'F');
    $pdf->SetFillColor(184, 146, 58);
    $pdf->Rect(0, 14, 210, 0.9, 'F');
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('vazirmatnb', '', 10);
    $pdf->writeHTMLCell(130, 8, 10, 3, '<span>' . inv_h($title) . '</span>', 0, 0, false, true, 'R');
    $pdf->SetFont('vazirmatn', '', 8);
    $pdf->writeHTMLCell(55, 8, 145, 4, 'صفحه ' . inv_fa($pageNo) . ' از ' . inv_fa($total), 0, 0, false, true, 'L');
    $pdf->SetTextColor(0, 0, 0);
}
/** پاورقی */
function cat_footer($pdf, $url = '') {
    $pdf->SetAutoPageBreak(false);
    $pdf->SetDrawColor(216, 210, 196);
    $pdf->Line(10, 286, 200, 286);
    $pdf->SetFont('vazirmatn', '', 7.5);
    $pdf->SetTextColor(110, 110, 110);
    $left = $url !== '' ? '<a href="' . inv_h($url) . '" style="color:#1d4ed8">مشاهده در فروشگاه</a>' : '';
    $pdf->writeHTMLCell(120, 6, 10, 288, inv_h(SITE_NAME) . (SITE_PHONE ? ' — تلفن: ' . inv_fa(inv_h(SITE_PHONE)) : ''), 0, 0, false, true, 'R');
    $pdf->writeHTMLCell(65, 6, 135, 288, $left, 0, 0, false, true, 'L');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetAutoPageBreak(true, 16);
}

/** مشخصات فنی → آرایه‌ی تخت [['g', نام گروه] | ['s', کلید, مقدار]] با سقف تعداد ردیف */
function cat_spec_items(array $specs, $budget) {
    $items = []; $lastG = null; $used = 0; $skipped = 0;
    foreach ($specs as $s) {
        $g = trim((string)$s['group_name']); $k = cat_clean($s['spec_key'], 45); $v = cat_clean($s['spec_value'], 110);
        if ($k === '' && $v === '') continue;
        $need = 1 + ($g !== '' && $g !== $lastG ? 1 : 0);
        if ($used + $need > $budget) { $skipped++; continue; }
        if ($g !== '' && $g !== $lastG) { $items[] = ['g', cat_clean($g, 60)]; $lastG = $g; }
        $items[] = ['s', $k, $v]; $used += $need;
    }
    return [$items, $skipped];
}
function cat_spec_col(array $items, $fs) {
    $h = '<table cellpadding="2" cellspacing="0" width="100%" border="0" style="font-size:' . $fs . 'pt">';
    foreach ($items as $it) {
        if ($it[0] === 'g') $h .= '<tr><td colspan="2" style="background-color:#f4ead2;color:#1b2a41"><b>' . cat_txt($it[1]) . '</b></td></tr>';
        else $h .= '<tr><td width="38%" style="color:#555555;border-bottom:0.3px solid #e5e0d3">' . cat_txt($it[1]) . '</td><td width="62%" style="border-bottom:0.3px solid #e5e0d3">' . cat_txt($it[2]) . '</td></tr>';
    }
    return $h . '</table>';
}

/** یک صفحه برای یک محصول */
function cat_product_page($pdf, array $p, array $specs, array $o, array $L, $pageNo, $total, $link) {
    $pdf->AddPage();
    $pdf->SetLink($link, 0, $pageNo);
    cat_header($pdf, SITE_NAME . ' — کاتالوگ محصولات', $pageNo, $total);
    $pdf->SetFont('vazirmatn', '', 9);

    // ── عکس اصلی (سمت راست) ──
    $imgBottom = 24;
    $main = cat_image($p['main_image'] ?? '');
    $pdf->SetDrawColor(216, 210, 196);
    $pdf->Rect(128, 22, 72, 72, 'D');                                // قاب عکس (سمت راست: ۱۲۸ تا ۲۰۰)
    if ($main) $pdf->Image($main, 199, 23, 70, 70, '', '', '', false, 300, '', false, false, 0, 'CM');
    else {
        $pdf->SetTextColor(160, 160, 160); $pdf->SetFont('vazirmatn', '', 9);
        $pdf->writeHTMLCell(72, 6, 10, 55, 'بدون تصویر', 0, 0, false, true, 'C');
        $pdf->SetTextColor(0, 0, 0);
    }
    $imgBottom = 94;
    if (!empty($o['gallery']) && !empty($p['all_images'])) {
        $extra = array_values(array_filter(array_map('trim', explode(',', $p['all_images'])), function ($u) use ($p) { return $u !== '' && $u !== ($p['main_image'] ?? ''); }));
        $x = 128; $n = 0;                                          // x = لبه‌ی چپ هر تصویر کوچک
        foreach ($extra as $u) {
            if ($n >= 3) break;
            $f = cat_image($u, 400);
            if (!$f) continue;
            $pdf->Rect($x, 96, 22.5, 22.5, 'D');
            $pdf->Image($f, $x + 22, 96.5, 21.5, 21.5, '', '', '', false, 300, '', false, false, 0, 'CM');
            $x += 24.75; $n++;
        }
        if ($n) $imgBottom = 118.5;
    }

    // ── عنوان و مشخصات کلی (سمت چپ عکس) ──
    $hasSale = !empty($p['sale_price']) && $p['sale_price'] > 0 && $p['sale_price'] < $p['price'];
    $avail = ($p['stock_status'] ?? '') === 'in_stock' && (int)$p['stock'] > 0 && $p['price'] > 0;
    $rows = [];
    if (!empty($p['brand']))         $rows[] = ['برند', cat_txt($p['brand'])];
    if (!empty($p['category_name'])) $rows[] = ['دسته‌بندی', cat_txt($p['category_name'])];
    if (!empty($o['price'])) {
        if ($p['price'] > 0) {
            if ($hasSale) {
                $rows[] = ['قیمت اصلی', '<span style="color:#888888">' . inv_money($p['price']) . '</span>'];
                $rows[] = ['قیمت ویژه', '<b style="color:#b91c1c">' . inv_money($p['sale_price']) . '</b>'
                    . ($p['discount_percent'] > 0 ? ' <span style="color:#b91c1c">(' . inv_fa((int)$p['discount_percent']) . '٪ تخفیف)</span>' : '')];
            } else $rows[] = ['قیمت', '<b>' . inv_money($p['price']) . '</b>'];
        } else $rows[] = ['قیمت', 'تماس بگیرید'];
    }
    $rows[] = ['موجودی', $avail ? '<span style="color:#2f6b4f">موجود</span>' : '<span style="color:#b91c1c">ناموجود</span>'];
    if (!empty($p['warranty'])) $rows[] = ['گارانتی', cat_txt(cat_clean($p['warranty'], 80))];
    if (!empty($p['seller']))   $rows[] = ['فروشنده', cat_txt(cat_clean($p['seller'], 60))];
    if ($p['rating'] > 0)       $rows[] = ['امتیاز کاربران', inv_fa(number_format((float)$p['rating'], 1)) . ' از ۵' . ($p['review_count'] > 0 ? ' (' . inv_fa((int)$p['review_count']) . ' نظر)' : '')];
    $rows[] = ['کد محصول', inv_fa((int)$p['id'])];

    $h = '<div style="font-size:14pt;color:#1b2a41"><b>' . cat_txt(cat_clean($p['name'], 150)) . '</b></div>';
    if (!empty($p['name_en'])) $h .= '<div style="font-size:8.5pt;color:#777777" dir="ltr" align="left">' . inv_h(cat_clean($p['name_en'], 120)) . '</div>';
    $h .= '<br><table cellpadding="3" cellspacing="0" width="100%" style="font-size:9pt">';
    foreach ($rows as $r) $h .= '<tr><td width="27%" style="color:#555555;border-bottom:0.3px solid #e5e0d3">' . $r[0] . '</td><td width="73%" style="border-bottom:0.3px solid #e5e0d3">' . $r[1] . '</td></tr>';
    $h .= '</table>';
    $pdf->writeHTMLCell(114, 0, 86, 22, $h, 0, 1, false, true, 'R');   // کادر ۱۰ تا ۱۲۴ (سمت چپ عکس)
    $y = max($pdf->GetY(), $imgBottom) + 3;

    // ── توضیح کوتاه / توضیحات ──
    $desc = cat_clean(!empty($p['short_desc']) ? $p['short_desc'] : ($p['description'] ?? ''), $L['desc']);
    if ($L['desc'] > 0 && $desc !== '') {
        $pdf->writeHTMLCell(190, 0, 10, $y, '<div style="font-size:8.5pt;color:#333333;line-height:1.5">' . cat_txt($desc) . '</div>', 0, 1, false, true, 'R');
        $y = $pdf->GetY() + 2;
    }

    // ── مشخصات فنی (دو ستون) ──
    [$items, $skipped] = cat_spec_items($specs, $L['specs']);
    if ($items) {
        $pdf->SetFont('vazirmatnb', '', 10);
        $pdf->SetTextColor(27, 42, 65);
        $pdf->writeHTMLCell(190, 0, 10, $y, '<b>مشخصات فنی</b>', 0, 1, false, true, 'R');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('vazirmatn', '', 8);
        $half = (int)ceil(count($items) / 2);
        $colA = array_slice($items, 0, $half); $colB = array_slice($items, $half);
        $html = '<table cellpadding="0" cellspacing="0" width="100%"><tr><td width="49%">' . cat_spec_col($colA, $L['fs']) . '</td><td width="2%"></td><td width="49%">'
              . ($colB ? cat_spec_col($colB, $L['fs']) : '') . '</td></tr></table>';
        $pdf->writeHTMLCell(190, 0, 10, $pdf->GetY(), $html, 0, 1, false, true, 'R');
        if ($skipped > 0) {
            $pdf->SetFont('vazirmatn', '', 7);
            $pdf->SetTextColor(120, 120, 120);
            $pdf->writeHTMLCell(190, 0, 10, $pdf->GetY() + 1, 'و ' . inv_fa($skipped) . ' مشخصه‌ی دیگر — لیست کامل در صفحه‌ی محصول در فروشگاه', 0, 1, false, true, 'R');
            $pdf->SetTextColor(0, 0, 0);
        }
    }
    cat_footer($pdf, $o['base_url'] !== '' ? rtrim($o['base_url'], '/') . '/product.php?id=' . (int)$p['id'] : '');
}

/**
 * @param array $products  ردیف‌های products (+ category_name)
 * @param array $specsByPid [product_id => [ردیف‌های product_specs]]
 * @param array $o  price, toc, gallery (bool) و base_url
 * @return TCPDF
 */
function catalog_build_pdf(array $products, array $specsByPid, array $o = []) {
    inv_boot();
    $o += ['price' => true, 'toc' => true, 'gallery' => true, 'base_url' => ''];
    $n = count($products);
    $tocPages = $o['toc'] ? (int)ceil($n / CAT_TOC_ROWS) : 0;
    $first = 2 + $tocPages; $total = 1 + $tocPages + $n;

    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->setPrintHeader(false); $pdf->setPrintFooter(false);
    $pdf->SetCreator(SITE_NAME); $pdf->SetAuthor(SITE_NAME); $pdf->SetTitle('کاتالوگ محصولات ' . SITE_NAME);
    $pdf->SetMargins(10, 22, 10); $pdf->SetAutoPageBreak(true, 16);
    $pdf->setRTL(true);
    $pdf->setCellPaddings(0, 0, 0, 0);

    // ── جلد ──
    $pdf->AddPage();
    $pdf->SetFillColor(27, 42, 65); $pdf->Rect(0, 0, 210, 297, 'F');
    $pdf->SetFillColor(184, 146, 58); $pdf->Rect(0, 150, 210, 1.2, 'F');
    $logoF = null;
    if (function_exists('icon_trimmed_url') && ($t = icon_trimmed_url(1))) $logoF = dirname(__DIR__) . '/' . preg_replace('/\?.*$/', '', rawurldecode($t));
    if ($logoF && is_file($logoF)) $pdf->Image($logoF, 130, 45, 50, 0, '', '', '', false, 300);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('vazirmatnb', '', 30);
    $pdf->writeHTMLCell(190, 0, 10, 105, 'کاتالوگ محصولات', 0, 1, false, true, 'C');
    $pdf->SetFont('vazirmatn', '', 16);
    $pdf->SetTextColor(217, 185, 104);
    $pdf->writeHTMLCell(190, 0, 10, 124, inv_h(SITE_NAME), 0, 1, false, true, 'C');
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('vazirmatn', '', 12);
    $pdf->writeHTMLCell(190, 0, 10, 165, 'تعداد محصولات: ' . inv_fa($n) . '<br>تاریخ تهیه: ' . inv_fa(jalali_str(date('Y-m-d H:i:s'), false)), 0, 1, false, true, 'C');
    $pdf->SetFont('vazirmatn', '', 10);
    $pdf->SetTextColor(200, 200, 200);
    $pdf->writeHTMLCell(190, 0, 10, 255, implode('<br>', array_filter([SITE_PHONE ? 'تلفن: ' . inv_fa(inv_h(SITE_PHONE)) : '', SITE_ADDRESS ? inv_h(SITE_ADDRESS) : ''])), 0, 1, false, true, 'C');
    $pdf->SetTextColor(0, 0, 0);

    // ── فهرست ──
    $links = [];
    foreach ($products as $i => $_) $links[$i] = $pdf->AddLink();
    for ($tp = 0; $tp < $tocPages; $tp++) {
        $pdf->AddPage();
        cat_header($pdf, 'فهرست محصولات', 2 + $tp, $total);
        $y = 22;
        for ($r = $tp * CAT_TOC_ROWS; $r < min($n, ($tp + 1) * CAT_TOC_ROWS); $r++) {
            if (($r - $tp * CAT_TOC_ROWS) % 2 === 0) { $pdf->SetFillColor(247, 244, 236); $pdf->Rect(10, $y - 0.6, 190, 6.6, 'F'); }
            $pdf->SetFont('vazirmatn', '', 8.5);
            $pdf->SetTextColor(100, 100, 100);
            $pdf->writeHTMLCell(12, 5.5, 10, $y, inv_fa($r + 1), 0, 0, false, true, 'R');
            $pdf->SetTextColor(20, 20, 20);
            $pdf->writeHTMLCell(150, 5.5, 22, $y, cat_txt(cat_clean($products[$r]['name'], 95)), 0, 0, false, true, 'R', true);
            $pdf->SetTextColor(100, 100, 100);
            $pdf->writeHTMLCell(26, 5.5, 174, $y, 'صفحه ' . inv_fa($first + $r), 0, 0, false, true, 'L');
            $pdf->Link(10, $y - 0.6, 190, 6.6, $links[$r]);
            $y += 6.6;
        }
        $pdf->SetTextColor(0, 0, 0);
        cat_footer($pdf);
    }

    // ── صفحه‌ی هر محصول (با کوچک‌کردن تدریجی برای جاشدن در یک صفحه) ──
    $levels = [
        ['specs' => 72, 'desc' => 700, 'fs' => 7.6],
        ['specs' => 58, 'desc' => 450, 'fs' => 7.2],
        ['specs' => 46, 'desc' => 260, 'fs' => 6.8],
        ['specs' => 32, 'desc' => 120, 'fs' => 6.6],
        ['specs' => 16, 'desc' => 0,   'fs' => 6.4],
    ];
    foreach ($products as $i => $p) {
        $pageNo = $first + $i;
        $specs = $specsByPid[$p['id']] ?? [];
        foreach ($levels as $li => $L) {
            $startPage = $pdf->getPage();
            $pdf->startTransaction();
            cat_product_page($pdf, $p, $specs, $o, $L, $pageNo, $total, $links[$i]);
            if ($pdf->getPage() === $startPage + 1 || $li === count($levels) - 1) { $pdf->commitTransaction(); break; }
            $pdf = $pdf->rollbackTransaction(true);
        }
    }
    return $pdf;
}
