# Účetní API – část 7.1

Aktuální úplný postup včetně zaúčtování a uzavírání je v [návodu 7.3](zauctovani_api.md). Tento dokument popisuje kontrakt předchozího řezu; nové příkazy a omezení zaúčtovaných/uzavřených objektů doplňuje 7.3.

Implementace: účty a otevřená účetní období. Schéma `ucto`, migrace
`App\Migrations\Version20261103010000`. Doklady, zaúčtování, uzavírání období
ani webové obrazovky tato část neobsahuje.

## Spuštění automatického ověření

Použijte existující projektové PHP, závislosti a uložená hesla rolí. Všechny
příkazy spouštějte z kořene projektu. Nová instalace se připravuje podle README.

1. V prvním okně ponechte běžet tunel (pokud již běží, nespouštějte další):

   ```powershell
   .\db\google\00-tunel.ps1
   ```

2. V druhém okně připravte výhradně testovací databázi:

   ```powershell
   .\tools\ucto\01-osnova-obdobi.ps1 -Target Test
   ```

3. Po úspěchu spusťte automatické ověření:

   ```powershell
   .\tools\ucto\02-osnova-obdobi-test.ps1
   ```

Skript znovu ověří migrace, provede jednotkové testy backendu, **celou**
integrační sadu a export účetního OpenAPI. Samostatně kontroluje, že proběhly
povinné testy `AccountingTest` a `AccountingConcurrencyTest`, žádný z nich
neselhal ani nebyl přeskočen. Při chybě skončí chybou; prázdná sada není úspěch.
Konečná zpráva je `Cast 7.1 OK: ... Playground nebyl zmenen.`

Testy vytvářejí vlastní jednotky a identity, používají skutečné DB role a
podepsané testovací tokeny. Běžící backend, web ani živé Entra přihlášení
nepotřebují. Všechny DB testy přijímají pouze `mikoucto_test`. Dokončení může
trvat několik minut. Testovací data se uklidí; audit operací zůstává zachovaný.
Nespouštějte současně jiné DB testy používající celkové snapshoty auditu.

Ve stávajících negativních partnerských testech se mohou vypsat hlášky
[critical] o odmítnutých polích nebo typech. Rozhoduje závěrečný výsledek
PHPUnit bez selhání a úspěch skriptu, nikoli samotná úroveň těchto logů.

Pokud starší verze sady na pomalejším počítači selhala v
`EntraTokenTest` / `not active yet`, příčinou byl čas vypočtený při načtení
PHPUnit data provideru. Test je opravený: relativní časy se vyhodnocují až
při spuštění konkrétního případu. Pravidla validace tokenů se nemění.
Po aktualizaci stačí znovu spustit `02-osnova-obdobi-test.ps1`.

Samostatná kontrola existujícího webu (neobsahuje účetní formuláře):

```powershell
$env:PLAYWRIGHT_CHANNEL='chromium'
.\tools\dev\18-web-test.ps1
```

Chromium musí být již nainstalované; přípravu prohlížeče popisuje `web.md`.
Samostatný export API: `.\tools\ucto\03-openapi.ps1` vytvoří
`shared/openapi/accounting.json` s jedenácti účetními cestami (od dodání 7.3), bez partnerských cest.

## Kontrakt a oprávnění

API používá stejný Entra Bearer access token a jednotky jako partnerský modul.
URL začíná `/api/v1/accounting-units/{unit}`. UUID jsou nezávislá na velikosti
písmen. Nové objekty mají serverové UUID v7 a `version=1`.

| Operace | Účty | Období |
| --- | --- | --- |
| GET seznam / POST vytvoření | `/accounts` | `/periods` |
| GET detail / PATCH změna | `/accounts/{id}` | `/periods/{id}` |
| GET historie | `/accounts/{id}/history` | `/periods/{id}/history` |

POST vyžaduje `Content-Type: application/json`, PATCH
`Content-Type: application/merge-patch+json`. Odpovědi mají `application/json`;
chyby `application/problem+json`. DELETE a změna stavu období nejsou dostupné.

| Role v dané jednotce | Čtení | Vytváření a změna | Historie |
| --- | --- | --- | --- |
| administrator | ano | ano | ano |
| accountant | ano | ne | ne |
| auditor | ano | ne | ano |
| reader | ano | ne | ne |

Pravidla odpovídají oprávněním `core.ucto.accounts.read/write/history` a
`core.ucto.periods.read/write/history`. DB role `mikoucto_app` je neprivilegovaná;
RLS omezuje jednotku i oprávnění. `mikoucto_read` nezapisuje. Auditní události
jsou v `core.audit_log` a respektují stávající omezení čtení historie.

