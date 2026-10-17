import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const root = process.cwd();
const read = (p: string) => readFileSync(resolve(root, p), 'utf8');
const walk = (dir: string): string[] =>
  readdirSync(dir).flatMap((n) => {
    const p = join(dir, n);
    return statSync(p).isDirectory() ? walk(p) : [p];
  });

// Kontraktet med computercat-api (2c, steget repo). Ändras det här måste 2c ändras samtidigt.
const START = '<!-- computercat:idea:start -->';
const END = '<!-- computercat:idea:end -->';

describe('README och CLAUDE.md', () => {
  it('README har exakt en idé-markör av varje slag, i rätt ordning, på egna rader', () => {
    const lines = read('README.md').split('\n');
    const starts = lines.flatMap((l, i) => (l === START ? [i] : []));
    const ends = lines.flatMap((l, i) => (l === END ? [i] : []));
    expect(starts).toHaveLength(1);
    expect(ends).toHaveLength(1);
    expect(starts[0]).toBeLessThan(ends[0]);
    expect(read('README.md').split(START)).toHaveLength(2);
    expect(read('README.md').split(END)).toHaveLength(2);
  });

  it('CLAUDE.md innehåller specens regler och verktyg', () => {
    const md = read('CLAUDE.md');
    for (const s of [
      'Bygg aldrig en egen backend',
      'get_game',
      'upsert_',
      'get_integration_guide',
      'get_build_guide',
      'Pusha till `main` när en ändring är klar och testad; det deployar Demo automatiskt.',
      'get_provisioning_status',
      'computercat-api',
      'npm run check',
      'npm audit --omit=dev --audit-level=high',
      'search_facts',
      'get_fact',
      'list_facts',
      '"build_guide": "v3"',
      'achievements.report',
      'scripts/ios-setup.mjs',
      'scripts/check-ios.mjs',
      'promote_release(target: app_store, stage: testflight',
      'scripts/cc-gate.mjs',
    ]) {
      expect(md, s).toContain(s);
    }
  });

  it('sidan gör inga nätverksanrop vid bygget', () => {
    for (const f of walk(resolve(root, 'src/app'))) {
      const src = readFileSync(f, 'utf8');
      expect(src, f).not.toContain('fetch(');
      expect(src, f).not.toContain('next/font/google');
    }
  });
});
