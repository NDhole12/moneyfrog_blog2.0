import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const TYPES = ['posts', 'pages', 'media', 'categories', 'tags', 'users'];
const USER_AGENT = 'CodesparkQA-migration';
const MAX_RETRIES = 3;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/**
 * Polite read-only crawl of the WP REST API. Plain GETs, fixed User-Agent,
 * no cookies or auth, >= delayMs between requests, retries on 5xx/network errors.
 */
export async function fetchAll({ base, outDir, fetchImpl = fetch, delayMs = 1000, retryBaseMs = 2000 }) {
  const root = base.replace(/\/+$/, '');
  const rawDir = path.join(outDir, 'raw');
  await mkdir(rawDir, { recursive: true });
  let lastRequestAt = 0;

  async function get(url) {
    for (let attempt = 0; ; attempt++) {
      const wait = lastRequestAt + delayMs - Date.now();
      if (wait > 0) await sleep(wait);
      lastRequestAt = Date.now();
      let res;
      try {
        res = await fetchImpl(url, { method: 'GET', headers: { 'User-Agent': USER_AGENT, Accept: 'application/json' } });
      } catch (err) {
        if (attempt >= MAX_RETRIES) throw new Error(`Network error for ${url}: ${err.message}`);
        await sleep(retryBaseMs * 2 ** attempt);
        continue;
      }
      if (res.status >= 500) {
        if (attempt >= MAX_RETRIES) throw new Error(`HTTP ${res.status} for ${url} after ${MAX_RETRIES} retries`);
        await sleep(retryBaseMs * 2 ** attempt);
        continue;
      }
      if (!res.ok) throw new Error(`HTTP ${res.status} for ${url} (not retried)`);
      return res;
    }
  }

  const counts = {};
  for (const type of TYPES) {
    const items = [];
    let totalPages = 1;
    for (let page = 1; page <= totalPages; page++) {
      const url = `${root}/wp-json/wp/v2/${type}?per_page=100&page=${page}&context=view`;
      const res = await get(url);
      totalPages = Number(res.headers.get('x-wp-totalpages')) || 1;
      items.push(...(await res.json()));
    }
    await writeFile(path.join(rawDir, `${type}.json`), JSON.stringify(items));
    counts[type] = items.length;
  }
  await writeFile(
    path.join(rawDir, 'fetch-summary.json'),
    JSON.stringify({ base: root, fetchedAt: new Date().toISOString(), counts }, null, 2),
  );
  return counts;
}

if (process.argv[1] && fileURLToPath(import.meta.url) === path.resolve(process.argv[1])) {
  const base = process.env.OLD_BLOG_BASE || 'https://blog.moneyfrog.in';
  const outDir = process.env.MIGRATION_OUT || path.resolve('output');
  fetchAll({ base, outDir })
    .then((c) => console.log(JSON.stringify(c)))
    .catch((e) => {
      console.error(e.message);
      process.exit(1);
    });
}
