# Návrh technologického řešení

Dokument popisuje navržený technologický stack systému `mikoucto`. Systém má čtyři části: databázi v cloudu, backend s API, webový frontend a lokálního klienta v Pythonu. Navazuje na [datový model GnuCash](gnucash_datovy_model.md) a [návrh úprav pro českou legislativu](gnucash_upravy_cz_legislativa.md), ze kterých přebírá účetní koncepty a požadavky na auditní stopu.

Datum původního návrhu: 29. října 2026. Aktualizace: 2. listopadu 2026.
Aktuální vývojové prostředí: **PostgreSQL 16 na Google Cloud VM**, databáze
`playground`, připojení přes SSH tunel na `localhost:5432`. Postup implementace
a ověření jednotlivých etap je v [implementačním plánu](implementace.md).

## 1. Požadavky

| # | Požadavek | Kde se řeší |
| --- | --- | --- |
| R1 | Transakčně ošetřené změny dat | DB transakce, optimistické zamykání, idempotence (kap. 3) |
| R2 | Backend v PHP oddělený od frontendu API rozhraním | REST API s OpenAPI (kap. 4) |
| R3 | Správa uživatelů a rolí | Autentizace, role a oprávnění (kap. 5) |
| R4 | Rozšiřitelnost o moduly | Modulární monolit (kap. 6) |
| R5 | Sledování změn podle uživatelů | Auditní log v databázi (kap. 7) |
| R6 | Export a import dat v logickém kontextu podle vazeb | Exportní profily nad grafem vazeb (kap. 8) |
| R7 | Cloudová databáze, co nejlevnější a přenositelná | Standardní PostgreSQL 16; vývoj na Google Cloud VM přes SSH tunel (kap. 2) |
| R8 | Lokální vývoj: web na portu 8005, backend na 7005 | Kap. 9 |
| R9 | Lokální klient v Pythonu nad stejným API | Kap. 4.4 |

## 2. Databáze

### 2.1 Volba: PostgreSQL

Zvoleno: **PostgreSQL 16** používaný ve standardním rozsahu. Vývoj běží na
existující Google Cloud VM `pg-free` v projektu `pg-free-michal`, zóna
`us-central1-a`, databáze `playground`. Windows klient i backend se připojují
přes SSH tunel na `localhost:5432`, `sslmode=disable`; přenos chrání SSH.
Instalaci popisuje [návod Google Cloud](PostgreSQL%20na%20Google%20Cloud%20%28free%20e2-micro%29.md).

| Kritérium | PostgreSQL | MySQL / MariaDB | Azure SQL (MS SQL) |
| --- | --- | --- | --- |
| Transakce a izolace včetně `SERIALIZABLE` | Plná podpora | `SERIALIZABLE` přes zámky, horší souběh | Plná podpora |
| Odložená kontrola omezení (např. MD = Dal na konci transakce) | `DEFERRABLE` constraint triggery | Ne | Omezeně |
| Přesná čísla pro částky | `numeric` | `decimal` | `decimal` |
| JSON pro auditní log | `jsonb` s indexy | `json` | `nvarchar` + funkce |
| Schémata pro oddělení modulů | Ano | Databáze místo schémat | Ano |
| Row-Level Security | Ano | Ne | Ano |
| Podpora PHP (Doctrine) a Pythonu | Výborná | Výborná | Dobrá, ovladače složitější na Windows |
| Cena v Azure pro vývoj | Nízká | Nízká | Vyšší |

Univerzálnost zajišťuje samotný PostgreSQL. Nabízí ho všichni velcí poskytovatelé cloudu i levné VPS a lze ho spustit lokálně. Přechod mezi poskytovateli znamená `pg_dump` / `pg_restore` a změnu `DATABASE_URL`.

### 2.2 Zásady návrhu schématu

