# Postup implementace mikoucto

Zvolená cesta: samostatná aplikace, Symfony 7.4 LTS backend, PostgreSQL 16
na Google Cloud VM `pg-free`, databáze `playground`. Připojení z Windows
vede přes SSH tunel na `localhost:5432`. GnuCash slouží jako analytický
podklad pro účetní koncepty; jeho zdrojový kód není backendem nové aplikace.

Každá etapa má spustitelný výstup a ověření. Etapy 1–6 jsou implementované.
Uživatel potvrdil skutečné přihlášení Entra a průchod web → Google DB včetně
vytvoření a změny partnera, historie a deaktivace. Části 7.1 až 7.4 jsou implementované;
živé účetní ověření ve vývojové DB a etapa 8 zůstávají navazujícími kroky.

| Etapa | Výstup | Podmínka dokončení | Stav |
| --- | --- | --- | --- |
| 1. Prostředí a DB | Přenosné PHP a Composer; kontrola nástrojů, přihlášení rolí, schémat a auditu | Kontrolní skript projde; při chybě vrací nenulový kód | Hotovo, ověřeno proti Google DB |
| 2. Minimální backend | Symfony na portu 7005, stav aplikace a dostupnost DB | Automatické testy a oba HTTP endpointy projdou | Hotovo, ověřeno přes HTTP |
| 3. Migrace a testovací DB | Doctrine Migrations, verzování schématu, oddělená testovací DB | Migrace projdou na prázdné testovací DB; opakování nemění schéma | Ověřeno na Google DB, 8 integračních testů; převzetí playground je samostatný krok |
| 4. Identita a oprávnění | Entra ID, uživatelé, role, účetní jednotky, RLS | Bez tokenu není přístup k datům; uživatel nevidí cizí jednotku | Implementováno; Entra skripty a skutečné přihlášení potvrzené uživatelem, první administrátor jednotky DEV připraven |
| 5. Partneři | API Platform, migrace doménových tabulek, validace, audit a souběh | Vytvoření a změna partnera mají audit; chybný vstup a zastaralá verze se odmítnou | Implementováno, skripty 12–14 |
| 6. Web | Vue + TypeScript na portu 8005, přihlášení a partneři | Celý průchod z prohlížeče přes API do DB a auditu | Hotovo: typecheck, build, 13 jednotkových a 18 prohlížečových testů prošly; živé přihlášení, vytvoření, změna, historie a deaktivace partnera potvrzené uživatelem |
| 7. Účetní základ | Osnova, období, doklad a řádky, kontrola podvojnosti | Vyrovnaný doklad projde, nevyrovnaný a zápis do uzavřeného období neprojdou | Části 7.1–7.4 implementovány a vývojově nasazeny po ověření obnovy; zbývá potvrzení živého účetního průchodu |
| 8. Rozšíření | Export/import, Python klient, následně fakturace a DPH | Každá funkce má vlastní ověřovací scénář | Plán |

## Etapa 1: Prostředí a DB

Nejprve dokončete instalaci DB podle [README](../README.md). Následující
příkazy se spouštějí z kořene repozitáře ve Windows PowerShellu 5.1 nebo novějším.

```powershell
.\tools\dev\00-nastroje.ps1
```

Stáhne PHP 8.4 NTS x64 a Composer z oficiálních serverů, ověří kontrolní
součty a rozbalí je do ignorovaných `tools/php` a `tools/composer`.
Nemění systémový PATH ani nevyžaduje instalaci serveru PHP jako služby.
Při opakování použije existující nástroje. Windows musí mít dostupné
Visual C++ runtime knihovny pro PHP.

V samostatném okně spusťte SSH tunel, pokud ještě neběží:

```powershell
.\db\google\00-tunel.ps1
```

V druhém okně ověřte prostředí a DB:

```powershell
.\tools\dev\01-kontrola.ps1
```

