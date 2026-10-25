import { describe, it, expect } from 'vitest';
import { sanitize } from '../src/sanitize.mjs';
import { ALLOWLIST } from '../src/allowlist.mjs';

const opts = { allow: ALLOWLIST };
const DE = 'Die besten Spiele und Freispiele, wie es sich nicht anders gehört, mit der Wette';
const link = '<a href="https://casino-example.invalid/">spiel</a>';
const paras = (r) => r.removed.filter((x) => x.kind === 'paragraph');

describe('sanitize: mixed-block and root-level runs', () => {
  it('removes spam text before a block child, keeps the English child', () => {
    const r = sanitize(`<div>${DE} ${link}<p>English body stays.</p></div>`, opts);
    expect(r.html).not.toMatch(/Freispiele|spiel/);
    expect(r.html).toContain('<p>English body stays.</p>');
    expect(paras(r)).toHaveLength(1);
  });

  it('removes root-level German spam with a link and no wrapper', () => {
    const r = sanitize(`${DE} ${link}<p>English stays.</p>`, opts);
    expect(r.html).not.toMatch(/Freispiele/);
    expect(r.html).toContain('English stays');
    expect(paras(r)).toHaveLength(1);
  });

  it('removes a spam lead run of an outer li and keeps the nested list', () => {
    const r = sanitize(`<ul><li>${DE} ${link}<ul><li>Clean nested point.</li></ul></li></ul>`, opts);
    expect(r.html).not.toMatch(/Freispiele/);
    expect(r.html).toContain('Clean nested point');
    expect(paras(r)).toHaveLength(1);
  });

  it('judges a span nested inside a mixed container', () => {
    const r = sanitize(`<div><span>${DE} ${link}</span><p>Fine.</p></div>`, opts);
    expect(r.html).not.toMatch(/Freispiele/);
    expect(r.html).toContain('Fine.');
  });

  it('keeps a legitimate English mixed block', () => {
    const r = sanitize('<div>Intro text <p>Body</p></div>', opts);
    expect(r.removed).toHaveLength(0);
    expect(r.flags).toHaveLength(0);
    expect(r.html).toContain('Intro text');
    expect(r.html).toContain('<p>Body</p>');
  });

  it('applies contagion to runs', () => {
    const r = sanitize(`<div>${DE} nur Text.<p>English.</p></div><p>${DE} ${link}</p>`, opts);
    expect(paras(r)).toHaveLength(2);
    expect(r.html).not.toMatch(/nur Text/);
    expect(r.html).toContain('English.');
  });

  it('drops a hidden-colour flag when its block is later removed as spam', () => {
    const r = sanitize(`<div><span style="color:#fff">${DE} ${link}</span></div><p style="color:white">pale ok</p>`, opts);
    expect(paras(r)).toHaveLength(1);
    const colour = r.flags.filter((f) => f.kind === 'hidden-colour');
    expect(colour).toHaveLength(1);
    expect(colour[0].excerpt).toContain('pale ok');
  });
});