- **Primární klíče UUID v7** (`uuid`): generuje je aplikace, jsou časově řazené a při importu mezi prostředími nekolidují.
- **Peněžní částky** jako `numeric(18,2)`, kurzy jako `numeric(18,6)`. Nikdy `float`.
- **Kalendářní datum** (`date`) odděleně od časového okamžiku (`timestamptz`). Účetní datum tak nebude záviset na časovém pásmu, což je slabina GnuCash.
- **Cizí klíče deklarované v DB** s `ON DELETE RESTRICT`. Graf vazeb pro export (kap. 8) se z nich čte automaticky.
- **Sloupec `version integer`** v každé editovatelné tabulce pro optimistické zamykání.
- **Bez fyzického mazání** u účetních záznamů: storno a opravné zápisy; u číselníků příznak `active` nebo `valid_from`/`valid_to`.
- **Jedno schéma na modul**: `core` (uživatelé, role, audit), `ucto` (účetnictví), `fakturace`, `majetek`…
- **Migrace** pouze přes Doctrine Migrations v repozitáři. Žádné ruční změny schématu.

### 2.3 Databázové role

| Role | Oprávnění | Použití |
| --- | --- | --- |
| `mikoucto_owner` | Vlastník schémat | Jen migrace |
| `mikoucto_app` | `SELECT/INSERT/UPDATE` na datové tabulky, audit jen čte; zápis provádí trigger, bez plošného `DELETE` | Backend |
| `mikoucto_read` | `SELECT` | Reporty, napojení na Fabric/Power BI |

Klienti (web, Python) se k databázi nikdy nepřipojují přímo, pouze přes API.

### 2.4 Poskytovatel a provoz

Pro vývoj je zvolená existující **Google Cloud Compute Engine VM `pg-free`**
(e2-micro), projekt `pg-free-michal`, zóna `us-central1-a`, PostgreSQL 16,
databáze `playground`. Původní návrh Azure Flexible Server se pro tuto fázi
nepoužívá; skripty `db/azure` zůstávají jako alternativa.

- VM a SSH tunel se spravují podle [instalačního návodu](PostgreSQL%20na%20Google%20Cloud%20%28free%20e2-micro%29.md).
- Port PostgreSQL se do internetu neotevírá; vývojový backend používá lokální konec tunelu.
- Aplikace používá `mikoucto_app`, migrace `mikoucto_owner`, reporty `mikoucto_read`.
- Hesla vývojových rolí jsou chráněna Windows DPAPI. Spouštěcí skript předá aplikační heslo pouze do prostředí procesu backendu.
- Automatické testy nepoužívají `playground` pro zápis. Databázové integrační testy dostanou samostatnou testovací DB v etapě 3.
- Správa vlastního PostgreSQL zahrnuje aktualizace, zálohy a ověření obnovy. Produkční prostředí a region jsou otevřené rozhodnutí.
- Ceny ani bezplatné limity nejsou garantovanou vlastností aplikace; provoz se sleduje v Google Cloud Billing.

Přenositelnost zajišťují standardní funkce PostgreSQL 16 a rozšíření
`pgcrypto`. UUID v7 bude generovat aplikace. Přesun dat lze provést pomocí
`pg_dump` / `pg_restore` a změny konfigurace. Nástroj pro automatický přesun
a ověření obnovy zatím není implementovaný.

Vývojové spojení: `localhost:5432`, `sslmode=disable` uvnitř SSH tunelu.
Pro případné přímé vzdálené připojení se použije TLS. `SET LOCAL` pro audit
platí pouze uvnitř jedné transakce.

## 3. Transakční zpracování změn

Na čtyřech vrstvách:

1. **Databázová transakce na jeden aplikační příkaz.** Každý zapisující požadavek API (např. „zaúčtovat fakturu“) proběhne v jedné transakci: buď celý, nebo vůbec. V Symfony zajistí middleware na Messenger bus (`doctrine_transaction`) nebo explicitní `EntityManager::wrapInTransaction()`.
2. **Integritní pravidla v databázi.** Podvojnost (součet řádků zápisu = 0) hlídá `DEFERRABLE INITIALLY DEFERRED` constraint trigger. Uzavřená období blokuje trigger. Pravidla tak platí i pro import a budoucí moduly, nejen pro jedno místo v PHP kódu.
3. **Optimistické zamykání při souběžné editaci.** `GET` vrací hlavičku `ETag` s verzí záznamu, změna vyžaduje `If-Match`. Při neshodě API vrátí `412 Precondition Failed` a klient nabídne načtení aktuálního stavu. Implementované partnerské API používá PATCH a atomický SQL UPDATE s podmínkou na očekávanou verzi; chybějící hlavička vrací 428.
4. **Idempotence.** `POST` požadavky nesou hlavičku `Idempotency-Key`. Opakované odeslání (výpadek sítě, dvojklik) nevytvoří duplicitní doklad. Klíče se ukládají v `core.idempotency_keys` po dobu 24 hodin.

