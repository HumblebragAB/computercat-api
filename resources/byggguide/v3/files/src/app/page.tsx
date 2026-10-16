'use client';

import { useCallback, useState } from 'react';
import { ClickGame } from '@/components/ClickGame';
import { ConfigBanner } from '@/components/ConfigBanner';
import { Leaderboard } from '@/components/Leaderboard';

export default function Home() {
  const [refreshKey, setRefreshKey] = useState(0);
  const onScoreSent = useCallback(() => setRefreshKey((k) => k + 1), []);

  return (
    <main className="mx-auto flex min-h-full max-w-md flex-col gap-8 px-4 py-10">
      <ConfigBanner />
      <header className="flex flex-col gap-2">
        <h1 className="text-3xl font-bold">Klickrace</h1>
        <p className="text-stone-700">
          Klicka så många gånger du kan på tio sekunder. Ditt bästa resultat per dygn (UTC) hamnar på topplistan.
        </p>
      </header>
      <ClickGame onScoreSent={onScoreSent} />
      <Leaderboard refreshKey={refreshKey} />
    </main>
  );
}
