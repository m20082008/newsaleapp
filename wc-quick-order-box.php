<?php
/**
 * Plugin Name: WC Quick Order Box
 * Description: فرم سفارش سریع + لیست سفارش‌های فروشنده + ویرایش سفارش‌های on-hold (AJAX) با کنترل موجودی و سازگاری HPOS. (بدون SMS)
 * Author: Sepand & Narges
 * Version: 2.1.0
 */

if ( ! defined('ABSPATH') ) exit;
if ( ! defined('QOF_VERSION') ) define('QOF_VERSION', '2.1.0');

/* =========================================================
 * PART 1/5
 * - HPOS + Emails defer
 * - Helpers (digits, sellers, labels, capacity)
 * - Cache + Enqueue assets (SelectWoo/Select2)
 * - Cached product dataset
 * - Shortcode [quick_order_box] (شروع رندر + دیتا)
 * ========================================================= */

/*======================================
=  HPOS Compatibility
======================================*/
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
});

/*======================================
=  Defer transactional emails
======================================*/
add_filter('woocommerce_defer_transactional_emails', '__return_true');

/*======================================
=  Helpers
======================================*/
function qof_normalize_digits($s){
    $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    $ar = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    $en = ['0','1','2','3','4','5','6','7','8','9'];
    return str_replace($ar, $en, str_replace($fa, $en, (string)$s));
}

function qof_sellers() {
    return [
        '910' => 'هاجر رستمی',
        '911' => 'مهرنوش هاشمی',
        '912' => 'اسماعیل آرین پور',
        '913' => 'زهرا حاتمی',
        '914' => 'شعبه تهرانپارس',
    ];
}

function qof_product_label($product){
    if ( ! $product ) return '';
    if ( $product->is_type('variation') ) {
        $parent = wc_get_product( $product->get_parent_id() );
        $base   = $parent ? $parent->get_name() : ('Variation #'.$product->get_id());
        $attrs  = wc_get_formatted_variation( $product, true, true, false );
        $attrs  = trim( wp_strip_all_tags( (string) $attrs ) );
        $label  = trim( $base . ( $attrs ? ' - ' . $attrs : '' ) );
        if ( $label === '' ) $label = $product->get_name() ?: ('#'.$product->get_id());
        return $label;
    }
    $name = $product->get_name();
    return $name !== '' ? $name : ('#'.$product->get_id());
}

/* استخراج ظرفیت از اتربیوت pa_multi */
function qof_capacity_from_product($product){
    $cap = 0;
    if ( ! $product ) return 0;

    if ( $product->is_type('variation') ) {
        $slug = $product->get_meta('attribute_pa_multi', true);
        if ($slug !== '') {
            $slug = qof_normalize_digits($slug);
            if (preg_match('/(\d+)/', $slug, $m)) $cap = intval($m[1]);
        }
        if (!$cap) {
            $val = $product->get_attribute('pa_multi');
            if ($val !== '') {
                $val = qof_normalize_digits($val);
                if (preg_match('/(\d+)/', $val, $m)) $cap = intval($m[1]);
            }
        }
    } else {
        $val = $product->get_attribute('pa_multi');
        if ($val !== '') {
            $val = qof_normalize_digits($val);
            if (preg_match('/(\d+)/', $val, $m)) $cap = intval($m[1]);
        }
    }
    return $cap > 0 ? $cap : 0;
}

/* قیمت عددی (بدون فرمت) */
function qof_product_price_num($product){
    if(!$product) return 0;
    $price = $product->get_price();
    if ($price === '' || $price === null) return 0;
    return (float) $price;
}

/*======================================
=  Cache helpers
======================================*/
function qof_products_cache_key() {
    return 'qof_products_cache_' . QOF_VERSION;
}
function qof_bust_products_cache() {
    delete_transient( qof_products_cache_key() );
}
add_action('woocommerce_reduce_order_stock', 'qof_bust_products_cache');
add_action('woocommerce_product_set_stock', 'qof_bust_products_cache');
add_action('woocommerce_variation_set_stock', 'qof_bust_products_cache');

/*======================================
=  Enqueue Front assets (SelectWoo/Select2)
======================================*/
function qof_enqueue_front_assets() {
    wp_enqueue_script('jquery');

    // ✅ SelectWoo + select2 style (اگر ووکامرس موجود باشد)
    if ( function_exists('WC') ) {
        wp_enqueue_script('selectWoo');
        wp_enqueue_style('select2');
        return;
    }

    // fallback (اگر احیاناً selectWoo نبود)
    wp_enqueue_style('qof-select2', plugins_url('assets/select2.min.css', __FILE__), [], '4.1.0');
    wp_enqueue_script('qof-select2', plugins_url('assets/select2.min.js', __FILE__), ['jquery'], '4.1.0', true);
}
add_action('wp_enqueue_scripts', 'qof_enqueue_front_assets');

/*======================================
=  Cached Products
======================================*/
function qof_get_cached_products() {
    $cache_key = qof_products_cache_key();
    $products = get_transient($cache_key);

    if (false === $products) {
        $products = wc_get_products([
            'status' => 'publish',
            'limit'  => -1,
            'type'   => ['simple', 'variation'],
            'return' => 'objects',
        ]);
        set_transient($cache_key, $products, HOUR_IN_SECONDS);
    }
    return is_array($products) ? $products : [];
}

/*======================================
=  Shortcode: [quick_order_box key="910|..."]
======================================*/
add_shortcode('quick_order_box', function($atts){
    qof_enqueue_front_assets();
    if ( ! function_exists('wc_get_products') ) return '<div style="color:#b91c1c">WooCommerce فعال نیست.</div>';

    $atts = shortcode_atts(['key' => ''], $atts, 'quick_order_box');

    $products = qof_get_cached_products();
    $bucketed = [];

    foreach ($products as $p){
        if ( ! $p ) continue;
        $stock = $p->get_stock_quantity();
        if ($stock === null) $stock = 0;

        $row = [
            'id'    => $p->get_id(),
            'label' => qof_product_label($p),
            'stock' => (int)$stock,
            'price' => (float) qof_product_price_num($p), // ✅ برای pending subtotal
        ];
        $cap = qof_capacity_from_product($p);
        $bucketed[$cap][] = $row;
    }

    foreach ($bucketed as $cap => &$list) {
        usort($list, fn($a,$b)=>strcasecmp($a['label'],$b['label']));
    }
    unset($list);

    $name_select_html = ''; $id_select_html = '';
    $all = []; $rendered_caps = []; $preferred_order = [4,6,8,12,0];

    foreach ($preferred_order as $cap) {
        if (!isset($bucketed[$cap])) continue;
        $group_label = $cap > 0 ? ($cap.' نفره') : 'سایر';
        $name_select_html .= '<optgroup label="'.esc_attr($group_label).'">';
        $id_select_html   .= '<optgroup label="'.esc_attr($group_label).'">';

        foreach ($bucketed[$cap] as $row) {
            $opt_text_name = $row['label'] . ' — [موجودی: ' . $row['stock'] . ']';
            $opt_text_id   = $row['id'] . ' — ' . $row['label'] . ' — [موجودی: ' . $row['stock'] . ']';

            $name_select_html .= '<option value="'.esc_attr($row['id']).'" data-stock="'.esc_attr($row['stock']).'" data-price="'.esc_attr($row['price']).'">'.esc_html($opt_text_name).'</option>';
            $id_select_html   .= '<option value="'.esc_attr($row['id']).'" data-stock="'.esc_attr($row['stock']).'" data-price="'.esc_attr($row['price']).'">'.esc_html($opt_text_id).'</option>';

            $all[] = $row;
        }

        $name_select_html .= '</optgroup>';
        $id_select_html   .= '</optgroup>';
        $rendered_caps[$cap] = true;
    }

    $others = [];
    foreach ($bucketed as $cap => $list) {
        if (isset($rendered_caps[$cap])) continue;
        foreach ($list as $row) $others[] = $row;
    }
    usort($others, fn($a,$b)=>strcasecmp($a['label'],$b['label']));

    if (!empty($others)) {
        $name_select_html .= '<optgroup label="'.esc_attr('سایر').'">';
        $id_select_html   .= '<optgroup label="'.esc_attr('سایر').'">';
        foreach ($others as $row) {
            $opt_text_name = $row['label'] . ' — [موجودی: ' . $row['stock'] . ']';
            $opt_text_id   = $row['id'] . ' — ' . $row['label'] . ' — [موجودی: ' . $row['stock'] . ']';
            $name_select_html .= '<option value="'.esc_attr($row['id']).'" data-stock="'.esc_attr($row['stock']).'" data-price="'.esc_attr($row['price']).'">'.esc_html($opt_text_name).'</option>';
            $id_select_html   .= '<option value="'.esc_attr($row['id']).'" data-stock="'.esc_attr($row['stock']).'" data-price="'.esc_attr($row['price']).'">'.esc_html($opt_text_id).'</option>';
            $all[] = $row;
        }
        $name_select_html .= '</optgroup>';
        $id_select_html   .= '</optgroup>';
    }

    ob_start(); ?>
    <style>
      #qof-form .row-flex{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
      #qof-form select{max-width:100%}
      #qof-form .w-id{width:220px}
      #qof-form .w-name{width:640px}
      #qof-form .qty-input{width:110px;text-align:center;font-size:18px;padding:6px}
      #qof-form .table-wrap{margin-top:10px;display:none;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden}
      #qof-form table{border-collapse:collapse;width:100%}
      #qof-form th,#qof-form td{padding:8px;border-top:1px solid #e5e7eb}
      #qof-form thead th{background:#f3f4f6;border-top:none}
      #qof-form .btn{cursor:pointer;border-radius:10px}
      #qof-form .btn-primary{border:1px solid #2563eb;background:#2563eb;color:#fff}
      #qof-form .btn-add{border:1px solid #10b981;background:#bbf7d0;color:#065f46;font-weight:600}
      #qof-form .btn-hold{border:1px solid #f59e0b;background:#fffbeb;color:#92400e;font-weight:700}
      #qof-form .addr-box{width:100%;min-height:96px;padding:10px;border:1px solid #d1d5db;border-radius:8px;font-size:14px}
      #qof-form .helper{color:#6b7280;font-size:12px;margin-top:6px}
      #qof-form .stock-badge{font-size:13px;background:#eef2ff;border:1px solid #c7d2fe;border-radius:8px;padding:6px 10px;color:#1e40af}
      #qof-form .stock-badge.zero{background:#fee2e2;border-color:#fecaca;color:#991b1b}
      #qof-form .muted{opacity:.6;pointer-events:none}
      .select2-container .select2-results > .select2-results__options{max-height: 70vh !important;overscroll-behavior: contain;}
      .select2-dropdown{max-height: 75vh !important;overflow: auto !important;}
      .select2-search--dropdown .select2-search__field{padding:8px; font-size:14px; line-height:1.4;}
      #qof-msg{display:none;margin-bottom:8px;padding:10px;border-radius:8px;white-space:pre-line}
      #qof-msg.ok{display:block;background:#ecfdf5;border:1px solid #10b981;color:#065f46}
      #qof-msg.err{display:block;background:#fef2f2;border:1px solid #ef4444;color:#991b1b}

      /* ✅ موبایل: جدول کارت‌گونه */
      @media (max-width: 768px){
        #qof-form .w-id{width:100%}
        #qof-form .w-name{width:100%}
        #qof-form .qty-input{width:88px;font-size:16px}
        #qof-form .btn-primary,#qof-form .btn-add{width:100%}
        #qof-form thead { display:none; }
        #qof-form tbody tr{display:flex; flex-wrap:wrap; gap:8px; padding:10px;}
        #qof-form tbody td{border:none; padding:0;}
        #qof-form .col-rownum{order:0;font-size:12px;opacity:.6;}
        #qof-form .col-name{order:1; flex:1 1 100%; font-weight:600; font-size:14px;}
        #qof-form .col-qty{order:2; display:flex; align-items:center; gap:6px;}
        #qof-form .col-del{order:3; margin-inline-start:auto;}
        #qof-form .col-stock{order:4; font-size:12px; opacity:.8;}
        #qof-form .col-id{order:5; font-size:12px; opacity:.6;}
      }
    </style>

    <div id="qof-msg"></div>

    <form id="qof-form" dir="rtl" method="post" action="#" style="display:grid;gap:12px;align-items:center">
        <div class="row-flex">
            <label for="qof-sel-id" style="min-width:70px">ID:</label>
            <select id="qof-sel-id" class="w-id">
                <option value="">انتخاب ID</option>
                <?php echo $id_select_html; ?>
            </select>
            <label for="qof-sel-name" style="min-width:40px">محصول:</label>
            <select id="qof-sel-name" class="w-name">
                <option value="">انتخاب محصول</option>
                <?php echo $name_select_html; ?>
            </select>
        </div>

        <div class="row-flex" id="qof-stock-line" style="display:none">
            <span id="qof-stock-badge" class="stock-badge">موجودی: — | قابل افزودن: —</span>
        </div>

        <div class="row-flex">
          <span>تعداد:</span>
          <button type="button" id="qof-btn-dec" style="font-size:22px;padding:6px 12px" class="btn">➖</button>
          <input type="number" id="qof-qty" value="1" min="0" class="qty-input">
          <button type="button" id="qof-btn-inc" style="font-size:22px;padding:6px 12px" class="btn">➕</button>
          <button type="button" id="qof-btn-add" style="margin-inline-start:12px;padding:10px 16px" class="btn btn-add muted" disabled>➕ اضافه کردن</button>
        </div>

        <div class="row-flex" style="width:100%">
          <div style="flex:1; min-width:240px">
            <label for="qof-cust-name" style="display:block;margin-bottom:6px">نام و نام‌خانوادگی مشتری:</label>
            <input type="text" id="qof-cust-name" style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px" placeholder="مثال: محسن رضایی">
          </div>
          <div style="flex:1; min-width:220px">
            <label for="qof-cust-phone" style="display:block;margin-bottom:6px">شماره موبایل:</label>
            <input type="tel" id="qof-cust-phone" style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px" placeholder="۰۹۱۱۱۱۱۱۱۱۱">
          </div>
          <div style="flex:1; min-width:220px">
            <label for="qof-seller" style="display:block;margin-bottom:6px">فروشنده:</label>
            <input type="text" id="qof-seller" style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px;background:#f9fafb" readonly>
          </div>
        </div>

        <!-- فقط برای شعبه تهرانپارس: نوع تحویل -->
        <div class="row-flex" id="qof-delivery-wrap" style="width:100%;display:none;margin-top:4px">
          <span style="font-weight:600">نحوه تحویل:</span>
          <label style="display:flex;align-items:center;gap:4px">
            <input type="radio" name="qof_delivery" value="home" checked>
            <span>ارسال درب منزل (پست)</span>
          </label>
          <label style="display:flex;align-items:center;gap:4px">
            <input type="radio" name="qof_delivery" value="branch">
            <span>تحویل در شعبه تهرانپارس</span>
          </label>
        </div>

        <div class="table-wrap" id="qof-table-wrap">
          <table id="qof-items-table">
            <thead>
              <tr>
                <th style="width:50px;text-align:center">#</th>
                <th style="width:110px">ID</th>
                <th>محصول</th>
                <th style="width:140px;text-align:center">موجودی فعلی</th>
                <th style="width:280px;text-align:center">تعداد (+/−)</th>
                <th style="width:100px;text-align:center">حذف</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>

        <div>
          <label for="qof-address" style="display:block;margin-bottom:6px">آدرس سفارش:</label>
          <textarea id="qof-address" class="addr-box" placeholder=""></textarea>
          <div class="helper">برای فعال شدن «ثبت نهایی»، حداقل یک آیتم اضافه شود. (برای کد ۹۱۰ نام/موبایل/آدرس اختیاری است، سایرین اجباری)</div>
        </div>

        <?php wp_nonce_field('qof_place_order_form','_wpnonce_qof'); ?>
        <input type="hidden" id="qof-hidden-items"   name="items" value="">
        <input type="hidden" id="qof-hidden-address" name="address" value="">
        <input type="hidden" id="qof-hidden-name"    name="cust_name" value="">
        <input type="hidden" id="qof-hidden-phone"   name="cust_phone" value="">
        <input type="hidden" id="qof-hidden-code"    name="user_code" value="">
        <input type="hidden" id="qof-hidden-delivery" name="delivery_type" value="">

        <div class="row-flex" style="justify-content:flex-start">
          <button type="submit" id="qof-btn-save" style="margin-top:4px;padding:12px 18px" class="btn btn-primary" disabled>✅ ثبت نهایی سفارش</button>
          <button type="button" id="qof-btn-hold" style="margin-top:4px;padding:12px 18px" class="btn btn-hold" disabled>⏸️ در انتظار</button>
        </div>
    </form>

<?php
    // ⛔️ ادامه JS + منطق pending/meta و AJAX در پارت 2 می‌آید
    return ob_get_clean();
});
<?php
/* =========================================================
 * PART 2/5
 * - JS کامل فرم سفارش (SelectWoo سرچ)
 * - قوانین اجباری بودن فیلدها (همه اجباری، فقط کد 910 اختیاری)
 * - pending (موجودی صفر) با req_qty + ذخیره subtotal pending سمت کلاینت
 * ========================================================= */

