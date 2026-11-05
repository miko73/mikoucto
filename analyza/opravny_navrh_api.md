# Část 9.2 – navazující opravný návrh

Implementace a automatické ověření podle [plánu etapy 9](etapa9_plan.md)
jsou dokončené dne **5. 11. 2026**.
Navazuje na [stornovací API 9.1](storno_api.md). Webové obrazovky patří do 9.3.

## Příprava a ověření

Při běžícím projektovém SSH tunelu spusťte z kořene projektu:

```powershell
.\tools\ucto\18-opravny-navrh-priprava.ps1
.\tools\ucto\19-opravny-navrh-test.ps1
```

Příprava aplikuje migraci `Version20261105010000` a kontroluje aktuální verzi
výhradně na `mikoucto_test`. Ověření opakuje přípravu, spouští jednotkovou
a úplnou databázovou/HTTP regresi, kontroluje provedení povinných sad,
exportuje OpenAPI a generuje i kontroluje webové typy. Vyžaduje nainstalované
backendové a webové závislosti podle README. Selhání kteréhokoliv kroku
ukončí skript chybou; úspěch potvrzuje závěrečná zpráva `Cast 9.2 OK`.
Testy vytvářejí a uklízejí vlastní izolovaná data.
HTTP procesy testů souběhu nejprve provedou čtecí GET, aby příprava routování,
oprávnění a serializace nebyla součástí měřeného čekání. Před odemčením
bariéry se nadále vyžaduje prokázaná blokace všech procesů v PostgreSQL;
limit dosažení databázové bariéry (45 s), databázové časové limity
a kontroly účetních výsledků se nemění. Pro sestavení studených metadat
před ohlášením připravenosti má proces samostatný limit 180 s; připravený
proces může čekat na přípravu druhého nejvýše 240 s.

Nasazení do `playground` patří do části 9.4 s novou zálohou a zkouškou
obnovy podle [postupu nasazení](vyvoj_nasazeni.md).

## POST correction-draft

`POST /api/v1/accounting-units/{unit}/documents/{id}/correction-draft`

`id` je původní zaúčtovaný doklad, ke kterému už existuje stornovací
protizápis. Nelze použít rozpracovaný návrh, samotné storno ani dřívější
opravný doklad. Všechny tři doklady patří do stejné účetní jednotky.

Hlavičky:

- `Authorization: Bearer …`, `Content-Type: application/json`, `Accept: application/json`.
- `If-Match`: silný ETag původního dokladu z jeho GET.
- `Idempotency-Key`: klíč pro tuto operaci, nejvýše 128 znaků.

Tělo má právě dvě pole:

```json
{
  "periodId": "UUID otevřeného cílového období",
  "postingDate": "2027-02-05"
}
```

Datum musí patřit do zvoleného otevřeného období a nesmí předcházet
původnímu dokladu. Zdrojové období může být uzavřené. Důvod vazby vychází
z provedeného storna; nový důvod, účty, částky ani vazby se neposílají.
Parametry v URL se nepřijímají. Chybné nebo cizí vstupní pole vrací 422,
chybný tvar těla nebo parametr URL 400, chybějící ETag 428,
zastaralý ETag 412, nedostupný doklad 404 a účetní konflikt 409.

API a SQL vyžadují přístup do jednotky a `core.units.read`,
`core.ucto.documents.read` a `core.ucto.documents.write`. Založení návrhu
nevyžaduje právo stornovat ani zaúčtovat. Čtenář a auditor mohou číst vazby,
ale návrh nezaloží. Oprávnění se kontrolují i před opakováním požadavku.

První úspěch vrací **201**, nový Document ve stavu `draft`, jeho `ETag`,
`Location` a `Idempotency-Replayed: false`. Řádky mají nová ID, ale přesně
stejné pořadí, účty, texty a MD/DAL jako originál. Partner, text dokladu
a měna se také kopírují. Žádná strana se neprohazuje a částky neprocházejí
přes `float`. Návrh má `number: null` a není zaúčtovaný.
Při založení platí i kontroly běžných návrhů: kopírované účty musejí být
aktivní a účtovatelné. Příkaz sám jejich nastavení nemění.

Vytvoření návrhu, řádků, auditované vazby a záznamu požadavku je jedna
transakce. Při chybě se vrátí všechny její zápisy. Originál a protizápis
si zachovávají obsah, číslo, verzi i ETag. Založení nečerpá pořadové číslo.

## Úprava a samostatné zaúčtování

Návrh se upravuje běžným `PATCH /documents/{correctionId}` s jeho aktuálním
ETag. Lze změnit účty, částky, texty, partnera, datum i otevřené období.
Datum stále nesmí předcházet originálu. Vazby, autora ani číslo přes PATCH
měnit nelze. Stejně je odmítají obyčejné návrhy a účetní příkazy.

Nevyrovnaný návrh lze uložit. Založení i úpravy zůstávají bez vlivu na
JSON/CSV sestavy a návrh blokuje uzavření svého období.

Zaúčtování proběhne teprve samostatným `POST /documents/{correctionId}/post`
s aktuálním ETag, vlastním klíčem a tělem `{}`. Platí stávající oprávnění
a validace zaúčtování včetně vyrovnání, otevřeného období a aktivních
účtovatelných účtů. Neúspěch sestavy nezmění. Úspěch přidělí číslo a teprve
poté se oprava projeví v sestavách. Období bez dalších návrhů lze uzavřít.
Opravný doklad nelze znovu stornovat ani použít jako zdroj další opravy.

