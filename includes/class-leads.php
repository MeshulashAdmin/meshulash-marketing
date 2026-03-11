<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Standalone lead/form tracking.
 * Works with or without WooCommerce — no WC dependency.
 *
 * Hooks into: Elementor Pro, Contact Form 7, WPForms, Gravity Forms,
 * Ninja Forms, Forminator, Fluent Forms, WS Form.
 *
 * Fires generate_lead event to dataLayer (via pending events) and
 * server-side via meshulash_server_event action (FB CAPI, GA4 MP, TikTok).
 */
class Meshulash_Leads {

    public function __construct() {
        if ( ! Meshulash_Settings::get( 'event_lead' ) ) return;

        // Elementor Pro
        add_action( 'elementor_pro/forms/new_record', [ $this, 'on_elementor' ], 10, 2 );

        // Contact Form 7
        add_action( 'wpcf7_mail_sent', [ $this, 'on_cf7' ] );

        // WPForms
        add_action( 'wpforms_process_complete', [ $this, 'on_wpforms' ], 10, 4 );

        // Gravity Forms
        add_action( 'gform_after_submission', [ $this, 'on_gravity_forms' ], 10, 2 );

        // Ninja Forms
        add_action( 'ninja_forms_after_submission', [ $this, 'on_ninja_forms' ] );

        // Forminator
        add_action( 'forminator_custom_form_submit_before_set_fields', [ $this, 'on_forminator' ], 10, 3 );

        // Fluent Forms
        add_action( 'fluentform/submission_inserted', [ $this, 'on_fluent_forms' ], 10, 3 );

        // WS Form
        add_action( 'wsf_submit_post_complete', [ $this, 'on_ws_form' ], 10, 2 );

        // Flush pending lead events as inline scripts
        add_action( 'wp_footer', [ $this, 'flush_pending_events' ], 999 );
    }

    /**
     * Get currency — WooCommerce if available, otherwise fallback setting.
     */
    private function get_currency() {
        if ( function_exists( 'get_woocommerce_currency' ) ) {
            return get_woocommerce_currency();
        }
        return Meshulash_Settings::get( 'default_currency' ) ?: 'USD';
    }

    /**
     * Fire lead event (shared logic).
     */
    private function fire_lead( $form_name, $form_plugin, $form_id = '', $extra = [] ) {
        $event_id = Meshulash_DataLayer::generate_event_id( 'lead' );

        // Store for dataLayer push on next page load
        $this->store_pending_event([
            'event'       => 'generate_lead',
            'event_id'    => $event_id,
            'form_name'   => sanitize_text_field( $form_name ),
            'form_plugin' => $form_plugin,
            'form_id'     => $form_id,
        ]);

        // Fire server-side event (FB CAPI, GA4 MP, TikTok)
        $server_data = array_merge([
            'content_name'     => sanitize_text_field( $form_name ),
            'content_category' => 'form_submission',
            'value'            => 0,
            'currency'         => $this->get_currency(),
        ], $extra );

        do_action( 'meshulash_server_event', 'Lead', $server_data, $event_id );

        if ( Meshulash_Settings::is_debug() ) {
            error_log( 'Meshulash Lead [' . $form_plugin . '] Form: ' . $form_name . ' Event ID: ' . $event_id );
        }
    }

    // ══════════════════════════════════════════════
    //  FORM PLUGIN HANDLERS
    // ══════════════════════════════════════════════

    public function on_elementor( $record, $handler ) {
        $form_name = $record->get_form_settings( 'form_name' ) ?: 'Elementor Form';

        // Try to extract email/phone for enhanced matching
        $extra = $this->extract_elementor_fields( $record );

        $this->fire_lead( $form_name, 'elementor', '', $extra );
    }

    public function on_cf7( $contact_form ) {
        $form_name = $contact_form->title();
        $form_id   = $contact_form->id();
        $this->fire_lead( $form_name, 'cf7', $form_id );
    }

    public function on_wpforms( $fields, $entry, $form_data, $entry_id ) {
        $form_name = $form_data['settings']['form_title'] ?? 'WPForm';
        $form_id   = $form_data['id'] ?? '';

        $extra = $this->extract_wpforms_fields( $fields );

        $this->fire_lead( $form_name, 'wpforms', $form_id, $extra );
    }

    public function on_gravity_forms( $entry, $form ) {
        $form_name = $form['title'] ?? 'Gravity Form';
        $form_id   = $form['id'] ?? '';

        $extra = $this->extract_gf_fields( $entry, $form );

        $this->fire_lead( $form_name, 'gravityforms', $form_id, $extra );
    }

    public function on_ninja_forms( $form_data ) {
        $form_name = 'Ninja Form';
        $form_id   = '';

        if ( isset( $form_data['settings']['title'] ) ) {
            $form_name = $form_data['settings']['title'];
        }
        if ( isset( $form_data['form_id'] ) ) {
            $form_id = $form_data['form_id'];
        }

        $this->fire_lead( $form_name, 'ninjaforms', $form_id );
    }

