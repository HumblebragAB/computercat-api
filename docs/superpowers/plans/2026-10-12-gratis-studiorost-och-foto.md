# Gratis studioröst och veckans gratisfoto – implementationsplan

> **Ändrat 2026-10-13:** Gratisfotot är borttaget. Att fota läxan kräver Guldstjärnan, och gratisnivån (App Attest) gäller bara studiorösten. Skanningen nekar `attest` med 402 `guld_required` innan något arbete görs, `FreeScanWeek`, skanningens gratistak (`free.scan_daily_limit`, `free.scan_ip_daily_limit`) och felkoderna `free_scan_used`/`free_busy` finns inte längre, och `glosis:check-free-usage` larmar på varje gratisskanning. Allt nedan om gratisfotot (Task 7, delar av Task 6, 8, 11 och 12) är historik.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Studiorösten blir gratis för alla, och gratisanvändare får fota en läxa per vecka. Gratisnivån skyddas med Apple App Attest, och Guld-vägen lämnas orörd.

**Architecture:** computercat-api får en App Attest-verifierare (CBOR + X.509 med PHP:s inbyggda openssl, inga nya beroenden), en tabell för attesterade nycklar, tillståndslösa engångs-challenges (HMAC, bara inlösen skrivs i cachen) och en `FreeGate` som i controllern sitter bredvid `GuldGate`. Båda grindarna ger en `GlosisCaller` (guld/free/admin) som gränserna, budgeten och `ai_usage` räknar på. Gratisnivån har egna gränser per nyckel, per IP och globalt, och en egen delbudget inom månadsbudgeten. Allt styrs av en flagga i Filament som är avslagen från början. Appen får ett eget Capacitor-plugin (`DCAppAttestService`). Utan köpbevis, eller när servern nekar beviset, signerar den förfrågningarna med en App Attest-assertion i stället, en i taget. På iOS slutar UI:t låsa studiorösten och första fotot i veckan, och Android är oförändrat. Gratisflaggan når appen via remote config. glosis.se får nya pris- och policytexter via en migrering.

**Tech Stack:** Laravel 12, Filament 3 och PHPUnit 11 (computercat-api, PHP 8.4, ext-openssl). Next.js static export, Capacitor 8 och Swift (DeviceCheck, CryptoKit), vitest 4 och Playwright (glosis-appen).

**Spec:** Eriks beslut 2026-10-12, sammanfattade i uppdraget och i `docs/beslut.md` (läggs till i Task 13). Apples dokumentation, hämtad 2026-10-12, är källan för formaten:
- Server: <https://developer.apple.com/documentation/devicecheck/validating-apps-that-connect-to-your-server> (JSON: `developer.apple.com/tutorials/data/documentation/devicecheck/validating-apps-that-connect-to-your-server.json`)
- App: <https://developer.apple.com/documentation/devicecheck/establishing-your-app-s-integrity>
- Testvektor: <https://developer.apple.com/documentation/devicecheck/attestation-object-validation-guide>
- Sandlåda och utrullning: <https://developer.apple.com/documentation/devicecheck/preparing-to-use-the-app-attest-service>
- Entitlement: <https://developer.apple.com/documentation/bundleresources/entitlements/com.apple.developer.devicecheck.appattest-environment>
- Rot-CA: <https://www.apple.com/certificateauthority/Apple_App_Attestation_Root_CA.pem>, SHA-256 (DER) `1cb9823ba28ba6ad2d33a006941de2ae4f513ef1d4e831b9f7e0fa7b6242c932`. Hämtad över TLS från apple.com 2026-10-12. Den finns inte i Apples publika rotlista, eftersom det är en privat CA.

### Verifierat mot Apples testvektor (2026-10-12, lokalt med PHP 8.4 och openssl)

