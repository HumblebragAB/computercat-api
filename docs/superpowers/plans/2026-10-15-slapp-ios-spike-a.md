# Släppflöde etapp 2, spike A (GitHub, utan Apple-nycklar) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans. Controllern kör spiken själv (den rör riktiga tjänster); steg markeras med `- [ ]`.

**Goal:** Bevisa den del av iOS-flödet som inte kräver Apple-nycklar: Computercat startar ett flöde i `HumblebragAB/computercat-release`, hittar körningen via `run-name`, jobb 1 checkar ut ett spelrepo med en token som bara kan läsa det repot och bygger en osignerad Capacitor iOS-arkivering, och jobb 2 tar emot artefakten utan att ha spelets kod.

**Architecture:** Alternativ 1 i specen (centralt släpprepo), med tre jobb: jobb 0 (`fetch`, ubuntu) är det enda som har appnyckeln; det skapar en token som bara läser spelrepot, checkar ut utan hooks och laddar upp källan som artefakt. Jobb 1 (`build`, macOS) kör spelets kod utan några hemligheter eller tokens. Jobb 2 (`sign`) får bara den osignerade arkiveringen. Skäl (granskning 2026-10-15): på GitHubs macOS-runner har steg sudo och kan läsa runnerprocessens minne, så en nyckel i samma jobb som spelets kod skyddas inte. Spiken skapar ett engångsspelrepo med Capacitor iOS, ett spikflöde på en spikgren i släpprepot och anropar GitHub från Computercat i prod via tinker. Resultaten skrivs i en bilaga och styr resten av etapp 2:s plan (spike B med Apple, sedan bygget).

**Tech Stack:** GitHub Actions (`macos`-runner, `workflow_dispatch`, `actions/create-github-app-token`), Capacitor iOS, Xcode (`xcodebuild archive` utan signering), Next.js statisk export från byggguide v2, Laravel tinker i prod.

**Spec:** `docs/superpowers/specs/2026-10-14-slappflode-design.md` (Del 2 "Byggflöden", "Hemligheter", "Statusläsning", "Säkerhet"; Etapper rad 2).

## Global Constraints

- Släpprepot är det enda repot med nycklar. Jobb 1 kör spelrepots kod och får inga hemligheter som kan nå annat än spelrepot självt; jobb 2 checkar aldrig ut spelrepot (spec Del 2).
- `workflow_dispatch` med indata spel, `commit_sha`, `release_uuid`; körningen hittas via `release_uuid` i `run-name` (spec "Byggflöden").
- Säkerhetskontrollen hämtas från Computercat och sha256-kontrolleras i flödet (spec "Byggflöden", jobb 1).
- Hemligheter: inga värden i transkriptet, loggar eller git. Kopiering av en nyckel kräver Eriks OK innan den görs, och värdet går i rör direkt till `gh secret set` utan att skrivas ut.
- Engångsresurser sparas på id när de skapas och städas bara på de id:na (CLAUDE.md "Radering").
- Allt som rör GitHub-appens behörigheter eller hemligheter i släpprepot frågas med AskUserQuestion innan det görs.

## Beslut 2026-10-15 (Erik)

- Jobb 0 använder en separat app "ComputerCat Release Reader" (bara Contents: read, installerad på alla repon i HumblebragAB). Erik skapar appen och nyckeln; nyckeln läggs som `CC_READER_PRIVATE_KEY` och app-id som `CC_READER_APP_ID` i släpprepot. ComputerCat Studio används bara av Computercat (dispatch, läsa körningar) och får Actions: read and write.
- Spiken får skapa `ccspike-ios` och lägga spikflödet i `computercat-release` via PR. Engångsrepon raderas av Erik efteråt (Claude raderar aldrig permanent); inga fler engångsrepon än det som behövs.
- Capacitor iOS går in i byggguide v3 tillsammans med achievements.
- Lokalt bevis 2026-10-15: Capacitor 8.5.3 (SPM, `npx cap add ios --packagemanager SPM`), Xcode 27, osignerad `xcodebuild archive` på 36 s, 9,4 MB, `App.app/App` körbar.

