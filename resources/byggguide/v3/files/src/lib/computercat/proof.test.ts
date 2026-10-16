import { describe, expect, it } from 'vitest';
import { createProof, fnv1aSignature } from './proof';

// Vektorerna är räknade 2026-10-13 med AntiCheatService::fnv1aSignature (PHP, byte för byte).
describe('fnv1aSignature', () => {
  it('matchar servern för ASCII', () => {
    expect(fnv1aSignature('round-1', 18, 'abc', 45000)).toBe('1uamwfr');
    expect(fnv1aSignature('2026-10-13', 42, 'k9', 10000)).toBe('afwtcv');
  });

  it('matchar servern för icke-ASCII (UTF-8-bytes, inte UTF-16)', () => {
    expect(fnv1aSignature('kattsvep-å', 1, 'x', 1)).toBe('wt2f7k');
  });
});

describe('createProof', () => {
  it('bygger beviset och avvisar värden som PHP ser som falska eller icke-heltal', () => {
    expect(createProof({ pid: 'round-1', moves: 18, stateHash: 'abc', elapsedMs: 45000 })).toEqual({
      pid: 'round-1',
      mc: 18,
      gh: 'abc',
      t: 45000,
      sig: '1uamwfr',
    });
    expect(() => createProof({ pid: '0', moves: 1, stateHash: 'x', elapsedMs: 1 })).toThrow(RangeError);
    expect(() => createProof({ pid: 'p', moves: 1, stateHash: '', elapsedMs: 1 })).toThrow(RangeError);
    expect(() => createProof({ pid: 'p', moves: 1.5, stateHash: 'x', elapsedMs: 1 })).toThrow(RangeError);
  });
});
