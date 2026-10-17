import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, symlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { addExportCompliance, BUNDLE_ID, CAPACITOR, capacitorConfigSource, checkIos } from '../scripts/check-ios.mjs';
import { setupIos } from '../scripts/ios-setup.mjs';

// Byggguide v3: scripts/check-ios.mjs och scripts/ios-setup.mjs. Fixturerna har samma form som det
// Capacitor 8.5.3 skapar med cap add ios --packagemanager SPM (bilagan 2026-10-15, Task 0).
const ID = 'se.computercat.kattsvep';
const COMPLIANT = '\t<key>ITSAppUsesNonExemptEncryption</key>\n\t<false/>\n';
const PLIST = (extra = '') => `<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
\t<key>CFBundleDisplayName</key>
\t<string>Kattsvep</string>
\t<key>CFBundleShortVersionString</key>
\t<string>$(MARKETING_VERSION)</string>
\t<key>CFBundleVersion</key>
\t<string>$(CURRENT_PROJECT_VERSION)</string>
${extra}</dict>
</plist>
`;
const SPM = (v: string) =>
  `let package = Package(\n    dependencies: [\n        .package(url: "https://github.com/ionic-team/capacitor-swift-pm.git", exact: "${v}")\n    ]\n)\n`;
// Samma form som Capacitor 8.5.3:s mall (Task 5, fixrunda 1): Debug och Release har var sin rad.
const PBX = (id: string) =>
  `\t\t\t\tINFOPLIST_FILE = App/Info.plist;\n\t\t\t\tPRODUCT_BUNDLE_IDENTIFIER = ${id};\n\t\t\t\tINFOPLIST_FILE = App/Info.plist;\n\t\t\t\tPRODUCT_BUNDLE_IDENTIFIER = ${id};\n`;
const PKG = (over: Record<string, unknown> = {}) =>
  JSON.stringify({
    version: '0.1.0',
    dependencies: { '@capacitor/core': '8.5.3', '@capacitor/ios': '8.5.3' },
    devDependencies: { '@capacitor/cli': '8.5.3' },
    ...over,
  });
const PACKAGE = PKG();

const dirs: string[] = [];
afterEach(() => {
  // Bara katalogerna som testet skapade.
  for (const dir of dirs.splice(0)) rmSync(dir, { recursive: true, force: true });
});

function write(root: string, files: Record<string, string | null>): string {
  for (const [path, content] of Object.entries(files)) {
    if (content === null) continue;
    mkdirSync(dirname(join(root, path)), { recursive: true });
    writeFileSync(join(root, path), content);
  }
  return root;
}

function tempRoot(): string {
  const root = mkdtempSync(join(tmpdir(), 'check-ios-'));
  dirs.push(root);
  return root;
}

/** Ett projekt som ios-setup har skapat; `files` ersätter eller (med null) tar bort filer. */
function project(files: Record<string, string | null> = {}): string {
  return write(tempRoot(), {
    'package.json': PACKAGE,
    'capacitor.config.ts': capacitorConfigSource(ID, 'Kattsvep'),
    'ios/App/App.xcodeproj/project.pbxproj': PBX(ID),
    'ios/App/App/Info.plist': PLIST(COMPLIANT),
    'ios/App/CapApp-SPM/Package.swift': SPM('8.5.3'),
    ...files,
  });
}

/** Ett nytt spelrepo efter npm run build. */
function freshRepo(withOut = true): string {
  return write(tempRoot(), { 'package.json': PACKAGE, 'out/index.html': withOut ? '<html></html>' : null });
}

/** Låtsas-cap: skriver det som cap add ios skriver och som kontrollen läser, med appId ur capacitor.config.ts. */
function fakeCapAdd(root: string): void {
  const id = /appId: "([^"]+)"/.exec(readFileSync(join(root, 'capacitor.config.ts'), 'utf8'))![1];
  write(root, {
    'ios/App/App.xcodeproj/project.pbxproj': PBX(id),
    'ios/App/App/Info.plist': PLIST(),
    'ios/App/CapApp-SPM/Package.swift': SPM('8.5.3'),
  });
}

