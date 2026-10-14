import type { NextConfig } from 'next';

// Statisk export: Coolify bygger med nixpacks och serverar out/ med nginx.
// Ingen server, inga API-routes, ingen bildoptimering.
const nextConfig: NextConfig = {
  output: 'export',
  images: { unoptimized: true },
};

export default nextConfig;
