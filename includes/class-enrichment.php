<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Meshulash_Enrichment {

    public function __construct() {
        // WC Subscriptions events
        if ( Meshulash_Settings::get( 'event_subscriptions' ) && class_exists( 'WC_Subscriptions' ) ) {
            add_action( 'woocommerce_subscription_renewal_payment_complete', [ $this, 'event_subscription_renewal' ], 10, 2 );
            add_action( 'woocommerce_subscription_status_cancelled', [ $this, 'event_subscription_cancelled' ] );
            add_action( 'woocommerce_subscription_status_active', [ $this, 'event_subscription_reactivated' ] );
            add_action( 'woocommerce_subscription_status_expired', [ $this, 'event_subscription_expired' ] );
            add_action( 'woocommerce_subscription_status_on-hold', [ $this, 'event_subscription_paused' ] );
        }

        // Profit tracking: add cost meta box to product editor
        if ( Meshulash_Settings::get( 'profit_tracking' ) ) {
            add_action( 'woocommerce_product_options_pricing', [ $this, 'add_cost_field' ] );
            add_action( 'woocommerce_process_product_meta', [ $this, 'save_cost_field' ] );
            add_action( 'woocommerce_variation_options_pricing', [ $this, 'add_variation_cost_field' ], 10, 3 );
            add_action( 'woocommerce_save_product_variation', [ $this, 'save_variation_cost_field' ], 10, 2 );
        }
    }

    // ══════════════════════════════════════════════
    //  WC SUBSCRIPTIONS EVENTS
    // ══════════════════════════════════════════════

    public function event_subscription_renewal( $subscription, $last_order ) {
        if ( ! $last_order ) return;

        $event_id = Meshulash_DataLayer::generate_event_id( 'subr' );
        $value    = (float) $last_order->get_total();
        $currency = $last_order->get_currency();

        do_action( 'meshulash_server_event', 'subscription_renewal', [
            'order_id'        => (string) $last_order->get_order_number(),
            'subscription_id' => (string) $subscription->get_id(),
            'value'           => $value,
            'currency'        => $currency,
        ], $event_id, $last_order );

        // Store for dataLayer push on next page load
        if ( function_exists( 'WC' ) && WC()->session ) {
            $events = WC()->session->get( 'meshulash_pending_events', [] );
            $events[] = [
                'event'           => 'subscription_renewal',
                'event_id'        => $event_id,
                'subscription_id' => (string) $subscription->get_id(),
                'ecommerce'       => [
                    'transaction_id' => (string) $last_order->get_order_number(),
                    'value'          => $value,
                    'currency'       => $currency,
                ],
            ];
            WC()->session->set( 'meshulash_pending_events', $events );
        }
    }

    public function event_subscription_cancelled( $subscription ) {
        $this->fire_subscription_status_event( $subscription, 'subscription_cancelled' );
    }

    public function event_subscription_reactivated( $subscription ) {
        $this->fire_subscription_status_event( $subscription, 'subscription_reactivated' );
    }

    public function event_subscription_expired( $subscription ) {
        $this->fire_subscription_status_event( $subscription, 'subscription_expired' );
    }

    public function event_subscription_paused( $subscription ) {
        $this->fire_subscription_status_event( $subscription, 'subscription_paused' );
    }

    private function fire_subscription_status_event( $subscription, $event_name ) {
        $event_id = Meshulash_DataLayer::generate_event_id( 'subs' );
        $parent   = $subscription->get_parent();
        $order    = $parent ? wc_get_order( $parent ) : null;

        do_action( 'meshulash_server_event', $event_name, [
            'subscription_id' => (string) $subscription->get_id(),
            'value'           => (float) $subscription->get_total(),
            'currency'        => $subscription->get_currency(),
        ], $event_id, $order );
    }

    // ══════════════════════════════════════════════
    //  PROFIT / MARGIN TRACKING
    // ══════════════════════════════════════════════

    /**
     * Add cost price field to simple product pricing.
     */
    public function add_cost_field() {
        $cost_field = Meshulash_Settings::get( 'product_cost_field' );
        woocommerce_wp_text_input([
            'id'          => $cost_field,
            'label'       => 'Cost Price (' . get_woocommerce_currency_symbol() . ')',
            'desc_tip'    => true,
            'description' => 'Product cost for profit/margin tracking (Meshulash Marketing).',
            'data_type'   => 'price',
        ]);
    }

    /**
     * Save cost price for simple products.
     */
    public function save_cost_field( $post_id ) {
        $cost_field = Meshulash_Settings::get( 'product_cost_field' );
        if ( isset( $_POST[ $cost_field ] ) ) {
            update_post_meta( $post_id, $cost_field, wc_format_decimal( sanitize_text_field( $_POST[ $cost_field ] ) ) );
        }
    }

    /**
     * Add cost price field to variation pricing.
     */
    public function add_variation_cost_field( $loop, $variation_data, $variation ) {
        $cost_field = Meshulash_Settings::get( 'product_cost_field' );
        $value = get_post_meta( $variation->ID, $cost_field, true );
        ?>
        <div class="variable_pricing">
            <p class="form-row form-row-first">
                <label><?php echo 'Cost Price (' . esc_html( get_woocommerce_currency_symbol() ) . ')'; ?></label>
                <input type="text"
                       name="<?php echo esc_attr( $cost_field ); ?>[<?php echo esc_attr( $loop ); ?>]"
                       value="<?php echo esc_attr( $value ); ?>"
                       class="wc_input_price short"
                       placeholder="0.00">
            </p>
        </div>
        <?php
    }

    /**
     * Save cost price for variations.
     */
    public function save_variation_cost_field( $variation_id, $i ) {
        $cost_field = Meshulash_Settings::get( 'product_cost_field' );
        if ( isset( $_POST[ $cost_field ][ $i ] ) ) {
            update_post_meta( $variation_id, $cost_field, wc_format_decimal( sanitize_text_field( $_POST[ $cost_field ][ $i ] ) ) );
        }
    }

    // ══════════════════════════════════════════════
    //  RFM SCORING
    // ══════════════════════════════════════════════

    /**
     * Calculate RFM score for a customer.
     * Returns: [ 'rfm_score' => '555', 'recency' => 5, 'frequency' => 5, 'monetary' => 5,
     *            'days_since_last' => 3, 'total_orders' => 12, 'total_spent' => 5000 ]
     */
    public static function calculate_rfm( $customer_id ) {
        if ( ! $customer_id ) return null;

        $orders = wc_get_orders([
            'customer_id' => $customer_id,
            'status'      => [ 'completed', 'processing' ],
            'limit'       => -1,
            'orderby'     => 'date',
            'order'       => 'DESC',
        ]);

        if ( empty( $orders ) ) return null;

        $total_orders = count( $orders );
        $total_spent  = 0;
        $last_order_date = null;

        foreach ( $orders as $order ) {
            $total_spent += (float) $order->get_total();
            if ( ! $last_order_date ) {
                $last_order_date = $order->get_date_created();
            }
        }

        // Recency: days since last order
        $days_since = $last_order_date
            ? (int) ( ( time() - $last_order_date->getTimestamp() ) / 86400 )
            : 999;

        // Score 1-5 (5 is best)
        // Recency: fewer days = higher score
        if ( $days_since <= 7 )        $r = 5;
        elseif ( $days_since <= 30 )   $r = 4;
        elseif ( $days_since <= 90 )   $r = 3;
        elseif ( $days_since <= 180 )  $r = 2;
        else                           $r = 1;

        // Frequency: more orders = higher score
        if ( $total_orders >= 20 )     $f = 5;
        elseif ( $total_orders >= 10 ) $f = 4;
        elseif ( $total_orders >= 5 )  $f = 3;
        elseif ( $total_orders >= 2 )  $f = 2;
        else                           $f = 1;

        // Monetary: higher spend = higher score
        if ( $total_spent >= 5000 )      $m = 5;
        elseif ( $total_spent >= 2000 )  $m = 4;
        elseif ( $total_spent >= 500 )   $m = 3;
        elseif ( $total_spent >= 100 )   $m = 2;
        else                             $m = 1;

        return [
            'rfm_score'      => $r . $f . $m,
            'recency'        => $r,
            'frequency'      => $f,
            'monetary'       => $m,
            'days_since_last' => $days_since,
            'total_orders'   => $total_orders,
            'total_spent'    => round( $total_spent, 2 ),
        ];
    }

    /**
     * Calculate profit for an order.
     * Returns total profit and items with profit data.
     */
    public static function calculate_order_profit( $order ) {
        if ( ! $order instanceof WC_Order ) return null;

        $cost_field   = Meshulash_Settings::get( 'product_cost_field' );
        $total_cost   = 0;
        $total_revenue = 0;
        $items_profit = [];

        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( ! $product ) continue;

            $quantity = $item->get_quantity();
            $revenue  = (float) $item->get_total();
            $cost     = (float) get_post_meta( $product->get_id(), $cost_field, true );

            $item_cost   = $cost * $quantity;
            $item_profit = $revenue - $item_cost;

            $total_cost    += $item_cost;
            $total_revenue += $revenue;

            if ( $cost > 0 ) {
                $items_profit[] = [
                    'item_id' => (string) $product->get_id(),
                    'name'    => $product->get_name(),
                    'cost'    => round( $item_cost, 2 ),
                    'revenue' => round( $revenue, 2 ),
                    'profit'  => round( $item_profit, 2 ),
                    'margin'  => $revenue > 0 ? round( ( $item_profit / $revenue ) * 100, 1 ) : 0,
                ];
            }
        }

        $total_profit = $total_revenue - $total_cost;

        return [
            'total_cost'    => round( $total_cost, 2 ),
            'total_revenue' => round( $total_revenue, 2 ),
            'total_profit'  => round( $total_profit, 2 ),
            'margin_pct'    => $total_revenue > 0 ? round( ( $total_profit / $total_revenue ) * 100, 1 ) : 0,
            'items'         => $items_profit,
        ];
    }
}
