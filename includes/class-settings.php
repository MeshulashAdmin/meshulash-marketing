<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Meshulash_Settings {

    private static $cache = null;

    public static function defaults() {
        return [
            // General
            'tracking_mode'             => 'direct', // 'direct' or 'gtm'
            'gtm_id'                    => '',
            'debug_mode'                => false,

            // Interaction Events (Direct Mode)
            'event_scroll_depth'        => true,
            'event_page_timer'          => true,
            'event_link_clicks'         => true,
            'timer_thresholds'          => '60,120',
            'scroll_thresholds'         => '25,50,75,90',

            // Pixel IDs
            'ga4_measurement_id'        => '',
            'ga4_measurement_ids'       => '',  // Additional GA4 IDs (comma-separated)
            'fb_pixel_id'               => '',
            'fb_pixel_ids'              => '',  // Additional FB Pixel IDs (comma-separated)
            'gads_conversion_id'        => '',

            // Bing / Microsoft Ads
            'bing_uet_id'               => '',

            // Pinterest
            'pinterest_tag_id'          => '',

            // Reddit
            'reddit_pixel_id'           => '',

            // Yahoo / Gemini
            'yahoo_pixel_id'            => '',
            'yahoo_dot_id'              => '',

            // Google Ads Conversion Labels
            'gads_label_purchase'       => '',
            'gads_label_begin_checkout' => '',
            'gads_label_add_to_cart'    => '',
            'gads_label_add_payment'    => '',
            'gads_label_sign_up'        => '',
            'gads_label_recurring'      => '',
            'gads_label_mid_tier'       => '',
            'gads_label_premium'        => '',
            'gads_label_luxury'         => '',
            'gads_label_vip'            => '',
            'gads_label_purchase_10plus'=> '',

            // Purchase Tier Thresholds
            'threshold_mid_tier'        => 600,
            'threshold_premium'         => 1200,
            'threshold_luxury'          => 2500,
            'threshold_vip_total_spent' => 2500,

            // TikTok Pixel
            'tt_pixel_id'               => '',

            // Server-Side: Facebook CAPI
            'fb_access_token'           => '',
            'fb_test_event_code'        => '',
            'fb_capi_enabled'           => false,

            // Server-Side: GA4 Measurement Protocol
            'ga4_api_secret'            => '',
            'ga4_mp_enabled'            => false,

            // Server-Side: TikTok Events API
            'tt_access_token'           => '',
            'tt_api_enabled'            => false,
            'tt_test_event_code'        => '',

            // UTM Tracking
            'utm_enabled'               => true,
            'utm_cookie_days'           => 90,
            'utm_hidden_fields'         => true,

            // Customer Journey
            'journey_enabled'           => true,
            'journey_max_steps'         => 50,

            // Enhanced Features (all ON by default for zero-config setup)
            'enhanced_conversions'      => true,
            'fb_advanced_matching'      => true,
            'consent_mode'              => false,  // Off by default — only needed for EU sites
            'consent_default_analytics' => 'granted',
            'consent_default_ads'       => 'granted',
            'session_enrichment'        => true,
            'cart_abandonment'          => true,
            'cart_abandon_timeout'      => 30,
            'profit_tracking'           => false,
            'product_cost_field'        => '_cost_price',

            // Bot Detection
            'bot_detection'             => true,

            // Duplicate Purchase Prevention
            'prevent_duplicate_purchase'=> true,

            // Order Status Filter (comma-separated statuses that trigger purchase)
            'purchase_order_statuses'   => 'completed,processing',

            // Form Tracking
            'form_tracking'             => true,

            // Download Tracking
            'download_tracking'         => true,
            'download_extensions'       => 'pdf,doc,docx,xls,xlsx,ppt,pptx,zip,rar,gz,csv,txt,mp3,mp4,avi,mov',

            // Head/Footer Scripts
            'head_scripts_global'       => '',
            'footer_scripts_global'     => '',

            // Cross-Domain Tracking (comma-separated domains)
            'cross_domain_enabled'      => false,
            'cross_domain_domains'      => '',

            // External ID (persistent visitor ID)
            'external_id_enabled'       => false,
            'external_id_expiry_days'   => 180,

            // Use sendBeacon API for server events
            'use_send_beacon'           => true,

            // Mumble WhatsApp Integration
            'mumble_enabled'            => false,
            'mumble_api_key'            => '',
            'mumble_on_purchase'        => true,
            'mumble_on_lead'            => true,
            'mumble_on_signup'          => false,
            'mumble_on_abandonment'     => false,
            'mumble_send_utm'           => true,
            'mumble_purchase_template'  => '',
            'mumble_abandon_template'   => '',
            'mumble_lead_template'      => '',
            'mumble_purchase_label'     => '',
            'mumble_lead_label'         => '',
            'mumble_default_team'       => '',

            // GitHub Auto-Updater
            'github_repo'               => '',  // e.g. "MeshulashDigital/meshulash-marketing"
            'github_token'              => '',  // Personal Access Token (for private repos)

            // Product Catalog Feed
            'catalog_enabled'           => true,

            // Per-Pixel Consent
            'consent_per_pixel'         => false,
            'consent_ga4'               => true,
            'consent_fb'                => true,
            'consent_gads'              => true,
            'consent_tiktok'            => true,
            'consent_bing'              => true,
            'consent_pinterest'         => true,
            'consent_reddit'            => true,
            'consent_yahoo'             => true,

            // Event Toggles
            'event_view_item'           => true,
            'event_view_item_list'      => true,
            'event_add_to_cart'         => true,
            'event_remove_from_cart'    => true,
            'event_view_cart'           => true,
            'event_begin_checkout'      => true,
            'event_add_shipping_info'   => true,
            'event_add_payment_info'    => true,
            'event_purchase'            => true,
            'event_refund'              => true,
            'event_search'              => true,
            'event_sign_up'             => true,
            'event_login'               => true,
            'event_custom_tiers'        => true,
            'event_add_to_wishlist'     => true,
            'event_coupon_applied'      => true,
            'event_order_status'        => true,
            'event_lead'                => true,
            'event_variation_select'    => true,
            'event_gallery_click'       => true,
            'event_checkout_fields'     => true,
            'event_subscriptions'       => true,
            'event_quick_view'          => true,
            'event_mini_cart'           => true,
            'event_form_submit'         => true,
            'event_file_download'       => true,
        ];
    }

    public static function get_all() {
        if ( null === self::$cache ) {
            self::$cache = wp_parse_args(
                get_option( 'meshulash_settings', [] ),
                self::defaults()
            );
        }
        return self::$cache;
    }

    public static function get( $key, $default = null ) {
        $settings = self::get_all();
        if ( isset( $settings[ $key ] ) ) {
            return $settings[ $key ];
        }
        $defaults = self::defaults();
        return $default ?? ( $defaults[ $key ] ?? null );
    }

    // Fields that allow raw HTML/JS (only for admins)
    private static $raw_fields = [ 'head_scripts_global', 'footer_scripts_global' ];

    // Sensitive fields that should be masked in the admin UI
    const SECRET_MASK = '••••••••••••••••';
    private static $secret_fields = [
        'fb_access_token',
        'ga4_api_secret',
        'tt_access_token',
        'mumble_api_key',
        'github_token',
    ];

    public static function is_secret_field( $key ) {
        return in_array( $key, self::$secret_fields, true );
    }

    public static function save( $data ) {
        $clean    = [];
        $defaults = self::defaults();
        $existing = get_option( 'meshulash_settings', [] );

        foreach ( $defaults as $key => $default_value ) {
            if ( is_bool( $default_value ) ) {
                // A hidden input (value="0") is rendered before each checkbox,
                // so if the key exists in $data it was on the current tab.
                // If it doesn't exist at all, preserve the existing saved value.
                if ( array_key_exists( $key, $data ) ) {
                    $clean[ $key ] = ! empty( $data[ $key ] ) && $data[ $key ] !== '0';
                } else {
                    $clean[ $key ] = isset( $existing[ $key ] ) ? (bool) $existing[ $key ] : $default_value;
                }
            } elseif ( is_int( $default_value ) ) {
                $clean[ $key ] = isset( $data[ $key ] ) ? intval( $data[ $key ] ) : ( $existing[ $key ] ?? $default_value );
            } elseif ( in_array( $key, self::$raw_fields, true ) ) {
                $clean[ $key ] = isset( $data[ $key ] ) ? wp_unslash( $data[ $key ] ) : ( $existing[ $key ] ?? $default_value );
            } elseif ( self::is_secret_field( $key ) ) {
                // Secret fields: mask = keep existing, empty = clear, new value = save
                if ( ! isset( $data[ $key ] ) || $data[ $key ] === self::SECRET_MASK ) {
                    $clean[ $key ] = $existing[ $key ] ?? $default_value;
                } elseif ( $data[ $key ] === '' ) {
                    $clean[ $key ] = '';
                } else {
                    $clean[ $key ] = sanitize_text_field( $data[ $key ] );
                }
            } else {
                $clean[ $key ] = isset( $data[ $key ] ) ? sanitize_text_field( $data[ $key ] ) : ( $existing[ $key ] ?? $default_value );
            }
        }

        update_option( 'meshulash_settings', $clean );
        self::$cache = null;
    }

    public static function clear_cache() {
        self::$cache = null;
    }

    public static function is_debug() {
        return (bool) self::get( 'debug_mode' );
    }
}