## Spikfrågor

| # | Fråga | Bevis |
|---|---|---|
| G1 | Kan Computercat (appens installationstoken) starta `workflow_dispatch` i släpprepot, och vilken behörighet krävs (Actions: write på släpprepot)? | 204 från `POST /repos/HumblebragAB/computercat-release/actions/workflows/<fil>/dispatches` från prod |
| G2 | Syns `release_uuid` i körningens namn direkt efter dispatch, och hur hittas körningen (`GET .../actions/runs?event=workflow_dispatch&created>=…`, fältet `name`)? Hur lång är fördröjningen? | körningens `id`, `name`, `status` läst från prod |
| G3 | Kan jobb 1 få en token som bara läser spelrepot (`actions/create-github-app-token` med `repositories: <spel>` och `permission-contents: read`), och når den inte ett annat repo? | utcheckning OK; `gh api repos/HumblebragAB/computercat-ankare` med samma token ger 404 |
| G4 | Var finns appnyckeln under jobb 1? Når spelets byggsteg den (miljö, disk)? | ett steg efter tokenskapandet söker i `env` och i runnerns temp efter nyckelns rubrikrad och skriver bara antal träffar |
| G5 | Bygger `npx cap sync ios` och `xcodebuild archive` med `CODE_SIGNING_ALLOWED=NO` en `.xcarchive` från Capacitor-projektet? Xcode-version på runnern, byggtid, artefaktens storlek. | `.xcarchive` med `Products/Applications/App.app`; tider ur körningen |
| G6 | Kan jobb 2 hämta artefakten utan att checka ut spelrepot, och ser jobb 2 något av spelrepot utöver artefakten? | jobb 2 listar arkivets innehåll; `ls` av arbetskatalogen är tom |
| G1b | Ger dispatch med `"return_run_details": true` ett run-id direkt (svar 200 med `workflow_run_id`)? Fungerar dispatch mot en gren där filen inte finns på standardgrenen? | svaren från prod |
| G7 | Kostnad: macOS-minuter per körning och vilken minutkvot organisationen har. | körningens `run_duration_ms`; kvoten frågas Erik om om API:t nekar |

## Task 1: Förutsättningar och beslut (frågor till Erik)

Verktyg: `source <scratchpad>/byggguide-v2/tools.sh` (definierar `$WT`, `$SCRATCH`, `prodtinker`, `coolifydb`); sätt `WT` till planens worktree och `mkdir -p $SCRATCH/ios-a`. v2 serveras i prod (verifierat 2026-10-14, Task 12 i byggguide v2).

- [ ] **Steg 1:** Läs appens behörigheter i prod (installationens `permissions`) och kontrollera att `computercat-release` ingår:

```bash
cat > $SCRATCH/tinker/ghperm.php <<'EOF'
$c = app(App\Provisioning\Clients\GitHubAppClient::class);
$r = Illuminate\Support\Facades\Http::withToken($c->jwt())->acceptJson()->get('https://api.github.com/app/installations/171274645');
echo json_encode(['permissions' => $r->json('permissions'), 'selection' => $r->json('repository_selection')])."\n";
echo 'release synlig: '.($c->getRepository('computercat-release') ? 'ja' : 'nej')."\n";
EOF
prodtinker $SCRATCH/tinker/ghperm.php
```

