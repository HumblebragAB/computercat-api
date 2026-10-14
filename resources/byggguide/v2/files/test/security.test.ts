import { spawnSync } from 'node:child_process';
import { chmodSync, mkdirSync, mkdtempSync, rmSync, symlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { afterAll, afterEach, describe, expect, it } from 'vitest';
import { contentSecurityPolicy } from '@/lib/computercat/csp';

// scripts/cc-gate.mjs (byggguide v2), säkerhetsdelen, körs som Coolify kör den: med repots rot som cwd.
const SCRIPT = resolve(process.cwd(), 'scripts/cc-gate.mjs');
const dirs: string[] = [];

// Audit-delen (Task 5) körs efter en godkänd säkerhetsdel; här svarar en falsk npm alltid rent.
const cleanNpm = mkdtempSync(join(tmpdir(), 'cc-gate-npm-'));
writeFileSync(join(cleanNpm, 'npm'), '#!/bin/sh\necho \'{"auditReportVersion":2,"vulnerabilities":{},"metadata":{"vulnerabilities":{}}}\'\n');
chmodSync(join(cleanNpm, 'npm'), 0o755);
afterAll(() => rmSync(cleanNpm, { recursive: true, force: true }));

afterEach(() => {
  // Bara katalogerna som testet skapade.
  for (const dir of dirs.splice(0)) rmSync(dir, { recursive: true, force: true });
});

// React skriver ' som &#x27; i attribut; skriptet ska godkänna det.
const metaTag = (policy: string) => `<meta http-equiv="Content-Security-Policy" content="${policy.replace(/'/g, '&#x27;')}"/>`;
const page = (...policies: string[]) =>
  `<!DOCTYPE html><html lang="sv"><head><meta charSet="utf-8"/>${policies.map(metaTag).join('')}<title>x</title></head><body>ok</body></html>`;
const okPage = page(contentSecurityPolicy(undefined));

function project(files: Record<string, string>): string {
  const dir = mkdtempSync(join(tmpdir(), 'cc-security-'));
  dirs.push(dir);
  for (const [path, content] of Object.entries(files)) {
    const full = join(dir, path);
    mkdirSync(dirname(full), { recursive: true });
    writeFileSync(full, content);
  }
  return dir;
}

function run(dir: string, env: Record<string, string> = {}) {
  const base: NodeJS.ProcessEnv = { ...process.env };
  delete base.NEXT_PUBLIC_COMPUTERCAT_URL;
  const r = spawnSync(process.execPath, [SCRIPT], { cwd: dir, env: { ...base, PATH: `${cleanNpm}:${process.env.PATH}`, ...env }, encoding: 'utf8' });
  return { status: r.status, output: `${r.stdout}${r.stderr}` };
}

// Planterade värden byggs vid körning, så att GitHubs push protection och gitleaks inte stoppar committen.
const filler = (n: number) => 'A1b2C3d4E5'.repeat(Math.ceil(n / 10)).slice(0, n);
const PLANTED: Array<[string, string]> = [
  ['private-key', '-----BEGIN ' + 'RSA PRIVATE' + ' KEY-----'],
  ['github-token', 'gh' + 'p_' + filler(36)],
  ['github-pat', 'github' + '_pat_' + filler(60)],
  ['slack-token', 'xo' + 'xb-' + filler(24)],
  ['slack-webhook', 'https://hooks.slack.com/services/' + 'T' + 'ABC123' + '/B000/xyz'],
  ['stripe-live-key', 'sk_' + 'live_' + filler(24)],
  ['aws-access-key', 'AK' + 'IA' + 'ABCDEFGHIJKLMNOP'],
  ['jwt', 'ey' + 'J' + filler(24) + '.ey' + 'J' + filler(24) + '.' + filler(24)],
];

describe('cc-gate.mjs: säkerhetsdelen', () => {
  it('godkänner en export med rätt CSP och utan fynd', () => {
    const res = run(project({ 'out/index.html': okPage, 'out/404.html': okPage, 'out/_next/static/a.js': 'console.log(1)' }));
    expect(res.output).toContain('check-security: OK (3 filer, 2 HTML-sidor med rätt CSP)');
    expect(res.status).toBe(0);
  });

  it.each(PLANTED)('fäller på %s med filnamn och mönstrets namn, aldrig värdet', (name, value) => {
    const res = run(project({ 'out/index.html': okPage, 'out/_next/static/chunks/app.js': `const k="${value}";` }));
    expect(res.status).toBe(1);
    expect(res.output).toContain(`check-security: out/_next/static/chunks/app.js: hemlighet (${name})`);
    expect(res.output).not.toContain(value);
  });

  it('kräver ordgräns: en nyckel som sitter ihop med ett ord före är ingen träff', () => {
    const res = run(project({ 'out/index.html': okPage, 'out/a.js': `const k="x${'sk_' + 'live_' + filler(24)}";` }));
    expect(res.status).toBe(0);
  });

  // Ordgräns: ett ordtecken direkt före träffen ska stoppa mönstret (gäller de mönster som börjar med \\b).
  it.each(PLANTED.filter(([name]) => !['private-key', 'slack-webhook'].includes(name)))('ordgräns för %s', (_name, value) => {
    const res = run(project({ 'out/index.html': okPage, 'out/a.js': `const k="x${value}";` }));
    expect(res.status).toBe(0);
  });

  it.each(['h', 'o', 'u', 's', 'r'])('github-token träffar gh%s_ men inte gh%s_ med fel längd', (letter) => {
    const hit = run(project({ 'out/index.html': okPage, 'out/a.js': `"gh${letter}_${filler(36)}"` }));
    expect(hit.status).toBe(letter === 'h' ? 0 : 1);
    const short = run(project({ 'out/index.html': okPage, 'out/a.js': `"ghp_${filler(35)}"` }));
    expect(short.status).toBe(0);
  });

  it.each(['sk', 'rk'])('stripe-live-key träffar %s_live_ och kräver minst 20 tecken', (prefix) => {
    expect(run(project({ 'out/index.html': okPage, 'out/a.js': `"${prefix}_` + `live_${filler(24)}"` })).status).toBe(1);
    expect(run(project({ 'out/index.html': okPage, 'out/a.js': `"${prefix}_` + `live_${filler(19)}"` })).status).toBe(0);
  });

  it('fäller på symboliska länkar i out/', () => {
    const dir = project({ 'out/index.html': okPage, 'hemligt.txt': 'x' });
    symlinkSync(join(dir, 'hemligt.txt'), join(dir, 'out/länk.txt'));
    const res = run(dir);
    expect(res.status).toBe(1);
    expect(res.output).toContain('check-security: out/länk.txt: symbolisk länk');
  });

  it('fäller på extern resurs som React lyft ovanför CSP-taggen', () => {
    const evil = `<!DOCTYPE html><html lang="sv"><head><script src="https://evil.example/x.js" async=""></script>${metaTag(contentSecurityPolicy(undefined))}</head><body></body></html>`;
    const res = run(project({ 'out/index.html': evil }));
    expect(res.status).toBe(1);
    expect(res.output).toContain('check-security: out/index.html: extern resurs (<script src>');
    expect(res.output).toContain('extern adress till https://evil.example');
  });

  it.each([
    ['<script src="//evil.example/x.js"></script>', 'script src'],
    ['<script src="/\\evil.example/x.js"></script>', 'script src'],
    ['<link rel="stylesheet" href="https://evil.example/a.css"/>', 'link href'],
    ['<img src="https://evil.example/a.png"/>', 'img src'],
    ['<img srcset="/a.png 1x, https://evil.example/b.png 2x"/>', 'img srcset'],
    ['<iframe src="https://evil.example"></iframe>', 'iframe src'],
    ['<form action="https://evil.example"></form>', 'form action'],
    ['<script src=https://evil.example/x.js></script>', 'script src'],
    ['<link rel="preload" href="data:text/css,x"/>', 'link href'],
  ])('fäller på %s', (tag, what) => {
    const html = okPage.replace('</body>', `${tag}</body>`);
    const res = run(project({ 'out/index.html': html }));
    expect(res.status).toBe(1);
    expect(res.output).toContain(`(<${what}>`);
  });

  it('släpper igenom relativa sökvägar utan snedstreck och canonical/alternate-länkar', () => {
    const html = okPage.replace(
      '</body>',
      '<img src="cat.png"/><link rel="preload" href="cat.png" as="image"/><link rel="canonical" href="https://spel.example/"/><link rel="alternate" hreflang="en" href="https://spel.example/en"/></body>',
    );
    const res = run(project({ 'out/index.html': html }));
    expect(res.output).toContain('check-security: OK');
    expect(res.status).toBe(0);
  });

  it('kontrollerar link href även när rel har flera värden eller versaler', () => {
    const res = run(project({ 'out/index.html': okPage.replace('</body>', '<link rel="Shortcut ICON" href="https://evil.example/f.ico"/></body>') }));
    expect(res.status).toBe(1);
    expect(res.output).toContain('<link href>');
  });

  it('fäller på <base> och meta refresh', () => {
    const base = run(project({ 'out/index.html': okPage.replace('<title>', '<base href="/x/"/><title>') }));
    expect(base.status).toBe(1);
    expect(base.output).toContain('<base> tillåts inte');
    const refresh = run(project({ 'out/index.html': okPage.replace('<title>', '<meta http-equiv="refresh" content="0;url=/x"/><title>') }));
    expect(refresh.status).toBe(1);
  });

  it('släpper igenom samma ursprung och data: i bilder', () => {
    const html = okPage.replace(
      '</body>',
      '<script src="/_next/static/a.js" async=""></script><link rel="stylesheet" href="/_next/static/a.css"/><img src="data:image/png;base64,AAAA"/><img srcset="./a.png 1x, ../b.png 2x"/><a href="https://example.com">l</a></body>',
    );
    const res = run(project({ 'out/index.html': html }));
    expect(res.output).toContain('check-security: OK');
    expect(res.status).toBe(0);
  });

  it('fäller på source maps och HTML med versaler i filnamnet', () => {
    const res = run(project({ 'out/index.html': okPage, 'out/A.JS.MAP': '{}', 'out/X.HTML': page() }));
    expect(res.status).toBe(1);
    expect(res.output).toContain('out/A.JS.MAP: source map');
    expect(res.output).toContain('out/X.HTML: CSP saknas');
  });

  it('fäller på source maps', () => {
    const res = run(project({ 'out/index.html': okPage, 'out/_next/static/a.js.map': '{}' }));
    expect(res.status).toBe(1);
    expect(res.output).toContain('check-security: out/_next/static/a.js.map: source map (*.map publiceras inte)');
  });

  it('fäller på dolda filer som .env och .git men släpper igenom .well-known', () => {
    const res = run(project({ 'out/index.html': okPage, 'out/.env.local': 'X=1', 'out/.git/config': '[core]', 'out/.well-known/security.txt': 'Contact: x' }));
    expect(res.status).toBe(1);
    expect(res.output).toContain('check-security: out/.env.local: dold fil');
    expect(res.output).toContain('check-security: out/.git: dold fil');
    expect(res.output).not.toContain('.well-known');
  });

  it('fäller på en HTML-sida utan CSP', () => {
    const res = run(project({ 'out/index.html': okPage, 'out/sub/index.html': page() }));
    expect(res.status).toBe(1);
    expect(res.output).toContain('check-security: out/sub/index.html: CSP saknas');
  });

  it('fäller på en CSP som avviker och på två CSP-taggar', () => {
    const res = run(project({
      'out/index.html': page(contentSecurityPolicy('https://annan.example/api/v1')),
      'out/dubbel.html': page(contentSecurityPolicy(undefined), contentSecurityPolicy(undefined)),
    }));
    expect(res.status).toBe(1);
    expect(res.output).toContain('check-security: out/index.html: CSP avviker från byggguidens policy');
    expect(res.output).toContain('check-security: out/dubbel.html: mer än en CSP-tagg');
  });

  it('följer NEXT_PUBLIC_COMPUTERCAT_URL i miljön', () => {
    const local = page(contentSecurityPolicy('http://127.0.0.1:8123/api/v1'));
    expect(run(project({ 'out/index.html': local }), { NEXT_PUBLIC_COMPUTERCAT_URL: 'http://127.0.0.1:8123/api/v1' }).status).toBe(0);
    expect(run(project({ 'out/index.html': local })).status).toBe(1);
  });

  it('läser NEXT_PUBLIC_COMPUTERCAT_URL ur .env.local som Next gör', () => {
    const dir = project({
      'out/index.html': page(contentSecurityPolicy('http://127.0.0.1:8123/api/v1')),
      '.env.local': 'NEXT_PUBLIC_COMPUTERCAT_URL="http://127.0.0.1:8123/api/v1"\n',
    });
    expect(run(dir).status).toBe(0);
  });

  it('fäller när out/ saknas eller adressen är ogiltig', () => {
    const missing = run(project({ 'README.md': 'x' }));
    expect(missing.status).toBe(1);
    expect(missing.output).toContain('check-security: out/ saknas');
    const http = run(project({ 'out/index.html': okPage }), { NEXT_PUBLIC_COMPUTERCAT_URL: 'http://evil.example/api' });
    expect(http.status).toBe(1);
    expect(http.output).toContain('ingen giltig adress');
    const res = run(project({ 'out/index.html': okPage }), { NEXT_PUBLIC_COMPUTERCAT_URL: 'inte en adress' });
    expect(res.status).toBe(1);
    expect(res.output).toContain('NEXT_PUBLIC_COMPUTERCAT_URL är ingen giltig adress');
  });
});
