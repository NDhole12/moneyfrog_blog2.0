import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { sanitize } from '../src/sanitize.mjs';
import { ALLOWLIST } from '../src/allowlist.mjs';

const fx = (n) => readFileSync(new URL(`./fixtures/${n}`, import.meta.url), 'utf8');
const opts = { allow: ALLOWLIST };

describe('sanitize', () => {
  it('removes german casino paragraph', () => {
    const r = sanitize(fx('dec-2025.html'), opts);
    expect(r.html.toLowerCase()).not.toContain('casino');
    expect(r.removed.filter((x) => x.kind === 'paragraph')).toHaveLength(1);
    expect(r.html).toContain('record low');
    expect(r.html).toContain('manufacturing PMI');
    expect(r.html).toContain('outflows');
    expect(r.html).toContain('Negatives in November');
  });

  it('removes german paragraph with off-list link even without a gambling term', () => {
    const html = '<p>Die Märkte sind nicht stabil, und der Anleger sieht sich mit Risiken konfrontiert. <a href="https://spam-example.invalid/">Mehr</a></p><p>Real English paragraph stays.</p>';
    const r = sanitize(html, opts);
    expect(r.html).not.toContain('Märkte');
    expect(r.html).toContain('Real English');
    expect(r.removed.filter((x) => x.kind === 'paragraph')).toHaveLength(1);
  });

  it('keeps hinglish and finance bonus', () => {
    const h = sanitize(fx('hinglish.html'), opts);
    expect(h.html).toContain('Kitna Sahi Hai Mutual Fund');
    expect(h.html).toContain('Health Cover Zaroori Hai');
    expect(h.removed).toHaveLength(0);
    const b = sanitize(fx('finance-bonus.html'), opts);
    expect(b.html).toContain('Pay hike or Bonus');
    expect(b.html).toContain('pay hike or a bonus');
    expect(b.removed).toHaveLength(0);
    expect(b.flags.filter((f) => f.kind === 'keyword').length).toBeLessThanOrEqual(1);
  });

  it('strips scripts handlers and hidden', () => {
    const html = `<p onclick="x()">Hi</p><script>alert(1)</script><style>p{}</style>
      <object data="a"></object><embed src="b">
      <div style="display:none">h1</div><div style="visibility: hidden">h2</div>
      <span style="font-size:0">h3</span><div style="position:absolute;left:-9999px">h4</div>
      <p hidden>h5</p><p>Visible</p>`;
    const r = sanitize(html, opts);
    for (const t of ['alert', 'onclick', 'h1', 'h2', 'h3', 'h4', 'h5', '<style', '<object', '<embed']) {
      expect(r.html).not.toContain(t);
    }
    expect(r.html).toContain('Visible');
    const kinds = r.removed.map((x) => x.kind);
    expect(kinds).toContain('script');
    expect(kinds).toContain('handler');
    expect(kinds).toContain('hidden');
  });

  it('keeps youtube iframe drops others', () => {
    const r = sanitize(fx('iframe-youtube.html'), opts);
    expect(r.html).toContain('youtube.com/embed/abc123');
    expect(r.html).not.toContain('widgets.example.invalid');
    expect(r.removed.filter((x) => x.kind === 'iframe')).toHaveLength(1);
  });

  it('unwraps non-allowlisted links keeps allowlisted', () => {
    const html = `<p><a href="https://random.example.invalid/x">outside</a> and <a href="https://www.amfiindia.com/a">amfi</a>
      and <a href="https://foo.gov.in/p">gov</a> and <a href="/relative">rel</a></p>`;
    const r = sanitize(html, opts);
    expect(r.html).toContain('outside');
    expect(r.html).not.toContain('random.example.invalid');
    expect(r.html).toContain('href="https://www.amfiindia.com/a"');
    expect(r.html).toContain('href="https://foo.gov.in/p"');
    expect(r.html).toContain('href="/relative"');
    expect(r.removed.filter((x) => x.kind === 'link')).toHaveLength(1);
  });

  it('is pure: same input gives same output', () => {
    const a = sanitize(fx('dec-2025.html'), opts);
    const b = sanitize(fx('dec-2025.html'), opts);
    expect(a).toEqual(b);
  });
});
