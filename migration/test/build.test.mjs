import { describe, it, expect } from 'vitest';
import { buildBundle, renderRedirectsPhp, renderInventory, renderReport } from '../src/build.mjs';
import { normalisePath } from '../src/slugs.mjs';

const H = 'https://blog.moneyfrog.in';
const rendered = (s) => ({ rendered: s });
const post = (o) => ({
  status: 'publish', date: '2024-03-01T10:00:00', modified: '2024-03-02T10:00:00', categories: [], tags: [], featured_media: 0,
  author: 2, excerpt: rendered(''), content: rendered('<p>Body</p>'), ...o,
  title: rendered(o.title), link: `${H}/${o.slug}/`,
});

function raw() {
  return {
    posts: [
      post({
        id: 1, slug: 'sip-basics', title: 'SIP basics &#8211; a guide', tags: [10, 11], featured_media: 500,
        categories: [5],
        excerpt: rendered('<p>Start a SIP &amp; stay put&#8230; <a class="read-more-button" href="x">Read more &gt;</a></p>'),
        content: rendered('<p>Real text.</p><p>Die besten Online Casino Spiele und Freispiele: Hier ist der Bonus <a href="https://casino-example.invalid/">jetzt spielen</a></p><script>alert(1)</script>'),
      }),
      post({ id: 2, slug: 'market-outlook-may-2024%ef%bb%bf', title: 'Market Outlook &#8211; May 2024', author: 3, content: rendered('<p>One two three four five six seven eight nine ten.</p>') }),
      post({ id: 3, slug: 'gilt-fund', title: 'Gilt fund (post)', date: '2024-01-01T00:00:00' }),
    ],
    pages: [
      { id: 90, slug: 'gilt-fund', status: 'publish', date: '2023-01-01T00:00:00', modified: '2023-01-02T00:00:00', author: 2, featured_media: 0, link: `${H}/moneyfrog-school/gilt-fund/`, title: rendered('Gilt Fund'), excerpt: rendered(''), content: rendered('<p>School page.</p>') },
      { id: 91, slug: 'moneyfrog-school', status: 'publish', date: '2023-01-01T00:00:00', modified: '2023-01-02T00:00:00', author: 2, link: `${H}/moneyfrog-school/`, title: rendered('Moneyfrog School'), excerpt: rendered(''), content: rendered('') },
    ],
    media: [
      { id: 500, source_url: `${H}/wp-content/uploads/2019/01/sip.jpg`, alt_text: 'A SIP chart', media_type: 'image' },
      { id: 501, source_url: `${H}/wp-content/uploads/2025/09/Post-%E2%80%93-One.jpg`, alt_text: '', media_type: 'image' },
    ],
    categories: [
      { id: 5, slug: 'mutual-funds', link: `${H}/category/mutual-funds/` },
      { id: 6, slug: 'market', link: `${H}/category/market/` },
    ],
    tags: [{ id: 10, slug: 'sip' }, { id: 11, slug: 'goal-setting' }],
    users: [
      { id: 2, name: 'Harshada Kadam', slug: 'harshada', description: ' She works on &amp; leads <b>marketing</b>. ', email: 'h@example.invalid', user_login: 'hk' },
      { id: 3, name: 'Mushtaq Kazi', slug: 'mushtaq', description: '' },
      { id: 4, name: 'No Posts', slug: 'nobody', description: 'unused' },
    ],
  };
}

