<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Meshulash_UTM {

    private static $utm_fields = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_id', 'campaign_id',
        'utm_content', 'adset_id', 'utm_ad', 'ad_id', 'utm_term', 'keyword_id',
        'device', 'GeoLoc', 'IntLoc', 'placement', 'matchtype', 'network',
        'gclid', 'wbraid', 'gbraid', 'fbclid', 'obcid', 'msclkid', 'li_fat_id',
        'tblci', 'ttcid', 'pmcid', 'yclid', 'vmcid', 'twclid', 'first_touch_url',
    ];

    public function __construct() {
        $utm_enabled     = Meshulash_Settings::get( 'utm_enabled' );
        $journey_enabled = Meshulash_Settings::get( 'journey_enabled' );

        if ( ! $utm_enabled && ! $journey_enabled ) return;

        // Save UTM + journey data to order (HPOS compatible)
        add_action( 'woocommerce_checkout_order_created', [ $this, 'save_utm_to_order' ] );

        // Admin: meta boxes on order page
        add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );

        if ( $utm_enabled ) {
            // Admin: UTM source column in orders list
            add_filter( 'manage_woocommerce_page_wc-orders_columns', [ $this, 'add_column' ] );
            add_action( 'manage_woocommerce_page_wc-orders_custom_column', [ $this, 'render_column' ], 10, 2 );

            // Legacy (CPT-based orders)
            add_filter( 'manage_edit-shop_order_columns', [ $this, 'add_column' ] );
            add_action( 'manage_shop_order_posts_custom_column', [ $this, 'render_column_legacy' ], 10, 2 );
        }
    }

    /**
     * Save UTM data from cookies to order meta (HPOS compatible).
     */
    public function save_utm_to_order( $order ) {
        // UTM cookie data
        if ( Meshulash_Settings::get( 'utm_enabled' ) ) {
            $tracking = [];

            foreach ( self::$utm_fields as $field ) {
                $tracking[ $field ] = isset( $_COOKIE[ $field ] )
                    ? sanitize_text_field( $_COOKIE[ $field ] )
                    : '';
            }

            $has_data = array_filter( $tracking );
            if ( ! empty( $has_data ) ) {
                $order->update_meta_data( '_meshulash_utm', $tracking );
            }
        }

        // Extract journey and extra data from the unified hidden_fields input
        if ( isset( $_POST['hidden_fields'] ) ) {
            $hidden_raw = sanitize_text_field( wp_unslash( $_POST['hidden_fields'] ) );
            $hidden = json_decode( $hidden_raw, true );

            if ( is_array( $hidden ) ) {
                // Save customer journey if available
                if ( Meshulash_Settings::get( 'journey_enabled' ) && ! empty( $hidden['customer_journey'] ) && is_array( $hidden['customer_journey'] ) ) {
                    $clean_journey = [];
                    foreach ( $hidden['customer_journey'] as $step ) {
                        if ( ! is_array( $step ) ) continue;
                        $clean_step = [
                            'ts'    => isset( $step['ts'] ) ? intval( $step['ts'] ) : 0,
                            'type'  => isset( $step['type'] ) ? sanitize_text_field( $step['type'] ) : 'page',
                            'url'   => isset( $step['url'] ) ? sanitize_text_field( $step['url'] ) : '',
                            'title' => isset( $step['title'] ) ? sanitize_text_field( $step['title'] ) : '',
                        ];
                        if ( ! empty( $step['event'] ) )       $clean_step['event'] = sanitize_text_field( $step['event'] );
                        if ( ! empty( $step['product'] ) )     $clean_step['product'] = sanitize_text_field( $step['product'] );
                        if ( ! empty( $step['search_term'] ) ) $clean_step['search_term'] = sanitize_text_field( $step['search_term'] );
                        if ( ! empty( $step['referrer'] ) )    $clean_step['referrer'] = sanitize_url( $step['referrer'] );
                        if ( ! empty( $step['utm_source'] ) )  $clean_step['utm_source'] = sanitize_text_field( $step['utm_source'] );
                        $clean_journey[] = $clean_step;
                    }
                    if ( ! empty( $clean_journey ) ) {
                        $order->update_meta_data( '_meshulash_journey', $clean_journey );
                    }
                }

                // Save the full hidden_fields dict as order meta (for reference)
                unset( $hidden['customer_journey'] ); // already saved separately
                if ( ! empty( $hidden ) ) {
                    $order->update_meta_data( '_meshulash_hidden_fields', $hidden );
                }
            }
        }

        $order->save();
    }

    /**
     * Add meta boxes to order admin.
     */
    public function add_meta_box() {
        $screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' )
            && wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id( 'shop-order' )
            : 'shop_order';

        if ( Meshulash_Settings::get( 'utm_enabled' ) ) {
            add_meta_box(
                'meshulash_utm_data',
                'Meshulash — Marketing Attribution',
                [ $this, 'render_meta_box' ],
                $screen,
                'side',
                'default'
            );
        }

        if ( Meshulash_Settings::get( 'journey_enabled' ) ) {
            add_meta_box(
                'meshulash_journey_data',
                'Meshulash — Customer Journey',
                [ $this, 'render_journey_meta_box' ],
                $screen,
                'normal',
                'default'
            );
        }
    }

    /**
     * Render UTM meta box content (combines cookie-based UTM + hidden fields data).
     */
    public function render_meta_box( $post_or_order ) {
        $order = ( $post_or_order instanceof WP_Post )
            ? wc_get_order( $post_or_order->ID )
            : $post_or_order;

        if ( ! $order ) return;

        // Merge cookie-based UTM data with hidden_fields data
        $tracking      = $order->get_meta( '_meshulash_utm' );
        $hidden_fields = $order->get_meta( '_meshulash_hidden_fields' );

        if ( ! is_array( $tracking ) )      $tracking = [];
        if ( ! is_array( $hidden_fields ) ) $hidden_fields = [];

        // Hidden fields override cookie-based (they're more complete)
        $all_data = array_merge( $tracking, $hidden_fields );

        // Remove "null" string values (JS sends "null" for empty fields)
        $all_data = array_filter( $all_data, function( $v ) {
            return $v !== '' && $v !== 'null';
        });

        if ( empty( $all_data ) ) {
            echo '<p style="color:#999;">No tracking data captured.</p>';
            return;
        }

        // Group fields for readability
        $groups = [
            'Campaign' => [
                'utm_source', 'utm_medium', 'utm_campaign', 'utm_id', 'campaign_id',
                'utm_content', 'adset_id', 'utm_ad', 'ad_id', 'utm_term', 'keyword_id',
            ],
            'Ad Platform' => [
                'device', 'GeoLoc', 'IntLoc', 'placement', 'matchtype', 'network',
            ],
            'Click IDs' => [
                'gclid', 'wbraid', 'gbraid', 'fbclid', 'obcid', 'msclkid', 'li_fat_id',
                'tblci', 'ttcid', 'pmcid', 'yclid', 'vmcid', 'twclid',
            ],
            'Pixel & Analytics' => [
                'ga_cid', 'ga_session_id', 'fbp', 'fbc', 'gcl_aw', 'gcl_dc', 'ttp',
            ],
            'Landing' => [
                'first_touch_url', 'page_url', 'page_referrer',
            ],
            'Device & Browser' => [
                'device_type', 'screen_resolution', 'viewport', 'language', 'timezone', 'user_agent',
            ],
        ];

        // Collect keys that don't belong to any group
        $grouped_keys = [];
        foreach ( $groups as $fields ) {
            $grouped_keys = array_merge( $grouped_keys, $fields );
        }

        echo '<div style="max-height:500px;overflow-y:auto;">';

        foreach ( $groups as $group_label => $fields ) {
            $group_data = [];
            foreach ( $fields as $key ) {
                if ( isset( $all_data[ $key ] ) ) {
                    $group_data[ $key ] = $all_data[ $key ];
                }
            }
            if ( empty( $group_data ) ) continue;

            echo '<p style="margin:10px 0 4px;font-weight:600;color:#1d2327;font-size:12px;text-transform:uppercase;letter-spacing:0.5px;">' . esc_html( $group_label ) . '</p>';
            echo '<table class="widefat striped" style="border:0;margin-bottom:4px;">';
            echo '<tbody>';
            foreach ( $group_data as $key => $value ) {
                $label = ucwords( str_replace( '_', ' ', $key ) );
                echo '<tr>';
                echo '<td style="padding:3px 8px;font-weight:500;width:40%;font-size:12px;">' . esc_html( $label ) . '</td>';
                echo '<td style="padding:3px 8px;word-break:break-all;font-size:12px;">' . esc_html( $value ) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        // Show any extra fields not in predefined groups
        $extra = array_diff_key( $all_data, array_flip( $grouped_keys ) );
        // Exclude noisy fields
        unset( $extra['page_title'], $extra['timestamp'], $extra['customer_journey'] );
        $extra = array_filter( $extra, function( $v ) {
            return $v !== '' && $v !== 'null';
        });

        if ( ! empty( $extra ) ) {
            echo '<p style="margin:10px 0 4px;font-weight:600;color:#1d2327;font-size:12px;text-transform:uppercase;letter-spacing:0.5px;">Other</p>';
            echo '<table class="widefat striped" style="border:0;">';
            echo '<tbody>';
            foreach ( $extra as $key => $value ) {
                $label = ucwords( str_replace( '_', ' ', $key ) );
                echo '<tr>';
                echo '<td style="padding:3px 8px;font-weight:500;width:40%;font-size:12px;">' . esc_html( $label ) . '</td>';
                echo '<td style="padding:3px 8px;word-break:break-all;font-size:12px;">' . esc_html( $value ) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '</div>';
    }

    /**
     * Render Customer Journey timeline meta box.
     */
    public function render_journey_meta_box( $post_or_order ) {
        $order = ( $post_or_order instanceof WP_Post )
            ? wc_get_order( $post_or_order->ID )
            : $post_or_order;

        if ( ! $order ) return;

        $journey = $order->get_meta( '_meshulash_journey' );

        if ( ! $journey || ! is_array( $journey ) || empty( $journey ) ) {
            echo '<p style="color:#999;">No journey data captured for this order.</p>';
            return;
        }

        $type_icons = [
            'page'      => '&#128196;',  // page
            'product'   => '&#128722;',  // shopping bag
            'category'  => '&#128193;',  // folder
            'tag'       => '&#127991;',  // label/tag
            'cart'      => '&#128722;',  // cart
            'checkout'  => '&#128179;',  // credit card
            'thank_you' => '&#9989;',    // check
            'search'    => '&#128269;',  // magnifier
            'event'     => '&#9889;',    // lightning
        ];

        $type_labels = [
            'page'      => 'Page',
            'product'   => 'Product',
            'category'  => 'Category',
            'tag'       => 'Tag',
            'cart'      => 'Cart',
            'checkout'  => 'Checkout',
            'thank_you' => 'Thank You',
            'search'    => 'Search',
            'event'     => 'Event',
        ];

        $total_steps = count( $journey );
        $first_ts    = $journey[0]['ts'] ?? 0;
        $last_ts     = end( $journey )['ts'] ?? 0;
        $duration    = $last_ts - $first_ts;

        // Summary
        echo '<div style="margin-bottom:12px;padding:8px 12px;background:#f0f6fc;border-left:3px solid #2271b1;border-radius:3px;">';
        echo '<strong>' . $total_steps . ' steps</strong>';
        if ( $duration > 0 ) {
            if ( $duration < 60 ) {
                echo ' &middot; ' . $duration . 's total';
            } elseif ( $duration < 3600 ) {
                echo ' &middot; ' . round( $duration / 60 ) . ' min total';
            } elseif ( $duration < 86400 ) {
                echo ' &middot; ' . round( $duration / 3600, 1 ) . ' hours total';
            } else {
                echo ' &middot; ' . round( $duration / 86400, 1 ) . ' days total';
            }
        }
        echo '</div>';

        // Timeline
        echo '<div class="meshulash-journey-timeline" style="position:relative;padding-left:24px;">';
        echo '<div style="position:absolute;left:10px;top:0;bottom:0;width:2px;background:#ddd;"></div>';

        foreach ( $journey as $i => $step ) {
            $type  = $step['type'] ?? 'page';
            $icon  = $type_icons[ $type ] ?? '&#128196;';
            $label = $type_labels[ $type ] ?? ucfirst( $type );
            $ts    = $step['ts'] ?? 0;
            $time  = $ts ? wp_date( 'M j, H:i:s', $ts ) : '';
            $url   = $step['url'] ?? '';
            $title = $step['title'] ?? $url;

            // Time delta from previous step
            $delta = '';
            if ( $i > 0 && $ts && isset( $journey[ $i - 1 ]['ts'] ) ) {
                $diff = $ts - $journey[ $i - 1 ]['ts'];
                if ( $diff < 60 )        $delta = '+' . $diff . 's';
                elseif ( $diff < 3600 )  $delta = '+' . round( $diff / 60 ) . 'm';
                elseif ( $diff < 86400 ) $delta = '+' . round( $diff / 3600, 1 ) . 'h';
                else                     $delta = '+' . round( $diff / 86400, 1 ) . 'd';
            }

            $bg_color = '#fff';
            if ( $type === 'event' )     $bg_color = '#fff8e1';
            if ( $type === 'checkout' )  $bg_color = '#e8f5e9';
            if ( $type === 'thank_you' ) $bg_color = '#e3f2fd';

            echo '<div style="position:relative;margin-bottom:8px;padding:6px 10px;background:' . $bg_color . ';border:1px solid #e0e0e0;border-radius:4px;font-size:12px;">';

            // Dot on timeline
            echo '<div style="position:absolute;left:-19px;top:10px;width:10px;height:10px;background:#2271b1;border-radius:50%;border:2px solid #fff;box-shadow:0 0 0 1px #ddd;"></div>';

            // Header line
            echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:2px;">';
            echo '<span>' . $icon . ' <strong>' . esc_html( $label ) . '</strong>';
            if ( ! empty( $step['event'] ) ) {
                echo ' &mdash; <code style="font-size:11px;">' . esc_html( $step['event'] ) . '</code>';
            }
            echo '</span>';
            echo '<span style="color:#888;font-size:11px;">' . esc_html( $time );
            if ( $delta ) echo ' <em>(' . esc_html( $delta ) . ')</em>';
            echo '</span>';
            echo '</div>';

            // Title / URL
            echo '<div style="color:#555;">';
            echo esc_html( $title );
            if ( $url && $url !== $title ) {
                echo ' <span style="color:#aaa;font-size:11px;">(' . esc_html( $url ) . ')</span>';
            }
            echo '</div>';

            // Extra info
            if ( ! empty( $step['product'] ) ) {
                echo '<div style="color:#1a73e8;font-size:11px;">Product: ' . esc_html( $step['product'] ) . '</div>';
            }
            if ( ! empty( $step['search_term'] ) ) {
                echo '<div style="color:#1a73e8;font-size:11px;">Search: "' . esc_html( $step['search_term'] ) . '"</div>';
            }
            if ( ! empty( $step['referrer'] ) ) {
                echo '<div style="color:#888;font-size:11px;">Referrer: ' . esc_html( $step['referrer'] ) . '</div>';
            }
            if ( ! empty( $step['utm_source'] ) ) {
                echo '<div style="color:#e65100;font-size:11px;">UTM Source: ' . esc_html( $step['utm_source'] ) . '</div>';
            }

            echo '</div>';
        }

        echo '</div>';
    }

    /**
     * Add UTM Source column to orders list.
     */
    public function add_column( $columns ) {
        $new = [];
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( $key === 'order_total' ) {
                $new['meshulash_utm_source'] = 'UTM Source';
            }
        }
        return $new;
    }

    /**
     * Render UTM Source column (HPOS).
     */
    public function render_column( $column, $order ) {
        if ( $column !== 'meshulash_utm_source' ) return;
        $tracking = $order->get_meta( '_meshulash_utm' );
        echo esc_html( $tracking['utm_source'] ?? '—' );
    }

    /**
     * Render UTM Source column (legacy CPT).
     */
    public function render_column_legacy( $column, $order_id ) {
        if ( $column !== 'meshulash_utm_source' ) return;
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        $tracking = $order->get_meta( '_meshulash_utm' );
        echo esc_html( $tracking['utm_source'] ?? '—' );
    }
}
