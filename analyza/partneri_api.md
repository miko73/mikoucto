# Fáze 5: Partneři

První doménovou funkcí je evidence partnerů oddělená po účetních jednotkách.
API Platform používá DTO, vlastní state provider a processor nad Doctrine
DBAL. Databázové změny provádí migrace `20261102020000`.

## 1. Příprava a ověření

Předpoklady: dokončené etapy 1–4, existující `mikoucto_test`, uložená hesla
rolí a běžící SSH tunel. Z kořene repozitáře spusťte:

```powershell
.\tools\dev\12-partneri-priprava.ps1
.\tools\dev\13-partneri-test.ps1
```

Skript 12 nainstaluje závislosti podle lockfilu a aplikuje migrace do
**testovací DB**. Skript 13 ověří dosavadní backend, partnery i izolaci dat
a vytvoří OpenAPI export. Registrace Entra pro automatické testy není
potřeba; podpisové klíče nahrazují pouze testy. Očekávaný konec: `Faze 5 OK`.

Při přípravě prošlo 52 testů backendu a 28 databázových scénářů na Google
PostgreSQL 16. Ověřen je i export OpenAPI a jeho chráněné HTTP zpřístupnění.
Partnerská migrace byla aplikovaná pouze do `mikoucto_test`.

Samostatně lze spustit:

```powershell
.\tools\dev\04-backend-test.ps1
.\tools\dev\07-db-test.ps1 -Filter PartnersTest
.\tools\dev\14-openapi.ps1
```

Pro skutečné použití aplikujte ověřené migrace do vývojové DB:

```powershell
.\tools\dev\12-partneri-priprava.ps1 -Target Development
```

Tento krok je explicitní: běžný testovací skript do `playground` nezapisuje.
Pro ruční přístup k API dokončete [Entra nastavení](entra_nastaveni.md),
založte uživatele a členství a spusťte backend:

```powershell
.\tools\dev\03-backend.ps1
```

Živé přihlášení přes MSAL z fáze 4 zůstává oddělené. Webové rozhraní partnerů
je další etapa; nyní je připravené API a jeho testy.

## 2. Datový model a oprávnění

`core.partners` obsahuje serverem vytvořené UUID v7, účetní jednotku, obchodní
údaje, příznak `active`, verzi a časy vytvoření/změny. Každý partner patří
právě jedné jednotce; přesun změnou jejího ID je zakázaný i databázovým triggerem.

| Pole JSON | Význam a validace |
| --- | --- |
| `name` | Povinný název, 1–200 znaků po ořezání |
| `registrationNumber` | Nepovinné registrační číslo; pro CZ přesně 8 číslic, zahraniční nejvýše 32 znaků |
| `vatNumber` | Nepovinné DIČ/VAT ID, základní formát prefixu země a alfanumerických znaků |
| `countryCode` | ISO kód země, výchozí `CZ`; normalizuje se na velká písmena |
| `street`, `city`, `postalCode` | Nepovinná adresa, nejvýše 200 / 120 / 20 znaků |
| `email` | Nepovinný e-mail, kontrola formátu, nejvýše 254 znaků |
| `phone` | Nepovinný kontakt, nejvýše 40 znaků |
| `active` | Boolean, výchozí `true`; `false` partnera deaktivuje |

`id`, `accountingUnitId`, `version`, `createdAt`, `updatedAt` jsou výstupní
pole spravovaná serverem. Vstup je přijímat nesmí. Neznámá pole a nesprávné
JSON typy se odmítnou. Volitelné texty lze vymazat hodnotou `null`; prázdné
volitelné texty se ukládají jako `null`. Název, země a aktivita nemohou být `null`.

Registrační číslo je jedinečné v kombinaci jednotka + země + číslo. Dvě
jednotky mohou vést téhož partnera nezávisle. Bez registračního čísla lze
evidovat například fyzickou osobu. Syntaktická validace nepotvrzuje existenci
v ARES, kontrolní součet IČO, registraci k DPH ani spolehlivost plátce.
Napojení registrů tato fáze neobsahuje.

| Role v jednotce | Čtení | Vytvoření a změna | Historie |
| --- | --- | --- | --- |
| `administrator` | Ano | Ano | Ano |
| `accountant` | Ano | Ano | Ne |
| `auditor` | Ano | Ne | Ano |
| `reader` | Ano | Ne | Ne |

Migrace přidává `core.partners.read`, `core.partners.write` a
`core.partners.history`. Voter ověřuje oprávnění v jednotce; RLS znovu
kontroluje řádky při každém čtení a zápisu. Backend nastavuje identitu,
jednotku a auditní kontext pouze uvnitř transakce. Aplikační role nemá
povolený fyzický DELETE partnerů.

## 3. Endpointy

Všechny cesty jsou pod `http://localhost:7005/api/v1` a vyžadují access token
pro toto API. `{unit}` a `{id}` jsou UUID.

| Metoda a cesta | Výsledek |
| --- | --- |
| `GET /accounting-units/{unit}/partners` | Pole partnerů, stránkování a filtry |
| `POST /accounting-units/{unit}/partners` | Vytvoření, HTTP 201, `ETag` a `Location` |
| `GET /accounting-units/{unit}/partners/{id}` | Detail a silný `ETag` |
| `PATCH /accounting-units/{unit}/partners/{id}` | Částečná změna s `If-Match`, HTTP 200 a aktuální `ETag` |
| `GET /accounting-units/{unit}/partners/{id}/history` | Stránkovaná historie z auditu |

