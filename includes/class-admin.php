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

        // Handle custom events save
        if ( isset( $_POST['meshulash_custom_events'] ) && is_array( $_POST['meshulash_custom_events'] ) ) {
            $clean_events = [];
            foreach ( $_POST['meshulash_custom_events'] as $ev ) {
                if ( empty( $ev['event_name'] ) || empty( $ev['selector'] ) ) continue;
                $clean_events[] = [
                    'event_name'     => sanitize_text_field( $ev['event_name'] ),
                    'selector'       => sanitize_text_field( $ev['selector'] ),
                    'trigger'        => in_array( $ev['trigger'] ?? 'click', [ 'click', 'submit', 'change', 'focus', 'hover', 'visibility' ], true ) ? $ev['trigger'] : 'click',
                    'event_category' => sanitize_text_field( $ev['event_category'] ?? '' ),
                    'event_label'    => sanitize_text_field( $ev['event_label'] ?? '' ),
                    'event_value'    => sanitize_text_field( $ev['event_value'] ?? '' ),
                    'server_side'    => ! empty( $ev['server_side'] ),
                    'once'           => ! isset( $ev['once'] ) || ! empty( $ev['once'] ),
                ];
            }
            update_option( 'meshulash_custom_events', $clean_events );
        } elseif ( isset( $_POST['meshulash_custom_events_tab'] ) ) {
            // Tab was submitted but no events — clear them
            update_option( 'meshulash_custom_events', [] );
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
            'custom_events' => 'Custom Events',
            'event_log'   => 'Event Log',
            'diagnostics' => 'Diagnostics',
            'dashboard'   => 'Dashboard',
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
                        case 'custom_events': $this->tab_custom_events( $s ); break;
                        case 'event_log': $this->tab_event_log( $s ); break;
                        case 'diagnostics': $this->tab_diagnostics( $s ); break;
                        case 'dashboard':   $this->tab_dashboard( $s ); break;
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
            $this->render_field( 'LinkedIn Partner ID', 'linkedin_partner_id', $s['linkedin_partner_id'], '123456',
                'text', 'From LinkedIn Campaign Manager &rarr; Insight Tag &rarr; Partner ID.' );
            $this->render_field( 'Snapchat Pixel ID', 'snapchat_pixel_id', $s['snapchat_pixel_id'], 'xxxxxxxx-xxxx-xxxx-xxxx',
                'text', 'From Snapchat Ads Manager &rarr; Events Manager.' );
            $this->render_field( 'Twitter / X Pixel ID', 'twitter_pixel_id', $s['twitter_pixel_id'], 'xxxxx',
                'text', 'From Twitter/X Ads &rarr; Events Manager &rarr; Pixel ID.' );
            $this->render_field( 'Taboola Pixel ID', 'taboola_pixel_id', $s['taboola_pixel_id'], '1234567',
                'text', 'From Taboola Ads &rarr; Tracking &rarr; Taboola Pixel.' );
            $this->render_field( 'Outbrain Pixel ID', 'outbrain_pixel_id', $s['outbrain_pixel_id'], 'xxxxxxxxxxxxxxxx',
                'text', 'From Outbrain Amplify &rarr; Conversions &rarr; Pixel ID.' );
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

            $engagement_labels = [
                'gads_label_outbound_click' => 'Outbound Click',
                'gads_label_form_start'     => 'Form Start',
                'gads_label_form_abandon'   => 'Form Abandon',
                'gads_label_video_play'     => 'Video Play',
                'gads_label_video_progress' => 'Video Progress',
                'gads_label_video_complete' => 'Video Complete',
                'gads_label_share'          => 'Share',
                'gads_label_print_page'     => 'Print Page',
                'gads_label_copy_text'      => 'Copy Text',
                'gads_label_file_download'  => 'File Download',
            ];

            $ecommerce_labels = [
                'gads_label_view_item'       => 'View Item',
                'gads_label_view_item_list'  => 'View Item List',
                'gads_label_remove_from_cart' => 'Remove from Cart',
                'gads_label_view_cart'       => 'View Cart',
                'gads_label_add_shipping'    => 'Add Shipping Info',
                'gads_label_search'          => 'Search',
                'gads_label_login'           => 'Login',
                'gads_label_generate_lead'   => 'Generate Lead',
                'gads_label_form_submit'     => 'Form Submit',
                'gads_label_add_to_wishlist' => 'Add to Wishlist',
                'gads_label_coupon_applied'  => 'Coupon Applied',
                'gads_label_refund'          => 'Refund',
            ];

            $enrichment_labels = [
                'gads_label_variation_select'  => 'Variation Select',
                'gads_label_gallery_click'     => 'Gallery Click',
                'gads_label_checkout_field'    => 'Checkout Field Focus',
                'gads_label_cart_abandonment'  => 'Cart Abandonment',
                'gads_label_quick_view'        => 'Quick View',
                'gads_label_mini_cart'         => 'Mini Cart Open',
            ];

            $subscription_labels = [
                'gads_label_sub_renewal'      => 'Subscription Renewal',
                'gads_label_sub_cancelled'    => 'Subscription Cancelled',
                'gads_label_sub_reactivated'  => 'Subscription Reactivated',
                'gads_label_sub_expired'      => 'Subscription Expired',
                'gads_label_sub_paused'       => 'Subscription Paused',
            ];

            $link_labels = [
                'gads_label_phone_click'     => 'Phone Link Click',
                'gads_label_email_click'     => 'Email Link Click',
                'gads_label_whatsapp_click'  => 'WhatsApp Click',
                'gads_label_social_click'    => 'Social Link Click',
                'gads_label_maps_click'      => 'Maps Click',
                'gads_label_cta_click'       => 'CTA Link Click',
            ];

            $interaction_labels = [
                'gads_label_scroll_depth'    => 'Scroll Depth',
                'gads_label_page_timer'      => 'Page Timer',
            ];

            foreach ( $labels as $key => $label ) {
                $this->render_field( $label, $key, $s[ $key ], 'AbCdEfGhIjKlMn' );
            }
            ?>
        </table>

        <h2>Ecommerce Event Labels</h2>
        <p class="description">Conversion labels for ecommerce events. Leave blank to skip.</p>
        <table class="form-table">
            <?php
            foreach ( $ecommerce_labels as $key => $label ) {
                $this->render_field( $label, $key, $s[ $key ], 'AbCdEfGhIjKlMn' );
            }
            ?>
        </table>

        <h2>Engagement Event Labels</h2>
        <p class="description">Conversion labels for engagement/behavioral events. Leave blank to skip.</p>
        <table class="form-table">
            <?php
            foreach ( $engagement_labels as $key => $label ) {
                $this->render_field( $label, $key, $s[ $key ], 'AbCdEfGhIjKlMn' );
            }
            ?>
        </table>

        <h2>Enrichment Event Labels</h2>
        <p class="description">Conversion labels for WooCommerce enrichment events. Leave blank to skip.</p>
        <table class="form-table">
            <?php
            foreach ( $enrichment_labels as $key => $label ) {
                $this->render_field( $label, $key, $s[ $key ], 'AbCdEfGhIjKlMn' );
            }
            ?>
        </table>

        <h2>Subscription Event Labels</h2>
        <p class="description">Conversion labels for WooCommerce subscription events. Leave blank to skip.</p>
        <table class="form-table">
            <?php
            foreach ( $subscription_labels as $key => $label ) {
                $this->render_field( $label, $key, $s[ $key ], 'AbCdEfGhIjKlMn' );
            }
            ?>
        </table>

        <h2>Link Click Labels</h2>
        <p class="description">Conversion labels for click-to-contact events. Leave blank to skip.</p>
        <table class="form-table">
            <?php
            foreach ( $link_labels as $key => $label ) {
                $this->render_field( $label, $key, $s[ $key ], 'AbCdEfGhIjKlMn' );
            }
            ?>
        </table>

        <h2>Interaction Event Labels</h2>
        <p class="description">Conversion labels for page interaction events. A single label covers all thresholds.</p>
        <table class="form-table">
            <?php
            foreach ( $interaction_labels as $key => $label ) {
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

        <hr>

        <h2>Engagement Events</h2>
        <p class="description">Auto-detected marketing engagement events. No configuration needed — just enable and they work automatically.</p>
        <table class="form-table">
            <?php
            $engagement_events = [
                'event_outbound_click'  => 'Outbound Link Clicks (clicks to external domains)',
                'event_form_start'      => 'Form Start (first field interaction — shows intent)',
                'event_form_abandon'    => 'Form Abandonment (started form but left without submitting)',
                'event_video_tracking'  => 'Video Play/Progress/Complete (YouTube &amp; Vimeo embeds)',
                'event_share'           => 'Social Share (native Web Share API + share button clicks)',
                'event_print'           => 'Print Page (user prints the page)',
                'event_copy'            => 'Copy Text (user copies content from the page)',
            ];
            foreach ( $engagement_events as $key => $label ) {
                $this->render_toggle( $label, $key, $s[ $key ] );
            }
            ?>
        </table>
        <div class="meshulash-info-box">
            <strong>Video tracking:</strong> Automatically detects YouTube and Vimeo embeds. Fires <code>video_play</code>,
            <code>video_progress</code> (25%, 50%, 75%), and <code>video_complete</code> events.<br>
            <strong>Form abandon:</strong> Detected via beacon on page unload — works even when the tab is closed.
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

        <hr>

        <h2>Pinterest Conversions API</h2>
        <p class="description">Server-side events sent directly to Pinterest for better attribution and match rates.</p>
        <table class="form-table">
            <?php
            $this->render_toggle( 'Enable Pinterest CAPI', 'pinterest_capi_enabled', $s['pinterest_capi_enabled'] );
            $this->render_field( 'Ad Account ID', 'pinterest_ad_account_id', $s['pinterest_ad_account_id'], '123456789',
                'text', 'From Pinterest Ads &rarr; Ad Account ID (numeric).' );
            $this->render_field( 'Access Token', 'pinterest_access_token', $s['pinterest_access_token'], 'pina_xxxxxxx...', 'password',
                'Generate in Pinterest Business &rarr; Apps &rarr; Generate Token with <code>ads:write</code> scope.' );
            ?>
        </table>

        <hr>

        <h2>Webhooks</h2>
        <p class="description">Send real-time event data to external services via HTTP webhooks. Compatible with Zapier, Make (Integromat), n8n, and any webhook receiver.</p>
        <table class="form-table">
            <?php
            $this->render_toggle( 'Enable Webhooks', 'webhook_enabled', $s['webhook_enabled'] );
            $this->render_field( 'Webhook URL', 'webhook_url', $s['webhook_url'], 'https://hooks.zapier.com/hooks/catch/...', 'text',
                'The endpoint URL that will receive POST requests with event data.' );
            $this->render_field( 'Webhook Secret', 'webhook_secret', $s['webhook_secret'], '', 'password',
                'Optional. Used to generate an HMAC-SHA256 signature in the <code>X-Meshulash-Signature</code> header for verifying authenticity.' );
            $this->render_field( 'Event Filter', 'webhook_events', $s['webhook_events'], 'all', 'text',
                'Comma-separated event names to send (e.g. <code>Purchase,Lead</code>). Use <code>all</code> to send everything.' );
            ?>
        </table>
        <div class="meshulash-info-box">
            <strong>Webhook payload includes:</strong> event name, event ID, timestamp, custom data, order details (if WC), UTM attribution, and geo data.<br>
            <strong>Headers:</strong> <code>Content-Type: application/json</code>, <code>X-Meshulash-Signature</code> (HMAC-SHA256 if secret is set).
        </div>

        <hr>

        <h2>Event Log</h2>
        <p class="description">Real-time event log for debugging. Stores the last 100 server-side events.</p>
        <table class="form-table">
            <?php
            $this->render_toggle( 'Enable Event Log', 'event_log_enabled', $s['event_log_enabled'] );
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

        <hr>

        <h2>Multi-Touch Attribution Model</h2>
        <p class="description">Choose how conversion credit is distributed across multiple marketing touchpoints (UTM sources). Used in the Dashboard for revenue attribution.</p>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="meshulash_attribution_model">Attribution Model</label></th>
                <td>
                    <select name="meshulash[attribution_model]" id="meshulash_attribution_model">
                        <option value="last_click" <?php selected( $s['attribution_model'], 'last_click' ); ?>>Last Click (default)</option>
                        <option value="first_click" <?php selected( $s['attribution_model'], 'first_click' ); ?>>First Click</option>
                        <option value="linear" <?php selected( $s['attribution_model'], 'linear' ); ?>>Linear</option>
                        <option value="time_decay" <?php selected( $s['attribution_model'], 'time_decay' ); ?>>Time Decay</option>
                        <option value="position_based" <?php selected( $s['attribution_model'], 'position_based' ); ?>>Position-Based (U-Shaped)</option>
                    </select>
                    <p class="description">Determines how revenue is attributed when a customer interacts with multiple sources before converting.</p>
                </td>
            </tr>
        </table>
        <div class="meshulash-info-box">
            <strong>Attribution Models:</strong><br>
            &bull; <strong>Last Click:</strong> 100% credit to the last touchpoint before conversion<br>
            &bull; <strong>First Click:</strong> 100% credit to the first touchpoint<br>
            &bull; <strong>Linear:</strong> Equal credit split across all touchpoints<br>
            &bull; <strong>Time Decay:</strong> More credit to touchpoints closer to conversion (7-day half-life)<br>
            &bull; <strong>Position-Based:</strong> 40% to first, 40% to last, 20% split among middle touchpoints
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
        <h3>Consent Plugin Integration</h3>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="meshulash_consent_integration">Consent Plugin</label></th>
                <td>
                    <select name="meshulash[consent_integration]" id="meshulash_consent_integration">
                        <option value="auto" <?php selected( $s['consent_integration'], 'auto' ); ?>>Auto-detect</option>
                        <option value="cookieyes" <?php selected( $s['consent_integration'], 'cookieyes' ); ?>>CookieYes</option>
                        <option value="complianz" <?php selected( $s['consent_integration'], 'complianz' ); ?>>Complianz</option>
                        <option value="cookiebot" <?php selected( $s['consent_integration'], 'cookiebot' ); ?>>CookieBot</option>
                        <option value="real_cookie_banner" <?php selected( $s['consent_integration'], 'real_cookie_banner' ); ?>>Real Cookie Banner</option>
                        <option value="none" <?php selected( $s['consent_integration'], 'none' ); ?>>None (manual only)</option>
                    </select>
                    <p class="description">Auto-detect listens for all supported plugins. Choose a specific one to reduce overhead.</p>
                </td>
            </tr>
        </table>
        <div class="meshulash-info-box">
            <strong>Supported consent plugins:</strong> CookieYes, Complianz, CookieBot, Real Cookie Banner.<br>
            When a user accepts/rejects cookies, Meshulash automatically updates Google Consent Mode, Facebook, and TikTok consent.<br><br>
            <strong>Manual integration:</strong> Call from your consent banner JS:<br>
            <code>meshulashGrantConsent({ all: true });</code> — grants everything<br>
            <code>meshulashGrantConsent({ analytics: true, ads: false });</code> — analytics only
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

        <h2>Geo-Location Enrichment</h2>
        <p class="description">Enrich server-side events with visitor's geo-location data (country, region, city, timezone) for better ad matching and analytics.</p>
        <table class="form-table">
            <?php
            $this->render_toggle( 'Enable Geo Enrichment', 'geo_enrichment', $s['geo_enrichment'] );
            ?>
        </table>
        <div class="meshulash-info-box">
            <strong>How it works:</strong><br>
            Visitor geo-location is detected using multiple sources (in order of priority):<br>
            &bull; <strong>CloudFlare headers</strong> — instant, no API call (if your site uses CloudFlare)<br>
            &bull; <strong>Server GeoIP headers</strong> — Nginx/Apache GeoIP module, Sucuri<br>
            &bull; <strong>IP API lookup</strong> — free API with 30-day caching per IP<br><br>
            <strong>Data enriched:</strong> country, region, city, timezone, ISP.<br>
            This data is added to Facebook CAPI <code>user_data</code> (hashed city/state/zip/country),
            pushed to the frontend <code>dataLayer</code>, and available in your dashboards &amp; reports.<br><br>
            Built directly into the plugin — no external proxy needed.
        </div>

        <hr>

        <h2>Default Currency</h2>
        <p class="description">Used when WooCommerce is not active (lead-generation sites). WooCommerce sites automatically use the store currency.</p>
        <table class="form-table">
            <?php
            $this->render_field( 'Currency Code', 'default_currency', $s['default_currency'] ?? 'ILS', 'ILS', 'text',
                'ISO 4217 currency code (e.g., USD, EUR, ILS, GBP).' );
            ?>
        </table>

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
            $this->render_toggle( 'LinkedIn Consent', 'consent_linkedin', $s['consent_linkedin'] );
            $this->render_toggle( 'Snapchat Consent', 'consent_snapchat', $s['consent_snapchat'] );
            $this->render_toggle( 'Twitter/X Consent', 'consent_twitter', $s['consent_twitter'] );
            $this->render_toggle( 'Taboola Consent', 'consent_taboola', $s['consent_taboola'] );
            $this->render_toggle( 'Outbrain Consent', 'consent_outbrain', $s['consent_outbrain'] );
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
    //  Tab: Custom Events
    // ──────────────────────────────────────────────
    private function tab_custom_events( $s ) {
        $events = get_option( 'meshulash_custom_events', [] );
        if ( ! is_array( $events ) ) $events = [];
        ?>
        <input type="hidden" name="meshulash_custom_events_tab" value="1">
        <h2>Custom Events</h2>
        <p class="description">Define your own tracking events. Each event fires to the dataLayer and is automatically dispatched to all configured platforms (GA4, Facebook, Google Ads, TikTok, etc.) — both client-side and server-side.</p>

        <div id="meshulash-custom-events-list">
            <?php if ( empty( $events ) ) : ?>
            <div class="meshulash-custom-event-row" data-index="0" style="background:#f9f9f9;border:1px solid #ddd;padding:12px;margin-bottom:12px;border-radius:4px;">
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
                    <div>
                        <label style="display:block;font-size:11px;font-weight:600;margin-bottom:2px;">Event Name *</label>
                        <input type="text" name="meshulash_custom_events[0][event_name]" placeholder="e.g. video_play" class="regular-text" style="width:180px;">
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;font-weight:600;margin-bottom:2px;">CSS Selector *</label>
                        <input type="text" name="meshulash_custom_events[0][selector]" placeholder="e.g. .play-button, #video" class="regular-text" style="width:220px;">
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;font-weight:600;margin-bottom:2px;">Trigger</label>
                        <select name="meshulash_custom_events[0][trigger]" style="width:120px;">
                            <option value="click">Click</option>
                            <option value="submit">Submit</option>
                            <option value="change">Change</option>
                            <option value="focus">Focus</option>
                            <option value="hover">Hover</option>
                            <option value="visibility">Element Visible</option>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;font-weight:600;margin-bottom:2px;">Category</label>
                        <input type="text" name="meshulash_custom_events[0][event_category]" placeholder="optional" style="width:120px;">
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;font-weight:600;margin-bottom:2px;">Label</label>
                        <input type="text" name="meshulash_custom_events[0][event_label]" placeholder="optional" style="width:120px;">
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;font-weight:600;margin-bottom:2px;">Value</label>
                        <input type="text" name="meshulash_custom_events[0][event_value]" placeholder="0" style="width:70px;">
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;font-weight:600;margin-bottom:2px;">Server-Side</label>
                        <input type="checkbox" name="meshulash_custom_events[0][server_side]" value="1">
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;font-weight:600;margin-bottom:2px;">Once</label>
                        <input type="checkbox" name="meshulash_custom_events[0][once]" value="1" checked>
                    </div>
                    <button type="button" class="button meshulash-remove-event" style="color:#d63638;border-color:#d63638;">&times; Remove</button>
                </div>
            </div>
            <?php else : ?>
                <?php foreach ( $events as $i => $ev ) : ?>
                <div class="meshulash-custom-event-row" data-index="<?php echo $i; ?>" style="background:#f9f9f9;border:1px solid #ddd;padding:12px;margin-bottom:12px;border-radius:4px;">
                    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
                        <div>
                            <label style="display:block;font-size:11px;font-weight:600;margin-bottom:2px;">Event Name *</label>
                            <input type="text" name="meshulash_custom_events[<?php echo $i; ?>][event_name]" value="<?php echo esc_attr( $ev['event_name'] ); ?>" class="regular-text" style="width:180px;">
                        </div>
                        <div>
                            <label style="display:block;font-size:11px;font-weight:600;margin-bottom:2px;">CSS Selector *</label>
                            <input type="text" name="meshulash_custom_events[<?php echo $i; ?>][selector]" value="<?php echo esc_attr( $ev['selector'] ); ?>" class="regular-text" style="width:220px;">
                        </div>
                        <div>
                            <label style="display:block;font-size:11px;font-weight:600;margin-bottom:2px;">Trigger</label>
                            <select name="meshulash_custom_events[<?php echo $i; ?>][trigger]" style="width:120px;">
                                <option value="click" <?php selected( $ev['trigger'], 'click' ); ?>>Click</option>
                                <option value="submit" <?php selected( $ev['trigger'], 'submit' ); ?>>Submit</option>
                                <option value="change" <?php selected( $ev['trigger'], 'change' ); ?>>Change</option>
                                <option value="focus" <?php selected( $ev['trigger'], 'focus' ); ?>>Focus</option>
                                <option value="hover" <?php selected( $ev['trigger'], 'hover' ); ?>>Hover</option>
                                <option value="visibility" <?php selected( $ev['trigger'], 'visibility' ); ?>>Element Visible</option>
                            </select>
                        </div>
                        <div>
                            <label style="display:block;font-size:11px;font-weight:600;margin-bottom:2px;">Category</label>
                            <input type="text" name="meshulash_custom_events[<?php echo $i; ?>][event_category]" value="<?php echo esc_attr( $ev['event_category'] ?? '' ); ?>" style="width:120px;">
                        </div>
                        <div>
                            <label style="display:block;font-size:11px;font-weight:600;margin-bottom:2px;">Label</label>
                            <input type="text" name="meshulash_custom_events[<?php echo $i; ?>][event_label]" value="<?php echo esc_attr( $ev['event_label'] ?? '' ); ?>" style="width:120px;">
                        </div>
                        <div>
                            <label style="display:block;font-size:11px;font-weight:600;margin-bottom:2px;">Value</label>
                            <input type="text" name="meshulash_custom_events[<?php echo $i; ?>][event_value]" value="<?php echo esc_attr( $ev['event_value'] ?? '' ); ?>" style="width:70px;">
                        </div>
                        <div>
                            <label style="display:block;font-size:11px;font-weight:600;margin-bottom:2px;">Server-Side</label>
                            <input type="checkbox" name="meshulash_custom_events[<?php echo $i; ?>][server_side]" value="1" <?php checked( ! empty( $ev['server_side'] ) ); ?>>
                        </div>
                        <div>
                            <label style="display:block;font-size:11px;font-weight:600;margin-bottom:2px;">Once</label>
                            <input type="checkbox" name="meshulash_custom_events[<?php echo $i; ?>][once]" value="1" <?php checked( $ev['once'] ?? true ); ?>>
                        </div>
                        <button type="button" class="button meshulash-remove-event" style="color:#d63638;border-color:#d63638;">&times; Remove</button>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <p>
            <button type="button" id="meshulash-add-event" class="button button-secondary">+ Add Custom Event</button>
        </p>

        <div class="meshulash-info-box">
            <strong>How it works:</strong><br>
            &bull; <strong>Event Name:</strong> The event pushed to dataLayer (e.g. <code>video_play</code>, <code>whatsapp_cta_click</code>)<br>
            &bull; <strong>CSS Selector:</strong> Which element to track (e.g. <code>.play-btn</code>, <code>#cta-whatsapp</code>, <code>a[href*="wa.me"]</code>)<br>
            &bull; <strong>Trigger:</strong> What interaction fires the event (click, hover, form submit, visibility on screen, etc.)<br>
            &bull; <strong>Category/Label/Value:</strong> Optional extra data sent with the event<br>
            &bull; <strong>Server-Side:</strong> When checked, the event is also sent via sendBeacon to Facebook CAPI, GA4 Measurement Protocol, and TikTok Events API<br>
            &bull; <strong>Once:</strong> Fire only once per page load (prevents duplicate triggers)<br><br>
            <strong>All platforms:</strong> Custom events are pushed to the dataLayer, which the dispatcher automatically sends to GA4, Facebook (as trackCustom), Google Ads, TikTok, Bing, Pinterest, and Reddit. If "Server-Side" is checked, they're also sent via CAPI/MP/TikTok API.
        </div>

        <script>
        jQuery(function($){
            var $list = $('#meshulash-custom-events-list');
            var idx = $list.find('.meshulash-custom-event-row').length;

            $('#meshulash-add-event').on('click', function(){
                var html = '<div class="meshulash-custom-event-row" data-index="'+idx+'" style="background:#f9f9f9;border:1px solid #ddd;padding:12px;margin-bottom:12px;border-radius:4px;">' +
                    '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">' +
                    '<div><label style="display:block;font-size:11px;font-weight:600;margin-bottom:2px;">Event Name *</label><input type="text" name="meshulash_custom_events['+idx+'][event_name]" placeholder="e.g. video_play" class="regular-text" style="width:180px;"></div>' +
                    '<div><label style="display:block;font-size:11px;font-weight:600;margin-bottom:2px;">CSS Selector *</label><input type="text" name="meshulash_custom_events['+idx+'][selector]" placeholder="e.g. .play-button" class="regular-text" style="width:220px;"></div>' +
                    '<div><label style="display:block;font-size:11px;font-weight:600;margin-bottom:2px;">Trigger</label><select name="meshulash_custom_events['+idx+'][trigger]" style="width:120px;"><option value="click">Click</option><option value="submit">Submit</option><option value="change">Change</option><option value="focus">Focus</option><option value="hover">Hover</option><option value="visibility">Element Visible</option></select></div>' +
                    '<div><label style="display:block;font-size:11px;font-weight:600;margin-bottom:2px;">Category</label><input type="text" name="meshulash_custom_events['+idx+'][event_category]" placeholder="optional" style="width:120px;"></div>' +
                    '<div><label style="display:block;font-size:11px;font-weight:600;margin-bottom:2px;">Label</label><input type="text" name="meshulash_custom_events['+idx+'][event_label]" placeholder="optional" style="width:120px;"></div>' +
                    '<div><label style="display:block;font-size:11px;font-weight:600;margin-bottom:2px;">Value</label><input type="text" name="meshulash_custom_events['+idx+'][event_value]" placeholder="0" style="width:70px;"></div>' +
                    '<div><label style="display:block;font-size:11px;font-weight:600;margin-bottom:2px;">Server-Side</label><input type="checkbox" name="meshulash_custom_events['+idx+'][server_side]" value="1"></div>' +
                    '<div><label style="display:block;font-size:11px;font-weight:600;margin-bottom:2px;">Once</label><input type="checkbox" name="meshulash_custom_events['+idx+'][once]" value="1" checked></div>' +
                    '<button type="button" class="button meshulash-remove-event" style="color:#d63638;border-color:#d63638;">&times; Remove</button>' +
                    '</div></div>';
                $list.append(html);
                idx++;
            });

            $list.on('click', '.meshulash-remove-event', function(){
                $(this).closest('.meshulash-custom-event-row').remove();
            });
        });
        </script>
        <?php
    }

    // ──────────────────────────────────────────────
    //  Tab: Event Log
    // ──────────────────────────────────────────────
    private function tab_event_log( $s ) {
        if ( ! $s['event_log_enabled'] ) {
            echo '<h2>Event Log</h2>';
            echo '<p>Event logging is disabled. Enable it in the <a href="?page=meshulash-marketing&tab=server_side">Server-Side tab</a>.</p>';
            return;
        }

        $entries = Meshulash_Event_Log::get_entries( 100 );
        ?>
        <h2>Real-Time Event Log</h2>
        <p class="description">Last <?php echo count( $entries ); ?> server-side events. Auto-refreshes every 10 seconds.</p>

        <p>
            <button type="button" id="meshulash-refresh-log" class="button button-secondary">Refresh</button>
            <button type="button" id="meshulash-clear-log" class="button" style="color:#d63638;border-color:#d63638;">Clear Log</button>
            <button type="button" id="meshulash-export-log-csv" class="button">Export CSV</button>
        </p>

        <table class="widefat striped" style="max-width:1200px;" id="meshulash-log-table">
            <thead>
                <tr>
                    <th>Time (UTC)</th>
                    <th>Event</th>
                    <th>Source</th>
                    <th>Event ID</th>
                    <th>Value</th>
                    <th>IP</th>
                    <th>URL</th>
                </tr>
            </thead>
            <tbody id="meshulash-log-body">
                <?php if ( empty( $entries ) ) : ?>
                <tr><td colspan="7" style="text-align:center;color:#999;">No events logged yet.</td></tr>
                <?php else : ?>
                <?php foreach ( $entries as $entry ) : ?>
                <tr>
                    <td style="white-space:nowrap;font-size:12px;"><?php echo esc_html( $entry['time'] ); ?></td>
                    <td><strong><?php echo esc_html( $entry['event'] ); ?></strong></td>
                    <td><span style="background:<?php echo $entry['source'] === 'server' ? '#e8f5e9' : '#e3f2fd'; ?>;padding:2px 6px;border-radius:3px;font-size:11px;"><?php echo esc_html( $entry['source'] ); ?></span></td>
                    <td style="font-family:monospace;font-size:11px;"><?php echo esc_html( $entry['event_id'] ?? '' ); ?></td>
                    <td><?php echo isset( $entry['value'] ) ? esc_html( ( $entry['currency'] ?? '' ) . ' ' . number_format( $entry['value'], 2 ) ) : '—'; ?></td>
                    <td style="font-size:12px;"><?php echo esc_html( $entry['ip'] ?? '' ); ?></td>
                    <td style="font-size:11px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html( $entry['url'] ?? '' ); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <script>
        jQuery(function($){
            var ajaxUrl='<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';

            function refreshLog(){
                $.post(ajaxUrl,{action:'meshulash_get_event_log'},function(res){
                    if(!res.success)return;
                    var entries=res.data.entries||[];
                    var $body=$('#meshulash-log-body');
                    if(!entries.length){$body.html('<tr><td colspan="7" style="text-align:center;color:#999;">No events logged yet.</td></tr>');return;}
                    var html='';
                    entries.forEach(function(e){
                        var valStr=e.value!==undefined?(e.currency||'')+' '+ parseFloat(e.value).toFixed(2):'—';
                        var srcColor=e.source==='server'?'#e8f5e9':'#e3f2fd';
                        html+='<tr><td style="white-space:nowrap;font-size:12px;">'+esc(e.time)+'</td><td><strong>'+esc(e.event)+'</strong></td><td><span style="background:'+srcColor+';padding:2px 6px;border-radius:3px;font-size:11px;">'+esc(e.source)+'</span></td><td style="font-family:monospace;font-size:11px;">'+esc(e.event_id||'')+'</td><td>'+esc(valStr)+'</td><td style="font-size:12px;">'+esc(e.ip||'')+'</td><td style="font-size:11px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">'+esc(e.url||'')+'</td></tr>';
                    });
                    $body.html(html);
                });
            }
            function esc(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML;}

            $('#meshulash-refresh-log').on('click',refreshLog);
            setInterval(refreshLog,10000);

            $('#meshulash-clear-log').on('click',function(){
                if(!confirm('Clear all event log entries?'))return;
                $.post(ajaxUrl,{action:'meshulash_clear_event_log'},function(){refreshLog();});
            });

            $('#meshulash-export-log-csv').on('click',function(){
                var rows=[['Time','Event','Source','Event ID','Value','Currency','IP','URL']];
                $('#meshulash-log-body tr').each(function(){
                    var cells=[];
                    $(this).find('td').each(function(){cells.push('"'+$(this).text().replace(/"/g,'""').trim()+'"');});
                    if(cells.length>1)rows.push(cells);
                });
                var csv=rows.map(function(r){return r.join(',');}).join('\n');
                var blob=new Blob(['\uFEFF'+csv],{type:'text/csv;charset=utf-8;'});
                var link=document.createElement('a');
                link.href=URL.createObjectURL(blob);
                link.download='meshulash-event-log-'+new Date().toISOString().slice(0,10)+'.csv';
                link.click();
            });
        });
        </script>
        <?php
    }

    // ──────────────────────────────────────────────
    //  Tab: Diagnostics
    // ──────────────────────────────────────────────
    private function tab_diagnostics( $s ) {
        $checks = [];

        // Tracking mode
        $mode = $s['tracking_mode'];
        if ( $mode === 'gtm' ) {
            $checks[] = [
                'label' => 'Tracking Mode',
                'value' => 'GTM',
                'status' => ! empty( $s['gtm_id'] ) ? 'ok' : 'error',
                'note'   => ! empty( $s['gtm_id'] ) ? $s['gtm_id'] : 'GTM mode selected but no GTM ID set',
            ];
        } else {
            $checks[] = [ 'label' => 'Tracking Mode', 'value' => 'Direct', 'status' => 'ok', 'note' => '' ];
        }

        // GA4
        $checks[] = [
            'label' => 'GA4 Measurement ID',
            'value' => $s['ga4_measurement_id'] ?: '—',
            'status' => ! empty( $s['ga4_measurement_id'] ) ? ( preg_match( '/^G-[A-Z0-9]+$/', $s['ga4_measurement_id'] ) ? 'ok' : 'warn' ) : 'off',
            'note'   => ! empty( $s['ga4_measurement_id'] ) && ! preg_match( '/^G-[A-Z0-9]+$/', $s['ga4_measurement_id'] ) ? 'Invalid format (expected G-XXXXXXX)' : '',
        ];

        // Facebook Pixel
        $checks[] = [
            'label' => 'Facebook Pixel',
            'value' => $s['fb_pixel_id'] ?: '—',
            'status' => ! empty( $s['fb_pixel_id'] ) ? 'ok' : 'off',
            'note'   => '',
        ];

        // Facebook CAPI
        $fb_capi_ok = $s['fb_capi_enabled'] && ! empty( $s['fb_pixel_id'] ) && ! empty( $s['fb_access_token'] );
        $checks[] = [
            'label' => 'Facebook CAPI',
            'value' => $s['fb_capi_enabled'] ? 'Enabled' : 'Disabled',
            'status' => ! $s['fb_capi_enabled'] ? 'off' : ( $fb_capi_ok ? 'ok' : 'error' ),
            'note'   => $s['fb_capi_enabled'] && empty( $s['fb_access_token'] ) ? 'Access token missing' : '',
        ];

        // Google Ads
        $checks[] = [
            'label' => 'Google Ads',
            'value' => $s['gads_conversion_id'] ?: '—',
            'status' => ! empty( $s['gads_conversion_id'] ) ? 'ok' : 'off',
            'note'   => ! empty( $s['gads_conversion_id'] ) && empty( $s['gads_label_purchase'] ) ? 'No purchase conversion label set' : '',
        ];

        // Enhanced Conversions
        $checks[] = [
            'label' => 'Enhanced Conversions',
            'value' => $s['enhanced_conversions'] ? 'Enabled' : 'Disabled',
            'status' => $s['enhanced_conversions'] ? 'ok' : 'off',
            'note'   => '',
        ];

        // TikTok Pixel
        $checks[] = [
            'label' => 'TikTok Pixel',
            'value' => $s['tt_pixel_id'] ?: '—',
            'status' => ! empty( $s['tt_pixel_id'] ) ? 'ok' : 'off',
            'note'   => '',
        ];

        // TikTok Events API
        $tt_api_ok = $s['tt_api_enabled'] && ! empty( $s['tt_pixel_id'] ) && ! empty( $s['tt_access_token'] );
        $checks[] = [
            'label' => 'TikTok Events API',
            'value' => $s['tt_api_enabled'] ? 'Enabled' : 'Disabled',
            'status' => ! $s['tt_api_enabled'] ? 'off' : ( $tt_api_ok ? 'ok' : 'error' ),
            'note'   => $s['tt_api_enabled'] && empty( $s['tt_access_token'] ) ? 'Access token missing' : '',
        ];

        // GA4 Measurement Protocol
        $ga4_mp_ok = $s['ga4_mp_enabled'] && ! empty( $s['ga4_measurement_id'] ) && ! empty( $s['ga4_api_secret'] );
        $checks[] = [
            'label' => 'GA4 Measurement Protocol',
            'value' => $s['ga4_mp_enabled'] ? 'Enabled' : 'Disabled',
            'status' => ! $s['ga4_mp_enabled'] ? 'off' : ( $ga4_mp_ok ? 'ok' : 'error' ),
            'note'   => $s['ga4_mp_enabled'] && empty( $s['ga4_api_secret'] ) ? 'API secret missing' : '',
        ];

        // Bing
        $checks[] = [
            'label' => 'Bing UET',
            'value' => $s['bing_uet_id'] ?: '—',
            'status' => ! empty( $s['bing_uet_id'] ) ? 'ok' : 'off',
            'note'   => '',
        ];

        // Pinterest
        $checks[] = [
            'label' => 'Pinterest Tag',
            'value' => $s['pinterest_tag_id'] ?: '—',
            'status' => ! empty( $s['pinterest_tag_id'] ) ? 'ok' : 'off',
            'note'   => '',
        ];

        // Pinterest CAPI
        $pin_capi_ok = $s['pinterest_capi_enabled'] && ! empty( $s['pinterest_access_token'] ) && ! empty( $s['pinterest_ad_account_id'] );
        $checks[] = [
            'label' => 'Pinterest CAPI',
            'value' => $s['pinterest_capi_enabled'] ? 'Enabled' : 'Disabled',
            'status' => ! $s['pinterest_capi_enabled'] ? 'off' : ( $pin_capi_ok ? 'ok' : 'error' ),
            'note'   => $s['pinterest_capi_enabled'] && empty( $s['pinterest_access_token'] ) ? 'Access token missing' : '',
        ];

        // LinkedIn
        $checks[] = [
            'label' => 'LinkedIn Insight Tag',
            'value' => $s['linkedin_partner_id'] ?: '—',
            'status' => ! empty( $s['linkedin_partner_id'] ) ? 'ok' : 'off',
            'note'   => '',
        ];

        // Snapchat
        $checks[] = [
            'label' => 'Snapchat Pixel',
            'value' => $s['snapchat_pixel_id'] ?: '—',
            'status' => ! empty( $s['snapchat_pixel_id'] ) ? 'ok' : 'off',
            'note'   => '',
        ];

        // Twitter/X
        $checks[] = [
            'label' => 'Twitter/X Pixel',
            'value' => $s['twitter_pixel_id'] ?: '—',
            'status' => ! empty( $s['twitter_pixel_id'] ) ? 'ok' : 'off',
            'note'   => '',
        ];

        // Taboola
        $checks[] = [
            'label' => 'Taboola Pixel',
            'value' => $s['taboola_pixel_id'] ?: '—',
            'status' => ! empty( $s['taboola_pixel_id'] ) ? 'ok' : 'off',
            'note'   => '',
        ];

        // Outbrain
        $checks[] = [
            'label' => 'Outbrain Pixel',
            'value' => $s['outbrain_pixel_id'] ?: '—',
            'status' => ! empty( $s['outbrain_pixel_id'] ) ? 'ok' : 'off',
            'note'   => '',
        ];

        // Webhooks
        $checks[] = [
            'label' => 'Webhooks',
            'value' => $s['webhook_enabled'] ? 'Enabled' : 'Disabled',
            'status' => ! $s['webhook_enabled'] ? 'off' : ( ! empty( $s['webhook_url'] ) ? 'ok' : 'error' ),
            'note'   => $s['webhook_enabled'] && empty( $s['webhook_url'] ) ? 'Webhook URL missing' : '',
        ];

        // Event Log
        $checks[] = [
            'label' => 'Event Log',
            'value' => $s['event_log_enabled'] ? 'Enabled' : 'Disabled',
            'status' => $s['event_log_enabled'] ? 'ok' : 'off',
            'note'   => '',
        ];

        // Consent Mode
        $checks[] = [
            'label' => 'Consent Mode v2',
            'value' => $s['consent_mode'] ? 'Enabled (' . $s['consent_integration'] . ')' : 'Disabled',
            'status' => $s['consent_mode'] ? 'ok' : 'off',
            'note'   => '',
        ];

        // UTM Tracking
        $checks[] = [
            'label' => 'UTM Tracking',
            'value' => $s['utm_enabled'] ? 'Enabled' : 'Disabled',
            'status' => $s['utm_enabled'] ? 'ok' : 'off',
            'note'   => '',
        ];

        // Customer Journey
        $checks[] = [
            'label' => 'Customer Journey',
            'value' => $s['journey_enabled'] ? 'Enabled' : 'Disabled',
            'status' => $s['journey_enabled'] ? 'ok' : 'off',
            'note'   => '',
        ];

        // WooCommerce
        $woo_active = class_exists( 'WooCommerce' );
        $checks[] = [
            'label' => 'WooCommerce',
            'value' => $woo_active ? 'Active (v' . WC()->version . ')' : 'Not installed (Lead Mode)',
            'status' => $woo_active ? 'ok' : 'ok',
            'note'   => ! $woo_active ? 'Core tracking + lead gen active. Install WooCommerce for ecommerce events.' : '',
        ];

        // Geo Enrichment
        $geo_enabled = $s['geo_enrichment'];
        $geo_source = '';
        if ( $geo_enabled ) {
            if ( ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
                $geo_source = 'CloudFlare';
            } elseif ( ! empty( $_SERVER['GEOIP_COUNTRY_CODE'] ) ) {
                $geo_source = 'Server GeoIP';
            } else {
                $geo_source = 'IP API (cached)';
            }
        }
        $checks[] = [
            'label' => 'Geo Enrichment',
            'value' => $geo_enabled ? 'Enabled (' . $geo_source . ')' : 'Disabled',
            'status' => $geo_enabled ? 'ok' : 'off',
            'note'   => $geo_enabled ? 'Events enriched with country, region, city, timezone' : '',
        ];

        // Mumble
        if ( $s['mumble_enabled'] ) {
            $checks[] = [
                'label' => 'Mumble WhatsApp',
                'value' => 'Enabled',
                'status' => ! empty( $s['mumble_api_key'] ) ? 'ok' : 'error',
                'note'   => empty( $s['mumble_api_key'] ) ? 'API key missing' : '',
            ];
        }

        // Engagement Events summary
        $eng_events = [ 'event_outbound_click', 'event_form_start', 'event_form_abandon', 'event_video_tracking', 'event_share', 'event_print', 'event_copy' ];
        $eng_active = array_filter( $eng_events, function( $k ) use ( $s ) { return ! empty( $s[ $k ] ); } );
        $checks[] = [
            'label' => 'Engagement Events',
            'value' => count( $eng_active ) . '/' . count( $eng_events ) . ' enabled',
            'status' => count( $eng_active ) > 0 ? 'ok' : 'off',
            'note'   => count( $eng_active ) > 0 ? implode( ', ', array_map( function( $k ) { return str_replace( 'event_', '', $k ); }, $eng_active ) ) : '',
        ];

        $icons = [ 'ok' => '&#9989;', 'warn' => '&#9888;&#65039;', 'error' => '&#10060;', 'off' => '&#9898;' ];
        ?>
        <h2>Diagnostics</h2>
        <p class="description">Health check for all Meshulash integrations. Green = working, Red = error, Gray = disabled.</p>

        <table class="widefat striped" style="max-width:800px;">
            <thead>
                <tr>
                    <th style="width:30px;"></th>
                    <th>Integration</th>
                    <th>Status</th>
                    <th>Note</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $checks as $check ) : ?>
                <tr>
                    <td style="text-align:center;"><?php echo $icons[ $check['status'] ]; ?></td>
                    <td><strong><?php echo esc_html( $check['label'] ); ?></strong></td>
                    <td><?php echo esc_html( $check['value'] ); ?></td>
                    <td style="color:<?php echo $check['status'] === 'error' ? '#d63638' : ( $check['status'] === 'warn' ? '#dba617' : '#666' ); ?>;">
                        <?php echo esc_html( $check['note'] ); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <hr>

        <h2>Active Events</h2>
        <p class="description">Events currently enabled in the Events tab.</p>
        <?php
        $defaults = Meshulash_Settings::defaults();
        $active = [];
        $inactive = [];
        foreach ( $defaults as $key => $val ) {
            if ( strpos( $key, 'event_' ) === 0 && is_bool( $val ) ) {
                $label = ucwords( str_replace( '_', ' ', str_replace( 'event_', '', $key ) ) );
                if ( $s[ $key ] ) {
                    $active[] = $label;
                } else {
                    $inactive[] = $label;
                }
            }
        }
        ?>
        <p>
            <strong style="color:#00a32a;"><?php echo count( $active ); ?> active:</strong>
            <?php echo esc_html( implode( ', ', $active ) ); ?>
        </p>
        <?php if ( ! empty( $inactive ) ) : ?>
        <p>
            <strong style="color:#999;"><?php echo count( $inactive ); ?> disabled:</strong>
            <?php echo esc_html( implode( ', ', $inactive ) ); ?>
        </p>
        <?php endif; ?>

        <hr>

        <h2>Environment</h2>
        <table class="widefat striped" style="max-width:800px;">
            <tbody>
                <tr><td style="width:200px;"><strong>Plugin Version</strong></td><td><?php echo esc_html( MESHULASH_VERSION ); ?></td></tr>
                <tr><td><strong>WordPress</strong></td><td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td></tr>
                <tr><td><strong>PHP</strong></td><td><?php echo esc_html( PHP_VERSION ); ?></td></tr>
                <tr><td><strong>Site URL</strong></td><td><?php echo esc_html( get_site_url() ); ?></td></tr>
                <tr><td><strong>SSL</strong></td><td><?php echo is_ssl() ? '&#9989; Yes' : '&#9888;&#65039; No (recommended for tracking)'; ?></td></tr>
            </tbody>
        </table>
        <?php
    }

    // ──────────────────────────────────────────────
    //  Tab: Dashboard (Revenue by Source)
    // ──────────────────────────────────────────────
    private function tab_dashboard( $s ) {
        $days = isset( $_GET['days'] ) ? intval( $_GET['days'] ) : 30;
        if ( ! in_array( $days, [ 7, 30, 90, 365 ], true ) ) $days = 30;

        if ( ! class_exists( 'WooCommerce' ) ) {
            echo '<p>WooCommerce is required for the dashboard.</p>';
            return;
        }

        // Query orders with UTM data
        $date_after = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
        $orders = wc_get_orders([
            'status'     => [ 'wc-completed', 'wc-processing' ],
            'limit'      => -1,
            'date_after'  => $date_after,
            'meta_key'   => '_meshulash_utm',
            'meta_compare' => 'EXISTS',
        ]);

        $by_source   = [];
        $by_medium   = [];
        $by_campaign = [];
        $by_coupon   = [];
        $total_rev   = 0;
        $total_orders = 0;

        foreach ( $orders as $order ) {
            $utm = $order->get_meta( '_meshulash_utm' );
            $hidden = $order->get_meta( '_meshulash_hidden_fields' );
            $all = array_merge(
                is_array( $utm ) ? $utm : [],
                is_array( $hidden ) ? $hidden : []
            );

            $source   = ! empty( $all['utm_source'] ) && $all['utm_source'] !== 'null' ? $all['utm_source'] : 'direct';
            $medium   = ! empty( $all['utm_medium'] ) && $all['utm_medium'] !== 'null' ? $all['utm_medium'] : 'none';
            $campaign = ! empty( $all['utm_campaign'] ) && $all['utm_campaign'] !== 'null' ? $all['utm_campaign'] : '(none)';
            $revenue  = (float) $order->get_total();

            $total_rev += $revenue;
            $total_orders++;

            // By source
            if ( ! isset( $by_source[ $source ] ) ) $by_source[ $source ] = [ 'orders' => 0, 'revenue' => 0 ];
            $by_source[ $source ]['orders']++;
            $by_source[ $source ]['revenue'] += $revenue;

            // By medium
            if ( ! isset( $by_medium[ $medium ] ) ) $by_medium[ $medium ] = [ 'orders' => 0, 'revenue' => 0 ];
            $by_medium[ $medium ]['orders']++;
            $by_medium[ $medium ]['revenue'] += $revenue;

            // By campaign
            if ( $campaign !== '(none)' ) {
                if ( ! isset( $by_campaign[ $campaign ] ) ) $by_campaign[ $campaign ] = [ 'orders' => 0, 'revenue' => 0 ];
                $by_campaign[ $campaign ]['orders']++;
                $by_campaign[ $campaign ]['revenue'] += $revenue;
            }

            // Coupon attribution
            $coupon_attr = $order->get_meta( '_meshulash_coupon_attribution' );
            if ( is_array( $coupon_attr ) ) {
                foreach ( $coupon_attr as $ca ) {
                    $code = $ca['coupon'];
                    if ( ! isset( $by_coupon[ $code ] ) ) $by_coupon[ $code ] = [ 'orders' => 0, 'revenue' => 0, 'source' => $ca['utm_source'] ];
                    $by_coupon[ $code ]['orders']++;
                    $by_coupon[ $code ]['revenue'] += $revenue;
                }
            }
        }

        // Sort by revenue desc
        uasort( $by_source, function( $a, $b ) { return $b['revenue'] <=> $a['revenue']; } );
        uasort( $by_medium, function( $a, $b ) { return $b['revenue'] <=> $a['revenue']; } );
        uasort( $by_campaign, function( $a, $b ) { return $b['revenue'] <=> $a['revenue']; } );
        uasort( $by_coupon, function( $a, $b ) { return $b['revenue'] <=> $a['revenue']; } );

        $currency = get_woocommerce_currency_symbol();
        $base_url = admin_url( 'admin.php?page=meshulash-marketing&tab=dashboard' );
        ?>
        <h2>Revenue Dashboard</h2>
        <p class="description">Revenue attribution based on UTM data stored on orders.</p>

        <div style="margin:10px 0 20px;">
            <?php foreach ( [ 7, 30, 90, 365 ] as $d ) : ?>
                <a href="<?php echo esc_url( $base_url . '&days=' . $d ); ?>"
                   class="button <?php echo $days === $d ? 'button-primary' : ''; ?>">
                    <?php echo $d === 365 ? '1 Year' : $d . ' Days'; ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Summary Cards -->
        <div style="display:flex;gap:16px;margin-bottom:24px;">
            <div style="flex:1;padding:16px;background:#f0f6fc;border-left:4px solid #2271b1;border-radius:4px;">
                <div style="font-size:24px;font-weight:700;"><?php echo esc_html( $currency . number_format( $total_rev, 0 ) ); ?></div>
                <div style="color:#666;">Total Revenue</div>
            </div>
            <div style="flex:1;padding:16px;background:#e8f5e9;border-left:4px solid #00a32a;border-radius:4px;">
                <div style="font-size:24px;font-weight:700;"><?php echo $total_orders; ?></div>
                <div style="color:#666;">Orders</div>
            </div>
            <div style="flex:1;padding:16px;background:#fff8e1;border-left:4px solid #dba617;border-radius:4px;">
                <div style="font-size:24px;font-weight:700;"><?php echo $total_orders ? esc_html( $currency . number_format( $total_rev / $total_orders, 0 ) ) : '—'; ?></div>
                <div style="color:#666;">AOV (Avg. Order Value)</div>
            </div>
            <div style="flex:1;padding:16px;background:#fce4ec;border-left:4px solid #d63638;border-radius:4px;">
                <div style="font-size:24px;font-weight:700;"><?php echo count( $by_source ); ?></div>
                <div style="color:#666;">Sources</div>
            </div>
        </div>

        <!-- By Source -->
        <h3>By Source</h3>
        <?php $this->render_dashboard_table( $by_source, $currency, $total_rev ); ?>

        <!-- By Medium -->
        <h3>By Medium</h3>
        <?php $this->render_dashboard_table( $by_medium, $currency, $total_rev ); ?>

        <?php if ( ! empty( $by_campaign ) ) : ?>
        <!-- By Campaign -->
        <h3>By Campaign</h3>
        <?php $this->render_dashboard_table( $by_campaign, $currency, $total_rev ); ?>
        <?php endif; ?>

        <?php if ( ! empty( $by_coupon ) ) : ?>
        <!-- Coupon Attribution -->
        <h3>Coupon Attribution</h3>
        <table class="widefat striped" style="max-width:800px;">
            <thead>
                <tr>
                    <th>Coupon</th>
                    <th>Source</th>
                    <th style="text-align:right;">Orders</th>
                    <th style="text-align:right;">Revenue</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $by_coupon as $code => $data ) : ?>
                <tr>
                    <td><strong><?php echo esc_html( $code ); ?></strong></td>
                    <td><?php echo esc_html( $data['source'] ); ?></td>
                    <td style="text-align:right;"><?php echo $data['orders']; ?></td>
                    <td style="text-align:right;"><?php echo esc_html( $currency . number_format( $data['revenue'], 0 ) ); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?php if ( $total_orders === 0 ) : ?>
        <div class="meshulash-info-box">
            No orders with UTM data found in the last <?php echo $days; ?> days. UTM data is captured on checkout
            when customers arrive via UTM-tagged links. Try a longer date range or check that UTM tracking is enabled.
        </div>
        <?php endif; ?>

        <?php if ( $total_orders > 0 ) : ?>
        <hr>
        <h3>Export Data</h3>
        <p>
            <button type="button" id="meshulash-export-csv" class="button button-secondary">Export Dashboard as CSV</button>
        </p>
        <script>
        jQuery(function($){
            $('#meshulash-export-csv').on('click',function(){
                var rows=[['Type','Name','Orders','Revenue','AOV','Share %']];
                <?php
                foreach ( $by_source as $name => $row ) {
                    $pct = $total_rev > 0 ? round( ( $row['revenue'] / $total_rev ) * 100, 1 ) : 0;
                    $aov = $row['orders'] > 0 ? round( $row['revenue'] / $row['orders'], 2 ) : 0;
                    echo "rows.push(['Source'," . wp_json_encode( $name ) . "," . $row['orders'] . "," . round( $row['revenue'], 2 ) . "," . $aov . "," . $pct . "]);\n";
                }
                foreach ( $by_medium as $name => $row ) {
                    $pct = $total_rev > 0 ? round( ( $row['revenue'] / $total_rev ) * 100, 1 ) : 0;
                    $aov = $row['orders'] > 0 ? round( $row['revenue'] / $row['orders'], 2 ) : 0;
                    echo "rows.push(['Medium'," . wp_json_encode( $name ) . "," . $row['orders'] . "," . round( $row['revenue'], 2 ) . "," . $aov . "," . $pct . "]);\n";
                }
                foreach ( $by_campaign as $name => $row ) {
                    $pct = $total_rev > 0 ? round( ( $row['revenue'] / $total_rev ) * 100, 1 ) : 0;
                    $aov = $row['orders'] > 0 ? round( $row['revenue'] / $row['orders'], 2 ) : 0;
                    echo "rows.push(['Campaign'," . wp_json_encode( $name ) . "," . $row['orders'] . "," . round( $row['revenue'], 2 ) . "," . $aov . "," . $pct . "]);\n";
                }
                ?>
                var csv=rows.map(function(r){return r.map(function(c){return '"'+String(c).replace(/"/g,'""')+'"';}).join(',');}).join('\n');
                var blob=new Blob(['\uFEFF'+csv],{type:'text/csv;charset=utf-8;'});
                var link=document.createElement('a');
                link.href=URL.createObjectURL(blob);
                link.download='meshulash-dashboard-<?php echo $days; ?>d-'+new Date().toISOString().slice(0,10)+'.csv';
                link.click();
            });
        });
        </script>
        <?php endif; ?>
        <?php
    }

    /**
     * Helper: render a revenue attribution table.
     */
    private function render_dashboard_table( $data, $currency, $total_rev ) {
        if ( empty( $data ) ) {
            echo '<p style="color:#999;">No data.</p>';
            return;
        }
        ?>
        <table class="widefat striped" style="max-width:800px;margin-bottom:24px;">
            <thead>
                <tr>
                    <th>Name</th>
                    <th style="text-align:right;">Orders</th>
                    <th style="text-align:right;">Revenue</th>
                    <th style="text-align:right;">AOV</th>
                    <th style="text-align:right;">Share</th>
                    <th style="width:200px;"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $data as $name => $row ) :
                    $pct = $total_rev > 0 ? ( $row['revenue'] / $total_rev ) * 100 : 0;
                    $aov = $row['orders'] > 0 ? $row['revenue'] / $row['orders'] : 0;
                ?>
                <tr>
                    <td><strong><?php echo esc_html( $name ); ?></strong></td>
                    <td style="text-align:right;"><?php echo $row['orders']; ?></td>
                    <td style="text-align:right;"><?php echo esc_html( $currency . number_format( $row['revenue'], 0 ) ); ?></td>
                    <td style="text-align:right;"><?php echo esc_html( $currency . number_format( $aov, 0 ) ); ?></td>
                    <td style="text-align:right;"><?php echo number_format( $pct, 1 ); ?>%</td>
                    <td>
                        <div style="background:#e0e0e0;border-radius:3px;height:14px;overflow:hidden;">
                            <div style="background:#2271b1;height:100%;width:<?php echo number_format( $pct, 1 ); ?>%;"></div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
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
