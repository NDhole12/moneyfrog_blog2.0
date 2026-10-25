// Domains that outbound links may point at. A host matches when it equals an
// entry or is a subdomain of it. Any *.gov.in host is also allowed (see isAllowedHost).
export const ALLOWLIST = Object.freeze([
  'moneyfrog.in',
  'blog.moneyfrog.in',
  'amfiindia.com',
  'sebi.gov.in',
  'rbi.org.in',
  'incometax.gov.in',
  'irdai.gov.in',
  'npstrust.org.in',
  'india.gov.in',
  'youtube.com',
  'youtu.be',
]);

export function isAllowedHost(host, allow = ALLOWLIST) {
  const h = String(host).toLowerCase().replace(/\.$/, '');
  if (h === 'gov.in' || h.endsWith('.gov.in')) return true;
  return allow.some((d) => h === d || h.endsWith(`.${d}`));
}
