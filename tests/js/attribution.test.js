'use strict';
// Run: node tests/js/attribution.test.js
// Loads assets/js/attribution.js into a vm sandbox with a fake window, document,
// location, localStorage and clock. No dependencies.

var fs = require('fs');
var path = require('path');
var vm = require('vm');
var assert = require('assert');

var SOURCE = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'js', 'attribution.js'), 'utf8');
var DAY = 24 * 60 * 60 * 1000;
var T0 = 1700000000000;

// A page load: `search` is the URL query, `opts.storage` pre-fills localStorage
// (a Map-like object), `opts.blocked` makes localStorage throw, `opts.cookie`
// pre-fills the cookie jar, `opts.now` is the clock. The same `env` can be
// loaded again to simulate the next page.
function makeEnv(opts) {
  opts = opts || {};
  var store = opts.storage || {};
  var jar = opts.cookie || {};
  var clock = { now: opts.now || T0 };
  var cookieWrites = [];
  var localStorage = {
    getItem: function (k) {
      if (opts.blocked) throw new Error('SecurityError');
      return Object.prototype.hasOwnProperty.call(store, k) ? store[k] : null;
    },
    setItem: function (k, v) {
      if (opts.blocked) throw new Error('SecurityError');
      store[k] = String(v);
    }
  };
  var document = {};
  Object.defineProperty(document, 'cookie', {
    get: function () {
      return Object.keys(jar).map(function (k) { return k + '=' + jar[k]; }).join('; ');
    },
    set: function (line) {
      cookieWrites.push(line);
      var pair = line.split(';')[0];
      var eq = pair.indexOf('=');
      jar[pair.slice(0, eq)] = pair.slice(eq + 1);
    }
  });
  return { store: store, jar: jar, clock: clock, cookieWrites: cookieWrites, localStorage: localStorage, document: document, opts: opts };
}

function load(env, search, protocol) {
  var window = { localStorage: env.localStorage, location: { search: search || '', protocol: protocol || 'https:' } };
  var sandbox = {
    window: window,
    document: env.document,
    Date: { now: function () { return env.clock.now; } },
    JSON: JSON, Object: Object, String: String, isFinite: isFinite,
    decodeURIComponent: decodeURIComponent, encodeURIComponent: encodeURIComponent
  };
  vm.runInNewContext(SOURCE, sandbox);
  return window.tassyirAttribution;
}

// The values come out of a vm context (other Object prototype): compare as
// plain, key-sorted JSON.
function norm(v) {
  if (v === null || typeof v !== 'object') return v;
  var o = {};
  Object.keys(v).sort().forEach(function (k) { o[k] = norm(v[k]); });
  return o;
}
function same(actual, expected, msg) {
  assert.strictEqual(JSON.stringify(norm(actual)), JSON.stringify(norm(expected)), msg);
}

var tests = [];
function test(name, fn) { tests.push([name, fn]); }

test('full UTMs are captured and read back', function () {
  var env = makeEnv();
  var a = load(env, '?utm_source=facebook&utm_medium=paid_social&utm_campaign=ramadan&utm_content=video1&utm_id=111&utm_content_id=222');
  same(a.read(), {
    source: 'facebook', medium: 'paid_social', campaign: 'ramadan', content: 'video1', campaignId: '111', contentId: '222'
  });
  var stored = JSON.parse(env.store.tsy_attr);
  assert.strictEqual(stored.t, T0);
  assert.strictEqual(stored.f, undefined);
});

test('aliases: first matching param wins', function () {
  var a = load(makeEnv(), '?campaign_id=9&utm_campaign_id=8&ad_id=7&content_id=6');
  same(a.read(), { campaignId: '8', contentId: '6' });
  var b = load(makeEnv(), '?ad_id=77');
  same(b.read(), { contentId: '77' });
  var c = load(makeEnv(), '?utm_id=1&utm_campaign_id=2&campaign_id=3');
  same(c.read(), { campaignId: '1' });
});

test('macros and blank values are skipped (also percent-encoded)', function () {
  var qs = '?utm_source=%7B%7Bsite_source_name%7D%7D&utm_medium=&utm_campaign=__CAMPAIGN_NAME__' +
    '&utm_content=%7Bcampaignid%7D&utm_id=%5Bx%5D&utm_content_id=%20%20&ad_id=555';
  var a = load(makeEnv(), qs);
  same(a.read(), { contentId: '555' });
  assert.strictEqual(load(makeEnv(), '?utm_source={{a}}&utm_campaign={b}').read(), null);
  // A broken percent sequence must not throw.
  same(load(makeEnv(), '?utm_source=100%&utm_medium=cpc').read(), { source: '100%', medium: 'cpc' });
});

test('values are capped at 255 characters', function () {
  var a = load(makeEnv(), '?utm_campaign=' + new Array(400).join('x'));
  assert.strictEqual(a.read().campaign.length, 255);
});

