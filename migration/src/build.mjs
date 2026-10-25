import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import * as cheerio from 'cheerio';
import { sanitize } from './sanitize.mjs';
import { buildPlan } from './review.mjs';
import { slugify, cleanSlug, isSchoolPage, normalisePath } from './slugs.mjs';
import { renderRedirectsPhp, renderInventory, renderReport } from './render.mjs';

export { renderRedirectsPhp, renderInventory, renderReport };

const OLD_ORIGIN = 'https://blog.moneyfrog.in';
const UPLOADS = '/wp-content/uploads/';
const EXCERPT_MAX = 300;
const FALLBACK_WORDS = 40;
const TOPICS = ['market-outlook', 'mutual-funds', 'tax', 'insurance', 'retirement', 'financial-planning', 'guides'];

const plain = (html) => cheerio.load(String(html ?? ''), null, false).text().replace(/\s+/g, ' ').trim();
const safeDecode = (s) => { try { return decodeURIComponent(s); } catch { return s; } };
const oldUrl = (u) => `${OLD_ORIGIN}${new URL(u, OLD_ORIGIN).pathname}`;
const pathOf = (u) => new URL(u, OLD_ORIGIN).pathname;

/** Whole words only, at most `max` chars including the ellipsis. */
function clip(text, max = EXCERPT_MAX) {
  if (text.length <= max) return text;
  const cut = text.slice(0, max - 1);
  const space = cut.lastIndexOf(' ');
  return `${(space > 0 ? cut.slice(0, space) : cut).trimEnd()}…`;
}

function makeExcerpt(oldExcerptHtml, cleanHtml) {
  const $ = cheerio.load(sanitize(oldExcerptHtml).html, null, false);
  $('a.read-more-button').remove();
  const fromOld = $.text().replace(/\s+/g, ' ').replace(/\s*read more\s*[>»]?\s*$/i, '').trim();
  if (fromOld) return clip(fromOld);
  return clip(plain(cleanHtml).split(' ').slice(0, FALLBACK_WORDS).join(' '));
}

const bump = (obj, key) => { obj[key] = (obj[key] ?? 0) + 1; };

function buildMedia(media, warnings) {
  return [...media].sort((a, b) => a.id - b.id).map((m) => {
    const p = pathOf(m.source_url);
    const i = p.indexOf(UPLOADS);
    if (i < 0) warnings.push(`media ${m.id} is outside ${UPLOADS}: ${m.source_url}`);
    return {
      oldId: m.id, sourceUrl: m.source_url, uploadPath: i < 0 ? null : safeDecode(p.slice(i + UPLOADS.length)),
      alt: plain(m.alt_text), oldPaths: [p],
    };
  });
}

function buildBylines(users, items) {
  const used = new Set(items.map((i) => i.byline));
  const bySlug = new Map();
  for (const u of users) {
    const name = plain(u.name);
    const slug = slugify(name);
    if (used.has(slug)) bySlug.set(slug, { slug, name, bio: plain(u.description), role: '' });
  }
  return [...bySlug.values()].sort((a, b) => (a.slug < b.slug ? -1 : a.slug > b.slug ? 1 : 0));
}

/** Throws unless every old public URL still lands somewhere on the new site. */
function assertCoverage(inventory, { items, bylineSlugs, redirects, media }) {
  const covered = new Set([
    ...items.map((i) => `/${i.newSlug}/`),
    ...TOPICS.map((t) => `/category/${t}/`),
    ...[...bylineSlugs].map((b) => `/author/${b}/`),
    ...media.filter((m) => m.uploadPath).map((m) => normalisePath(`${UPLOADS}${m.uploadPath}`)),
    ...Object.keys(redirects),
  ]);
  const missing = inventory.filter((u) => !covered.has(normalisePath(new URL(u).pathname)));
  if (missing.length) throw new Error(`${missing.length} old URL(s) uncovered:\n${missing.join('\n')}`);
}

/** One record per slug collision: who won, who lost, and where each old URL now resolves. */
function buildSlugDecisions(assigned, items, redirects) {
  const byId = new Map(items.map((i) => [`${i.oldType}:${i.oldId}`, i]));
  const live = new Set(items.map((i) => `/${i.newSlug}/`));
  const resolve = (p) => {
    const k = normalisePath(p);
    return redirects[k] ?? (live.has(k) ? k : '(not resolved)');
  };
  const info = (a) => ({ oldId: a.oldId, type: a.type, title: byId.get(`${a.type}:${a.oldId}`).title, newSlug: a.newSlug });
  return assigned.filter((a) => a.newSlug !== a.wantedSlug).map((loser) => {
    const winner = assigned.find((a) => a.newSlug === loser.wantedSlug);
    const paths = [...new Set([...winner.oldPaths, ...loser.oldPaths, ...loser.droppedPaths])];
    return {
      slug: loser.wantedSlug, winner: info(winner), loser: info(loser),
      droppedPaths: loser.droppedPaths, resolutions: paths.map((p) => ({ path: p, to: resolve(p) })),
    };
  }).sort((x, y) => (x.slug < y.slug ? -1 : x.slug > y.slug ? 1 : x.loser.oldId - y.loser.oldId));
}