Det här är kontrollerat och ingen gissning:
- Attesteringsobjektet är CBOR `{fmt: "apple-appattest", attStmt: {x5c: [credCert, intermediate], receipt}, authData}`. Kedjan credCert → intermediate → Apple App Attestation Root CA verifierar med `openssl_x509_verify`. Intermediate är P-384, credCert P-256 och giltigt i 3 dygn.
- authData är rpIdHash(32), flags(1), counter(4, big-endian), aaguid(16), credIdLen(2), credentialId(32), COSE-nyckel `{1:2, 3:-7, -1:1, -2:x, -3:y}` och sist en extensions-map `{apple_bundle_version_01: "1", apple_validation_category_01: h'01000000'}`. **ED-biten (0x80) är inte satt fast extensions finns.** Parsern läser därför extensions om det finns bytes kvar, oberoende av flaggan.
- `openssl_x509_parse()` ger råa DER-bytes för OID `1.2.840.113635.100.8.2`: `30 24 A1 22 04 20 <nonce 32 B>`.
- `SHA256(0x04‖x‖y)` = keyId = credentialId (= certifikatets CN i hex).
- **Apples exempel är inte konsekvent på två punkter** (bekräftat i <https://developer.apple.com/forums/thread/842509>, inget svar från Apple). (1) Exemplets nonce stämmer bara med den *råa* challengen efter authData, inte med SHA256(challenge). (2) Den publicerade "public key hash" (`inGjK2…`) är fel, keyId (`zgSY9…`) är rätt. Vi följer artikeltexten (clientDataHash = SHA256(challenge)). I testet med Apples vektor matas den råa challengen in som clientDataHash, och skälet står i en kommentar.
- `apple_validation_category_01` är 4 byte little-endian i exemplet (artikeln säger UInt32), och `apple_bundle_version_01` är `"1"` (artikeln säger "1.0"). Apple dokumenterar inte talvärdena för App Store och TestFlight (`ValidationCategory.Value` saknar råvärden). **Beslut:** värdena tolkas, sparas och loggas men avgör inte i v1. Task 14 samlar in verkliga värden.
- **Assertionens signatur:** artikeln säger "verify that the assertion's signature is valid for nonce". Två oberoende bibliotek som används i drift gör samma sak. Båda räknar `nonce = SHA256(authenticatorData ‖ SHA256(clientData))` och verifierar sedan ECDSA med SHA-256 *över nonce som meddelande*, alltså en dubbel hashning:
  - node-app-attest (`src/verifyAssertion.js`): `createVerify("SHA256").update(nonce).verify(publicKey, signature)`
  - devicecheck-appattest (Kotlin, `AssertionValidator.kt`): `Signature.getInstance("SHA256withECDSA")`, `update(nonce)`

  Båda källorna är kontrollerade 2026-10-12. Standardvarianten är därför `openssl_verify($nonce, $signature, $key, OPENSSL_ALGO_SHA256)`. Enkel hashning (`openssl_verify($authData.$clientDataHash, …)`) är bara en dokumenterad reserv om enhetsprovet visar något annat. Apple har ingen assertion-testvektor, så raden märks `// ASSUMED` tills det riktiga enhetsprovet i Task 14 bekräftat varianten. Produktionsgrinden kräver att märkningen är borta, och gratisflaggan slås inte på förrän provet gått igenom. (Forumtråden <https://developer.apple.com/forums/thread/662477> saknar svar från Apple och avgör inte frågan.)
- Sandlådans aaguid heter `appattestdevelop` i ena artikeln och `appattestsandbox` i den andra. Båda räknas som `development`, och development-nycklar godtas bara när `APP_ENV` inte är `production`. Appen får entitlement `production`, och TestFlight och App Store använder alltid production.

Apples exempelvektor och rotcertifikatet ligger förberedda bredvid planen:
`/private/tmp/claude-501/-Users-erik/df8fbe8c-a613-4585-a510-85d05838d9b5/scratchpad/glosis-appattest-apple-sample.json` och `…/scratchpad/Apple_App_Attestation_Root_CA.pem`. Kopiera in dem i Task 2. Hämta dem på nytt om scratchpad är tömd (källorna står ovan).

## Global Constraints

- **Guld-vägen är oförändrad:** samma köpbevis, samma gränser (15 skanningar per vecka, sandbox 5, 100 nya ord per köp och dygn, globalt `tts.daily_new_limit`), samma felkoder och samma räknarnycklar (`glosis-scan:tx:…`, `glosis-tts:tx:…`). De befintliga testsviterna `GlosisTtsTest`, `GlosisScanTest`, `voice.studio.test.ts` och `scan.computercat.test.ts` ska gå igenom utan att deras förväntningar ändras.
- Gratisgränser (beslut, inte inställningar): **1 skanning per App Attest-nyckel och ISO-vecka (Europe/Stockholm)**, **100 nya (ocachade) TTS-ord per nyckel och Stockholmsdygn**. Cachade ord räknas aldrig.
- Gratisnivån har egna inställningar i Filament: på/av (`free.enabled`, **standard av**), globalt dagstak för skanningar (standard 40), skanningar per IP och dygn (10), globalt dagstak för nya ord (800), nya ord per IP och dygn (300), delbudget per månad (20 USD, ryms i den gemensamma månadsbudgeten som fortsatt gäller), Apple Team ID (`app_attest.team_id`, 10 tecken A–Z0–9).
- Varje `ai_usage`-rad har `tier` (`guld`|`free`|`admin`). Varje rad med `tier = free` har `app_attest_key_id`.
- Inga nya Composer- eller npm-beroenden. CBOR-avkodning skrivs själv (ungefär 120 rader, strikt delmängd), och X.509 hanteras med `ext-openssl` som `AppleTransactionVerifier` redan gör.
- Android: ingen gratisnivå. Butiksbygget på Android skickar aldrig `attest`, och Android-flödet är oförändrat (501, enhetens röst). Guld på Android har studiorösten som förut, och utan Guld öppnar studiorösten och fotot säljsidan med dagens texter. Google Play-texten ändras inte och får aldrig lova gratis studioröst eller gratisfoto. Play Integrity tas upp senare (beslut-raden i Task 13).
- Gratisflaggan slås inte på i produktion förrän enhetsprovet (Task 14, Step 2) är grönt och `ASSUMED` är borta.
- glosis.se (`~/Web/glosis`) ändras med en migrering som respekterar texter redigerade i admin. Push till `main` är produktion och sker först när iOS-versionen är ute (Task 13b, Task 14).
- Användartexter är på svenska och barnvänliga, utan tankstreck (—, –) i copy. Skriv "mail", aldrig "mejl". Inga interna detaljer i `message`.
- Fail loud: varje nekad gratisförfrågan loggas på servern med orsak (key-hash, aldrig keyId i klartext), och appen visar ett synligt degraderat läge (raden i inställningarna eller felrutan i fotovyn).
- Loggar och `ai_usage` innehåller aldrig keyId, assertion, kvitto, clientData, bild eller IP i klartext. Bara `substr(sha256(…), 0, 16)`. Apples kvitto sparas inte alls (dataminimering).
- Databascachen (`CACHE_STORE=database` i produktion) får inga rader per utfärdad challenge: challenges är tillståndslösa (HMAC), och utgångna `glosis-`-rader gallras varje timme.
- Arbetssätt: `~/.claude/bin/wt-start ~/Web/computercat-api feat/gratisniva --db` (migrationer), `~/.claude/bin/wt-start ~/App/glosis feat/gratisniva` och `~/.claude/bin/wt-start ~/Web/glosis feat/gratisniva-texter`. Inga ändringar i huvudkatalogerna. Commits utan Co-Authored-By (Eriks regel). Inget mot produktion utan Eriks uttryckliga OK.

## Review Focus

1. **Samma assertion skickas två gånger** (replay): bara den ena får gå igenom. Räknaren flyttas atomiskt (`UPDATE … WHERE sign_count < ?`), och challengen löses in med `Cache::add`. Test i Task 5. **Två olika förfrågningar samtidigt från samma app** (studiorösten för två hastigheter, eller studiorösten medan ett foto skickas) får inte göra att den ena nekas för att räknarna kommer i fel ordning. AttestService skickar en i taget och provar en gång till vid `invalid_attest`. Test i Task 10.
2. **Enhet utan App Attest** (simulator, gammal iPad, jailbrutet läge där `isSupported` är false): gratisanvändaren får enhetens röst med raden "Studiorösten är inte tillgänglig just nu", och fotot visar ett tydligt fel. Inga eviga omförsök. Test i Task 10 och Task 11.
3. **Guldstjärnan har gått ut eller återbetalats** (`proof()` ger null, eller servern svarar 402 på beviset): appen blir tyst en gratisanvändare resten av sessionen och fastnar aldrig i en 402-loop. Test i Task 11.
4. **Ominstallation eller okänd nyckel** (servern svarar 401 `unknown_key`): appen glömmer nyckeln och registrerar en ny vid nästa förfrågan. Inget låst läge. Test i Task 10 och Task 11.
5. **Flaggan stängs av mitt i en session** (503 `free_unavailable`): gratisanvändarens studioröst slutar fråga under sessionen och visar raden, fotot visar "pausat", och Guld påverkas inte. Test i Task 6, Task 7 och Task 11.

## Öppna frågor för Erik (avgörs före inskick, inte under bygget)

1. **App Privacy i App Store Connect.** App Attest-nyckelns id är ett id per installation som servern sparar och räknar på. Ska det redovisas under Identifiers → Device ID ("App Functionality", inte kopplat till person, ingen spårning)? Appen ligger i Kids-kategorin, där redovisningen granskas extra. Beslutet behövs före inskicket i Task 14, Step 5.4.
2. **Taken för gratis.** Standardvärdena (40 skanningar och 800 nya ord per dygn för alla, 10 skanningar och 300 ord per IP, 20 USD per månad) är rimliga antaganden. De går att ändra i Filament utan ny release, men Erik bör godkänna dem innan flaggan slås på.

Redan bestämt i planen och inte öppet: utrullning i steg (Phased Release i App Store) och backoff vid Apples `serverUnavailable` (Task 10, Task 14). Granskningsanteckningarna i `Fastfile` uppdateras i Task 13.

---

## Filkarta

computercat-api (`~/Web/computercat-api`, i worktreen):

| Fil | Ansvar |
|---|---|
| `app/Services/Glosis/AppAttest/Cbor.php` | Strikt CBOR-avkodare (RFC 7049, delmängd) |
| `app/Services/Glosis/AppAttest/AuthenticatorData.php` | Tolkar authData (attestering och assertion) |
| `app/Services/Glosis/AppAttest/AppAttestVerifier.php` | Attestering och assertion enligt Apple |
| `app/Services/Glosis/AppAttest/AttestedKey.php` | Resultatet av en godkänd attestering |
| `app/Services/Glosis/AppAttest/InvalidAttestationException.php` | Avvisning med intern orsak |
| `app/Services/Glosis/AppAttest/AttestChallenges.php` | Tillståndslösa engångs-challenges (HMAC, inlösen i cachen) |
| `app/Models/AppAttestKey.php` + migration | Attesterade nycklar |
| `app/Http/Controllers/Api/V1/GlosisAttestController.php` | `attest/challenge`, `attest/register` |
| `app/Services/Glosis/GlosisCaller.php` | Vem som anropar (guld/free/admin) och gränsnycklar |
| `app/Services/Glosis/FreeGate.php`, `FreeRequest.php` | Gratisnivåns grind (assertion → caller) |
| `GlosisSettings`, `AiBudget`, `BudgetReservation`, `AiUsage`, `StudioVoice`, båda controllers, `AppServiceProvider`, `bootstrap/app.php`, `routes/api.php`, `GameResource`, `AiUsageReport` + vy, `GameVoices`, `RemoteConfigController` | Ändras |
| `app/Console/Commands/CheckGlosisFreeUsage.php`, `PruneGlosisAttestKeys.php`, `PruneGlosisCache.php`, `routes/console.php` | Invarianter och gallring |
| `resources/certs/Apple_App_Attestation_Root_CA.pem` | Rot-CA |
| `tests/Support/CborEncoder.php`, `tests/Support/FakeAppAttest.php` | Testhjälp |

glosis (`~/App/glosis`, i worktreen):

| Fil | Ansvar |
|---|---|
| `apps/app/ios/App/App/GlosisAppAttestPlugin.swift`, `App.entitlements`, `project.pbxproj`, `MainViewController.swift` | Native-plugin och entitlement |
| `apps/app/src/native/appattest.ts` | JS-sidan av pluginet |
| `apps/app/src/services/attest.ts` | Nyckelns livscykel, registrering, signering |
| `apps/app/src/services/freeScan.ts` | Lokal ledtråd "veckans gratisfoto är använt" och ISO-vecka i Stockholm |
| `apps/app/src/services/freeSwitch.ts` | Gratisflaggan från remote config |
| `apps/app/src/test/services.ts` | Delad testfabrik för `Services` |
| `voice.studio.ts`, `scan.computercat.ts`, `scan.ts`, `services/index.ts` | Gratisvägen |
| `views/common/hooks.ts`, `Settings.tsx`, `EditorStart.tsx`, `Photo.tsx`, `Paywall.tsx`, `PlusBits.tsx` | UI, per plattform |
| fastlane-text (bara iOS sv), `Fastfile`, `docs/*` | Copy och dokumentation |

glosis.se (`~/Web/glosis`, i worktreen):

| Fil | Ansvar |
|---|---|
| `database/migrations/2026_10_12_000010_gratis_studiorost_och_veckans_foto.php` | Pris, uttalskortet, FAQ och integritetspolicyn i produktionsdatabasen |
| `database/seeders/HomePageSeeder.php` | Samma texter för nya installationer |

---

### Task 1: CBOR-avkodare (computercat-api)

**Files:**
- Create: `app/Services/Glosis/AppAttest/Cbor.php`
- Create: `app/Services/Glosis/AppAttest/InvalidAttestationException.php`
- Create: `tests/Support/CborEncoder.php`
- Test: `tests/Unit/Glosis/CborTest.php`

**Interfaces:**
- Produces: `Cbor::decode(string $bytes): mixed` (exakt ett värde, inga bytes efter det), `Cbor::decodeAt(string $bytes, int &$offset): mixed`. Kastar `InvalidAttestationException` vid allt utanför delmängden. Byte- och textsträngar blir båda PHP-`string`, maps `array` (nycklar int|string) och listor `list`.
- Produces (test): `Tests\Support\CborEncoder::encode(mixed $v): string`, `CborEncoder::text(string $s): CborText`. Vanliga strängar kodas som byte-strängar, `CborText` och map-nycklar av typen string som textsträngar.

- [ ] **Step 1: Skriv testhjälpen och de fallerande testerna**

```php
<?php
// tests/Support/CborEncoder.php
namespace Tests\Support;

/** Minimal CBOR-kodare för tester (RFC 7049). Strängar = byte-strängar, CborText och map-nycklar = text. */
final class CborEncoder
{
    public static function text(string $s): CborText
    {
        return new CborText($s);
    }

    public static function encode(mixed $v): string
    {
        return match (true) {
            $v instanceof CborText => self::head(3, strlen($v->value)).$v->value,
            is_int($v) && $v >= 0 => self::head(0, $v),
            is_int($v) => self::head(1, -1 - $v),
            is_string($v) => self::head(2, strlen($v)).$v,
            is_array($v) && array_is_list($v) => self::head(4, count($v)).implode('', array_map(self::encode(...), $v)),
            is_array($v) => self::head(5, count($v)).implode('', array_map(
                fn ($k, $x) => (is_string($k) ? self::encode(new CborText($k)) : self::encode($k)).self::encode($x),
                array_keys($v), $v)),
            default => throw new \InvalidArgumentException('Typen stöds inte'),
        };
    }

    /** Rått huvud, för att kunna bygga fientlig indata i tester. */
    public static function head(int $major, int $n): string
    {
        $m = $major << 5;

        return match (true) {
            $n < 24 => chr($m | $n),
            $n < 0x100 => chr($m | 24).chr($n),
            $n < 0x10000 => chr($m | 25).pack('n', $n),
            $n < 0x100000000 => chr($m | 26).pack('N', $n),
            default => chr($m | 27).pack('J', $n),
        };
    }
}

final class CborText
{
    public function __construct(public readonly string $value) {}
}
```

```php
<?php
// tests/Unit/Glosis/CborTest.php
namespace Tests\Unit\Glosis;

use App\Services\Glosis\AppAttest\Cbor;
use App\Services\Glosis\AppAttest\InvalidAttestationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\CborEncoder as E;

class CborTest extends TestCase
{
    public function test_avkodar_apples_exempelobjekt(): void
    {
        $fx = json_decode(file_get_contents(__DIR__.'/../../Fixtures/glosis-appattest-apple-sample.json'), true);
        $obj = Cbor::decode(base64_decode($fx['attestation'], true));

        $this->assertSame('apple-appattest', $obj['fmt']);
        $this->assertCount(2, $obj['attStmt']['x5c']);
        $this->assertSame(226, strlen($obj['authData']));
    }

    public function test_rundtur_for_det_som_stods(): void
    {
        $value = ['a' => 1, 'b' => -7, 'c' => "\x00\xff", 'd' => [1, 2, ['x' => E::text('y')]], 3 => -1];
        $decoded = Cbor::decode(E::encode($value));
        $this->assertSame(['a' => 1, 'b' => -7, 'c' => "\x00\xff", 'd' => [1, 2, ['x' => 'y']], 3 => -1], $decoded);
    }

    public function test_decode_at_flyttar_fram_offset(): void
    {
        $bytes = E::encode(['k' => 1]).E::encode(['l' => 2]);
        $o = 0;
        $this->assertSame(['k' => 1], Cbor::decodeAt($bytes, $o));
        $this->assertSame(['l' => 2], Cbor::decodeAt($bytes, $o));
        $this->assertSame(strlen($bytes), $o);
    }

    /** @return array<string, array{string}> */
    public static function hostile(): array
    {
        return [
            'tom' => [''],
            'bytes efter värdet' => [E::encode(1)."\x00"],
            'obestämd längd' => ["\x5f\x41a\xff"],
            'tagg' => ["\xc0\x01"],
            'flyttal' => ["\xfb".str_repeat("\x00", 8)],
            'true' => ["\xf5"],
            'längd längre än data' => [E::head(2, 1000).'abc'],
            '64-bitars längd över PHP_INT_MAX' => ["\x5b\xff\xff\xff\xff\xff\xff\xff\xff"],
            'för djup' => [str_repeat("\x81", 20)."\x01"],
            'för många element' => [E::head(4, 100_000)],
            'dubbel map-nyckel' => ["\xa2\x61k\x01\x61k\x02"],
            'map-nyckel som lista' => ["\xa1\x80\x01"],
            'ogiltig UTF-8 i text' => ["\x62\xc3\x28"],
        ];
    }

    #[DataProvider('hostile')]
    public function test_avvisar_fientlig_indata(string $bytes): void
    {
        $this->expectException(InvalidAttestationException::class);
        Cbor::decode($bytes);
    }
}
```

- [ ] **Step 2: Kör och se att det fallerar**

Run: `cp /private/tmp/claude-501/-Users-erik/df8fbe8c-a613-4585-a510-85d05838d9b5/scratchpad/glosis-appattest-apple-sample.json tests/Fixtures/ && php artisan test --filter=CborTest`
Expected: FAIL, `Class "App\Services\Glosis\AppAttest\Cbor" not found`

- [ ] **Step 3: Implementera**

```php
<?php
// app/Services/Glosis/AppAttest/InvalidAttestationException.php
namespace App\Services\Glosis\AppAttest;

use RuntimeException;

/** Avvisad attestering eller assertion. Meddelandet är internt (loggas), visas aldrig i appen. */
final class InvalidAttestationException extends RuntimeException {}
```

```php
<?php
// app/Services/Glosis/AppAttest/Cbor.php
namespace App\Services\Glosis\AppAttest;

/**
 * Strikt CBOR-avkodare (RFC 7049) för App Attest. Stöder exakt det Apples
 * objekt använder: heltal, byte- och textsträngar med bestämd längd, listor
 * och maps. Taggar, flyttal, true/false/null och obestämda längder avvisas,
 * liksom dubbla map-nycklar, för djup nästling och längder större än datan.
 * Indatan kommer från nätet: varje längd kontrolleras mot det som finns kvar.
 */
final class Cbor
{
    private const MAX_DEPTH = 8;

    private const MAX_ITEMS = 64;

    public static function decode(string $bytes): mixed
    {
        $offset = 0;
        $value = self::item($bytes, $offset, 0);
        if ($offset !== strlen($bytes)) {
            throw new InvalidAttestationException('CBOR: data efter värdet');
        }

        return $value;
    }

    public static function decodeAt(string $bytes, int &$offset): mixed
    {
        return self::item($bytes, $offset, 0);
    }

    private static function item(string $b, int &$o, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidAttestationException('CBOR: för djup nästling');
        }
        if ($o >= strlen($b)) {
            throw new InvalidAttestationException('CBOR: slut på data');
        }
        $initial = ord($b[$o++]);
        $major = $initial >> 5;
        if ($major > 5) {
            throw new InvalidAttestationException("CBOR: typ {$major} stöds inte");
        }
        $n = self::argument($b, $o, $initial & 0x1f);

        switch ($major) {
            case 0:
                return $n;
            case 1:
                return -1 - $n;
            case 2:
            case 3:
                if ($n > strlen($b) - $o) {
                    throw new InvalidAttestationException('CBOR: strängen är längre än datan');
                }
                $s = substr($b, $o, $n);
                $o += $n;
                if ($major === 3 && ! mb_check_encoding($s, 'UTF-8')) {
                    throw new InvalidAttestationException('CBOR: text är inte UTF-8');
                }

                return $s;
            case 4:
                self::guardCount($n);
                $list = [];
                for ($i = 0; $i < $n; $i++) {
                    $list[] = self::item($b, $o, $depth + 1);
                }

                return $list;
            default:
                self::guardCount($n);
                $map = [];
                for ($i = 0; $i < $n; $i++) {
                    $key = self::item($b, $o, $depth + 1);
                    if (! is_int($key) && ! is_string($key)) {
                        throw new InvalidAttestationException('CBOR: map-nyckel är inte tal eller sträng');
                    }
                    if (array_key_exists($key, $map)) {
                        throw new InvalidAttestationException('CBOR: dubbel map-nyckel');
                    }
                    $map[$key] = self::item($b, $o, $depth + 1);
                }

                return $map;
        }
    }

    private static function argument(string $b, int &$o, int $info): int
    {
        if ($info < 24) {
            return $info;
        }
        $size = match ($info) {
            24 => 1, 25 => 2, 26 => 4, 27 => 8,
            default => throw new InvalidAttestationException('CBOR: obestämd eller reserverad längd'),
        };
        if ($size > strlen($b) - $o) {
            throw new InvalidAttestationException('CBOR: slut på data i längden');
        }
        $raw = substr($b, $o, $size);
        $o += $size;
        $n = match ($size) {
            1 => ord($raw),
            2 => unpack('n', $raw)[1],
            4 => unpack('N', $raw)[1],
            8 => unpack('J', $raw)[1],
        };
        if ($n < 0) {
            // unpack('J') ger negativt tal över PHP_INT_MAX.
            throw new InvalidAttestationException('CBOR: talet är för stort');
        }

        return $n;
    }

    private static function guardCount(int $n): void
    {
        if ($n > self::MAX_ITEMS) {
            throw new InvalidAttestationException('CBOR: för många element');
        }
    }
}
```

- [ ] **Step 4: Kör testerna**

Run: `php artisan test --filter=CborTest`
Expected: PASS (16 tester)

- [ ] **Step 5: Commit**

```bash
git add app/Services/Glosis/AppAttest tests/Support/CborEncoder.php tests/Unit/Glosis/CborTest.php tests/Fixtures/glosis-appattest-apple-sample.json
git commit -m "Glosis: strikt CBOR-avkodare för App Attest"
```

---

### Task 2: Verifiera attesteringen (computercat-api)

**Files:**
- Create: `app/Services/Glosis/AppAttest/AuthenticatorData.php`
- Create: `app/Services/Glosis/AppAttest/AttestedKey.php`
- Create: `app/Services/Glosis/AppAttest/AppAttestVerifier.php` (metoden `verifyAttestation`, assertion i Task 3)
- Create: `resources/certs/Apple_App_Attestation_Root_CA.pem` (kopiera från scratchpad, kontrollera SHA-256 ovan)
- Create: `tests/Support/FakeAppAttest.php`
- Test: `tests/Unit/Glosis/AppAttestVerifierTest.php`

**Interfaces:**
- Consumes: `Cbor`, `InvalidAttestationException` (Task 1).
- Produces:
  - `AppAttestVerifier::__construct(?string $rootPem = null)`. Null = rotfilen ur `resources/`, kontrollerad mot `ROOT_SHA256`.
  - `verifyAttestation(string $attestation, string $keyId, string $clientDataHash, string $appId, int $now, bool $allowDevelopment): AttestedKey`. `$attestation` och `$keyId` är råa bytes (32 B keyId).
  - `AttestedKey` (readonly): `string $keyId` (rå), `string $publicKeyPem`, `string $environment` (`production`|`development`), `?string $bundleVersion`, `?int $validationCategory`.
  - `AuthenticatorData::parse(string $data, bool $attested): AuthenticatorData` med `rpIdHash`, `flags`, `signCount`, `aaguid`, `credentialId`, `coseKeyPoint(): string`, `bundleVersion(): ?string`, `validationCategory(): ?int`.
  - Testhjälp: `FakeAppAttest::rootPem(): string`, `attest(string $challenge, string $appId, array $o = []): array{keyId: string, rawKeyId: string, attestation: string, privateKey: OpenSSLAsymmetricKey}`, `FakeAppAttest::assert(OpenSSLAsymmetricKey $key, string $clientData, string $appId, int $count, array $o = []): string` (base64).

- [ ] **Step 1: Skriv testhjälpen**

```php
<?php
// tests/Support/FakeAppAttest.php
namespace Tests\Support;

use OpenSSLAsymmetricKey;
use OpenSSLCertificate;

/**
 * Egen kedja som ser ut som Apples App Attest: rot → intermediate → credCert,
 * där credCert har nonce-tillägget 1.2.840.113635.100.8.2 = SEQUENCE { [1] { OCTET STRING nonce } }.
 * Varje fält kan bytas via $o för att testa avvisningar.
 */
final class FakeAppAttest
{
    public const AAGUID_PRODUCTION = "appattest\0\0\0\0\0\0\0";

    public OpenSSLAsymmetricKey $rootKey;

    public OpenSSLCertificate $root;

    public OpenSSLAsymmetricKey $intKey;

    public OpenSSLCertificate $intermediate;

    public function __construct()
    {
        $this->rootKey = FakeAppleChain::ecKey('secp384r1');
        $this->root = self::sign(['commonName' => 'Fake App Attestation Root CA'], $this->rootKey, null, $this->rootKey, 'v3_ca', 3650);
        $this->intKey = FakeAppleChain::ecKey('secp384r1');
        $this->intermediate = self::sign(['commonName' => 'Fake App Attestation CA 1'], $this->intKey, $this->root, $this->rootKey, 'v3_ca', 3650);
    }

    public function rootPem(): string
    {
        openssl_x509_export($this->root, $pem);

        return $pem;
    }

    /**
     * @param  array{key?: OpenSSLAsymmetricKey, appId?: string, count?: int, aaguid?: string, credentialId?: string, nonce?: string, fmt?: string, leafDays?: int, intermediate?: OpenSSLCertificate, extensions?: array<string, mixed>, x5c?: list<string>}  $o
     * @return array{keyId: string, rawKeyId: string, attestation: string, privateKey: OpenSSLAsymmetricKey}
     */
    public function attest(string $challenge, string $appId, array $o = []): array
    {
        $key = $o['key'] ?? FakeAppleChain::ecKey('prime256v1');
        $point = self::point($key);
        $keyId = hash('sha256', $point, true);
        $authData = self::authData(
            hash('sha256', $o['appId'] ?? $appId, true),
            $o['count'] ?? 0,
            $o['aaguid'] ?? self::AAGUID_PRODUCTION,
            $o['credentialId'] ?? $keyId,
            $point,
            $o['extensions'] ?? ['apple_bundle_version_01' => CborEncoder::text('1'), 'apple_validation_category_01' => "\x01\0\0\0"],
        );
        $nonce = $o['nonce'] ?? hash('sha256', $authData.hash('sha256', $challenge, true), true);
        $leaf = $this->leaf($key, $nonce, $o['leafDays'] ?? 3);
        $x5c = $o['x5c'] ?? [FakeAppleChain::der($leaf), FakeAppleChain::der($o['intermediate'] ?? $this->intermediate)];
        $obj = [
            'fmt' => CborEncoder::text($o['fmt'] ?? 'apple-appattest'),
            'attStmt' => ['x5c' => $x5c, 'receipt' => 'fake-receipt'],
            'authData' => $authData,
        ];

        return ['keyId' => base64_encode($keyId), 'rawKeyId' => $keyId, 'attestation' => base64_encode(CborEncoder::encode($obj)), 'privateKey' => $key];
    }

    /** authData enligt WebAuthn/Apple: rpIdHash, flags, counter, [aaguid, credIdLen, credId, COSE-nyckel], [extensions]. */
    public static function authData(string $rpIdHash, int $count, ?string $aaguid = null, ?string $credentialId = null, ?string $point = null, array $extensions = []): string
    {
        $out = $rpIdHash.chr($aaguid !== null ? 0x40 : 0x00).pack('N', $count);
        if ($aaguid !== null) {
            $out .= $aaguid.pack('n', strlen((string) $credentialId)).$credentialId
                .CborEncoder::encode([1 => 2, 3 => -7, -1 => 1, -2 => substr((string) $point, 1, 32), -3 => substr((string) $point, 33, 32)]);
        }
        if ($extensions !== []) {
            $out .= CborEncoder::encode($extensions);
        }

        return $out;
    }

    /**
     * Som vi antar att appen gör (se planens "Assertionens signatur"): nonce = SHA256(authData ‖ SHA256(clientData)),
     * ECDSA P-256/SHA-256 med nonce som meddelande, DER-signatur. Ändras tillsammans med verifyAssertion om Task 14 visar annat.
     *
     * @param  array{appId?: string, key?: OpenSSLAsymmetricKey, signedClientData?: string}  $o
     */
    public static function assert(OpenSSLAsymmetricKey $key, string $clientData, string $appId, int $count, array $o = []): string
    {
        $authData = self::authData(hash('sha256', $o['appId'] ?? $appId, true), $count);
        $nonce = hash('sha256', $authData.hash('sha256', $o['signedClientData'] ?? $clientData, true), true);
        openssl_sign($nonce, $sig, $o['key'] ?? $key, OPENSSL_ALGO_SHA256);

        return base64_encode(CborEncoder::encode(['signature' => $sig, 'authenticatorData' => $authData]));
    }

    public static function point(OpenSSLAsymmetricKey $key): string
    {
        $d = openssl_pkey_get_details($key)['ec'];

        return "\x04".str_pad($d['x'], 32, "\0", STR_PAD_LEFT).str_pad($d['y'], 32, "\0", STR_PAD_LEFT);
    }

    private function leaf(OpenSSLAsymmetricKey $key, string $nonce, int $days): OpenSSLCertificate
    {
        $ext = '1.2.840.113635.100.8.2 = DER:3024a1220420'.bin2hex($nonce);

        return self::sign(['commonName' => 'fake credCert'], $key, $this->intermediate, $this->intKey, 'v3_leaf', $days, $ext);
    }

    private static function sign(array $dn, OpenSSLAsymmetricKey $key, ?OpenSSLCertificate $issuer, OpenSSLAsymmetricKey $issuerKey, string $section, int $days, string $extra = ''): OpenSSLCertificate
    {
        $cnf = tempnam(sys_get_temp_dir(), 'fakeattest');
        file_put_contents($cnf, "[ req ]\ndistinguished_name = dn\n[ dn ]\n"
            ."[ v3_ca ]\nbasicConstraints = critical,CA:TRUE\nkeyUsage = critical,keyCertSign,cRLSign\n"
            ."[ v3_leaf ]\nbasicConstraints = critical,CA:FALSE\nkeyUsage = critical,digitalSignature\n{$extra}\n");
        try {
            $opt = ['config' => $cnf, 'x509_extensions' => $section, 'digest_alg' => 'sha256'];

            return openssl_csr_sign(openssl_csr_new($dn, $key, $opt), $issuer, $issuerKey, $days, $opt, random_int(1000, PHP_INT_MAX));
        } finally {
            @unlink($cnf);
        }
    }
}
```

- [ ] **Step 2: Skriv de fallerande testerna**

```php
<?php
// tests/Unit/Glosis/AppAttestVerifierTest.php
namespace Tests\Unit\Glosis;

use App\Services\Glosis\AppAttest\AppAttestVerifier;
use App\Services\Glosis\AppAttest\InvalidAttestationException;
use Tests\Support\FakeAppAttest;
use Tests\TestCase;

class AppAttestVerifierTest extends TestCase
{
    private const APP_ID = 'ABCDE12345.se.computercat.glosis';

    private const CHALLENGE = 'test-challenge';

    private static ?FakeAppAttest $fake = null;

    private function fake(): FakeAppAttest
    {
        return self::$fake ??= new FakeAppAttest;
    }

    private function verifier(): AppAttestVerifier
    {
        return new AppAttestVerifier($this->fake()->rootPem());
    }

    private function verify(array $a, ?int $now = null, bool $dev = false, string $challenge = self::CHALLENGE): \App\Services\Glosis\AppAttest\AttestedKey
    {
        return $this->verifier()->verifyAttestation(base64_decode($a['attestation']), $a['rawKeyId'], hash('sha256', $challenge, true), self::APP_ID, $now ?? time(), $dev);
    }

    /**
     * Apples egen vektor med Apples riktiga rot. Exemplet stämmer bara om den
     * råa challengen används som clientDataHash (Apple-forumet tråd 842509);
     * i drift är clientDataHash SHA256(challenge) enligt artikeln.
     */
    public function test_apples_exempel_med_riktiga_roten(): void
    {
        $fx = json_decode(file_get_contents(base_path('tests/Fixtures/glosis-appattest-apple-sample.json')), true);
        $key = (new AppAttestVerifier)->verifyAttestation(
            base64_decode($fx['attestation'], true), base64_decode($fx['keyId'], true),
            $fx['clientDataHashRaw'], $fx['appId'], $fx['verifyAt'], false);

        $this->assertSame('production', $key->environment);
        $this->assertSame('1', $key->bundleVersion);
        $this->assertSame(1, $key->validationCategory);
        $this->assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $key->publicKeyPem);
    }

    public function test_godkand_attestering(): void
    {
        $a = $this->fake()->attest(self::CHALLENGE, self::APP_ID);
        $key = $this->verify($a);
        $this->assertSame($a['rawKeyId'], $key->keyId);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function rejected(): array
    {
        return [
            'fel rpId' => [['appId' => 'ABCDE12345.se.annan.app'], 'RP ID'],
            'räknare inte 0' => [['count' => 1], 'counter'],
            'okänd aaguid' => [['aaguid' => str_repeat("\0", 16)], 'aaguid'],
            'credentialId ≠ keyId' => [['credentialId' => str_repeat("\x01", 32)], 'credentialId'],
            'nonce stämmer inte' => [['nonce' => str_repeat("\x02", 32)], 'nonce'],
            'fel fmt' => [['fmt' => 'packed'], 'fmt'],
            'ett certifikat i x5c' => [['x5c' => ['x']], 'x5c'],
        ];
    }

    /** @dataProvider rejected */
    public function test_avvisar(array $o, string $reason): void
    {
        $a = $this->fake()->attest(self::CHALLENGE, self::APP_ID, $o);
        $this->expectException(InvalidAttestationException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($reason, '/').'/i');
        $this->verify($a);
    }

    public function test_avvisar_annan_challenge(): void
    {
        $a = $this->fake()->attest(self::CHALLENGE, self::APP_ID);
        $this->expectExceptionMessageMatches('/nonce/');
        $this->verify($a, challenge: 'en-annan');
    }

    public function test_avvisar_kedja_fran_annan_rot(): void
    {
        $a = (new FakeAppAttest)->attest(self::CHALLENGE, self::APP_ID);
        $this->expectException(InvalidAttestationException::class);
        $this->verify($a);
    }

    public function test_avvisar_utgangen_credcert(): void
    {
        $a = $this->fake()->attest(self::CHALLENGE, self::APP_ID, ['leafDays' => 1]);
        $this->expectExceptionMessageMatches('/giltig/');
        $this->verify($a, time() + 3 * 86400);
    }

    public function test_development_nyckel_bara_nar_det_ar_tillatet(): void
    {
        $a = $this->fake()->attest(self::CHALLENGE, self::APP_ID, ['aaguid' => 'appattestdevelop']);
        $this->assertSame('development', $this->verify($a, dev: true)->environment);
        $this->expectExceptionMessageMatches('/development/');
        $this->verify($a, dev: false);
    }

    public function test_avvisar_for_stor_eller_trasig_indata(): void
    {
        $this->expectException(InvalidAttestationException::class);
        $this->verifier()->verifyAttestation(str_repeat("\xa0", 20_000), str_repeat("\0", 32), str_repeat("\0", 32), self::APP_ID, time(), false);
    }

    public function test_fel_rotfil_vagrar_starta(): void
    {
        $this->expectException(\RuntimeException::class);
        $tmp = tempnam(sys_get_temp_dir(), 'root');
        file_put_contents($tmp, $this->fake()->rootPem());
        AppAttestVerifier::fromRootFile($tmp);
    }
}
```

- [ ] **Step 3: Kör och se att det fallerar**

Run: `php artisan test --filter=AppAttestVerifierTest`
Expected: FAIL, `Class "App\Services\Glosis\AppAttest\AppAttestVerifier" not found`

- [ ] **Step 4: Implementera**

```php
<?php
// app/Services/Glosis/AppAttest/AttestedKey.php
namespace App\Services\Glosis\AppAttest;

final readonly class AttestedKey
{
    public function __construct(
        /** SHA256 av publika nyckeln (32 byte), samma som appens keyId. */
        public string $keyId,
        public string $publicKeyPem,
        /** production eller development (aaguid). */
        public string $environment,
        public ?string $bundleVersion,
        public ?int $validationCategory,
    ) {}
}
```

```php
<?php
// app/Services/Glosis/AppAttest/AuthenticatorData.php
namespace App\Services\Glosis\AppAttest;

/**
 * authData enligt WebAuthn (https://www.w3.org/TR/webauthn/#sec-authenticator-data)
 * med Apples App Attest-fält: rpIdHash(32) flags(1) counter(4) [aaguid(16)
 * credIdLen(2) credentialId(32) COSE-nyckel] [extensions].
 *
 * Apples exempel (attestation-object-validation-guide) har extensions efter
 * COSE-nyckeln utan att ED-biten (0x80) är satt. Därför läses extensions när
 * det finns bytes kvar, oavsett flaggan, och måste då vara exakt en CBOR-map.
 */
final readonly class AuthenticatorData
{
    /**
     * @param  array<int, mixed>|null  $coseKey
     * @param  array<int|string, mixed>  $extensions
     */
    private function __construct(
        public string $rpIdHash,
        public int $flags,
        public int $signCount,
        public ?string $aaguid,
        public ?string $credentialId,
        private ?array $coseKey,
        private array $extensions,
    ) {}

    public static function parse(string $data, bool $attested): self
    {
        if (strlen($data) < 37 || strlen($data) > 1024) {
            throw new InvalidAttestationException('authData har fel längd');
        }
        $rp = substr($data, 0, 32);
        $flags = ord($data[32]);
        $count = unpack('N', substr($data, 33, 4))[1];
        $offset = 37;
        $aaguid = $credentialId = $cose = null;

        if ($attested) {
            if (strlen($data) < 55) {
                throw new InvalidAttestationException('authData saknar attesterad nyckel');
            }
            $aaguid = substr($data, 37, 16);
            $len = unpack('n', substr($data, 53, 2))[1];
            if ($len !== 32 || strlen($data) < 55 + $len) {
                throw new InvalidAttestationException('credentialId har fel längd');
            }
            $credentialId = substr($data, 55, 32);
            $offset = 55 + 32;
            $cose = Cbor::decodeAt($data, $offset);
            if (! is_array($cose)) {
                throw new InvalidAttestationException('COSE-nyckeln är inte en map');
            }
        }

        $extensions = [];
        if ($offset < strlen($data)) {
            $extensions = Cbor::decodeAt($data, $offset);
            if (! is_array($extensions) || $offset !== strlen($data)) {
                throw new InvalidAttestationException('authData: extensions har fel form');
            }
        }

        return new self($rp, $flags, $count, $aaguid, $credentialId, $cose, $extensions);
    }

    /** 0x04 ‖ x ‖ y ur COSE-nyckeln (RFC 8152: kty 2 = EC2, alg -7 = ES256, crv 1 = P-256). */
    public function coseKeyPoint(): string
    {
        $k = $this->coseKey ?? [];
        if (($k[1] ?? null) !== 2 || ($k[3] ?? null) !== -7 || ($k[-1] ?? null) !== 1
            || ! is_string($k[-2] ?? null) || strlen($k[-2]) !== 32
            || ! is_string($k[-3] ?? null) || strlen($k[-3]) !== 32) {
            throw new InvalidAttestationException('COSE-nyckeln är inte en P-256-nyckel');
        }

        return "\x04".$k[-2].$k[-3];
    }

    public function bundleVersion(): ?string
    {
        $v = $this->extensions['apple_bundle_version_01'] ?? null;

        return is_string($v) && $v !== '' && strlen($v) <= 64 && mb_check_encoding($v, 'UTF-8') ? $v : null;
    }

    /** Artikeln säger UInt32; Apples exempel har 4 byte little-endian. Båda tolkas. */
    public function validationCategory(): ?int
    {
        $v = $this->extensions['apple_validation_category_01'] ?? null;
        if (is_int($v)) {
            return $v;
        }

        return is_string($v) && strlen($v) === 4 ? unpack('V', $v)[1] : null;
    }
}
```

```php
<?php
// app/Services/Glosis/AppAttest/AppAttestVerifier.php
namespace App\Services\Glosis\AppAttest;

use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use RuntimeException;

/**
 * Apple App Attest på servern, enligt "Validating apps that connect to your
 * server" (developer.apple.com/documentation/devicecheck/validating-apps-that-connect-to-your-server).
 * Stegnumren i kommentarerna är artikelns. Testat mot Apples exempelvektor
 * (attestation-object-validation-guide) med Apples riktiga rot.
 */
final class AppAttestVerifier
{
    public const ROOT_CERT_PATH = 'certs/Apple_App_Attestation_Root_CA.pem';

    /** SHA-256 (DER) för https://www.apple.com/certificateauthority/Apple_App_Attestation_Root_CA.pem, hämtad 2026-10-12. */
    public const ROOT_SHA256 = '1cb9823ba28ba6ad2d33a006941de2ae4f513ef1d4e831b9f7e0fa7b6242c932';

    public const NONCE_OID = '1.2.840.113635.100.8.2';

    /** DER: SEQUENCE(36) { [1](34) { OCTET STRING(32) nonce } }. */
    private const NONCE_PREFIX = "\x30\x24\xa1\x22\x04\x20";

    public const AAGUID_PRODUCTION = "appattest\0\0\0\0\0\0\0";

    /** "appattestdevelop" i valideringsartikeln, "appattestsandbox" i preparing-to-use. Båda är development. */
    public const AAGUIDS_DEVELOPMENT = ['appattestdevelop', 'appattestsandbox'];

    private const MAX_ATTESTATION_BYTES = 16_384;

    private const MAX_ASSERTION_BYTES = 2_048;

    private readonly OpenSSLCertificate $root;

    public function __construct(?string $rootPem = null)
    {
        $root = $rootPem === null ? self::loadPinnedRoot(resource_path(self::ROOT_CERT_PATH)) : @openssl_x509_read($rootPem);
        if (! $root instanceof OpenSSLCertificate) {
            throw new RuntimeException('App Attest-roten går inte att läsa');
        }
        $this->root = $root;
    }

    /** Läser roten och kontrollerar att det är Apples (för test av kontrollen). */
    public static function fromRootFile(string $path): self
    {
        $root = self::loadPinnedRoot($path);
        openssl_x509_export($root, $pem);

        return new self($pem);
    }

    private static function loadPinnedRoot(string $path): OpenSSLCertificate
    {
        $pem = @file_get_contents($path);
        $cert = $pem === false ? false : @openssl_x509_read($pem);
        if (! $cert instanceof OpenSSLCertificate) {
            throw new RuntimeException('Apple App Attestation Root CA saknas i resources/'.self::ROOT_CERT_PATH);
        }
        if (! hash_equals(self::ROOT_SHA256, (string) openssl_x509_fingerprint($cert, 'sha256'))) {
            throw new RuntimeException('Apple App Attestation Root CA har fel SHA-256, filen är utbytt eller trasig');
        }

        return $cert;
    }

    /**
     * @param  string  $keyId  rå keyId (32 byte) från appen
     * @param  string  $clientDataHash  SHA256 av challengen som servern gav ut
     * @param  string  $appId  Team ID + "." + bundle-id
     *
     * @throws InvalidAttestationException
     */
    public function verifyAttestation(string $attestation, string $keyId, string $clientDataHash, string $appId, int $now, bool $allowDevelopment): AttestedKey
    {
        if ($attestation === '' || strlen($attestation) > self::MAX_ATTESTATION_BYTES) {
            throw new InvalidAttestationException('Attesteringen saknas eller är för stor');
        }
        if (strlen($keyId) !== 32 || strlen($clientDataHash) === 0) {
            throw new InvalidAttestationException('keyId eller clientDataHash har fel längd');
        }

        $obj = Cbor::decode($attestation);
        if (! is_array($obj) || ($obj['fmt'] ?? null) !== 'apple-appattest') {
            throw new InvalidAttestationException('fmt är inte apple-appattest');
        }
        $stmt = $obj['attStmt'] ?? null;
        $authData = $obj['authData'] ?? null;
        $x5c = is_array($stmt) ? ($stmt['x5c'] ?? null) : null;
        $receipt = is_array($stmt) ? ($stmt['receipt'] ?? null) : null;
        if (! is_string($authData) || ! is_array($x5c) || ! array_is_list($x5c) || count($x5c) !== 2
            || ! is_string($receipt) || $receipt === '') {
            throw new InvalidAttestationException('attStmt har fel form (x5c med två certifikat, receipt, authData)');
        }

        // 1. Kedjan credCert → intermediate → Apples rot, giltig nu.
        $credCert = $this->verifyChain($x5c, $now);

        // 2–3. nonce = SHA256(authData ‖ clientDataHash)
        $nonce = hash('sha256', $authData.$clientDataHash, true);

        // 4. credCert-tillägget 1.2.840.113635.100.8.2 innehåller nonce.
        $ext = openssl_x509_parse($credCert)['extensions'][self::NONCE_OID] ?? null;
        if (! is_string($ext) || ! hash_equals(self::NONCE_PREFIX.$nonce, $ext)) {
            throw new InvalidAttestationException('nonce stämmer inte');
        }

        // 5. SHA256 av publika nyckeln (X9.62 okomprimerad) = keyId
        $public = openssl_pkey_get_public($credCert);
        $details = $public instanceof OpenSSLAsymmetricKey ? openssl_pkey_get_details($public) : false;
        if (! is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC || ($details['ec']['curve_name'] ?? null) !== 'prime256v1') {
            throw new InvalidAttestationException('credCert har ingen P-256-nyckel');
        }
        $point = "\x04".str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT).str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
        if (! hash_equals(hash('sha256', $point, true), $keyId)) {
            throw new InvalidAttestationException('Nyckelns hash är inte keyId');
        }

        $auth = AuthenticatorData::parse($authData, attested: true);
        // 6. RP ID
        if (! hash_equals(hash('sha256', $appId, true), $auth->rpIdHash)) {
            throw new InvalidAttestationException('RP ID stämmer inte');
        }
        // 7. counter
        if ($auth->signCount !== 0) {
            throw new InvalidAttestationException('counter är inte 0');
        }
        // 8. aaguid
        $environment = match (true) {
            $auth->aaguid === self::AAGUID_PRODUCTION => 'production',
            in_array($auth->aaguid, self::AAGUIDS_DEVELOPMENT, true) => 'development',
            default => throw new InvalidAttestationException('Okänd aaguid'),
        };
        if ($environment === 'development' && ! $allowDevelopment) {
            throw new InvalidAttestationException('development-nyckel i produktion');
        }
        // 9. credentialId
        if (! hash_equals($keyId, (string) $auth->credentialId)) {
            throw new InvalidAttestationException('credentialId är inte keyId');
        }
        // Nyckeln i authData är samma som i credCert.
        if (! hash_equals($point, $auth->coseKeyPoint())) {
            throw new InvalidAttestationException('COSE-nyckeln är inte credCerts nyckel');
        }

        // 10–11. Extensions tolkas och sparas men avgör inte än (talvärdena för
        // App Store och TestFlight är odokumenterade; se beslut 2026-10-12).
        // Kvittot (receipt) krävs i formen men sparas inte: det behövs bara för Apples riskbedömning
        // (assessing-fraud-risk), som inte används i v1. Dataminimering; läggs till om riskbedömningen behövs.
        return new AttestedKey($keyId, $details['key'], $environment, $auth->bundleVersion(), $auth->validationCategory());
    }

    /** @param list<mixed> $x5c */
    private function verifyChain(array $x5c, int $now): OpenSSLCertificate
    {
        $certs = [];
        foreach ($x5c as $der) {
            $cert = is_string($der) && $der !== '' ? @openssl_x509_read("-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END CERTIFICATE-----\n") : false;
            if (! $cert instanceof OpenSSLCertificate) {
                throw new InvalidAttestationException('x5c-certifikat går inte att läsa');
            }
            $certs[] = $cert;
        }
        [$leaf, $intermediate] = $certs;
        $info = ['root' => openssl_x509_parse($this->root), 'intermediate' => openssl_x509_parse($intermediate), 'credCert' => openssl_x509_parse($leaf)];
        if (in_array(false, $info, true)) {
            throw new InvalidAttestationException('Certifikat går inte att tolka');
        }
        if ($info['intermediate']['issuer'] !== $info['root']['subject'] || $info['credCert']['issuer'] !== $info['intermediate']['subject']) {
            throw new InvalidAttestationException('Namnkedjan stämmer inte');
        }
        $rootKey = openssl_pkey_get_public($this->root);
        $intKey = openssl_pkey_get_public($intermediate);
        if (! $rootKey || ! $intKey || openssl_x509_verify($intermediate, $rootKey) !== 1 || openssl_x509_verify($leaf, $intKey) !== 1) {
            throw new InvalidAttestationException('Signaturerna i kedjan stämmer inte');
        }
        $isCa = fn (array $i) => preg_match('/(^|,\s*)CA:TRUE(\s*,|$)/', (string) ($i['extensions']['basicConstraints'] ?? '')) === 1;
        if (! $isCa($info['intermediate']) || $isCa($info['credCert'])) {
            throw new InvalidAttestationException('CA-flaggorna i kedjan stämmer inte');
        }
        foreach ($info as $name => $i) {
            if (! is_int($i['validFrom_time_t'] ?? null) || ! is_int($i['validTo_time_t'] ?? null)
                || $now < $i['validFrom_time_t'] || $now > $i['validTo_time_t']) {
                throw new InvalidAttestationException("Certifikatet {$name} är inte giltigt nu");
            }
        }

        return $leaf;
    }
}
```

Kopiera roten: `cp /private/tmp/claude-501/-Users-erik/df8fbe8c-a613-4585-a510-85d05838d9b5/scratchpad/Apple_App_Attestation_Root_CA.pem resources/certs/` och kontrollera med `openssl x509 -in resources/certs/Apple_App_Attestation_Root_CA.pem -outform der | shasum -a 256`. Väntat: `1cb9823b…c932`.

- [ ] **Step 5: Kör testerna**

Run: `php artisan test --filter=AppAttestVerifierTest`
Expected: PASS. Fallerar `test_apples_exempel_med_riktiga_roten` på "giltig" är `verifyAt` fel (credCert gäller 2026-04-20 18:13 till 2026-04-23 18:13 UTC).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Glosis/AppAttest resources/certs/Apple_App_Attestation_Root_CA.pem tests/Support/FakeAppAttest.php tests/Unit/Glosis/AppAttestVerifierTest.php
git commit -m "Glosis: verifiera App Attest-attestering enligt Apple, testad mot Apples exempel"
```

---

### Task 3: Verifiera assertioner (computercat-api)

**Files:**
- Modify: `app/Services/Glosis/AppAttest/AppAttestVerifier.php` (lägg till `verifyAssertion`)
- Test: `tests/Unit/Glosis/AppAttestVerifierTest.php` (nya testmetoder)

**Interfaces:**
- Produces: `AppAttestVerifier::verifyAssertion(string $assertion, string $clientData, string $publicKeyPem, int $storedSignCount, string $appId): int`. Returnerar den nya räknaren (> `$storedSignCount`). Challengen i clientData kontrolleras av anroparen (Task 5).

- [ ] **Step 1: Skriv de fallerande testerna** (lägg till i `AppAttestVerifierTest`)

```php
    private function attested(): array
    {
        $a = $this->fake()->attest(self::CHALLENGE, self::APP_ID);

        return [$a['privateKey'], $this->verify($a)->publicKeyPem];
    }

    public function test_godkand_assertion_ger_ny_raknare(): void
    {
        [$priv, $pem] = $this->attested();
        $cd = '{"action":"tts","challenge":"c1"}';
        $count = $this->verifier()->verifyAssertion(base64_decode(FakeAppAttest::assert($priv, $cd, self::APP_ID, 1)), $cd, $pem, 0, self::APP_ID);
        $this->assertSame(1, $count);
    }

    /** @return array<string, array{array<string, mixed>, int, string}> */
    public static function badAssertions(): array
    {
        return [
            'räknaren ökar inte' => [[], 5, 'counter'],
            'fel rpId' => [['appId' => 'ABCDE12345.se.annan.app'], 0, 'RP ID'],
            'ändrad clientData' => [['signedClientData' => '{"action":"tts","challenge":"annan"}'], 0, 'Signaturen'],
        ];
    }

    /** @dataProvider badAssertions */
    public function test_avvisar_assertion(array $o, int $stored, string $reason): void
    {
        [$priv, $pem] = $this->attested();
        $cd = '{"action":"tts","challenge":"c1"}';
        $assertion = base64_decode(FakeAppAttest::assert($priv, $cd, self::APP_ID, 5, $o));
        $this->expectExceptionMessageMatches('/'.preg_quote($reason, '/').'/');
        $this->verifier()->verifyAssertion($assertion, $cd, $pem, $stored, self::APP_ID);
    }

    public function test_avvisar_assertion_signerad_med_annan_nyckel(): void
    {
        [, $pem] = $this->attested();
        $cd = '{"action":"tts","challenge":"c1"}';
        $other = \Tests\Support\FakeAppleChain::ecKey('prime256v1');
        $this->expectExceptionMessageMatches('/Signaturen/');
        $this->verifier()->verifyAssertion(base64_decode(FakeAppAttest::assert($other, $cd, self::APP_ID, 1)), $cd, $pem, 0, self::APP_ID);
    }

    public function test_avvisar_assertion_med_fel_form(): void
    {
        [, $pem] = $this->attested();
        $this->expectException(InvalidAttestationException::class);
        $this->verifier()->verifyAssertion(\Tests\Support\CborEncoder::encode(['signature' => 'x']), '{}', $pem, 0, self::APP_ID);
    }
```

- [ ] **Step 2: Kör och se att det fallerar**

Run: `php artisan test --filter=AppAttestVerifierTest`
Expected: FAIL, `Call to undefined method …::verifyAssertion()`

- [ ] **Step 3: Implementera** (lägg till i `AppAttestVerifier`)

```php
    /**
     * Assertion enligt artikelns "Verify the assertion".
     *
     * @return int den nya räknaren, som anroparen sparar atomiskt
     *
     * @throws InvalidAttestationException
     */
    public function verifyAssertion(string $assertion, string $clientData, string $publicKeyPem, int $storedSignCount, string $appId): int
    {
        if ($assertion === '' || strlen($assertion) > self::MAX_ASSERTION_BYTES) {
            throw new InvalidAttestationException('Assertionen saknas eller är för stor');
        }
        $obj = Cbor::decode($assertion);
        $signature = is_array($obj) ? ($obj['signature'] ?? null) : null;
        $authData = is_array($obj) ? ($obj['authenticatorData'] ?? null) : null;
        if (! is_string($signature) || $signature === '' || ! is_string($authData) || count($obj) !== 2) {
            throw new InvalidAttestationException('Assertionen har fel form');
        }

        // 1–3. nonce = SHA256(authenticatorData ‖ SHA256(clientData)); signaturen är ECDSA P-256/SHA-256 med
        // nonce som meddelande (dubbel hashning), som node-app-attest (createVerify('SHA256').update(nonce)) och
        // devicecheck-appattest (SHA256withECDSA, update(nonce)).
        // ASSUMED — verifieras mot en riktig enhet (planens Task 14) innan gratisflaggan slås på; Apple saknar assertion-testvektor.
        // Reserv om enhetsprovet visar enkel hashning: openssl_verify($authData.hash('sha256', $clientData, true), …).
        $nonce = hash('sha256', $authData.hash('sha256', $clientData, true), true);
        $key = openssl_pkey_get_public($publicKeyPem);
        if (! $key instanceof OpenSSLAsymmetricKey
            || openssl_verify($nonce, $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new InvalidAttestationException('Signaturen stämmer inte');
        }

        $auth = AuthenticatorData::parse($authData, attested: false);
        // 4. RP ID
        if (! hash_equals(hash('sha256', $appId, true), $auth->rpIdHash)) {
            throw new InvalidAttestationException('RP ID stämmer inte');
        }
        // 5. counter > förra (> 0 första gången, eftersom attesteringen hade 0)
        if ($auth->signCount <= $storedSignCount) {
            throw new InvalidAttestationException('counter ökar inte (replay?)');
        }

        // 6. Challengen kontrolleras av FreeGate. 7–8: extensions loggas men avgör inte (se attesteringen).
        return $auth->signCount;
    }
```

- [ ] **Step 4: Kör testerna**

Run: `php artisan test --filter=AppAttestVerifierTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/Glosis/AppAttest/AppAttestVerifier.php tests/Unit/Glosis/AppAttestVerifierTest.php
git commit -m "Glosis: verifiera App Attest-assertioner (signatur, RP ID, räknare)"
```

---

### Task 4: Nyckeltabell, challenges, inställningar och registrering (computercat-api)

**Files:**
- Create: `database/migrations/2026_10_12_100001_create_glosis_app_attest_keys_table.php`
- Create: `app/Models/AppAttestKey.php`
- Create: `app/Services/Glosis/AppAttest/AttestChallenges.php`
- Create: `app/Http/Controllers/Api/V1/GlosisAttestController.php`
- Modify: `app/Services/Glosis/GlosisSettings.php` (gratisinställningar)
- Modify: `app/Providers/AppServiceProvider.php` (throttles `glosis-attest-challenge`, `glosis-attest-register`)
- Modify: `bootstrap/app.php` (CSRF-undantag rad 30, 429-JSON rad 46)
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/V1/GlosisAttestTest.php`

**Interfaces:**
- Consumes: `AppAttestVerifier::verifyAttestation` (Task 2).
- Produces:
  - `POST /api/v1/games/glosis/attest/challenge` → 200 `{"challenge": "<54 tecken base64url>", "expires_in": 300}`. 503 `free_unavailable` när flaggan är av eller Team ID saknas.
  - `POST /api/v1/games/glosis/attest/register` JSON `{"keyId": "<base64 32 B>", "attestation": "<base64>", "challenge": "<från ovan>"}` → 201 `{"status":"registered"}` (ny), 200 (samma nyckel igen, räknaren rörs inte), 422 `invalid_attestation`, 503 `free_unavailable`. Appen räknar clientDataHash = SHA256(challengens UTF-8-bytes).
  - `AttestChallenges::issue(): string` (54 tecken base64url, tillståndslös HMAC), `AttestChallenges::consume(mixed $challenge): bool` (engångs, atomisk; skriver bara en `:used`-markör).
  - `php artisan glosis:prune-cache` (varje timme): raderar utgångna `glosis-`-rader i databascachen. Räknar först.
  - `AppAttestKey` (tabell `glosis_app_attest_keys`): `key_id` (base64-sträng), `public_key`, `sign_count`, `environment`, `bundle_version`, `validation_category`, `last_used_at`. Ingen spärrkolumn och inget kvitto (se beslut). `AppAttestKey::advanceSignCount(int $count): bool` (atomisk). Konstanterna `ENV_PRODUCTION`, `ENV_DEVELOPMENT`.
  - `GlosisSettings`: `freeEnabled(): bool`, `appAttestAppId(): ?string`, `freeScanDailyLimit(): int`, `freeScanIpDailyLimit(): int`, `freeTtsDailyNewLimit(): int`, `freeTtsIpDailyNewLimit(): int`, `freeMonthlyBudgetMicroUsd(): int`. Konstanterna `FREE_SCAN_WEEKLY_PER_KEY = 1`, `TTS_DAILY_NEW_PER_KEY = 100`.

- [ ] **Step 1: Skriv de fallerande testerna**

```php
<?php
// tests/Feature/Api/V1/GlosisAttestTest.php
namespace Tests\Feature\Api\V1;

use App\Models\AppAttestKey;
use App\Models\Game;
use App\Services\Glosis\AppAttest\AppAttestVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeAppAttest;
use Tests\TestCase;

class GlosisAttestTest extends TestCase
{
    use RefreshDatabase;

    public const TEAM = 'ABCDE12345';

    public const APP_ID = self::TEAM.'.se.computercat.glosis';

    private static ?FakeAppAttest $fake = null;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();
        self::$fake ??= new FakeAppAttest;
        $this->app->instance(AppAttestVerifier::class, new AppAttestVerifier(self::$fake->rootPem()));
        $this->game = Game::where('slug', 'glosis')->firstOrFail();
        $this->game->update(['settings' => array_merge($this->game->settings ?? [], ['free' => ['enabled' => true], 'app_attest' => ['team_id' => self::TEAM]])]);
    }

    private function challenge(): string
    {
        return $this->postJson('/api/v1/games/glosis/attest/challenge')->assertOk()->json('challenge');
    }

    private function register(array $a, string $challenge)
    {
        return $this->postJson('/api/v1/games/glosis/attest/register', ['keyId' => $a['keyId'], 'attestation' => $a['attestation'], 'challenge' => $challenge]);
    }

    public function test_registrerar_en_ny_nyckel(): void
    {
        $ch = $this->challenge();
        $this->register(self::$fake->attest($ch, self::APP_ID), $ch)->assertCreated()->assertJson(['status' => 'registered']);

        $key = AppAttestKey::sole();
        $this->assertSame(0, $key->sign_count);
        $this->assertSame('production', $key->environment);
        $this->assertSame('1', $key->bundle_version);
    }

    public function test_challenge_galler_bara_en_gang(): void
    {
        $ch = $this->challenge();
        $this->register(self::$fake->attest($ch, self::APP_ID), $ch)->assertCreated();
        $this->register(self::$fake->attest($ch, self::APP_ID), $ch)->assertStatus(422)->assertJson(['error' => 'invalid_attestation']);
    }

    public function test_paddad_eller_okand_challenge_nekas(): void
    {
        $this->register(self::$fake->attest('egen', self::APP_ID), 'egen')->assertStatus(422);
        $this->register(self::$fake->attest(str_repeat('a', 54), self::APP_ID), str_repeat('a', 54))->assertStatus(422);
    }

    public function test_utgangen_challenge_nekas(): void
    {
        $ch = $this->challenge();
        $this->travel(301)->seconds();
        $this->register(self::$fake->attest($ch, self::APP_ID), $ch)->assertStatus(422);
    }

    public function test_samma_nyckel_igen_ar_ok_och_nollstaller_inte_raknaren(): void
    {
        $ch1 = $this->challenge();
        $a = self::$fake->attest($ch1, self::APP_ID);
        $this->register($a, $ch1)->assertCreated();
        AppAttestKey::sole()->update(['sign_count' => 7]);

        // Appen fick inget svar och attesterar samma nyckel igen med en ny challenge: 200, räknaren orörd.
        $ch2 = $this->challenge();
        $this->register(self::$fake->attest($ch2, self::APP_ID, ['key' => $a['privateKey']]), $ch2)->assertOk();
        $this->assertSame(7, AppAttestKey::sole()->sign_count);

        // Det gamla objektet med en ny challenge nekas (nonce).
        $ch3 = $this->challenge();
        $this->register($a, $ch3)->assertStatus(422);
    }

    public function test_forfalskad_attestering_nekas_och_inget_sparas(): void
    {
        $ch = $this->challenge();
        $this->register((new FakeAppAttest)->attest($ch, self::APP_ID), $ch)->assertStatus(422);
        $this->assertSame(0, AppAttestKey::count());
    }

    public function test_fel_form_ger_422(): void
    {
        $ch = $this->challenge();
        foreach ([[], ['keyId' => 'x', 'attestation' => 'y', 'challenge' => $ch], ['keyId' => [], 'attestation' => 1, 'challenge' => null]] as $body) {
            $this->postJson('/api/v1/games/glosis/attest/register', $body)->assertStatus(422);
        }
    }

    public function test_avstangd_gratisniva_ger_503(): void
    {
        $this->game->update(['settings' => array_merge($this->game->settings, ['free' => ['enabled' => false]])]);
        $this->postJson('/api/v1/games/glosis/attest/challenge')->assertStatus(503)->assertJson(['error' => 'free_unavailable']);
    }

    public function test_saknat_team_id_ger_503(): void
    {
        $settings = $this->game->settings;
        unset($settings['app_attest']);
        $this->game->update(['settings' => $settings]);
        $this->postJson('/api/v1/games/glosis/attest/challenge')->assertStatus(503);
    }

    public function test_registrering_ar_begransad_per_ip(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->postJson('/api/v1/games/glosis/attest/register', [])->assertStatus(422);
        }
        $this->postJson('/api/v1/games/glosis/attest/register', [])->assertStatus(429);
    }
}
```

- [ ] **Step 2: Kör och se att det fallerar**

Run: `php artisan test --filter=GlosisAttestTest`
Expected: FAIL, 404 på `/attest/challenge`

- [ ] **Step 3: Migration och modell**

```php
<?php
// database/migrations/2026_10_12_100001_create_glosis_app_attest_keys_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Glosis gratisnivå: en rad per App Attest-nyckel (en installation av appen på
 * en enhet). Ingen koppling till person, konto eller barn. Gallras efter 13
 * månader utan användning (glosis:prune-attest-keys). Bara additiv.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('glosis_app_attest_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('key_id', 44);          // base64(SHA256(publika nyckeln)), som appen skickar den
            $table->text('public_key');            // PEM ur credCert
            $table->unsignedInteger('sign_count')->default(0);
            $table->string('environment', 16);     // production | development
            $table->string('bundle_version', 64)->nullable();
            $table->unsignedInteger('validation_category')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['game_id', 'key_id']);
            $table->index('last_used_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('glosis_app_attest_keys');
    }
};
```

```php
<?php
// app/Models/AppAttestKey.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** En attesterad App Attest-nyckel (Glosis gratisnivå). Se migrationen. */
class AppAttestKey extends Model
{
    public const ENV_PRODUCTION = 'production';

    public const ENV_DEVELOPMENT = 'development';

    protected $table = 'glosis_app_attest_keys';

    protected $fillable = ['game_id', 'key_id', 'public_key', 'sign_count', 'environment', 'bundle_version', 'validation_category', 'last_used_at'];

    protected $hidden = ['public_key'];

    protected function casts(): array
    {
        return ['sign_count' => 'integer', 'validation_category' => 'integer', 'last_used_at' => 'datetime'];
    }

    /**
     * Sparar en ny räknare bara om den är större än den sparade. Ett villkorat
     * UPDATE är atomiskt i databasen: av två parallella anrop med samma
     * assertion får bara det ena 1 påverkad rad.
     */
    public function advanceSignCount(int $count): bool
    {
        $updated = static::whereKey($this->getKey())->where('sign_count', '<', $count)
            ->update(['sign_count' => $count, 'last_used_at' => now()]);
        if ($updated === 1) {
            $this->sign_count = $count;
        }

        return $updated === 1;
    }

    /** Kort hash för loggar; keyId loggas aldrig. */
    public function logHash(): string
    {
        return substr(hash('sha256', $this->key_id), 0, 16);
    }
}
```

- [ ] **Step 4: Challenges**

```php
<?php
// app/Services/Glosis/AppAttest/AttestChallenges.php
namespace App\Services\Glosis\AppAttest;

use Illuminate\Support\Facades\Cache;

/**
 * Engångs-challenges för App Attest ("Provide a challenge" i Apples artikel).
 *
 * Tillståndslösa: challengen är base64url(16 slumpbyte ‖ utfärdad tid (8 byte) ‖
 * HMAC-SHA256(nyckel, de 24 första)[0..16]), 54 tecken. Nyckeln härleds ur
 * APP_KEY. Utfärdandet skriver inget i cachen. I produktion är cachen
 * databas-store, och appen hämtar en challenge per gratisförfrågan, så varje
 * utfärdad challenge hade annars blivit en rad. Bara inlösen skriver: en
 * ":used"-markör med Cache::add (insertOrIgnore, atomisk), så av två
 * samtidiga inlösningar lyckas en. Utgångna markörer gallras av
 * glosis:prune-cache. Challengen loggas aldrig.
 */
final class AttestChallenges
{
    public const TTL_SECONDS = 300;

    /** Tillåten klockskillnad framåt (flera webbservrar). */
    private const FUTURE_SKEW_SECONDS = 30;

    public function issue(): string
    {
        $body = random_bytes(16).pack('J', now()->getTimestamp());

        return rtrim(strtr(base64_encode($body.self::mac($body)), '+/', '-_'), '=');
    }

    public function consume(mixed $challenge): bool
    {
        if (! is_string($challenge) || preg_match('/^[A-Za-z0-9_-]{54}\z/', $challenge) !== 1) {
            return false;
        }
        $raw = base64_decode(strtr($challenge, '-_', '+/'), true);
        if ($raw === false || strlen($raw) !== 40) {
            return false;
        }
        $body = substr($raw, 0, 24);
        if (! hash_equals(self::mac($body), substr($raw, 24))) {
            return false;
        }
        $issued = unpack('J', substr($body, 16, 8))[1];
        $now = now()->getTimestamp();
        if ($issued > $now + self::FUTURE_SKEW_SECONDS || $now - $issued > self::TTL_SECONDS) {
            return false;
        }

        return Cache::add('glosis-attest-used:'.hash('sha256', $challenge), 1, self::TTL_SECONDS + self::FUTURE_SKEW_SECONDS + 60);
    }

    private static function mac(string $body): string
    {
        $key = hash_hmac('sha256', 'glosis-attest-challenge', (string) config('app.key'), true);

        return substr(hash_hmac('sha256', $body, $key, true), 0, 16);
    }
}
```

- [ ] **Step 5: Inställningar** (lägg till i `GlosisSettings`)

```php
    /** Gratisnivån (beslut 2026-10-12): en skanning per nyckel och ISO-vecka. */
    public const FREE_SCAN_WEEKLY_PER_KEY = 1;

    /** Nya ord per App Attest-nyckel och Stockholmsdygn, samma tal som per köp. */
    public const TTS_DAILY_NEW_PER_KEY = 100;

    public const DEFAULT_FREE_SCAN_DAILY_LIMIT = 40;

    public const DEFAULT_FREE_SCAN_IP_DAILY_LIMIT = 10;

    public const DEFAULT_FREE_TTS_DAILY_NEW_LIMIT = 800;

    public const DEFAULT_FREE_TTS_IP_DAILY_NEW_LIMIT = 300;

    public const DEFAULT_FREE_MONTHLY_BUDGET_USD = 20;

    /** settings.free.enabled (Filament-växeln). Avslagen om den saknas. */
    public function freeEnabled(): bool
    {
        return data_get($this->settings, 'free.enabled') === true;
    }

    /** "TEAMID.se.computercat.glosis", eller null (fel i loggen) om Team ID saknas eller har fel form. */
    public function appAttestAppId(): ?string
    {
        $team = data_get($this->settings, 'app_attest.team_id');
        if (! is_string($team) || preg_match('/^[A-Z0-9]{10}\z/', $team) !== 1) {
            Log::error("Glosis: settings.app_attest.team_id saknas eller har fel form för {$this->slug}");

            return null;
        }

        return $team.'.'.AppleTransactionVerifier::BUNDLE_ID;
    }

    public function freeScanDailyLimit(): int
    {
        return (int) $this->number('free.scan_daily_limit', self::DEFAULT_FREE_SCAN_DAILY_LIMIT);
    }

    public function freeScanIpDailyLimit(): int
    {
        return (int) $this->number('free.scan_ip_daily_limit', self::DEFAULT_FREE_SCAN_IP_DAILY_LIMIT);
    }

    public function freeTtsDailyNewLimit(): int
    {
        return (int) $this->number('free.tts_daily_new_limit', self::DEFAULT_FREE_TTS_DAILY_NEW_LIMIT);
    }

    public function freeTtsIpDailyNewLimit(): int
    {
        return (int) $this->number('free.tts_ip_daily_new_limit', self::DEFAULT_FREE_TTS_IP_DAILY_NEW_LIMIT);
    }

    public function freeMonthlyBudgetMicroUsd(): int
    {
        return (int) round($this->number('free.monthly_budget_usd', self::DEFAULT_FREE_MONTHLY_BUDGET_USD) * 1_000_000);
    }
```

- [ ] **Step 6: Controller, routes och throttles**

```php
<?php
// app/Http/Controllers/Api/V1/GlosisAttestController.php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppAttestKey;
use App\Models\Game;
use App\Services\Glosis\AppAttest\AppAttestVerifier;
use App\Services\Glosis\AppAttest\AttestChallenges;
use App\Services\Glosis\AppAttest\InvalidAttestationException;
use App\Services\Glosis\GlosisSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Glosis gratisnivå: App Attest (iOS). Inga konton; en installation av appen
 * har en nyckel som Apple intygat. Flöde enligt Apples "Establishing your app's
 * integrity": challenge → attestKey(SHA256(challenge)) → register.
 */
class GlosisAttestController extends Controller
{
    private const UNAVAILABLE = 'Gratisfunktionerna är pausade just nu. Försök igen senare.';

    private const INVALID = 'Appen kunde inte kontrolleras. Försök igen om en stund.';

    public function challenge(Game $game, AttestChallenges $challenges): JsonResponse
    {
        if (! $this->available($game)) {
            return $this->error(503, 'free_unavailable', self::UNAVAILABLE);
        }

        return response()->json(['challenge' => $challenges->issue(), 'expires_in' => AttestChallenges::TTL_SECONDS]);
    }

    public function register(Request $request, Game $game, AppAttestVerifier $verifier, AttestChallenges $challenges): JsonResponse
    {
        if (! $this->available($game)) {
            return $this->error(503, 'free_unavailable', self::UNAVAILABLE);
        }
        $appId = (string) GlosisSettings::for($game)->appAttestAppId();
        $body = json_decode($request->getContent(), true, 4);
        $keyIdB64 = is_array($body) ? ($body['keyId'] ?? null) : null;
        $attB64 = is_array($body) ? ($body['attestation'] ?? null) : null;
        $challenge = is_array($body) ? ($body['challenge'] ?? null) : null;
        $keyId = is_string($keyIdB64) && strlen($keyIdB64) === 44 ? base64_decode($keyIdB64, true) : false;
        $attestation = is_string($attB64) && strlen($attB64) <= 22_000 ? base64_decode($attB64, true) : false;
        if ($keyId === false || strlen($keyId) !== 32 || $attestation === false || ! $challenges->consume($challenge)) {
            Log::info('Glosis attest: registrering med fel form eller ogiltig challenge');

            return $this->error(422, 'invalid_attestation', self::INVALID);
        }

        try {
            $attested = $verifier->verifyAttestation($attestation, $keyId, hash('sha256', $challenge, true), $appId, now()->getTimestamp(), allowDevelopment: ! app()->isProduction());
        } catch (InvalidAttestationException $e) {
            Log::warning('Glosis attest: attesteringen avvisad', ['key' => substr(hash('sha256', $keyIdB64), 0, 16), 'reason' => $e->getMessage()]);

            return $this->error(422, 'invalid_attestation', self::INVALID);
        }

        $canonical = base64_encode($attested->keyId);
        try {
            AppAttestKey::create([
                'game_id' => $game->id, 'key_id' => $canonical, 'public_key' => $attested->publicKeyPem,
                'sign_count' => 0, 'environment' => $attested->environment,
                'bundle_version' => $attested->bundleVersion, 'validation_category' => $attested->validationCategory,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Samma nyckel, nytt giltigt intyg (appen fick inget svar förra gången). Räknaren rörs inte.
            // OVERIFIERAT mot riktig enhet: Apple kan vägra attestera samma nyckel två gånger. Vägen finns
            // för säkerhets skull och är bara testad med FakeAppAttest; publika nyckeln är densamma eftersom
            // keyId = SHA256(nyckeln) och attesteringen nyss verifierats.
            return response()->json(['status' => 'registered']);
        }
        Log::info('Glosis attest: ny nyckel', [
            'key' => substr(hash('sha256', $canonical), 0, 16), 'environment' => $attested->environment,
            'bundle_version' => $attested->bundleVersion, 'validation_category' => $attested->validationCategory,
        ]);

        return response()->json(['status' => 'registered'], 201);
    }

    private function available(Game $game): bool
    {
        $settings = GlosisSettings::for($game);

        return $settings->freeEnabled() && $settings->appAttestAppId() !== null;
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['error' => $code, 'message' => $message], $status);
    }
}
```

I `routes/api.php`, direkt efter TTS-gruppen:

```php
    // Glosis gratisnivå (App Attest, iOS). Challenge per förfrågan; registrering en gång per installation.
    Route::middleware([ResolveGame::class])
        ->withoutMiddleware(\Illuminate\Routing\Middleware\SubstituteBindings::class)
        ->group(function () {
            Route::post('/games/{game}/attest/challenge', [GlosisAttestController::class, 'challenge'])
                ->where('game', 'glosis')->middleware('throttle:glosis-attest-challenge');
            Route::post('/games/{game}/attest/register', [GlosisAttestController::class, 'register'])
                ->where('game', 'glosis')->middleware('throttle:glosis-attest-register');
        });
```

I `AppServiceProvider::boot()`:

```php
        // App Attest-challenges: en per gratisförfrågan (ett pass ger ungefär fyra).
        // En skolklass delar IP, därför generöst.
        RateLimiter::for('glosis-attest-challenge', fn (Request $request) => Limit::perMinute(120)->by('glosis-attest-ch:'.$request->ip()));

        // Nya nycklar: en per installation. Det finns ingen proxy framför (nginx terminerar TLS), så ip() är
        // klientens adress; en skola bakom NAT delar den. 60/h räcker för en klass som installerar samtidigt.
        // Gränsen hindrar inte en angripare med många IP-adresser; de verkliga gränserna är taken per IP och
        // de globala gratistaken (beslut 2026-10-12).
        RateLimiter::for('glosis-attest-register', fn (Request $request) => Limit::perHour(60)->by('glosis-attest-reg:'.$request->ip()));
```

I `bootstrap/app.php`: Capacitor på Android har origin `https://localhost`, som Sanctum räknar som "stateful". Därför behöver de nya endpointarna samma CSRF-undantag som scan och tts (rad 30), och samma 429-kontrakt (rad 46):

```php
        $middleware->validateCsrfTokens(except: [
            'api/v1/interest/*/unsubscribe', 'api/v1/games/glosis/scan', 'api/v1/games/glosis/tts/prepare',
            'api/v1/games/glosis/attest/challenge', 'api/v1/games/glosis/attest/register',
        ]);
```

```php
            if ($request->is('api/v1/games/glosis/scan', 'api/v1/games/glosis/tts/prepare', 'api/v1/games/glosis/attest/*')) {
```

Lägg till i `GlosisAttestTest`:

```php
    public function test_429_har_appens_form(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->postJson('/api/v1/games/glosis/attest/register', []);
        }
        $this->postJson('/api/v1/games/glosis/attest/register', [])->assertStatus(429)
            ->assertJson(['error' => 'rate_limited'])->assertJsonStructure(['retry_after']);
    }

    public function test_csrf_galler_inte_for_appens_origin(): void
    {
        $this->withHeaders(['Origin' => 'https://localhost', 'Referer' => 'https://localhost/'])
            ->postJson('/api/v1/games/glosis/attest/challenge')->assertOk();
    }
```

- [ ] **Step 7: Kör testerna**

Run: `php artisan test --filter=GlosisAttestTest`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_10_12_100001_create_glosis_app_attest_keys_table.php app/Models/AppAttestKey.php app/Services/Glosis/AppAttest/AttestChallenges.php app/Http/Controllers/Api/V1/GlosisAttestController.php app/Services/Glosis/GlosisSettings.php app/Providers/AppServiceProvider.php bootstrap/app.php routes/api.php tests/Feature/Api/V1/GlosisAttestTest.php
git commit -m "Glosis: App Attest-registrering med engångs-challenges och nyckeltabell"
```

---

### Task 5: GlosisCaller, FreeGate och ai_usage per nivå (computercat-api)

**Files:**
- Create: `app/Services/Glosis/GlosisCaller.php`, `app/Services/Glosis/FreeGate.php`, `app/Services/Glosis/FreeRequest.php`
- Create: `database/migrations/2026_10_12_100002_add_tier_to_ai_usage_table.php`
- Modify: `app/Models/AiUsage.php` (fillable `tier`, `app_attest_key_id`, konstanter)
- Modify: `app/Services/Glosis/BudgetReservation.php`, `app/Services/Glosis/AiBudget.php` (delbudget för gratis)
- Modify: `app/Services/Glosis/StudioVoice.php` (`prepare(Game, GlosisCaller, …)`, gratisgränser)
- Modify: `app/Filament/Resources/GameResource/Pages/GameVoices.php:215` (`GlosisCaller::admin()`)
- Test: `tests/Feature/Glosis/FreeGateTest.php`, `tests/Feature/Glosis/AiBudgetFreeTest.php`

**Interfaces:**
- Consumes: `AppAttestKey::advanceSignCount`, `AttestChallenges::consume`, `AppAttestVerifier::verifyAssertion`, `GlosisSettings::free*`.
- Produces:
  - `GlosisCaller::guld(VerifiedTransaction)`, `::free(AppAttestKey, ?string $ip)`, `::admin()`. Fält `tier`, `environment`, `attestKeyId`, `ip`. Metoderna `isFree()`, `limitId()` (guld: `'tx:'.sha256(otid)`, oförändrat), `logHash()`.
  - `FreeGate::verify(mixed $attest, Game $game, string $feature, ?string $ip): FreeRequest` med `FreeRequest{GlosisCaller $caller, array $clientData}`. Kastar `GlosisRejection`: 503 `free_unavailable`, 422 `invalid_attest`, 401 `unknown_key`.
  - `FreeGate::reject(int $status, string $code, string $feature, array $extra = [], array $headers = []): GlosisRejection` med texter för `free_unavailable|invalid_attest|unknown_key|free_scan_used|free_busy`.
  - `AiBudget::reserve(Game, int, string $feature, string $tier = GlosisCaller::GULD): ?BudgetReservation`. `record(…, ?GlosisCaller $caller = null)` som sista argument. `AiBudget::spentMicroUsd(Game, DateTimeInterface, ?string $tier = null)`.
  - `AiUsage::TIER_GULD|TIER_FREE|TIER_ADMIN`.
  - `StudioVoice::prepare(Game $game, GlosisCaller $caller, string $speed, array $words, string $language, string $gender)`. `StudioVoice::adminTransaction()` tas bort, ersätts av `GlosisCaller::admin()`.

- [ ] **Step 1: Skriv de fallerande testerna**

```php
<?php
// tests/Feature/Glosis/FreeGateTest.php
namespace Tests\Feature\Glosis;

use App\Models\AppAttestKey;
use App\Models\Game;
use App\Services\Glosis\AppAttest\AppAttestVerifier;
use App\Services\Glosis\AppAttest\AttestChallenges;
use App\Services\Glosis\FreeGate;
use App\Services\Glosis\GlosisCaller;
use App\Services\Glosis\GlosisRejection;
use App\Services\Glosis\GuldGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\V1\GlosisAttestTest as A;
use Tests\Support\FakeAppAttest;
use Tests\TestCase;

class FreeGateTest extends TestCase
{
    use RefreshDatabase;

    private static ?FakeAppAttest $fake = null;

    private Game $game;

    private AppAttestKey $key;

    private \OpenSSLAsymmetricKey $priv;

    protected function setUp(): void
    {
        parent::setUp();
        self::$fake ??= new FakeAppAttest;
        $this->app->instance(AppAttestVerifier::class, new AppAttestVerifier(self::$fake->rootPem()));
        $this->game = Game::where('slug', 'glosis')->firstOrFail();
        $this->game->update(['settings' => array_merge($this->game->settings ?? [], ['free' => ['enabled' => true], 'app_attest' => ['team_id' => A::TEAM]])]);
        [$this->key, $this->priv] = self::registeredKey($this->game, self::$fake);
    }

    /** Registrerar en nyckel via verifieraren (som controllern gör). @return array{AppAttestKey, \OpenSSLAsymmetricKey} */
    public static function registeredKey(Game $game, FakeAppAttest $fake): array
    {
        $a = $fake->attest('ch', A::APP_ID);
        $att = app(AppAttestVerifier::class)->verifyAttestation(base64_decode($a['attestation']), $a['rawKeyId'], hash('sha256', 'ch', true), A::APP_ID, time(), false);
        $key = AppAttestKey::create(['game_id' => $game->id, 'key_id' => $a['keyId'], 'public_key' => $att->publicKeyPem, 'sign_count' => 0, 'environment' => 'production']);

        return [$key, $a['privateKey']];
    }

    /** @return array{keyId: string, assertion: string, clientData: string} */
    public static function credential(AppAttestKey $key, \OpenSSLAsymmetricKey $priv, string $action, array $fields, int $count, ?string $challenge = null): array
    {
        $cd = json_encode($fields + ['action' => $action, 'challenge' => $challenge ?? app(AttestChallenges::class)->issue()]);

        return ['keyId' => $key->key_id, 'assertion' => FakeAppAttest::assert($priv, $cd, A::APP_ID, $count), 'clientData' => $cd];
    }

    private function gate(): FreeGate
    {
        return app(FreeGate::class);
    }

    public function test_godkand_forfragan_ger_gratis_caller(): void
    {
        $req = $this->gate()->verify(self::credential($this->key, $this->priv, 'tts', ['words' => ['dog']], 1), $this->game, GuldGate::FEATURE_TTS, '1.2.3.4');
        $this->assertSame(GlosisCaller::FREE, $req->caller->tier);
        $this->assertSame($this->key->id, $req->caller->attestKeyId);
        $this->assertSame(['dog'], $req->clientData['words']);
        $this->assertSame(1, $this->key->fresh()->sign_count);
    }

    public function test_samma_assertion_tva_ganger_nekas(): void
    {
        $cred = self::credential($this->key, $this->priv, 'tts', [], 1);
        $this->gate()->verify($cred, $this->game, GuldGate::FEATURE_TTS, null);
        $this->assertRejected(422, 'invalid_attest', fn () => $this->gate()->verify($cred, $this->game, GuldGate::FEATURE_TTS, null));
    }

    public function test_ny_challenge_men_gammal_raknare_nekas(): void
    {
        $this->gate()->verify(self::credential($this->key, $this->priv, 'tts', [], 5), $this->game, GuldGate::FEATURE_TTS, null);
        $this->assertRejected(422, 'invalid_attest', fn () => $this->gate()->verify(self::credential($this->key, $this->priv, 'tts', [], 5), $this->game, GuldGate::FEATURE_TTS, null));
    }

    public function test_paddad_challenge_nekas_och_raknaren_rors_inte(): void
    {
        $this->assertRejected(422, 'invalid_attest', fn () => $this->gate()->verify(self::credential($this->key, $this->priv, 'tts', [], 1, str_repeat('b', 54)), $this->game, GuldGate::FEATURE_TTS, null));
        $this->assertSame(0, $this->key->fresh()->sign_count);
    }

    public function test_utgangen_challenge_nekas(): void
    {
        $cred = self::credential($this->key, $this->priv, 'tts', [], 1);
        $this->travel(301)->seconds();
        $this->assertRejected(422, 'invalid_attest', fn () => $this->gate()->verify($cred, $this->game, GuldGate::FEATURE_TTS, null));
    }

    public function test_assertion_for_annan_funktion_nekas(): void
    {
        $this->assertRejected(422, 'invalid_attest', fn () => $this->gate()->verify(self::credential($this->key, $this->priv, 'scan', [], 1), $this->game, GuldGate::FEATURE_TTS, null));
    }

    public function test_okand_nyckel_ger_401(): void
    {
        $cred = self::credential($this->key, $this->priv, 'tts', [], 1);
        $this->assertRejected(401, 'unknown_key', fn () => $this->gate()->verify(['keyId' => base64_encode(str_repeat("\x07", 32))] + $cred, $this->game, GuldGate::FEATURE_TTS, null));
    }

    public function test_fel_form_ger_422(): void
    {
        foreach ([null, 'x', [], ['keyId' => 'a', 'assertion' => 'b', 'clientData' => 'c'], ['keyId' => $this->key->key_id, 'assertion' => 'AA==', 'clientData' => str_repeat('a', 9000)]] as $bad) {
            $this->assertRejected(422, 'invalid_attest', fn () => $this->gate()->verify($bad, $this->game, GuldGate::FEATURE_TTS, null));
        }
    }

    public function test_avstangd_flagga_ger_503(): void
    {
        $this->game->update(['settings' => array_merge($this->game->settings, ['free' => ['enabled' => false]])]);
        $this->assertRejected(503, 'free_unavailable', fn () => $this->gate()->verify(self::credential($this->key, $this->priv, 'tts', [], 1), $this->game, GuldGate::FEATURE_TTS, null));
    }

    private function assertRejected(int $status, string $code, \Closure $fn): void
    {
        try {
            $fn();
            $this->fail('Ingen avvisning');
        } catch (GlosisRejection $e) {
            $this->assertSame([$status, $code], [$e->status, $e->error]);
            $this->assertStringNotContainsString('—', $e->userMessage);
        }
    }
}
```

```php
<?php
// tests/Feature/Glosis/AiBudgetFreeTest.php
namespace Tests\Feature\Glosis;

use App\Models\AiUsage;
use App\Models\Game;
use App\Services\Glosis\AiBudget;
use App\Services\Glosis\GlosisCaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiBudgetFreeTest extends TestCase
{
    use RefreshDatabase;

    public function test_gratis_stoppas_av_delbudgeten_men_guld_gar_igenom(): void
    {
        $game = Game::where('slug', 'glosis')->firstOrFail();
        $game->update(['settings' => array_merge($game->settings ?? [], ['ai' => ['monthly_budget_usd' => 10], 'free' => ['monthly_budget_usd' => 1]])]);
        $budget = app(AiBudget::class);

        $this->assertNotNull($r = $budget->reserve($game, 900_000, AiUsage::FEATURE_SCAN, GlosisCaller::FREE));
        $budget->release($r);
        $this->assertNull($budget->reserve($game, 1_100_000, AiUsage::FEATURE_SCAN, GlosisCaller::FREE));
        $this->assertNotNull($budget->reserve($game, 1_100_000, AiUsage::FEATURE_SCAN));
    }

    public function test_avslag_i_delbudgeten_lamnar_inget_reserverat_i_den_globala(): void
    {
        $game = Game::where('slug', 'glosis')->firstOrFail();
        $game->update(['settings' => array_merge($game->settings ?? [], ['ai' => ['monthly_budget_usd' => 2], 'free' => ['monthly_budget_usd' => 1]])]);
        $budget = app(AiBudget::class);
        $this->assertNull($budget->reserve($game, 1_500_000, AiUsage::FEATURE_SCAN, GlosisCaller::FREE));
        $this->assertNotNull($budget->reserve($game, 2_000_000, AiUsage::FEATURE_SCAN));
    }
}
```

- [ ] **Step 2: Kör och se att det fallerar**

Run: `php artisan test --filter='FreeGateTest|AiBudgetFreeTest'`
Expected: FAIL, `Class "App\Services\Glosis\FreeGate" not found`

- [ ] **Step 3: Migration för ai_usage**

```php
<?php
// database/migrations/2026_10_12_100002_add_tier_to_ai_usage_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * ai_usage får nivån (guld, free, admin) och, för gratis, nyckeln. Befintliga
 * rader: environment Admin → tier admin, övriga guld (allt före gratisnivån var Guld).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage', function (Blueprint $table) {
            $table->string('tier', 8)->default('guld')->after('environment');
            $table->foreignId('app_attest_key_id')->nullable()->after('tier')->constrained('glosis_app_attest_keys')->nullOnDelete();
            $table->index(['tier', 'created_at'], 'ai_usage_tier_created_at_index');
        });
        $admin = DB::table('ai_usage')->where('environment', 'Admin')->update(['tier' => 'admin']);
        Log::info('ai_usage: tier satt', ['admin' => $admin, 'guld' => DB::table('ai_usage')->where('tier', 'guld')->count()]);
    }

    public function down(): void
    {
        Schema::table('ai_usage', function (Blueprint $table) {
            $table->dropIndex('ai_usage_tier_created_at_index');
            $table->dropConstrainedForeignId('app_attest_key_id');
            $table->dropColumn('tier');
        });
    }
};
```

I `AiUsage`: lägg till `'tier', 'app_attest_key_id'` i `$fillable`, `'app_attest_key_id' => 'integer'` i casts och:

```php
    public const TIER_GULD = 'guld';

    public const TIER_FREE = 'free';

    public const TIER_ADMIN = 'admin';
