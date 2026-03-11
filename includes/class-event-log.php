<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Real-time event log for debugging.
 * Stores the last 100 events in a WordPress option for viewing in admin.
 * No custom DB table needed — uses wp_options for simplicity.
 */
class Meshulash_Event_Log {

    const OPTION_KEY = 'meshulash_event_log';
    const MAX_ENTRIES = 100;

    public function __construct() {
        if ( ! Meshulash_Settings::get( 'event_log_enabled' ) ) return;

        // Log server-side events
        add_action( 'meshulash_server_event', [ $this, 'log_server_event' ], 99, 4 );

        // AJAX endpoint for fetching log (admin only)
        add_action( 'wp_ajax_meshulash_get_event_log', [ $this, 'ajax_get_log' ] );
        add_action( 'wp_ajax_meshulash_clear_event_log', [ $this, 'ajax_clear_log' ] );
    }

    /**
     * Log a server-side event.
     */
    public function log_server_event( $event_name, $custom_data, $event_id, $order = null ) {
        $entry = [
            'time'     => gmdate( 'Y-m-d H:i:s' ),
            'event'    => $event_name,
            'event_id' => substr( $event_id, 0, 20 ),
            'source'   => 'server',
            'ip'       => substr( Meshulash_DataLayer::get_client_ip(), 0, 20 ),
            'url'      => isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( substr( $_SERVER['REQUEST_URI'], 0, 100 ) ) : '',
        ];

        // Add value/currency if present
        if ( isset( $custom_data['value'] ) ) {
            $entry['value'] = (float) $custom_data['value'];
        }
        if ( isset( $custom_data['currency'] ) ) {
            $entry['currency'] = $custom_data['currency'];
        }

        // Add order info
        if ( $order instanceof WC_Order ) {
            $entry['order_id'] = $order->get_order_number();
            $entry['value']    = (float) $order->get_total();
            $entry['currency'] = $order->get_currency();
        }

        // Add to log
        $log = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $log ) ) $log = [];

        array_unshift( $log, $entry ); // newest first

        // Trim to max entries
        if ( count( $log ) > self::MAX_ENTRIES ) {
            $log = array_slice( $log, 0, self::MAX_ENTRIES );
        }

        update_option( self::OPTION_KEY, $log, false ); // autoload = false
    }

    /**
     * Log a client-side event (called via beacon/AJAX).
     */
    public static function log_client_event( $event_name, $event_data, $event_id ) {
        if ( ! Meshulash_Settings::get( 'event_log_enabled' ) ) return;

        $entry = [
            'time'     => gmdate( 'Y-m-d H:i:s' ),
            'event'    => $event_name,
            'event_id' => substr( $event_id, 0, 20 ),
            'source'   => 'client',
            'ip'       => substr( Meshulash_DataLayer::get_client_ip(), 0, 20 ),
            'url'      => isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_text_field( substr( $_SERVER['HTTP_REFERER'], 0, 100 ) ) : '',
        ];

        if ( isset( $event_data['value'] ) ) {
            $entry['value'] = (float) $event_data['value'];
        }

        $log = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $log ) ) $log = [];

        array_unshift( $log, $entry );

        if ( count( $log ) > self::MAX_ENTRIES ) {
            $log = array_slice( $log, 0, self::MAX_ENTRIES );
        }

        update_option( self::OPTION_KEY, $log, false );
    }

    /**
     * AJAX: Get event log entries.
     */
    public function ajax_get_log() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $log = get_option( self::OPTION_KEY, [] );
        wp_send_json_success( [ 'entries' => is_array( $log ) ? $log : [] ] );
    }

    /**
     * AJAX: Clear event log.
     */
    public function ajax_clear_log() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        delete_option( self::OPTION_KEY );
        wp_send_json_success( [ 'cleared' => true ] );
    }

    /**
     * Get log entries (for admin display).
     */
    public static function get_entries( $limit = 50 ) {
        $log = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $log ) ) return [];
        return array_slice( $log, 0, $limit );
    }
}
