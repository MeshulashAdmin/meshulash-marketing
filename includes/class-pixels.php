<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Direct Pixel Injection — replaces GTM.
 *
 * Injects GA4 (gtag.js), Facebook Pixel (fbevents.js), and Google Ads (via gtag.js)
 * directly into the page. A lightweight JS dispatcher intercepts dataLayer.push()
 * and automatically fires the corresponding pixel calls.
 */
class Meshulash_Pixels {

    public function __construct() {
        // Only run in Direct Mode
        if ( Meshulash_Settings::get( 'tracking_mode' ) !== 'direct' ) return;

        // Consent Mode must be FIRST (before any gtag config)
        if ( Meshulash_Settings::get( 'consent_mode' ) ) {
            add_action( 'wp_head', [ $this, 'inject_consent_mode' ], 0 );
        }

        add_action( 'wp_head', [ $this, 'inject_pixels' ], 1 );
        add_action( 'wp_head', [ $this, 'inject_dispatcher' ], 2 );
    }

    /**
     * Inject Google Consent Mode v2 defaults.
     * Must run before any gtag/pixel initialization.
     */
    public function inject_consent_mode() {
        $analytics = Meshulash_Settings::get( 'consent_default_analytics' );
        $ads       = Meshulash_Settings::get( 'consent_default_ads' );
        ?>
<script>
window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}
gtag('consent','default',{
'analytics_storage':'<?php echo esc_js( $analytics ); ?>',
'ad_storage':'<?php echo esc_js( $ads ); ?>',
'ad_user_data':'<?php echo esc_js( $ads ); ?>',
'ad_personalization':'<?php echo esc_js( $ads ); ?>',
'functionality_storage':'granted',
'personalization_storage':'granted',
'security_storage':'granted'
});
</script>
<script>
/* Meshulash: Call this function when user grants consent */
window.meshulashGrantConsent=function(opts){
opts=opts||{};
var update={};
if(opts.analytics!==undefined)update.analytics_storage=opts.analytics?'granted':'denied';
if(opts.ads!==undefined){
update.ad_storage=opts.ads?'granted':'denied';
update.ad_user_data=opts.ads?'granted':'denied';
update.ad_personalization=opts.ads?'granted':'denied';
}
if(opts.all){
update.analytics_storage='granted';
update.ad_storage='granted';
update.ad_user_data='granted';
update.ad_personalization='granted';
}
gtag('consent','update',update);
if(typeof fbq==='function'){
if(update.ad_storage==='granted')fbq('consent','grant');
else fbq('consent','revoke');
}
if(typeof ttq!=='undefined'){
if(update.ad_storage==='granted')ttq.grantConsent();
else ttq.revokeConsent();
}
};
</script>
<?php $this->inject_consent_auto_detection(); ?>
        <?php
    }

    /**
     * Auto-detect consent plugins and bridge their decisions to meshulashGrantConsent().
     */
    private function inject_consent_auto_detection() {
        $integration = Meshulash_Settings::get( 'consent_integration', 'auto' );
        if ( $integration === 'none' ) return;
        ?>
<script>
(function(){
var grant=window.meshulashGrantConsent;
if(!grant)return;
var mode='<?php echo esc_js( $integration ); ?>';

/* ── CookieYes ────────────────────────────── */
function tryCookieYes(){
if(mode!=='auto'&&mode!=='cookieyes')return;
document.addEventListener('cookieyes_consent_update',function(e){
var d=e.detail||{};
grant({analytics:d.analytics==='yes',ads:d.advertisement==='yes'});
});
}

/* ── Complianz ────────────────────────────── */
function tryComplianz(){
if(mode!=='auto'&&mode!=='complianz')return;
document.addEventListener('cmplz_fire_categories',function(e){
var cats=window.cmplz_categories||e.detail||{};
grant({analytics:!!cats.statistics,ads:!!cats.marketing});
});
/* Complianz also fires on revoke */
document.addEventListener('cmplz_revoke',function(){
grant({analytics:false,ads:false});
});
}

/* ── CookieBot ────────────────────────────── */
function tryCookieBot(){
if(mode!=='auto'&&mode!=='cookiebot')return;
window.addEventListener('CookiebotOnAccept',function(){
var c=window.Cookiebot||{consent:{}};
grant({analytics:!!c.consent.statistics,ads:!!c.consent.marketing});
});
window.addEventListener('CookiebotOnDecline',function(){
grant({analytics:false,ads:false});
});
}

/* ── Real Cookie Banner ───────────────────── */
function tryRealCookieBanner(){
if(mode!=='auto'&&mode!=='real_cookie_banner')return;
document.addEventListener('RCB/OptIn/All',function(){grant({all:true});});
document.addEventListener('RCB/OptOut/All',function(){grant({analytics:false,ads:false});});
}

tryCookieYes();
tryComplianz();
tryCookieBot();
tryRealCookieBanner();
})();
</script>
        <?php
    }

    /**
     * Inject base pixel scripts (GA4, FB, GADS) into <head>.
     */
    public function inject_pixels() {
        $ga4_id  = Meshulash_Settings::get( 'ga4_measurement_id' );
        $fb_id   = Meshulash_Settings::get( 'fb_pixel_id' );
        $gads_id = Meshulash_Settings::get( 'gads_conversion_id' );

        echo "<!-- Meshulash Marketing — Direct Pixel Mode -->\n";

        // GA4 + Google Ads via gtag.js (single script for both)
        if ( $ga4_id || $gads_id ) {
            $primary_id = $ga4_id ?: ( 'AW-' . $gads_id );
            ?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr( $primary_id ); ?>"></script>
<script>
window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}
gtag('js',new Date());
<?php if ( $ga4_id ) : ?>
gtag('config','<?php echo esc_js( $ga4_id ); ?>',{send_page_view:true});
<?php endif; ?>
<?php if ( $gads_id ) : ?>
gtag('config','AW-<?php echo esc_js( $gads_id ); ?>');
<?php endif; ?>
</script>
            <?php

            // Google Ads Enhanced Conversions — send hashed user data
            if ( Meshulash_Settings::get( 'enhanced_conversions' ) && $gads_id ) {
                $ec_data = Meshulash_DataLayer::get_enhanced_conversions_data();
                if ( ! empty( $ec_data ) ) {
                    echo '<script>gtag("set","user_data",' . wp_json_encode( $ec_data, JSON_UNESCAPED_UNICODE ) . ');</script>' . "\n";
                }
            }
        }

        // Facebook Pixel
        if ( $fb_id ) {
            ?>
<script>
!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
document,'script','https://connect.facebook.net/en_US/fbevents.js');
<?php
                    if ( Meshulash_Settings::get( 'fb_advanced_matching' ) ) {
                        $am_data = Meshulash_DataLayer::get_fb_advanced_matching_data();
                        if ( ! empty( $am_data ) ) {
                            echo "fbq('init','" . esc_js( $fb_id ) . "'," . wp_json_encode( $am_data ) . ");\n";
                        } else {
                            echo "fbq('init','" . esc_js( $fb_id ) . "');\n";
                        }
                    } else {
                        echo "fbq('init','" . esc_js( $fb_id ) . "');\n";
                    }
