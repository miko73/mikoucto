# Implementační plán 7.1: účtová osnova a účetní období

Datum: 3. listopadu 2026. Stav: **implementováno a automaticky ověřeno v mikoucto_test; vývojové nasazení neprovedeno**.
Účetní migrace, API, testy a skripty jsou dodané. Aktuální postup a výsledky
jsou v [kontraktu API](ucto_api.md). Níže zůstává pořadí a obsah implementačního zadání.
Navazuje na [etapu 7](etapa7.md); její kapitoly 1 a 2 rozpracovává do úkolů.

## Cíl a rozsah dodávky

Administrátor účetní jednotky dokáže přes API založit, načíst a změnit účet
nebo otevřené období. Oprávnění, izolaci jednotek, audit, idempotenci
vytváření a ochranu proti přepsání novější verze vynucuje server a databáze.
Součástí dodávky jsou automatické testy a PowerShell příprava → ověření.

Webové formuláře, doklady, částky, MD/DAL, číslování, zaúčtování a uzavírání
období jsou navazující části 7.2–7.4. Současný web partnerů musí dál fungovat.
Do 7.1 nepatří automatické naplnění legislativní účtové osnovy.

## 1. Dokončit kontrakt a technické ověření

**Výstup:** `analyza/ucto_api.md` s konkrétními poli, pravidly a příklady.
Pravidla níže jsou výchozí rozhodnutí pro implementaci.

| Objekt | Pole a omezení |
| --- | --- |
| Účet | `code`: řetězec 1–20 číslic, jedinečný v jednotce, zachovat počáteční nuly; `name`: 1–200 znaků po oříznutí; `active` a `postable`: boolean, výchozí true; `parentId`: UUID nebo null |
| Rodič účtu | Stejná jednotka, nesmí vzniknout vazba na sebe ani cyklus; kontrola platí i při souběhu a přímém SQL aplikační role |
| Období | `name`: 1–200 znaků po oříznutí; `startsOn` a `endsOn`: datum `YYYY-MM-DD`, obě hranice včetně, začátek ≤ konec; bez překryvu v jednotce |
| Stav období | Při vytvoření `open`, řízený serverem; v této části není přechod do `closed` ani endpoint pro jeho změnu |
| Serverová pole | UUID v7, jednotka, verze od 1, čas vytvoření/změny; autor změny v auditu. Klient tato pole nesmí přepisovat |
| Změny | PATCH s `If-Match`, změna bez věcného rozdílu nezvyšuje verzi ani audit; fyzický DELETE není dostupný |

V 7.1 dovolíme administrátorovi změnit kód účtu a hranice otevřeného období,
protože ještě nejsou doklady. Před přidáním dokladů musí 7.2 doplnit omezení
změn použitých účtů a období. Deaktivace účtu nemění jeho historii ani potomky.
`postable` je samostatný příznak; existence potomků jej automaticky nepřepíná.

Před první migrací zaznamenat i výchozí kontrakt navazujících částí:
CZK; částky jako desetinné řetězce a `numeric(18,2)`; nejvyšší částka řádku
`9999999999999999.99`, výpočty součtů přesně bez float a bez přetečení;
kladná částka právě na jedné straně MD/DAL. Číslo dokladu se v 7.3 přidělí
atomicky v jednotce, období a řadě interních dokladů. Uzavření smí provést
administrátor, jen bez rozepsaných dokladů, se společným zámkem období při
zaúčtování. Toto je návrh budoucího kontraktu, ne implementace těchto funkcí.

Technické ověření na testovací DB před psaním finální migrace:

- Prověřit dostupnost a možnost instalace `btree_gist`. Preferované řešení
  překryvů je exclusion constraint nad jednotkou a `daterange` se zahrnutými
  hranicemi. Rozšíření případně instaluje správce explicitně, nikoli aplikace.
  Pokud není dostupné, nejprve přepracovat a otestovat mechanismus serializace;
  nepřejít potichu na samotnou aplikační kontrolu SELECT → INSERT.
- Ověřit, že současný audit a `core.visible_audit_unit` obslouží nové schéma
  `ucto`; případnou úpravu přidat novou migrací se zachováním staré historie.
- Stanovit pořadí transakčních zámků a chování při deadlocku/serialization
  failure. Testy musí mít omezené čekání a nesmějí zůstat viset.

**Podmínka dokončení:** kontrakt nemá otevřenou volbu typu dat, překryvů,
oprávnění nebo odpovědí API. Technické ověření neprovádí změny v `playground`.

## 2. Přidat migraci, zabezpečení a přímé DB testy

**Soubory:** nová verze v `backend/migrations`, SQL v
`backend/migrations/sql`; staré migrace neupravovat. Číslo nové verze zvolit
při implementaci podle skutečně dostupných verzí.

1. Vytvořit schéma `ucto`, vlastněné `mikoucto_owner`, tabulky `accounts`
   a `periods`. Aplikační a reportovací role dostanou jen potřebná oprávnění,
   žádné DDL ani přístup do migrační evidence.
