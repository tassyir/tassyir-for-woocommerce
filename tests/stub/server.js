#!/usr/bin/env node
/**
 * Tiny local stand-in for the Tassyir API + dashboard approval page, for wp-env testing.
 * Implements the happy paths of WOOCOMMERCE_PLUGIN_CONTRACT.md and VERIFIES every signature
 * (§A) the plugin sends. No dependencies: `node tests/stub/server.js` (PORT env, default 8787).
 *
 * Extra test endpoints:
 *   GET  /_stub/log              every request received (method, path, signature check, body)
 *   POST /_stub/commands         body = one heartbeat command, delivered on the next heartbeat
 *   POST /_stub/mode             body = { "orders": "ok" | "fail" }  (fail = answer 503)
 */
'use strict';

const http = require('http');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

const PORT = Number(process.env.PORT || 8787);
const LOG_FILE = path.join(__dirname, 'requests.log');

const codes = new Map(); // code -> { state, siteHost }
const connections = new Map(); // id -> { secret (Buffer) }
const nonces = new Set();
const orders = new Map(); // woo order id -> tassyir order
const storedImages = new Set();
const pendingCommands = [];
const productPrices = new Map(); // woo product/variation id -> price (from product sync)

// COD form test geography: [code, name, arabicName, home fee, desk fee or null].
const STUB_PROVINCES = [
	[9, 'Blida', 'البليدة', 600, 400],
	[15, 'Tizi Ouzou', 'تيزي وزو', 700, null],
	[16, 'Alger', 'الجزائر', 500, 350],
	[25, 'Constantine', 'قسنطينة', 800, 500],
	[31, 'Oran', 'وهران', 800, 500],
];
// [codeOns, name, arabicName, has a desk].
const STUB_TOWNS = {
	9: [[901, 'Blida', 'البليدة', true], [915, 'Boufarik', 'بوفاريك', false]],
	15: [[1501, 'Tizi Ouzou', 'تيزي وزو', false], [1530, 'Azazga', 'عزازقة', false]],
	16: [[1601, 'Alger Centre', 'الجزائر الوسطى', true], [1621, 'Bab Ezzouar', 'باب الزوار', true], [1628, 'Hydra', 'حيدرة', false]],
	25: [[2501, 'Constantine', 'قسنطينة', true], [2506, 'El Khroub', 'الخروب', false]],
	31: [[3101, 'Oran', 'وهران', true], [3111, 'Es Senia', 'السانية', false]],
};
const received = [];
let orderMode = 'ok';
let commandSeq = 1;

// §B1 host rule: lower-cased, one leading "www." ignored.
function normHost(u) {
	return new URL(u).host.toLowerCase().replace(/^www\./, '');
}

function send(res, status, body, headers = {}) {
	const text = body === undefined ? '' : JSON.stringify(body);
	res.writeHead(status, { 'Content-Type': 'application/json', ...headers });
	res.end(text);
}

function record(entry) {
	received.push(entry);
	const line = `${new Date().toISOString()} ${entry.method} ${entry.path} -> ${entry.status} ${entry.note || ''}`;
	console.log(line);
	fs.appendFileSync(LOG_FILE, line + '\n');
}

function verify(req, raw) {
	const h = req.headers;
	// Like the backend: chunked bodies are refused, Content-Length must match the body.
	if (h['transfer-encoding'] || Number(h['content-length']) !== raw.length) return 'missing or wrong Content-Length';
	const conn = connections.get(h['x-tassyir-connection']);
	if (!conn) return 'unknown connection';
	const ts = Number(h['x-tassyir-timestamp']);
	if (!Number.isInteger(ts) || Math.abs(Date.now() / 1000 - ts) > 300) return 'timestamp skew';
	const nonce = h['x-tassyir-nonce'] || '';
	if (!/^[0-9a-f]{32}$/.test(nonce)) return 'bad nonce';
	if (nonces.has(nonce)) return 'nonce reused';
	const canonical = [ts, nonce, req.method.toUpperCase(), req.url.split('?')[0], crypto.createHash('sha256').update(raw).digest('hex')].join('\n');
	const expected = crypto.createHmac('sha256', conn.secret).update(canonical).digest();
	const given = Buffer.from(h['x-tassyir-signature'] || '', 'base64');
	if (given.length !== expected.length || !crypto.timingSafeEqual(given, expected)) return 'bad signature';
	if (!/^\d+\.\d+\.\d+/.test(h['x-tassyir-plugin'] || '')) return 'missing plugin version';
	nonces.add(nonce);
	return null;
}

