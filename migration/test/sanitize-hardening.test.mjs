import { describe, it, expect } from 'vitest';
import { sanitize } from '../src/sanitize.mjs';
import { ALLOWLIST } from '../src/allowlist.mjs';

const opts = { allow: ALLOWLIST };
const DE = 'Die besten Spiele und Freispiele, wie es sich nicht anders gehört, mit der Wette';
const kinds = (r, k) => r.removed.filter((x) => x.kind === k);

describe('sanitize: full removal log', () => {
  it('logs tags dropped by the final whitelist, unsafe hrefs and style attributes', () => {
    const html = `<form><input name="a"><button>Go</button></form><noscript>n</noscript><base href="x"><meta name="a">
      <p style="color:red">One</p><p style="margin:0">Two</p>
      <p><a href="javascript:alert(1)">js</a> <a href="data:text/html,x">dt</a> <a href="  JaVaScRiPt:1">js2</a></p>`;
    const r = sanitize(html, opts);
    const tags = kinds(r, 'tag').map((x) => x.detail);
    for (const t of ['form', 'input', 'button', 'base', 'meta']) expect(tags).toContain(t);
    expect(kinds(r, 'script').map((x) => x.detail)).toContain('noscript');
    expect(kinds(r, 'link')).toHaveLength(3);
    expect(r.html).not.toMatch(/javascript:|data:text/i);
    expect(r.html).toContain('js');
    const style = kinds(r, 'style');
    expect(style).toHaveLength(1);
    expect(style[0].detail).toContain('2');
  });

  it('normalises protocol-relative youtube iframe and keeps it', () => {
    const r = sanitize('<iframe src="//www.youtube.com/embed/x"></iframe>', opts);
    expect(r.html).toContain('src="https://www.youtube.com/embed/x"');
    expect(kinds(r, 'iframe')).toHaveLength(0);
  });
});

describe('sanitize: link host checks', () => {
  it('unwraps look-alike hosts, protocol-relative off-list links', () => {
    const html = `<p><a href="https://evilmoneyfrog.in/x">a1</a> <a href="https://moneyfrog.in.evil.com/x">a2</a>
      <a href="//evil.com/x">a3</a> <a href="https://blog.moneyfrog.in/ok">fine</a></p>`;
    const r = sanitize(html, opts);
    expect(kinds(r, 'link')).toHaveLength(3);
    expect(r.html).not.toContain('evil');
    expect(r.html).toContain('href="https://blog.moneyfrog.in/ok"');
    for (const t of ['a1', 'a2', 'a3']) expect(r.html).toContain(t);
  });
});

describe('sanitize: hidden content', () => {
  it('removes opacity:0, left:-99px, collapse, zero-size clip, text-indent, escaped and commented CSS', () => {
    const cases = [
      '<div style="opacity:0">h</div>',
      '<div style="position:absolute;left:-99px">h</div>',
      '<div style="position:fixed; top: -50px">h</div>',
      '<div style="visibility:collapse">h</div>',
      '<div style="height:0;overflow:hidden">h</div>',
      '<div style="clip:rect(0,0,0,0)">h</div>',
      '<div style="clip-path:inset(100%)">h</div>',
      '<p style="text-indent:-9999px">h</p>',
      '<span style="font-size:0.0em">h</span>',
      '<div style="DISPLAY : /* x */ none">h</div>',
      '<div style="d\\69splay:none">h</div>',
      '<div aria-hidden="true"><a href="https://moneyfrog.in/x">h</a></div>',
    ];
    for (const c of cases) {
      const r = sanitize(`${c}<p>Visible</p>`, opts);
      expect(r.html, c).not.toMatch(/>h</);
      expect(r.html, c).toContain('Visible');
      expect(kinds(r, 'hidden'), c).toHaveLength(1);
    }
  });

  it('logs a nested hidden parent once, even with a link inside', () => {
    const r = sanitize('<div style="display:none"><p>spam <a href="https://x.example.invalid/">l</a></p><div hidden>deep</div></div><p>ok</p>', opts);
    expect(r.removed).toHaveLength(1);
    expect(r.removed[0].kind).toBe('hidden');
    expect(r.html).toBe('<p>ok</p>');
  });

  it('flags white text without background but does not remove it', () => {
    const r = sanitize('<p style="color:#FFF">pale words</p><p style="color:#fff;background:#000">on dark</p>', opts);
    expect(r.html).toContain('pale words');
    expect(r.flags.filter((f) => f.kind === 'hidden-colour')).toHaveLength(1);
  });

  it('does not remove ordinary small visible styles', () => {
    const r = sanitize('<p style="font-size:14px;opacity:.9;margin-left:-10px">fine</p>', opts);
    expect(r.html).toContain('fine');
    expect(kinds(r, 'hidden')).toHaveLength(0);
  });
});