```

- [ ] **Step 4: GlosisCaller, FreeRequest och FreeGate**

```php
<?php
// app/Services/Glosis/GlosisCaller.php
namespace App\Services\Glosis;

use App\Models\AppAttestKey;

/**
 * Vem som gör ett betalt anrop: ett Guld-köp, en gratisnyckel (App Attest)
 * eller provlyssningen i Filament. Gränserna, budgeten och ai_usage räknar på det.
 */
final readonly class GlosisCaller
{
    public const GULD = 'guld';

    public const FREE = 'free';

    public const ADMIN = 'admin';

    private function __construct(
        public string $tier,
        /** Underlag för räknarnycklar och logghash. Loggas aldrig i klartext. */
        private string $subject,
        /** Production, Sandbox eller Admin (som VerifiedTransaction och ai_usage.environment). */
        public string $environment,
        public ?int $attestKeyId = null,
        public ?string $ip = null,
    ) {}

    public static function guld(VerifiedTransaction $transaction): self
    {
        return new self(self::GULD, $transaction->originalTransactionId, $transaction->environment);
    }

    public static function free(AppAttestKey $key, ?string $ip): self
    {
        return new self(self::FREE, 'attest-key:'.$key->id, $key->environment === AppAttestKey::ENV_PRODUCTION ? 'Production' : 'Sandbox', $key->id, $ip);
    }

    /** Samma räknarnyckel som StudioVoice::adminTransaction() hade ('filament-preview'). */
    public static function admin(): self
    {
        return new self(self::ADMIN, 'filament-preview', 'Admin');
    }

    public function isFree(): bool
    {
        return $this->tier === self::FREE;
    }

    /** Räknarnyckelns id. Guld: 'tx:' + sha256(köpet), exakt som före gratisnivån, så att räknarna fortsätter över driftsättningen. */
    public function limitId(): string
    {
        return ($this->isFree() ? 'key:' : 'tx:').hash('sha256', $this->subject);
    }

    /** Guld: samma värde som GuldGate::txHash(). */
    public function logHash(): string
    {
        return substr(hash('sha256', $this->subject), 0, 16);
    }
}
```

```php
<?php
// app/Services/Glosis/FreeRequest.php
namespace App\Services\Glosis;