2. Přidat kontroly typů a rozsahů, indexy, jedinečnost `(accounting_unit_id,code)`,
   složenou vazbu rodiče `(accounting_unit_id,parent_id)` a zákaz cyklů.
   Kontrolu hierarchie serializovat transakčním zámkem jednotky v DB triggeru;
   ověřit viditelnost změn po čekání a dvě souběžné změny rodičů.
3. Přidat databázovou ochranu identity, verze a `created_at`, nastavení
   `updated_at`, kontrolu otevřeného stavu a ochranu překryvů období.
4. Připojit `core.audit_attach`; RLS pro SELECT/INSERT/UPDATE musí používat
   existující kontext a ověřovat konkrétní oprávnění, ne pouze členství.
5. Přidat oprávnění `core.ucto.accounts.read/write/history` a
   `core.ucto.periods.read/write/history`. Prefix `core.` záměrně odpovídá
   současnému `UnitPermissionVoter`; není důvod ho nyní obecně přepisovat.
6. Pro idempotenci vytvořit `ucto.account_create_requests` a
   `ucto.period_create_requests` s vazbou na příslušný objekt, jednotku,
   uživatele, operaci, klíč, hash a uloženou odpověď. RLS omezí také uživatele.
   Zachovat současné chování platnosti klíčů, včetně přesného popisu expirace.
   `core.idempotency_keys` nelze použít přímo: má FK na `core.partners`.

| Role | Čtení účtů a období | Zápis | Historie |
| --- | --- | --- | --- |
| administrator | ano | ano | ano |
| accountant | ano | ne | ne |
| auditor | ano | ne | ano |
| reader | ano | ne | ne |

**Podmínka dokončení:** omezení fungují pod `mikoucto_app`, bez kontextu se
přístup neotevře, reportovací role nezapisuje. První migrace i její opakování
projdou v `mikoucto_test`. Neúspěšný zápis nezanechá objekt ani falešný audit.

## 3. Implementovat účetní API

**Umístění:** `backend/src/Modules/Accounting/` s adresáři `ApiResource`,
`Application`, `Infrastructure`, `State` a případně `Domain` podle potřeby.
Použít DBAL, `IdentitySession` a vzory partnerů. Nevytvářet alternativní
přihlášení nebo nový obecný framework.

Základ URL: `/api/v1/accounting-units/{unit}`.

| Operace | Účty | Období |
| --- | --- | --- |
| GET seznam / POST vytvoření | `/accounts` | `/periods` |
| GET detail / PATCH změna | `/accounts/{id}` | `/periods/{id}` |
| GET historie | `/accounts/{id}/history` | `/periods/{id}/history` |

- Oddělit vstupní DTO od výstupních. Validovat skutečné JSON typy, neznámá
  pole, UUID, existující kalendářní data a délky. PATCH rozliší chybějící
  pole a null; null je přípustné jen pro rodiče.
- Založení vyžaduje `Idempotency-Key`; zámek klíče, objekt, audit a uložená
  odpověď patří do jedné transakce. Opakování vrátí původní výsledek.
- PATCH vyžaduje silný ETag a porovnání verze přímo při SQL UPDATE.
- Seznamy mají stabilní řazení, stránkování 20/max. 100, hledání podle kódu
  a názvu; účty filtr `active`, období datumový filtr popsaný v kontraktu.
  Historie je samostatně stránkovaná. Bez neomezeného načtení celého auditu.
- Rozlišit 401 (bez přihlášení), 403 (bez oprávnění), 404 (nedostupný objekt),
  400 (neplatný formát/chybějící klíč), 422 (validace), 409 (duplicita,
  překryv, cyklus či jiný konflikt klíče), 412 (stará verze), 428 (chybí If-Match).
  Cizí objekt nesmí odpovědí prozradit svá data.
- Doménové DB konflikty mapovat cíleně podle SQLSTATE a názvu constraintu;
  například exclusion violation se nesmí změnit na falešné „Database unavailable“.
  Nedoplňovat obecné polykání všech databázových výjimek.

**Podmínka dokončení:** integrační test projde přes skutečný Symfony/API
Platform request, správně zapíše do testovací DB a ověří obsah i hlavičky.

## 4. Zapojit konfiguraci a OpenAPI

1. Rozšířit `backend/config/packages/api_platform.yaml`: nyní mapuje pouze
   `Partners/ApiResource`; přidat účetní zdroje a vhodný společný název API.
2. Doplnit výjimky pro nové DTO/doménové objekty v `backend/config/services.yaml`,
   aby je autowiring nepovažoval za služby s neřešitelnými skalárními argumenty.
3. Zdokumentovat vstupy, odpovědi, Bearer, ETag, idempotenci a chybové stavy.
   Zachovat partnerské schéma a chování stávajícího OpenAPI dekorátoru.
4. Dodat export `shared/openapi/accounting.json` a ověření účetních cest.
   Stanovit účetní filtr exportu; soubor musí skutečně odpovídat názvu.
   Partnerský export, generování typů a frontend se nesmějí rozbít.

**Podmínka dokončení:** lint kontejneru, test účetního OpenAPI a současný
`PartnerOpenApiTest` projdou; export odpovídá skutečným endpointům.