## Účty

Příklad těla POST (nejde o účet předepsaný legislativou):

```json
{"code":"001","name":"Testovací účet","active":true,"postable":true,"parentId":null}
```

- `code` je povinný řetězec 1–20 číslic. Krajní mezery se oříznou, počáteční
  nuly zůstanou. Kód je jedinečný v jednotce; jiná jednotka jej může použít.
- `name` je povinný řetězec délky 1–200 po oříznutí krajních mezer.
- `active`, `postable` jsou skutečné JSON booleany, výchozí true.
  Hodnoty `"false"`, 0 a null se nepřevádějí na boolean.
- `parentId` je UUID existujícího účtu stejné jednotky nebo null, výchozí null.
  Nesmí vzniknout vlastní rodič ani cyklus, včetně souběžných změn přes SQL.
- V 7.1 lze změnit kód, název, aktivitu, účtovatelnost a rodiče. Deaktivace
  nemění potomky; `postable` není automaticky odvozené od existence potomků.

Výstup navíc obsahuje `id`, `accountingUnitId`, `version`, `createdAt`,
`updatedAt`. Tato pole a neznámé vlastnosti klient nesmí zadávat.

## Období

```json
{"name":"2026","startsOn":"2026-01-01","endsOn":"2026-12-31"}
```

Všechna tři pole jsou povinná. Název má délku 1–200 po oříznutí. Data jsou
platná kalendářní data `YYYY-MM-DD` v letech 0001–9999, bez času. Začátek ≤ konec,
obě hranice včetně. Jednodenní období je platné. Dvě období stejné jednotky
nesmějí obsahovat společný den, ani při souběžném INSERT/UPDATE.

Po období končícím 2026-12-31 lze založit další od 2027-01-01; další začínající
2026-12-31 se odmítne. Stejná data v jiné jednotce nekolidují.

Nové období má serverový `status="open"`. PATCH mění název nebo hranice
otevřeného období. `status`, ID, jednotka, verze a časové údaje nejsou vstupní
pole. Uzavírání a pravidla změn období s doklady patří do pozdější dodávky.

## Verze, idempotence a chyby

Detail, vytvoření a změna vracejí silný `ETag` ve tvaru `"<uuid>:<verze>"`.
POST vrátí 201 a `Location`. PATCH vyžaduje původní ETag v `If-Match`:
chybějící hlavička → 428, špatný formát → 400, cizí/starý ETag → 412.
Prázdný PATCH nebo bezezměnová úprava zachová verzi i audit. Ověření verze
probíhá v SQL; dvě změny se stejným ETag nepřepíšou jedna druhou.

POST vyžaduje `Idempotency-Key`: 1–128 znaků `A–Z`, `a–z`, číslice, `.`, `_`,
`:` nebo `-`. Klíč je omezen jednotkou, uživatelem a operací. Stejný klíč a
stejná normalizovaná data po dobu 24 hodin vracejí původní odpověď 201, ETag a
Location, i když byl objekt později upraven. Hlavička `Idempotency-Replayed`
je při prvním požadavku `false`, při opakování `true`. Jiná data → 409.

Po expiraci smí klíč vytvořit nový objekt; stále platí jedinečnost kódu a
zákaz překryvů. Nový objekt, audit a evidence klíče se potvrzují společně.
Při chybě nebo ztrátě odpovědi opakujte stejný požadavek se stejným klíčem.

| HTTP | Význam |
| --- | --- |
| 400 | Chybný JSON, datový typ, neznámé/serverové pole nebo chybějící idempotentní klíč |
| 401 | Chybějící nebo neplatné přihlášení |
| 403 | Chybí oprávnění do jednotky nebo k operaci |
| 404 | Objekt v povolené jednotce neexistuje; cizí objekt se nezpřístupní |
| 409 | Duplicitní kód, překryv, neplatný rodič/cyklus nebo konflikt idempotence |
| 412 / 428 | Zastaralý ETag / chybějící If-Match |
| 422 | Neplatná hodnota pole, délka nebo rozsah dat |
| 503 | Nedostupná databáze nebo přerušená transakce; úspěch není potvrzen |

Neúspěšný zápis nezanechá částečný objekt ani audit úspěšné změny. Při
souběžné přímé SQL práci může databáze transakci odmítnout také kvůli deadlocku;
volající provede rollback a případné opakování celé transakce.

## Seznamy a historie

