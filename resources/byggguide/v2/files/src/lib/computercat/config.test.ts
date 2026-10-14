import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { DEFAULT_BASE_URL, parseConfig } from './config';

describe('parseConfig', () => {
  it('använder standardadressen när URL saknas', () => {
    expect(parseConfig({ game: 'kattsvep' })).toEqual({
      ok: true,
      config: { game: 'kattsvep', baseUrl: DEFAULT_BASE_URL },
    });
  });

  it('tar bort avslutande snedstreck från en egen adress', () => {
    const r = parseConfig({ game: 'kattsvep', url: 'https://staging.example.se/api/v1/' });
    expect(r).toEqual({ ok: true, config: { game: 'kattsvep', baseUrl: 'https://staging.example.se/api/v1' } });
  });

  it('saknat spel ger ett synligt fel, inte ett påhittat spel', () => {
    const r = parseConfig({ game: '  ' });
    expect(r.ok).toBe(false);
    if (!r.ok) expect(r.error).toContain('NEXT_PUBLIC_COMPUTERCAT_GAME');
  });

  it('avvisar en slug som skulle ändra URL:en', () => {
    expect(parseConfig({ game: 'kattsvep/../admin' }).ok).toBe(false);
    expect(parseConfig({ game: 'Kattsvep' }).ok).toBe(false);
  });

  it('kräver https utom mot localhost', () => {
    expect(parseConfig({ game: 'kattsvep', url: 'http://api.computercat.co/api/v1' }).ok).toBe(false);
    expect(parseConfig({ game: 'kattsvep', url: 'http://localhost:8000/api/v1' }).ok).toBe(true);
    expect(parseConfig({ game: 'kattsvep', url: 'inte en adress' }).ok).toBe(false);
  });

  it('readConfig läser variablerna med bokstavliga namn så att Next bakar in dem', () => {
    const src = readFileSync(resolve(process.cwd(), 'src/lib/computercat/config.ts'), 'utf8');
    expect(src).toContain('process.env.NEXT_PUBLIC_COMPUTERCAT_GAME');
    expect(src).toContain('process.env.NEXT_PUBLIC_COMPUTERCAT_URL');
    expect(src).not.toMatch(/process\.env\[/);
  });
});
