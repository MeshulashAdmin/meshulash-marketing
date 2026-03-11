<?php
/**
 * Plugin Name: Meshulash Marketing — Tracking & Conversions
 * Plugin URI:  https://meshulashdigital.com
 * Description: Complete marketing tracking suite for ecommerce & lead generation: GTM dataLayer, GA4, Facebook, Google Ads, TikTok, Bing, Pinterest, LinkedIn, Snapchat, Twitter/X, Taboola, Outbrain — client-side and server-side with webhooks. Works with or without WooCommerce. Built by Meshulash Digital.
 * Version:     1.5.2
 * Author:      Meshulash Digital
 * Author URI:  https://meshulashdigital.com
 * License:     GPL-2.0-or-later
 * Requires at least: 5.8
 * Tested up to: 6.7
 * WC requires at least: 7.0
 * WC tested up to: 9.6
 * Requires PHP: 7.4
 * Text Domain: meshulash-marketing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Kill switch: add define('MESHULASH_DISABLE', true); to wp-config.php to disable all tracking
if ( defined( 'MESHULASH_DISABLE' ) && MESHULASH_DISABLE ) {
    add_action( 'admin_notices', function () {
        if ( current_user_can( 'manage_options' ) ) {
            echo '<div class="notice notice-warning"><p><strong>Meshulash Marketing</strong> is disabled via <code>MESHULASH_DISABLE</code> in wp-config.php. Remove it to re-enable tracking.</p></div>';
        }
    });
    return;
}

define( 'MESHULASH_VERSION', '1.5.2' );
define( 'MESHULASH_PLUGIN_FILE', __FILE__ );
define( 'MESHULASH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MESHULASH_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Declare HPOS compatibility
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_storage', __FILE__, true );
    }
});

final class Meshulash_Marketing {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_includes();
        $this->init_hooks();
    }

    private function load_includes() {
        require_once MESHULASH_PLUGIN_DIR . 'includes/class-settings.php';
        require_once MESHULASH_PLUGIN_DIR . 'includes/class-admin.php';
        require_once MESHULASH_PLUGIN_DIR . 'includes/class-gtm.php';
        require_once MESHULASH_PLUGIN_DIR . 'includes/class-datalayer.php';
        require_once MESHULASH_PLUGIN_DIR . 'includes/class-ecommerce.php';
        require_once MESHULASH_PLUGIN_DIR . 'includes/class-utm.php';
        require_once MESHULASH_PLUGIN_DIR . 'includes/class-server-side.php';
        require_once MESHULASH_PLUGIN_DIR . 'includes/class-ajax.php';
        require_once MESHULASH_PLUGIN_DIR . 'includes/class-pixels.php';
        require_once MESHULASH_PLUGIN_DIR . 'includes/class-enrichment.php';
        require_once MESHULASH_PLUGIN_DIR . 'includes/class-mumble.php';
        require_once MESHULASH_PLUGIN_DIR . 'includes/class-catalog.php';
        require_once MESHULASH_PLUGIN_DIR . 'includes/class-leads.php';
        require_once MESHULASH_PLUGIN_DIR . 'includes/class-geo.php';
        require_once MESHULASH_PLUGIN_DIR . 'includes/class-webhooks.php';
        require_once MESHULASH_PLUGIN_DIR . 'includes/class-event-log.php';
        require_once MESHULASH_PLUGIN_DIR . 'includes/class-updater.php';
    }

    private function init_hooks() {
        // Admin
        if ( is_admin() ) {
            new Meshulash_Admin();
            new Meshulash_Updater();
        }

        // Bot detection: skip all frontend tracking for crawlers
        if ( ! is_admin() && Meshulash_Settings::get( 'bot_detection' ) && self::is_bot() ) {
            return;
        }

        $has_wc = $this->is_woocommerce_active();

        // UTM must load on both frontend and admin (meta boxes, order columns, order save hook)
        new Meshulash_UTM();

        // ── Core tracking — works with or without WooCommerce ──
        if ( ! is_admin() ) {
            $mode = Meshulash_Settings::get( 'tracking_mode' );

            if ( $mode === 'gtm' ) {
                new Meshulash_GTM();
            } else {
                new Meshulash_Pixels();
            }

            new Meshulash_DataLayer();
            new Meshulash_Server_Side();
            new Meshulash_Leads();       // Lead/form tracking (no WC dependency)
            new Meshulash_Geo();         // Geo-location enrichment

            add_action( 'wp_head', [ $this, 'inject_head_scripts' ], 99 );
            add_action( 'wp_footer', [ $this, 'inject_footer_scripts' ], 99 );
        }

        // Always register AJAX handlers (beacon works without WC)
        new Meshulash_Ajax();
        new Meshulash_Webhooks();
        new Meshulash_Event_Log();

        // ── WooCommerce-specific features ──
        if ( $has_wc ) {
            new Meshulash_Ecommerce();
            new Meshulash_Enrichment();
            new Meshulash_Mumble();

            if ( Meshulash_Settings::get( 'catalog_enabled' ) ) {
                new Meshulash_Catalog();
            }

            // Cart restore link handler
            if ( ! is_admin() ) {
                add_action( 'template_redirect', [ $this, 'handle_cart_restore' ] );
            }
        }
    }

    private function is_woocommerce_active() {
        return class_exists( 'WooCommerce' );
    }

    /**
     * Detect bots/crawlers via User-Agent string.
     */
    public static function is_bot() {
        if ( ! isset( $_SERVER['HTTP_USER_AGENT'] ) ) return true;
        $ua = strtolower( $_SERVER['HTTP_USER_AGENT'] );
        $bots = [
            'googlebot', 'bingbot', 'slurp', 'duckduckbot', 'baiduspider',
            'yandexbot', 'sogou', 'exabot', 'facebot', 'facebookexternalhit',
            'ia_archiver', 'alexabot', 'mj12bot', 'ahrefsbot', 'semrushbot',
            'dotbot', 'rogerbot', 'screaming frog', 'uptimerobot', 'pingdom',
            'crawl', 'spider', 'bot/', 'bot;', 'headlesschrome',
            'phantomjs', 'lighthouse', 'chrome-lighthouse', 'gtmetrix',
            'pagespeed', 'google page speed', 'wprocket',
            'mediapartners-google', 'adsbot-google', 'apis-google',
            'feedfetcher', 'appengine-google', 'nutch',
            'petalbot', 'bytespider', 'gptbot', 'chatgpt-user',
            'claudebot', 'anthropic-ai', 'ccbot', 'amazonbot',
        ];
        foreach ( $bots as $bot ) {
            if ( strpos( $ua, $bot ) !== false ) return true;
        }
        return false;
    }

    /**
     * Inject global head scripts.
     */
    public function inject_head_scripts() {
        $scripts = Meshulash_Settings::get( 'head_scripts_global' );
        if ( $scripts ) {
            echo "\n<!-- Meshulash: Head Scripts -->\n" . $scripts . "\n<!-- /Meshulash: Head Scripts -->\n";
        }
    }

    /**
     * Inject global footer scripts.
     */
    public function inject_footer_scripts() {
        $scripts = Meshulash_Settings::get( 'footer_scripts_global' );
        if ( $scripts ) {
            echo "\n<!-- Meshulash: Footer Scripts -->\n" . $scripts . "\n<!-- /Meshulash: Footer Scripts -->\n";
        }
    }

    /**
     * Handle cart restore links: ?meshulash_cart=base64(json)
     * Rebuilds the cart from encoded product data and redirects to cart.
     */
    public function handle_cart_restore() {
        if ( ! isset( $_GET['meshulash_cart'] ) ) return;

        $encoded = sanitize_text_field( $_GET['meshulash_cart'] );
        $decoded = base64_decode( $encoded );
        if ( ! $decoded ) {
            wp_safe_redirect( wc_get_cart_url() );
            exit;
        }

        $items = json_decode( $decoded, true );
        if ( ! is_array( $items ) ) {
            wp_safe_redirect( wc_get_cart_url() );
            exit;
        }

        // Clear existing cart and rebuild
        WC()->cart->empty_cart();

        foreach ( $items as $item ) {
            $product_id   = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
            $quantity      = isset( $item['q'] ) ? max( 1, absint( $item['q'] ) ) : 1;
            $variation_id  = isset( $item['v'] ) ? absint( $item['v'] ) : 0;
            $variation     = isset( $item['a'] ) && is_array( $item['a'] ) ? $item['a'] : [];

            if ( $product_id ) {
                WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variation );
            }
        }

        // Optional coupon restore
        if ( isset( $_GET['meshulash_coupon'] ) ) {
            $coupon = sanitize_text_field( $_GET['meshulash_coupon'] );
            if ( $coupon ) {
                WC()->cart->apply_coupon( $coupon );
            }
        }

        // Redirect to cart (strip the restore params)
        wp_safe_redirect( wc_get_cart_url() );
        exit;
    }

    public function activate() {
        if ( ! get_option( 'meshulash_settings' ) ) {
            update_option( 'meshulash_settings', Meshulash_Settings::defaults() );
        }
    }
}

register_activation_hook( __FILE__, function () {
    Meshulash_Marketing::instance()->activate();
});

add_action( 'plugins_loaded', function () {
    Meshulash_Marketing::instance();
});