describe('sanitize: block judging (R26) and contagion (R25)', () => {
  const link = '<a href="https://casino-example.invalid/">spiel</a>';
  it('removes German casino text inside div, td and h3', () => {
    for (const tpl of [`<div>${DE} ${link}</div>`, `<table><tr><td>${DE} ${link}</td></tr></table>`, `<h3>${DE} ${link}</h3>`]) {
      const r = sanitize(`${tpl}<p>English stays.</p>`, opts);
      expect(r.html, tpl).not.toMatch(/Freispiele/);
      expect(r.html, tpl).toContain('English stays');
      expect(kinds(r, 'paragraph'), tpl).toHaveLength(1);
    }
  });

  it('keeps a good parent li when only a nested block is bad', () => {
    const r = sanitize(`<ul><li><p>Good English point about rupee.</p><p>${DE} ${link}</p></li></ul>`, opts);
    expect(r.html).toContain('Good English point');
    expect(kinds(r, 'paragraph')).toHaveLength(1);
  });

  it('keeps and flags an English gambling sentence with an external news link', () => {
    const r = sanitize('<p>Markets are betting on a rate cut, says <a href="https://news.example.invalid/a">a news report</a>.</p>', opts);
    expect(r.html).toContain('betting on a rate cut');
    expect(kinds(r, 'paragraph')).toHaveLength(0);
    const kw = r.flags.filter((f) => f.kind === 'keyword');
    expect(kw).toHaveLength(1);
    expect(kw[0].excerpt).toContain('betting');
    expect(kinds(r, 'link')).toHaveLength(1);
  });

  it('flags once per block', () => {
    const r = sanitize(`<p>${DE} casino jackpot</p>`, opts);
    expect(r.flags.filter((f) => f.kind === 'non-english')).toHaveLength(1);
    expect(r.flags.filter((f) => f.kind === 'keyword')).toHaveLength(1);
  });

  it('contagion removes all German blocks in an item with spam, keeps English', () => {
    const html = `<ul><li>English bullet one about the rupee.</li></ul>
      <p>${DE} erster Absatz.</p>
      <p>${DE} ${link}</p>
      <p>${DE} dritter Absatz.</p>
      <ul><li>English bullet two about PMI.</li></ul>`;
    const r = sanitize(html, opts);
    expect(kinds(r, 'paragraph')).toHaveLength(3);
    expect(kinds(r, 'paragraph').filter((x) => x.detail.startsWith('contagion:'))).toHaveLength(2);
    expect(r.html).not.toMatch(/Absatz|Freispiele/);
    expect(r.html).toContain('English bullet one');
    expect(r.html).toContain('English bullet two');
    expect(r.flags.filter((f) => f.kind === 'non-english')).toHaveLength(0);
  });

  it('German without any spam in the item is only flagged', () => {
    const r = sanitize(`<p>${DE} nur Text.</p><p>English.</p>`, opts);
    expect(kinds(r, 'paragraph')).toHaveLength(0);
    expect(r.flags.some((f) => f.kind === 'non-english')).toBe(true);
  });
});
