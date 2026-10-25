const MARKET_TITLE = /market (outlook|update|commentary)|sensex|nifty|midcap|budget 20\d\d.*market/i;
const MF_TITLE = /mutual fund|\bsip\b|elss|debt fund|\bnfo\b/i;
const RETIRE_TITLE = /retire|nps|pension/i;

const BUDGET_TITLE = /budget\s*20\d\d|union budget|budget simplified|budget 20\d\d-\d\d/i;

/** Union Budget coverage is tax; personal-budgeting posts are not. */
const isBudgetTax = (title, cats) => BUDGET_TITLE.test(title) || (cats.includes('budget') && /20\d\d/.test(title));

const has = (slugs, ...names) => names.some((n) => slugs.includes(n));

function mutualFundTags(title) {
  return [
    /\bsip\b/i.test(title) && 'sip',
    /debt fund/i.test(title) && 'debt-funds',
    /equity fund/i.test(title) && 'equity-funds',
    /direct.*regular|regular.*direct/i.test(title) && 'direct-vs-regular',
  ].filter(Boolean);
}

/** First match wins. Returns the new topic slug and any extra tag slugs. */
export function mapTopic({ type, title = '', categorySlugs = [] }) {
  const cats = categorySlugs;
  if (type === 'page' || has(cats, 'infographics', 'faq', 'moneyfrog-school', 'financial-glossary')) return { topic: 'guides', tags: [] };
  if (MARKET_TITLE.test(title) || has(cats, 'market', 'market-market')) return { topic: 'market-outlook', tags: [] };
  if (has(cats, 'mutual-funds') || MF_TITLE.test(title)) return { topic: 'mutual-funds', tags: mutualFundTags(title) };
  if (has(cats, 'tax') || /\btax\b/i.test(title) || isBudgetTax(title, cats)) return { topic: 'tax', tags: [] };
  if (has(cats, 'insurance', 'health-insurance', 'life-insurance') || /insurance/i.test(title)) return { topic: 'insurance', tags: [] };
  if (has(cats, 'retirement') || RETIRE_TITLE.test(title)) return { topic: 'retirement', tags: [] };
  return { topic: 'financial-planning', tags: [] };
}