/** En godkänd gratisförfrågan: vem, och den signerade clientData (förfrågans innehåll). */
final readonly class FreeRequest
{
    /** @param array<string, mixed> $clientData */
    public function __construct(public GlosisCaller $caller, public array $clientData) {}
}
```

```php
<?php
// app/Services/Glosis/FreeGate.php
namespace App\Services\Glosis;

use App\Models\AppAttestKey;
use App\Models\Game;
use App\Services\Glosis\AppAttest\AppAttestVerifier;
use App\Services\Glosis\AppAttest\AttestChallenges;
use App\Services\Glosis\AppAttest\InvalidAttestationException;
use Illuminate\Support\Facades\Log;

/**
 * Gratisnivåns grind (beslut 2026-10-12). Appen skickar
 *   "attest": {"keyId": "<base64>", "assertion": "<base64>", "clientData": "<JSON-sträng>"}
 * där clientData = {"action": "scan"|"tts", "challenge": "<engångs>", ...förfrågans fält}.
 * clientData är det appen signerat, och förfrågans innehåll läses därifrån.
 *
 * Ordning: form → nyckeln finns → signatur, RP ID och räknare
 * (AppAttestVerifier) → challengen löses in (engångs) → räknaren sparas atomiskt.
 */
final class FreeGate
{
    private const MESSAGES = [
        GuldGate::FEATURE_SCAN => [
            'free_unavailable' => 'Gratisfotot är pausat just nu. Skriv in orden själv så länge.',
            'invalid_attest' => 'Appen kunde inte kontrolleras. Försök igen om en stund.',
            'unknown_key' => 'Appen behöver kontrolleras på nytt. Försök igen.',
            'free_scan_used' => 'Du har använt veckans gratisfoto. Nästa går att ta på måndag. Med Guldstjärnan kan du fota fler läxor.',
            'free_busy' => 'Många fotar sina läxor just nu. Försök igen i morgon, eller skriv in orden så länge.',
        ],
        GuldGate::FEATURE_TTS => [
            'free_unavailable' => 'Studiorösten är pausad just nu. Appen använder telefonens röst så länge.',
            'invalid_attest' => 'Studiorösten kunde inte starta. Appen använder telefonens röst så länge.',
            'unknown_key' => 'Studiorösten behöver starta om. Appen använder telefonens röst så länge.',
        ],
    ];

    public function __construct(
        private readonly AppAttestVerifier $verifier,
        private readonly AttestChallenges $challenges,
    ) {}

    /** @throws GlosisRejection */
    public function verify(mixed $attest, Game $game, string $feature, ?string $ip): FreeRequest
    {
        $settings = GlosisSettings::for($game);
        $appId = $settings->freeEnabled() ? $settings->appAttestAppId() : null;
        if ($appId === null) {
            throw $this->reject(503, 'free_unavailable', $feature);
        }

        $keyIdB64 = is_array($attest) ? ($attest['keyId'] ?? null) : null;
        $assertionB64 = is_array($attest) ? ($attest['assertion'] ?? null) : null;
        $clientData = is_array($attest) ? ($attest['clientData'] ?? null) : null;
        $keyId = is_string($keyIdB64) && strlen($keyIdB64) === 44 ? base64_decode($keyIdB64, true) : false;
        $assertion = is_string($assertionB64) && strlen($assertionB64) <= 4096 ? base64_decode($assertionB64, true) : false;
        $data = is_string($clientData) && strlen($clientData) <= 8192 ? json_decode($clientData, true, 8) : null;
        if ($keyId === false || strlen($keyId) !== 32 || $assertion === false || ! is_array($data) || array_is_list($data)
            || ($data['action'] ?? null) !== $feature || ! is_string($data['challenge'] ?? null)) {
            Log::info("Glosis {$feature}: gratisförfrågan med fel form");

            throw $this->reject(422, 'invalid_attest', $feature);
        }

        $key = AppAttestKey::where('game_id', $game->id)->where('key_id', base64_encode($keyId))->first();
        if ($key === null) {
            Log::info("Glosis {$feature}: okänd nyckel", ['key' => substr(hash('sha256', base64_encode($keyId)), 0, 16)]);

            throw $this->reject(401, 'unknown_key', $feature);
        }

        try {
            $count = $this->verifier->verifyAssertion($assertion, $clientData, $key->public_key, $key->sign_count, $appId);
        } catch (InvalidAttestationException $e) {
            Log::warning("Glosis {$feature}: assertion avvisad", ['key' => $key->logHash(), 'reason' => $e->getMessage()]);

            throw $this->reject(422, 'invalid_attest', $feature);
        }
        if (! $this->challenges->consume($data['challenge'])) {
            Log::info("Glosis {$feature}: challenge ogiltig, utgången eller använd", ['key' => $key->logHash()]);

            throw $this->reject(422, 'invalid_attest', $feature);
        }
        if (! $key->advanceSignCount($count)) {
            Log::warning("Glosis {$feature}: räknaren hann flyttas av ett annat anrop (replay?)", ['key' => $key->logHash()]);

            throw $this->reject(422, 'invalid_attest', $feature);
        }

        return new FreeRequest(GlosisCaller::free($key, $ip), $data);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, string>  $headers
     */
    public function reject(int $status, string $code, string $feature, array $extra = [], array $headers = []): GlosisRejection
    {
        return new GlosisRejection($status, $code, self::MESSAGES[$feature][$code], $extra, $headers);
    }
}
```

- [ ] **Step 5: Delbudget i AiBudget**

`BudgetReservation` får en tredje konstruktorparameter `public readonly ?string $freeKey = null`. I `AiBudget`:

```php
    public const FREE_KEY_PREFIX = 'glosis-ai-budget-free:';

    public static function freeMonthKey(Game $game, DateTimeInterface $now): string
    {
        return self::FREE_KEY_PREFIX.$game->id.':'.CarbonImmutable::instance($now)->setTimezone(GuldStatus::TIME_ZONE)->format('Y-m');
    }
```

I `reserve()`: ny signatur `reserve(Game $game, int $microUsd, string $feature, string $tier = GlosisCaller::GULD)`. Efter den befintliga globala kontrollen, i stället för `return new BudgetReservation($key, $microUsd);`:

```php
        if ($tier !== GlosisCaller::FREE) {
            return new BudgetReservation($key, $microUsd);
        }

        // Gratisnivåns delbudget: ryms i den globala, så att missbruk av gratis inte kan äta upp Guld-köparnas utrymme.
        $freeKey = self::freeMonthKey($game, $now);
        Cache::add($freeKey, self::spentMicroUsd($game, $now, GlosisCaller::FREE), $ttl);
        $free = Cache::increment($freeKey, $microUsd);
        $freeBudget = GlosisSettings::for($game)->freeMonthlyBudgetMicroUsd();
        if ($free === false || $free > $freeBudget) {
            if ($free !== false) {
                Cache::decrement($freeKey, $microUsd);
            }
            Cache::decrement($key, $microUsd);
            Log::warning('Glosis: gratisnivåns månadsbudget är förbrukad', [
                'feature' => $feature, 'month' => $now->format('Y-m'),
                'budget_usd' => AiCost::toUsd($freeBudget), 'counter_failed' => $free === false,
            ]);

            return null;
        }

        return new BudgetReservation($key, $microUsd, $freeKey);
```

I `record()`: lägg till parametern `?GlosisCaller $caller = null` sist. I `AiUsage::create([...])` lägger du till:

```php
            'tier' => $caller?->tier ?? ($environment === 'Admin' ? AiUsage::TIER_ADMIN : AiUsage::TIER_GULD),
            'app_attest_key_id' => $caller?->attestKeyId,
```

och efter justeringen av `$reservation->key` samma `$delta` på `$reservation->freeKey` när den inte är null. I `release()` återför du även `$reservation->freeKey`. `spentMicroUsd(Game $game, DateTimeInterface $now, ?string $tier = null)` lägger till `->when($tier, fn ($q) => $q->where('tier', $tier))`.

- [ ] **Step 6: StudioVoice räknar på GlosisCaller**

I `StudioVoice`: ta bort `adminTransaction()`. Ändra `prepare()` och `generate()` så att de tar `GlosisCaller $caller` i stället för `VerifiedTransaction $transaction`. Loggarna använder `'tx' => $caller->logHash()` och `'tier' => $caller->tier`, och miljön blir `$caller->environment`. Gränsblocket i `generate()` blir:

```php
                $now = CarbonImmutable::now(GuldStatus::TIME_ZONE);
                $day = $now->format('Y-m-d');
                $ttl = (int) ceil($now->diffInSeconds($now->addDay()->startOfDay())) + 60;
                // Guld: samma nycklar som före gratisnivån. Gratis: egen global räknare och en per IP,
                // så att gratis aldrig äter upp Guld-köparnas dagsutrymme (beslut 2026-10-12).
                // Scope-namnen 'purchase' och 'global' är desamma som i dag (loggarna, GlosisTtsTest).
                $counters = [['glosis-tts:'.$caller->limitId().':'.$day, GlosisSettings::TTS_DAILY_NEW_PER_KEY, $caller->isFree() ? 'key' : 'purchase']];
                if ($caller->isFree()) {
                    $counters[] = ['glosis-tts:free-day:'.$game->id.':'.$day, $settings->freeTtsDailyNewLimit(), 'free_global'];
                    $counters[] = ['glosis-tts:free-ip:'.hash('sha256', (string) $caller->ip).':'.$day, $settings->freeTtsIpDailyNewLimit(), 'free_ip'];
                } else {
                    $counters[] = ['glosis-tts:day:'.$game->id.':'.$day, $settings->ttsDailyNewLimit(), 'global'];
                }
                $undoCounters = function () use ($counters, $ttl) {
                    foreach ($counters as [$key]) {
                        RateLimiter::decrement($key, $ttl);
                    }
                };
                $over = null;
                foreach ($counters as [$key, $limit, $scope]) {
                    if (RateLimiter::hit($key, $ttl) > $limit) {
                        $over ??= $scope;
                    }
                }
                if ($over !== null) {
                    $undoCounters();
                    Log::info('Glosis tts: gränsen för nya ord nådd', ['tx' => $caller->logHash(), 'tier' => $caller->tier, 'scope' => $over]);

                    return 'limit';
                }
```

`TTS_DAILY_NEW_PER_PURCHASE` behålls som alias (`= self::TTS_DAILY_NEW_PER_KEY`), eftersom Filament-texten och befintliga tester använder den. Budgetanropen blir `$this->budget->reserve($game, $cost, AiUsage::FEATURE_TTS, $caller->tier)` och `$this->budget->record($game, $reservation, AiUsage::FEATURE_TTS, $caller->environment, $cost, ['characters' => $characters], $caller)`.

`GameVoices.php:215`: `$studioVoice->prepare($game, GlosisCaller::admin(), …)`. `GlosisTtsController` (tills Task 6): `$voice->prepare($game, GlosisCaller::guld($transaction), …)`.

Svep efter syskon: `/usr/bin/grep -rn "adminTransaction\|VerifiedTransaction \$transaction" app tests`. Inga träffar får finnas kvar i StudioVoice-flödet.

- [ ] **Step 7: Kör de nya testerna och hela Glosis-sviten (Guld oförändrat)**

Run: `php artisan test --filter='FreeGateTest|AiBudgetFreeTest|GlosisTtsTest|GlosisScanTest|GameVoicesTest|AiUsageReportTest'`
Expected: PASS, utan ändrade förväntningar i de befintliga testerna.

- [ ] **Step 8: Commit**

```bash
git add app/Services/Glosis app/Models/AiUsage.php database/migrations/2026_10_12_100002_add_tier_to_ai_usage_table.php app/Filament/Resources/GameResource/Pages/GameVoices.php app/Http/Controllers/Api/V1/GlosisTtsController.php tests/Feature/Glosis
git commit -m "Glosis: GlosisCaller och FreeGate; ai_usage per nivå och delbudget för gratis"
```

---

### Task 6: Gratis studioröst i TTS-endpointen (computercat-api)

**Files:**
- Modify: `app/Http/Controllers/Api/V1/GlosisTtsController.php`
- Test: `tests/Feature/Api/V1/GlosisFreeTtsTest.php`

**Interfaces:**
- Consumes: `FreeGate::verify`, `GlosisCaller::guld`, `StudioVoice::prepare(Game, GlosisCaller, …)`.
- Produces: `POST /api/v1/games/glosis/tts/prepare` med **antingen** `proof` (oförändrat) **eller** `{"attest": {keyId, assertion, clientData}}`, där clientData innehåller `speed`, `words`, `voice` och (valfritt) `language`. Båda samtidigt ger 422 `invalid_request`. Inget av dem ger 422 `invalid_proof` som i dag. Gratisfelen: 401 `unknown_key`, 422 `invalid_attest`, 503 `free_unavailable`. Svaret 200 har samma form som för Guld.

- [ ] **Step 1: Skriv de fallerande testerna**

```php
<?php
// tests/Feature/Api/V1/GlosisFreeTtsTest.php
namespace Tests\Feature\Api\V1;

use App\Models\AiUsage;
use App\Models\AppAttestKey;
use App\Models\Game;
use App\Services\Glosis\AppAttest\AppAttestVerifier;
use App\Services\Glosis\StudioVoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Glosis\FreeGateTest as G;
use Tests\Support\FakeAppAttest;
use Tests\TestCase;

class GlosisFreeTtsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/games/glosis/tts/prepare';

    private static ?FakeAppAttest $fake = null;

    private Game $game;

    private AppAttestKey $key;

    private \OpenSSLAsymmetricKey $priv;

    private int $count = 0;

    protected function setUp(): void
    {
        parent::setUp();
        self::$fake ??= new FakeAppAttest;
        $this->app->instance(AppAttestVerifier::class, new AppAttestVerifier(self::$fake->rootPem()));
        config(['app.url' => 'https://api.computercat.co']);
        Storage::fake(StudioVoice::DISK);
        Http::preventStrayRequests();
        Http::fake(['api.elevenlabs.io/*' => Http::response("\xFF\xFB\x90\x64ljud", 200, ['Content-Type' => 'audio/mpeg'])]);
        $this->game = Game::where('slug', 'glosis')->firstOrFail();
        $this->game->update(['settings' => array_merge($this->game->settings ?? [], [
            'elevenlabs' => ['api_key' => Crypt::encryptString('xi-test'), 'voice_id' => 'TestVoice123'],
            'free' => ['enabled' => true], 'app_attest' => ['team_id' => GlosisAttestTest::TEAM],
        ])]);
        [$this->key, $this->priv] = G::registeredKey($this->game, self::$fake);
    }

    private function free(array $words, array $extra = [])
    {
        return $this->postJson(self::URL, ['attest' => G::credential($this->key, $this->priv, 'tts', ['speed' => 'normal', 'voice' => 'female', 'words' => $words] + $extra, ++$this->count)]);
    }

    public function test_gratis_far_studiorost_och_bokfors_som_gratis(): void
    {
        $this->free(['dog'])->assertOk()->assertJsonStructure(['urls' => ['dog'], 'skipped', 'voice_used']);
        $row = AiUsage::sole();
        $this->assertSame('free', $row->tier);
        $this->assertSame($this->key->id, $row->app_attest_key_id);
        $this->assertSame('Production', $row->environment);
    }

    public function test_cachade_ord_raknas_inte_och_100_nya_per_nyckel_och_dygn(): void
    {
        // Bara bokstäver: 0 → "orda", 12 → "ordbc" osv.
        $word = fn (int $i) => 'ord'.strtr((string) $i, '0123456789', 'abcdefghij');
        for ($batch = 0; $batch < 3; $batch++) {
            $this->free(array_map($word, range($batch * 40, $batch * 40 + 39)))->assertOk();
        }
        // 120 olika ord: 100 genererade, 20 nekade med limit
        $this->assertSame(100, AiUsage::where('tier', 'free')->count());
        // Cachat ord: fungerar trots att gränsen nåtts, och bokförs inte.
        $this->free([$word(0)])->assertOk()->assertJsonPath('skipped', []);
        $this->assertSame(100, AiUsage::where('tier', 'free')->count());
    }

    public function test_globalt_gratistak_paverkar_inte_guld_raknaren(): void
    {
        $this->game->update(['settings' => array_merge($this->game->settings, ['free' => ['enabled' => true, 'tts_daily_new_limit' => 1]])]);
        $res = $this->free(['dog', 'cat'])->assertOk();
        $this->assertSame([['word' => 'cat', 'reason' => 'limit']], $res->json('skipped'));
        $this->assertSame(0, \Illuminate\Support\Facades\RateLimiter::attempts('glosis-tts:day:'.$this->game->id.':'.now('Europe/Stockholm')->format('Y-m-d')));
    }

    public function test_bade_proof_och_attest_ger_422(): void
    {
        $this->postJson(self::URL, ['proof' => ['platform' => 'ios', 'jws' => 'x'], 'attest' => G::credential($this->key, $this->priv, 'tts', ['speed' => 'normal', 'words' => ['dog']], 1)])
            ->assertStatus(422)->assertJson(['error' => 'invalid_request']);
    }

    public function test_ogiltiga_ord_i_client_data_ger_422_utan_anrop(): void
    {
        $this->free([str_repeat('x', 2000)])->assertStatus(422)->assertJson(['error' => 'invalid_request']);
        Http::assertNothingSent();
    }

    public function test_avstangd_flagga_ger_503_och_guld_fungerar_fortfarande(): void
    {
        $this->game->update(['settings' => array_merge($this->game->settings, ['free' => ['enabled' => false]])]);
        $this->free(['dog'])->assertStatus(503)->assertJson(['error' => 'free_unavailable']);
    }

