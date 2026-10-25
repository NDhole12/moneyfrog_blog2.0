import { writeFileSync } from 'node:fs';
import { mapTopic } from './topics.mjs';

const FEFF = /﻿/g;

/** Mirrors MoneyfrogCore\Redirects::normalisePath() exactly. */
export function normalisePath(uri) {
  const noQuery = String(uri).split('?', 2)[0];
  const noHash = noQuery.split('#', 2)[0];
  const bytes = noHash.replace(/(?:%[0-9a-fA-F]{2})+/g, (m) =>
    Buffer.from(m.slice(1).split('%').map((h) => parseInt(h, 16))).toString('utf8'));
  const lowered = bytes.replace(FEFF, '').toLowerCase();
  const path = `/${lowered}`.replace(/\/{2,}/g, '/');
  return path.endsWith('/') ? path : `${path}/`;
}

export function cleanSlug(slug) {
  let s = String(slug);
  try {
    s = decodeURIComponent(s);
  } catch {
    s = s.replace(/%ef%bb%bf/gi, '');
  }
  return s.replace(FEFF, '').replace(/%ef%bb%bf/gi, '').toLowerCase()
    .replace(/[^a-z0-9-]/g, '-').replace(/-{2,}/g, '-').replace(/^-+|-+$/g, '');
}

export const slugify = (s) => cleanSlug(String(s).normalize('NFKD').replace(/[̀-ͯ]/g, ''));

const byDate = (a, b) => String(a.date).localeCompare(String(b.date)) || a.oldId - b.oldId;

const baseSlug = (it) => cleanSlug(it.slug) || `item-${it.oldId}`;

/** Oldest item keeps the bare slug; later collisions get -2, -3... */
export function assignSlugs(items) {
  const used = new Set();
  const chosen = new Map();
  const groups = new Map();
  for (const it of [...items].sort(byDate)) {
    const base = baseSlug(it);
    groups.set(base, [...(groups.get(base) ?? []), it]);
  }
  for (const [base, g] of groups) { used.add(base); chosen.set(g[0], base); }
  for (const [base, g] of groups) {
    for (const it of g.slice(1)) {
      let n = 2;
      while (used.has(`${base}-${n}`)) n += 1;
      used.add(`${base}-${n}`);
      chosen.set(it, `${base}-${n}`);
    }
  }
  const live = new Set([...chosen.values()].map((s) => `/${s}/`));
  return items.map((it) => {
    const own = `/${chosen.get(it)}/`;
    const oldPaths = [it.oldPath];
    const cleaned = cleanSlug(it.slug);
    const extra = it.type === 'page' ? `/moneyfrog-school/${cleaned}/` : `/${cleaned}/`;
    if (cleaned && !oldPaths.includes(extra)) oldPaths.push(extra);
    // A path that is another item's live URL must not redirect away from it.
    const kept = oldPaths.filter((p) => { const n = normalisePath(p); return n === own || !live.has(n); });
    return { oldId: it.oldId, type: it.type, newSlug: chosen.get(it), oldPaths: kept };
  });
}

const pathOf = (link) => {
  try { return new URL(link, 'https://placeholder.invalid').pathname; } catch { return String(link); }
};

/** Old category paths (incl. nested and duplicate `parent/parent` forms) -> topic. */
export function buildCategoryMap(categories) {
  const map = {};
  for (const c of categories) {
    const topic = mapTopic({ type: 'post', title: '', categorySlugs: [c.slug] }).topic;
    const path = pathOf(c.link);
    map[path] = topic;
    const parts = path.split('/').filter(Boolean);
    const parent = parts.length === 3 ? parts[1] : null;
    if (parent && c.slug === `${parent}-${parent}`) map[`/category/${parent}/${parent}/`] = topic;
  }
  return map;
}

export function buildAuthorMap(users) {
  return Object.fromEntries(users.map((u) => [u.slug, slugify(u.name)]));
}

const SCHOOL = '/moneyfrog-school/';
export const isSchoolPage = (link) => {
  const p = normalisePath(pathOf(link));
  return p.startsWith(SCHOOL) && p !== SCHOOL;
};

/** Redirects for old pages that are NOT migrated as Guides posts. */
export function pageRedirects(pages) {
  const out = {};
  for (const pg of pages) {
    if (isSchoolPage(pg.link)) continue;
    const key = normalisePath(pathOf(pg.link));
    if (key === SCHOOL) out[key] = '/category/guides/';
    else if (/^\/contact(-us)?\/$/.test(key)) out[key] = 'https://moneyfrog.in/contact-us';
    else out[key] = '/';
  }
  return out;
}

export function buildRedirects(assigned, categoryMap, authorMap, pages = []) {
  const map = {};
  const put = (from, to) => { const k = normalisePath(from); if (k !== to) map[k] = to; };
  for (const a of assigned) for (const p of a.oldPaths) put(p, `/${a.newSlug}/`);
  for (const [p, topic] of Object.entries(categoryMap)) put(p, `/category/${topic}/`);
  for (const [oldSlug, byline] of Object.entries(authorMap)) put(`/author/${oldSlug}/`, `/author/${byline}/`);
  for (const [k, v] of Object.entries(pageRedirects(pages))) map[k] = v;
  return map;
}

const csvCell = (v) => {
  const s = Array.isArray(v) ? v.join('|') : String(v ?? '');
  return /[",\n\r]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
};
export const REVIEW_COLUMNS = ['old_id', 'type', 'date', 'title', 'old_categories', 'new_topic', 'new_tags', 'new_slug', 'flags'];

export function writeReviewCsv(rows, file) {
  const lines = [REVIEW_COLUMNS.join(',')];
  for (const r of rows) lines.push(REVIEW_COLUMNS.map((c) => csvCell(r[c])).join(','));
  writeFileSync(file, `${lines.join('\n')}\n`, 'utf8');
}
