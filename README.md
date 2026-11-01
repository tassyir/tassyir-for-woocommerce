# Tassyir for WooCommerce

WordPress plugin that sends WooCommerce orders and products to Tassyir. It only ever calls
**out** to `api.tassyir.io`, queues every order in Action Scheduler and retries until Tassyir
confirms it, uploads product photos itself, and sends a heartbeat that Tassyir answers with
commands.

- API contract: `WOOCOMMERCE_PLUGIN_CONTRACT.md` (Tassyir workspace), sections A–G.
- Plan / design: `WOOCOMMERCE_PLUGIN_PLAN.md`, §1 and §3.
- wordpress.org listing text: [`readme.txt`](readme.txt).

## Layout

```
tassyir-for-woocommerce.php  header, constants, autoload, HPOS/blocks compatibility, boot
uninstall.php                disconnect (best effort), delete options + jobs; order meta kept
includes/                    PSR-4, namespace Tassyir\WooCommerce
  Plugin.php                 builds the components, wires hooks, activation, environment info
  Signer.php                 contract §A signing (pure)
  Api_Client.php             the ONE door to Tassyir (wp_remote_request, 20 s, error mapping)
  Api_Response.php           result value object
  Connection.php             options (autoload off), connect state, exchange, health flags
  Order_Sync.php             order hooks → one job per order, push, back-off, backfill
  Backoff.php                1 m, 5 m, 15 m, 1 h, 6 h, every 6 h up to 3 days (pure)
  Rest_Renderer.php          REST v3 JSON (context=edit) from WooCommerce's own controllers
  Product_Sync.php           product hooks, "Sync all" in batches of 20, image uploads
  Heartbeat.php              recurring heartbeat, settings, commands + acks
  Manual_Webhooks.php        leftover webhook-only webhooks, "Delete them"
  Queue.php                  Action Scheduler helpers (group `tassyir`), cron health
  Failure_Log.php            last 20 failures
  Updater.php                self-update (plugin-update-checker, prefixed by Strauss)
  self-update.php            hooks Updater (Composer "files" autoload); not in the wordpress.org build
  Admin/                     settings page, orders column/bulk action/meta box, notices, labels
assets/                      admin scripts and styles (enqueued, never inline)
languages/                   .pot + ar, fr translations
.wordpress-org/              wordpress.org listing screenshots (and icons/banners to come)
tests/unit/                  pure PHPUnit tests (signing, back-off)
tests/integration/           WordPress + WooCommerce test suite (wp-env)
tests/stub/server.js         local fake Tassyir (happy paths, verifies signatures)
bin/build.sh                 builds dist/tassyir-for-woocommerce-<version>[-wporg].zip
```

## Tooling (Docker only, no local PHP needed)

```bash
# Dependencies (also prefixes vendor libraries into vendor-prefixed/ with Strauss). The script
# runs Composer on a copy inside the container: Strauss cannot delete folders on a Windows mount.
bin/composer.sh . install

# Coding standards: WordPress-Extra + PHPCompatibilityWP 7.4+
docker run --rm -v "$PWD:/app" -w /app --entrypoint php composer:2 vendor/bin/phpcs

# Static analysis: PHPStan level 6 + szepeviktor/phpstan-wordpress
docker run --rm -v "$PWD:/app" -w /app --entrypoint php composer:2 -d memory_limit=2G vendor/bin/phpstan analyse

# Unit tests on PHP 7.4 and 8.3
docker run --rm -v "$PWD:/app" -w /app php:7.4-cli vendor/bin/phpunit
docker run --rm -v "$PWD:/app" -w /app php:8.3-cli vendor/bin/phpunit

# Local WordPress + WooCommerce (http://localhost:8890, admin / password)
npx wp-env start
npx wp-env run cli wp plugin activate tassyir-for-woocommerce

# Integration tests (WordPress test suite, WooCommerce loaded)
npx wp-env run tests-cli --env-cwd=wp-content/plugins/tassyir-for-woocommerce \
  vendor/bin/phpunit -c phpunit-integration.xml.dist

# Fake Tassyir for manual testing (the dev site points at it via TASSYIR_API_URL/TASSYIR_APP_URL)
node tests/stub/server.js

# Release zips: self-hosted, and the wordpress.org build (no self-updater)
bin/build.sh
bin/build.sh --wporg
```

On Windows Git Bash, prefix docker commands with `MSYS_NO_PATHCONV=1` and use `-v "$(pwd -W):/app"`.