Kontrola používá uložená hesla z inicializace DB, přihlásí se samostatně jako
`mikoucto_owner`, `mikoucto_app` a `mikoucto_read`. Ověří potřebné objekty,
aktuální měsíční oddíl a řetězec auditu, zákaz přímého zápisu aplikace do
auditu a režim read-only reportovací role. Kontroly běží pouze pro čtení.
Úspěch: `Prostredi a databazovy zaklad OK`.

Pro samotné nástroje lze použít `-BezDatabaze`; tento režim výslovně
nepotvrzuje připravenost DB.

## Etapa 2: Minimální backend

Instalace závislostí a kontrola konfigurace:

```powershell
.\tools\dev\02-backend-instalace.ps1
```

Použije `backend/composer.lock` a vytvoří náhodný `APP_SECRET` v ignorovaném
`backend/.env.local`, pokud soubor ještě neexistuje. Hesla DB do něj nezapisuje.
Existující `.env.local` nemění; pokud jej vytváříte ručně, nastavte také `APP_SECRET`.

Spuštění backendu (třetí okno, ponechat otevřené):

```powershell
.\tools\dev\03-backend.ps1
```

Skript dešifruje pouze aplikační heslo Windows DPAPI a předá připojení do
prostředí procesu PHP. Backend se připojuje jako `mikoucto_app`.
Původní hodnotu `DATABASE_URL` po ukončení obnoví. Pokud je `DATABASE_URL`
již nastavená, respektuje ji. Pro jiný konec SSH tunelu použijte `-DatabasePort`.

| Požadavek | Úspěch | Při nedostupné DB |
| --- | --- | --- |
| `GET http://localhost:7005/api/v1/health` | HTTP 200, `status=ok` | Zůstane HTTP 200, potvrzuje běh aplikace |
| `GET http://localhost:7005/api/v1/health/database` | HTTP 200, `database=ok` | HTTP 503, `application/problem+json`, bez hesel a vnitřních detailů |

Automatické testy bez běžícího backendu a bez zápisu do vývojové DB:

```powershell
.\tools\dev\04-backend-test.ps1
```

Testy používají náhradu DB spojení a jednu skutečnou zkoušku nedostupného
lokálního portu. Nepřipojují se k vývojové databázi `playground`.

Testy včetně HTTP ověření běžícího backendu a skutečné Google DB:

```powershell
.\tools\dev\04-backend-test.ps1 -Http
```

Očekáváno: úspěšná sada PHPUnit, `HTTP health OK`, `HTTP health/database OK`
a `Testy backendu OK`. HTTP ověření DB provádí pouze `SELECT 1`.

Zastavení backendu: Ctrl+C v jeho okně. Používá vestavěný PHP server vázaný
na `127.0.0.1`; je určený pro místní vývoj. Spouštěcí skripty řeší načítání
PHP rozšíření i z Windows cest s diakritikou.

## Etapa 3: Migrace a testovací DB

### 3.1 Závislosti

```powershell
.\tools\dev\02-backend-instalace.ps1
```

Přidán je Doctrine Migrations 3.9, přesná verze je v `backend/composer.lock`.
Migrace používají samostatnou konfiguraci v `backend/bin/migrations`,
oddělenou proměnnou `DATABASE_MIGRATIONS_URL` a roli `mikoucto_owner`.
Spouštěcí skript vybere cílovou DB a připojení vždy explicitně; nezdědí
`DATABASE_URL` běžící aplikace. Hesla zůstávají v chráněném úložišti DPAPI.

### 3.2 Prázdná testovací DB na Google PostgreSQL 16

Při běžícím SSH tunelu:

```powershell
.\tools\dev\05-test-db.ps1
```

Zadejte heslo `postgres`. Skript ověří PostgreSQL 16 a existenci projektových
rolí, vytvoří `mikoucto_test` s vlastníkem `mikoucto_owner` a povolí přístup
aplikační a reportovací roli. Testovací a vývojová DB sdílejí server a již
existující přihlašovací role; databázové objekty a data jsou oddělené.
Skript nemění hesla ani globální oprávnění rolí a neobsahuje DROP DATABASE.
Existující testovací DB ponechá; při jiném vlastníkovi nebo kódování skončí
chybou. Případné další testy ji tedy nemažou ani nevytvářejí znovu.

