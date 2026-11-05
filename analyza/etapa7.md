# Etapa 7: účetní základ krok za krokem

Datum plánu: **3. listopadu 2026**. Navazuje na dokončenou etapu 6:
přihlášení Entra, jednotky, partneři, web a audit na Google PostgreSQL fungují.

**Stav:** části **7.1 (účty a období), 7.2 (rozpracované doklady) a
7.3 (zaúčtování a uzamčení) i 7.4 (účetní web) jsou implementované**: migrace, API, testy
a skripty jsou v projektu. Aktuální postup a výsledky jsou v
[návodu 7.3](zauctovani_api.md); [účty/období](ucto_api.md) a
[návrhy dokladů](doklady_api.md) popisují předchozí části.
Vývojová `playground` se testy nemigruje. [Účetní web](ucetni_web.md) má
samostatné lokální ověření. Dne 4. 11. proběhla ověřená obnova a samostatné
[vývojové nasazení](vyvoj_nasazeni.md); připravená je jednotka UCTO_DEV.
Živý účetní průchod s přihlášením Entra zbývá.

Zachováváme způsob práce **nastavení → ověření → další nastavení**.
Vývojová data jsou v `playground`, automatické databázové testy výhradně
v `mikoucto_test`. Backend běží na portu 7005, web na 8005, DB přes SSH tunel
na 5432. Etapa nepřesouvá databázi ani hosting do Azure.

Podrobný [implementační plán části 7.1](etapa7_1_plan.md) rozděluje další
vývoj na kontrakt, migraci, API, OpenAPI, automatické testy a předání.

## Co lze spustit nyní

Kapitola 0 ověřuje dosavadní funkce. Kapitola 1 odkazuje na dokončený
kontrakt první účetní části. **Kapitola 2 už obsahuje existující příkazy
pro přípravu a automatické ověření účtů a období.** Kapitola 3 doplňuje
existující ověření rozpracovaných dokladů. Kapitola 4 přidává skutečné
příkazy pro zaúčtování/uzamčení. Kapitola 5 popisuje navazující vývoj
webových obrazovek.

Původní výsledek `OK (28 tests, 226 assertions)` dokládal dosavadní aplikaci.
Nová sada navíc vyžaduje skutečné provedení `AccountingTest` a
`AccountingConcurrencyTest`; samotné `Already at the latest version` nestačí.

## Přehled postupu a podmínek dokončení

| Krok | Co připravíme | Co musí projít před pokračováním |
| --- | --- | --- |
| 0 | Kontrola stávající aplikace | Nástroje, DB a regresní testy dosavadních funkcí |
| 1 | Návrh prvního účetního řezu | Zapsaný model, stavy, přesnost částek a pravidla období |
| 2 – část 7.1 | Účtová osnova a období, migrace a API | Validace, role, RLS, audit a opakování migrací |
| 3 – část 7.2 | Rozpracované doklady a řádky MD/DAL | Uložení, změna, cizí vazby, přesnost částek a konflikt verze |
| 4 – část 7.3 | Zaúčtování a uzavření období | Vyrovnanost, neměnnost, idempotence a ochrana proti souběhu |
| 5 – část 7.4 | Web účetního modulu | Prohlížečové testy v desktopovém i mobilním rozměru |
| 6 | Vývojová migrace a ruční průchod | Skutečný zápis, zaúčtování, deník a historie v Google DB |

## 0. Ověřte výchozí stav – lze spustit už nyní

### 0.1 Otevřete projekt a ponechte běžet tunel

V PowerShellu přejděte do projektu:

```powershell
Set-Location -LiteralPath 'C:\Users\KočandrleMichal\Projects\mikoucto'
```

V samostatném okně spusťte tunel, pokud už neběží:

```powershell
.\db\google\00-tunel.ps1
```

### 0.2 Zkontrolujte prostředí a dosavadní backend

V druhém okně, také z kořene projektu:

```powershell
.\tools\dev\01-kontrola.ps1
.\tools\dev\04-backend-test.ps1
.\tools\dev\07-db-test.ps1
```

