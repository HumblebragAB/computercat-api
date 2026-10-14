import { afterEach, describe, expect, it, vi } from 'vitest';
import { browserStorage, memoryStorage, readJson, writeJson } from './storage';

const isNumberBox = (v: unknown): v is { n: number } =>
  typeof v === 'object' && v !== null && typeof (v as { n?: unknown }).n === 'number';

afterEach(() => vi.restoreAllMocks());

describe('storage', () => {
  it('skriver och läser JSON', () => {
    const s = memoryStorage();
    writeJson(s, 'k', { n: 3 });
    expect(readJson(s, 'k', isNumberBox)).toEqual({ n: 3 });
  });

  it('trasig JSON ignoreras synligt och tas bort', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const s = memoryStorage();
    s.setItem('k', '{inte json');
    expect(readJson(s, 'k', isNumberBox)).toBeNull();
    expect(s.getItem('k')).toBeNull();
    expect(warn).toHaveBeenCalled();
  });

  it('värde med fel form ignoreras', () => {
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    const s = memoryStorage();
    s.setItem('k', JSON.stringify({ n: 'tre' }));
    expect(readJson(s, 'k', isNumberBox)).toBeNull();
  });

  it('utan webbläsare blir det minneslagring med en varning', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const s = browserStorage();
    s.setItem('a', 'b');
    expect(s.getItem('a')).toBe('b');
    expect(warn).toHaveBeenCalledWith(expect.stringContaining('localStorage'));
  });
});