- [ ] **Steg 2:** En AskUserQuestion med alla beslut som ändrar något hos Erik (utan svar: stanna):
  1. Vilken nyckel jobb 0 använder: (a) en separat app "ComputerCat Release Reader" med bara Contents: read, installerad på organisationens repon, så att en läckt nyckel bara kan läsa; eller (b) en ny privat nyckel till ComputerCat Studio. Nyckeln skapas av Erik ("Generate a private key"); jag lägger in den med `gh secret set CC_APP_PRIVATE_KEY -R HumblebragAB/computercat-release < <fil>` utan att läsa den och raderar filen.
  2. ComputerCat Studio får Actions: Read and write (för dispatch och att läsa körningar); Erik bekräftar med passkey.
  3. Spiken skapar det privata engångsrepot `HumblebragAB/ccspike-ios` (arkiveras efteråt), lägger det i installationen och lägger spikflödet på `main` i `computercat-release` via en PR som Erik godkänner (`workflow_dispatch` kräver troligen att flödesfilen finns på standardgrenen; spikflödet tas bort med en ny PR efteråt).
  4. Capacitor iOS i byggguiden: i v3 tillsammans med achievements (`docs/superpowers/specs/2026-10-14-achievements-klient-design.md`) eller i en egen v4. Spikens repo är ingen mall.
  Om G4 visar att nyckeln nåtts av spelets kod roteras den direkt (Erik raderar nyckeln i appens inställningar).

- [ ] **Steg 3:** Efter Eriks OK: ändra behörigheten (Chrome; Erik bekräftar passkey), lägg in hemligheterna, och kör om steg 1. Förväntat: `actions: write` i `permissions`.

## Task 2: Engångsspelrepo med Capacitor iOS

- [ ] **Steg 1:** Grundkod från låsta v2 (som i spike B för byggguide v2):

```bash
cd $WT && KEEP=$(php artisan computercat:byggguide:verify v2 --keep | sed -n 's/^Katalogen behålls: //p') && echo "KEEP=$KEEP" > $SCRATCH/ios-a/ids.env
cd "$KEEP" && rm -rf out .next node_modules
npm install --save-exact --ignore-scripts @capacitor/core@latest @capacitor/ios@latest @capacitor/cli@latest   # versionerna noteras och låses i bilagan
```

Skriv `capacitor.config.ts`:

```ts
import type { CapacitorConfig } from '@capacitor/cli';

const config: CapacitorConfig = { appId: 'co.humblebrag.ccspikeios', appName: 'CC Spike iOS', webDir: 'out' };
export default config;
```

```bash
npm ci --ignore-scripts && NEXT_PUBLIC_COMPUTERCAT_GAME=ccspike-ios npm run build && npx cap add ios --packagemanager SPM && npx cap sync ios
```

Förväntat: `ios/App/App.xcodeproj` finns. Notera Capacitor-versionen och om projektet använder Swift Package Manager eller CocoaPods (`ios/App/Podfile` finns eller inte).

- [ ] **Steg 2: Lokalt bevis för G5 före Actions.**

```bash
cd "$KEEP/ios/App" && xcodebuild -project App.xcodeproj -scheme App   # -workspace App.xcworkspace om Capacitor valde CocoaPods -configuration Release -destination 'generic/platform=iOS' -archivePath $SCRATCH/ios-a/local.xcarchive CODE_SIGNING_ALLOWED=NO CODE_SIGNING_REQUIRED=NO archive > $SCRATCH/ios-a/xcode-local.log 2>&1; echo "exit=$?"
ls $SCRATCH/ios-a/local.xcarchive/Products/Applications/
```

Förväntat: `exit=0` och `App.app`. Fel: läs loggens sista `error:`-rader; misslyckas osignerad arkivering helt är alternativ 1 i fara (specen: stanna och fråga Erik).

- [ ] **Steg 3:** Privat engångsrepo, id sparas:

```bash
cd "$KEEP" && git init -q -b main && git add -A && git -c user.name=Spike -c user.email=spike@invalid commit -qm "Spike: v2-grundkod med Capacitor iOS"
gh repo create HumblebragAB/ccspike-ios --private --description "Engångsrepo för spike släppflöde iOS (arkiveras)" --source=. --remote=origin --push
gh api repos/HumblebragAB/ccspike-ios --jq '"REPO_ID=\(.id) private=\(.private)"' | tee -a $SCRATCH/ios-a/ids.env
git rev-parse HEAD | sed 's/^/SHA=/' | tee -a $SCRATCH/ios-a/ids.env
```

