import { mkdir, readFile, writeFile, stat, rename } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const USER_AGENT = 'CodesparkQA-migration';
const OLD_HOST = 'blog.moneyfrog.in';
const MAX_RETRIES = 3;
const MAX_HOPS = 3;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/** An upload path is safe when it stays inside the output folder. */
export function isSafeUploadPath(p) {
  if (typeof p !== 'string' || p === '' || p.includes('\0') || p.includes('\\')) return false;
  if (p.startsWith('/') || /^[A-Za-z]:/.test(p)) return false;
  return p.split('/').every((seg) => seg !== '' && seg !== '.' && seg !== '..');
}

export const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'ico'];

export function isAllowedExtension(p) {
  const ext = path.extname(String(p)).slice(1).toLowerCase();
  return ALLOWED_EXTENSIONS.includes(ext);
}

/**
 * The bytes must look like the type the extension claims (rejects soft-404 HTML pages).
 * Pass contentType null to check the magic bytes only (files already on disk).
 */
export function looksLikeExpectedType(ext, contentType, body) {
  const ct = String(contentType ?? '').toLowerCase();
  const ctIs = (prefix) => contentType === null || ct.startsWith(prefix);
  const head = body.subarray(0, 16);
  const startsWith = (...bytes) => bytes.every((b, i) => head[i] === b);
  const raster = startsWith(0xff, 0xd8, 0xff) || startsWith(0x89, 0x50, 0x4e, 0x47) || startsWith(0x47, 0x49, 0x46, 0x38)
    || (head.subarray(0, 4).toString('latin1') === 'RIFF' && head.subarray(8, 12).toString('latin1') === 'WEBP');
  switch (ext) {
    // A raster extension accepts any raster format (the old blog has a real WebP saved as .jpeg).
    case 'jpg': case 'jpeg': case 'png': case 'gif': case 'webp': return ctIs('image/') && raster;
    case 'pdf': return ctIs('application/pdf') && head.subarray(0, 4).toString('latin1') === '%PDF';
    case 'ico': return ctIs('image/') && startsWith(0, 0, 1, 0);
    default: return false;
  }
}

/**
 * Polite read-only download of the media listed in a bundle. Plain GETs, fixed
 * User-Agent, no cookies or auth, >= delayMs between requests, 3 retries with
 * backoff on 5xx or network errors, only from the old blog host. Files already
 * on disk are skipped, so the run can be resumed. Writes <outDir>/media-manifest.json.
 */
