import { afterEach, describe, expect, it, vi } from 'vitest';
import { startBackgroundSync } from './background';

afterEach(() => vi.useRealTimers());

describe('startBackgroundSync', () => {
  it('tömmer direkt, när nätet kommer tillbaka och med jämna mellanrum, och slutar vid stop', () => {
    vi.useFakeTimers();
    const flush = vi.fn(() => Promise.resolve());
    const target = new EventTarget();
    const stop = startBackgroundSync(flush, target, 60_000);
    expect(flush).toHaveBeenCalledTimes(1);
    target.dispatchEvent(new Event('online'));
    expect(flush).toHaveBeenCalledTimes(2);
    vi.advanceTimersByTime(60_000);
    expect(flush).toHaveBeenCalledTimes(3);
    stop();
    target.dispatchEvent(new Event('online'));
    vi.advanceTimersByTime(120_000);
    expect(flush).toHaveBeenCalledTimes(3);
  });
});
