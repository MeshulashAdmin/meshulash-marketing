<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Meshulash_Admin {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
        add_action( 'admin_init', [ $this, 'handle_save' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'wp_ajax_meshulash_check_update', [ $this, 'ajax_check_update' ] );
    }

    public function add_menu_page() {
        add_menu_page(
            'Meshulash Marketing',
            'Meshulash',
            'manage_options',
            'meshulash-marketing',
            [ $this, 'render_page' ],
            'dashicons-chart-area',
            58
        );
    }

    public function enqueue_admin_assets( $hook ) {
        if ( $hook !== 'toplevel_page_meshulash-marketing' ) return;

        wp_enqueue_style(
            'meshulash-admin',
            MESHULASH_PLUGIN_URL . 'assets/css/meshulash-admin.css',
            [],
            MESHULASH_VERSION
        );
    }

    public function handle_save() {
        if ( ! isset( $_POST['meshulash_save_nonce'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['meshulash_save_nonce'], 'meshulash_save_settings' ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;

        // Handle "Enable All Events" button
        if ( isset( $_POST['meshulash_enable_all_events'] ) ) {
            $existing = get_option( 'meshulash_settings', [] );
            $defaults = Meshulash_Settings::defaults();
            foreach ( $defaults as $key => $val ) {
                if ( strpos( $key, 'event_' ) === 0 && is_bool( $val ) ) {
                    $existing[ $key ] = true;
                }
            }
            update_option( 'meshulash_settings', $existing );
            Meshulash_Settings::clear_cache();
            add_action( 'admin_notices', function () {
                echo '<div class="notice notice-success is-dismissible"><p>All events have been enabled.</p></div>';
            });
            return;
        }

        Meshulash_Settings::save( $_POST['meshulash'] ?? [] );

        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-success is-dismissible"><p>Meshulash settings saved.</p></div>';
        });
    }

    public function render_page() {
        $s    = Meshulash_Settings::get_all();
        $tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'pixels';
        $tabs = [
            'pixels'      => 'Pixels',
            'gads_labels' => 'Google Ads Labels',
            'thresholds'  => 'Thresholds',
            'events'      => 'Events',
            'server_side' => 'Server-Side',
            'utm'         => 'UTM Tracking',
            'enhanced'    => 'Enhanced',
            'catalog'     => 'Catalog',
            'mumble'      => 'Mumble',
            'scripts'     => 'Scripts',
            'general'     => 'General',
        ];
        ?>
        <div class="wrap meshulash-wrap">
            <div class="meshulash-header">
                <h1>&#9650; Meshulash Marketing</h1>
                <span class="meshulash-version">v<?php echo MESHULASH_VERSION; ?></span>
            </div>

            <nav class="nav-tab-wrapper meshulash-tabs">
                <?php foreach ( $tabs as $key => $label ) : ?>
                    <a href="?page=meshulash-marketing&tab=<?php echo $key; ?>"
                       class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html( $label ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <form method="post" class="meshulash-form">
                <?php wp_nonce_field( 'meshulash_save_settings', 'meshulash_save_nonce' ); ?>

                <div class="meshulash-panel">
                    <?php
                    switch ( $tab ) {
                        case 'pixels':      $this->tab_pixels( $s ); break;
                        case 'gads_labels': $this->tab_gads_labels( $s ); break;
                        case 'thresholds':  $this->tab_thresholds( $s ); break;
                        case 'events':      $this->tab_events( $s ); break;
                        case 'server_side': $this->tab_server_side( $s ); break;
                        case 'utm':         $this->tab_utm( $s ); break;
                        case 'enhanced':    $this->tab_enhanced( $s ); break;
                        case 'catalog':     $this->tab_catalog( $s ); break;
                        case 'mumble':      $this->tab_mumble( $s ); break;
                        case 'scripts':     $this->tab_scripts( $s ); break;
                        case 'general':     $this->tab_general( $s ); break;
                    }
                    ?>
                </div>

                <p class="submit">
                    <button type="submit" class="button button-primary button-hero">Save Settings</button>
                </p>
            </form>
        </div>
        <?php
    }

    // ──────────────────────────────────────────────
    //  Tab: Pixel IDs
    // ──────────────────────────────────────────────
    private function tab_pixels( $s ) {
        ?>
        <h2>Tracking Mode</h2>
        <p class="description">Choose how tracking scripts are loaded.</p>
        <table class="form-table">
            <tr>
                <th scope="row">Mode</th>
                <td>
                    <fieldset>
                        <label style="display:block;margin-bottom:8px;">
                            <input type="radio" name="meshulash[tracking_mode]" value="direct"
                                <?php checked( $s['tracking_mode'], 'direct' ); ?>>
                            <strong>Direct Pixel Injection</strong> (Recommended)
                            <p class="description" style="margin:2px 0 0 24px;">
                                GA4, Facebook Pixel, and Google Ads scripts are injected directly by the plugin.
                                No GTM needed. Faster page load, fewer requests, full control.
                            </p>
                        </label>
                        <label style="display:block;">
                            <input type="radio" name="meshulash[tracking_mode]" value="gtm"
                                <?php checked( $s['tracking_mode'], 'gtm' ); ?>>
                            <strong>GTM Mode</strong>
                            <p class="description" style="margin:2px 0 0 24px;">
                                Only pushes data to <code>dataLayer</code>. You manage tags/triggers in Google Tag Manager.
                            </p>
                        </label>
                    </fieldset>
                </td>
            </tr>
        </table>

        <hr>

        <h2>Pixel IDs</h2>
        <p class="description">Enter your tracking IDs. Used for both client-side and server-side.</p>
        <table class="form-table">
            <?php
            $this->render_field( 'GTM Container ID', 'gtm_id', $s['gtm_id'], 'GTM-XXXXXXXX', 'text',
                'Only used in GTM Mode. Leave blank in Direct Mode.' );
            $this->render_field( 'GA4 Measurement ID', 'ga4_measurement_id', $s['ga4_measurement_id'], 'G-XXXXXXXXXX' );
            $this->render_field( 'Additional GA4 IDs', 'ga4_measurement_ids', $s['ga4_measurement_ids'], 'G-AAAA, G-BBBB',
                'text', 'Comma-separated. For sending data to multiple GA4 properties.' );
            $this->render_field( 'Facebook Pixel ID', 'fb_pixel_id', $s['fb_pixel_id'], '123456789012345' );
            $this->render_field( 'Additional FB Pixel IDs', 'fb_pixel_ids', $s['fb_pixel_ids'], '111111, 222222',
                'text', 'Comma-separated. For multiple Facebook pixels.' );
            $this->render_field( 'Google Ads Conversion ID', 'gads_conversion_id', $s['gads_conversion_id'], '123456789' );
            $this->render_field( 'TikTok Pixel ID', 'tt_pixel_id', $s['tt_pixel_id'], 'XXXXXXXXXXXXXXXXX' );
            $this->render_field( 'Bing / Microsoft UET ID', 'bing_uet_id', $s['bing_uet_id'], '12345678',
                'text', 'From Microsoft Advertising &rarr; UET Tags.' );
            $this->render_field( 'Pinterest Tag ID', 'pinterest_tag_id', $s['pinterest_tag_id'], '1234567890123',
                'text', 'From Pinterest Ads &rarr; Conversions &rarr; Tag Manager.' );
            $this->render_field( 'Reddit Pixel ID', 'reddit_pixel_id', $s['reddit_pixel_id'], 't2_xxxxxxxx',
                'text', 'From Reddit Ads &rarr; Events Manager &rarr; Pixel.' );
            $this->render_field( 'Yahoo Pixel ID', 'yahoo_pixel_id', $s['yahoo_pixel_id'], '12345',
                'text', 'Yahoo/Gemini pixel ID.' );
            $this->render_field( 'Yahoo Dot Project ID', 'yahoo_dot_id', $s['yahoo_dot_id'], '10000',
                'text', 'Yahoo Dot Tag project ID.' );
            ?>
        </table>
        <?php
    }

    // ──────────────────────────────────────────────
    //  Tab: Google Ads Conversion Labels
    // ──────────────────────────────────────────────
    private function tab_gads_labels( $s ) {
        ?>
        <h2>Google Ads Conversion Labels</h2>
        <p class="description">Enter the conversion label for each event. Leave blank to skip that conversion.</p>
        <table class="form-table">
            <?php
            $labels = [
                'gads_label_purchase'        => 'Purchase',
                'gads_label_begin_checkout'  => 'Begin Checkout',
                'gads_label_add_to_cart'     => 'Add to Cart',
                'gads_label_add_payment'     => 'Add Payment Info',
                'gads_label_sign_up'         => 'Sign Up',
                'gads_label_recurring'       => 'Recurring Customer',
                'gads_label_mid_tier'        => 'Mid-Tier Purchase',
                'gads_label_premium'         => 'Premium Purchase',
                'gads_label_luxury'          => 'Luxury Purchase',
                'gads_label_vip'             => 'VIP Customer',
                'gads_label_purchase_10plus' => 'Purchase 10+',
            ];
            foreach ( $labels as $key => $label ) {
                $this->render_field( $label, $key, $s[ $key ], 'AbCdEfGhIjKlMn' );
            }
            ?>
        </table>
        <?php
    }

    // ──────────────────────────────────────────────
    //  Tab: Thresholds
    // ──────────────────────────────────────────────
    private function tab_thresholds( $s ) {
        ?>
        <h2>Purchase Tier Thresholds</h2>
        <p class="description">Configure the order value thresholds for custom purchase tier events.</p>
        <table class="form-table">
            <?php
            $this->render_field( 'Mid-Tier Minimum', 'threshold_mid_tier', $s['threshold_mid_tier'], '600', 'number' );
            $this->render_field( 'Premium Minimum', 'threshold_premium', $s['threshold_premium'], '1200', 'number' );
            $this->render_field( 'Luxury Minimum', 'threshold_luxury', $s['threshold_luxury'], '2500', 'number' );
            $this->render_field( 'VIP Total Spent', 'threshold_vip_total_spent', $s['threshold_vip_total_spent'], '2500', 'number',
                'Lifetime total spent threshold to trigger VIP customer event.' );
            ?>
        </table>

        <div class="meshulash-info-box">
            <strong>How tiers work:</strong><br>
            Order value &ge; Mid-Tier &amp; &lt; Premium &rarr; <code>midTierPurchase</code><br>
            Order value &ge; Premium &amp; &lt; Luxury &rarr; <code>premiumPurchase</code><br>
            Order value &ge; Luxury &rarr; <code>luxuryPurchase</code><br>
            Customer lifetime spend &ge; VIP &rarr; <code>vipCustomer</code>
        </div>
        <?php
    }

    // ──────────────────────────────────────────────
    //  Tab: Events
    // ──────────────────────────────────────────────
    private function tab_events( $s ) {
        ?>
        <div style="margin-bottom:15px;">
            <button type="submit" name="meshulash_enable_all_events" value="1" class="button button-secondary" style="background:#6C2BD9;color:#fff;border-color:#6C2BD9;">
                Enable All Events
            </button>
            <span class="description" style="margin-left:8px;">Turn on every event toggle at once.</span>
        </div>
        <h2>Event Toggles</h2>
        <p class="description">Enable or disable individual dataLayer events.</p>
        <table class="form-table">
            <?php
            $events = [
                'event_view_item'         => 'view_item (Product Page)',
                'event_view_item_list'    => 'view_item_list (Category Page)',
                'event_add_to_cart'       => 'add_to_cart',
                'event_remove_from_cart'  => 'remove_from_cart',
                'event_view_cart'         => 'view_cart (Cart Page)',
                'event_begin_checkout'    => 'begin_checkout (Checkout Page)',
                'event_add_shipping_info' => 'add_shipping_info',
                'event_add_payment_info'  => 'add_payment_info',
                'event_purchase'          => 'purchase (Thank You Page)',
                'event_refund'            => 'refund (Server-side)',
                'event_search'            => 'search',
                'event_sign_up'           => 'sign_up (Registration)',
                'event_login'             => 'login',
                'event_custom_tiers'      => 'Custom Tier Events (midTier, premium, luxury, VIP, recurring, purchaseNumber)',
                'event_add_to_wishlist'   => 'add_to_wishlist',
                'event_coupon_applied'    => 'coupon_applied (tracks coupon usage on orders)',
                'event_order_status'      => 'Order Status Changes (cancelled, failed, on-hold)',
                'event_lead'              => 'Lead / Form Submission',
            ];
            foreach ( $events as $key => $label ) {
                $this->render_toggle( $label, $key, $s[ $key ] );
            }
            ?>
        </table>

        <hr>

        <h2>Enrichment Events</h2>
        <p class="description">Additional tracking events for deeper insights.</p>
        <table class="form-table">
            <?php
            $enrichment_events = [
                'event_variation_select'  => 'Variation/Option Selection (size, color picks on product page)',
                'event_gallery_click'     => 'Image Gallery Clicks (thumbnail, zoom, lightbox navigation)',
                'event_checkout_fields'   => 'Checkout Field Micro-Events (field focus, validation errors)',
                'event_subscriptions'     => 'WooCommerce Subscriptions (renewal, cancel, reactivate, expire)',
                'event_quick_view'        => 'Quick View (product popup/modal interactions)',
                'event_mini_cart'         => 'Mini-Cart (open, remove from mini-cart)',
                'event_form_submit'       => 'Form Submissions (CF7, WPForms, Elementor, Gravity, Ninja, Forminator, Fluent, WS Form)',
                'event_file_download'     => 'File Downloads (PDF, DOC, ZIP, etc.)',
            ];
            foreach ( $enrichment_events as $key => $label ) {
                $this->render_toggle( $label, $key, $s[ $key ] );
            }
            ?>
        </table>

        <hr>

        <h2>Interaction Events (Direct Mode)</h2>
        <p class="description">These events are handled by the plugin's JS in Direct Mode. In GTM Mode, manage these in your GTM container.</p>
        <table class="form-table">
            <?php
            $this->render_toggle( 'Scroll Depth Tracking', 'event_scroll_depth', $s['event_scroll_depth'] );
            $this->render_field( 'Scroll Thresholds (%)', 'scroll_thresholds', $s['scroll_thresholds'], '25,50,75,90', 'text',
                'Comma-separated percentages.' );
            $this->render_toggle( 'Page Timer Events', 'event_page_timer', $s['event_page_timer'] );
            $this->render_field( 'Timer Thresholds (seconds)', 'timer_thresholds', $s['timer_thresholds'], '60,120', 'text',
                'Comma-separated seconds.' );
            $this->render_toggle( 'Link Click Tracking', 'event_link_clicks', $s['event_link_clicks'] );
            ?>
        </table>
        <div class="meshulash-info-box">
            <strong>Link clicks tracked:</strong> Phone (<code>tel:</code>), Email (<code>mailto:</code>),
            WhatsApp, Social media (Facebook, Instagram, TikTok, LinkedIn, Twitter),
            Google Maps / Waze, and CTA buttons.
        </div>
        <?php
    }

    // ──────────────────────────────────────────────
    //  Tab: Server-Side
    // ──────────────────────────────────────────────
    private function tab_server_side( $s ) {
        ?>
        <h2>Facebook Conversions API (CAPI)</h2>
        <p class="description">Server-side event deduplication with client-side pixel. Events are sent directly from your server to Facebook.</p>
        <table class="form-table">
            <?php
            $this->render_toggle( 'Enable Facebook CAPI', 'fb_capi_enabled', $s['fb_capi_enabled'] );
            $this->render_field( 'Access Token', 'fb_access_token', $s['fb_access_token'], 'EAAxxxxxxx...', 'password',
                'Generate in Facebook Events Manager &rarr; Settings &rarr; Conversions API.' );
            $this->render_field( 'Test Event Code', 'fb_test_event_code', $s['fb_test_event_code'], 'TEST12345',
                'text', 'Optional. Use for testing in Events Manager. Remove for production.' );
            ?>
        </table>

        <hr>

        <h2>GA4 Measurement Protocol</h2>
        <p class="description">Server-side events sent directly to Google Analytics 4.</p>
        <table class="form-table">
            <?php
            $this->render_toggle( 'Enable GA4 Measurement Protocol', 'ga4_mp_enabled', $s['ga4_mp_enabled'] );
            $this->render_field( 'API Secret', 'ga4_api_secret', $s['ga4_api_secret'], 'xXxXxXxXxXxX', 'password',
                'Generate in GA4 &rarr; Admin &rarr; Data Streams &rarr; Measurement Protocol API secrets.' );
            ?>
        </table>

        <hr>

        <h2>TikTok Events API</h2>
        <p class="description">Server-side events sent directly to TikTok. Works with or without the TikTok Pixel.</p>
        <table class="form-table">
            <?php
            $this->render_toggle( 'Enable TikTok Events API', 'tt_api_enabled', $s['tt_api_enabled'] );
            $this->render_field( 'Access Token', 'tt_access_token', $s['tt_access_token'], 'xxxxxxxxxxxx', 'password',
                'Generate in TikTok Events Manager &rarr; Settings &rarr; Events API.' );
            $this->render_field( 'Test Event Code', 'tt_test_event_code', $s['tt_test_event_code'], 'TEST12345',
                'text', 'Optional. Use for testing. Remove for production.' );
            ?>
        </table>

        <div class="meshulash-info-box">
            <strong>Server-side events sent to all platforms:</strong><br>
            <code>ViewContent</code>, <code>AddToCart</code>, <code>InitiateCheckout</code>,
            <code>AddPaymentInfo</code>, <code>Purchase</code>, <code>CompleteRegistration</code>,
            <code>Lead</code>, <code>Search</code> + all custom events (tiers, VIP, recurring, etc.).<br><br>
            Each platform receives events in its native format. Standard events where supported, custom events for everything else.
        </div>
        <?php
    }

    // ──────────────────────────────────────────────
    //  Tab: UTM
    // ──────────────────────────────────────────────
    private function tab_utm( $s ) {
        ?>
        <h2>UTM Attribution Tracking</h2>
        <p class="description">Automatically captures UTM parameters and click IDs from cookies and saves them to WooCommerce orders.</p>
        <table class="form-table">
            <?php
            $this->render_toggle( 'Enable UTM Tracking', 'utm_enabled', $s['utm_enabled'] );
            $this->render_field( 'Cookie Expiry (days)', 'utm_cookie_days', $s['utm_cookie_days'], '90', 'number',
                'How many days UTM cookies should persist.' );
            ?>
        </table>

        <div class="meshulash-info-box">
            <strong>Tracked parameters:</strong><br>
            <code>utm_source</code>, <code>utm_medium</code>, <code>utm_campaign</code>, <code>utm_id</code>,
            <code>utm_content</code>, <code>utm_term</code>, <code>gclid</code>, <code>fbclid</code>,
            <code>msclkid</code>, <code>wbraid</code>, <code>gbraid</code>, <code>ttcid</code>,
            <code>li_fat_id</code>, <code>obcid</code>, <code>tblci</code>, and more.<br><br>
            All data is stored in order meta and visible in the order admin panel.
        </div>

        <hr>

        <h2>Hidden Fields Injection</h2>
        <p class="description">Automatically inject marketing attribution data as hidden fields into every form on the page. When forms are submitted via webhook, CRM, or automation — the tracking data goes with it.</p>
        <table class="form-table">
            <?php
            $this->render_toggle( 'Inject Hidden Fields into Forms', 'utm_hidden_fields', $s['utm_hidden_fields'] );
            ?>
        </table>

        <div class="meshulash-info-box">
            <strong>How it works:</strong><br>
            When enabled, a <code>meshulash_tracking</code> hidden field is injected into every <code>&lt;form&gt;</code> on the page containing a JSON dict of all UTM parameters, click IDs, and cookie data.<br><br>
            Individual hidden fields are also added for each parameter (<code>msh_utm_source</code>, <code>msh_utm_medium</code>, <code>msh_gclid</code>, etc.) for easy webhook/CRM mapping.<br><br>
            <strong>Works with:</strong> Elementor Forms, Contact Form 7, WPForms, Gravity Forms, WooCommerce checkout, and any standard HTML form. Dynamically loaded forms (popups, AJAX) are also supported via MutationObserver.
        </div>

        <hr>

        <h2>Customer Journey (Path to Sale)</h2>
        <p class="description">Track every page visit and interaction as a customer journey timeline. The full path is saved to orders and included in form hidden fields.</p>
        <table class="form-table">
            <?php
            $this->render_toggle( 'Enable Journey Tracking', 'journey_enabled', $s['journey_enabled'] );
            $this->render_field( 'Max Journey Steps', 'journey_max_steps', $s['journey_max_steps'], '50', 'number',
                'Maximum number of steps to store per visitor. Oldest steps are trimmed first.' );
            ?>
        </table>

        <div class="meshulash-info-box">
            <strong>How it works:</strong><br>
            Every page view is recorded with URL, page title, timestamp, and page type (product, category, cart, checkout, search, etc.).<br>
            Key events (add to cart, form submit, etc.) are also logged as journey steps.<br><br>
            The full journey is stored in <code>localStorage</code> (no cookie size limits) and synced to:<br>
            &bull; Hidden form fields (<code>meshulash_journey</code>) — sent with webhooks/CRM<br>
            &bull; WooCommerce order meta — visible as a visual timeline in the order admin<br><br>
            <strong>Example path:</strong> Google Ad &rarr; Category Page &rarr; Product Page &rarr; Add to Cart &rarr; Checkout &rarr; Purchase
        </div>
        <?php
    }

    // ──────────────────────────────────────────────
    //  Tab: Enhanced
    // ──────────────────────────────────────────────
    private function tab_enhanced( $s ) {
        ?>
        <h2>Google Ads Enhanced Conversions</h2>
        <p class="description">Sends hashed user data (email, phone, name, address) with Google Ads conversion tags for better attribution and match rates.</p>
        <table class="form-table">
            <?php
            $this->render_toggle( 'Enable Enhanced Conversions', 'enhanced_conversions', $s['enhanced_conversions'] );
            ?>
        </table>
        <div class="meshulash-info-box">
            <strong>How it works:</strong><br>
            When a logged-in user is detected, their billing data is normalized and passed to <code>gtag("set","user_data",{...})</code>.
            Google uses this to match conversions back to ad clicks with higher accuracy.<br>
            No additional setup needed in Google Ads — just enable Enhanced Conversions in your Google Ads account settings.
        </div>

        <hr>

        <h2>Facebook Advanced Matching</h2>
        <p class="description">Passes hashed customer data to <code>fbq('init')</code> for improved match rates between website visitors and Facebook users.</p>
        <table class="form-table">
            <?php
            $this->render_toggle( 'Enable Advanced Matching', 'fb_advanced_matching', $s['fb_advanced_matching'] );
            ?>
        </table>
        <div class="meshulash-info-box">
            <strong>How it works:</strong><br>
            For logged-in users, their email, phone, name, city, state, zip, and country are SHA256-hashed and passed to the Facebook Pixel initialization.
            Facebook uses this to match more conversions to your ad campaigns.<br><br>
            <strong>Data sent:</strong> <code>em</code> (email), <code>ph</code> (phone), <code>fn</code> (first name), <code>ln</code> (last name), <code>ct</code> (city), <code>st</code> (state), <code>zp</code> (zip), <code>country</code>
        </div>

        <hr>

        <h2>Google Consent Mode v2</h2>
        <p class="description">Required for EU compliance. Sets default consent state before any tracking loads. Your cookie consent banner can call <code>meshulashGrantConsent()</code> to update.</p>
        <table class="form-table">
            <?php
            $this->render_toggle( 'Enable Consent Mode', 'consent_mode', $s['consent_mode'] );
            ?>
            <tr>
                <th scope="row"><label for="meshulash_consent_default_analytics">Analytics Default</label></th>
                <td>
                    <select name="meshulash[consent_default_analytics]" id="meshulash_consent_default_analytics">
                        <option value="denied" <?php selected( $s['consent_default_analytics'], 'denied' ); ?>>Denied (recommended for EU)</option>
                        <option value="granted" <?php selected( $s['consent_default_analytics'], 'granted' ); ?>>Granted</option>
                    </select>
                    <p class="description">Default state for <code>analytics_storage</code> before user consent.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="meshulash_consent_default_ads">Ads Default</label></th>
                <td>
                    <select name="meshulash[consent_default_ads]" id="meshulash_consent_default_ads">
                        <option value="denied" <?php selected( $s['consent_default_ads'], 'denied' ); ?>>Denied (recommended for EU)</option>
                        <option value="granted" <?php selected( $s['consent_default_ads'], 'granted' ); ?>>Granted</option>
                    </select>
                    <p class="description">Default state for <code>ad_storage</code>, <code>ad_user_data</code>, <code>ad_personalization</code>.</p>
                </td>
            </tr>
        </table>
        <div class="meshulash-info-box">
            <strong>Integration with your cookie banner:</strong><br>
            Call from your consent banner JS when user accepts:<br>
            <code>meshulashGrantConsent({ all: true });</code> — grants everything<br>
            <code>meshulashGrantConsent({ analytics: true, ads: false });</code> — analytics only<br>
            This updates Google, Facebook, and TikTok consent in one call.
        </div>

        <hr>

        <h2>Visitor Session Enrichment</h2>
        <p class="description">Adds visitor context to every event: new vs returning, session count, days since first visit, device type.</p>
        <table class="form-table">
            <?php
            $this->render_toggle( 'Enable Session Enrichment', 'session_enrichment', $s['session_enrichment'] );
            ?>
        </table>
        <div class="meshulash-info-box">
            <strong>Data added to every event:</strong><br>
            <code>visitor_type</code> (new/returning), <code>session_count</code>, <code>days_since_first</code>, <code>device_type</code> (desktop/mobile/tablet).<br>
            Uses localStorage — no cookie overhead. Session timeout: 30 minutes.
        </div>

        <hr>

        <h2>Cart Abandonment Detection</h2>
        <p class="description">Fires a <code>cart_abandonment</code> event when a user with cart items shows exit intent or becomes inactive.</p>
        <table class="form-table">
            <?php
            $this->render_toggle( 'Enable Cart Abandonment', 'cart_abandonment', $s['cart_abandonment'] );
            $this->render_field( 'Inactivity Timeout (minutes)', 'cart_abandon_timeout', $s['cart_abandon_timeout'], '30', 'number',
                'Minutes of inactivity before firing abandonment event.' );
            ?>
        </table>
        <div class="meshulash-info-box">
            <strong>Triggers:</strong><br>
            &bull; <strong>Exit intent</strong> — mouse moves toward browser close button<br>
            &bull; <strong>Inactivity</strong> — no mouse/keyboard/scroll activity for the configured timeout<br>
            &bull; <strong>Tab switch</strong> — user switches away from the tab for extended time<br><br>
            The <code>abandonment_trigger</code> field tells you which trigger fired.
        </div>

        <hr>

        <h2>Product Profit / Margin Tracking</h2>
        <p class="description">If products have a cost price, profit and margin data is sent with purchase events and stored on orders.</p>
        <table class="form-table">
            <?php
            $this->render_toggle( 'Enable Profit Tracking', 'profit_tracking', $s['profit_tracking'] );
            $this->render_field( 'Cost Price Meta Key', 'product_cost_field', $s['product_cost_field'], '_cost_price', 'text',
                'Product meta key for cost/wholesale price. A "Cost Price" field will be added to the product editor.' );
            ?>
        </table>
        <div class="meshulash-info-box">
            <strong>How it works:</strong><br>
            A cost price field is added to the WooCommerce product pricing section. On purchase, a <code>purchase_profit</code>
            event fires with total profit and margin percentage. Data is also stored in order meta for reporting.<br><br>
            <strong>Customer RFM Scoring:</strong> A <code>customer_rfm</code> event fires on every purchase with Recency/Frequency/Monetary scores (1-5 each).
            The combined <code>rfm_score</code> (e.g., "545") is stored in order meta.
        </div>

        <hr>

        <h2>Bot Detection</h2>
        <p class="description">Skip all tracking for known bots, crawlers, and page speed tools.</p>
        <table class="form-table">
            <?php
            $this->render_toggle( 'Enable Bot Detection', 'bot_detection', $s['bot_detection'] );
            ?>
        </table>
        <div class="meshulash-info-box">
            Detects 40+ known bots: Googlebot, Bingbot, Ahrefs, SEMrush, GTmetrix, Lighthouse, GPTBot, etc.
            When detected, no pixels or events load — keeping your analytics clean.
        </div>

        <hr>

        <h2>Duplicate Purchase Prevention</h2>
        <table class="form-table">
            <?php
            $this->render_toggle( 'Prevent Duplicate Purchase Events', 'prevent_duplicate_purchase', $s['prevent_duplicate_purchase'] );
            $this->render_field( 'Purchase Order Statuses', 'purchase_order_statuses', $s['purchase_order_statuses'], 'completed,processing', 'text',
                'Comma-separated WooCommerce order statuses that trigger the purchase event. Only these statuses will fire tracking.' );
            ?>
        </table>

        <hr>

        <h2>Form Tracking</h2>
        <p class="description">Automatically tracks form submissions from popular WordPress form plugins.</p>
        <table class="form-table">
            <?php
            $this->render_toggle( 'Enable Form Tracking', 'form_tracking', $s['form_tracking'] );
            ?>
        </table>
        <div class="meshulash-info-box">
            <strong>Supported form plugins:</strong><br>
            Contact Form 7, WPForms, Gravity Forms, Ninja Forms, Forminator, Fluent Forms, WS Form, Elementor Forms.<br>
            Fires a <code>form_submit</code> event with <code>form_id</code>, <code>form_name</code>, and <code>form_plugin</code>.
            Maps to Facebook <code>Lead</code>, GA4 <code>form_submit</code>, Bing <code>submit_lead_form</code>.
        </div>

        <hr>

        <h2>Download Tracking</h2>
        <p class="description">Tracks clicks on links to downloadable files.</p>
        <table class="form-table">
            <?php
            $this->render_toggle( 'Enable Download Tracking', 'download_tracking', $s['download_tracking'] );
            $this->render_field( 'File Extensions', 'download_extensions', $s['download_extensions'], 'pdf,doc,docx,xls,xlsx,zip', 'text',
                'Comma-separated file extensions to track.' );
            ?>
        </table>

        <hr>

        <h2>Cross-Domain Tracking</h2>
        <p class="description">Enable GA4 cross-domain measurement for users navigating between your domains.</p>
        <table class="form-table">
            <?php
            $this->render_toggle( 'Enable Cross-Domain', 'cross_domain_enabled', $s['cross_domain_enabled'] );
            $this->render_field( 'Domains', 'cross_domain_domains', $s['cross_domain_domains'], 'example.com,shop.example.com', 'text',
                'Comma-separated list of domains to link.' );
            ?>
        </table>

        <hr>

        <h2>External ID Tracking</h2>
        <p class="description">Persistent visitor ID stored in a cookie/transient for improved Facebook Advanced Matching.</p>
        <table class="form-table">
            <?php
            $this->render_toggle( 'Enable External ID', 'external_id_enabled', $s['external_id_enabled'] );
            $this->render_field( 'Expiry (days)', 'external_id_expiry_days', $s['external_id_expiry_days'], '180', 'number' );
            ?>
        </table>

        <hr>

        <h2>Reliable Event Delivery</h2>
        <table class="form-table">
            <?php
            $this->render_toggle( 'Use sendBeacon API', 'use_send_beacon', $s['use_send_beacon'] );
            ?>
        </table>
        <div class="meshulash-info-box">
            Uses the browser's <code>navigator.sendBeacon()</code> API for server-side events.
            More reliable than AJAX — events are delivered even when the user navigates away or closes the tab.
        </div>

        <hr>

        <h2>Per-Pixel Consent</h2>
        <p class="description">Fine-grained consent control per tracking platform. Useful with cookie consent plugins.</p>
        <table class="form-table">
            <?php
            $this->render_toggle( 'Enable Per-Pixel Consent', 'consent_per_pixel', $s['consent_per_pixel'] );
            $this->render_toggle( 'GA4 Consent', 'consent_ga4', $s['consent_ga4'] );
            $this->render_toggle( 'Facebook Consent', 'consent_fb', $s['consent_fb'] );
            $this->render_toggle( 'Google Ads Consent', 'consent_gads', $s['consent_gads'] );
            $this->render_toggle( 'TikTok Consent', 'consent_tiktok', $s['consent_tiktok'] );
            $this->render_toggle( 'Bing Consent', 'consent_bing', $s['consent_bing'] );
            $this->render_toggle( 'Pinterest Consent', 'consent_pinterest', $s['consent_pinterest'] );
            $this->render_toggle( 'Reddit Consent', 'consent_reddit', $s['consent_reddit'] );
            $this->render_toggle( 'Yahoo Consent', 'consent_yahoo', $s['consent_yahoo'] );
            ?>
        </table>
        <div class="meshulash-info-box">
            When enabled, only pixels with consent granted will fire. Toggle off a pixel to temporarily disable it.
        </div>
        <?php
    }

    // ──────────────────────────────────────────────
    //  Tab: Product Catalog Feed
    // ──────────────────────────────────────────────
    private function tab_catalog( $s ) {
        ?>
        <h2>Product Catalog Feed</h2>
        <p class="description">Generate XML product feeds for Facebook Commerce, Google Shopping, and Pinterest Catalogs. Feeds are regenerated daily via cron.</p>
        <table class="form-table">
            <?php
            $this->render_toggle( 'Enable Product Catalog', 'catalog_enabled', $s['catalog_enabled'] );
            ?>
        </table>

        <?php if ( $s['catalog_enabled'] ) : ?>
        <hr>

        <h2>Feed URLs</h2>
        <p class="description">Copy these URLs into your advertising platform's catalog/feed settings.</p>
        <table class="form-table">
            <?php
            $feeds = [
                'facebook'  => 'Facebook Product Catalog',
                'google'    => 'Google Shopping / Merchant Center',
                'pinterest' => 'Pinterest Catalogs',
            ];
            foreach ( $feeds as $type => $label ) :
                $url = Meshulash_Catalog::get_feed_url( $type );
            ?>
            <tr>
                <th scope="row"><?php echo esc_html( $label ); ?></th>
                <td>
                    <input type="text" value="<?php echo esc_url( $url ); ?>" class="large-text" readonly
                           onclick="this.select();" style="cursor:pointer;background:#f9f9f9;">
                    <p class="description">
                        <a href="<?php echo esc_url( $url ); ?>" target="_blank">Preview feed &rarr;</a>
                    </p>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>

        <hr>

        <h2>Regenerate Feeds</h2>
        <p class="description">Feeds are regenerated automatically every 24 hours. Click below to regenerate now.</p>
        <?php
        $regen_url = wp_nonce_url(
            admin_url( 'admin.php?page=meshulash-marketing&tab=catalog&meshulash_generate_feed=1' ),
            'meshulash_gen_feed'
        );
        ?>
        <p>
            <a href="<?php echo esc_url( $regen_url ); ?>" class="button button-secondary">
                Regenerate All Feeds Now
            </a>
        </p>

        <div class="meshulash-info-box">
            <strong>Feed details:</strong><br>
            &bull; All published products (simple, variable, external) are included<br>
            &bull; Variable products export each variation as a separate item with <code>item_group_id</code><br>
            &bull; Images: main image + up to 9 gallery images<br>
            &bull; Brand: from product "brand" attribute, <code>_brand</code> meta, or site name<br>
            &bull; GTIN: from <code>_gtin</code> or <code>_global_unique_id</code> product meta<br>
            &bull; Google Product Category: from <code>_google_product_category</code> product or category meta<br>
            &bull; Cached in <code>wp-content/uploads/meshulash-feeds/</code>
        </div>

        <?php endif; ?>
        <?php
    }

    // ──────────────────────────────────────────────
    //  Tab: Mumble WhatsApp Integration
    // ──────────────────────────────────────────────
    private function tab_mumble( $s ) {
        $nonce = wp_create_nonce( 'meshulash_nonce' );
        ?>
        <h2>Mumble WhatsApp Integration</h2>
        <p class="description">Connect your store to <a href="https://mumble.co.il" target="_blank">Mumble</a> to automatically sync customers and send WhatsApp messages on purchases, leads, and cart abandonment.</p>

        <table class="form-table">
            <?php $this->render_toggle( 'Enable Mumble Integration', 'mumble_enabled', $s['mumble_enabled'] ); ?>
            <?php $this->render_field( 'Mumble API Key', 'mumble_api_key', $s['mumble_api_key'], 'Paste your Mumble API key here', 'password' ); ?>
        </table>

        <p>
            <button type="button" id="meshulash-mumble-test" class="button button-secondary"
                    style="background:#25D366;color:#fff;border-color:#25D366;">
                Test Connection
            </button>
            <span id="meshulash-mumble-test-result" style="margin-left:10px;"></span>
        </p>

        <hr>

        <h2>Automatic Triggers</h2>
        <p class="description">Choose when to automatically create/update customers in Mumble and send WhatsApp templates.</p>
        <table class="form-table">
            <?php $this->render_toggle( 'On Purchase', 'mumble_on_purchase', $s['mumble_on_purchase'] ); ?>
            <?php $this->render_toggle( 'On Lead / Form Submission', 'mumble_on_lead', $s['mumble_on_lead'] ); ?>
            <?php $this->render_toggle( 'On User Registration', 'mumble_on_signup', $s['mumble_on_signup'] ); ?>
            <?php $this->render_toggle( 'On Cart Abandonment', 'mumble_on_abandonment', $s['mumble_on_abandonment'] ); ?>
            <?php $this->render_toggle( 'Include UTM Data', 'mumble_send_utm', $s['mumble_send_utm'] ); ?>
        </table>

        <hr>

        <h2>Templates</h2>
        <p class="description">Enter the template ID from Mumble to send automatically on each trigger. Leave empty to skip sending a template (customer will still be created).</p>

        <p>
            <button type="button" id="meshulash-mumble-load-templates" class="button button-secondary">
                Load Templates from Mumble
            </button>
            <span id="meshulash-mumble-templates-status" style="margin-left:10px;"></span>
        </p>
        <div id="meshulash-mumble-templates-list" style="display:none;margin:10px 0;padding:10px;background:#f9f9f9;border:1px solid #ddd;border-radius:4px;max-height:300px;overflow-y:auto;font-size:12px;"></div>

        <table class="form-table">
            <?php $this->render_field( 'Purchase Template ID', 'mumble_purchase_template', $s['mumble_purchase_template'], 'e.g. 12345', 'text',
                'Sent after a successful purchase. Variables: {{1}}=First Name, {{2}}=Order Number, {{3}}=Order Total' ); ?>
            <?php $this->render_field( 'Lead Template ID', 'mumble_lead_template', $s['mumble_lead_template'], 'e.g. 12345', 'text',
                'Sent after a form submission. Variables: {{1}}=Name' ); ?>
            <?php $this->render_field( 'Cart Abandonment Template ID', 'mumble_abandon_template', $s['mumble_abandon_template'], 'e.g. 12345', 'text',
                'Sent when cart is abandoned. Variables: {{1}}=Name, {{2}}=Cart Restore URL' ); ?>
        </table>

        <hr>

        <h2>Labels & Teams</h2>
        <p class="description">Automatically assign customers to Mumble labels and teams.</p>

        <p>
            <button type="button" id="meshulash-mumble-load-labels" class="button button-secondary">
                Load Labels from Mumble
            </button>
            <button type="button" id="meshulash-mumble-load-teams" class="button button-secondary" style="margin-left:6px;">
                Load Teams from Mumble
            </button>
            <span id="meshulash-mumble-labels-status" style="margin-left:10px;"></span>
        </p>
        <div id="meshulash-mumble-labels-list" style="display:none;margin:10px 0;padding:10px;background:#f9f9f9;border:1px solid #ddd;border-radius:4px;max-height:200px;overflow-y:auto;font-size:12px;"></div>

        <table class="form-table">
            <?php $this->render_field( 'Purchase Label', 'mumble_purchase_label', $s['mumble_purchase_label'], 'e.g. Customers', 'text',
                'Label name to assign when a customer makes a purchase.' ); ?>
            <?php $this->render_field( 'Lead Label', 'mumble_lead_label', $s['mumble_lead_label'], 'e.g. Leads', 'text',
                'Label name to assign when a form is submitted.' ); ?>
            <?php $this->render_field( 'Default Team', 'mumble_default_team', $s['mumble_default_team'], 'e.g. Sales', 'text',
                'Assign new customers to this team in Mumble.' ); ?>
        </table>

        <div class="meshulash-info-box">
            <strong>How it works:</strong><br>
            &bull; When a trigger fires (purchase, lead, etc.), the customer is <strong>created or updated</strong> in Mumble with their phone, name, email, and UTM data<br>
            &bull; If a template ID is set, a WhatsApp template message is sent automatically<br>
            &bull; If a label is set, the customer is added to that label in Mumble<br>
            &bull; UTM data (source, medium, campaign, gclid, fbclid, etc.) is sent as custom fields — visible in Mumble's customer profile<br>
            &bull; Cart abandonment sends the cart restore URL as a template variable so the customer can resume their cart<br><br>
            <strong>Getting your API key:</strong> Go to Mumble &rarr; Settings &rarr; API &rarr; Copy your API key
        </div>

        <script>
        jQuery(function($){
            var nonce = '<?php echo esc_js( $nonce ); ?>';
            var ajaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';

            // Test Connection
            $('#meshulash-mumble-test').on('click', function(){
                var $btn = $(this), $result = $('#meshulash-mumble-test-result');
                var apiKey = $('input[name="meshulash[mumble_api_key]"]').val();
                $btn.prop('disabled', true);
                $result.text('Testing...').css('color', '#666');
                $.post(ajaxUrl, {
                    action: 'meshulash_mumble_test',
                    nonce: nonce,
                    api_key: apiKey
                }, function(resp){
                    $btn.prop('disabled', false);
                    if(resp.success){
                        $result.html('<strong style="color:#00a32a;">&#10004; ' + resp.data.message + ' (' + resp.data.templates_count + ' templates found)</strong>');
                    } else {
                        $result.html('<strong style="color:#d63638;">&#10006; ' + (resp.data || 'Connection failed') + '</strong>');
                    }
                }).fail(function(){
                    $btn.prop('disabled', false);
                    $result.html('<strong style="color:#d63638;">&#10006; Request failed</strong>');
                });
            });

            // Load Templates
            $('#meshulash-mumble-load-templates').on('click', function(){
                var $btn = $(this), $status = $('#meshulash-mumble-templates-status'), $list = $('#meshulash-mumble-templates-list');
                $btn.prop('disabled', true);
                $status.text('Loading...').css('color', '#666');
                $.post(ajaxUrl, {
                    action: 'meshulash_mumble_templates',
                    nonce: nonce
                }, function(resp){
                    $btn.prop('disabled', false);
                    if(resp.success && resp.data){
                        var templates = Array.isArray(resp.data) ? resp.data : (resp.data.data || resp.data.templates || []);
                        if(!templates.length){
                            $status.text('No templates found');
                            $list.hide();
                            return;
                        }
                        $status.text(templates.length + ' templates loaded');
                        var html = '<table style="width:100%;border-collapse:collapse;"><tr style="font-weight:bold;border-bottom:2px solid #ddd;"><td style="padding:4px 8px;">ID</td><td style="padding:4px 8px;">Name</td><td style="padding:4px 8px;">Status</td><td style="padding:4px 8px;">Language</td></tr>';
                        templates.forEach(function(t){
                            html += '<tr style="border-bottom:1px solid #eee;"><td style="padding:4px 8px;"><code>' + (t.id || t.template_id || '-') + '</code></td><td style="padding:4px 8px;">' + (t.name || t.template_name || '-') + '</td><td style="padding:4px 8px;">' + (t.status || '-') + '</td><td style="padding:4px 8px;">' + (t.language || '-') + '</td></tr>';
                        });
                        html += '</table>';
                        $list.html(html).show();
                    } else {
                        $status.html('<span style="color:#d63638;">' + (resp.data || 'Failed') + '</span>');
                        $list.hide();
                    }
                }).fail(function(){
                    $btn.prop('disabled', false);
                    $status.html('<span style="color:#d63638;">Request failed</span>');
                });
            });

            // Load Labels
            $('#meshulash-mumble-load-labels').on('click', function(){
                var $btn = $(this), $status = $('#meshulash-mumble-labels-status'), $list = $('#meshulash-mumble-labels-list');
                $btn.prop('disabled', true);
                $status.text('Loading labels...').css('color', '#666');
                $.post(ajaxUrl, {
                    action: 'meshulash_mumble_labels',
                    nonce: nonce
                }, function(resp){
                    $btn.prop('disabled', false);
                    if(resp.success && resp.data){
                        var labels = Array.isArray(resp.data) ? resp.data : (resp.data.data || resp.data.labels || []);
                        if(!labels.length){
                            $status.text('No labels found');
                            $list.hide();
                            return;
                        }
                        $status.text(labels.length + ' labels loaded');
                        var html = '<strong>Available Labels:</strong><br>';
                        labels.forEach(function(l){
                            html += '&bull; <code>' + (l.name || l.label_name || JSON.stringify(l)) + '</code><br>';
                        });
                        $list.html(html).show();
                    } else {
                        $status.html('<span style="color:#d63638;">' + (resp.data || 'Failed') + '</span>');
                        $list.hide();
                    }
                }).fail(function(){
                    $btn.prop('disabled', false);
                    $status.html('<span style="color:#d63638;">Request failed</span>');
                });
            });

            // Load Teams
            $('#meshulash-mumble-load-teams').on('click', function(){
                var $status = $('#meshulash-mumble-labels-status'), $list = $('#meshulash-mumble-labels-list');
                $status.text('Loading teams...').css('color', '#666');
                $.post(ajaxUrl, {
                    action: 'meshulash_mumble_teams',
                    nonce: nonce
                }, function(resp){
                    if(resp.success && resp.data){
                        var teams = Array.isArray(resp.data) ? resp.data : (resp.data.data || resp.data.teams || []);
                        if(!teams.length){
                            $status.text('No teams found');
                            return;
                        }
                        $status.text(teams.length + ' teams loaded');
                        var html = '<strong>Available Teams:</strong><br>';
                        teams.forEach(function(t){
                            html += '&bull; <code>' + (t.name || t.team_name || JSON.stringify(t)) + '</code><br>';
                        });
                        $list.html(html).show();
                    } else {
                        $status.html('<span style="color:#d63638;">' + (resp.data || 'Failed') + '</span>');
                    }
                }).fail(function(){
                    $status.html('<span style="color:#d63638;">Request failed</span>');
                });
            });
        });
        </script>
        <?php
    }

    // ──────────────────────────────────────────────
    //  Tab: Head/Footer Scripts
    // ──────────────────────────────────────────────
    private function tab_scripts( $s ) {
        ?>
        <h2>Global Head Scripts</h2>
        <p class="description">Code placed here will be injected into <code>&lt;head&gt;</code> on every page. Use for custom pixels, verification tags, or third-party scripts.</p>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="meshulash_head_scripts_global">Head Scripts</label></th>
                <td>
                    <textarea id="meshulash_head_scripts_global"
                              name="meshulash[head_scripts_global]"
                              rows="10" cols="80"
                              class="large-text code"
                              placeholder="<!-- Paste your head scripts here -->"><?php echo esc_textarea( $s['head_scripts_global'] ); ?></textarea>
                    <p class="description">Supports HTML, JavaScript, and tracking snippets. Only admins can edit this field.</p>
                </td>
            </tr>
        </table>

        <hr>

        <h2>Global Footer Scripts</h2>
        <p class="description">Code placed here will be injected before <code>&lt;/body&gt;</code> on every page.</p>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="meshulash_footer_scripts_global">Footer Scripts</label></th>
                <td>
                    <textarea id="meshulash_footer_scripts_global"
                              name="meshulash[footer_scripts_global]"
                              rows="10" cols="80"
                              class="large-text code"
                              placeholder="<!-- Paste your footer scripts here -->"><?php echo esc_textarea( $s['footer_scripts_global'] ); ?></textarea>
                    <p class="description">Best for non-critical scripts that don't need to be in the head.</p>
                </td>
            </tr>
        </table>

        <div class="meshulash-info-box">
            <strong>Common uses:</strong><br>
            &bull; Custom verification tags (Google Search Console, Facebook domain verification)<br>
            &bull; Live chat widgets (Tawk.to, Tidio, Drift)<br>
            &bull; Custom conversion pixels not covered by the plugin<br>
            &bull; Hotjar, Clarity, or other analytics tools<br><br>
            <strong>Note:</strong> Bot detection still applies — scripts won't load for known crawlers.
        </div>
        <?php
    }

    // ──────────────────────────────────────────────
    //  Tab: General
    // ──────────────────────────────────────────────
    private function tab_general( $s ) {
        ?>
        <h2>General Settings</h2>
        <table class="form-table">
            <?php
            $this->render_toggle( 'Debug Mode', 'debug_mode', $s['debug_mode'] );
            ?>
        </table>
        <div class="meshulash-info-box">
            <strong>Debug Mode:</strong> When enabled, all dataLayer pushes are logged to the browser console with
            <code>console.log('Meshulash [event_name]', data)</code>. Disable in production.
        </div>

        <h2>Plugin Updates</h2>
        <table class="form-table">
            <tr>
                <th scope="row">Current Version</th>
                <td><strong><?php echo esc_html( MESHULASH_VERSION ); ?></strong></td>
            </tr>
            <tr>
                <th scope="row">Latest Version</th>
                <td>
                    <span id="meshulash-latest-version">—</span>
                    <button type="button" class="button" id="meshulash-check-update" style="margin-left:10px;">Check for Updates</button>
                    <span id="meshulash-update-spinner" class="spinner" style="float:none;"></span>
                    <span id="meshulash-update-msg" style="margin-left:10px;"></span>
                </td>
            </tr>
        </table>
        <script>
        (function(){
            var btn = document.getElementById('meshulash-check-update');
            var spinner = document.getElementById('meshulash-update-spinner');
            var msg = document.getElementById('meshulash-update-msg');
            var latest = document.getElementById('meshulash-latest-version');
            btn.addEventListener('click', function(){
                btn.disabled = true;
                spinner.classList.add('is-active');
                msg.textContent = '';
                var data = new FormData();
                data.append('action', 'meshulash_check_update');
                data.append('_wpnonce', '<?php echo esc_js( wp_create_nonce( 'meshulash_check_update' ) ); ?>');
                fetch(ajaxurl, {method:'POST', body:data})
                    .then(function(r){return r.json();})
                    .then(function(res){
                        spinner.classList.remove('is-active');
                        btn.disabled = false;
                        if(res.success){
                            latest.textContent = res.data.latest;
                            if(res.data.has_update){
                                msg.innerHTML = '<span style="color:#d63638;font-weight:bold;">Update available!</span> <a href="'+res.data.update_url+'" class="button button-primary" style="margin-left:8px;">Update Now</a>';
                            } else {
                                msg.innerHTML = '<span style="color:#00a32a;font-weight:bold;">You are up to date.</span>';
                            }
                        } else {
                            msg.innerHTML = '<span style="color:#d63638;">'+res.data+'</span>';
                        }
                    })
                    .catch(function(){
                        spinner.classList.remove('is-active');
                        btn.disabled = false;
                        msg.textContent = 'Request failed.';
                    });
            });
        })();
        </script>
        <?php
    }

    /**
     * AJAX: check for plugin updates (clears cache, fetches fresh from GitHub).
     */
    public function ajax_check_update() {
        check_ajax_referer( 'meshulash_check_update', '_wpnonce' );

        if ( ! current_user_can( 'update_plugins' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $updater = new Meshulash_Updater();
        $info    = $updater->get_remote_info_fresh();

        if ( ! $info || empty( $info['version'] ) || $info['version'] === '0.0.0' ) {
            wp_send_json_error( 'Could not reach GitHub. Try again later.' );
        }

        $has_update = version_compare( $info['version'], MESHULASH_VERSION, '>' );

        wp_send_json_success([
            'latest'     => $info['version'],
            'current'    => MESHULASH_VERSION,
            'has_update' => $has_update,
            'update_url' => $has_update ? admin_url( 'update-core.php' ) : '',
        ]);
    }

    // ──────────────────────────────────────────────
    //  Field Renderers
    // ──────────────────────────────────────────────
    private function render_field( $label, $key, $value, $placeholder = '', $type = 'text', $description = '' ) {
        // Mask secret fields so real values never appear in HTML source
        $display_value = $value;
        $is_secret = ( $type === 'password' && Meshulash_Settings::is_secret_field( $key ) );
        if ( $is_secret && ! empty( $value ) ) {
            $display_value = Meshulash_Settings::SECRET_MASK;
        }
        ?>
        <tr>
            <th scope="row"><label for="meshulash_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
            <td>
                <input type="<?php echo esc_attr( $type ); ?>"
                       id="meshulash_<?php echo esc_attr( $key ); ?>"
                       name="meshulash[<?php echo esc_attr( $key ); ?>]"
                       value="<?php echo esc_attr( $display_value ); ?>"
                       placeholder="<?php echo esc_attr( $placeholder ); ?>"
                       class="regular-text"
                       <?php if ( $is_secret ) echo 'autocomplete="off"'; ?>>
                <?php if ( $is_secret && ! empty( $value ) ) : ?>
                    <span class="dashicons dashicons-yes-alt" style="color:#46b450;vertical-align:middle;" title="Saved"></span>
                <?php endif; ?>
                <?php if ( $description ) : ?>
                    <p class="description"><?php echo wp_kses_post( $description ); ?></p>
                <?php endif; ?>
                <?php if ( $is_secret ) : ?>
                    <p class="description"><em>Clear the field and save to remove the stored value.</em></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    private function render_toggle( $label, $key, $value ) {
        ?>
        <tr>
            <th scope="row"><?php echo esc_html( $label ); ?></th>
            <td>
                <label class="meshulash-toggle">
                    <input type="hidden" name="meshulash[<?php echo esc_attr( $key ); ?>]" value="0">
                    <input type="checkbox"
                           name="meshulash[<?php echo esc_attr( $key ); ?>]"
                           value="1"
                           <?php checked( $value ); ?>>
                    <span class="meshulash-toggle-slider"></span>
                </label>
            </td>
        </tr>
        <?php
    }
}
