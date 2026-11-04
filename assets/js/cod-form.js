/**
 * Tassyir COD Form — WooCommerce client (Tassyir for WooCommerce plugin).
 *
 * Ported from the Shopify theme app extension (extensions/cod-form in the
 * Tassyir backend repo): same fields, behaviour, offer cards and copy. What
 * differs is WordPress plumbing only:
 * - the browser talks to the shop's own REST routes (/wp-json/tassyir/v1/…),
 *   which call Tassyir server-side, signed; the browser never sees Tassyir;
 * - a WordPress nonce, fetched at load (cached pages carry stale ones), goes
 *   with every POST in the X-Tassyir-Nonce header;
 * - the product's variants are the WooCommerce product's (a variable
 *   product's variations); Tassyir's own variants are never adopted, since
 *   the plugin creates the WooCommerce order from these lines;
 * - after the order, the browser goes where the plugin says (WooCommerce's
 *   order-received page, or the merchant's URL), else the inline panel.
 *
 * ES2017-safe / no-transpile: only `var`, `function`, string concatenation
 * and Promises — no arrow functions, template literals, optional chaining
 * (?.) or nullish coalescing (??). It ships unbundled, with no build step.
 * Everything is wrapped in a single IIFE; the only globals are the caches
 * below, shared on purpose across multiple form instances on one page.
 *
 * Endpoints (same origin, under the root's data-tsy-rest base):
 *   GET  config
 *   GET  towns?province=<code>
 *   GET  product-fields?product=<WooCommerce product id>
 *   GET  nonce
 *   POST upload   (multipart, field "image")
 *   POST quote    (the server's price of the cart; see "quote" below)
 *   POST orders
 */
