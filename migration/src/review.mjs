import { readFileSync, mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { mapTopic } from './topics.mjs';
import {
  assignSlugs, cleanSlug, slugify, buildRedirects, buildCategoryMap, buildAuthorMap, pageRedirects, isSchoolPage, writeReviewCsv, normalisePath,
} from './slugs.mjs';

const decode = (s) => s.replace(/&#(\d+);/g, (_, n) => String.fromCodePoint(+n))
  .replace(/&amp;/g, '&').replace(/&nbsp;/g, ' ');
/** WordPress leaves '__trashed' on restored posts; fall back to the title. */
const usableSlug = (src) => {
  const c = cleanSlug(src.slug);
  return c === '' || c === 'trashed' ? slugify(decode(src.title.rendered)) : src.slug;
};
const pathOf = (l) => new URL(l).pathname;

/** Pure: raw WordPress REST data in, migration plan out. */
export function buildPlan({ posts, pages, categories, users }) {
  const catById = new Map(categories.map((c) => [c.id, c]));
  const items = [
    ...posts.map((p) => ({ src: p, type: 'post' })),
    ...pages.filter((p) => isSchoolPage(p.link)).map((p) => ({ src: p, type: 'page' })),
  ].map(({ src, type }) => ({
    oldId: src.id, type, slug: usableSlug(src), oldPath: pathOf(src.link), date: src.date,
    title: decode(src.title.rendered), catSlugs: (src.categories ?? []).map((id) => catById.get(id)?.slug).filter(Boolean),
  }));
  const assigned = assignSlugs(items);
  const rows = items.map((it, i) => {
    const t = mapTopic({ type: it.type, title: it.title, categorySlugs: it.catSlugs });
    const a = assigned[i];
    const flags = [
      /%ef%bb%bf|﻿/i.test(it.oldPath) && 'bom-slug',
      a.newSlug !== cleanSlug(it.slug) && 'slug-collision',
      t.topic === 'financial-planning' && 'default-topic',
    ].filter(Boolean);
    return {
      old_id: it.oldId, type: it.type, date: it.date, title: it.title, old_categories: it.catSlugs,
      new_topic: t.topic, new_tags: t.tags, new_slug: a.newSlug, flags,
    };
  });
  const redirects = buildRedirects(assigned, buildCategoryMap(categories), buildAuthorMap(users), pages);
  return { assigned, rows, redirects, nonMigrated: pages.filter((p) => !isSchoolPage(p.link)) };
}

function main() {
  const dir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../output');
  const rd = (n) => JSON.parse(readFileSync(path.join(dir, 'raw', `${n}.json`), 'utf8'));
  const plan = buildPlan({ posts: rd('posts'), pages: rd('pages'), categories: rd('categories'), users: rd('users') });
  mkdirSync(dir, { recursive: true });
  writeReviewCsv(plan.rows, path.join(dir, 'review.csv'));
  const count = {};
  plan.rows.forEach((r) => { count[r.new_topic] = (count[r.new_topic] ?? 0) + 1; });
  const coll = plan.rows.filter((r) => r.flags.includes('slug-collision'));
  const kind = { item: 0, category: 0, author: 0, page: 0 };
  const pr = pageRedirects(plan.nonMigrated);
  for (const k of Object.keys(plan.redirects)) {
    if (k.startsWith('/category/')) kind.category += 1;
    else if (k.startsWith('/author/')) kind.author += 1;
    else if (k in pr) kind.page += 1;
    else kind.item += 1;
  }
  console.log('topics', count);
  console.log('collisions', coll.length, coll.map((r) => `${r.old_id}:${r.type}:${r.new_slug}`));
  console.log('bom slugs', plan.rows.filter((r) => r.flags.includes('bom-slug')).length);
  console.log('redirects', Object.keys(plan.redirects).length, kind);
  console.log('non-migrated pages', plan.nonMigrated.map((p) => `${decode(p.title.rendered)} | ${normalisePath(pathOf(p.link))} -> ${pr[normalisePath(pathOf(p.link))]}`));
}

if (process.argv[1] === fileURLToPath(import.meta.url)) main();
