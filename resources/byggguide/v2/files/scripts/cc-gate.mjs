// Byggguide v2: spärren (spec delprojekt 3, del 3). Del 1: säkerhetskontroll av den statiska
// exporten i out/. Del 2 (sist i filen): npm audit för produktionsberoenden.
// Körs sist i `npm run check` och i Coolify-bygget, där kommandot hämtar den här filen över
// https från den låsta byggguidens REST (Computercat serverar bara låsta filer och kontrollerar
// deras sha256 innan de skickas). Skriptet importerar inget utanför Node, eftersom Coolify kör
// det fristående från /tmp. Skriver aldrig ut ett hittat värde, bara fil och mönstrets namn.
//
// Hotmodell
// - Skyddas: att en hemlighet, en source map, en dold fil eller en sida utan (eller med kringgången)
//   Content-Security-Policy publiceras på Demo.
// - Indata: innehållet i out/ och miljön, båda producerade av spelets kod och spelets Claude.
// - Antaganden: Node och filsystemet är hederliga; out/ är resultatet av `npm run build`.
// - Gräns (R12): spärren fångar misstag och kända mönster, inte avsiktligt skadlig spelkod.
//   `npm run build` kör repots egen kod före kontrollerna och kan i princip påverka byggmiljön.
//   Att ta bort eller ändra skripten eller `check` stänger inte av spärren i Coolify, men ett
//   lokalt `check` bevisar ingenting om en ändrad kopia.
// - CSP-metataggen gäller bara resurser som webbläsaren hittar efter den. React/Next lyfter
//   <script async src>, <link rel=stylesheet> m.fl. ovanför metataggen, så alla resurs-URL:er i
//   HTML kontrolleras separat och måste vara samma ursprung (se checkResources).
import { existsSync, lstatSync, readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

const OUT = 'out';
const URL_NAME = 'NEXT_PUBLIC_COMPUTERCAT_URL';
const DEFAULT_API_URL = 'https://api.computercat.co/api/v1';

// Exakta mönster med ordgränser (specen). Namnet skrivs ut, aldrig träffen.
const SECRETS = [
  ['private-key', /-----BEGIN [A-Z ]*PRIVATE KEY-----/],
  ['github-token', /\bgh[pousr]_[A-Za-z0-9]{36}\b/],
  ['github-pat', /\bgithub_pat_[A-Za-z0-9_]{50,}\b/],
  ['slack-token', /\bxox[abprs]-[A-Za-z0-9-]{10,}\b/],
  ['slack-webhook', /hooks\.slack\.com\/services\/T[A-Z0-9]+\//],
  ['stripe-live-key', /\b[sr]k_live_[A-Za-z0-9]{20,}\b/],
  ['aws-access-key', /\bAKIA[0-9A-Z]{16}\b/],
  ['jwt', /\beyJ[A-Za-z0-9_-]{20,}\.eyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\b/],
];

const findings = [];
const report = (file, what) => findings.push(`${file}: ${what}`);

// Samma ordning som Next vid `next build` (och som check-export.mjs): miljön först, sedan filerna.
function fromEnvFiles(name) {
  for (const file of ['.env.production.local', '.env.local', '.env.production', '.env']) {
    if (!existsSync(file)) continue;
    for (const line of readFileSync(file, 'utf8').split(/\r?\n/)) {
      const m = line.match(new RegExp(`^\\s*(?:export\\s+)?${name}\\s*=\\s*(.*?)\\s*$`));
      const value = m?.[1].replace(/^(['"])(.*)\1$/, '$2').trim();
      if (value) return value;
    }
  }
  return null;
}

// Samma regler som parseConfig i src/lib/computercat/config.ts: https, http bara mot
// localhost/127.0.0.1, ingen joker, inget ogenomskinligt ursprung.
function apiOrigin(raw) {
  let url;
  try {
    url = new URL(raw);
  } catch {
    return null;
  }
  const local = url.hostname === 'localhost' || url.hostname === '127.0.0.1';
  if (url.protocol !== 'https:' && !(local && url.protocol === 'http:')) return null;
  if (raw.includes('*') || url.origin === 'null') return null;
  return url.origin;
}

// Samma policy som src/lib/computercat/csp.ts (test/security.test.ts jämför dem). Skriptet kan inte
// importera csp.ts: Coolify kör det fristående.
function expectedPolicy() {
  const raw = process.env[URL_NAME]?.trim() || fromEnvFiles(URL_NAME) || DEFAULT_API_URL;
  const origin = apiOrigin(raw);
  if (origin === null) return null;
  return [
    "default-src 'self'",
    "script-src 'self' 'unsafe-inline'",
    "style-src 'self' 'unsafe-inline'",
    "img-src 'self' data:",
    "font-src 'self'",
    `connect-src 'self' ${origin}`,
    "object-src 'none'",
    "base-uri 'self'",
    "form-action 'self'",
  ].join('; ');
}

function walk(dir) {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name);
    const stat = lstatSync(path);
    // .env*, .git och andra dolda filer ska aldrig publiceras; .well-known är en standardkatalog.
    if (name.startsWith('.') && !(name === '.well-known' && stat.isDirectory())) {
      report(path, 'dold fil (.env, .git m.fl. publiceras inte)');
      return [];
    }
    if (stat.isSymbolicLink()) {
      report(path, 'symbolisk länk (tillåts inte i out/)');
      return [];
    }
    return stat.isDirectory() ? walk(path) : [path];
  });
}

const ENTITIES = { quot: '"', amp: '&', apos: "'", lt: '<', gt: '>' };
const decode = (text) =>
  text.replace(/&(?:#x([0-9a-f]+)|#(\d+)|(quot|amp|apos|lt|gt));/gi, (_, hex, dec, name) =>
    hex ? String.fromCodePoint(parseInt(hex, 16)) : dec ? String.fromCodePoint(Number(dec)) : ENTITIES[name.toLowerCase()],
  );

const TAG = /<([a-zA-Z][a-zA-Z0-9-]*)\b((?:"[^"]*"|'[^']*'|[^>"'])*)>/g;
const ATTR = /([a-zA-Z_:][a-zA-Z0-9_:.-]*)\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'>]+))/g;

function parseTags(html) {
  const tags = [];
  for (const [, name, rest] of html.matchAll(TAG)) {
    const attrs = {};
    for (const [, key, d, s, u] of rest.matchAll(ATTR)) attrs[key.toLowerCase()] = decode(d ?? s ?? u ?? '');
    tags.push({ name: name.toLowerCase(), attrs });
  }
  return tags;
}

function cspPolicies(tags) {
  return tags
    .filter((t) => t.name === 'meta' && (t.attrs['http-equiv'] ?? '').trim().toLowerCase() === 'content-security-policy')
    .map((t) => t.attrs.content ?? '');
}

// URL-bärande attribut per element. data: tillåts bara för bilder (img-src 'self' data:).
const RESOURCE_ATTRS = {
  script: ['src'],
  link: ['href', 'imagesrcset'],
  iframe: ['src'],
  frame: ['src'],
  img: ['src', 'srcset'],
  source: ['src', 'srcset'],
  video: ['src', 'poster'],
  audio: ['src'],
  track: ['src'],
  object: ['data'],
  embed: ['src'],
  form: ['action'],
  input: ['src', 'formaction'],
  button: ['formaction'],
  base: ['href'],
};
const DATA_OK = new Set(['img', 'source']);

function sameOrigin(value, allowData) {
  const v = value.trim();
  if (/[\u0000-\u001f\u007f\s\\]/.test(v)) return false; // webbläsare tar bort/omtolkar dessa tecken
  if (allowData && /^data:/i.test(v)) return true;
  return (v.startsWith('/') && !v.startsWith('//')) || v.startsWith('./') || v.startsWith('../');
}

function checkResources(file, tags) {
  for (const { name, attrs } of tags) {
    if (name === 'base') {
      report(file, '<base> tillåts inte');
      continue;
    }
    if (name === 'meta' && (attrs['http-equiv'] ?? '').trim().toLowerCase() === 'refresh') {
      report(file, '<meta http-equiv="refresh"> tillåts inte');
      continue;
    }
    for (const attr of RESOURCE_ATTRS[name] ?? []) {
      const value = attrs[attr];
      if (value === undefined || value.trim() === '') continue;
      const parts = attr.endsWith('srcset') ? value.split(',').map((p) => p.trim().split(/\s+/)[0]) : [value];
      for (const part of parts) {
        if (part && !sameOrigin(part, DATA_OK.has(name))) {
          let shown = 'ogiltig adress';
          try {
            shown = new URL(part, 'https://x.invalid').origin;
          } catch {
            // behåll ogiltig adress
          }
          report(file, `extern resurs (<${name} ${attr}> pekar utanför samma ursprung: ${shown})`);
        }
      }
    }
  }
}

if (!existsSync(OUT) || !lstatSync(OUT).isDirectory()) {
  console.error('check-security: out/ saknas. Kör npm run build först.');
  process.exit(1);
}

const policy = expectedPolicy();
if (policy === null) {
  console.error(`check-security: ${URL_NAME} är ingen giltig adress.`);
  process.exit(1);
}

const files = walk(OUT);
let pages = 0;
for (const file of files) {
  if (file.toLowerCase().endsWith('.map')) report(file, 'source map (*.map publiceras inte)');
  // latin1 läser varje byte som ett tecken: mönstren är ASCII och träffar oavsett filens kodning.
  const text = readFileSync(file, 'latin1');
  for (const [name, pattern] of SECRETS) {
    if (pattern.test(text)) report(file, `hemlighet (${name})`);
  }
  if (file.toLowerCase().endsWith('.html')) {
    pages++;
    const tags = parseTags(text);
    checkResources(file, tags);
    const found = cspPolicies(tags);
    if (found.length === 0) report(file, 'CSP saknas (<meta http-equiv="Content-Security-Policy">)');
    else if (found.length > 1) report(file, 'mer än en CSP-tagg');
    else if (found[0] !== policy) report(file, 'CSP avviker från byggguidens policy');
  }
}

if (findings.length > 0) {
  for (const finding of findings) console.error(`check-security: ${finding}`);
  console.error(`check-security: ${findings.length} fynd. Rätta källan (inte out/) och kör npm run check igen.`);
  process.exit(1);
}

console.log(`check-security: OK (${files.length} filer, ${pages} HTML-sidor med rätt CSP)`);