Izolace: výchozí `READ COMMITTED`; pro operace nad čítači dokladů, uzávěrku a import `SERIALIZABLE` nebo zámek `SELECT … FOR UPDATE` na řádek čítače. Při chybě serializace backend operaci automaticky zopakuje (max. 3×).

## 4. Backend a API

### 4.1 Technologie

| Vrstva | Volba | Důvod |
| --- | --- | --- |
| Jazyk | PHP 8.4 | Požadavek; typované vlastnosti, enumy, readonly třídy |
| Framework | **Symfony 7.4 LTS** | Dlouhodobá podpora, bundly pro moduly, Messenger, Security s Voters |
| API | **API Platform 4** | REST + JSON, automatická specifikace OpenAPI, stránkování, filtry, validace |
| Datová vrstva | Doctrine DBAL 4 + Doctrine Migrations | Implementováno: transakce a atomická kontrola verze v SQL; API DTO mají vlastní providers/processors |
| Autentizace | OAuth 2.0 / OpenID Connect (kap. 5) | Stejný mechanismus pro web i Python klienta |
| Testy | PHPUnit, testy API proti testovací DB | |
| Kvalita kódu | PHPStan (úroveň 8), PHP CS Fixer | |

Alternativa **Laravel 12** je rychlejší na začátek, ale modularita, oddělení vrstev a správa oprávnění se v ní hůř drží při rostoucím počtu modulů. Pro dlouhodobě rozšiřovaný systém doporučuji Symfony.

### 4.2 Zásady API

- Prefix `/api/v1/…`. Nekompatibilní změny jen v nové verzi.
- Specifikace OpenAPI 3.1 dostupná na `/api/docs.json`. Z ní se generují klienti pro frontend (TypeScript) a Python.
- Zdroje (CRUD) pro číselníky a jednoduché entity; **příkazy** pro účetní operace: `POST /api/v1/faktury/{id}/zauctovat`, `POST /api/v1/obdobi/{id}/uzavrit`. Účetní stav se nemění obecným `PATCH`.
- Jednotný formát chyb RFC 9457 (`application/problem+json`).
- Hlavička `X-Request-Id` se propisuje do logů i auditu.

### 4.3 Vrstvy uvnitř modulu

```
src/Modules/<Modul>/
  Domain/          entity, hodnotové objekty, doménová pravidla
  Application/     příkazy a jejich handlery (jedna transakce = jeden handler)
  Infrastructure/  Doctrine mapování, repozitáře, migrace
  Api/             API Platform zdroje, DTO, Voters
  module.yaml      metadata modulu: oprávnění, exportní profily, položky menu
```

### 4.4 Python klient

- Python 3.12, HTTP knihovna `httpx`, typovaný klient generovaný z OpenAPI (`openapi-python-client`).
- Uživatelské rozhraní: **PySide6 (Qt)** pro bohatší tabulky a formuláře, nebo Tkinter pro jednoduché nástroje.
- Přihlášení OAuth 2.0 Authorization Code + PKCE přes systémový prohlížeč. Tokeny uložené v systémovém úložišti (`keyring`, Windows Credential Manager), ne v souboru.
- Klient má stejná oprávnění jako uživatel na webu. Nemá žádnou vlastní cestu k databázi.

## 5. Uživatelé, role a oprávnění

### 5.1 Autentizace

Doporučení: **Microsoft Entra ID** (OIDC), pokud budou uživatelé z organizace. Odpadne správa hesel a MFA. Backend ověřuje JWT přístupové tokeny. Pro uživatele mimo organizaci nebo pro úplnou nezávislost: **Keycloak**, případně vlastní přihlášení v Symfony s krátkodobým přístupovým tokenem (15 min) a obnovovacím tokenem s rotací.

Ve všech variantách si backend vede vlastní tabulku `core.users`. Role a oprávnění se spravují v aplikaci, ne v poskytovateli identity.