`private` false: stanna omedelbart. Lägg till repot i appens installation (Chrome, som för släpprepot) så att `create-github-app-token` kan ge en token för det.

## Task 3: Spikflödet i släpprepot

- [ ] **Steg 1:** Gren `spike/ios-a` i `computercat-release` med `.github/workflows/release-ios-spike.yml` (spiken körs från grenen; det riktiga flödet ligger på `main` och ändras bara via PR):

```yaml
name: release-ios-spike
run-name: "ios ${{ inputs.game }} ${{ inputs.release_uuid }}"
on:
  workflow_dispatch:
    inputs:
      game: { required: true, type: string }
      commit_sha: { required: true, type: string }
      release_uuid: { required: true, type: string }
permissions: {}
env:
  CC_GATE_SHA256: "__SHA256_FRÅN_V2_MANIFESTET__"
jobs:
  fetch:
    runs-on: ubuntu-24.04
    timeout-minutes: 10
    steps:
      - name: Validera indata
        env: { GAME: "${{ inputs.game }}", SHA: "${{ inputs.commit_sha }}", UUID: "${{ inputs.release_uuid }}" }
        run: |
          [[ "$GAME" =~ ^[a-z][a-z0-9-]{2,39}$ ]] && [[ "$SHA" =~ ^[0-9a-f]{40}$ ]] && [[ "$UUID" =~ ^[0-9a-f-]{36}$ ]] || { echo "ogiltig indata"; exit 1; }
      - id: token
        uses: actions/create-github-app-token@v2
        with:
          app-id: ${{ secrets.CC_READER_APP_ID }}
          private-key: ${{ secrets.CC_READER_PRIVATE_KEY }}
          owner: HumblebragAB
          repositories: ${{ inputs.game }}
          permission-contents: read
      - name: G3 token når bara spelrepot
        env: { GH_TOKEN: "${{ steps.token.outputs.token }}", GAME: "${{ inputs.game }}" }
        run: |
          gh api "repos/HumblebragAB/$GAME" --jq .full_name
          gh api repos/HumblebragAB/computercat-release --silent; echo "släpprepot exit=$? (förväntat 1, 404)"
      - uses: actions/checkout@v4
        with:
          repository: HumblebragAB/${{ inputs.game }}
          ref: ${{ inputs.commit_sha }}
          token: ${{ steps.token.outputs.token }}
          persist-credentials: false
          submodules: false
          lfs: false
          path: src
      - run: tar -C src --exclude=.git -czf source.tgz .
      - uses: actions/upload-artifact@v4
        with: { name: source, path: source.tgz, retention-days: 1 }
  build:
    needs: fetch
    runs-on: macos-15
    timeout-minutes: 60
    outputs:
      xcode: ${{ steps.info.outputs.xcode }}
    steps:
      - name: G4 inga hemligheter i jobbet
        run: |
          echo "env-träffar: $(env | grep -cE 'BEGIN [A-Z ]*PRIVATE KEY' || true)"
          echo "token-variabler: $(env | grep -ciE '^(GH_TOKEN|GITHUB_TOKEN)=' || true)"
          sudo -n true 2>/dev/null && echo "sudo=ja" || echo "sudo=nej"
      - uses: actions/download-artifact@v4
        with: { name: source }
      - run: mkdir src && tar -C src -xzf source.tgz && rm source.tgz
      - uses: actions/setup-node@v4
        with: { node-version: 22 }
      - working-directory: src
        run: npm ci --ignore-scripts
      - name: Bygg och säkerhetskontroll
        working-directory: src
        env: { NEXT_PUBLIC_COMPUTERCAT_GAME: "${{ inputs.game }}" }
        run: |
          npm run build
          curl -fsS https://api.computercat.co/api/v1/build-guide/v2/files/scripts/cc-gate.mjs -o "$RUNNER_TEMP/cc-gate.mjs"
          echo "${CC_GATE_SHA256}  $RUNNER_TEMP/cc-gate.mjs" | shasum -a 256 -c -
          node "$RUNNER_TEMP/cc-gate.mjs"
      - working-directory: src
        run: npx cap sync ios
      - id: info
        run: echo "xcode=$(xcodebuild -version | head -1)" >> "$GITHUB_OUTPUT"
      - name: G5 osignerad arkivering
        working-directory: src/ios/App
        run: |
          xcodebuild -project App.xcodeproj -scheme App -configuration Release -destination 'generic/platform=iOS' \
            -archivePath "$RUNNER_TEMP/App.xcarchive" CODE_SIGNING_ALLOWED=NO CODE_SIGNING_REQUIRED=NO archive | tail -5
          ditto -c -k --keepParent "$RUNNER_TEMP/App.xcarchive" "$RUNNER_TEMP/xcarchive.zip"
          du -sh "$RUNNER_TEMP/App.xcarchive" "$RUNNER_TEMP/xcarchive.zip"
      - uses: actions/upload-artifact@v4
        with: { name: xcarchive, path: "${{ runner.temp }}/xcarchive.zip", retention-days: 1 }
  sign:
    needs: build
    runs-on: macos-15
    timeout-minutes: 30
    steps:
      - uses: actions/download-artifact@v4
        with: { name: xcarchive }
      - name: G6 bara artefakten
        run: |
          echo "xcode i build: ${{ needs.build.outputs.xcode }}"
          ditto -x -k xcarchive.zip .
          test -x App.xcarchive/Products/Applications/App.app/App && echo "App körbar=ja"
          ls -a | tr '\n' ' '
```

