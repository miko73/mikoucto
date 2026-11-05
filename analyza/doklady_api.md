# Rozpracované doklady – API a ověření části 7.2

Aktuální úplný postup včetně zaúčtování a uzavírání je v [návodu 7.3](zauctovani_api.md). Tento dokument popisuje kontrakt předchozího řezu; nové příkazy a omezení zaúčtovaných/uzavřených objektů doplňuje 7.3.

V projektu je implementace hlavičky dokladu a řádků MD/DAL, jejich společné
verze, auditu, idempotence a přesných součtů. Nová migrace je
`App\Migrations\Version20261103020000`, doplněná migrační ochranou izolace
`Version20261103020100`. Navazuje na účty a období části 7.1.

## Postup automatického ověření

Potřebujete připravený backend, `mikoucto_test`, uložená hesla projektových
rolí a tunel. Testy si vytvoří jednotky, účty, období, partnery a identity.
Běžící web, backendový HTTP server ani živé přihlášení Microsoft nevyžadují.

1. V prvním okně PowerShellu z kořene projektu ponechte běžet tunel.
   Pokud už běží, další nespouštějte:

   ```powershell
   .\db\google\00-tunel.ps1
   ```

2. V druhém okně, také z kořene projektu, připravte testovací databázi:

   ```powershell
   .\tools\ucto\04-doklady-priprava.ps1 -Target Test
   ```

   Očekávejte aktuální migraci `Version20261103020100` a
   `Rozpracovane doklady pripraveny: Test`.

3. Potom spusťte automatické ověření:

   ```powershell
   .\tools\ucto\05-doklady-test.ps1
   ```

   Proběhnou jednotkové testy backendu, celá integrační DB sada, ověření
   aktuálnosti migrací a export OpenAPI. Skript zkontroluje skutečný běh
   čtyř povinných tříd: AccountingTest, AccountingConcurrencyTest,
   DraftDocumentsTest a DraftConcurrencyTest. Prázdná nebo přeskočená
   povinná sada je chyba. Úspěšný závěr začíná `Cast 7.2 OK`.

Testy mohou trvat více než deset minut. Nespouštějte vedle nich jiné DB
sady s celkovými snapshoty auditu. Oficiální vstupy do DB testů nyní
používají společný databázový zámek: druhá sada je před spuštěním odmítnuta
a příkaz lze zopakovat po skončení první. Úklid odstraní vlastní testovací data,
audit operací zůstane. Známé `[critical]` hlášky negativních partnerských
testů neposuzujte samostatně; rozhoduje závěrečný PHPUnit výsledek a skript.

Webovou regresi lze samostatně zopakovat podle `ucto_api.md` a `web.md`.
Část 7.2 poskytuje API; formuláře dokladů patří do navazující části 7.4.

## Datový kontrakt

URL: `/api/v1/accounting-units/{unit}/documents`.
POST vytvoří návrh; GET vrátí seznam. GET/PATCH `/{id}` načte/změní celek,
GET `/{id}/history` vrátí historii. DELETE a zaúčtování nejsou operace 7.2.

Příklad vstupu (zástupné hodnoty UUID nahradí konkrétní objekty jednotky):

```json
{
  "periodId": "<UUID_OBDOBI>",
  "postingDate": "2026-11-03",
  "text": "Rozpracovaný doklad",
  "partnerId": null,
  "lines": [
    {"accountId": "<UUID_UCTU_A>", "text": "MD", "md": "100.00", "dal": "0.00"},
    {"accountId": "<UUID_UCTU_B>", "text": "DAL", "md": "0.00", "dal": "99.00"}
  ]
}
```

- `periodId`, `postingDate` a `text` jsou povinné. Období musí být otevřené,
  datum uvnitř jeho hranic včetně. Text má 1–2000 znaků po oříznutí.
- Partner je nepovinný, výchozí null. Musí patřit do téže jednotky; může být
  deaktivovaný. Klient může vazbu odstranit přes `partnerId:null`.
- `lines` je pole 0–500 objektů, výchozí prázdné. Návrh smí mít i jeden řádek.
  Účet musí být ze stejné jednotky, aktivní a účtovatelný.
