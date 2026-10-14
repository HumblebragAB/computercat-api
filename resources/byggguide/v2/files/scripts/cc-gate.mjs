// Byggguide v2: spärren (spec delprojekt 3, del 3). Del 1: säkerhetskontroll av den statiska
// exporten i out/. Del 2 (sist i filen): npm audit för produktionsberoenden.
// Körs sist i `npm run check` och i Coolify-bygget, där kommandot hämtar den här filen över
// https från den låsta byggguidens REST (Computercat serverar bara låsta filer och kontrollerar
// deras sha256 innan de skickas); spelrepot kan inte ändra det som körs där. Skriptet importerar
// inget utanför Node, eftersom Coolify kör det fristående från /tmp.
// Skriver aldrig ut ett hittat värde, bara fil och mönstrets namn.
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

// Samma policy som src/lib/computercat/csp.ts (test/security.test.ts jämför dem). Skriptet kan inte
// importera csp.ts: Coolify kör det fristående.
function expectedPolicy() {
  const raw = process.env[URL_NAME]?.trim() || fromEnvFiles(URL_NAME) || DEFAULT_API_URL;
  let origin;
  try {
    origin = new URL(raw).origin;
  } catch {
    return null;
  }
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

function cspPolicies(html) {
  const policies = [];
  for (const [tag] of html.matchAll(/<meta\b[^>]*>/gi)) {
    const attrs = {};
    for (const [, key, double, single] of tag.matchAll(/([a-zA-Z:-]+)\s*=\s*(?:"([^"]*)"|'([^']*)')/g)) {
      attrs[key.toLowerCase()] = decode(double ?? single ?? '');
    }
    if ((attrs['http-equiv'] ?? '').toLowerCase() === 'content-security-policy') policies.push(attrs.content ?? '');
  }
  return policies;
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
  if (file.endsWith('.map')) report(file, 'source map (*.map publiceras inte)');
  // latin1 läser varje byte som ett tecken: mönstren är ASCII och träffar oavsett filens kodning.
  const text = readFileSync(file, 'latin1');
  for (const [name, pattern] of SECRETS) {
    if (pattern.test(text)) report(file, `hemlighet (${name})`);
  }
  if (file.endsWith('.html')) {
    pages++;
    const found = cspPolicies(text);
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