?>
fbq('track','PageView');
</script>
<noscript><img height="1" width="1" style="display:none"
src="https://www.facebook.com/tr?id=<?php echo esc_attr( $fb_id ); ?>&ev=PageView&noscript=1"/></noscript>
            <?php
        }

        // TikTok Pixel
        $tt_id = Meshulash_Settings::get( 'tt_pixel_id' );
        if ( $tt_id ) {
            ?>
<script>
!function(w,d,t){w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=
["page","track","identify","instances","debug","on","off","once","ready","alias",
"group","enableCookie","disableCookie","holdConsent","revokeConsent","grantConsent"],
ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};
for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);
ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e};
ttq.load=function(e,n){var r="https://analytics.tiktok.com/i18n/pixel/events.js",o=n&&n.partner;
ttq._i=ttq._i||{};ttq._i[e]=[];ttq._i[e]._u=r;ttq._t=ttq._t||{};ttq._t[e]=+new Date;
ttq._o=ttq._o||{};ttq._o[e]=n||{};var a=document.createElement("script");a.type="text/javascript";
a.async=!0;a.src=r+"?sdkid="+e+"&lib="+t;var s=document.getElementsByTagName("script")[0];
s.parentNode.insertBefore(a,s)};
ttq.load('<?php echo esc_js( $tt_id ); ?>');
ttq.page();
}(window,document,'ttq');
</script>
            <?php
        }

        // Bing / Microsoft UET
        $bing_id = Meshulash_Settings::get( 'bing_uet_id' );
        if ( $bing_id ) {
            ?>
<script>
(function(w,d,t,r,u){var f,n,i;w[u]=w[u]||[];f=function(){var o={ti:"<?php echo esc_js( $bing_id ); ?>",enableAutoSpaTracking:true};o.q=w[u];w[u]=new UET(o);w[u].push("pageLoad")};n=d.createElement(t);n.src=r;n.async=1;n.onload=n.onreadystatechange=function(){var s=this.readyState;if(s&&s!=="loaded"&&s!=="complete")return;f();n.onload=n.onreadystatechange=null};i=d.getElementsByTagName(t)[0];i.parentNode.insertBefore(n,i)})(window,document,"script","//bat.bing.com/bat.js","uetq");
</script>
<noscript><img src="//bat.bing.com/action/0?ti=<?php echo esc_attr( $bing_id ); ?>&Ver=2" height="0" width="0" style="display:none;visibility:hidden"/></noscript>
            <?php
        }

        // Pinterest Tag
        $pin_id = Meshulash_Settings::get( 'pinterest_tag_id' );
        if ( $pin_id ) {
            ?>
<script>
!function(e){if(!window.pintrk){window.pintrk=function(){window.pintrk.queue.push(Array.prototype.slice.call(arguments))};var n=window.pintrk;n.queue=[];n.version="3.0";var t=document.createElement("script");t.async=!0;t.src=e;var r=document.getElementsByTagName("script")[0];r.parentNode.insertBefore(t,r)}}("https://s.pinimg.com/ct/core.js");
pintrk('load','<?php echo esc_js( $pin_id ); ?>');
pintrk('page');
</script>
<noscript><img height="1" width="1" style="display:none" alt="" src="https://ct.pinterest.com/v3/?event=init&tid=<?php echo esc_attr( $pin_id ); ?>&noscript=1"/></noscript>
            <?php
        }

        // Reddit Pixel
        $reddit_id = Meshulash_Settings::get( 'reddit_pixel_id' );
        if ( $reddit_id ) {
            ?>
<script>
!function(w,d){if(!w.rdt){var p=w.rdt=function(){p.sendEvent?p.sendEvent.apply(p,arguments):p.callQueue.push(arguments)};p.callQueue=[];var t=d.createElement("script");t.src="https://www.redditstatic.com/ads/pixel.js";t.async=!0;var s=d.getElementsByTagName("script")[0];s.parentNode.insertBefore(t,s)}}(window,document);
rdt('init','<?php echo esc_js( $reddit_id ); ?>');
rdt('track','PageVisit');
</script>
            <?php
        }

        // Yahoo Dot Tag
        $yahoo_pid = Meshulash_Settings::get( 'yahoo_pixel_id' );
        $yahoo_did = Meshulash_Settings::get( 'yahoo_dot_id' );
        if ( $yahoo_pid && $yahoo_did ) {
            ?>
<script>
(function(w,d,t,r,u){w[u]=w[u]||[];w[u].push({'projectId':'<?php echo esc_js( $yahoo_did ); ?>','properties':{'pixelId':'<?php echo esc_js( $yahoo_pid ); ?>'}});var s=d.createElement(t);s.src=r;s.async=true;s.onload=s.onreadystatechange=function(){var y,rs=this.readyState;if(rs&&rs!="complete"&&rs!="loaded")return;try{y=YAHOO.ywa.I13N.fire498();if(y){y.tracking(w[u])}}catch(e){}};var scr=d.getElementsByTagName(t)[0];scr.parentNode.insertBefore(s,scr)})(window,document,"script","https://s.yimg.com/wi/ytc.js","dotq");
</script>
            <?php
        }

        // LinkedIn Insight Tag
        $li_id = Meshulash_Settings::get( 'linkedin_partner_id' );
        if ( $li_id ) {
            ?>
<script>
_linkedin_partner_id="<?php echo esc_js( $li_id ); ?>";window._linkedin_data_partner_ids=window._linkedin_data_partner_ids||[];window._linkedin_data_partner_ids.push(_linkedin_partner_id);
(function(l){if(!l){var s=document.getElementsByTagName("script")[0];var b=document.createElement("script");b.type="text/javascript";b.async=true;b.src="https://snap.licdn.com/li.lms-analytics/insight.min.js";s.parentNode.insertBefore(b,s)}})(window.lintrk);
</script>
<noscript><img height="1" width="1" style="display:none" alt="" src="https://px.ads.linkedin.com/collect/?pid=<?php echo esc_attr( $li_id ); ?>&fmt=gif"/></noscript>
            <?php
        }

        // Snapchat Pixel
        $snap_id = Meshulash_Settings::get( 'snapchat_pixel_id' );
        if ( $snap_id ) {
            ?>
<script>
(function(e,t,n){if(e.snaptr)return;var a=e.snaptr=function(){a.handleRequest?a.handleRequest.apply(a,arguments):a.queue.push(arguments)};a.queue=[];var s='script';var r=t.createElement(s);r.async=!0;r.src=n;var u=t.getElementsByTagName(s)[0];u.parentNode.insertBefore(r,u)})(window,document,'https://sc-static.net/scevent.min.js');
snaptr('init','<?php echo esc_js( $snap_id ); ?>',{});
snaptr('track','PAGE_VIEW');
</script>
            <?php
        }

        // Twitter / X Pixel
        $twtr_id = Meshulash_Settings::get( 'twitter_pixel_id' );
        if ( $twtr_id ) {
            ?>
<script>
!function(e,t,n,s,u,a){e.twq||(s=e.twq=function(){s.exe?s.exe.apply(s,arguments):s.queue.push(arguments)},s.version='1.1',s.queue=[],u=t.createElement(n),u.async=!0,u.src='https://static.ads-twitter.com/uwt.js',a=t.getElementsByTagName(n)[0],a.parentNode.insertBefore(u,a))}(window,document,'script');
twq('config','<?php echo esc_js( $twtr_id ); ?>');
</script>
            <?php
        }

        // Taboola Pixel
        $tbl_id = Meshulash_Settings::get( 'taboola_pixel_id' );
        if ( $tbl_id ) {
            ?>
<script>
window._tfa=window._tfa||[];window._tfa.push({notify:'event',name:'page_view',id:<?php echo intval( $tbl_id ); ?>});
!function(t,f,a,x){if(!document.getElementById(x)){t.async=1;t.src=a;t.id=x;f.parentNode.insertBefore(t,f)}}(document.createElement('script'),document.getElementsByTagName('script')[0],'//cdn.taboola.com/libtrc/unip/<?php echo intval( $tbl_id ); ?>/tfa.js','tb_tfa_script');
</script>
            <?php
        }

        // Outbrain Pixel
        $ob_id = Meshulash_Settings::get( 'outbrain_pixel_id' );
        if ( $ob_id ) {
            ?>
<script>
!function(_window,_document){var OB_ADV_ID='<?php echo esc_js( $ob_id ); ?>';if(_window.obApi){var toArray=function(o){return Object.prototype.toString.call(o)==='[object Array]'?o:[o]};_window.obApi.marketerId=toArray(_window.obApi.marketerId).concat(toArray(OB_ADV_ID));return}var api=_window.obApi=function(){api.dispatch?api.dispatch.apply(api,arguments):api.queue.push(arguments)};api.version='1.1';api.loaded=!0;api.marketerId=OB_ADV_ID;api.queue=[];var tag=_document.createElement('script');tag.async=!0;tag.src='//amplify.outbrain.com/cp/obtp.js';tag.type='text/javascript';var script=_document.getElementsByTagName('script')[0];script.parentNode.insertBefore(tag,script)}(window,document);
obApi('track','PAGE_VIEW');
</script>
            <?php
        }

        // Additional FB Pixel IDs
        $fb_extra = Meshulash_Settings::get( 'fb_pixel_ids' );
        if ( $fb_id && $fb_extra ) {
            $extra_ids = array_map( 'trim', explode( ',', $fb_extra ) );
            foreach ( $extra_ids as $extra_id ) {
                if ( $extra_id ) {
                    echo "<script>fbq('init','" . esc_js( $extra_id ) . "');</script>\n";
                }
            }
        }

        // Additional GA4 Measurement IDs
        $ga4_extra = Meshulash_Settings::get( 'ga4_measurement_ids' );
        if ( $ga4_extra ) {
            $extra_ids = array_map( 'trim', explode( ',', $ga4_extra ) );
            foreach ( $extra_ids as $extra_id ) {
                if ( $extra_id ) {
                    echo "<script>gtag('config','" . esc_js( $extra_id ) . "',{send_page_view:true});</script>\n";
                }
            }
        }

        echo "<!-- End Meshulash Marketing — Direct Pixel Mode -->\n";
    }

    /**
     * Inject the JS dispatcher that intercepts dataLayer.push()
     * and automatically fires GA4 gtag() / FB fbq() / GADS conversion calls.
     */
    public function inject_dispatcher() {
        $ga4_id    = Meshulash_Settings::get( 'ga4_measurement_id' );
        $fb_id     = Meshulash_Settings::get( 'fb_pixel_id' );
        $gads_id   = Meshulash_Settings::get( 'gads_conversion_id' );
        $tt_id     = Meshulash_Settings::get( 'tt_pixel_id' );
        $bing_id   = Meshulash_Settings::get( 'bing_uet_id' );
        $pin_id    = Meshulash_Settings::get( 'pinterest_tag_id' );
        $reddit_id = Meshulash_Settings::get( 'reddit_pixel_id' );
        $yahoo_pid = Meshulash_Settings::get( 'yahoo_pixel_id' );
        $li_id     = Meshulash_Settings::get( 'linkedin_partner_id' );
        $snap_id   = Meshulash_Settings::get( 'snapchat_pixel_id' );
        $twtr_id   = Meshulash_Settings::get( 'twitter_pixel_id' );
        $tbl_id    = Meshulash_Settings::get( 'taboola_pixel_id' );
        $ob_id     = Meshulash_Settings::get( 'outbrain_pixel_id' );
        $debug     = Meshulash_Settings::is_debug();

        // Build GADS labels map for JS
        $gads_labels = [];
        $label_keys = [
            'purchase'         => 'gads_label_purchase',
            'begin_checkout'   => 'gads_label_begin_checkout',
            'add_to_cart'      => 'gads_label_add_to_cart',
            'add_payment_info' => 'gads_label_add_payment',
            'sign_up'          => 'gads_label_sign_up',
            'recurringCustomer'=> 'gads_label_recurring',
            'midTierPurchase'  => 'gads_label_mid_tier',
            'premiumPurchase'  => 'gads_label_premium',
            'luxuryPurchase'   => 'gads_label_luxury',
            'vipCustomer'      => 'gads_label_vip',
            'purchase10Plus'   => 'gads_label_purchase_10plus',
            // Engagement events
            'outbound_click'   => 'gads_label_outbound_click',
            'form_start'       => 'gads_label_form_start',
            'form_abandon'     => 'gads_label_form_abandon',
            'video_play'       => 'gads_label_video_play',
            'video_progress'   => 'gads_label_video_progress',
            'video_complete'   => 'gads_label_video_complete',
            'share'            => 'gads_label_share',
            'print_page'       => 'gads_label_print_page',
            'copy_text'        => 'gads_label_copy_text',
            'file_download'    => 'gads_label_file_download',
            // Ecommerce events
            'view_item'        => 'gads_label_view_item',
            'view_item_list'   => 'gads_label_view_item_list',
            'remove_from_cart' => 'gads_label_remove_from_cart',
            'view_cart'        => 'gads_label_view_cart',
            'add_shipping_info'=> 'gads_label_add_shipping',
            'search'           => 'gads_label_search',
            'login'            => 'gads_label_login',
            'generate_lead'    => 'gads_label_generate_lead',
            'form_submit'      => 'gads_label_form_submit',
            'add_to_wishlist'  => 'gads_label_add_to_wishlist',
            'coupon_applied'   => 'gads_label_coupon_applied',
            'refund'           => 'gads_label_refund',
            // Enrichment events
            'variation_select'      => 'gads_label_variation_select',
            'gallery_click'         => 'gads_label_gallery_click',
            'checkout_field_focus'  => 'gads_label_checkout_field',
            'cart_abandonment'      => 'gads_label_cart_abandonment',
            'quick_view'            => 'gads_label_quick_view',
            'mini_cart_open'        => 'gads_label_mini_cart',
            // Subscription events
            'subscription_renewal'     => 'gads_label_sub_renewal',
            'subscription_cancelled'   => 'gads_label_sub_cancelled',
            'subscription_reactivated' => 'gads_label_sub_reactivated',
            'subscription_expired'     => 'gads_label_sub_expired',
            'subscription_paused'      => 'gads_label_sub_paused',
            // Link click events
            'phone_link_click'   => 'gads_label_phone_click',
            'email_link_click'   => 'gads_label_email_click',
            'whatsapp_click'     => 'gads_label_whatsapp_click',
            'social_link_click'  => 'gads_label_social_click',
            'maps_click'         => 'gads_label_maps_click',
            'cta_link_click'     => 'gads_label_cta_click',
            // Interaction events
            'scroll_depth'       => 'gads_label_scroll_depth',
            'page_timer'         => 'gads_label_page_timer',
        ];

        foreach ( $label_keys as $event => $setting_key ) {
            $label = Meshulash_Settings::get( $setting_key );
            if ( $label ) {
                $gads_labels[ $event ] = $label;
            }
        }

        ?>
<script>
(function(){
var _debug=<?php echo $debug ? 'true' : 'false'; ?>;
var _ga4=<?php echo $ga4_id ? "'" . esc_js( $ga4_id ) . "'" : 'null'; ?>;
var _fb=<?php echo $fb_id ? 'true' : 'false'; ?>;
var _gadsId=<?php echo $gads_id ? "'" . esc_js( $gads_id ) . "'" : 'null'; ?>;
var _tt=<?php echo $tt_id ? 'true' : 'false'; ?>;
var _bing=<?php echo $bing_id ? 'true' : 'false'; ?>;
var _pin=<?php echo $pin_id ? 'true' : 'false'; ?>;
var _rdt=<?php echo $reddit_id ? 'true' : 'false'; ?>;
var _yahoo=<?php echo ( $yahoo_pid ? 'true' : 'false' ); ?>;
var _li=<?php echo $li_id ? 'true' : 'false'; ?>;
var _snap=<?php echo $snap_id ? 'true' : 'false'; ?>;
var _twtr=<?php echo $twtr_id ? "'" . esc_js( $twtr_id ) . "'" : 'null'; ?>;
var _tbl=<?php echo $tbl_id ? intval( $tbl_id ) : 'null'; ?>;
var _ob=<?php echo $ob_id ? 'true' : 'false'; ?>;
var _gadsLabels=<?php echo wp_json_encode( $gads_labels ); ?>;
var _fired={};

function log(){if(_debug){var a=['%cMeshulash','background:#6C2BD9;color:#fff;padding:2px 6px;border-radius:3px;font-weight:bold'];for(var i=0;i<arguments.length;i++)a.push(arguments[i]);console.log.apply(console,a);}}
function logPixel(name,color,evt,data){if(_debug){console.log('%c'+name+'%c '+evt,'background:'+color+';color:#fff;padding:1px 5px;border-radius:2px;font-weight:bold','color:'+color+';font-weight:bold',data||'');}}

// ── Event-to-Pixel mapping ──
var fbMap={
    'view_item':'ViewContent','view_item_list':null,'select_item':null,
    'add_to_cart':'AddToCart','remove_from_cart':null,'view_cart':null,
    'begin_checkout':'InitiateCheckout','add_shipping_info':null,
    'add_payment_info':'AddPaymentInfo','purchase':'Purchase',
    'search':'Search','sign_up':'CompleteRegistration','login':null,
    'add_to_wishlist':'AddToWishlist','generate_lead':'Lead',
    'coupon_applied':null,'form_submit':'Lead',
    // Custom events
    'recurringCustomer':'recurringCustomer','midTierPurchase':'midTierPurchase',
    'premiumPurchase':'premiumPurchase','luxuryPurchase':'luxuryPurchase',
    'vipCustomer':'vipCustomer','purchase10Plus':'purchase10Plus',
    // Enrichment events
    'subscription_renewal':'Subscribe','subscription_cancelled':null,
    'subscription_reactivated':null,'subscription_expired':null,'subscription_paused':null,
    'variation_select':null,'gallery_click':null,'checkout_field_focus':null,
    'cart_abandonment':null,'quick_view':null,'mini_cart_open':null,
    'file_download':null,
    // Engagement events
    'outbound_click':'OutboundClick','form_start':'FormStart','form_abandon':'FormAbandon',
    'video_play':'VideoPlay','video_progress':null,'video_complete':'VideoComplete',
    'print_page':'PrintPage','copy_text':'CopyText','share':'Share'
};

var fbStandard=['ViewContent','AddToCart','InitiateCheckout','AddPaymentInfo','Purchase','Search','CompleteRegistration','Lead','AddToWishlist','Subscribe'];

// Pinterest event mapping
var pinMap={
    'view_item':'pagevisit','view_item_list':'viewcategory',
    'add_to_cart':'addtocart','begin_checkout':'checkout',
    'purchase':'checkout','search':'search',
    'sign_up':'signup','generate_lead':'lead','form_submit':'lead',
    'video_play':'watchvideo','video_complete':'watchvideo',
    'form_start':'lead','share':'custom','file_download':'custom',
    'outbound_click':'custom','form_abandon':'custom',
    'video_progress':'custom','print_page':'custom','copy_text':'custom'
};

// Reddit event mapping
var rdtMap={
    'view_item':'ViewContent','add_to_cart':'AddToCart',
    'begin_checkout':'AddToCart','purchase':'Purchase',
    'search':'Search','sign_up':'SignUp',
    'generate_lead':'Lead','form_submit':'Lead',
    'add_to_wishlist':'AddToWishlist',
    'video_play':'ViewContent','video_complete':'ViewContent',
    'form_start':'Lead','share':'Custom','file_download':'Custom',
    'outbound_click':'Custom','form_abandon':'Custom',
    'video_progress':'Custom','print_page':'Custom','copy_text':'Custom'
};

// Bing UET event mapping
var bingMap={
    'purchase':'purchase','add_to_cart':'add_to_cart',
    'begin_checkout':'begin_checkout','search':'search',
    'sign_up':'sign_up','generate_lead':'submit_lead_form',
    'form_submit':'submit_lead_form','view_item':'page_view',
    'file_download':'file_download','outbound_click':'outbound_click',
    'form_start':'form_start','video_play':'video_play','share':'share',
    'form_abandon':'form_abandon','video_progress':'video_progress',
    'video_complete':'video_complete','print_page':'print_page','copy_text':'copy_text'
};

function dispatch(obj){
    if(!obj||!obj.event)return;
    var evt=obj.event;
    var eid=obj.event_id||'';
    var ecom=obj.ecommerce||{};

    // Dedup
    if(eid&&_fired[eid])return;
    if(eid)_fired[eid]=1;

    if(_debug){console.groupCollapsed('%cMeshulash%c Event: '+evt+(eid?' ['+eid.substr(0,12)+'...]':''),'background:#6C2BD9;color:#fff;padding:2px 6px;border-radius:3px;font-weight:bold','color:#6C2BD9;font-weight:bold');console.log('Full payload:',obj);}

    // ── Build common params ──
    var val=typeof ecom.value!=='undefined'?ecom.value:undefined;
    var cur=ecom.currency||'';

    // ── GA4 via gtag() ──
    if(_ga4&&typeof gtag==='function'){
        var ga4Params={};
        if(cur)ga4Params.currency=cur;
        if(typeof val!=='undefined')ga4Params.value=val;
        if(ecom.transaction_id)ga4Params.transaction_id=ecom.transaction_id;
        if(ecom.items)ga4Params.items=ecom.items;
        if(ecom.item_list_name)ga4Params.item_list_name=ecom.item_list_name;
        if(ecom.shipping_tier)ga4Params.shipping_tier=ecom.shipping_tier;
        if(ecom.payment_type)ga4Params.payment_type=ecom.payment_type;
        if(ecom.tax)ga4Params.tax=ecom.tax;
        if(ecom.shipping)ga4Params.shipping=ecom.shipping;
        if(obj.search_term)ga4Params.search_term=obj.search_term;
        if(obj.method)ga4Params.method=obj.method;
        if(obj.purchase_number)ga4Params.purchase_number=obj.purchase_number;
        if(obj.total_purchases)ga4Params.total_purchases=obj.total_purchases;
        if(obj.total_spent)ga4Params.total_spent=obj.total_spent;
        if(obj.customer_id)ga4Params.customer_id=obj.customer_id;
        if(obj.target_phone)ga4Params.target_phone=obj.target_phone;
        if(obj.target_email)ga4Params.target_email=obj.target_email;
        if(obj.target_social)ga4Params.target_social=obj.target_social;
        if(obj.scroll_threshold)ga4Params.scroll_threshold=obj.scroll_threshold;
        if(obj.timer_seconds)ga4Params.timer_seconds=obj.timer_seconds;
        if(obj.link_url)ga4Params.link_url=obj.link_url;
        if(obj.visitor_type)ga4Params.visitor_type=obj.visitor_type;
        if(obj.session_count)ga4Params.session_count=obj.session_count;
        if(obj.days_since_first)ga4Params.days_since_first=obj.days_since_first;
        if(obj.device_type)ga4Params.device_type=obj.device_type;
        if(typeof obj.profit!=='undefined')ga4Params.profit=obj.profit;
        if(typeof obj.margin_pct!=='undefined')ga4Params.margin_pct=obj.margin_pct;
        if(obj.rfm_score)ga4Params.rfm_score=obj.rfm_score;
        if(obj.variation_attribute)ga4Params.variation_attribute=obj.variation_attribute;
        if(obj.variation_value)ga4Params.variation_value=obj.variation_value;
        if(obj.gallery_action)ga4Params.gallery_action=obj.gallery_action;
        if(obj.field_name)ga4Params.field_name=obj.field_name;
        // Form & download params
        if(obj.form_id)ga4Params.form_id=obj.form_id;
        if(obj.form_name)ga4Params.form_name=obj.form_name;
        if(obj.form_plugin)ga4Params.form_plugin=obj.form_plugin;
        if(obj.file_url)ga4Params.file_url=obj.file_url;
        if(obj.file_name)ga4Params.file_name=obj.file_name;
        if(obj.file_extension)ga4Params.file_extension=obj.file_extension;
        // Engagement params
        if(obj.outbound_domain)ga4Params.outbound_domain=obj.outbound_domain;
        if(obj.video_provider)ga4Params.video_provider=obj.video_provider;
        if(obj.video_title)ga4Params.video_title=obj.video_title;
        if(obj.video_url)ga4Params.video_url=obj.video_url;
        if(typeof obj.video_percent!=='undefined')ga4Params.video_percent=obj.video_percent;
        if(obj.share_method)ga4Params.share_method=obj.share_method;
        if(obj.share_url)ga4Params.share_url=obj.share_url;
        if(obj.copied_text)ga4Params.copied_text=obj.copied_text;
        if(obj.page_title)ga4Params.page_title=obj.page_title;

        gtag('event',evt,ga4Params);
        logPixel('GA4','#F29900',evt,ga4Params);
    }

    // ── Facebook Pixel ──
    if(_fb&&typeof fbq==='function'){
        var fbEvt=fbMap[evt];
        if(!fbEvt&&evt.match(/^purchaseNumber\d+$/))fbEvt='Purchase_'+evt.replace('purchaseNumber','');
        if(!fbEvt&&evt.match(/^purchase_\d+_products$/))fbEvt=evt;
        if(!fbEvt&&evt==='phone_link_click')fbEvt='Phone Link Click';
        if(!fbEvt&&evt==='email_link_click')fbEvt='Email Link Click';
        if(!fbEvt&&evt==='whatsapp_click')fbEvt='WhatsApp Click';
        if(!fbEvt&&evt==='social_link_click')fbEvt='Social Link Click';
        if(!fbEvt&&evt==='maps_click')fbEvt='Maps Click';
        if(!fbEvt&&evt==='cta_link_click')fbEvt='CTA Link Click';
        if(!fbEvt&&evt==='scroll_depth')fbEvt='Scroll_'+(obj.scroll_threshold||0);
        if(!fbEvt&&evt.match(/^page_timer_/))fbEvt='PageTimer_'+(obj.timer_seconds||0);
        if(!fbEvt&&evt==='outbound_click')fbEvt='OutboundClick';
        if(!fbEvt&&evt==='form_start')fbEvt='FormStart';
        if(!fbEvt&&evt==='form_abandon')fbEvt='FormAbandon';
        if(!fbEvt&&evt==='video_play')fbEvt='VideoPlay';
        if(!fbEvt&&evt==='video_complete')fbEvt='VideoComplete';
        if(!fbEvt&&evt==='video_progress')fbEvt='VideoProgress_'+(obj.video_percent||0);
        if(!fbEvt&&evt==='print_page')fbEvt='PrintPage';
        if(!fbEvt&&evt==='copy_text')fbEvt='CopyText';
        if(!fbEvt&&evt==='share')fbEvt='Share';

        if(fbEvt){
            var fbData={};
            if(cur)fbData.currency=cur;
            if(typeof val!=='undefined')fbData.value=val;
            if(ecom.items){
                fbData.content_ids=ecom.items.map(function(i){return i.item_id;});
                fbData.contents=ecom.items.map(function(i){return{id:i.item_id,quantity:i.quantity||1};});
                fbData.num_items=ecom.items.length;
            }
            fbData.content_type='product';
            if(ecom.items&&ecom.items.length){
                fbData.content_name=ecom.items[0].item_name||'';
                if(!fbData.content_name&&ecom.items[0].name)fbData.content_name=ecom.items[0].name;
                if(ecom.items[0].item_category)fbData.content_category=ecom.items[0].item_category;
            }
            if(obj.search_term)fbData.search_string=obj.search_term;

            var isStandard=fbStandard.indexOf(fbEvt)!==-1;
            var eventOpts=eid?{eventID:eid}:{};

            if(isStandard){
                fbq('track',fbEvt,fbData,eventOpts);
            }else{
                fbq('trackCustom',fbEvt,fbData,eventOpts);
            }
            logPixel('Facebook','#1877F2',isStandard?'track: '+fbEvt:'trackCustom: '+fbEvt,fbData);
        }
    }

    // ── Google Ads Conversions ──
    if(_gadsId&&typeof gtag==='function'){
        var gadsLabel=_gadsLabels[evt];
        if(!gadsLabel&&evt.match(/^page_timer_/))gadsLabel=_gadsLabels['page_timer'];
        if(gadsLabel){
            var convData={
                send_to:'AW-'+_gadsId+'/'+gadsLabel,
                transaction_id:ecom.transaction_id||eid
            };
            if(typeof val!=='undefined')convData.value=val;
            if(cur)convData.currency=cur;
            gtag('event','conversion',convData);
            logPixel('Google Ads','#34A853','conversion: '+evt,convData);
        }
    }

    // ── TikTok Pixel ──
    if(_tt&&typeof ttq!=='undefined'){
        var ttMap={
            'view_item':'ViewContent','add_to_cart':'AddToCart',
            'begin_checkout':'InitiateCheckout','add_payment_info':'AddPaymentInfo',
            'purchase':'CompletePayment','sign_up':'CompleteRegistration',
            'search':'Search','add_to_wishlist':'AddToWishlist',
            'generate_lead':'SubmitForm','form_submit':'SubmitForm','coupon_applied':null
        };
        var ttEvt=ttMap[evt];
        if(ttEvt){
            var ttData={};
            if(ecom.items&&ecom.items.length){
                ttData.contents=ecom.items.map(function(i){var c={content_id:i.item_id,content_type:'product',quantity:i.quantity||1,price:i.price};if(i.item_name)c.content_name=i.item_name;return c;});
                ttData.content_type='product';
            }
            if(typeof val!=='undefined')ttData.value=val;
            else if(ttEvt==='ViewContent')ttData.value=0;
            if(cur)ttData.currency=cur;
            else if(ttEvt==='ViewContent')ttData.currency='ILS';
            if(ttEvt==='ViewContent'&&ecom.items&&ecom.items.length){
                ttData.description=ecom.items[0].item_name||'';
                if(!ttData.value&&ecom.items[0].price)ttData.value=ecom.items[0].price;
            }
            if(obj.search_term)ttData.query=obj.search_term;
            ttq.track(ttEvt,ttData);
            logPixel('TikTok','#000000',ttEvt,ttData);
        }else if(evt!=='view_item_list'&&evt!=='view_cart'&&evt!=='select_item'&&evt!=='remove_from_cart'&&evt!=='login'){
            var ttCustom={};
            if(typeof val!=='undefined'&&val>0){ttCustom.value=val;if(cur)ttCustom.currency=cur;}
            ttq.track(evt,ttCustom);
            logPixel('TikTok','#000000','custom: '+evt,ttCustom);
        }
    }

    // ── Bing / Microsoft UET ──
    if(_bing&&typeof uetq!=='undefined'){
        var bingEvt=bingMap[evt];
        if(bingEvt){
            var bingData={event_category:'ecommerce',event_label:evt};
            if(typeof val!=='undefined')bingData.revenue_value=val;
            if(cur)bingData.currency=cur;
            if(ecom.transaction_id)bingData.transaction_id=ecom.transaction_id;
            window.uetq.push('event',bingEvt,bingData);
            logPixel('Bing','#008373',bingEvt,bingData);
        }
    }

    // ── Pinterest ──
    if(_pin&&typeof pintrk==='function'){
        var pinEvt=pinMap[evt];
        if(pinEvt){
            var pinData={};
            if(typeof val!=='undefined')pinData.value=val;
            if(cur)pinData.currency=cur;
            if(ecom.transaction_id)pinData.order_id=ecom.transaction_id;
            if(ecom.items&&ecom.items.length){
                pinData.line_items=ecom.items.map(function(i){return{product_id:i.item_id,product_name:i.item_name,product_price:i.price,product_quantity:i.quantity||1};});
            }
            if(obj.search_term)pinData.search_query=obj.search_term;
            if(pinEvt==='custom')pinData.event_name=evt;
            pintrk('track',pinEvt,pinData);
            logPixel('Pinterest','#E60023',pinEvt+(pinEvt==='custom'?' ('+evt+')':''),pinData);
        }
    }

    // ── Reddit ──
    if(_rdt&&typeof rdt==='function'){
        var rdtEvt=rdtMap[evt];
        if(rdtEvt){
            var rdtData={};
            if(typeof val!=='undefined')rdtData.value=val;
            if(cur)rdtData.currency=cur;
            if(ecom.transaction_id)rdtData.transactionId=ecom.transaction_id;
            if(ecom.items&&ecom.items.length){
                rdtData.products=ecom.items.map(function(i){return{id:i.item_id,name:i.item_name,price:i.price};});
                rdtData.itemCount=ecom.items.length;
            }
            if(rdtEvt==='Custom')rdtData.customEventName=evt;
            rdt('track',rdtEvt,rdtData);
            logPixel('Reddit','#FF4500',rdtEvt+(rdtEvt==='Custom'?' ('+evt+')':''),rdtData);
        }
    }

    // ── Yahoo Dot Tag ──
    if(_yahoo&&typeof dotq!=='undefined'){
        var yahooAction=evt;
        if(evt==='purchase')yahooAction='conversion';
        else if(evt==='add_to_cart')yahooAction='addtocart';
        else if(evt==='begin_checkout')yahooAction='checkout';
        else if(evt==='form_submit'||evt==='generate_lead')yahooAction='lead';
        window.dotq=window.dotq||[];
        var yahooData={et:'custom',ea:yahooAction};
        if(typeof val!=='undefined')yahooData.gv=val;
        window.dotq.push({projectId:'',properties:{pixelId:'',qstrings:yahooData}});
        logPixel('Yahoo','#720E9E',yahooAction,yahooData);
    }

    // ── LinkedIn Insight Tag ──
    if(_li&&typeof window.lintrk==='function'){
        var liMap={'purchase':'conversion','sign_up':'conversion','generate_lead':'conversion','form_submit':'conversion','begin_checkout':'conversion','form_start':'conversion','video_play':'conversion','video_complete':'conversion','share':'conversion','outbound_click':'conversion','form_abandon':'conversion','file_download':'conversion','print_page':'conversion','copy_text':'conversion','video_progress':'conversion'};
        var liEvt=liMap[evt];
        if(liEvt){
            window.lintrk('track',{conversion_id:evt});
            logPixel('LinkedIn','#0A66C2','conversion: '+evt);
        }
    }

    // ── Snapchat Pixel ──
    if(_snap&&typeof snaptr==='function'){
        var snapMap={
            'view_item':'VIEW_CONTENT','add_to_cart':'ADD_CART',
            'begin_checkout':'START_CHECKOUT','purchase':'PURCHASE',
            'search':'SEARCH','sign_up':'SIGN_UP','add_to_wishlist':'ADD_TO_WISHLIST',
            'generate_lead':'SIGN_UP','form_submit':'SIGN_UP',
            'view_item_list':'LIST_VIEW','add_payment_info':'ADD_BILLING',
            'share':'SHARE','video_play':'VIEW_CONTENT','video_complete':'VIEW_CONTENT',
            'video_progress':'VIEW_CONTENT','form_start':'CUSTOM_EVENT_1',
            'form_abandon':'CUSTOM_EVENT_2','outbound_click':'CUSTOM_EVENT_3',
            'file_download':'CUSTOM_EVENT_4','print_page':'CUSTOM_EVENT_5','copy_text':'CUSTOM_EVENT_5'
        };
        var snapEvt=snapMap[evt];
        if(snapEvt){
            var snapData={};
            if(typeof val!=='undefined')snapData.price=val;
            if(cur)snapData.currency=cur;
            if(ecom.transaction_id)snapData.transaction_id=ecom.transaction_id;
            if(ecom.items&&ecom.items.length){
                snapData.item_ids=ecom.items.map(function(i){return i.item_id;});
                snapData.number_items=ecom.items.length;
            }
            if(obj.search_term)snapData.search_string=obj.search_term;
            snaptr('track',snapEvt,snapData);
            logPixel('Snapchat','#FFFC00',snapEvt,snapData);
        }
    }

    // ── Twitter / X Pixel ──
    if(_twtr&&typeof twq==='function'){
        var twMap={
            'view_item':'tw-'+_twtr+'-view_content','add_to_cart':'tw-'+_twtr+'-add_to_cart',
            'begin_checkout':'tw-'+_twtr+'-checkout_initiated','purchase':'tw-'+_twtr+'-purchase',
            'search':'tw-'+_twtr+'-search','sign_up':'tw-'+_twtr+'-sign_up',
            'generate_lead':'tw-'+_twtr+'-lead','form_submit':'tw-'+_twtr+'-lead',
            'add_to_wishlist':'tw-'+_twtr+'-add_to_wishlist',
            'video_play':'tw-'+_twtr+'-video_play','video_complete':'tw-'+_twtr+'-video_complete',
            'video_progress':'tw-'+_twtr+'-video_progress',
            'share':'tw-'+_twtr+'-share','outbound_click':'tw-'+_twtr+'-outbound_click',
            'form_start':'tw-'+_twtr+'-form_start','form_abandon':'tw-'+_twtr+'-form_abandon',
            'file_download':'tw-'+_twtr+'-file_download',
            'print_page':'tw-'+_twtr+'-print_page','copy_text':'tw-'+_twtr+'-copy_text'
        };
        var twEvt=twMap[evt];
        if(twEvt){
            var twData={};
            if(typeof val!=='undefined')twData.value=val;
            if(cur)twData.currency=cur;
            if(ecom.transaction_id)twData.order_id=ecom.transaction_id;
            if(ecom.items&&ecom.items.length){
                twData.num_items=ecom.items.length;
                twData.content_ids=ecom.items.map(function(i){return i.item_id;});
                twData.content_type='product';
            }
            twq('event',twEvt,twData);
            logPixel('Twitter/X','#1DA1F2',evt,twData);
        }
    }

    // ── Taboola Pixel ──
    if(_tbl){
        var tblMap={
            'view_item':'view_content','add_to_cart':'add_to_cart',
            'begin_checkout':'checkout','purchase':'purchase',
            'search':'search','sign_up':'lead','generate_lead':'lead',
            'form_submit':'lead',
            'video_play':'video_play','video_complete':'video_complete',
            'video_progress':'video_progress',
            'share':'share','outbound_click':'outbound_click',
            'form_start':'form_start','form_abandon':'form_abandon',
            'file_download':'file_download',
            'print_page':'print_page','copy_text':'copy_text'
        };
        var tblEvt=tblMap[evt];
        if(tblEvt){
            window._tfa=window._tfa||[];
            var tblData={notify:'event',name:tblEvt,id:_tbl};
            if(typeof val!=='undefined')tblData.revenue=val;
            if(cur)tblData.currency=cur;
            if(ecom.transaction_id)tblData.orderid=ecom.transaction_id;
            if(ecom.items&&ecom.items.length)tblData.quantity=ecom.items.length;
            window._tfa.push(tblData);
            logPixel('Taboola','#003B6F',tblEvt,tblData);
        }
    }

    // ── Outbrain Pixel ──
    if(_ob&&typeof obApi==='function'){
        var obMap={
            'purchase':'Purchase','add_to_cart':'Add To Cart',
            'begin_checkout':'Checkout','sign_up':'Lead',
            'generate_lead':'Lead','form_submit':'Lead',
            'view_item':'View Content','search':'Search',
            'video_play':'Video Play','video_complete':'Video Complete',
            'video_progress':'Video Progress',
            'share':'Share','outbound_click':'Outbound Click',
            'form_start':'Form Start','form_abandon':'Form Abandon',
            'file_download':'File Download',
            'print_page':'Print Page','copy_text':'Copy Text'
        };
        var obEvt=obMap[evt];
        if(obEvt){
            var obData={};
            if(typeof val!=='undefined')obData.orderValue=val;
            if(cur)obData.currency=cur;
            if(ecom.transaction_id)obData.orderId=ecom.transaction_id;
            obApi('track',obEvt,obData);
            logPixel('Outbrain','#FF5100',obEvt,obData);
        }
    }

    if(_debug){console.groupEnd();}
}

// ── Intercept dataLayer.push ──
var dl=window.dataLayer=window.dataLayer||[];
var origPush=dl.push.bind(dl);
dl.push=function(){
    var result=origPush.apply(dl,arguments);
    for(var i=0;i<arguments.length;i++){
        var obj=arguments[i];
        if(obj&&obj.event&&obj.event_id){
            dispatch(obj);
        }
    }
    return result;
};
for(var i=0;i<dl.length;i++){
    if(dl[i]&&dl[i].event&&dl[i].event_id){
        dispatch(dl[i]);
    }
}

window._meshulashDispatch=dispatch;
if(_debug){
console.log('%c▲ Meshulash Marketing — Debug Mode Active','background:#6C2BD9;color:#fff;padding:6px 12px;border-radius:4px;font-size:14px;font-weight:bold');
console.table({
'GA4':{active:!!_ga4,id:_ga4||'—'},
'Facebook':{active:_fb,id:_fb?'✓':'—'},
'Google Ads':{active:!!_gadsId,id:_gadsId?'AW-'+_gadsId:'—'},
'TikTok':{active:_tt,id:_tt?'✓':'—'},
'Bing/Microsoft':{active:_bing,id:_bing?'✓':'—'},
'Pinterest':{active:_pin,id:_pin?'✓':'—'},
'Reddit':{active:_rdt,id:_rdt?'✓':'—'},
'Yahoo':{active:_yahoo,id:_yahoo?'✓':'—'},
'LinkedIn':{active:_li,id:_li?'✓':'—'},
'Snapchat':{active:_snap,id:_snap?'✓':'—'},
'Twitter/X':{active:!!_twtr,id:_twtr||'—'},
'Taboola':{active:!!_tbl,id:_tbl?String(_tbl):'—'},
'Outbrain':{active:_ob,id:_ob?'✓':'—'}
});
console.log('%cGADS Labels:','font-weight:bold',_gadsLabels);
console.log('%cTip:%c Expand each event group below to see the full payload + per-pixel dispatch','color:#6C2BD9;font-weight:bold','color:inherit');
}
})();
</script>
        <?php
    }
}
