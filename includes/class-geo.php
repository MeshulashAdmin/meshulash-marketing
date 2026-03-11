<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Geo-location enrichment for server-side events.
 *
 * Enriches events with visitor's country, region, city, timezone, ISP.
 * Uses multi-source detection:
 *   1. CloudFlare headers (CF-IPCountry, CF-IPCity)
 *   2. Server GeoIP headers (GEOIP_COUNTRY_CODE)
 *   3. Free ip-api.com API with aggressive caching (30-day transient per IP)
 *
 * Enriches: Facebook CAPI user_data, GA4 MP user properties, TikTok context.
 */
class Meshulash_Geo {

    private static $cache = [];

    public function __construct() {
        if ( ! Meshulash_Settings::get( 'geo_enrichment' ) ) return;

        // Hook into server-side events to enrich with geo data
        add_filter( 'meshulash_enrich_user_data', [ $this, 'enrich_user_data' ], 10, 2 );
        add_filter( 'meshulash_enrich_event_data', [ $this, 'enrich_event_data' ], 10, 2 );

        // Expose geo data to frontend via localized script
        add_filter( 'meshulash_localize_data', [ $this, 'add_geo_to_frontend' ] );
    }

    /**
     * Look up geo data for an IP address.
     * Returns: [ 'country' => 'IL', 'region' => 'Tel Aviv', 'city' => 'Tel Aviv',
     *            'timezone' => 'Asia/Jerusalem', 'isp' => 'Bezeq', 'lat' => 32.0, 'lon' => 34.8 ]
     */
    public static function lookup( $ip = '' ) {
        if ( ! $ip ) {
            $ip = Meshulash_DataLayer::get_client_ip();
        }

        if ( ! $ip || ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
            return [];
        }

        // In-memory cache
        if ( isset( self::$cache[ $ip ] ) ) {
            return self::$cache[ $ip ];
        }

        // 1. Try server/CDN headers first (free, instant, no API call)
        $geo = self::from_headers();
        if ( ! empty( $geo['country'] ) ) {
            self::$cache[ $ip ] = $geo;
            return $geo;
        }

        // 2. Try WP transient cache
        $transient_key = 'msh_geo_' . md5( $ip );
        $cached = get_transient( $transient_key );
        if ( is_array( $cached ) && ! empty( $cached ) ) {
            self::$cache[ $ip ] = $cached;
            return $cached;
        }

        // 3. API lookup with caching
        $geo = self::api_lookup( $ip );
        if ( ! empty( $geo['country'] ) ) {
            set_transient( $transient_key, $geo, 30 * DAY_IN_SECONDS );
            self::$cache[ $ip ] = $geo;
            return $geo;
        }

        // Cache the miss too (avoid repeated API calls for the same IP)
        set_transient( $transient_key, [], DAY_IN_SECONDS );
        return [];
    }

    /**
     * Detect geo from CDN/server headers.
     * CloudFlare, Sucuri, Nginx GeoIP module, MaxMind GeoIP module.
     */
    private static function from_headers() {
        $geo = [];

        // CloudFlare
        if ( ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
            $geo['country'] = strtoupper( sanitize_text_field( $_SERVER['HTTP_CF_IPCOUNTRY'] ) );
        }
        if ( ! empty( $_SERVER['HTTP_CF_IPCITY'] ) ) {
            $geo['city'] = sanitize_text_field( $_SERVER['HTTP_CF_IPCITY'] );
        }
        if ( ! empty( $_SERVER['HTTP_CF_IPCONTINENT'] ) ) {
            $geo['continent'] = sanitize_text_field( $_SERVER['HTTP_CF_IPCONTINENT'] );
        }
        if ( ! empty( $_SERVER['HTTP_CF_REGION'] ) ) {
            $geo['region'] = sanitize_text_field( $_SERVER['HTTP_CF_REGION'] );
        }

        // Nginx GeoIP / MaxMind module
        if ( empty( $geo['country'] ) && ! empty( $_SERVER['GEOIP_COUNTRY_CODE'] ) ) {
            $geo['country'] = strtoupper( sanitize_text_field( $_SERVER['GEOIP_COUNTRY_CODE'] ) );
        }
        if ( empty( $geo['city'] ) && ! empty( $_SERVER['GEOIP_CITY'] ) ) {
            $geo['city'] = sanitize_text_field( $_SERVER['GEOIP_CITY'] );
        }
        if ( empty( $geo['region'] ) && ! empty( $_SERVER['GEOIP_REGION_NAME'] ) ) {
            $geo['region'] = sanitize_text_field( $_SERVER['GEOIP_REGION_NAME'] );
        }

        // Sucuri
        if ( empty( $geo['country'] ) && ! empty( $_SERVER['HTTP_X_SUCURI_COUNTRY'] ) ) {
            $geo['country'] = strtoupper( sanitize_text_field( $_SERVER['HTTP_X_SUCURI_COUNTRY'] ) );
        }

        return $geo;
    }