`TASSYIR_API_URL` (default `https://api.tassyir.io`) and `TASSYIR_APP_URL` (default
`https://app.tassyir.io`) can be defined in `wp-config.php` to point the plugin at a local Tassyir.

### Against a local Tassyir backend

A backend running with `NODE_ENV` other than `production` accepts local sites on the plugin's
connect paths: `localhost`, `127.0.0.1`, `*.localhost`, `*.test`, `*.local` and
`host.docker.internal`, over http or https, with a port. So `http://localhost:8890` connects as
is: point `TASSYIR_API_URL` at `http://host.docker.internal:<port>` (the backend, reached from the
container) and `TASSYIR_APP_URL` at the dashboard (`http://localhost:<port>`, reached from your
browser), then click **Connect to Tassyir**. Production refuses these addresses.

## COD form (Phase 2, contract §H)

Port of the Shopify COD form (`extensions/cod-form` in the backend repo): same fields,
behaviour, offer cards and copy. `assets/js/cod-form.js` is the Shopify script with WordPress
plumbing only (REST base, nonce, WooCommerce variations, device id, success redirect);
`assets/css/cod-form.css` is its stylesheet with every rule scoped under `.tsy-cod-form`.

```
includes/Cod/
  Storefront.php     signed §H calls: config, towns, product-fields, upload, quote, submit
  Rest.php           /wp-json/tassyir/v1/{config,towns,product-fields,nonce,quote,upload,orders}
  Order_Creator.php  the WooCommerce order of an allowed / flagged submission (§H6 "allow")
  Receipt.php        the order-received page of a held / dropped one (no order), views/receipt-classic.php
  Request_Guard.php  nonce, per-IP rate limit, the §H6 customer block
  Form.php, views/   the markup (static, cacheable) and the product data the script reads
  Placement.php      block tassyir/cod-form, shortcode [tassyir_cod_form], product pages
  Settings.php       WooCommerce → Tassyir → Checkout form (Admin/Cod_Settings_Page.php)
  Texts.php          the form's ar / fr / en texts
assets/blocks/cod-form/  block.json + a build-free editor script
```

Flow: the browser calls only the shop's REST routes (nonce fetched at load). `orders` checks the
honeypot, nonce and per-IP limit, calls `submit`, then on `allow` / `flag` creates the WooCommerce
order (lines and fee from the answer, `DZ-xx` state, `_tassyir_*` meta) which Phase 1 pushes like
any other; on `hold` / `drop` it creates no order and answers exactly like an allowed one (below).
The stub (`tests/stub/server.js`) implements §H's routes; `STUB_VERDICT=hold` forces a verdict, and
a phone ending in 0000000 is held.

### The push never runs in the customer's request

Saving the order queues `tassyir_push_order` (Order_Sync, as for any order); the submit request
calls Tassyir once (`submit`) and nothing else. After creating an order it also asks Action
Scheduler to run soon (`Queue::run_soon()`): at shutdown, once PHP-FPM / LiteSpeed has ended the
response, it sends Action Scheduler's own async-runner loopback. Under mod_php (wp-env) it does not,
because the "non-blocking" loopback still costs the request 10 ms to 1 s (libcurl rounds the 0.01 s
timeout up to a second with some DNS resolvers); WP-Cron then runs the job, spawned by the
order-received page load that follows. Measured in wp-env (Docker on Windows, where WordPress takes
4-11 s to boot for ANY request, the bare `GET /nonce` included):

| | submit → redirect URL | of which WordPress boot | Tassyir `submit` | after Tassyir answered |
|---|---|---|---|---|
| before (b082860), 3 runs | 9.5 / 13.7 / 8.4 s | 6.4 / 10.8 / 5.9 s | 0.13 / 0.12 / 0.03 s | 1.5 / 1.4 / 1.2 s |
| after, allowed, 3 runs | 10.3 / 10.2 / 4.9 s | 7.6 / 7.7 / 3.4 s | 0.10 / 0.07 / 0.04 s | 1.3 / 1.2 / 0.8 s |
| after, held, 2 runs | 8.8 / 11.4 s | 7.3 / 9.9 s | 0.04 / 0.09 s | 0.07 / 0.05 s |

The time after Tassyir answers is WooCommerce saving the order and sending its emails (as at
checkout). No push happened in the request before either: the reported ~10 s is this environment's
boot, twice (the submit, then the order-received page). The push reached the stub ~6 s after
`submit`, from the WP-Cron request the order-received page spawned.

### Held submissions look like orders (§H6 hold / drop)

