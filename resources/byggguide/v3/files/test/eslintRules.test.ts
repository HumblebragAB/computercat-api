import { resolve } from 'node:path';
import { ESLint } from 'eslint';
import { describe, expect, it } from 'vitest';

// Byggguide v3: säkerhetsreglerna i eslint.config.mjs (spec delprojekt 3, del 3, punkt 3).
// Koden lintas som om den låg i en befintlig komponent, med projektets riktiga konfiguration.
const FILE = resolve(process.cwd(), 'src/components/ConfigBanner.tsx');
const eslint = new ESLint({ cwd: process.cwd() });

async function rulesFor(code: string, file: string = FILE): Promise<string[]> {
  const [result] = await eslint.lintText(code, { filePath: file });
  return result.messages.map((m) => m.ruleId ?? `parse: ${m.message}`);
}

describe('säkerhetsreglerna i eslint', () => {
  it.each([
    ['dangerouslySetInnerHTML', 'export function A({ h }: { h: string }) {\n  return <div dangerouslySetInnerHTML={{ __html: h }} />;\n}\n', 'no-restricted-syntax'],
    ['dangerouslySetInnerHTML som objektnyckel', "import { createElement } from 'react';\nexport const a = (h: string) => createElement('div', { dangerouslySetInnerHTML: { __html: h } });\n", 'no-restricted-syntax'],
    ['dangerouslySetInnerHTML som nyckel i sträng', "import { createElement } from 'react';\nexport const a = (h: string) => createElement('div', { 'dangerouslySetInnerHTML': { __html: h } });\n", 'no-restricted-syntax'],
    ['dangerouslySetInnerHTML i ett spritt objekt', "export const props = (h: string) => ({ ...{ dangerouslySetInnerHTML: { __html: h } } });\n", 'no-restricted-syntax'],
    ['eval', 'export const a = (s: string) => eval(s);\n', 'no-eval'],
    ['new Function', "export const f = new Function('return 1');\n", 'no-new-func'],
    ['setTimeout med sträng', "setTimeout('alert(1)', 10);\n", 'no-implied-eval'],
    ['setInterval med sträng', 'setInterval(`alert(1)`, 10);\n', 'no-implied-eval'],
    ['en annan NEXT_PUBLIC-variabel', 'export const k = process.env.NEXT_PUBLIC_STRIPE_KEY;\n', 'no-restricted-syntax'],
    ['NEXT_PUBLIC med hakparentes', "export const k = process.env['NEXT_PUBLIC_COMPUTERCAT_GAME'];\n", 'no-restricted-syntax'],
    ['destrukturering av process.env', 'const { NEXT_PUBLIC_STRIPE_KEY } = process.env;\nexport const k = NEXT_PUBLIC_STRIPE_KEY;\n', 'no-restricted-syntax'],
    ['alias av process.env', 'const env = process.env;\nexport const k = env.NEXT_PUBLIC_STRIPE_KEY;\n', 'no-restricted-syntax'],
    ['spridning av process.env', 'export const all = { ...process.env };\n', 'no-restricted-syntax'],
    ['globalThis.process.env', 'export const k = globalThis.process.env.NEXT_PUBLIC_STRIPE_KEY;\n', 'no-restricted-syntax'],
    ['window.process.env', 'export const k = window.process.env.NEXT_PUBLIC_STRIPE_KEY;\n', 'no-restricted-syntax'],
    ['process[env] med hakparentes', "export const k = process['env'].NEXT_PUBLIC_STRIPE_KEY;\n", 'no-restricted-syntax'],
    ['tilldelning av dangerouslySetInnerHTML', 'export function f(el: HTMLElement, h: string) {\n  (el as unknown as { dangerouslySetInnerHTML: unknown }).dangerouslySetInnerHTML = { __html: h };\n}\n', 'no-restricted-syntax'],
    ['dangerouslySetInnerHTML med hakparentes', "export const x = (o: Record<string, unknown>) => o['dangerouslySetInnerHTML'];\n", 'no-restricted-syntax'],
    // v3 (faktabanksspecen, "Underlag till byggguide v3"): omvägar som v2 släppte igenom.
    ['destrukturering av env ur process', 'const { env } = process;\nexport const k = env.NEXT_PUBLIC_STRIPE_KEY;\n', 'no-restricted-syntax'],
    ["self['process'].env", "export const k = self['process'].env.NEXT_PUBLIC_STRIPE_KEY;\n", 'no-restricted-syntax'],
    ["globalThis['process'].env", "export const k = globalThis['process'].env.NEXT_PUBLIC_STRIPE_KEY;\n", 'no-restricted-syntax'],
    ['alias av process', 'const p = process;\nexport const k = p.env.NEXT_PUBLIC_STRIPE_KEY;\n', 'no-restricted-syntax'],
    ['process ur globalThis med destrukturering', 'const { process: p } = globalThis;\nexport const k = p.env.NEXT_PUBLIC_STRIPE_KEY;\n', 'no-restricted-syntax'],
    ['process som argument', 'const read = (o: { env: Record<string, string | undefined> }) => o.env.NEXT_PUBLIC_STRIPE_KEY;\nexport const k = read(process);\n', 'no-restricted-syntax'],
    ['globalThis.process.env.NODE_ENV', 'export const n = globalThis.process.env.NODE_ENV;\n', 'no-restricted-syntax'],
    ['self[`process`] med mall-literal', 'export const k = self[`process`].env.NEXT_PUBLIC_STRIPE_KEY;\n', 'no-restricted-syntax'],
    ["import { env } from 'process'", "import { env } from 'process';\nexport const k = env.NEXT_PUBLIC_STRIPE_KEY;\n", 'no-restricted-imports'],
    ["import { env } from 'node:process'", "import { env } from 'node:process';\nexport const k = env.NEXT_PUBLIC_STRIPE_KEY;\n", 'no-restricted-imports'],
    ["import * as p from 'process'", "import * as p from 'process';\nexport const k = p.env.NEXT_PUBLIC_STRIPE_KEY;\n", 'no-restricted-imports'],
    ["import p from 'node:process'", "import p from 'node:process';\nexport const k = p.env.NEXT_PUBLIC_STRIPE_KEY;\n", 'no-restricted-imports'],
    ["require('process')", "export const k = require('process').env.NEXT_PUBLIC_STRIPE_KEY;\n", 'no-restricted-syntax'],
    ["require('node:process')", "export const k = require('node:process').env.NEXT_PUBLIC_STRIPE_KEY;\n", 'no-restricted-syntax'],
    ["import('node:process')", "export const k = import('node:process').then((m) => m.env.NEXT_PUBLIC_STRIPE_KEY);\n", 'no-restricted-syntax'],
  ])('fäller %s', async (_name, code, rule) => {
    const rules = await rulesFor(code);
    expect(rules.filter((r) => r.startsWith('parse:'))).toEqual([]);
    expect(rules).toContain(rule);
  }, 30_000);

  it('släpper igenom de två tillåtna variablerna, timers med funktion och vanlig JSX', async () => {
    const code = [
      'export const g = process.env.NEXT_PUBLIC_COMPUTERCAT_GAME;',
      'export const u = process.env.NEXT_PUBLIC_COMPUTERCAT_URL;',
      'export const n = process.env.NODE_ENV;',
      'setTimeout(() => undefined, 10);',
      'export function B({ t }: { t: string }) {',
      '  return <p>{t}</p>;',
      '}',
      '',
    ].join('\n');
    const rules = await rulesFor(code);
    expect(rules.filter((r) => r.startsWith('parse:'))).toEqual([]);
    for (const rule of ['no-restricted-syntax', 'no-eval', 'no-new-func', 'no-implied-eval']) {
      expect(rules).not.toContain(rule);
    }
  }, 30_000);

  it('testfiler får läsa process.cwd() (de bakas aldrig in i out/)', async () => {
    const [result] = await eslint.lintText("import { resolve } from 'node:path';\nexport const root = resolve(process.cwd(), 'src');\n", {
      filePath: resolve(process.cwd(), 'src/lib/computercat/config.test.ts'),
    });
    const rules = result.messages.map((m) => m.ruleId ?? `parse: ${m.message}`);
    expect(rules.filter((r) => r.startsWith('parse:'))).toEqual([]);
    expect(rules).not.toContain('no-restricted-syntax');
  }, 30_000);

  // Spelets kod gäller var den än ligger i repot, inte bara under src/.
  it.each([
    ['process.env.SECRET_KEY i lib/x.ts i roten', 'lib/x.ts', 'export const k = process.env.SECRET_KEY;\n'],
    ['process.env.SECRET_KEY i app/page.tsx i roten', 'app/page.tsx', 'export const k = process.env.SECRET_KEY;\n'],
    ['const p = process i lib/x.ts i roten', 'lib/x.ts', 'const p = process;\nexport const k = p.env.SECRET_KEY;\n'],
  ])('fäller %s', async (_name, file, code) => {
    const rules = await rulesFor(code, resolve(process.cwd(), file));
    expect(rules.filter((r) => r.startsWith('parse:'))).toEqual([]);
    expect(rules).toContain('no-restricted-syntax');
  }, 30_000);

  it('guidens skript körs i Node vid bygget och får läsa process.env (och parsas)', async () => {
    const rules = await rulesFor('export const h = process.env.HOME;\n', resolve(process.cwd(), 'scripts/foo.mjs'));
    expect(rules.filter((r) => r.startsWith('parse:'))).toEqual([]);
    expect(rules).not.toContain('no-restricted-syntax');
  }, 30_000);

  it('ett objektfält som heter process (shorthand=false-nyckel) fälls inte', async () => {
    const rules = await rulesFor('export const handlers = { process: () => 1 };\n', resolve(process.cwd(), 'src/lib/x.ts'));
    expect(rules.filter((r) => r.startsWith('parse:'))).toEqual([]);
    expect(rules).not.toContain('no-restricted-syntax');
  }, 30_000);

  it('src/app/page.tsx får läsa de två tillåtna variablerna', async () => {
    const code = 'export const g = process.env.NEXT_PUBLIC_COMPUTERCAT_GAME;\nexport const n = process.env.NODE_ENV;\n';
    const rules = await rulesFor(code, resolve(process.cwd(), 'src/app/page.tsx'));
    expect(rules.filter((r) => r.startsWith('parse:'))).toEqual([]);
    expect(rules).not.toContain('no-restricted-syntax');
  }, 30_000);
});
