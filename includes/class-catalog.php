<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Product Catalog Feed Generator.
 * Generates XML feeds for Facebook, Google Shopping, and Pinterest.
 * Feeds are accessible via: /wp-json/meshulash/v1/feed/{type}
 */
class Meshulash_Catalog {

    public function __construct() {
        // Register REST API endpoints for feeds
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );

        // Schedule feed generation
        add_action( 'meshulash_generate_feeds', [ $this, 'generate_all_feeds' ] );
        if ( ! wp_next_scheduled( 'meshulash_generate_feeds' ) ) {
            wp_schedule_event( time(), 'daily', 'meshulash_generate_feeds' );
        }

        // Admin: add feed URLs to admin page
        add_action( 'admin_init', [ $this, 'maybe_generate_on_demand' ] );
    }

    /**
     * Register REST API routes for feed access.
     */
    public function register_routes() {
        register_rest_route( 'meshulash/v1', '/feed/(?P<type>facebook|google|pinterest)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'serve_feed' ],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Serve a cached feed or generate on-the-fly.
     */
    public function serve_feed( $request ) {
        $type = $request->get_param( 'type' );
        $feed_file = $this->get_feed_path( $type );

        // Serve cached file if it exists and is less than 24h old
        if ( file_exists( $feed_file ) && ( time() - filemtime( $feed_file ) ) < 86400 ) {
            $content = file_get_contents( $feed_file );
        } else {
            $content = $this->generate_feed( $type );
            if ( $content ) {
                wp_mkdir_p( dirname( $feed_file ) );
                file_put_contents( $feed_file, $content );
            }
        }

        if ( ! $content ) {
            return new WP_Error( 'no_products', 'No products found', [ 'status' => 404 ] );
        }

        $response = new WP_REST_Response( null, 200 );
        header( 'Content-Type: application/xml; charset=utf-8' );
        echo $content;
        exit;
    }

    /**
     * Get the file path for a feed type.
     */
    private function get_feed_path( $type ) {
        $upload_dir = wp_upload_dir();
        return $upload_dir['basedir'] . '/meshulash-feeds/' . $type . '-feed.xml';
    }

    /**
     * Get the public URL for a feed type.
     */
    public static function get_feed_url( $type ) {
        return rest_url( 'meshulash/v1/feed/' . $type );
    }

    /**
     * Generate all feed types.
     */
    public function generate_all_feeds() {
        if ( ! Meshulash_Settings::get( 'catalog_enabled' ) ) return;

        foreach ( [ 'facebook', 'google', 'pinterest' ] as $type ) {
            $content = $this->generate_feed( $type );
            if ( $content ) {
                $feed_file = $this->get_feed_path( $type );
                wp_mkdir_p( dirname( $feed_file ) );
                file_put_contents( $feed_file, $content );
            }
        }
    }

    /**
     * Generate feed XML for a given type.
     */
    public function generate_feed( $type ) {
        if ( ! function_exists( 'WC' ) ) return '';

        $products = wc_get_products([
            'status'  => 'publish',
            'limit'   => -1,
            'type'    => [ 'simple', 'variable', 'external' ],
            'orderby' => 'date',
            'order'   => 'DESC',
        ]);

        if ( empty( $products ) ) return '';

        $shop_name = get_bloginfo( 'name' );
        $shop_url  = home_url( '/' );
        $currency  = get_woocommerce_currency();

        switch ( $type ) {
            case 'facebook':
                return $this->generate_facebook_feed( $products, $shop_name, $shop_url, $currency );
            case 'google':
                return $this->generate_google_feed( $products, $shop_name, $shop_url, $currency );
            case 'pinterest':
                return $this->generate_pinterest_feed( $products, $shop_name, $shop_url, $currency );
            default:
                return '';
        }
    }

    /**
     * Generate Facebook Product Catalog feed.
     */
    private function generate_facebook_feed( $products, $shop_name, $shop_url, $currency ) {
        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
        $xml .= "<channel>\n";
        $xml .= '<title>' . $this->cdata( $shop_name ) . "</title>\n";
        $xml .= '<link>' . esc_url( $shop_url ) . "</link>\n";
        $xml .= "<description>Product Catalog</description>\n";

        foreach ( $products as $product ) {
            $items = $this->get_product_items( $product );
            foreach ( $items as $item ) {
                $xml .= "<item>\n";
                $xml .= '<g:id>' . esc_xml( $item['id'] ) . "</g:id>\n";
                $xml .= '<g:title>' . $this->cdata( $item['title'] ) . "</g:title>\n";
                $xml .= '<g:description>' . $this->cdata( $item['description'] ) . "</g:description>\n";
                $xml .= '<g:link>' . esc_url( $item['link'] ) . "</g:link>\n";
                $xml .= '<g:image_link>' . esc_url( $item['image_link'] ) . "</g:image_link>\n";
                if ( ! empty( $item['additional_images'] ) ) {
                    foreach ( array_slice( $item['additional_images'], 0, 9 ) as $img ) {
                        $xml .= '<g:additional_image_link>' . esc_url( $img ) . "</g:additional_image_link>\n";
                    }
                }
                $xml .= '<g:availability>' . esc_xml( $item['availability'] ) . "</g:availability>\n";
                $xml .= '<g:price>' . esc_xml( $item['price'] . ' ' . $currency ) . "</g:price>\n";
                if ( $item['sale_price'] ) {
                    $xml .= '<g:sale_price>' . esc_xml( $item['sale_price'] . ' ' . $currency ) . "</g:sale_price>\n";
                }
                $xml .= '<g:brand>' . $this->cdata( $item['brand'] ) . "</g:brand>\n";
                $xml .= '<g:condition>' . esc_xml( $item['condition'] ) . "</g:condition>\n";
                if ( $item['gtin'] ) {
                    $xml .= '<g:gtin>' . esc_xml( $item['gtin'] ) . "</g:gtin>\n";
                }
                if ( $item['sku'] ) {
                    $xml .= '<g:mpn>' . esc_xml( $item['sku'] ) . "</g:mpn>\n";
                }
                if ( $item['group_id'] ) {
                    $xml .= '<g:item_group_id>' . esc_xml( $item['group_id'] ) . "</g:item_group_id>\n";
                }
                if ( $item['product_type'] ) {
                    $xml .= '<g:product_type>' . $this->cdata( $item['product_type'] ) . "</g:product_type>\n";
                }
                if ( $item['inventory'] !== null ) {
                    $xml .= '<g:inventory>' . intval( $item['inventory'] ) . "</g:inventory>\n";
                }
                $xml .= "</item>\n";
            }
        }

        $xml .= "</channel>\n</rss>";
        return $xml;
    }

    /**
     * Generate Google Shopping feed.
     */
    private function generate_google_feed( $products, $shop_name, $shop_url, $currency ) {
        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
        $xml .= "<channel>\n";
        $xml .= '<title>' . $this->cdata( $shop_name ) . "</title>\n";
        $xml .= '<link>' . esc_url( $shop_url ) . "</link>\n";
        $xml .= "<description>Google Shopping Feed</description>\n";

        foreach ( $products as $product ) {
            $items = $this->get_product_items( $product );
            foreach ( $items as $item ) {
                $xml .= "<item>\n";
                $xml .= '<g:id>' . esc_xml( $item['id'] ) . "</g:id>\n";
                $xml .= '<g:title>' . $this->cdata( mb_substr( $item['title'], 0, 150 ) ) . "</g:title>\n";
                $xml .= '<g:description>' . $this->cdata( mb_substr( $item['description'], 0, 5000 ) ) . "</g:description>\n";
                $xml .= '<g:link>' . esc_url( $item['link'] ) . "</g:link>\n";
                $xml .= '<g:image_link>' . esc_url( $item['image_link'] ) . "</g:image_link>\n";
                if ( ! empty( $item['additional_images'] ) ) {
                    foreach ( array_slice( $item['additional_images'], 0, 9 ) as $img ) {
                        $xml .= '<g:additional_image_link>' . esc_url( $img ) . "</g:additional_image_link>\n";
                    }
                }
                $xml .= '<g:availability>' . esc_xml( $item['availability'] ) . "</g:availability>\n";
                $xml .= '<g:price>' . esc_xml( $item['price'] . ' ' . $currency ) . "</g:price>\n";
                if ( $item['sale_price'] ) {
                    $xml .= '<g:sale_price>' . esc_xml( $item['sale_price'] . ' ' . $currency ) . "</g:sale_price>\n";
                }
                $xml .= '<g:brand>' . $this->cdata( $item['brand'] ) . "</g:brand>\n";
                $xml .= '<g:condition>' . esc_xml( $item['condition'] ) . "</g:condition>\n";
                if ( $item['gtin'] ) {
                    $xml .= '<g:gtin>' . esc_xml( $item['gtin'] ) . "</g:gtin>\n";
                    $xml .= "<g:identifier_exists>yes</g:identifier_exists>\n";
                } elseif ( $item['sku'] ) {
                    $xml .= '<g:mpn>' . esc_xml( $item['sku'] ) . "</g:mpn>\n";
                    $xml .= "<g:identifier_exists>yes</g:identifier_exists>\n";
                } else {
                    $xml .= "<g:identifier_exists>no</g:identifier_exists>\n";
                }
                if ( $item['group_id'] ) {
                    $xml .= '<g:item_group_id>' . esc_xml( $item['group_id'] ) . "</g:item_group_id>\n";
                }
                if ( $item['product_type'] ) {
                    $xml .= '<g:product_type>' . $this->cdata( $item['product_type'] ) . "</g:product_type>\n";
                }
                if ( $item['google_category'] ) {
                    $xml .= '<g:google_product_category>' . $this->cdata( $item['google_category'] ) . "</g:google_product_category>\n";
                }
                // Shipping weight
                $weight = $product->get_weight();
                if ( $weight ) {
                    $weight_unit = get_option( 'woocommerce_weight_unit', 'kg' );
                    $xml .= '<g:shipping_weight>' . esc_xml( $weight . ' ' . $weight_unit ) . "</g:shipping_weight>\n";
                }
                $xml .= "</item>\n";
            }
        }

        $xml .= "</channel>\n</rss>";
        return $xml;
    }

    /**
     * Generate Pinterest Product feed.
     */
    private function generate_pinterest_feed( $products, $shop_name, $shop_url, $currency ) {
        // Pinterest uses same RSS format as Google Shopping
        return $this->generate_google_feed( $products, $shop_name, $shop_url, $currency );
    }

    /**
     * Extract product items (handles variable products).
     */
    private function get_product_items( $product ) {
        $items = [];

        if ( $product->is_type( 'variable' ) ) {
            $variations = $product->get_available_variations();
            foreach ( $variations as $variation_data ) {
                $variation = wc_get_product( $variation_data['variation_id'] );
                if ( ! $variation || ! $variation->is_purchasable() ) continue;

                $item = $this->format_product_data( $variation );
                $item['group_id'] = (string) $product->get_id();
                // Use parent title + variation attributes
                $attr_str = implode( ' / ', array_filter( $variation_data['attributes'] ) );
                if ( $attr_str ) {
                    $item['title'] = $product->get_name() . ' — ' . $attr_str;
                }
                // Use parent image if variation has none
                if ( ! $item['image_link'] ) {
                    $item['image_link'] = $this->get_product_image( $product );
                }
                // Use parent description if variation has none
                if ( ! $item['description'] ) {
                    $item['description'] = $this->get_product_description( $product );
                }
                // Use parent categories
                $item['product_type'] = $this->get_product_categories( $product );
                $item['google_category'] = $this->get_google_category( $product );
                $items[] = $item;
            }

            // If no variations found, add parent as simple product
            if ( empty( $items ) ) {
                $items[] = $this->format_product_data( $product );
            }
        } else {
            $items[] = $this->format_product_data( $product );
        }

        return $items;
    }

    /**
     * Format a single product/variation into feed data.
     */
    private function format_product_data( $product ) {
        $regular_price = (float) $product->get_regular_price();
        $sale_price    = $product->is_on_sale() ? (float) $product->get_sale_price() : 0;
        $price         = $regular_price ?: (float) $product->get_price();

        return [
            'id'                => (string) $product->get_id(),
            'title'             => $product->get_name(),
            'description'       => $this->get_product_description( $product ),
            'link'              => $product->get_permalink(),
            'image_link'        => $this->get_product_image( $product ),
            'additional_images' => $this->get_additional_images( $product ),
            'availability'      => $product->is_in_stock() ? 'in stock' : 'out of stock',
            'price'             => number_format( $price, 2, '.', '' ),
            'sale_price'        => $sale_price > 0 && $sale_price < $price ? number_format( $sale_price, 2, '.', '' ) : '',
            'brand'             => $this->get_brand( $product ),
            'condition'         => 'new',
            'gtin'              => $product->get_meta( '_gtin' ) ?: $product->get_meta( '_global_unique_id' ) ?: '',
            'sku'               => $product->get_sku(),
            'group_id'          => '',
            'product_type'      => $this->get_product_categories( $product ),
            'google_category'   => $this->get_google_category( $product ),
            'inventory'         => $product->managing_stock() ? $product->get_stock_quantity() : null,
        ];
    }

    private function get_product_description( $product ) {
        $desc = $product->get_short_description();
        if ( ! $desc ) $desc = $product->get_description();
        if ( ! $desc ) $desc = $product->get_name();
        return wp_strip_all_tags( strip_shortcodes( $desc ) );
    }

    private function get_product_image( $product ) {
        $image_id = $product->get_image_id();
        if ( $image_id ) {
            $url = wp_get_attachment_image_url( $image_id, 'full' );
            if ( $url ) return $url;
        }
        return wc_placeholder_img_src( 'full' );
    }

    private function get_additional_images( $product ) {
        $gallery_ids = $product->get_gallery_image_ids();
        $images = [];
        foreach ( $gallery_ids as $img_id ) {
            $url = wp_get_attachment_image_url( $img_id, 'full' );
            if ( $url ) $images[] = $url;
        }
        return $images;
    }

    private function get_brand( $product ) {
        // Try brand attribute first
        $brand = $product->get_attribute( 'brand' );
        if ( $brand ) return $brand;
        // Try brand meta
        $brand = $product->get_meta( '_brand' );
        if ( $brand ) return $brand;
        // Fallback to shop name
        return get_bloginfo( 'name' );
    }

    private function get_product_categories( $product ) {
        $terms = wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'names' ] );
        if ( is_wp_error( $terms ) || empty( $terms ) ) return '';
        return implode( ' > ', $terms );
    }

    private function get_google_category( $product ) {
        // Check for custom google_product_category meta
        $cat = $product->get_meta( '_google_product_category' );
        if ( $cat ) return $cat;
        // Check category meta
        $terms = wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'ids' ] );
        foreach ( $terms as $term_id ) {
            $cat = get_term_meta( $term_id, '_google_product_category', true );
            if ( $cat ) return $cat;
        }
        return '';
    }

    private function cdata( $str ) {
        $str = wp_strip_all_tags( strip_shortcodes( (string) $str ) );
        if ( preg_match( '/[<>&]/', $str ) ) {
            return '<![CDATA[' . str_replace( ']]>', ']]]]><![CDATA[>', $str ) . ']]>';
        }
        return $str;
    }

    /**
     * Handle on-demand feed generation from admin.
     */
    public function maybe_generate_on_demand() {
        if ( ! isset( $_GET['meshulash_generate_feed'] ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'meshulash_gen_feed' ) ) return;

        $this->generate_all_feeds();

        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-success is-dismissible"><p>Meshulash: Product feeds regenerated successfully.</p></div>';
        });
    }
}