`DELETE` a `PUT` nejsou publikované. Deaktivace je běžný PATCH s
`{"active":false}` a aktuálním `If-Match`. Detail neaktivního partnera zůstává
dostupný pro dohledání vazeb a historie.

### Seznam a filtry

Podporované parametry:

- `page`: celé číslo od 1, nejvýše 100000.
- `itemsPerPage`: 1–100, výchozí 20.
- `search`: nejvýše 100 znaků, hledá v názvu a registračním čísle bez rozlišení velikosti písmen.
- `active`: `true` nebo `false`; bez parametru se vrací aktivní i neaktivní.

Výsledek je JSON pole, počet všech odpovídajících řádků je v hlavičce
`X-Total-Count`. Řazení je stabilní podle názvu a ID. Znaky `%` a `_` se
v hledání chápou jako doslovný text. Neznámé filtry se odmítnou.

### Vytvoření a idempotence

Příklad těla:

```json
{
  "name": "Testovací partner",
  "countryCode": "CZ",
  "city": "Praha",
  "email": "partner@example.com"
}
```

Hlavičky:

```http
Authorization: Bearer <access_token>
Content-Type: application/json
Idempotency-Key: <nove-uuid-pro-tento-pozadavek>
```

Klíč má 1–128 znaků z písmen, číslic, `.`, `_`, `:`, `-`. Stejný klíč
se stejným normalizovaným tělem, uživatelem a jednotkou po dobu 24 hodin
vrátí původní odpověď HTTP 201 a `Idempotency-Replayed: true`. První odpověď
má tuto hlavičku `false`. Opakování nevytvoří dalšího partnera ani auditní zápis.
Změněné tělo se stejným platným klíčem vrátí HTTP 409.

Vrací se uložená odpověď vytvoření, i když se partner mezitím změnil; pro
aktuální stav použijte GET detailu. Po vypršení klíče je stejné jméno klíče
novým požadavkem. Unikátní registrační číslo nadále brání duplicitě entity.

`core.idempotency_keys` je chráněná RLS podle jednotky a uživatele. Datový
zápis, audit a evidence klíče jsou v jedné transakci. Opakování stejného
klíče se serializuje transakčním advisory zámkem. Expirace sama řádek nemaže;
údržbu nevyužitých expirovaných klíčů doplní provozní nástroje.

### Změna a souběh

GET detailu a zápis vracejí například:

```http
ETag: "0192e000-0000-7000-8000-000000000001:1"
```

Při částečné změně pošlete přesně tuto hodnotu:

```http
PATCH /api/v1/accounting-units/<unit>/partners/<id>
Authorization: Bearer <access_token>
Content-Type: application/merge-patch+json
If-Match: "0192e000-0000-7000-8000-000000000001:1"

{"city":"Brno","email":null}
```

Chybějící `If-Match` vrací 428. Zastaralá verze nebo ETag jiného partnera
vrací 412; klient musí znovu načíst detail a nabídnout uživateli porovnání.
Wildcard `*`, slabé ETagy a jiný formát se odmítnou. SQL UPDATE kontroluje
verzi přímo v podmínce, takže souběžná změna nemůže přepsat již potvrzený zápis.

Skutečná změna zvýší verzi a aktualizuje čas změny. PATCH bez věcné změny
vrátí stejnou verzi bez dalšího auditu. Neodeslaná pole zůstávají zachovaná;
serverová identita partnera a účetní jednotka jsou neměnné.

### Historie

Historie podporuje `page` a `itemsPerPage` se stejnými limity. Vrací objekt
`items`, `total`, `page`, `itemsPerPage`. Položka obsahuje čas, operaci,
interní ID uživatele, `request_id`, stará a nová data a změněné sloupce.
Audit používá databázové názvy sloupců ve `snake_case`. Historií jiné
jednotky se nelze dostat k cizímu partnerovi.

## 4. Chyby

| HTTP | Význam |
| --- | --- |
| 400 | Chybný formát požadavku, neznámé pole, chybějící klíč nebo chybný ETag |
| 401 | Chybějící/neplatný access token |
| 403 | Chybějící oprávnění v jednotce |
| 404 | Partner není v povolené jednotce nalezen |
| 409 | Duplicitní registrační číslo nebo konflikt idempotentního požadavku |
| 412 | Verze partnera se změnila |
| 422 | Neplatné hodnoty, odpověď obsahuje `violations` s polem a zprávou |
| 428 | Chybí `If-Match` |

Chyby mají `application/problem+json`. `X-Request-Id` propojuje požadavek,
odpověď a audit. Interní chyby DB, tokeny a připojovací údaje se klientovi nevracejí.

## 5. OpenAPI a další etapa

```powershell
.\tools\dev\14-openapi.ps1
```

Export **OpenAPI 3.1** je v `shared/openapi/partners.json`. Obsahuje partnerské
operace, vstupní a výstupní schémata, Bearer autentizaci, `If-Match`,
`Idempotency-Key` a chybové statusy. Je dostupný také přihlášeným klientům na
`GET /api/docs.json`. Ručně implementované identity endpointy jsou zatím
popsané v návodu fáze 4, do tohoto partnerského exportu nejsou zahrnuté.

Fáze 6 naváže Vue rozhraním: výběr jednotky, seznam partnerů, formulář,
deaktivace, historie a řešení konfliktu při editaci.