    public function test_okand_nyckel_ger_401(): void
    {
        $this->key->delete();
        $this->free(['dog'])->assertStatus(401)->assertJson(['error' => 'unknown_key']);
    }
}
```

Guld-vägen när flaggan är av täcks redan av `GlosisTtsTest`, som inte sätter `free.enabled`.

- [ ] **Step 2: Kör och se att det fallerar**

Run: `php artisan test --filter=GlosisFreeTtsTest`
Expected: FAIL (422 `invalid_proof` eftersom `attest` inte känns igen)

- [ ] **Step 3: Implementera** (ersätt början av `prepare()` fram till `$result = …`)

```php
    public function prepare(Request $request, Game $game, GuldGate $gate, FreeGate $free, StudioVoice $voice): JsonResponse
    {
        try {
            $body = json_decode($request->getContent(), true, 16);
            $body = is_array($body) ? $body : [];
            $hasAttest = array_key_exists('attest', $body);
            if ($hasAttest && array_key_exists('proof', $body)) {
                throw new GlosisRejection(422, 'invalid_request', self::INVALID_REQUEST);
            }

            if ($hasAttest) {
                // Gratisnivån: förfrågan är clientData, som appen signerat med App Attest.
                $freeRequest = $free->verify($body['attest'], $game, GuldGate::FEATURE_TTS, $request->ip());
                $params = $freeRequest->clientData;
                $caller = $freeRequest->caller;
            } else {
                $proof = $gate->parse($body['proof'] ?? null, GuldGate::FEATURE_TTS);
                $params = $body;
                $caller = null;
            }

            $speed = $params['speed'] ?? null;
            $words = $params['words'] ?? null;
            $language = array_key_exists('language', $params) ? $params['language'] : ElevenLabsClient::LANGUAGE;
            $gender = array_key_exists('voice', $params) ? $params['voice'] : GlosisSettings::DEFAULT_GENDER;
            if (! is_string($speed) || ! array_key_exists($speed, StudioVoice::SPEEDS) || ! self::validWords($words)
                || ! is_string($language) || ! array_key_exists($language, GlosisSettings::LANGUAGES)
                || ! is_string($gender) || ! array_key_exists($gender, GlosisSettings::GENDERS)) {
                throw new GlosisRejection(422, 'invalid_request', self::INVALID_REQUEST);
            }

            $caller ??= GlosisCaller::guld($gate->verify($proof, $game, GuldGate::FEATURE_TTS));
            $result = $voice->prepare($game, $caller, $speed, $words, $language, $gender);
        } catch (GlosisRejection $e) {
            return $e->toResponse();
        }
```

med `private const INVALID_REQUEST = 'Något blev fel med studiorösten. Appen använder telefonens röst så länge.';` (samma text som i dag). Uppdatera docblocken med gratisformen och felkoderna.

En gratisförfrågan med ogiltiga ord har redan förbrukat sin challenge och räknare. Det är avsiktligt: förfrågan var signerad, och appen hämtar en ny challenge nästa gång.

- [ ] **Step 4: Kör testerna**

Run: `php artisan test --filter='GlosisFreeTtsTest|GlosisTtsTest'`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/V1/GlosisTtsController.php tests/Feature/Api/V1/GlosisFreeTtsTest.php
git commit -m "Glosis: studiorösten för gratisanvändare via App Attest"
```

---

### Task 7: Veckans gratisfoto i skanningen (computercat-api)

**Files:**
- Modify: `app/Http/Controllers/Api/V1/GlosisScanController.php`
- Test: `tests/Feature/Api/V1/GlosisFreeScanTest.php`

**Interfaces:**
- Consumes: `FreeGate`, `GlosisCaller`, `AiBudget::reserve(…, $tier)`, `record(…, $caller)`.
- Produces: `POST /api/v1/games/glosis/scan`, multipart med `image` och **antingen** `proof` (oförändrat) **eller** `attest` (JSON-sträng `{keyId, assertion, clientData}`), där clientData = `{"action":"scan","challenge":…,"imageSha256":"<hex av de uppladdade bytesen>"}`. Nya fel: 429 `free_scan_used` (`retry_after`, `Retry-After` = sekunder till måndag 00:00 Stockholm), 429 `free_busy` (globalt tak eller IP-tak), 401 `unknown_key`, 422 `invalid_attest` (också när bildens hash inte stämmer), 503 `free_unavailable`.
- Veckoräknaren per nyckel återförs om Claude inte kunde läsa bilden (`scan_failed`) eller om inga ord hittades, så att barnet inte förlorar veckans foto på en suddig bild. Den globala räknaren och IP-räknaren återförs inte.

- [ ] **Step 1: Skriv de fallerande testerna**

```php
<?php
// tests/Feature/Api/V1/GlosisFreeScanTest.php
namespace Tests\Feature\Api\V1;

use App\Models\AiUsage;
use App\Models\AppAttestKey;
use App\Models\Game;
use App\Services\Glosis\AppAttest\AppAttestVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\Feature\Glosis\FreeGateTest as G;
use Tests\Support\FakeAppAttest;
use Tests\TestCase;

class GlosisFreeScanTest extends TestCase
{
    use RefreshDatabase;

    private static ?FakeAppAttest $fake = null;

    private Game $game;

    private AppAttestKey $key;

    private \OpenSSLAsymmetricKey $priv;

    private int $count = 0;

    protected function setUp(): void
    {
        parent::setUp();
        self::$fake ??= new FakeAppAttest;
        $this->app->instance(AppAttestVerifier::class, new AppAttestVerifier(self::$fake->rootPem()));
        // Claude-svaret fejkas på samma sätt som i GlosisScanTest (FakeClaudeTransport); kopiera dess setUp-del för Anthropic-nyckel och transport.
        $this->game = Game::where('slug', 'glosis')->firstOrFail();
        $this->game->update(['settings' => array_merge($this->game->settings ?? [], ['free' => ['enabled' => true], 'app_attest' => ['team_id' => GlosisAttestTest::TEAM]])]);
        [$this->key, $this->priv] = G::registeredKey($this->game, self::$fake);
        Carbon::setTestNow(Carbon::parse('2026-10-14 12:00', 'Europe/Stockholm'));
    }

    private function scan(?string $hashOverride = null, ?AppAttestKey $key = null, ?\OpenSSLAsymmetricKey $priv = null)
    {
        $image = UploadedFile::fake()->image('laxa.jpg', 800, 600);
        $hash = $hashOverride ?? hash_file('sha256', $image->getRealPath());
        $cred = G::credential($key ?? $this->key, $priv ?? $this->priv, 'scan', ['imageSha256' => $hash], ++$this->count);

        return $this->post('/api/v1/games/glosis/scan', ['image' => $image, 'attest' => json_encode($cred)], ['Accept' => 'application/json']);
    }

    public function test_en_gratisskanning_per_vecka(): void
    {
        $this->scan()->assertOk();
        $res = $this->scan()->assertStatus(429)->assertJson(['error' => 'free_scan_used']);
        $this->assertSame((string) $res->json('retry_after'), $res->headers->get('Retry-After'));
        $this->assertStringContainsString('måndag', $res->json('message'));
        $this->assertSame(1, AiUsage::where('tier', 'free')->where('feature', 'scan')->count());
    }

    public function test_ny_vecka_ger_ny_skanning(): void
    {
        $this->scan()->assertOk();
        Carbon::setTestNow(Carbon::parse('2026-10-19 00:00:30', 'Europe/Stockholm'));
        $this->scan()->assertOk();
    }

    public function test_bildens_hash_maste_stamma(): void
    {
        $this->scan(str_repeat('0', 64))->assertStatus(422)->assertJson(['error' => 'invalid_attest']);
        $this->scan()->assertOk(); // veckans skanning är inte förbrukad
    }

    public function test_globalt_gratistak(): void
    {
        $this->game->update(['settings' => array_merge($this->game->settings, ['free' => ['enabled' => true, 'scan_daily_limit' => 1]])]);
        $this->scan()->assertOk();
        [$key2, $priv2] = G::registeredKey($this->game, self::$fake);
        $this->scan(key: $key2, priv: $priv2)->assertStatus(429)->assertJson(['error' => 'free_busy']);
    }

    public function test_ip_tak(): void
    {
        $this->game->update(['settings' => array_merge($this->game->settings, ['free' => ['enabled' => true, 'scan_ip_daily_limit' => 1]])]);
        $this->scan()->assertOk();
        [$key2, $priv2] = G::registeredKey($this->game, self::$fake);
        $this->scan(key: $key2, priv: $priv2)->assertStatus(429)->assertJson(['error' => 'free_busy']);
    }

    public function test_olasbar_bild_forbrukar_inte_veckans_foto(): void
    {
        // Fejka att Claude inte kan läsa bilden (ScanFailedException), som i GlosisScanTest.
        $this->scan()->assertStatus(502);
        // Fejka ett lyckat svar.
        $this->scan()->assertOk();
    }

    public function test_bade_proof_och_attest_ger_422(): void
    {
        $this->post('/api/v1/games/glosis/scan', ['image' => UploadedFile::fake()->image('a.jpg'), 'proof' => '{}', 'attest' => '{}'], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJson(['error' => 'invalid_request']);
    }
}
```

Fejkningen av Claude kopieras ordagrant från `GlosisScanTest::setUp` (`FakeClaudeTransport`). Läs den filen och använd samma hjälpare, så att testet inte hittar på ett eget sätt.

- [ ] **Step 2: Kör och se att det fallerar**

Run: `php artisan test --filter=GlosisFreeScanTest`
Expected: FAIL (422 `invalid_proof`)

- [ ] **Step 3: Implementera**

I `__invoke` och `scan()` lägger du till `FreeGate $free` och `Request $request` (som redan finns). Steg 1–4 i `scan()` blir:

```php
        $attestRaw = $request->input('attest');
        if ($attestRaw !== null && $request->has('proof')) {
            return $this->error(422, 'invalid_request', 'Något blev fel. Försök igen.');
        }
        $isFree = $attestRaw !== null;

        // 1. Köpbeviset eller gratisnyckeln, formen
        $storeProof = null;
        if (! $isFree) {
            $rawProof = $request->input('proof');
            $proof = is_string($rawProof) && strlen($rawProof) <= 20_000 ? json_decode($rawProof, true) : null;
            $storeProof = $gate->parse($proof, GuldGate::FEATURE_SCAN);
        }

        // 2. Bilden
        $image = $this->readImage($request->file('image'));
        if ($image === null) {
            return $this->error(422, 'invalid_image', 'Bilden gick inte att använda. Ta en ny bild (jpg, png eller webp, högst 5 MB).');
        }
        [$imageBytes, $mime] = $image;

        // 3–4. Guld: köpet. Gratis: App Attest, och att bilden är den som signerades.
        if ($isFree) {
            $attest = is_string($attestRaw) && strlen($attestRaw) <= 20_000 ? json_decode($attestRaw, true, 4) : null;
            $freeRequest = $free->verify($attest, $game, GuldGate::FEATURE_SCAN, $request->ip());
            $signed = $freeRequest->clientData['imageSha256'] ?? null;
            if (! is_string($signed) || ! hash_equals(hash('sha256', $imageBytes), strtolower($signed))) {
                Log::warning('Glosis scan: bilden är inte den som signerades', ['key' => $freeRequest->caller->logHash()]);

                throw $free->reject(422, 'invalid_attest', GuldGate::FEATURE_SCAN);
            }
            $caller = $freeRequest->caller;
            $transaction = null;
        } else {
            $transaction = $gate->verify($storeProof, $game, GuldGate::FEATURE_SCAN);
            $caller = GlosisCaller::guld($transaction);
        }
        $txHash = $caller->logHash();
```

Steg 6 får tier: `$budget->reserve($game, AiCost::SCAN_RESERVE_MICRO_USD, AiUsage::FEATURE_SCAN, $caller->tier)`. Steg 7 delas upp. Guld-grenen är exakt den befintliga koden. För gratis:

```php
        $refundWeekly = null;
        if ($caller->isFree()) {
            $refundWeekly = $this->freeLimits($game, $caller, $budget, $reservation, $free, $nowStockholm, $secondsToMonday);
        } else {
            // Befintlig veckogräns per köp, oförändrad.
        }
```

```php
    /**
     * Gratisgränserna. Räknas först och avgörs på värdet hit() returnerar, som
     * Guld-gränsen. Returnerar veckoräknarens nyckel, så att den kan återföras
     * om bilden inte gick att läsa.
     *
     * @throws GlosisRejection 429 free_scan_used | free_busy
     */
    private function freeLimits(Game $game, GlosisCaller $caller, AiBudget $budget, BudgetReservation $reservation, FreeGate $free, CarbonImmutable $now, int $secondsToMonday): string
    {
        $settings = GlosisSettings::for($game);
        $day = $now->format('Y-m-d');
        $dayTtl = (int) ceil($now->diffInSeconds($now->addDay()->startOfDay())) + 60;
        $weekKey = 'glosis-scan:free:'.$caller->limitId().':'.$now->format('o-\WW');
        $globalKey = 'glosis-scan:free-day:'.$game->id.':'.$day;
        $ipKey = 'glosis-scan:free-ip:'.hash('sha256', (string) $caller->ip).':'.$day;

        if (RateLimiter::hit($weekKey, $secondsToMonday + 60) > GlosisSettings::FREE_SCAN_WEEKLY_PER_KEY) {
            RateLimiter::decrement($weekKey, $secondsToMonday + 60);
            $budget->release($reservation);
            Log::info('Glosis scan: veckans gratisfoto använt', ['key' => $caller->logHash()]);

            throw $free->reject(429, 'free_scan_used', GuldGate::FEATURE_SCAN, ['retry_after' => $secondsToMonday], ['Retry-After' => (string) $secondsToMonday]);
        }
        $globalOver = RateLimiter::hit($globalKey, $dayTtl) > $settings->freeScanDailyLimit();
        $ipOver = RateLimiter::hit($ipKey, $dayTtl) > $settings->freeScanIpDailyLimit();
        if ($globalOver || $ipOver) {
            RateLimiter::decrement($weekKey, $secondsToMonday + 60);
            RateLimiter::decrement($globalKey, $dayTtl);
            RateLimiter::decrement($ipKey, $dayTtl);
            $budget->release($reservation);
            Log::warning('Glosis scan: gratistaket nått', ['key' => $caller->logHash(), 'scope' => $globalOver ? 'global' : 'ip']);

            throw $free->reject(429, 'free_busy', GuldGate::FEATURE_SCAN);
        }

        return $weekKey;
    }
```

I `catch (ScanFailedException $e)` och efter ett lyckat svar med `count($result->words) === 0`: `if ($refundWeekly !== null) { RateLimiter::decrement($refundWeekly, $secondsToMonday + 60); }`. `recordUsage()` tar `GlosisCaller $caller` och skickar `$caller->environment` och `$caller` till `$budget->record(…)`. Loggarna använder `'environment' => $caller->environment` och `'tier' => $caller->tier`.

Svep: `/usr/bin/grep -n "transaction->" app/Http/Controllers/Api/V1/GlosisScanController.php`. Varje träff efter steg 4 ska använda `$caller`.

- [ ] **Step 4: Kör testerna**

Run: `php artisan test --filter='GlosisFreeScanTest|GlosisScanTest'`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/V1/GlosisScanController.php tests/Feature/Api/V1/GlosisFreeScanTest.php
git commit -m "Glosis: en gratisskanning per vecka och nyckel, med globalt tak och IP-tak"
```

---

### Task 8: Filament, invarianter och gallring (computercat-api)

**Files:**
- Modify: `app/Filament/Resources/GameResource.php` (ny sektion efter "Glosis: AI-kostnader")
- Modify: `app/Services/Glosis/AiUsageReport.php`, `resources/views/filament/pages/ai-usage-overview.blade.php`
- Create: `app/Console/Commands/CheckGlosisFreeUsage.php`, `app/Console/Commands/PruneGlosisAttestKeys.php`, `app/Console/Commands/PruneGlosisCache.php`
- Modify: `routes/console.php`
- Modify: `app/Http/Controllers/Api/V1/RemoteConfigController.php` (gratisflaggan till appen)
- Test: `tests/Feature/Glosis/FreeUsageInvariantTest.php`, `tests/Feature/Glosis/AiUsageReportTest.php` (nytt fall), `tests/Feature/Api/V1/GlosisRemoteConfigTest.php`

**Interfaces:**
- Produces: `GET /api/v1/games/glosis/config` → `data.glosis_free_enabled: bool` (finns bara för glosis). `php artisan glosis:check-free-usage` (exit 1 och `Log::error` vid brott), `php artisan glosis:prune-attest-keys --months=13 [--dry-run]`, `AiUsageReport::build()` får `month_free_micro_usd` och `free_budget_micro_usd`.

Invarianter (kontrolleras för i dag och i går, Stockholm):
1. Varje `ai_usage`-rad med `tier = free` har `app_attest_key_id`.
2. Antalet gratis TTS-rader per dygn ≤ `freeTtsDailyNewLimit()`, och per nyckel och dygn ≤ 100.
3. Antalet gratis skanningsrader per dygn ≤ `freeScanDailyLimit()`.
4. Gratisnivåns kostnad hittills i månaden ≤ `freeMonthlyBudgetMicroUsd()`.

- [ ] **Step 1: Skriv de fallerande testerna**

```php
<?php
// tests/Feature/Glosis/FreeUsageInvariantTest.php
namespace Tests\Feature\Glosis;

use App\Models\AiUsage;
use App\Models\AppAttestKey;
use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FreeUsageInvariantTest extends TestCase
{
    use RefreshDatabase;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();
        $this->game = Game::where('slug', 'glosis')->firstOrFail();
    }

    private function row(array $attrs): void
    {
        AiUsage::create($attrs + ['game_id' => $this->game->id, 'feature' => 'tts', 'environment' => 'Production', 'units' => 1, 'est_cost_usd' => '0.000200', 'tier' => 'free']);
    }

    private function key(): AppAttestKey
    {
        return AppAttestKey::create(['game_id' => $this->game->id, 'key_id' => base64_encode(random_bytes(32)), 'public_key' => 'x', 'environment' => 'production']);
    }

    public function test_gront_nar_allt_stammer(): void
    {
        $this->row(['app_attest_key_id' => $this->key()->id]);
        $this->artisan('glosis:check-free-usage')->assertExitCode(0);
    }

    public function test_gratisrad_utan_nyckel_larmar(): void
    {
        $this->row(['app_attest_key_id' => null]);
        $this->artisan('glosis:check-free-usage')->expectsOutputToContain('utan nyckel')->assertExitCode(1);
    }

    public function test_fler_an_100_ord_per_nyckel_larmar(): void
    {
        $id = $this->key()->id;
        for ($i = 0; $i < 101; $i++) {
            $this->row(['app_attest_key_id' => $id]);
        }
        $this->artisan('glosis:check-free-usage')->expectsOutputToContain('per nyckel')->assertExitCode(1);
    }

    public function test_gallring_raknar_forst_och_raderar_bara_gamla(): void
    {
        $old = $this->key();
        $old->forceFill(['last_used_at' => now()->subMonths(14)])->save();
        $fresh = $this->key();
        $fresh->forceFill(['last_used_at' => now()])->save();

        $this->artisan('glosis:prune-attest-keys --months=13 --dry-run')->expectsOutputToContain('1 nyckel')->assertExitCode(0);
        $this->assertSame(2, AppAttestKey::count());
        $this->artisan('glosis:prune-attest-keys --months=13')->assertExitCode(0);
        $this->assertSame([$fresh->id], AppAttestKey::pluck('id')->all());
    }
}
```

- [ ] **Step 2: Kör och se att det fallerar**

Run: `php artisan test --filter=FreeUsageInvariantTest`
Expected: FAIL, kommandot finns inte

- [ ] **Step 3: Implementera kommandona**

```php
<?php
// app/Console/Commands/CheckGlosisFreeUsage.php
namespace App\Console\Commands;

use App\Models\AiUsage;
use App\Models\Game;
use App\Services\Glosis\AiBudget;
use App\Services\Glosis\GlosisSettings;
use App\Services\Glosis\GuldStatus;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Invarianterna för Glosis gratisnivå (planen 2026-10-12). Larmar i loggen och med exit 1.
 *
 * Taken läses som de är när kommandot körs. Sänks ett tak i Filament mitt på dagen kan dagens
 * redan bokförda rader ligga över det nya taket och ge ett falskt larm; kontrollera då mot
 * taket som gällde. Höjs ett tak blir kontrollen bara snällare.
 */
class CheckGlosisFreeUsage extends Command
{
    protected $signature = 'glosis:check-free-usage';

    protected $description = 'Kontrollerar att gratisnivåns användning håller sig inom gränserna och är kopplad till nycklar';

    public function handle(): int
    {
        $game = Game::where('slug', 'glosis')->firstOrFail();
        $settings = GlosisSettings::for($game);
        $now = CarbonImmutable::now(GuldStatus::TIME_ZONE);
        $tz = config('app.timezone');
        $problems = [];

        foreach ([$now->startOfDay(), $now->subDay()->startOfDay()] as $day) {
            $range = [$day->setTimezone($tz), $day->addDay()->setTimezone($tz)];
            $free = fn () => AiUsage::where('game_id', $game->id)->where('tier', AiUsage::TIER_FREE)->where('created_at', '>=', $range[0])->where('created_at', '<', $range[1]);
            $d = $day->format('Y-m-d');

            if (($n = $free()->whereNull('app_attest_key_id')->count()) > 0) {
                $problems[] = "{$d}: {$n} gratisrader utan nyckel";
            }
            if (($n = $free()->where('feature', AiUsage::FEATURE_TTS)->count()) > $settings->freeTtsDailyNewLimit()) {
                $problems[] = "{$d}: {$n} nya gratisord, taket är {$settings->freeTtsDailyNewLimit()}";
            }
            $perKey = $free()->where('feature', AiUsage::FEATURE_TTS)->selectRaw('app_attest_key_id, count(*) as n')->groupBy('app_attest_key_id')->having('n', '>', GlosisSettings::TTS_DAILY_NEW_PER_KEY)->get()->count();
            if ($perKey > 0) {
                $problems[] = "{$d}: {$perKey} nycklar över ".GlosisSettings::TTS_DAILY_NEW_PER_KEY.' nya ord per nyckel';
            }
            if (($n = $free()->where('feature', AiUsage::FEATURE_SCAN)->count()) > $settings->freeScanDailyLimit()) {
                $problems[] = "{$d}: {$n} gratisskanningar, taket är {$settings->freeScanDailyLimit()}";
            }
        }
        $spent = AiBudget::spentMicroUsd($game, $now, AiUsage::TIER_FREE);
        if ($spent > $settings->freeMonthlyBudgetMicroUsd()) {
            $problems[] = 'gratisnivåns månadskostnad '.($spent / 1_000_000).' USD är över delbudgeten';
        }

        foreach ($problems as $p) {
            $this->error($p);
        }
        if ($problems !== []) {
            Log::error('Glosis: gratisnivåns invarianter bryts', ['problems' => $problems]);

            return self::FAILURE;
        }
        $this->info('Gratisnivåns invarianter håller.');

        return self::SUCCESS;
    }
}
```

```php
<?php
// app/Console/Commands/PruneGlosisAttestKeys.php
namespace App\Console\Commands;

use App\Models\AppAttestKey;
use Illuminate\Console\Command;

/** Gallrar App Attest-nycklar som inte använts på --months månader (integritetspolicyn). Räknar först. */
class PruneGlosisAttestKeys extends Command
{
    protected $signature = 'glosis:prune-attest-keys {--months=13} {--dry-run}';

    protected $description = 'Raderar Glosis App Attest-nycklar som inte använts på länge';

    public function handle(): int
    {
        $months = max(1, (int) $this->option('months'));
        $cutoff = now()->subMonths($months);
        $ids = AppAttestKey::where(fn ($q) => $q->where('last_used_at', '<', $cutoff)
            ->orWhere(fn ($q) => $q->whereNull('last_used_at')->where('created_at', '<', $cutoff)))->pluck('id');
        $total = AppAttestKey::count();
        $this->info(sprintf('%d nyckel/nycklar av %d oanvända sedan %s.', $ids->count(), $total, $cutoff->toDateString()));
        if ($this->option('dry-run') || $ids->isEmpty()) {
            return self::SUCCESS;
        }
        // Raderar exakt de id:n som räknades ovan.
        $deleted = AppAttestKey::whereIn('id', $ids)->delete();
        $this->info("Raderade {$deleted}.");

        return self::SUCCESS;
    }
}
```

```php
<?php
// app/Console/Commands/PruneGlosisCache.php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Databascachen (CACHE_STORE=database i produktion) tar aldrig bort utgångna
 * rader själv. Gratisnivån skriver räknare per nyckel, IP och dygn och en
 * ":used"-markör per inlöst challenge, så de utgångna raderna gallras här.
 * Bara Glosis egna nycklar (prefix + "glosis-") och bara utgångna. Räknar först.
 */
class PruneGlosisCache extends Command
{
    protected $signature = 'glosis:prune-cache {--dry-run}';

    protected $description = 'Raderar utgångna Glosis-rader i databascachen';

    public function handle(): int
    {
        if (config('cache.default') !== 'database') {
            $this->info('Cachen är inte databas-store, inget att gallra.');

            return self::SUCCESS;
        }
        $table = config('cache.stores.database.table', 'cache');
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], config('cache.prefix').'glosis-').'%';
        $query = fn () => DB::table($table)->where('key', 'like', $like)->where('expiration', '<', now()->getTimestamp());
        $count = $query()->count();
        $this->info("{$count} utgångna Glosis-rader i cachen.");
        if ($this->option('dry-run') || $count === 0) {
            return self::SUCCESS;
        }
        $deleted = $query()->delete();
        $this->info("Raderade {$deleted}.");

        return self::SUCCESS;
    }
}
```

Test (i `FreeUsageInvariantTest`, med `config(['cache.default' => 'database'])` och cachetabellen från migrationen): en utgången `glosis-`-rad, en giltig `glosis-`-rad och en utgången rad med annat prefix. Efter körningen finns bara de två senare kvar, och `--dry-run` raderar inget.

I `routes/console.php`:

```php
Schedule::command('glosis:check-free-usage')->dailyAt('06:00');
Schedule::command('glosis:prune-attest-keys --months=13')->monthlyOn(1, '04:00');
Schedule::command('glosis:prune-cache')->hourly();
```

- [ ] **Step 4: Filament-sektionen**

```php
            Forms\Components\Section::make('Glosis: gratisnivå')
                ->description('Studioröst och ett foto i veckan utan Guldstjärnan, skyddat med Apple App Attest (iOS). Stäng av här för att stoppa allt gratis direkt; Guld påverkas inte.')
                ->collapsed()
                ->visible(fn (?Game $record) => $record?->slug === 'glosis')
                ->schema([
                    Forms\Components\Toggle::make('settings.free.enabled')
                        ->label('Gratisnivån på')
                        ->helperText('Av = appen får 503 free_unavailable och visar telefonens röst och "pausat" för fotot.'),
                    Forms\Components\TextInput::make('settings.app_attest.team_id')
                        ->label('Apple Team ID')
                        ->regex('/^[A-Z0-9]{10}$/')
                        ->helperText('10 tecken, Apple Developer → Membership. Krävs för App Attest (RP ID = Team ID + bundle-id).'),
                    Forms\Components\TextInput::make('settings.free.scan_daily_limit')->label('Gratisskanningar per dygn (alla)')->integer()->minValue(0)
                        ->placeholder((string) GlosisSettings::DEFAULT_FREE_SCAN_DAILY_LIMIT)->helperText('Per nyckel gäller alltid 1 i veckan.'),
                    Forms\Components\TextInput::make('settings.free.scan_ip_daily_limit')->label('Gratisskanningar per IP och dygn')->integer()->minValue(0)
                        ->placeholder((string) GlosisSettings::DEFAULT_FREE_SCAN_IP_DAILY_LIMIT),
                    Forms\Components\TextInput::make('settings.free.tts_daily_new_limit')->label('Nya gratisord per dygn (alla)')->integer()->minValue(0)
                        ->placeholder((string) GlosisSettings::DEFAULT_FREE_TTS_DAILY_NEW_LIMIT)->helperText('Egen räknare, äter inte av Guld-taket. Per nyckel gäller alltid '.GlosisSettings::TTS_DAILY_NEW_PER_KEY.'.'),
                    Forms\Components\TextInput::make('settings.free.tts_ip_daily_new_limit')->label('Nya gratisord per IP och dygn')->integer()->minValue(0)
                        ->placeholder((string) GlosisSettings::DEFAULT_FREE_TTS_IP_DAILY_NEW_LIMIT),
                    Forms\Components\TextInput::make('settings.free.monthly_budget_usd')->label('Gratisnivåns månadsbudget (USD)')->numeric()->minValue(0)->step(0.01)
                        ->placeholder((string) GlosisSettings::DEFAULT_FREE_MONTHLY_BUDGET_USD)->helperText('Ryms i den gemensamma månadsbudgeten.'),
                ]),
```

I `AiUsageReport::build()`: lägg till `'tier'` i `select` och räkna `$monthFree += $micro` när `$row->tier === 'free'` och raden ligger i månaden. Returnera `'month_free_micro_usd' => $monthFree` och `'free_budget_micro_usd' => GlosisSettings::for($game)->freeMonthlyBudgetMicroUsd()`. I vyn, under raden med månadstotalen: `Varav gratisnivån: {{ $usd($report['month_free_micro_usd']) }} av {{ $usd($report['free_budget_micro_usd']) }}`. Lägg ett testfall i `AiUsageReportTest`: en gratisrad på 0,50 USD ger `month_free_micro_usd = 500000`.

- [ ] **Step 5: Gratisflaggan i remote config**

Appen döljer "Gratis en gång i veckan" och den fria studiorösten när flaggan är av (Task 11 och 12). Den befintliga publika endpointen `GET /games/{game}/config` (`RemoteConfigController`) får värdet:

```php
        // Glosis gratisnivå (Filament, settings.free.enabled). Appen döljer gratislöftena när den är av;
        // servern avgör ändå (FreeGate). Reserverat namn: en RemoteConfig-rad med samma nyckel skrivs över.
        if ($game->slug === 'glosis') {
            $data['glosis_free_enabled'] = GlosisSettings::for($game)->freeEnabled();
        }
```

```php
<?php
// tests/Feature/Api/V1/GlosisRemoteConfigTest.php
namespace Tests\Feature\Api\V1;

use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlosisRemoteConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_gratisflaggan_syns_i_config(): void
    {
        $this->getJson('/api/v1/games/glosis/config')->assertOk()->assertJsonPath('data.glosis_free_enabled', false);
        $game = Game::where('slug', 'glosis')->firstOrFail();
        $game->update(['settings' => array_merge($game->settings ?? [], ['free' => ['enabled' => true]])]);
        $this->getJson('/api/v1/games/glosis/config')->assertOk()->assertJsonPath('data.glosis_free_enabled', true);
    }
}
```

Svep: andra spel får inte nyckeln (`/usr/bin/grep -rn "games/.*/config" tests` och kör deras tester).

- [ ] **Step 6: Kör hela sviten**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Console/Commands routes/console.php app/Filament app/Services/Glosis/AiUsageReport.php resources/views/filament/pages/ai-usage-overview.blade.php app/Http/Controllers/Api/V1/RemoteConfigController.php tests/Feature/Glosis tests/Feature/Api/V1/GlosisRemoteConfigTest.php
git commit -m "Glosis: Filament-inställningar för gratisnivån, invariantkontroll och gallring av nycklar"
```

---

### Task 9: Native App Attest-plugin och entitlement (glosis)

**Files:**
- Create: `apps/app/ios/App/App/GlosisAppAttestPlugin.swift`
- Create: `apps/app/ios/App/App/App.entitlements`
- Modify: `apps/app/ios/App/App.xcodeproj/project.pbxproj` (fil i Sources och grupp, `CODE_SIGN_ENTITLEMENTS` i targetets Debug och Release)
- Modify: `apps/app/ios/App/App/MainViewController.swift`
- Create: `apps/app/src/native/appattest.ts`
- Test: `apps/app/src/native/appattest.test.ts`

Kontrollerat 2026-10-12: det finns ingen `.entitlements`-fil under `apps/app/ios/App/App`, och `CODE_SIGN_ENTITLEMENTS` saknas i `project.pbxproj`.

**Interfaces:**
- Produces (JS): `GlosisAppAttest` med `isSupported(): Promise<{supported: boolean}>`, `generateKey(): Promise<{keyId: string}>`, `attestKey({keyId, challenge}): Promise<{attestation: string}>` (Swift räknar SHA256(challenge.utf8)), `generateAssertion({keyId, clientData}): Promise<{assertion: string}>` (Swift räknar SHA256(clientData.utf8)). Felkoder via `attestErrorCode(e)`: `'unsupported' | 'server_unavailable' | 'invalid_key' | 'invalid_input' | 'failed'`.

- [ ] **Step 1: Skriv det fallerande testet**

```ts
// apps/app/src/native/appattest.test.ts
import { describe, expect, it } from 'vitest';
import { APPATTEST_PLUGIN, attestErrorCode, GlosisAppAttest } from './appattest';

describe('App Attest-pluginet', () => {
  it('har samma namn som jsName i Swift', () => {
    expect(APPATTEST_PLUGIN).toBe('GlosisAppAttest');
  });

  it('säger nej på webben i stället för att kasta', async () => {
    await expect(GlosisAppAttest.isSupported()).resolves.toEqual({ supported: false });
    await expect(GlosisAppAttest.generateKey()).rejects.toMatchObject({ code: 'unsupported' });
  });

  it('läser felkoden från call.reject', () => {
    expect(attestErrorCode({ code: 'server_unavailable' })).toBe('server_unavailable');
    expect(attestErrorCode({ code: 'invalid_key' })).toBe('invalid_key');
    expect(attestErrorCode(new Error('x'))).toBe('failed');
    expect(attestErrorCode({ code: 'något annat' })).toBe('failed');
  });
});
```

- [ ] **Step 2: Kör och se att det fallerar**

Run: `pnpm --filter app test -- appattest`
Expected: FAIL, modulen saknas

- [ ] **Step 3: Implementera JS-sidan**

```ts
// apps/app/src/native/appattest.ts
import { registerPlugin } from '@capacitor/core';

/**
 * Glosis eget App Attest-plugin (ios/App/App/GlosisAppAttestPlugin.swift), DCAppAttestService.
 * Apple: https://developer.apple.com/documentation/devicecheck/establishing-your-app-s-integrity
 * Hasharna räknas i Swift: attestKey får SHA256(challenge), generateAssertion SHA256(clientData),
 * så att JS bara hanterar strängar som servern hashar på samma sätt.
 */
export interface GlosisAppAttestPlugin {
  isSupported(): Promise<{ supported: boolean }>;
  generateKey(): Promise<{ keyId: string }>;
  attestKey(opts: { keyId: string; challenge: string }): Promise<{ attestation: string }>;
  generateAssertion(opts: { keyId: string; clientData: string }): Promise<{ assertion: string }>;
}

/** Måste vara samma som `jsName` i Swift. */
export const APPATTEST_PLUGIN = 'GlosisAppAttest';

const unsupported = async (): Promise<never> => {
  throw Object.assign(new Error('App Attest finns bara i iOS-appen'), { code: 'unsupported' });
};

export const GlosisAppAttest = registerPlugin<GlosisAppAttestPlugin>(APPATTEST_PLUGIN, {
  web: { isSupported: async () => ({ supported: false }), generateKey: unsupported, attestKey: unsupported, generateAssertion: unsupported },
});

export type AttestPluginError = 'unsupported' | 'server_unavailable' | 'invalid_key' | 'invalid_input' | 'failed';
const KNOWN: readonly AttestPluginError[] = ['unsupported', 'server_unavailable', 'invalid_key', 'invalid_input'];

export function attestErrorCode(e: unknown): AttestPluginError {
  const code = typeof e === 'object' && e !== null ? (e as { code?: unknown }).code : undefined;
  return KNOWN.find((k) => k === code) ?? 'failed';
}
```

- [ ] **Step 4: Swift, entitlement och projektfilen**

