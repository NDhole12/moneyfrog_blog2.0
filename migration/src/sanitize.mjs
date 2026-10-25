import * as cheerio from 'cheerio';
import sanitizeHtml from 'sanitize-html';
import { ALLOWLIST, isAllowedHost } from './allowlist.mjs';

const GAMBLING = /\b(casino|betting|sportsbook|poker|slot machine|jackpot|bonus code|freispiele|wett)/i;
const GERMAN_STOPWORDS = new Set(['und', 'der', 'die', 'das', 'nicht', 'sich', 'mit', 'für', 'ist', 'eine', 'wie', 'zum']);
const YOUTUBE_HOSTS = ['www.youtube.com', 'youtube.com', 'www.youtube-nocookie.com', 'youtube-nocookie.com'];
const SCRIPT_LIKE = new Set(['script', 'style', 'object', 'embed', 'noscript', 'applet', 'template']);
const ALLOWED_TAGS = sanitizeHtml.defaults.allowedTags.concat(['img', 'iframe', 'figure', 'figcaption', 'span', 'h1', 'h2']);
const ALLOWED_TAG_SET = new Set(ALLOWED_TAGS);
const BLOCK_SELECTOR = 'p,li,div,td,th,h1,h2,h3,h4,h5,h6,blockquote,dd,dt,figcaption';
const ANY_BLOCK = `${BLOCK_SELECTOR},ul,ol,table,thead,tbody,tfoot,tr,dl,section,article,aside,header,footer,nav,figure,pre,form,details,summary,main`;
const BLOCK_TAGS = new Set(ANY_BLOCK.split(','));
const WHITE = new Set(['white', '#fff', '#ffffff', 'rgb(255,255,255)']);

const excerpt = (s) => s.replace(/\s+/g, ' ').trim().slice(0, 120);

function germanSignal(text) {
  const words = (text.toLowerCase().match(/[a-zäöüß]+/g) ?? []).filter((w) => GERMAN_STOPWORDS.has(w));
  return words.length >= 3 && new Set(words).size >= 2;
}

function parseUrl(href) {
  try {
    return new URL(String(href).trim(), 'https://placeholder.invalid');
  } catch {
    return null;
  }
}

/** 'ok' (relative, mailto, tel, or on-list web host), 'outbound' (off-list web host), 'unsafe' (other scheme). */
function classifyHref(href, allow) {
  const u = parseUrl(href);
  if (!u) return 'unsafe';
  if (u.protocol === 'mailto:' || u.protocol === 'tel:') return 'ok';
  if (u.protocol !== 'https:' && u.protocol !== 'http:') return 'unsafe';
  if (u.hostname === 'placeholder.invalid') return 'ok';
  return isAllowedHost(u.hostname, allow) ? 'ok' : 'outbound';
}

function normaliseCss(s) {
  return s
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/\\([0-9a-fA-F]{1,6})\s?/g, (_, h) => {
      const cp = parseInt(h, 16);
      return cp > 0 && cp <= 0x10ffff ? String.fromCodePoint(cp) : '\u{fffd}';
    })
    .replace(/\\(.)/g, '$1')
    .toLowerCase()
    .replace(/\s+/g, '');
}

function parseDecls(style) {
  const out = {};
  for (const part of normaliseCss(style).split(';')) {
    const i = part.indexOf(':');
    if (i > 0) out[part.slice(0, i)] = part.slice(i + 1).replace(/!important$/, '');
  }
  return out;
}

const isZero = (v) => v !== undefined && /^[0-9.]/.test(v) && parseFloat(v) === 0;
const negAtLeast = (v, n) => {
  const m = /^-(\d*\.?\d+)/.exec(v ?? '');
  return Boolean(m) && parseFloat(m[1]) >= n;
};

