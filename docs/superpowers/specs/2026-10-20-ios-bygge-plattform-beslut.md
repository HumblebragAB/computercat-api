# Beslut: var iOS-bygget körs

Datum: 2026-10-20. Status: beslutat av Erik.

## Bakgrund

GitHub Actions gratiskvot räcker inte för Humblebrags appar, och vi har fått köpa till minuter. Det som kostar är macOS-jobben, som räknas tio gånger mot kvoten. Frågan var om bygget kunde flyttas till Coolify eller till Xcode Cloud.

## Beslut

**ComputerCats iOS-bygge stannar i GitHub Actions**, i släpprepot `HumblebragAB/computercat-release` (`release-ios.yml`) enligt `2026-10-14-slappflode-design.md`, Del 2. Faktabankens, byggguidens och release-texternas beskrivning av iOS-bygget ändras inte. (Samma dag lades regeln att pusha varje färdigt steg till Demo till, i `process-pusha-varje-steg` och MCP-serverns instruktioner.)

**Släppflödet startas bara med `workflow_dispatch` från Computercat, när ett spel släpps.** Det får aldrig köras på push, pull request eller schema. Ett macOS-jobb som körs på varje push är precis den kostnad vi ville bort från, så lägg inte till en sådan trigger, inte heller som kontroll.

## Bortvalda alternativ

- **Coolify.** Servern kör Linux. Xcode kräver macOS, och Apples licens tillåter macOS bara på Apple-hårdvara. Coolify kan alltså inte bygga iOS. Android skulle gå, men Android-minuterna kostar lite.
- **Xcode Cloud.** 25 bygg-timmar i månaden ingår i Apple Developer Program. Det fanns två skäl att avstå:
  1. Ett Xcode Cloud-projekt (`ciProduct`) ser ut att behöva skapas för hand i Xcode eller App Store Connect, en gång per app. App Store Connect API:t kan starta byggen (`POST /v1/ciBuildRuns`) och läsa projekt, men jag hittade inget anrop som skapar ett projekt. Det skulle ge varje nytt spel ett manuellt steg, i en kedja som annars går helt utan människor. Det här är **inte verifierat** mot Apples dokumentation, bara slutsatsen av att ett sådant anrop inte gick att hitta.
  2. Xcode Cloud klonar och bygger spelrepot med sina egna behörigheter. Det passar inte modellen i slappflode-design, där signeringsnycklarna bara finns i släpprepot och spelets kod aldrig körs i samma jobb som de.

Om frågan kommer upp igen: gör först en spike som visar att ett Xcode Cloud-projekt kan skapas och kopplas till ett repo helt via API.

## Samma regel i andra repon

Glosis (`HumblebragAB/glosis-app`) fick samma dag villkor på sina workflows: macOS-jobb körs bara när iOS-filer ändrats, och release hoppar över ändringar som inte når appen. Se `docs/beslut.md` 2026-10-20 i det repot.