```swift
// apps/app/ios/App/App/GlosisAppAttestPlugin.swift
import Foundation
import Capacitor
import CryptoKit
import DeviceCheck

/// Apple App Attest (DCAppAttestService) för Glosis gratisnivå.
/// Apple: "Establishing your app's integrity". clientDataHash räknas här:
/// attestKey får SHA256(challengens UTF-8-bytes), generateAssertion SHA256(clientData:s UTF-8-bytes).
/// computercat-api räknar samma hashar (GlosisAttestController, FreeGate).
@objc(GlosisAppAttestPlugin)
public class GlosisAppAttestPlugin: CAPPlugin, CAPBridgedPlugin {
    public let identifier = "GlosisAppAttestPlugin"
    public let jsName = "GlosisAppAttest"
    public let pluginMethods: [CAPPluginMethod] = [
        CAPPluginMethod(name: "isSupported", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "generateKey", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "attestKey", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "generateAssertion", returnType: CAPPluginReturnPromise)
    ]

    private let service = DCAppAttestService.shared

    @objc func isSupported(_ call: CAPPluginCall) {
        call.resolve(["supported": service.isSupported])
    }

    @objc func generateKey(_ call: CAPPluginCall) {
        guard service.isSupported else { call.reject("App Attest stöds inte på enheten", "unsupported"); return }
        service.generateKey { keyId, error in
            if let keyId { call.resolve(["keyId": keyId]) } else { self.fail(call, error) }
        }
    }

    @objc func attestKey(_ call: CAPPluginCall) {
        guard let keyId = call.getString("keyId"), let challenge = call.getString("challenge"), !challenge.isEmpty else {
            call.reject("keyId och challenge krävs", "invalid_input"); return
        }
        let hash = Data(SHA256.hash(data: Data(challenge.utf8)))
        service.attestKey(keyId, clientDataHash: hash) { attestation, error in
            if let attestation { call.resolve(["attestation": attestation.base64EncodedString()]) } else { self.fail(call, error) }
        }
    }

    @objc func generateAssertion(_ call: CAPPluginCall) {
        guard let keyId = call.getString("keyId"), let clientData = call.getString("clientData"), !clientData.isEmpty else {
            call.reject("keyId och clientData krävs", "invalid_input"); return
        }
        let hash = Data(SHA256.hash(data: Data(clientData.utf8)))
        service.generateAssertion(keyId, clientDataHash: hash) { assertion, error in
            if let assertion { call.resolve(["assertion": assertion.base64EncodedString()]) } else { self.fail(call, error) }
        }
    }

    /// Apple: serverUnavailable → försök igen senare med samma nyckel; andra fel → kasta nyckeln.
    private func fail(_ call: CAPPluginCall, _ error: Error?) {
        var code = "failed"
        if let dc = error as? DCError {
            switch dc.code {
            case .serverUnavailable: code = "server_unavailable"
            case .invalidKey: code = "invalid_key"
            case .invalidInput: code = "invalid_input"
            case .featureUnsupported: code = "unsupported"
            default: code = "failed"
            }
        }
        CAPLog.print("GlosisAppAttest: \(code) \(String(describing: error))")
        call.reject(error?.localizedDescription ?? "App Attest misslyckades", code)
    }
}
```

```xml
<!-- apps/app/ios/App/App/App.entitlements -->
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
	<key>com.apple.developer.devicecheck.appattest-environment</key>
	<string>production</string>
</dict>
</plist>
```

`MainViewController.capacitorDidLoad()`: lägg till `bridge?.registerPluginInstance(GlosisAppAttestPlugin())`.

`project.pbxproj`: gör som för `GlosisStoreKitPlugin.swift` (rad 19, 35, 75 och 170):
- PBXBuildFile: `A1C0DE062FF00001000A0B01 /* GlosisAppAttestPlugin.swift in Sources */ = {isa = PBXBuildFile; fileRef = A1C0DE052FF00001000A0B01 /* GlosisAppAttestPlugin.swift */; };`
- PBXFileReference: `A1C0DE052FF00001000A0B01 /* GlosisAppAttestPlugin.swift */ = {isa = PBXFileReference; lastKnownFileType = sourcecode.swift; path = GlosisAppAttestPlugin.swift; sourceTree = "<group>"; };` och `A1C0DE072FF00001000A0B01 /* App.entitlements */ = {isa = PBXFileReference; lastKnownFileType = text.plist.entitlements; path = App.entitlements; sourceTree = "<group>"; };`
- Gruppen (rad 74–75): båda fil-id:na.
- Sources-fasen (rad 169–170): `A1C0DE062FF00001000A0B01 /* GlosisAppAttestPlugin.swift in Sources */,`
- Targetets Debug (`504EC3171FED79650016851F`) och Release (`504EC3181FED79650016851F`): `CODE_SIGN_ENTITLEMENTS = App/App.entitlements;`

- [ ] **Step 5: Kör testet och bygg iOS utan signering (som ci.yml)**

Run: `pnpm --filter app test -- appattest && pnpm --filter app cap:sync && xcodebuild -project apps/app/ios/App/App.xcodeproj -scheme App -sdk iphonesimulator -configuration Debug CODE_SIGNING_ALLOWED=NO build | tail -3`
Expected: PASS och `** BUILD SUCCEEDED **`

- [ ] **Step 6: Commit**

```bash
git add apps/app/ios/App/App/GlosisAppAttestPlugin.swift apps/app/ios/App/App/App.entitlements apps/app/ios/App/App.xcodeproj/project.pbxproj apps/app/ios/App/App/MainViewController.swift apps/app/src/native/appattest.ts apps/app/src/native/appattest.test.ts
git commit -m "Glosis: App Attest-plugin och entitlement (production)"
```

---

### Task 10: AttestService i appen (glosis)

**Files:**
- Create: `apps/app/src/services/attest.ts`
- Test: `apps/app/src/services/attest.test.ts`

**Interfaces:**
- Consumes: `GlosisAppAttestPlugin`, `attestErrorCode` (Task 9), `KeyValueStore`.
- Produces:
  - `createAttest(deps: { baseUrl: string; plugin: GlosisAppAttestPlugin; store: KeyValueStore; platform: string; fetchImpl?: typeof fetch; timeoutMs?: number; now?: () => number }): AttestService`
  - `AttestService.send<T extends AttestAnswer>(action: 'tts' | 'scan', fields: Record<string, unknown>, post: (cred: AttestCredential) => Promise<T>): Promise<T>`. Signerar och skickar **en förfrågan i taget**, eftersom räknaren i assertionen måste nå servern i ordning. Annars nekar servern den som kommer efter (`UPDATE … WHERE sign_count < ?`). Vid 422 `invalid_attest` görs ett nytt försök med en ny challenge. Vid 401 `unknown_key` glöms nyckeln.
  - `AttestService.forgetKey(): Promise<void>`
  - `AttestAnswer = { status: number; error?: unknown }`, `AttestCredential = { keyId: string; assertion: string; clientData: string }`
  - `class AttestError extends Error { code: 'unsupported' | 'free_unavailable' | 'not_ready' | 'failed' }`
  - `ATTEST_KEY = 'glosis-attest-key'`. Lagras i `KeyValueStore` (Capacitor Preferences = UserDefaults), inte i nyckelringen. Apple: nyckeln överlever inte ominstallation, och nyckelringen gör det. Ett id i nyckelringen skulle alltså peka på en nyckel som inte finns.
  - Backoff vid `serverUnavailable` från Apple (preparing-to-use-the-app-attest-service: "Be prepared to handle these errors and to have a mechanism to pull back on requests"): samma nyckel, nästa försök efter 1 min, 5 min, 30 min, 2 h och sedan 6 h.

- [ ] **Step 1: Skriv de fallerande testerna**

```ts
// apps/app/src/services/attest.test.ts
import { describe, expect, it, vi } from 'vitest';
import { ATTEST_KEY, AttestError, createAttest, type AttestCredential } from './attest';
import type { GlosisAppAttestPlugin } from '../native/appattest';

function memStore(init: Record<string, string> = {}) {
  const m = new Map(Object.entries(init));
  return { get: async (k: string) => m.get(k) ?? null, set: async (k: string, v: string) => { m.set(k, v); }, remove: async (k: string) => { m.delete(k); }, m };
}

function plugin(over: Partial<GlosisAppAttestPlugin> = {}): GlosisAppAttestPlugin {
  return {
    isSupported: vi.fn(async () => ({ supported: true })),
    generateKey: vi.fn(async () => ({ keyId: 'KEY' })),
    attestKey: vi.fn(async () => ({ attestation: 'ATT' })),
    generateAssertion: vi.fn(async ({ clientData }) => ({ assertion: `SIG(${clientData})` })),
    ...over,
  };
}

const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status });

function server(register = () => json(201, { status: 'registered' })) {
  let n = 0;
  return vi.fn(async (url: string) => {
    if (url.endsWith('/challenge')) return json(200, { challenge: `ch${++n}`, expires_in: 300 });
    if (url.endsWith('/register')) return register();
    throw new Error(url);
  });
}

const base = { baseUrl: 'https://api.test/api/v1', platform: 'ios' };
const ok = async (_c: AttestCredential) => ({ status: 200 });

describe('createAttest', () => {
  it('registrerar en gång och signerar sedan med en ny challenge per förfrågan', async () => {
    const store = memStore();
    const p = plugin();
    const a = createAttest({ ...base, plugin: p, store, fetchImpl: server() as unknown as typeof fetch });
    const sent: AttestCredential[] = [];
    const post = async (c: AttestCredential) => { sent.push(c); return { status: 200 }; };

    await a.send('tts', { words: ['dog'], challenge: 'försök att skriva över' }, post);
    await a.send('tts', { words: ['cat'] }, post);

    expect(p.generateKey).toHaveBeenCalledTimes(1);
    expect(p.attestKey).toHaveBeenCalledWith({ keyId: 'KEY', challenge: 'ch1' });
    expect(JSON.parse(sent[0].clientData)).toEqual({ words: ['dog'], action: 'tts', challenge: 'ch2' });
    expect(JSON.parse(sent[1].clientData).challenge).toBe('ch3');
    expect(sent[0].assertion).toBe(`SIG(${sent[0].clientData})`);
    expect(JSON.parse(store.m.get(ATTEST_KEY)!)).toMatchObject({ keyId: 'KEY', registered: true });
  });

  it('skickar en förfrågan i taget: nästa signeras först när förra fått svar', async () => {
    const p = plugin();
    const a = createAttest({ ...base, plugin: p, store: memStore(), fetchImpl: server() as unknown as typeof fetch });
    let release!: () => void;
    const first = a.send('tts', {}, () => new Promise((r) => { release = () => r({ status: 200 }); }));
    const second = a.send('scan', {}, ok);
    await vi.waitFor(() => expect(p.generateAssertion).toHaveBeenCalledTimes(1));
    await new Promise((r) => setTimeout(r, 10));
    expect(p.generateAssertion).toHaveBeenCalledTimes(1);
    release();
    await Promise.all([first, second]);
    expect(p.generateAssertion).toHaveBeenCalledTimes(2);
    expect(p.generateKey).toHaveBeenCalledTimes(1);
  });

  it('ett fel i en förfrågan stoppar inte kön', async () => {
    const a = createAttest({ ...base, plugin: plugin(), store: memStore(), fetchImpl: server() as unknown as typeof fetch });
    await expect(a.send('tts', {}, async () => { throw new Error('nät'); })).rejects.toThrow('nät');
    await expect(a.send('tts', {}, ok)).resolves.toEqual({ status: 200 });
  });

  it('422 invalid_attest provas en gång till med ny challenge, sedan ges svaret tillbaka', async () => {
    const a = createAttest({ ...base, plugin: plugin(), store: memStore(), fetchImpl: server() as unknown as typeof fetch });
    const post = vi.fn(async () => ({ status: 422, error: 'invalid_attest' }));
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    await expect(a.send('tts', {}, post)).resolves.toEqual({ status: 422, error: 'invalid_attest' });
    expect(post).toHaveBeenCalledTimes(2);
    expect(JSON.parse(post.mock.calls[0][0].clientData).challenge).not.toBe(JSON.parse(post.mock.calls[1][0].clientData).challenge);
  });

  it('401 unknown_key glömmer nyckeln, så att nästa förfrågan registrerar en ny', async () => {
    const store = memStore();
    const p = plugin();
    const a = createAttest({ ...base, plugin: p, store, fetchImpl: server() as unknown as typeof fetch });
    await a.send('tts', {}, async () => ({ status: 401, error: 'unknown_key' }));
    expect(store.m.has(ATTEST_KEY)).toBe(false);
    await a.send('tts', {}, ok);
    expect(p.generateKey).toHaveBeenCalledTimes(2);
  });

  it('serverUnavailable behåller nyckeln och väntar (backoff), andra fel kastar den (Apple)', async () => {
    let t = 1_000_000;
    const store = memStore();
    const p = plugin({ attestKey: vi.fn(async () => { throw Object.assign(new Error('nät'), { code: 'server_unavailable' }); }) });
    const a = createAttest({ ...base, plugin: p, store, now: () => t, fetchImpl: server() as unknown as typeof fetch });
    await expect(a.send('tts', {}, ok)).rejects.toMatchObject({ code: 'not_ready' });
    expect(JSON.parse(store.m.get(ATTEST_KEY)!)).toMatchObject({ keyId: 'KEY', registered: false, failures: 1, retryAt: t + 60_000 });

    await expect(a.send('tts', {}, ok)).rejects.toMatchObject({ code: 'not_ready' });
    expect(p.attestKey).toHaveBeenCalledTimes(1); // väntar, frågar inte Apple igen

    t += 61_000;
    (p.attestKey as ReturnType<typeof vi.fn>).mockRejectedValueOnce(Object.assign(new Error('x'), { code: 'invalid_key' }));
    await expect(a.send('tts', {}, ok)).rejects.toBeInstanceOf(AttestError);
    expect(store.m.has(ATTEST_KEY)).toBe(false);
  });

  it('underkänd registrering (422) kastar nyckeln och loggar svaret', async () => {
    const store = memStore();
    const err = vi.spyOn(console, 'error').mockImplementation(() => {});
    const a = createAttest({ ...base, plugin: plugin(), store, fetchImpl: server(() => json(422, { error: 'invalid_attestation' })) as unknown as typeof fetch });
    await expect(a.send('tts', {}, ok)).rejects.toMatchObject({ code: 'failed' });
    expect(store.m.has(ATTEST_KEY)).toBe(false);
    expect(err).toHaveBeenCalled();
  });

  it('avstängd gratisnivå ger free_unavailable', async () => {
    const f = vi.fn(async () => json(503, { error: 'free_unavailable', message: 'pausat' }));
    const a = createAttest({ ...base, plugin: plugin(), store: memStore(), fetchImpl: f as unknown as typeof fetch });
    await expect(a.send('tts', {}, ok)).rejects.toMatchObject({ code: 'free_unavailable' });
  });

  it('utan stöd (Android, simulator) anropas aldrig servern', async () => {
    const f = server();
    await expect(createAttest({ ...base, platform: 'android', plugin: plugin(), store: memStore(), fetchImpl: f as unknown as typeof fetch }).send('tts', {}, ok)).rejects.toMatchObject({ code: 'unsupported' });
    await expect(createAttest({ ...base, plugin: plugin({ isSupported: async () => ({ supported: false }) }), store: memStore(), fetchImpl: f as unknown as typeof fetch }).send('tts', {}, ok)).rejects.toMatchObject({ code: 'unsupported' });
    expect(f).not.toHaveBeenCalled();
  });

  it('trasig post i lagringen ger en ny nyckel, inte ett fel', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    const p = plugin();
    await createAttest({ ...base, plugin: p, store: memStore({ [ATTEST_KEY]: '{oops' }), fetchImpl: server() as unknown as typeof fetch }).send('tts', {}, ok);
    expect(p.generateKey).toHaveBeenCalledTimes(1);
  });
});
```

- [ ] **Step 2: Kör och se att det fallerar**

Run: `pnpm --filter app test -- attest`
Expected: FAIL, modulen saknas

- [ ] **Step 3: Implementera**

```ts
// apps/app/src/services/attest.ts
import { attestErrorCode, type GlosisAppAttestPlugin } from '../native/appattest';
import type { KeyValueStore } from '../storage';

/** Nyckelns id och om servern godkänt den. Preferences: försvinner med appen, precis som nyckeln (Apple). */
export const ATTEST_KEY = 'glosis-attest-key';
const TIMEOUT_MS = 15_000;
/** Väntetider efter Apples serverUnavailable (preparing-to-use-the-app-attest-service). */
export const ATTEST_BACKOFF_MS = [60_000, 5 * 60_000, 30 * 60_000, 2 * 3_600_000, 6 * 3_600_000];

export type AttestCredential = { keyId: string; assertion: string; clientData: string };
export type AttestAnswer = { status: number; error?: unknown };
export type AttestErrorCode = 'unsupported' | 'free_unavailable' | 'not_ready' | 'failed';

export class AttestError extends Error {
  constructor(readonly code: AttestErrorCode, message: string) {
    super(message);
    this.name = 'AttestError';
  }
}

export interface AttestService {
  send<T extends AttestAnswer>(action: 'tts' | 'scan', fields: Record<string, unknown>, post: (cred: AttestCredential) => Promise<T>): Promise<T>;
  forgetKey(): Promise<void>;
}

type Saved = { keyId: string; registered: boolean; failures?: number; retryAt?: number };
type Answer = { status: number; json: Record<string, unknown>; text: string };
const truncate = (s: string, n = 4096) => (s.length > n ? `${s.slice(0, n)}…` : s);

export type AttestDeps = {
  baseUrl: string;
  plugin: GlosisAppAttestPlugin;
  store: KeyValueStore;
  platform: string;
  fetchImpl?: typeof fetch;
  timeoutMs?: number;
  now?: () => number;
};

/** Apple App Attest mot computercat-api (POST …/games/glosis/attest/challenge och /register). */
export function createAttest({ baseUrl, plugin, store, platform, fetchImpl, timeoutMs = TIMEOUT_MS, now = Date.now }: AttestDeps): AttestService {
  const api = `${baseUrl.replace(/\/+$/, '')}/games/glosis/attest`;
  let registering: Promise<string> | null = null;
  let tail: Promise<unknown> = Promise.resolve();

  async function post(path: string, body?: unknown): Promise<Answer> {
    const abort = new AbortController();
    const timer = setTimeout(() => abort.abort(), timeoutMs);
    try {
      const res = await (fetchImpl ?? fetch)(`${api}${path}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: body === undefined ? undefined : JSON.stringify(body),
        signal: abort.signal,
      });
      const text = await res.text();
      let json: unknown;
      try { json = JSON.parse(text); } catch { json = undefined; }
      return { status: res.status, json: (typeof json === 'object' && json !== null ? json : {}) as Record<string, unknown>, text };
    } catch (e) {
      throw new AttestError('failed', abort.signal.aborted ? `inget svar inom ${timeoutMs / 1000} s` : `ingen kontakt: ${String(e)}`);
    } finally {
      clearTimeout(timer);
    }
  }

  function unavailable(r: Answer): AttestError | null {
    return r.status === 503 && r.json.error === 'free_unavailable' ? new AttestError('free_unavailable', String(r.json.message ?? 'Gratisnivån är pausad')) : null;
  }

  async function challenge(): Promise<string> {
    const r = await post('/challenge');
    const off = unavailable(r);
    if (off) throw off;
    if (r.status !== 200 || typeof r.json.challenge !== 'string') {
      console.error('computercat-api: challenge svarade med fel', { status: r.status, body: truncate(r.text) });
      throw new AttestError('failed', `challenge: HTTP ${r.status}`);
    }
    return r.json.challenge;
  }

  async function load(): Promise<Saved | null> {
    const raw = await store.get(ATTEST_KEY);
    if (!raw) return null;
    try {
      const v = JSON.parse(raw) as Partial<Saved>;
      if (typeof v.keyId === 'string' && v.keyId && typeof v.registered === 'boolean') return v as Saved;
    } catch { /* trasig post, se nedan */ }
    console.error('Glosis: trasig App Attest-post i lagringen, skapar en ny nyckel');
    await store.remove(ATTEST_KEY);
    return null;
  }

  async function register(): Promise<string> {
    if (platform !== 'ios' || !(await plugin.isSupported()).supported) throw new AttestError('unsupported', 'App Attest stöds inte på enheten');
    let saved = await load();
    if (saved?.registered) return saved.keyId;
    if (saved && (saved.retryAt ?? 0) > now()) throw new AttestError('not_ready', 'Väntar på Apples App Attest (serverUnavailable)');
    if (!saved) {
      const { keyId } = await plugin.generateKey();
      // Sparas före attesteringen: vid serverUnavailable ska samma nyckel användas nästa gång (Apple).
      saved = { keyId, registered: false };
      await store.set(ATTEST_KEY, JSON.stringify(saved));
    }
    const ch = await challenge();
    let attestation: string;
    try {
      ({ attestation } = await plugin.attestKey({ keyId: saved.keyId, challenge: ch }));
    } catch (e) {
      if (attestErrorCode(e) === 'server_unavailable') {
        const failures = (saved.failures ?? 0) + 1;
        const wait = ATTEST_BACKOFF_MS[Math.min(failures, ATTEST_BACKOFF_MS.length) - 1];
        await store.set(ATTEST_KEY, JSON.stringify({ ...saved, failures, retryAt: now() + wait }));
      } else {
        await store.remove(ATTEST_KEY);
      }
      throw new AttestError('not_ready', `attestKey: ${String(e)}`);
    }
    const r = await post('/register', { keyId: saved.keyId, attestation, challenge: ch });
    if (r.status === 200 || r.status === 201) {
      await store.set(ATTEST_KEY, JSON.stringify({ keyId: saved.keyId, registered: true }));
      return saved.keyId;
    }
    console.error('computercat-api: App Attest-registreringen nekades', { status: r.status, body: truncate(r.text) });
    const off = unavailable(r);
    if (off) throw off;
    // Apple: underkänd attestering → kasta nyckel-id:t och skapa en ny nästa gång.
    if (r.status === 422) await store.remove(ATTEST_KEY);
    throw new AttestError('failed', `register: HTTP ${r.status}`);
  }

  async function sign(action: 'tts' | 'scan', fields: Record<string, unknown>): Promise<AttestCredential> {
    registering ??= register().finally(() => { registering = null; });
    const keyId = await registering;
    const ch = await challenge();
    // action och challenge sist: fälten kan aldrig skriva över dem.
    const clientData = JSON.stringify({ ...fields, action, challenge: ch });
    try {
      const { assertion } = await plugin.generateAssertion({ keyId, clientData });
      return { keyId, assertion, clientData };
    } catch (e) {
      if (attestErrorCode(e) === 'invalid_key') await store.remove(ATTEST_KEY);
      throw new AttestError('failed', `generateAssertion: ${String(e)}`);
    }
  }

  async function attempt<T extends AttestAnswer>(action: 'tts' | 'scan', fields: Record<string, unknown>, send: (c: AttestCredential) => Promise<T>): Promise<T> {
    let answer = await send(await sign(action, fields));
    if (answer.status === 422 && answer.error === 'invalid_attest') {
      console.warn('Glosis: servern nekade assertionen, försöker en gång till med ny challenge');
      answer = await send(await sign(action, fields));
    }
    if (answer.status === 401 && answer.error === 'unknown_key') await store.remove(ATTEST_KEY);
    return answer;
  }

  return {
    send(action, fields, send) {
      // En i taget: räknaren i assertionen måste nå servern i den ordning den skapades.
      const run = tail.then(() => attempt(action, fields, send));
      tail = run.catch(() => undefined);
      return run;
    },
    forgetKey: () => store.remove(ATTEST_KEY),
  };
}
```

- [ ] **Step 4: Kör testerna**

Run: `pnpm --filter app test -- attest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add apps/app/src/services/attest.ts apps/app/src/services/attest.test.ts
git commit -m "Glosis: AttestService (nyckel, registrering, signerade förfrågningar i kö, backoff)"
```

---

### Task 11: Studioröst och fotoinläsning utan Guld i tjänsterna (glosis)

**Files:**
- Modify: `apps/app/src/services/voice.studio.ts`, `apps/app/src/services/scan.computercat.ts`, `apps/app/src/services/scan.ts`, `apps/app/src/services/index.ts`
- Create: `apps/app/src/services/freeScan.ts`, `apps/app/src/services/freeSwitch.ts`, `apps/app/src/test/services.ts`
- Modify (tester som bygger `Services` för hand, till den delade fabriken): `apps/app/src/state/AppProvider.test.tsx:11-18`, `apps/app/src/views/games/games.test.tsx:15-22`, `apps/app/src/views/training/training.test.tsx:31-38`, `apps/app/src/views/plus/plus.test.tsx:25-32`
- Test: `voice.studio.test.ts`, `scan.computercat.test.ts`, `freeScan.test.ts`, `freeSwitch.test.ts`, `index.test.ts`

**Interfaces:**
- Consumes: `AttestService`, `AttestError` (Task 10). Serverns `GET /games/glosis/config` med `data.glosis_free_enabled` (Task 8).
- Produces:
  - `StudioVoiceDeps.attest?: AttestService | null` och `ComputercatScanDeps.attest?: AttestService | null`. Med giltigt köpbevis: oförändrat. Utan köpbevis och med `attest`: gratisvägen. Svarar servern 402 `guld_required` på ett köpbevis (återbetalt eller utgånget) och `attest` finns, byter tjänsten till gratisvägen resten av sessionen och gör om förfrågan en gång. Utan båda: som i dag.
  - `ScanErrorCode` får `'free_scan_used' | 'free_unavailable' | 'free_busy' | 'attest_failed'`. Serverns `invalid_attest` och `unknown_key` blir `attest_failed`.
  - `Services` får `freeTier: boolean` (true i iOS-butiksbygget och i testbygget, false på Android), `freeScan: FreeScanHint` och `freeSwitch: FreeSwitch`.
  - `freeScan.ts`: `stockholmIsoWeek(d: Date): string` (`"2026-W42"`), `createFreeScanHint(store): FreeScanHint` med `used(now?)` och `markUsed(now?)`. Nyckel `glosis-free-scan-week`.
  - `freeSwitch.ts`: `FreeSwitch = { enabled(): boolean; subscribe(f): () => void; refresh(): Promise<void> }`, `createFreeSwitch({ baseUrl, store, fetchImpl? })` och `staticFreeSwitch(on: boolean)`. Nyckel `glosis-free-enabled`. Okänt läge räknas som på: servern avgör ändå, och ett nej syns som fel.
  - `apps/app/src/test/services.ts`: `makeTestServices(over?: Partial<Services>): Services` (mockade köp, `mockScan(0)`, tyst röst, `freeTier: true`, `staticFreeSwitch(true)`).

- [ ] **Step 1: Delad testfabrik och fallerande tester**

```ts
// apps/app/src/test/services.ts
import type { Services } from '../services';
import { createFreeScanHint } from '../services/freeScan';
import { staticFreeSwitch } from '../services/freeSwitch';
import { createMockPurchases } from '../services/purchases.mock';
import { mockScan } from '../services/scan.mock';
import type { VoiceService } from '../services/voice';
import { memoryStore } from '../storage/localStore';

export const silentVoice = (): VoiceService => ({ available: () => false, voices: () => [], speak() {}, cancel() {}, onVoicesChanged: () => () => {} });

/** Services för komponenttester. Ett nytt fält i Services läggs till här, inte i varje testfil. */
export function makeTestServices(over: Partial<Services> = {}): Services {
  const store = over.store ?? memoryStore();
  const mock = createMockPurchases(store, { delayMs: 0 });
  return {
    store, purchases: mock, mockPurchases: mock, scan: mockScan(0), voice: silentVoice(),
    speech: { available: async () => false, listen: async () => [], stop() {} },
    freeTier: true, freeScan: createFreeScanHint(store), freeSwitch: staticFreeSwitch(true),
    ...over,
  };
}
```

Byt de fyra handbyggda `services()`/`makeServices()` mot fabriken och behåll deras parametrar. Exempel `training.test.tsx`: `const makeServices = (store = memoryStore(), scan: ScanService = mockScan(0), voice: VoiceService = silentVoice()) => makeTestServices({ store, scan, voice });`. Exempel `plus.test.tsx`: `makeTestServices({ store, purchases: { ...mock, ...override }, mockPurchases: mock })`. Kör `pnpm --filter app test` och se att allt är grönt före nästa steg.

```ts
// apps/app/src/services/freeScan.test.ts
import { describe, expect, it } from 'vitest';
import { createFreeScanHint, stockholmIsoWeek } from './freeScan';
import { memoryStore } from '../storage/localStore';

describe('stockholmIsoWeek', () => {
  it.each([
    ['2026-10-12T10:00:00Z', '2026-W42'],
    ['2026-10-11T21:30:00Z', '2026-W41'], // söndag 23:30 i Stockholm
    ['2026-10-11T22:30:00Z', '2026-W42'], // måndag 00:30 i Stockholm
    ['2026-12-31T12:00:00Z', '2026-W53'],
    ['2027-01-01T12:00:00Z', '2026-W53'],
    ['2027-01-04T12:00:00Z', '2027-W01'],
  ])('%s → %s (samma som serverns o-\\WW)', (iso, week) => {
    expect(stockholmIsoWeek(new Date(iso))).toBe(week);
  });
});

describe('FreeScanHint', () => {
  it('är använd resten av veckan och ny på måndag', async () => {
    const h = createFreeScanHint(memoryStore());
    const tue = new Date('2026-10-13T08:00:00Z');
    expect(await h.used(tue)).toBe(false);
    await h.markUsed(tue);
    expect(await h.used(new Date('2026-10-18T20:00:00Z'))).toBe(true);
    expect(await h.used(new Date('2026-10-19T08:00:00Z'))).toBe(false);
  });
});
```

```ts
// apps/app/src/services/freeSwitch.test.ts
import { describe, expect, it, vi } from 'vitest';
import { createFreeSwitch, FREE_SWITCH_KEY } from './freeSwitch';
import { memoryStore } from '../storage/localStore';

const cfg = (body: unknown, status = 200) => vi.fn(async () => new Response(JSON.stringify(body), { status }));

