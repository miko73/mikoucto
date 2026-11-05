# Část 9.1 – stornovací protizápis a JSON API

Implementace a automatické ověření podle [plánu etapy 9](etapa9_plan.md)
jsou dokončené dne **5. 11. 2026**.
Tato část přidává backend. Webové tlačítko a opravný návrh následují v 9.2–9.3.

## Příprava a ověření

V prvním okně PowerShellu spusťte obvyklý tunel a nechte jej běžet:

```powershell
.\db\google\00-tunel.ps1
```

Ve druhém okně, z kořene projektu:

```powershell
.\tools\ucto\16-storno-priprava.ps1
.\tools\ucto\17-storno-test.ps1
```

Oba příkazy používají pouze `mikoucto_test`. První aplikuje migraci
`Version20261104010000` a ověří opakovatelnost. Druhý opakuje přípravu,
spustí všechny backendové jednotkové i databázové testy, kontroluje skutečné
provedení povinných účetních sad, exportuje OpenAPI, z něj generuje webové
typy a spouští jejich TypeScript kontrolu. Vyžaduje proto také nainstalované
webové závislosti podle README. Skript při jakémkoliv
selhání skončí chybou. Testy zakládají a uklízejí vlastní izolovaná data.
Úspěch se pozná podle závěrečné zelené zprávy `Cast 9.1 OK`.

Vývojová databáze `playground` se těmito příkazy nemění. Nasazení nové
migrace na ni patří do [postupu se zálohou a zkouškou obnovy](vyvoj_nasazeni.md).

## Co přesně příkaz dělá

Zdroj musí být běžný zaúčtovaný doklad. Vznikne nový zaúčtovaný doklad
se stejnými účty, partnerem, texty a pořadím řádků. MD a DAL každého řádku
se přesně prohodí. Zdroj zůstane beze změny včetně stavu, verze a ETag.
Nový doklad dostane nové ID, nová ID řádků, vlastní číslo, autora a čas.
Důvod a trvalá vazba se ukládají zvlášť a jsou auditované.

Cílové období musí být otevřené a obsahovat zvolené datum. Datum nesmí
předcházet zdroji. Zdrojové období smí být uzavřené. Účty musí být aktivní
a účtovatelné. Příkaz jejich nastavení nemění. Celá operace je jedna transakce;
při chybě nevznikne částečný doklad, nevyčerpá se číslo ani nezůstane audit úspěchu.

Ke zdroji lze vytvořit nejvýše jeden protizápis. Stornovací doklad nelze
znovu stornovat. Částky zůstávají nezáporné a měna CZK. Zápisy zůstávají
v deníku, sestavách i CSV: původních MD 100 / DAL 100 spolu s protizápisem
dávají čistý pohyb nula, ale celkové obraty MD 200 / DAL 200.
Pohyb z jiného období se do počátečních stavů automaticky nepřenáší.

Regresní scénáře ověřují také původních 100,00, protizápis a následné
zaúčtování 80,00 existujícím příkazem pro návrhy. Samotné uložení návrhu
sestavy nezmění. Ve stejném období jsou výsledné celkové obraty MD i DAL
280,00 a konečné strany 80,00. Při opravě v následujícím období zůstane
původní období beze změny a nové vykáže pohyb −20,00 / +20,00.
Kontroly zahrnují JSON i CSV. Založení propojeného opravného návrhu
zůstává součástí 9.2.

## POST reverse

`POST /api/v1/accounting-units/{unit}/documents/{id}/reverse`

Hlavičky:

- `Authorization: Bearer …` – stávající přístupový token pro API.
- `Content-Type: application/json`, `Accept: application/json`.
- `If-Match` – přesný silný ETag původního dokladu z jeho GET.
- `Idempotency-Key` – nový klíč pro tuto zamýšlenou operaci, nejvýše 128 znaků.

Tělo obsahuje právě tato tři pole; identifikátor období se vezme z API období:

```json
{
  "periodId": "UUID otevřeného období",
  "postingDate": "2027-02-05",
  "reason": "Původní částka byla chybná."
}
```

`reason` má po oříznutí 1–1000 znaků. Datum je platné `YYYY-MM-DD`.
Účty, částky, ID nového dokladu, autor, stav ani číslo se neposílají.
Parametry v URL příkaz nepřijímá.