Spouštějte příkazy jednotlivě. Při chybě nepokračujte. Poslední skript
pracuje se samostatnou testovací DB; nepřidává účetní funkce do `playground`.
Pokud testovací DB ještě není připravená, použijte postup etapy 3
v [implementačním plánu](implementace.md#etapa-3-migrace-a-testovací-db).

### 0.3 Ověřte dosavadní web

Pro už nainstalovaný Chromium lze ve stejném okně nejprve nastavit:

```powershell
$env:PLAYWRIGHT_CHANNEL='chromium'
```

Potom spusťte kontrolu webu:

```powershell
.\tools\dev\18-web-test.ps1
```

Dosavadní doložený výsledek je 13 jednotkových a 18 prohlížečových testů.
Po rozšíření modulu se počet změní; rozhodující je úspěch celé sady.

**Hotovo, když:** výchozí kontroly projdou. Zapište datum a výsledek;
novou část pak porovnáváme s tímto funkčním základem.

## 1. Kontrakt prvního účetního řezu

Pravidla 7.1 jsou zapsaná v [kontraktu účetního API](ucto_api.md): pole a
jejich typy, role, rozsahy období, vazby účtů, idempotence, ETag, historie,
chyby a databázové předpoklady. Podrobné původní zadání zachovává
[implementační plán](etapa7_1_plan.md).

První řez tvoří účty a otevřená období ve schématu `ucto`. Účet má kód,
název, aktivitu, účtovatelnost a nepovinného rodiče ze stejné jednotky.
Období má název a datum od–do včetně; dvě období jedné jednotky se nesmějí
překrývat ani při souběhu. Spravuje je administrátor, ostatní role čtou;
historii navíc čte auditor. Pravidla vynucují API i databázová oprávnění.

Navazující doklady budou CZK, s desetinnými řetězci a `numeric(18,2)`
(nejvýše `9999999999999999.99` na straně řádku), bez float a tichého zaokrouhlení.
Rozepsaný doklad smí být nevyrovnaný; zaúčtovaný musí mít MD = DAL a bude
neměnný. Číslo se přidělí atomicky při zaúčtování podle jednotky, období
a řady interních dokladů. Uzavření období administrátorem odmítne rozepsané
doklady. Návrhy a přesné řádky jsou dodané v 7.2; zaúčtování a uzavírání navazují v 7.3.

Fakturace, DPH, cizí měny, storno tok a úplná roční závěrka zůstávají mimo
první řez. Technické uzamčení období není provedení roční závěrky.

## 2. Část 7.1 – účtová osnova a účetní období

### 2.1 Co je dodané

Migrace `Version20261103010000`, schéma `ucto`, API účtů a období v jednotce,
RLS, audit, role, ETag a samostatná evidence idempotence. API má seznam,
detail, vytvoření, změnu a historii. Webové formuláře jsou dodané v části 7.4.

### 2.2 Nastavení → automatické ověření

Předpoklady: připravené závislosti backendu a databáze `mikoucto_test` z etapy 3,
uložená hesla rolí a fungující tunel. Backend ani web nemusí být spuštěný.
Není potřeba ručně zakládat testovací účty, období nebo uživatele.

1. V prvním okně z kořene projektu spusťte tunel, pokud ještě neběží:

   ```powershell
   .\db\google\00-tunel.ps1
   ```

2. V druhém okně z kořene projektu připravte testovací schéma:

   ```powershell
   .\tools\ucto\01-osnova-obdobi.ps1 -Target Test
   ```

   Očekávejte `Ucty a obdobi pripraveny: Test` a aktuálnost migrací.
   Rozšíření `btree_gist` instaluje migrační vlastník; pokud nemá oprávnění,
   postupujte podle databázových předpokladů v kontraktu API.

3. Po úspěchu spusťte ověření:

   ```powershell
   .\tools\ucto\02-osnova-obdobi-test.ps1
   ```

   Skript ověří backendové jednotkové testy, celou DB integrační sadu,
   skutečný běh nových účetních testů, opakování migrací a export OpenAPI.
   Očekávaný závěr začíná `Cast 7.1 OK`. Při chybě nepokračujte k migraci
   vývojové DB; ponechte výpis pro opravu. Testy trvají několik minut.

4. Dosavadní web ověřte podle kroku 0.3. Nemusíte hledat účetní formuláře:
   tato dodávka je ještě nepřidává.

### 2.3 Co přesně automatická sada ověřuje

- Založení, detail, změny, seznamy a historii účtů i období.
- Duplicitní kód v jednotce, cizího rodiče, cyklus a neplatné vstupy.
- Překryvy období včetně společného hraničního dne a souběžných zápisů.
- Přímé SQL neprivilegovanou rolí, RLS, audit a ochranu serverových údajů.
- Všechny role, ETag, bezezměnovou úpravu, opakování a expiraci klíče.
- Dva skutečně souběžné procesy při zakládání období, změnách hierarchie
  a opakování stejného HTTP požadavku; ne pouze dva postupné požadavky.
- Zachování účetního schématu, dat a historie při opakované migraci.

**Hotovo na testovací DB, když:** projdou nové i dosavadní kontroly.
Vývojové nasazení do `playground`, ověření zálohy a živý účetní průchod
jsou oddělené kroky; tento test je neprovádí.

## 3. Část 7.2 – rozpracovaný doklad a řádky MD/DAL

### 3.1 Dodaná implementace

Návrh dokladu se ukládá jako jeden celek s řádky v jedné transakci.
Smí být prázdný, jednořádkový i nevyrovnaný. Server vrací přesné součty,
rozdíl a stav draft; verze se vztahuje na celý doklad. Změny mají historii
hlavičky i starých/nových řádků a společné request ID.

Databázová funkce kontroluje práva, jednotku, verzi, datum a vazby. Aplikační
role nemůže samostatným SQL změnit řádek a obejít ETag. Použitý kód účtu
a hranice období jsou chráněné i při souběžném ukládání dokladu.

Kontrakt, omezení, vysvětlení PATCH a chyby obsahuje [návod dokladů](doklady_api.md).
[Implementační plán 7.2](etapa7_2_plan.md) zachovává podrobné zadání.

### 3.2 Nastavení → automatické ověření

1. Ponechte běžet tunel z kroku 0.1. Backend ani web nejsou pro testy potřeba.
2. V druhém okně z kořene projektu spusťte:

   ```powershell
   .\tools\ucto\04-doklady-priprava.ps1 -Target Test
   ```

   Očekávejte zprávu `Rozpracovane doklady pripraveny: Test`.

3. Po úspěchu spusťte:

   ```powershell
   .\tools\ucto\05-doklady-test.ps1
   ```

   Skript provede jednotkové testy a celou DB regresi včetně nových povinných
   tříd DraftDocumentsTest a DraftConcurrencyTest. Ověří také existující
   účetní třídy a export OpenAPI. Při selhání nebo přeskočení povinných
   testů vrátí chybu. Úspěšný závěr začíná `Cast 7.2 OK`.

Testy samy připraví účty, období, partnery, role a dokumenty v oddělených
jednotkách. Ověří uložení 100.00/99.00, doplnění na 100.00/100.00, vysoké
součty, chybný poslední řádek, rollback po částečné výměně, ETag, idempotenci,
RLS, historii a skutečný souběh dvou procesů. Obsluha nezadává testovací data.

**Hotovo na testovací DB, když:** projdou nové i regresní kontroly. Test
nemigruje `playground`, neprovádí zaúčtování ani nepotvrzuje živé použití
přes Entra či budoucí webový formulář.

## 4. Část 7.3 – zaúčtování a uzamčení období

### 4.1 Dodaná implementace

Zaúčtování ověří otevřené období, datum, nejméně dva platné řádky a přesnou
rovnost MD/DAL, přidělí číslo řady INT a označí doklad posted. Čítač, stav,
audit a idempotentní odpověď se potvrzují společně. Zaúčtovaný doklad
a řádky jsou pro aplikaci neměnné, i při bezezměnovém PATCH.

Uzavření období provádí pouze administrátor a odmítne zbývající drafty.
Potom nelze založit nebo změnit zápis do období, upravovat období ani je
znovuotevřít. Čtení a historie zůstávají dostupné. Nejde o automatickou
roční závěrku. Přesný kontrakt a chyby jsou v [návodu 7.3](zauctovani_api.md).

### 4.2 Nastavení → automatické ověření

1. Ponechte běžet SSH tunel. Backend ani web nejsou pro tuto sadu potřeba.
2. Z kořene projektu v druhém okně spusťte:

   ```powershell
   .\tools\ucto\06-zauctovani-priprava.ps1 -Target Test
   ```

3. Po úspěchu spusťte:

   ```powershell
   .\tools\ucto\07-zauctovani-test.ps1
   ```

Očekávaný závěr začíná `Cast 7.3 OK`. Skript vyžaduje skutečný úspěšný běh
nových PostingTest a PostingConcurrencyTest i dosavadních účetních tříd,
provede jednotkové/DB regrese, ověření migrací a export 11 účetních cest.
Při chybě vrátí nenulový kód a ponechá JUnit report pro diagnostiku.

Automatické scénáře zahrnují vyrovnaný/nevyrovnaný návrh, rollback čísla při
pozdní chybě, neměnnost, role, ETag, idempotentní opakování, zbývající drafty,
zákaz znovuotevření a pět skutečně souběžných scénářů dvou procesů.

**Hotovo na testovací DB, když:** projdou nové i dosavadní kontroly.
Vývojové nasazení a živé použití jsou oddělené; webová tlačítka dodává 7.4.

## 5. Část 7.4 – webové obrazovky

**Implementace je dodaná.** Přesná obsluha, skript `08-web-test.ps1`,
kontrakt deníku a stav ověření jsou v [návodu účetního webu](ucetni_web.md).
Následující scénáře vymezují dodanou funkčnost; živé ověření kapitoly 6
se eviduje samostatně.

### 5.1 Nastavení: doplníme navigaci a formuláře

Web nabízí sekce **Účty**, **Období** a **Doklady**.
Formulář návrhu má hlavičku, tabulku řádků, součty MD/DAL a rozdíl.
Zaúčtování je samostatná potvrzovaná akce. Zaúčtovaný doklad se zobrazí
jen ke čtení, včetně čísla a historie. Deník zobrazuje zaúčtované záznamy
s filtrem období a účtu a s přechodem na doklad.

Pro čtenáře se nezobrazují akce zápisu. Uzavření období vyžaduje příslušné
oprávnění a potvrzení. Chyba spojení nesmí zobrazovat úspěšné zaúčtování;
opakování zápisu zachová původní idempotentní požadavek.

### 5.2 Ověření: automaticky v prohlížeči

Automatické testy pokrývají sestavení návrhu, změnu řádků, validaci, konflikt verze,
zaúčtování, uzavření období, roli čtenáře, změnu jednotky a výpadek spojení.
Scénáře běží v desktopovém i mobilním rozměru a doplní stávající partnerské
testy. Náhradní API v těchto testech nenahrazuje ověření DB pravidel z kroku 4.

**Hotovo, když:** projde TypeScript, build, všechny dosavadní i nové testy
a obrazovky jsou ověřené také vizuálně.

## 6. Vývojová migrace a ruční průchod proti Google DB

Tento krok následuje **až po dodání a ověření příslušné účetní části**.
Pro části 7.1 až 7.3 migrace existují. Vývojové nasazení je samostatný krok
autorizovaný až po přípravě zálohy a ověření její obnovy; testovací skripty
je neprovádějí.

### 6.1 Nejprve testovací DB, potom vývojová

Po dodání migrací použijeme existující migrační nástroje:

```powershell
# Nejprve testovaci databaze a nove databazove testy.
.\tools\dev\06-migrace.ps1 -Target Test -Action Status
.\tools\dev\06-migrace.ps1 -Target Test -Action Migrate
.\tools\dev\07-db-test.ps1
```

Teprve po úspěchu nových i regresních kontrol se připraví aktuální záloha,
ověří skutečná obnova a nacvičí migrace. Postup a jeho ochrany popisuje
[vývojové nasazení](vyvoj_nasazeni.md):

```powershell
$zaloha = .\tools\ucto\09-vyvoj-zaloha.ps1 -PassThru
.\tools\ucto\10-vyvoj-obnova-test.ps1 -BackupDirectory $zaloha
.\tools\ucto\11-vyvoj-nasazeni.ps1 -BackupDirectory $zaloha
.\tools\ucto\11-vyvoj-nasazeni.ps1 -BackupDirectory $zaloha -Apply
.\tools\dev\06-migrace.ps1 -Target Development -Action UpToDate
```

Vývojová DB se nemaže ani neresetuje. Skript 10 obnovuje pouze novou označenou
kopii na stejném PostgreSQL 16 a po ověření ji uklidí. Skript 11 bez `-Apply`
provádí pouze kontrolu; samotná existence exportního souboru k nasazení nestačí.

### 6.2 Připravíme oddělenou jednotku pro ruční účetní test

Pro zkoušku uzavření slouží samostatná vývojová jednotka `UCTO_DEV`.
Neuzavírejte období, ve kterém chcete dál pořizovat jiné doklady. Nasazení
připraví tuto jednotku stejnému existujícímu uživateli, pokud je v DEV právě
jeden aktivní administrátor; stav a ID jsou v `deployment-report.json`.
Při nejednoznačné identitě lze použít existující `09-uzivatel.ps1`.

Účty, období, partner a doklady pro následující scénář patří do `UCTO_DEV`.
Partner z dosavadní jednotky `DEV` se do cizího účetnictví nepřipojuje.

### 6.3 Provedete živý scénář v prohlížeči

1. Ponechte běžet tunel a spusťte/restartujte backend skriptem 03 a web
   skriptem 17, jako v [etapě 6](web.md#33-spusťte-tunel-backend-a-web).
2. Přihlaste se přes Microsoft a vyberte `UCTO_DEV`.
3. Založte dva testovací účty podle dodaných pravidel kódu a označte je jako
   účtovatelné. Založte testovací období.
4. Vytvořte návrh se dvěma řádky: první MD `100.00`, druhý DAL `99.00`.
   Návrh se uloží; pokus o zaúčtování se musí odmítnout.
5. Změňte DAL na `100.00` a zaúčtujte. Ověřte stav, číslo dokladu,
   součty, deník a historii. Opakování nesmí vytvořit další zaúčtování.
6. Otevřete zaúčtovaný doklad a ověřte, že nelze změnit hlavičku ani řádky.
7. Vytvořte další návrh v období a ověřte odmítnutí uzavření. Návrh následně
   dokončete a zaúčtujte, aby období neobsahovalo rozpracované doklady.
8. Uzavřete testovací období. Pokus o další zápis do něj musí být odmítnutý.
   Deník a historie zůstanou dostupné ke čtení.
9. Přepněte do `DEV` a ověřte, že testovací účetní data z `UCTO_DEV` nevidíte.

**Hotovo, když:** jsou výsledky zaznamenané pro skutečné API a Google DB,
včetně odmítnutých operací. Potom se příslušná část označí v implementačním
plánu jako ověřená; celá etapa 7 až po dokončení všech čtyř částí.

## Dostupné účetní skripty a navazující práce

| Dostupný skript | Účel |
| --- | --- |
| `tools/ucto/01-osnova-obdobi.ps1` | Migrace a ověření aktuálnosti; výchozí cíl Test |
| `tools/ucto/02-osnova-obdobi-test.ps1` | Nové účetní testy, dosavadní regrese a kontrola jejich skutečného běhu |
| `tools/ucto/03-openapi.ps1` | Export skutečných účetních endpointů do `shared/openapi/accounting.json` |
| `tools/ucto/04-doklady-priprava.ps1` | Příprava migrací dokladů, výchozí cíl Test |
| `tools/ucto/05-doklady-test.ps1` | Celá regrese a kontrola skutečného běhu nových dokladových testů |
| `tools/ucto/06-zauctovani-priprava.ps1` | Příprava migrací zaúčtování a uzavírání, výchozí cíl Test |
| `tools/ucto/07-zauctovani-test.ps1` | Úplná sada včetně povinných testů zaúčtování a souběhu |
| `tools/ucto/08-web-test.ps1` | Účetní web, původní partnerské scénáře, typy a sestavení; desktop i mobil |
| `tools/ucto/09-vyvoj-zaloha.ps1` | Konzistentní záloha playground a manifest otisků; zdroj jen pro čtení |
| `tools/ucto/10-vyvoj-obnova-test.ps1` | Obnova na nové kopii, porovnání, migrace a účetní scénář; úklid kopie |
| `tools/ucto/11-vyvoj-nasazeni.ps1` | Kontrola ověřené zálohy; s -Apply transakční vývojové nasazení |
| `tools/ucto/12-vyvoj-kontrola.ps1` | Jen čtení: verze, audit, aplikační práva UCTO_DEV; volitelně HTTP |

**Živý průchod v UCTO_DEV byl podle potvrzení uživatele dokončen a funguje
(4. 11. 2026).** Záloha, obnova a vývojové nasazení již prošly. Navazuje
[plán etapy 8 – hlavní kniha a obratová předvaha](etapa8_plan.md).
