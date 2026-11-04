/*
 * Tassyir for WooCommerce — campaign attribution capture.
 *
 * Runs on every front-end page (an ad may land on the home page or a category
 * page, long before a COD form shows) and keeps the campaign the visitor
 * arrived with in THEIR browser for 30 days, so the COD form can send it with
 * the order. It runs in the browser because shops sit behind page caches.
 *
 * The rules are a port of Tassyir's storefront attribution core:
 *   - utm_* and campaign/ad id parameters, first matching alias wins;
 *   - unexpanded ad-platform macros ({{x}}, {x}, [x], __X__) are ignored;
 *   - when a URL carries only an ad network's click id, the NETWORK is derived
 *     (source/medium). The click id itself is never stored or sent;
 *   - the last campaign wins as a whole (new UTMs replace the stored ones).
 *
 * Storage: localStorage `tsy_attr` = {u:{...}, t:<ms>[, f:1]}, a first-party
 * cookie of the same name when storage is blocked. `f:1` marks a value that
 * is only a click-id floor, so a real campaign is never overwritten by one.
 * Nothing is read from the referrer. Dependency-free, never throws.
 */
(function () {
  'use strict';

  var KEY = 'tsy_attr';
  var WINDOW_MS = 30 * 24 * 60 * 60 * 1000;
  var MAX_AGE_S = 30 * 24 * 60 * 60;
  var MAX_LEN = 255;

  // Utm field -> URL params; the first matching param wins.
  var UTM_FIELDS = [
    ['source', ['utm_source']],
    ['medium', ['utm_medium']],
    ['campaign', ['utm_campaign']],
    ['content', ['utm_content']],
    ['campaignId', ['utm_id', 'utm_campaign_id', 'campaign_id']],
    ['contentId', ['utm_content_id', 'content_id', 'ad_id']]
  ];

  // Click id (lower case) -> the channel it proves. fbclid is checked first.
  var FBCLID = { name: 'fbclid', source: 'facebook', medium: 'paid_social' };
  var CLICK_IDS = [
    { name: 'gclid', source: 'google', medium: 'cpc' },
    { name: 'gbraid', source: 'google', medium: 'cpc' },
    { name: 'wbraid', source: 'google', medium: 'cpc' },
    { name: 'msclkid', source: 'bing', medium: 'cpc' },
    { name: 'ttclid', source: 'tiktok', medium: 'paid_social' },
    { name: 'twclid', source: 'twitter', medium: 'paid_social' },
    { name: 'li_fat_id', source: 'linkedin', medium: 'paid_social' },
    { name: 'sccid', source: 'snapchat', medium: 'paid_social' }
  ];

  function safeDecode(value) {
    try {
      return decodeURIComponent(value);
    } catch (e) {
      return value;
    }
  }

  // An ad-platform macro the platform failed to expand, or a blank value.
  function isMacroValue(value) {
    var v = String(value).replace(/^\s+|\s+$/g, '');
    if (!v) return true;
    var decoded = v.indexOf('%') !== -1 ? safeDecode(v) : v;
    return (
      /^[{[]|[}\]]$/.test(decoded) ||
      decoded.indexOf('{{') !== -1 ||
      decoded.indexOf('}}') !== -1 ||
      /^__.+__$/.test(decoded)
    );
  }

  // The query string as {lower-cased name: first value} (the first occurrence
  // wins, like URLSearchParams.get()); also keeps the exact-case names for UTMs.
  function parseQuery(search) {
    var exact = {};
    var lower = {};
    var query = String(search || '').replace(/^\?/, '');
    if (!query) return { exact: exact, lower: lower };
    var parts = query.split('&');
    for (var i = 0; i < parts.length; i++) {
      if (!parts[i]) continue;
      var eq = parts[i].indexOf('=');
      var rawName = eq === -1 ? parts[i] : parts[i].slice(0, eq);
      var rawValue = eq === -1 ? '' : parts[i].slice(eq + 1);
      var name = safeDecode(rawName.replace(/\+/g, ' '));
      var value = safeDecode(rawValue.replace(/\+/g, ' '));
      if (!Object.prototype.hasOwnProperty.call(exact, name)) exact[name] = value;
      var key = name.toLowerCase();
      if (!Object.prototype.hasOwnProperty.call(lower, key)) lower[key] = value;
    }
    return { exact: exact, lower: lower };
  }

  function has(obj, key) {
    return Object.prototype.hasOwnProperty.call(obj, key);
  }

  function cap(value) {
    return String(value).slice(0, MAX_LEN);
  }

  function parseUtm(exact) {
    var utm = {};
    var any = false;
    for (var i = 0; i < UTM_FIELDS.length; i++) {
      var field = UTM_FIELDS[i][0];
      var keys = UTM_FIELDS[i][1];
      for (var j = 0; j < keys.length; j++) {
        var value = has(exact, keys[j]) ? exact[keys[j]] : '';
        if (value && !isMacroValue(value)) {
          utm[field] = cap(value);
          any = true;
          break;
        }
      }
    }
    return any ? utm : null;
  }

  // The channel a click id proves ({source, medium}), or null. The id's VALUE is
  // only tested for presence; it is never returned.
  function clickFloor(lower) {
    if (has(lower, FBCLID.name) && lower[FBCLID.name]) {
      return { source: FBCLID.source, medium: FBCLID.medium };
    }
    for (var i = 0; i < CLICK_IDS.length; i++) {
      var entry = CLICK_IDS[i];
      var value = has(lower, entry.name) ? lower[entry.name] : '';
      if (value && !isMacroValue(value)) {
        return { source: entry.source, medium: entry.medium };
      }
    }
    return null;
  }

  // ── storage ───────────────────────────────────────────────────────────────

  function parseStored(raw) {
    if (!raw) return null;
    try {
      var parsed = JSON.parse(raw);
      if (!parsed || typeof parsed !== 'object' || typeof parsed.t !== 'number' || !isFinite(parsed.t)) return null;
      if (!parsed.u || typeof parsed.u !== 'object') return null;
      return parsed;
    } catch (e) {
      return null;
    }
  }

  function fresh(stored, now) {
    return !!stored && now - stored.t <= WINDOW_MS;
  }

  function readLocal() {
    try {
      return window.localStorage.getItem(KEY);
    } catch (e) {
      return null;
    }
  }

  function readCookie() {
    try {
      var match = document.cookie.match(/(?:^|;\s*)tsy_attr=([^;]*)/);
      return match ? safeDecode(match[1]) : null;
    } catch (e) {
      return null;
    }
  }

  // The stored value if it is valid and inside the window, else null.
  function load(now) {
    var stored = parseStored(readLocal());
    if (fresh(stored, now)) return stored;
    stored = parseStored(readCookie());
    return fresh(stored, now) ? stored : null;
  }

  function save(value) {
    var json = JSON.stringify(value);
    try {
      window.localStorage.setItem(KEY, json);
      return;
    } catch (e) { /* storage blocked: the cookie below keeps it */ }
    try {
      var secure = window.location && window.location.protocol === 'https:' ? '; Secure' : '';
      document.cookie = KEY + '=' + encodeURIComponent(json) + '; path=/; max-age=' + MAX_AGE_S + '; SameSite=Lax' + secure;
    } catch (e) { /* nothing left to try */ }
  }

  // ── capture / read ────────────────────────────────────────────────────────

  function capture() {
    var query = parseQuery(window.location && window.location.search);
    var utm = parseUtm(query.exact);
    var floor = utm ? null : clickFloor(query.lower);
    if (!utm && !floor) return; // nothing on this URL: write nothing

    var now = Date.now();
    if (utm) {
      save({ u: utm, t: now }); // the last campaign replaces the stored one whole
      return;
    }
    var prev = load(now);
    if (prev && !prev.f) return; // a real campaign within the window beats a floor
    save({ u: floor, t: now, f: 1 });
  }

  var FIELDS = ['source', 'medium', 'campaign', 'content', 'campaignId', 'contentId'];

  function read() {
    try {
      var stored = load(Date.now());
      if (!stored) return null;
      var out = {};
      var any = false;
      for (var i = 0; i < FIELDS.length; i++) {
        var value = stored.u[FIELDS[i]];
        if (typeof value === 'string' && value) {
          out[FIELDS[i]] = cap(value);
          any = true;
        }
      }
      return any ? out : null;
    } catch (e) {
      return null;
    }
  }

  try {
    window.tassyirAttribution = { read: read };
    capture();
  } catch (e) { /* attribution must never break the page */ }
})();