Implementovaná fáze 4 používá single-tenant Entra ID a delegované v2 access
tokeny s povinným scope a seznamem povolených klientů. Registrace se připraví
podle [návodu Entra](entra_nastaveni.md). První uživatele a účetní jednotku
založí správce skriptem; další přidělování rolí v jednotce nabízí chráněné API.
Pro ověření před vznikem webu slouží MSAL Node klient s Authorization Code + PKCE.

### 5.2 Model oprávnění (RBAC)

```
core.users ─┬─< core.user_roles >── core.roles ─< core.role_permissions >── core.permissions
            └─ každé členství core.user_roles odkazuje na core.accounting_units
```

- **Oprávnění** jsou jemná a pojmenovaná `modul.zdroj.akce`, např. `ucto.zapis.zauctovat`, `ucto.obdobi.uzavrit`, `core.uzivatel.spravovat`. Každý modul je deklaruje v `module.yaml`. Při instalaci modulu se zaregistrují.
- **Role** jsou sady oprávnění spravované v aplikaci: např. Administrátor, Hlavní účetní, Účetní, Fakturant, Auditor (jen čtení včetně auditu), Pouze čtení.
- **Kontrola** v Symfony Voters na úrovni API operace a případně konkrétního záznamu (např. jen vlastní účetní jednotka).
- **Oddělení povinností**: kdo doklad pořídil, ten jej nemůže sám schválit, pokud to modul vyžaduje.
- Změny rolí a oprávnění se samy zapisují do auditu.

Ve fázi 4 mají role a oprávnění klíč `code`; členství je vždy omezené sloupcem
`accounting_unit_id`. RLS chrání účetní jednotky, členství, identity a scoped
audit. Role `mikoucto_app` tyto tabulky přímo nemění; změny členství provádí
omezené databázové funkce, které znovu ověří oprávnění a zachovají audit.
Vlastník DB je důvěryhodný správce. Aplikační kontext nastavuje backend až
po ověření tokenu a po konci transakce se zruší.

## 6. Modularita

Doporučení: **modulární monolit**. Jedna aplikace a jedno nasazení, ale vnitřně oddělené moduly. Pro očekávanou velikost systému jsou mikroslužby zbytečně složité, hlavně kvůli distribuovaným transakcím.

Pravidla:

1. **Jádro `core`**: uživatelé, role, audit, export a import, číselníky společné všem modulům (měny, partneři, účetní jednotky), konfigurace.
2. **Modul** = Symfony bundle + vlastní DB schéma + vlastní migrace + vlastní prefix API + vlastní oprávnění + vlastní sekce frontendu.
3. Modul smí odkazovat na tabulky `core`. Na jiný modul jen přes jeho veřejné rozhraní (PHP služba nebo doménová událost), ne přímým dotazem do jeho tabulek.
4. Komunikace mezi moduly přes **doménové události** (Symfony Messenger). Události v rámci jedné transakce se zpracují synchronně; pro asynchronní zpracování se použije **transactional outbox** (tabulka `core.outbox` zapsaná ve stejné transakci).
5. Frontend načítá seznam aktivních modulů a jejich menu z `GET /api/v1/moduly`. Nový modul se objeví bez úprav jádra frontendu.

Výchozí moduly: `ucto` (osnova, zápisy, období, uzávěrka), `fakturace`, `dph`. Později například `majetek`, `banka`, `reporty`.

## 7. Sledování změn (audit)

### 7.1 Dvě úrovně záznamu

| Úroveň | Co zachycuje | Jak |
| --- | --- | --- |
| **Datový audit** | Každou změnu řádku: tabulka, klíč, operace, původní a nové hodnoty | Obecný trigger PostgreSQL na auditovaných tabulkách |
| **Aplikační událost** | Byznysový význam: „zaúčtoval fakturu FV260012“, „uzavřel období 2026-09“, „importoval soubor X“ | Zápis z handleru příkazu ve stejné transakci |

Obě úrovně spojuje `transaction_id` (`txid_current()`) a `request_id`. Z události se tak dá dohledat přesná změna dat a naopak.

### 7.2 Identita uživatele v databázi

Backend na začátku každé transakce nastaví kontext:

```sql
SET LOCAL app.user_id    = '0190f3c2-…';
SET LOCAL app.request_id = 'b7c1…';
SET LOCAL app.client     = 'web' ;   -- web / python / import / system
```

Trigger hodnoty přečte přes `current_setting('app.user_id', true)`. Audit tak zachytí i změny z importu nebo z budoucích modulů, aniž by na něj vývojář musel myslet.