A bot must not learn that it was held, yet no WooCommerce order may exist and no Purchase may be
tracked. So a held (or dropped) submission gets a receipt, kept 2 hours in a transient:
- the same answer keys as an order (`orderId`, totals, `redirect`, …), except `eventId`, which is
  empty; the form fires its Meta / TikTok Purchase and the `tassyir:cod:purchase` DOM event only
  with an event id, so a held submission fires none;
- an order number taken the way WooCommerce takes order ids (a posts row inserted and deleted at
  once, plain SQL so no hook or cache purge sees it): increasing, never repeated by a later order;
- the same redirect: `/checkout/order-received/<number>/?key=wc_order_<13>` for "order received",
  `?order=<number>&total=…` for a custom URL, the inline panel (now with the number) for inline.

On that URL WooCommerce renders its own page (same template, title, body classes, CSS and JS) and
finds no order; `Receipt` fills the order-confirmation blocks (block themes, through `render_block`,
with WooCommerce's wrappers, default attributes and its own translations) or replaces
`checkout/thankyou.php` (classic themes) from the receipt, using an in-memory order that
`Order_Creator::build()` makes and nothing saves. In wp-env the held page's `<main>` is identical to
an allowed one's apart from the number and the customer's data. The same receipt is returned for a
double press (by `submissionId`).

Trade-offs, left as they are:
- `eventId` is empty in the JSON answer of a held submission, and with the form's own pixels on, a
  held submission sends no Purchase: a bot reading the answer or watching pixel requests can tell.
- No order hook runs on a receipt (`woocommerce_thankyou`, `woocommerce_before_thankyou`,
  `woocommerce_order_details_*`), and an order lookup by number or key finds nothing: that is what
  keeps PixelYourSite and the official Meta / Google / TikTok plugins from sending Purchase. A tag
  manager rule on the URL alone ("Page URL contains order-received") cannot tell the two apart and
  still fires.
- Content other plugins add to the order-received page through those hooks is missing on a receipt.
- After 2 hours (or after a restart that flushes transients) the receipt URL shows WooCommerce's own
  "no order" page, where a real order's page would ask a guest to log in or confirm the email.

**§G notes** (plugin-side behaviour the backend may rely on; proposed text for the contract):
- The COD form's order push is the normal `tassyir_push_order` job; the form's request never calls
  `POST /woo-plugin/orders`. After an order it dispatches Action Scheduler's async runner once the
  response is sent (PHP-FPM / LiteSpeed), else WP-Cron runs it.
- `hold` / `drop`: no WooCommerce order and no order hook, but a receipt with an order-id-like number
  and an order-received URL; the number is never a WooCommerce order's, so a Tassyir order released
  from the Spam folder has no WooCommerce number to match (as today).

## Signing test vector (contract §A)

Both the plugin (`tests/unit/SignerTest.php`) and the backend should produce:

| | |
|---|---|
| secret (base64url of bytes `0x00…0x1f`) | `AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8` |
| timestamp | `1761700000` |
| nonce | `00112233445566778899aabbccddeeff` |
| method, path | `POST`, `/woo-plugin/orders` |
| body (raw bytes) | `{"order":{"id":123},"channel":"plugin","attempt":1}` |
| sha256(body) | `01cd852686520798d2345b743eee4097bcddc4a7e5f0dde072cef33afec0f980` |
| **X-Tassyir-Signature** | **`iXSgCBkAihTSN9916OERE9b/ERJD5Skske6K1riEV9s=`** |

Same secret/timestamp/nonce, `POST /woo-plugin/disconnect` with body `{}` →
`9BDzs3ZLva2OhiCuw0bsZcO4DzjvEiV2v9K7WMOAXKs=`.

The backend's own vector (same secret, timestamp `1761742800`, nonce
`0123456789abcdef0123456789abcdef`, `POST /woo-plugin/orders`, body
`{"order":{"id":48954},"channel":"plugin","attempt":1}`) →
`fa3MFlIj4Upaw0lSvj6PEBOXcgasHfyqn1RnhCf8g/s=`; also asserted in `SignerTest`.

## wordpress.org build

wordpress.org forbids self-updaters. `bin/build.sh --wporg` builds
`dist/tassyir-for-woocommerce-<version>-wporg.zip` from the same sources, but drops:
- `includes/Updater.php` and `includes/self-update.php` (the Composer `files` autoload that hooks it);
- `yahnis-elsts/plugin-update-checker`, Strauss and `vendor-prefixed/`;
- the readme's "Updates" bullet.

Slug and version are the same as the self-hosted zip. Listing images and screenshots live in
`.wordpress-org/`; the submission and SVN steps are in [`SUBMISSION.md`](SUBMISSION.md).
