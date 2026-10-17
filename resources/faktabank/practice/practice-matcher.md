---
id: practice-matcher
title: Turbaserade tvåspelarmatcher går via client.matches
topic: practice
tags: [matcher, tvåspelarmatch, turbaserad, matches, watch, stale, inbjudan, inbjudningskod]
summary: Gå via client.matches, rita från watch, styr på terminal, visa text vid onError, hantera stale. Bjud in med inviteUrl/readJoinToken, eller med inskriven kod när inbjudningstypen är code.
updated: "2026-10-17"
build_guide: [v3]
---

## Regel

Spela turbaserade tvåspelarmatcher bara via `client.matches` i `src/lib/computercat/`. Anropa aldrig matchvägarna direkt med `fetch`. Läs regelverkets drag i `get_integration_guide`, rita alltid från `watch` och visa ett synligt läge vid varje fel.

## Varför

Servern äger reglerna och det dolda läget, och klienten sköter ETag, `version`, paus, backoff och fel. Ett direktanrop tappar det, och ett drag som krockar eller ett fel som inte visas gör att spelaren väntar för evigt utan att veta varför.

## Så gör du

1. Hämta regelverken och deras drag (actions, payload, state och händelser) ur avsnittet "Turn-based Matches" i `get_integration_guide`. Saknas avsnittet för spelet: fråga en människa. Ett annat regelverk ger `ruleset_not_allowed`.
2. Skapa: `matches.create(ruleset, options?, namn?)` ger `{ match, joinToken }`. Visa `inviteUrl(joinToken, origin?)` som QR-kod och länk. I iOS-appen måste `origin` vara spelets publika webbadress (`https://…`); `capacitor://localhost` kastar.
3. Gå med: läs `readJoinToken()` vid start (den tar bort token ur adressen) och anropa `matches.join(token, namn?)` om den gav en token.
   Är regelverkets inbjudningstyp `code` (står i "Turn-based Matches" i `get_integration_guide`, till exempel `reveal`) är `joinToken` en kod på 4 tecken. Skicka då aldrig koden till `inviteUrl()`; `readJoinToken()` tar bara emot token på 43 tecken och ger `null` för en kod. Visa koden stort hos den som skapade matchen, låt motspelaren skriva in den i ett textfält och anropa `matches.join(inskrivet, namn?)`. Servern bryr sig inte om versaler eller blanksteg.
4. Rita från `watch(id, { onChange, onError })`. `onChange` får varje ny version, också den som `act` redan gav. Spara handtaget och anropa `stop()` när spelaren lämnar matchen.
5. `onError` ger `{ kind, error, terminal }`. Visa text vid varje `onError`: en banner om `terminal` är falskt, tillbaka till menyn om det är sant. Styr på `terminal`, inte på `kind`. `gone` är alltid terminal.
6. Drag: `matches.act(snapshot, typ, payload?)` på den senaste snapshot spelaren såg. `MatchError` med `code` `stale`: rita `e.snapshot` (saknas den kommer nästa `onChange`). Drag som båda spelarna gör i samma fas (till exempel låsa ett tal eller trycka "redo"): skicka samma drag igen en gång med `e.snapshot` om det fortfarande är tillåtet för spelaren där. Andra drag erbjuds igen bara om de fortfarande är tillåtna.
7. Visa aldrig en felkod som den är. Styr på `e.code` och visa spelets egen text; `describeError` duger för nät- och serverfel men inte för `MatchError`.
8. Efter omladdning: `matches.active()` ger spelarens öppna matcher. Väntar en match du skapade (`status` `waiting`, `yourSeat` 0): `matches.invite(id)` ger en ny `joinToken` för en ny QR-kod (eller en ny kod att visa). Bevaka sedan med `watch`.

## Källa

- src/lib/computercat/matches.ts
- src/lib/computercat/matches.test.ts
- resources/byggguide/v3/files/CLAUDE.md
- app/Http/Controllers/Api/V1/MatchController.php
- app/Services/IntegrationGuide.php