function hiddenByStyle(d) {
  if (d.display === 'none') return true;
  if (d.visibility === 'hidden' || d.visibility === 'collapse') return true;
  if (isZero(d.opacity)) return true;
  if (isZero(d['font-size'])) return true;
  const clipped = [d.overflow, d['overflow-x'], d['overflow-y']].includes('hidden');
  if (clipped && ['height', 'width', 'max-height', 'max-width'].some((p) => isZero(d[p]))) return true;
  if (/^rect\(0/.test(d.clip ?? '') || /^inset\(100%/.test(d['clip-path'] ?? '')) return true;
  if (['absolute', 'fixed'].includes(d.position) && ['left', 'top', 'right', 'bottom'].some((p) => negAtLeast(d[p], 50))) return true;
  if (negAtLeast(d['text-indent'], 999)) return true;
  return false;
}

const whiteTextNoBackground = (d) =>
  WHITE.has(d.color ?? '') && !('background' in d) && !('background-color' in d) && !('background-image' in d);

function attached(el, root) {
  let n = el;
  while (n.parent) n = n.parent;
  return n === root;
}

/**
 * Pure: sanitises WordPress post HTML. Returns cleaned html plus a log of every
 * removal and a list of items flagged for human review.
 */
export function sanitize(html, { allow = ALLOWLIST } = {}) {
  const removed = [];
  const flags = [];
  const $ = cheerio.load(String(html ?? ''), null, false);
  const root = $.root()[0];
  const live = (el) => attached(el, root);
  const colourFlags = [];

  // 0. Protocol-relative URLs -> https so allowed ones survive the final pass.
  $('a[href], iframe[src], img[src]').each((_, el) => {
    const attr = el.tagName === 'a' ? 'href' : 'src';
    const v = (el.attribs[attr] ?? '').trim();
    if (v.startsWith('//')) el.attribs[attr] = `https:${v}`;
  });

  // 1. Hidden content first, so descendants of a removed node are never logged again.
  $('*').each((_, el) => {
    if (!live(el)) return;
    const a = el.attribs ?? {};
    const d = parseDecls(a.style ?? '');
    const text = $(el).text();
    const ariaHiddenSpam =
      a['aria-hidden'] === 'true' && text.trim() !== '' && (el.tagName === 'a' || $(el).find('a[href]').length > 0);
    if ('hidden' in a || ariaHiddenSpam || hiddenByStyle(d)) {
      removed.push({ kind: 'hidden', detail: `${el.tagName}: ${excerpt(text)}` });
      $(el).remove();
    } else if (whiteTextNoBackground(d) && text.trim() !== '') {
      colourFlags.push({ el, flag: { kind: 'hidden-colour', excerpt: excerpt(text) } });
    }
  });

  // 2. Script-like elements.
  $('*').each((_, el) => {
    if (live(el) && SCRIPT_LIKE.has(el.tagName)) {
      removed.push({ kind: 'script', detail: el.tagName });
      $(el).remove();
    }
  });

  // 3. Event-handler attributes.
  $('*').each((_, el) => {
    for (const name of Object.keys(el.attribs ?? {})) {
      if (/^on/i.test(name)) {
        removed.push({ kind: 'handler', detail: `${el.tagName}[${name}]` });
        $(el).removeAttr(name);
      }
    }
  });

  // 4. Iframes: YouTube only; every other src is logged.
  $('iframe').each((_, el) => {
    const src = el.attribs?.src ?? '';
    const u = parseUrl(src);
    const ok = u && u.protocol === 'https:' && YOUTUBE_HOSTS.includes(u.hostname);
    if (!ok) {
      removed.push({ kind: 'iframe', detail: src });
      $(el).remove();
    }
  });

  // 4b. Images with non-web sources.
  $('img[src]').each((_, el) => {
    if (classifyHref(el.attribs.src, allow) === 'unsafe') {
      removed.push({ kind: 'link', detail: el.attribs.src.slice(0, 120) });
      $(el).remove();
    }
  });

  // 5. Judge every inline run: the consecutive non-block children of the root and of each
  //    block-like container. A leaf block is a single run; a mixed block's own text is judged
  //    separately from its block children, and only the run's nodes are removed.
  const isBlock = (n) => n.type === 'tag' && (BLOCK_TAGS.has(n.tagName) || $(n).find(ANY_BLOCK).length > 0);
  const containers = [root, ...$('*').toArray().filter((n) => isBlock(n))];
  const judged = [];
  for (const container of containers) {
    if (container !== root && !live(container)) continue;
    const runs = [];
    let current = [];
    for (const child of container.children ?? []) {
      if (isBlock(child)) {
        if (current.length) runs.push(current);
        current = [];
      } else {
        current.push(child);
      }
    }
    if (current.length) runs.push(current);
    for (const nodes of runs) {
      const text = nodes.map((n) => $(n).text()).join('');
      if (text.trim() === '') continue;
      const german = germanSignal(text);
      const keyword = GAMBLING.test(text);
      const badLink = nodes.some((n) =>
        $(n)
          .find('a[href]')
          .addBack('a[href]')
          .toArray()
          .some((a) => classifyHref(a.attribs.href, allow) === 'outbound'),
      );
      // R26: (gambling AND off-list link AND german) OR (german AND off-list link) reduces to german AND off-list link.
      if (german && badLink) {
        removed.push({ kind: 'paragraph', detail: excerpt(text) });
        for (const n of nodes) $(n).remove();
        continue;
      }
      const mine = [];
      if (german) mine.push({ kind: 'non-english', excerpt: excerpt(text) });
      if (keyword) mine.push({ kind: 'keyword', excerpt: excerpt(text) });
      if (mine.length) judged.push({ nodes, text, flags: mine });
    }
  }

  // 6. R25 contagion: spam found in this item taints its other non-English runs.
  const spamFound = removed.some((r) => r.kind === 'paragraph');
  for (const j of judged) {
    if (spamFound && live(j.nodes[0]) && j.flags.some((f) => f.kind === 'non-english')) {
      removed.push({ kind: 'paragraph', detail: `contagion: ${excerpt(j.text)}` });
      for (const n of j.nodes) $(n).remove();
    } else {
      flags.push(...j.flags);
    }
  }

  // Colour flags only survive if their element is still in the document.
  for (const c of colourFlags) if (live(c.el)) flags.push(c.flag);

  // 7. Links: unsafe schemes and off-list hosts are unwrapped (text kept) and logged.
  $('a[href]').each((_, el) => {
    if (classifyHref(el.attribs.href, allow) !== 'ok') {
      removed.push({ kind: 'link', detail: el.attribs.href.slice(0, 200) });
      $(el).replaceWith($(el).contents());
    }
  });

  // 8. Tags the final whitelist would silently drop.
  $('*').each((_, el) => {
    if (!ALLOWED_TAG_SET.has(el.tagName)) removed.push({ kind: 'tag', detail: el.tagName });
  });

  // 9. Style attributes: one summary entry for the item.
  let styleCount = 0;
  $('[style]').each((_, el) => {
    styleCount += 1;
    $(el).removeAttr('style');
  });
  if (styleCount > 0) removed.push({ kind: 'style', detail: `${styleCount} style attribute(s) dropped` });

  const clean = sanitizeHtml($.root().html() ?? '', {
    allowedTags: ALLOWED_TAGS,
    allowedAttributes: {
      '*': ['class', 'id', 'title', 'lang'],
      a: ['href', 'name', 'target', 'rel'],
      img: ['src', 'srcset', 'sizes', 'alt', 'width', 'height', 'loading'],
      iframe: ['src', 'width', 'height', 'allowfullscreen', 'frameborder', 'title'],
    },
    allowedIframeHostnames: YOUTUBE_HOSTS,
    allowedSchemes: ['http', 'https', 'mailto', 'tel'],
    allowProtocolRelative: false,
  });

  return { html: clean, removed, flags };
}
