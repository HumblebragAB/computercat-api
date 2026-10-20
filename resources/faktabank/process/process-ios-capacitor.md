---
id: process-ios-capacitor
title: iOS-projektet skapas med ios-setup och Capacitor
topic: process
tags: [ios, capacitor, ios-setup, check-ios, xcode, spm]
summary: Spelets iOS-projekt skapas en gång med node scripts/ios-setup.mjs och bundle-id:t från get_release_status. Ändra inte ios/ för hand utom bilderna; versionen står bara i package.json.
updated: "2026-10-20"
build_guide: [v3]
---

## Regel

Skapa iOS-projektet en gång med `node scripts/ios-setup.mjs <bundle_id> "<name>"`, där `bundle_id` är `targets.app_store.ios.bundle_id` från `get_release_status` och `name` är spelets namn från `get_game`. Hitta aldrig på ett bundle-id, ändra aldrig `ios/` eller `capacitor.config.ts` för hand, och sätt aldrig version eller byggnummer någon annanstans än i `package.json`.

## Varför

Computercat bygger appen i släpprepot och kontrollerar att `appId` och `PRODUCT_BUNDLE_IDENTIFIER` är spelets bundle-id och att `Info.plist` har exportdeklarationen. Version och byggnummer sätts av bygget (`MARKETING_VERSION` från `package.json`, `CURRENT_PROJECT_VERSION` från Computercat). Ett fel här märks annars först efter flera minuter på en macOS-maskin.

## Så gör du

1. `get_release_status`: saknas `app_store` i `release_targets` eller är `targets.app_store.ios.bundle_id` `null`, be Erik sätta målet med `set_release_targets`.
2. `npm ci --ignore-scripts` och `npm run build`.
3. `node scripts/ios-setup.mjs <bundle_id> "<name>"`. Skriptet skriver `capacitor.config.ts`, kör `cap add ios --packagemanager SPM` och lägger in `ITSAppUsesNonExemptEncryption` = `false`. Det vägrar om `ios/` redan finns.
4. `npm run check` ska sluta med `check-ios: OK`. Faller `check-ios`: läs felraden; den säger vilken fil och vilket värde.
5. Committa `capacitor.config.ts` och `ios/` och pusha. Coolify bygger Demo som vanligt; `ios/` påverkar inte webbygget.
6. Inga certifikat, nycklar, profiler, `ExportOptions.plist` eller byggflöden i repot.
7. Byt Capacitors appikon och startbild mot spelets egna enligt `process-butiksresurser` (bildfilerna får bytas, projektfilerna inte). Skicka sedan till TestFlight enligt `process-slappflode`.

## Källa

- resources/byggguide/v3/files/scripts/ios-setup.mjs
- resources/byggguide/v3/files/scripts/check-ios.mjs
- resources/byggguide/v3/files/CLAUDE.md
- resources/byggguide/v3/manifest.json
- app/Releases/ReleaseStatus.php
- app/Services/BuildGuide.php
- docs/superpowers/specs/bilagor/2026-10-15-github-actions-ios.md
