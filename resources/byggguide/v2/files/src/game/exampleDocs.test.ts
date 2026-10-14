import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { expect, it } from 'vitest';
import { LEADERBOARD_TYPE } from './constants';

// Hör till exempelspelet och tas bort tillsammans med det (se CLAUDE.md, "Exemplets topplista").
it('CLAUDE.md beskriver exemplets topplista', () => {
  const md = readFileSync(resolve(process.cwd(), 'CLAUDE.md'), 'utf8');
  expect(md).toContain('upsert_leaderboard_type');
  expect(md).toContain(LEADERBOARD_TYPE);
});
