<?php

/* start width x heiht x length */
/**
 * Custom carpet dimensions pricing (category-limited)
 * Category slug: custom-size
 * Formula: width * height * length * 9
 */

// ---------- helpers ----------
function sc_is_geo_carpet_product( $product ) {
    if ( ! $product ) return false;

    $product_id = $product->get_id();

    // If variation, check parent category too
    if ( $product->is_type('variation') ) {
        $product_id = $product->get_parent_id();
    }

    return has_term( 'custom-size', 'product_cat', $product_id );
}

function sc_get_dimension_rate() {
    return 9; // Tk per unit (sqft/cuft/etc)
}

// ---------- 1) Display fields on product page ----------
add_action( 'woocommerce_before_add_to_cart_button', function() {
    global $product;
    if ( ! sc_is_geo_carpet_product( $product ) ) return;

    ?>
    <div class="sc-dimensions" style="margin:12px 0; padding:12px; border:1px solid #ddd;">
        <p style="margin:0 0 10px;"><strong>Enter dimensions</strong></p>

        <p class="form-row form-row-first">
            <label for="sc_width">Width</label>
            <input type="number" step="0.01" min="0" id="sc_width" name="sc_width" required />
        </p>

        <p class="form-row form-row-last">
            <label for="sc_height">Height</label>
            <input type="number" step="0.01" min="0" id="sc_height" name="sc_height" required />
        </p>

        <p class="form-row form-row-wide">
            <label for="sc_length">Length</label>
            <input type="number" step="0.01" min="0" id="sc_length" name="sc_length" required />
        </p>

        <p class="form-row form-row-wide" style="margin-top:8px;">
            <small>
                Rate: <strong><?php echo esc_html( sc_get_dimension_rate() ); ?> Tk</strong> per sqf
            </small>
        </p>

        <p class="form-row form-row-wide" style="margin-top:8px;">
            <strong>Calculated total sqf:</strong> <span id="sc_calc_price">0</span> Tk
        </p>
    </div>
    <?php
}, 20 );

// ---------- 2) Validate fields ----------
add_filter( 'woocommerce_add_to_cart_validation', function( $passed, $product_id, $qty, $variation_id = 0 ) {
    $product = wc_get_product( $variation_id ?: $product_id );
    if ( ! sc_is_geo_carpet_product( $product ) ) return $passed;

    $w = isset($_POST['sc_width']) ? floatval($_POST['sc_width']) : 0;
    $h = isset($_POST['sc_height']) ? floatval($_POST['sc_height']) : 0;
    $l = isset($_POST['sc_length']) ? floatval($_POST['sc_length']) : 0;

    if ( $w <= 0 || $h <= 0 || $l <= 0 ) {
        wc_add_notice( 'Please enter Width, Height, and Length (all must be greater than 0).', 'error' );
        return false;
    }

    return $passed;
}, 10, 4 );

// ---------- 3) Save fields into cart item ----------
add_filter( 'woocommerce_add_cart_item_data', function( $cart_item_data, $product_id, $variation_id ) {

    $product = wc_get_product( $variation_id ?: $product_id );
    if ( ! sc_is_geo_carpet_product( $product ) ) return $cart_item_data;

    $w = floatval($_POST['sc_width'] ?? 0);
    $h = floatval($_POST['sc_height'] ?? 0);
    $l = floatval($_POST['sc_length'] ?? 0);

    $cart_item_data['sc_dims'] = [
        'width'  => $w,
        'height' => $h,
        'length' => $l,
    ];

    // Store base price ONCE to avoid compounding recalculations
    $cart_item_data['sc_base_price'] = (float) $product->get_price('edit');

    // Ensure unique line items when dimensions differ
    $cart_item_data['sc_unique_key'] = md5( wp_json_encode($cart_item_data['sc_dims']) . microtime(true) );

    return $cart_item_data;
}, 10, 3 );


// ---------- 4) Show in cart/checkout ----------
add_filter( 'woocommerce_get_item_data', function( $item_data, $cart_item ) {
    if ( empty($cart_item['sc_dims']) ) return $item_data;

    $d = $cart_item['sc_dims'];

    $item_data[] = ['name' => 'Width',  'value' => wc_clean($d['width'])];
    $item_data[] = ['name' => 'Height', 'value' => wc_clean($d['height'])];
    $item_data[] = ['name' => 'Length', 'value' => wc_clean($d['length'])];

    return $item_data;
}, 10, 2 );

// ---------- 5) Adjust price in cart ----------
add_action( 'woocommerce_before_calculate_totals', function( $cart ) {
    if ( is_admin() && ! defined('DOING_AJAX') ) return;

    foreach ( $cart->get_cart() as $cart_item ) {
        if ( empty($cart_item['sc_dims']) || ! isset($cart_item['sc_base_price']) ) continue;

        $product = $cart_item['data'];
        if ( ! sc_is_geo_carpet_product( $product ) ) continue;
		$w = (float) $cart_item['sc_dims']['width'];
		$l = (float) $cart_item['sc_dims']['length'];
		$rate = (float) sc_get_dimension_rate(); // 9

        // FEET sqft:
		$sqft  = $w * $l;     // FEET sqft (2D)
		$addon = $sqft * $rate;
		$base  = (float) $cart_item['sc_base_price'];
		$product->set_price( $base + $addon );
    }
}, 20 );


// ---------- 6) Save to order meta ----------
add_action( 'woocommerce_checkout_create_order_line_item', function( $item, $cart_item_key, $values ) {
    if ( empty($values['sc_dims']) ) return;

    $d = $values['sc_dims'];
    $item->add_meta_data( 'Width',  $d['width'], true );
    $item->add_meta_data( 'Height', $d['height'], true );
    $item->add_meta_data( 'Length', $d['length'], true );
}, 10, 3 );

// ---------- 7) Live calculation on product page (frontend only) ----------
add_action( 'wp_footer', function() {
    if ( ! is_product() ) return;

    global $product;
    if ( ! sc_is_geo_carpet_product( $product ) ) return;

    $rate = (float) sc_get_dimension_rate();
    ?>
    <script>
      jQuery(function($){
        const rate = <?php echo json_encode($rate); ?>;

        function num(sel){
          const v = parseFloat($(sel).val());
          return isNaN(v) ? 0 : v;
        }

function recalc(){
  const w = num('#sc_width');
  const h = num('#sc_height');
  const l = num('#sc_length');

  const addon = (w * h * l) * rate;
  $('#sc_calc_price').text(addon.toFixed(2));
}


        // Input updates
        $(document).on('input', '#sc_width, #sc_height, #sc_length', recalc);

        // Variation updates (important)
        $('.variations_form').on('found_variation reset_data', function(){
          recalc();
        });

        recalc();
      });
    </script>
    <?php
}, 50 );
