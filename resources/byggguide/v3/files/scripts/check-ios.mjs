// Byggguide v3: kontroll av spelets iOS-projekt (Capacitor, Swift Package Manager). Körs sist i
// npm run check. Utan ios/ finns inget att kontrollera. Kraven är desamma som släpprepots
// release-ios.yml ställer (bundle-id, exportdeklaration, version och byggnummer från bygget),
// så att felet syns lokalt i stället för efter flera minuter på en macOS-maskin.
import { existsSync, lstatSync, readdirSync, readFileSync } from 'node:fs';
import { basename, join } from 'node:path';
import { pathToFileURL } from 'node:url';

// Samma mönster som Computercat (BundleId) och släpprepots "Validera indata".
export const BUNDLE_ID = /^se\.computercat\.[a-z][a-z0-9]{1,39}$/;
const VERSION = /^(0|[1-9][0-9]{0,2})\.(0|[1-9][0-9]{0,2})\.(0|[1-9][0-9]{0,2})$/;
const SECRET_FILE = /\.(p8|p12|cer|pem|key|mobileprovision)$/i;
// Guidens Capacitor; samma som manifestets ios.capacitor (BuildGuideV3Test jämför).
export const CAPACITOR = '8.5.3';
const CAPACITOR_PACKAGES = [['@capacitor/core', 'dependencies'], ['@capacitor/ios', 'dependencies'], ['@capacitor/cli', 'devDependencies']];
// Bara Capacitors kopia av out/ (ignorerad av ios/.gitignore) hoppas över, och bara på exakt den platsen.
const SKIP = join('ios', 'App', 'App', 'public');

/** capacitor.config.ts för spelet. appId och appName kommer från Computercat (get_release_status, get_game). */
export function capacitorConfigSource(appId, appName) {
  if (typeof appId !== 'string' || !BUNDLE_ID.test(appId)) {
    throw new Error(`ogiltigt bundle-id ${JSON.stringify(appId)}; använd targets.app_store.ios.bundle_id från get_release_status (se.computercat.<slug utan bindestreck>)`);
  }
  const name = typeof appName === 'string' ? appName.trim() : '';
  const control = [...name].some((c) => c.charCodeAt(0) < 32 || c.charCodeAt(0) === 127);
  if (name === '' || name.length > 50 || control || /[<>&]/.test(name)) {
    throw new Error(`ogiltigt appnamn ${JSON.stringify(appName)}: 1–50 tecken, utan radbrytningar och utan < > &; fråga en människa om spelets namn inte går`);
  }
  return [
    "import type { CapacitorConfig } from '@capacitor/cli';",
    '',
    '// Skrivet av scripts/ios-setup.mjs (byggguide v3). appId är spelets bundle-id i Computercat',
    '// (get_release_status: targets.app_store.ios.bundle_id). Ändra det aldrig för hand.',
    'const config: CapacitorConfig = {',
    `  appId: ${JSON.stringify(appId)},`,
    `  appName: ${JSON.stringify(name)},`,
    "  webDir: 'out',",
    '};',
    '',
    'export default config;',
    '',
  ].join('\n');
}

/** Exportdeklarationen (bilagan B8): utan den frågar App Store Connect för varje bygge. */
export function addExportCompliance(plist) {
  if (/<key>ITSAppUsesNonExemptEncryption<\/key>/.test(plist)) return plist;
  const end = plist.lastIndexOf('</dict>');
  if (end === -1) throw new Error('Info.plist saknar </dict>');
  return `${plist.slice(0, end)}\t<key>ITSAppUsesNonExemptEncryption</key>\n\t<false/>\n${plist.slice(end)}`;
}

/** CFBundleDisplayName ur Info.plist, med XML-entiteter avkodade; null om nyckeln saknas. */
export function readDisplayName(plist) {
  const m = /<key>CFBundleDisplayName<\/key>\s*<string>([^<]*)<\/string>/.exec(plist);
  if (m === null) return null;
  const entities = { quot: '"', apos: "'", lt: '<', gt: '>' };
  // &amp; sist och i samma pass: ett enda tolkningssteg, så att &amp;quot; blir &quot; och inte ".
  return m[1].replace(/&(quot|apos|lt|gt|amp);/g, (_, e) => (e === 'amp' ? '&' : entities[e]));
}

