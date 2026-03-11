<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Meshulash_Server_Side {

    public function __construct() {
        add_action( 'meshulash_server_event', [ $this, 'handle_event' ], 10, 4 );
    }

    /**
     * Central handler: dispatches server-side events to FB CAPI, GA4 MP, and TikTok.
     *
     * @param string        $event_name  Facebook-style event name (e.g., 'Purchase', 'ViewContent')
     * @param array         $custom_data Event-specific data
     * @param string        $event_id    Unique event ID for deduplication
     * @param WC_Order|null $order       Order object (if applicable)
     */
    public function handle_event( $event_name, $custom_data, $event_id, $order = null ) {
        // Facebook CAPI
        if ( Meshulash_Settings::get( 'fb_capi_enabled' ) && Meshulash_Settings::get( 'fb_access_token' ) ) {
            $this->send_facebook_event( $event_name, $custom_data, $event_id, $order );
        }

        // GA4 Measurement Protocol
        if ( Meshulash_Settings::get( 'ga4_mp_enabled' ) && Meshulash_Settings::get( 'ga4_api_secret' ) ) {
            $this->send_ga4_event( $event_name, $custom_data, $event_id, $order );
        }

        // TikTok Events API
        if ( Meshulash_Settings::get( 'tt_api_enabled' ) && Meshulash_Settings::get( 'tt_access_token' ) ) {
            $this->send_tiktok_event( $event_name, $custom_data, $event_id, $order );
        }

        // Pinterest CAPI
        if ( Meshulash_Settings::get( 'pinterest_capi_enabled' ) && Meshulash_Settings::get( 'pinterest_access_token' ) ) {
            $this->send_pinterest_event( $event_name, $custom_data, $event_id, $order );
        }
    }

    // ══════════════════════════════════════════════
    //  FACEBOOK CONVERSIONS API
    // ══════════════════════════════════════════════
    private function send_facebook_event( $event_name, $custom_data, $event_id, $order = null ) {
        $pixel_id     = Meshulash_Settings::get( 'fb_pixel_id' );
        $access_token = Meshulash_Settings::get( 'fb_access_token' );

        if ( ! $pixel_id || ! $access_token ) return;

        // Safety: skip if token is the masked placeholder
        if ( $access_token === Meshulash_Settings::SECRET_MASK ) return;

        $fb_event_name = $event_name; // Already in FB format from caller

        // Build user data (enriched with geo if enabled)
        $user_data = Meshulash_DataLayer::get_user_data_hashed( $order );
        $user_data = apply_filters( 'meshulash_enrich_user_data', $user_data, $order );

        $event = [
            'event_name'       => $fb_event_name,
            'event_time'       => time(),
            'event_id'         => $event_id,
            'action_source'    => 'website',
            'event_source_url' => $this->get_current_url(),
            'user_data'        => $user_data,
            'custom_data'      => $custom_data,
        ];

        $payload = [ 'data' => [ $event ] ];

        $test_code = Meshulash_Settings::get( 'fb_test_event_code' );
        if ( $test_code ) {
            $payload['test_event_code'] = $test_code;
        }

        $url = 'https://graph.facebook.com/v21.0/' . $pixel_id . '/events';

        // Include access_token in payload body (more reliable than URL param)
        $payload['access_token'] = $access_token;

        $is_debug = Meshulash_Settings::is_debug();

        $response = wp_remote_post( $url, [
            'timeout'  => $is_debug ? 15 : 5,
            'blocking' => $is_debug,  // Blocking in debug so we can log the response
            'headers'  => [ 'Content-Type' => 'application/json' ],
            'body'     => wp_json_encode( $payload ),
        ]);

        if ( $is_debug ) {
            $log = 'Meshulash CAPI [' . $fb_event_name . '] Pixel: ' . $pixel_id . ' Event ID: ' . $event_id;
            if ( is_wp_error( $response ) ) {
                $log .= ' | WP ERROR: ' . $response->get_error_message();
            } else {
                $code = wp_remote_retrieve_response_code( $response );
                $body = wp_remote_retrieve_body( $response );
                $log .= ' | HTTP ' . $code . ': ' . substr( $body, 0, 500 );
            }
            error_log( $log );
        }
    }

    // ══════════════════════════════════════════════
    //  GA4 MEASUREMENT PROTOCOL
    // ══════════════════════════════════════════════
    private function send_ga4_event( $event_name, $custom_data, $event_id, $order = null ) {
        $measurement_id = Meshulash_Settings::get( 'ga4_measurement_id' );
        $api_secret     = Meshulash_Settings::get( 'ga4_api_secret' );

        if ( ! $measurement_id || ! $api_secret ) return;

        // Map to GA4 event names
        $ga4_map = [
            'ViewContent'          => 'view_item',
            'AddToCart'            => 'add_to_cart',
            'InitiateCheckout'     => 'begin_checkout',
            'AddPaymentInfo'       => 'add_payment_info',
            'Purchase'             => 'purchase',
            'CompleteRegistration' => 'sign_up',
            'Lead'                 => 'generate_lead',
            'Search'               => 'search',
            'Refund'               => 'refund',
            'AddToWishlist'        => 'add_to_wishlist',
        ];

        $ga4_event = $ga4_map[ $event_name ] ?? strtolower( $event_name );

        $client_id = Meshulash_DataLayer::get_ga_client_id();
        if ( ! $client_id ) {
            $client_id = sprintf( '%d.%d', mt_rand( 1000000000, 9999999999 ), time() );
        }

        $params = [
            'engagement_time_msec' => '100',
            'session_id'           => Meshulash_DataLayer::get_ga_session_id() ?: (string) time(),
        ];

        if ( isset( $custom_data['value'] ) )    $params['value']    = (float) $custom_data['value'];
        if ( isset( $custom_data['currency'] ) ) $params['currency'] = $custom_data['currency'];
        if ( isset( $custom_data['order_id'] ) ) $params['transaction_id'] = $custom_data['order_id'];
        if ( isset( $custom_data['coupon'] ) )   $params['coupon'] = $custom_data['coupon'];

        // Build items from order
        if ( $order instanceof WC_Order && in_array( $ga4_event, [ 'purchase', 'refund' ], true ) ) {
            $items = [];
            foreach ( $order->get_items() as $item ) {
                $ga4_item = Meshulash_DataLayer::format_order_item( $item );
                if ( ! empty( $ga4_item ) ) $items[] = $ga4_item;
            }
            if ( ! empty( $items ) ) $params['items'] = $items;
        }

        $payload = [
            'client_id' => $client_id,
            'events'    => [[ 'name' => $ga4_event, 'params' => $params ]],
        ];

        if ( is_user_logged_in() ) {
            $payload['user_id'] = (string) get_current_user_id();
        } elseif ( $order && $order->get_user_id() ) {
            $payload['user_id'] = (string) $order->get_user_id();
        }

        $url = 'https://www.google-analytics.com/mp/collect?measurement_id=' . $measurement_id . '&api_secret=' . $api_secret;

        wp_remote_post( $url, [
            'timeout'  => 5,
            'blocking' => false,
            'headers'  => [ 'Content-Type' => 'application/json' ],
            'body'     => wp_json_encode( $payload ),
        ]);

        if ( Meshulash_Settings::is_debug() ) {
            error_log( 'Meshulash GA4 MP [' . $ga4_event . '] Client: ' . $client_id );
        }
    }

    // ══════════════════════════════════════════════
    //  TIKTOK EVENTS API
    // ══════════════════════════════════════════════
    private function send_tiktok_event( $event_name, $custom_data, $event_id, $order = null ) {
        $pixel_id     = Meshulash_Settings::get( 'tt_pixel_id' );
        $access_token = Meshulash_Settings::get( 'tt_access_token' );

        if ( ! $pixel_id || ! $access_token ) return;

        // Map to TikTok event names
        $tt_map = [
            'ViewContent'          => 'ViewContent',
            'AddToCart'            => 'AddToCart',
            'InitiateCheckout'     => 'InitiateCheckout',
            'AddPaymentInfo'       => 'AddPaymentInfo',
            'Purchase'             => 'CompletePayment',  // TikTok uses CompletePayment
            'CompleteRegistration' => 'CompleteRegistration',
            'Lead'                 => 'SubmitForm',
            'Search'               => 'Search',
            'AddToWishlist'        => 'AddToWishlist',
        ];

        // Standard TikTok events
        $tt_standard = [
            'ViewContent', 'AddToCart', 'InitiateCheckout', 'AddPaymentInfo',
            'CompletePayment', 'CompleteRegistration', 'SubmitForm', 'Search',
            'AddToWishlist', 'PlaceAnOrder', 'Subscribe', 'Contact', 'Download',
        ];

        $tt_event = $tt_map[ $event_name ] ?? $event_name;

        // Build user data (TikTok format, enriched with geo)
        $user_data = $this->get_tiktok_user_data( $order );
        $user_data = apply_filters( 'meshulash_enrich_user_data', $user_data, $order );

        // Build properties
        $properties = [];
        if ( isset( $custom_data['value'] ) )    $properties['value']    = (float) $custom_data['value'];
        if ( isset( $custom_data['currency'] ) ) $properties['currency'] = $custom_data['currency'];
        if ( isset( $custom_data['order_id'] ) ) $properties['order_id'] = $custom_data['order_id'];
        if ( isset( $custom_data['content_ids'] ) ) {
            $properties['contents'] = array_map( function ( $id ) use ( $custom_data ) {
                return [
                    'content_id'   => (string) $id,
                    'content_type' => 'product',
                    'quantity'     => 1,
                ];
            }, $custom_data['content_ids'] );
            $properties['content_type'] = 'product';
        }
        if ( isset( $custom_data['content_name'] ) ) $properties['description'] = $custom_data['content_name'];
        if ( isset( $custom_data['num_items'] ) )     $properties['num_items'] = $custom_data['num_items'];

        $event = [
            'pixel_code' => $pixel_id,
            'event'      => $tt_event,
            'event_id'   => $event_id,
            'type'       => 'track',
            'timestamp'  => date( 'c' ),
            'context'    => [
                'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ) : '',
                'ip'         => Meshulash_DataLayer::get_client_ip(),
                'page'       => [
                    'url'      => $this->get_current_url(),
                    'referrer' => isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_url( $_SERVER['HTTP_REFERER'] ) : '',
                ],
                'user' => $user_data,
            ],
            'properties' => $properties,
        ];

        // Add test event code
        $test_code = Meshulash_Settings::get( 'tt_test_event_code' );
        if ( $test_code ) {
            $event['test_event_code'] = $test_code;
        }

        $payload = [ 'data' => [ $event ] ];

        wp_remote_post( 'https://business-api.tiktok.com/open_api/v1.3/event/track/', [
            'timeout'  => 5,
            'blocking' => false,
            'headers'  => [
                'Content-Type' => 'application/json',
                'Access-Token' => $access_token,
            ],
            'body' => wp_json_encode( $payload ),
        ]);

        if ( Meshulash_Settings::is_debug() ) {
            error_log( 'Meshulash TikTok [' . $tt_event . '] Event ID: ' . $event_id );
        }
    }

    // ══════════════════════════════════════════════
    //  PINTEREST CONVERSIONS API
    // ══════════════════════════════════════════════
    private function send_pinterest_event( $event_name, $custom_data, $event_id, $order = null ) {
        $ad_account_id = Meshulash_Settings::get( 'pinterest_ad_account_id' );
        $access_token  = Meshulash_Settings::get( 'pinterest_access_token' );

        if ( ! $ad_account_id || ! $access_token ) return;
        if ( $access_token === Meshulash_Settings::SECRET_MASK ) return;

        // Map to Pinterest event names
        $pin_map = [
            'ViewContent'          => 'page_visit',
            'AddToCart'            => 'add_to_cart',
            'InitiateCheckout'     => 'checkout',
            'Purchase'             => 'checkout',
            'CompleteRegistration' => 'signup',
            'Lead'                 => 'lead',
            'Search'               => 'search',
            'AddToWishlist'        => 'add_to_cart',
        ];

        $pin_event = $pin_map[ $event_name ] ?? null;
        if ( ! $pin_event ) return;

        // Build user data
        $user_data = [];
        $email = '';
        $phone = '';

        if ( $order instanceof WC_Order ) {
            $email = $order->get_billing_email();
            $phone = $order->get_billing_phone();
        } elseif ( is_user_logged_in() ) {
            $user  = wp_get_current_user();
            $email = $user->user_email;
            $phone = get_user_meta( $user->ID, 'billing_phone', true );
        }

        if ( $email ) $user_data['em'] = [ hash( 'sha256', strtolower( trim( $email ) ) ) ];
        if ( $phone ) $user_data['ph'] = [ hash( 'sha256', preg_replace( '/[^0-9]/', '', $phone ) ) ];

        $client_ip = Meshulash_DataLayer::get_client_ip();
        if ( $client_ip ) $user_data['client_ip_address'] = $client_ip;
        if ( isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
            $user_data['client_user_agent'] = sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] );
        }

        // Pinterest click ID
        if ( isset( $_COOKIE['_epik'] ) ) {
            $user_data['click_id'] = sanitize_text_field( $_COOKIE['_epik'] );
        }

        // Build custom data
        $pin_custom = [];
        if ( isset( $custom_data['value'] ) )    $pin_custom['value']    = (string) $custom_data['value'];
        if ( isset( $custom_data['currency'] ) ) $pin_custom['currency'] = $custom_data['currency'];
        if ( isset( $custom_data['order_id'] ) ) $pin_custom['order_id'] = (string) $custom_data['order_id'];
        if ( isset( $custom_data['content_ids'] ) ) {
            $pin_custom['content_ids'] = array_map( 'strval', $custom_data['content_ids'] );
        }
        if ( isset( $custom_data['num_items'] ) ) $pin_custom['num_items'] = (int) $custom_data['num_items'];

        $event = [
            'event_name'       => $pin_event,
            'action_source'    => 'web',
            'event_time'       => time(),
            'event_id'         => $event_id,
            'event_source_url' => $this->get_current_url(),
            'user_data'        => $user_data,
            'custom_data'      => $pin_custom,
        ];

        $payload = [ 'data' => [ $event ] ];

        $url = 'https://api.pinterest.com/v5/ad_accounts/' . $ad_account_id . '/events';

        wp_remote_post( $url, [
            'timeout'  => 5,
            'blocking' => Meshulash_Settings::is_debug(),
            'headers'  => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $access_token,
            ],
            'body' => wp_json_encode( $payload ),
        ]);

        if ( Meshulash_Settings::is_debug() ) {
            error_log( 'Meshulash Pinterest CAPI [' . $pin_event . '] Event ID: ' . $event_id );
        }
    }

    /**
     * Build TikTok user data (hashed).
     */
    private function get_tiktok_user_data( $order = null ) {
        $data = [];

        $email = '';
        $phone = '';

        if ( $order instanceof WC_Order ) {
            $email = $order->get_billing_email();
            $phone = $order->get_billing_phone();
        } elseif ( is_user_logged_in() ) {
            $user  = wp_get_current_user();
            $email = $user->user_email;
            $phone = get_user_meta( $user->ID, 'billing_phone', true );
        }

        if ( $email ) $data['email'] = hash( 'sha256', strtolower( trim( $email ) ) );
        if ( $phone ) $data['phone'] = hash( 'sha256', preg_replace( '/[^0-9]/', '', $phone ) );

        // TikTok click ID
        if ( isset( $_COOKIE['ttclid'] ) ) {
            $data['ttclid'] = sanitize_text_field( $_COOKIE['ttclid'] );
        }
        if ( isset( $_COOKIE['_ttp'] ) ) {
            $data['ttp'] = sanitize_text_field( $_COOKIE['_ttp'] );
        }

        return $data;
    }

    /**
     * Get current page URL.
     */
    private function get_current_url() {
        if ( isset( $_SERVER['HTTP_HOST'] ) && isset( $_SERVER['REQUEST_URI'] ) ) {
            $scheme = is_ssl() ? 'https' : 'http';
            return $scheme . '://' . sanitize_text_field( $_SERVER['HTTP_HOST'] ) . sanitize_text_field( $_SERVER['REQUEST_URI'] );
        }
        return home_url();
    }
}
