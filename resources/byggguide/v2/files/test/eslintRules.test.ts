import { resolve } from 'node:path';
import { ESLint } from 'eslint';
import { describe, expect, it } from 'vitest';

// Byggguide v2: säkerhetsreglerna i eslint.config.mjs (spec delprojekt 3, del 3, punkt 3).
// Koden lintas som om den låg i en befintlig komponent, med projektets riktiga konfiguration.
const FILE = resolve(process.cwd(), 'src/components/ConfigBanner.tsx');
const eslint = new ESLint({ cwd: process.cwd() });

async function rulesFor(code: string): Promise<string[]> {
  const [result] = await eslint.lintText(code, { filePath: FILE });
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
});