export async function downloadMedia({
  bundlePath,
  outDir,
  delayMs = 1000,
  retryBaseMs = 2000,
  allowedHosts = [OLD_HOST],
  maxConsecutiveErrors = 5,
  fetchImpl = fetch,
  onProgress = () => {},
}) {
  const bundle = JSON.parse(await readFile(bundlePath, 'utf8'));
  const root = path.resolve(outDir);
  await mkdir(root, { recursive: true });
  const manifestPath = path.join(root, 'media-manifest.json');
  const manifest = {};
  let lastRequestAt = 0;
  let consecutiveErrors = 0;

  async function request(url) {
    const wait = lastRequestAt + delayMs - Date.now();
    if (wait > 0) await sleep(wait);
    lastRequestAt = Date.now();
    return fetchImpl(url, { method: 'GET', redirect: 'manual', headers: { 'User-Agent': USER_AGENT } });
  }

  /** Returns { status, body? }; status is a number or 'off-host'. */
  async function fetchOne(sourceUrl) {
    let url = sourceUrl;
    for (let hop = 0; hop <= MAX_HOPS; hop++) {
      let parsed;
      try { parsed = new URL(url); } catch { return { status: 'bad-url' }; }
      if (!['http:', 'https:'].includes(parsed.protocol) || !allowedHosts.includes(parsed.host)) return { status: 'off-host' };
      let res;
      for (let attempt = 0; ; attempt++) {
        try {
          res = await request(url);
        } catch (err) {
          if (attempt >= MAX_RETRIES) return { status: 'network-error', error: err.message };
          await sleep(retryBaseMs * 2 ** attempt);
          continue;
        }
        if (res.status >= 500 && attempt < MAX_RETRIES) {
          await sleep(retryBaseMs * 2 ** attempt);
          continue;
        }
        break;
      }
      if (res.status >= 300 && res.status < 400 && res.headers.get('location')) {
        url = new URL(res.headers.get('location'), url).toString();
        continue;
      }
      if (res.status === 200) return { status: 200, body: Buffer.from(await res.arrayBuffer()), contentType: res.headers.get('content-type') };
      return { status: res.status };
    }
    return { status: 'too-many-redirects' };
  }

  let done = 0;
  for (const m of bundle.media ?? []) {
    done++;
    if (!isSafeUploadPath(m.uploadPath)) {
      manifest[m.oldId] = { uploadPath: m.uploadPath ?? null, ok: false, status: 'bad-path', bytes: 0 };
      continue;
    }
    if (!isAllowedExtension(m.uploadPath)) {
      manifest[m.oldId] = { uploadPath: m.uploadPath, ok: false, status: 'blocked-extension', bytes: 0 };
      continue;
    }
    const dest = path.join(root, ...m.uploadPath.split('/'));
    if (path.relative(root, dest).startsWith('..')) {
      manifest[m.oldId] = { uploadPath: m.uploadPath, ok: false, status: 'bad-path', bytes: 0 };
      continue;
    }
    const existing = await stat(dest).catch(() => null);
    if (existing && existing.isFile() && existing.size > 0
      && looksLikeExpectedType(path.extname(m.uploadPath).slice(1).toLowerCase(), null, (await readFile(dest)).subarray(0, 16))) {
      manifest[m.oldId] = { uploadPath: m.uploadPath, ok: true, status: 200, bytes: existing.size };
      continue;
    }
    // A cached file that fails the check is fetched once more; if that is bad too it is recorded as bad-content.
    const result = await fetchOne(m.sourceUrl);
    if (result.status === 200 && !looksLikeExpectedType(path.extname(m.uploadPath).slice(1).toLowerCase(), result.contentType, result.body)) {
      manifest[m.oldId] = { uploadPath: m.uploadPath, ok: false, status: 'bad-content', bytes: 0 };
      consecutiveErrors = 0;
    } else if (result.status === 200) {
      await mkdir(path.dirname(dest), { recursive: true });
      await writeFile(`${dest}.part`, result.body);
      await rename(`${dest}.part`, dest);
      manifest[m.oldId] = { uploadPath: m.uploadPath, ok: true, status: 200, bytes: result.body.length };
      consecutiveErrors = 0;
    } else {
      manifest[m.oldId] = { uploadPath: m.uploadPath, ok: false, status: result.status, bytes: 0 };
      const hostTrouble = result.status === 429 || result.status === 'network-error' || (typeof result.status === 'number' && result.status >= 500);
      consecutiveErrors = hostTrouble ? consecutiveErrors + 1 : 0;
      if (consecutiveErrors >= maxConsecutiveErrors) {
        await writeFile(manifestPath, JSON.stringify(manifest, null, 2));
        throw new Error(`Stopping: ${consecutiveErrors} consecutive host errors (last status ${result.status}) at media ${m.oldId}`);
      }
    }
    onProgress(done, bundle.media.length, m, manifest[m.oldId]);
  }
  await writeFile(manifestPath, JSON.stringify(manifest, null, 2));
  return manifest;
}

// CLI: node migration/src/media.mjs [--bundle=<path>] [--out=<dir>] [--delay=<ms>]
if (process.argv[1] && import.meta.url === pathToFileURL(path.resolve(process.argv[1])).href) {
  const here = path.dirname(fileURLToPath(import.meta.url));
  const arg = (name, fallback) => process.argv.find((a) => a.startsWith(`--${name}=`))?.slice(name.length + 3) ?? fallback;
  const outRoot = path.join(here, '..', 'output');
  const bundlePath = arg('bundle', path.join(outRoot, 'bundle.json'));
  const outDir = arg('out', path.join(outRoot, 'media'));
  const delayMs = Math.max(1000, Number(arg('delay', '1000')) || 1000);
  (async () => {
    const manifest = await downloadMedia({
      bundlePath, outDir, delayMs,
      onProgress: (n, total, m, r) => { if (n % 25 === 0 || !r.ok) console.log(`${n}/${total} ${m.uploadPath} ${r.ok ? 'ok' : `FAILED ${r.status}`}`); },
    });
    const all = Object.values(manifest);
    const ok = all.filter((r) => r.ok);
    console.log(`media: ${ok.length} ok, ${all.length - ok.length} failed, ${ok.reduce((s, r) => s + r.bytes, 0)} bytes`);
  })().catch((err) => {
    console.error(err.message);
    process.exit(1);
  });
}
