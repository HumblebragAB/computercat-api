'use client';

import { useEffect } from 'react';
import { getComputercat, startBackgroundSync } from '@/lib/computercat';

/** Kontrollerar spelaren och tömmer resultatkön vid start, vid "online" och varje minut. */
export function BackgroundSync() {
  useEffect(() => {
    const cc = getComputercat();
    if (!cc.ok) return;
    cc.client.auth.verify().catch((e: unknown) => console.warn('[computercat] kunde inte kontrollera spelaren', e));
    return startBackgroundSync(() => cc.client.queue.flush(), window);
  }, []);
  return null;
}