describe('capacitorConfigSource', () => {
  it('skriver spelets bundle-id och namn och inget annat id', () => {
    const src = capacitorConfigSource('se.computercat.tinkerisle', 'Tinker Isle');
    expect(src).toContain('appId: "se.computercat.tinkerisle",');
    expect(src).toContain('appName: "Tinker Isle",');
    expect(src).toContain("webDir: 'out',");
    expect(src.match(/se\.computercat\.[a-z0-9]+/g)).toEqual(['se.computercat.tinkerisle']);
  });

  it.each(['', 'se.computercat.', 'se.computercat.k', 'se.computercat.tinker-isle', 'se.computercat.Tinker', 'co.humblebrag.tinkerisle', 'se.computercat.tinkerisle\n'])(
    'vägrar bundle-id %j',
    (id) => {
      expect(() => capacitorConfigSource(id, 'Tinker Isle')).toThrow('bundle-id');
    },
  );

  it.each(['', '   ', 'x'.repeat(51), 'Rad\nbrytning', 'Katt & Hund', 'A<b>'])('vägrar appnamn %j', (name) => {
    expect(() => capacitorConfigSource(ID, name)).toThrow('appnamn');
  });

  it('har samma mönster som Computercat och släpprepot', () => {
    expect(BUNDLE_ID.source).toBe('^se\\.computercat\\.[a-z][a-z0-9]{1,39}$');
  });
});

describe('addExportCompliance', () => {
  it('lägger in nyckeln en gång, före sista </dict>', () => {
    const once = addExportCompliance(PLIST());
    expect(once).toBe(PLIST(COMPLIANT));
    expect(addExportCompliance(once)).toBe(once);
  });

  it('kastar utan </dict>', () => {
    expect(() => addExportCompliance('<plist/>')).toThrow('</dict>');
  });
});