Seznam vrací JSON pole a `X-Total-Count`. Parametry `page` od 1 (nejvýše 100000),
`itemsPerPage` od 1 do 100 (výchozí 20), `search` nejvýše 100 znaků.
Účty podporují `active=true|false` a hledání v kódu/názvu, řadí se podle kódu
jako řetězce a ID. Období hledají podle názvu, podporují `on=YYYY-MM-DD`
(období obsahující daný den) a řadí se podle začátku a ID. Neznámé parametry,
pole parametrů a neplatné hodnoty → 400. Znaky `%` a `_` se hledají doslovně.

Historie přijímá pouze stránkování a vrací `items`, `total`, `page`,
`itemsPerPage`, sestupně podle času a auditního ID; obsahuje autora,
request ID, operaci a původní/nová data. Pro čtení se ověřuje existence
objektu v jednotce i oprávnění k historii.

## Databázové předpoklady a nasazení

Migrační vlastník instaluje důvěryhodné PostgreSQL rozšíření `btree_gist`,
pokud chybí, a použije jeho UUID GiST operátor pro exclusion constraint.
Na současné testovací DB byla ověřena dostupnost trusted rozšíření.
Na serveru, kde instalace není dovolena, migrace skončí chybou a musí ji
předem připravit správce; zákaz překryvů se nesmí vypnout.

Změny hierarchie účtů používají transakční advisory zámek jednotky a
rekurzivní kontrolu v triggeru. Vyžadují izolaci READ COMMITTED, aby kontrola
po čekání viděla potvrzeného předchůdce; zápis v jiné izolaci se odmítne.
API používá stejný zámek před načtením záznamů. Přímý SQL klient musí
respektovat verze a auditní kontext a být připraven na odmítnutí transakce.

`playground` se testy nemění. Vývojové nasazení je samostatné: nejprve
záloha a doložená obnova do oddělené DB, potom explicitně
`01-osnova-obdobi.ps1 -Target Development` a živé ověření přes API.
Dokud tento postup neproběhne, nové funkce nepovažujte za nasazené do vývojové DB.

## Výsledky ověření 3. listopadu 2026

Ověřeno na této pracovní kopii s migrací `20261103010000`, PHP 8.4.27
a skutečnou Google PostgreSQL databází `mikoucto_test` přes existující tunel.

| Kontrola | Skutečný výsledek |
| --- | --- |
| `tools/ucto/02-osnova-obdobi-test.ps1` | Úspěch, konečná zpráva `Cast 7.1 OK`; ověřena také aktuálnost migrací a export účetního OpenAPI |
| Jednotkové testy backendu v této sadě | 58 testů, 129 assertions, bez selhání |
| Celá DB integrační sada | 40 testů, 387 assertions, bez selhání; integrační část trvala přibližně 9 minut 45 sekund |
| Povinné účetní testy v integrační sadě | 8 testů `AccountingTest` a 4 testy `AccountingConcurrencyTest`, žádný přeskočený |
| Doplňkový test rozšířené kontroly expirace klíče | 1 test, 34 assertions; po expiraci lze stejným klíčem vytvořit nový nekolidující účet/období |
| Doplňkový test OpenAPI po doplnění kontroly unikátních parametrů | 1 test, 29 assertions, bez selhání |
| `tools/dev/18-web-test.ps1` s Chromium | 13 jednotkových a 18 prohlížečových testů, typecheck a build úspěšné |
| Regenerace frontendových typů a build nad rozšířeným `partners.json` | Úspěch |
| PowerShell skripty 01–03 | Syntaxe úspěšně ověřena parserem; příprava, ověření a export provedeny také skutečným během |

Po celé sadě byly ještě zpřesněny dvě testovací kontroly (expirace a unikátní
OpenAPI parametry) a samostatně ověřeny podle řádků výše. Nejde o navýšení
počtu odlišných testů v celé sadě; samostatné běhy opakovaly příslušné testy.

**Neprovedeno:** migrace `playground`, její záloha/obnova a živý účetní
průchod s produkční Entra identitou. Účetní obrazovky, doklady a zaúčtování
patří do dalších částí a nejsou touto sadou vydávané za implementované.

Navazující část 7.2 (rozpracované doklady) je popsaná v [samostatném návodu](doklady_api.md). Její ověřovací skript 05 vyžaduje také skutečný běh dokladových testů. Ochrany přidané s 7.2 omezují změnu kódu aktuálně použitého účtu a hranic období s dokladem; aktuální chování má přednost před popisem možností změn v původním samostatném řezu 7.1.

Část 7.3 je dodaná: [zaúčtování a uzavření období](zauctovani_api.md). Aktuální export obsahuje 12 cest včetně deníku; skript 07 navíc vyžaduje provedení nových povinných testů. Starší výsledky a rozsah výše zachovávají historický stav příslušného řezu. Nový doklad zůstává draft, ale po serverovém příkazu může být posted s číslem a metadaty; období může být closed.
