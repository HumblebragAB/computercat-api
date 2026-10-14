// Kontrollerar att den statiska exporten finns och, om NEXT_PUBLIC_COMPUTERCAT_GAME
// är satt (i miljön eller i en .env-fil som Next läser vid bygget), att slugen
// faktiskt bakats in i klientkoden (annars skickar spelet inget).
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';

const fail = (msg) => {
  console.error(`check-export: ${msg}`);
  process.exit(1);
};

const walk = (dir) =>
  readdirSync(dir).flatMap((name) => {
    const p = join(dir, name);
    return statSync(p).isDirectory() ? walk(p) : [p];
  });

const NAME = 'NEXT_PUBLIC_COMPUTERCAT_GAME';

// Samma ordning som Next vid `next build`: miljön först, sedan filerna uppifrån och ned.
// Utan det här kontrollerar skriptet ingenting för den som bara har slugen i .env.local.
function slugFromEnvFiles() {
  for (const file of ['.env.production.local', '.env.local', '.env.production', '.env']) {
    if (!existsSync(file)) continue;
    for (const line of readFileSync(file, 'utf8').split(/\r?\n/)) {
      const m = line.match(new RegExp(`^\\s*(?:export\\s+)?${NAME}\\s*=\\s*(.*?)\\s*$`));
      const value = m?.[1].replace(/^(['"])(.*)\1$/, '$2').trim();
      if (value) return { value, source: file };
    }
  }
  return null;
}

if (!existsSync('out/index.html')) {
  fail('out/index.html saknas. Är output: "export" satt i next.config.ts?');
}

const fromEnv = process.env[NAME]?.trim();
const found = fromEnv ? { value: fromEnv, source: 'miljön' } : slugFromEnvFiles();
const game = found?.value;
if (game) {
  const baked = walk('out/_next')
    .filter((f) => f.endsWith('.js'))
    .some((f) => readFileSync(f, 'utf8').includes(JSON.stringify(game)));
  if (!baked) {
    fail(`slugen "${game}" (från ${found.source}) finns inte i någon JS-fil under out/_next; variabeln har inte bakats in.`);
  }
}

console.log(`check-export: OK (out/index.html${game ? `, slug "${game}" inbakad` : ', ingen slug satt'})`);