describe('checkIos', () => {
  it('utan ios/ finns inget att kontrollera', () => {
    expect(checkIos(freshRepo())).toEqual({ present: false, errors: [] });
  });

  it('godkänner ett projekt som ios-setup skapat', () => {
    expect(checkIos(project())).toEqual({ present: true, errors: [] });
  });

  it('har guidens Capacitor-version', () => {
    expect(CAPACITOR).toBe('8.5.3');
  });

  it('fäller på varje symbolisk länk i ios/, också en som pekar inåt', () => {
    const root = project();
    symlinkSync('/etc/hosts', join(root, 'ios/App/hosts'));
    symlinkSync('Info.plist', join(root, 'ios/App/App/kopia.plist'));
    const errors = checkIos(root).errors.join('\n');
    expect(errors).toContain('ios/App/hosts: symbolisk länk');
    expect(errors).toContain('ios/App/App/kopia.plist: symbolisk länk');
  });

  it('fäller när ios/ själv är en symbolisk länk', () => {
    const real = project();
    const root = write(tempRoot(), { 'package.json': PACKAGE, 'capacitor.config.ts': capacitorConfigSource(ID, 'Kattsvep') });
    symlinkSync(join(real, 'ios'), join(root, 'ios'));
    const { present, errors } = checkIos(root);
    expect(present).toBe(true);
    expect(errors.join('\n')).toContain('ios: symbolisk länk');
  });

  it('fäller när ios är en fil', () => {
    const root = write(tempRoot(), { 'package.json': PACKAGE, ios: 'x' });
    expect(checkIos(root).errors.join('\n')).toContain('ios: ska vara en katalog');
  });

  it('fäller på en symbolisk länk som heter ios/App/App/public', () => {
    const root = project();
    symlinkSync('/etc', join(root, 'ios/App/App/public'));
    expect(checkIos(root).errors.join('\n')).toContain('ios/App/App/public: symbolisk länk');
  });

  it('hoppar bara över exakt ios/App/App/public', () => {
    const root = project({ 'ios/App/App/public/x.p12': 'kopia av out/', 'ios/App/public/dist.p12': 'x' });
    const errors = checkIos(root).errors.join('\n');
    expect(errors).not.toContain('ios/App/App/public');
    expect(errors).toContain('ios/App/public/dist.p12');
  });

  it.each([
    ['annat bundle-id i Xcode-projektet', { 'ios/App/App.xcodeproj/project.pbxproj': PBX('se.computercat.annat') }, 'PRODUCT_BUNDLE_IDENTIFIER'],
    ['två bundle-id i Xcode-projektet', { 'ios/App/App.xcodeproj/project.pbxproj': PBX(ID) + PBX('se.computercat.annat') }, 'PRODUCT_BUNDLE_IDENTIFIER'],
    ['appId i fel form', { 'capacitor.config.ts': capacitorConfigSource(ID, 'Kattsvep').replace(ID, 'co.humblebrag.kattsvep') }, 'appId'],
    ['två appId', { 'capacitor.config.ts': `${capacitorConfigSource(ID, 'Kattsvep')}  appId: "${ID}",\n` }, 'appId'],
    ['saknad capacitor.config.ts', { 'capacitor.config.ts': null }, 'capacitor.config.ts saknas'],
    ['webDir som inte är out', { 'capacitor.config.ts': capacitorConfigSource(ID, 'Kattsvep').replace("webDir: 'out'", "webDir: 'public'") }, 'webDir'],
    ['saknad exportdeklaration', { 'ios/App/App/Info.plist': PLIST() }, 'ITSAppUsesNonExemptEncryption'],
    ['hårdkodat byggnummer', { 'ios/App/App/Info.plist': PLIST(COMPLIANT).replace('$(CURRENT_PROJECT_VERSION)', '7') }, 'CFBundleVersion'],
    ['hårdkodad version', { 'ios/App/App/Info.plist': PLIST(COMPLIANT).replace('$(MARKETING_VERSION)', '1.0') }, 'CFBundleShortVersionString'],
    ['annan Capacitor i Package.swift', { 'ios/App/CapApp-SPM/Package.swift': SPM('8.5.2') }, 'capacitor-swift-pm'],
    ['CocoaPods i stället för SPM', { 'ios/App/CapApp-SPM/Package.swift': null, 'ios/App/Podfile': "platform :ios, '15.0'\n" }, 'Swift Package Manager'],
    ['version utan PATCH', { 'package.json': PKG({ version: '1.0' }) }, 'MAJOR.MINOR.PATCH'],
    ['saknad @capacitor/cli', { 'package.json': PKG({ devDependencies: {} }) }, '@capacitor/cli saknas'],
    ['annan @capacitor/ios i package.json', { 'package.json': PKG({ dependencies: { '@capacitor/core': '8.5.3', '@capacitor/ios': '8.5.2' } }) }, '@capacitor/ios är 8.5.2'],
    ['intervall för @capacitor/core', { 'package.json': PKG({ dependencies: { '@capacitor/core': '^8.5.3', '@capacitor/ios': '8.5.3' } }) }, '@capacitor/core är ^8.5.3'],
    ['certifikat i repot', { 'ios/App/dist.p12': 'x' }, 'dist.p12'],
    ['profil i repot', { 'ios/App/App/app.mobileprovision': 'x' }, 'app.mobileprovision'],
    ['ExportOptions.plist i repot', { 'ios/ExportOptions.plist': '<plist/>' }, 'ExportOptions.plist'],
    ['exportoptions.plist med små bokstäver', { 'ios/exportoptions.plist': '<plist/>' }, 'exportoptions.plist'],
    ['.crt i repot', { 'ios/a.crt': 'x' }, 'a.crt'],
    ['.der i repot', { 'ios/a.der': 'x' }, 'a.der'],
    ['.provisionprofile i repot', { 'ios/a.provisionprofile': 'x' }, 'a.provisionprofile'],
    ['.keychain i repot', { 'ios/a.keychain': 'x' }, 'a.keychain'],
    ['.p7b i repot', { 'ios/a.p7b': 'x' }, 'a.p7b'],
    ['.P12 med versaler', { 'ios/a.P12': 'x' }, 'a.P12'],
    ['kommenterad Package.swift-rad och en annan version', { 'ios/App/CapApp-SPM/Package.swift': `// capacitor-swift-pm.git", exact: "8.5.3"\n${SPM('9.0.0')}` }, 'capacitor-swift-pm'],
    ['två capacitor-swift-pm i Package.swift', { 'ios/App/CapApp-SPM/Package.swift': SPM('8.5.3') + SPM('9.0.0') }, 'capacitor-swift-pm'],
    ['kommenterad exportdeklaration', { 'ios/App/App/Info.plist': PLIST(`<!--\n${COMPLIANT}-->\n`) }, 'ITSAppUsesNonExemptEncryption'],
    ['dubbel exportdeklaration', { 'ios/App/App/Info.plist': PLIST(COMPLIANT + COMPLIANT) }, 'ITSAppUsesNonExemptEncryption'],
    ['dubbel CFBundleVersion', { 'ios/App/App/Info.plist': PLIST(COMPLIANT + '\t<key>CFBundleVersion</key>\n\t<string>7</string>\n') }, 'CFBundleVersion'],
    ['dubbel CFBundleShortVersionString', { 'ios/App/App/Info.plist': PLIST(COMPLIANT + '\t<key>CFBundleShortVersionString</key>\n\t<string>1.0</string>\n') }, 'CFBundleShortVersionString'],
    ['extra bundle-id utan mellanslag', { 'ios/App/App.xcodeproj/project.pbxproj': `${PBX(ID)}\t\tPRODUCT_BUNDLE_IDENTIFIER=se.evil.x;\n` }, 'PRODUCT_BUNDLE_IDENTIFIER'],
    ['bundle-id för en sdk-variant', { 'ios/App/App.xcodeproj/project.pbxproj': `${PBX(ID)}\t\t"PRODUCT_BUNDLE_IDENTIFIER[sdk=iphoneos*]" = se.evil.y;\n` }, 'PRODUCT_BUNDLE_IDENTIFIER'],
    ['bundle-id med citattecken och fel värde', { 'ios/App/App.xcodeproj/project.pbxproj': `${PBX(ID)}\t\tPRODUCT_BUNDLE_IDENTIFIER = "se.evil.z";\n` }, 'PRODUCT_BUNDLE_IDENTIFIER'],
    ['annan INFOPLIST_FILE', { 'ios/App/App.xcodeproj/project.pbxproj': `${PBX(ID)}\t\tINFOPLIST_FILE = App/Other.plist;\n` }, 'INFOPLIST_FILE'],
    ['saknad INFOPLIST_FILE', { 'ios/App/App.xcodeproj/project.pbxproj': `\t\tPRODUCT_BUNDLE_IDENTIFIER = ${ID};\n` }, 'INFOPLIST_FILE'],
    ['appId bara i en kommentar', { 'capacitor.config.ts': `/*\n  appId: "${ID}",\n*/\nconst config = { appId: process.env.X };\n  webDir: 'out',\n` }, 'appId'],
  ])('fäller: %s', (_name, files, needle) => {
    const { present, errors } = checkIos(project(files));
    expect(present).toBe(true);
    expect(errors.join('\n')).toContain(needle);
  });
});