- [ ] **Steg 2:** Ersätt `__SHA256_FRÅN_V2_MANIFESTET__` med v2-manifestets sha256 för `scripts/cc-gate.mjs` (hämtas med `jq -r '.files[] | select(.path=="scripts/cc-gate.mjs") | .sha256' resources/byggguide/v2/manifest.json`). Ersätt `-project App.xcodeproj` med `-project App.xcodeproj` (SPM) eller `-workspace App.xcworkspace` (CocoaPods) enligt Task 2. Klona släpprepot till `$SCRATCH/ios-a/release`, skapa grenen `spike/ios-a`, committa flödet, pusha grenen och öppna en PR till `main` (`gh pr create`). Erik godkänner och mergar enligt beslutet i Task 1; först därefter går dispatch (prova först dispatch mot grenen och notera svaret: 404 bekräftar att filen måste finnas på `main`).

## Task 4: Dispatch från Computercat och körningen (G1, G2, G7)

- [ ] **Steg 1:** Från prod, med appens installationstoken (genereras i tinker; skrivs aldrig ut):

```php
$c = app(App\Provisioning\Clients\GitHubAppClient::class);
$uuid = (string) Illuminate\Support\Str::uuid();
$t0 = now();
$r = Illuminate\Support\Facades\Http::withToken($c->installationToken())->acceptJson()
    ->post('https://api.github.com/repos/HumblebragAB/computercat-release/actions/workflows/release-ios-spike.yml/dispatches',
        ['ref' => 'spike/ios-a', 'inputs' => ['game' => 'ccspike-ios', 'commit_sha' => '__SHA__' /* ersätts med SHA ur ids.env via sed */, 'release_uuid' => $uuid]]);
echo "dispatch status={$r->status()} uuid={$uuid}\n";
for ($i = 0; $i < 20; $i++) {
    sleep(3);
    $runs = Illuminate\Support\Facades\Http::withToken($c->installationToken())->acceptJson()
        ->get('https://api.github.com/repos/HumblebragAB/computercat-release/actions/runs', ['event' => 'workflow_dispatch', 'created' => '>='.$t0->copy()->subMinute()->toIso8601ZuluString()])->json('workflow_runs') ?? [];
    $hit = collect($runs)->first(fn ($run) => str_contains($run['name'] ?? '', $uuid));
    if ($hit) { echo 'hittad efter '.(($i + 1) * 3)." s: id={$hit['id']} name={$hit['name']} status={$hit['status']}\n"; break; }
}
```

