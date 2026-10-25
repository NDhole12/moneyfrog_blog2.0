import { describe, it, expect } from 'vitest';
import { cleanSlug, assignSlugs, buildRedirects, normalisePath, pageRedirects, buildCategoryMap } from '../src/slugs.mjs';

describe('normalisePath (parity with Redirects::normalisePath)', () => {
  it.each([
    ['/market-outlook-december-2025%ef%bb%bf/', '/market-outlook-december-2025/'],
    ['/market-outlook-december-2025﻿', '/market-outlook-december-2025/'],
    ['//A//B?x=1', '/a/b/'],
    ['', '/'],
    ['/?utm_source=x', '/'],
    ['/Moneyfrog-School/Gilt-Fund', '/moneyfrog-school/gilt-fund/'],
    ['/a/b#frag', '/a/b/'],
  ])('%j', (input, expected) => expect(normalisePath(input)).toBe(expected));
});

describe('slugs', () => {
  it('bom slug cleaned', () => {
    expect(cleanSlug('market-outlook-december-2025%ef%bb%bf')).toBe('market-outlook-december-2025');
    expect(cleanSlug('Market﻿ Outlook!')).toBe('market-outlook');
    const [a] = assignSlugs([{ oldId: 1, type: 'post', slug: 'x%ef%bb%bf', oldPath: '/x%ef%bb%bf/', date: '2025-01-01' }]);
    expect(a.newSlug).toBe('x');
    expect(a.oldPaths).toEqual(['/x%ef%bb%bf/', '/x/']);
  });

  it('collision keeps both and maps each old URL', () => {
    const assigned = assignSlugs([
      { oldId: 2, type: 'page', slug: 'gilt-fund', oldPath: '/moneyfrog-school/gilt-fund/', date: '2026-02-01' },
      { oldId: 1, type: 'post', slug: 'gilt-fund', oldPath: '/gilt-fund/', date: '2020-01-01' },
    ]);
    const bySlug = Object.fromEntries(assigned.map((a) => [a.oldId, a.newSlug]));
    expect(bySlug).toEqual({ 1: 'gilt-fund', 2: 'gilt-fund-2' });
    const map = buildRedirects(assigned, {}, {});
    expect(map['/moneyfrog-school/gilt-fund/']).toBe('/gilt-fund-2/');
    expect(map['/gilt-fund/']).toBeUndefined(); // identity skipped
  });

  it('category redirects', () => {
    const cats = [
      { id: 509, slug: 'market', link: 'https://blog.moneyfrog.in/category/market/' },
      { id: 543, slug: 'market-market', link: 'https://blog.moneyfrog.in/category/market/market-market/' },
      { id: 68, slug: 'insurance', link: 'https://blog.moneyfrog.in/category/insurance/' },
      { id: 69, slug: 'health-insurance', link: 'https://blog.moneyfrog.in/category/insurance/health-insurance/' },
    ];
    const catMap = buildCategoryMap(cats);
    const map = buildRedirects([], catMap, { mushtaq: 'mushtaq-kazi', same: 'same' });
    expect(map['/category/market/']).toBe('/category/market-outlook/');
    expect(map['/category/market/market-market/']).toBe('/category/market-outlook/');
    expect(map['/category/market/market/']).toBe('/category/market-outlook/');
    expect(map['/category/insurance/health-insurance/']).toBe('/category/insurance/');
    expect(map['/category/insurance/']).toBeUndefined();
    expect(map['/author/mushtaq/']).toBe('/author/mushtaq-kazi/');
    expect(map['/author/same/']).toBeUndefined();
  });

  it('page redirects for non-migrated pages', () => {
    const r = pageRedirects([
      { title: 'Moneyfrog School', link: 'https://blog.moneyfrog.in/moneyfrog-school/' },
      { title: 'Contact Us', link: 'https://blog.moneyfrog.in/contact-us/' },
      { title: 'About', link: 'https://blog.moneyfrog.in/about/' },
      { title: 'Gilt', link: 'https://blog.moneyfrog.in/moneyfrog-school/gilt-fund/' },
    ]);
    expect(r).toEqual({
      '/moneyfrog-school/': '/category/guides/',
      '/contact-us/': 'https://moneyfrog.in/contact-us',
      '/about/': '/',
    });
  });
});
