import { DEFAULT_BASE_URL } from './config';

// Byggguide v2: Content-Security-Policy för den statiska exporten. Sätts som <meta> i
// src/app/layout.tsx och kontrolleras i varje out/**/*.html av scripts/cc-gate.mjs
// (lokalt i npm run check och i Coolify). test/security.test.ts visar att skriptet godkänner
// exakt den här policyn; en ändring här kräver en ny byggguideversion.
// 'unsafe-inline' för skript krävs av Next.js-exportens inline-skript (self.__next_f.push).
export function contentSecurityPolicy(apiUrl: string | undefined): string {
  // Ogiltig adress: new URL kastar och bygget faller (fail loud).
  const origin = new URL(apiUrl?.trim() || DEFAULT_BASE_URL).origin;
  return [
    "default-src 'self'",
    "script-src 'self' 'unsafe-inline'",
    "style-src 'self' 'unsafe-inline'",
    "img-src 'self' data:",
    "font-src 'self'",
    `connect-src 'self' ${origin}`,
    "object-src 'none'",
    "base-uri 'self'",
    "form-action 'self'",
  ].join('; ');
}
