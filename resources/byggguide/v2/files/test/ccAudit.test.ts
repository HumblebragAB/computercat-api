import { spawnSync } from 'node:child_process';
import { chmodSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';
import { contentSecurityPolicy } from '@/lib/computercat/csp';

// Audit-delen i scripts/cc-gate.mjs (byggguide v2), i ett projekt vars out/ klarar säkerhetsdelen,
// mot en falsk npm först i PATH. Svarens form är
// npm 10:s (verifierad 2026-10-14): en rapport har auditReportVersion, metadata och
// vulnerabilities; ett fel har message och error, och orsaken står i message.
const SCRIPT = resolve(process.cwd(), 'scripts/cc-gate.mjs');
const dirs: string[] = [];

afterEach(() => {
  // Bara katalogerna som testet skapade.
  for (const dir of dirs.splice(0)) rmSync(dir, { recursive: true, force: true });
});

type Answer = { status: number; stdout: string };
const report = (vulnerabilities: Record<string, unknown>): string =>
  JSON.stringify({ auditReportVersion: 2, vulnerabilities, metadata: { vulnerabilities: {} } });
const vulnerability = (name: string, severity: string) => ({ name, severity, isDirect: true, via: [], range: '*', fixAvailable: false });
const CLEAN: Answer = { status: 0, stdout: report({}) };
const REGISTRY_DOWN: Answer = {
  status: 1,
  stdout: JSON.stringify({ message: 'request to https://registry.npmjs.org/-/npm/v1/security/audits/quick failed, reason: getaddrinfo ENOTFOUND registry.npmjs.org', error: { summary: '', detail: '' } }),
};

// Svar nummer n tas ur answers[n]; det sista upprepas.
function fakeNpm(answers: Answer[]): string {
  const dir = mkdtempSync(join(tmpdir(), 'cc-audit-'));
  dirs.push(dir);
  answers.forEach((a, i) => {
    writeFileSync(join(dir, `svar-${i}.json`), a.stdout);
    writeFileSync(join(dir, `kod-${i}`), String(a.status));
  });
  const last = answers.length - 1;
  writeFileSync(
    join(dir, 'npm'),
    `#!/bin/sh
n=$(cat "${dir}/anrop" 2>/dev/null || echo 0)
echo $((n + 1)) > "${dir}/anrop"
echo "$@" >> "${dir}/argument"
echo "\${NPM_CONFIG_PRODUCTION-osatt} \${npm_config_production-osatt}" >> "${dir}/miljo"
i=$n; [ "$i" -gt ${last} ] && i=${last}
cat "${dir}/svar-$i.json"
exit $(cat "${dir}/kod-$i")
`,
  );
  chmodSync(join(dir, 'npm'), 0o755);
  return dir;
}

// Ett projekt vars out/ klarar säkerhetsdelen, så att audit-delen nås.
function project(): string {
  const dir = mkdtempSync(join(tmpdir(), 'cc-gate-project-'));
  dirs.push(dir);
  mkdirSync(join(dir, 'out'));
  const policy = contentSecurityPolicy(undefined).replace(/'/g, '&#x27;');
  writeFileSync(join(dir, 'out/index.html'), `<!DOCTYPE html><html><head><meta http-equiv="Content-Security-Policy" content="${policy}"/></head><body>ok</body></html>`);
  return dir;
}

function run(answers: Answer[], env: Record<string, string> = {}) {
  const dir = fakeNpm(answers);
  const base: NodeJS.ProcessEnv = { ...process.env };
  delete base.NEXT_PUBLIC_COMPUTERCAT_URL;
  const r = spawnSync(process.execPath, [SCRIPT], {
    cwd: project(),
    env: { ...base, PATH: `${dir}:${process.env.PATH}`, CC_AUDIT_RETRY_DELAY_MS: '0', ...env },
    encoding: 'utf8',
  });
  return {
    status: r.status,
    output: `${r.stdout}${r.stderr}`,
    calls: Number(readFileSync(join(dir, 'anrop'), 'utf8')),
    args: readFileSync(join(dir, 'argument'), 'utf8'),
    env: readFileSync(join(dir, 'miljo'), 'utf8'),
  };
}

describe('cc-gate.mjs: audit-delen', () => {
  // Spike A (S8): nixpacks sätter NPM_CONFIG_PRODUCTION=false, och då tar --omit=dev med dev-beroenden.
  it('startar npm utan NPM_CONFIG_PRODUCTION och npm_config_production', () => {
    const res = run([CLEAN], { NPM_CONFIG_PRODUCTION: 'false', npm_config_production: 'false' });
    expect(res.status).toBe(0);
    expect(res.env.trim()).toBe('osatt osatt');
  });

  it('godkänner en ren rapport och kör npm audit med specens flaggor', () => {
    const res = run([CLEAN]);
    expect(res.status).toBe(0);
    expect(res.output).toContain('cc-audit: OK');
    expect(res.calls).toBe(1);
    expect(res.args).toContain('audit --omit=dev --audit-level=high --json --registry=https://registry.npmjs.org/');
  });

  it('fäller på high och critical med paketnamn och allvarlighetsgrad', () => {
    const res = run([{ status: 1, stdout: report({ lodash: vulnerability('lodash', 'high'), debug: vulnerability('debug', 'critical'), ms: vulnerability('ms', 'moderate') }) }]);
    expect(res.status).toBe(1);
    expect(res.output).toContain('cc-audit: debug (critical)');
    expect(res.output).toContain('cc-audit: lodash (high)');
    expect(res.output).not.toContain('ms (moderate)');
    expect(res.output).not.toContain('npm-registret');
    expect(res.calls).toBe(1);
  });

  it('släpper igenom moderate när npm avslutar med 0', () => {
    expect(run([{ status: 0, stdout: report({ ms: vulnerability('ms', 'moderate') }) }]).status).toBe(0);
  });

  it('fäller på high i rapporten även om npm avslutar med 0 (fail closed)', () => {
    const res = run([{ status: 0, stdout: report({ lodash: vulnerability('lodash', 'high') }) }]);
    expect(res.status).toBe(1);
    expect(res.output).toContain('cc-audit: lodash (high)');
  });

  it('försöker en gång till när registret inte svarar och fäller sedan med specens text', () => {
    const res = run([REGISTRY_DOWN]);
    expect(res.status).toBe(1);
    expect(res.output).toContain('npm-registret svarade inte');
    expect(res.calls).toBe(2);
  });

  it('godkänner när registret svarar vid andra försöket', () => {
    const res = run([REGISTRY_DOWN, CLEAN]);
    expect(res.status).toBe(0);
    expect(res.calls).toBe(2);
  });

  it('räknar 5xx från registret som registerfel', () => {
    const res = run([{ status: 1, stdout: JSON.stringify({ message: 'audit endpoint returned an error', error: { code: 'E503', summary: '', detail: '' } }) }, CLEAN]);
    expect(res.status).toBe(0);
    expect(res.calls).toBe(2);
  });

  it('fäller direkt med koden vid ett annat npm-fel, utan omförsök', () => {
    const res = run([{ status: 1, stdout: JSON.stringify({ message: 'Unable to authenticate', error: { code: 'E401', summary: '', detail: '' } }) }]);
    expect(res.status).toBe(1);
    expect(res.output).toContain('npm audit misslyckades (E401)');
    expect(res.output).not.toContain('npm-registret svarade inte');
    expect(res.calls).toBe(1);
  });

  it('fäller direkt när utdata inte är JSON, aldrig som ren', () => {
    const res = run([{ status: 0, stdout: 'inte json' }]);
    expect(res.status).toBe(1);
    expect(res.output).toContain('npm audit misslyckades (ingen JSON)');
    expect(res.calls).toBe(1);
  });

  it('fäller när npm avslutar med fel utan high eller critical i rapporten', () => {
    const res = run([{ status: 1, stdout: report({}) }]);
    expect(res.status).toBe(1);
    expect(res.output).toContain('npm audit avslutade med kod 1');
  });
});