Alternativa přes již přihlášený Google Cloud SDK a existující SSH klíč:

```powershell
.\tools\dev\05-test-db.ps1 -ViaSsh
```

Tato varianta použije `sudo -n -u postgres psql` přímo na VM. Vyžaduje
již ověřený hostitelský klíč a SSH účet s dostupným sudo bez interaktivního
hesla. SQL neobsahuje tajemství a je stejné jako pro variantu přes tunel.

### 3.3 Migrace testovací DB

```powershell
.\tools\dev\06-migrace.ps1 -Target Test -Action Status
.\tools\dev\06-migrace.ps1 -Target Test -Action Migrate
.\tools\dev\06-migrace.ps1 -Target Test -Action UpToDate
```

`Status` je pouze pro čtení. Před první migrací ukáže jednu dostupnou migraci.
`Migrate` vytvoří chráněnou evidenci `migration.versions` a provede chybějící
migrace. `UpToDate` skončí úspěšně jen tehdy, když žádná migrace nechybí.
Parametr `-DatabasePort` podporuje jiný lokální port SSH tunelu.

První verze `App\Migrations\Version20261102000000` v prázdné DB vytvoří
schémata `core`, `ext`, rozšíření `pgcrypto`, výchozí oprávnění, auditní
tabulky, funkce, triggery a měsíční oddíly. Používá neměnný SQL snapshot
v `backend/migrations/sql`; do budoucna se přidávají nové migrace, nikoli
úpravy již aplikovaných souborů. Globální role vytvořila dřívější instalace.
Migrační SQL běží transakčně s nastavením all-or-nothing.

### 3.4 Integrační testy

```powershell
.\tools\dev\07-db-test.ps1
```

Skript nejprve aktualizuje pouze `mikoucto_test`, předá tři samostatná
testovací připojení a spustí `backend/phpunit.integration.xml.dist`.
Před prvním připojením testy vyžadují přesný název DB a role, následně
ověří také skutečnou databázi na serveru. Přepínač pro vývojovou DB nemají.
Původní jednotkové a HTTP testy z etapy 2 zůstávají oddělené.

Ověřovací scénáře:

- Skutečně přihlášená aplikační role vloží a změní řádek s auditním kontextem;
  reportovací role přečte výsledek a audit se správným uživatelem a požadavkem.
- Změna bez auditního kontextu je odmítnuta; bezvýznamný UPDATE nový audit nevytvoří.
- Zápis do auditu přímo aplikací je zakázán a migrační evidence je nepřístupná.
- Při chybě v transakci zmizí datový zápis i auditní zápis.
- Opakované migrace nemění data, definice objektů ani existující záznamy verzí.
- Existující auditní základ lze převzít bez jeho přepsání; nekompatibilní sloupce
  kontrola odmítne.
- Úmyslně selhaná testovací migrace vrátí DDL i data a nezapíše aplikovanou verzi.

Testovací tabulky mají náhodná jména a po scénářích se odstraňují. Auditní
záznamy úspěšně potvrzeného testu zůstávají v testovací DB a jsou zapečetěné;
slouží také k ověření opakovaných běhů. Rollback může ponechat mezery v sekvencích.
Nedochází k čištění ani zápisu účetních dat v `playground`.

Úspěch: PHPUnit bez chyb a závěrečná zpráva `Etapa 3 OK`.

Při implementaci byla testovací DB vytvořena na skutečném Google PostgreSQL 16.
Prošlo 8 integračních testů se 74 kontrolami, samostatně 7 původních a ochranných
testů backendu s 18 kontrolami. První migrace na prázdné DB i následné opakování
byly ověřeny. Ve vývojové `playground` byl zatím načten pouze stav migrací;
záznam její výchozí verze provede až explicitní krok 3.5.

### 3.5 Převzetí existující vývojové DB

Po ověření testů spusťte explicitně:

```powershell
.\tools\dev\06-migrace.ps1 -Target Development -Action Status
.\tools\dev\06-migrace.ps1 -Target Development -Action Migrate
.\tools\dev\06-migrace.ps1 -Target Development -Action UpToDate
```

První migrace rozpozná existující `core.audit_log`. Ověří sloupce, vlastníky,
funkce, ochranné triggery, oddíly, oprávnění a integritu auditu a pouze uloží
záznam verze. Auditní objekty ani auditní data nepřepisuje. Částečný základ
nebo nekompatibilní strukturu odmítne. Tato kontrola ověřuje strukturu a hlavní
invarianty; není univerzálním porovnáním všech definic s původní instalací.

`migration.versions` je ve vlastním schématu bez přístupu aplikační a reportovací
role. První migrace nemá automatický krok zpět, který by odstranil audit.
Návrat případných budoucích aplikačních migrací se řeší podle jejich obsahu.

## Etapa 4: Identita a oprávnění

Kompletní přípravu tenantu, registrací a prvního uživatele popisuje
[Entra nastavení](entra_nastaveni.md). Registrace v účtu uživatele dosud
neexistují; tato část postupu se provede před skutečným přihlášením.

| Skript | Účel |
| --- | --- |
| `08-entra-konfigurace.ps1` | Konkrétní tenant, API a povolení klienti v ignorovaném `.env.local` |
| `09-uzivatel.ps1` | Založení identity a jednotky, přidělení scoped role migračním vlastníkem |
| `10-identita-test.ps1` | Kryptografické, HTTP a databázové ověření bez skutečných registrací |
| `11-entra-test.ps1` | Přihlášení přes MSAL a prohlížeč, následně GET `/me` a dostupných jednotek |

Všechny skripty jsou ve `tools/dev`. Přesné parametry a pořadí včetně migrace
vývojové DB jsou v samostatném návodu. Pro testy nejprve spusťte:

```powershell
.\tools\dev\02-backend-instalace.ps1
.\tools\dev\10-identita-test.ps1
```

Nová migrace `20261102010000` přidává `core.users`, `core.accounting_units`,
`core.roles`, `core.permissions`, `core.role_permissions`, `core.user_roles`
a RLS. Uživatelská identita je vázaná na tenant/object ID; žádné automatické
zakládání účtu nebo převzetí oprávnění z neověřených hlaviček není zapnuté.
Role jsou specifické pro jednotku a jejich změny jsou auditované. Uživatel
nevidí cizí jednotku ani její audit, členství a identity. Datový kontext je
platný pouze uvnitř transakce. Cílový sloupec budoucích doménových tabulek
je `accounting_unit_id`.

Výchozí role jsou `administrator`, `accountant`, `auditor`, `reader`.
Administrátor může v rámci své jednotky přidělit a odebrat roli, ale nemůže
odebrat posledního aktivního administrátora. Globální identitu nového
uživatele a novou jednotku zatím zakládá správce skriptem 09.

API přijímá delegované v2 access tokeny pro konkrétní API a povoleného klienta.
Ověřuje skutečný RSA podpis, klíče Entra, issuer, tenant, audience, časové
claims, `oid`, scope a `azp`. Chybějící konfigurace chráněný přístup neotevře.
Pro živý test je připraven MSAL Node klient s Authorization Code + PKCE;
tokeny jsou pouze v paměti procesu.

Automatické testy zahrnují neplatné a vypršené tokeny, nepřihlášené a
neregistrované identity, změnu identity mezi dvěma HTTP požadavky, čtení
a zápis do cizí jednotky přímo přes DB, scoped audit, idempotenci registrace
a ochranu posledního administrátora. Soukromý RSA klíč vzniká jen v testech;
žádná vývojová cesta obcházející ověřování tokenů není součástí backendu.

## Etapa 5: Partneři

