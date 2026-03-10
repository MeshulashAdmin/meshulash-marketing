<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Meshulash_Ajax {

    public function __construct() {
        // Get product data (for client-side add_to_cart enrichment)
        add_action( 'wp_ajax_meshulash_get_product', [ $this, 'get_product' ] );
        add_action( 'wp_ajax_nopriv_meshulash_get_product', [ $this, 'get_product' ] );

        // Add shipping info event (called from JS on checkout)
        add_action( 'wp_ajax_meshulash_shipping_info', [ $this, 'shipping_info' ] );
        add_action( 'wp_ajax_nopriv_meshulash_shipping_info', [ $this, 'shipping_info' ] );

        // Add payment info event (called from JS on checkout)
        add_action( 'wp_ajax_meshulash_payment_info', [ $this, 'payment_info' ] );
        add_action( 'wp_ajax_nopriv_meshulash_payment_info', [ $this, 'payment_info' ] );

        // sendBeacon endpoint for reliable event delivery
        add_action( 'wp_ajax_meshulash_beacon_event', [ $this, 'beacon_event' ] );
        add_action( 'wp_ajax_nopriv_meshulash_beacon_event', [ $this, 'beacon_event' ] );
    }

    /**
     * Get product data for a given product ID.
     */
    public function get_product() {
        check_ajax_referer( 'meshulash_nonce', 'nonce' );

        $product_id = isset( $_POST['product_id'] ) ? intval( $_POST['product_id'] ) : 0;
        $quantity   = isset( $_POST['quantity'] ) ? max( 1, intval( $_POST['quantity'] ) ) : 1;

        if ( ! $product_id ) {
            wp_send_json_error( 'Missing product ID' );
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            wp_send_json_error( 'Product not found' );
        }

        $item = Meshulash_DataLayer::format_item( $product, $quantity );

        wp_send_json_success([
            'item'     => $item,
            'currency' => get_woocommerce_currency(),
            'value'    => (float) ( $product->get_price() * $quantity ),
        ]);
    }

    /**
     * Handle add_shipping_info event from checkout JS.
     */
    public function shipping_info() {
        check_ajax_referer( 'meshulash_nonce', 'nonce' );

        if ( ! Meshulash_Settings::get( 'event_add_shipping_info' ) ) {
            wp_send_json_success( [ 'skipped' => true ] );
        }

        $cart = WC()->cart;
        if ( ! $cart || $cart->is_empty() ) {
            wp_send_json_error( 'Cart is empty' );
        }

        $shipping_tier = isset( $_POST['shipping_tier'] ) ? sanitize_text_field( $_POST['shipping_tier'] ) : '';

        $items = [];
        $total = 0;
        foreach ( $cart->get_cart() as $cart_item ) {
            $item = Meshulash_DataLayer::format_cart_item( $cart_item );
            $items[] = $item;
            $total += $item['price'] * $item['quantity'];
        }

        $event_data = [
            'event'     => 'add_shipping_info',
            'event_id'  => Meshulash_DataLayer::generate_event_id( 'asi' ),
            'ecommerce' => [
                'currency'      => get_woocommerce_currency(),
                'value'         => (float) $total,
                'shipping_tier' => $shipping_tier,
                'items'         => $items,
            ],
        ];

        wp_send_json_success( [ 'datalayer' => $event_data ] );
    }

    /**
     * Handle add_payment_info event from checkout JS.
     */
    public function payment_info() {
        check_ajax_referer( 'meshulash_nonce', 'nonce' );

        if ( ! Meshulash_Settings::get( 'event_add_payment_info' ) ) {
            wp_send_json_success( [ 'skipped' => true ] );
        }

        $cart = WC()->cart;
        if ( ! $cart || $cart->is_empty() ) {
            wp_send_json_error( 'Cart is empty' );
        }

        $payment_type = isset( $_POST['payment_type'] ) ? sanitize_text_field( $_POST['payment_type'] ) : '';

        $items = [];
        $total = 0;
        $product_ids = [];
        foreach ( $cart->get_cart() as $cart_item ) {
            $item = Meshulash_DataLayer::format_cart_item( $cart_item );
            $items[] = $item;
            $total += $item['price'] * $item['quantity'];
            $product_ids[] = $item['item_id'];
        }

        $event_id = Meshulash_DataLayer::generate_event_id( 'api' );

        $event_data = [
            'event'     => 'add_payment_info',
            'event_id'  => $event_id,
            'ecommerce' => [
                'currency'     => get_woocommerce_currency(),
                'value'        => (float) $total,
                'payment_type' => $payment_type,
                'items'        => $items,
            ],
        ];

        // Server-side
        do_action( 'meshulash_server_event', 'AddPaymentInfo', [
            'content_ids'  => $product_ids,
            'content_type' => 'product',
            'value'        => (float) $total,
            'currency'     => get_woocommerce_currency(),
        ], $event_id );

        wp_send_json_success( [ 'datalayer' => $event_data ] );
    }

    /**
     * Handle sendBeacon events for reliable server-side delivery.
     */
    public function beacon_event() {
        check_ajax_referer( 'meshulash_nonce', 'nonce' );

        $event_name = isset( $_POST['event_name'] ) ? sanitize_text_field( $_POST['event_name'] ) : '';
        $event_id   = isset( $_POST['event_id'] ) ? sanitize_text_field( $_POST['event_id'] ) : '';
        $event_data = isset( $_POST['event_data'] ) ? json_decode( wp_unslash( $_POST['event_data'] ), true ) : [];

        if ( ! $event_name || ! is_array( $event_data ) ) {
            wp_send_json_error( 'Invalid beacon data' );
        }

        // Fire server-side event
        do_action( 'meshulash_server_event', $event_name, $event_data, $event_id );

        wp_send_json_success( [ 'sent' => true ] );
    }
}
