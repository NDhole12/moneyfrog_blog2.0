import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import http from 'node:http';
import { mkdtemp, writeFile, readFile, rm, stat } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { downloadMedia } from '../src/media.mjs';

let server, base, tmp, hits, seen;
const JPG = Buffer.concat([Buffer.from([0xff, 0xd8, 0xff]), Buffer.from('JPEG!')]); // 8 bytes
const FLAKY = Buffer.concat([Buffer.from([0xff, 0xd8, 0xff]), Buffer.from('FLAKY')]);

beforeEach(async () => {
  hits = {};
  seen = [];
  server = http.createServer((req, res) => {
    hits[req.url] = (hits[req.url] || 0) + 1;
    seen.push({ at: Date.now(), headers: req.headers, url: req.url });
    if (req.url === '/wp-content/uploads/2019/01/ok.jpg') return res.writeHead(200, { 'content-type': 'image/jpeg' }).end(JPG);
    if (req.url === '/wp-content/uploads/2019/01/gone.jpg') return res.writeHead(404).end('nope');
    if (req.url === '/wp-content/uploads/2019/01/flaky.jpg') {
      return hits[req.url] < 3 ? res.writeHead(503).end('busy') : res.writeHead(200, { 'content-type': 'image/jpeg' }).end(FLAKY);
    }
    if (req.url === '/wp-content/uploads/2019/01/hop.jpg') return res.writeHead(301, { location: '/wp-content/uploads/2019/01/ok.jpg' }).end();
    if (req.url === '/wp-content/uploads/2019/01/away.jpg') return res.writeHead(302, { location: 'http://example.invalid/x.jpg' }).end();
    if (req.url === '/wp-content/uploads/2019/01/soft.jpg') return res.writeHead(200, { 'content-type': 'text/html' }).end('<html>not found</html>');
    if (req.url === '/wp-content/uploads/2019/01/fake.png') return res.writeHead(200, { 'content-type': 'image/png' }).end('<html>nope</html>');
    if (req.url === '/wp-content/uploads/2019/01/evil.svg') return res.writeHead(200, { 'content-type': 'image/svg+xml' }).end('<svg><script>alert(1)</script></svg>');
    if (req.url === '/wp-content/uploads/2019/01/good.svg') return res.writeHead(200, { 'content-type': 'image/svg+xml' }).end('<svg xmlns="http://www.w3.org/2000/svg"></svg>');
    if (req.url === '/wp-content/uploads/2019/01/shell.php') return res.writeHead(200, { 'content-type': 'image/jpeg' }).end(JPG);
    res.writeHead(404).end();
  });
  await new Promise((r) => server.listen(0, '127.0.0.1', r));
  base = `http://127.0.0.1:${server.address().port}`;
  tmp = await mkdtemp(path.join(os.tmpdir(), 'mf-media-'));
});

afterEach(async () => {
  await new Promise((r) => server.close(r));
  await rm(tmp, { recursive: true, force: true });
});

async function makeBundle(media) {
  const bundlePath = path.join(tmp, 'bundle.json');
  await writeFile(bundlePath, JSON.stringify({ generatedAt: 'x', source: base, items: [], bylines: [], media }));
  return bundlePath;
}
const m = (oldId, name, extra = {}) => ({
  oldId, sourceUrl: `${base}/wp-content/uploads/2019/01/${name}`, uploadPath: `2019/01/${name}`, alt: '', oldPaths: [], ...extra,
});
const opts = (bundlePath) => ({ bundlePath, outDir: path.join(tmp, 'out'), delayMs: 0, retryBaseMs: 1, allowedHosts: [new URL(base).host] });

