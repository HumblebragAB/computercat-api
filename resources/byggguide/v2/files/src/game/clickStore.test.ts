import { beforeEach, describe, expect, it } from 'vitest';
import { memoryStorage, type KeyValueStore } from '../lib/computercat/storage';
import { createClickStore, ROUND_MS } from './clickStore';

const T0 = 1_000_000;

// Storen får sin lagring injicerad; testet rör aldrig globalThis.localStorage, som i
// Node 25+ är en getter som inte går att skriva över (och som varnar utan --localstorage-file).
let storage: KeyValueStore;
let useClickStore: ReturnType<typeof createClickStore>;

beforeEach(() => {
  storage = memoryStorage();
  useClickStore = createClickStore(() => storage);
});

describe('useClickStore', () => {
  it('klick räknas inte innan rundan startat', () => {
    useClickStore.getState().click(T0);
    expect(useClickStore.getState().clicks).toBe(0);
  });

  it('en runda räknar klick och ger resultatet', () => {
    const s = useClickStore.getState();
    s.start(T0);
    s.click(T0 + 100);
    s.click(T0 + 200);
    s.click(T0 + 300);
    expect(useClickStore.getState().finish()).toBe(3);
    expect(useClickStore.getState()).toMatchObject({ phase: 'finished', lastScore: 3, best: 3 });
  });

  it('klick efter tio sekunder räknas inte', () => {
    const s = useClickStore.getState();
    s.start(T0);
    s.click(T0 + ROUND_MS);
    expect(useClickStore.getState().clicks).toBe(0);
  });

  it('finish två gånger ger resultatet bara en gång', () => {
    const s = useClickStore.getState();
    s.start(T0);
    s.click(T0 + 1);
    expect(useClickStore.getState().finish()).toBe(1);
    expect(useClickStore.getState().finish()).toBeNull();
  });

  it('bästa resultat behålls över rundor', () => {
    const s = useClickStore.getState();
    s.start(T0);
    for (let i = 1; i <= 5; i++) s.click(T0 + i);
    useClickStore.getState().finish();
    s.start(T0 + 20_000);
    s.click(T0 + 20_001);
    useClickStore.getState().finish();
    expect(useClickStore.getState().best).toBe(5);
  });

  it('bara bästa resultatet sparas lokalt', () => {
    const s = useClickStore.getState();
    s.start(T0);
    s.click(T0 + 1);
    useClickStore.getState().finish();
    const saved = JSON.parse(storage.getItem('spelmall-click')!);
    expect(saved.state).toEqual({ best: 1 });
  });
});