describe('FreeSwitch', () => {
  it('följer serverns glosis_free_enabled och minns det', async () => {
    const store = memoryStore();
    const s = createFreeSwitch({ baseUrl: 'https://api.test/api/v1/', store, fetchImpl: cfg({ data: { glosis_free_enabled: false } }) as unknown as typeof fetch });
    const seen = vi.fn();
    s.subscribe(seen);
    expect(s.enabled()).toBe(true);
    await s.refresh();
    expect(s.enabled()).toBe(false);
    expect(seen).toHaveBeenCalledTimes(1);
    expect(await store.get(FREE_SWITCH_KEY)).toBe('0');
  });

  it('utan svar gäller senast kända läge, och saknat fält loggas', async () => {
    const store = memoryStore();
    await store.set(FREE_SWITCH_KEY, '0');
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    const err = vi.spyOn(console, 'error').mockImplementation(() => {});
    const down = createFreeSwitch({ baseUrl: 'https://api.test/api/v1', store, fetchImpl: vi.fn(async () => { throw new Error('nät'); }) as unknown as typeof fetch });
    await down.refresh();
    expect(down.enabled()).toBe(false);
    await createFreeSwitch({ baseUrl: 'https://api.test/api/v1', store, fetchImpl: cfg({ data: {} }) as unknown as typeof fetch }).refresh();
    expect(err).toHaveBeenCalled();
  });
});
```

Nya fall i `voice.studio.test.ts`. Använd testfilens befintliga hjälpare för fetch, ljud och klocka (läs filen först och skriv i dess stil). `attest` är en fejk som kör `post` direkt:

```ts
const fakeAttest = () => {
  const a = {
    send: vi.fn(async (action: string, fields: Record<string, unknown>, post: (c: AttestCredential) => Promise<AttestAnswer>) =>
      post({ keyId: 'K', assertion: 'A', clientData: JSON.stringify({ ...fields, action, challenge: 'c' }) })),
    forgetKey: vi.fn(async () => {}),
  };
  return a;
};

  it('utan köpbevis: skickar bara attest, signerat av AttestService', async () => {
    // purchases.proof() → null; prepare(['dog']) och vänta in förfrågan
    expect(attest.send).toHaveBeenCalledWith('tts', { speed: 'normal', voice: 'female', words: ['dog'] }, expect.any(Function));
    expect(JSON.parse(lastBody())).toEqual({ attest: { keyId: 'K', assertion: 'A', clientData: '{"speed":"normal","voice":"female","words":["dog"],"action":"tts","challenge":"c"}' } });
  });

  it('med giltigt köpbevis används aldrig App Attest (Guld oförändrat)', async () => { /* proof finns → attest.send anropas inte, body har proof */ });

  it('402 på köpbeviset (återbetalt): byter till gratisvägen och gör om förfrågan en gång', async () => {
    // första svaret 402 guld_required på proof → direkt ny förfrågan med attest → 200; nästa prepare går direkt via attest
    // ingen loop: om även gratisvägen svarar fel blir det fail() en gång
  });

  it('503 free_unavailable stänger av studiorösten resten av sessionen', async () => { /* två prepare med 61 s emellan → bara en förfrågan */ });

  it('App Attest stöds inte: enhetens röst, synligt, inga nya försök', async () => { /* send kastar AttestError('unsupported') → studioUnavailable true, speak går till device, ingen ny send */ });
```

Nya fall i `scan.computercat.test.ts`:

```ts
  it('utan köpbevis: skickar attest med bildens sha256 i clientData', async () => {
    // attest.send('scan', { imageSha256 }) där imageSha256 är hex av exakt den JPEG som skickas som 'image'
    // FormData har 'attest' men inte 'proof'
  });
  it.each([
    [429, 'free_scan_used', 'free_scan_used'],
    [429, 'free_busy', 'free_busy'],
    [503, 'free_unavailable', 'free_unavailable'],
    [422, 'invalid_attest', 'attest_failed'],
    [401, 'unknown_key', 'attest_failed'],
  ])('HTTP %i %s → ScanError %s', async (status, error, code) => { /* … */ });
  it('402 på köpbeviset med attest: samma bild skickas en gång till via gratisvägen', async () => { /* … */ });
  it('AttestError unsupported → platform_not_supported, free_unavailable → free_unavailable', async () => { /* … */ });
```

I `index.test.ts`: iOS-butiksbygget ger `freeTier: true` och ett attest-objekt till både röst och skanning. Android-butiksbygget ger `freeTier: false`, inget attest och `freeSwitch.enabled() === false`. Testbygget ger `freeTier: true`.

- [ ] **Step 2: Kör och se att det fallerar**

Run: `pnpm --filter app test -- freeScan freeSwitch voice.studio scan.computercat index`
Expected: FAIL

- [ ] **Step 3: freeScan.ts och freeSwitch.ts**

```ts
// apps/app/src/services/freeScan.ts
import type { KeyValueStore } from '../storage';

/**
 * Ledtråd i appen om att veckans gratisfoto är använt, så att knappen kan säga det i förväg.
 * Servern avgör alltid (en per App Attest-nyckel och ISO-vecka i Europe/Stockholm); ledtråden
 * sätts efter ett lyckat gratisfoto och när servern svarar free_scan_used.
 */
export const FREE_SCAN_KEY = 'glosis-free-scan-week';

/** ISO-vecka i Stockholm, samma format som serverns CarbonImmutable::format('o-\WW'). */
export function stockholmIsoWeek(now: Date): string {
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Stockholm', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(now);
  const get = (t: string) => Number(parts.find((p) => p.type === t)?.value);
  const d = new Date(Date.UTC(get('year'), get('month') - 1, get('day')));
  const weekday = d.getUTCDay() || 7;
  d.setUTCDate(d.getUTCDate() + 4 - weekday); // torsdagen i samma ISO-vecka avgör året
  const year = d.getUTCFullYear();
  const week = Math.ceil(((d.getTime() - Date.UTC(year, 0, 1)) / 86_400_000 + 1) / 7);
  return `${year}-W${String(week).padStart(2, '0')}`;
}

export type FreeScanHint = {
  used(now?: Date): Promise<boolean>;
  markUsed(now?: Date): Promise<void>;
};

export function createFreeScanHint(store: KeyValueStore): FreeScanHint {
  return {
    used: async (now = new Date()) => (await store.get(FREE_SCAN_KEY)) === stockholmIsoWeek(now),
    markUsed: (now = new Date()) => store.set(FREE_SCAN_KEY, stockholmIsoWeek(now)),
  };
}
```

```ts
// apps/app/src/services/freeSwitch.ts
import type { KeyValueStore } from '../storage';

/**
 * Gratisnivåns på/av från Filament (computercat-api GET /games/glosis/config, data.glosis_free_enabled).
 * Styr bara vad UI:t lovar ("Gratis en gång i veckan", studiorösten för gratis); servern avgör alltid.
 * Okänt läge räknas som på, och senast kända läge sparas.
 */
export const FREE_SWITCH_KEY = 'glosis-free-enabled';

export type FreeSwitch = { enabled(): boolean; subscribe(f: () => void): () => void; refresh(): Promise<void> };

export const staticFreeSwitch = (on: boolean): FreeSwitch => ({ enabled: () => on, subscribe: () => () => {}, refresh: async () => {} });

export function createFreeSwitch({ baseUrl, store, fetchImpl }: { baseUrl: string; store: KeyValueStore; fetchImpl?: typeof fetch }): FreeSwitch {
  let on = true;
  const subs = new Set<() => void>();
  const set = (v: boolean) => { if (v !== on) { on = v; subs.forEach((f) => f()); } };
  return {
    enabled: () => on,
    subscribe(f) { subs.add(f); return () => { subs.delete(f); }; },
    async refresh() {
      const cached = await store.get(FREE_SWITCH_KEY);
      if (cached === '0' || cached === '1') set(cached === '1');
      try {
        const res = await (fetchImpl ?? fetch)(`${baseUrl.replace(/\/+$/, '')}/games/glosis/config`, { headers: { Accept: 'application/json' } });
        const body = (await res.json().catch(() => null)) as { data?: Record<string, unknown> } | null;
        const v = body?.data?.glosis_free_enabled;
        if (res.ok && typeof v === 'boolean') {
          set(v);
          await store.set(FREE_SWITCH_KEY, v ? '1' : '0');
        } else {
          console.error('computercat-api: config saknar glosis_free_enabled', { status: res.status });
        }
      } catch (e) {
        console.warn('Glosis: kunde inte hämta gratisnivåns läge, använder senast kända', e);
      }
    },
  };
}
```

- [ ] **Step 4: voice.studio.ts**

Lägg till `attest?: AttestService | null` i `StudioVoiceDeps` och i destruktureringen, och sessionsflaggan `let forceFree = false;` (Guld-beviset nekades, se nedan). Dela `request()` i en `post(body)` som gör fetch med tidsgräns och returnerar `{ status, error, text }` (status 0 och `why` vid nätfel), och själva logiken:

```ts
  type Posted = { status: number; error?: unknown; text: string; why?: string };

  async function post(body: string): Promise<Posted> {
    const abort = new AbortController();
    const timer = setTimeout(() => abort.abort(), PREPARE_TIMEOUT_MS);
    try {
      const res = await doFetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body, signal: abort.signal });
      const text = await res.text();
      let json: unknown;
      try { json = JSON.parse(text); } catch { json = undefined; }
      const error = typeof json === 'object' && json !== null ? (json as Record<string, unknown>).error : undefined;
      return { status: res.status, error, text };
    } catch (e) {
      return { status: 0, text: '', why: abort.signal.aborted ? `inget svar inom ${PREPARE_TIMEOUT_MS / 1000} s` : `ingen kontakt med computercat-api (${String(e)})` };
    } finally {
      clearTimeout(timer);
    }
  }

  async function request(voice: StudioVoice, speed: Speed, words: string[], retried = false): Promise<void> {
    let proof: PurchaseProof | null = null;
    if (!forceFree) {
      try {
        proof = await getProof();
      } catch (e) {
        fail('köpbeviset gick inte att hämta', e);
        return;
      }
    }
    if (!proof && !attest) { fail('inget köpbevis på enheten'); return; }

    // Guld: köpbeviset (oförändrat). Utan Guld: gratisnivån via App Attest (bara iOS), en förfrågan i taget.
    const free = !proof;
    let res: Posted;
    try {
      res = proof
        ? await post(JSON.stringify({ proof, speed, voice, words }))
        : await attest!.send('tts', { speed, voice, words }, (cred) => post(JSON.stringify({ attest: cred })));
    } catch (e) {
      const code = e instanceof AttestError ? e.code : 'failed';
      // Inget stöd eller avstängd gratisnivå ändras inte under sessionen: sluta fråga.
      if (code === 'unsupported' || code === 'free_unavailable') disabled = true;
      fail(`gratisnivån (${code})`, e);
      return;
    }
    if (res.status === 0) { fail(res.why ?? 'ingen kontakt'); return; }

    // Återbetald eller utgången Guldstjärna: gratisvägen resten av sessionen, en gång till direkt.
    if (!free && res.status === 402 && attest && !retried) {
      console.warn('Glosis: köpbeviset ger inte Guld längre, studiorösten går via gratisnivån');
      forceFree = true;
      proofCache = null;
      return request(voice, speed, words, true);
    }
    // …resten som i dag: status !== 200 → console.error + fail; 501 → disabled; 402/422 → proofCache = null.
    // Nytt: if (free && res.status === 503 && res.error === 'free_unavailable') disabled = true;
    // (401 unknown_key hanteras redan i AttestService: nyckeln glöms.)
```

Uppdatera filens docblock: "Med Guldstjärnan: köpbeviset. Utan, eller när köpet inte gäller längre: gratisnivån via App Attest (iOS)."

- [ ] **Step 5: scan.computercat.ts och scan.ts**

`scan.ts`: lägg till de fyra nya koderna i `ScanErrorCode` med en kommentar per kod. I `scan.computercat.ts`: `SERVER_CODES` får `'free_scan_used', 'free_unavailable', 'free_busy'`, deps får `attest?` och tjänsten `let forceFree = false;`. Flödet:

```ts
    async scan(image) {
      let proof = null;
      if (!forceFree) {
        try {
          proof = purchases.proof ? await purchases.proof() : null;
        } catch (e) {
          throw new ScanError('unexpected', `Köpbeviset gick inte att hämta: ${String(e)}`);
        }
      }
      if (!proof && !attest) throw new ScanError('guld_required', 'Inget köpbevis för Guldstjärnan på enheten');
      const jpeg = await prepareImage(image);

      let answer = proof ? await sendGuld(jpeg, proof) : await sendFree(jpeg);
      // Återbetald eller utgången Guldstjärna: samma bild via gratisnivån, och gratis resten av sessionen.
      if (proof && answer.status === 402 && attest) {
        console.warn('Glosis: köpbeviset ger inte Guld längre, fotot går via gratisnivån');
        forceFree = true;
        answer = await sendFree(jpeg);
      }
      return interpret(answer); // dagens tolkning av 200 och fel, utbruten till en funktion
    },
```

```ts
  type Posted = { status: number; error?: unknown; text: string; json: unknown };

  async function sendFree(jpeg: Blob): Promise<Posted> {
    const imageSha256 = await sha256Hex(jpeg);
    try {
      return await attest!.send('scan', { imageSha256 }, (cred) => {
        const body = new FormData();
        body.append('image', jpeg, 'laxa.jpg');
        body.append('attest', JSON.stringify(cred));
        return post(body);
      });
    } catch (e) {
      if (e instanceof ScanError) throw e;
      const c = e instanceof AttestError ? e.code : 'failed';
      throw new ScanError(c === 'unsupported' ? 'platform_not_supported' : c === 'free_unavailable' ? 'free_unavailable' : 'attest_failed', `App Attest: ${String(e)}`);
    }
  }

  /** Hex av SHA-256 över exakt de bytes som laddas upp (servern jämför med imageSha256 i clientData). */
  async function sha256Hex(blob: Blob): Promise<string> {
    const buf = await crypto.subtle.digest('SHA-256', await new Response(blob).arrayBuffer());
    return Array.from(new Uint8Array(buf), (b) => b.toString(16).padStart(2, '0')).join('');
  }
```

`post(body)` gör dagens fetch med tidsgräns. Nätfel och tidsgräns kastar `ScanError('network', …)` som i dag. Annars returneras `{ status, error, text, json }`. I `interpret()`, före `SERVER_CODES.find`: `if (o.error === 'invalid_attest' || o.error === 'unknown_key') throw new ScanError('attest_failed', message)`. AttestService har redan glömt nyckeln vid `unknown_key`. `free_scan_used` får `retryAfter` som `rate_limited`.

- [ ] **Step 6: index.ts**

```ts
import { GlosisAppAttest } from '../native/appattest';
import { createAttest } from './attest';
import { createFreeScanHint, type FreeScanHint } from './freeScan';
import { createFreeSwitch, staticFreeSwitch, type FreeSwitch } from './freeSwitch';
// Services: freeTier: boolean; freeScan: FreeScanHint; freeSwitch: FreeSwitch;

  const freeScan = createFreeScanHint(store);
  // …i butiksgrenen:
    // Gratisnivån (studioröst och veckans foto) finns bara på iOS: App Attest. Android: som förut.
    const attest = platform === 'ios' ? createAttest({ baseUrl: COMPUTERCAT_API_URL, plugin: GlosisAppAttest, store, platform }) : null;
    const freeSwitch = attest ? createFreeSwitch({ baseUrl: COMPUTERCAT_API_URL, store }) : staticFreeSwitch(false);
    void freeSwitch.refresh();
    const scan = createComputercatScan({ baseUrl: COMPUTERCAT_API_URL, purchases, attest });
    const voice = createStudioVoice({ baseUrl: COMPUTERCAT_API_URL, purchases, attest, device: base.voice });
    return { ...base, purchases, mockPurchases: null, scan, voice, freeTier: attest !== null, freeScan, freeSwitch };
  // …testbygget och webben: mockade tjänster, gratisnivån visas som på iOS.
  return { ...base, purchases: mock, mockPurchases: mock, scan: mockScan(), freeTier: true, freeScan, freeSwitch: staticFreeSwitch(true) };
```

- [ ] **Step 7: Kör testerna**

Run: `pnpm --filter app test && pnpm --filter app typecheck`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add apps/app/src/services apps/app/src/test apps/app/src/state/AppProvider.test.tsx apps/app/src/views/games/games.test.tsx apps/app/src/views/training/training.test.tsx apps/app/src/views/plus/plus.test.tsx
git commit -m "Glosis: studioröst och veckans foto utan Guld via App Attest; delad testfabrik"
```

---

### Task 12: UI – studiorösten öppen, veckans gratisfoto, säljsidan (glosis)

**Files:**
- Modify: `apps/app/src/views/common/hooks.ts` (`useStudio`, nya `useFreeTierOn` och `useFreeScanUsed`)
- Modify: `apps/app/src/views/training/Settings.tsx`, `EditorStart.tsx`, `Photo.tsx`
- Modify: `apps/app/src/views/plus/Paywall.tsx` (`PW`, foto-texten per plattform), `PlusBits.tsx` (`featsFor`)
- Test: `apps/app/src/views/training/training.test.tsx`, `apps/app/src/views/plus/plus.test.tsx`, `apps/app/e2e/flows.spec.ts`

**Interfaces:**
- Consumes: `services.freeTier`, `services.freeScan`, `services.freeSwitch`, `ScanErrorCode` (Task 11).
- Produces:
  - `useFreeTierOn(): boolean` = `services.freeTier && freeSwitch.enabled()` (via `useSyncExternalStore`).
  - `useStudio(): boolean` = `(plus.active || useFreeTierOn()) && kid.studio !== false`.
  - `useFreeScanUsed(): { used: boolean; markUsed(): Promise<void> }`.
  - `featsFor(freeTier: boolean)`. `PaywallCtx` behåller `'voice'`, som bara nås på Android utan Guld.

Plattformsreglerna (beslut 2 och 5): på **iOS** (och i testbygget) är studiorösten öppen för alla och fotot gratis en gång i veckan, så länge gratisflaggan är på. På **Android** är allt som förut: Guld får studioröst och foto, utan Guld öppnar båda säljsidan med dagens texter.

Texter (Eriks beslut 4: naturlig svenska, inga tankstreck):
- Inställningar, studioröst, underrad: "Tydlig inspelad röst för veckans ord. På, tryck för att stänga av" eller "… Av, tryck för att slå på" (dagens mönster utan tankstreck). iOS utan Guld när gratisflaggan är av: "Studiorösten är pausad just nu. Telefonens röst används." Knappen gör då inget. Android utan Guld: `PlusTag` och `paywall('voice')` som i dag.
- Fota läxan: Guld "Orden läses in åt dig. Du kollar dem innan de sparas." (oförändrat). Android utan Guld: "Orden läses in åt dig automatiskt." med `PlusTag`, öppnar säljsidan (oförändrat). iOS gratis, oanvänt: "Gratis en gång i veckan. Orden läses in åt dig." iOS gratis, använt: "Veckans gratisfoto är använt. Nästa går på måndag." med `PlusTag`. iOS med gratisflaggan av: "Gratisfotot är pausat just nu." med `PlusTag`. De två sista öppnar säljsidan.
- Säljsidan på iOS: `photo: ['Fota fler läxor', 'Gratis kan du fota en läxa i veckan. Med Guldstjärnan kan du fota fler.']` och FEATS `['camera', 'Fota fler läxor'], ['ball', 'Obegränsade minispel'], ['bag', 'Hela butiken'], ['egg', 'Alla sex figurer'], ['family', 'Upp till 4 barn']`. Säljsidan på Android: dagens `photo`, `voice` och FEATS oförändrade.
- Fotovyn, `free_scan_used`: rubrik "Veckans gratisfoto är använt", texten från servern, knapparna "Fota fler med Guldstjärnan" (→ säljsidan `photo`) och de vanliga "Skriv in orden i stället" och "Klistra in text". `free_busy`: "Många fotar just nu" / serverns text / `retry: 'none'`. `free_unavailable`: "Paus" / "Gratisfotot är pausat just nu. Skriv in orden själv så länge." / `retry: 'none'`. `attest_failed`: "Det gick inte att kontrollera appen" / "Försök igen om en stund." / `retry: 'same'`.

- [ ] **Step 1: Skriv de fallerande testerna**

I `training.test.tsx`, med `makeTestServices`:

```tsx
  it('iOS: studiorösten går att slå på utan Guldstjärnan och öppnar inte säljsidan', async () => {
    // makeTestServices() (freeTier true, flaggan på), utan plus → klick på "Studioröst" → studio växlad, ingen paywall, ingen PlusTag
  });

  it('iOS med gratisflaggan av: studiorösten är pausad och knappen gör inget', async () => {
    // makeTestServices({ freeSwitch: staticFreeSwitch(false) }) → texten "Studiorösten är pausad just nu." och ingen navigering
  });

  it('Android utan Guld: studiorösten öppnar säljsidan som förut; med Guld går den att slå på', async () => {
    // makeTestServices({ freeTier: false, freeSwitch: staticFreeSwitch(false) }) → klick → paywall 'voice' och PlusTag
    // samma tjänster med köpt Guld (buyPlus via mock) → klick växlar studio, ingen paywall
  });

  it('Fota läxan på iOS: gratis en gång i veckan, sedan säljsidan', async () => {
    // utan plus: "Gratis en gång i veckan" och ingen PlusTag; klick öppnar filväljaren
    // efter freeScan.markUsed(): "Veckans gratisfoto är använt. Nästa går på måndag." och klick → paywall('photo')
  });

  it('Fota läxan på iOS med gratisflaggan av: "Gratisfotot är pausat just nu." och säljsidan', async () => { /* … */ });

  it('Fota läxan på Android utan Guld: säljsidan som förut, inget löfte om gratisfoto', async () => {
    // freeTier false → ingen text "Gratis en gång i veckan", klick → paywall('photo')
  });

  it('Photo: free_scan_used visar veckorutan med knapp till Guldstjärnan och markerar veckan', async () => {
    // scan kastar new ScanError('free_scan_used', 'Du har använt veckans gratisfoto. …', 400000)
    // → rubriken "Veckans gratisfoto är använt", knappen "Fota fler med Guldstjärnan" → paywall photo, freeScan.used() === true
  });

  it('Photo: lyckat gratisfoto markerar veckan, Guld gör det inte', async () => { /* … */ });
```

I `plus.test.tsx`:

```tsx
  it('säljsidan på iOS säljer inte studiorösten längre', () => {
    // makeTestServices() → Paywall ctx 'home': "Studioröst" finns inte, "Fota fler läxor" finns
  });
  it('säljsidan på Android är oförändrad', () => {
    // makeTestServices({ freeTier: false }) → "Studioröst på alla ord" och "Fota läxan" finns, "en läxa i veckan" finns inte
  });
```

I `e2e/flows.spec.ts` (testbygget räknas som iOS):

```ts
test('gratis: studiorösten utan säljsida, ett foto i veckan, sedan säljsidan', async ({ page }) => {
  await pickEgg(page);
  await page.getByRole('button', { name: 'Inställningar' }).click();
  await page.getByRole('button', { name: /Studioröst/ }).click();
  await expectView(page, 'settings');

  await page.goto('/');
  await page.getByRole('button', { name: 'Ny lista' }).click();
  await expect(page.getByText('Gratis en gång i veckan')).toBeVisible();
  await page.getByTestId('photo-file').setInputFiles({ name: 'laxa.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64') });
  await page.getByRole('button', { name: 'Spara lista' }).click({ timeout: 10_000 });
  await expectView(page, 'home');

  await page.getByRole('button', { name: 'Ny lista' }).click();
  await expect(page.getByText('Veckans gratisfoto är använt. Nästa går på måndag.')).toBeVisible();
  await shot(page, '06b-editorstart-gratis-anvant');
  await page.getByRole('button', { name: /Fota läxan/ }).click();
  await expectView(page, 'paywall');
  await expect(page.getByRole('heading', { name: 'Fota fler läxor' })).toBeVisible();
  await expect(page.getByText('Studioröst')).toHaveCount(0);
});
```

- [ ] **Step 2: Kör och se att det fallerar**

Run: `pnpm --filter app test -- training plus`
Expected: FAIL

- [ ] **Step 3: Implementera**

`hooks.ts` (importera `useState`, `useEffect` och `useSyncExternalStore` från react):

```ts
/** Gratisnivån gäller här: plattformen har den (iOS, testbygget) och den är inte avstängd i Filament. */
export function useFreeTierOn(): boolean {
  const { services } = useApp();
  const on = useSyncExternalStore(services.freeSwitch.subscribe, services.freeSwitch.enabled, services.freeSwitch.enabled);
  return services.freeTier && on;
}

/** Studiorösten (beslut 2026-10-12): Guld överallt, gratis där gratisnivån gäller. */
export function useStudio(): boolean {
  const { kid, plus } = useApp();
  const freeOn = useFreeTierOn();
  return (plus.active || freeOn) && kid.studio !== false;
}

/** Ledtråd om veckans gratisfoto. Servern avgör; detta styr bara texten och knappen. */
export function useFreeScanUsed() {
  const { services } = useApp();
  const [used, setUsed] = useState(false);
  useEffect(() => {
    let live = true;
    services.freeScan.used().then((u) => { if (live) setUsed(u); }, (e) => console.error('Glosis: kunde inte läsa veckans gratisfoto', e));
    return () => { live = false; };
  }, [services]);
  const markUsed = useCallback(async () => { await services.freeScan.markUsed(); setUsed(true); }, [services]);
  return { used, markUsed };
}
```

`Settings.tsx`:

```tsx
  const freeOn = useFreeTierOn();
  const studioAllowed = plus.active || freeOn;
  const studioPaused = !studioAllowed && services.freeTier; // iOS, gratisflaggan av
  const studioOn = studioAllowed && kid.studio !== false;
  const toggleStudio = () => {
    if (studioPaused) return;
    if (!studioAllowed) { paywall('voice'); return; } // Android utan Guld, som förut
    const next = kid.studio === false;
    updateKid((k) => ({ ...k, studio: next }));
    if (next) services.voice.speak(STUDIO_SAMPLE, { speed: 'normal', voiceId: kid.voice, studio: true, studioVoice: kid.studioVoice });
  };
  // <b>Studioröst{!studioAllowed && !studioPaused && <PlusTag />}</b>
  // <span>{studioPaused ? 'Studiorösten är pausad just nu. Telefonens röst används.'
  //   : `Tydlig inspelad röst för veckans ord. ${studioOn ? 'På, tryck för att stänga av' : 'Av, tryck för att slå på'}`}</span>
```

`EditorStart.tsx`:

```tsx
  const freeOn = useFreeTierOn();
  const { used } = useFreeScanUsed();
  const locked = !plus.active && (!freeOn || used);
  const photo = () => {
    if (locked) { paywall('photo'); return; }
    file.current?.click();
  };
  const sub = plus.active ? 'Orden läses in åt dig. Du kollar dem innan de sparas.'
    : !services.freeTier ? 'Orden läses in åt dig automatiskt.'
    : !freeOn ? 'Gratisfotot är pausat just nu.'
    : used ? 'Veckans gratisfoto är använt. Nästa går på måndag.'
    : 'Gratis en gång i veckan. Orden läses in åt dig.';
  // <b>Fota läxan{locked && <PlusTag />}</b><span>{sub}</span>
```

`Photo.tsx`:
- Effekten vid start: `if (!plus.active && !freeOn) { go({ view: 'paywall', ctx: 'photo' }, { replace: true }); return; }`
- Efter lyckad läsning: `if (!plus.active) void markUsed();` (från `useFreeScanUsed`).
- `PROBLEMS` får posterna enligt texterna ovan. `Problem` får fältet `guld?: boolean`. När det är satt visas knappen "Fota fler med Guldstjärnan" (`go({ view: 'paywall', ctx: 'photo' }, { replace: true })`) först i felrutan.
- I `catch`: `if (code === 'free_scan_used') void markUsed();`. För `free_scan_used` används serverns `message` som `msg`.

`Paywall.tsx`: `PW.photo` blir iOS-texten ovan, och `PW_PHOTO_GULD_ONLY = ['Fota läxan', 'Ta en bild på glosbladet, så läses orden in automatiskt.']` (dagens text). I komponenten: `const [title, desc] = route.ctx === 'photo' && !services.freeTier ? PW_PHOTO_GULD_ONLY : PW[route.ctx] ?? PW.home;`. `PW.voice` står kvar och nås bara på Android.

`PlusBits.tsx`:

```ts
/** Säljsidans lista. Android har ingen gratisnivå än (beslut 2026-10-12): där är listan som förut. */
export const FEATS_GULD_ONLY: readonly [IconName, string][] = [
  ['camera', 'Fota läxan'], ['studiomic', 'Studioröst på alla ord'], ['ball', 'Obegränsade minispel'],
  ['bag', 'Hela butiken'], ['egg', 'Alla sex figurer'], ['family', 'Upp till 4 barn'],
];
export const FEATS_WITH_FREE: readonly [IconName, string][] = [
  ['camera', 'Fota fler läxor'], ['ball', 'Obegränsade minispel'],
  ['bag', 'Hela butiken'], ['egg', 'Alla sex figurer'], ['family', 'Upp till 4 barn'],
];
export const featsFor = (freeTier: boolean) => (freeTier ? FEATS_WITH_FREE : FEATS_GULD_ONLY);
```

Svep: `/usr/bin/grep -rn "FEATS\b\|PW\.photo\|paywall('voice')" apps/app/src`. Varje träff ska gå via `featsFor` eller plattformsvalet ovan.

- [ ] **Step 4: Kör enhetstester, typer och e2e**

Run: `pnpm --filter app test && pnpm --filter app typecheck && pnpm --filter app e2e`
Expected: PASS. Titta på `e2e/screens/10-paywall.png` och `06b-editorstart-gratis-anvant.png`: fem rader i listan, ingen trasig layout.

- [ ] **Step 5: Commit**

```bash
git add apps/app/src apps/app/e2e
git commit -m "Glosis: studiorösten för alla på iOS, veckans gratisfoto och säljsida per plattform"
```

---

### Task 13: Butikstexter och dokumentation (glosis)

**Files:**
- Modify: `apps/app/ios/App/fastlane/metadata/sv/description.txt`, `apps/app/ios/App/fastlane/Fastfile` (`REVIEW_NOTES`)
- **Inte** ändrad: `apps/app/android/fastlane/metadata/android/sv-SE/full_description.txt`. Android har ingen gratisnivå, och där är studiorösten och fotot fortfarande Guld, så dagens text stämmer. Testet nedan låser det.
- Modify: `docs/beslut.md`, `docs/handoff/HANDOFF.md`, `docs/integritetspolicy.md`, `docs/release.md`
- Test: `apps/app/scripts/metadata.test.ts` (vitest tar med alla `*.test.ts` utanför `e2e/`)

- [ ] **Step 1: Skriv det fallerande testet**

```ts
// apps/app/scripts/metadata.test.ts
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

const read = (f: string) => readFileSync(new URL(`../${f}`, import.meta.url), 'utf8');
const IOS = 'ios/App/fastlane/metadata/sv/description.txt';
const ANDROID = 'android/fastlane/metadata/android/sv-SE/full_description.txt';

describe('butikstexterna', () => {
  it('iOS: studiorösten och en läxa i veckan är gratis, studiorösten säljs inte som Guld', () => {
    const t = read(IOS);
    expect(t).not.toMatch(/studioröst på alla ord/i);
    expect(t).toMatch(/en läxa i veckan/);
  });

  it('Android lovar ingen gratisnivå (beslut 2026-10-12: Android har ingen än)', () => {
    const t = read(ANDROID);
    expect(t).not.toMatch(/en läxa i veckan|även studiorösten|studiorösten är gratis/i);
    expect(t).toMatch(/fota läxan, studioröst på alla ord/i);
  });

  it.each([IOS, ANDROID])('%s följer skrivreglerna', (f) => {
    expect(read(f)).not.toMatch(/[—–]|mejl/);
  });
});
```

Kör: `pnpm --filter app test -- metadata`. Väntat: FAIL på iOS-testet, medan Android-testet redan är grönt.

- [ ] **Step 2: Texterna**

Bara `description.txt` (iOS), andra stycket, sista meningen: "…och till sist att höra det och stava. Orden läses upp med tydligt engelskt uttal av en inspelad röst." Stycket om Guldstjärnan ersätts med:

> Det mesta är gratis, även studiorösten och att fota en läxa i veckan. Guldstjärnan låser upp resten för 99 kr per läsår: fler fotade läxor i veckan, obegränsade minispel, hela butiken, alla sex figurer och upp till fyra barn på samma enhet. Guldstjärnan gäller till och med 30 juni och förnyas aldrig automatiskt. Vill ni fortsätta nästa läsår köper ni en ny, och i juni säger appen till.

`Fastfile` `REVIEW_NOTES`. Ersätt foto-raderna med:

```
  - Fota läxan: gratis en gång i veckan, fler med Guldstjärnan. Bilden skickas till vår server och vidare till Anthropic Claude, som läser ut ordparen. Bilden sparas inte.
  - Studiorösten (gratis): de engelska orden skickas till vår server, som läser upp dem med ElevenLabs. Inget om barnet skickas.
  - Appen använder Apples App Attest så att vår server vet att anropen kommer från appen. Inget konto och ingen spårning.
  …
  - Photo scan: free once a week, more with Guldstjärnan. The photo is sent to our server and on to Anthropic Claude, which extracts the word pairs. The image is not stored.
  - Studio voice (free): only the English words are sent to our server, which reads them with ElevenLabs. Nothing about the child is sent.
  - The app uses Apple App Attest so that our server knows requests come from the app. No account, no tracking.
```

- [ ] **Step 3: Dokumentationen**

`docs/beslut.md`, överst:

```markdown
## 2026-10-12

**Studiorösten är gratis, och gratis kan man fota en läxa i veckan.** Studiorösten (ElevenLabs) gäller alla. Utan Guldstjärnan går det att fota en läxa per ISO-vecka (Europe/Stockholm). Guldstjärnan ger fler (veckotaket 15 som förut). Säljsidan, butikstexterna och granskningsanteckningarna säljer inte längre studiorösten. Ersätter HANDOFF 2.1 (raderna Fota läxan och Studioröst), 6.1 och 6.3 där de krockar.

**Gratisnivån känns igen med Apple App Attest (iOS).** Ingen inloggning: appen skapar en App Attest-nyckel per installation, Apple intygar den och computercat-api räknar på nyckeln: en skanning per vecka, 100 nya studioröst-ord per dygn (cachade ord räknas inte). En ominstallation ger en ny nyckel; det är en accepterad risk. Utöver det finns tak per IP och globala dagstak för gratis, och en egen delbudget per månad, så att missbruk inte kan äta av Guld-köparnas utrymme. Allt gratis går att stänga av direkt i Filament (Glosis: gratisnivå). Guldstjärnans köpbevis och gränser är oförändrade.

**Ett gratisfoto som inte gick att läsa räknas inte.** Läser Claude inte bilden, eller hittas inga ord, kan barnet försöka igen samma vecka. De globala taken och IP-taken räknas ändå.

**App Attest-nyckelns id ligger i Preferences, inte i nyckelringen.** Apple: nyckeln överlever inte en ominstallation, och nyckelringen gör det. Ett id i nyckelringen skulle peka på en nyckel som inte finns.

**Android har ingen gratisnivå än.** Android har inget App Attest. Studiorösten och fotot fungerar som förut (Guldstjärnan, Google Play-kontroll), och säljsidan och Google Play-texten är oförändrade på Android. Play Integrity är vägen när Android tas upp igen.

**De verkliga gränserna för gratis är taken per IP och de globala taken.** Nya App Attest-nycklar begränsas till 60 per timme och IP (en skolklass bakom samma NAT ska kunna installera). Det hindrar inte någon med många adresser och enheter. Det som håller kostnaden nere är de globala dagstaken för gratis och gratisnivåns delbudget, som går att sänka i Filament.

**Inga kvitton och ingen spärrlista för nycklar i v1.** Apples kvitto (för riskbedömningen i assessing-fraud-risk) kontrolleras i attesteringen men sparas inte. Det finns ingen spärr per nyckel. Missbruk stoppas med taken eller med gratisflaggan. Båda läggs till om det behövs.

**Om ett köp återbetalas byter appen till gratisnivån.** Svarar servern 402 på köpbeviset går studiorösten och fotot via App Attest resten av sessionen, i stället för att fastna.

**Apples tillägg för valideringskategori och bundle-version avgör inte än.** De tolkas och sparas per nyckel men nekar inget, eftersom Apple inte dokumenterar talvärdena för App Store och TestFlight. Ta ställning när verkliga värden finns i tabellen.
```

`HANDOFF.md` 2.1: `| Fota läxan | 1 per vecka | Fler (se beslut 2026-10-12) |` och `| Studioröst | ✓ | ✓ |`. Rubrikerna 6.1 och 6.3 får "(se beslut 2026-10-12)" i stället för "(Guldstjärnan)".

`integritetspolicy.md`: i köpstycket (rad 21) blir "När appen använder en Guldstjärnan-funktion…" till "När appen med Guldstjärnan använder en funktion…". Nytt avsnitt efter "Studiorösten":

```markdown
## Kontroll av appen (App Attest)

Studiorösten och att fota läxan går via vår server och kostar oss pengar. För att de ska vara gratis utan att kunna missbrukas använder appen på iPhone och iPad Apples App Attest. Första gången skapar telefonen en nyckel som bara finns i den här installationen av appen, och Apple intygar att den hör till en äkta Glosis-app.

Vår server sparar nyckelns id, den publika nyckeln och en räknare (Apples intyg kontrolleras men sparas inte), och använder dem för att hålla koll på gränserna (en fotad läxa i veckan och 100 nya ord om dagen). Nyckeln är inte kopplad till namn, mailadress, Apple-konto eller något om barnet. Den försvinner om appen tas bort, och vi raderar nycklar som inte använts på 13 månader. Kostnaden för varje anrop bokförs med nyckelns nummer, utan bild, ord eller IP-adress.
```

Avsnittet "Vår server" (rad 37) blir: "Servern (computercat-api) används för fotoinläsning, studioröst, kontroll av köp och App Attest-nycklar…".

`release.md`: nytt avsnitt "App Attest" med stegen från Task 14, och en rad i tabellen med secrets om att `IOS_PROVISION_PROFILE_BASE64` måste genereras om när en capability läggs till.

- [ ] **Step 4: Kör testet**

Run: `pnpm --filter app test -- metadata`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add apps/app/ios/App/fastlane apps/app/scripts docs
git commit -m "Glosis: butikstexter och dokumentation för gratis studioröst och veckans foto"
```

---

### Task 13b: glosis.se – pris, uttalskortet och integritetspolicyn (~/Web/glosis)

glosis.se är en Laravel-CMS. Innehållet ligger i produktionsdatabasen (`pages.blocks`), och **varje push till `main` går live** med `php artisan migrate --force` (README rad 61). Därför ändras texterna med en migrering som bara skriver över värden som fortfarande är seedens egna. En text som redaktören ändrat i admin rörs inte, och då loggas en varning. Det är samma mönster som `2026_10_02_000010_integritetspolicyn_galler_appen.php` och `2026_10_07_000010_sommarlov_pa_startsidan.php`. Seedern ändras också, så att en ny installation får de nya texterna. Arbeta i en worktree: `~/.claude/bin/wt-start ~/Web/glosis feat/gratisniva-texter`.

**Pushen till `main` sker först när iOS-versionen med gratisnivån är ute i App Store och flaggan är på** (Task 14, Step 5). Innan dess skulle webbplatsen lova något appen inte gör. Pushen kräver Eriks uttryckliga OK.

**Files:**
- Create: `database/migrations/2026_10_12_000010_gratis_studiorost_och_veckans_foto.php`
- Modify: `database/seeders/HomePageSeeder.php` (rad 113: kortet "Riktigt engelskt uttal", rad 179–184: prisblocket)
- Test: `tests/Feature/GratisnivanTexterTest.php`

**Interfaces:**
- Consumes: `App\Models\Page` (`Page::HEM`, slug `integritet`), blockformen `['type' => …, 'data' => …]`.
- Produces: migreringens publika konstanter `GAMLA` och `NYA` (texterna före och efter), som testet och `down()` använder.

Texterna (naturlig svenska, inga tankstreck, sant för både iOS och Android):
- Kortet "Riktigt engelskt uttal": gammal text "Varje ord läses upp av en tydlig studioröst, och i talövningarna säger barnet orden själv." Ny text: "Orden läses upp med tydligt engelskt uttal, på iPhone och iPad av en inspelad studioröst. I talövningarna säger barnet orden själv."
- Pris, Gratis, gammal lista `['Egna glosor – skriv eller klistra in', 'Alla övningar och provläge', 'Ett glosdjur som växer', 'Ett minispel om dagen']`. Ny lista: `['Egna glosor att skriva eller klistra in', 'Alla övningar och provläge', 'Ett glosdjur som växer', 'Ett minispel om dagen', 'Studioröst och en fotad läxa i veckan på iPhone och iPad']`.
- Pris, Guldstjärnan, gammal lista `['Fota läxan – orden läses in automatiskt', 'Studioröst på alla ord', 'Obegränsade minispel', 'Hela butiken och alla sex figurer', 'Upp till 4 barn i familjen']`. Ny lista: `['Fota fler läxor i veckan', 'Obegränsade minispel', 'Hela butiken och alla sex figurer', 'Upp till 4 barn i familjen']`.
- FAQ "Hur fungerar fotoinläsningen?": gammalt svar oförändrat plus en mening sist: " Gratis kan ni fota en läxa i veckan på iPhone och iPad, med Guldstjärnan fler."
- Integritetspolicyn: nytt textblock (`level` h2) "Kontroll av appen (App Attest)" direkt efter blocket "Studiorösten", med samma text som `~/App/glosis/docs/integritetspolicy.md` (Task 13). Blocket "Vår server" ändras från "Servern används för fotoinläsning, studioröst och kontroll av köp." till "Servern används för fotoinläsning, studioröst, kontroll av köp och App Attest-nycklar." (resten av stycket oförändrat). Köpstyckets mening "När appen använder en funktion i Guldstjärnan som går via vår server skickas butikens kvitto på köpet med" ändras inte, eftersom den fortfarande stämmer.

- [ ] **Step 1: Skriv de fallerande testerna**

```php
<?php
// tests/Feature/GratisnivanTexterTest.php
namespace Tests\Feature;

use App\Models\Page;
use Database\Seeders\HomePageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GratisnivanTexterTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRERING = 'migrations/2026_10_12_000010_gratis_studiorost_och_veckans_foto.php';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function migrering(): object
    {
        return require database_path(self::MIGRERING);
    }

    /** Startsidan som den seedades före ändringen: seeda, sätt tillbaka de gamla texterna. */
    private function gammalStartsida(): Page
    {
        $this->seed(HomePageSeeder::class);
        $m = $this->migrering();
        $m->down();

        return Page::where('slug', Page::HEM)->firstOrFail();
    }

    public function test_startsidan_far_de_nya_texterna(): void
    {
        $this->gammalStartsida();
        $this->migrering()->up();

        $svar = $this->get('/')->assertOk();
        $svar->assertSee('Studioröst och en fotad läxa i veckan på iPhone och iPad');
        $svar->assertSee('Fota fler läxor i veckan');
        $svar->assertDontSee('Studioröst på alla ord');
        $svar->assertSee('på iPhone och iPad av en inspelad studioröst');
    }

    public function test_redigerad_text_rors_inte_och_varning_loggas(): void
    {
        $sida = $this->gammalStartsida();
        $block = $sida->blocks;
        $pris = collect($block)->search(fn ($b) => $b['type'] === 'pricing');
        $block[$pris]['data']['plans'][1]['features'][] = 'Egen rad från admin';
        $sida->blocks = $block;
        $sida->save();

        \Illuminate\Support\Facades\Log::spy();
        $this->migrering()->up();

        $guld = Page::where('slug', Page::HEM)->first()->blocks[$pris]['data']['plans'][1]['features'];
        $this->assertContains('Studioröst på alla ord', $guld);
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'Guldstjärnan'));
    }

    public function test_migreringen_ar_idempotent_och_kan_backas(): void
    {
        $this->gammalStartsida();
        $this->migrering()->up();
        $efter = Page::where('slug', Page::HEM)->first()->blocks;
        $this->migrering()->up();
        $this->assertSame($efter, Page::where('slug', Page::HEM)->first()->blocks);
        $this->migrering()->down();
        $this->get('/')->assertSee('Studioröst på alla ord');
    }

    public function test_integritetspolicyn_far_app_attest_efter_studiorosten(): void
    {
        (require database_path('migrations/2026_10_01_000010_skapa_integritetspolicy.php'))->up();
        (require database_path('migrations/2026_10_02_000010_integritetspolicyn_galler_appen.php'))->up();
        $this->migrering()->up();

        $rubriker = array_map(fn ($b) => $b['data']['heading'] ?? null, Page::where('slug', 'integritet')->first()->blocks);
        $i = array_search('Studiorösten', $rubriker, true);
        $this->assertSame('Kontroll av appen (App Attest)', $rubriker[$i + 1]);
        $this->migrering()->up();
        $this->assertSame(1, count(array_keys(array_map(fn ($b) => $b['data']['heading'] ?? null, Page::where('slug', 'integritet')->first()->blocks), 'Kontroll av appen (App Attest)', true)));
    }

    public function test_utan_sidor_gor_migreringen_ingenting(): void
    {
        $this->migrering()->up();
        $this->assertSame(0, Page::count());
    }

    public function test_seedern_har_de_nya_texterna(): void
    {
        $this->seed(HomePageSeeder::class);
        $this->get('/')->assertSee('Fota fler läxor i veckan')->assertDontSee('Studioröst på alla ord');
    }
}
```

- [ ] **Step 2: Kör och se att det fallerar**

Run: `php artisan test --filter=GratisnivanTexterTest`
Expected: FAIL, migreringen saknas

- [ ] **Step 3: Migreringen**

```php
<?php
// database/migrations/2026_10_12_000010_gratis_studiorost_och_veckans_foto.php
use App\Models\Page;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Glosis 2026-10-12: studiorösten och en fotad läxa i veckan är gratis på iPhone och iPad.
 * Guldstjärnan säljs inte längre med studiorösten. Källa: ~/App/glosis/docs/beslut.md (2026-10-12).
 *
 * Bara värden som fortfarande är seedens egna skrivs över. En text som redaktören ändrat i admin
 * rörs inte, och då loggas en varning så att den kan föras över för hand. Utan sidor (tester)
 * gör migreringen ingenting. up() och down() är idempotenta.
 */