function multipartFields(req, raw) {
	const m = /boundary=(.+)$/.exec(req.headers['content-type'] || '');
	if (!m) return null;
	const fields = {};
	for (const part of raw.toString('latin1').split('--' + m[1])) {
		const nm = /name="([^"]+)"(?:; filename="([^"]*)")?/.exec(part);
		if (!nm) continue;
		const value = part.slice(part.indexOf('\r\n\r\n') + 4, part.length - 2);
		fields[nm[1]] = nm[2] !== undefined ? { filename: nm[2], bytes: value.length, type: (/Content-Type: (\S+)/.exec(part) || [])[1] } : value;
	}
	return fields;
}

const server = http.createServer((req, res) => {
	const chunks = [];
	req.on('data', (c) => chunks.push(c));
	req.on('end', () => {
		const raw = Buffer.concat(chunks);
		const url = new URL(req.url, `http://${req.headers.host}`);
		const p = url.pathname;
		const entry = { at: new Date().toISOString(), method: req.method, path: p };
		const done = (status, body, note, headers) => {
			entry.status = status;
			entry.note = note;
			record(entry);
			send(res, status, body, headers);
		};

		// --- Test helpers -------------------------------------------------------------
		if (p === '/_stub/log') return send(res, 200, received);
		if (p === '/_stub/commands' && req.method === 'POST') {
			const cmd = JSON.parse(raw.toString() || '{}');
			cmd.id = cmd.id || String(1000 + commandSeq++);
			pendingCommands.push(cmd);
			return send(res, 200, cmd);
		}
		if (p === '/_stub/mode' && req.method === 'POST') {
			orderMode = JSON.parse(raw.toString() || '{}').orders || 'ok';
			return send(res, 200, { orders: orderMode });
		}

		// --- B1: dashboard approval page (auto-approves) --------------------------------
		if (p === '/connect/woocommerce') {
			const site = url.searchParams.get('site');
			const ret = url.searchParams.get('return');
			const state = url.searchParams.get('state');
			let siteHost;
			try {
				siteHost = normHost(site);
				if (normHost(ret) !== siteHost) throw new Error('other host');
			} catch (e) {
				return done(400, { code: 'WOO_PLUGIN_RETURN_URL_INVALID' }, String(e.message));
			}
			if (url.searchParams.get('cancel')) {
				entry.status = 302;
				record(entry);
				res.writeHead(302, { Location: `${ret}&error=cancelled&state=${encodeURIComponent(state)}` });
				return res.end();
			}
			const code = crypto.randomBytes(32).toString('base64url');
			codes.set(code, { state, siteHost });
			entry.status = 302;
			entry.note = `approved site=${siteHost} v=${url.searchParams.get('v')}`;
			record(entry);
			res.writeHead(302, { Location: `${ret}&code=${code}&state=${encodeURIComponent(state)}` });
			return res.end();
		}

		let body = {};
		const isJson = (req.headers['content-type'] || '').startsWith('application/json');
		if (isJson && raw.length) {
			try {
				body = JSON.parse(raw.toString());
			} catch (e) {
				return done(400, { code: 'BAD_JSON' }, 'bad json');
			}
		}
		entry.body = isJson ? body : multipartFields(req, raw);

		// --- F4: update manifest (public) -----------------------------------------------
		if (p === '/woo-plugin/update.json') {
			return done(200, {
				name: 'Tassyir for WooCommerce',
				slug: 'tassyir-for-woocommerce',
				version: process.env.LATEST_VERSION || '1.0.0',
				download_url: `http://localhost:${PORT}/woo-plugin/download`,
				requires: '6.2',
				tested: '6.8',
				requires_php: '7.4',
				last_updated: new Date().toISOString(),
				sections: { changelog: '<p>Stub</p>' },
			});
		}

		// --- B3: exchange (unsigned) ----------------------------------------------------
		if (p === '/woo-plugin/connect/exchange' && req.method === 'POST') {
			const bound = codes.get(body.code);
			codes.delete(body.code);
			if (!bound || bound.state !== body.state || normHost(body.siteUrl) !== bound.siteHost) {
				return done(400, { code: 'WOO_PLUGIN_CODE_INVALID', message: 'nope' }, 'code invalid');
			}
			const id = '1234567890123456789';
			const secret = crypto.randomBytes(32);
			connections.set(id, { secret });
			return done(200, {
				connectionId: id,
				secret: secret.toString('base64url'),
				business: { id: '100000000000000001', name: 'Demo Business' },
				store: { id: '42', name: 'Demo Store' },
				upgradedFrom: process.env.UPGRADED_FROM || null,
				settings: { importStatuses: ['processing', 'on-hold', 'pending'], importFrom: new Date().toISOString(), autoCreateProducts: true, heartbeatSeconds: 900 },
				manualWebhooks: { deliveryUrlPrefix: `https://api.tassyir.io/woocommerce-webhook/${id}/` },
			}, `connected checkout=${body.checkout} hpos=${body.hpos} wc=${body.wcVersion} php=${body.phpVersion}`);
		}

		// --- Signed routes --------------------------------------------------------------
		if (p.startsWith('/woo-plugin/')) {
			const problem = verify(req, raw);
			entry.signature = problem || 'valid';
			if (problem) return done(401, { code: 'WOO_PLUGIN_SIGNATURE_INVALID' }, `signature: ${problem}`);
		}

		if (p === '/woo-plugin/orders') {
			if (orderMode === 'fail') return done(503, { code: 'SERVICE_UNAVAILABLE' }, 'forced failure');
			const o = body.order || {};
			const missing = ['id', 'number', 'status', 'date_created_gmt', 'billing', 'shipping', 'line_items', 'shipping_lines', 'coupon_lines', 'meta_data', 'total', 'shipping_total', 'customer_note', 'payment_method'].filter((k) => !(k in o));
			if (missing.length) return done(400, { code: 'WOO_PLUGIN_ORDER_INVALID' }, `missing ${missing.join(',')}`);
			const known = orders.get(o.id);
			if (known) return done(200, { status: 'duplicate', tassyirOrderId: known.id, orderNumber: known.number, reason: null, retry: false }, `order ${o.id} duplicate channel=${body.channel}`);
			const created = { id: String(9000000 + orders.size), number: '#' + String(123 + orders.size).padStart(8, '0') };
			orders.set(o.id, created);
			return done(200, { status: 'created', tassyirOrderId: created.id, orderNumber: created.number, reason: null, retry: false },
				`order ${o.id} created channel=${body.channel} attempt=${body.attempt} lines=${o.line_items.length} total=${o.total}`);
		}

		if (p === '/woo-plugin/products') {
			const items = body.products || [];
			const results = [];
			const needImages = [];
			for (const item of items) {
				const prod = item.product || {};
				if (prod.id && prod.price !== undefined) productPrices.set(Number(prod.id), Number(prod.price) || 0);
				(item.variations || []).forEach((v) => { if (v && v.id) productPrices.set(Number(v.id), Number(v.price) || 0); });
				results.push({ wooProductId: prod.id, status: body.reason === 'delete' ? 'deleted' : 'created', tassyirProductId: String(5000 + prod.id), reason: null });
				(prod.images || []).forEach((img, i) => {
					if (!storedImages.has(img.id)) needImages.push({ attachmentId: img.id, wooProductId: prod.id, wooVariationId: 0, role: i === 0 ? 'main' : 'gallery' });
				});
			}
			return done(200, { results, needImages: needImages.slice(0, 50) }, `${items.length} products reason=${body.reason} needImages=${needImages.length}`);
		}

		if (p === '/woo-plugin/images') {
			const f = entry.body || {};
			if (!f.file || !f.attachmentId || !f.modified) return done(400, { code: 'WOO_PLUGIN_IMAGE_INVALID' }, 'missing fields');
			storedImages.add(Number(f.attachmentId));
			return done(200, { url: `https://cdn.tassyir.test/${f.attachmentId}`, status: 'stored' }, `image ${f.attachmentId} ${f.file.type} ${f.file.bytes} bytes role=${f.role}`);
		}

		if (p === '/woo-plugin/heartbeat') {
			const acked = new Set(body.ackCommandIds || []);
			for (let i = pendingCommands.length - 1; i >= 0; i--) if (acked.has(pendingCommands[i].id)) pendingCommands.splice(i, 1);
			return done(200, {
				serverTime: new Date().toISOString(),
				settings: { importStatuses: ['processing', 'on-hold', 'pending'], importFrom: '2026-01-01T00:00:00Z', autoCreateProducts: true, heartbeatSeconds: 900 },
				commands: pendingCommands,
				minPluginVersion: '1.0.0',
				latestPluginVersion: process.env.LATEST_VERSION || '1.0.0',
			}, `heartbeat pending=${body.queue && body.queue.pending} recent=${(body.recentOrders || []).length} acks=${[...acked].join(',')} commands=${pendingCommands.length}`);
		}

		// --- COD form storefront routes (contract §H: the Shopify proxy shapes + submit) --------
		if (p === '/woo-plugin/storefront/config') {
			return done(200, {
				active: true, currency: 'DZD', orderPricingMode: 'off', codesAvailable: false,
				provinces: STUB_PROVINCES.map(([code, name, arabicName]) => ({ code, name, arabicName })),
				fees: Object.fromEntries(STUB_PROVINCES.map(([code, , , home, desk]) => [code, { home, stopdesk: desk, deliverable: true, stopdeskAvailable: desk !== null }])),
			}, 'storefront config');
		}
		if (p === '/woo-plugin/storefront/towns') {
			const towns = STUB_TOWNS[body.province] || [];
			return done(200, { towns: towns.map(([codeOns, name, arabicName, stopdesk]) => ({ codeOns, name, arabicName, stopdesk, homeDelivery: true })) }, `storefront towns ${body.province}`);
		}
		if (p === '/woo-plugin/storefront/product-fields') {
			const bundle = process.env.STUB_BUNDLE === '0' ? null : {
				id: 'bundle-1', name: 'Offre quantité',
				options: [
					{ title: '1 pièce', quantity: 1, discount_price: 4900, isDefault: true },
					{ title: '2 pièces', quantity: 2, discount_price: 8900 },
					{ title: '3 pièces', quantity: 3, discount_price: 12500, freeShipping: true },
				],
			};
			return done(200, { productId: 'tp-' + body.wooProductId, optionNames: [], variants: [], fields: [], bundle }, `storefront fields product=${body.wooProductId}`);
		}
		if (p === '/woo-plugin/storefront/upload') {
			const f = (entry.body || {}).image;
			if (!f) return done(400, { code: 'UPLOAD_INVALID' }, 'no file');
			const imageId = crypto.randomBytes(8).toString('hex');
			return done(200, { imageId, imageUrl: `https://cdn.tassyir.test/images/${imageId}/public`, originalName: f.filename, mimeType: f.type, size: f.bytes, uploadedAt: new Date().toISOString() }, `storefront upload ${f.type} ${f.bytes} bytes`);
		}
		if (p === '/woo-plugin/storefront/quote') {
			return done(200, { available: false, orderPricingMode: 'off', reason: 'pricing_not_on' }, 'storefront quote');
		}
		if (p === '/woo-plugin/storefront/submit') {
			const verdict = process.env.STUB_VERDICT || (String(body.phone || '').replace(/\D/g, '').endsWith('0000000') ? 'hold' : 'allow');
			const prov = STUB_PROVINCES.find(([code]) => code === body.provinceCode);
			if (!prov) return done(400, { code: 'COMMUNE_NOT_SERVED', message: 'Delivery not available for this province' }, 'submit unserved');
			const fee = body.stopdesk && prov[4] !== null ? prov[4] : prov[3];
			const lines = (body.items || []).map((it) => {
				const unit = productPrices.get(Number(it.wooVariationId || it.wooProductId)) || 2500;
				return { wooProductId: it.wooProductId, wooVariationId: it.wooVariationId || 0, quantity: it.quantity, unitPrice: unit, total: unit * it.quantity };
			});
			const goods = lines.reduce((sum, l) => sum + l.total, 0);
			const allowed = ['submissionId', 'name', 'phone', 'phone2', 'address', 'note', 'provinceCode', 'townOns', 'stopdesk', 'items', 'customFields', 'customFieldRules', 'quoteToken', 'codes', 'customer'];
			const unknown = Object.keys(body).filter((k) => !allowed.includes(k));
			if (unknown.length) return done(400, { code: 'WOO_FORM_BODY_INVALID', errors: unknown }, `submit unknown fields ${unknown.join(',')}`);
			const town = (STUB_TOWNS[body.provinceCode] || []).find(([ons]) => ons === body.townOns) || [body.townOns, '', '', false];
			const quoteId = ['allow', 'flag'].includes(verdict) ? 'wq_' + crypto.randomBytes(16).toString('hex') : null;
			const digits = String(body.phone || '').replace(/\D/g, '').replace(/^(00)?213/, '').replace(/^0/, '');
			const c = body.customer || {};
			return done(200, {
				verdict, quoteId, expiresAt: quoteId ? new Date(Date.now() + 7200000).toISOString() : null, phone: '+213' + digits,
				destination: { provinceCode: body.provinceCode, provinceName: prov[1], provinceArabicName: prov[2], townOns: body.townOns, townName: town[1], townArabicName: town[2], stopdesk: !!body.stopdesk && prov[4] !== null },
				lines, shippingPrice: fee, freeShipping: false, total: goods + fee,
			}, `storefront submit verdict=${verdict} lines=${lines.length} total=${goods + fee} ip=${c.ip} fwd=${c.forwardedIp} cf=${c.behindCloudflare} device=${c.deviceId}`);
		}

		if (p === '/woo-plugin/disconnect') {
			connections.delete(req.headers['x-tassyir-connection']);
			entry.status = 204;
			record(entry);
			res.writeHead(204);
			return res.end();
		}

		return done(404, { code: 'NOT_FOUND' }, 'no route');
	});
});

server.listen(PORT, '0.0.0.0', () => console.log(`Tassyir stub listening on http://localhost:${PORT}`));
