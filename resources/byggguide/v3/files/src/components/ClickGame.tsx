'use client';

import { useEffect, useState } from 'react';
import { motion } from 'framer-motion';
import { ROUND_MS, useClickStore } from '@/game/clickStore';
import { LEADERBOARD_TYPE } from '@/game/constants';
import { describeError, getComputercat } from '@/lib/computercat';

function sendScore(score: number, setStatus: (s: string) => void, onScoreSent: () => void): void {
  const cc = getComputercat();
  if (!cc.ok) {
    setStatus(`Inte skickat: ${cc.error}`);
    return;
  }
  let flushed;
  try {
    ({ flushed } = cc.client.recordScore(LEADERBOARD_TYPE, score, { round_ms: ROUND_MS }));
  } catch (e) {
    setStatus(`Resultatet kunde inte sparas: ${describeError(e)}`);
    return;
  }
  setStatus('Sparat lokalt, skickas till topplistan…');
  flushed.then(
    (r) => {
      if (r.dropped.length > 0) {
        setStatus(`Computercat tog inte emot resultatet: ${r.dropped.map((d) => d.reason).join('; ')}`);
      } else if (r.remaining > 0) {
        setStatus(`Offline: ${r.remaining} resultat i kö, skickas när nätet är tillbaka.`);
      } else {
        setStatus('Skickat till topplistan.');
        onScoreSent();
      }
    },
    (e: unknown) => setStatus(describeError(e)),
  );
}

export function ClickGame({ onScoreSent }: { onScoreSent: () => void }) {
  const phase = useClickStore((s) => s.phase);
  const clicks = useClickStore((s) => s.clicks);
  const startedAt = useClickStore((s) => s.startedAt);
  const lastScore = useClickStore((s) => s.lastScore);
  const best = useClickStore((s) => s.best);
  const [now, setNow] = useState(0);
  const [status, setStatus] = useState<string | null>(null);

  useEffect(() => {
    void useClickStore.persist.rehydrate();
  }, []);

  useEffect(() => {
    if (phase !== 'running') return;
    const id = setInterval(() => {
      const t = Date.now();
      setNow(t);
      const s = useClickStore.getState();
      if (s.startedAt !== null && t - s.startedAt >= ROUND_MS) {
        const score = s.finish();
        if (score !== null) sendScore(score, setStatus, onScoreSent);
      }
    }, 100);
    return () => clearInterval(id);
  }, [phase, onScoreSent]);

  function handlePress() {
    const t = Date.now();
    if (phase === 'running') {
      useClickStore.getState().click(t);
      return;
    }
    setStatus(null);
    setNow(t);
    useClickStore.getState().start(t);
  }

  const remainingMs =
    phase === 'running' && startedAt !== null ? Math.min(ROUND_MS, Math.max(0, ROUND_MS - (now - startedAt))) : ROUND_MS;

  return (
    <section className="flex flex-col items-center gap-4">
      <div className="flex w-full justify-between text-sm text-stone-600">
        <span>Tid kvar: {Math.ceil(remainingMs / 1000)} s</span>
        <span>Bästa: {best}</span>
      </div>
      <motion.button
        type="button"
        onClick={handlePress}
        whileTap={{ scale: 0.92 }}
        aria-label={phase === 'running' ? `Klicka (${clicks} hittills)` : 'Starta en runda'}
        className="h-48 w-48 rounded-full bg-amber-700 text-4xl font-bold text-white shadow-lg active:bg-amber-800"
      >
        {phase === 'running' ? clicks : 'Start'}
      </motion.button>
      {lastScore !== null && phase !== 'running' && <p className="text-lg">Du fick {lastScore} klick.</p>}
      {status && (
        <p role="status" className="text-center text-sm text-stone-700">
          {status}
        </p>
      )}
    </section>
  );
}