test('last campaign replaces the stored one whole', function () {
  var env = makeEnv();
  load(env, '?utm_source=facebook&utm_medium=paid_social&utm_campaign=one&utm_id=1');
  env.clock.now = T0 + DAY;
  var a = load(env, '?utm_source=tiktok&utm_campaign=two');
  same(a.read(), { source: 'tiktok', campaign: 'two' });
});

test('a URL with nothing to capture writes nothing and keeps the stored value', function () {
  var env = makeEnv();
  load(env, '?utm_source=google&utm_medium=cpc');
  var before = env.store.tsy_attr;
  env.clock.now = T0 + DAY;
  var a = load(env, '?page=2&foo=bar');
  assert.strictEqual(env.store.tsy_attr, before);
  same(a.read(), { source: 'google', medium: 'cpc' });
  assert.strictEqual(load(makeEnv(), '').read(), null);
  assert.strictEqual(Object.keys(makeEnv().store).length, 0);
});

test('click ids give the channel only', function () {
  var cases = [
    ['?fbclid=AAA', 'facebook', 'paid_social'],
    ['?gclid=AAA', 'google', 'cpc'],
    ['?gbraid=AAA', 'google', 'cpc'],
    ['?wbraid=AAA', 'google', 'cpc'],
    ['?msclkid=AAA', 'bing', 'cpc'],
    ['?ttclid=AAA', 'tiktok', 'paid_social'],
    ['?twclid=AAA', 'twitter', 'paid_social'],
    ['?li_fat_id=AAA', 'linkedin', 'paid_social'],
    ['?sccid=AAA', 'snapchat', 'paid_social']
  ];
  cases.forEach(function (c) {
    same(load(makeEnv(), c[0]).read(), { source: c[1], medium: c[2] }, c[0]);
  });
  // fbclid is checked first.
  same(load(makeEnv(), '?gclid=G&fbclid=F').read(), { source: 'facebook', medium: 'paid_social' });
});

test('ScCid (Snapchat) is matched case-insensitively', function () {
  same(load(makeEnv(), '?ScCid=abc123').read(), { source: 'snapchat', medium: 'paid_social' });
  same(load(makeEnv(), '?GCLID=abc').read(), { source: 'google', medium: 'cpc' });
  same(load(makeEnv(), '?FbClId=abc').read(), { source: 'facebook', medium: 'paid_social' });
});

test('a click-id macro is not a click id', function () {
  assert.strictEqual(load(makeEnv(), '?ttclid=__CLICKID__').read(), null);
  assert.strictEqual(load(makeEnv(), '?gclid=%7Bgclid%7D').read(), null);
});

test('UTMs win over a click id on the same URL', function () {
  var a = load(makeEnv(), '?utm_source=newsletter&fbclid=AAA');
  same(a.read(), { source: 'newsletter' });
});

test('the floor does not override stored UTMs, but updates an earlier floor', function () {
  var env = makeEnv();
  load(env, '?utm_source=facebook&utm_medium=paid_social&utm_campaign=real');
  env.clock.now = T0 + DAY;
  var a = load(env, '?gclid=XYZ');
  same(a.read(), { source: 'facebook', medium: 'paid_social', campaign: 'real' });

  var env2 = makeEnv();
  load(env2, '?fbclid=AAA');
  same(load(env2, '').read(), { source: 'facebook', medium: 'paid_social' });
  env2.clock.now = T0 + DAY;
  var b = load(env2, '?ttclid=BBB'); // an earlier floor is updated
  same(b.read(), { source: 'tiktok', medium: 'paid_social' });
  assert.strictEqual(JSON.parse(env2.store.tsy_attr).t, T0 + DAY);
  // Real UTMs then replace the floor.
  env2.clock.now = T0 + 2 * DAY;
  same(load(env2, '?utm_source=google&utm_medium=cpc&utm_campaign=c').read(),
    { source: 'google', medium: 'cpc', campaign: 'c' });
});

test('the floor is stored over UTMs that are outside the window', function () {
  var env = makeEnv();
  load(env, '?utm_source=facebook&utm_campaign=old');
  env.clock.now = T0 + 31 * DAY;
  var a = load(env, '?gclid=XYZ');
  same(a.read(), { source: 'google', medium: 'cpc' });
});

test('a value older than 30 days is gone', function () {
  var env = makeEnv();
  load(env, '?utm_source=facebook');
  env.clock.now = T0 + 30 * DAY;
  same(load(env, '').read(), { source: 'facebook' });
  env.clock.now = T0 + 30 * DAY + 1;
  assert.strictEqual(load(env, '').read(), null);
});