Skriv koden till `$SCRATCH/tinker/dispatch.php`, kör `sed "s/__SHA__/$SHA/" $SCRATCH/tinker/dispatch.php > $SCRATCH/tinker/run.php && prodtinker $SCRATCH/tinker/run.php`. Kör en gång till med `return_run_details` i kroppen (G1b). Spara uuid och run-id i `ids.env`. Förväntat: `dispatch status=204` (svaret har ingen kropp och inget run-id; därför `run-name`) och körningen hittad inom några sekunder. 403: behörigheten saknas (Task 1).

- [ ] **Steg 2:** Följ körningen (`gh run watch <id> -R HumblebragAB/computercat-release`) och läs loggen för stegen G3–G6 med `gh run view <id> --log | grep -E 'andra repo|env-träffar|disk-träffar|App.app|xcode i build|du -sh|^.*M\s'`. Förväntat: G3 `exit=1`, G4 `0`/`0`, G5 `App.app` och storlek, G6 bara `App.xcarchive` i katalogen. Skriv ut aldrig hela loggen.

- [ ] **Steg 3:** Tider och kostnad: `gh api repos/HumblebragAB/computercat-release/actions/runs/<id>/timing --jq .run_duration_ms` och jobbens start/slut. Läs organisationens Actions-förbrukning med Eriks `gh` (kräver scope `admin:org`; saknas det: AskUserQuestion) och notera minuter per körning och multiplikatorn för macOS som GitHub anger i dag.

## Task 5: Städning och bilaga

- [ ] **Steg 1:** Städa på sparade id: `[ "$(gh api repos/HumblebragAB/ccspike-ios --jq .id)" = "$REPO_ID" ] && gh api -X PATCH repos/HumblebragAB/ccspike-ios -F archived=true`; ta bort repot ur installationen (Chrome); öppna en PR som tar bort spikflödet från `main` i släpprepot (Erik mergar); radera grenen `spike/ios-a` bara om dess sha är den spiken pushade; `rm -rf "$KEEP" $SCRATCH/ios-a/local.xcarchive`. Hemligheterna `CC_READER_APP_ID`/`CC_READER_PRIVATE_KEY` och Actions-behörigheten står kvar (det riktiga flödet behöver dem).
- [ ] **Steg 2:** Ny bilaga `docs/superpowers/specs/bilagor/2026-10-15-github-actions-ios.md` med G1–G7: exakta statuskoder, fältnamn, tider, Xcode-version, Capacitor-version och pakethanterare, storlek, kostnad per körning, och vad som ändrar specen.
- [ ] **Steg 3:** Commit i worktreen. Sedan: plan för spike B (Apple: nyckelns roll, profiler och certifikat via API, signering i jobb 2, TestFlight) när Erik lagt nycklarna i valvet, och därefter bygget av etapp 2.

## Review Focus

- En utcheckningstoken som når mer än spelrepot (G3) eller en appnyckel som syns för spelets byggsteg (G4): då håller inte alternativ 1:s löfte; stanna och fråga Erik innan något byggs vidare.
- Dispatch utan run-id: två samtidiga dispatcher måste skiljas åt; `release_uuid` i namnet är enda nyckeln, så sökningen måste vara exakt (`str_contains` på uuid, inte på spelet).
- Osignerad arkivering som lyckas lokalt men faller på runnern (annan Xcode): runnerns version noteras och låses i det riktiga flödet.
- Kostnad: macOS-minuter i privat repo kan ta slut; en körning som avbryts av det ska bli `failed` med orsak (spec "Statusläsning").
- Hashkontrollen av `cc-gate.mjs` får inte bli en tyst varning: `shasum -c` ska fälla steget.
