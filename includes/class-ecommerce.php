<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Meshulash_Ecommerce {

    public function __construct() {
        // Product page: view_item
        add_action( 'wp_footer', [ $this, 'event_view_item' ] );

        // Category page: view_item_list
        add_action( 'wp_footer', [ $this, 'event_view_item_list' ] );

        // Cart page: view_cart
        add_action( 'wp_footer', [ $this, 'event_view_cart' ] );

        // Checkout page: begin_checkout
        add_action( 'wp_footer', [ $this, 'event_begin_checkout' ] );

        // Purchase: woocommerce_thankyou (with duplicate prevention)
        add_action( 'woocommerce_thankyou', [ $this, 'event_purchase' ], 10, 1 );

        // Refund: server-side only
        add_action( 'woocommerce_order_refunded', [ $this, 'event_refund' ], 10, 2 );

        // Search
        add_action( 'wp_footer', [ $this, 'event_search' ] );

        // Registration
        add_action( 'user_register', [ $this, 'event_sign_up' ] );

        // Login
        add_action( 'wp_login', [ $this, 'event_login' ], 10, 2 );

        // Add to cart — pass product data to frontend JS via AJAX response
        add_filter( 'woocommerce_add_to_cart_fragments', [ $this, 'add_to_cart_fragment' ] );

            // Server-side add_to_cart uses shared event_id via transient
        add_action( 'woocommerce_add_to_cart', [ $this, 'server_side_add_to_cart' ], 10, 6 );

        // Remove from cart — attach product data to fragment for JS
        add_action( 'woocommerce_cart_item_removed', [ $this, 'event_remove_from_cart_session' ], 10, 2 );

        // Expose pending events via footer (for non-AJAX page loads)
        add_action( 'wp_footer', [ $this, 'flush_session_events' ], 999 );

        // Wishlist — support YITH and TI WooCommerce Wishlist
        add_action( 'yith_wcwl_added_to_wishlist', [ $this, 'event_add_to_wishlist' ] );
        add_action( 'tinvwl_product_added', [ $this, 'event_add_to_wishlist_ti' ] );

        // Coupon applied
        add_action( 'woocommerce_applied_coupon', [ $this, 'event_coupon_applied' ] );

        // Order status changes (server-side only — cancelled, failed, on-hold)
        add_action( 'woocommerce_order_status_cancelled', [ $this, 'event_order_cancelled' ] );
        add_action( 'woocommerce_order_status_failed', [ $this, 'event_order_failed' ] );
        add_action( 'woocommerce_order_status_on-hold', [ $this, 'event_order_on_hold' ] );

        // Lead / Form submissions — Elementor + Contact Form 7 + WPForms
        add_action( 'elementor_pro/forms/new_record', [ $this, 'event_lead_elementor' ], 10, 2 );
        add_action( 'wpcf7_mail_sent', [ $this, 'event_lead_cf7' ] );
        add_action( 'wpforms_process_complete', [ $this, 'event_lead_wpforms' ], 10, 4 );

        // Product data cache: embed product data as inline JS for fast client-side events
        add_action( 'wp_footer', [ $this, 'output_product_data_cache' ], 5 );
    }

    // ══════════════════════════════════════════════
    //  VIEW ITEM (Product Page)
    // ══════════════════════════════════════════════
    public function event_view_item() {
        if ( ! Meshulash_Settings::get( 'event_view_item' ) ) return;
        if ( ! is_product() ) return;

        global $product;
        if ( ! $product ) return;

        $event_id = Meshulash_DataLayer::generate_event_id( 'vi' );
        $item     = Meshulash_DataLayer::format_item( $product );

        Meshulash_DataLayer::push([
            'event'     => 'view_item',
            'event_id'  => $event_id,
            'ecommerce' => [
                'currency' => get_woocommerce_currency(),
                'value'    => (float) $product->get_price(),
                'items'    => [ $item ],
            ],
        ], 'view_item');

        // Trigger server-side
        do_action( 'meshulash_server_event', 'ViewContent', [
            'content_ids'  => [ (string) $product->get_id() ],
            'content_name' => $product->get_name(),
            'content_type' => 'product',
            'value'        => (float) $product->get_price(),
            'currency'     => get_woocommerce_currency(),
        ], $event_id );
    }

    // ══════════════════════════════════════════════
    //  VIEW ITEM LIST (Category / Archive)
    // ══════════════════════════════════════════════
    public function event_view_item_list() {
        if ( ! Meshulash_Settings::get( 'event_view_item_list' ) ) return;
        if ( ! is_product_category() && ! is_product_tag() && ! is_shop() ) return;

        $queried = get_queried_object();
        $list_name = '';
        $list_id   = '';

        if ( $queried instanceof WP_Term ) {
            $list_name = $queried->name;
            $list_id   = (string) $queried->term_id;
        } elseif ( is_shop() ) {
            $list_name = 'Shop';
            $list_id   = 'shop';
        }

        global $wp_query;
        $items = [];
        $index = 0;

        foreach ( $wp_query->posts as $post ) {
            $product = wc_get_product( $post->ID );
            if ( ! $product ) continue;

            $item = Meshulash_DataLayer::format_item( $product, 1, $index );
            $item['item_list_name'] = $list_name;
            $item['item_list_id']   = $list_id;
            $items[] = $item;
            $index++;
        }

        if ( empty( $items ) ) return;

        $event_id = Meshulash_DataLayer::generate_event_id( 'vil' );

        Meshulash_DataLayer::push([
            'event'     => 'view_item_list',
            'event_id'  => $event_id,
            'ecommerce' => [
                'item_list_name' => $list_name,
                'item_list_id'   => $list_id,
                'items'          => $items,
            ],
        ], 'view_item_list');
    }

    // ══════════════════════════════════════════════
    //  VIEW CART
    // ══════════════════════════════════════════════
    public function event_view_cart() {
        if ( ! Meshulash_Settings::get( 'event_view_cart' ) ) return;
        if ( ! is_cart() ) return;

        $cart  = WC()->cart;
        if ( ! $cart || $cart->is_empty() ) return;

        $items = [];
        $total = 0;
        $index = 0;

        foreach ( $cart->get_cart() as $cart_item ) {
            $item = Meshulash_DataLayer::format_cart_item( $cart_item, $index );
            $items[] = $item;
            $total += $item['price'] * $item['quantity'];
            $index++;
        }

        Meshulash_DataLayer::push([
            'event'     => 'view_cart',
            'event_id'  => Meshulash_DataLayer::generate_event_id( 'vc' ),
            'ecommerce' => [
                'currency' => get_woocommerce_currency(),
                'value'    => (float) $total,
                'items'    => $items,
            ],
        ], 'view_cart');
    }

    // ══════════════════════════════════════════════
    //  BEGIN CHECKOUT
    // ══════════════════════════════════════════════
    public function event_begin_checkout() {
        if ( ! Meshulash_Settings::get( 'event_begin_checkout' ) ) return;
        if ( ! is_checkout() || is_order_received_page() ) return;

        $cart = WC()->cart;
        if ( ! $cart || $cart->is_empty() ) return;

        $items = [];
        $total = 0;
        $index = 0;
        $product_ids = [];

        foreach ( $cart->get_cart() as $cart_item ) {
            $item = Meshulash_DataLayer::format_cart_item( $cart_item, $index );
            $items[] = $item;
            $total += $item['price'] * $item['quantity'];
            $product_ids[] = $item['item_id'];
            $index++;
        }

        $event_id = Meshulash_DataLayer::generate_event_id( 'bc' );

        Meshulash_DataLayer::push([
            'event'     => 'begin_checkout',
            'event_id'  => $event_id,
            'ecommerce' => [
                'currency' => get_woocommerce_currency(),
                'value'    => (float) $total,
                'items'    => $items,
            ],
        ], 'begin_checkout');

        // Server-side
        do_action( 'meshulash_server_event', 'InitiateCheckout', [
            'content_ids'  => $product_ids,
            'content_type' => 'product',
            'num_items'    => count( $items ),
            'value'        => (float) $total,
            'currency'     => get_woocommerce_currency(),
        ], $event_id );
    }

    // ══════════════════════════════════════════════
    //  PURCHASE (Thank You Page)
    // ══════════════════════════════════════════════
    public function event_purchase( $order_id ) {
        if ( ! Meshulash_Settings::get( 'event_purchase' ) ) return;
        if ( ! $order_id ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        // Duplicate prevention: check if already tracked
        if ( Meshulash_Settings::get( 'prevent_duplicate_purchase' ) && $order->get_meta( '_meshulash_tracked' ) ) return;

        // Order status filter: only fire for configured statuses
        $allowed_statuses = array_map( 'trim', explode( ',', Meshulash_Settings::get( 'purchase_order_statuses' ) ) );
        $order_status     = $order->get_status(); // e.g. 'processing', 'completed'
        if ( ! empty( $allowed_statuses[0] ) && ! in_array( $order_status, $allowed_statuses, true ) ) return;

        $items       = [];
        $product_ids = [];
        $total_value = 0;
        $index       = 0;

        foreach ( $order->get_items() as $item ) {
            $ga4_item = Meshulash_DataLayer::format_order_item( $item, $index );
            if ( empty( $ga4_item ) ) continue;

            $items[]       = $ga4_item;
            $product_ids[] = $ga4_item['item_id'];
            $total_value  += $ga4_item['price'] * $ga4_item['quantity'];
            $index++;
        }

        $event_id       = Meshulash_DataLayer::generate_event_id( 'pur' );
        $transaction_id = (string) $order->get_order_number();
        $currency       = $order->get_currency();
        $customer_id    = $order->get_user_id();

        // Standard purchase event
        Meshulash_DataLayer::push([
            'event'     => 'purchase',
            'event_id'  => $event_id,
            'ecommerce' => [
                'transaction_id' => $transaction_id,
                'value'          => (float) $total_value,
                'currency'       => $currency,
                'tax'            => (float) $order->get_total_tax(),
                'shipping'       => (float) $order->get_shipping_total(),
                'items'          => $items,
            ],
        ], 'purchase');

        // Server-side purchase
        do_action( 'meshulash_server_event', 'Purchase', [
            'content_ids'    => $product_ids,
            'content_type'   => 'product',
            'value'          => (float) $total_value,
            'currency'       => $currency,
            'num_items'      => count( $items ),
            'order_id'       => $transaction_id,
        ], $event_id, $order );

        // Profit / margin data
        if ( Meshulash_Settings::get( 'profit_tracking' ) && class_exists( 'Meshulash_Enrichment' ) ) {
            $profit_data = Meshulash_Enrichment::calculate_order_profit( $order );
            if ( $profit_data && $profit_data['total_cost'] > 0 ) {
                Meshulash_DataLayer::push([
                    'event'      => 'purchase_profit',
                    'event_id'   => Meshulash_DataLayer::generate_event_id( 'prf' ),
                    'ecommerce'  => [
                        'transaction_id' => $transaction_id,
                        'value'          => (float) $total_value,
                        'currency'       => $currency,
                    ],
                    'profit'       => $profit_data['total_profit'],
                    'margin_pct'   => $profit_data['margin_pct'],
                    'total_cost'   => $profit_data['total_cost'],
                ], 'purchase_profit');

                // Store profit data on order meta
                $order->update_meta_data( '_meshulash_profit', $profit_data );
            }
        }

        // RFM scoring
        if ( $customer_id && class_exists( 'Meshulash_Enrichment' ) ) {
            $rfm = Meshulash_Enrichment::calculate_rfm( $customer_id );
            if ( $rfm ) {
                Meshulash_DataLayer::push([
                    'event'          => 'customer_rfm',
                    'event_id'       => Meshulash_DataLayer::generate_event_id( 'rfm' ),
                    'rfm_score'      => $rfm['rfm_score'],
                    'rfm_recency'    => $rfm['recency'],
                    'rfm_frequency'  => $rfm['frequency'],
                    'rfm_monetary'   => $rfm['monetary'],
                    'customer_id'    => (string) $customer_id,
                    'total_orders'   => $rfm['total_orders'],
                    'total_spent'    => $rfm['total_spent'],
                ], 'customer_rfm');

                // Store RFM on order meta
                $order->update_meta_data( '_meshulash_rfm', $rfm );
            }
        }

        // Custom business events
        if ( Meshulash_Settings::get( 'event_custom_tiers' ) ) {
            $this->fire_custom_purchase_events( $order, $total_value, $transaction_id, $items );
        }

        // Mark as tracked (prevent duplicate on refresh)
        $order->update_meta_data( '_meshulash_tracked', time() );
        $order->save();
    }

    /**
     * Fire custom business events based on purchase data.
     */
    private function fire_custom_purchase_events( $order, $total_value, $transaction_id, $items ) {
        $customer_id       = $order->get_user_id();
        $number_of_purchases = 0;
        $total_spent         = 0;

        if ( $customer_id ) {
            $number_of_purchases = wc_get_customer_order_count( $customer_id );
            $total_spent         = (float) wc_get_customer_total_spent( $customer_id );
        }

        $currency = $order->get_currency();

        // Purchase Number events (1-10)
        if ( $customer_id && $number_of_purchases >= 1 && $number_of_purchases <= 10 ) {
            Meshulash_DataLayer::push([
                'event'           => 'purchaseNumber' . $number_of_purchases,
                'event_id'        => Meshulash_DataLayer::generate_event_id( 'pn' ),
                'transaction_id'  => $transaction_id,
                'value'           => (float) $total_value,
                'currency'        => $currency,
                'purchase_number' => (string) $number_of_purchases,
            ], 'purchaseNumber' . $number_of_purchases);
        }

        // Purchase 10+
        if ( $customer_id && $number_of_purchases > 10 ) {
            Meshulash_DataLayer::push([
                'event'          => 'purchase10Plus',
                'event_id'       => Meshulash_DataLayer::generate_event_id( 'p10' ),
                'transaction_id' => $transaction_id,
                'value'          => (float) $total_value,
                'currency'       => $currency,
            ], 'purchase10Plus');
        }

        // Recurring Customer (2+ purchases)
        if ( $customer_id && $number_of_purchases > 1 ) {
            Meshulash_DataLayer::push([
                'event'           => 'recurringCustomer',
                'event_id'        => Meshulash_DataLayer::generate_event_id( 'rec' ),
                'transaction_id'  => $transaction_id,
                'value'           => (float) $total_value,
                'currency'        => $currency,
                'customer_id'     => (string) $customer_id,
                'total_purchases' => $number_of_purchases,
                'total_spent'     => $total_spent,
            ], 'recurringCustomer');
        }

        // Purchase Tiers
        $mid     = (int) Meshulash_Settings::get( 'threshold_mid_tier' );
        $premium = (int) Meshulash_Settings::get( 'threshold_premium' );
        $luxury  = (int) Meshulash_Settings::get( 'threshold_luxury' );

        if ( $total_value >= $luxury ) {
            Meshulash_DataLayer::push([
                'event'          => 'luxuryPurchase',
                'event_id'       => Meshulash_DataLayer::generate_event_id( 'lux' ),
                'transaction_id' => $transaction_id,
                'value'          => (float) $total_value,
                'currency'       => $currency,
            ], 'luxuryPurchase');
        } elseif ( $total_value >= $premium ) {
            Meshulash_DataLayer::push([
                'event'          => 'premiumPurchase',
                'event_id'       => Meshulash_DataLayer::generate_event_id( 'prm' ),
                'transaction_id' => $transaction_id,
                'value'          => (float) $total_value,
                'currency'       => $currency,
            ], 'premiumPurchase');
        } elseif ( $total_value >= $mid ) {
            Meshulash_DataLayer::push([
                'event'          => 'midTierPurchase',
                'event_id'       => Meshulash_DataLayer::generate_event_id( 'mid' ),
                'transaction_id' => $transaction_id,
                'value'          => (float) $total_value,
                'currency'       => $currency,
            ], 'midTierPurchase');
        }

        // VIP Customer
        $vip_threshold = (int) Meshulash_Settings::get( 'threshold_vip_total_spent' );
        if ( $customer_id && $total_spent >= $vip_threshold ) {
            Meshulash_DataLayer::push([
                'event'       => 'vipCustomer',
                'event_id'    => Meshulash_DataLayer::generate_event_id( 'vip' ),
                'customer_id' => (string) $customer_id,
                'total_spent' => $total_spent,
                'currency'    => $currency,
            ], 'vipCustomer');
        }

        // Number of products in this order
        Meshulash_DataLayer::push([
            'event'          => 'purchase_' . count( $items ) . '_products',
            'event_id'       => Meshulash_DataLayer::generate_event_id( 'pct' ),
            'transaction_id' => $transaction_id,
            'value'          => (float) $total_value,
            'currency'       => $currency,
            'items'          => $items,
        ], 'purchase_x_products');
    }

    // ══════════════════════════════════════════════
    //  REFUND (Server-side, admin action)
    // ══════════════════════════════════════════════
    public function event_refund( $order_id, $refund_id ) {
        if ( ! Meshulash_Settings::get( 'event_refund' ) ) return;

        $order  = wc_get_order( $order_id );
        $refund = wc_get_order( $refund_id );
        if ( ! $order || ! $refund ) return;

        $event_id = Meshulash_DataLayer::generate_event_id( 'ref' );

        // GA4 Measurement Protocol refund event
        do_action( 'meshulash_server_event', 'Refund', [
            'transaction_id' => (string) $order->get_order_number(),
            'value'          => (float) abs( $refund->get_total() ),
            'currency'       => $order->get_currency(),
        ], $event_id, $order );
    }

    // ══════════════════════════════════════════════
    //  SEARCH
    // ══════════════════════════════════════════════
    public function event_search() {
        if ( ! Meshulash_Settings::get( 'event_search' ) ) return;
        if ( ! is_search() ) return;

        $search_term = get_search_query();
        if ( empty( $search_term ) ) return;

        Meshulash_DataLayer::push([
            'event'       => 'search',
            'event_id'    => Meshulash_DataLayer::generate_event_id( 'src' ),
            'search_term' => sanitize_text_field( $search_term ),
        ], 'search');
    }

    // ══════════════════════════════════════════════
    //  SIGN UP (Registration)
    // ══════════════════════════════════════════════
    public function event_sign_up( $user_id ) {
        if ( ! Meshulash_Settings::get( 'event_sign_up' ) ) return;

        $event_id = Meshulash_DataLayer::generate_event_id( 'reg' );

        // Store event in session to push on next page load
        $this->store_session_event([
            'event'    => 'sign_up',
            'event_id' => $event_id,
            'method'   => 'website',
        ]);

        // Server-side
        $user = get_userdata( $user_id );
        if ( $user ) {
            do_action( 'meshulash_server_event', 'CompleteRegistration', [
                'content_name' => 'registration',
                'status'       => true,
                'value'        => 0,
                'currency'     => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
            ], $event_id );
        }
    }

    // ══════════════════════════════════════════════
    //  LOGIN
    // ══════════════════════════════════════════════
    public function event_login( $user_login, $user ) {
        if ( ! Meshulash_Settings::get( 'event_login' ) ) return;

        $this->store_session_event([
            'event'    => 'login',
            'event_id' => Meshulash_DataLayer::generate_event_id( 'lgn' ),
            'method'   => 'website',
        ]);
    }

    // ══════════════════════════════════════════════
    //  ADD TO CART — WC Fragment for client-side
    // ══════════════════════════════════════════════
    public function add_to_cart_fragment( $fragments ) {
        if ( ! Meshulash_Settings::get( 'event_add_to_cart' ) ) return $fragments;

        $cart     = WC()->cart->get_cart();
        $last_key = array_key_last( $cart );

        if ( $last_key && isset( $cart[ $last_key ] ) ) {
            $cart_item = $cart[ $last_key ];
            $item      = Meshulash_DataLayer::format_cart_item( $cart_item );

            // Use the same event_id that server_side_add_to_cart stored
            $event_id = WC()->session ? WC()->session->get( 'meshulash_atc_event_id', '' ) : '';
            if ( ! $event_id ) {
                $event_id = Meshulash_DataLayer::generate_event_id( 'atc' );
            }

            $fragments['meshulash_add_to_cart'] = wp_json_encode([
                'event'     => 'add_to_cart',
                'event_id'  => $event_id,
                'ecommerce' => [
                    'currency' => get_woocommerce_currency(),
                    'value'    => (float) ( $item['price'] * $item['quantity'] ),
                    'items'    => [ $item ],
                ],
            ]);

            // Clear the stored event_id
            if ( WC()->session ) {
                WC()->session->set( 'meshulash_atc_event_id', '' );
            }
        }

        return $fragments;
    }

    /**
     * Fire server-side add_to_cart event with shared event_id.
     */
    public function server_side_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
        if ( ! Meshulash_Settings::get( 'event_add_to_cart' ) ) return;

        $actual_id = $variation_id ?: $product_id;
        $product   = wc_get_product( $actual_id );
        if ( ! $product ) return;

        // Generate event_id and store for the fragment to pick up (same ID = dedup)
        $event_id = Meshulash_DataLayer::generate_event_id( 'atc' );
        if ( WC()->session ) {
            WC()->session->set( 'meshulash_atc_event_id', $event_id );
        }

        do_action( 'meshulash_server_event', 'AddToCart', [
            'content_ids'  => [ (string) $product->get_id() ],
            'content_name' => $product->get_name(),
            'content_type' => 'product',
            'value'        => (float) $product->get_price() * $quantity,
            'currency'     => get_woocommerce_currency(),
        ], $event_id );
    }

    // ══════════════════════════════════════════════
    //  REMOVE FROM CART — store event in session
    // ══════════════════════════════════════════════
    public function event_remove_from_cart_session( $cart_item_key, $cart ) {
        if ( ! Meshulash_Settings::get( 'event_remove_from_cart' ) ) return;

        $removed = $cart->removed_cart_contents[ $cart_item_key ] ?? null;
        if ( ! $removed || empty( $removed['data'] ) ) return;

        $product = wc_get_product( $removed['data']->get_id() );
        if ( ! $product ) return;

        $item = Meshulash_DataLayer::format_item( $product, $removed['quantity'] );

        $this->store_session_event([
            'event'     => 'remove_from_cart',
            'event_id'  => Meshulash_DataLayer::generate_event_id( 'rfc' ),
            'ecommerce' => [
                'currency' => get_woocommerce_currency(),
                'value'    => (float) ( $item['price'] * $item['quantity'] ),
                'items'    => [ $item ],
            ],
        ]);
    }

    // ══════════════════════════════════════════════
    //  ADD TO WISHLIST
    // ══════════════════════════════════════════════
    public function event_add_to_wishlist( $product_id = 0 ) {
        if ( ! Meshulash_Settings::get( 'event_add_to_wishlist' ) ) return;
        if ( ! $product_id ) return;

        $product = wc_get_product( $product_id );
        if ( ! $product ) return;

        $event_id = Meshulash_DataLayer::generate_event_id( 'wl' );
        $item     = Meshulash_DataLayer::format_item( $product );

        $this->store_session_event([
            'event'     => 'add_to_wishlist',
            'event_id'  => $event_id,
            'ecommerce' => [
                'currency' => get_woocommerce_currency(),
                'value'    => (float) $product->get_price(),
                'items'    => [ $item ],
            ],
        ]);

        do_action( 'meshulash_server_event', 'AddToWishlist', [
            'content_ids'  => [ (string) $product->get_id() ],
            'content_name' => $product->get_name(),
            'content_type' => 'product',
            'value'        => (float) $product->get_price(),
            'currency'     => get_woocommerce_currency(),
        ], $event_id );
    }

    public function event_add_to_wishlist_ti( $data ) {
        $product_id = isset( $data['product_id'] ) ? $data['product_id'] : 0;
        $this->event_add_to_wishlist( $product_id );
    }

    // ══════════════════════════════════════════════
    //  COUPON APPLIED
    // ══════════════════════════════════════════════
    public function event_coupon_applied( $coupon_code ) {
        if ( ! Meshulash_Settings::get( 'event_coupon_applied' ) ) return;

        $this->store_session_event([
            'event'     => 'coupon_applied',
            'event_id'  => Meshulash_DataLayer::generate_event_id( 'cpn' ),
            'coupon'    => sanitize_text_field( $coupon_code ),
        ]);
    }

    // ══════════════════════════════════════════════
    //  ORDER STATUS CHANGES (server-side)
    // ══════════════════════════════════════════════
    public function event_order_cancelled( $order_id ) {
        $this->fire_order_status_event( $order_id, 'order_cancelled' );
    }

    public function event_order_failed( $order_id ) {
        $this->fire_order_status_event( $order_id, 'order_failed' );
    }

    public function event_order_on_hold( $order_id ) {
        $this->fire_order_status_event( $order_id, 'order_on_hold' );
    }

    private function fire_order_status_event( $order_id, $event_name ) {
        if ( ! Meshulash_Settings::get( 'event_order_status' ) ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $event_id = Meshulash_DataLayer::generate_event_id( 'os' );

        do_action( 'meshulash_server_event', $event_name, [
            'order_id' => (string) $order->get_order_number(),
            'value'    => (float) $order->get_total(),
            'currency' => $order->get_currency(),
        ], $event_id, $order );
    }

    // ══════════════════════════════════════════════
    //  LEAD / FORM SUBMISSIONS
    // ══════════════════════════════════════════════
    public function event_lead_elementor( $record, $handler ) {
        if ( ! Meshulash_Settings::get( 'event_lead' ) ) return;

        $form_name = $record->get_form_settings( 'form_name' );
        $event_id  = Meshulash_DataLayer::generate_event_id( 'lead' );

        $this->store_session_event([
            'event'     => 'generate_lead',
            'event_id'  => $event_id,
            'form_name' => sanitize_text_field( $form_name ),
        ]);

        do_action( 'meshulash_server_event', 'Lead', [
            'content_name'     => sanitize_text_field( $form_name ),
            'content_category' => 'form_submission',
            'value'            => 0,
            'currency'         => get_woocommerce_currency(),
        ], $event_id );
    }

    public function event_lead_cf7( $contact_form ) {
        if ( ! Meshulash_Settings::get( 'event_lead' ) ) return;

        $event_id  = Meshulash_DataLayer::generate_event_id( 'lead' );
        $form_name = $contact_form->title();

        $this->store_session_event([
            'event'     => 'generate_lead',
            'event_id'  => $event_id,
            'form_name' => sanitize_text_field( $form_name ),
        ]);

        do_action( 'meshulash_server_event', 'Lead', [
            'content_name'     => sanitize_text_field( $form_name ),
            'content_category' => 'form_submission',
            'value'            => 0,
            'currency'         => get_woocommerce_currency(),
        ], $event_id );
    }

    public function event_lead_wpforms( $fields, $entry, $form_data, $entry_id ) {
        if ( ! Meshulash_Settings::get( 'event_lead' ) ) return;

        $event_id  = Meshulash_DataLayer::generate_event_id( 'lead' );
        $form_name = $form_data['settings']['form_title'] ?? 'WPForm';

        $this->store_session_event([
            'event'     => 'generate_lead',
            'event_id'  => $event_id,
            'form_name' => sanitize_text_field( $form_name ),
        ]);

        do_action( 'meshulash_server_event', 'Lead', [
            'content_name'     => sanitize_text_field( $form_name ),
            'content_category' => 'form_submission',
            'value'            => 0,
            'currency'         => get_woocommerce_currency(),
        ], $event_id );
    }

    // ══════════════════════════════════════════════
    //  SESSION EVENT HELPERS
    // ══════════════════════════════════════════════

    /**
     * Store an event in WC session for rendering on next page load.
     */
    private function store_session_event( $event_data ) {
        if ( ! function_exists( 'WC' ) || ! WC()->session ) return;

        $events = WC()->session->get( 'meshulash_pending_events', [] );
        $events[] = $event_data;
        WC()->session->set( 'meshulash_pending_events', $events );
    }

    /**
     * Flush pending session events to dataLayer in the footer.
     */
    public function flush_session_events() {
        if ( ! function_exists( 'WC' ) || ! WC()->session ) return;

        $events = WC()->session->get( 'meshulash_pending_events', [] );
        if ( empty( $events ) ) return;

        foreach ( $events as $event_data ) {
            $label = $event_data['event'] ?? 'session_event';
            Meshulash_DataLayer::push( $event_data, $label );
        }

        WC()->session->set( 'meshulash_pending_events', [] );
    }

    // ══════════════════════════════════════════════
    //  PRODUCT DATA CACHE
    // ══════════════════════════════════════════════

    /**
     * Embed product data as inline JS on product/loop pages.
     * Frontend JS can use this for instant add-to-cart tracking without AJAX.
     */
    public function output_product_data_cache() {
        if ( ! function_exists( 'WC' ) ) return;

        $products = [];

        // Single product page
        if ( is_product() ) {
            global $product;
            if ( $product ) {
                $products[ $product->get_id() ] = Meshulash_DataLayer::format_item( $product );
            }
        }

        // Shop/category pages — cache visible products
        if ( is_shop() || is_product_category() || is_product_tag() ) {
            global $wp_query;
            if ( $wp_query && $wp_query->posts ) {
                foreach ( $wp_query->posts as $post ) {
                    $product = wc_get_product( $post->ID );
                    if ( $product ) {
                        $products[ $product->get_id() ] = Meshulash_DataLayer::format_item( $product );
                    }
                }
            }
        }

        if ( empty( $products ) ) return;

        $json = wp_json_encode( $products, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        echo '<script>window.meshulashProductData=' . $json . ';</script>' . "\n";
    }
}
