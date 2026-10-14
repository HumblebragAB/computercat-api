import { spawnSync } from 'node:child_process';
import { chmodSync, mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
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
    expect(run(project({ 'README.md': 'x' })).output).toContain('check-security: out/ saknas');
    const res = run(project({ 'out/index.html': okPage }), { NEXT_PUBLIC_COMPUTERCAT_URL: 'inte en adress' });
    expect(res.status).toBe(1);
    expect(res.output).toContain('NEXT_PUBLIC_COMPUTERCAT_URL är ingen giltig adress');
  });
});
