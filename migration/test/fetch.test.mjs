import { describe, it, expect } from 'vitest';
import { mkdtemp, readFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fetchAll } from '../src/fetch.mjs';

const mkRes = (status, body, pages) => ({
  status,
  ok: status < 400,
  headers: { get: (k) => (k.toLowerCase() === 'x-wp-totalpages' ? String(pages) : null) },
  json: async () => body,
});

describe('fetchAll', () => {
  it('paginates, retries 5xx, sends only the UA and writes raw files', async () => {
    const calls = [];
    let failedOnce = false;
    const fetchImpl = async (url, init) => {
      calls.push({ url, init });
      if (url.includes('/posts') && !failedOnce) {
        failedOnce = true;
        return mkRes(503, [], 1);
      }
      const page = Number(new URL(url).searchParams.get('page'));
      return mkRes(200, [{ id: page }], url.includes('/posts') ? 2 : 1);
    };
    const outDir = await mkdtemp(path.join(tmpdir(), 'cs-fetch-'));
    const counts = await fetchAll({ base: 'https://old.example.invalid/', outDir, fetchImpl, delayMs: 0, retryBaseMs: 1 });
    expect(counts.posts).toBe(2);
    expect(counts.users).toBe(1);
    expect(calls.every((c) => c.init.headers['User-Agent'] === 'CodesparkQA-migration')).toBe(true);
    expect(calls.every((c) => !('Cookie' in c.init.headers) && !('Authorization' in c.init.headers))).toBe(true);
    const summary = JSON.parse(await readFile(path.join(outDir, 'raw', 'fetch-summary.json'), 'utf8'));
    expect(summary.counts.posts).toBe(2);
    expect(summary.fetchedAt).toBeTruthy();
    expect(JSON.parse(await readFile(path.join(outDir, 'raw', 'posts.json'), 'utf8'))).toHaveLength(2);
  });

  it('gives up after 3 retries on persistent 5xx', async () => {
    const outDir = await mkdtemp(path.join(tmpdir(), 'cs-fetch-'));
    let n = 0;
    const fetchImpl = async () => (n++, mkRes(500, [], 1));
    await expect(fetchAll({ base: 'https://old.example.invalid', outDir, fetchImpl, delayMs: 0, retryBaseMs: 1 })).rejects.toThrow(/HTTP 500/);
    expect(n).toBe(4);
  });
});
