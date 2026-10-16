import { afterEach, describe, expect, it, vi } from 'vitest';
import { BASE, fakeFetch, json } from '../../../test/fakeFetch';
import { ApiError, NetworkError, preview, requestJson } from './http';

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
