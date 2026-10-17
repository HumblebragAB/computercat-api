// Byggguide v3: skapar spelets iOS-projekt en gång (Capacitor 8.5.3, Swift Package Manager).
//   node scripts/ios-setup.mjs <bundle-id> "<spelets namn>"
// bundle-id: get_release_status, targets.app_store.ios.bundle_id. Namnet: get_game, name.
// Skriver capacitor.config.ts, kör cap add ios ur node_modules (utan skal) och lägger in
// exportdeklarationen. Kontrollerar resultatet med check-ios.mjs.
import { spawnSync } from 'node:child_process';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';
import { addExportCompliance, capacitorConfigSource, checkIos, readDisplayName } from './check-ios.mjs';

function capAddIos(root) {
  const cap = join(root, 'node_modules', '.bin', 'cap');
  if (!existsSync(cap)) throw new Error('node_modules/.bin/cap saknas; kör npm ci --ignore-scripts först');
  const r = spawnSync(cap, ['add', 'ios', '--packagemanager', 'SPM'], { cwd: root, stdio: 'inherit' });
  if (r.status !== 0) throw new Error(`cap add ios misslyckades (exitkod ${r.status}${r.signal ? `, signal ${r.signal}` : ''}${r.error ? `, ${r.error.message}` : ''})`);
}

const RERUN = 'ta bort ios/ och capacitor.config.ts om de inte är committade, och kör ios-setup igen (inget raderas automatiskt)';

export function setupIos({ root, appId, appName, addIos = capAddIos }) {
  // Indata först: ett ogiltigt id eller namn ska aldrig lämna något efter sig.
  const config = capacitorConfigSource(appId, appName);
  if (existsSync(join(root, 'ios'))) {
    throw new Error(`ios/ finns redan. ios-setup körs bara en gång; kontrollera projektet med node scripts/check-ios.mjs. Ta aldrig bort ett committat ios/; fråga en människa. Efter ett avbrutet försök: ${RERUN}`);
  }
  const configPath = join(root, 'capacitor.config.ts');
  if (existsSync(configPath) && readFileSync(configPath, 'utf8') !== config) {
    throw new Error('capacitor.config.ts finns redan med annat innehåll; ändra den inte utan att fråga en människa');
  }
  if (!existsSync(join(root, 'out', 'index.html'))) {
    throw new Error('out/index.html saknas; kör npm run build först (Capacitor kopierar den statiska exporten)');
  }

  writeFileSync(configPath, config);
  // Från och med nu kan ett avbrott lämna capacitor.config.ts och en del av ios/ kvar.
  // Felet behåller originalet (cause) och säger hur man kör om; inget raderas automatiskt.
  try {
    finishIos({ root, appName, addIos });
  } catch (cause) {
    const original = cause instanceof Error ? cause.message : String(cause);
    const name = /CFBundleDisplayName/.test(original) ? ' Därefter: fråga en människa om namnet om det inte går att skriva rätt.' : '';
    throw new Error(`${original}\nios-setup avbröts efter att filer skrivits: ${RERUN}.${name}`, { cause });
  }
}

function finishIos({ root, appName, addIos }) {
  addIos(root);

  const plistPath = join(root, 'ios', 'App', 'App', 'Info.plist');
  if (!existsSync(plistPath)) throw new Error('ios/App/App/Info.plist saknas efter cap add ios');
  const plist = readFileSync(plistPath, 'utf8');
  // Capacitor sätter namnet med String.replace, så $1 eller $$ i namnet blir tyst ett annat namn.
  const shown = readDisplayName(plist);
  if (shown !== appName.trim()) {
    throw new Error(`CFBundleDisplayName i Info.plist är ${JSON.stringify(shown)}, ska vara ${JSON.stringify(appName.trim())}; cap add ios skrev fel visningsnamn`);
  }
  writeFileSync(plistPath, addExportCompliance(plist));

  const { errors } = checkIos(root);
  if (errors.length > 0) throw new Error(`iOS-projektet klarar inte kontrollen:\n- ${errors.join('\n- ')}`);
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) {
  const [appId = '', appName = ''] = process.argv.slice(2);
  try {
    setupIos({ root: process.cwd(), appId, appName });
    console.log(`ios-setup: OK (${appId}). Kör npm run check och committa capacitor.config.ts och ios/.`);
  } catch (e) {
    console.error(`ios-setup: ${e instanceof Error ? e.message : String(e)}`);
    process.exit(1);
  }
}
