# Meshulash Marketing — WooCommerce Tracking & Conversions

A complete ecommerce tracking suite for WooCommerce. Client-side pixels, server-side APIs, marketing attribution, customer intelligence, and WhatsApp automation — all from one plugin, zero coding required.

Built by [Meshulash Digital](https://meshulash.digital).

---

## Features at a Glance

| Category | What You Get |
|---|---|
| **Pixel Tracking** | GA4, Facebook, Google Ads, TikTok, Bing, Pinterest, Reddit, Yahoo |
| **Server-Side** | Facebook CAPI, GA4 Measurement Protocol, TikTok Events API |
| **GTM Support** | Full dataLayer integration or direct pixel injection — your choice |
| **Ecommerce Events** | 20+ GA4 events: view_item, add_to_cart, purchase, refund, and more |
| **Attribution** | UTM parameters, click IDs (GCLID, FBCLID, MSCLKID…), customer journey |
| **Smart Conversions** | Purchase tiers, recurring customer detection, RFM scoring, profit tracking |
| **Product Feeds** | Auto-generated feeds for Facebook, Google Shopping, and Pinterest |
| **WhatsApp** | Mumble integration for purchase notifications, leads, and cart abandonment |
| **Auto-Updates** | GitHub-based updater with one-click updates in wp-admin |

---

## Requirements

- WordPress 5.8+
- WooCommerce 7.0+
- PHP 7.4+

Compatible with WooCommerce HPOS (High-Performance Order Storage).

---

## Installation

1. Download the latest `meshulash-marketing.zip` from [Releases](https://github.com/MeshulashAdmin/meshulash-marketing/releases).
2. In WordPress admin, go to **Plugins → Add New → Upload Plugin**.
3. Upload the ZIP and activate.
4. Go to **Meshulash** in the admin sidebar to configure.

### Auto-Updates

Once installed, the plugin can update itself from GitHub:

1. Go to **Meshulash → General** tab.
2. Set **GitHub Repository** to `MeshulashAdmin/meshulash-marketing`.
3. That's it — WordPress will check for new releases every 12 hours and show update notices automatically.

---

## Configuration

The plugin settings are organized into tabs:

### General

- **Tracking Mode**: Choose between **Direct** (pixels injected by the plugin) or **GTM** (events pushed to dataLayer for Google Tag Manager to handle).
- **GTM Container ID**: Your GTM-XXXXXXX ID (only needed in GTM mode).
- **Debug Mode**: Logs all tracking events to the browser console.
- **Bot Detection**: Automatically skips tracking for 45+ known bots and crawlers.
- **Duplicate Purchase Prevention**: Prevents the same order from being tracked twice.

### Pixels & IDs

Configure your pixel/measurement IDs:

| Platform | Setting |
|---|---|
| Google Analytics 4 | Measurement ID (G-XXXXXXX) |
| Facebook | Pixel ID |
| Google Ads | Conversion ID + labels per event |
| TikTok | Pixel ID |
| Bing / Microsoft | UET Tag ID |
| Pinterest | Tag ID |
| Reddit | Pixel ID |
| Yahoo | Pixel ID / Dot ID |

**Multiple pixels**: GA4 and Facebook support comma-separated additional IDs for multi-account tracking.

### Server-Side APIs

Server-side tracking sends events directly from your server to the platforms' APIs, improving data accuracy and resilience against ad blockers.

- **Facebook CAPI**: Access Token + optional Test Event Code
- **GA4 Measurement Protocol**: API Secret
- **TikTok Events API**: Access Token + optional Test Event Code

All server-side events include:
- Hashed PII (email, phone, address) for user matching
- Event ID deduplication (prevents double-counting with client-side)
- IP address and User Agent forwarding
- Async non-blocking requests (won't slow down your site)

### Events

Toggle individual ecommerce events on/off:

**Standard GA4 Events:**
- `view_item` — Product page views
- `view_item_list` — Category/shop page listings
- `select_item` — Product click from listing
- `add_to_cart` / `remove_from_cart`
- `view_cart`
- `begin_checkout`
- `add_shipping_info` / `add_payment_info`
- `purchase`
- `refund`
- `search`
- `sign_up` / `login`
- `add_to_wishlist` (supports YITH & TI WooCommerce Wishlist)

**Interaction Events (Direct Mode):**
- Link clicks (phone, email, WhatsApp, social, maps, CTA buttons)
- Scroll depth (configurable thresholds: 25%, 50%, 75%, 90%)
- Page timers (configurable thresholds: 60s, 120s)
- Form submissions
- File downloads (configurable extensions)

**Smart Business Events:**
- `midTierPurchase` / `premiumPurchase` / `luxuryPurchase` — Based on order value thresholds
- `recurringCustomer` — Returning buyers
- `vipCustomer` — Total lifetime spend above threshold
- `purchase10Plus` — 10+ orders lifetime
- `purchaseNumber1` through `purchaseNumber10` — Tracks nth purchase
- `purchase_X_products` — Product count in order

### Google Ads Conversion Labels

Map conversion labels to specific events:
- Purchase
- Begin Checkout
- Add to Cart
- Add Payment Info
- Sign Up
- Recurring Customer
- Mid-Tier / Premium / Luxury / VIP purchases

### Purchase Tier Thresholds

Customize the order value tiers:

| Tier | Default Threshold |
|---|---|
| Mid-Tier | ₪600+ |
| Premium | ₪1,200+ |
| Luxury | ₪2,500+ |
| VIP (lifetime) | ₪2,500+ total spent |

### UTM & Attribution

- **Automatic UTM capture**: Reads 28+ URL parameters on landing
- **Cookie persistence**: Configurable duration (default: 90 days)
- **Click ID tracking**: GCLID, FBCLID, MSCLKID, TikTok, LinkedIn, Pinterest, Yahoo, Twitch
- **Hidden fields**: Auto-injects tracking data into all forms (Contact Form 7, Elementor, WPForms, Gravity Forms, etc.)
- **Customer journey**: Records page-by-page navigation with timestamps, up to 50 steps
- **Order meta**: All attribution data saved to the order for reporting

**Data captured per order:**
- UTM parameters (source, medium, campaign, content, term)
- Click IDs (gclid, fbclid, msclkid, etc.)
- Landing page & referrer
- Device type, screen resolution, browser language
- GA4 client ID & session ID
- Facebook browser ID (_fbp), click ID (_fbc)
- Full customer journey

### Consent Mode

- **Google Consent Mode v2**: Set default consent state for analytics and ads storage
- **Per-pixel consent**: Granular control over which pixels require consent
- **JavaScript API**: Call `meshulashGrantConsent()` from your cookie banner to grant all consent

```javascript
// Example: Grant consent when user accepts cookies
document.querySelector('.accept-cookies').addEventListener('click', function() {
    meshulashGrantConsent();
});
```

### Profit Tracking

- Adds a **Cost Price** field to simple and variable products
- Calculates profit and margin % per order
- Fires `purchase_profit` event with profit data
- Stores profit metrics on the order for reporting

### RFM Scoring

Automatic customer scoring on each purchase:

| Metric | Score 5 | Score 1 |
|---|---|---|
| **Recency** | Purchased 0–7 days ago | 180+ days ago |
| **Frequency** | 20+ orders | 1 order |
| **Monetary** | $5,000+ total | Under $100 |

Fires `customer_rfm` event with combined score (e.g., "555" = best customer).

---

## Product Catalog Feeds

Auto-generated product feeds accessible via REST API:

| Platform | Endpoint |
|---|---|
| Facebook | `/wp-json/meshulash/v1/feed/facebook` |
| Google Shopping | `/wp-json/meshulash/v1/feed/google` |
| Pinterest | `/wp-json/meshulash/v1/feed/pinterest` |

- XML format, regenerated daily via WordPress cron
- 24-hour cache for performance
- Includes: title, description, price, sale price, images, availability, SKU, categories
- Handles variable products (each variation as a separate item)
- On-demand regeneration from admin panel

---

## Mumble WhatsApp Integration

Connect with [Mumble](https://mumble.co.il) for automated WhatsApp messaging:

- **On Purchase**: Create/update customer in Mumble + send template message
- **On Lead**: Add form submissions to Mumble
- **On Sign-Up**: Sync new registrations
- **On Cart Abandonment**: Send recovery message with cart restore link
- **UTM Sync**: Pass attribution data to Mumble for campaign tracking
- **Templates & Labels**: Configure templates and customer labels per trigger

### Cart Restore Links

The plugin generates encoded cart URLs that rebuild a customer's cart:

```
https://yoursite.com/?meshulash_cart=BASE64_ENCODED_CART&meshulash_coupon=SAVE10
```

Used by the Mumble integration for cart abandonment recovery.

---

## Custom Scripts

Inject custom code globally via the **Scripts** tab:

- **Head Scripts**: Injected before `</head>` — ideal for additional tracking pixels, meta tags
- **Footer Scripts**: Injected before `</body>` — ideal for chat widgets, custom JS

Accepts raw HTML/JavaScript. Only editable by administrators.

---

## Cross-Domain Tracking

Enable tracking across multiple domains:

1. Toggle **Cross-Domain Tracking** on
2. Enter comma-separated domains (e.g., `shop.example.com, blog.example.com`)
3. GA4 will automatically link sessions across these domains

---

## Technical Details

### DataLayer Structure (GTM Mode)

All events are pushed to `window.dataLayer` following GA4 conventions:

```javascript
dataLayer.push({
    event: 'purchase',
    ecommerce: {
        transaction_id: '12345',
        value: 299.90,
        currency: 'ILS',
        items: [
            {
                item_id: '42',
                item_name: 'Product Name',
                price: 149.95,
                quantity: 2,
                item_category: 'Category'
            }
        ]
    },
    meshulash_event_id: 'purchase_abc123_1710000000',
    user_data: { /* hashed PII */ }
});
```

### Direct Mode Pixel Dispatcher

In Direct mode, the plugin intercepts all `dataLayer.push()` calls and automatically maps events to each platform's pixel format:

- **GA4**: `gtag('event', ...)` with full ecommerce parameters
- **Facebook**: `fbq('track', 'Purchase', ...)` with content mapping
- **Google Ads**: `gtag('event', 'conversion', ...)` with conversion labels
- **TikTok**: `ttq.track('CompletePayment', ...)`
- **Bing**: `window.uetq.push('event', ...)`
- **Pinterest**: `pintrk('track', 'checkout', ...)`
- **Reddit**: `rdt('track', 'Purchase', ...)`
- **Yahoo**: `dotq.push({ projectId: ..., event: 'conversion' })`

### Event Deduplication

Every event includes a unique `meshulash_event_id`. Server-side events send the same ID to prevent double-counting between client-side and server-side tracking.

### sendBeacon API

Server-side events use the `navigator.sendBeacon()` API by default, ensuring events are delivered reliably even when the user navigates away from the page.

### Order Meta

The plugin stores tracking data on each WooCommerce order:

| Meta Key | Content |
|---|---|
| `_meshulash_utm` | UTM parameters & click IDs |
| `_meshulash_journey` | Customer journey steps |
| `_meshulash_hidden_fields` | Full tracking context from form |
| `_meshulash_tracked` | Timestamp (duplicate prevention) |
| `_meshulash_profit` | Profit & margin data |
| `_meshulash_rfm` | RFM score breakdown |

### Admin Order View

UTM data appears in a meta box on the order edit screen. A "UTM Source" column is added to the orders list for quick filtering.

---

## Kill Switch

To disable all tracking in an emergency, add this to `wp-config.php`:

```php
define( 'MESHULASH_DISABLE', true );
```

Admins will see a notice that tracking is disabled. Remove the line to re-enable.

---

## Form Integrations

The plugin detects and tracks form submissions from:

- **Elementor Forms** (via `elementor_pro/forms/new_record`)
- **Contact Form 7** (via `wpcf7_submit`)
- **WPForms** (via `wpforms_process_complete`)

Form data (name, email, phone) is hashed and sent server-side as `Lead` events.

---

## WooCommerce Subscriptions

If [WooCommerce Subscriptions](https://woocommerce.com/products/woocommerce-subscriptions/) is active, the plugin tracks:

- `subscription_renewal`
- `subscription_cancelled`
- `subscription_reactivated`
- `subscription_expired`
- `subscription_paused`

---

## Supported Wishlist Plugins

- [YITH WooCommerce Wishlist](https://yithemes.com/themes/plugins/yith-woocommerce-wishlist/)
- [TI WooCommerce Wishlist](https://wordpress.org/plugins/ti-woocommerce-wishlist/)

---

## FAQ

**Q: GTM mode or Direct mode?**
A: Use **Direct mode** for a simple setup — the plugin handles everything. Use **GTM mode** if you already have a GTM container and want full control over tag firing rules.

**Q: Do I need server-side tracking?**
A: It's recommended. Server-side tracking improves data accuracy, especially for conversions, and is resilient against ad blockers and browser restrictions (ITP, ETP).

**Q: Will this slow down my site?**
A: No. Server-side API calls are non-blocking (async). Client-side scripts are minimal and loaded efficiently. Product data is cached inline to avoid extra AJAX requests.

**Q: Does it work with cookie consent plugins?**
A: Yes. Enable Consent Mode in settings and call `meshulashGrantConsent()` from your cookie banner when the user accepts. Per-pixel consent is also available.

**Q: How do I test tracking?**
A: Enable **Debug Mode** in the General tab. All tracking events will be logged to the browser console with full details.

---

## Changelog

### 1.1.0
- Added GitHub-based auto-updater
- Added Bing, Pinterest, Reddit, Yahoo pixel support
- Added product catalog feeds (Facebook, Google Shopping, Pinterest)
- Added Mumble WhatsApp integration
- Added RFM scoring
- Added profit tracking
- Added WooCommerce Subscriptions support
- Added interaction events (scroll depth, page timers, link clicks)
- Added cross-domain tracking
- Added per-pixel consent controls

### 1.0.0
- Initial release
- GA4, Facebook, Google Ads, TikTok tracking
- GTM and Direct mode
- Server-side: Facebook CAPI, GA4 MP, TikTok Events API
- UTM attribution and customer journey
- Smart purchase tier events

---

## License

GPL-2.0-or-later

---

## Support

For support, feature requests, or bug reports, contact [Meshulash Digital](https://meshulash.digital).