describe('bundle', () => {
  it('bundle has no secrets and every old path redirects', () => {
    const { bundle, redirects } = buildBundle(raw());
    const json = JSON.stringify(bundle);
    expect(json).not.toMatch(/"(email|password|user_login|user_pass)"/);
    expect(json).not.toContain('casino-example');
    expect(json).not.toContain('h@example');
    expect(json).not.toContain('"id"');
    for (const it of bundle.items) {
      for (const p of it.oldPaths) {
        const k = normalisePath(p);
        expect(k === `/${it.newSlug}/` || k in redirects, `${p} -> ${it.newSlug}`).toBe(true);
      }
    }
  });

  it('builds items with sanitised html, excerpt, tags and featured media', () => {
    const { bundle } = buildBundle(raw());
    const sip = bundle.items.find((i) => i.oldId === 1);
    expect(sip).toMatchObject({
      oldType: 'post', newSlug: 'sip-basics', title: 'SIP basics – a guide', topic: 'mutual-funds',
      byline: 'harshada-kadam', featuredMediaOldId: 500, date: '2024-03-01T10:00:00', modified: '2024-03-02T10:00:00',
    });
    expect(sip.html).toContain('Real text.');
    expect(sip.html).not.toMatch(/casino|script|alert/i);
    expect(sip.excerpt).toBe('Start a SIP & stay put…');
    expect([...sip.tags].sort()).toEqual(['goal-setting', 'sip']);
    expect(new Set(sip.tags).size).toBe(sip.tags.length);
    const mo = bundle.items.find((i) => i.oldId === 2);
    expect(mo.newSlug).toBe('market-outlook-may-2024');
    expect(mo.excerpt).toBe('One two three four five six seven eight nine ten.');
    expect(mo.featuredMediaOldId).toBeNull();
    const page = bundle.items.find((i) => i.oldId === 90);
    expect(page).toMatchObject({ oldType: 'page', topic: 'guides', newSlug: 'gilt-fund' });
    expect(bundle.items.find((i) => i.oldId === 3).newSlug).toBe('gilt-fund-2');
    expect(bundle.items.some((i) => i.oldId === 91)).toBe(false);
  });

  it('caps excerpts at 300 chars on a word boundary', () => {
    const r = raw();
    r.posts[1].excerpt = rendered(`<p>${'word '.repeat(200)}</p>`);
    const ex = buildBundle(r).bundle.items.find((i) => i.oldId === 2).excerpt;
    expect(ex.length).toBeLessThanOrEqual(300);
    expect(ex).toMatch(/word…?$/);
  });

  it('bylines: only authors with items, slug from name, plain-text bio, empty role', () => {
    const { bundle } = buildBundle(raw());
    expect(bundle.bylines).toEqual([
      { slug: 'harshada-kadam', name: 'Harshada Kadam', bio: 'She works on & leads marketing.', role: '' },
      { slug: 'mushtaq-kazi', name: 'Mushtaq Kazi', bio: '', role: '' },
    ]);
  });

  it('media keeps the original upload path and is not redirected', () => {
    const { bundle, redirects } = buildBundle(raw());
    expect(bundle.media).toEqual([
      { oldId: 500, sourceUrl: `${H}/wp-content/uploads/2019/01/sip.jpg`, uploadPath: '2019/01/sip.jpg', alt: 'A SIP chart', oldPaths: ['/wp-content/uploads/2019/01/sip.jpg'] },
      { oldId: 501, sourceUrl: `${H}/wp-content/uploads/2025/09/Post-%E2%80%93-One.jpg`, uploadPath: '2025/09/Post-–-One.jpg', alt: '', oldPaths: ['/wp-content/uploads/2025/09/Post-%E2%80%93-One.jpg'] },
    ]);
    expect(Object.keys(redirects).some((k) => k.includes('wp-content'))).toBe(false);
  });

  it('inventory lists every old public URL, sorted and unique', () => {
    const { inventory } = buildBundle(raw());
    expect(inventory).toEqual([...new Set(inventory)].sort());
    expect(inventory).toEqual(expect.arrayContaining([
      `${H}/sip-basics/`, `${H}/market-outlook-may-2024%ef%bb%bf/`,
      `${H}/moneyfrog-school/`, `${H}/moneyfrog-school/gilt-fund/`, `${H}/category/market/`,
      `${H}/author/harshada/`, `${H}/author/nobody/`, `${H}/wp-content/uploads/2019/01/sip.jpg`,
    ]));
    expect(inventory.every((u) => u.startsWith(`${H}/`))).toBe(true);
    expect(renderInventory(inventory).endsWith('\n')).toBe(true);
  });

  it('redirects.php is sorted, escaped and host-free except allowed targets', () => {
    const php = renderRedirectsPhp({ '/b/': '/x/', "/a'b/": '/y/', '/contact-us/': 'https://moneyfrog.in/contact-us' });
    expect(php.startsWith('<?php')).toBe(true);
    expect(php).toContain('migration/src/build.mjs');
    expect(php).not.toMatch(/20\d\d-\d\d-\d\d/);
    expect(php.indexOf("'/a\\'b/'")).toBeGreaterThan(-1);
    expect(php.indexOf("'/a\\'b/'")).toBeLessThan(php.indexOf("'/b/'"));
    const { redirects } = buildBundle(raw());
    for (const v of Object.values(redirects).filter((t) => !t.startsWith('/'))) {
      expect(v).toBe('https://moneyfrog.in/contact-us');
    }
  });

  it('report counts removals and flags and escapes excerpts', () => {
    const { reportData } = buildBundle(raw());
    const html = renderReport(reportData);
    expect(html).toContain('<!doctype html>');
    expect(html).not.toMatch(/<script|src=|href="http/i);
    expect(html).toContain('SIP basics');
    expect(html).toContain('script');
  });

  it('is deterministic', () => {
    const a = buildBundle(raw());
    const b = buildBundle(raw());
    expect(JSON.stringify(a.bundle)).toBe(JSON.stringify(b.bundle));
    expect(renderRedirectsPhp(a.redirects)).toBe(renderRedirectsPhp(b.redirects));
    expect(renderReport(a.reportData)).toBe(renderReport(b.reportData));
    expect(a.inventory).toEqual(b.inventory);
  });
});