add_shortcode('quick_order_box', function($atts){
    qof_enqueue_front_assets();
    if ( ! function_exists('wc_get_products') ) return '<div style="color:#b91c1c">WooCommerce فعال نیست.</div>';

    $atts = shortcode_atts(['key' => ''], $atts, 'quick_order_box');

    // === (همان بخش دیتاست پارت 1 را اینجا فرض کن که ساخته شده) ===
    // برای اینکه این پارت مستقل نباشد، در فایل نهایی پارت1+2 بهم می‌چسبند.
    // پس اینجا فقط ادامه‌ی خروجی HTML/JS بعد از </form> می‌آید.

    ob_start(); ?>
    <!-- ✅ ادامه از انتهای پارت 1: (دقیقاً بعد از </form>) -->

    <script>
    jQuery(function($){
        const allProducts = <?php echo wp_json_encode($all); ?>;
        const defaultShortcodeKey = "<?php echo esc_js($atts['key']); ?>";
        const urlParams = new URLSearchParams(window.location.search);
        const urlKey = urlParams.get('key') || urlParams.get('code') || '';
        const userCode = urlKey || defaultShortcodeKey;

        const sellerMap = <?php echo wp_json_encode( qof_sellers(), JSON_UNESCAPED_UNICODE ); ?>;
        const sellerName = sellerMap[userCode] || "";
        $('#qof-seller').val(sellerName);

        // ✅ فقط برای کد 910: نام/موبایل/آدرس اختیاری (سایرین اجباری)
        const is910 = (String(userCode) === '910');

        const isTehranpars = (sellerName === 'شعبه تهرانپارس' || userCode === '914');
        if (isTehranpars) $('#qof-delivery-wrap').show(); else $('#qof-delivery-wrap').hide();

        $('input[name="qof_delivery"]').on('change', function(){
            if (!isTehranpars) return;
            const val = $('input[name="qof_delivery"]:checked').val();
            if (val === 'branch') {
                $('#qof-address').val('باید به شعبه تهرانپارس تحویل گردد.').prop('readonly', true);
            } else {
                if ($('#qof-address').val() === 'باید به شعبه تهرانپارس تحویل گردد.') $('#qof-address').val('');
                $('#qof-address').prop('readonly', false);
            }
        });

        const items = [];

        // ✅ init selectWoo/select2
        const hasSelect = $.fn && ($.fn.selectWoo || $.fn.select2);
        function initSelect(sel){
            if(!hasSelect) return;
            const $el = $(sel);
            const opts = { placeholder: '', allowClear: true, width: 'resolve', dir: 'rtl' };
            if ($.fn.selectWoo) { $el.selectWoo(opts); } else { $el.select2(opts); }
        }
        function destroySelect(sel){
            const $el = $(sel);
            try { if ($.fn.selectWoo && $el.data('select2')) $el.selectWoo('destroy'); } catch(e){}
            try { if ($.fn.select2  && $el.data('select2')) $el.select2('destroy'); } catch(e){}
        }
        function reinitSelect(sel){
            const $el = $(sel);
            const val = $el.val();
            destroySelect(sel);
            initSelect(sel);
            $el.val(val).trigger('change.select2');
        }
        function reinitBoth(){ reinitSelect('#qof-sel-id'); reinitSelect('#qof-sel-name'); }
        if (hasSelect){ initSelect('#qof-sel-id'); initSelect('#qof-sel-name'); }

        // dataset helpers
        function findById(id){ return allProducts.find(p => String(p.id) === String(id)); }
        function findLabelById(id){ const f = findById(id); return f ? f.label : ''; }
        function baseStockById(id){ const f = findById(id); return f ? (f.stock||0) : 0; }
        function priceById(id){ const f = findById(id); return f ? (parseFloat(f.price||0) || 0) : 0; }

        function sumSelectedQty(id){
            return items
              .filter(x=>String(x.id)===String(id))
              .reduce((a,b)=>a+(+b.qty||0),0);
        }
        function availableStock(id){ return Math.max(0, baseStockById(id) - sumSelectedQty(id)); }
        function getSelectedId(){ return $('#qof-sel-id').val() || $('#qof-sel-name').val() || ''; }

        function updateStockBadge(){
            const pid = getSelectedId();
            if(!pid){ $('#qof-stock-line').hide(); return; }
            const total = baseStockById(pid);
            const avail = availableStock(pid);
            const $badge = $('#qof-stock-badge');
            $('#qof-stock-line').show();
            $badge.text('موجودی: '+total+' | قابل افزودن: '+avail);
            $badge.toggleClass('zero', avail===0);
        }

        function clampQty(val, max){
            val = parseInt(val||0,10);
            if (!Number.isFinite(val)) val = 0;
            const min = 1;
            if (val < min) val = min;
            if (typeof max === 'number' && max >= 0) val = Math.min(val, max);
            return val;
        }

        function refreshAddButtonState(){
            const pid = getSelectedId();
            const totalStock = pid ? baseStockById(pid) : 0;
            const avail = pid ? availableStock(pid) : 0;
            let qty = parseInt($('#qof-qty').val()||0,10);
            qty = Number.isFinite(qty) ? qty : 0;

            // ✅ موجودی صفر هم اجازه اضافه شدن بده (pending)
            const ok = pid && (
              (totalStock === 0 && qty >= 1) ||
              (totalStock > 0 && avail>0 && qty>=1 && qty<=avail)
            );
            $('#qof-btn-add').prop('disabled', !ok).toggleClass('muted', !ok);
        }

        function syncSelects(val){
            $('#qof-sel-id').val(val).trigger('change.select2');
            $('#qof-sel-name').val(val).trigger('change.select2');
        }

        function onSelectChange(){
            const pid = getSelectedId();
            updateStockBadge();
            if(!pid){
                $('#qof-qty').prop('disabled', false).val(1);
                $('#qof-btn-inc, #qof-btn-dec').prop('disabled', false).toggleClass('muted', false);
                refreshAddButtonState();
                return;
            }

            const totalStock = baseStockById(pid);

            // ✅ اگر موجودی صفر است، تعداد آزاد باشد
            if (totalStock === 0) {
                $('#qof-qty').prop('disabled', false).val(1);
                $('#qof-btn-inc, #qof-btn-dec').prop('disabled', false).toggleClass('muted', false);
                refreshAddButtonState();
                return;
            }

            $('#qof-qty').prop('disabled', false);
            $('#qof-btn-inc, #qof-btn-dec').prop('disabled', false).toggleClass('muted', false);

            const avail = availableStock(pid);
            if (avail===0){
                alert('موجودی این محصول صفر است.');
                $('#qof-qty').val(1);
            } else {
                $('#qof-qty').val(1);
            }
            refreshAddButtonState();
        }

        $('#qof-sel-id').on('change', function(){ syncSelects($(this).val()); onSelectChange(); });
        $('#qof-sel-name').on('change', function(){ syncSelects($(this).val()); onSelectChange(); });

        $('#qof-btn-inc').on('click', ()=>{
            const pid = getSelectedId(); if(!pid) return;
            const stock = baseStockById(pid);
            const cur = +$('#qof-qty').val()||1;

            if (stock === 0) {
                $('#qof-qty').val(cur+1);
                refreshAddButtonState();
                return;
            }

            const avail = availableStock(pid);
            $('#qof-qty').val( Math.min(cur+1, Math.max(1, avail)) );
            refreshAddButtonState();
        });

        $('#qof-btn-dec').on('click', ()=>{
            const cur = +$('#qof-qty').val()||1;
            $('#qof-qty').val( Math.max(1, cur-1) );
            refreshAddButtonState();
        });

        $('#qof-qty').on('input change', ()=>{
            const pid = getSelectedId();
            if (!pid) { refreshAddButtonState(); return; }

            const stock = baseStockById(pid);
            if (stock === 0) {
                const v = clampQty($('#qof-qty').val(), null);
                $('#qof-qty').val(v);
                refreshAddButtonState();
                return;
            }

            const avail = availableStock(pid);
            $('#qof-qty').val( clampQty($('#qof-qty').val(), avail) );
            refreshAddButtonState();
        });

        function renderTable(){
            const $wrap = $('#qof-table-wrap');
            const tbody = $('#qof-items-table tbody').empty();
            if(items.length === 0){
                $wrap.hide(); checkReady(); updateStockBadge(); return;
            }
            $wrap.show();

            items.forEach((it,idx)=>{
                const pid = it.id;
                const stock = baseStockById(pid);
                const sumOthers = items.reduce((a,b,i)=> i===idx ? a : (String(b.id)===String(pid) ? a+(+b.qty||0) : a), 0);
                const rowMax = Math.max(0, stock - sumOthers);

                const $tr = $('<tr>');
                $tr.append(`<td class="col-rownum" style="text-align:center;font-weight:600">${idx + 1}</td>`);
                $tr.append(`<td class="col-id">${it.id}</td>`);

                const pendingInfo = (it.locked_zero && (it.req_qty||0)>0)
                  ? `<div class="qof-muted" style="font-size:12px;margin-top:2px">تعداد درخواستی: ${it.req_qty} | قیمت واحد: ${it.price || 0}</div>`
                  : '';

                $tr.append(`<td class="col-name">${it.name}${pendingInfo}</td>`);
                $tr.append(`<td class="col-stock" style="text-align:center">موجودی: ${stock}</td>`);

                const lockedZero = !!it.locked_zero;
                const minQty = lockedZero ? 0 : 1;
                const disableInc = lockedZero || it.qty >= rowMax;
                const disableDec = lockedZero || it.qty <= minQty;

                const qtyControls = $(`
                  <td class="col-qty" style="text-align:center;display:flex;align-items:center;justify-content:center;gap:6px;flex-wrap:wrap">
                    <button class="qof-row-dec" data-i="${idx}" type="button" ${disableDec?'disabled':''}
                      style="font-size:20px;padding:6px 12px;margin-inline:4px;background:#fee2e2;border:1px solid #ef4444;border-radius:10px;${disableDec?'opacity:.6;cursor:not-allowed':''}">➖</button>

                    <!-- ✅ موبایل: دکمه‌های کوچک + و - -->
                    <button class="qof-row-dec-mini" data-i="${idx}" type="button" ${disableDec?'disabled':''}
                      style="display:none;font-size:14px;padding:6px 10px;background:#fee2e2;border:1px solid #ef4444;border-radius:10px;${disableDec?'opacity:.6;cursor:not-allowed':''}">-</button>

                    <input type="number" class="qof-row-qty" data-i="${idx}" value="${it.qty}" min="${minQty}" ${(!lockedZero && rowMax>0)?`max="${rowMax}"`:''} ${lockedZero?'disabled':''}
                      style="width:86px;text-align:center;font-size:17px;padding:6px">

                    <button class="qof-row-inc-mini" data-i="${idx}" type="button" ${disableInc?'disabled':''}
                      style="display:none;font-size:14px;padding:6px 10px;background:#dcfce7;border:1px solid #16a34a;border-radius:10px;${disableInc?'opacity:.6;cursor:not-allowed':''}">+</button>

                    <button class="qof-row-inc" data-i="${idx}" type="button" ${disableInc?'disabled':''}
                      style="font-size:20px;padding:6px 12px;margin-inline:4px;background:#dcfce7;border:1px solid #16a34a;border-radius:10px;${disableInc?'opacity:.6;cursor:not-allowed':''}">➕</button>
                  </td>
                `);

                // ✅ در موبایل، miniها فعال شوند
                if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches){
                  qtyControls.find('.qof-row-dec-mini,.qof-row-inc-mini').css('display','inline-flex');
                }

                $tr.append(qtyControls);
                $tr.append(`<td class="col-del" style="text-align:center"><button data-i="${idx}" type="button" class="qof-btn-del" style="cursor:pointer">❌</button></td>`);
                tbody.append($tr);
            });

            checkReady(); updateStockBadge();
        }

        function checkReady(){
            const hasItems = items.length > 0;
            const hasZeroQty = items.some(it => (parseInt(it && it.qty, 10) || 0) === 0);

            // ✅ اعتبارسنجی فیلدهای مشتری/آدرس:
            const nameOk  = is910 ? true : String($('#qof-cust-name').val()||'').trim().length > 0;
            const phoneOk = is910 ? true : String($('#qof-cust-phone').val()||'').trim().length > 0;
            const addrOk  = is910 ? true : String($('#qof-address').val()||'').trim().length > 0;

            // ثبت نهایی: هم آیتم داشته باشیم، هم pending (qty=0) نداشته باشیم، هم فیلدها ok باشند
            $('#qof-btn-save').prop('disabled', !hasItems || hasZeroQty || !(nameOk && phoneOk && addrOk));
            // در انتظار: فقط آیتم داشته باشد
            $('#qof-btn-hold').prop('disabled', !hasItems);
        }

        $('#qof-address,#qof-cust-name,#qof-cust-phone').on('input', checkReady);

        // add item
        $('#qof-btn-add').on('click', function(){
            const pid = getSelectedId();
            if(!pid){ refreshAddButtonState(); return; }
            const totalStock = baseStockById(pid);
            const unitPrice = priceById(pid);

            // ✅ موجودی صفر => pending با qty=0 + req_qty + price + pending_subtotal
            if (totalStock === 0) {
                const requestedQty = clampQty($('#qof-qty').val(), null);
                if (requestedQty < 1) { alert('تعداد درخواستی معتبر نیست.'); return; }
                const name  = findLabelById(pid) || '(بدون نام)';
                const existingIdx = items.findIndex(x => String(x.id) === String(pid));
                const pendingSubtotal = (requestedQty * unitPrice);

                if (existingIdx >= 0) {
                    items[existingIdx].qty = 0;
                    items[existingIdx].locked_zero = true;
                    items[existingIdx].req_qty = requestedQty;
                    items[existingIdx].price = unitPrice;
                    items[existingIdx].pending_subtotal = pendingSubtotal;
                } else {
                    items.push({id: pid, name, qty: 0, req_qty: requestedQty, locked_zero: true, price: unitPrice, pending_subtotal: pendingSubtotal});
                }
                renderTable();
                syncSelects(''); $('#qof-qty').val(1); refreshAddButtonState();
                return;
            }

            const avail = availableStock(pid);
            if(avail<=0){ alert('موجودی کافی نیست.'); refreshAddButtonState(); return; }
            let qty = clampQty($('#qof-qty').val(), avail);
            if(qty<1){ alert('تعداد معتبر نیست.'); return; }
            const name  = findLabelById(pid) || '(بدون نام)';
            items.push({id: pid, name, qty});
            renderTable();
            syncSelects(''); $('#qof-qty').val(1); refreshAddButtonState();
        });

        // row +/- handlers
        $('#qof-items-table').on('click','.qof-row-inc, .qof-row-inc-mini', function(){
            const idx = +$(this).data('i');
            if (items[idx] && items[idx].locked_zero) return;
            const pid = items[idx].id;
            const stock = baseStockById(pid);
            const sumOthers = items.reduce((a,b,i)=> i===idx ? a : (String(b.id)===String(pid) ? a+(+b.qty||0) : a), 0);
            const rowMax = Math.max(0, stock - sumOthers);
            if(items[idx].qty < rowMax){ items[idx].qty++; }
            renderTable();
        });

        $('#qof-items-table').on('click','.qof-row-dec, .qof-row-dec-mini', function(){
            const idx = +$(this).data('i');
            if (items[idx] && items[idx].locked_zero) return;
            items[idx].qty = Math.max(1, (+items[idx].qty||1)-1);
            renderTable();
        });

        $('#qof-items-table').on('change input','.qof-row-qty', function(){
            const idx = +$(this).data('i');
            if (items[idx] && items[idx].locked_zero) { items[idx].qty = 0; renderTable(); return; }
            const pid = items[idx].id;
            const stock = baseStockById(pid);
            const sumOthers = items.reduce((a,b,i)=> i===idx ? a : (String(b.id)===String(pid) ? a+(+b.qty||0) : a), 0);
            const rowMax = Math.max(0, stock - sumOthers);
            const v = clampQty($(this).val(), rowMax);
            items[idx].qty = Math.max(1, v);
            renderTable();
        });

        $('#qof-items-table').on('click','.qof-btn-del',function(){
            items.splice($(this).data('i'),1);
            renderTable();
        });

        let submitType = 'processing';
        $('#qof-btn-save').on('click', function(){ submitType = 'processing'; });
        $('#qof-btn-hold').on('click', function(){ submitType = 'on-hold'; $('#qof-form').trigger('submit'); });

        let submitting = false;
        $('#qof-form').on('submit', function(e){
            e.preventDefault();
            if (submitting) return false;

            if (items.length === 0){
                $('#qof-msg').removeClass('ok').addClass('err').text('هیچ آیتمی انتخاب نشده.').show();
                return false;
            }

            // اگر qty=0 داریم => on-hold
            if (items.some(it => (parseInt(it && it.qty, 10) || 0) === 0)) submitType = 'on-hold';

            // ✅ اگر ثبت نهایی است و کد 910 نیست => فیلدها باید پر باشد
            if (submitType === 'processing' && !is910) {
                const n = String($('#qof-cust-name').val()||'').trim();
                const p = String($('#qof-cust-phone').val()||'').trim();
                const a = String($('#qof-address').val()||'').trim();
                if (!n || !p || !a){
                    $('#qof-msg').removeClass('ok').addClass('err').text('برای ثبت نهایی، نام/موبایل/آدرس الزامی است.').show();
                    checkReady();
                    return false;
                }
            }

            const address   = ($('#qof-address').val()||'').trim();
            const custName  = ($('#qof-cust-name').val()||'').trim();
            const custPhone = ($('#qof-cust-phone').val()||'').trim();

            let deliveryType = '';
            if (isTehranpars) deliveryType = $('input[name="qof_delivery"]:checked').val() || 'home';

            $('#qof-hidden-items').val(JSON.stringify(items));
            $('#qof-hidden-address').val(address);
            $('#qof-hidden-name').val(custName);
            $('#qof-hidden-phone').val(custPhone);
            $('#qof-hidden-code').val(userCode);
            $('#qof-hidden-delivery').val(deliveryType);

            submitting = true;

            const isHold = (submitType === 'on-hold');
            const $btnMain = $('#qof-btn-save');
            const $btnHold = $('#qof-btn-hold');

            $btnMain.prop('disabled', true).css({opacity: 0.6, cursor: 'not-allowed'});
            $btnHold.prop('disabled', true).css({opacity: 0.6, cursor: 'not-allowed'});
            if(isHold) $btnHold.text('در حال ثبت (در انتظار)...'); else $btnMain.text('در حال ثبت سفارش...');

            $.ajax({
              url: '<?php echo esc_js(admin_url("admin-ajax.php")); ?>',
              type: 'POST',
              dataType: 'json',
              data: {
                action: 'qof_place_order',
                _wpnonce_qof: $('input[name="_wpnonce_qof"]').val(),
                items: $('#qof-hidden-items').val(),
                address: $('#qof-hidden-address').val(),
                cust_name: $('#qof-hidden-name').val(),
                cust_phone: $('#qof-hidden-phone').val(),
                user_code: $('#qof-hidden-code').val(),
                delivery_type: $('#qof-hidden-delivery').val(),
                submit_type: submitType
              }
            }).done(function(res){
              if(res && res.success && res.data && res.data.ok){
                 $('#qof-msg').removeClass('ok err');
                 const stTxt = (submitType === 'on-hold') ? ' (در انتظار)' : '';
                 $('#qof-msg').addClass('ok').text('سفارش با موفقیت ثبت شد'+stTxt+'. شماره سفارش: #'+res.data.order_id).show();

                 // reset
                 items.splice(0, items.length);
                 $('#qof-address').val('').prop('readonly', false);
                 $('#qof-cust-name').val(''); $('#qof-cust-phone').val('');
                 if (isTehranpars) $('input[name="qof_delivery"][value="home"]').prop('checked', true);

                 renderTable(); updateStockBadge(); checkReady();
                 syncSelects('');
                 submitType = 'processing';
              } else {
                 const msg = (res && res.data && res.data.err) ? res.data.err : 'خطای نامشخص در ثبت سفارش.';
                 $('#qof-msg').removeClass('ok').addClass('err').text(msg).show();
              }
            }).fail(function(){
              $('#qof-msg').removeClass('ok').addClass('err').text('عدم ارتباط با سرور. دوباره تلاش کن.').show();
            }).always(function(){
              submitting = false;
              $btnMain.css({opacity: 1, cursor: ''}).text('✅ ثبت نهایی سفارش');
              $btnHold.css({opacity: 1, cursor: ''}).text('⏸️ در انتظار');
              checkReady();
              refreshAddButtonState();
            });
        });

        // init
        updateStockBadge();
        refreshAddButtonState();
        checkReady();
    });
    </script>

<?php
    return ob_get_clean();
});
<?php
/* =========================================================
 * PART 3/5
 * - AJAX ثبت سفارش (qof_place_order)
 * - ذخیره Pending (موجودی صفر) + req_qty + price + pending_subtotal
 * - ✅ متای جمع مبلغ Pendingها برای ربات تلگرام
 * - ذخیره اطلاعات مشتری/موبایل/آدرس + delivery
 * ========================================================= */

