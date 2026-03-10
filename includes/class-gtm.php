<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Meshulash_GTM {

    public function __construct() {
        $gtm_id = Meshulash_Settings::get( 'gtm_id' );
        if ( empty( $gtm_id ) ) return;

        add_action( 'wp_head', [ $this, 'inject_head' ], 1 );
        add_action( 'wp_body_open', [ $this, 'inject_body' ], 1 );
    }

    public function inject_head() {
        $gtm_id = esc_attr( Meshulash_Settings::get( 'gtm_id' ) );
        ?>
<!-- Meshulash Marketing — GTM -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','<?php echo $gtm_id; ?>');</script>
<!-- End Meshulash Marketing — GTM -->
        <?php
    }

    public function inject_body() {
        $gtm_id = esc_attr( Meshulash_Settings::get( 'gtm_id' ) );
        ?>
<!-- Meshulash Marketing — GTM (noscript) -->
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=<?php echo $gtm_id; ?>"
height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<!-- End Meshulash Marketing — GTM (noscript) -->
        <?php
    }
}