(function () {
  'use strict';

  // The REST base (…/wp-json/tassyir/v1/) printed on every form root.
  function restBase() {
    var root = document.querySelector('.tsy-cod-form[data-tsy-rest]');
    var base = root ? root.getAttribute('data-tsy-rest') : '/wp-json/tassyir/v1/';
    return base.charAt(base.length - 1) === '/' ? base : base + '/';
  }

  function restUrl(path) {
    var base = restBase();
    // Sites without pretty permalinks use ?rest_route=/tassyir/v1/: the
    // query string of `path` must then join with "&".
    if (base.indexOf('?') >= 0) return base + path.replace('?', '&');
    return base + path;
  }

  var CONFIG_URL = 'config';
  var TOWNS_URL = 'towns?province=';
  // `rules=1`: this script applies custom fields' "show only when". Without
  // it the server marks ruled fields optional and leaves the rule out.
  var FIELDS_URL = 'product-fields?rules=1&product=';
  var NONCE_URL = 'nonce';
  var UPLOAD_URL = 'upload';
  var ORDERS_URL = 'orders';
  var QUOTE_URL = 'quote';
  var NONCE_HEADER = 'X-Tassyir-Nonce';

  // The nonce of the POST routes, fetched once per page (never baked into
  // the HTML: a cached page would carry a stale one). `fresh` refetches it,
  // after the server said it expired.
  var noncePromise = null;
  function getNonce(fresh) {
    if (!noncePromise || fresh) {
      noncePromise = fetch(restUrl(NONCE_URL), { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
        .then(function (res) {
          if (!res.ok) throw new Error('nonce request failed');
          return res.json();
        })
        .then(function (data) {
          return data && data.nonce ? String(data.nonce) : '';
        })
        .catch(function () {
          noncePromise = null;
          return '';
        });
    }
    return noncePromise;
  }

  // POSTs with the nonce; once more with a fresh nonce when it was refused.
  function postWithNonce(path, init, retried) {
    return getNonce(!!retried).then(function (nonce) {
      var options = {};
      var key;
      for (key in init) {
        if (init.hasOwnProperty(key)) options[key] = init[key];
      }
      var headers = {};
      for (key in init.headers || {}) {
        if (init.headers.hasOwnProperty(key)) headers[key] = init.headers[key];
      }
      headers[NONCE_HEADER] = nonce;
      options.headers = headers;
      options.method = 'POST';
      options.credentials = 'same-origin';
      return fetch(restUrl(path), options).then(function (res) {
        if (res.status === 403 && !retried) {
          return res.clone().json().then(function (data) {
            if (data && data.code === 'tassyir_bad_nonce') return postWithNonce(path, init, true);
            return res;
          }, function () {
            return res;
          });
        }
        return res;
      });
    });
  }

  // A first-party id of this browser, for Tassyir's spam gate (same role as
  // the Shopify form's device signal). localStorage first, a cookie when
  // storage is blocked; never sent anywhere but this shop's own routes.
  var DEVICE_KEY = 'tsy_device_id';
  function getDeviceId() {
    var id = '';
    try {
      id = window.localStorage.getItem(DEVICE_KEY) || '';
    } catch (e) {
      id = '';
    }
    if (!id) {
      var match = document.cookie.match(/(?:^|;\s*)tsy_device_id=([A-Za-z0-9-]{8,64})/);
      id = match ? match[1] : '';
    }
    if (!/^[A-Za-z0-9-]{8,64}$/.test(id)) id = generateSubmissionId();
    try {
      window.localStorage.setItem(DEVICE_KEY, id);
    } catch (e) { /* storage blocked: the cookie below keeps it */ }
    document.cookie = DEVICE_KEY + '=' + id + '; path=/; max-age=31536000; SameSite=Lax';
    return id;
  }

  var UPLOAD_ICON_SVG =
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" aria-hidden="true">' +
    '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>' +
    '<polyline points="17 8 12 3 7 8"></polyline>' +
    '<line x1="12" y1="3" x2="12" y2="15"></line>' +
    '</svg>';

  var ARABIC_DIGIT_MAP = {
    '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4',
    '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9',
    '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4',
    '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9'
  };

  // ---- utils ----------------------------------------------------------

  // Mirrors the server's normalizePhoneNumber() just enough to validate;
  // the RAW value (not this normalized form) is what gets submitted — the
  // server re-normalizes and is the source of truth.
  function normalizePhoneDigits(raw) {
    var mapped = '';
    var s = String(raw || '');
    for (var i = 0; i < s.length; i++) {
      var ch = s.charAt(i);
      mapped += ARABIC_DIGIT_MAP.hasOwnProperty(ch) ? ARABIC_DIGIT_MAP[ch] : ch;
    }
    var digits = mapped.replace(/[\s\-.()]/g, '');
    if (digits.indexOf('00213') === 0) digits = '0' + digits.slice(5);
    else if (digits.indexOf('+213') === 0) digits = '0' + digits.slice(4);
    return digits;
  }

  function isValidPhone(raw) {
    return /^0[567]\d{8}$/.test(normalizePhoneDigits(raw));
  }

  function generateSubmissionId() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
      return window.crypto.randomUUID();
    }
    return 'sid-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
  }

  // U+00A0 NO-BREAK SPACE, deliberately not a plain space. A plain space is
  // bidi class WS, which splits "4 000" into two SEPARATE number runs; the
  // bidi algorithm then reorders those runs around the adjacent Arabic
  // currency, so the amount renders as "000 4" and reads as 0004. NBSP is
  // bidi class CS, so rule W4 folds it into ONE number run that stays intact
  // in both LTR and RTL paragraphs (and it never wraps mid-amount).
  var GROUP_SEPARATOR = '\u00A0';

  function formatMoney(amount, isRtl) {
    var grouped = String(Math.round(amount || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, GROUP_SEPARATOR);
    return isRtl ? grouped + ' دج' : grouped + ' DA';
  }

  function isTrue(value) {
    return value === 'true' || value === true;
  }

  // The Shopify form's theme-editor preview mode. In WordPress the block
  // editor shows the server-rendered markup without running this script, so
  // a live page is never a preview.
  function isDesignMode() {
    return false;
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  // ---- quote helpers (pure: no DOM, no network — unit-tested) ------------
  //
  // The summary of a shop that runs the server's pricing is the SERVER's quote
  // (POST /apps/tassyir/quote), never arithmetic done here. Everything below
  // only reads a quote answer; nothing computes a price.

  // Arabic fallbacks, overridden by the block's locale strings (the
  // data-tsy-i18n JSON, filled by Liquid from locales/*.json).
  var DEFAULT_I18N = {
    code_toggle: 'لديك كود خصم؟',
    code_placeholder: 'أدخل الكود',
    code_apply: 'تطبيق',
    code_remove: 'إزالة',
    code_applied: 'تم تطبيق الكود {code}: −{amount}',
    code_checking: 'جارٍ التحقق من الكود…',
    code_unavailable: 'تعذّر التحقق من الكود الآن، سيُراجَع عند إرسال الطلب.',
    reject_unknown: 'هذا الكود غير صحيح',
    reject_expired: 'هذا الكود انتهى',
    reject_not_started: 'هذا الكود لم يبدأ بعد',
    reject_used_up: 'تم استعمال هذا الكود بالكامل',
    reject_wrong_store: 'هذا الكود لا يخص هذا المتجر',
    reject_not_applicable: 'الكود لا ينطبق على سلتك',
    reject_beaten_by: 'عرض أفضل مطبّق بالفعل',
    reject_no_stacking: 'لا يجمع مع العرض المطبّق',
    reject_ineligible: 'هذا الكود غير متاح لطلبك',
    savings: 'وفّرت {amount}',
    nudge_free_one: 'أضف قطعة واحدة للتوصيل المجاني',
    nudge_free_two: 'أضف قطعتين للتوصيل المجاني',
    nudge_free_amount: 'أضف {amount} للتوصيل المجاني',
    nudge_next_one: 'أضف قطعة واحدة وتوفّر {saving} إضافية',
    nudge_next_two: 'أضف قطعتين وتوفّر {saving} إضافية',
    total_changed: 'تم تحديث السعر، المجموع الآن {total}. تحقق منه ثم اضغط على زر الطلب مجددًا.',
    offer_ended: 'انتهى أحد العروض قبل إرسال طلبك. المجموع المحدَّث {total}، اضغط على زر الطلب للتأكيد.',
    offer_ended_retry: 'انتهى أحد العروض قبل إرسال طلبك. تم تحديث السعر، راجع المجموع ثم اضغط على زر الطلب.',
    price_changed: 'تغيّر السعر، مجموع طلبك {total}.',
    code_not_applied: 'لم يُطبَّق الكود {code}.',
    // The form's own messages (the Shopify script had them inline, Arabic
    // only); the plugin sends them in the form's language.
    err_name: 'الاسم مطلوب',
    err_phone: 'رقم الهاتف غير صالح',
    err_phone2: 'رقم الهاتف الثاني غير صالح',
    err_province: 'الرجاء اختيار الولاية',
    err_town: 'الرجاء اختيار البلدية',
    err_address: 'العنوان مطلوب',
    err_required: '{label} مطلوب',
    err_upload: 'تعذر رفع الصورة ({status})، حاول مرة أخرى.',
    err_upload_network: 'تعذر رفع الصورة (شبكة)، حاول مرة أخرى.',
    err_network: 'تعذر إرسال الطلب، الرجاء المحاولة مرة أخرى.',
    err_too_many: 'محاولات كثيرة، الرجاء المحاولة لاحقاً.',
    err_check: 'الرجاء التحقق من المعلومات المدخلة.',
    err_generic: 'حدث خطأ ({detail})، الرجاء المحاولة مرة أخرى.'
  };

  // Every reason of the cart-quote `rejectedCodes` has a message; the
  // eligibility ones (audience, channel, wilaya, delivery method...) all read
  // "not available for your order".
  var REJECT_KEYS = {
    code_unknown: 'reject_unknown',
    code_expired: 'reject_expired',
    code_not_started: 'reject_not_started',
    code_used_up: 'reject_used_up',
    code_wrong_store: 'reject_wrong_store',
    code_not_applicable: 'reject_not_applicable',
    beaten_by: 'reject_beaten_by',
    no_stacking: 'reject_no_stacking'
  };

  function rejectionKey(reason) {
    return REJECT_KEYS.hasOwnProperty(reason) ? REJECT_KEYS[reason] : 'reject_ineligible';
  }

  // "{name}" placeholders; an unknown one is left as written.
  function fillTemplate(template, values) {
    return String(template == null ? '' : template).replace(/\{(\w+)\}/g, function (match, name) {
      return values && values.hasOwnProperty(name) ? String(values[name]) : match;
    });
  }

  // Case and spaces do not matter to the server; neither here.
  function codeKey(code) {
    return String(code == null ? '' : code).replace(/\s+/g, '').toUpperCase();
  }

  // The discount rows the summary lists: product and order class, one per
  // offer. Delivery is shown by the free-delivery chip, never as a row.
  function visibleDiscounts(quote) {
    var out = [];
    var rows = quote && quote.discounts ? quote.discounts : [];
    for (var i = 0; i < rows.length; i++) {
      if (rows[i].class === 'delivery') continue;
      if (!(Number(rows[i].amount) > 0)) continue;
      out.push({ title: rows[i].title, amount: Number(rows[i].amount), code: rows[i].code || null });
    }
    return out;
  }

  // What the code field says about the typed code, from one quote answer:
  // { state: 'none' | 'applied' | 'rejected' | 'unknown', amount?, reason? }.
  // 'unknown' = the answer neither applied nor rejected it (no verdict).
  function codeVerdict(quote, code) {
    var wanted = codeKey(code);
    if (!wanted) return { state: 'none' };
    if (!quote) return { state: 'unknown' };
    var rejected = quote.rejectedCodes || [];
    for (var i = 0; i < rejected.length; i++) {
      if (codeKey(rejected[i].code) === wanted) return { state: 'rejected', reason: rejected[i].reason };
    }
    var amount = 0;
    var applied = false;
    var rows = quote.discounts || [];
    for (var j = 0; j < rows.length; j++) {
      if (rows[j].code && codeKey(rows[j].code) === wanted) {
        applied = true;
        amount += Number(rows[j].amount) || 0;
      }
    }
    if (applied) return { state: 'applied', amount: amount };
    // The server accepted it silently but nothing changed the price.
    return { state: 'rejected', reason: 'code_not_applicable' };
  }

  // The one nudge, as text (or '' for none). `money` formats dinars.
  function describeNudge(nudge, i18n, money) {
    if (!nudge) return '';
    if (nudge.type === 'free_delivery') {
      // An amount threshold names the missing dinars; a quantity one, pieces.
      if (Number(nudge.amount) > 0) {
        return fillTemplate(i18n.nudge_free_amount, { amount: money(nudge.amount) });
      }
      if (Number(nudge.units) === 1) return i18n.nudge_free_one;
      if (Number(nudge.units) === 2) return i18n.nudge_free_two;
      return '';
    }
    if (nudge.type === 'next_tier' && Number(nudge.saving) > 0) {
      return fillTemplate(Number(nudge.units) === 1 ? i18n.nudge_next_one : i18n.nudge_next_two, {
        saving: money(nudge.saving)
      });
    }
    return '';
  }

  // A quote may only be sent back as `quoteToken` for the SAME inputs it
  // priced, before it expires (15 min): anything else is re-quoted first.
  function quoteIsUsable(quote, quotedKey, currentKey, nowMs) {
    if (!quote || !quote.quoteToken || quotedKey !== currentKey) return false;
    var expires = Date.parse(quote.expiresAt);
    // A little margin: the token must still be valid when the order lands.
    return isFinite(expires) && expires - nowMs > 20000;
  }

  // The item of an order line as the QUOTE takes it: product identity and
  // quantity only. The client never sends a price (the server refuses one).
  function quoteItemOf(item) {
    var out = { quantity: item.quantity };
    if (item.wooProductId) out.wooProductId = item.wooProductId;
    if (item.wooVariationId) out.wooVariationId = item.wooVariationId;
    if (item.sku) out.sku = item.sku;
    if (item.bundleId) out.bundleId = item.bundleId;
    return out;
  }

  // The form's strings: the JSON the plugin prints in data-tsy-i18n (the
  // form's language) over the Arabic fallbacks above. A missing or empty
  // entry keeps the fallback.
  function readI18n(root) {
    var merged = {};
    var key;
    for (key in DEFAULT_I18N) {
      if (DEFAULT_I18N.hasOwnProperty(key)) merged[key] = DEFAULT_I18N[key];
    }
    var given = {};
    try {
      given = JSON.parse(root.getAttribute('data-tsy-i18n') || '{}') || {};
    } catch (e) {
      given = {};
    }
    for (key in given) {
      if (given.hasOwnProperty(key) && typeof given[key] === 'string' && given[key]) {
        merged[key] = given[key];
      }
    }
    return merged;
  }

  // ---- shared config/towns/fields cache (intentionally global — one fetch
  // for every block instance on the page) --------------------------------

  // Retries a config fetch up to `attemptsLeft` times with a fixed backoff
  // between tries, before finally rejecting. Only used for the config gate:
  // it decides whether the whole form exists, so a transient blip/5xx on
  // flaky mobile networks shouldn't be fatal for the rest of the page load.
  function fetchConfigWithRetry(attemptsLeft) {
    return fetch(restUrl(CONFIG_URL), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (res) {
        if (!res.ok) throw new Error('config request failed');
        return res.json();
      })
      .catch(function (err) {
        if (attemptsLeft > 1) {
          return new Promise(function (resolve) {
            setTimeout(resolve, 1200);
          }).then(function () {
            return fetchConfigWithRetry(attemptsLeft - 1);
          });
        }
        throw err;
      });
  }

  function getConfig() {
    if (!window.__tsyCodConfig) {
      window.__tsyCodConfig = fetchConfigWithRetry(2).catch(function (err) {
        // Clear the memo on final failure so a later call (e.g. a second
        // block instance initializing after this one, or the theme editor
        // re-running init) gets a fresh attempt instead of being stuck
        // permanently rejected for the rest of the page's life.
        window.__tsyCodConfig = null;
        throw err;
      });
    }
    return window.__tsyCodConfig;
  }

  function getTowns(provinceCode) {
    window.__tsyTownsCache = window.__tsyTownsCache || new Map();
    var cache = window.__tsyTownsCache;
    if (cache.has(provinceCode)) return cache.get(provinceCode);
    var promise = fetch(restUrl(TOWNS_URL + encodeURIComponent(provinceCode)), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    }).then(function (res) {
      if (!res.ok) throw new Error('towns request failed');
      return res.json();
    }).catch(function (err) {
      // Same memo-clearing bug as getConfig: don't leave this province
      // permanently failed — reselecting it should retry.
      cache.delete(provinceCode);
      throw err;
    });
    cache.set(provinceCode, promise);
    return promise;
  }

  // Custom fields are per PRODUCT (not per variant). Tassyir finds the
  // product from the WooCommerce product id (Phase 1's product link).
  function getProductFields(productId) {
    window.__tsyFieldsCache = window.__tsyFieldsCache || {};
    var cache = window.__tsyFieldsCache;
    if (cache.hasOwnProperty(productId)) return cache[productId];
    var promise = fetch(restUrl(FIELDS_URL + encodeURIComponent(productId)), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    }).then(function (res) {
      if (!res.ok) throw new Error('product fields request failed');
      return res.json();
    }).catch(function (err) {
      delete cache[productId];
      throw err;
    });
    cache[productId] = promise;
    return promise;
  }

  // ---- per-form instance ------------------------------------------------

  function initForm(root) {
    var isRtl = (root.getAttribute('dir') || 'ltr') === 'rtl';
    var requireAddress = isTrue(root.getAttribute('data-require-address'));
    var enableStopdeskSetting = isTrue(root.getAttribute('data-enable-stopdesk'));
    var fireMetaPixel = isTrue(root.getAttribute('data-fire-meta-pixel'));
    var fireTikTokPixel = isTrue(root.getAttribute('data-fire-tiktok-pixel'));
    var shippingPendingText = root.getAttribute('data-shipping-pending-text') || '—';
    // Shown in the summary's shipping row and on the offer card when the
    // selected quantity-offer tier ships free (merchant setting).
    var freeShippingLabel = root.getAttribute('data-free-shipping-label') || 'توصيل مجاني';
    var deskAvailableLabel = root.getAttribute('data-desk-available-label') || '';
    var uploadLabel = root.getAttribute('data-upload-label') || 'اضغط لرفع صورة';
    var optionalLabel = root.getAttribute('data-optional-label') || '(اختياري)';
    var provincePlaceholder = root.getAttribute('data-province-placeholder') || '…';
    var townPlaceholder = root.getAttribute('data-town-placeholder') || '…';
    var bundleLabel = root.getAttribute('data-bundle-label') || 'اختر العرض المناسب';
    var bundleItemLabel = root.getAttribute('data-bundle-item-label') || 'القطعة';
    var showBundleSavings = isTrue(root.getAttribute('data-show-bundle-savings'));
    var bundleSavingsLabel = root.getAttribute('data-bundle-savings-label') || 'وفر';
    var blockId = root.getAttribute('data-block-id') || String(Math.random()).slice(2);
    // Quantity-offer cards on/off (merchant setting); off keeps the stepper.
    var showOffers = root.getAttribute('data-show-offers') !== 'false';
    var i18n = readI18n(root);

    function removeForm() {
      if (root.parentNode) root.parentNode.removeChild(root);
    }

    var productData;
    try {
      productData = JSON.parse(root.getAttribute('data-tsy-product') || '{}');
    } catch (e) {
      return removeForm();
    }
    if (!productData || !productData.variants || !productData.variants.length) {
      return removeForm();
    }

    var el = {
      bundle: root.querySelector('[data-tsy-bundle]'),
      variantOptions: root.querySelector('[data-tsy-variant-options]'),
      qty: root.querySelector('[data-tsy-qty-input]'),
      qtyDec: root.querySelector('[data-tsy-qty-decrement]'),
      qtyInc: root.querySelector('[data-tsy-qty-increment]'),
      name: root.querySelector('[data-tsy-name]'),
      phone: root.querySelector('[data-tsy-phone]'),
      phone2: root.querySelector('[data-tsy-phone2]'),
      province: root.querySelector('[data-tsy-province]'),
      town: root.querySelector('[data-tsy-town]'),
      home: root.querySelector('[data-tsy-delivery-home]'),
      stopdesk: root.querySelector('[data-tsy-delivery-stopdesk]'),
      stopdeskOption: root.querySelector('[data-tsy-stopdesk-option]'),
      deliveryTypeGroup: root.querySelector('[data-tsy-delivery-type-group]'),
      address: root.querySelector('[data-tsy-address]'),
      note: root.querySelector('[data-tsy-note]'),
      customFields: root.querySelector('[data-tsy-custom-fields]'),
      honeypot: root.querySelector('[data-tsy-honeypot]'),
      subtotalOut: root.querySelector('[data-tsy-summary-subtotal]'),
      subtotal2Out: root.querySelector('[data-tsy-summary-subtotal2]'),
      summaryVariant: root.querySelector('[data-tsy-summary-variant]'),
      shippingOut: root.querySelector('[data-tsy-summary-shipping]'),
      totalOut: root.querySelector('[data-tsy-summary-total]'),
      summary: root.querySelector('[data-tsy-summary]'),
      discounts: root.querySelector('[data-tsy-summary-discounts]'),
      savings: root.querySelector('[data-tsy-summary-savings]'),
      nudge: root.querySelector('[data-tsy-summary-nudge]'),
      codeBox: root.querySelector('[data-tsy-code]'),
      codeToggle: root.querySelector('[data-tsy-code-toggle]'),
      codePanel: root.querySelector('[data-tsy-code-panel]'),
      codeInput: root.querySelector('[data-tsy-code-input]'),
      codeApply: root.querySelector('[data-tsy-code-apply]'),
      codeStatus: root.querySelector('[data-tsy-code-status]'),
      successNote: root.querySelector('[data-tsy-success-note]'),
      error: root.querySelector('[data-tsy-error]'),
      submit: root.querySelector('[data-tsy-submit]'),
      submitLabel: root.querySelector('[data-tsy-submit-label]'),
      spinner: root.querySelector('[data-tsy-spinner]'),
      fields: root.querySelector('[data-tsy-form]'),
      success: root.querySelector('[data-tsy-success]'),
      orderId: root.querySelector('[data-tsy-order-id]')
    };

    var state = {
      submissionId: generateSubmissionId(),
      fees: {},
      // codeOns (string) -> town object from the last /towns fetch for the
      // currently selected province, so the delivery-type gate can read the
      // selected town's `stopdesk` flag without another request.
      townsByCode: {},
      // undefined = no town selected yet / no per-town data — never treated
      // as a block on its own, only `=== false` disables stopdesk.
      selectedTownStopdesk: undefined,
      lastShipping: 0,
      lastTotal: 0,
      submitting: false,
      // Merchant-defined custom fields (TEXT/IMAGE) for this product, and
      // the shopper's current values keyed by fieldId.
      customFields: [],
      customFieldValues: {},
      // Number of in-flight image uploads — submit stays disabled while > 0.
      uploadingCount: 0,
      // Active quantity-offer (bundle) for this product, or null. When set,
      // the offer cards ARE the purchase choice — the quantity stepper is
      // hidden and every submitted item carries this bundle's id.
      bundle: null,
      selectedBundleOptionIndex: 0,
      // One entry per unit in the selected option's quantity, each holding a
      // variant id — only populated (and only rendered as selects) when the
      // product has >1 variants AND the selected option's quantity > 1.
      bundleSlotVariants: [],
      // Selected variant, driven by the option pills (data-tsy-variant-options)
      // for multi-variant products, or the single implicit variant otherwise.
      selectedVariantId: null,
      // Copy of the selected variant's `options` array (aligned with
      // productData.optionNames), kept for resolving the next pill click.
      selectedOptions: []
    };

    // The server's quote (POST /apps/tassyir/quote), when this shop prices
    // with it. Until the first answer the form shows today's summary.
    var quoteState = {
      // null = not asked yet, 'on' = the server's pricing decides the order,
      // 'off' = it does not (or cannot for this product): today's summary and
      // no codes, no token, no further quote calls.
      mode: null,
      // The store has a LIVE code offer (the answer's `codesAvailable`): the
      // code field is drawn only then. Absent (an older backend) reads false.
      codesAvailable: false,
      // The product's custom fields/variants have settled, so the lines the
      // quote names are the ones the order will send.
      ready: false,
      seq: 0,
      timer: null,
      controller: null,
      // The last answer shown, and the inputs (JSON) it priced.
      shown: null,
      shownKey: null,
      pendingKey: null,
      // The inputs of the last COMPLETED attempt (an answer or a failure): the
      // same inputs are not asked again, so a failing call cannot loop.
      lastKey: null,
      // Inputs changed since `shown`: the old numbers stay, dimmed, until the
      // new quote lands.
      stale: false,
      // The code the buyer applied (sent with every quote and the submit).
      code: '',
      codePending: false,
      // A quote call failed: no verdict on the code, today's summary.
      failed: false
    };

    function findVariantById(id) {
      for (var i = 0; i < productData.variants.length; i++) {
        if (String(productData.variants[i].id) === String(id)) return productData.variants[i];
      }
      return null;
    }

    var initialVariant = findVariantById(productData.selectedVariantId) || productData.variants[0];
    state.selectedVariantId = initialVariant ? initialVariant.id : null;
    state.selectedOptions = initialVariant && initialVariant.options ? initialVariant.options.slice() : [];

    function getSelectedVariant() {
      return findVariantById(state.selectedVariantId) || productData.variants[0];
    }

    function getQuantity() {
      if (!el.qty) return 1;
      var n = parseInt(el.qty.value, 10);
      if (!isFinite(n) || n < 1) n = 1;
      return n > 999 ? 999 : n;
    }

    function getSelectedBundleOption() {
      if (!state.bundle || !state.bundle.options || !state.bundle.options.length) return null;
      return state.bundle.options[state.selectedBundleOptionIndex] || state.bundle.options[0];
    }

    // True when a quantity offer is active and its selected tier ships free.
    function isFreeShippingSelected() {
      if (!state.bundle) return false;
      var option = getSelectedBundleOption();
      return !!(option && option.freeShipping === true);
    }

    function isStopdeskSelected() {
      return !!(el.stopdesk && el.stopdesk.checked && !el.stopdesk.disabled);
    }

    function showError(message) {
      if (!el.error) return;
      el.error.textContent = message;
      el.error.hidden = false;
    }

    function clearError() {
      if (!el.error) return;
      el.error.hidden = true;
      el.error.textContent = '';
    }

    // Under an active bundle, the subtotal IS the selected tier's fixed
    // discount_price (never variant.price * quantity) — no strikethrough,
    // no savings math, exactly the storefront in-page-checkout contract.
    function recalcTotals() {
      applyCustomFieldVisibility();
      var subtotal;
      if (state.bundle) {
        var option = getSelectedBundleOption();
        subtotal = option ? Number(option.discount_price) || 0 : 0;
      } else {
        var variant = getSelectedVariant();
        subtotal = (variant ? variant.price / 100 : 0) * getQuantity();
      }

      var provinceCode = el.province ? el.province.value : '';
      var feeRow = provinceCode ? state.fees[provinceCode] : null;
      var shipping = 0;
      if (feeRow) {
        shipping = isStopdeskSelected()
          ? (feeRow.stopdesk != null ? feeRow.stopdesk : feeRow.home)
          : feeRow.home;
      }

      // A tier flagged free shipping waives the whole fee. Display only: the
      // server re-derives it from the stored bundle (the order is charged 0
      // and marked as an offer there), never from this total. Free shipping
      // never makes a province deliverable, so the province's own checks
      // (not-deliverable, no stopdesk) still run before this matters.
      var freeShipping = isFreeShippingSelected();
      if (freeShipping) shipping = 0;

      var total = subtotal + shipping;

      // A shop that prices with the server shows ITS quote: the numbers above
      // are only today's summary, used until the first quote lands and
      // whenever the quote is unavailable (and for shops that do not use it).
      if (quoteDrives()) {
        paintQuote();
      } else {
        state.lastShipping = shipping;
        state.lastTotal = total;

        if (el.subtotalOut) el.subtotalOut.textContent = formatMoney(subtotal, isRtl);
        if (el.subtotal2Out) el.subtotal2Out.textContent = formatMoney(subtotal, isRtl);
        updateSummaryVariantLabel();
        if (el.shippingOut) {
          el.shippingOut.textContent = freeShipping
            ? freeShippingLabel
            : (feeRow ? formatMoney(shipping, isRtl) : shippingPendingText);
          el.shippingOut.classList.toggle('tsy-is-free-shipping', freeShipping);
        }
        if (el.totalOut) el.totalOut.textContent = formatMoney(total, isRtl);
        clearQuoteExtras();
      }
      scheduleQuote(false);
    }

    // ---- the server's quote -------------------------------------------------

    function money(amount) {
      return formatMoney(amount, isRtl);
    }

    function quoteDrives() {
      return quoteState.mode === 'on' && !!quoteState.shown && !state.designPreview;
    }

    // Clears what only a quote draws (discount rows, savings, nudge).
    function clearQuoteExtras() {
      if (el.discounts) {
        el.discounts.innerHTML = '';
        el.discounts.hidden = true;
      }
      if (el.savings) {
        el.savings.textContent = '';
        el.savings.hidden = true;
      }
      if (el.nudge) {
        el.nudge.textContent = '';
        el.nudge.hidden = true;
      }
      if (el.summary) el.summary.classList.remove('tsy-is-quote-stale');
    }

    // Draws the summary from the quote answer on screen: the numbers are the
    // server's, nothing is computed here. The item row and the subtotal show
    // the catalogue subtotal; each offer is a discount row; delivery is the
    // quoted price, or the free-delivery chip with the waived fee struck.
    function paintQuote() {
      var q = quoteState.shown;
      var delivery = q.delivery;
      var free = !!(delivery && delivery.freeShipping);
      state.lastShipping = delivery ? Number(delivery.price) || 0 : 0;
      state.lastTotal = Number(q.total) || 0;

      if (el.subtotalOut) el.subtotalOut.textContent = money(q.subtotal);
      if (el.subtotal2Out) el.subtotal2Out.textContent = money(q.subtotal);
      updateSummaryVariantLabel();

      if (el.discounts) {
        var rows = visibleDiscounts(q);
        var html = '';
        for (var i = 0; i < rows.length; i++) {
          html +=
            '<div class="tsy-summary-row tsy-summary-discount">' +
              '<span class="tsy-summary-row-label">' + escapeHtml(rows[i].title) +
                (rows[i].code ? ' <span class="tsy-summary-code">' + escapeHtml(rows[i].code) + '</span>' : '') +
              '</span>' +
              '<span class="tsy-summary-row-value tsy-discount-value">−' + escapeHtml(money(rows[i].amount)) + '</span>' +
            '</div>';
        }
        el.discounts.innerHTML = html;
        el.discounts.hidden = rows.length === 0;
      }

      if (el.shippingOut) {
        if (!delivery) {
          el.shippingOut.textContent = shippingPendingText;
        } else if (free) {
          el.shippingOut.innerHTML =
            (Number(delivery.fee) > 0 ? '<s class="tsy-struck">' + escapeHtml(money(delivery.fee)) + '</s> ' : '') +
            escapeHtml(freeShippingLabel);
        } else {
          el.shippingOut.textContent = money(delivery.price);
        }
        el.shippingOut.classList.toggle('tsy-is-free-shipping', free);
      }
      if (el.totalOut) el.totalOut.textContent = money(q.total);

      if (el.savings) {
        var saved = Number(q.savings) || 0;
        el.savings.textContent = saved > 0 ? fillTemplate(i18n.savings, { amount: money(saved) }) : '';
        el.savings.hidden = !(saved > 0);
      }
      if (el.nudge) {
        var nudgeText = describeNudge(q.nudge, i18n, money);
        el.nudge.textContent = nudgeText;
        el.nudge.hidden = !nudgeText;
      }
      if (el.summary) el.summary.classList.toggle('tsy-is-quote-stale', quoteState.stale);
    }

    // The order lines the form would submit, as the submit and the quote both
    // take them (so the quote prices exactly what the order will carry).
    function buildOrderItems() {
      if (state.bundle) {
        // Bundle items never send a price - the server always re-derives
        // bundle pricing from the active ProductBundle.
        return buildBundleItems();
      }
      var variant = getSelectedVariant();
      var item = { quantity: getQuantity() };
      if (variant) {
        applyVariantIdentity(item, variant);
        item.title = productData.productTitle;
        item.price = variant.price / 100;
      }
      return [item];
    }

    function currentQuoteBody() {
      var items = buildOrderItems();
      var lines = [];
      for (var i = 0; i < items.length; i++) lines.push(quoteItemOf(items[i]));
      var body = { items: lines };
      var provinceCode = el.province && el.province.value ? parseInt(el.province.value, 10) : NaN;
      if (isFinite(provinceCode)) {
        body.provinceCode = provinceCode;
        body.stopdesk = isStopdeskSelected();
      }
      if (quoteState.code) body.codes = [quoteState.code];
      // Only a complete number: the token is bound to the buyer's phone, and a
      // partial one would never match the submit's.
      if (el.phone && isValidPhone(el.phone.value)) body.phone = el.phone.value.trim();
      return body;
    }

    function quoteKeyOf(body) {
      return JSON.stringify(body);
    }

    function postQuote(body) {
      var options = {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(body)
      };
      if (typeof AbortController === 'function') {
        if (quoteState.controller) quoteState.controller.abort();
        quoteState.controller = new AbortController();
        options.signal = quoteState.controller.signal;
      }
      return postWithNonce(QUOTE_URL, options).then(function (res) {
        return res.text().then(function (text) {
          var data = {};
          try { data = JSON.parse(text) || {}; } catch (e) { /* non-JSON body */ }
          return { status: res.status, ok: res.ok, data: data };
        });
      });
    }

    function setQuoteMode(mode) {
      quoteState.mode = mode;
      if (el.codeBox) el.codeBox.hidden = mode !== 'on' || !quoteState.codesAvailable;
    }

    // Debounced (about 300 ms) on every change of what the price depends on.
    // Does nothing in the theme editor, before the product's fields settled, or
    // once this shop is known not to use the server's quote. The same inputs
    // are never asked twice in a row (a failure included: no retry loop).
    function scheduleQuote(immediate) {
      if (!quoteState.ready || quoteState.mode === 'off' || state.designPreview || isDesignMode()) return;
      var key = quoteKeyOf(currentQuoteBody());
      if (key === quoteState.shownKey) {
        // Back to what the shown quote priced (e.g. a quantity stepped up and down).
        clearTimeout(quoteState.timer);
        if (quoteState.stale) {
          quoteState.stale = false;
          if (el.summary) el.summary.classList.remove('tsy-is-quote-stale');
        }
        return;
      }
      if (key === quoteState.lastKey || key === quoteState.pendingKey) return;
      quoteState.stale = !!quoteState.shown;
      if (quoteState.stale && el.summary) el.summary.classList.add('tsy-is-quote-stale');
      clearTimeout(quoteState.timer);
      quoteState.timer = setTimeout(function () {
        runQuote(false);
      }, immediate ? 0 : 300);
    }

    // Asks for the quote of the form as it is NOW. `force` asks even when the
    // shown quote priced these inputs (its token may be about to expire).
    function runQuote(force) {
      var body = currentQuoteBody();
      var key = quoteKeyOf(body);
      if (!force && key === quoteState.shownKey) return Promise.resolve(quoteState.shown);
      var seq = ++quoteState.seq;
      quoteState.pendingKey = key;
      return postQuote(body)
        .then(function (result) {
          // A newer request replaced this one.
          if (seq !== quoteState.seq) return null;
          quoteState.pendingKey = null;
          acceptQuote(result, key);
          return quoteState.shown;
        })
        .catch(function () {
          if (seq !== quoteState.seq) return null;
          quoteState.pendingKey = null;
          quoteFailed(key);
          return null;
        });
    }

    function acceptQuote(result, key) {
      var data = result.data || {};
      quoteState.codePending = false;
      quoteState.lastKey = key;
      if (!result.ok) return quoteFailed(key);
      quoteState.codesAvailable = data.codesAvailable === true;

      // Only a business whose orders the server prices has a quote to show;
      // for the others today's summary, no codes and no token stay.
      if (data.orderPricingMode !== 'on') {
        quoteState.shown = null;
        quoteState.shownKey = null;
        quoteState.stale = false;
        setQuoteMode('off');
        recalcTotals();
        return renderCodeStatus();
      }
      setQuoteMode('on');
      quoteState.failed = false;
      if (data.available !== true) {
        // This cart cannot be quoted (an unlinked product, a pack, ...): the
        // order is priced as before and so is the summary.
        quoteState.shown = null;
        quoteState.shownKey = null;
        quoteState.stale = false;
        if (data.reason === 'unlinked_items') setQuoteMode('off');
      } else {
        quoteState.shown = data;
        quoteState.shownKey = key;
        quoteState.stale = false;
      }
      recalcTotals();
      renderCodeStatus();
    }

    // The quote call failed (network, 4xx, 5xx, rate limit): today's summary,
    // no token. Never blocks the order.
    function quoteFailed(key) {
      quoteState.codePending = false;
      quoteState.lastKey = key;
      quoteState.failed = true;
      quoteState.shown = null;
      quoteState.shownKey = null;
      quoteState.stale = false;
      recalcTotals();
      renderCodeStatus();
    }

    // ---- the code field -----------------------------------------------------

    function renderCodeStatus() {
      if (!el.codeStatus) return;
      var text = '';
      var kind = '';
      if (quoteState.code) {
        if (quoteState.codePending) {
          text = i18n.code_checking;
          kind = 'pending';
        } else if (!quoteState.shown) {
          text = i18n.code_unavailable;
          kind = 'pending';
        } else {
          var verdict = codeVerdict(quoteState.shown, quoteState.code);
          if (verdict.state === 'applied') {
            text = fillTemplate(i18n.code_applied, { code: quoteState.code, amount: money(verdict.amount) });
            kind = 'ok';
          } else if (verdict.state === 'rejected') {
            text = i18n[rejectionKey(verdict.reason)];
            kind = 'error';
          }
        }
      }
      el.codeStatus.textContent = text;
      el.codeStatus.hidden = !text;
      el.codeStatus.className = 'tsy-code-status' + (kind ? ' is-' + kind : '');
      var remove = root.querySelector('[data-tsy-code-remove]');
      if (remove) remove.hidden = !quoteState.code;
    }

    // Applies the typed code: asks for the quote straight away (no debounce).
    function applyCodeFromInput() {
      var next = el.codeInput ? el.codeInput.value.trim() : '';
      quoteState.code = next;
      var key = quoteKeyOf(currentQuoteBody());
      if (key === quoteState.shownKey || key === quoteState.lastKey) {
        quoteState.codePending = false;
        return renderCodeStatus();
      }
      quoteState.codePending = !!next;
      renderCodeStatus();
      scheduleQuote(true);
    }

    function removeCode() {
      if (el.codeInput) el.codeInput.value = '';
      applyCodeFromInput();
    }

    // What is typed in the field when the buyer submits counts as applied.
    function syncCodeFromInput() {
      if (!el.codeInput) return;
      var typed = el.codeInput.value.trim();
      if (typed !== quoteState.code) quoteState.code = typed;
    }

    // The summary's secondary line under the product name: the selected
    // bundle option's title (primary color, 600) takes priority over the
    // plain variant title (muted, storefront default) when a bundle is
    // active; hidden entirely when neither applies (single-variant,
    // no-bundle product).
    function updateSummaryVariantLabel() {
      if (!el.summaryVariant) return;
      if (state.bundle) {
        var option = getSelectedBundleOption();
        var title = option && option.title ? option.title : '';
        el.summaryVariant.textContent = title;
        el.summaryVariant.hidden = !title;
        el.summaryVariant.className = 'tsy-summary-item-variant tsy-is-bundle-option';
        return;
      }
      if (productData.variants.length > 1) {
        var variant = getSelectedVariant();
        el.summaryVariant.textContent = variant ? variant.title : '';
        el.summaryVariant.hidden = false;
        el.summaryVariant.className = 'tsy-summary-item-variant';
        return;
      }
      el.summaryVariant.textContent = '';
      el.summaryVariant.hidden = true;
      el.summaryVariant.className = 'tsy-summary-item-variant';
    }

    // Quantity stepper is entirely hidden while a bundle is active — the
    // offer cards ARE the quantity choice (storefront parity).
    function updateQuantityVisibility() {
      var qtyField = root.querySelector('[data-tsy-field="quantity"]');
      if (qtyField) qtyField.hidden = !!state.bundle;
    }

    // Reads the currently selected town's `stopdesk` flag (from the last
    // /towns fetch for this province) into state, for updateStopdeskAvailability
    // to combine with the wilaya-level fee row gate.
    function updateSelectedTownStopdesk() {
      var code = el.town ? el.town.value : '';
      var town = code ? state.townsByCode[code] : null;
      state.selectedTownStopdesk = town ? town.stopdesk : undefined;
    }

    // Storefront parity: the home/stopdesk segmented control only appears
    // once a commune is selected (the whole group, not just the stopdesk
    // pill). Hidden again when the province changes (which clears the
    // town), falling back to "home" so a hidden control never leaves a
    // stopdesk fee applied.
    function updateDeliveryTypeVisibility() {
      if (!el.deliveryTypeGroup) return;
      var townSelected = !!(el.town && el.town.value);
      var show = townSelected && enableStopdeskSetting;
      el.deliveryTypeGroup.hidden = !show;
      if (!show && el.stopdesk && el.stopdesk.checked && el.home) {
        el.home.checked = true;
      }
    }

    // Theme-editor-only diagnostic for the product-fields lookup. Custom
    // fields AND quantity offers both hang off that one lookup, so when it
    // fails they both just... don't appear, which is indistinguishable from
    // 'this product has neither'. Shoppers never see this; it exists so a
    // merchant is not left guessing which of five silent gates tripped.
    function renderFieldsDiagnostic(data) {
      if (!isDesignMode() || !data) return;
      var reason = data.reason || data.bundleReason;
      if (!reason) return;

      var msg;
      if (reason === 'sku_missing') {
        msg = isRtl
          ? 'لا يوجد SKU على هذا المنتج في Shopify، لذا لا يمكن ربطه بمنتج Tassyir.'
          : 'This Shopify product has no SKU, so it cannot be matched to a Tassyir product.';
      } else if (reason === 'sku_unresolved') {
        msg = isRtl
          ? 'لم يتم العثور على منتج Tassyir بنفس SKU. الحقول المخصصة وعروض الكمية لن تظهر.'
          : 'No Tassyir product matches this SKU, so custom fields and quantity offers will not appear.';
      } else if (reason === 'bundle_missing_or_inactive') {
        msg = isRtl
          ? 'عرض الكمية المرتبط بهذا المنتج غير موجود أو غير مفعّل.'
          : 'The quantity offer attached to this product is missing or switched off.';
      } else if (reason === 'bundle_not_volume') {
        msg = isRtl
          ? 'نوع عرض الكمية غير مدعوم في هذا النموذج.'
          : 'That bundle type is not supported by this form.';
      } else if (reason === 'bundle_no_valid_options') {
        msg = isRtl
          ? 'عرض الكمية لا يحتوي على مستويات صالحة (كل مستوى يحتاج كمية وسعر).'
          : 'The quantity offer has no valid tiers - each tier needs a quantity and a price.';
      } else {
        return;
      }

      var notice = document.createElement('div');
      notice.className = 'tsy-cod-notice';
      notice.setAttribute('role', 'status');
      notice.setAttribute('dir', isRtl ? 'rtl' : 'ltr');
      notice.style.cssText =
        'margin:0 0 16px;padding:10px 12px;border:1px solid #cfd8e3;' +
        'background:#f4f7fb;color:#33475b;border-radius:8px;font-size:13px;' +
        'line-height:1.5;text-align:' + (isRtl ? 'right' : 'left') + ';';
      notice.textContent = 'ⓘ ' + msg;
      root.insertBefore(notice, root.firstChild);
    }

    // Shown ONLY inside the theme editor. When the config gate fails the live
    // storefront removes the form entirely (removeForm), which looked to
    // merchants like their edits were "reverting" — the editor happily rendered
    // a healthy-looking sample form while the live store showed nothing. This
    // banner makes the real reason (store disconnected / subscription inactive /
    // config unreachable) unmissable in the editor.
    function renderDisconnectedNotice(reason) {
      var msg;
      if (reason === 'no_store') {
        msg = isRtl
          ? 'لم يتم اختيار متجر Tassyir لهذا المتجر، لذا النموذج مخفي في متجرك المباشر. اختر المتجر من لوحة تحكم Tassyir.'
          : 'No Tassyir store is selected for this shop, so the form is hidden on your live store. Choose one in your Tassyir dashboard.';
      } else if (reason === 'inactive') {
        msg = isRtl
          ? 'اشتراك Tassyir غير نشط، لذا نموذج الطلب مخفي في متجرك المباشر. جدّد اشتراكك من لوحة تحكم Tassyir.'
          : 'Your Tassyir subscription is inactive, so the order form is hidden on your live store. Renew it from your Tassyir dashboard.';
      } else if (reason === 'error') {
        msg = isRtl
          ? 'تعذّر الوصول إلى Tassyir لتحميل النموذج. إذا كان متجرك مرتبطًا فهذا غالبًا مؤقت.'
          : 'Couldn’t reach Tassyir to load the form. If your store is connected, this is usually temporary.';
      } else {
        msg = isRtl
          ? 'هذا المتجر غير مرتبط بـ Tassyir، لذا نموذج الطلب مخفي في متجرك المباشر. أعد ربط متجر Shopify من لوحة تحكم Tassyir.'
          : 'This store isn’t connected to Tassyir, so the order form is hidden on your live store. Reconnect your Shopify store from your Tassyir dashboard.';
      }
      var notice = document.createElement('div');
      notice.className = 'tsy-cod-notice';
      notice.setAttribute('role', 'status');
      notice.setAttribute('dir', isRtl ? 'rtl' : 'ltr');
      notice.style.cssText =
        'margin:0 0 16px;padding:12px 14px;border:1px solid #f0c36d;' +
        'background:#fff8e6;color:#7a5b00;border-radius:8px;font-size:14px;' +
        'line-height:1.5;text-align:' + (isRtl ? 'right' : 'left') + ';';
      notice.textContent = '⚠ ' + msg;
      root.insertBefore(notice, root.firstChild);
    }

    // Inside the theme editor, an unlinked/unsubscribed shop (or an
    // unreachable backend) must NOT hide the block — the merchant would be
    // styling an invisible form. Render a static sample preview instead;
    // submit stays disabled so no editor click can create an order. Real
    // storefronts never take this path: there the form removes itself.
    function setupDesignPreview() {
      state.designPreview = true;
      state.fees = {
        16: { home: 600, stopdesk: 400, deliverable: true, stopdeskAvailable: true },
        31: { home: 800, stopdesk: 500, deliverable: true, stopdeskAvailable: true }
      };
      if (el.province) {
        el.province.innerHTML =
          '<option value="">' + escapeHtml(provincePlaceholder) + '</option>' +
          '<option value="16">' + (isRtl ? '16 – الجزائر' : '16 – Alger') + '</option>' +
          '<option value="31">' + (isRtl ? '31 – وهران' : '31 – Oran') + '</option>';
      }
      if (el.submit) el.submit.disabled = true;
      updateStopdeskAvailability();
      recalcTotals();
    }

    // The radio GROUP's visibility is a static, Liquid-rendered decision
    // (hidden entirely when `enable_stopdesk` is off — see the block
    // markup). This combines the selected wilaya's fee row (province-level)
    // with the selected town's `stopdesk` flag (commune-level, `=== false`
    // only — undefined/missing never blocks) to decide whether the
    // stopdesk OPTION itself is shown at all — storefront parity: towns
    // without stopdesk simply don't render the button, it isn't shown
    // disabled. Also hides when no province is selected yet. Falls back to
    // "home" when it flips to hidden while checked.
    function updateStopdeskAvailability() {
      var provinceCode = el.province ? el.province.value : '';
      var feeRow = provinceCode ? state.fees[provinceCode] : null;
      var allowed =
        enableStopdeskSetting &&
        !!feeRow &&
        !!feeRow.stopdeskAvailable &&
        state.selectedTownStopdesk !== false;
      if (el.stopdeskOption) el.stopdeskOption.hidden = !allowed;
      if (!el.stopdesk) return;
      el.stopdesk.disabled = !allowed;
      if (!allowed && el.stopdesk.checked && el.home) el.home.checked = true;
    }

    // ---- variant option pills ---------------------------------------------

    // Unique values for one option index, in first-appearance order across
    // productData.variants. Recomputed on demand (render + click) rather
    // than cached, so it always reflects the same deterministic order.
    //
    // The backend index-aligns each variant's `options` array to
    // `optionNames`; a variant that has no value for a given axis still
    // contributes an entry at that index, but as an EMPTY STRING rather
    // than being omitted — e.g. a leftover "base" variant alongside real
    // s/m x red/blue combos carries `options: ['', '']`. That '' means
    // "no value for this axis", not a real selectable choice, so it must
    // never turn into a blank unlabelled pill. The variant itself is left
    // completely untouched (still resolvable/orderable via
    // findVariantById/findVariantByOptions) — only the pill VALUE list
    // excludes it.
    function getOptionValues(optIndex) {
      var values = [];
      for (var i = 0; i < productData.variants.length; i++) {
        var variantOptions = productData.variants[i].options;
        var value = variantOptions ? variantOptions[optIndex] : null;
        if (value == null) continue;
        if (String(value).trim() === '') continue;
        if (values.indexOf(value) === -1) values.push(value);
      }
      return values;
    }

    function optionsMatch(variantOptions, target) {
      if (!variantOptions || variantOptions.length !== target.length) return false;
      for (var i = 0; i < target.length; i++) {
        if (variantOptions[i] !== target[i]) return false;
      }
      return true;
    }

    function findVariantByOptions(target) {
      for (var i = 0; i < productData.variants.length; i++) {
        if (optionsMatch(productData.variants[i].options, target)) return productData.variants[i];
      }
      return null;
    }

    function findFirstVariantWithOptionValue(optIndex, value) {
      for (var i = 0; i < productData.variants.length; i++) {
        var variant = productData.variants[i];
        if (variant.options && variant.options[optIndex] === value) return variant;
      }
      return null;
    }

    function findFirstAvailableVariantWithOptionValue(optIndex, value) {
      for (var i = 0; i < productData.variants.length; i++) {
        var variant = productData.variants[i];
        if (variant.available !== false && variant.options && variant.options[optIndex] === value) return variant;
      }
      return null;
    }

    // A pill is "unavailable" when swapping just this option's value (with
    // every other currently-selected option held fixed) leads nowhere
    // in-stock: either the exact combination exists and is sold out, or no
    // exact combination exists yet and no variant anywhere carries this
    // value while being available. Generalized over the "base" options array
    // so both the top-level pills (state.selectedOptions) and each bundle
    // slot's independent pills (that slot's own selected options) can share
    // the same availability rule.
    function isValueAvailableFrom(baseOptions, optIndex, value) {
      var candidate = baseOptions.slice();
      candidate[optIndex] = value;
      var exact = findVariantByOptions(candidate);
      if (exact) return exact.available !== false;
      return !!findFirstAvailableVariantWithOptionValue(optIndex, value);
    }

    function isValueAvailable(optIndex, value) {
      return isValueAvailableFrom(state.selectedOptions, optIndex, value);
    }

    function selectVariantOption(optIndex, value) {
      var nextOptions = state.selectedOptions.slice();
      nextOptions[optIndex] = value;

      var resolved = findVariantByOptions(nextOptions);

      if (!resolved) {
        // No variant exists for this exact combination (the other groups
        // haven't been narrowed to match yet) — fall back to any variant
        // carrying the clicked value, syncing every other group to match it.
        resolved = findFirstVariantWithOptionValue(optIndex, value);
      } else if (resolved.available === false) {
        // Exact combination exists but is sold out — prefer an available
        // variant that still carries the clicked value, syncing the rest.
        var availableFallback = findFirstAvailableVariantWithOptionValue(optIndex, value);
        if (availableFallback) resolved = availableFallback;
      }

      if (!resolved) return;

      state.selectedVariantId = resolved.id;
      state.selectedOptions = resolved.options ? resolved.options.slice() : nextOptions;

      renderVariantOptions();
      if (state.bundle) {
        seedBundleSlotVariants();
        paintBundle();
      }
      recalcTotals();
    }

    function wireVariantPill(button) {
      button.addEventListener('click', function () {
        var optIndex = parseInt(button.getAttribute('data-tsy-opt'), 10);
        var valIndex = parseInt(button.getAttribute('data-tsy-val'), 10);
        if (!isFinite(optIndex) || !isFinite(valIndex)) return;
        var values = getOptionValues(optIndex);
        var value = values[valIndex];
        if (value == null) return;
        selectVariantOption(optIndex, value);
      });
    }

    // Whether ANY pill group would render for the current productData — the
    // single source of truth for both renderVariantOptions()'s own early-outs
    // and for deciding the [data-tsy-field="variant"] field's visibility from
    // elsewhere (paintBundle()/renderBundle() below, once a bundle is active
    // and takes over that field). No-ops for single-variant products and for
    // Shopify's degenerate no-options placeholder ("Title" / "Default Title").
    function variantOptionGroupsExist() {
      var optionNames = productData.optionNames || [];
      var isDegenerate = optionNames.length === 1 && optionNames[0] === 'Title';
      if (productData.variants.length < 2 || !optionNames.length || isDegenerate) return false;
      for (var optIndex = 0; optIndex < optionNames.length; optIndex++) {
        if (getOptionValues(optIndex).length) return true;
      }
      return false;
    }

    // The wrapping [data-tsy-field="variant"] field is hidden whenever there
    // is nothing to pick (single-variant product) OR a bundle is active — the
    // bundle's own per-slot pills (buildBundleSlotSelectsMarkup) take over
    // variant selection entirely, and showing both would be two competing
    // pickers. Called from both renderVariantOptions() and paintBundle()/
    // renderBundle() so whichever of the two runs LAST in a given flow always
    // leaves the field in the right state — see the call-ordering note above
    // renderBundle().
    function updateVariantFieldVisibility() {
      var variantField = root.querySelector('[data-tsy-field="variant"]');
      if (!variantField) return;
      variantField.hidden = !variantOptionGroupsExist() || !!state.bundle;
    }

    // One pill group per product option (size, color, ...), storefront
    // product-page parity. Static product data — safe to call once during
    // init, independent of the /config fetch.
    function renderVariantOptions() {
      if (!el.variantOptions) return;
      var optionNames = productData.optionNames || [];
      var isDegenerate = optionNames.length === 1 && optionNames[0] === 'Title';

      if (productData.variants.length < 2 || !optionNames.length || isDegenerate) {
        el.variantOptions.innerHTML = '';
        updateVariantFieldVisibility();
        return;
      }

      var html = '';
      var anyGroup = false;
      for (var optIndex = 0; optIndex < optionNames.length; optIndex++) {
        var values = getOptionValues(optIndex);
        if (!values.length) continue;
        anyGroup = true;

        html += '<div class="tsy-variant-group" data-tsy-variant-group="' + optIndex + '">';
        html += '<div class="tsy-variant-group-label">' + escapeHtml(optionNames[optIndex]) + '</div>';
        html += '<div class="tsy-variant-pills">';
        for (var vi = 0; vi < values.length; vi++) {
          var value = values[vi];
          var isSelected = state.selectedOptions[optIndex] === value;
          var isAvailable = isValueAvailable(optIndex, value);
          html +=
            '<button type="button" class="tsy-variant-pill' +
            (isSelected ? ' is-selected' : '') +
            (isAvailable ? '' : ' is-unavailable') +
            '" data-tsy-opt="' + optIndex + '" data-tsy-val="' + vi + '">' +
            escapeHtml(value) +
            '</button>';
        }
        html += '</div></div>';
      }

      if (!anyGroup) {
        el.variantOptions.innerHTML = '';
        updateVariantFieldVisibility();
        return;
      }

      el.variantOptions.innerHTML = html;
      updateVariantFieldVisibility();

      var buttons = el.variantOptions.querySelectorAll('[data-tsy-opt]');
      for (var bi = 0; bi < buttons.length; bi++) {
        wireVariantPill(buttons[bi]);
      }
    }

    // ---- custom fields (TEXT / IMAGE) --------------------------------------

    function refreshSubmitAvailability() {
      if (el.submit) el.submit.disabled = state.submitting || state.uploadingCount > 0;
    }

    function customFieldErrorNode(fieldId) {
      return root.querySelector('[data-tsy-cf-error="' + fieldId + '"]');
    }

    function showCustomFieldError(fieldId, message) {
      var node = customFieldErrorNode(fieldId);
      if (!node) return;
      node.textContent = message;
      node.hidden = false;
    }

    function clearCustomFieldError(fieldId) {
      var node = customFieldErrorNode(fieldId);
      if (!node) return;
      node.hidden = true;
      node.textContent = '';
    }

    function buildCustomFieldMarkup(field) {
      var mark = isCustomFieldRequired(field)
        ? ' <span class="tsy-req">*</span>'
        : ' <span class="tsy-optional">' + escapeHtml(optionalLabel) + '</span>';
      var labelHtml =
        '<label for="tsy-cf-' + field.id + '">' + escapeHtml(field.label) + mark + '</label>';

      if (field.type === 'IMAGE') {
        return (
          '<div class="tsy-field tsy-custom-field" data-tsy-custom-field="' + field.id + '">' +
            labelHtml +
            '<div class="tsy-cf-upload">' +
              '<input type="file" accept="image/*" id="tsy-cf-' + field.id + '" data-tsy-cf-file="' + field.id + '" class="tsy-cf-file-input">' +
              '<label class="tsy-cf-upload-box" for="tsy-cf-' + field.id + '" data-tsy-cf-upload-box="' + field.id + '">' +
                '<span class="tsy-cf-upload-icon" aria-hidden="true">' + UPLOAD_ICON_SVG + '</span>' +
                '<span class="tsy-cf-upload-text">' + escapeHtml(uploadLabel) + '</span>' +
              '</label>' +
              '<div class="tsy-cf-preview" data-tsy-cf-preview="' + field.id + '" hidden>' +
                '<img class="tsy-cf-preview-img" data-tsy-cf-preview-img="' + field.id + '" alt="">' +
                '<button type="button" class="tsy-cf-remove" data-tsy-cf-remove="' + field.id + '" aria-label="Remove">×</button>' +
              '</div>' +
            '</div>' +
            '<p class="tsy-cf-error" data-tsy-cf-error="' + field.id + '" hidden></p>' +
          '</div>'
        );
      }

      return (
        '<div class="tsy-field tsy-custom-field" data-tsy-custom-field="' + field.id + '">' +
          labelHtml +
          '<input type="text" id="tsy-cf-' + field.id + '" data-tsy-cf-text="' + field.id + '" maxlength="2000" placeholder="' + escapeHtml(field.label) + '">' +
          '<p class="tsy-cf-error" data-tsy-cf-error="' + field.id + '" hidden></p>' +
        '</div>'
      );
    }

    function handleCustomFieldUpload(field, file) {
      var uploadBox = root.querySelector('[data-tsy-cf-upload-box="' + field.id + '"]');
      var preview = root.querySelector('[data-tsy-cf-preview="' + field.id + '"]');
      var previewImg = root.querySelector('[data-tsy-cf-preview-img="' + field.id + '"]');

      clearCustomFieldError(field.id);
      delete state.customFieldValues[field.id];
      state.uploadingCount++;
      refreshSubmitAvailability();

      var formData = new FormData();
      formData.append('image', file);

      postWithNonce(UPLOAD_URL, { body: formData })
        .then(function (res) {
          return res
            .json()
            .catch(function () { return {}; })
            .then(function (data) {
              return { ok: res.ok, status: res.status, data: data || {} };
            });
        })
        .then(function (result) {
          state.uploadingCount = Math.max(0, state.uploadingCount - 1);
          refreshSubmitAvailability();
          if (!result.ok || !result.data || !result.data.imageUrl) {
            // Status embedded for supportability — turns any user screenshot
            // of the error into a usable diagnostic.
            showCustomFieldError(field.id, fillTemplate(i18n.err_upload, { status: result.status }));
            return;
          }
          state.customFieldValues[field.id] = result.data.imageUrl;
          // The preview is the local file, not the uploaded copy: the shop
          // page loads nothing from another host.
          if (previewImg && window.URL && typeof window.URL.createObjectURL === 'function') {
            previewImg.src = window.URL.createObjectURL(file);
          }
          if (preview) preview.hidden = false;
          if (uploadBox) uploadBox.hidden = true;
        })
        .catch(function () {
          state.uploadingCount = Math.max(0, state.uploadingCount - 1);
          refreshSubmitAvailability();
          showCustomFieldError(field.id, i18n.err_upload_network);
        });
    }

    function wireCustomField(field) {
      if (field.type === 'IMAGE') {
        var fileInput = root.querySelector('[data-tsy-cf-file="' + field.id + '"]');
        var uploadBox = root.querySelector('[data-tsy-cf-upload-box="' + field.id + '"]');
        var preview = root.querySelector('[data-tsy-cf-preview="' + field.id + '"]');

        if (fileInput) {
          fileInput.addEventListener('change', function () {
            var file = fileInput.files && fileInput.files[0];
            if (!file) return;
            handleCustomFieldUpload(field, file);
          });
        }

        var removeBtn = root.querySelector('[data-tsy-cf-remove="' + field.id + '"]');
        if (removeBtn) {
          removeBtn.addEventListener('click', function () {
            delete state.customFieldValues[field.id];
            if (preview) preview.hidden = true;
            if (uploadBox) uploadBox.hidden = false;
            if (fileInput) fileInput.value = '';
            clearCustomFieldError(field.id);
          });
        }
        return;
      }

      var textInput = root.querySelector('[data-tsy-cf-text="' + field.id + '"]');
      if (textInput) {
        textInput.addEventListener('input', function () {
          state.customFieldValues[field.id] = textInput.value;
          clearCustomFieldError(field.id);
        });
      }
    }

    function renderCustomFields(fields) {
      state.customFields = fields || [];
      if (!el.customFields || !state.customFields.length) return;

      var html = '';
      for (var i = 0; i < state.customFields.length; i++) {
        html += buildCustomFieldMarkup(state.customFields[i]);
      }
      el.customFields.innerHTML = html;

      for (var j = 0; j < state.customFields.length; j++) {
        wireCustomField(state.customFields[j]);
      }
      applyCustomFieldVisibility();
    }

    // ---- "show only when" -------------------------------------------------
    // A field may carry one rule from product-fields: an option and some of
    // its values. It shows when ANY unit being ordered picks one of them. The
    // Tassyir checkout and the server's order check (ruleRequiresAnswer) ask
    // the same question; all three must agree or a valid order is refused.

    function normToken(value) {
      return String(value == null ? '' : value).trim().toLowerCase();
    }

    function ruleOptionIndex(rule) {
      var names = productData.optionNames || [];
      for (var i = 0; i < names.length; i++) {
        if (normToken(names[i]) === normToken(rule.option)) return i;
      }
      return -1;
    }

    // A rule this form cannot apply (its values are gone, or the options on
    // screen do not include it): the field shows and is optional, the
    // server's answer for the same case.
    function ruleApplies(rule) {
      return !!(rule && rule.values && rule.values.length && ruleOptionIndex(rule) >= 0);
    }

    // The picks of every unit being ordered: each slot of a quantity offer
    // when its units are chosen one by one (buildBundleItems' own test), else
    // the one selected variant.
    function orderedPicks() {
      var option = state.bundle ? getSelectedBundleOption() : null;
      var qty = option ? option.quantity : 1;
      if (
        state.bundle &&
        productData.variants.length > 1 &&
        qty > 1 &&
        state.bundleSlotVariants.length === qty
      ) {
        var picks = [];
        for (var i = 0; i < state.bundleSlotVariants.length; i++) {
          var variant = findVariantById(state.bundleSlotVariants[i]);
          picks.push(variant && variant.options ? variant.options : []);
        }
        return picks;
      }
      return [state.selectedOptions || []];
    }

    function isCustomFieldShown(field) {
      var rule = field.showWhen;
      if (!ruleApplies(rule)) return true;
      var index = ruleOptionIndex(rule);
      var picks = orderedPicks();
      for (var p = 0; p < picks.length; p++) {
        var picked = picks[p][index];
        if (picked == null) continue;
        for (var v = 0; v < rule.values.length; v++) {
          if (normToken(rule.values[v]) === normToken(picked)) return true;
        }
      }
      return false;
    }

    function isCustomFieldRequired(field) {
      if (!field.isRequired) return false;
      return !field.showWhen || ruleApplies(field.showWhen);
    }

    // Re-run after every change of pick (recalcTotals follows each one).
    function applyCustomFieldVisibility() {
      for (var i = 0; i < state.customFields.length; i++) {
        var field = state.customFields[i];
        var node = root.querySelector('[data-tsy-custom-field="' + field.id + '"]');
        if (!node) continue;
        var shown = isCustomFieldShown(field);
        node.hidden = !shown;
        if (!shown) clearCustomFieldError(field.id);
      }
    }

    // ---- quantity-offer (bundle) cards -------------------------------------

    // Seeds every unit slot with the currently selected top variant — called
    // whenever the selected option (and therefore slot count) changes, and
    // whenever the top variant select changes while a bundle is active.
    function seedBundleSlotVariants() {
      var option = getSelectedBundleOption();
      var qty = option ? option.quantity : 1;
      var variant = getSelectedVariant();
      var vid = variant ? variant.id : (productData.variants[0] ? productData.variants[0].id : null);
      state.bundleSlotVariants = [];
      for (var i = 0; i < qty; i++) {
        state.bundleSlotVariants.push(vid);
      }
    }

    function buildBundleOptionMarkup(option, index, isSelected) {
      var title = option.title ? option.title : ('×' + option.quantity);

      // Landing-checkout-style savings display (merchant-toggleable): the
      // compare-at total is unit price × tier quantity, exactly like the
      // storefront computes it. Display only — the server reprices anyway.
      var savingsPill = '';
      var originalOut = '';
      if (showBundleSavings) {
        var variant = getSelectedVariant();
        var unitPrice = variant ? variant.price / 100 : 0;
        var originalTotal = unitPrice * option.quantity;
        var savings = originalTotal - option.discount_price;
        if (savings > 0) {
          var pct = Math.round((savings / originalTotal) * 100);
          savingsPill =
            '<span class="tsy-bundle-savings">' +
            escapeHtml(bundleSavingsLabel) + ' ' + formatMoney(savings, isRtl) +
            (pct > 0 ? ' (' + pct + '%)' : '') +
            '</span>';
          originalOut =
            '<span class="tsy-bundle-option-original">' +
            formatMoney(originalTotal, isRtl) +
            '</span>';
        }
      }

      var freeShippingBadge = option.freeShipping === true
        ? '<span class="tsy-bundle-free-shipping">' + escapeHtml(freeShippingLabel) + '</span>'
        : '';

      return (
        '<label class="tsy-bundle-option' + (isSelected ? ' is-selected' : '') + '" data-tsy-bundle-option="' + index + '">' +
          '<input type="radio" name="tsy-bundle-' + blockId + '" value="' + index + '" data-tsy-bundle-radio' + (isSelected ? ' checked' : '') + '>' +
          '<span class="tsy-bundle-radio-circle" aria-hidden="true"></span>' +
          '<span class="tsy-bundle-option-main">' +
            savingsPill +
            '<span class="tsy-bundle-option-title">' + escapeHtml(title) + '</span>' +
            freeShippingBadge +
          '</span>' +
          '<span class="tsy-bundle-price-col">' +
            '<span class="tsy-bundle-option-price">' + formatMoney(option.discount_price, isRtl) + '</span>' +
            originalOut +
          '</span>' +
        '</label>'
      );
    }

    // One slot block per unit in the selected tier's quantity, each holding
    // one pill GROUP per product option — mirrors renderVariantOptions()'s
    // markup exactly (same class names) so the existing .tsy-variant-* CSS
    // applies with no new styling beyond the slot wrapper itself. Rendered
    // for every quantity, including 1: a single-unit tier still needs a way
    // to pick which variant that one unit is (this is the quantity-1 fix —
    // previously nothing rendered here at quantity 1 AND the main variant
    // field was independently hidden while a bundle was active, leaving no
    // picker anywhere).
    // Title-based picker for products whose variants carry no option values.
    // Kept deliberately: it is the only way to choose between variants that
    // differ by name alone.
    function buildBundleSlotTitleMarkup(slotIndex) {
      if (productData.variants.length < 2) return '';
      var selectId = 'tsy-bundle-slot-' + blockId + '-' + slotIndex;
      var html = '<div class="tsy-field tsy-bundle-slot">';
      html += '<label for="' + selectId + '">' + escapeHtml(bundleItemLabel) + ' ' + (slotIndex + 1) + '</label>';
      html += '<select id="' + selectId + '" data-tsy-bundle-slot="' + slotIndex + '">';
      for (var v = 0; v < productData.variants.length; v++) {
        var variant = productData.variants[v];
        var selected = String(variant.id) === String(state.bundleSlotVariants[slotIndex]) ? ' selected' : '';
        var disabled = variant.available === false ? ' disabled' : '';
        html += '<option value="' + variant.id + '"' + selected + disabled + '>' + escapeHtml(variant.title) + '</option>';
      }
      html += '</select></div>';
      return html;
    }

    function buildBundleSlotMarkup(slotIndex) {
      var optionNames = productData.optionNames || [];
      var slotVariant = findVariantById(state.bundleSlotVariants[slotIndex]);
      var slotOptions = slotVariant && slotVariant.options ? slotVariant.options : [];

      var groupsHtml = '';
      var anyGroup = false;
      for (var optIndex = 0; optIndex < optionNames.length; optIndex++) {
        var values = getOptionValues(optIndex);
        if (!values.length) continue;
        anyGroup = true;

        groupsHtml += '<div class="tsy-variant-group" data-tsy-variant-group="' + optIndex + '">';
        groupsHtml += '<div class="tsy-variant-group-label">' + escapeHtml(optionNames[optIndex]) + '</div>';
        groupsHtml += '<div class="tsy-variant-pills">';
        for (var vi = 0; vi < values.length; vi++) {
          var value = values[vi];
          var isSelected = slotOptions[optIndex] === value;
          var isAvailable = isValueAvailableFrom(slotOptions, optIndex, value);
          groupsHtml +=
            '<button type="button" class="tsy-variant-pill' +
            (isSelected ? ' is-selected' : '') +
            (isAvailable ? '' : ' is-unavailable') +
            '" data-tsy-slot="' + slotIndex + '" data-tsy-opt="' + optIndex + '" data-tsy-val="' + vi + '">' +
            escapeHtml(value) +
            '</button>';
        }
        groupsHtml += '</div></div>';
      }

      // No option metadata at all (variants told apart only by name, which
      // Tassyir allows) — pills can't express that, so fall back to the
      // title picker this used to be. Without it a multi-variant product
      // would lose its picker entirely now that the main variant field is
      // hidden whenever a bundle is active.
      if (!anyGroup) return buildBundleSlotTitleMarkup(slotIndex);

      return (
        '<div class="tsy-field tsy-bundle-slot">' +
          '<label>' + escapeHtml(bundleItemLabel) + ' ' + (slotIndex + 1) + '</label>' +
          '<div class="tsy-bundle-slot-groups">' + groupsHtml + '</div>' +
        '</div>'
      );
    }

    function buildBundleSlotSelectsMarkup() {
      var slotsHtml = '';
      for (var i = 0; i < state.bundleSlotVariants.length; i++) {
        slotsHtml += buildBundleSlotMarkup(i);
      }
      // Nothing to show (e.g. Shopify's degenerate no-options placeholder) —
      // render no container at all rather than an empty one.
      if (!slotsHtml) return '';
      return '<div class="tsy-bundle-slots" data-tsy-bundle-slots>' + slotsHtml + '</div>';
    }

    function wireBundleOptionRadios() {
      if (!el.bundle) return;
      var radios = el.bundle.querySelectorAll('[data-tsy-bundle-radio]');
      for (var i = 0; i < radios.length; i++) {
        radios[i].addEventListener('change', handleBundleOptionChange);
      }
    }

    function handleBundleOptionChange(e) {
      var idx = parseInt(e.target.value, 10);
      if (!state.bundle || !isFinite(idx) || idx < 0 || idx >= state.bundle.options.length) return;
      state.selectedBundleOptionIndex = idx;
      seedBundleSlotVariants();
      paintBundle();
      recalcTotals();
    }

    function wireBundleSlotPills() {
      if (!el.bundle) return;
      var buttons = el.bundle.querySelectorAll('[data-tsy-slot]');
      for (var i = 0; i < buttons.length; i++) {
        wireBundleSlotPill(buttons[i]);
      }
      // Slots that fell back to the title picker (no option metadata).
      var selects = el.bundle.querySelectorAll('[data-tsy-bundle-slot]');
      for (var s = 0; s < selects.length; s++) {
        wireBundleSlotSelect(selects[s]);
      }
    }

    function wireBundleSlotSelect(select) {
      select.addEventListener('change', function () {
        var idx = parseInt(select.getAttribute('data-tsy-bundle-slot'), 10);
        if (!isFinite(idx)) return;
        state.bundleSlotVariants[idx] = select.value;
        recalcTotals();
      });
    }

    // Resolves the slot's variant the same way selectVariantOption() resolves
    // the top-level selection (exact match, else first variant carrying the
    // clicked value, else an available fallback for a sold-out exact match),
    // but scoped entirely to this ONE slot — clicking a slot's pill never
    // touches state.selectedVariantId or any other slot.
    function selectBundleSlotOption(slotIndex, optIndex, value) {
      var optionNames = productData.optionNames || [];
      var currentVariant = findVariantById(state.bundleSlotVariants[slotIndex]);
      var current;
      if (currentVariant && currentVariant.options) {
        current = currentVariant.options.slice();
      } else {
        current = [];
        for (var i = 0; i < optionNames.length; i++) current.push('');
      }

      var next = current.slice();
      next[optIndex] = value;

      var resolved = findVariantByOptions(next);
      if (!resolved) {
        resolved = findFirstVariantWithOptionValue(optIndex, value);
      } else if (resolved.available === false) {
        var availableFallback = findFirstAvailableVariantWithOptionValue(optIndex, value);
        if (availableFallback) resolved = availableFallback;
      }
      if (!resolved) return;

      state.bundleSlotVariants[slotIndex] = resolved.id;
      paintBundle();
      recalcTotals();
    }

    function wireBundleSlotPill(button) {
      button.addEventListener('click', function () {
        var slotIndex = parseInt(button.getAttribute('data-tsy-slot'), 10);
        var optIndex = parseInt(button.getAttribute('data-tsy-opt'), 10);
        var valIndex = parseInt(button.getAttribute('data-tsy-val'), 10);
        if (!isFinite(slotIndex) || !isFinite(optIndex) || !isFinite(valIndex)) return;
        var values = getOptionValues(optIndex);
        var value = values[valIndex];
        if (value == null) return;
        selectBundleSlotOption(slotIndex, optIndex, value);
      });
    }

    // Repaints the offer cards + the per-slot variant pills for the
    // currently selected option, without touching totals. Always renders the
    // slot pills for a multi-variant product with real options — including
    // at quantity 1 — and re-derives the main variant field's visibility
    // last, so this is also the function that "wins" call ordering whenever
    // it runs after renderVariantOptions() (see updateVariantFieldVisibility
    // above).
    function paintBundle() {
      if (!el.bundle || !state.bundle) return;

      var html = '<div class="tsy-bundle-label">' + escapeHtml(bundleLabel) + '</div>';
      html += '<div class="tsy-bundle-options" data-tsy-bundle-options>';
      for (var i = 0; i < state.bundle.options.length; i++) {
        html += buildBundleOptionMarkup(state.bundle.options[i], i, i === state.selectedBundleOptionIndex);
      }
      html += '</div>';

      if (productData.variants.length > 1) {
        html += buildBundleSlotSelectsMarkup();
      }

      el.bundle.innerHTML = html;
      wireBundleOptionRadios();
      wireBundleSlotPills();
      updateVariantFieldVisibility();
    }

    // Entry point once /apps/tassyir/product-fields resolves. `bundle` is
    // `null`/absent for products without an active VOLUME offer — the
    // container stays hidden and nothing else about the form changes.
    //
    // Call-ordering note: during init this runs AFTER renderVariantOptions()
    // (see the bottom of this file — renderVariantOptions() runs
    // synchronously at wiring time, renderBundle() only once the async
    // product-fields fetch resolves). renderVariantOptions() unconditionally
    // shows the main variant field whenever the product has real options,
    // with no knowledge of a bundle that hasn't loaded yet — so both branches
    // here re-derive the field's visibility themselves (paintBundle() does it
    // for the active-bundle branch, directly below for the cleared/no-bundle
    // branch) rather than assuming renderVariantOptions() already got it
    // right.
    function renderBundle(bundle) {
      var hasOptions = !!(bundle && bundle.options && bundle.options.length);
      state.bundle = hasOptions ? bundle : null;

      if (!state.bundle) {
        if (el.bundle) el.bundle.hidden = true;
        updateQuantityVisibility();
        updateVariantFieldVisibility();
        recalcTotals();
        return;
      }

      var defaultIndex = 0;
      for (var i = 0; i < state.bundle.options.length; i++) {
        if (state.bundle.options[i].isDefault) {
          defaultIndex = i;
          break;
        }
      }
      state.selectedBundleOptionIndex = defaultIndex;
      seedBundleSlotVariants();

      if (el.bundle) el.bundle.hidden = false;
      updateQuantityVisibility();
      paintBundle();
      recalcTotals();
    }

    // ---- wiring ---------------------------------------------------------

    // Pills are static product data (no network dependency), so this runs
    // once here rather than inside getConfig().then(...).
    renderVariantOptions();

    if (el.qty) {
      el.qty.addEventListener('input', recalcTotals);
      el.qty.addEventListener('change', function () {
        el.qty.value = String(getQuantity());
        recalcTotals();
      });
    }
    if (el.qtyDec && el.qty) {
      el.qtyDec.addEventListener('click', function () {
        el.qty.value = String(Math.max(1, getQuantity() - 1));
        recalcTotals();
      });
    }
    if (el.qtyInc && el.qty) {
      el.qtyInc.addEventListener('click', function () {
        el.qty.value = String(Math.min(999, getQuantity() + 1));
        recalcTotals();
      });
    }
    if (el.phone) {
      // Live input constraint: digits only, max 10, local 0-prefixed form.
      // Pasted "+213…"/"00213…" and Arabic-Indic digits are normalized by
      // normalizePhoneDigits first; a leading 5/6/7 (typed without the 0)
      // gets the 0 auto-prepended. Prefix validity (05/06/07) is still
      // enforced by validate() + the server — this only shapes the input.
      el.phone.addEventListener('input', function () {
        var digits = normalizePhoneDigits(el.phone.value).replace(/\D/g, '');
        if (digits && '567'.indexOf(digits.charAt(0)) >= 0) {
          digits = '0' + digits;
        }
        if (digits.length > 10) digits = digits.slice(0, 10);
        if (el.phone.value !== digits) el.phone.value = digits;
        // The quote's token is bound to the buyer's phone: ask again once a
        // complete number is typed (and when it stops being one).
        scheduleQuote(false);
      });
    }
    if (el.home) el.home.addEventListener('change', recalcTotals);
    if (el.stopdesk) el.stopdesk.addEventListener('change', recalcTotals);

    if (el.province) {
      el.province.addEventListener('change', function () {
        var provinceCode = el.province.value;
        state.townsByCode = {};
        if (el.town) {
          el.town.innerHTML = '<option value="">' + escapeHtml(townPlaceholder) + '</option>';
          el.town.disabled = true;
        }
        updateSelectedTownStopdesk();
        updateStopdeskAvailability();
        updateDeliveryTypeVisibility();
        recalcTotals();
        if (!provinceCode) return;

        if (state.designPreview) {
          if (el.town) {
            el.town.innerHTML =
              '<option value="">' + escapeHtml(townPlaceholder) + '</option><option value="1">' +
              (isRtl ? 'معاينة' : 'Preview') +
              '</option>';
            el.town.disabled = false;
          }
          return;
        }

        getTowns(provinceCode)
          .then(function (data) {
            // Discard stale responses: fast province switching on a slow
            // connection can let an older province's fetch resolve after a
            // newer selection is already in place.
            if (el.province.value !== provinceCode) return;
            if (!data || data.active === false || !el.town) return;
            var towns = data.towns || [];
            var options = '<option value="">' + escapeHtml(townPlaceholder) + '</option>';
            state.townsByCode = {};
            for (var i = 0; i < towns.length; i++) {
              var town = towns[i];
              state.townsByCode[String(town.codeOns)] = town;
              var label = isRtl && town.arabicName ? town.arabicName : town.name;
              // Storefront parity: desk-equipped towns get a "(مكتب متوفر)"
              // suffix in the dropdown; towns without one are never hidden.
              if (town.stopdesk && deskAvailableLabel) {
                label += ' (' + deskAvailableLabel + ')';
              }
              options += '<option value="' + town.codeOns + '">' + escapeHtml(label) + '</option>';
            }
            el.town.innerHTML = options;
            el.town.disabled = false;
            updateSelectedTownStopdesk();
            updateStopdeskAvailability();
            recalcTotals();
          })
          .catch(function () {
            // Leave the town select empty/disabled; retry by reselecting the province.
          });
      });
    }

    if (el.town) {
      el.town.addEventListener('change', function () {
        updateSelectedTownStopdesk();
        updateStopdeskAvailability();
        updateDeliveryTypeVisibility();
        recalcTotals();
      });
    }

    if (el.submit) el.submit.addEventListener('click', handleSubmit);

    // The collapsed code field: a link opens it, Enter or the button applies.
    // It only exists (is only shown) for a shop whose orders the server
    // prices; see setQuoteMode.
    if (el.codeToggle) {
      el.codeToggle.addEventListener('click', function () {
        if (el.codePanel) el.codePanel.hidden = false;
        el.codeToggle.hidden = true;
        if (el.codeInput) el.codeInput.focus();
      });
    }
    if (el.codeInput) {
      el.codeInput.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' || event.keyCode === 13) {
          event.preventDefault();
          applyCodeFromInput();
        }
      });
    }
    if (el.codeApply) el.codeApply.addEventListener('click', applyCodeFromInput);
    var codeRemove = root.querySelector('[data-tsy-code-remove]');
    if (codeRemove) codeRemove.addEventListener('click', removeCode);

    // ---- validation + submit --------------------------------------------

    function focusAndFail(target, message) {
      showError(message);
      if (target && typeof target.focus === 'function') target.focus();
      return false;
    }

    function validate() {
      if (!el.name || !el.name.value.trim()) return focusAndFail(el.name, i18n.err_name);
      if (!el.phone || !isValidPhone(el.phone.value)) return focusAndFail(el.phone, i18n.err_phone);
      if (el.phone2 && el.phone2.value.trim() && !isValidPhone(el.phone2.value)) {
        return focusAndFail(el.phone2, i18n.err_phone2);
      }
      if (!el.province || !el.province.value) return focusAndFail(el.province, i18n.err_province);
      if (!el.town || !el.town.value) return focusAndFail(el.town, i18n.err_town);
      if (el.address && requireAddress && !el.address.value.trim()) {
        return focusAndFail(el.address, i18n.err_address);
      }
      for (var i = 0; i < state.customFields.length; i++) {
        var field = state.customFields[i];
        if (!isCustomFieldRequired(field) || !isCustomFieldShown(field)) continue;
        var value = state.customFieldValues[field.id];
        if (!value || !String(value).trim()) {
          var target =
            root.querySelector('[data-tsy-cf-text="' + field.id + '"]') ||
            root.querySelector('[data-tsy-cf-upload-box="' + field.id + '"]');
          return focusAndFail(target, fillTemplate(i18n.err_required, { label: field.label }));
        }
      }
      return true;
    }

    // Attaches the WooCommerce identity of the line onto an item that already
    // has quantity (and bundleId, for bundle items) set: the product, the
    // variation of a variable product, its SKU, and the values the customer
    // picked for attributes the variation leaves open ("Any …").
    function applyVariantIdentity(item, variant) {
      item.wooProductId = productData.productId;
      if (variant.wooVariationId) item.wooVariationId = variant.wooVariationId;
      if (variant.sku) item.sku = variant.sku;
      if (variant.attributes) item.attributes = variant.attributes;
    }

    // Merges the per-slot variant picks into one item per distinct variant
    // (quantity = how many slots picked it), each carrying the active
    // bundle's id. Order of distinct variants follows first appearance
    // among the slots, not object-key iteration order (variant ids are
    // numeric strings, which some engines reorder as object keys).
    function buildBundleItems() {
      var option = getSelectedBundleOption();
      var qty = option ? option.quantity : 1;
      var items = [];

      if (productData.variants.length > 1 && qty > 1 && state.bundleSlotVariants.length === qty) {
        var counts = {};
        var order = [];
        for (var i = 0; i < state.bundleSlotVariants.length; i++) {
          var vid = state.bundleSlotVariants[i];
          if (!counts.hasOwnProperty(vid)) {
            counts[vid] = 0;
            order.push(vid);
          }
          counts[vid]++;
        }
        for (var j = 0; j < order.length; j++) {
          var variant = findVariantById(order[j]);
          var slotItem = { quantity: counts[order[j]], bundleId: state.bundle.id };
          if (variant) {
            applyVariantIdentity(slotItem, variant);
            slotItem.title = productData.productTitle;
          }
          items.push(slotItem);
        }
        return items;
      }

      var singleVariant = getSelectedVariant();
      var singleItem = { quantity: qty, bundleId: state.bundle.id };
      if (singleVariant) {
        applyVariantIdentity(singleItem, singleVariant);
        singleItem.title = productData.productTitle;
      }
      items.push(singleItem);
      return items;
    }

    function buildPayload() {
      // Bundle items never send a price (the server re-derives bundle
      // pricing); see buildOrderItems, shared with the quote.
      var items = buildOrderItems();

      var customFields = [];
      for (var i = 0; i < state.customFields.length; i++) {
        var field = state.customFields[i];
        if (!isCustomFieldShown(field)) continue;
        var value = state.customFieldValues[field.id];
        if (value && String(value).trim()) {
          customFields.push({ fieldId: field.id, value: String(value) });
        }
      }

      var payload = {
        name: el.name.value.trim(),
        phone: el.phone.value.trim(),
        provinceCode: parseInt(el.province.value, 10),
        townOns: parseInt(el.town.value, 10),
        stopdesk: isStopdeskSelected(),
        submissionId: state.submissionId,
        items: items,
        // Always sent, even as [] — the server enforces required-field
        // coverage for this product against whatever is (or isn't) here.
        customFields: customFields,
        // Only the fields this form showed are above, so the server may hold
        // a ruled field to the same rule (see isCustomFieldShown).
        customFieldRules: true
      };
      if (el.phone2 && el.phone2.value.trim()) payload.phone2 = el.phone2.value.trim();
      if (el.address && el.address.value.trim()) payload.address = el.address.value.trim();
      if (el.note && el.note.value.trim()) payload.note = el.note.value.trim();
      payload.deviceId = getDeviceId();
      // The campaign the visitor arrived with (utm_*, ad ids, the ad network
      // derived from a click id), kept by attribution.js. Optional: a failure
      // here must never stop an order. Not part of the quote body — it has no
      // effect on pricing and would change the quote cache key.
      try {
        var attribution = window.tassyirAttribution && window.tassyirAttribution.read();
        if (attribution) payload.attribution = attribution;
      } catch (e) { /* attribution is optional */ }
      // Honeypot: only ever populated by bots — sending it lets the server
      // reject through the same validation path as any other bad request.
      if (el.honeypot && el.honeypot.value) payload.website = el.honeypot.value;
      // The code and the price promise, only for a shop the server prices. The
      // token goes back only for the SAME inputs it priced and before it
      // expires (anything else is re-quoted first, see ensureQuoteForSubmit);
      // the server ignores a token it cannot match and prices the order now.
      if (quoteState.mode === 'on') {
        if (quoteState.code) payload.codes = [quoteState.code];
        var quotedKey = quoteKeyOf(currentQuoteBody());
        if (quoteIsUsable(quoteState.shown, quoteState.shownKey, quotedKey, Date.now())) {
          payload.quoteToken = quoteState.shown.quoteToken;
        }
      }
      return payload;
    }

    // Before the order is sent, the quote on screen must be for the form as it
    // IS (a quote still in flight, an expired token, a code typed but not
    // applied). It is asked again if not; when the fresh total is not the one
    // the buyer was looking at, the new price is shown and the order waits for
    // a second click. Never blocks on a failing quote: that sends the order
    // without a token (today's behaviour).
    function ensureQuoteForSubmit() {
      if (quoteState.mode !== 'on') return Promise.resolve({ stop: false });
      syncCodeFromInput();
      var key = quoteKeyOf(currentQuoteBody());
      if (quoteIsUsable(quoteState.shown, quoteState.shownKey, key, Date.now())) {
        return Promise.resolve({ stop: false });
      }
      clearTimeout(quoteState.timer);
      var seen = state.lastTotal;
      var timeout = new Promise(function (resolve) {
        setTimeout(function () { resolve(null); }, 4000);
      });
      return Promise.race([runQuote(true), timeout]).then(function () {
        var fresh = quoteState.shown;
        if (fresh && quoteIsUsable(fresh, quoteState.shownKey, key, Date.now())) {
          if (Math.round(Number(fresh.total)) !== Math.round(Number(seen))) {
            showError(fillTemplate(i18n.total_changed, { total: money(fresh.total) }));
            return { stop: true };
          }
        }
        return { stop: false };
      });
    }

    // The server refused the order because an offer ran out (or was deleted)
    // between the quote and the submit; nothing was created. Its answer holds
    // the cart priced again: show it and let the buyer confirm (same
    // submissionId: no order exists to duplicate).
    function handleOfferConflict(data) {
      var fresh = data && data.quote;
      var key = quoteKeyOf(currentQuoteBody());
      if (fresh && typeof fresh.total === 'number' && fresh.quoteToken) {
        fresh.available = true;
        fresh.orderPricingMode = 'on';
        quoteState.shown = fresh;
        quoteState.shownKey = key;
        quoteState.lastKey = key;
        quoteState.stale = false;
        quoteState.failed = false;
        recalcTotals();
        renderCodeStatus();
        showError(fillTemplate(i18n.offer_ended, { total: money(fresh.total) }));
        return;
      }
      showError(i18n.offer_ended_retry);
      runQuote(true);
    }

    function setSubmitting(isSubmitting) {
      state.submitting = isSubmitting;
      refreshSubmitAvailability();
      if (el.spinner) el.spinner.hidden = !isSubmitting;
      if (el.submitLabel) el.submitLabel.style.visibility = isSubmitting ? 'hidden' : 'visible';
    }

    function handleSubmit() {
      if (state.submitting || state.uploadingCount > 0) return;
      clearError();
      // The theme editor's preview iframe can render a fully "healthy"
      // linked-shop form (setupDesignPreview only runs for the
      // unlinked/inactive/error paths) — a click here would create a real
      // order in the merchant's dashboard. Never rely on `disabled` alone:
      // refreshSubmitAvailability() re-derives it from submitting/upload
      // state only and knows nothing about design mode, so it can
      // re-enable the button. Block at the source instead.
      if (isDesignMode()) {
        showError(
          isRtl
            ? 'لا يمكن إرسال طلبات حقيقية من داخل محرر القوالب — هذا استعراض فقط.'
            : 'Orders can’t be placed from inside the theme editor preview.',
        );
        return;
      }
      if (!validate()) return;

      setSubmitting(true);
      var payload;
      ensureQuoteForSubmit()
        .then(function (gate) {
          if (gate.stop) {
            setSubmitting(false);
            return null;
          }
          payload = buildPayload();
          return postOrder(payload);
        })
        .then(function (result) {
          if (!result) return result;
          // An API from before "show only when" refuses the unknown key.
          // Send once more without it: that API never applied rules anyway.
          if (result.status === 400 && /customFieldRules/.test(result.raw || '')) {
            delete payload.customFieldRules;
            return postOrder(payload);
          }
          return result;
        })
        .then(function (result) {
          // null: the order was held back for the buyer to see a new price.
          if (!result) return;
          if (result.ok && result.data && result.data.success) handleSuccess(result.data);
          else handleErrorResponse(result.status, result.data, result.raw);
        })
        .catch(function () {
          showError(i18n.err_network);
          setSubmitting(false);
        });
    }

    function postOrder(payload) {
      return postWithNonce(ORDERS_URL, {
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(payload)
      }).then(function (res) {
        // Read as text first so a non-JSON error body (an infrastructure
        // error page rather than our API's JSON) can still be surfaced.
        return res.text().then(function (text) {
          var data = {};
          try { data = JSON.parse(text) || {}; } catch (e) { /* non-JSON body */ }
          return { status: res.status, ok: res.ok, data: data, raw: text };
        });
      });
    }

    // Navigates after a short delay so the async pixel queues (fbq/ttq)
    // fired just before this call get a chance to flush first.
    function navigateTo(url) {
      setTimeout(function () {
        window.location.href = url;
      }, 400);
    }

    function handleSuccess(data) {
      setSubmitting(false);
      if (el.fields) el.fields.hidden = true;
      if (el.success) el.success.hidden = false;
      if (el.orderId && data.orderId) el.orderId.textContent = '#' + data.orderId;

      var total = data.total != null ? data.total : state.lastTotal;
      var shippingPrice = data.shippingPrice != null ? data.shippingPrice : state.lastShipping;

      // The order is created either way; the buyer is told when the price is
      // not the one they were quoted (priceChanged) or the code did not apply.
      var note = '';
      if (data.priceChanged) {
        note = fillTemplate(i18n.price_changed, { total: money(total) });
      } else if (data.rejectedCodes && data.rejectedCodes.length) {
        note = fillTemplate(i18n.code_not_applied, { code: data.rejectedCodes[0].code });
      }
      if (el.successNote && note) {
        el.successNote.textContent = note;
        el.successNote.hidden = false;
      }

      // Purchase only for a real order: it carries Tassyir's quote id as its
      // event id, so the browser event and Tassyir's server-side (CAPI) event
      // deduplicate. A submission Tassyir held has none and fires nothing (no
      // order exists; the page looks the same either way).
      var eventId = data.eventId ? String(data.eventId) : '';
      if (eventId) {
        document.dispatchEvent(new CustomEvent('tassyir:cod:purchase', {
          detail: {
            orderId: data.orderId,
            eventId: eventId,
            total: total,
            shippingPrice: shippingPrice,
            currency: 'DZD',
            priceChanged: data.priceChanged === true,
            rejectedCodes: data.rejectedCodes || []
          }
        }));
        if (fireMetaPixel && typeof window.fbq === 'function') {
          window.fbq('track', 'Purchase', { value: total, currency: 'DZD' }, { eventID: eventId });
        }
        if (fireTikTokPixel && window.ttq && typeof window.ttq.track === 'function') {
          window.ttq.track('CompletePayment', { value: total, currency: 'DZD' }, { event_id: eventId });
        }
      }

      // Idempotency key only rotates after a confirmed success — a retry
      // after a failed/interrupted submit must reuse the same submissionId.
      state.submissionId = generateSubmissionId();

      // The plugin says where to go: WooCommerce's order-received page or
      // the merchant's URL (settings), for an order and for a held submission
      // alike. No `redirect` (the inline mode) keeps the inline panel above.
      if (data.redirect && /^https?:\/\//.test(String(data.redirect))) {
        navigateTo(String(data.redirect));
      }
    }

    function extractMessage(data) {
      if (!data) return null;
      if (typeof data.message === 'string') return data.message;
      if (Array.isArray(data.message)) return data.message.join(', ');
      return null;
    }

    function handleErrorResponse(status, data, raw) {
      setSubmitting(false);
      if (status === 404 || (status === 403 && !(data && data.code === 'tassyir_bad_nonce'))) {
        // Shop unlinked or subscription inactive — nothing the shopper can
        // fix; remove the form like the inactive-config path does.
        return removeForm();
      }
      // An offer ran out between the quote and the submit: no order exists.
      if (status === 409 && data && (data.code === 'OFFER_LIMIT_REACHED' || data.code === 'OFFER_GONE')) {
        return handleOfferConflict(data);
      }
      if (status === 429) return showError(i18n.err_too_many);
      if (status === 400) return showError(extractMessage(data) || i18n.err_check);
      // Status (and any server message) embedded for supportability — a user
      // screenshot of this banner is enough to identify the failing layer.
      // When the body isn't our API's JSON (no message), show a snippet of the
      // raw body instead: infrastructure error pages identify their emitter.
      var serverMessage = extractMessage(data);
      var detail = serverMessage;
      if (!detail && raw) {
        detail = String(raw).replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 120);
      }
      showError(fillTemplate(i18n.err_generic, { detail: status + (detail ? ': ' + detail : '') }));
    }

    // ---- init -------------------------------------------------------------

    getConfig()
      .then(function (config) {
        if (!config || config.active !== true) {
          if (isDesignMode()) {
            renderDisconnectedNotice(config && config.reason);
            return setupDesignPreview();
          }
          return removeForm();
        }

        state.fees = config.fees || {};

        if (el.province) {
          var options = '<option value="">' + escapeHtml(provincePlaceholder) + '</option>';
          var provinces = config.provinces || [];
          for (var i = 0; i < provinces.length; i++) {
            var province = provinces[i];
            var feeRow = state.fees[province.code];
            var label = isRtl && province.arabicName ? province.arabicName : province.name;
            var disabled = feeRow && feeRow.deliverable === false ? ' disabled' : '';
            options += '<option value="' + province.code + '"' + disabled + '>' + escapeHtml(label) + '</option>';
          }
          el.province.innerHTML = options;
        }

        // No province is selected yet, so this disables the stopdesk radio
        // (forcing "home") until the shopper picks one.
        updateStopdeskAvailability();
        recalcTotals();

        // Custom fields are skipped entirely in the theme editor preview
        // (no real shop/product context to resolve them against) and when
        // no variant carries a SKU (nothing to resolve the product from).
        if (!isDesignMode()) {
          getProductFields(productData.productId)
            .then(function (data) {
              if (!data || data.active === false) {
                // A shop that is not active has nothing to quote.
                quoteState.mode = 'off';
                return;
              }
              // Tassyir's own variants (data.variants) are NOT adopted here,
              // unlike the Shopify form: the plugin creates the WooCommerce
              // order from these lines, so the customer picks a WooCommerce
              // variation. Tassyir maps it through Phase 1's product link.
              renderFieldsDiagnostic(data);
              renderCustomFields(data.fields || []);
              renderBundle(showOffers ? data.bundle || null : null);
            })
            .catch(function () {
              // Non-fatal: the form still works without custom fields
              // and without a bundle — normal per-variant purchase.
            })
            .then(function () {
              // The lines the order will send are settled: from here the
              // server's quote may be asked (the first answer also says
              // whether this shop uses it at all).
              if (quoteState.mode !== 'off') {
                quoteState.ready = true;
                scheduleQuote(false);
              }
            });
        }
      })
      .catch(function () {
        if (isDesignMode()) {
          renderDisconnectedNotice('error');
          return setupDesignPreview();
        }
        removeForm();
      });
  }

  // The block renders one <script src> tag per instance and browsers
  // execute every copy, so init() can run more than once on a page (plus
  // the shopify:section:load re-run below). Without this guard each run
  // would attach its own submit listener (each with its own submissionId)
  // — one click, N orders. A freshly-rendered root never has the attribute
  // yet, so it still initializes; a root that already ran is skipped.
  function initRoot(root) {
    if (root.getAttribute('data-tsy-init') === '1') return;
    root.setAttribute('data-tsy-init', '1');
    try {
      initForm(root);
    } catch (e) {
      // Isolate one broken instance (bad/missing product JSON, unexpected
      // markup, ...) so a single throw doesn't abort the loop and silently
      // kill every other block instance on the page. Storefront asset —
      // deliberately no console noise beyond this comment.
    }
  }

  // `scope` narrows the search to one freshly-rendered section instead of
  // rescanning the whole document — used by the shopify:section:load
  // handler below. Defaults to the whole document on normal page load.
  function init(scope) {
    var searchRoot = scope || document;
    var roots = searchRoot.querySelectorAll('.tsy-cod-form');
    for (var i = 0; i < roots.length; i++) {
      initRoot(roots[i]);
    }
    // Some themes render the block as the section's own root element
    // rather than a descendant of it, in which case the querySelectorAll
    // above finds nothing and the scope element itself must be checked.
    if (searchRoot !== document && searchRoot.classList && searchRoot.classList.contains('tsy-cod-form')) {
      initRoot(searchRoot);
    }
  }

  // Test seam: this file ships unbundled, so a harness (see
  // src/integrations/shopify/storefront-proxy/cod-form-quote.spec.ts) loads it
  // in a bare context and reads the pure quote helpers from here. Inert unless
  // the harness sets the flag before loading; a storefront never does.
  if (window.__TSY_COD_TEST__ === true) {
    window.__tsyCodTestHooks = {
      rejectionKey: rejectionKey,
      fillTemplate: fillTemplate,
      codeKey: codeKey,
      visibleDiscounts: visibleDiscounts,
      codeVerdict: codeVerdict,
      describeNudge: describeNudge,
      quoteIsUsable: quoteIsUsable,
      quoteItemOf: quoteItemOf,
      DEFAULT_I18N: DEFAULT_I18N,
      formatMoney: formatMoney
    };
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      init();
    });
  } else {
    init();
  }

})();
