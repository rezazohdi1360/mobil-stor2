<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isAdmin()) redirect('../login.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete'])) {
    if (!empty($_POST['all_mode'])) {            // «انتخاب همه»: همه‌ی محصولات (مطابق جستجوی فعلی)
        $q = sanitize((string)($_POST['q'] ?? ''));
        if ($q !== '') { $st = $pdo->prepare("DELETE FROM products WHERE name LIKE ?"); $st->execute(["%$q%"]); $n = $st->rowCount(); }
        else { $n = $pdo->exec("DELETE FROM products"); }
        setFlash('success', $n . ' محصول حذف شد');
        redirect('products.php');
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['sel'] ?? [])), fn($v) => $v > 0)));
    if ($ids) {
        foreach (array_chunk($ids, 200) as $chunk) {
            $pdo->prepare("DELETE FROM products WHERE id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")")->execute($chunk);
        }
        setFlash('success', count($ids) . ' محصول حذف شد');
    } else {
        setFlash('error', 'هیچ محصولی انتخاب نشده است');
    }
    redirect('products.php');
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM products WHERE id=?")->execute([$id]);
    setFlash('success', 'محصول حذف شد');
    redirect('products.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_stock'])) {
    $pdo->prepare("UPDATE products SET stock=? WHERE id=?")->execute([(int)$_POST['stock'], (int)$_POST['product_id']]);
    setFlash('success', 'موجودی بروزرسانی شد');
    redirect('products.php');
}

// 🏷 تخفیف برای هر کالا (درصد). صفر = حذف تخفیف
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_discount'])) {
    $pid = (int)$_POST['product_id'];
    $pct = max(0, min(90, (int)faToEn($_POST['discount'] ?? '0')));
    if ($pct > 0) {
        $pdo->prepare("UPDATE products SET sale_price = ROUND(price * (100 - ?) / 100, -3), discount_percent = ? WHERE id = ? AND price > 0")
            ->execute([$pct, $pct, $pid]);
        setFlash('success', "تخفیف {$pct}٪ روی کالا اعمال شد");
    } else {
        $pdo->prepare("UPDATE products SET sale_price = NULL, discount_percent = 0 WHERE id = ?")->execute([$pid]);
        setFlash('success', 'تخفیف کالا حذف شد');
    }
    redirect('products.php' . (isset($_GET['q']) ? '?q=' . urlencode($_GET['q']) : ''));
}

if (isset($_GET['toggle_status'])) {
    $id = (int)$_GET['toggle_status'];
    $pdo->prepare("UPDATE products SET status = 1 - status WHERE id=?")->execute([$id]);
    setFlash('success', 'وضعیت تغییر کرد');
    redirect('products.php');
}

$search = sanitize($_GET['q'] ?? '');
$where = "WHERE 1=1";
$params = [];
if ($search) {
    $where .= " AND name LIKE ?";
    $params[] = "%$search%";
}

$products = $pdo->prepare("SELECT * FROM products $where ORDER BY id DESC LIMIT 300");
$products->execute($params);
$products = $products->fetchAll();
$cs = $pdo->prepare("SELECT COUNT(*) FROM products $where"); $cs->execute($params);
$totalMatch = (int)$cs->fetchColumn();

$pageTitle = 'مدیریت محصولات';
require_once '../includes/header.php';
?>

<style>
    /* افکت بزرگ‌نمایی عکس در جدول */
    .product-thumb {
        display: inline-block;
        transition: all 0.25s ease;
        cursor: pointer;
        position: relative;
    }
    .product-thumb:hover {
        transform: scale(1.15);
        z-index: 10;
    }
    .product-thumb:hover img {
        border-color: #f97316 !important;
        box-shadow: 0 8px 20px rgba(249, 115, 22, 0.3);
    }
    .product-thumb img {
        transition: all 0.25s ease;
    }
    /* Tooltip ساده */
    .product-thumb::after {
        content: '🔍 مشاهده';
        position: absolute;
        bottom: -22px;
        left: 50%;
        transform: translateX(-50%);
        background: #1e293b;
        color: #fff;
        font-size: 9px;
        padding: 2px 6px;
        border-radius: 4px;
        white-space: nowrap;
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.2s;
    }
    .product-thumb:hover::after {
        opacity: 1;
    }
