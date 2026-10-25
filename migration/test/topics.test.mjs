import { describe, it, expect } from 'vitest';
import { mapTopic } from '../src/topics.mjs';

describe('mapTopic', () => {
  it('market outlooks leave financial planning', () => {
    const r = mapTopic({ type: 'post', title: 'Market Outlook – December 2025', categorySlugs: ['online-financial-planning'] });
    expect(r.topic).toBe('market-outlook');
    expect(mapTopic({ type: 'post', title: 'Weekly note', categorySlugs: ['market-market'] }).topic).toBe('market-outlook');
  });

  it('school page goes to guides', () => {
    expect(mapTopic({ type: 'page', title: 'Gilt Fund', categorySlugs: [] }).topic).toBe('guides');
    expect(mapTopic({ type: 'post', title: 'Chart pack', categorySlugs: ['infographics'] }).topic).toBe('guides');
    expect(mapTopic({ type: 'post', title: 'Questions', categorySlugs: ['faq'] }).topic).toBe('guides');
  });

  it('hinglish mf title maps to mutual-funds', () => {
    expect(mapTopic({ type: 'post', title: 'Kitna Sahi Hai Mutual Fund', categorySlugs: [] }).topic).toBe('mutual-funds');
  });

  it('adds mutual fund tags by title', () => {
    expect(mapTopic({ type: 'post', title: 'SIP in debt funds: direct vs regular', categorySlugs: ['mutual-funds'] }).tags)
      .toEqual(expect.arrayContaining(['sip', 'debt-funds', 'direct-vs-regular']));
    expect(mapTopic({ type: 'post', title: 'Equity fund basics', categorySlugs: ['mutual-funds'] }).tags).toEqual(['equity-funds']);
  });

  it('falls through tax, insurance, retirement, default', () => {
    expect(mapTopic({ type: 'post', title: 'Save more', categorySlugs: ['tax'] }).topic).toBe('tax');
    expect(mapTopic({ type: 'post', title: 'Cover', categorySlugs: ['health-insurance'] }).topic).toBe('insurance');
    expect(mapTopic({ type: 'post', title: 'NPS explained', categorySlugs: [] }).topic).toBe('retirement');
    expect(mapTopic({ type: 'post', title: 'Spend smart', categorySlugs: ['how-to'] }).topic).toBe('financial-planning');
  });
});