- Řádek má povinné accountId, md, dal a volitelný text do 500 znaků,
  výchozí prázdný. Právě jedna strana je kladná a druhá nulová.
- UUID v7, jednotka, čísla pořadí řádků, verze, stav, měna, konečné číslo,
  časy a součty určuje server. Neznámá a serverová vstupní pole se odmítají.

Vstupní částky jsou řetězce: `"1"` nebo `"1.2"` se normalizují na `"1.00"`
a `"1.20"`. Nejvyšší hodnota strany řádku je `"9999999999999999.99"`.
Odmítají se čísla JSON, znaménka, exponent, čárka, mezery, počáteční nuly
jako `"01.00"` a více než dvě místa, včetně `"1.000"`. K žádnému tichému
zaokrouhlení nedochází. Databáze používá numeric bez typmodu s kontrolou
rozsahu a scale; PHP počítá s desetinnými řetězci bez float a přetečení.

Detail obsahuje řádky s jejich serverovým ID a lineNo, `totalMd`, `totalDal`,
`difference` (MD − DAL) jako přesné řetězce se dvěma místy a `balanced`.
Součet může překročit rozsah jednoho řádku. Návrh 100.00/99.00 se uloží jako
`draft`, balanced=false, difference=1.00. Prázdný návrh má balanced=true,
ale tím není způsobilý k zaúčtování. Stav zůstává draft, currency=CZK,
number=null. Seznam obsahuje součty a hlavičky bez řádků.

## Změny, verze a opakování

PATCH vyžaduje `Content-Type: application/merge-patch+json` a silný If-Match
vrácený při načtení. Chybějící pole zachová hodnotu. Přítomné `lines` nahrazuje
celou sadu, `lines:[]` ji vyprázdní; nejde o připojení jednotlivého řádku.
Řádky ve vstupu nemají ID. Skutečně změněná sada dostane nové serverové UUID,
změna pouze hlavičky zachová řádky. Stejná normalizovaná data a pořadí zachovají
verzi, časy, ID řádků i audit. Verze patří celému dokladu; starý If-Match
nepřepíše novější hlavičku ani řádky. Chybějící If-Match → 428, starý → 412.

POST vyžaduje Idempotency-Key s pravidly části 7.1: klíč v jednotce a pro
uživatele/operaci, 24 hodin, stejná normalizovaná data vracejí původní
odpověď 201, Location, ETag a Idempotency-Replayed=true. Platí i po následné
úpravě návrhu. Jiná data se stejným čerstvým klíčem → 409; po expiraci může
vzniknout nový návrh. Doklad, řádky, audit i evidence klíče patří do stejné
transakce. Po výpadku odpovědi opakujte stejný POST se stejným klíčem.

## Oprávnění a databázová ochrana

Administrátor a účetní vytvářejí a mění návrhy, všechny čtyři role čtou.
Historii čtou administrátor a auditor. Používá se stávající ověření Entra
a oprávnění `core.ucto.documents.read/write/history` v konkrétní jednotce.

`ucto.documents` a `ucto.document_lines` mají RLS a audit. Aplikační role
nemá jejich přímý INSERT/UPDATE/DELETE. Zapisuje přes `ucto.save_draft`,
která ověřuje identitu z kontextu, oprávnění, jednotku, verzi, všechny vazby,
období, datum a účty a zapisuje celek atomicky. SECURITY DEFINER má bezpečný
search_path, veřejné spouštění je zakázané a uvnitř funkce jsou explicitní
kontroly práv; nespoléhá na RLS vlastníka.

Zápis používá jednotkový zámek účtů, zámek klíče při POST, zámky období
v pořadí UUID a zámek hlavičky. Izolace zápisu návrhu i změny hranic období je READ COMMITTED. Po čekání se
pravidla znovu kontrolují. Chyba vrací zpět i částečnou výměnu řádků a audit.

Kód účtu s aktuálními řádky dokladů a hranice období s dokladem jsou chráněné
proti změně také při souběhu. Název lze změnit. Účet lze deaktivovat;
při příštím uložení návrhu je potřeba jeho řádek odebrat nebo nahradit.
Partnera lze deaktivovat bez zrušení vazby.

## Seznamy, historie a chyby

