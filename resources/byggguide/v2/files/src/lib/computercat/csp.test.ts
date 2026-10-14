import { describe, expect, it } from 'vitest';
import { contentSecurityPolicy } from './csp';

describe('contentSecurityPolicy', () => {
  it('är byggguidens policy med Computercats ursprung som standard', () => {
    expect(contentSecurityPolicy(undefined)).toBe(
      "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self' https://api.computercat.co; object-src 'none'; base-uri 'self'; form-action 'self'",
    );
  });

  it('tar ursprunget, inte sökvägen, ur NEXT_PUBLIC_COMPUTERCAT_URL', () => {
    expect(contentSecurityPolicy('http://127.0.0.1:8123/api/v1/')).toContain("connect-src 'self' http://127.0.0.1:8123;");
    expect(contentSecurityPolicy('   ')).toContain("connect-src 'self' https://api.computercat.co;");
  });

  it('kastar på en ogiltig adress, så att bygget faller i stället för att släppa igenom fel policy', () => {
    expect(() => contentSecurityPolicy('inte en adress')).toThrow();
  });
});
