<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Webhook integration — fires HTTP webhooks on tracking events.
 * Compatible with Zapier, Make, n8n, and any webhook receiver.
 */
class Meshulash_Webhooks {

    public function __construct() {
        // Only if webhooks are enabled and URL is set
        if ( ! Meshulash_Settings::get( 'webhook_enabled' ) ) return;
        $url = Meshulash_Settings::get( 'webhook_url' );
        if ( ! $url ) return;

        // Hook into server-side events
        add_action( 'meshulash_server_event', [ $this, 'on_server_event' ], 20, 4 );
    }

    /**
     * Fire webhook on server-side events.
     */
    public function on_server_event( $event_name, $custom_data, $event_id, $order = null ) {
        // Check if this event type should trigger webhooks
        $events_filter = Meshulash_Settings::get( 'webhook_events' );
        if ( $events_filter && $events_filter !== 'all' ) {
            $allowed = array_map( 'trim', explode( ',', $events_filter ) );
            if ( ! in_array( $event_name, $allowed, true ) ) return;
        }

        $payload = [
            'event'      => $event_name,
            'event_id'   => $event_id,
            'timestamp'  => gmdate( 'c' ),
            'site_url'   => home_url(),
            'data'       => $custom_data,
        ];

        // Add order data if available
        if ( $order instanceof WC_Order ) {
            $payload['order'] = [
                'id'        => $order->get_order_number(),
                'total'     => (float) $order->get_total(),
                'currency'  => $order->get_currency(),
                'email'     => $order->get_billing_email(),
                'phone'     => $order->get_billing_phone(),
                'name'      => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'city'      => $order->get_billing_city(),
                'country'   => $order->get_billing_country(),
                'status'    => $order->get_status(),
            ];

            // Add UTM attribution
            $utm = $order->get_meta( '_meshulash_utm' );
            if ( is_array( $utm ) && ! empty( $utm ) ) {
                $payload['attribution'] = $utm;
            }
        }

        // Add visitor geo data if available
        if ( class_exists( 'Meshulash_Geo' ) ) {
            $geo = Meshulash_Geo::get_current_geo();
            if ( ! empty( $geo ) ) {
                $payload['geo'] = $geo;
            }
        }

        // Add webhook secret header if configured
        $headers = [ 'Content-Type' => 'application/json' ];
        $secret = Meshulash_Settings::get( 'webhook_secret' );
        if ( $secret && $secret !== Meshulash_Settings::SECRET_MASK ) {
            $headers['X-Meshulash-Secret'] = $secret;
            $headers['X-Meshulash-Signature'] = hash_hmac( 'sha256', wp_json_encode( $payload ), $secret );
        }

        $url = Meshulash_Settings::get( 'webhook_url' );

        wp_remote_post( $url, [
            'timeout'  => 5,
            'blocking' => false,
            'headers'  => $headers,
            'body'     => wp_json_encode( $payload ),
        ]);

        if ( Meshulash_Settings::is_debug() ) {
            error_log( 'Meshulash Webhook [' . $event_name . '] → ' . $url );
        }
    }
}
