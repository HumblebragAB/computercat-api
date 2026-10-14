import { readConfig } from '@/lib/computercat';

/** Syns när NEXT_PUBLIC_COMPUTERCAT_* saknas eller är fel. Värdena bakas in vid bygget. */
export function ConfigBanner() {
  const cfg = readConfig();
  if (cfg.ok) return null;
  return (
    <div role="alert" className="rounded-lg bg-amber-100 p-3 text-sm text-amber-950">
      {cfg.error}
    </div>
  );
}