</style>

<div class="max-w-7xl mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-6 flex-wrap gap-3">
        <div>
            <h1 class="text-2xl font-black text-slate-900 flex items-center gap-2">
                <span class="w-2 h-7 bg-brand-orange rounded-full"></span>
                📦 مدیریت محصولات
            </h1>
            <p class="text-xs text-slate-500 mt-1"><?= count($products) ?> محصول</p>
        </div>
        <div class="flex gap-2">
            <a href="index.php" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold px-4 py-2.5 rounded-xl text-xs">← داشبورد</a>
            <a href="../import.php?mode=all" onclick="return confirm('محصولات پوشه MP (فایل JSON و عکس‌های هر محصول) در ریشه‌ی برنامه خوانده شود؟\nمحصولات جدید اضافه و محصولات موجود به‌روزرسانی می‌شوند.')"
               class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-4 py-2.5 rounded-xl text-xs" title="وارد کردن محصولات جدید و به‌روزرسانی موجودها از ریشه‌ی برنامه">🔄 به‌روزرسانی و ورود محصولات</a>
            <a href="product_add.php" class="bg-brand-orange hover:bg-brand-hover text-white font-bold px-4 py-2.5 rounded-xl text-xs">➕ افزودن محصول</a>
        </div>
    </div>

    <form method="get" class="bg-white border border-slate-200 rounded-2xl p-4 mb-5 flex gap-2">
        <input type="text" name="q" value="<?= sanitize($search) ?>" placeholder="جستجوی محصول..."
               class="flex-1 px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-brand-orange">
        <button class="bg-brand-orange hover:bg-brand-hover text-white font-bold px-6 py-2.5 rounded-xl text-xs">🔍 جستجو</button>
    </form>

    <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
        <!-- ☑ عملیات روی محصولات انتخاب‌شده (باکس انتخاب در ستون عملیات) -->
    <form id="bulkForm" method="post" class="bg-white border border-slate-200 rounded-2xl p-3 mb-3 flex items-center gap-3 flex-wrap text-xs">
        <input type="hidden" name="all_mode" id="allMode" value="0"><input type="hidden" name="q" value="<?= $search ?>">
        <span class="font-black text-slate-800">☑ انتخاب‌شده: <span id="bulkCount">۰</span><span id="bulkAllNote" class="text-green-700 font-bold"></span></span>
        <button type="button" id="bulkEditBtn" class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-4 py-2 rounded-xl">✏️ ویرایش جزئیات انتخاب‌شده‌ها</button>
        <button type="submit" name="bulk_delete" value="1" id="bulkDelBtn" disabled onclick="return confirm('محصولات انتخاب‌شده حذف شوند؟ این کار قابل بازگشت نیست.')"
                class="bg-red-600 hover:bg-red-700 text-white font-bold px-4 py-2 rounded-xl disabled:opacity-40 disabled:cursor-not-allowed">🗑 حذف انتخاب‌شده‌ها</button>
        <span class="text-slate-400">با تیک ستون «انتخاب» (کنار عملیات) محصولات را انتخاب کنید.</span>
        <span class="w-full h-0"></span>
        <!-- 📄 کاتالوگ PDF: هر محصول یک صفحه با عکس، قیمت و مشخصات فنی -->
        <span class="font-black text-slate-800">📄 کاتالوگ PDF:</span>
        <label class="flex items-center gap-1 cursor-pointer"><input type="checkbox" name="cat_price" value="1" checked> قیمت‌ها</label>
        <label class="flex items-center gap-1 cursor-pointer"><input type="checkbox" name="cat_toc" value="1" checked> فهرست</label>
        <label class="flex items-center gap-1 cursor-pointer"><input type="checkbox" name="cat_gallery" value="1" checked> عکس‌های بیشتر</label>
        <button type="submit" formaction="catalog.php" id="catBtn" class="bg-slate-800 hover:bg-slate-900 text-white font-bold px-4 py-2 rounded-xl"
                onclick="if(document.getElementById('allMode').value!=='1'&&!document.querySelectorAll('.sel-cb:checked').length){alert('ابتدا محصولات مورد نظر را (ستون «انتخاب») تیک بزنید؛ یا از دکمه‌ی «کاتالوگ همه‌ی محصولات» استفاده کنید.');return false;}return true;">📄 کاتالوگ انتخاب‌شده‌ها</button>
        <a href="catalog.php?all=1&q=<?= urlencode($_GET['q'] ?? '') ?>" onclick="return confirm('کاتالوگ PDF همه‌ی محصولات<?= $search !== '' ? ' (مطابق جستجوی فعلی)' : '' ?> ساخته شود؟ برای محصولات زیاد ممکن است چند دقیقه طول بکشد.')"
           class="bg-slate-100 hover:bg-slate-200 text-slate-800 font-bold px-4 py-2 rounded-xl">📚 کاتالوگ همه‌ی محصولات<?= $search !== '' ? ' (جستجوی فعلی)' : '' ?></a>
    </form>
    <div id="editModal" style="display:none;position:fixed;inset:0;z-index:1000;background:rgba(15,23,42,.55);overflow-y:auto;padding:16px">
        <div style="max-width:760px;margin:32px auto;background:#fff;border-radius:16px;box-shadow:0 25px 60px rgba(0,0,0,.4);position:relative">
            <button type="button" id="editClose" title="بستن" style="position:absolute;top:10px;left:12px;font-size:20px;line-height:1;color:#64748b;background:none;border:0;cursor:pointer">✕</button>
            <div id="editPanel" style="padding:44px 16px 16px"></div>
        </div>
    </div>
    <script>
    (function(){
        function fa(n){return String(n).replace(/\d/g,function(d){return '۰۱۲۳۴۵۶۷۸۹'[d]});}
        var TOTAL=<?= (int)$totalMatch ?>, QS=<?= json_encode($search, JSON_UNESCAPED_UNICODE) ?>;
        var all=document.getElementById('selAll'), cnt=document.getElementById('bulkCount'), del=document.getElementById('bulkDelBtn'),
            eb=document.getElementById('bulkEditBtn'), ep=document.getElementById('editPanel'), modal=document.getElementById('editModal'), am=document.getElementById('allMode'),
            note=document.getElementById('bulkAllNote');
        var prevSel=0;
        function boxes(){return document.querySelectorAll('.sel-cb')}
        function upd(){
            var n=document.querySelectorAll('.sel-cb:checked').length, allMode=am.value==='1'; prevSel=allMode?TOTAL:n;
            cnt.textContent=fa(allMode?TOTAL:n); del.disabled=!(allMode?TOTAL:n);
            note.textContent=allMode?' (همه‌ی محصولات'+(QS?' مطابق جستجو':'')+')':'';
        }
        // دکمه «انتخاب همه» بعد از اسکریپت در صفحه می‌آید؛ پس با delegation به کل سند وصل می‌شود
        document.addEventListener('change',function(e){
            if(e.target.id!=='selAll') return;
            all=e.target;
            boxes().forEach(function(c){c.checked=all.checked});
            am.value=all.checked?'1':'0'; upd(); if(all.checked) openEdit();
        });
        document.addEventListener('change',function(e){
            if(e.target.classList&&e.target.classList.contains('sel-cb')){
                if(am.value==='1'&&!e.target.checked){am.value='0'; var sa=document.getElementById('selAll'); if(sa)sa.checked=false;}   // برداشتن تیک یک محصول = انتخاب دستی
                var before=prevSel; upd();
                if(e.target.checked && before===0) openEdit();   // اولین انتخاب → باکس مشخصات
            }
        });
        function openEdit(){
            var ids=[];document.querySelectorAll('.sel-cb:checked').forEach(function(c){ids.push(c.value)});
            var allMode=am.value==='1';
            modal.style.display='block';
            if(!ids.length&&!allMode){ep.innerHTML='<div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-xl p-3 text-xs">ابتدا با باکس انتخاب (ستون عملیات) محصولات را انتخاب کنید.</div>';return;}
            ep.textContent='در حال بارگذاری...';
            var url=allMode?'products_import.php?editpanel=1&all=1&q='+encodeURIComponent(QS):'products_import.php?editpanel=1&ids='+ids.join(',');
            fetch(url,{credentials:'same-origin'}).then(function(r){
                if(!r.ok) throw new Error('HTTP '+r.status); return r.text();
            }).then(function(h){ep.innerHTML=h;})
              .catch(function(err){ep.textContent='خطا در دریافت اطلاعات: '+err.message;});
        }
        eb.addEventListener('click',openEdit);
        document.getElementById('editClose').addEventListener('click',function(){modal.style.display='none'});
        modal.addEventListener('click',function(e){if(e.target===modal)modal.style.display='none'});
        document.addEventListener('keydown',function(e){if(e.key==='Escape')modal.style.display='none'});
        upd();
    })();
    </script>

    <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 border-b border-slate-200">
                    <tr class="text-xs text-slate-500">
                        <th class="px-4 py-3 text-right font-bold">#</th>
                        <th class="px-4 py-3 text-right font-bold">تصویر</th>
                        <th class="px-4 py-3 text-right font-bold">نام</th>
                        <th class="px-4 py-3 text-right font-bold">قیمت</th>
                        <th class="px-4 py-3 text-right font-bold">موجودی</th>
                        <th class="px-4 py-3 text-right font-bold">تخفیف (٪)</th>
                        <th class="px-4 py-3 text-right font-bold">نمایش در سایت</th>
                        <th class="px-4 py-3 text-right font-bold">عملیات</th>
                        <th class="px-4 py-3 text-center font-bold whitespace-nowrap"><label class="cursor-pointer">انتخاب <input type="checkbox" id="selAll" class="align-middle"></label></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($products)): ?>
                        <tr>
                            <td colspan="9" class="px-4 py-12 text-center text-slate-400 text-xs">
                                محصولی یافت نشد
                            </td>
                        </tr>
                    <?php else: foreach ($products as $p): 
                        // 🖼 اصلاح مسیر عکس برای پنل ادمین
                        $imgSrc = $p['main_image'] ?? '';
                        if (empty($imgSrc)) {
                            $imgSrc = 'https://placehold.co/100x100/f1f5f9/1e293b?text=No+Img';
                        } elseif (strpos($imgSrc, 'http') !== 0) {
                            $imgSrc = '../' . $imgSrc;
                        }
                        $rowAvailable = ($p['stock_status'] === 'in_stock' && $p['stock'] > 0 && $p['price'] > 0);
                    ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 text-xs font-bold"><?= $p['id'] ?></td>
                            
                            <!-- 🆕 عکس قابل کلیک -->
                            <td class="px-4 py-3">
                                <a href="../product.php?id=<?= $p['id'] ?>" 
                                   target="_blank" 
                                   class="product-thumb"
                                   title="مشاهده صفحه محصول">
                                    <img src="<?= sanitize($imgSrc) ?>" 
                                         class="w-12 h-12 rounded-lg object-contain bg-slate-50 p-1 border border-slate-100" 
                                         onerror="this.src='https://placehold.co/100x100/f1f5f9/1e293b?text=P'">
                                </a>
                            </td>
                            
                            <td class="px-4 py-3">
                                <div class="text-xs font-bold text-slate-800 line-clamp-2 max-w-xs"><?= sanitize(mb_substr($p['name'], 0, 60)) ?></div>
                                <?php if ($p['brand']): ?>
                                    <div class="text-[10px] text-slate-400 mt-0.5"><?= sanitize($p['brand']) ?></div>
                                <?php endif; ?>
                            </td>
                            
                            <td class="px-4 py-3 text-xs">
                                <?php if ($p['sale_price'] && $p['sale_price'] < $p['price']): ?>
                                    <div class="line-through text-slate-400 text-[10px]"><?= number_format($p['price']) ?></div>
                                    <div class="font-bold text-brand-orange"><?= number_format($p['sale_price']) ?></div>
                                <?php else: ?>
                                    <div class="font-bold"><?= number_format($p['price']) ?></div>
                                <?php endif; ?>
                            </td>
                            
                            <td class="px-4 py-3">
                                <form method="post" class="flex items-center gap-1">
                                    <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                                    <input type="number" name="stock" value="<?= $p['stock'] ?>" min="0" class="w-16 px-2 py-1 text-xs border border-slate-200 rounded-lg text-center">
                                    <button name="update_stock" class="text-xs bg-emerald-500 hover:bg-emerald-600 text-white px-2 py-1 rounded-lg">✓</button>
                                </form>
                                <!-- 🆕 وضعیت موجودی در سایت -->
                                <?php if ($rowAvailable): ?>
                                    <div class="text-[10px] text-emerald-600 font-bold mt-1">● موجود در سایت</div>
                                <?php else: ?>
                                    <div class="text-[10px] text-red-500 font-bold mt-1">● ناموجود در سایت</div>
                                <?php endif; ?>
                            </td>
                            
                            <td class="px-4 py-3">
                                <form method="post" class="flex items-center gap-1">
                                    <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                                    <input type="number" name="discount" min="0" max="90" value="<?= (int)$p['discount_percent'] ?>" class="w-16 px-2 py-1 text-xs border border-slate-200 rounded-lg text-center" title="درصد تخفیف (۰ = بدون تخفیف)">
                                    <button name="set_discount" value="1" class="text-xs bg-brand-orange hover:bg-brand-hover text-white px-2 py-1 rounded-lg">🏷</button>
                                </form>
                            </td>

                            <td class="px-4 py-3">
                                <?php if ($p['status']): ?>
                                    <a href="?toggle_status=<?= $p['id'] ?>" class="text-[10px] bg-emerald-100 hover:bg-emerald-200 text-emerald-700 px-3 py-1.5 rounded-full font-bold whitespace-nowrap" title="برای مخفی کردن کلیک کنید">👁 نمایش داده می‌شود</a>
                                <?php else: ?>
                                    <a href="?toggle_status=<?= $p['id'] ?>" class="text-[10px] bg-slate-200 hover:bg-slate-300 text-slate-600 px-3 py-1.5 rounded-full font-bold whitespace-nowrap" title="برای نمایش کلیک کنید">🙈 مخفی است</a>
                                <?php endif; ?>
                            </td>
                            
                            <td class="px-4 py-3">
                                <div class="flex gap-1 flex-wrap">
                                    <a href="product_edit.php?id=<?= $p['id'] ?>" 
                                       class="text-[10px] bg-blue-100 hover:bg-blue-200 text-blue-700 px-2 py-1 rounded-lg font-bold"
                                       title="ویرایش محصول">
                                        ✏ ویرایش
                                    </a>
                                    <a href="../product.php?id=<?= $p['id'] ?>" 
                                       target="_blank" 
                                       class="text-[10px] bg-purple-100 hover:bg-purple-200 text-purple-700 px-2 py-1 rounded-lg font-bold"
                                       title="مشاهده در سایت">
                                        👁 مشاهده
                                    </a>
                                    <a href="?delete=<?= $p['id'] ?>" 
                                       onclick="return confirm('حذف شود؟')" 
                                       class="text-[10px] bg-red-100 hover:bg-red-200 text-red-700 px-2 py-1 rounded-lg font-bold"
                                       title="حذف محصول">
                                        🗑
                                    </a>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-center"><input type="checkbox" class="sel-cb" name="sel[]" value="<?= (int)$p['id'] ?>" form="bulkForm"></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