test('corrupt storage is treated as absent', function () {
  var bad = ['not json', '{"u":"x","t":1}', '{"t":' + T0 + '}', '{"u":{"source":"a"},"t":"yesterday"}', 'null', '[]', '{"u":{"source":"a"}}'];
  bad.forEach(function (raw) {
    var env = makeEnv({ storage: { tsy_attr: raw } });
    assert.strictEqual(load(env, '').read(), null, raw);
    // and a new capture is not blocked by it
    same(load(env, '?utm_source=ok').read(), { source: 'ok' }, raw);
  });
  // Non-string or empty fields in an otherwise valid blob are dropped.
  var env = makeEnv({ storage: { tsy_attr: JSON.stringify({ u: { source: 5, medium: '', campaign: { a: 1 }, content: 'c', gclid: 'LEAK' }, t: T0 }) } });
  same(load(env, '').read(), { content: 'c' });
  // A corrupt cookie too.
  assert.strictEqual(load(makeEnv({ blocked: true, cookie: { tsy_attr: '%E0%A4%A' } }), '').read(), null);
});

test('storage blocked falls back to the cookie', function () {
  var env = makeEnv({ blocked: true });
  var a = load(env, '?utm_source=facebook&utm_medium=paid_social');
  assert.strictEqual(env.cookieWrites.length, 1);
  var w = env.cookieWrites[0];
  assert.ok(/^tsy_attr=/.test(w), w);
  assert.ok(w.indexOf('path=/') !== -1 && w.indexOf('max-age=2592000') !== -1 && w.indexOf('SameSite=Lax') !== -1, w);
  assert.ok(w.indexOf('Secure') !== -1, 'https adds Secure');
  same(a.read(), { source: 'facebook', medium: 'paid_social' });
  // The next page reads it back from the cookie.
  env.clock.now = T0 + DAY;
  same(load(env, '').read(), { source: 'facebook', medium: 'paid_social' });

  var http = makeEnv({ blocked: true });
  load(http, '?utm_source=x', 'http:');
  assert.ok(http.cookieWrites[0].indexOf('Secure') === -1, 'no Secure over http');

  // Floor rule works through the cookie too.
  var env2 = makeEnv({ blocked: true });
  load(env2, '?utm_source=real');
  same(load(env2, '?gclid=Z').read(), { source: 'real' });
});

test('a working localStorage writes no cookie', function () {
  var env = makeEnv();
  load(env, '?utm_source=x');
  assert.strictEqual(env.cookieWrites.length, 0);
});

test('read() never exposes a click id', function () {
  var env = makeEnv();
  var a = load(env, '?fbclid=SECRETFB&gclid=SECRETG&ScCid=SECRETSC&utm_source=x');
  assert.ok(env.store.tsy_attr.indexOf('SECRET') === -1, 'not even stored');
  assert.ok(JSON.stringify(a.read()).indexOf('SECRET') === -1);
  var b = load(makeEnv(), '?ttclid=SECRETTT');
  assert.ok(JSON.stringify(b.read()).indexOf('SECRET') === -1);
  same(Object.keys(b.read()).sort(), ['medium', 'source']);
  // Only the six known keys can ever come out, even from a doctored blob.
  var env2 = makeEnv({ storage: { tsy_attr: JSON.stringify({ u: { source: 'a', fbclid: 'SECRET', c: { gclid: 'SECRET' } }, f: 'SECRET', t: T0 }) } });
  var r = load(env2, '').read();
  same(r, { source: 'a' });
});

test('it never throws into the page', function () {
  // location missing entirely
  var env = makeEnv();
  var sandbox = { window: { localStorage: env.localStorage }, document: env.document, Date: Date, JSON: JSON };
  vm.runInNewContext(SOURCE, sandbox);
  assert.strictEqual(sandbox.window.tassyirAttribution.read(), null);
  // document.cookie throwing and storage blocked
  var hostile = { window: { localStorage: { getItem: function () { throw new Error('x'); }, setItem: function () { throw new Error('x'); } }, location: { search: '?utm_source=a', protocol: 'https:' } }, Date: Date, JSON: JSON };
  Object.defineProperty(hostile, 'document', { value: Object.defineProperty({}, 'cookie', { get: function () { throw new Error('c'); }, set: function () { throw new Error('c'); } }) });
  vm.runInNewContext(SOURCE, hostile);
  assert.strictEqual(hostile.window.tassyirAttribution.read(), null);
});

var failed = 0;
tests.forEach(function (t) {
  try {
    t[1]();
    console.log('ok   - ' + t[0]);
  } catch (e) {
    failed++;
    console.log('FAIL - ' + t[0] + '\n       ' + (e && e.stack ? e.stack.split('\n').slice(0, 4).join('\n       ') : e));
  }
});
console.log('\n' + (tests.length - failed) + '/' + tests.length + ' passed');
process.exit(failed ? 1 : 0);
