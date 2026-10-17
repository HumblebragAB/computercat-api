import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { BASE, fakeFetch, json } from '../../../test/fakeFetch';
import { ApiError, NetworkError, TIMEOUT_MS, preview, requestJson, requestJsonMeta, type FetchLike } from './http';

afterEach(() => vi.restoreAllMocks());

describe('requestJson', () => {
  it('skickar JSON med Accept, Content-Type och Bearer-token', async () => {
    const f = fakeFetch({ 'POST /x': [json(201, { data: { ok: true } })] });
    const res = await requestJson<{ data: { ok: boolean } }>(f.fetch, BASE, 'POST', '/x', { token: 't1', body: { score: 3 } });
    expect(res.data.ok).toBe(true);
    expect(f.calls[0].headers).toEqual({
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Authorization: 'Bearer t1',
    });
    expect(f.calls[0].body).toEqual({ score: 3 });
  });

  it('GET utan body skickar ingen Content-Type och ingen token', async () => {
    const f = fakeFetch({ 'GET /y': [json(200, {})] });
    await requestJson(f.fetch, BASE, 'GET', '/y');
    expect(f.calls[0].headers).toEqual({ Accept: 'application/json' });
  });

  it('4xx ger ApiError med serverns meddelande och loggar request och svar', async () => {
    const error = vi.spyOn(console, 'error').mockImplementation(() => {});
    const f = fakeFetch({ 'POST /x': [json(422, { message: 'Score is implausibly low.' })] });
    const p = requestJson(f.fetch, BASE, 'POST', '/x', { token: 'hemlig', body: { score: 1 } });
    await expect(p).rejects.toMatchObject({ name: 'ApiError', status: 422, message: 'Score is implausibly low.' });
    expect(error).toHaveBeenCalledWith('[computercat] POST /x → 422', {
      request: '{"score":1}',
      response: '{"message":"Score is implausibly low."}',
    });
    expect(JSON.stringify(error.mock.calls)).not.toContain('hemlig');
  });

  it('fel som inte är JSON (502 från proxyn) ger ApiError med texten', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    const f = fakeFetch({ 'GET /y': [new Response('<html>Bad gateway</html>', { status: 502 })] });
    const err = await requestJson(f.fetch, BASE, 'GET', '/y').catch((e: unknown) => e);
    expect(err).toBeInstanceOf(ApiError);
    expect((err as ApiError).message).toBe('HTTP 502');
    expect((err as ApiError).body).toBe('<html>Bad gateway</html>');
  });

  it('nätverksfel ger NetworkError, inte ApiError', async () => {
    const f = fakeFetch({ 'GET /y': ['network-error'] });
    await expect(requestJson(f.fetch, BASE, 'GET', '/y')).rejects.toBeInstanceOf(NetworkError);
  });

  it('lösenord och token i request och svar maskeras i loggen, även nästlade', async () => {
    const error = vi.spyOn(console, 'error').mockImplementation(() => {});
    const f = fakeFetch({ 'POST /auth/login': [json(422, { message: 'Fel', token: 'svar-token' })] });
    const body = { email: 'a@b.se', Password: 'hemligt-lösen', nested: { list: [{ current_password: 'gammalt', secret: 's' }] } };
    await requestJson(f.fetch, BASE, 'POST', '/auth/login', { body }).catch(() => {});
    const logged = JSON.stringify(error.mock.calls);
    for (const v of ['hemligt-lösen', 'gammalt', 'svar-token']) expect(logged).not.toContain(v);
    expect(logged).toContain('a@b.se');
    expect(logged).toContain('[REDACTED]');
  });

  it('loggen kortas till 4 KB', () => {
    const out = preview('a'.repeat(10_000));
    expect(out.startsWith('a'.repeat(4096))).toBe(true);
    expect(out).toContain('(+5904 tecken)');
  });
});