function readAppId(source) {
  const found = [...source.matchAll(/^\s*appId:\s*("(?:[^"\\]|\\.)*"),\s*$/gm)];
  if (found.length !== 1) return null;
  try {
    return JSON.parse(found[0][1]);
  } catch {
    return null;
  }
}

/** Alla poster under dir. lstat, så att en symbolisk länk syns som länk och aldrig följs. */
function walk(root, dir) {
  return readdirSync(join(root, dir)).flatMap((name) => {
    const rel = join(dir, name);
    if (rel === SKIP) return [];
    const st = lstatSync(join(root, rel));
    if (st.isSymbolicLink()) return [{ rel, link: true }];
    if (st.isDirectory()) return walk(root, rel);
    return [{ rel, link: false }];
  });
}

/** Fel i spelets iOS-projekt. present: false när ios/ saknas (spelet är inte förberett för iOS). */
export function checkIos(root) {
  if (!existsSync(join(root, 'ios'))) return { present: false, errors: [] };
  const errors = [];
  const read = (p) => (existsSync(join(root, p)) ? readFileSync(join(root, p), 'utf8') : null);

  let pkg = {};
  try {
    pkg = JSON.parse(read('package.json') ?? '{}');
  } catch {
    errors.push('package.json går inte att läsa');
  }
  if (!VERSION.test(String(pkg.version ?? ''))) {
    errors.push(`package.json: version ${JSON.stringify(pkg.version)} ska vara MAJOR.MINOR.PATCH (0–999 per del); versionen sätts bara där`);
  }

  const config = read('capacitor.config.ts');
  const appId = config === null ? null : readAppId(config);
  if (config === null) {
    errors.push('capacitor.config.ts saknas; iOS-projektet skapas med node scripts/ios-setup.mjs');
  } else if (appId === null || !BUNDLE_ID.test(appId)) {
    errors.push('capacitor.config.ts: appId ska stå en gång och vara spelets bundle-id (se.computercat.<slug utan bindestreck>)');
  } else if (!/^\s*webDir:\s*'out',\s*$/m.test(config)) {
    errors.push("capacitor.config.ts: webDir ska vara 'out'");
  }

  const pbx = read('ios/App/App.xcodeproj/project.pbxproj');
  if (pbx === null) {
    errors.push('ios/App/App.xcodeproj/project.pbxproj saknas; iOS-projektet skapas med node scripts/ios-setup.mjs');
  } else if (appId !== null) {
    const ids = [...new Set([...pbx.matchAll(/^\s*PRODUCT_BUNDLE_IDENTIFIER = "?([^";]+)"?;/gm)].map((m) => m[1]))];
    if (ids.length !== 1 || ids[0] !== appId) {
      errors.push(`project.pbxproj: PRODUCT_BUNDLE_IDENTIFIER är ${ids.join(', ') || 'saknas'}, ska vara ${appId} som i capacitor.config.ts`);
    }
  }

  const plist = read('ios/App/App/Info.plist');
  if (plist === null) {
    errors.push('ios/App/App/Info.plist saknas');
  } else {
    if (!/<key>ITSAppUsesNonExemptEncryption<\/key>\s*<false\s*\/>/.test(plist)) {
      errors.push('Info.plist: ITSAppUsesNonExemptEncryption ska vara false');
    }
    if (!/<key>CFBundleShortVersionString<\/key>\s*<string>\$\(MARKETING_VERSION\)<\/string>/.test(plist)) {
      errors.push('Info.plist: CFBundleShortVersionString ska vara $(MARKETING_VERSION); versionen sätts bara i package.json');
    }
    if (!/<key>CFBundleVersion<\/key>\s*<string>\$\(CURRENT_PROJECT_VERSION\)<\/string>/.test(plist)) {
      errors.push('Info.plist: CFBundleVersion ska vara $(CURRENT_PROJECT_VERSION); byggnumret sätts av Computercat');
    }
  }

  for (const [name, section] of CAPACITOR_PACKAGES) {
    const got = pkg.dependencies?.[name] ?? pkg.devDependencies?.[name];
    if (got === undefined) {
      errors.push(`package.json: ${name} saknas; byggguiden kräver ${name} ${CAPACITOR} i ${section}`);
    } else if (got !== CAPACITOR) {
      errors.push(`package.json: ${name} är ${got}, byggguiden kräver exakt ${CAPACITOR}; ändra inte Capacitors version i spelet`);
    }
  }
  const spm = read('ios/App/CapApp-SPM/Package.swift');
  if (spm === null) {
    errors.push('ios/App/CapApp-SPM/Package.swift saknas: iOS-projektet ska använda Swift Package Manager');
  } else if (!spm.includes(`capacitor-swift-pm.git", exact: "${CAPACITOR}"`)) {
    errors.push(`Package.swift: capacitor-swift-pm ska vara exakt ${CAPACITOR}; kör npx cap sync ios`);
  }
  if (existsSync(join(root, 'ios', 'App', 'Podfile'))) {
    errors.push('ios/App/Podfile finns: byggguiden använder Swift Package Manager, inte CocoaPods');
  }

  for (const { rel, link } of walk(root, 'ios')) {
    if (link) {
      errors.push(`${rel}: symbolisk länk; ios/ får inte innehålla länkar (en länk kan peka ut ur repot i bygget)`);
    } else if (SECRET_FILE.test(rel) || basename(rel) === 'ExportOptions.plist') {
      errors.push(`${rel}: certifikat, nycklar, profiler och exportinställningar hör inte hemma i repot; Computercat signerar`);
    }
  }

  return { present: true, errors };
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) {
  const { present, errors } = checkIos(process.cwd());
  if (!present) {
    console.log('check-ios: ingen ios/ (spelet är inte förberett för iOS); inget att kontrollera.');
  } else if (errors.length > 0) {
    for (const e of errors) console.error(`check-ios: ${e}`);
    process.exit(1);
  } else {
    console.log('check-ios: OK');
  }
}