Seznam: page od 1 do 100000, itemsPerPage 1–100 (výchozí 20), search do 100
znaků, periodId, from a to (data YYYY-MM-DD, od ≤ do). Řazení postingDate DESC,
id DESC, hlavička X-Total-Count. Neznámé a neplatné parametry se odmítají.
Historie přijímá jen stránkování a obsahuje události hlavičky i starých
a nových řádků, autora, request ID a stará/nová data. Více auditních řádků
jedné transakce nejsou duplicitní doklady. Částky md/dal v původních a nových
auditech řádků se vracejí jako řetězce, aby je PHP nepřevedlo na float.

400: formát/typy, neznámá pole, chybějící klíč. 401: přihlášení.
403: oprávnění. 404: nedostupný doklad. 422: validace hodnot/částek.
409: konflikt vazeb, pravidel nebo klíče. 412/428: verze/If-Match.
503: nedostupná DB nebo přerušená transakce. Nepotvrzuje úspěšný zápis.

Účetní OpenAPI se exportuje přes `tools/ucto/03-openapi.ps1` do
`shared/openapi/accounting.json` s jedenácti cestami včetně příkazů zaúčtování a uzavření období.

Testování probíhá pouze v `mikoucto_test`. Migrace playground, záloha a
ověřená obnova i živé účetní použití jsou samostatné kroky. Tato dodávka
neprovádí zaúčtování, uzamčení období ani účetní webové formuláře.

## Výsledky ověření implementace

Ověřeno na pracovní kopii s migracemi `20261103020000` a
`20261103020100`, PHP 8.4.27 a skutečnou databází `mikoucto_test`.

| Kontrola | Skutečný výsledek |
| --- | --- |
| `tools/ucto/05-doklady-test.ps1` | Úspěch, konečná zpráva `Cast 7.2 OK`; aktuální migrace a export OpenAPI ověřeny |
| Jednotkové testy backendu | 72 testů, 170 assertions, bez selhání |
| Celá DB integrační sada v samostatném běhu | 51 testů, 531 assertions, bez selhání; integrační část 16 minut 35 sekund |
| Povinné třídy v reportu | AccountingTest 8, AccountingConcurrencyTest 4, DraftDocumentsTest 7, DraftConcurrencyTest 4; žádný povinný test přeskočený |
| Doplňkový běh poslední rozšířené kontroly velkých částek | 1 test, 27 assertions; přesnost ověřena v historii dokladu i obecném auditním endpointu, bez selhání |
| Webová regrese nad rozšířeným OpenAPI | 13 jednotkových a 18 prohlížečových testů, generování typů, typecheck a build úspěšné |
| Ochrana souběhu celých DB sad | Druhá sada odmítnuta před spuštěním testů s exit 2; po uvolnění lze testovací CLI spustit |
| PowerShell skripty | Syntaxe ověřena parserem, příprava a testovací/exportní workflow ověřeny skutečným během |

Poslední kontrola obecného auditu byla doplněna během běhu celé sady,
a proto byla po jeho dokončení samostatně zopakována. Nejde o další
odlišný test; jde o rozšířenou variantu již existujícího testu.

Během přípravy byly odstraněny kolize paralelních sad nad společným auditem
a časování přípravy HTTP subprocessů. Podpisový klíč vznikne před signálem
připravenosti, oba procesy se připraví před společným startem a čekání má
časový limit. Při selhání hlavního ověřovacího skriptu se JUnit report
ponechá v `backend/var` a jeho cesta se vypíše pro diagnostiku.

**Samostatně neprovedeno:** migrace `playground`, ověření obnovy její zálohy,
živý účetní průchod s Entra identitou, zaúčtování a účetní web. Výsledky
výše dokládají implementaci a testovací ověření části 7.2.

Část 7.3 je dodaná: [zaúčtování a uzavření období](zauctovani_api.md). Aktuální export obsahuje 12 cest včetně deníku; skript 07 navíc vyžaduje provedení nových povinných testů. Starší výsledky a rozsah výše zachovávají historický stav příslušného řezu. Nový doklad zůstává draft, ale po serverovém příkazu může být posted s číslem a metadaty; období může být closed.