describe('requestJsonMeta', () => {
  /** fakeFetch registrerar inte `cache` eller `signal`; lindningen fångar init och respekterar en avbruten signal. */
  function capturing(inner: FetchLike) {
    const inits: RequestInit[] = [];
    const fetch: FetchLike = async (input, init) => {
      inits.push(init);
      if (init.signal?.aborted) throw init.signal.reason;
      return inner(input, init);
    };
    return { fetch, inits };
  }

  it('200 ger status, body och etag', async () => {
    const f = fakeFetch({
      'GET /m/1': [new Response(JSON.stringify({ data: { version: 3 } }), { status: 200, headers: { 'Content-Type': 'application/json', ETag: '"3-11"' } })],
    });
    const res = await requestJsonMeta<{ data: { version: number } }>(f.fetch, BASE, 'GET', '/m/1', { token: 't1' });
    expect(res).toEqual({ status: 200, body: { data: { version: 3 } }, etag: '"3-11"' });
    expect(f.calls[0].headers.Authorization).toBe('Bearer t1');
    expect(f.unmatched).toEqual([]);
  });

  it('200 utan ETag ger etag null', async () => {
    const f = fakeFetch({ 'GET /m/1': [json(200, { ok: true })] });
    const res = await requestJsonMeta(f.fetch, BASE, 'GET', '/m/1', {});
    expect(res.etag).toBeNull();
  });

  it('304 ger status 304 och body null utan fel och utan loggning', async () => {
    const error = vi.spyOn(console, 'error').mockImplementation(() => {});
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const f = fakeFetch({ 'GET /m/1': [new Response(null, { status: 304, headers: { ETag: '"3-11"' } })] });
    const res = await requestJsonMeta(f.fetch, BASE, 'GET', '/m/1', { headers: { 'If-None-Match': '"3-11"' } });
    expect(res).toEqual({ status: 304, body: null, etag: '"3-11"' });
    expect(error).not.toHaveBeenCalled();
    expect(warn).not.toHaveBeenCalled();
  });

  it('skickar If-None-Match och cache: no-store', async () => {
    const f = fakeFetch({ 'GET /m/1': [new Response(null, { status: 304, headers: { ETag: '"3-11"' } })] });
    const c = capturing(f.fetch);
    await requestJsonMeta(c.fetch, BASE, 'GET', '/m/1', { token: 't1', headers: { 'If-None-Match': '"3-11"' }, noStore: true });
    expect(f.calls[0].headers).toEqual({ Accept: 'application/json', 'If-None-Match': '"3-11"', Authorization: 'Bearer t1' });
    expect(c.inits[0].cache).toBe('no-store');
  });

  it('utan noStore sätts ingen cache-inställning', async () => {
    const f = fakeFetch({ 'GET /m/1': [json(200, {})] });
    const c = capturing(f.fetch);
    await requestJsonMeta(c.fetch, BASE, 'GET', '/m/1', {});
    expect(c.inits[0].cache).toBeUndefined();
  });

  it('egen authorization-header i gemener tas bort, även utan token', async () => {
    const f = fakeFetch({ 'GET /m/1': [json(200, {})] });
    await requestJsonMeta(f.fetch, BASE, 'GET', '/m/1', { token: 't1', headers: { authorization: 'Bearer annan', 'If-None-Match': '"1-1"' } });
    expect(f.calls[0].headers).toEqual({ Accept: 'application/json', 'If-None-Match': '"1-1"', Authorization: 'Bearer t1' });
    await requestJsonMeta(f.fetch, BASE, 'GET', '/m/1', { headers: { AUTHORIZATION: 'Bearer annan' } });
    expect(f.calls[1].headers).toEqual({ Accept: 'application/json' });
  });

  describe('utan AbortSignal.any', () => {
    let original: PropertyDescriptor | undefined;
    beforeEach(() => {
      original = Object.getOwnPropertyDescriptor(AbortSignal, 'any');
      Object.defineProperty(AbortSignal, 'any', { value: undefined, configurable: true, writable: true });
    });
    afterEach(() => {
      vi.useRealTimers();
      if (original) Object.defineProperty(AbortSignal, 'any', original);
    });

    it('lyssnare på anroparens signal tas bort efter varje anrop', async () => {
      const f = fakeFetch({ 'GET /m/1': [json(200, {})] });
      const ctrl = new AbortController();
      const add = vi.spyOn(ctrl.signal, 'addEventListener');
      const remove = vi.spyOn(ctrl.signal, 'removeEventListener');
      for (let i = 0; i < 5; i++) await requestJsonMeta(f.fetch, BASE, 'GET', '/m/1', { signal: ctrl.signal });
      expect(add).toHaveBeenCalledTimes(5);
      expect(remove).toHaveBeenCalledTimes(5);
      for (let i = 0; i < 5; i++) expect(remove.mock.calls[i][1]).toBe(add.mock.calls[i][1]);
    });

    it('lyssnaren tas bort även när anropet misslyckas', async () => {
      vi.spyOn(console, 'error').mockImplementation(() => {});
      const f = fakeFetch({ 'GET /m/1': [json(500, {}), 'network-error'] });
      const ctrl = new AbortController();
      const add = vi.spyOn(ctrl.signal, 'addEventListener');
      const remove = vi.spyOn(ctrl.signal, 'removeEventListener');
      await requestJsonMeta(f.fetch, BASE, 'GET', '/m/1', { signal: ctrl.signal }).catch(() => {});
      await requestJsonMeta(f.fetch, BASE, 'GET', '/m/1', { signal: ctrl.signal }).catch(() => {});
      expect(add).toHaveBeenCalledTimes(2);
      expect(remove).toHaveBeenCalledTimes(2);
    });

    it('timeouten avbryter fortfarande', async () => {
      vi.useFakeTimers();
      const ctrl = new AbortController();
      const fetch: FetchLike = (_input, init) =>
        new Promise((_resolve, reject) => {
          init.signal?.addEventListener('abort', () => reject(init.signal?.reason));
        });
      const p = requestJsonMeta(fetch, BASE, 'GET', '/m/1', { signal: ctrl.signal });
      const done = expect(p).rejects.toBeInstanceOf(NetworkError);
      await vi.advanceTimersByTimeAsync(TIMEOUT_MS);
      await done;
      expect(ctrl.signal.aborted).toBe(false);
    });
  });

  it('egen header kan inte ersätta Authorization', async () => {
    const f = fakeFetch({ 'GET /m/1': [json(200, {})] });
    await requestJsonMeta(f.fetch, BASE, 'GET', '/m/1', { token: 't1', headers: { Authorization: 'Bearer annan' } });
    expect(f.calls[0].headers.Authorization).toBe('Bearer t1');
  });

  it('avbruten signal ger NetworkError', async () => {
    const f = fakeFetch({ 'GET /m/1': [json(200, {})] });
    const c = capturing(f.fetch);
    const ctrl = new AbortController();
    ctrl.abort();
    await expect(requestJsonMeta(c.fetch, BASE, 'GET', '/m/1', { signal: ctrl.signal })).rejects.toBeInstanceOf(NetworkError);
    expect(c.inits[0].signal?.aborted).toBe(true);
  });

  it('signal som avbryts under anropet når fetch', async () => {
    const ctrl = new AbortController();
    const fetch: FetchLike = (_input, init) =>
      new Promise((_resolve, reject) => {
        init.signal?.addEventListener('abort', () => reject(init.signal?.reason));
      });
    const p = requestJsonMeta(fetch, BASE, 'GET', '/m/1', { signal: ctrl.signal });
    ctrl.abort();
    await expect(p).rejects.toBeInstanceOf(NetworkError);
  });

  it('utan AbortSignal.any kombineras signalen med en egen AbortController', async () => {
    const original = Object.getOwnPropertyDescriptor(AbortSignal, 'any');
    Object.defineProperty(AbortSignal, 'any', { value: undefined, configurable: true, writable: true });
    try {
      const ctrl = new AbortController();
      let seen: AbortSignal | undefined;
      const fetch: FetchLike = (_input, init) =>
        new Promise((_resolve, reject) => {
          seen = init.signal ?? undefined;
          init.signal?.addEventListener('abort', () => reject(init.signal?.reason));
        });
      const p = requestJsonMeta(fetch, BASE, 'GET', '/m/1', { signal: ctrl.signal });
      expect(seen).not.toBe(ctrl.signal);
      ctrl.abort();
      await expect(p).rejects.toBeInstanceOf(NetworkError);
    } finally {
      if (original) Object.defineProperty(AbortSignal, 'any', original);
    }
  });

  it('409 kastar ApiError med body', async () => {
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    const f = fakeFetch({ 'POST /m/1/actions': [json(409, { message: 'Stale.', error: 'stale' })] });
    const err = await requestJsonMeta(f.fetch, BASE, 'POST', '/m/1/actions', { body: { type: 'x' } }).catch((e: unknown) => e);
    expect(err).toBeInstanceOf(ApiError);
    expect((err as ApiError).status).toBe(409);
    expect((err as ApiError).body).toEqual({ message: 'Stale.', error: 'stale' });
    expect(f.calls[0].body).toEqual({ type: 'x' });
  });

  it('5xx loggas med console.error som requestJson', async () => {
    const error = vi.spyOn(console, 'error').mockImplementation(() => {});
    const f = fakeFetch({ 'GET /m/1': [json(500, { message: 'Server Error' })] });
    await expect(requestJsonMeta(f.fetch, BASE, 'GET', '/m/1', {})).rejects.toMatchObject({ name: 'ApiError', status: 500 });
    expect(error).toHaveBeenCalledWith('[computercat] GET /m/1 → 500', { request: '', response: '{"message":"Server Error"}' });
  });

  it('nätverksfel ger NetworkError', async () => {
    const f = fakeFetch({ 'GET /m/1': ['network-error'] });
    await expect(requestJsonMeta(f.fetch, BASE, 'GET', '/m/1', {})).rejects.toBeInstanceOf(NetworkError);
  });

  it('Retry-After i sekunder hamnar i ApiError.retryAfter; saknad eller HTTP-datum ger null', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    const limited = (retryAfter?: string) =>
      new Response(JSON.stringify({ message: 'För många anrop.', code: 'rate_limited' }), {
        status: 429,
        headers: { 'Content-Type': 'application/json', ...(retryAfter === undefined ? {} : { 'Retry-After': retryAfter }) },
      });
    const f = fakeFetch({ 'GET /m/1': [limited('3'), limited(), limited('Wed, 21 Oct 2026 07:28:00 GMT')] });
    const errs: unknown[] = [];
    for (let i = 0; i < 3; i++) errs.push(await requestJsonMeta(f.fetch, BASE, 'GET', '/m/1', {}).catch((e: unknown) => e));
    expect(errs.map((e) => (e as ApiError).retryAfter)).toEqual([3, null, null]);
  });
});