Podrobný kontrakt, oprávnění a příklady jsou v [partnerském API](partneri_api.md).
Implementované jsou DTO zdroje API Platform s vlastními providers/processors,
validací Symfony a persistencí Doctrine DBAL. Migrace `20261102020000`
vytváří `core.partners` a chráněnou evidenci idempotence.

```powershell
.\tools\dev\12-partneri-priprava.ps1
.\tools\dev\13-partneri-test.ps1
```

Výchozím cílem je `mikoucto_test`. Příprava vývojové DB vyžaduje explicitní
`12-partneri-priprava.ps1 -Target Development`. Testy nevyžadují skutečné
registrace Entra, používají podepsané tokeny s testovacími klíči v testovacím
kontejneru a skutečné Google PostgreSQL.

Partner je omezen účetní jednotkou a RLS, má UUID v7, kontakty, adresu,
volitelná registrační čísla, aktivitu a verzi. Nové oprávnění pro zápis mají
administrátor a účetní, pro čtení všechny role, pro historii administrátor
a auditor. Vytvoření vyžaduje `Idempotency-Key`; transakce zahrnuje partnera,
audit i evidenci opakování. PATCH vyžaduje silný `If-Match`, verze se ověřuje
přímo v SQL. Neaktivita nahrazuje fyzické mazání.

Kontroly zahrnují celou cestu přes API Platform, formát a typy vstupu,
nepovolené přepisování serverových polí, role a RLS, historii, deaktivaci,
zastaralý ETag, opakování požadavku, konflikt klíče a rollback při duplicitě.
U změny bez věcného rozdílu zůstává verze a audit zachovaný. Seznam podporuje
stránkování a filtry; historie má samostatné stránkování.

`14-openapi.ps1` generuje `shared/openapi/partners.json` ve formátu OpenAPI
3.1. Tento kontrakt zahrnuje partnerské zdroje; dosavadní identity kontrolery
zůstávají popsané v návodu fáze 4. Ověření veřejných registrů a kontrolního
součtu IČO zatím není implementované.

## Etapa 6: Web

Vue 3 + TypeScript na portu 8005: přihlášení MSAL popup + PKCE, výběr účetní
jednotky, seznam a formulář partnerů, historie, deaktivace a řešení konfliktu
verze. OpenAPI typy vznikají z exportu fáze 5. Konfigurace neobsahuje hesla,
tokeny zůstávají v paměti. Návod včetně SPA registrace, lokálních testů
a ručního ověření je v [etapě 6](web.md).

| Skript | Účel |
| --- | --- |
| `15-web-priprava.ps1` | npm ci a generování API typů |
| `16-web-konfigurace.ps1` | Veřejná ID, přidání SPA mezi povolené klienty backendu |
| `17-web.ps1` | Vite localhost:8005 s proxy na API localhost:7005 |
| `18-web-test.ps1` | Jednotkové testy, TypeScript, build a prohlížečové testy |

Úspěšné kroky byly navíc doložené uživatelským výpisem PowerShellu:

| Příkaz | Potvrzený výsledek |
| --- | --- |
| `tools/dev/11-entra-test.ps1` | Health backendu a DB 200, anonymní identita 401, skutečné přihlášení Michala a jednotka DEV, `Zivy test Entra OK` |
| `tools/dev/18-web-test.ps1` | Generování OpenAPI typů, 13 jednotkových testů, TypeScript, produkční build a 18 prohlížečových testů prošly |
| `PLAYWRIGHT_CHANNEL=chromium` a `tools/dev/18-web-test.ps1 -InstallBrowser` | Instalace prohlížeče a opakování celé sady: znovu 13 jednotkových a 18 prohlížečových testů prošlo |