    /**
     * API lookup using ip-api.com (free, no key needed, 45 req/min).
     * Only called server-to-server, not exposed to browser.
     */
    private static function api_lookup( $ip ) {
        $url = 'http://ip-api.com/json/' . urlencode( $ip ) . '?fields=status,country,countryCode,region,regionName,city,zip,lat,lon,timezone,isp,as';

        $response = wp_remote_get( $url, [
            'timeout'   => 3,
            'sslverify' => false, // ip-api.com free tier is HTTP only
        ]);

        if ( is_wp_error( $response ) ) {
            if ( Meshulash_Settings::is_debug() ) {
                error_log( 'Meshulash Geo: API error — ' . $response->get_error_message() );
            }
            return [];
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! is_array( $body ) || ( $body['status'] ?? '' ) !== 'success' ) {
            return [];
        }

        return [
            'country'      => $body['countryCode'] ?? '',
            'country_name' => $body['country'] ?? '',
            'region'       => $body['regionName'] ?? '',
            'region_code'  => $body['region'] ?? '',
            'city'         => $body['city'] ?? '',
            'zip'          => $body['zip'] ?? '',
            'lat'          => $body['lat'] ?? '',
            'lon'          => $body['lon'] ?? '',
            'timezone'     => $body['timezone'] ?? '',
            'isp'          => $body['isp'] ?? '',
            'as'           => $body['as'] ?? '',
        ];
    }

    /**
     * Enrich FB CAPI / server-side user_data with geo.
     * Adds hashed city, state, zip, country for better matching.
     */
    public function enrich_user_data( $user_data, $order = null ) {
        $geo = self::lookup();
        if ( empty( $geo ) ) return $user_data;

        // Only fill in geo data if not already provided from order/user
        if ( empty( $user_data['ct'] ) && ! empty( $geo['city'] ) ) {
            $user_data['ct'] = hash( 'sha256', strtolower( trim( $geo['city'] ) ) );
        }
        if ( empty( $user_data['st'] ) && ! empty( $geo['region'] ) ) {
            $user_data['st'] = hash( 'sha256', strtolower( trim( $geo['region'] ) ) );
        }
        if ( empty( $user_data['zp'] ) && ! empty( $geo['zip'] ) ) {
            $user_data['zp'] = hash( 'sha256', strtolower( trim( $geo['zip'] ) ) );
        }
        if ( empty( $user_data['country'] ) && ! empty( $geo['country'] ) ) {
            $user_data['country'] = hash( 'sha256', strtolower( trim( $geo['country'] ) ) );
        }

        return $user_data;
    }

    /**
     * Enrich event custom_data with raw geo info (for analytics/reporting).
     */
    public function enrich_event_data( $custom_data, $event_name ) {
        $geo = self::lookup();
        if ( empty( $geo ) ) return $custom_data;

        $custom_data['geo_country']  = $geo['country'] ?? '';
        $custom_data['geo_region']   = $geo['region'] ?? '';
        $custom_data['geo_city']     = $geo['city'] ?? '';
        $custom_data['geo_timezone'] = $geo['timezone'] ?? '';

        return $custom_data;
    }

    /**
     * Add geo data to frontend localized meshulash object.
     */
    public function add_geo_to_frontend( $data ) {
        $geo = self::lookup();
        if ( ! empty( $geo ) ) {
            $data['geo'] = [
                'country'      => $geo['country'] ?? '',
                'country_name' => $geo['country_name'] ?? '',
                'region'       => $geo['region'] ?? '',
                'city'         => $geo['city'] ?? '',
                'timezone'     => $geo['timezone'] ?? '',
            ];
        }
        return $data;
    }

    /**
     * Get geo data for the current visitor (public static for use anywhere).
     */
    public static function get_current_geo() {
        if ( ! Meshulash_Settings::get( 'geo_enrichment' ) ) return [];
        return self::lookup();
    }
}