První úspěch vrátí **201** a celý nový doklad ve stávajícím formátu Document.
`Location` ukazuje na nový doklad; odpověď `ETag` patří jemu, nikoli zdroji.
`Idempotency-Replayed: false` označuje nové zpracování požadavku.

Stejný klíč stejného uživatele a jednotky se stejnými normalizovanými vstupy
včetně zdrojové verze vrátí po dobu 24 hodin původní tělo, status a hlavičky
s `Idempotency-Replayed: true`. Jiný obsah pod stejným klíčem skončí 409.
Oprávnění se kontrolují i při opakování.

Nový klíč, jiný uživatel nebo vypršení 24 hodin neumožní druhé storno:
shodné cílové období, datum a důvod vrátí existující protizápis se statusem
**200**, bez dalšího čísla či zaúčtování. To funguje i po pozdějším uzavření
cílového období. Jiné hodnoty vrátí 409 a `Location` existujícího protizápisu.
Zastaralý zdrojový ETag nadále vrací 412, kromě platného opakování uložené odpovědi.

| Stav | Význam |
| --- | --- |
| 200 | Již existuje shodný protizápis |
| 201 | Nový protizápis nebo opakování původní odpovědi 201 |
| 400 | Chybné tělo/hlavička, chybějící klíč, zakázaný parametr |
| 401 / 403 | Chybějící přihlášení / oprávnění |
| 404 | Zdroj není dostupný v této jednotce |
| 409 | Konflikt stavu, období, účtu nebo opakování |
| 412 / 428 | Zastaralý / chybějící ETag |
| 422 | Neplatné nebo nepovolené vstupní pole |

Administrátor a účetní mají nové `core.ucto.documents.reverse`. Současně se
vyžadují `core.units.read`, `core.ucto.documents.read`, `.write` a `.post`.
Kontroly jsou v HTTP vrstvě i SQL funkci. Aplikační role nemůže přímo měnit
vazby ani zaúčtované doklady.

## GET relations a historie

`GET /api/v1/accounting-units/{unit}/documents/{id}/relations`

Vyžaduje čtení dokladů v jednotce. Vrací `documentId`, `reversal` a `reverses`.
U zdroje je vyplněné `reversal`, u protizápisu `reverses`; bez vazby jsou obě
hodnoty `null`. Každá vazba obsahuje související `id`, `number`, `periodId`,
`postingDate` a společný `reason`, `createdBy`, `createdAt`.
Odpověď se neukládá do mezipaměti a nemá ETag původního dokladu.

Po ztrátě odpovědi na POST lze bezpečně zopakovat tentýž požadavek se stejným
klíčem nebo ověřit existující vazbu tímto GET. Obyčejný GET dokladu zachovává
dosavadní tvar; staré uložené odpovědi z jiných účetních příkazů zůstávají čitelné.

Historie obou dokladů zahrnuje samostatnou událost vložení
`ucto.document_reversals` s důvodem a oběma ID. Přístup k historii nadále
vyžaduje její dosavadní oprávnění; samotné právo stornovat je nerozšiřuje.

## Záznam výsledků

Ověření dne **5. 11. 2026**:

- `16-storno-priprava.ps1`: migrace a kontrola aktuální verze
  `Version20261104010000` na `mikoucto_test` prošly bez dalších změn schématu.
- Backendová jednotková sada: **126 testů, 374 kontrol**, úspěšně.
  Po izolaci cache prošel také samostatný opakovaný běh.
- Úplná databázová a HTTP regrese: **89 testů, 1 141 kontrol**, bez chyb
  a vynechaných testů; běh trval 43 minut 20 sekund.
- Z toho `ReversalTest`: **10 testů** a `ReversalConcurrencyTest`:
  **4 testy**. Zahrnují oba příklady opravy na 80,00, přesné nejvyšší částky,
  role, izolaci jednotek, idempotenci, audit, SQL ochrany, rollback a souběh.
- Export a kontrola účetního OpenAPI prošly. Následné `npm run generate`
  a `npm run typecheck` nad čerstvým kontraktem prošly.

Ověřovací skript nyní zahrnuje i webové typy. Jednotkové běhy používají
vlastní adresář cache, aby si při souběhu nesmazaly soubory kontejneru.

Vývojová databáze `playground` nebyla měněna.
Živý průchod přes Entra a prohlížeč není součástí backendové části 9.1.