add_action('wp_ajax_qof_place_order', 'qof_place_order_ajax');
add_action('wp_ajax_nopriv_qof_place_order', 'qof_place_order_ajax');

function qof_place_order_ajax(){
    if ( empty($_POST['_wpnonce_qof']) || ! wp_verify_nonce( wp_unslash($_POST['_wpnonce_qof']), 'qof_place_order_form') ) {
        wp_send_json_error(['err' => 'خطای امنیتی (Nonce).']);
    }

    $raw           = isset($_POST['items']) ? wp_unslash($_POST['items']) : '[]';
    $items         = json_decode($raw, true);

    $address       = isset($_POST['address'])        ? sanitize_textarea_field( wp_unslash($_POST['address']) ) : '';
    $cust_name     = isset($_POST['cust_name'])      ? sanitize_text_field( wp_unslash($_POST['cust_name']) )   : '';
    $cust_phone    = isset($_POST['cust_phone'])     ? sanitize_text_field( wp_unslash($_POST['cust_phone']) )  : '';
    $user_code     = isset($_POST['user_code'])      ? sanitize_text_field( wp_unslash($_POST['user_code']) )   : '';
    $delivery_type = isset($_POST['delivery_type'])  ? sanitize_text_field( wp_unslash($_POST['delivery_type']) ) : '';
    $submit_type   = isset($_POST['submit_type'])    ? sanitize_text_field( wp_unslash($_POST['submit_type']) ) : 'processing';

    if ( ! in_array($submit_type, ['processing','on-hold'], true) ) $submit_type = 'processing';
    if ( ! is_array($items) || empty($items) ) wp_send_json_error(['err'=>'هیچ آیتمی ارسال نشده است.']);

    // ✅ قانون: همه اجباری، فقط کد 910 اختیاری (برای ثبت نهایی)
    $is910 = (string)$user_code === '910';
    if ($submit_type === 'processing' && !$is910) {
        if (trim($cust_name) === '' || trim($cust_phone) === '' || trim($address) === '') {
            wp_send_json_error(['err'=>'برای ثبت نهایی، نام/موبایل/آدرس الزامی است.']);
        }
    }

    $map    = qof_sellers();
    $seller = isset($map[$user_code]) ? $map[$user_code] : '';

    $user = wp_get_current_user();
    $uid  = (int) ($user->ID ?? 0);
    $ip   = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field( wp_unslash($_SERVER['REMOTE_ADDR']) ) : '';

    // --- parse items
    $req = [];                 // pid => qty (موجود)
    $pending_ids = [];         // [pid,...]
    $pending_req = [];         // pid => req_qty
    $pending_price = [];       // pid => unit_price (numeric)
    $pending_subtotal = [];    // pid => req_qty * unit_price
    $pending_total_sum = 0.0;  // ✅ جمع مبلغ pendingها (برای تلگرام)

    $prod_cache = [];
    $get_prod = function($pid) use (&$prod_cache){
        $pid = (int)$pid;
        if (!isset($prod_cache[$pid])) $prod_cache[$pid] = wc_get_product($pid);
        return $prod_cache[$pid];
    };

    foreach($items as $it){
        $pid = isset($it['id']) ? absint($it['id']) : 0;
        if(!$pid) continue;

        $qty_raw = $it['qty'] ?? 0;
        $qty = (int) qof_normalize_digits($qty_raw);
        if($qty < 0) wp_send_json_error(['err'=>'تعداد منفی معتبر نیست.']);

        $req_raw = $it['req_qty'] ?? 0;
        $req_qty = (int) qof_normalize_digits($req_raw);
        if($req_qty < 0) wp_send_json_error(['err'=>'تعداد درخواستی منفی معتبر نیست.']);

        // pending (qty=0)
        if($qty === 0){
            $pending_ids[(int)$pid] = 1;

            if ($req_qty > 0) $pending_req[(int)$pid] = (int)$req_qty;

            // قیمت از کلاینت ممکن است بیاید، اما برای امنیت از محصول هم می‌گیریم
            $client_price = isset($it['price']) ? (float) $it['price'] : null;
            $product = $get_prod($pid);
            $unit_price = 0.0;

            if ($product) {
                $unit_price = (float) ($product->get_price() !== '' ? $product->get_price() : 0);
            }
            if ($client_price !== null && $client_price >= 0) {
                // اگر کلاینت فرستاده، ولی با اختلاف خیلی شدید نبود، همان را قبول کنیم
                // (برای سادگی: همیشه قیمت محصول را مبنا می‌گیریم، اما اگر محصول قیمت نداشت از کلاینت استفاده می‌کنیم)
                if ($unit_price <= 0 && $client_price > 0) $unit_price = $client_price;
            }

            $pending_price[(int)$pid] = (float)$unit_price;
            $sub = 0.0;
            if ($req_qty > 0 && $unit_price > 0) $sub = ((float)$req_qty) * ((float)$unit_price);
            $pending_subtotal[(int)$pid] = (float)$sub;
            $pending_total_sum += (float)$sub;

            continue;
        }

        // موجود
        $req[$pid] = ($req[$pid] ?? 0) + $qty;
    }

    $pending_ids = array_values(array_unique(array_map('absint', array_keys($pending_ids))));

    // اگر pid هم در req هست هم pending، pending حذف شود
    if (!empty($pending_ids) && !empty($req)) {
        $pending_ids = array_values(array_diff($pending_ids, array_map('absint', array_keys($req))));
    }

    if(empty($req) && empty($pending_ids)) wp_send_json_error(['err'=>'آیتم معتبر یافت نشد.']);

    // اگر pending داریم => سفارش on-hold شود
    if (!empty($pending_ids)) $submit_type = 'on-hold';

    // Stock check فقط برای اقلام موجود
    $errors = [];
    foreach($req as $pid=>$qty){
        $product = $get_prod($pid);
        if( ! $product ){ $errors[] = "محصول #$pid یافت نشد."; continue; }
        $stock = (int) ($product->get_stock_quantity() ?? 0);
        if ( $qty > $stock ){
            $errors[] = sprintf('موجودی کافی برای «%s» نیست. درخواست: %d | موجودی: %d', $product->get_name(), $qty, $stock);
        }
    }
    if(!empty($errors)) wp_send_json_error(['err'=>implode("\n", $errors)]);

    try{
        $order = wc_create_order([ 'customer_id' => $uid ?: 0 ]);

        // add existing items
        foreach($req as $pid=>$qty){
            $product = $get_prod($pid);
            if( $product ) $order->add_product( $product, $qty );
        }

        // ✅ meta: pending IDs
        if (!empty($pending_ids)) $order->update_meta_data('_wc_qof_pending_items', $pending_ids);
        else $order->delete_meta_data('_wc_qof_pending_items');

        // ✅ meta: pending req qty map
        $pending_req_clean = [];
        if (!empty($pending_ids)) {
            foreach ($pending_ids as $pid) {
                $pending_req_clean[(int)$pid] = (int) ($pending_req[$pid] ?? 0);
            }
        }
        if (!empty($pending_req_clean)) $order->update_meta_data('_wc_qof_pending_req_qty', $pending_req_clean);
        else $order->delete_meta_data('_wc_qof_pending_req_qty');

        // ✅ meta: pending unit price map (numeric)
        $pending_price_clean = [];
        if (!empty($pending_ids)) {
            foreach ($pending_ids as $pid) {
                $pending_price_clean[(int)$pid] = (float) ($pending_price[$pid] ?? 0);
            }
        }
        if (!empty($pending_price_clean)) $order->update_meta_data('_wc_qof_pending_unit_price', $pending_price_clean);
        else $order->delete_meta_data('_wc_qof_pending_unit_price');

        // ✅ meta: pending subtotal map (numeric)
        $pending_sub_clean = [];
        if (!empty($pending_ids)) {
            foreach ($pending_ids as $pid) {
                $pending_sub_clean[(int)$pid] = (float) ($pending_subtotal[$pid] ?? 0);
            }
        }
        if (!empty($pending_sub_clean)) $order->update_meta_data('_wc_qof_pending_subtotal', $pending_sub_clean);
        else $order->delete_meta_data('_wc_qof_pending_subtotal');

        // ✅ مهم برای ربات تلگرام: جمع مبلغ آیتم‌های pending
        // این متا را با متای مبلغ کل سفارش اصلی جمع می‌کنی
        $order->update_meta_data('_wc_qof_pending_total', (float) $pending_total_sum);

        // addresses
        $addr = [
            'first_name' => $cust_name,
            'last_name'  => '',
            'address_1'  => $address,
            'address_2'  => '',
            'city'       => '',
            'state'      => '',
            'postcode'   => '',
            'country'    => '',
            'phone'      => $cust_phone,
            'email'      => $uid ? ($user->user_email ?? '') : '',
        ];
        $order->set_address($addr, 'billing');
        $order->set_address($addr, 'shipping');

        // notes
        $note_lines = [];
        if ($user_code)  $note_lines[] = 'کد کاربر: '.$user_code;
        if ($ip)         $note_lines[] = 'IP: '.$ip;
        if ($cust_name)  $note_lines[] = 'نام مشتری: '.$cust_name;
        if ($cust_phone) $note_lines[] = 'موبایل مشتری: '.$cust_phone;
        if ($seller)     $note_lines[] = 'فروشنده: '.$seller;

        if ($delivery_type) {
            $note_lines[] = ($delivery_type === 'branch') ? 'نحوه تحویل: تحویل در شعبه تهرانپارس' : 'نحوه تحویل: ارسال درب منزل (پست)';
        }

        $note_lines[] = ($submit_type === 'on-hold') ? 'نوع ثبت: در انتظار' : 'نوع ثبت: نهایی';
        $note_lines[] = 'ثبت از «WC Quick Order Box» (AJAX).';

        // ✅ pending را در order_note ننویس (طبق خواسته قبلی)
        $order->add_order_note( implode(" | ", $note_lines) );
        if ($seller) $order->set_customer_note('فروشنده: '.$seller);

        // meta basics
        if ($user_code)      $order->update_meta_data('_wc_qof_user_code', $user_code);
        if ($cust_name)      $order->update_meta_data('_wc_qof_customer_fullname', $cust_name);
        if ($cust_phone)     $order->update_meta_data('_wc_qof_customer_phone', $cust_phone);
        if ($delivery_type)  $order->update_meta_data('_wc_qof_delivery_type', $delivery_type);
        $order->update_meta_data('_wc_qof_submit_type', $submit_type);

        $order->calculate_totals();

        $target_status = ($submit_type === 'on-hold') ? 'on-hold' : 'processing';
        $order->update_status($target_status, 'ثبت از فرم سفارش سریع (AJAX).', true);
        $order->save();

        $order_id = $order->get_id();

        // reduce stock ONLY for existing items
        if ( function_exists('wc_maybe_reduce_stock_levels') ) {
            wc_maybe_reduce_stock_levels( $order_id );
        }

        // new stocks return
        $newstocks = [];
        foreach (array_keys($req) as $pid) {
            $pp = wc_get_product($pid);
            $newstocks[$pid] = (int) ($pp ? ($pp->get_stock_quantity() ?? 0) : 0);
        }

        qof_bust_products_cache();

        wp_send_json_success([
            'ok'       => 1,
            'order_id' => $order_id,
            'stocks'   => $newstocks,
            // برای debug در صورت نیاز:
            // 'pending_total' => $pending_total_sum,
        ]);

    } catch (Throwable $e){
        wp_send_json_error(['err'=>'خطا در ایجاد سفارش: '.$e->getMessage()]);
    }
}
<?php
/* =========================================================
 * PART 4/5
 * - Shortcode گزارش سفارش‌ها: [qof_orders]
 * - نمایش جزئیات سفارش (AJAX)
 * - UI ویرایش سفارش‌های on-hold:
 *    - ✅ ویرایش نام/موبایل/آدرس + delivery
 *    - ✅ select2 سرچ برای افزودن محصول (حذف نشده)
 *    - ✅ pending (موجودی صفر) با req_qty + قیمت/مبلغ
 *    - ✅ دکمه کوچک + و - در موبایل (برای qty)
 *    - ✅ دکمه "فلش/آیکون" برای pending: انتقال req_qty به qty (درخواست شما)
 * ========================================================= */