describe('setupIos', () => {
  it('skriver konfigurationen före cap add, lägger in exportdeklarationen och klarar kontrollen', () => {
    const root = freshRepo();
    setupIos({ root, appId: ID, appName: 'Kattsvep', addIos: fakeCapAdd });
    expect(readFileSync(join(root, 'capacitor.config.ts'), 'utf8')).toBe(capacitorConfigSource(ID, 'Kattsvep'));
    expect(readFileSync(join(root, 'ios/App/App/Info.plist'), 'utf8')).toBe(PLIST(COMPLIANT));
    expect(checkIos(root)).toEqual({ present: true, errors: [] });
  });

  it('vägrar ett ogiltigt bundle-id innan något skrivs', () => {
    const root = freshRepo();
    const addIos = vi.fn();
    expect(() => setupIos({ root, appId: 'se.computercat.kattsvep-x', appName: 'Kattsvep', addIos })).toThrow('bundle-id');
    expect(existsSync(join(root, 'capacitor.config.ts'))).toBe(false);
    expect(addIos).not.toHaveBeenCalled();
  });

  it('vägrar utan out/index.html och skriver inget', () => {
    const root = freshRepo(false);
    expect(() => setupIos({ root, appId: ID, appName: 'Kattsvep', addIos: fakeCapAdd })).toThrow('npm run build');
    expect(existsSync(join(root, 'capacitor.config.ts'))).toBe(false);
  });

  it('körs bara en gång', () => {
    const root = freshRepo();
    mkdirSync(join(root, 'ios'));
    expect(() => setupIos({ root, appId: ID, appName: 'Kattsvep', addIos: fakeCapAdd })).toThrow('ios/ finns redan');
  });

  it('skriver inte över en capacitor.config.ts med annat innehåll', () => {
    const root = write(freshRepo(), { 'capacitor.config.ts': capacitorConfigSource('se.computercat.annat', 'Annat') });
    expect(() => setupIos({ root, appId: ID, appName: 'Kattsvep', addIos: fakeCapAdd })).toThrow('capacitor.config.ts finns redan');
  });

  it('fäller när cap add inte gav ett godkänt projekt', () => {
    const root = freshRepo();
    const wrongId = (r: string) => {
      fakeCapAdd(r);
      write(r, { 'ios/App/App.xcodeproj/project.pbxproj': PBX('se.computercat.annat') });
    };
    expect(() => setupIos({ root, appId: ID, appName: 'Kattsvep', addIos: wrongId })).toThrow('PRODUCT_BUNDLE_IDENTIFIER');
  });

  it('fäller när cap add skrev ett annat visningsnamn än det validerade', () => {
    const root = freshRepo();
    const wrongName = (r: string) => {
      fakeCapAdd(r);
      write(r, { 'ios/App/App/Info.plist': PLIST().replace('<string>Kattsvep</string>', '<string>Katt $1</string>') });
    };
    expect(() => setupIos({ root, appId: ID, appName: 'Kattsvep', addIos: wrongName })).toThrow('CFBundleDisplayName');
  });

  it('klarar ett namn med $$, $1 och citattecken (och $& vägras av appnamn-regeln) när visningsnamnet skrivs rätt', () => {
    const root = freshRepo();
    const name = 'Katt $$ $1 "Hund"';
    const rightName = (r: string) => {
      fakeCapAdd(r);
      write(r, { 'ios/App/App/Info.plist': PLIST().replace('<string>Kattsvep</string>', () => '<string>Katt $$ $1 &quot;Hund&quot;</string>') });
    };
    expect(() => capacitorConfigSource(ID, 'Katt $& Hund')).toThrow('appnamn');
    setupIos({ root, appId: ID, appName: `  ${name}  `, addIos: rightName });
    expect(readFileSync(join(root, 'capacitor.config.ts'), 'utf8')).toContain(`appName: ${JSON.stringify(name)},`);
    expect(checkIos(root)).toEqual({ present: true, errors: [] });
  });

  it('ett misslyckat cap add lämnar besked om hur man kör om, med orsaken kvar', () => {
    const root = freshRepo();
    const boom = new Error('cap add ios misslyckades (exitkod 1)');
    const failing = () => {
      throw boom;
    };
    let thrown: unknown;
    try {
      setupIos({ root, appId: ID, appName: 'Kattsvep', addIos: failing });
    } catch (e) {
      thrown = e;
    }
    expect((thrown as Error).message).toContain('cap add ios misslyckades');
    expect((thrown as Error).message).toContain('ta bort ios/ och capacitor.config.ts');
    expect((thrown as Error).cause).toBe(boom);
    expect(existsSync(join(root, 'capacitor.config.ts'))).toBe(true); // raderas aldrig automatiskt
  });

  it('fel visningsnamn säger också åt en att fråga en människa om namnet', () => {
    const root = freshRepo();
    const wrongName = (r: string) => {
      fakeCapAdd(r);
      write(r, { 'ios/App/App/Info.plist': PLIST().replace('<string>Kattsvep</string>', '<string>Katt $1</string>') });
    };
    expect(() => setupIos({ root, appId: ID, appName: 'Kattsvep', addIos: wrongName })).toThrow('fråga en människa om namnet');
  });

  it('"ios/ finns redan" säger hur man kör om', () => {
    const root = freshRepo();
    mkdirSync(join(root, 'ios'));
    expect(() => setupIos({ root, appId: ID, appName: 'Kattsvep', addIos: fakeCapAdd })).toThrow('ta bort ios/ och capacitor.config.ts');
  });
});