## 5. Dodat automatickou sadu a příkazy pro obsluhu

**Plánované nové soubory:**

- `backend/tests/Support/AccountingFixture.php` – dvě jednotky, všechny role,
  testovací podepsané identity, úklid dat i při chybě.
- Testy validace a OpenAPI v `backend/tests`.
- Testy účtů, období, RLS/auditu a souběhu v `backend/tests/Integration`.
- `tools/ucto/01-osnova-obdobi.ps1` – příprava závislostí a migrací;
  výchozí `-Target Test`, vývojová DB jen explicitně.
- `tools/ucto/02-osnova-obdobi-test.ps1` – nové účetní testy a regrese;
  přijímá jen testovací DB, při chybě vrací nenulový exit code.

Testy zapojit do skutečně spouštěných PHPUnit sad. Samotné `--filter` na
neexistující třídu není důkaz. Ověřovací skript zkontroluje report s počtem
provedených účetních testů a odmítne prázdnou nebo přeskočenou povinnou sadu.
Výpis musí oddělit přípravu schématu, nové testy a regresi.

| Povinný scénář | Očekávání |
| --- | --- |
| Založit, načíst, změnit účet/období | Správná data, verze, ETag a audit |
| `001` dvakrát v A, jednou v B | Druhý v A odmítnut; v B povolen |
| Rodič z B, vlastní rodič, víceúrovňový cyklus | Odmítnuto HTTP i přímo DB rolí |
| Souběžné vzájemné přiřazení rodičů | Nevznikne cyklus |
| Obrácené či neplatné datum | Odmítnuto |
| Společný hraniční den období | Odmítnut; následující den povolen |
| Dvě souběžně zakládaná překrývající se období | Nejvýše jeden potvrzený zápis |
| Dvě souběžné změny hranic období do překryvu | Nejvýše jedna potvrzená změna |
| Cizí jednotka, chybějící kontext, všechny role | Odpovídající ochrana API, RLS i historie |
| Opakovaný POST, souběžný POST, konflikt těla a expirace klíče | Výsledek podle kontraktu, žádný duplicitní objekt nebo audit |
| Dvě změny se stejným ETag | Druhá nepřepíše první |
| PATCH beze změny a neúspěšný zápis | Bez nadbytečné verze nebo falešného auditu |
| Změna serverových polí nebo stavu na closed | Odmítnuto |
| Opakování migrace a dosavadní testy | Beze změn hotového schématu, identity a partneři funkční |

Souběh se ověřuje dvěma nezávislými DB relacemi/procesy se synchronizací,
nikoli dvěma postupnými voláními. Kontrolovat finální stav po COMMIT,
rollback neúspěšné transakce, úklid procesů a časové limity.

## 6. Pořadí ověření a předání

Vývoj provádět po krocích 1 → 2 → 3 → 4 → 5; testy psát spolu s každou
částí. Nečekat s ověřením databázové izolace až na hotové API.

Pro uživatele jsou nyní dostupné tyto příkazy:

```powershell
# Okno 1: pouze pokud tunel jeste nebezi.
.\db\google\00-tunel.ps1
```

```powershell
# Okno 2, z korene projektu. Oba skripty jsou dodane.
.\tools\ucto\01-osnova-obdobi.ps1 -Target Test
.\tools\ucto\02-osnova-obdobi-test.ps1
```

Ověřovací sada má pokrýt také existující backendové a DB testy.
Dosavadní web ověřit přes `tools/dev/18-web-test.ps1` (s připraveným prohlížečem).
Integrační testy nepotřebují běžící web ani backendový HTTP server; potřebují
tunel, přístup k `mikoucto_test` a existující projektové role.

Předání obsahuje skutečné výsledky a počty testů, verzi kódu, export OpenAPI,
přesné příkazy a opravený stav v `etapa7.md`, `implementace.md` a README.
Neoznačovat část za ověřenou pouze podle úspěšné migrace.

## Podmínky dokončení a navazující nasazení

Část 7.1 je implementovaná a ověřená na testovací DB, až když:

- Účty i období mají funkční API a všechny uvedené scénáře procházejí.
- Omezení, oprávnění, audit a souběh platí také mimo HTTP vrstvu.
- Automatické skripty skutečně spouštějí nové testy a spolehlivě hlásí chyby.
- Dosavadní backend, partneři, přihlášení, OpenAPI a webové testy procházejí.
- Dokumentace jasně rozlišuje implementaci/testovací ověření od migrace
  vývojové DB a od dosud nedodaných webových obrazovek.

Nasazení do `playground` je oddělený navazující krok: nejprve připravit
obnovitelnou zálohu a doložit obnovu do oddělené DB; poté explicitně provést
vývojovou migraci a ověření skutečným administrátorem přes API. Tento krok
nesmí být vedlejším efektem testovacího skriptu. Pokud ještě není provedený,
zůstane v předání výslovně uveden jako neprovedený.

Další programovací část po úspěšné 7.1 je **7.2: rozpracovaný účetní doklad**.
Její podrobný plán vznikne nad skutečně dodaným schématem a kontraktem 7.1.