add_shortcode('qof_orders', function($atts){
    if ( ! function_exists('wc_get_orders') ) {
        return '<div style="color:#b91c1c">WooCommerce فعال نیست.</div>';
    }

    qof_enqueue_front_assets();

    $atts = shortcode_atts([
        'code'     => '',
        'per_page' => '100',
        'status'   => '',
    ], $atts, 'qof_orders');

    $url_code = '';
    if (isset($_GET['code']))   $url_code = sanitize_text_field(wp_unslash($_GET['code']));
    if (isset($_GET['key']))    $url_code = sanitize_text_field(wp_unslash($_GET['key']));
    if (isset($_GET['seller'])) $url_code = sanitize_text_field(wp_unslash($_GET['seller']));

    $seller_code = $url_code !== '' ? $url_code : (string)$atts['code'];
    $seller_code = trim($seller_code);

    if ($seller_code === '') {
        return '<div style="color:#b91c1c">کد فروشنده مشخص نشده است.</div>';
    }

    $statuses = [];
    if (!empty($atts['status'])) {
        $statuses = array_filter(array_map('trim', explode(',', (string)$atts['status'])));
    } else {
        $statuses = ['pending','processing','on-hold','completed','cancelled','failed','refunded'];
    }

    $per_page = max(1, intval($atts['per_page']));

    $orders = wc_get_orders([
        'type'       => 'shop_order',
        'status'     => $statuses,
        'limit'      => $per_page,
        'orderby'    => 'date',
        'order'      => 'DESC',
        'return'     => 'objects',
        'meta_query' => [
            [
                'key'   => '_wc_qof_user_code',
                'value' => $seller_code,
            ]
        ],
    ]);

    if (is_array($orders) && !empty($orders)) {
        usort($orders, function($a, $b){
            if ( ! $a instanceof WC_Order || ! $b instanceof WC_Order ) return 0;
            $a_hold = ((string) $a->get_status() === 'on-hold');
            $b_hold = ((string) $b->get_status() === 'on-hold');
            if ($a_hold !== $b_hold) return $a_hold ? -1 : 1;
            $a_date = $a->get_date_created();
            $b_date = $b->get_date_created();
            $a_ts   = $a_date ? $a_date->getTimestamp() : 0;
            $b_ts   = $b_date ? $b_date->getTimestamp() : 0;
            return $b_ts <=> $a_ts;
        });
    }

    $nonce      = wp_create_nonce('qof_orders_nonce');
    $edit_nonce = wp_create_nonce('qof_orders_edit_nonce');

    // product dataset for edit UI
    $products = qof_get_cached_products();
    $bucketed = [];
    foreach ($products as $p){
        if(!$p) continue;
        $stock = $p->get_stock_quantity();
        if ($stock === null) $stock = 0;

        // ✅ قیمت
        $price = 0;
        if ($p) {
            $rawp = $p->get_price();
            $price = ($rawp !== '' && $rawp !== null) ? (float)$rawp : 0;
        }

        $row = ['id'=>$p->get_id(),'label'=>qof_product_label($p),'stock'=>(int)$stock,'price'=>$price];
        $cap = qof_capacity_from_product($p);
        $bucketed[$cap][] = $row;
    }
    foreach ($bucketed as $cap => &$list) usort($list, fn($a,$b)=>strcasecmp($a['label'],$b['label']));
    unset($list);

    $edit_name_select_html = ''; $edit_id_select_html = '';
    $edit_all_products = []; $rendered_caps = []; $preferred_order = [4,6,8,12,0];

    foreach ($preferred_order as $cap) {
        if (!isset($bucketed[$cap])) continue;
        $group_label = $cap > 0 ? ($cap.' نفره') : 'سایر';
        $edit_name_select_html .= '<optgroup label="'.esc_attr($group_label).'">';
        $edit_id_select_html   .= '<optgroup label="'.esc_attr($group_label).'">';
        foreach ($bucketed[$cap] as $row) {
            $opt_text_name = $row['label'] . ' — [موجودی: ' . $row['stock'] . ']';
            $opt_text_id   = $row['id'] . ' — ' . $row['label'] . ' — [موجودی: ' . $row['stock'] . ']';
            $edit_name_select_html .= '<option value="'.esc_attr($row['id']).'" data-stock="'.esc_attr($row['stock']).'" data-price="'.esc_attr($row['price']).'">'.esc_html($opt_text_name).'</option>';
            $edit_id_select_html   .= '<option value="'.esc_attr($row['id']).'" data-stock="'.esc_attr($row['stock']).'" data-price="'.esc_attr($row['price']).'">'.esc_html($opt_text_id).'</option>';
            $edit_all_products[] = $row;
        }
        $edit_name_select_html .= '</optgroup>';
        $edit_id_select_html   .= '</optgroup>';
        $rendered_caps[$cap] = true;
    }

    $others = [];
    foreach ($bucketed as $cap => $list) {
        if (isset($rendered_caps[$cap])) continue;
        foreach ($list as $row) $others[] = $row;
    }
    usort($others, fn($a,$b)=>strcasecmp($a['label'],$b['label']));
    if (!empty($others)) {
        $edit_name_select_html .= '<optgroup label="'.esc_attr('سایر').'">';
        $edit_id_select_html   .= '<optgroup label="'.esc_attr('سایر').'">';
        foreach ($others as $row) {
            $opt_text_name = $row['label'] . ' — [موجودی: ' . $row['stock'] . ']';
            $opt_text_id   = $row['id'] . ' — ' . $row['label'] . ' — [موجودی: ' . $row['stock'] . ']';
            $edit_name_select_html .= '<option value="'.esc_attr($row['id']).'" data-stock="'.esc_attr($row['stock']).'" data-price="'.esc_attr($row['price']).'">'.esc_html($opt_text_name).'</option>';
            $edit_id_select_html   .= '<option value="'.esc_attr($row['id']).'" data-stock="'.esc_attr($row['stock']).'" data-price="'.esc_attr($row['price']).'">'.esc_html($opt_text_id).'</option>';
            $edit_all_products[] = $row;
        }
        $edit_name_select_html .= '</optgroup>';
        $edit_id_select_html   .= '</optgroup>';
    }

    ob_start(); ?>
    <style>
      .qof-orders-wrap{direction:rtl}
      .qof-table{width:100%;border-collapse:collapse;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden}
      .qof-table th,.qof-table td{padding:10px;border-top:1px solid #e5e7eb;vertical-align:top}
      .qof-table thead th{background:#f3f4f6;border-top:none;text-align:right}
      .qof-order-link{color:#2563eb;cursor:pointer;text-decoration:underline}
      .qof-details{background:#f9fafb;padding:8px 12px;border:1px dashed #d1d5db;border-radius:8px;margin-top:6px;display:none}
      .qof-badge{display:inline-block;font-size:12px;padding:3px 8px;border:1px solid #d1d5db;border-radius:999px;background:#fff}
      .qof-btn{cursor:pointer;border-radius:10px;padding:8px 12px;border:1px solid #2563eb;background:#2563eb;color:#fff;font-size:13px}
      .qof-btn.qof-btn-secondary{background:#fff;color:#2563eb}
      .qof-btn:disabled{opacity:.6;cursor:not-allowed}
      .qof-muted{opacity:.7}
      .qof-input{border:1px solid #d1d5db;border-radius:10px;padding:6px 10px;font-size:13px}
      .qof-edit-box{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:10px;margin-top:10px}
      .qof-edit-box table{width:100%;border-collapse:collapse}
      .qof-edit-box th,.qof-edit-box td{padding:6px;border-bottom:1px solid #f3f4f6;vertical-align:middle}
      .qof-edit-box th{text-align:right;background:#f9fafb}
      .qof-edit-row-muted{opacity:.6}
      .qof-btn.qof-btn-final{border:1px solid #2563eb;background:#2563eb;color:#fff}
      .qof-btn.qof-btn-hold{border:1px solid #f59e0b;background:#fffbeb;color:#92400e;font-weight:700}
      .qof-edit-mini{display:none}
      @media (max-width: 768px){
        .qof-table th,.qof-table td{font-size:13px}
        .qof-details{font-size:13px}
        .qof-edit-mini{display:inline-flex}
      }
    </style>

    <div class="qof-orders-wrap" data-nonce="<?php echo esc_attr($nonce); ?>"
         data-edit-nonce="<?php echo esc_attr($edit_nonce); ?>"
         data-seller="<?php echo esc_attr($seller_code); ?>">
      <div style="margin:8px 0 12px">
        <span class="qof-badge">کد فروشنده: <strong><?php echo esc_html($seller_code); ?></strong></span>
        <span class="qof-muted" style="margin-inline-start:10px">نمایش عمومی سفارش‌های ثبت‌شده با همین کد</span>
      </div>

      <?php if (empty($orders)) : ?>
        <div style="color:#374151;background:#f3f4f6;border:1px solid #e5e7eb;padding:10px;border-radius:8px">هیچ سفارشی یافت نشد.</div>
      <?php else: ?>
      <div class="qof-table-wrap" style="border:1px solid #e5e7eb;border-radius:10px;overflow:hidden">
        <table class="qof-table">
          <thead>
            <tr>
              <th style="width:120px">شماره سفارش</th>
              <th style="width:120px">وضعیت</th>
              <th style="width:160px">تاریخ</th>
              <th style="width:180px">نام مشتری</th>
              <th style="width:140px">موبایل</th>
              <th>آدرس</th>
              <th style="width:140px">جمع کل</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($orders as $order):
              $oid   = $order->get_id();
              $status= wc_get_order_status_name( $order->get_status() );
              $date  = $order->get_date_created() ? $order->get_date_created()->date_i18n('Y/m/d H:i') : '';
              $bn    = trim(($order->get_billing_first_name().' '.$order->get_billing_last_name()));
              $phone = $order->get_billing_phone();
              $addr1 = $order->get_billing_address_1();
              $total = $order->get_formatted_order_total();
          ?>
            <tr>
              <td>
                <a class="qof-order-link" data-oid="<?php echo esc_attr($oid); ?>" href="javascript:void(0)">#<?php echo esc_html($oid); ?></a>
                <div id="qof-details-<?php echo esc_attr($oid); ?>" class="qof-details"></div>
              </td>
              <td class="qof-status-cell" data-oid="<?php echo esc_attr($oid); ?>" data-status="<?php echo esc_attr($order->get_status()); ?>"><?php echo esc_html($status); ?></td>
              <td><?php echo esc_html($date); ?></td>
              <td><?php echo esc_html($bn ?: '—'); ?></td>
              <td><?php echo esc_html($phone ?: '—'); ?></td>
              <td><?php echo esc_html($addr1 ?: '—'); ?></td>
              <td><?php echo wp_kses_post($total); ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <script>
    (function(){
      const wrap = document.querySelector('.qof-orders-wrap');
      if(!wrap) return;

      const nonce = wrap.getAttribute('data-nonce');
      const editNonce = wrap.getAttribute('data-edit-nonce');
      const seller = wrap.getAttribute('data-seller');
      const ajaxUrl = '<?php echo esc_js(admin_url("admin-ajax.php")); ?>';
      const allProducts = <?php echo wp_json_encode($edit_all_products); ?>;
      const editSelectHtmlById = <?php echo wp_json_encode($edit_id_select_html); ?>;
      const editSelectHtmlByName = <?php echo wp_json_encode($edit_name_select_html); ?>;

      function escapeHtml(str){
        return String(str == null ? '' : str)
          .replace(/&/g,'&amp;')
          .replace(/</g,'&lt;')
          .replace(/>/g,'&gt;')
          .replace(/\"/g,'&quot;')
          .replace(/'/g,'&#039;');
      }
      function parseIntSafe(v, def){
        const n = parseInt(String(v == null ? '' : v), 10);
        return Number.isFinite(n) ? n : def;
      }
      function parseFloatSafe(v, def){
        const n = parseFloat(String(v == null ? '' : v));
        return Number.isFinite(n) ? n : def;
      }

      function nextStatusFrom(slug){
        if (slug === 'processing') return 'on-hold';
        if (slug === 'on-hold') return 'processing';
        return '';
      }
      function toggleBtnTextByCurrent(slug){
        if (slug === 'processing') return 'تغییر به در انتظار';
        if (slug === 'on-hold') return 'تغییر به در حال انجام';
        return '';
      }
      function loadingTpl(){ return '<div style="padding:6px 2px">در حال دریافت جزییات...</div>'; }

      // dataset helpers
      function findById(id){ return allProducts.find(p => String(p.id) === String(id)); }
      function findLabelById(id){ const f = findById(id); return f ? f.label : ''; }
      function baseStockById(id){ const f = findById(id); return f ? (f.stock||0) : 0; }
      function priceById(id){ const f = findById(id); return f ? (parseFloat(f.price||0) || 0) : 0; }

      function showEditMsg(editBox, msg, ok){
        const el = editBox ? editBox.querySelector('.qof-edit-msg') : null;
        if (!el) return;
        el.style.display = 'block';
        el.style.borderColor = ok ? '#16a34a' : '#991b1b';
        el.style.background = ok ? '#ecfdf5' : '#fef2f2';
        el.style.color = ok ? '#065f46' : '#991b1b';
        el.textContent = msg || '';
      }

      function detailsTpl(data){
        if(!data || !data.items) return '<div style="color:#991b1b">خطا در دریافت اطلاعات.</div>';

        let html = '';
        const statusSlug = data.status_slug || '';
        const statusName = data.status_name || '';
        const canEdit = (statusSlug === 'on-hold');
        const hasZeroQty = !!data.has_zero_qty;

        const nextSlug = nextStatusFrom(statusSlug);
        const btnText = toggleBtnTextByCurrent(statusSlug);

        html += '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:6px 0 10px">';
        if (statusName) html += '<span class="qof-badge">وضعیت: <strong class="qof-status-name" data-oid="'+escapeHtml(data.order_id||'')+'">'+escapeHtml(statusName)+'</strong></span>';

        if (nextSlug && btnText) {
          if (nextSlug === 'processing' && hasZeroQty) {
            html += '<button type="button" class="qof-btn" disabled>'+escapeHtml(btnText)+'</button>';
            html += '<span class="qof-muted">تا زمانی که آیتم با تعداد ۰ وجود دارد، امکان تغییر به پردازش نیست.</span>';
          } else {
            html += '<button type="button" class="qof-btn qof-toggle-status" data-oid="'+escapeHtml(data.order_id||'')+'">'+escapeHtml(btnText)+'</button>';
          }
        }

        if (canEdit) html += '<button type="button" class="qof-btn qof-btn-secondary qof-edit-order" data-oid="'+escapeHtml(data.order_id||'')+'">ویرایش سفارش</button>';
        html += '</div>';

        // customer block (نمایش)
        html += '<div style="margin:6px 0 10px; display:grid; gap:6px">';
        html += '<div class="qof-badge">نام مشتری: <strong>' + escapeHtml(data.customer_name || '—') + '</strong></div>';
        html += '<div class="qof-badge">موبایل: <strong>' + escapeHtml(data.customer_phone || '—') + '</strong></div>';
        html += '<div class="qof-badge">آدرس: <strong>' + escapeHtml(data.customer_address || '—') + '</strong></div>';
        if (data.delivery_type) {
          html += '<div class="qof-badge">نحوه تحویل: <strong>' + (data.delivery_type==='branch'?'تحویل شعبه تهرانپارس':'ارسال درب منزل') + '</strong></div>';
        }
        html += '</div>';

        html += '<div style="margin:4px 0 8px; font-weight:600">اقلام سفارش</div>';
        html += '<div style="overflow:auto"><table style="width:100%;border-collapse:collapse">';
        html += '<thead><tr><th style="text-align:center;border-bottom:1px solid #e5e7eb;padding:6px;width:40px">#</th><th style="text-align:right;border-bottom:1px solid #e5e7eb;padding:6px">محصول</th><th style="text-align:center;border-bottom:1px solid #e5e7eb;padding:6px;width:90px">تعداد</th><th style="text-align:center;border-bottom:1px solid #e5e7eb;padding:6px;width:120px">جمع جزء</th><th style="text-align:center;border-bottom:1px solid #e5e7eb;padding:6px;width:120px">مبلغ خط</th></tr></thead><tbody>';
        data.items.forEach(function(it, i){
          const rq = parseInt(it.req_qty || 0, 10);
          const pendingTxt = (parseInt(it.qty||0,10)===0 && rq>0)
            ? ('<div class="qof-muted" style="font-size:12px;margin-top:2px">تعداد درخواستی: '+rq+' | قیمت واحد: '+escapeHtml(String(it.pending_price||0))+'</div>')
            : '';
          html += '<tr><td style="padding:6px;text-align:center;border-bottom:1px solid #f3f4f6">'+String(i+1)+'</td><td style="padding:6px;border-bottom:1px solid #f3f4f6">'+escapeHtml(it.name||'')+pendingTxt+'</td><td style="padding:6px;text-align:center;border-bottom:1px solid #f3f4f6">'+escapeHtml(it.qty||0)+'</td><td style="padding:6px;text-align:center;border-bottom:1px solid #f3f4f6">'+(it.subtotal||'')+'</td><td style="padding:6px;text-align:center;border-bottom:1px solid #f3f4f6">'+(it.total||'')+'</td></tr>';
        });
        html += '</tbody></table></div>';

        if (canEdit) {
          html += '<div class="qof-edit-box" data-oid="'+escapeHtml(data.order_id||'')+'" style="display:none">';
          html += '<div class="qof-muted" style="margin:2px 0 10px">در ویرایش: عدد ۰ یعنی «در انتظار». فقط دکمه حذف، حذف واقعی انجام می‌دهد.</div>';
          html += '<div class="qof-edit-msg" style="display:none;margin:6px 0;padding:8px;border-radius:10px;border:1px solid #e5e7eb;background:#f9fafb"></div>';

          // ✅ ویرایش اطلاعات مشتری/آدرس/موبایل
          html += '<div style="display:grid;gap:8px;margin:8px 0 12px;padding:10px;border:1px dashed #e5e7eb;border-radius:10px;background:#f9fafb">';
          html += '<div style="display:flex;gap:10px;flex-wrap:wrap">';
          html +=   '<div style="flex:1;min-width:220px"><label class="qof-muted" style="display:block;margin-bottom:4px">نام و نام‌خانوادگی</label><input type="text" class="qof-input qof-edit-cust-name" value="'+escapeHtml(data.customer_name||'')+'" style="width:100%"></div>';
          html +=   '<div style="flex:1;min-width:200px"><label class="qof-muted" style="display:block;margin-bottom:4px">شماره موبایل</label><input type="text" class="qof-input qof-edit-cust-phone" value="'+escapeHtml(data.customer_phone||'')+'" style="width:100%"></div>';
          html += '</div>';
          html += '<div><label class="qof-muted" style="display:block;margin-bottom:4px">آدرس</label><textarea class="qof-input qof-edit-cust-addr" style="width:100%;min-height:70px">'+escapeHtml(data.customer_address||'')+'</textarea></div>';

          // delivery (فقط اگر سفارش Tehranpars باشد)
          if (data.is_tehranpars) {
            const dt = data.delivery_type || 'home';
            html += '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">';
            html += '<span style="font-weight:700">نحوه تحویل:</span>';
            html += '<label style="display:flex;align-items:center;gap:6px"><input type="radio" name="qof_edit_delivery_'+escapeHtml(data.order_id||'')+'" value="home" '+(dt==='home'?'checked':'')+'>ارسال درب منزل</label>';
            html += '<label style="display:flex;align-items:center;gap:6px"><input type="radio" name="qof_edit_delivery_'+escapeHtml(data.order_id||'')+'" value="branch" '+(dt==='branch'?'checked':'')+'>تحویل شعبه تهرانپارس</label>';
            html += '</div>';
          }
          html += '</div>';

          html += '<div style="overflow:auto"><table><thead><tr><th style="width:40px;text-align:center">#</th><th>محصول</th><th style="width:90px;text-align:center">موجودی</th><th style="width:210px;text-align:center">تعداد</th><th style="width:110px;text-align:center">حذف</th></tr></thead><tbody class="qof-edit-tbody">';

          data.items.forEach(function(it, i){
            const pid = (it && (it.id || it.id === 0)) ? String(it.id) : '';
            const stock = (it && (it.stock || it.stock === 0)) ? String(it.stock) : '-';
            const qty = (it && (it.qty || it.qty === 0)) ? String(it.qty) : '0';
            const rq = (it && (it.req_qty || it.req_qty === 0)) ? String(it.req_qty) : '0';
            const pr = (it && (it.pending_price || it.pending_price === 0)) ? String(it.pending_price) : '0';

            const rqNum = parseIntSafe(rq, 0);
            const isPending = (parseIntSafe(qty,0)===0 && rqNum>0);

            const rqTxt = isPending
              ? ('<div class="qof-muted" style="font-size:12px;margin-top:2px">تعداد درخواستی: '+escapeHtml(rq)+' | قیمت واحد: '+escapeHtml(pr)+'</div>')
              : '';

            // ✅ دکمه آیکون برای pending: req_qty -> qty
            const moveBtn = isPending
              ? ('<button type="button" class="qof-btn qof-btn-secondary qof-pending-move" title="انتقال تعداد درخواستی به شمارنده" style="padding:6px 10px">↩️</button>')
              : '';

            html += '<tr class="qof-edit-row" data-pid="'+escapeHtml(pid)+'" data-orig="'+escapeHtml(qty)+'" data-deleted="0" data-req="'+escapeHtml(rq)+'" data-price="'+escapeHtml(pr)+'">';
            html += '<td style="text-align:center">'+String(i+1)+'</td>';
            html += '<td>'+escapeHtml(it.name||'')+rqTxt+'</td>';
            html += '<td style="text-align:center"><span class="qof-edit-stock">'+escapeHtml(stock)+'</span></td>';
            html += '<td style="text-align:center;display:flex;gap:6px;align-items:center;justify-content:center;flex-wrap:wrap">';
            html +=   '<button type="button" class="qof-btn qof-btn-secondary qof-edit-dec" style="padding:6px 10px;background:#fee2e2;border-color:#ef4444;color:#991b1b">➖</button>';
            html +=   '<button type="button" class="qof-btn qof-btn-secondary qof-edit-dec-mini qof-edit-mini" style="padding:6px 10px;background:#fee2e2;border-color:#ef4444;color:#991b1b">-</button>';
            html +=   '<input type="number" min="0" class="qof-input qof-edit-qty" value="'+escapeHtml(qty)+'" style="width:90px;text-align:center" '+(isPending?'disabled':'')+'>';
            html +=   '<button type="button" class="qof-btn qof-btn-secondary qof-edit-inc-mini qof-edit-mini" style="padding:6px 10px;background:#dcfce7;border-color:#16a34a;color:#065f46">+</button>';
            html +=   '<button type="button" class="qof-btn qof-btn-secondary qof-edit-inc" style="padding:6px 10px;background:#dcfce7;border-color:#16a34a;color:#065f46">➕</button>';
            html +=   moveBtn;
            html += '</td>';
            html += '<td style="text-align:center"><button type="button" class="qof-btn qof-btn-secondary qof-edit-remove">حذف</button></td>';
            html += '</tr>';
          });

          html += '</tbody></table></div>';

          html += '<div class="qof-edit-stock-line qof-muted" style="margin-top:8px;display:none"></div>';

          // ✅ بخش افزودن محصول (select2 سرچ) حذف نشده
          html += '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px">';
          html += '<select class="qof-edit-sel-id" style="min-width:220px;max-width:260px"><option value="">انتخاب ID</option>' + (editSelectHtmlById || '') + '</select>';
          html += '<select class="qof-edit-sel-name" style="min-width:260px;max-width:520px"><option value="">انتخاب محصول</option>' + (editSelectHtmlByName || '') + '</select>';
          html += '<input type="number" min="1" class="qof-input qof-edit-add-qty" value="1" style="width:100px;text-align:center">';
          html += '<button type="button" class="qof-btn qof-edit-add">افزودن</button>';
          html += '<button type="button" class="qof-btn qof-btn-final qof-edit-save-final" data-oid="'+escapeHtml(data.order_id||'')+'" '+(hasZeroQty?'disabled':'')+'>ذخیره و ثبت نهایی</button>';
          html += '<button type="button" class="qof-btn qof-btn-hold qof-edit-save-hold" data-oid="'+escapeHtml(data.order_id||'')+'">ذخیره و در انتظار</button>';
          html += '<button type="button" class="qof-btn qof-btn-secondary qof-back-orders">لیست سفارشات</button>';
          html += '</div>';

          html += '</div>';
        }

        if (data.totals_html) html += '<div style="margin-top:8px"><span style="font-weight:600">جمع کل:</span> '+data.totals_html+'</div>';
        if (data.pending_total_view) html += '<div class="qof-muted" style="margin-top:6px">جمع مبلغ اقلام ناموجود: '+escapeHtml(data.pending_total_view)+'</div>';

        return html;
      }

      // baseline-aware helpers (برای افزایش تا سقف موجودی + مقدار قبلی سفارش)
      function sumEditQty(editBox, id){
        const rows = editBox ? editBox.querySelectorAll('.qof-edit-row') : [];
        let sum = 0;
        rows.forEach(function(row){
          if (String(row.getAttribute('data-pid')) !== String(id)) return;
          if (row.getAttribute('data-deleted') === '1') return;
          const qtyEl = row.querySelector('.qof-edit-qty');
          sum += Math.max(0, parseIntSafe(qtyEl ? qtyEl.value : 0, 0));
        });
        return sum;
      }
      function sumEditOrigQty(editBox, id){
        const rows = editBox ? editBox.querySelectorAll('.qof-edit-row') : [];
        let sum = 0;
        rows.forEach(function(row){
          if (String(row.getAttribute('data-pid')) !== String(id)) return;
          if (row.getAttribute('data-deleted') === '1') return;
          sum += Math.max(0, parseIntSafe(row.getAttribute('data-orig'), 0));
        });
        return sum;
      }
      function availableStock(editBox, id){
        return Math.max(0, baseStockById(id) + sumEditOrigQty(editBox, id) - sumEditQty(editBox, id));
      }

      function getSelectedId(editBox){
        if (!editBox) return '';
        const selId = editBox.querySelector('.qof-edit-sel-id');
        const selName = editBox.querySelector('.qof-edit-sel-name');
        return (selId && selId.value) ? selId.value : ((selName && selName.value) ? selName.value : '');
      }

      function updateEditStockBadge(editBox){
        if (!editBox) return;
        const line = editBox.querySelector('.qof-edit-stock-line');
        if (!line) return;
        const pid = getSelectedId(editBox);
        if (!pid) { line.style.display = 'none'; line.textContent = ''; return; }
        const av = availableStock(editBox, pid);
        line.style.display = 'block';
        line.textContent = 'موجودی قابل انتخاب: ' + String(av);
      }

      function initEditSelect(editBox){
        const selId = editBox ? editBox.querySelector('.qof-edit-sel-id') : null;
        const selName = editBox ? editBox.querySelector('.qof-edit-sel-name') : null;
        if (!selId || !selName || editBox.getAttribute('data-inited') === '1') return;
        editBox.setAttribute('data-inited', '1');

        const $ = window.jQuery;
        const hasSelect = $ && $.fn && ($.fn.selectWoo || $.fn.select2);
        if (!hasSelect) { updateEditStockBadge(editBox); return; }

        function initSelect(el){
          const $el = $(el);
          const opts = { placeholder: '', allowClear: true, width: 'resolve', dir:'rtl' };
          if ($.fn.selectWoo) $el.selectWoo(opts); else $el.select2(opts);
        }
        initSelect(selId); initSelect(selName);

        let syncing = false;
        function sync(fromEl, toEl){
          if (syncing) return;
          syncing = true;
          const v = fromEl.value || '';
          $(toEl).val(v).trigger('change.select2');
          syncing = false;
          updateEditStockBadge(editBox);
        }
        selId.addEventListener('change', function(){ sync(selId, selName); });
        selName.addEventListener('change', function(){ sync(selName, selId); });

        editBox.addEventListener('input', function(ev){
          const t = ev && ev.target ? ev.target : null;
          if (t && t.classList && t.classList.contains('qof-edit-qty')) updateEditStockBadge(editBox);
        });

        updateEditStockBadge(editBox);
      }

      function upsertEditRow(editBox, pid, name, stock, addQty, reqQty){
        const tbody = editBox ? editBox.querySelector('.qof-edit-tbody') : null;
        if (!tbody) return;

        const pidStr = String(pid);
        const existing = tbody.querySelector('.qof-edit-row[data-pid="'+pidStr.replace(/"/g,'')+'"]');
        if (existing) {
          existing.setAttribute('data-deleted','0');
          existing.classList.remove('qof-edit-row-muted');

          const qtyEl = existing.querySelector('.qof-edit-qty');
          const baseStock = baseStockById(pid);

          if (baseStock === 0) {
            existing.setAttribute('data-req', String(reqQty||0));
            existing.setAttribute('data-price', String(priceById(pid)||0));
            if (qtyEl) { qtyEl.value = '0'; qtyEl.setAttribute('disabled','disabled'); }
          } else {
            if (qtyEl) qtyEl.value = String(Math.max(0, parseIntSafe(qtyEl.value, 0) + addQty));
          }
          return;
        }

        const safeName = escapeHtml(name || ('#' + pidStr));
        const safeStock = escapeHtml((stock || stock === 0) ? String(stock) : '-');
        const rq = Math.max(0, parseIntSafe(reqQty, 0));
        const pr = Math.max(0, parseFloatSafe(priceById(pid), 0));
        const rqTxt = (addQty===0 && rq>0) ? ('<div class="qof-muted" style="font-size:12px;margin-top:2px">تعداد درخواستی: '+escapeHtml(String(rq))+' | قیمت واحد: '+escapeHtml(String(pr))+'</div>') : '';
        const disableQty = (addQty===0 && rq>0) ? 'disabled' : '';

        const rowHtml =
          '<tr class="qof-edit-row" data-pid="'+escapeHtml(pidStr)+'" data-orig="0" data-deleted="0" data-req="'+escapeHtml(String(rq))+'" data-price="'+escapeHtml(String(pr))+'">' +
            '<td style="text-align:center">—</td>' +
            '<td>' + safeName + rqTxt + '</td>' +
            '<td style="text-align:center"><span class="qof-edit-stock">' + safeStock + '</span></td>' +
            '<td style="text-align:center;display:flex;gap:6px;align-items:center;justify-content:center;flex-wrap:wrap">' +
              '<button type="button" class="qof-btn qof-btn-secondary qof-edit-dec" style="padding:6px 10px;background:#fee2e2;border-color:#ef4444;color:#991b1b">➖</button>' +
              '<button type="button" class="qof-btn qof-btn-secondary qof-edit-dec-mini qof-edit-mini" style="padding:6px 10px;background:#fee2e2;border-color:#ef4444;color:#991b1b">-</button>' +
              '<input type="number" min="0" class="qof-input qof-edit-qty" value="'+(addQty===0?'0':escapeHtml(String(addQty)))+'" style="width:90px;text-align:center" '+disableQty+'>' +
              '<button type="button" class="qof-btn qof-btn-secondary qof-edit-inc-mini qof-edit-mini" style="padding:6px 10px;background:#dcfce7;border-color:#16a34a;color:#065f46">+</button>' +
              '<button type="button" class="qof-btn qof-btn-secondary qof-edit-inc" style="padding:6px 10px;background:#dcfce7;border-color:#16a34a;color:#065f46">➕</button>' +
              ((addQty===0 && rq>0) ? '<button type="button" class="qof-btn qof-btn-secondary qof-pending-move" title="انتقال تعداد درخواستی به شمارنده" style="padding:6px 10px">↩️</button>' : '') +
            '</td>' +
            '<td style="text-align:center"><button type="button" class="qof-btn qof-btn-secondary qof-edit-remove">حذف</button></td>' +
          '</tr>';
        tbody.insertAdjacentHTML('beforeend', rowHtml);

        const rows = tbody.querySelectorAll('.qof-edit-row');
        rows.forEach(function(r, i){ const c = r.querySelector('td'); if (c) c.textContent = String(i+1); });
      }

      function collectEditItems(editBox){
        const rows = editBox ? editBox.querySelectorAll('.qof-edit-row') : [];
        const items = [];
        rows.forEach(function(row){
          const pid = parseIntSafe(row.getAttribute('data-pid'), 0);
          const qtyEl = row.querySelector('.qof-edit-qty');
          const qty = qtyEl ? parseIntSafe(qtyEl.value, 0) : 0;
          const deleted = (row.getAttribute('data-deleted') === '1') ? 1 : 0;
          const reqQty = parseIntSafe(row.getAttribute('data-req'), 0);
          const price = parseFloatSafe(row.getAttribute('data-price'), 0);

          if (pid > 0) items.push({
            product_id: pid,
            qty: Math.max(0, qty),
            deleted: deleted,
            req_qty: Math.max(0, reqQty),
            price: Math.max(0, price),
          });
        });

        // ✅ اطلاعات مشتری
        const custName = editBox.querySelector('.qof-edit-cust-name');
        const custPhone = editBox.querySelector('.qof-edit-cust-phone');
        const custAddr = editBox.querySelector('.qof-edit-cust-addr');

        const payload = {
          items: items,
          customer: {
            name: custName ? String(custName.value||'').trim() : '',
            phone: custPhone ? String(custPhone.value||'').trim() : '',
            address: custAddr ? String(custAddr.value||'').trim() : '',
          }
        };

        // delivery
        const oid = editBox.getAttribute('data-oid');
        if (oid) {
          const r = editBox.querySelector('input[name="qof_edit_delivery_'+oid+'"]:checked');
          if (r) payload.customer.delivery_type = String(r.value||'').trim();
        }

        return payload;
      }

      wrap.addEventListener('click', function(e){
        const backBtn = e.target.closest('.qof-back-orders');
        if (backBtn) {
          e.preventDefault();
          const details = e.target.closest('.qof-details');
          if (details) details.style.display = 'none';
          window.scrollTo({top: 0, behavior: 'smooth'});
          return;
        }

        const editBtn = e.target.closest('.qof-edit-order');
        if (editBtn) {
          const oid = editBtn.getAttribute('data-oid');
          if (!oid) return;
          const detailsBox = document.getElementById('qof-details-' + oid);
          const editBox = detailsBox ? detailsBox.querySelector('.qof-edit-box') : null;
          if (!editBox) return;
          const isOpen = editBox.style.display !== 'none' && editBox.style.display !== '';
          editBox.style.display = isOpen ? 'none' : 'block';
          if (!isOpen) initEditSelect(editBox);
          return;
        }

        // ✅ pending move: req_qty -> qty
        const moveBtn = e.target.closest('.qof-pending-move');
        if (moveBtn) {
          const editBox = e.target.closest('.qof-edit-box');
          const row = e.target.closest('.qof-edit-row');
          if (!row) return;

          const pid = row.getAttribute('data-pid');
          const reqQty = parseIntSafe(row.getAttribute('data-req'), 0);
          if (!pid || reqQty <= 0) { if(editBox) showEditMsg(editBox,'تعداد درخواستی برای انتقال وجود ندارد.', false); return; }

          // اگر موجودی فعلی اجازه دهد:
          const av = availableStock(editBox, pid);
          if (reqQty > av) {
            if(editBox) showEditMsg(editBox, 'موجودی کافی نیست. موجودی قابل انتخاب: '+String(av), false);
            return;
          }

          // qty فعال و برابر reqQty
          row.setAttribute('data-req','0');
          const qtyEl = row.querySelector('.qof-edit-qty');
          if (qtyEl) {
            qtyEl.removeAttribute('disabled');
            qtyEl.value = String(reqQty);
          }

          if(editBox) showEditMsg(editBox, 'تعداد درخواستی به شمارنده منتقل شد. برای اعمال نهایی، ذخیره را بزنید.', true);
          updateEditStockBadge(editBox);

          // دکمه ↩️ را مخفی کن
          moveBtn.style.display = 'none';
          return;
        }

        const removeBtn = e.target.closest('.qof-edit-remove');
        if (removeBtn) {
          const editBox = e.target.closest('.qof-edit-box');
          const row = e.target.closest('.qof-edit-row');
          if (!row) return;
          row.setAttribute('data-deleted','1');
          const qtyEl = row.querySelector('.qof-edit-qty');
          if (qtyEl) qtyEl.value = '0';
          row.classList.add('qof-edit-row-muted');
          if (editBox) showEditMsg(editBox, 'آیتم برای حذف واقعی علامت‌گذاری شد (ذخیره را بزنید).', true);
          if (editBox) updateEditStockBadge(editBox);
          return;
        }

        // qty +/- buttons
        const incBtn = e.target.closest('.qof-edit-inc, .qof-edit-inc-mini');
        if (incBtn) {
          const editBox = e.target.closest('.qof-edit-box');
          const row = e.target.closest('.qof-edit-row');
          if (!row) return;

          const qtyEl = row.querySelector('.qof-edit-qty');
          if (!qtyEl || qtyEl.disabled) return;

          const pid = row.getAttribute('data-pid');
          const cur = parseIntSafe(qtyEl.value, 0);
          const av = availableStock(editBox, pid);
          // در این لحظه، cur داخل sumEditQty حساب می‌شود، پس یک واحد افزایش باید در av لحاظ شود:
          if (1 > av) { showEditMsg(editBox,'موجودی کافی نیست.', false); return; }
          qtyEl.value = String(cur + 1);
          updateEditStockBadge(editBox);
          return;
        }

        const decBtn = e.target.closest('.qof-edit-dec, .qof-edit-dec-mini');
        if (decBtn) {
          const editBox = e.target.closest('.qof-edit-box');
          const row = e.target.closest('.qof-edit-row');
          if (!row) return;

          const qtyEl = row.querySelector('.qof-edit-qty');
          if (!qtyEl || qtyEl.disabled) return;

          const cur = parseIntSafe(qtyEl.value, 0);
          qtyEl.value = String(Math.max(0, cur - 1));
          updateEditStockBadge(editBox);
          return;
        }

        const addBtn = e.target.closest('.qof-edit-add');
        if (addBtn) {
          const editBox = e.target.closest('.qof-edit-box');
          if (!editBox) return;
          initEditSelect(editBox);

          const qtyAddEl = editBox.querySelector('.qof-edit-add-qty');
          let qtyAdd = parseIntSafe(qtyAddEl ? qtyAddEl.value : 1, 1);
          qtyAdd = Number.isFinite(qtyAdd) ? qtyAdd : 1;

          const pidStr = getSelectedId(editBox);
          const pid = parseIntSafe(pidStr, 0);
          if (!pid) { showEditMsg(editBox, 'ابتدا یک محصول انتخاب کنید.', false); return; }

          const baseStock = baseStockById(pid);

          // ✅ اگر موجودی ۰ است: pending با req_qty + price
          if (baseStock === 0) {
            const requested = Math.max(1, qtyAdd);
            const name = findLabelById(pid) || ('#' + String(pid));
            upsertEditRow(editBox, pid, name, baseStock, 0, requested);
            showEditMsg(editBox, 'این کالا به صورت «در انتظار (qty=0)» اضافه شد. تعداد درخواستی ثبت شد. ذخیره را بزنید.', true);
            updateEditStockBadge(editBox);
            return;
          }

          qtyAdd = Math.max(1, qtyAdd);
          const av = availableStock(editBox, pid);
          if (qtyAdd > av) { showEditMsg(editBox, 'موجودی کافی نیست. موجودی قابل انتخاب: ' + String(av), false); return; }

          const name = findLabelById(pid) || ('#' + String(pid));
          upsertEditRow(editBox, pid, name, baseStock, qtyAdd, 0);
          showEditMsg(editBox, 'آیتم اضافه شد (برای اعمال نهایی، ذخیره را بزنید).', true);
          updateEditStockBadge(editBox);

          // clear selects
          const selId = editBox.querySelector('.qof-edit-sel-id');
          const selName = editBox.querySelector('.qof-edit-sel-name');
          const $ = window.jQuery;
          if ($ && $.fn && ($.fn.selectWoo || $.fn.select2)) {
            if (selId) $(selId).val('').trigger('change.select2');
            if (selName) $(selName).val('').trigger('change.select2');
          } else {
            if (selId) selId.value = '';
            if (selName) selName.value = '';
          }
          updateEditStockBadge(editBox);
          return;
        }

        function doSave(e, mode){
          const btn = e.target.closest(mode==='final'?'.qof-edit-save-final':'.qof-edit-save-hold');
          const oid = btn ? btn.getAttribute('data-oid') : '';
          const editBox = e.target.closest('.qof-edit-box');
          if (!oid || !editBox || !btn) return false;

          const payload = collectEditItems(editBox);
          const items = payload.items || [];
          if (!items || !items.length) { showEditMsg(editBox, 'هیچ آیتمی برای ذخیره وجود ندارد.', false); return true; }

          btn.disabled = true;
          const oldText = btn.textContent;
          btn.textContent = 'در حال ذخیره...';

          const form = new FormData();
          form.append('action', 'qof_update_order_items');
          form.append('nonce',  editNonce);
          form.append('seller', seller);
          form.append('order_id', oid);
          form.append('items', JSON.stringify(items));
          form.append('customer', JSON.stringify(payload.customer || {}));
          form.append('save_mode', mode);

          fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body: form })
          .then(r => r.json())
          .then(res => {
            if (res && res.success) {
              const data = res.data || {};
              const orderData = data.order || data;

              const box = document.getElementById('qof-details-' + oid);
              if (box) { box.innerHTML = detailsTpl(orderData); box.setAttribute('data-loaded','1'); }

              const cell = wrap.querySelector('.qof-status-cell[data-oid="'+String(oid)+'"]');
              if (cell && orderData && orderData.status_name) cell.textContent = orderData.status_name;

              // update total in table
              const link = wrap.querySelector('.qof-order-link[data-oid="'+String(oid)+'"]');
              if (link && orderData && orderData.totals_html) {
                const tr = link.closest('tr');
                if (tr) {
                  const tds = tr.querySelectorAll('td');
                  const totalTd = tds && tds.length ? tds[tds.length - 1] : null;
                  if (totalTd) totalTd.innerHTML = orderData.totals_html;
                }
              }
            } else {
              const msg = (res && res.data && res.data.err) ? res.data.err : 'خطا در ذخیره تغییرات.';
              showEditMsg(editBox, msg, false);
            }
          })
          .catch(() => showEditMsg(editBox, 'خطا در ارتباط با سرور.', false))
          .finally(() => { btn.disabled = false; btn.textContent = oldText; });

          return true;
        }

        const saveFinalBtn = e.target.closest('.qof-edit-save-final');
        if (saveFinalBtn) { doSave(e, 'final'); return; }

        const saveHoldBtn = e.target.closest('.qof-edit-save-hold');
        if (saveHoldBtn) { doSave(e, 'hold'); return; }

        const btn = e.target.closest('.qof-toggle-status');
        if (btn) {
          const oid = btn.getAttribute('data-oid');
          if (!oid) return;
          btn.disabled = true;
          const oldText = btn.textContent;
          btn.textContent = 'در حال تغییر...';

          const form = new FormData();
          form.append('action', 'qof_toggle_order_status');
          form.append('nonce',  nonce);
          form.append('seller', seller);
          form.append('order_id', oid);

          fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body: form })
          .then(r => r.json())
          .then(res => {
            if(res && res.success){
              const data = res.data || {};
              const newName = data.status_name || '';
              const newSlug = data.status_slug || '';

              const box = document.getElementById('qof-details-'+oid);
              if (box) {
                const statusEl = box.querySelector('.qof-status-name');
                if (statusEl && newName) statusEl.textContent = newName;
                const nextText = toggleBtnTextByCurrent(newSlug);
                if (nextText) btn.textContent = nextText; else btn.remove();
              }

              const cell = wrap.querySelector('.qof-status-cell[data-oid="'+String(oid)+'"]');
              if (cell) {
                if (newName) cell.textContent = newName;
                if (newSlug) cell.setAttribute('data-status', newSlug);

                // refresh details
                if (box) {
                  const form2 = new FormData();
                  form2.append('action', 'qof_get_order_details');
                  form2.append('nonce',  nonce);
                  form2.append('seller', seller);
                  form2.append('order_id', oid);
                  fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body: form2 })
                  .then(r2 => r2.json())
                  .then(res2 => { if (res2 && res2.success) { box.innerHTML = detailsTpl(res2.data || {}); box.setAttribute('data-loaded','1'); } })
                  .catch(() => {});
                }
              }
            } else {
              const msg = (res && res.data && res.data.err) ? res.data.err : 'خطا در تغییر وضعیت.';
              alert(msg);
            }
          })
          .catch(() => alert('خطا در ارتباط با سرور.'))
          .finally(() => { btn.disabled = false; if (btn.textContent === 'در حال تغییر...') btn.textContent = oldText; });
          return;
        }

        const a = e.target.closest('.qof-order-link');
        if(!a) return;
        const oid = a.getAttribute('data-oid');
        const box = document.getElementById('qof-details-'+oid);
        if(!box) return;

        if (box.getAttribute('data-loaded') === '1'){
          box.style.display = (box.style.display === 'none' || box.style.display === '') ? 'block' : 'none';
          return;
        }

        box.innerHTML = loadingTpl();
        box.style.display = 'block';

        const form = new FormData();
        form.append('action', 'qof_get_order_details');
        form.append('nonce',  nonce);
        form.append('seller', seller);
        form.append('order_id', oid);

        fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body: form })
        .then(r => r.json())
        .then(res => {
          if(res && res.success){
            box.innerHTML = detailsTpl(res.data || {});
            box.setAttribute('data-loaded','1');
          } else {
            const msg = (res && res.data && res.data.err) ? res.data.err : 'خطا در دریافت اطلاعات.';
            box.innerHTML = '<div style="color:#991b1b">'+msg+'</div>';
          }
        })
        .catch(() => { box.innerHTML = '<div style="color:#991b1b">عدم ارتباط با سرور.</div>'; });
      });

      // init select2 inside details when opened
      wrap.addEventListener('click', function(e){
        const details = e.target.closest('.qof-details');
        if (!details) return;
        const editBox = details.querySelector('.qof-edit-box');
        if (editBox && editBox.style.display !== 'none') {
          // already open
        }
      });

    })();
    </script>
<?php
    return ob_get_clean();
});
<?php
/* =========================================================
 * PART 5/5 (آخر فایل)
 * - Metaهای اقلام ناموجود (برای ربات تلگرام)
 * - ساخت payload جزئیات سفارش (برای نمایش در گزارش)
 * - AJAX: دریافت جزئیات، تغییر وضعیت، ویرایش سفارش
 *
 * ✅ Meta Names (طبق درخواست شما):
 * 1) _qof_pending_items   => JSON اقلام ناموجود + تعداد درخواستی + قیمت + مبلغ خط
 * 2) _qof_pending_total   => مبلغ کل اقلام ناموجود (عدد)  ← همونی که با meta مبلغ کل سفارش جمع می‌کنی
 *
 * Meta آیتم‌ها (روی هر order_item):
 * - _qof_req_qty
 * - _qof_pending_price
 * - _qof_pending_line_total
 * ========================================================= */

/** ---------- Pending Meta Helpers ---------- */

function qof_order_item_get_req_qty($item){
    $v = $item instanceof WC_Order_Item ? $item->get_meta('_qof_req_qty', true) : 0;
    return max(0, intval($v));
}
function qof_order_item_get_pending_price($item){
    $v = $item instanceof WC_Order_Item ? $item->get_meta('_qof_pending_price', true) : 0;
    return max(0, floatval($v));
}
function qof_order_item_get_pending_line_total($item){
    $v = $item instanceof WC_Order_Item ? $item->get_meta('_qof_pending_line_total', true) : 0;
    return max(0, floatval($v));
}

/**
 * rebuild + save:
 * - _qof_pending_items (JSON)
 * - _qof_pending_total (number)
 * based on order items where qty==0 AND req_qty>0
 */
function qof_rebuild_pending_metas(WC_Order $order){
    $pending_items = [];
    $pending_total = 0.0;

    foreach ($order->get_items('line_item') as $item_id => $item){
        if (!$item instanceof WC_Order_Item_Product) continue;

        $qty = intval($item->get_quantity());
        $req = qof_order_item_get_req_qty($item);

        if ($qty === 0 && $req > 0){
            $pid   = $item->get_product_id();
            $name  = $item->get_name();
            $price = qof_order_item_get_pending_price($item);

            // اگر price خالی بود، از محصول بخوان
            if ($price <= 0 && $pid){
                $p = wc_get_product($pid);
                if ($p) {
                    $rawp = $p->get_price();
                    $price = ($rawp !== '' && $rawp !== null) ? (float)$rawp : 0.0;
                }
            }

            $line_total = $req * $price;
            $pending_total += $line_total;

            // ذخیره روی آیتم هم هماهنگ شود
            $item->update_meta_data('_qof_pending_price', $price);
            $item->update_meta_data('_qof_pending_line_total', $line_total);

            $pending_items[] = [
                'product_id' => intval($pid),
                'name'       => (string)$name,
                'req_qty'    => intval($req),
                'price'      => (float)$price,
                'line_total' => (float)$line_total,
            ];
        }
    }

    // persist item metas
    $order->save();

    $order->update_meta_data('_qof_pending_items', wp_json_encode($pending_items, JSON_UNESCAPED_UNICODE));
    $order->update_meta_data('_qof_pending_total', $pending_total);
    $order->save();

    return [$pending_items, $pending_total];
}

/** ---------- Build Details Payload ---------- */

function qof_build_order_details_payload(WC_Order $order){
    $items = [];
    $has_zero_qty = false;

    foreach ($order->get_items('line_item') as $item){
        if (!$item instanceof WC_Order_Item_Product) continue;

        $product = $item->get_product();
        $pid = $item->get_product_id();
        $qty = (int)$item->get_quantity();

        $stock = '-';
        if ($product && $product->managing_stock()) {
            $sq = $product->get_stock_quantity();
            $stock = ($sq === null) ? 0 : (int)$sq;
        } elseif ($product) {
            // اگر مدیریت موجودی فعال نیست، جهت نمایش
            $sq = $product->get_stock_quantity();
            $stock = ($sq === null) ? 0 : (int)$sq;
        }

        $req_qty = qof_order_item_get_req_qty($item);
        $pending_price = qof_order_item_get_pending_price($item);

        if ($qty === 0 && $req_qty > 0) $has_zero_qty = true;

        $items[] = [
            'id'            => (int)$pid,
            'name'          => $item->get_name(),
            'qty'           => $qty,
            'stock'         => $stock,
            'subtotal'      => wc_price((float)$item->get_subtotal(), ['currency' => $order->get_currency()]),
            'total'         => wc_price((float)$item->get_total(), ['currency' => $order->get_currency()]),
            'req_qty'       => (int)$req_qty,
            'pending_price' => (float)$pending_price,
        ];
    }

    // pending meta for view
    $pending_total = (float)$order->get_meta('_qof_pending_total', true);
    $pending_total_view = $pending_total > 0 ? wc_price($pending_total, ['currency'=>$order->get_currency()]) : '';

    $status_slug = (string)$order->get_status();
    $status_name = wc_get_order_status_name($status_slug);

    $bn = trim(($order->get_billing_first_name().' '.$order->get_billing_last_name()));
    $phone = (string)$order->get_billing_phone();
    $addr  = (string)$order->get_billing_address_1();

    $is_tehranpars = ((string)$order->get_meta('_wc_qof_branch', true) === 'tehranpars');
    $delivery_type = (string)$order->get_meta('_wc_qof_delivery_type', true); // home|branch (در Tehranpars)
    if ($delivery_type === '') $delivery_type = 'home';

    return [
        'order_id'           => $order->get_id(),
        'status_slug'        => $status_slug,
        'status_name'        => $status_name,
        'customer_name'      => $bn,
        'customer_phone'     => $phone,
        'customer_address'   => $addr,
        'is_tehranpars'      => $is_tehranpars,
        'delivery_type'      => $delivery_type,
        'items'              => $items,
        'has_zero_qty'       => $has_zero_qty,
        'totals_html'        => $order->get_formatted_order_total(),
        'pending_total_view' => $pending_total_view,
    ];
}

/** ---------- Seller/Access Guard ---------- */
function qof_guard_order_access($order_id, $seller_code){
    $order = wc_get_order($order_id);
    if (!$order) return [null, 'سفارش یافت نشد.'];

    $saved = (string)$order->get_meta('_wc_qof_user_code', true);
    if ($saved === '' || $saved !== (string)$seller_code) {
        return [null, 'عدم دسترسی: کد فروشنده معتبر نیست.'];
    }
    return [$order, ''];
}

/** ---------- AJAX: Get Order Details ---------- */
add_action('wp_ajax_qof_get_order_details', 'qof_ajax_get_order_details');
add_action('wp_ajax_nopriv_qof_get_order_details', 'qof_ajax_get_order_details');
function qof_ajax_get_order_details(){
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (!wp_verify_nonce($nonce, 'qof_orders_nonce')) {
        wp_send_json_error(['err'=>'nonce نامعتبر است.']);
    }

    $seller = isset($_POST['seller']) ? sanitize_text_field(wp_unslash($_POST['seller'])) : '';
    $oid    = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    if (!$seller || !$oid) wp_send_json_error(['err'=>'ورودی نامعتبر.']);

    [$order, $err] = qof_guard_order_access($oid, $seller);
    if (!$order) wp_send_json_error(['err'=>$err]);

    // همیشه pending meta ها را sync کن
    qof_rebuild_pending_metas($order);

    wp_send_json_success(qof_build_order_details_payload($order));
}

/** ---------- AJAX: Toggle Order Status (processing <-> on-hold) ---------- */
add_action('wp_ajax_qof_toggle_order_status', 'qof_ajax_toggle_order_status');
add_action('wp_ajax_nopriv_qof_toggle_order_status', 'qof_ajax_toggle_order_status');
function qof_ajax_toggle_order_status(){
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (!wp_verify_nonce($nonce, 'qof_orders_nonce')) {
        wp_send_json_error(['err'=>'nonce نامعتبر است.']);
    }

    $seller = isset($_POST['seller']) ? sanitize_text_field(wp_unslash($_POST['seller'])) : '';
    $oid    = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    if (!$seller || !$oid) wp_send_json_error(['err'=>'ورودی نامعتبر.']);

    [$order, $err] = qof_guard_order_access($oid, $seller);
    if (!$order) wp_send_json_error(['err'=>$err]);

    // اگر pending داریم اجازه processing نده
    qof_rebuild_pending_metas($order);
    $pending_total = (float)$order->get_meta('_qof_pending_total', true);

    $cur = (string)$order->get_status();
    if ($cur === 'processing') {
        $order->update_status('on-hold', 'QOF: toggle to on-hold');
    } elseif ($cur === 'on-hold') {
        if ($pending_total > 0) {
            wp_send_json_error(['err'=>'تا زمانی که اقلام ناموجود (در انتظار) دارید، امکان تغییر به پردازش نیست.']);
        }
        $order->update_status('processing', 'QOF: toggle to processing');
    } else {
        wp_send_json_error(['err'=>'این سفارش در وضعیت قابل تغییر نیست.']);
    }

    wp_send_json_success([
        'status_slug' => $order->get_status(),
        'status_name' => wc_get_order_status_name($order->get_status()),
    ]);
}

/** ---------- Stock Delta-aware helper ---------- */
function qof_apply_stock_delta(WC_Product $product, $delta){
    // delta > 0 یعنی باید از موجودی کم شود
    // delta < 0 یعنی باید به موجودی برگردد
    if (!$product->managing_stock()) return true;

    $stock = $product->get_stock_quantity();
    $stock = ($stock === null) ? 0 : (int)$stock;

    if ($delta > 0) {
        if ($stock < $delta) return false;
        wc_update_product_stock($product, $delta, 'decrease');
        return true;
    }
    if ($delta < 0) {
        wc_update_product_stock($product, abs($delta), 'increase');
        return true;
    }
    return true;
}

/** ---------- Update customer fields ---------- */
function qof_update_customer_fields(WC_Order $order, $customer){
    if (!is_array($customer)) return;

    $name = isset($customer['name']) ? trim((string)$customer['name']) : '';
    $phone= isset($customer['phone']) ? trim((string)$customer['phone']) : '';
    $addr = isset($customer['address']) ? trim((string)$customer['address']) : '';

    if ($name !== '') {
        $parts = preg_split('/\s+/', $name);
        $first = $parts ? array_shift($parts) : $name;
        $last  = $parts ? trim(implode(' ', $parts)) : '';
        $order->set_billing_first_name($first);
        $order->set_billing_last_name($last);
    }
    if ($phone !== '') $order->set_billing_phone($phone);
    if ($addr  !== '') $order->set_billing_address_1($addr);

    if (isset($customer['delivery_type'])) {
        $dt = (string)$customer['delivery_type'];
        if ($dt === 'home' || $dt === 'branch') {
            $order->update_meta_data('_wc_qof_delivery_type', $dt);
        }
    }
}

/** ---------- AJAX: Update Order Items (Edit) ---------- */
add_action('wp_ajax_qof_update_order_items', 'qof_ajax_update_order_items');
add_action('wp_ajax_nopriv_qof_update_order_items', 'qof_ajax_update_order_items');
function qof_ajax_update_order_items(){
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (!wp_verify_nonce($nonce, 'qof_orders_edit_nonce')) {
        wp_send_json_error(['err'=>'nonce نامعتبر است.']);
    }

    $seller = isset($_POST['seller']) ? sanitize_text_field(wp_unslash($_POST['seller'])) : '';
    $oid    = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $itemsj = isset($_POST['items']) ? wp_unslash($_POST['items']) : '';
    $custj  = isset($_POST['customer']) ? wp_unslash($_POST['customer']) : '';
    $mode   = isset($_POST['save_mode']) ? sanitize_text_field(wp_unslash($_POST['save_mode'])) : 'hold';

    if (!$seller || !$oid) wp_send_json_error(['err'=>'ورودی نامعتبر.']);

    [$order, $err] = qof_guard_order_access($oid, $seller);
    if (!$order) wp_send_json_error(['err'=>$err]);

    // فقط ویرایش سفارش‌های on-hold (طبق ساختار شما)
    if ((string)$order->get_status() !== 'on-hold') {
        wp_send_json_error(['err'=>'این سفارش قابل ویرایش نیست (فقط سفارش‌های در انتظار).']);
    }

    $items = json_decode($itemsj, true);
    if (!is_array($items)) $items = [];

    $customer = json_decode($custj, true);
    if (!is_array($customer)) $customer = [];

    // Map product_id => order_item_id (اولین مورد)
    $existing_map = [];
    foreach ($order->get_items('line_item') as $item_id => $item){
        if (!$item instanceof WC_Order_Item_Product) continue;
        $pid = (int)$item->get_product_id();
        if ($pid > 0 && !isset($existing_map[$pid])) $existing_map[$pid] = $item_id;
    }

    // برای عملیات موجودی: اول همه تغییرات را محاسبه و validate کنیم
    $to_process = [];
    foreach ($items as $row){
        $pid     = isset($row['product_id']) ? (int)$row['product_id'] : 0;
        $qty_new = isset($row['qty']) ? max(0, (int)$row['qty']) : 0;
        $deleted = !empty($row['deleted']) ? 1 : 0;
        $req_qty = isset($row['req_qty']) ? max(0, (int)$row['req_qty']) : 0;
        $price   = isset($row['price']) ? max(0, (float)$row['price']) : 0.0;

        if ($pid <= 0) continue;

        $product = wc_get_product($pid);
        if (!$product) {
            wp_send_json_error(['err'=>"محصول با ID {$pid} یافت نشد."]);
        }

        // qty فعلی در سفارش
        $qty_old = 0;
        $item_id = isset($existing_map[$pid]) ? (int)$existing_map[$pid] : 0;
        if ($item_id) {
            $it = $order->get_item($item_id);
            if ($it instanceof WC_Order_Item_Product) $qty_old = (int)$it->get_quantity();
        }

        // اگر حذف شده: qty_new را 0 در نظر بگیر
        if ($deleted) $qty_new = 0;

        // اگر موجودی پایه 0 و req_qty داریم => pending: qty_new باید 0 باشد
        $base_stock = $product->get_stock_quantity();
        $base_stock = ($base_stock === null) ? 0 : (int)$base_stock;

        if ($base_stock === 0 && $req_qty > 0) {
            $qty_new = 0; // pending
            if ($price <= 0) {
                $rawp = $product->get_price();
                $price = ($rawp !== '' && $rawp !== null) ? (float)$rawp : 0.0;
            }
        }

        $delta = $qty_new - $qty_old;

        // اگر افزایش می‌خواهیم: باید موجودی >= delta باشد (ولی اجازه افزایش تا سقف "موجودی + مقدار قبلی سفارش" با همین delta logic درست می‌شود)
        if ($delta > 0 && $product->managing_stock()) {
            $stock_now = $product->get_stock_quantity();
            $stock_now = ($stock_now === null) ? 0 : (int)$stock_now;
            if ($stock_now < $delta) {
                $pname = $product->get_name();
                wp_send_json_error(['err'=>"موجودی کافی نیست: {$pname} | افزایش: {$delta} | موجودی: {$stock_now}"]);
            }
        }

        $to_process[] = [
            'pid'     => $pid,
            'product' => $product,
            'item_id' => $item_id,
            'qty_old' => $qty_old,
            'qty_new' => $qty_new,
            'delta'   => $delta,
            'deleted' => $deleted,
            'req_qty' => $req_qty,
            'price'   => $price,
        ];
    }

    // اعمال تغییرات
    foreach ($to_process as $r){
        /** @var WC_Product $product */
        $product = $r['product'];
        $pid     = $r['pid'];
        $item_id = $r['item_id'];
        $qty_old = $r['qty_old'];
        $qty_new = $r['qty_new'];
        $delta   = $r['delta'];
        $deleted = $r['deleted'];
        $req_qty = $r['req_qty'];
        $price   = $r['price'];

        // اگر آیتم وجود دارد
        if ($item_id) {
            $item = $order->get_item($item_id);
            if (!$item instanceof WC_Order_Item_Product) continue;

            // حذف واقعی فقط با دکمه حذف (deleted=1)
            if ($deleted) {
                // بازگرداندن موجودی به اندازه qty_old
                if ($qty_old > 0 && $product->managing_stock()) {
                    qof_apply_stock_delta($product, -$qty_old);
                }
                $order->remove_item($item_id);
                continue;
            }

            // اعمال delta موجودی
            if ($delta != 0 && $product->managing_stock()) {
                $ok = qof_apply_stock_delta($product, $delta);
                if (!$ok) {
                    $pname = $product->get_name();
                    wp_send_json_error(['err'=>"موجودی «{$pname}» همین الان تغییر کرد. نیاز: {$qty_new} | موجودی فعلی: ".(int)$product->get_stock_quantity()]);
                }
            }

            // ست کردن qty
            $item->set_quantity($qty_new);

            // اگر pending (qty=0 و req_qty>0) => متاها را ست کن
            if ($qty_new === 0 && $req_qty > 0) {
                $item->update_meta_data('_qof_req_qty', $req_qty);
                $item->update_meta_data('_qof_pending_price', $price);
                $item->update_meta_data('_qof_pending_line_total', $req_qty * $price);
                // totals صفر (چون سفارش نباید قیمت اینها را در total رسمی حساب کند)
                $item->set_subtotal(0);
                $item->set_total(0);
            } else {
                // حالت عادی => pending meta ها پاک شود
                $item->delete_meta_data('_qof_req_qty');
                $item->delete_meta_data('_qof_pending_price');
                $item->delete_meta_data('_qof_pending_line_total');

                // totals بر اساس قیمت فعلی محصول
                $unit = (float)$product->get_price();
                $line_sub = $unit * $qty_new;
                $item->set_subtotal($line_sub);
                $item->set_total($line_sub);
            }

            $item->save();
        } else {
            // آیتم جدید (اگر qty_new==0 و req_qty==0 و deleted==0 => عملاً چیزی برای افزودن نیست)
            if ($deleted) continue;

            // اگر pending: qty_new=0 و req_qty>0 => اجازه می‌دهیم آیتم با qty=0 ساخته شود (برای نگهداشت در لیست)
            // اگر عادی: qty_new>0 => آیتم اضافه می‌شود + موجودی کم می‌شود
            if ($qty_new > 0 && $product->managing_stock()) {
                $ok = qof_apply_stock_delta($product, $delta); // delta = qty_new
                if (!$ok) {
                    $pname = $product->get_name();
                    wp_send_json_error(['err'=>"موجودی کافی نیست: {$pname}"]);
                }
            }

            $item = new WC_Order_Item_Product();
            $item->set_product($product);
            $item->set_name($product->get_name());
            $item->set_quantity($qty_new);

            if ($qty_new === 0 && $req_qty > 0) {
                $item->update_meta_data('_qof_req_qty', $req_qty);
                $item->update_meta_data('_qof_pending_price', $price);
                $item->update_meta_data('_qof_pending_line_total', $req_qty * $price);
                $item->set_subtotal(0);
                $item->set_total(0);
            } else {
                $unit = (float)$product->get_price();
                $line_sub = $unit * $qty_new;
                $item->set_subtotal($line_sub);
                $item->set_total($line_sub);
            }

            $order->add_item($item);
        }
    }

    // بروزرسانی اطلاعات مشتری/تحویل
    qof_update_customer_fields($order, $customer);

    // محاسبه مجدد totals سفارش (بدون pending چون total آنها صفر است)
    $order->calculate_totals(false);

    // rebuild pending metas (برای ربات)
    qof_rebuild_pending_metas($order);

    // اگر ذخیره نهایی خواسته شده:
    if ($mode === 'final') {
        $pending_total = (float)$order->get_meta('_qof_pending_total', true);
        if ($pending_total > 0) {
            wp_send_json_error(['err'=>'به دلیل وجود اقلام در انتظار (ناموجود)، امکان ثبت نهایی نیست.']);
        }
        $order->update_status('processing', 'QOF: save final');
    } else {
        $order->update_status('on-hold', 'QOF: save hold');
    }

    $order->save();

    wp_send_json_success([
        'order' => qof_build_order_details_payload($order),
        'meta_pending_total_key' => '_qof_pending_total',
        'meta_pending_items_key' => '_qof_pending_items',
    ]);
}

/* =========================================================
 * END OF FILE
 * ========================================================= */