describe('downloadMedia', () => {
  it('saves files at their upload path, records failures, retries 5xx, follows same-host redirects', async () => {
    const bundlePath = await makeBundle([m(1, 'ok.jpg'), m(2, 'gone.jpg'), m(3, 'flaky.jpg'), m(4, 'hop.jpg')]);
    const manifest = await downloadMedia(opts(bundlePath));

    expect(await readFile(path.join(tmp, 'out/2019/01/ok.jpg'), 'latin1')).toBe('ÿØÿJPEG!');
    expect(await readFile(path.join(tmp, 'out/2019/01/flaky.jpg'), 'latin1')).toBe('ÿØÿFLAKY');
    expect(await readFile(path.join(tmp, 'out/2019/01/hop.jpg'), 'latin1')).toBe('ÿØÿJPEG!');
    expect(manifest[1]).toMatchObject({ uploadPath: '2019/01/ok.jpg', ok: true, status: 200, bytes: 8 });
    expect(manifest[2]).toMatchObject({ ok: false, status: 404 });
    expect(hits['/wp-content/uploads/2019/01/gone.jpg']).toBe(1);
    expect(hits['/wp-content/uploads/2019/01/flaky.jpg']).toBe(3);
    const onDisk = JSON.parse(await readFile(path.join(tmp, 'out/media-manifest.json'), 'utf8'));
    expect(onDisk['3'].ok).toBe(true);
  });

  it('is polite: fixed user agent, no cookies or auth, spacing between requests', async () => {
    const bundlePath = await makeBundle([m(1, 'ok.jpg'), m(2, 'gone.jpg')]);
    await downloadMedia({ ...opts(bundlePath), delayMs: 60 });
    expect(seen.length).toBe(2);
    for (const s of seen) {
      expect(s.headers['user-agent']).toBe('CodesparkQA-migration');
      expect(s.headers.cookie).toBeUndefined();
      expect(s.headers.authorization).toBeUndefined();
    }
    expect(seen[1].at - seen[0].at).toBeGreaterThanOrEqual(55);
  });

  it('skips files already present (resumable) and records them ok', async () => {
    const bundlePath = await makeBundle([m(1, 'ok.jpg')]);
    await downloadMedia(opts(bundlePath));
    const before = hits['/wp-content/uploads/2019/01/ok.jpg'];
    const manifest = await downloadMedia(opts(bundlePath));
    expect(hits['/wp-content/uploads/2019/01/ok.jpg']).toBe(before);
    expect(manifest[1]).toMatchObject({ ok: true, bytes: 8 });
  });

  it('refuses other hosts, redirects off-host and unsafe paths without a request', async () => {
    const bundlePath = await makeBundle([
      m(1, 'ok.jpg', { sourceUrl: 'http://example.invalid/wp-content/uploads/2019/01/ok.jpg' }),
      m(2, 'away.jpg'),
      m(3, 'ok.jpg', { uploadPath: '../escape.jpg' }),
      m(4, 'ok.jpg', { uploadPath: '/abs.jpg' }),
    ]);
    const manifest = await downloadMedia(opts(bundlePath));
    expect(manifest[1]).toMatchObject({ ok: false, status: 'off-host' });
    expect(manifest[2]).toMatchObject({ ok: false, status: 'off-host' });
    expect(manifest[3]).toMatchObject({ ok: false, status: 'bad-path' });
    expect(manifest[4]).toMatchObject({ ok: false, status: 'bad-path' });
    await expect(stat(path.join(tmp, 'escape.jpg'))).rejects.toThrow();
    expect(seen.map((s) => s.url)).toEqual(['/wp-content/uploads/2019/01/away.jpg']);
  });

  it('blocks other extensions and soft-404 or fake bodies, and unsafe svg', async () => {
    const bundlePath = await makeBundle([m(1, 'shell.php'), m(2, 'soft.jpg'), m(3, 'fake.png'), m(4, 'evil.svg'), m(5, 'good.svg')]);
    const manifest = await downloadMedia(opts(bundlePath));
    expect(manifest[1]).toMatchObject({ ok: false, status: 'blocked-extension' });
    expect(manifest[2]).toMatchObject({ ok: false, status: 'bad-content' });
    expect(manifest[3]).toMatchObject({ ok: false, status: 'bad-content' });
    expect(manifest[4]).toMatchObject({ ok: false, status: 'bad-content' });
    expect(manifest[5]).toMatchObject({ ok: true });
    expect(hits['/wp-content/uploads/2019/01/shell.php']).toBeUndefined();
    for (const f of ['soft.jpg', 'fake.png', 'evil.svg', 'shell.php']) await expect(stat(path.join(tmp, 'out/2019/01', f))).rejects.toThrow();
  });

  it('defaults to the old blog host only', async () => {
    const bundlePath = await makeBundle([m(1, 'ok.jpg')]);
    const manifest = await downloadMedia({ bundlePath, outDir: path.join(tmp, 'out'), delayMs: 0 });
    expect(manifest[1]).toMatchObject({ ok: false, status: 'off-host' });
    expect(seen.length).toBe(0);
  });

  it('stops and reports when the host keeps erroring', async () => {
    const media = Array.from({ length: 8 }, (_, i) => m(i + 1, `bad${i}.jpg`));
    server.removeAllListeners('request');
    server.on('request', (req, res) => { seen.push({ url: req.url, headers: req.headers, at: Date.now() }); res.writeHead(503).end(); });
    const bundlePath = await makeBundle(media);
    await expect(downloadMedia({ ...opts(bundlePath), maxConsecutiveErrors: 2 })).rejects.toThrow(/stopping/i);
    const onDisk = JSON.parse(await readFile(path.join(tmp, 'out/media-manifest.json'), 'utf8'));
    expect(Object.keys(onDisk).length).toBeLessThan(8);
  });
});