### 7.3 Tabulka auditu

```sql
CREATE TABLE core.audit_log (
    id            bigint GENERATED ALWAYS AS IDENTITY,
    occurred_at   timestamptz NOT NULL DEFAULT clock_timestamp(),
    tx_id         bigint      NOT NULL DEFAULT txid_current(),
    request_id    text,
    user_id       uuid,
    client        text,
    table_name    text        NOT NULL,     -- 'ucto.zapis_radek'
    row_id        uuid        NOT NULL,
    operation     char(1)     NOT NULL,     -- I / U / D
    old_data      jsonb,
    new_data      jsonb,
    changed_cols  text[],                   -- jen u UPDATE
    prev_hash     bytea,
    row_hash      bytea,
    PRIMARY KEY (id, occurred_at)
) PARTITION BY RANGE (occurred_at);         -- měsíční partitions
```

- Role `mikoucto_app` audit pouze čte; zapisuje do něj zabezpečený databázový trigger. Přímý `INSERT`, `UPDATE` a `DELETE` aplikace nemá povolený.
- Řetězení `row_hash = sha256(prev_hash || data)` odhalí dodatečný zásah do logu. Tím se plní požadavek na průkaznost oprav podle ZoÚ § 35.
- API nabídne historii záznamu `GET /api/v1/{zdroj}/{id}/historie` a přehled změn uživatele pro roli Auditor.

## 8. Export a import v logickém kontextu

### 8.1 Princip

Export neprobíhá po jednotlivých tabulkách, ale po **agregátech**. Agregát je kořenový záznam a vše, co k němu logicky patří. Pro každou vazbu se určí, jak se s ní naloží:

| Typ vazby | Příklad | Export | Import |
| --- | --- | --- | --- |
| **Složení** (část celku) | Faktura → řádky faktury | Vnořeně, celé | Vytvoří nebo nahradí spolu s kořenem |
| **Odkaz na kmenová data** | Faktura → partner, měna, daňová sazba | Přirozený klíč (IČO, kód měny) + volitelně celý záznam | Dohledá podle přirozeného klíče; podle režimu vytvoří, nebo odmítne |
| **Odkaz na jiný agregát** | Úhrada → faktura | Jen identifikátor | Musí již existovat nebo být ve stejném balíku |

### 8.2 Exportní profily

Každý modul deklaruje profily v `module.yaml`. Graf vazeb se doplní automaticky z cizích klíčů v `information_schema`, profil určí jen kořen a typ vazeb:

```yaml
export_profiles:
  faktura:
    root: fakturace.faktura
    compose: [fakturace.faktura_radek]
    reference:
      core.partner:   { natural_key: [ico], include: optional }
      core.mena:      { natural_key: [kod] }
      dph.sazba:      { natural_key: [kod, platnost_od] }
    filter: [datum_vystaveni, stav, partner]
  ucetni_obdobi:
    root: ucto.obdobi
    compose: [ucto.zapis, ucto.zapis_radek]
    reference:
      ucto.ucet: { natural_key: [cislo] }
```

### 8.3 Formát balíku

ZIP s:

- `manifest.json`: verze formátu a schématu (číslo poslední migrace), profil, filtr, autor, čas, počty záznamů;
- `data/<tabulka>.ndjson`: jeden řádek = jeden záznam; tabulky v pořadí podle topologického řazení vazeb;
- volitelně `data/<tabulka>.csv` pro práci v Excelu;
- `SHA256SUMS`.

### 8.4 Import

1. Kontrola manifestu: verze schématu, kontrolní součty, oprávnění uživatele k profilu.
2. **Suchý běh (dry-run)** jako výchozí: vypíše, co se vytvoří, změní nebo odmítne, včetně nenalezených odkazů.
3. Režimy: `create-only`, `upsert` podle UUID nebo přirozeného klíče, `replace` agregátu (jen pro neuzamčená data).
4. Celý balík v **jedné databázové transakci**. Při jakékoli chybě se nic neuloží.
5. Import běží přes stejné doménové služby jako API: platí stejná validace, integritní triggery i audit (`app.client = 'import'`).
6. Velké importy běží asynchronně jako úloha (`POST /api/v1/importy` → stav úlohy).

