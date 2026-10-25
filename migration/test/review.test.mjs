import { describe, it, expect } from 'vitest';
import { buildPlan } from '../src/review.mjs';

describe('buildPlan', () => {
  it('replaces a __trashed slug with the title slug and flags collisions', () => {
    const post = (id, slug, title, date) => ({ id, slug, date, link: `https://blog.moneyfrog.in/${slug}/`, title: { rendered: title }, categories: [] });
    const plan = buildPlan({
      posts: [post(1, '__trashed', 'Market Outlook &#8211; October 2023', '2023-10-03T00:00:00')],
      pages: [{ id: 9, slug: 'moneyfrog-school', date: '2020-01-01', link: 'https://blog.moneyfrog.in/moneyfrog-school/', title: { rendered: 'Moneyfrog School' } }],
      categories: [], users: [],
    });
    expect(plan.rows[0].new_slug).toBe('market-outlook-october-2023');
    expect(plan.rows).toHaveLength(1);
    expect(plan.redirects['/moneyfrog-school/']).toBe('/category/guides/');
  });
});
