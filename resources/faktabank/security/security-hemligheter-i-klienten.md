---
id: security-hemligheter-i-klienten
title: Inga hemligheter i klienten
topic: security
tags: [hemlighet, nyckel, nycklar, api-nyckel, env, token, secret]
summary: Allt i spelet är publikt. Bara spelets slug och Computercats adress får ligga i NEXT_PUBLIC-variabler; nycklar hör aldrig hemma i spelrepot.
updated: "2026-10-14"
---

## Regel

Spelets kod och allt i `out/` kan läsas av vem som helst. Därför:

- Bara `NEXT_PUBLIC_COMPUTERCAT_GAME` och `NEXT_PUBLIC_COMPUTERCAT_URL` får användas som publika variabler.
- API-nycklar, tokens, lösenord och privata nycklar får aldrig finnas i repot, i `.env`-filer som committas eller i koden.
- Spelarens token från Computercat sparas bara av klienten i `src/lib/computercat/`.

## Varför

En nyckel i en statisk sida är publicerad i samma ögonblick som Demo byggs, och kan inte tas tillbaka. Computercat behöver ingen hemlighet i spelet: anonym inloggning ger varje spelare en egen token.

## Så gör du

- Behöver spelet en tjänst som kräver nyckel: det hör hemma i Computercat. Beskriv behovet och fråga en människa.
- Har en nyckel ändå hamnat i en commit: säg det direkt till en människa. Nyckeln måste bytas, att ta bort den ur koden räcker inte.

## Källa

- resources/byggguide/v1/files/dot.env.example
- src/lib/computercat/auth.ts
- src/lib/computercat/config.ts
