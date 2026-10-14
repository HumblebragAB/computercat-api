import type { Metadata, Viewport } from 'next';
import { BackgroundSync } from '@/components/BackgroundSync';
import { contentSecurityPolicy } from '@/lib/computercat/csp';
import './globals.css';

export const metadata: Metadata = {
  title: 'Spelmall',
  description: 'Computercat-spel byggt enligt Computercats byggguide.',
};

export const viewport: Viewport = {
  width: 'device-width',
  initialScale: 1,
  viewportFit: 'cover',
  themeColor: '#f5f0e8',
};

// Byggguide v2: policyn kontrolleras i varje out/**/*.html av scripts/cc-gate.mjs.
// Bara i produktionsbygget: next build sätter alltid NODE_ENV=production, så out/ och
// kontrollen påverkas inte, medan npm run dev slipper policyn (Eriks beslut 2026-10-14).
const CSP = contentSecurityPolicy(process.env.NEXT_PUBLIC_COMPUTERCAT_URL);
const IS_PRODUCTION_BUILD = process.env.NODE_ENV === 'production';

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="sv" className="h-full">
      <head>{IS_PRODUCTION_BUILD && <meta httpEquiv="Content-Security-Policy" content={CSP} />}</head>
      <body className="h-full antialiased">
        {children}
        <BackgroundSync />
      </body>
    </html>
  );
}
