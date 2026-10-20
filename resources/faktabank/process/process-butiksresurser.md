---
id: process-butiksresurser
title: Appikon, startbild och skärmdumpar (icon, splash, screenshots)
topic: process
tags: [ikon, appikon, splash, startbild, skärmdumpar, screenshots, favicon, butiksbilder]
summary: "Varje spel byter Capacitors appikon och startbild mot egna bilder, har egna webbikoner och lägger butikens skärmdumpar i store/screenshots/. Bildfilerna i ios/ får bytas."
updated: "2026-10-20"
build_guide: [v3]
---

## Regel

1. Innan spelet skickas till TestFlight första gången, och senast före App Store, byter du **appikonen** och **startbilden** i `ios/` mot spelets egna bilder. Capacitors standardbilder (Capacitor-logon) får aldrig gå till App Store.
2. Spelet har egna **webbikoner**: `src/app/icon.png` och `src/app/apple-icon.png`.
3. **Skärmdumparna** till App Store ligger i spelrepot under `store/screenshots/<enhet>/NN-namn.png`.
4. Regeln att `ios/` inte ändras för hand gäller projektfilerna. Du får byta **bildfilerna** i `AppIcon.appiconset` och `Splash.imageset`, med samma filnamn och samma mått. Rör aldrig `Contents.json`, `Info.plist`, `project.pbxproj`, `LaunchScreen.storyboard` eller `capacitor.config.ts`.
5. Varje bild är en PNG **utan alfakanal** (helt ogenomskinlig). Hitta inte på grafik som spelet inte har: saknas underlag för en ikon, fråga en människa.

## Varför

`ios-setup.mjs` skapar iOS-projektet ur Capacitors mall, och mallen har Capacitors ikon och en startbild med Capacitor-logon. Inget i bygget stoppar dem, så utan det här steget går appen till TestFlight och App Store Connect med Capacitors ikon. App Store Connect visar ikonen ur det bearbetade bygget, så en ny ikon syns först med nästa version.

App Store avvisar en appikon med alfakanal, och skärmdumpar får inte heller ha alfakanal eller genomskinlighet.

`check-ios.mjs` och släpprepots kontroller (`release-ios.yml`) läser bara bundle-id, `Info.plist`, Capacitors version, Swift Package Manager och att det inte finns symboliska länkar, nycklar, certifikat eller profiler i `ios/`. En utbytt PNG med samma namn påverkar ingen av dem. Ändrar du `Contents.json` eller lägger till filer med andra namn kan Xcode i stället ignorera bilden eller fälla bygget, så håll dig till filbytet.

`promote_release(target: app_store, stage: testflight)` svarar med `store_assets`: en varning för varje bild som fortfarande är Capacitors standardbild. Varningen stoppar inte släppet. Byt bilden och skicka den i nästa version.

## Så gör du

### Appikon

- Fil: `ios/App/App/Assets.xcassets/AppIcon.appiconset/AppIcon-512@2x.png`. `Contents.json` har en enda post, 1024×1024 universal, så den här filen är hela ikonen.
- 1024×1024 pixlar, RGB utan alfa, fyllt ända ut i hörnen. Rita inga rundade hörn; iOS lägger på masken själv.

### Startbild

- Filer: `ios/App/App/Assets.xcassets/Splash.imageset/splash-2732x2732.png`, `splash-2732x2732-1.png` och `splash-2732x2732-2.png`. Alla tre är 2732×2732 och får vara samma bild.
- `LaunchScreen.storyboard` visar bilden med aspect fill: den fyller skärmen och beskärs. På en iPhone i stående läge syns bara en mittremsa, ungefär 1250 pixlar bred. Lägg logga eller titel inom en centrerad yta på cirka 1200×1200 och fyll resten med spelets bakgrundsfärg.

### Webbikoner

- `src/app/icon.png` (till exempel 512×512) och `src/app/apple-icon.png` (180×180). Next.js app router gör dem till `<link rel="icon">` och `<link rel="apple-touch-icon">` på samma ursprung, så de klarar CSP-kontrollen i `cc-gate.mjs` (`security-csp`). Länka aldrig en ikon från en annan domän.

### Skärmdumpar

App Store Connect tar PNG eller JPEG, 1–10 per enhetsstorlek, utan alfa. Gör de här, en mapp per enhet:

| Mapp | Enhet | Stående | Liggande |
|---|---|---|---|
| `store/screenshots/iphone69/` | iPhone 6,9" (Dynamic Island, stor skärm) | 1320×2868 | 2868×1320 |
| `store/screenshots/iphone63/` | iPhone 6,3" (Dynamic Island, mellanstor skärm) | 1206×2622 | 2622×1206 |
| `store/screenshots/ipad13/` | iPad 13" | 2064×2752 | 2752×2064 |

- Apple kräver minst en skärmdump för iPhone med Dynamic Island, mellanstor skärm, och en för iPad 13" om appen kan köras på iPad. Capacitors projekt är byggt för både iPhone och iPad, så iPad 13" behövs.
- Har du redan 6,5"-bilder (1242×2688 eller 2688×1242) i `store/screenshots/iphone65/` får de ligga kvar; Apple godtar dem för iPhone med Face ID, stor skärm.
- Döp filerna `01-start.png`, `02-…` i den ordning de ska visas. Samma nummer och namn i varje mapp.
- Visa riktiga spellägen. Inga påhittade topplistor eller siffror som spelet inte kan visa.
- I en webbläsare: 440×956 punkter med enhetsskala 3 ger 1320×2868, 402×874 med skala 3 ger 1206×2622, och 1032×1376 med skala 2 ger 2064×2752. Lägg inte till ett npm-paket för att ta bilderna (`stack-nytt-beroende`).
- `store/` ligger utanför `public/` och `ios/` och kommer inte med i webbygget eller appen. Erik laddar upp skärmdumparna i App Store Connect.

### Kontrollera bilderna

Mått och färgtyp utan extra verktyg (färgtyp 2 är RGB utan alfa; 6 är RGBA och fel):

```
node -e "for (const f of process.argv.slice(1)) { const b = require('fs').readFileSync(f); console.log(f, b.readUInt32BE(16) + 'x' + b.readUInt32BE(20), 'färgtyp', b[25], b.includes('tRNS') ? 'tRNS (genomskinlighet)' : '') }" <filer>
```

Kör sedan `npm run check`; sista raden ska fortfarande vara `check-ios: OK`. Committa bilderna med ett meddelande som säger vad som byttes, till exempel `Appikon, startbild och webbikoner`.

## Källa

- app/Releases/TestFlight/StoreAssetCheck.php
- app/Mcp/Tools/PromoteReleaseTool.php
- scripts/ios-setup.mjs
- scripts/check-ios.mjs
- scripts/cc-gate.mjs
- src/app/layout.tsx
- https://developer.apple.com/help/app-store-connect/reference/screenshot-specifications
- https://developer.apple.com/design/human-interface-guidelines/app-icons
- https://nextjs.org/docs/app/api-reference/file-conventions/metadata/app-icons
