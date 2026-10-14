import type { Metadata, Viewport } from 'next';
import { BackgroundSync } from '@/components/BackgroundSync';
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

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="sv" className="h-full">
      <body className="h-full antialiased">
        {children}
        <BackgroundSync />
      </body>
    </html>
  );
}
