---
id: security-sparfiler
title: Sparningar i molnet (saves)
topic: security
tags: [sparning, sparfil, saves, molnsparning, version, merge, personuppgifter]
summary: Vad en molnsparning är (JSON, högst 512 KB, version), hur konflikter slås ihop, att ägande och hemligheter inte hör hemma där och att personuppgifter kräver en människas beslut.
updated: "2026-10-14"
build_guide: [v1, v2, v3]
---

## Regel

Spara bara spelets eget tillstånd i molnsparningar och använd alltid `saves.sync(nyckel, lokalt, merge)` med en merge som aldrig tappar spelarens framsteg. Lägg aldrig nycklar, tokens eller köpt innehåll i en sparning. Ska en sparning innehålla personuppgifter: fråga en människa först.

## Varför

En sparning är en JSON-blob per spelare, spel och nyckel (`main` som standard). Klienten äger formatet; servern lagrar och returnerar det.

- Storlek: en begäran över 512 000 byte avvisas med 413 och `Save data too large. Maximum 512 KB.`
- `data` måste vara ett objekt som inte är tomt; `saves.put` kastar `RangeError` för ett tomt objekt.
- Version: första sparningen skickas med `version` 0. Varje lyckad sparning ökar versionen med 1. Skickar klienten en annan version än serverns svarar servern 409 med `Version conflict. Fetch latest save and merge.`, `server_version` och serverns kopia.
- `checksum` är valfri (högst 64 tecken). Servern lagrar den men kontrollerar den inte, och `saves.ts` skickar ingen.
- Servern tar bort nyckeln `ownership` ur varje sparning. Ägande (köpt innehåll) avgörs av servern.

Sparningen skickas av klienten, och nycklar hör aldrig hemma i klienten; se `security-hemligheter-i-klienten`.

Skillnad mellan versionerna: bara v2:s `CLAUDE.md` säger uttryckligen att något som rör personuppgifter kräver en människas beslut. Sparningarna fungerar likadant i v1 och v2.

## Så gör du

1. Använd `saves.sync(nyckel, lokalt, merge)`. Den hämtar sparningen, kör `merge(lokalt, moln)`, skickar med serverns version och vid 409 slår ihop med serverns kopia och försöker en gång till. Krockar det igen kastar den `Sparningen <nyckel> krockade två gånger i rad; försök igen senare`; visa felet.
2. Skriv `merge` enligt integrationsmanualen: bästa resultat per nivå, union av achievements, molnets inställningar, högsta streak.
3. `saves.get` ger `null` bara när servern svarar `Save not found.`; andra fel kastas och ska visas.
4. Vill du spara något utöver spelets tillstånd (till exempel namn eller e-post): fråga en människa innan du bygger.

## Källa

- app/Http/Controllers/Api/V1/GameSaveController.php
- app/Services/IntegrationGuide.php
- src/lib/computercat/saves.ts
- docs/game-integration-guide.md
- resources/byggguide/v1/files/CLAUDE.md
- resources/byggguide/v2/files/CLAUDE.md
