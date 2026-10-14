import { describe, expect, it } from 'vitest';
import { describeError } from './errors';
import { ApiError, NetworkError } from './http';

const api = (status: number, message: string) => new ApiError(status, message, { message }, 'GET', '/x');

describe('describeError', () => {
  it('saknad topplista pekar på upsert_leaderboard_type', () => {
    expect(describeError(api(404, 'Leaderboard type not found.'))).toContain('upsert_leaderboard_type');
  });

  it('okänt spel pekar på NEXT_PUBLIC_COMPUTERCAT_GAME', () => {
    expect(describeError(api(404, 'Game not found.'))).toContain('NEXT_PUBLIC_COMPUTERCAT_GAME');
  });

  it('offline säger att spelet sparas lokalt', () => {
    expect(describeError(new NetworkError('GET', '/x', new TypeError('Failed to fetch')))).toContain('Offline');
  });

  it('övriga fel visar status och serverns meddelande', () => {
    expect(describeError(api(500, 'Server Error'))).toBe('Computercat svarade 500: Server Error');
  });
});