    public function on_forminator( $entry, $form_id, $field_data_array ) {
        $form_name = 'Forminator #' . $form_id;

        // Try to get form title
        $form = \Forminator_API::get_form( $form_id );
        if ( ! is_wp_error( $form ) && isset( $form->settings['formName'] ) ) {
            $form_name = $form->settings['formName'];
        }

        $this->fire_lead( $form_name, 'forminator', $form_id );
    }

    public function on_fluent_forms( $entry_id, $form_data, $form ) {
        $form_name = $form->title ?? 'Fluent Form';
        $form_id   = $form->id ?? '';
        $this->fire_lead( $form_name, 'fluentforms', $form_id );
    }

    public function on_ws_form( $form, $submit ) {
        $form_name = $form->label ?? 'WS Form';
        $form_id   = $form->id ?? '';
        $this->fire_lead( $form_name, 'wsform', $form_id );
    }

    // ══════════════════════════════════════════════
    //  FIELD EXTRACTION (for enhanced matching)
    // ══════════════════════════════════════════════

    /**
     * Extract email/phone from Elementor form submission for CAPI matching.
     */
    private function extract_elementor_fields( $record ) {
        $extra = [];
        $raw = $record->get( 'fields' );

        foreach ( $raw as $field ) {
            $type  = $field['type'] ?? '';
            $value = $field['value'] ?? '';

            if ( $type === 'email' && $value ) {
                $extra['lead_email'] = sanitize_email( $value );
            }
            if ( $type === 'tel' && $value ) {
                $extra['lead_phone'] = sanitize_text_field( $value );
            }
        }

        return $extra;
    }

    /**
     * Extract email/phone from WPForms submission.
     */
    private function extract_wpforms_fields( $fields ) {
        $extra = [];

        foreach ( $fields as $field ) {
            $type  = $field['type'] ?? '';
            $value = $field['value'] ?? '';

            if ( $type === 'email' && $value ) {
                $extra['lead_email'] = sanitize_email( $value );
            }
            if ( $type === 'phone' && $value ) {
                $extra['lead_phone'] = sanitize_text_field( $value );
            }
        }

        return $extra;
    }

    /**
     * Extract email/phone from Gravity Forms entry.
     */
    private function extract_gf_fields( $entry, $form ) {
        $extra = [];

        if ( ! isset( $form['fields'] ) ) return $extra;

        foreach ( $form['fields'] as $field ) {
            $type  = $field->type ?? '';
            $value = rgar( $entry, (string) $field->id );

            if ( $type === 'email' && $value ) {
                $extra['lead_email'] = sanitize_email( $value );
            }
            if ( $type === 'phone' && $value ) {
                $extra['lead_phone'] = sanitize_text_field( $value );
            }
        }

        return $extra;
    }

    // ══════════════════════════════════════════════
    //  PENDING EVENTS (WC-independent session storage)
    // ══════════════════════════════════════════════

    /**
     * Store a pending event for dataLayer push on next page load.
     * Uses WC session if available, otherwise WP transient with cookie key.
     */
    private function store_pending_event( $event_data ) {
        // Prefer WC session if available
        if ( function_exists( 'WC' ) && WC()->session ) {
            $events   = WC()->session->get( 'meshulash_pending_events', [] );
            $events[] = $event_data;
            WC()->session->set( 'meshulash_pending_events', $events );
            return;
        }

        // Fallback: WP transient keyed by a visitor cookie
        $key = $this->get_visitor_key();
        $transient_name = 'msh_pending_' . $key;
        $events = get_transient( $transient_name );
        if ( ! is_array( $events ) ) $events = [];
        $events[] = $event_data;
        set_transient( $transient_name, $events, 300 ); // 5 min TTL
    }

    /**
     * Get or create a visitor key for transient storage.
     */
    private function get_visitor_key() {
        $cookie_name = 'meshulash_vid';

        if ( isset( $_COOKIE[ $cookie_name ] ) ) {
            return sanitize_text_field( $_COOKIE[ $cookie_name ] );
        }

        $key = bin2hex( random_bytes( 8 ) );
        if ( ! headers_sent() ) {
            setcookie( $cookie_name, $key, time() + 300, '/', '', is_ssl(), true );
        }
        $_COOKIE[ $cookie_name ] = $key;

        return $key;
    }

    /**
     * Flush pending lead events as inline dataLayer scripts.
     * For WC-independent lead tracking on non-WC sites.
     */
    public function flush_pending_events() {
        // If WC is active, class-ecommerce handles flushing via WC session
        if ( function_exists( 'WC' ) && WC()->session ) return;

        $key = $this->get_visitor_key();
        $transient_name = 'msh_pending_' . $key;
        $events = get_transient( $transient_name );

        if ( empty( $events ) || ! is_array( $events ) ) return;

        delete_transient( $transient_name );

        foreach ( $events as $event ) {
            Meshulash_DataLayer::push( $event, 'pending lead: ' . ( $event['event'] ?? '' ) );
        }
    }
}