/** Pure: raw WordPress REST data in, bundle + generated file contents out. */
export function buildBundle({ posts, pages, media, categories, tags, users }) {
  const warnings = [];
  const cleanUsers = users.map((u) => ({ id: u.id, slug: u.slug, name: plain(u.name) }));
  const plan = buildPlan({ posts, pages, categories, users: cleanUsers });
  const sources = [
    ...posts.map((src) => ({ src, type: 'post' })),
    ...pages.filter((p) => isSchoolPage(p.link)).map((src) => ({ src, type: 'page' })),
  ];
  const tagSlug = new Map(tags.map((t) => [t.id, cleanSlug(t.slug)]));
  const authorSlug = new Map(cleanUsers.map((u) => [u.id, slugify(u.name)]));

  const removedCounts = {};
  const flagCounts = {};
  const topicCounts = {};
  const entries = [];

  const items = sources.map(({ src, type }, i) => {
    const a = plan.assigned[i];
    const row = plan.rows[i];
    if (a.oldId !== src.id || a.type !== type) throw new Error(`plan misaligned at ${i}`);
    const clean = sanitize(src.content.rendered);
    const title = plain(src.title.rendered);
    for (const r of clean.removed) bump(removedCounts, r.kind);
    for (const f of clean.flags) bump(flagCounts, f.kind);
    bump(topicCounts, row.new_topic);
    if (clean.removed.length || clean.flags.length) {
      entries.push({ oldId: src.id, title, oldUrl: oldUrl(src.link), removed: clean.removed, flags: clean.flags });
    }
    const byline = authorSlug.get(src.author);
    if (!byline) throw new Error(`item ${src.id} has unknown author ${src.author}`);
    const originalTags = (src.tags ?? []).map((id) => tagSlug.get(id)).filter(Boolean);
    return {
      oldId: src.id, oldType: type, newSlug: a.newSlug, title, html: clean.html,
      excerpt: makeExcerpt(src.excerpt?.rendered, clean.html),
      date: src.date, modified: src.modified, topic: row.new_topic,
      tags: [...new Set([...row.new_tags, ...originalTags])],
      byline, featuredMediaOldId: src.featured_media || null, oldPaths: a.oldPaths,
    };
  });

  // An item's live URL always wins over any redirect key that would shadow it.
  const live = new Set(items.map((it) => `/${it.newSlug}/`));
  const redirects = {};
  for (const [k, v] of Object.entries(plan.redirects)) {
    if (live.has(k)) warnings.push(`dropped redirect ${k} -> ${v}: it is a live item path`);
    else redirects[k] = v;
  }

  const mediaOut = buildMedia(media, warnings);
  const bylines = buildBylines(users, items);
  const bylineSlugs = new Set(bylines.map((b) => b.slug));
  // Authors with no migrated items have no archive on the new site: send them home.
  for (const u of users) {
    if (!bylineSlugs.has(slugify(plain(u.name)))) redirects[normalisePath(`/author/${u.slug}/`)] = '/';
  }
  const bundle = {
    generatedAt: items.map((i) => i.modified).sort().at(-1) ?? '',
    source: OLD_ORIGIN, items, bylines, media: mediaOut,
  };

  const inventory = [...new Set([
    ...posts.map((p) => oldUrl(p.link)),
    ...pages.map((p) => oldUrl(p.link)),
    ...categories.map((c) => oldUrl(c.link)),
    ...users.map((u) => `${OLD_ORIGIN}/author/${u.slug}/`),
    ...media.map((m) => oldUrl(m.source_url)),
  ])].sort();

  assertCoverage(inventory, { items, bylineSlugs, redirects, media: mediaOut });

  entries.sort((x, y) => x.oldId - y.oldId);
  const slugDecisions = buildSlugDecisions(plan.assigned, items, redirects);
  const reportData = { total: items.length, removedCounts, flagCounts, topicCounts, slugDecisions, entries };
  return { bundle, redirects, inventory, reportData, warnings };
}

function main() {
  const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
  const out = path.join(root, 'migration/output');
  const rd = (n) => JSON.parse(readFileSync(path.join(out, 'raw', `${n}.json`), 'utf8'));
  const r = buildBundle(Object.fromEntries(['posts', 'pages', 'media', 'categories', 'tags', 'users'].map((n) => [n, rd(n)])));
  mkdirSync(out, { recursive: true });
  const phpFile = path.join(root, 'wp-content/mu-plugins/moneyfrog-core/data/redirects.php');
  writeFileSync(path.join(out, 'bundle.json'), `${JSON.stringify(r.bundle, null, 2)}\n`, 'utf8');
  writeFileSync(path.join(out, 'report.html'), renderReport(r.reportData), 'utf8');
  writeFileSync(path.join(out, 'inventory.txt'), renderInventory(r.inventory), 'utf8');
  writeFileSync(phpFile, renderRedirectsPhp(r.redirects), 'utf8');
  const b = r.bundle;
  console.log('items', b.items.length, 'bylines', b.bylines.map((x) => x.slug), 'media', b.media.length);
  console.log('topics', r.reportData.topicCounts);
  console.log('redirects', Object.keys(r.redirects).length, 'inventory', r.inventory.length);
  console.log('removed', r.reportData.removedCounts, 'flags', r.reportData.flagCounts);
  console.log('items with removals/flags', r.reportData.entries.length);
  console.log('warnings', r.warnings);
}

if (process.argv[1] === fileURLToPath(import.meta.url)) main();
