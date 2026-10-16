// @vitest-environment jsdom
import { cleanup, render, screen, act } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const SLUG = 'kattsvep';
const API = 'https://api.computercat.co/api/v1';
const QUEUE_KEY = `computercat:${SLUG}:score-queue`;
const SESSION_KEY = `computercat:${SLUG}:session`;

type Entry = { rank: number; score: number; metadata: unknown; submitted_at: string; user: { id: number; display_name: string } };

const reply = (status: number, body: unknown) =>
  new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });

/** Tillståndsfull falsk Computercat-server bakom en stubbad global fetch. */
function fakeServer() {
  const server = {
    online: true,
    anonymousCreated: 0,
    requests: [] as { method: string; path: string; auth: string | null; body: unknown }[],
    entries: [] as Entry[],
  };
  const user = { id: 9, display_name: 'Player-9', is_anonymous: true, created_at: null };
  const fetchStub = async (input: string, init: RequestInit = {}) => {
    if (!server.online) throw new TypeError('Failed to fetch');
    const method = (init.method ?? 'GET').toUpperCase();
    const path = input.slice(API.length);
    const headers = (init.headers ?? {}) as Record<string, string>;
    const body = typeof init.body === 'string' ? JSON.parse(init.body) : undefined;
    server.requests.push({ method, path, auth: headers.Authorization ?? null, body });
    if (method === 'POST' && path === '/auth/anonymous') {
      server.anonymousCreated++;
      return reply(201, { user, token: '9|token' });
    }
    if (method === 'GET' && path === '/auth/me') return reply(200, { user });
    const board = `/games/${SLUG}/leaderboards/daily-clicks`;
    if (method === 'GET' && path === board) return reply(200, { data: server.entries, period_key: '2026-10-13' });
    if (method === 'POST' && path === board) {
      const { score, metadata } = body as { score: number; metadata: unknown };
      const entry: Entry = { rank: server.entries.length + 1, score, metadata, submitted_at: 'nu', user: { id: 9, display_name: 'Player-9' } };
      server.entries.push(entry);
      return reply(201, { data: entry });
    }
    return reply(404, { message: `okänd väg ${method} ${path}` });
  };
  return { server, fetchStub };
}

async function mountPage() {
  vi.resetModules(); // ny sidladdning: ny singleton, lagringen (localStorage) består
  const { default: Home } = await import('@/app/page');
  const { BackgroundSync } = await import('@/components/BackgroundSync'); // monteras i layout.tsx
  return render(
    <>
      <Home />
      <BackgroundSync />
    </>,
  );
}

async function playRound(clicks: number) {
  const user = userEvent.setup();
  await user.click(await screen.findByRole('button', { name: 'Starta en runda' }));
  for (let i = 0; i < clicks; i++) await user.click(screen.getByRole('button', { name: /^Klicka/ }));
  // Bara klockan är falsk (Date); spelets riktiga 100 ms-intervall märker att rundan tagit slut.
  vi.setSystemTime(Date.now() + 10_000);
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.stubEnv('NEXT_PUBLIC_COMPUTERCAT_GAME', SLUG);
  vi.stubEnv('NEXT_PUBLIC_COMPUTERCAT_URL', '');
  vi.spyOn(console, 'warn').mockImplementation(() => {});
  vi.spyOn(console, 'error').mockImplementation(() => {});
  localStorage.clear();
});

afterEach(() => {
  cleanup();
  vi.useRealTimers();
  vi.unstubAllGlobals();
  vi.unstubAllEnvs();
  vi.restoreAllMocks();
});

describe('hela vägen: spela, köa, skicka, visa topplistan', () => {
  it('en runda ger ett köat resultat som skickas, topplistan hämtas om och en omladdning behåller allt', async () => {
    const { server, fetchStub } = fakeServer();
    vi.stubGlobal('fetch', fetchStub);

    await mountPage();
    expect(await screen.findByText(/Ingen har spelat/)).toBeTruthy();

    await playRound(3);

    expect(await screen.findByText('Skickat till topplistan.')).toBeTruthy();
    const posts = server.requests.filter((r) => r.method === 'POST' && r.path.endsWith('/daily-clicks'));
    expect(posts).toHaveLength(1);
    expect(posts[0].body).toEqual({ score: 3, metadata: { round_ms: 10000 } }); // exemplet skickar inget proof
    expect(posts[0].auth).toBe('Bearer 9|token');
    expect(JSON.parse(localStorage.getItem(QUEUE_KEY)!)).toEqual([]);

    // Topplistan hämtades om efter att resultatet skickats och spelarens rad syns.
    expect(await screen.findByText('1. Player-9')).toBeTruthy();
    expect(screen.getByText('3', { selector: 'span.font-mono' })).toBeTruthy();
    expect(server.requests.filter((r) => r.method === 'GET' && r.path.endsWith('/daily-clicks')).length).toBeGreaterThanOrEqual(2);
    expect(screen.getByText('Bästa: 3')).toBeTruthy();
    expect(server.anonymousCreated).toBe(1);

    // Omladdning: samma localStorage, nya moduler.
    cleanup();
    await mountPage();
    expect(await screen.findByText('Bästa: 3')).toBeTruthy();
    expect(await screen.findByText('1. Player-9')).toBeTruthy();
    expect(server.anonymousCreated).toBe(1);
    expect(JSON.parse(localStorage.getItem(SESSION_KEY)!).token).toBe('9|token');
    await vi.waitFor(() => expect(server.requests.some((r) => r.path === '/auth/me' && r.auth === 'Bearer 9|token')).toBe(true));
    expect(server.anonymousCreated).toBe(1);
  });

  it('offline: synligt meddelande, resultatet ligger kvar i kön och skickas när nätet är tillbaka', async () => {
    const { server, fetchStub } = fakeServer();
    server.online = false;
    vi.stubGlobal('fetch', fetchStub);

    await mountPage();
    expect(await screen.findByText(/Offline: kunde inte nå Computercat/)).toBeTruthy(); // topplistan, ingen påhittad lista

    await playRound(2);

    expect(await screen.findByText('Offline: 1 resultat i kö, skickas när nätet är tillbaka.')).toBeTruthy();
    const queued = JSON.parse(localStorage.getItem(QUEUE_KEY)!);
    expect(queued).toHaveLength(1);
    expect(queued[0]).toMatchObject({ type: 'daily-clicks', score: 2 });

    server.online = true;
    act(() => {
      window.dispatchEvent(new Event('online'));
    });
    await vi.waitFor(() => expect(JSON.parse(localStorage.getItem(QUEUE_KEY)!)).toEqual([]), { timeout: 3000 });
    expect(server.entries).toHaveLength(1);
    expect(server.entries[0].score).toBe(2);
    expect(server.anonymousCreated).toBe(1);
  });
});
