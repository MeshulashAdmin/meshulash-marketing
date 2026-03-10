<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Meshulash_Mumble {

    private $api_base = 'https://app.mumble.co.il/mumbleapi/';
    private $api_key  = '';

    public function __construct() {
        $this->api_key = Meshulash_Settings::get( 'mumble_api_key' );

        if ( ! Meshulash_Settings::get( 'mumble_enabled' ) || ! $this->api_key ) return;

        // On purchase — sync customer + optional template
        if ( Meshulash_Settings::get( 'mumble_on_purchase' ) ) {
            add_action( 'woocommerce_thankyou', [ $this, 'on_purchase' ], 20, 1 );
        }

        // On lead / form submission
        if ( Meshulash_Settings::get( 'mumble_on_lead' ) ) {
            add_action( 'elementor_pro/forms/new_record', [ $this, 'on_lead_elementor' ], 20, 2 );
            add_action( 'wpcf7_mail_sent', [ $this, 'on_lead_cf7' ], 20 );
            add_action( 'wpforms_process_complete', [ $this, 'on_lead_wpforms' ], 20, 4 );
        }

        // On registration
        if ( Meshulash_Settings::get( 'mumble_on_signup' ) ) {
            add_action( 'user_register', [ $this, 'on_signup' ], 20 );
        }

        // AJAX handlers for admin (get templates, send template, test connection)
        add_action( 'wp_ajax_meshulash_mumble_test', [ $this, 'ajax_test_connection' ] );
        add_action( 'wp_ajax_meshulash_mumble_templates', [ $this, 'ajax_get_templates' ] );
        add_action( 'wp_ajax_meshulash_mumble_labels', [ $this, 'ajax_get_labels' ] );
        add_action( 'wp_ajax_meshulash_mumble_teams', [ $this, 'ajax_get_teams' ] );
        add_action( 'wp_ajax_meshulash_mumble_send_template', [ $this, 'ajax_send_template' ] );
    }

    // ══════════════════════════════════════════════
    //  EVENT HANDLERS
    // ══════════════════════════════════════════════

    /**
     * On purchase: create/update customer in Mumble + optional template.
     */
    public function on_purchase( $order_id ) {
        if ( ! $order_id ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        // Prevent duplicate sends
        if ( $order->get_meta( '_meshulash_mumble_sent' ) ) return;

        $phone = $order->get_billing_phone();
        if ( ! $phone ) return;

        // Build customer data
        $customer_data = $this->build_customer_data_from_order( $order );

        // Create/update customer in Mumble
        $result = $this->add_customer( $customer_data );

        if ( Meshulash_Settings::is_debug() ) {
            error_log( 'Meshulash Mumble [purchase] Phone: ' . $phone . ' Result: ' . wp_json_encode( $result ) );
        }

        // Add label if configured
        $label = Meshulash_Settings::get( 'mumble_purchase_label' );
        if ( $label && $phone ) {
            $this->add_customer_to_label( $phone, $label );
        }

        // Send purchase template if configured
        $template_id = Meshulash_Settings::get( 'mumble_purchase_template' );
        if ( $template_id ) {
            $variables = $this->build_purchase_template_vars( $order );
            $this->send_template( $phone, $template_id, $variables );
        }

        $order->update_meta_data( '_meshulash_mumble_sent', time() );
        $order->save();
    }

    /**
     * On cart abandonment — called via dataLayer event hook or scheduled action.
     */
    public static function on_abandonment_manual( $phone, $cart_restore_url, $customer_name = '' ) {
        $instance = new self();
        if ( ! $instance->api_key || ! Meshulash_Settings::get( 'mumble_on_abandonment' ) ) return;

        $template_id = Meshulash_Settings::get( 'mumble_abandon_template' );
        if ( ! $template_id || ! $phone ) return;

        $variables = [];
        if ( $customer_name ) $variables[] = $customer_name;
        if ( $cart_restore_url ) $variables[] = $cart_restore_url;

        $instance->send_template( $phone, $template_id, $variables );

        if ( Meshulash_Settings::is_debug() ) {
            error_log( 'Meshulash Mumble [abandonment] Phone: ' . $phone );
        }
    }

    /**
     * On lead (Elementor form).
     */
    public function on_lead_elementor( $record, $handler ) {
        $raw_fields = $record->get( 'fields' );
        $fields = [];
        foreach ( $raw_fields as $f ) {
            $fields[ strtolower( $f['id'] ) ] = $f['value'];
        }

        $phone = $this->extract_phone( $fields );
        if ( ! $phone ) return;

        $customer_data = $this->build_customer_data_from_form( $fields, $phone );
        $customer_data['notes'] = 'Lead from: ' . ( $record->get_form_settings( 'form_name' ) ?: 'Elementor form' );

        $this->add_customer( $customer_data );

        $label = Meshulash_Settings::get( 'mumble_lead_label' );
        if ( $label ) {
            $this->add_customer_to_label( $phone, $label );
        }

        $template_id = Meshulash_Settings::get( 'mumble_lead_template' );
        if ( $template_id ) {
            $variables = [];
            $name = $this->extract_name( $fields );
            if ( $name ) $variables[] = $name;
            $this->send_template( $phone, $template_id, $variables );
        }

        if ( Meshulash_Settings::is_debug() ) {
            error_log( 'Meshulash Mumble [lead_elementor] Phone: ' . $phone );
        }
    }

    /**
     * On lead (Contact Form 7).
     */
    public function on_lead_cf7( $contact_form ) {
        $submission = WPCF7_Submission::get_instance();
        if ( ! $submission ) return;

        $fields = $submission->get_posted_data();
        $phone  = $this->extract_phone( $fields );
        if ( ! $phone ) return;

        $customer_data = $this->build_customer_data_from_form( $fields, $phone );
        $customer_data['notes'] = 'Lead from: ' . $contact_form->title();

        $this->add_customer( $customer_data );

        $label = Meshulash_Settings::get( 'mumble_lead_label' );
        if ( $label ) {
            $this->add_customer_to_label( $phone, $label );
        }

        $template_id = Meshulash_Settings::get( 'mumble_lead_template' );
        if ( $template_id ) {
            $variables = [];
            $name = $this->extract_name( $fields );
            if ( $name ) $variables[] = $name;
            $this->send_template( $phone, $template_id, $variables );
        }

        if ( Meshulash_Settings::is_debug() ) {
            error_log( 'Meshulash Mumble [lead_cf7] Phone: ' . $phone );
        }
    }

    /**
     * On lead (WPForms).
     */
    public function on_lead_wpforms( $fields, $entry, $form_data, $entry_id ) {
        $flat = [];
        foreach ( $fields as $f ) {
            $flat[ strtolower( $f['name'] ?? $f['id'] ?? '' ) ] = $f['value'] ?? '';
        }

        $phone = $this->extract_phone( $flat );
        if ( ! $phone ) return;

        $customer_data = $this->build_customer_data_from_form( $flat, $phone );
        $customer_data['notes'] = 'Lead from: ' . ( $form_data['settings']['form_title'] ?? 'WPForm' );

        $this->add_customer( $customer_data );

        $label = Meshulash_Settings::get( 'mumble_lead_label' );
        if ( $label ) {
            $this->add_customer_to_label( $phone, $label );
        }

        $template_id = Meshulash_Settings::get( 'mumble_lead_template' );
        if ( $template_id ) {
            $variables = [];
            $name = $this->extract_name( $flat );
            if ( $name ) $variables[] = $name;
            $this->send_template( $phone, $template_id, $variables );
        }

        if ( Meshulash_Settings::is_debug() ) {
            error_log( 'Meshulash Mumble [lead_wpforms] Phone: ' . $phone );
        }
    }

    /**
     * On signup (user registration).
     */
    public function on_signup( $user_id ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) return;

        $phone = get_user_meta( $user_id, 'billing_phone', true );
        if ( ! $phone ) return;

        $customer_data = [
            'customer_phone'      => $this->normalize_phone( $phone ),
            'customer_first_name' => $user->first_name,
            'customer_last_name'  => $user->last_name,
            'customer_email'      => $user->user_email,
        ];

        if ( Meshulash_Settings::get( 'mumble_send_utm' ) ) {
            $customer_data = array_merge( $customer_data, $this->get_utm_from_cookies() );
        }

        $team = Meshulash_Settings::get( 'mumble_default_team' );
        if ( $team ) $customer_data['team'] = $team;

        $this->add_customer( $customer_data );

        if ( Meshulash_Settings::is_debug() ) {
            error_log( 'Meshulash Mumble [signup] User: ' . $user_id . ' Phone: ' . $phone );
        }
    }

    // ══════════════════════════════════════════════
    //  DATA BUILDERS
    // ══════════════════════════════════════════════

    /**
     * Build Mumble customer data from WC_Order.
     */
    private function build_customer_data_from_order( $order ) {
        $data = [
            'customer_phone'      => $this->normalize_phone( $order->get_billing_phone() ),
            'customer_first_name' => $order->get_billing_first_name(),
            'customer_last_name'  => $order->get_billing_last_name(),
            'customer_email'      => $order->get_billing_email(),
            'customer_city'       => $order->get_billing_city(),
            'customer_address'    => trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() ),
        ];

        // UTM data from order meta
        if ( Meshulash_Settings::get( 'mumble_send_utm' ) ) {
            $utm = $order->get_meta( '_meshulash_utm' );
            if ( is_array( $utm ) ) {
                $utm_map = [
                    'utm_source'   => 'utm_source',
                    'utm_medium'   => 'utm_medium',
                    'utm_campaign' => 'utm_campaign',
                    'utm_content'  => 'utm_content',
                    'utm_term'     => 'utm_term',
                    'utm_id'       => 'utm_id',
                    'campaign_id'  => 'campaign_id',
                    'adset_id'     => 'adset_id',
                    'ad_id'        => 'ad_id',
                    'keyword_id'   => 'keyword_id',
                    'gclid'        => 'gclid',
                    'fbclid'       => 'fbclid',
                    'msclkid'      => 'msclkid',
                    'ttcid'        => 'ttcid',
                    'placement'    => 'placement',
                    'matchtype'    => 'matchtype',
                    'network'      => 'network',
                    'device'       => 'device',
                    'GeoLoc'       => 'GeoLoc',
                    'IntLoc'       => 'IntLoc',
                ];
                foreach ( $utm_map as $utm_key => $mumble_key ) {
                    if ( ! empty( $utm[ $utm_key ] ) ) {
                        $data[ $mumble_key ] = $utm[ $utm_key ];
                    }
                }
            }
        }

        // Add notes with order summary
        $items_summary = [];
        foreach ( $order->get_items() as $item ) {
            $items_summary[] = $item->get_name() . ' x' . $item->get_quantity();
        }
        $data['notes'] = sprintf(
            'WooCommerce Order #%s | Total: %s %s | Items: %s',
            $order->get_order_number(),
            $order->get_total(),
            $order->get_currency(),
            implode( ', ', $items_summary )
        );

        $team = Meshulash_Settings::get( 'mumble_default_team' );
        if ( $team ) $data['team'] = $team;

        return $data;
    }

    /**
     * Build Mumble customer data from form fields.
     */
    private function build_customer_data_from_form( $fields, $phone ) {
        $data = [
            'customer_phone' => $this->normalize_phone( $phone ),
        ];

        // Try to extract name
        $name = $this->extract_name( $fields );
        if ( $name ) {
            $parts = explode( ' ', $name, 2 );
            $data['customer_first_name'] = $parts[0];
            if ( isset( $parts[1] ) ) $data['customer_last_name'] = $parts[1];
        }

        // Try to extract email
        $email = $this->extract_email( $fields );
        if ( $email ) $data['customer_email'] = $email;

        // UTM from cookies
        if ( Meshulash_Settings::get( 'mumble_send_utm' ) ) {
            $data = array_merge( $data, $this->get_utm_from_cookies() );
        }

        $team = Meshulash_Settings::get( 'mumble_default_team' );
        if ( $team ) $data['team'] = $team;

        return $data;
    }

    /**
     * Build template variables for a purchase confirmation.
     */
    private function build_purchase_template_vars( $order ) {
        return [
            $order->get_billing_first_name(),
            $order->get_order_number(),
            $order->get_formatted_order_total(),
        ];
    }

    // ══════════════════════════════════════════════
    //  MUMBLE API CALLS
    // ══════════════════════════════════════════════

    /**
     * Add or update a customer in Mumble.
     */
    public function add_customer( $data ) {
        return $this->api_request( 'add-new-customer', $data );
    }

    /**
     * Get all templates from Mumble.
     */
    public function get_templates() {
        return $this->api_request( 'get-templates', [], 'GET' );
    }

    /**
     * Send a template message to a customer.
     */
    public function send_template( $phone, $template_id, $variables = [], $button_variables = [], $media_link = '' ) {
        $data = [
            'customer_phone' => $this->normalize_phone( $phone ),
            'template_id'    => $template_id,
        ];

        if ( ! empty( $variables ) ) {
            $data['text_variable'] = $variables;
        }
        if ( ! empty( $button_variables ) ) {
            $data['button_variables'] = $button_variables;
        }
        if ( $media_link ) {
            $data['media_link'] = $media_link;
        }

        $endpoint = ! empty( $variables ) ? 'send-template-text-variable' : 'send-template';
        return $this->api_request( $endpoint, $data );
    }

    /**
     * Send a text message to a customer.
     */
    public function send_text( $phone, $message ) {
        return $this->api_request( 'send-text', [
            'customer_phone' => $this->normalize_phone( $phone ),
            'message'        => $message,
        ]);
    }

    /**
     * Get all labels from Mumble.
     */
    public function get_labels() {
        return $this->api_request( 'get-all-labels', [], 'GET' );
    }

    /**
     * Add a customer to a label.
     */
    public function add_customer_to_label( $phone, $label_name ) {
        return $this->api_request( 'add-customer-to-label', [
            'customer_phone' => $this->normalize_phone( $phone ),
            'label_name'     => $label_name,
        ]);
    }

    /**
     * Get all teams from Mumble.
     */
    public function get_teams() {
        return $this->api_request( 'get-all-teams', [], 'GET' );
    }

    /**
     * Get a customer by phone.
     */
    public function get_customer( $phone ) {
        return $this->api_request( 'get-customer', [
            'customer_phone' => $this->normalize_phone( $phone ),
        ]);
    }

    // ══════════════════════════════════════════════
    //  AJAX HANDLERS (Admin)
    // ══════════════════════════════════════════════

    public function ajax_test_connection() {
        check_ajax_referer( 'meshulash_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( $_POST['api_key'] ) : $this->api_key;
        if ( ! $api_key ) wp_send_json_error( 'No API key provided' );

        $this->api_key = $api_key;
        $result = $this->get_templates();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( [ 'message' => 'Connected successfully', 'templates_count' => is_array( $result ) ? count( $result ) : 0 ] );
    }

    public function ajax_get_templates() {
        check_ajax_referer( 'meshulash_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $result = $this->get_templates();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( $result );
    }

    public function ajax_get_labels() {
        check_ajax_referer( 'meshulash_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $result = $this->get_labels();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( $result );
    }

    public function ajax_get_teams() {
        check_ajax_referer( 'meshulash_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $result = $this->get_teams();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( $result );
    }

    public function ajax_send_template() {
        check_ajax_referer( 'meshulash_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $phone       = isset( $_POST['phone'] ) ? sanitize_text_field( $_POST['phone'] ) : '';
        $template_id = isset( $_POST['template_id'] ) ? sanitize_text_field( $_POST['template_id'] ) : '';
        $variables   = isset( $_POST['variables'] ) ? array_map( 'sanitize_text_field', (array) $_POST['variables'] ) : [];

        if ( ! $phone || ! $template_id ) {
            wp_send_json_error( 'Phone and template ID are required' );
        }

        $result = $this->send_template( $phone, $template_id, $variables );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( $result );
    }

    // ══════════════════════════════════════════════
    //  CORE API REQUEST
    // ══════════════════════════════════════════════

    /**
     * Make a request to the Mumble API.
     *
     * @param string $endpoint  API endpoint (e.g. 'add-new-customer')
     * @param array  $data      Request body data
     * @param string $method    HTTP method (POST or GET)
     * @return array|WP_Error   Decoded response or error
     */
    private function api_request( $endpoint, $data = [], $method = 'POST' ) {
        if ( ! $this->api_key ) {
            return new WP_Error( 'mumble_no_key', 'Mumble API key is not configured' );
        }

        $url = $this->api_base . $endpoint;

        $args = [
            'timeout' => 15,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Mumble-Api-Key' => $this->api_key,
            ],
        ];

        if ( $method === 'GET' ) {
            if ( ! empty( $data ) ) {
                $url = add_query_arg( $data, $url );
            }
            $response = wp_remote_get( $url, $args );
        } else {
            $args['body'] = wp_json_encode( $data );
            $response = wp_remote_post( $url, $args );
        }

        if ( is_wp_error( $response ) ) {
            if ( Meshulash_Settings::is_debug() ) {
                error_log( 'Meshulash Mumble API error [' . $endpoint . ']: ' . $response->get_error_message() );
            }
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $decoded = json_decode( $body, true );

        if ( $code >= 400 ) {
            $msg = isset( $decoded['message'] ) ? $decoded['message'] : 'HTTP ' . $code;
            if ( Meshulash_Settings::is_debug() ) {
                error_log( 'Meshulash Mumble API [' . $endpoint . '] HTTP ' . $code . ': ' . $body );
            }
            return new WP_Error( 'mumble_api_error', $msg );
        }

        return $decoded ?: [];
    }

    // ══════════════════════════════════════════════
    //  HELPERS
    // ══════════════════════════════════════════════

    /**
     * Normalize phone number to international format.
     * Converts Israeli local format (05x) to +9725x.
     */
    private function normalize_phone( $phone ) {
        $phone = preg_replace( '/[^0-9+]/', '', $phone );

        // Israeli local → international
        if ( preg_match( '/^0(5\d{8})$/', $phone, $m ) ) {
            return '972' . $m[1];
        }

        // Remove leading + if present (Mumble expects digits only)
        $phone = ltrim( $phone, '+' );

        return $phone;
    }

    /**
     * Extract phone number from form fields array.
     */
    private function extract_phone( $fields ) {
        $phone_keys = [ 'phone', 'tel', 'telephone', 'mobile', 'cell', 'phone_number', 'customer_phone', 'billing_phone' ];
        foreach ( $phone_keys as $key ) {
            foreach ( $fields as $field_key => $value ) {
                if ( stripos( $field_key, $key ) !== false && ! empty( $value ) ) {
                    return sanitize_text_field( $value );
                }
            }
        }
        // Fallback: find any field that looks like a phone number
        foreach ( $fields as $value ) {
            if ( is_string( $value ) && preg_match( '/^[+]?[0-9\s\-()]{7,15}$/', trim( $value ) ) ) {
                return sanitize_text_field( $value );
            }
        }
        return '';
    }

    /**
     * Extract name from form fields array.
     */
    private function extract_name( $fields ) {
        $name_keys = [ 'name', 'full_name', 'fullname', 'customer_name', 'your-name', 'first_name' ];
        foreach ( $name_keys as $key ) {
            foreach ( $fields as $field_key => $value ) {
                if ( stripos( $field_key, $key ) !== false && ! empty( $value ) ) {
                    return sanitize_text_field( $value );
                }
            }
        }
        return '';
    }

    /**
     * Extract email from form fields array.
     */
    private function extract_email( $fields ) {
        $email_keys = [ 'email', 'e-mail', 'your-email', 'customer_email', 'mail' ];
        foreach ( $email_keys as $key ) {
            foreach ( $fields as $field_key => $value ) {
                if ( stripos( $field_key, $key ) !== false && is_email( $value ) ) {
                    return sanitize_email( $value );
                }
            }
        }
        return '';
    }

    /**
     * Get UTM data from cookies (for form submissions where order meta isn't available).
     */
    private function get_utm_from_cookies() {
        $utm_fields = [
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
            'utm_id', 'campaign_id', 'adset_id', 'ad_id', 'keyword_id',
            'gclid', 'fbclid', 'msclkid', 'ttcid',
            'device', 'GeoLoc', 'IntLoc', 'placement', 'matchtype', 'network',
        ];

        $data = [];
        foreach ( $utm_fields as $field ) {
            if ( isset( $_COOKIE[ $field ] ) && $_COOKIE[ $field ] !== '' ) {
                $data[ $field ] = sanitize_text_field( $_COOKIE[ $field ] );
            }
        }
        return $data;
    }
}