Reprodukovatelné PowerShell příkazy a zkrácené výsledky jsou v
[záznamu úspěšných kroků ve web.md](web.md#6-úspěšné-kroky-doložené-výpisem-powershellu).
Řádek se zrušeným `17-web.ps1^C` nedokládá spuštění webu. Ruční zápis partnera,
změna, historie a deaktivace jsou ověřené samostatným potvrzením uživatele;
prohlížečové testy s náhradním API je neověřují proti Google DB.

## Etapa 7: účetní základ

Část **7.1 je implementovaná**. Nová migrace `20261103010000` přidává
schéma `ucto`, účty, otevřená období a samostatné evidence idempotence.
API používá stejné Entra přihlášení a jednotky jako partneři. Administrátor
spravuje účty a období, účetní/čtenář/auditor čtou, auditor navíc historii.
RLS, audit, jedinečnost kódů, ochrana hierarchie a překryvů jsou také v DB.

Konkrétní příkazy a kontrakt jsou v [účetním API](ucto_api.md), návaznosti
v [etapě 7](etapa7.md). Při běžícím tunelu:

```powershell
.\tools\ucto\01-osnova-obdobi.ps1 -Target Test
.\tools\ucto\02-osnova-obdobi-test.ps1
```

Výchozím a jediným testovacím cílem je `mikoucto_test`. Vývojová `playground`
se tím nemigruje. V tehdejší dodávce 7.1 nebylo vývojové nasazení ani web
součástí řezu; jejich pozdější dokončení je zaznamenané níže a v
[návodu vývojového nasazení](vyvoj_nasazeni.md).

| Část | Stav | Výstup |
| --- | --- | --- |
| 7.1 Účtová osnova a účetní období | Implementace dodaná; výsledky automatického ověření jsou v kontraktu API | Migrace, API, RLS, audit, verze, idempotence, OpenAPI a automatické testy včetně skutečného souběhu |
| 7.2 Rozpracovaný doklad | Implementace a automatické testy dodané; viz návod dokladů | Hlavička, řádky MD/DAL, vazby na účty a partnera, přesnost částek a verzování |
| 7.3 Zaúčtování a uzavření období | Implementace a automatické testy dodané; viz návod 7.3 | Vyrovnanost, neměnnost, číslování a ochrana proti souběhu |
| 7.4 Webové ověření | Implementace dodaná; viz návod účetního webu | Přehledy, formuláře, přesné MD/DAL, příkazy, historie a serverový účetní deník |

**Živý účetní průchod uživatel potvrdil jako funkční dne 4. 11. 2026.**
Tím je etapa 7 ověřená i v prohlížeči; podkladem je potvrzení obsluhy,
nikoli nový automatický běh. Záloha, obnova a vývojové nasazení jsou
ověřené; viz [záznam a postup](vyvoj_nasazeni.md).

Konkrétní zadání navazující části je v [přípravě 7.2 – rozpracovaný doklad a řádky MD/DAL](etapa7_2_plan.md).

Část **7.2** doplňuje migrace `20261103020000` (doklady, řádky, chráněný zápis)
a `20261103020100` (ochrana izolace při změně hranic období). Zápis návrhu
probíhá přes `ucto.save_draft`, řádky mají přesné částky bez zaokrouhlování,
společnou verzi celku, audit a vazby pouze uvnitř jednotky. Aktuální obsluha:

```powershell
.\tools\ucto\04-doklady-priprava.ps1 -Target Test
.\tools\ucto\05-doklady-test.ps1
```

Přesný kontrakt, očekávané výsledky a záznam ověření jsou v
[návodu rozpracovaných dokladů](doklady_api.md). Původní skripty 01/02
zůstávají dostupné; migrační nástroje vždy aplikují všechny aktuální verze,
nejde o možnost vybrat pouze starší část 7.1 pro vývojové nasazení.

Část **7.3** doplňuje migrace `20261103030000` a `20261103030100`,
zaúčtování s transakčním číslováním INT, neměnnost dokladů, uzavírání období,
oprávnění a idempotentní příkazy. [Návod 7.3](zauctovani_api.md) obsahuje
aktuální kontrakt a skutečné výsledky. Úplné ověření při běžícím tunelu:

```powershell
.\tools\ucto\06-zauctovani-priprava.ps1 -Target Test
.\tools\ucto\07-zauctovani-test.ps1
```

Vývojová `playground` se tímto testováním nemigruje.

Část **7.4** doplňuje webové formuláře, potvrzované akce zaúčtování a uzavření,
historii, řešení konfliktů a opakování nejistých zápisů se stejným klíčem.
Nový stránkovaný GET `/api/v1/accounting-units/{unit}/journal` filtruje
zaúčtované řádky podle období, účtu, data a hledání. Používá stávající
oprávnění ke čtení dokladů, RLS a přesné řetězcové částky; nová migrace není potřeba.
Návod a stav ověření jsou v [účetním webu](ucetni_web.md).

```powershell
.\tools\ucto\08-web-test.ps1
```

Dne **4. 11. 2026** byla ověřena konzistentní záloha `playground`, její
skutečná obnova na samostatném PostgreSQL 16 cíli a nácvik pěti migrací
včetně účetních pravidel. Celá DB regrese prošla: **65 testů / 665 kontrol**.
Následovalo transakční vývojové nasazení na `Version20261103030100`,
s porovnáním a zachováním původních řádků a auditu před COMMIT.

Pro stávajícího administrátora je připravena jednotka **UCTO_DEV**.
Kontrola skutečnou aplikační rolí ověřila oprávnění a čtení všech účetních
sekcí; backend i web odpovídají přes HTTP. Záloha, reporty a skripty
09–12 jsou popsané ve [vývojovém nasazení](vyvoj_nasazeni.md).
Obsluha následně potvrdila dokončení živého účetního průchodu v UCTO_DEV
podle kapitoly 5 návodu nasazení. Konkrétní čísla dokladů nebyla do tohoto
záznamu předána.

## Etapa 8: hlavní kniha a obratová předvaha

Stav: **etapa 8 dokončena**. Části 8.1–8.3 jsou implementované a automaticky ověřené;
živou přejímku 8.4 uživatel potvrdil dne 4. 11. 2026.
[Plán etapy 8](etapa8_plan.md) vymezuje výpočty v jednom období, přesné
zůstatky, JSON API, export CSV, web a automatické i živé ověření.
Část 8.1 přidává hlavní knihu a předvahu jako JSON API bez nové migrace.
Příkazy, oprávnění a pravidla výpočtu jsou v [návodu sestav](sestavy_api.md).
CSV v části 8.2 je implementované a ověřené; viz
[návod exportů](sestavy_csv.md). Část 8.3 dodává obě obrazovky, přesné souhrny,
stránkování, detail dokladu pouze ke čtení, historii a stažení celého CSV.
[Návod pro obsluhu](sestavy_web.md) obsahuje i konkrétní živou přejímku.

Závěrečné společné ověření 8.1/8.2 dne 4. 11. 2026 prošlo:
**114 jednotkových testů / 342 kontrol** a **75 DB testů / 917 kontrol**
(33 minut 11 sekund). Úplný běh zahrnuje také všechny scénáře původně
opravované v části 8.1. Prošly generované typy, TypeScript, sestavení webu
a 18 webových jednotkových testů. Kontrakt obsahuje 16 účetních cest.
Export čte kurzorem jeden read-only snapshot, ověřuje limit 50 000 řádků
a připraví soubor před zahájením stahování. Nová migrace nebyla potřeba.
Ověření webu 8.3: **25 jednotkových a 50 prohlížečových testů** (desktop
i mobil), generované typy, TypeScript a build prošly. Backend ani migrace
se v 8.3 neměnily; DB sada nebyla opakována. Živý průchod **8.4 nad UCTO_DEV**
je potvrzen obsluhou; konkrétní čísla dokladů nebyla předána.

## Etapa 9: opravné a stornovací doklady

[Plán etapy 9](etapa9_plan.md) rozděluje práci na atomické stornovací API,
navazující opravný návrh, web a živé ověření. Stav: **část 9.1 v implementaci
a automatickém ověření**; [aktuální kontrakt a příkazy](storno_api.md).
Část 9.1 přidává
nový zaúčtovaný protizápis se záměnou MD/DAL, důvodem a trvalou vazbou
na původní doklad. Původní doklad se nemění.
