=== Tassyir for WooCommerce ===
Contributors: tassyir
Tags: woocommerce, cod, cash on delivery, algeria, order form
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 8.0
WC tested up to: 11.2
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send WooCommerce orders and products to Tassyir, with a cash-on-delivery order form. Every order is queued and retried until Tassyir confirms it.

== Description ==

Tassyir manages orders, confirmation and delivery for online shops. This plugin connects your WooCommerce store to your Tassyir store in one click:

* **Orders**: every new order (classic checkout, block checkout, orders created in wp-admin or by other plugins) is sent to Tassyir. Orders wait in a local queue and are retried automatically (after 1 minute, 5 minutes, 15 minutes, 1 hour, then every 6 hours for 3 days) until Tassyir confirms them, so an order is never lost when the network or Tassyir is briefly unavailable.
* **Products**: new and edited products, with their variations and photos, so Tassyir can create them. Existing Tassyir products are never overwritten.
* **Health**: a periodic status check tells Tassyir that the plugin works and lets it ask for orders it is missing.
* **Cash-on-delivery order form** (optional): a one-page COD form on product pages, or anywhere with the "Tassyir COD form" block or the `[tassyir_cod_form]` shortcode (also in Elementor's shortcode widget). The customer gives a name and phone, picks the wilaya and the commune (only the communes the shop's carrier serves), home or desk delivery (desk only where the carrier has one), and a quantity or one of the shop's quantity offers; the total updates with Tassyir's live delivery fees. Arabic by default (right to left), French and English, in the shop's colours. Tassyir checks each order for spam before it exists; the form then creates a normal WooCommerce order (stock, emails and reports keep working), sent to Tassyir like any other.

The plugin only makes outgoing requests to Tassyir. Tassyir never connects to your site, so it works on hosts whose firewall blocks incoming requests.

The form's page HTML can be cached by page-cache plugins (LiteSpeed Cache, WP Rocket, W3 Total Cache): everything that changes comes from the plugin's own REST routes, which are never cached.

In wp-admin you get a Tassyir column and a "Send to Tassyir" bulk action in the orders list, a Tassyir box on each order, and a settings page under WooCommerce → Tassyir.

== External services ==

This plugin connects to **Tassyir** (https://tassyir.io), the order management service the shop owner subscribes to. Nothing is sent before the shop owner clicks "Connect to Tassyir" and approves the connection on the Tassyir dashboard.

* **Tassyir API** (`https://api.tassyir.io`):
  * when an order is created or changes status, and when the owner uses "Send to Tassyir" or "Send orders since…": the order as the WooCommerce REST API shows it (customer name, phone, email, billing and shipping addresses, products, totals, payment method, customer note, order meta);
  * when a product is created, edited or deleted, and on "Sync all products": the product as the WooCommerce REST API shows it, its variations, and the files of the photos Tassyir asks for;
  * every 15 minutes (as set by Tassyir) and when the settings page is opened: plugin, WordPress, WooCommerce and PHP versions, checkout type, queue size, the time WP-Cron last ran, and the ids, statuses and dates of the 50 most recent orders with their Tassyir state;
  * when connecting and disconnecting: the site address, versions and a one-time code.
  * with the COD form turned on: when a page shows the form, the wilaya list, delivery fees, communes, the product's custom fields and offers, and the price of the cart (product, quantity, wilaya, delivery method, and the phone number once complete); when the customer submits it, the order details (name, phones, wilaya, commune, address, note, products, custom-field values and uploaded images) with the customer's IP address, any forwarded-for headers, browser user agent and a random device id kept in the customer's browser, so Tassyir can price the order and check it for spam.
* **Tassyir dashboard** (`https://app.tassyir.io`): the browser is sent there when the owner clicks "Connect" or "Reconnect", to choose the business and store; no data is sent by the server.
* **Updates**: WordPress asks `https://api.tassyir.io/woo-plugin/update.json` whether a newer version of this plugin exists (only in the build downloaded from Tassyir).

Tassyir terms of service: https://tassyir.io/en/terms-of-service
Tassyir privacy policy: https://tassyir.io/en/privacy-policy

== Installation ==

1. In wp-admin go to Plugins → Add New Plugin, search for "Tassyir", then install and activate **Tassyir for WooCommerce**. (You can also upload the zip from your Tassyir dashboard, Apps → WooCommerce, with Plugins → Add New Plugin → Upload Plugin.)
2. After activation you land on WooCommerce → Tassyir. Click **Connect to Tassyir**, choose your business and store, approve, and you are sent back to WordPress, connected.
3. Optional: click **Sync all products**, and **Send orders since…** to send recent orders.

WP-Cron must run for the queue to move. On sites with very little traffic, ask your host for a real cron job calling `wp-cron.php` every 5 minutes.

== Frequently Asked Questions ==

= What happens if Tassyir is unreachable? =

Orders stay in the queue (Tools → Scheduled Actions, group "tassyir") and are retried for 3 days. After that they are marked "Failed" and can be sent again from the orders list.

= Does it change my orders or products? =

No. The plugin only reads orders and products and stores its own sync state in order meta (`_tassyir_*`).

= How does the COD form create orders? =

The customer's browser only talks to your own site. Your site asks Tassyir for the price and a spam check, then creates the WooCommerce order itself (payment method "Cash on delivery", the status WooCommerce gives COD orders, the delivery fee as a shipping line). Submissions Tassyir holds as possible spam create no WooCommerce order and send no Purchase event; they wait in Tassyir's Spam folder, and the customer sees the usual order received page. Delivery fees, wilayas and desks come from your Tassyir store, so there is nothing to set up in WordPress.

= Does the COD form work with my page cache and my pixel plugin? =

Yes. The form is cached like any page and fetches a fresh security token and prices when it loads. Meta and TikTok Purchase events are optional (WooCommerce → Tassyir → Checkout form) and off by default when PixelYourSite is active, which already sends Purchase from the order received page.

= What is deleted when I delete the plugin? =

The connection and settings, and the queued jobs. The `_tassyir_*` order meta is kept so the history stays visible if you install it again.

= Do I need a Tassyir account? =

Yes. The plugin sends your orders to your Tassyir store; you can create an account at https://tassyir.io.

= Which checkout does it support? =

Both the classic checkout and the block checkout, with or without WooCommerce's High-Performance Order Storage (HPOS). Orders created in wp-admin or by other plugins are sent too.

== Screenshots ==

1. The Tassyir page once connected: connection status, the order queue, product sync and the problems log.
2. Before connecting: one button, and what the plugin will send to Tassyir.
3. The Tassyir column in the WooCommerce orders list.
4. The Tassyir box on an order, with a link to the order in Tassyir.
5. The product sync status after "Sync all products".
6. The cash-on-delivery form on a product page, in Arabic, with the shop's quantity offers.
7. The form filled in: commune with a desk, desk delivery, live total with Tassyir's delivery fee.

== Changelog ==

= 1.0.1 =
* Redesigned settings screens: connection status at a glance, clearer actions, and a cleaner checkout form tab with switches, a language switcher for the texts and a save bar.

= 1.0.0 =
* First release: one-click connect, order queue with retries, product and photo sync, status check with automatic repair of missed orders.
* Cash-on-delivery order form (block, shortcode, product pages) with Tassyir's geography, delivery fees, offers and spam protection.
