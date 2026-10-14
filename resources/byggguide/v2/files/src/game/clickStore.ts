import { create } from 'zustand';
import { createJSONStorage, persist, type StateStorage } from 'zustand/middleware';

export const ROUND_MS = 10_000;

type Phase = 'idle' | 'running' | 'finished';

type ClickState = {
  phase: Phase;
  clicks: number;
  startedAt: number | null;
  lastScore: number | null;
  best: number;
  start: (now: number) => void;
  click: (now: number) => void;
  /** Avslutar rundan och ger resultatet, eller null om ingen runda pågick. */
  finish: () => number | null;
  reset: () => void;
};

/**
 * Lagringen kommer utifrån så att testerna kan ge en egen. I webbläsaren är den
 * localStorage; saknas den (Node, privat läge) körs spelet utan sparat bästa resultat.
 */
export function createClickStore(getStorage: () => StateStorage) {
  return create<ClickState>()(
    persist(
      (set, get) => ({
        phase: 'idle',
        clicks: 0,
        startedAt: null,
        lastScore: null,
        best: 0,
        start(now) {
          if (get().phase === 'running') return;
          set({ phase: 'running', clicks: 0, startedAt: now, lastScore: null });
        },
        click(now) {
          const s = get();
          if (s.phase !== 'running' || s.startedAt === null || now - s.startedAt >= ROUND_MS) return;
          set({ clicks: s.clicks + 1 });
        },
        finish() {
          const s = get();
          if (s.phase !== 'running') return null;
          set({ phase: 'finished', startedAt: null, lastScore: s.clicks, best: Math.max(s.best, s.clicks) });
          return s.clicks;
        },
        reset() {
          set({ phase: 'idle', clicks: 0, startedAt: null });
        },
      }),
      {
        name: 'spelmall-click',
        storage: createJSONStorage(getStorage),
        partialize: (s) => ({ best: s.best }),
        // Den statiska HTML:en renderas utan localStorage; läs in efter mount så att hydreringen stämmer.
        skipHydration: true,
      },
    ),
  );
}

// `() => localStorage` anropas först när lagringen behövs; saknas den fångar createJSONStorage felet.
export const useClickStore = createClickStore(() => localStorage);
