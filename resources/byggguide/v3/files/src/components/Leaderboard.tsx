'use client';

import { useEffect, useState } from 'react';
import { LEADERBOARD_TYPE } from '@/game/constants';
import { describeError, getComputercat, type LeaderboardPage } from '@/lib/computercat';

type BoardState = { kind: 'loading' } | { kind: 'error'; message: string } | { kind: 'ok'; page: LeaderboardPage };

async function loadBoard(): Promise<BoardState> {
  const cc = getComputercat();
  if (!cc.ok) return { kind: 'error', message: cc.error };
  try {
    return { kind: 'ok', page: await cc.client.leaderboards.get(LEADERBOARD_TYPE) };
  } catch (e) {
    return { kind: 'error', message: describeError(e) };
  }
}

export function Leaderboard({ refreshKey }: { refreshKey: number }) {
  const [state, setState] = useState<BoardState>({ kind: 'loading' });

  useEffect(() => {
    let cancelled = false;
    loadBoard().then((next) => {
      if (!cancelled) setState(next);
    });
    return () => {
      cancelled = true;
    };
  }, [refreshKey]);

  return (
    <section aria-labelledby="topplista" className="flex flex-col gap-2">
      <h2 id="topplista" className="text-xl font-semibold">
        Dagens topplista
      </h2>
      {state.kind === 'loading' && <p className="text-sm text-stone-600">Hämtar…</p>}
      {state.kind === 'error' && (
        <p role="alert" className="rounded-lg bg-red-100 p-3 text-sm text-red-900">
          {state.message}
        </p>
      )}
      {state.kind === 'ok' &&
        (state.page.entries.length === 0 ? (
          <p className="text-sm text-stone-600">Ingen har spelat {state.page.periodKey} (UTC) än.</p>
        ) : (
          <ol className="flex flex-col gap-1">
            {state.page.entries.map((e) => (
              <li key={e.user.id} className="flex justify-between rounded-lg bg-white/70 px-3 py-2">
                <span>
                  {e.rank ?? '–'}. {e.user.display_name}
                </span>
                <span className="font-mono">{e.score}</span>
              </li>
            ))}
          </ol>
        ))}
    </section>
  );
}
