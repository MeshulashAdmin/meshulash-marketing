<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Meshulash_DataLayer {

    public function __construct() {
        add_action( 'wp_head', [ $this, 'init_datalayer' ], 2 );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_script' ] );
    }

    public function init_datalayer() {
        echo '<script>window.dataLayer = window.dataLayer || [];</script>' . "\n";
    }

    public function enqueue_frontend_script() {
        wp_enqueue_script(
            'meshulash-frontend',
            MESHULASH_PLUGIN_URL . 'assets/js/meshulash-frontend.js',
            [ 'jquery' ],
            MESHULASH_VERSION,
            true
        );

        wp_localize_script( 'meshulash-frontend', 'meshulash', [
            'ajax_url'          => admin_url( 'admin-ajax.php' ),
            'nonce'             => wp_create_nonce( 'meshulash_nonce' ),
            'currency'          => self::get_currency(),
            'has_woocommerce'   => class_exists( 'WooCommerce' ),
            'debug'             => Meshulash_Settings::is_debug(),
            'mode'              => Meshulash_Settings::get( 'tracking_mode' ),
            // Interaction events (Direct Mode)
            'link_clicks'       => (bool) Meshulash_Settings::get( 'event_link_clicks' ),
            'scroll_depth'      => (bool) Meshulash_Settings::get( 'event_scroll_depth' ),
            'scroll_thresholds' => Meshulash_Settings::get( 'scroll_thresholds' ),
            'page_timer'        => (bool) Meshulash_Settings::get( 'event_page_timer' ),
            'timer_thresholds'  => Meshulash_Settings::get( 'timer_thresholds' ),
            // UTM
            'utm_enabled'       => (bool) Meshulash_Settings::get( 'utm_enabled' ),
            'utm_cookie_days'   => (int) Meshulash_Settings::get( 'utm_cookie_days' ),
            'utm_hidden_fields' => (bool) Meshulash_Settings::get( 'utm_hidden_fields' ),
            // Customer Journey
            'journey_enabled'   => (bool) Meshulash_Settings::get( 'journey_enabled' ),
            'journey_max_steps' => (int) Meshulash_Settings::get( 'journey_max_steps' ),
            // Enhanced Features
            'session_enrichment'    => (bool) Meshulash_Settings::get( 'session_enrichment' ),
            'cart_abandonment'      => (bool) Meshulash_Settings::get( 'cart_abandonment' ),
            'cart_abandon_timeout'  => (int) Meshulash_Settings::get( 'cart_abandon_timeout' ),
            'consent_mode'          => (bool) Meshulash_Settings::get( 'consent_mode' ),
            // New event toggles
            'event_variation_select' => (bool) Meshulash_Settings::get( 'event_variation_select' ),
            'event_gallery_click'    => (bool) Meshulash_Settings::get( 'event_gallery_click' ),
            'event_checkout_fields'  => (bool) Meshulash_Settings::get( 'event_checkout_fields' ),
            'event_quick_view'       => (bool) Meshulash_Settings::get( 'event_quick_view' ),
            'event_mini_cart'        => (bool) Meshulash_Settings::get( 'event_mini_cart' ),
            'event_form_submit'      => (bool) Meshulash_Settings::get( 'event_form_submit' ),
            'event_file_download'    => (bool) Meshulash_Settings::get( 'event_file_download' ),
            // Form & download tracking
            'form_tracking'          => (bool) Meshulash_Settings::get( 'form_tracking' ),
            'download_tracking'      => (bool) Meshulash_Settings::get( 'download_tracking' ),
            'download_extensions'    => Meshulash_Settings::get( 'download_extensions' ),
            // Engagement events
            'event_outbound_click'   => (bool) Meshulash_Settings::get( 'event_outbound_click' ),
            'event_form_start'       => (bool) Meshulash_Settings::get( 'event_form_start' ),
            'event_form_abandon'     => (bool) Meshulash_Settings::get( 'event_form_abandon' ),
            'event_video_tracking'   => (bool) Meshulash_Settings::get( 'event_video_tracking' ),
            'event_share'            => (bool) Meshulash_Settings::get( 'event_share' ),
            'event_print'            => (bool) Meshulash_Settings::get( 'event_print' ),
            'event_copy'             => (bool) Meshulash_Settings::get( 'event_copy' ),
            // sendBeacon
            'use_send_beacon'        => (bool) Meshulash_Settings::get( 'use_send_beacon' ),
            // GA4 measurement ID (needed for session_id cookie parsing in JS)
            'ga4_measurement_id'     => Meshulash_Settings::get( 'ga4_measurement_id' ),
            // Cart restore data (for abandonment links)
            'cart_restore_url'       => self::get_cart_restore_url(),
            // Custom events
            'custom_events'          => self::get_custom_events(),
        ]);

        // Allow other modules (e.g. Geo) to enrich the localized data
        $data = wp_scripts()->get_data( 'meshulash-frontend', 'data' );
        // The filter is applied via inline script instead
        $extra = apply_filters( 'meshulash_localize_data', [] );
        if ( ! empty( $extra ) ) {
            wp_add_inline_script( 'meshulash-frontend', 'Object.assign(window.meshulash, ' . wp_json_encode( $extra ) . ');', 'before' );
        }
    }

    /**
     * Get currency — WooCommerce if available, otherwise fallback setting.
     */
    public static function get_currency() {
        if ( function_exists( 'get_woocommerce_currency' ) ) {
            return get_woocommerce_currency();
        }
        return Meshulash_Settings::get( 'default_currency' ) ?: 'USD';
    }

    /**
     * Get custom events configuration.
     */
    public static function get_custom_events() {
        $events = get_option( 'meshulash_custom_events', [] );
        return is_array( $events ) ? array_values( $events ) : [];
    }

    /**
     * Build a cart restore URL from current WC cart contents.
     * Returns empty string if cart is empty or not on cart/checkout page.
     */
    public static function get_cart_restore_url() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) return '';

        $cart = WC()->cart->get_cart();
        if ( empty( $cart ) ) return '';

        $items = [];
        foreach ( $cart as $cart_item ) {
            $entry = [ 'id' => $cart_item['product_id'], 'q' => $cart_item['quantity'] ];
            if ( ! empty( $cart_item['variation_id'] ) ) {
                $entry['v'] = $cart_item['variation_id'];
                if ( ! empty( $cart_item['variation'] ) ) {
                    $entry['a'] = $cart_item['variation'];
                }
            }
            $items[] = $entry;
        }

        $encoded = base64_encode( wp_json_encode( $items ) );
        $url = home_url( '/?meshulash_cart=' . $encoded );

        // Include applied coupons
        $coupons = WC()->cart->get_applied_coupons();
        if ( ! empty( $coupons ) ) {
            $url .= '&meshulash_coupon=' . urlencode( implode( ',', $coupons ) );
        }

        return $url;
    }

    /**
     * Generate a unique event ID for deduplication between client and server.
     */
    public static function generate_event_id( $prefix = 'msh' ) {
        return $prefix . '-' . bin2hex( random_bytes(8) ) . '-' . time();
    }

    /**
     * Render a dataLayer.push() script tag.
     */
    public static function push( array $data, $debug_label = '' ) {
        $json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        $script = '<script>window.dataLayer=window.dataLayer||[];dataLayer.push(' . $json . ');';

        if ( Meshulash_Settings::is_debug() && $debug_label ) {
            $script .= 'console.log("%cMeshulash PHP%c ' . esc_js( $debug_label ) . '","background:#6C2BD9;color:#fff;padding:1px 5px;border-radius:2px;font-weight:bold","color:#6C2BD9;font-weight:bold",' . $json . ');';
        }

        $script .= '</script>';
        echo $script . "\n";
    }

    /**
     * Format a WC product as a GA4 item.
     */
    public static function format_item( $product, $quantity = 1, $index = 0 ) {
        if ( ! $product instanceof WC_Product ) return [];

        $categories = wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'names' ] );
        $category   = ! empty( $categories ) ? $categories[0] : '';
        $categories_str = ! empty( $categories ) ? implode( ', ', $categories ) : '';

        $item = [
            'item_id'       => (string) $product->get_id(),
            'item_name'     => $product->get_name(),
            'price'         => (float) $product->get_price(),
            'quantity'      => (int) $quantity,
            'item_category' => $category,
        ];

        // Add up to 5 category levels for GA4
        if ( count( $categories ) > 1 ) {
            for ( $i = 1; $i < min( count( $categories ), 5 ); $i++ ) {
                $item[ 'item_category' . ( $i + 1 ) ] = $categories[ $i ];
            }
        }

        $brand = $product->get_attribute( 'brand' );
        if ( $brand ) {
            $item['item_brand'] = $brand;
        }

        $sku = $product->get_sku();
        if ( $sku ) {
            $item['item_sku'] = $sku;
        }

        if ( $index > 0 ) {
            $item['index'] = $index;
        }

        return $item;
    }

    /**
     * Format cart item as GA4 item.
     */
    public static function format_cart_item( $cart_item, $index = 0 ) {
        $product  = $cart_item['data'];
        $quantity = $cart_item['quantity'];
        return self::format_item( $product, $quantity, $index );
    }

    /**
     * Format order line item as GA4 item.
     */
    public static function format_order_item( $item, $index = 0 ) {
        $product = $item->get_product();
        if ( ! $product ) return [];

        $ga4_item = self::format_item( $product, $item->get_quantity(), $index );
        // Use line item price (may differ from current product price)
        $ga4_item['price'] = (float) ( $item->get_total() / max( 1, $item->get_quantity() ) );
        return $ga4_item;
    }

    /**
     * Get user data for server-side events (hashed).
     */
    public static function get_user_data_hashed( $order = null ) {
        $data = [];

        // Always include client IP, user agent, and FB cookies (required by CAPI even for anonymous visitors)
        $data['client_ip_address'] = self::get_client_ip();
        $data['client_user_agent'] = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ) : '';

        if ( isset( $_COOKIE['_fbp'] ) ) $data['fbp'] = sanitize_text_field( $_COOKIE['_fbp'] );
        if ( isset( $_COOKIE['_fbc'] ) ) $data['fbc'] = sanitize_text_field( $_COOKIE['_fbc'] );

        // Add PII when available (order or logged-in user)
        $email = $phone = $fname = $lname = $city = $state = $zip = $country = '';

        if ( $order instanceof WC_Order ) {
            $email   = $order->get_billing_email();
            $phone   = $order->get_billing_phone();
            $fname   = $order->get_billing_first_name();
            $lname   = $order->get_billing_last_name();
            $city    = $order->get_billing_city();
            $state   = $order->get_billing_state();
            $zip     = $order->get_billing_postcode();
            $country = $order->get_billing_country();
        } elseif ( is_user_logged_in() ) {
            $user    = wp_get_current_user();
            $email   = $user->user_email;
            $phone   = get_user_meta( $user->ID, 'billing_phone', true );
            $fname   = $user->first_name;
            $lname   = $user->last_name;
            $city    = get_user_meta( $user->ID, 'billing_city', true );
            $state   = get_user_meta( $user->ID, 'billing_state', true );
            $zip     = get_user_meta( $user->ID, 'billing_postcode', true );
            $country = get_user_meta( $user->ID, 'billing_country', true );
        }

        if ( $email )   $data['em'] = hash( 'sha256', strtolower( trim( $email ) ) );
        if ( $phone )   $data['ph'] = hash( 'sha256', preg_replace( '/[^0-9]/', '', $phone ) );
        if ( $fname )   $data['fn'] = hash( 'sha256', strtolower( trim( $fname ) ) );
        if ( $lname )   $data['ln'] = hash( 'sha256', strtolower( trim( $lname ) ) );
        if ( $city )    $data['ct'] = hash( 'sha256', strtolower( trim( $city ) ) );
        if ( $state )   $data['st'] = hash( 'sha256', strtolower( trim( $state ) ) );
        if ( $zip )     $data['zp'] = hash( 'sha256', strtolower( trim( $zip ) ) );
        if ( $country ) $data['country'] = hash( 'sha256', strtolower( trim( $country ) ) );

        return $data;
    }

    /**
     * Get client IP address.
     */
    public static function get_client_ip() {
        $headers = [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ];
        foreach ( $headers as $header ) {
            if ( ! empty( $_SERVER[ $header ] ) ) {
                $ip = sanitize_text_field( $_SERVER[ $header ] );
                // Handle comma-separated (X-Forwarded-For)
                if ( strpos( $ip, ',' ) !== false ) {
                    $ip = trim( explode( ',', $ip )[0] );
                }
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }
        return '';
    }

    /**
     * Get GA4 client_id from _ga cookie.
     */
    public static function get_ga_client_id() {
        if ( isset( $_COOKIE['_ga'] ) ) {
            $parts = explode( '.', sanitize_text_field( $_COOKIE['_ga'] ) );
            if ( count( $parts ) >= 4 ) {
                return $parts[2] . '.' . $parts[3];
            }
        }
        return '';
    }

    /**
     * Get user data for Google Ads Enhanced Conversions.
     * Returns plain-text normalized data (Google hashes it).
     */
    public static function get_enhanced_conversions_data() {
        $data = [];

        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            if ( $user->user_email )  $data['email']        = strtolower( trim( $user->user_email ) );
            if ( $user->first_name )  $data['address']['first_name'] = strtolower( trim( $user->first_name ) );
            if ( $user->last_name )   $data['address']['last_name']  = strtolower( trim( $user->last_name ) );

            $phone   = get_user_meta( $user->ID, 'billing_phone', true );
            $city    = get_user_meta( $user->ID, 'billing_city', true );
            $state   = get_user_meta( $user->ID, 'billing_state', true );
            $zip     = get_user_meta( $user->ID, 'billing_postcode', true );
            $country = get_user_meta( $user->ID, 'billing_country', true );
            $street  = get_user_meta( $user->ID, 'billing_address_1', true );

            if ( $phone )   $data['phone_number'] = $phone;
            if ( $street )  $data['address']['street']      = strtolower( trim( $street ) );
            if ( $city )    $data['address']['city']        = strtolower( trim( $city ) );
            if ( $state )   $data['address']['region']      = strtolower( trim( $state ) );
            if ( $zip )     $data['address']['postal_code'] = strtolower( trim( $zip ) );
            if ( $country ) $data['address']['country']     = strtolower( trim( $country ) );
        }

        // Clean up empty address
        if ( isset( $data['address'] ) && empty( $data['address'] ) ) {
            unset( $data['address'] );
        }

        return $data;
    }

    /**
     * Get user data for Facebook Advanced Matching.
     * Returns SHA256 hashed data in FB format.
     */
    public static function get_fb_advanced_matching_data() {
        $data = [];

        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            if ( $user->user_email )  $data['em'] = hash( 'sha256', strtolower( trim( $user->user_email ) ) );
            if ( $user->first_name )  $data['fn'] = hash( 'sha256', strtolower( trim( $user->first_name ) ) );
            if ( $user->last_name )   $data['ln'] = hash( 'sha256', strtolower( trim( $user->last_name ) ) );

            $phone   = get_user_meta( $user->ID, 'billing_phone', true );
            $city    = get_user_meta( $user->ID, 'billing_city', true );
            $state   = get_user_meta( $user->ID, 'billing_state', true );
            $zip     = get_user_meta( $user->ID, 'billing_postcode', true );
            $country = get_user_meta( $user->ID, 'billing_country', true );

            if ( $phone )   $data['ph'] = hash( 'sha256', preg_replace( '/[^0-9]/', '', $phone ) );
            if ( $city )    $data['ct'] = hash( 'sha256', strtolower( trim( $city ) ) );
            if ( $state )   $data['st'] = hash( 'sha256', strtolower( trim( $state ) ) );
            if ( $zip )     $data['zp'] = hash( 'sha256', strtolower( trim( $zip ) ) );
            if ( $country ) $data['country'] = hash( 'sha256', strtolower( trim( $country ) ) );
        }

        return $data;
    }

    /**
     * Get GA4 session_id from cookie.
     */
    public static function get_ga_session_id() {
        $ga4_id = Meshulash_Settings::get( 'ga4_measurement_id' );
        if ( ! $ga4_id ) return '';

        // Cookie name format: _ga_XXXXXXX (measurement ID without G-)
        $cookie_suffix = str_replace( [ 'G-', '-' ], [ '', '' ], $ga4_id );
        $cookie_name   = '_ga_' . $cookie_suffix;

        if ( isset( $_COOKIE[ $cookie_name ] ) ) {
            $parts = explode( '.', sanitize_text_field( $_COOKIE[ $cookie_name ] ) );
            return $parts[2] ?? '';
        }
        return '';
    }
}
