import * as cheerio from 'cheerio';
import sanitizeHtml from 'sanitize-html';
import { ALLOWLIST, isAllowedHost } from './allowlist.mjs';

const GAMBLING = /\b(casino|betting|sportsbook|poker|slot machine|jackpot|bonus code|freispiele|wett)/i;
const GERMAN_STOPWORDS = new Set(['und', 'der', 'die', 'das', 'nicht', 'sich', 'mit', 'für', 'ist', 'eine', 'wie', 'zum']);
const HIDDEN_STYLE = [
  /display\s*:\s*none/i,
  /visibility\s*:\s*hidden/i,
  /font-size\s*:\s*0(?:\.0+)?(?:px|em|rem|pt|%)?\s*(?:;|!|$)/i,
  /(?:left|top|right|bottom|text-indent)\s*:\s*-\d{3,}/i,
];
const YOUTUBE_HOSTS = ['www.youtube.com', 'youtube.com', 'www.youtube-nocookie.com', 'youtube-nocookie.com'];

const excerpt = (s) => s.replace(/\s+/g, ' ').trim().slice(0, 120);

function germanStopwordCount(text) {
  const words = text.toLowerCase().match(/[a-zäöüß]+/g) ?? [];
  return words.filter((w) => GERMAN_STOPWORDS.has(w)).length;
}

function hostOf(href) {
  try {
    const u = new URL(href, 'https://placeholder.invalid');
    if (u.hostname === 'placeholder.invalid') return null; // relative
    return /^https?:$/.test(u.protocol) ? u.hostname : null;
  } catch {
    return null;
  }
}

const isOutbound = (href, allow) => {
  const host = hostOf(href);
  return host !== null && !isAllowedHost(host, allow);
};

/**
 * Pure: sanitises WordPress post HTML. Returns cleaned html plus a log of what
 * was removed and what was flagged for human review.
 */
export function sanitize(html, { allow = ALLOWLIST } = {}) {
  const removed = [];
  const flags = [];
  const $ = cheerio.load(String(html ?? ''), null, false);

  $('script, style, object, embed').each((_, el) => {
    removed.push({ kind: 'script', detail: el.tagName });
    $(el).remove();
  });

  $('*').each((_, el) => {
    for (const name of Object.keys(el.attribs ?? {})) {
      if (/^on/i.test(name)) {
        removed.push({ kind: 'handler', detail: `${el.tagName}[${name}]` });
        $(el).removeAttr(name);
      }
    }
  });

  $('[hidden], [style]').each((_, el) => {
    if (!el.parent) return;
    const style = el.attribs?.style ?? '';
    if ('hidden' in (el.attribs ?? {}) || HIDDEN_STYLE.some((re) => re.test(style))) {
      removed.push({ kind: 'hidden', detail: `${el.tagName}: ${excerpt($(el).text())}` });
      $(el).remove();
    }
  });

  $('iframe').each((_, el) => {
    const src = el.attribs?.src ?? '';
    const host = hostOf(src.startsWith('//') ? `https:${src}` : src);
    if (!host || !YOUTUBE_HOSTS.includes(host.toLowerCase())) {
      removed.push({ kind: 'iframe', detail: src });
      $(el).remove();
    }
  });

  // Paragraph-level spam check (before links are unwrapped so they can be judged).
  $('p, li').each((_, el) => {
    if (!el.parent) return;
    if (el.tagName === 'li' && $(el).children('p').length) return; // judged via its <p>
    const text = $(el).text();
    const hasKeyword = GAMBLING.test(text);
    const german = germanStopwordCount(text) >= 3;
    const badLink = $(el)
      .find('a[href]')
      .toArray()
      .some((a) => isOutbound(a.attribs.href, allow));
    // Also remove German text carrying an off-list link even without a gambling term
    // (real-world injections name the brand only in the link text).
    if ((hasKeyword && (badLink || german)) || (german && badLink)) {
      removed.push({ kind: 'paragraph', detail: excerpt(text) });
      $(el).remove();
      return;
    }
    if (german) flags.push({ kind: 'non-english', excerpt: excerpt(text) });
    if (hasKeyword) flags.push({ kind: 'keyword', excerpt: excerpt(text) });
  });

  $('a[href]').each((_, el) => {
    const href = el.attribs.href;
    if (isOutbound(href, allow)) {
      removed.push({ kind: 'link', detail: href });
      $(el).replaceWith($(el).contents());
    }
  });

  const clean = sanitizeHtml($.root().html() ?? '', {
    allowedTags: sanitizeHtml.defaults.allowedTags.concat(['img', 'iframe', 'figure', 'figcaption', 'span', 'h1', 'h2']),
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
