export type OnlineTarget = {
  addEventListener(type: 'online', listener: () => void): void;
  removeEventListener(type: 'online', listener: () => void): void;
};

/** Töm kön nu, när nätet kommer tillbaka och med jämna mellanrum. Returnerar stop(). */
export function startBackgroundSync(
  flush: () => Promise<unknown>,
  target: OnlineTarget,
  intervalMs = 60_000,
): () => void {
  const run = () => {
    void flush();
  };
  run();
  target.addEventListener('online', run);
  const id = setInterval(run, intervalMs);
  return () => {
    target.removeEventListener('online', run);
    clearInterval(id);
  };
}