## 9. Lokální vývoj (fáze 1)

### 9.1 Rozložení

```
┌──────────────────────────┐      ┌──────────────────────────┐
│ Web frontend             │      │ Python klient            │
│ http://localhost:8005    │      │ (desktop)                │
└────────────┬─────────────┘      └────────────┬─────────────┘
             │  HTTPS/JSON, Bearer token        │
             └───────────────┬──────────────────┘
                             ▼
              ┌──────────────────────────┐
              │ Backend API (Symfony)    │
              │ http://localhost:7005    │
              └────────────┬─────────────┘
                           │ SSH tunel, localhost:5432
                           ▼
              ┌──────────────────────────┐
              │ PostgreSQL 16 v cloudu   │
              │ Google Cloud VM pg-free  │
              └──────────────────────────┘
```

### 9.2 Spuštění bez administrátorských práv

Na spravovaném počítači nemusí být k dispozici Docker Desktop (vyžaduje práva správce). Proto návrh počítá s přenosnými binárkami rozbalenými do uživatelské složky:

| Část | Nástroj | Spuštění |
| --- | --- | --- |
| Backend | PHP 8.4 ZIP pro Windows (NTS x64), Composer | `tools/dev/03-backend.ps1`, lokálně port 7005 |
| Frontend | Node.js LTS (alespoň 22.12), Vue 3 + TypeScript + Vite | `tools/dev/17-web.ps1`, místně port 8005; příprava a testy skripty 15–18 |
| Databáze | PostgreSQL 16 na Google Cloud VM | SSH tunel `db/google/00-tunel.ps1`; `DATABASE_URL` pouze v prostředí procesu nebo v ignorovaném `.env.local` |
| Lokální DB pro testy (volitelně) | PostgreSQL ZIP binárky | `pg_ctl -D data -o "-p 5433" start` |
| Python klient | Python 3.12, `venv` | `python -m mikoucto_client` |

Pokud Docker k dispozici bude, doplní se `compose.yaml` se stejnými porty.

Ověřit předem: dostupné SSH spojení na VM a lokální port 5432. Port databáze se do internetu neotevírá.

### 9.3 Komunikace web ↔ API

- Vite dev server přesměruje `/api` na `http://127.0.0.1:7005`. Prohlížeč pak vidí jeden origin a CORS není ve vývoji potřeba.
- Implementovaný web volá API přes tuto proxy; samostatná CORS konfigurace backendu není součástí etapy 6. MSAL používá samostatnou SPA registraci podle [návodu webu](web.md).
- Python klient volá `http://localhost:7005/api/v1` přímo.

### 9.4 Struktura repozitáře

```
mikoucto/
  analyza/            dokumentace
  backend/            Symfony aplikace, src/Modules/*, migrations/
  frontend/           webová aplikace
  client-python/      lokální klient
  shared/openapi/     exportovaná specifikace API (verzovaná)
  tools/              skripty pro vývoj
```

## 10. Rozhodnutí

Potvrzeno 29. října 2026:

| # | Otázka | Rozhodnutí |
| --- | --- | --- |
| 1 | Technologie webového frontendu (port 8005) | **Vue 3 + TypeScript + Vite** jako samostatná SPA, komunikuje výhradně přes API |
| 2 | Poskytovatel identity | **Microsoft Entra ID** |
| 3 | Framework backendu | **Symfony 7.4 LTS + API Platform** |
| 4 | Databáze | **Standardní PostgreSQL 16**, poskytovatel zaměnitelný; aktualizováno 2. listopadu 2026 |
| 5 | Databáze ve fázi 1 | **Google Cloud VM pg-free**, databáze `playground`, připojení přes SSH tunel; automatické testy odděleně |
| 6 | UI Python klienta | **PySide6** |
| 7 | Více účetních jednotek v jedné databázi | Ano, implementovaný sloupec `accounting_unit_id` + Row-Level Security |

Otevřené: produkční prostředí, region a zálohování se zvolí po ověření provozu a požadavků organizace. Současná VM slouží vývoji.

## 11. Další kroky

Realizace pokračuje podle [implementačního plánu](implementace.md): kontrola prostředí a DB, minimální backend, migrace a testovací DB, identita a oprávnění, partneři, web, účetní základ a další moduly. Každá etapa má vlastní spuštění a podmínky dokončení.