return new class extends Migration
{
    public const UTTAL_GAMMAL = 'Varje ord läses upp av en tydlig studioröst, och i talövningarna säger barnet orden själv.';

    public const UTTAL_NY = 'Orden läses upp med tydligt engelskt uttal, på iPhone och iPad av en inspelad studioröst. I talövningarna säger barnet orden själv.';

    public const GRATIS_GAMMAL = ['Egna glosor – skriv eller klistra in', 'Alla övningar och provläge', 'Ett glosdjur som växer', 'Ett minispel om dagen'];

    public const GRATIS_NY = ['Egna glosor att skriva eller klistra in', 'Alla övningar och provläge', 'Ett glosdjur som växer', 'Ett minispel om dagen', 'Studioröst och en fotad läxa i veckan på iPhone och iPad'];

    public const GULD_GAMMAL = ['Fota läxan – orden läses in automatiskt', 'Studioröst på alla ord', 'Obegränsade minispel', 'Hela butiken och alla sex figurer', 'Upp till 4 barn i familjen'];

    public const GULD_NY = ['Fota fler läxor i veckan', 'Obegränsade minispel', 'Hela butiken och alla sex figurer', 'Upp till 4 barn i familjen'];

    public const FOTO_FRAGA = 'Hur fungerar fotoinläsningen?';

    public const FOTO_TILLAGG = ' Gratis kan ni fota en läxa i veckan på iPhone och iPad, med Guldstjärnan fler.';

    public const SERVER_GAMMAL = 'Servern används för fotoinläsning, studioröst och kontroll av köp.';

    public const SERVER_NY = 'Servern används för fotoinläsning, studioröst, kontroll av köp och App Attest-nycklar.';

    public const ATTEST_RUBRIK = 'Kontroll av appen (App Attest)';

    public const ATTEST_TEXT = "Studiorösten och att fota läxan går via vår server och kostar oss pengar. För att de ska vara gratis utan att kunna missbrukas använder appen på iPhone och iPad Apples App Attest. Första gången skapar telefonen en nyckel som bara finns i den här installationen av appen, och Apple intygar att den hör till en äkta Glosis-app.\n\nVår server sparar nyckelns id, den publika nyckeln och en räknare (Apples intyg kontrolleras men sparas inte), och använder dem för att hålla koll på gränserna (en fotad läxa i veckan och 100 nya ord om dagen). Nyckeln är inte kopplad till namn, mailadress, Apple-konto eller något om barnet. Den försvinner om appen tas bort, och vi raderar nycklar som inte använts på 13 månader. Kostnaden för varje anrop bokförs med nyckelns nummer, utan bild, ord eller IP-adress.";

    public function up(): void
    {
        $this->startsidan(fn ($gammal, $ny) => [$gammal, $ny]);
        $this->integritet(true);
    }

    public function down(): void
    {
        $this->startsidan(fn ($gammal, $ny) => [$ny, $gammal]);
        $this->integritet(false);
    }

    /** @param \Closure(mixed, mixed): array{0: mixed, 1: mixed} $riktning ger [från, till] */
    private function startsidan(\Closure $riktning): void
    {
        $sida = Page::where('slug', Page::HEM)->first();
        if ($sida === null) {
            return;
        }
        $block = $sida->blocks ?? [];
        foreach ($block as $i => $b) {
            if (($b['type'] ?? null) === 'features') {
                foreach ($b['data']['items'] ?? [] as $j => $kort) {
                    if (($kort['title'] ?? null) === 'Riktigt engelskt uttal') {
                        $block[$i]['data']['items'][$j]['text'] = $this->byt($kort['text'] ?? null, ...$riktning(self::UTTAL_GAMMAL, self::UTTAL_NY), 'uttalskortet');
                    }
                }
            }
            if (($b['type'] ?? null) === 'pricing') {
                foreach ($b['data']['plans'] ?? [] as $j => $plan) {
                    [$gammal, $ny] = match ($plan['name'] ?? null) {
                        'Gratis' => $riktning(self::GRATIS_GAMMAL, self::GRATIS_NY),
                        'Guldstjärnan' => $riktning(self::GULD_GAMMAL, self::GULD_NY),
                        default => [null, null],
                    };
                    if ($gammal !== null) {
                        $block[$i]['data']['plans'][$j]['features'] = $this->byt($plan['features'] ?? null, $gammal, $ny, "prislistan {$plan['name']}");
                    }
                }
            }
            if (($b['type'] ?? null) === 'faq') {
                foreach ($b['data']['items'] ?? [] as $j => $fraga) {
                    if (($fraga['question'] ?? null) === self::FOTO_FRAGA) {
                        $svar = (string) ($fraga['answer'] ?? '');
                        $har = str_ends_with($svar, self::FOTO_TILLAGG);
                        $block[$i]['data']['items'][$j]['answer'] = $riktning(false, true)[1]
                            ? ($har ? $svar : $svar.self::FOTO_TILLAGG)
                            : ($har ? substr($svar, 0, -strlen(self::FOTO_TILLAGG)) : $svar);
                    }
                }
            }
        }
        $sida->blocks = $block;
        $sida->save();
    }

    private function integritet(bool $upp): void
    {
        $sida = Page::where('slug', 'integritet')->first();
        if ($sida === null) {
            return;
        }
        $block = array_values($sida->blocks ?? []);
        $rubriker = array_map(fn ($b) => $b['data']['heading'] ?? null, $block);
        $finns = array_search(self::ATTEST_RUBRIK, $rubriker, true);

        if ($upp && $finns === false) {
            $efter = array_search('Studiorösten', $rubriker, true);
            if ($efter === false) {
                Log::warning('Integritetspolicyn saknar blocket "Studiorösten"; App Attest-stycket lades inte in. För in det för hand från migreringen 2026_10_12_000010.');
            } else {
                array_splice($block, $efter + 1, 0, [['type' => 'text', 'data' => ['level' => 'h2', 'heading' => self::ATTEST_RUBRIK, 'body' => self::ATTEST_TEXT]]]);
            }
        }
        if (! $upp && $finns !== false) {
            array_splice($block, $finns, 1);
        }
        foreach ($block as $i => $b) {
            if (($b['data']['heading'] ?? null) === 'Vår server') {
                [$fran, $till] = $upp ? [self::SERVER_GAMMAL, self::SERVER_NY] : [self::SERVER_NY, self::SERVER_GAMMAL];
                $body = (string) ($b['data']['body'] ?? '');
                if (str_starts_with($body, $fran)) {
                    $block[$i]['data']['body'] = $till.substr($body, strlen($fran));
                } elseif (! str_starts_with($body, $till)) {
                    Log::warning('Integritetspolicyns stycke "Vår server" är redigerat i admin och ändrades inte. Lägg till "App Attest-nycklar" för hand.');
                }
            }
        }
        $sida->blocks = $block;
        $sida->save();
    }

    /** Byter $nuvarande mot $till bara om det är exakt $fran (seedens text). Redan $till: inget. Annat: varning. */
    private function byt(mixed $nuvarande, mixed $fran, mixed $till, string $vad): mixed
    {
        if ($nuvarande === $fran || $nuvarande === $till) {
            return $till;
        }
        Log::warning("glosis.se: {$vad} är redigerad i admin och ändrades inte (gratis studioröst och veckans foto, migreringen 2026_10_12_000010). För över texten för hand.");

        return $nuvarande;
    }
};
```

Varningstexten för Guld-listan innehåller "prislistan Guldstjärnan", som testet söker efter.

- [ ] **Step 4: Seedern**

`HomePageSeeder.php` rad 113: `'text' => 'Orden läses upp med tydligt engelskt uttal, på iPhone och iPad av en inspelad studioröst. I talövningarna säger barnet orden själv.'`. Rad 180–184: listorna `GRATIS_NY` och `GULD_NY` ovan. FAQ-svaret: lägg till meningen ovan. Testets `gammalStartsida()` använder `down()` för att få fram den gamla formen, så seedern och migreringen måste ha exakt samma nya texter. Låt seedern läsa konstanterna från migreringen (`(require database_path(…))::GULD_NY` fungerar inte på en anonym klass), eller kopiera texterna ordagrant. Testet `test_seedern_har_de_nya_texterna` fångar en skillnad.

- [ ] **Step 5: Kör hela sviten**

Run: `php artisan test`
Expected: PASS (även `StartsidanTest`, `TextsidaTest`, `PlatshallareOchLankarTest`)

- [ ] **Step 6: Commit (ingen push)**

```bash
git add database/migrations/2026_10_12_000010_gratis_studiorost_och_veckans_foto.php database/seeders/HomePageSeeder.php tests/Feature/GratisnivanTexterTest.php
git commit -m "glosis.se: studioröst och en fotad läxa i veckan gratis på iPhone och iPad; App Attest i integritetspolicyn"
```

`wt-finish` först när Task 14 Step 5 säger till. Pushen till `main` är produktion.

---

### Task 14: Manuella steg, enhetsprov och utrullning

Den här tasken rör Apple-kontot, GitHub-secrets och produktion. Varje steg som rör produktion kräver Eriks uttryckliga OK i stunden.

**Files:**
- Create (efter enhetsprovet): `tests/Fixtures/glosis-appattest-device.json` och ett nytt test i `tests/Unit/Glosis/AppAttestVerifierTest.php` (computercat-api)
- Modify: `app/Services/Glosis/AppAttest/AppAttestVerifier.php` (ta bort `ASSUMED`)

- [ ] **Step 1 (Erik): Slå på App Attest för app-id:t**
  1. <https://developer.apple.com/account/resources/identifiers/list> → `se.computercat.glosis` → Capabilities → kryssa i **App Attest** → Save → Confirm.
  2. Profiles → **Glosis App Store** (står nu som Invalid) → Edit → Save. Välj samma distributionscertifikat. Ladda ner `Glosis_App_Store.mobileprovision`.
  3. Kontrollera: `security cms -D -i ~/Downloads/Glosis_App_Store.mobileprovision | /usr/bin/grep -A1 appattest`. Ska visa `com.apple.developer.devicecheck.appattest-environment` med `production`.
  4. Uppdatera GitHub-secreten: `base64 -i ~/Downloads/Glosis_App_Store.mobileprovision | gh secret set IOS_PROVISION_PROFILE_BASE64 --repo <glosis-repot>`. Radera sedan filen i Hämtade filer.
  5. Team ID: Apple Developer → Membership details → Team ID (10 tecken). Används i Step 4.

  Utan steg 1–4 misslyckas `fastlane beta`, eftersom profilen saknar entitlementen som `App.entitlements` begär.

- [ ] **Step 2: Enhetsprov (tar bort ASSUMED på assertionens signatur)**
  1. Xcode → öppna `apps/app/ios/App/App.xcodeproj` från worktreen efter `pnpm --filter app cap:sync`. Kör Debug på en riktig iPhone med automatisk signering, som använder samma app-id och capability. Entitlementen `production` gör att nyckeln får production-aaguid.
  2. Safari → Utveckla → iPhonen → Glosis. Kör i konsolen:

```js
const P = window.Capacitor.registerPlugin('GlosisAppAttest');
const { keyId } = await P.generateKey();
const challenge = 'glosis-device-fixture-' + Date.now();
const { attestation } = await P.attestKey({ keyId, challenge });
const clientData = JSON.stringify({ speed: 'normal', voice: 'female', words: ['dog'], action: 'tts', challenge: 'fixture' });
const { assertion } = await P.generateAssertion({ keyId, clientData });
copy(JSON.stringify({ appId: '<TEAMID>.se.computercat.glosis', keyId, challenge, attestation, clientData, assertion, verifyAt: Math.floor(Date.now() / 1000) }, null, 2));
```

  3. Spara som `tests/Fixtures/glosis-appattest-device.json` i computercat-api. Det är ett engångsprov utan personuppgifter, och Team ID är inte hemligt. Lägg till testet:

```php
    /** Riktig iPhone (planens Task 14): attestering och assertion med Apples riktiga rot. */
    public function test_riktig_enhet_attestering_och_assertion(): void
    {
        $fx = json_decode(file_get_contents(base_path('tests/Fixtures/glosis-appattest-device.json')), true);
        $v = new AppAttestVerifier;
        $key = $v->verifyAttestation(base64_decode($fx['attestation'], true), base64_decode($fx['keyId'], true), hash('sha256', $fx['challenge'], true), $fx['appId'], $fx['verifyAt'], false);
        $this->assertSame('production', $key->environment);
        $this->assertSame(1, $v->verifyAssertion(base64_decode($fx['assertion'], true), $fx['clientData'], $key->publicKeyPem, 0, $fx['appId']));
    }
```

  4. Går testet igenom: ta bort raden `// ASSUMED …` och reservkommentaren i `verifyAssertion`, och ersätt dem med `// Bekräftat mot riktig enhet, tests/Fixtures/glosis-appattest-device.json.`. Fallerar signaturen: byt till reserven (enkel hashning, `openssl_verify($authData.hash('sha256', $clientData, true), …)`) i både `verifyAssertion` och `FakeAppAttest::assert`, och kör om. Behåll den variant som enheten bekräftar, och skriv den i beslut.md. Fallerar båda: stanna och fråga Erik. Notera också `bundle_version` och `validation_category` från provet i beslut.md. **Gratisflaggan får inte slås på (Step 5) förrän det här testet är grönt.**
  5. Commit: `git commit -m "Glosis: App Attest bekräftat mot riktig enhet"`.

- [ ] **Step 3: Granskning före produktion**
  Kör `completion-gate` och `security-first-mindset` på båda grenarna. Kontrollera `/usr/bin/grep -rn "ASSUMED" app/Services/Glosis` (ska vara tomt). `php artisan test` och `pnpm test && pnpm --filter app e2e` ska vara gröna.

- [ ] **Step 4 (Erik OK): Servern först, bakåtkompatibel**
  1. Migrationerna mot en färsk kopia av produktionsdatabasen lokalt (`--db`-worktreen). Jämför `select tier, count(*) from ai_usage group by tier` med `select environment, count(*) …`. Admin-raderna ska ha blivit `admin`, och resten `guld`.
  2. `wt-finish`, push till trunken och driftsätt computercat-api enligt projektets vanliga deploy. Kör `php artisan migrate --force` i produktion (efter OK).
  3. Smoke på Guld med nuvarande TestFlight-bygge: ett nytt ord i studiorösten och en skanning. Kontrollera att `ai_usage` får `tier = guld` och att svaren är som förut. Gratisflaggan är av, så `POST …/attest/challenge` ska ge 503 `free_unavailable`.
  4. Filament → Glosis → "Glosis: gratisnivå": fyll i Team ID, men låt flaggan vara av.

- [ ] **Step 5 (Erik OK): Appen**
  1. `wt-finish` i glosis, push till trunken. release.yml bygger TestFlight med den nya profilen.
  2. Slå på flaggan i Filament, **först när enhetsprovet i Step 2 är grönt och `ASSUMED` är borta**. Gamla appversioner skickar aldrig `attest`, så det är ofarligt för dem.
  3. På TestFlight utan Guld: studiorösten spelar ett nytt ord, ett foto går igenom, foto nummer två samma vecka ger veckorutan, och med flygplansläge syns raden om telefonens röst. Med Guld (sandlådeköp): allt som förut. Kör `php artisan glosis:check-free-usage` i produktion (läsande). Den ska vara grön, och raderna ska ha `tier = free` med nyckel.
  4. App Privacy i App Store Connect enligt Eriks beslut (Öppna frågor, punkt 1), **före inskick**. `fastlane metadata` för iOS-texten (Android-texten ändras inte). Skicka in versionen med **Phased Release for Automatic Updates** påslaget (App Store Connect → versionen → "Phased Release", 7 dagar). Apple ber appar sprida ut första attesteringen (preparing-to-use-the-app-attest-service), och appen backar redan vid `serverUnavailable` (Task 10).
  5. När versionen är ute i App Store: `wt-finish` för glosis.se (Task 13b) och push till `main` efter Eriks OK. Det går live direkt via Quick Deploy och kör migreringen. Kontrollera https://glosis.se/#pris och https://glosis.se/integritet, och läs Laravel-loggen efter varningar från migreringen (redigerade texter som inte ändrades).
  6. Rollback: stäng av flaggan i Filament. Gratis upphör direkt, appen slutar lova gratis vid nästa start (remote config), och Guld påverkas inte. Serverkoden behöver inte backas. glosis.se backas med `php artisan migrate:rollback --step=1` i produktion (kräver OK) eller med en ny migrering.
