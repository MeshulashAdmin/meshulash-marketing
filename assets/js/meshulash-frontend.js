/**
 * Meshulash Marketing — Frontend Event Listeners
 *
 * Handles:
 * - add_to_cart (AJAX & form submit, with dedup)
 * - remove_from_cart
 * - select_item (product click in lists)
 * - add_shipping_info & add_payment_info (checkout)
 * - Interaction events: scroll depth, page timers, link clicks
 * - UTM cookie management
 *
 * In Direct Mode, events pushed to dataLayer are auto-dispatched
 * to GA4/FB/GADS by the pixel dispatcher (class-pixels.php).
 * This JS just needs to pushDL() and everything fires.
 */
(function ($) {
    'use strict';

    var M = window.meshulash || {};
    var debug = M.debug;
    var firedEvents = {};

    // ══════════════════════════════════════════════
    //  HELPERS
    // ══════════════════════════════════════════════

    function log() {
        if (debug) {
            var args = Array.prototype.slice.call(arguments);
            args.unshift('%cMeshulash JS%c', 'background:#6C2BD9;color:#fff;padding:1px 5px;border-radius:2px;font-weight:bold', 'color:inherit');
            console.log.apply(console, args);
        }
    }

    function pushDL(data, label) {
        window.dataLayer = window.dataLayer || [];

        // Deduplication
        if (data.event_id && firedEvents[data.event_id]) {
            log('Skipped duplicate:', label, data.event_id);
            return;
        }
        if (data.event_id) {
            firedEvents[data.event_id] = true;
        }

        // Clear previous ecommerce (GA4 best practice)
        window.dataLayer.push({ ecommerce: null });
        window.dataLayer.push(data);
        log(label || data.event, data);
    }

    function eid(prefix) {
        return (prefix || 'msh') + '-' + Math.random().toString(36).substr(2, 10) + '-' + Date.now();
    }

    // ══════════════════════════════════════════════
    //  ADD TO CART
    // ══════════════════════════════════════════════

    // 1. WC AJAX add to cart (archive/shop pages) — uses fragments
    $(document.body).on('added_to_cart', function (e, fragments) {
        if (fragments && fragments.meshulash_add_to_cart) {
            try {
                var eventData = JSON.parse(fragments.meshulash_add_to_cart);
                pushDL(eventData, 'add_to_cart (AJAX fragment)');
            } catch (err) {
                log('Fragment parse error:', err);
            }
            return;
        }

        // Fallback: get data from the last clicked button
        var $btn = $(document).data('_meshulash_last_atc');
        if ($btn) {
            fetchAndPushATC($btn.data('product_id'), parseInt($btn.data('quantity')) || 1);
            $(document).removeData('_meshulash_last_atc');
        }
    });

    // Track which button was clicked for the fallback
    $(document).on('click', '.ajax_add_to_cart', function () {
        $(document).data('_meshulash_last_atc', $(this));
    });

    // 2. Single product page form submit
    $(document).on('click', '.single_add_to_cart_button', function () {
        var $form = $(this).closest('form.cart');
        var productId = $form.find('input[name="product_id"]').val()
            || $form.find('[name="add-to-cart"]').val()
            || $(this).val();
        var quantity = parseInt($form.find('input[name="quantity"]').val()) || 1;

        if (productId) {
            fetchAndPushATC(productId, quantity);
        }
    });

    function fetchAndPushATC(productId, quantity) {
        if (!productId) return;

        $.post(M.ajax_url, {
            action: 'meshulash_get_product',
            nonce: M.nonce,
            product_id: productId,
            quantity: quantity
        }, function (res) {
            if (res.success) {
                pushDL({
                    event: 'add_to_cart',
                    event_id: eid('atc'),
                    ecommerce: {
                        currency: res.data.currency,
                        value: res.data.value,
                        items: [res.data.item]
                    }
                }, 'add_to_cart');
            }
        });
    }

    // ══════════════════════════════════════════════
    //  REMOVE FROM CART
    // ══════════════════════════════════════════════

    $(document.body).on('removed_from_cart', function (e, fragments, cartHash, $btn) {
        var name = '';
        var $row = $btn ? $btn.closest('.cart_item, .woocommerce-cart-form__cart-item') : null;
        if ($row && $row.length) {
            name = $row.find('.product-name a').first().text().trim();
        }

        pushDL({
            event: 'remove_from_cart',
            event_id: eid('rfc'),
            ecommerce: {
                items: [{ item_name: name || 'Unknown' }]
            }
        }, 'remove_from_cart');
    });

    // ══════════════════════════════════════════════
    //  SELECT ITEM (click product in list)
    // ══════════════════════════════════════════════

    $(document).on('click', [
        '.products .product a:not(.add_to_cart_button)',
        '.jet-woo-products__item a.jet-woo-product-thumbnail'
    ].join(','), function () {
        var $p = $(this).closest('.product, .jet-woo-products__item');
        if (!$p.length) return;

        var name = $p.find('.woocommerce-loop-product__title, .jet-woo-product-title').first().text().trim();
        var priceText = $p.find('.price ins .woocommerce-Price-amount, .price .woocommerce-Price-amount').first().text().trim();
        var price = parseFloat(priceText.replace(/[^\d.-]/g, '')) || 0;
        var pid = $p.find('.add_to_cart_button').data('product_id') || '';

        pushDL({
            event: 'select_item',
            event_id: eid('si'),
            ecommerce: {
                items: [{
                    item_name: name || 'Unknown',
                    item_id: String(pid),
                    price: price
                }]
            }
        }, 'select_item');
    });

    // ══════════════════════════════════════════════
    //  CHECKOUT: SHIPPING & PAYMENT INFO
    // ══════════════════════════════════════════════

    var shippingFired = false;
    var paymentFired = false;

    $(document).on('change', 'input[name^="shipping_method"]', function () {
        if (shippingFired) return;
        shippingFired = true;

        var tier = $(this).val() || '';
        $.post(M.ajax_url, {
            action: 'meshulash_shipping_info',
            nonce: M.nonce,
            shipping_tier: tier
        }, function (res) {
            if (res.success && res.data.datalayer) {
                pushDL(res.data.datalayer, 'add_shipping_info');
            }
        });
    });

    $(document).on('change', '#terms, input[name="payment_method"]', function () {
        if (this.id === 'terms' && !this.checked) return;
        if (paymentFired) return;
        paymentFired = true;

        var method = $('input[name="payment_method"]:checked').val() || '';
        $.post(M.ajax_url, {
            action: 'meshulash_payment_info',
            nonce: M.nonce,
            payment_type: method
        }, function (res) {
            if (res.success && res.data.datalayer) {
                pushDL(res.data.datalayer, 'add_payment_info');
            }
        });
    });

    // Reset flags on checkout refresh
    $(document.body).on('updated_checkout', function () {
        shippingFired = false;
        paymentFired = false;
    });

    // ══════════════════════════════════════════════
    //  INTERACTION: LINK CLICKS
    // ══════════════════════════════════════════════

    if (M.link_clicks) {
        $(document).on('click', 'a[href]', function () {
            var href = ($(this).attr('href') || '').toLowerCase();
            var text = $(this).text().trim().substring(0, 100);
            var event = null;
            var extra = {};

            // Phone
            if (href.indexOf('tel:') === 0) {
                event = 'phone_link_click';
                extra.target_phone = href.replace('tel:', '').replace(/\s/g, '');
            }
            // Email
            else if (href.indexOf('mailto:') === 0) {
                event = 'email_link_click';
                extra.target_email = href.replace('mailto:', '').split('?')[0];
            }
            // WhatsApp
            else if (href.indexOf('api.whatsapp') !== -1 || href.indexOf('wa.me') !== -1) {
                event = 'whatsapp_click';
                var waMatch = href.match(/(?:wa\.me\/|phone=)(\d+)/);
                extra.target_phone = waMatch ? waMatch[1] : '';
            }
            // Maps
            else if (href.indexOf('maps/search') !== -1 || href.indexOf('waze.com') !== -1 || href.indexOf('google.com/maps') !== -1) {
                event = 'maps_click';
                extra.link_url = href;
            }
            // Social Media
            else if (/facebook\.com|instagram\.com|tiktok\.com|linkedin\.com|twitter\.com|x\.com|youtube\.com/.test(href)) {
                event = 'social_link_click';
                try { extra.target_social = new URL(href).hostname.replace('www.', ''); } catch (e) { extra.target_social = href; }
            }

            if (event) {
                extra.event = event;
                extra.event_id = eid('lc');
                extra.link_text = text;
                extra.link_url = extra.link_url || href;
                pushDL(extra, event);
            }
        });

        // CTA button clicks (configurable selector)
        $(document).on('click', '.elementor-button-link, .wp-block-button__link, a.cta-button, [data-meshulash-cta]', function () {
            var href = $(this).attr('href') || '';
            // Skip if already caught by link click handlers above
            if (/^(tel:|mailto:)/.test(href.toLowerCase())) return;
            if (/whatsapp|facebook|instagram|maps|waze/.test(href.toLowerCase())) return;

            pushDL({
                event: 'cta_link_click',
                event_id: eid('cta'),
                link_text: $(this).text().trim().substring(0, 100),
                link_url: href
            }, 'cta_link_click');
        });
    }

    // ══════════════════════════════════════════════
    //  INTERACTION: SCROLL DEPTH
    // ══════════════════════════════════════════════

    if (M.scroll_depth && M.scroll_thresholds) {
        var thresholds = M.scroll_thresholds.split(',').map(Number).filter(Boolean);
        var scrollFired = {};

        $(window).on('scroll', debounce(function () {
            var scrollTop = $(window).scrollTop();
            var docHeight = $(document).height() - $(window).height();
            if (docHeight <= 0) return;

            var scrollPercent = Math.round((scrollTop / docHeight) * 100);

            thresholds.forEach(function (t) {
                if (scrollPercent >= t && !scrollFired[t]) {
                    scrollFired[t] = true;
                    pushDL({
                        event: 'scroll_depth',
                        event_id: eid('sd'),
                        scroll_threshold: t,
                        scroll_direction: 'vertical'
                    }, 'scroll_depth ' + t + '%');
                }
            });
        }, 200));
    }

    // ══════════════════════════════════════════════
    //  INTERACTION: PAGE TIMERS
    // ══════════════════════════════════════════════

    if (M.page_timer && M.timer_thresholds) {
        var timers = M.timer_thresholds.split(',').map(Number).filter(Boolean);

        timers.forEach(function (seconds) {
            setTimeout(function () {
                pushDL({
                    event: 'page_timer_' + seconds,
                    event_id: eid('pt'),
                    timer_seconds: seconds,
                    page_url: window.location.href
                }, 'page_timer_' + seconds);
            }, seconds * 1000);
        });
    }

    // ══════════════════════════════════════════════
    //  UTM COOKIE MANAGEMENT
    // ══════════════════════════════════════════════

    if (M.utm_enabled) {
        (function () {
            var params = [
                'utm_source', 'utm_medium', 'utm_campaign', 'utm_id', 'campaign_id',
                'utm_content', 'adset_id', 'utm_ad', 'ad_id', 'utm_term', 'keyword_id',
                'device', 'GeoLoc', 'IntLoc', 'placement', 'matchtype', 'network',
                'gclid', 'wbraid', 'gbraid', 'fbclid', 'obcid', 'msclkid', 'li_fat_id',
                'tblci', 'ttcid', 'pmcid', 'yclid', 'vmcid', 'twclid'
            ];

            var url = new URL(window.location.href);
            var days = M.utm_cookie_days || 90;
            var hasUtm = false;

            params.forEach(function (p) {
                var val = url.searchParams.get(p);
                if (val) {
                    setCookie(p, val, days);
                    hasUtm = true;
                }
            });

            // First touch — only set if not already stored
            if (!getCookie('first_touch_url')) {
                setCookie('first_touch_url', window.location.href, days);
                // Save first-touch UTM snapshot
                var ftSrc = url.searchParams.get('utm_source');
                if (ftSrc) {
                    setCookie('first_touch_utm_source', ftSrc, days);
                    setCookie('first_touch_utm_medium', url.searchParams.get('utm_medium') || '', days);
                    setCookie('first_touch_utm_campaign', url.searchParams.get('utm_campaign') || '', days);
                }
            }

            // Last touch — always update when UTMs arrive
            if (hasUtm) {
                setCookie('last_touch_url', window.location.href, days);
                setCookie('last_touch_utm_source', url.searchParams.get('utm_source') || '', days);
                setCookie('last_touch_utm_medium', url.searchParams.get('utm_medium') || '', days);
                setCookie('last_touch_utm_campaign', url.searchParams.get('utm_campaign') || '', days);
                setCookie('utm_landing_page', window.location.pathname, days);
                log('UTM cookies saved (first + last touch)');
            }
        })();
    }

    // ══════════════════════════════════════════════
    //  HIDDEN FIELDS INJECTION
    // ══════════════════════════════════════════════

    if (M.utm_hidden_fields) {
        var hiddenFieldParams = [
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_id', 'campaign_id',
            'utm_content', 'adset_id', 'utm_ad', 'ad_id', 'utm_term', 'keyword_id',
            'device', 'GeoLoc', 'IntLoc', 'placement', 'matchtype', 'network',
            'gclid', 'wbraid', 'gbraid', 'fbclid', 'obcid', 'msclkid', 'li_fat_id',
            'tblci', 'ttcid', 'pmcid', 'yclid', 'vmcid', 'twclid'
        ];

        /**
         * Collect ALL marketing/tracking data into a single dict.
         * UTMs, click IDs, pixel cookies, user agent, referrer, journey, etc.
         */
        function getTrackingData() {
            var data = {};

            // UTM & click ID cookies — ALWAYS include every key ("null" if no value, like GTM)
            hiddenFieldParams.forEach(function (p) {
                data[p] = getCookie(p) || 'null';
            });

            // Default source/medium/campaign (like GTM)
            if (data.utm_source === 'null') data.utm_source = 'direct';
            if (data.utm_medium === 'null') data.utm_medium = 'none';

            // GA client_id (extracted from _ga cookie: GA1.2.XXXXXXX.YYYYYYY → XXXXXXX.YYYYYYY)
            var gaCookie = getCookie('_ga');
            if (gaCookie) {
                var gaParts = gaCookie.split('.');
                data.ga_cid = gaParts.length >= 4 ? gaParts[2] + '.' + gaParts[3] : 'null';
            } else {
                data.ga_cid = 'null';
            }

            // GA session_id (from _ga_XXXXXXX cookie)
            data.ga_session_id = 'null';
            if (M.ga4_measurement_id) {
                var cookieSuffix = M.ga4_measurement_id.replace('G-', '').replace(/-/g, '');
                var gaSessionCookie = getCookie('_ga_' + cookieSuffix);
                if (gaSessionCookie) {
                    var sessParts = gaSessionCookie.split('.');
                    if (sessParts.length >= 3) {
                        data.ga_session_id = sessParts[2];
                    }
                }
            }

            // Pixel attribution cookies (without underscore prefix, like GTM)
            data.fbp = getCookie('_fbp') || 'null';
            data.fbc = getCookie('_fbc') || 'null';

            // Google remarketing cookies
            data.gcl_aw = getCookie('_gcl_aw') || 'null';
            data.gcl_dc = getCookie('_gcl_dc') || 'null';

            // TikTok
            data.ttp = getCookie('_ttp') || 'null';

            // First touch
            data.first_touch_url = getCookie('first_touch_url') || 'null';
            data.first_touch_utm_source = getCookie('first_touch_utm_source') || 'null';
            data.first_touch_utm_medium = getCookie('first_touch_utm_medium') || 'null';
            data.first_touch_utm_campaign = getCookie('first_touch_utm_campaign') || 'null';

            // Last touch
            data.last_touch_url = getCookie('last_touch_url') || 'null';
            data.last_touch_utm_source = getCookie('last_touch_utm_source') || 'null';
            data.last_touch_utm_medium = getCookie('last_touch_utm_medium') || 'null';
            data.last_touch_utm_campaign = getCookie('last_touch_utm_campaign') || 'null';

            // Page context
            data.page_url = window.location.href;
            data.page_title = document.title || '';
            data.page_referrer = document.referrer || '';

            // Browser / device info
            data.user_agent = navigator.userAgent || '';
            data.screen_resolution = screen.width + 'x' + screen.height;
            data.viewport = window.innerWidth + 'x' + window.innerHeight;
            data.language = navigator.language || '';
            data.timezone = Intl && Intl.DateTimeFormat ? Intl.DateTimeFormat().resolvedOptions().timeZone : '';
            data.timestamp = new Date().toISOString();

            // Device type
            var w = window.innerWidth;
            data.device_type = w <= 768 ? 'mobile' : (w <= 1024 ? 'tablet' : 'desktop');

            // Session data from localStorage
            try {
                var session = JSON.parse(localStorage.getItem('meshulash_session') || '{}');
                data.session_count = session.count || 1;
                data.first_visit = session.first_visit || '';
                data.visitor_type = (session.count || 0) > 1 ? 'returning' : 'new';
            } catch (e) {
                data.session_count = 1;
                data.first_visit = '';
                data.visitor_type = 'new';
            }

            // Days since first visit
            try {
                var firstVisit = parseInt(localStorage.getItem('meshulash_first_visit')) || 0;
                data.days_since_first = firstVisit ? Math.floor((Math.floor(Date.now() / 1000) - firstVisit) / 86400) : 0;
            } catch (e) {
                data.days_since_first = 0;
            }

            // Customer journey
            try {
                var journey = JSON.parse(localStorage.getItem('meshulash_journey') || '[]');
                data.customer_journey = journey.length ? journey : [];
            } catch (e) {
                data.customer_journey = [];
            }

            return data;
        }

        /**
         * Inject a single hidden_fields input into a form element.
         * Contains ALL tracking data as a JSON dict.
         */
        function injectHiddenFields(form) {
            // Skip if already injected
            if (form.querySelector('input[name="hidden_fields"]')) return;
            // Skip the WP admin / plugin settings forms
            if (form.closest('.meshulash-form, #adminmenuwrap, #wpadminbar')) return;

            var data = getTrackingData();
            if (!Object.keys(data).length) return;

            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'hidden_fields';
            input.id = 'hidden_fields';
            input.value = JSON.stringify(data);
            form.appendChild(input);

            log('Hidden fields injected into form', form.id || form.action || '(anonymous)');
        }

        /**
         * Inject into all existing forms on the page.
         */
        function injectAllForms() {
            var forms = document.querySelectorAll('form');
            for (var i = 0; i < forms.length; i++) {
                injectHiddenFields(forms[i]);
            }
        }

        // Run on DOM ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', injectAllForms);
        } else {
            injectAllForms();
        }

        // Also run after a short delay (catch late-rendered forms like Elementor)
        setTimeout(injectAllForms, 1500);

        // MutationObserver: catch dynamically added forms (popups, AJAX-loaded content)
        if (typeof MutationObserver !== 'undefined') {
            var observer = new MutationObserver(function (mutations) {
                var shouldInject = false;
                for (var i = 0; i < mutations.length; i++) {
                    var added = mutations[i].addedNodes;
                    for (var j = 0; j < added.length; j++) {
                        var node = added[j];
                        if (node.nodeType !== 1) continue;
                        if (node.tagName === 'FORM' || (node.querySelectorAll && node.querySelectorAll('form').length)) {
                            shouldInject = true;
                            break;
                        }
                    }
                    if (shouldInject) break;
                }
                if (shouldInject) {
                    setTimeout(injectAllForms, 100);
                }
            });
            observer.observe(document.body || document.documentElement, {
                childList: true,
                subtree: true
            });
        }

        // Update value right before submission (freshest data)
        $(document).on('submit', 'form', function () {
            var existing = this.querySelector('input[name="hidden_fields"]');
            if (existing) {
                existing.value = JSON.stringify(getTrackingData());
            }
        });

        // Elementor Forms: hook into the AJAX data before submission
        $(document).on('submit_success', '.elementor-form', function () {
            log('Elementor form submitted with hidden fields');
        });

        log('Hidden fields injection enabled');
    }

    // ══════════════════════════════════════════════
    //  CUSTOMER JOURNEY (PATH TO SALE)
    // ══════════════════════════════════════════════

    if (M.journey_enabled) {
        var JOURNEY_KEY = 'meshulash_journey';
        var MAX_STEPS = M.journey_max_steps || 50;

        /**
         * Detect the page type for richer journey data.
         */
        function detectPageType() {
            var body = document.body.className || '';
            if (body.indexOf('single-product') !== -1) return 'product';
            if (body.indexOf('woocommerce-cart') !== -1) return 'cart';
            if (body.indexOf('woocommerce-checkout') !== -1) return 'checkout';
            if (body.indexOf('woocommerce-order-received') !== -1 || body.indexOf('thankyou') !== -1) return 'thank_you';
            if (body.indexOf('search-results') !== -1 || body.indexOf('search') !== -1) return 'search';
            if (body.indexOf('tax-product_cat') !== -1 || body.indexOf('post-type-archive-product') !== -1) return 'category';
            if (body.indexOf('tax-product_tag') !== -1) return 'tag';
            return 'page';
        }

        /**
         * Get the journey array from localStorage.
         */
        function getJourney() {
            try {
                var raw = localStorage.getItem(JOURNEY_KEY);
                return raw ? JSON.parse(raw) : [];
            } catch (e) {
                return [];
            }
        }

        /**
         * Save journey to localStorage.
         */
        function saveJourney(journey) {
            try {
                // Trim to max steps (remove oldest)
                if (journey.length > MAX_STEPS) {
                    journey = journey.slice(journey.length - MAX_STEPS);
                }
                localStorage.setItem(JOURNEY_KEY, JSON.stringify(journey));
            } catch (e) {
                log('Journey save error:', e);
            }
        }

        /**
         * Add a step to the journey.
         */
        function addJourneyStep(type, data) {
            var journey = getJourney();
            var step = {
                ts: Math.floor(Date.now() / 1000),
                type: type || 'page',
                url: data.url || window.location.pathname,
                title: data.title || document.title.substring(0, 100)
            };
            if (data.event) step.event = data.event;
            if (data.product) step.product = data.product;
            if (data.search_term) step.search_term = data.search_term;
            if (data.referrer) step.referrer = data.referrer;
            if (data.utm_source) step.utm_source = data.utm_source;

            journey.push(step);
            saveJourney(journey);
            log('Journey step:', step);
        }

        // Record current page view
        (function () {
            var pageType = detectPageType();
            var stepData = {
                url: window.location.pathname + window.location.search,
                title: document.title.substring(0, 100)
            };

            // Add referrer on first visit or external referrer
            if (document.referrer && document.referrer.indexOf(window.location.hostname) === -1) {
                stepData.referrer = document.referrer;
            }

            // Add UTM source if present in URL
            try {
                var urlParams = new URL(window.location.href);
                var src = urlParams.searchParams.get('utm_source');
                if (src) stepData.utm_source = src;
            } catch (e) {}

            // Product page: add product name
            if (pageType === 'product') {
                var prodTitle = document.querySelector('.product_title, h1.entry-title');
                if (prodTitle) stepData.product = prodTitle.textContent.trim().substring(0, 80);
            }

            // Search page: add query
            if (pageType === 'search') {
                var searchInput = document.querySelector('input[name="s"]');
                if (searchInput) stepData.search_term = searchInput.value;
            }

            addJourneyStep(pageType, stepData);
        })();

        // Track add_to_cart as a journey event
        $(document.body).on('added_to_cart', function () {
            addJourneyStep('event', { event: 'add_to_cart', title: 'Added to Cart' });
        });

        // Track form submissions as a journey event
        $(document).on('submit', 'form', function () {
            var formId = this.id || '';
            var formAction = (this.getAttribute('action') || '').substring(0, 80);
            // Skip WC checkout (that's tracked as a page type)
            if (formId === 'order_review' || formAction.indexOf('wc-ajax=checkout') !== -1) return;
            if (this.closest('.meshulash-form')) return;
            addJourneyStep('event', { event: 'form_submit', title: 'Form: ' + (formId || formAction || 'unknown') });
        });

        /**
         * Get journey as a compact string for hidden fields / order meta.
         * Returns a JSON string of the journey array.
         */
        window._meshulashGetJourney = function () {
            return JSON.stringify(getJourney());
        };

        /**
         * Clear journey (call after purchase to start fresh).
         */
        window._meshulashClearJourney = function () {
            localStorage.removeItem(JOURNEY_KEY);
            log('Journey cleared');
        };

        // On thank you page, set a flag to clear journey after it's saved
        if (detectPageType() === 'thank_you') {
            // Clear after a delay to ensure order meta is saved
            setTimeout(function () {
                window._meshulashClearJourney();
            }, 3000);
        }

        // Journey data is now included in the unified hidden_fields dict
        // (via getTrackingData() -> customer_journey key)

        log('Journey tracking enabled (max ' + MAX_STEPS + ' steps)');
    }

    // ══════════════════════════════════════════════
    //  SESSION ENRICHMENT
    // ══════════════════════════════════════════════

    if (M.session_enrichment) {
        (function () {
            var SESSION_KEY = 'meshulash_session';
            var FIRST_VISIT_KEY = 'meshulash_first_visit';

            // Detect device type
            function getDeviceType() {
                var ua = navigator.userAgent;
                if (/tablet|ipad|playbook|silk/i.test(ua)) return 'tablet';
                if (/mobile|iphone|ipod|android|blackberry|opera mini|iemobile/i.test(ua)) return 'mobile';
                return 'desktop';
            }

            // Get or create session data
            var session;
            try {
                session = JSON.parse(localStorage.getItem(SESSION_KEY) || '{}');
            } catch (e) {
                session = {};
            }

            var now = Math.floor(Date.now() / 1000);
            var firstVisit = parseInt(localStorage.getItem(FIRST_VISIT_KEY)) || 0;

            if (!firstVisit) {
                firstVisit = now;
                localStorage.setItem(FIRST_VISIT_KEY, String(now));
            }

            // Session expires after 30 min of inactivity
            var SESSION_TIMEOUT = 1800;
            var isNewSession = !session.start || (now - (session.last_active || 0)) > SESSION_TIMEOUT;

            if (isNewSession) {
                session.count = (session.count || 0) + 1;
                session.start = now;
            }
            session.last_active = now;

            localStorage.setItem(SESSION_KEY, JSON.stringify(session));

            var visitorType = session.count > 1 ? 'returning' : 'new';
            var daysSinceFirst = Math.floor((now - firstVisit) / 86400);
            var deviceType = getDeviceType();

            // Enrich every dataLayer push with session data
            var origPushDL = pushDL;
            pushDL = function (data, label) {
                if (data && typeof data === 'object') {
                    data.visitor_type = visitorType;
                    data.session_count = session.count;
                    data.days_since_first = daysSinceFirst;
                    data.device_type = deviceType;
                }
                origPushDL(data, label);
            };

            log('Session enrichment:', visitorType, 'session #' + session.count, deviceType);
        })();
    }

    // ══════════════════════════════════════════════
    //  CART ABANDONMENT DETECTION
    // ══════════════════════════════════════════════

    if (M.cart_abandonment) {
        (function () {
            var CART_KEY = 'meshulash_cart_active';
            var abandonFired = false;
            var abandonTimeout = (M.cart_abandon_timeout || 30) * 60 * 1000;

            // Only track abandonment on cart & checkout pages
            var body = document.body.className || '';
            var isCartPage = body.indexOf('woocommerce-cart') !== -1;
            var isCheckoutPage = body.indexOf('woocommerce-checkout') !== -1;
            var isThankYou = body.indexOf('woocommerce-order-received') !== -1;

            // Set cart active flag on add_to_cart (from any page)
            $(document.body).on('added_to_cart', function () {
                localStorage.setItem(CART_KEY, '1');
            });

            // Clear on thank you page
            if (isThankYou) {
                localStorage.removeItem(CART_KEY);
            }

            // Only attach abandonment listeners on cart/checkout pages
            if (!isCartPage && !isCheckoutPage) {
                log('Cart abandonment: not on cart/checkout — skipping listeners');
                return;
            }

            function fireAbandonment(trigger) {
                if (abandonFired) return;
                abandonFired = true;
                var eventData = {
                    event: 'cart_abandonment',
                    event_id: eid('cab'),
                    abandonment_trigger: trigger,
                    abandonment_page: isCheckoutPage ? 'checkout' : 'cart'
                };
                // Include cart restore link (generated server-side)
                if (M.cart_restore_url) {
                    eventData.cart_restore_url = M.cart_restore_url;
                }
                pushDL(eventData, 'cart_abandonment (' + trigger + ')');
            }

            // Exit intent (mouse leaves viewport at top)
            document.addEventListener('mouseout', function (e) {
                if (e.clientY < 5 && !e.relatedTarget && !e.toElement) {
                    fireAbandonment('exit_intent');
                }
            });

            // Inactivity timer
            var inactivityTimer = null;
            function resetInactivity() {
                if (inactivityTimer) clearTimeout(inactivityTimer);
                inactivityTimer = setTimeout(function () {
                    fireAbandonment('inactivity');
                }, abandonTimeout);
            }
            ['mousemove', 'keydown', 'scroll', 'touchstart'].forEach(function (evt) {
                document.addEventListener(evt, resetInactivity, { passive: true });
            });
            resetInactivity();

            // Page visibility change (tab switch / minimize)
            document.addEventListener('visibilitychange', function () {
                if (document.hidden) {
                    setTimeout(function () {
                        if (document.hidden) {
                            fireAbandonment('tab_hidden');
                        }
                    }, Math.min(abandonTimeout, 120000));
                }
            });

            log('Cart abandonment detection enabled on ' + (isCheckoutPage ? 'checkout' : 'cart') + ' (' + (M.cart_abandon_timeout || 30) + ' min timeout)');
        })();
    }

    // ══════════════════════════════════════════════
    //  VARIATION / OPTION SELECTION
    // ══════════════════════════════════════════════

    if (M.event_variation_select) {
        $(document).on('change', '.variations select, .variations input[type="radio"]', function () {
            var $this = $(this);
            var attrName = $this.attr('name') || $this.data('attribute_name') || '';
            var attrValue = $this.val() || '';

            // Clean up attribute name (remove "attribute_" prefix)
            attrName = attrName.replace('attribute_', '').replace('pa_', '');

            if (attrName && attrValue) {
                pushDL({
                    event: 'variation_select',
                    event_id: eid('vs'),
                    variation_attribute: attrName,
                    variation_value: attrValue,
                    product_name: ($('.product_title').first().text() || '').trim().substring(0, 80)
                }, 'variation_select: ' + attrName + '=' + attrValue);
            }
        });

        // Also track when WC resets variations
        $(document).on('click', '.reset_variations', function () {
            pushDL({
                event: 'variation_reset',
                event_id: eid('vr'),
                product_name: ($('.product_title').first().text() || '').trim().substring(0, 80)
            }, 'variation_reset');
        });
    }

    // ══════════════════════════════════════════════
    //  IMAGE GALLERY CLICKS
    // ══════════════════════════════════════════════

    if (M.event_gallery_click) {
        // WooCommerce product gallery thumbnails
        $(document).on('click', '.woocommerce-product-gallery__image, .flex-control-thumbs img, .woocommerce-product-gallery .woocommerce-product-gallery__trigger', function () {
            var action = 'thumbnail_click';
            var $img = $(this).find('img').first();
            if (!$img.length) $img = $(this);

            if ($(this).hasClass('woocommerce-product-gallery__trigger')) {
                action = 'zoom';
            }

            pushDL({
                event: 'gallery_click',
                event_id: eid('gc'),
                gallery_action: action,
                image_src: ($img.attr('src') || '').substring(0, 200),
                product_name: ($('.product_title').first().text() || '').trim().substring(0, 80)
            }, 'gallery_click: ' + action);
        });

        // Gallery lightbox / photoswipe navigation
        $(document).on('click', '.pswp__button--arrow--left, .pswp__button--arrow--right', function () {
            var dir = $(this).hasClass('pswp__button--arrow--left') ? 'prev' : 'next';
            pushDL({
                event: 'gallery_click',
                event_id: eid('gc'),
                gallery_action: 'lightbox_' + dir
            }, 'gallery_click: lightbox_' + dir);
        });
    }

    // ══════════════════════════════════════════════
    //  CHECKOUT FIELD MICRO-EVENTS
    // ══════════════════════════════════════════════

    if (M.event_checkout_fields) {
        (function () {
            var focusedFields = {};

            // Track field focus (once per field per session)
            $(document).on('focus', '.woocommerce-checkout input, .woocommerce-checkout select, .woocommerce-checkout textarea', function () {
                var name = this.name || this.id || '';
                if (!name || focusedFields[name]) return;
                focusedFields[name] = true;

                // Clean field name
                var label = name.replace('billing_', '').replace('shipping_', '').replace('order_', '');

                pushDL({
                    event: 'checkout_field_focus',
                    event_id: eid('cff'),
                    field_name: label,
                    field_group: name.indexOf('billing_') === 0 ? 'billing' : (name.indexOf('shipping_') === 0 ? 'shipping' : 'order')
                }, 'checkout_field_focus: ' + label);
            });

            // Track field errors (WC validation)
            $(document.body).on('checkout_error', function () {
                var errors = [];
                $('.woocommerce-error li').each(function () {
                    errors.push($(this).text().trim().substring(0, 100));
                });
                if (errors.length) {
                    pushDL({
                        event: 'checkout_validation_error',
                        event_id: eid('cve'),
                        error_count: errors.length,
                        error_messages: errors.join(' | ').substring(0, 500)
                    }, 'checkout_validation_error');
                }
            });

            // Track checkout step completion
            $(document).on('blur', '.woocommerce-checkout input[name="billing_email"]', function () {
                if (this.value && this.value.indexOf('@') !== -1) {
                    pushDL({
                        event: 'checkout_email_entered',
                        event_id: eid('cee')
                    }, 'checkout_email_entered');
                }
            });
        })();
    }

    // ══════════════════════════════════════════════
    //  QUICK VIEW TRACKING
    // ══════════════════════════════════════════════

    if (M.event_quick_view) {
        // Support common quick-view plugins: YITH, WooCommerce Quick View, Elementor, JetWoo
        $(document).on('click', [
            '.yith-wcqv-button',
            '.quick-view-button',
            '.woosq-btn',
            '.jet-woo-product-button__link[data-product_id]',
            '[data-product-quickview]',
            '.xoo-qv-button'
        ].join(','), function () {
            var productId = $(this).data('product_id') || $(this).data('product-id') || '';
            var productName = $(this).closest('.product').find('.woocommerce-loop-product__title').text().trim() || '';

            pushDL({
                event: 'quick_view',
                event_id: eid('qv'),
                ecommerce: {
                    items: [{
                        item_id: String(productId),
                        item_name: productName.substring(0, 100)
                    }]
                }
            }, 'quick_view: ' + (productName || productId));
        });
    }

    // ══════════════════════════════════════════════
    //  MINI-CART TRACKING
    // ══════════════════════════════════════════════

    if (M.event_mini_cart) {
        // Track mini-cart open (various theme implementations)
        $(document).on('click', [
            '.cart-contents',
            '.mini-cart-toggle',
            '.header-cart-icon',
            '.site-header-cart',
            '.xoo-wsc-cart-trigger',
            '[data-toggle="minicart"]',
            '.elementor-menu-cart__toggle'
        ].join(','), function () {
            pushDL({
                event: 'mini_cart_open',
                event_id: eid('mc')
            }, 'mini_cart_open');
        });

        // Track remove from mini-cart
        $(document).on('click', '.mini_cart_item .remove, .widget_shopping_cart .remove', function () {
            var name = $(this).closest('.mini_cart_item, .cart_item').find('a:not(.remove)').first().text().trim() || '';
            pushDL({
                event: 'mini_cart_remove',
                event_id: eid('mcr'),
                ecommerce: {
                    items: [{ item_name: name.substring(0, 100) }]
                }
            }, 'mini_cart_remove: ' + name);
        });
    }

    // ══════════════════════════════════════════════
    //  FORM TRACKING
    // ══════════════════════════════════════════════
    if (M.form_tracking && M.event_form_submit !== false) {
        var formsFired = {};

        function fireFormEvent(formId, formName, formPlugin) {
            var key = formPlugin + '_' + formId;
            if (formsFired[key]) return;
            formsFired[key] = true;
            pushDL({
                event: 'form_submit',
                event_id: eid('frm'),
                form_id: formId,
                form_name: formName,
                form_plugin: formPlugin
            }, 'form_submit: ' + formPlugin + ' — ' + formName);
        }

        // Contact Form 7
        document.addEventListener('wpcf7mailsent', function (e) {
            var detail = e.detail || {};
            fireFormEvent(detail.contactFormId || '', detail.contactFormId || 'CF7 Form', 'cf7');
        });

        // WPForms
        $(document).on('wpformsAjaxSubmitSuccess', function (e, response) {
            var $form = $(e.target);
            var formId = $form.data('formid') || '';
            fireFormEvent(formId, 'WPForms #' + formId, 'wpforms');
        });

        // Gravity Forms
        if (typeof gform !== 'undefined') {
            $(document).on('gform_confirmation_loaded', function (e, formId) {
                fireFormEvent(formId, 'Gravity Forms #' + formId, 'gravityforms');
            });
        }

        // Ninja Forms
        $(document).on('nfFormSubmitResponse', function (e, response) {
            var formId = response && response.id ? response.id : '';
            fireFormEvent(formId, 'Ninja Forms #' + formId, 'ninjaforms');
        });

        // Forminator
        $(document).on('forminator:form:submit:success', function (e, response) {
            var $form = $(e.target);
            var formId = $form.data('form-id') || $form.attr('id') || '';
            fireFormEvent(formId, 'Forminator #' + formId, 'forminator');
        });

        // Fluent Forms
        $(document).on('fluentform_submission_success', function (e, response, $form) {
            var formId = $form ? ($form.data('form_id') || '') : '';
            fireFormEvent(formId, 'Fluent Forms #' + formId, 'fluentforms');
        });

        // WS Form
        $(document).on('wsf-submit-complete', function (e, formObject, formId) {
            fireFormEvent(formId || '', 'WS Form #' + (formId || ''), 'wsform');
        });

        // Elementor Forms (frontend AJAX success)
        $(document).on('submit_success', '.elementor-form', function (e, response) {
            var $form = $(this);
            var formName = $form.find('[name="form_name"]').val() || $form.closest('.elementor-widget').data('id') || '';
            fireFormEvent(formName, 'Elementor Form: ' + formName, 'elementor');
        });

        // Generic fallback: any form with class .meshulash-track-form
        $(document).on('submit', '.meshulash-track-form', function () {
            var $form = $(this);
            var formId = $form.attr('id') || $form.attr('name') || '';
            fireFormEvent(formId, formId || 'Custom Form', 'custom');
        });

        log('Form tracking enabled');
    }

    // ══════════════════════════════════════════════
    //  DOWNLOAD TRACKING
    // ══════════════════════════════════════════════
    if (M.download_tracking && M.event_file_download !== false) {
        var dlExts = (M.download_extensions || 'pdf,doc,docx,xls,xlsx,zip').split(',').map(function (e) { return e.trim().toLowerCase(); });
        var dlExtRegex = new RegExp('\\.(' + dlExts.join('|') + ')(\\?.*)?$', 'i');

        $(document).on('click', 'a[href]', function () {
            var href = this.href || '';
            if (!dlExtRegex.test(href)) return;

            var parts = href.split('/');
            var fileName = parts[parts.length - 1].split('?')[0];
            var ext = fileName.split('.').pop().toLowerCase();

            pushDL({
                event: 'file_download',
                event_id: eid('dl'),
                file_url: href,
                file_name: fileName,
                file_extension: ext
            }, 'file_download: ' + fileName);
        });

        log('Download tracking enabled (' + dlExts.join(', ') + ')');
    }

    // ══════════════════════════════════════════════
    //  SENDBEACON API FOR SERVER EVENTS
    // ══════════════════════════════════════════════
    if (M.use_send_beacon) {
        window.meshulashBeacon = function (eventName, eventData, eventId) {
            if (!navigator.sendBeacon) return false;
            var data = new FormData();
            data.append('action', 'meshulash_beacon_event');
            data.append('nonce', M.nonce);
            data.append('event_name', eventName);
            data.append('event_data', JSON.stringify(eventData));
            data.append('event_id', eventId || '');
            var sent = navigator.sendBeacon(M.ajax_url, data);
            if (sent) log('Beacon sent →', eventName);
            return sent;
        };

        // Use beacon for purchase events on page unload (ensures delivery)
        window.addEventListener('beforeunload', function () {
            // Flush any pending server events via beacon
            if (window._meshulashPendingBeacons) {
                window._meshulashPendingBeacons.forEach(function (b) {
                    window.meshulashBeacon(b.name, b.data, b.id);
                });
            }
        });
    }

    // ══════════════════════════════════════════════
    //  PRODUCT DATA CACHE CONSUMPTION
    // ══════════════════════════════════════════════
    // When meshulashProductData is available, enhance add-to-cart clicks with cached data
    if (window.meshulashProductData) {
        $(document).on('click', '.add_to_cart_button, .single_add_to_cart_button', function () {
            var productId = $(this).data('product_id') || $(this).val() || $('input[name="product_id"]').val();
            if (productId && window.meshulashProductData[productId]) {
                log('Product data cache hit for ID:', productId);
                // Data is already available for the dispatcher via the standard add_to_cart flow
            }
        });
    }

    // ══════════════════════════════════════════════
    //  UTILITIES
    // ══════════════════════════════════════════════

    function setCookie(name, value, days) {
        var d = new Date();
        d.setTime(d.getTime() + days * 86400000);
        document.cookie = name + '=' + encodeURIComponent(value) + ';expires=' + d.toUTCString() + ';path=/;SameSite=Lax';
    }

    function getCookie(name) {
        var match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
        return match ? decodeURIComponent(match[2]) : null;
    }

    function debounce(fn, wait) {
        var t;
        return function () {
            var ctx = this, args = arguments;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(ctx, args); }, wait);
        };
    }

    log('Frontend initialized');

})(jQuery);