Příklad 100 → storno → oprava 80 ve stejném období dává celkové obraty
MD i DAL 280 a konečné strany 80. Je-li originál ve starém období a storno
s opravou v novém, staré období zůstane na 100. Nové období má obraty
180 / 180 a pohyby −20 / +20; automatický převod počátečních stavů se nedělá.

## Opakování a souběh

Stejný uživatel, jednotka, operace, klíč a normalizované hodnoty včetně
zdrojové verze vracejí po 24 hodin původní odpověď i status s
`Idempotency-Replayed: true`. Jiný obsah pod stejným klíčem vrací 409.
Uložená odpověď zůstává původním snímkem, i když už někdo návrh upravil
nebo zaúčtoval. Aktuální stav lze získat běžným GET dokladu.

Nový klíč, jiný uživatel nebo vypršení uložené odpovědi návrh znovu nezaloží.
Pokud původně požadované období a datum souhlasí, vrátí **200** a současný
stav existující opravy včetně jejího aktuálního ETag. To platí i po přesunu
návrhu do jiného období, jeho zaúčtování nebo uzavření období. Jiné vstupy
vracejí 409 s `Location` již existující opravy. Pozdější úpravy tak nikdy
nezpůsobí přepsání návrhu původní kopií.

Trvalá unikátní vazba v `ucto.document_corrections` zajišťuje nejvýše jednu
opravu ke zdroji. Pořadí zámků zachovává jednotku, klíč, období podle UUID
a doklady podle UUID; transakce vyžaduje `READ COMMITTED`.
Při souběhu se zavřením období buď vznikne návrh a uzavření selže, nebo se
období uzavře a založení selže bez nových zápisů.

## Vazby a historie

`GET /documents/{id}/relations` zůstává bez cache a nezávislý na ETag dokladu:

- Originál: `reversal` ukazuje na storno, `correction` na opravný doklad.
- Storno: `reverses` ukazuje na originál, `correction` na opravný doklad.
- Oprava: `corrects` ukazuje na originál, `reversal` na jeho storno.

Každá vazba nese ID, nullable číslo, aktuální období a datum, stav, důvod,
autora a čas vzniku dané vazby. Nevyplněné směry jsou null. Číslo a stav
opravy se po zaúčtování aktualizují při dalším načtení vazeb.

Historie všech tří dokladů obsahuje samostatnou událost vložení
`ucto.document_corrections`. Aplikační role nemůže vazbu vložit, měnit ani
smazat přímo. Složené cizí klíče chrání shodu jednotky a dvojice originál–storno;
SQL trigger ověřuje přesnou počáteční kopii i při obejití HTTP vrstvy.

## Záznam výsledků

Ověření dne **5. 11. 2026**:

- Migrace `Version20261105010000` na `mikoucto_test`, opakovaná příprava
  skriptem 18 a kontrola aktuální verze prošly.
- Jednotková sada: **140 testů, 410 kontrol**, úspěšně; prošla také znovu
  v úplném ověřovacím skriptu 19.
- První cílená DB/HTTP sada: **14 testů, 265 kontrol**, úspěšně
  (11 minut 50 sekund), včetně čtyř testů souběhu a obou příkladů z 9.1.
- Úplný DB/HTTP běh: **102 testů, 1 340 kontrol**, 100 testů úspěšných;
  dva testy souběhu (shodný klíč opravy a shodný klíč storna) překročily
  limit před dosažením databázové bariéry. Běh trval 55 minut 34 sekund.
  `CorrectionTest` prošel všech **9 testů, 186 kontrol** včetně podvržené
  kopie a neshodné dvojice originál–storno. Žádný test nebyl vynechán.
- Příprava HTTP procesů byla upravena na předběžný čtecí GET. Opakování
  všech **21 testů souběhu** potvrdilo úspěch všech čtyř testů opravy,
  čtyř testů návrhů, pěti testů zaúčtování a čtyř testů storna, včetně
  obou původních problémových případů. Jediná chyba tohoto opakování
  nastala v prvním HTTP procesu účetní osnovy při studené přípravě
  (`Accounting worker timeout at ready`), ještě před bariérou.
  Následně byly odděleny limity přípravy a doplněn úklid při selhání
  konstruktoru. Opakování tohoto případu s čerstvou cache prošlo:
  **1 test, 14 kontrol**, 1 minuta 9 sekund.
- Export účetního OpenAPI a následné `npm run generate` i
  `npm run typecheck` prošly nad novým kontraktem.

Všech **102 různých DB/HTTP scénářů** má úspěšné ověření: 81 nesouběžných
v úplném běhu a všech 21 souběžných v navazujících bězích po opravě přípravy.
Nejde o jediný bezchybný úplný běh; původní dva problémy a následné ověření
jsou výše výslovně zaznamenané. Změna po úplném běhu se týkala pouze
testovacích procesů; aplikační kód zůstal stejný.

Diagnostické JUnit reporty jsou v `backend/var/correction-focused.xml`,
`backend/var/accounting-tests-3b8ca851422947898e7a4eb7d95542fb.xml`,
`backend/var/correction-concurrency-final.xml` a
`backend/var/correction-cold-start-final.xml` (lokální soubory mimo Git).

Vývojová databáze `playground` nebyla měněna. Webová obsluha a živá přejímka
zůstávají částmi 9.3–9.4.
