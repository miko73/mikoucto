# Etapa 7 – záloha, ověření obnovy a vývojové nasazení

Nástroje se vztahují ke stávající VM `pg-free` v projektu `pg-free-michal`,
zóně `us-central1-a`, databázi `playground` a PostgreSQL 16. Využívají již
nastavené SSH přihlášení `michal_kocandrle` a `sudo -n -u postgres` stejně
jako příprava testovací DB. Nevytvářejí nové cloudové prostředky ani role
PostgreSQL. Hesla ani připojovací řetězce nepředávají v argumentech procesů.

## 1. Připravte tunel a dokončete testy

Příkazy spouštějte z kořene projektu. V prvním okně ponechte běžet tunel:

```powershell
.\db\google\00-tunel.ps1
```

Ve druhém okně dokončete ověření aplikace, pokud od posledního úspěšného
běhu došlo ke změnám:

```powershell
.\tools\ucto\07-zauctovani-test.ps1
.\tools\ucto\08-web-test.ps1
```

Celá DB sada pracuje výhradně v `mikoucto_test` a trvá přes dvacet minut.
Příprava zálohy ji nenahrazuje.

## 2. Vytvořte konzistentní zálohu

```powershell
$zaloha = .\tools\ucto\09-vyvoj-zaloha.ps1 -PassThru
```

Skript vytvoří nový adresář `db/backups/playground_<UTC čas>_<náhodné ID>`.
Adresář je mimo Git a přístup mají vlastník, SYSTEM a správci Windows.
Archiv obsahuje aplikační data; samotný `database.dump` není šifrovaný.

Spojení `mikoucto_owner` drží na `playground` transakci REPEATABLE READ
READ ONLY a exportovaný snapshot. Ze stejného snapshotu se pořídí manifest
a úplný archiv nativním `pg_dump` 16 na VM. Identifikátor spojení a náhodné
označení ověřují, že tunel skutečně vede na tuto VM. Po přenosu přes SCP
se porovná SHA-256 vzdáleného a místního souboru a snapshot se uvolní.

Obsah adresáře:

- `database.dump`: úplná logická záloha jedné databáze v custom formátu.
- `archive.json`: velikost, SHA-256 archivu a SHA-256 zdrojového manifestu.
- `source.json`: otisky řádků a schématu, audit, sekvence, role bez hesel,
  ACL, metadata zdroje a SHA-256 všech souborů migrací.
- `roles.dpapi.clixml`: kopie již šifrovaných projektových hesel; lze ji
  dešifrovat pouze pod stejným Windows účtem na stejném počítači.

Manifest porovnává celé tabulky i jednotlivé auditní partitions.
Součet kontrolovaných řádků tedy může zahrnovat auditní řádek zároveň
v partition i v rodičovské tabulce; nejde o počet unikátních obchodních záznamů.
Otisk řádku se počítá z kanonického JSON, v deterministickém pořadí,
pomocí SHA-256. Přesné částky zůstávají v databázové reprezentaci.

Samotné vytvoření archivu ještě neznamená ověřenou obnovu.

## 3. Ověřte obnovu a nacvičte nasazení

```powershell
.\tools\ucto\10-vyvoj-obnova-test.ps1 -BackupDirectory $zaloha
```

Skript vytvoří novou databázi `mikoucto_restore_<ID>` na stejném PostgreSQL 16,
se stejným kódováním, locale, vlastníkem a DB oprávněními. Existující cílovou
databázi odmítne měnit. Kopii označí sdíleným databázovým komentářem, nahraje
místní archiv zpět na VM a obnoví jej nativním `pg_restore` 16 v jedné transakci.

Poté provede:

1. Porovnání dat, sloupců, funkcí, vlastníků, ACL, constraints, indexů,
   triggerů, partitions, RLS, rozšíření a stavů sekvencí se zdrojovým manifestem.
   Čítače sekvencí nejsou MVCC data; připouští se jejich posun dopředu během
   zálohy, nikoli návrat nebo opětovné použití již spotřebované hodnoty.
2. Kontrolu `core.audit_verify()` v obnovené databázi.
3. Skutečné spuštění všech čekajících migrací v chráněné vnější transakci,
   včetně zámků a kontrol, které použije vývojové nasazení.
4. Ověření zachování každého původního řádku, včetně opakujících se řádků.
5. Účetní scénář pod skutečnou aplikační DB rolí: odmítnutí nevyrovnaného
   dokladu, správné číslo, opakování zaúčtování bez dalšího auditu, deník
   s filtrem, odmítnutí uzavření s návrhem, úspěšné uzavření po zaúčtování,
   neměnnost dokladu, zákaz dalších zápisů a izolaci jednotek.
6. Nové porovnání zdrojové `playground` s původním otiskem.

Výsledek je v `restore-report.json`. Obnovovací databáze a vzdálená pracovní
kopie archivu se po pokusu uklidí; před DROP se znovu ověřuje přesný název,
vlastník a komentář s ID zálohy. `playground`, `mikoucto_test` ani jiné
existující databáze nejsou přípustnými cíli obnovy nebo jejího úklidu.
Místní archiv zůstává uložený. Před opakováním pokusu se odstraní jen staré
průběžné výsledky, aby se nemohl omylem použít report předchozího běhu.

## 4. Proveďte kontrolu a vývojové nasazení

Nejprve spusťte pouze kontrolu:

```powershell
.\tools\ucto\11-vyvoj-nasazeni.ps1 -BackupDirectory $zaloha
```

Bez `-Apply` se nic nemigruje. Kontrola vyžaduje správné hashe souborů,
úspěšnou skutečnou obnovu, nácvik vnější transakce, platný audit, shodu
migračních souborů a report mladší než 24 hodin. Zdrojová data i schéma
musí odpovídat záloze. Pokud došlo ke změně, vytvořte a ověřte novou zálohu.

Vlastní vývojovou migraci spustí:

```powershell
.\tools\ucto\11-vyvoj-nasazeni.ps1 -BackupDirectory $zaloha -Apply
```

Nasazení používá pouze `playground` a roli `mikoucto_owner`. V jedné vnější
transakci zamkne původní aplikační tabulky proti zápisu, znovu ověří jejich
shodu se zálohou, aplikuje transakční migrace a ověří zachování původních
řádků i auditu. Teprve potom provede COMMIT. Čtení může pokračovat; zápisy
mohou během této krátké operace čekat nebo narazit na svůj časový limit.

Pokud je v jednotce `DEV` právě jeden aktivní administrátor, připraví se
pro stejného existujícího uživatele také jednotka `UCTO_DEV`. Nevytváří se
náhradní živá Entra identita ani přihlašovací token. Při nejednoznačné identitě
nasazení uvede, že je nutné jednotku přidělit existujícím skriptem 09-uzivatel.

Výsledek a ID testovací jednotky jsou v `deployment-report.json`.
Přístup skutečnou aplikační DB rolí, oprávnění testovací jednotky, verzi a audit
ověří následující kontrola. S `-Http` vyžaduje také běžící backend a web:

```powershell
.\tools\ucto\12-vyvoj-kontrola.ps1 -BackupDirectory $zaloha -Http
```

Při chybě nebo ztrátě spojení před opakováním ověřte tento report a stav
migrací; neobnovujte automaticky celou zdrojovou DB přes případné novější zápisy:

```powershell
.\tools\dev\06-migrace.ps1 -Target Development -Action Status
```

## 5. Proveďte živý průchod z prohlížeče

Tento postup vyplňujte přímo ve webové aplikaci. Vytvoříte dva účty, jedno
testovací období a dva doklady. Částky jsou pouze zkušební, v českých korunách.
**Zaúčtovaný doklad už nelze upravovat a uzavřené období nelze znovu otevřít.**
Proto všechny kroky provádějte v jednotce **UCTO_DEV**, nikoli v **DEV**.

### 5.1 Otevřete aplikaci a vyberte správnou jednotku

1. Z kořene projektu spusťte `.\spustit.ps1`, případně použijte ruční postup
   ze začátku [README](../README.md#ruční-spuštění-aplikace-krok-za-krokem).
2. Otevřete [http://localhost:8005/](http://localhost:8005/).
   Pokud už stránku máte otevřenou a nemáte rozpracovaný ani nepotvrzený zápis,
   můžete ji obnovit klávesou F5.
3. Přihlaste se svým stávajícím účtem Microsoft.
4. Ve výběru účetní jednotky zvolte **UCTO_DEV**. V záhlaví účetní sekce
   se musí zobrazovat `UCTO_DEV / Účetnictví`.
5. V navigaci najděte **Účty**, **Období**, **Doklady** a **Deník**.

Pokud jednotku UCTO_DEV nevidíte nebo nemáte tlačítka pro vytváření záznamů,
dál nepokračujte: nejprve je potřeba ověřit přiřazení jednotky a oprávnění účtu.

**Při čekání na zápis:** pokud aplikace oznámí, že výsledek není potvrzený,
neobnovujte stránku a nezakládejte záznam znovu. Použijte tlačítko pro opakování
původního požadavku. Další krok dělejte až po potvrzení uložení.

### 5.2 Vytvořte první účet – pokladnu

Účet je položka účtové osnovy, na kterou se zapisují částky. „Účtovatelný“
znamená, že jej lze vybrat na řádku dokladu. Nadřazený účet slouží ke členění
osnovy; pro tuto zkoušku jej nepotřebujete.

1. Klikněte na **Účty** a potom na **Nový účet**.
2. Vyplňte formulář přesně takto:

| Pole | Co vyplnit nebo vybrat |
| --- | --- |
| Kód účtu | `211` |
| Název účtu | `Pokladna` |
| Nadřazený účet | Ponechte **Bez nadřazeného účtu**. |
| Aktivní účet | Zaškrtněte. |
| Účtovatelný účet | Zaškrtněte. |

3. Klikněte na **Uložit záznam**. Počkejte na zprávu **Záznam byl uložen.**
4. Klikněte na **Zavřít**. V seznamu zkontrolujte účet **211 Pokladna**,
   sloupec **Účtovatelný: Ano** a stav **Aktivní**.

Pokud účet 211 v UCTO_DEV již existuje z předchozího pokusu, nezakládejte jej
podruhé. Otevřete jej kliknutím na kód a název a ověřte uvedené hodnoty.
Existující používaný účet nepřejmenovávejte jen kvůli této zkoušce.

### 5.3 Vytvořte druhý účet – bankovní účet

1. V sekci **Účty** klikněte znovu na **Nový účet**.
2. Vyplňte:

| Pole | Co vyplnit nebo vybrat |
| --- | --- |
| Kód účtu | `221` |
| Název účtu | `Bankovní účet` |
| Nadřazený účet | **Bez nadřazeného účtu**; nevybírejte účet 211. |
| Aktivní účet | Zaškrtněte. |
| Účtovatelný účet | Zaškrtněte. |

3. Klikněte na **Uložit záznam**, počkejte na potvrzení a klikněte na **Zavřít**.
4. V seznamu musí být oba účty aktivní a účtovatelné. Pokud 221 již existuje,
   postupujte stejně jako u existujícího účtu 211.

### 5.4 Vytvořte samostatné testovací období

Období určuje rozsah dat, do kterého můžete pořizovat doklady. Pro tuto zkoušku
použijeme listopad 2026 a datum dokladu 4. listopadu 2026. Datum nemusí být dnešní.

1. Klikněte na **Období**. Nejprve se podívejte, zda už v UCTO_DEV existuje
   období zasahující do listopadu 2026.
2. Pokud neexistuje, klikněte na **Nové období** a vyplňte:

| Pole | Hodnota |
| --- | --- |
| Název období | `Živý test – listopad 2026` |
| Začátek období | `01.11.2026` |
| Konec období | `30.11.2026` |

3. Data vyberte v kalendáři nebo napište ve formátu, který nabízí prohlížeč.
   Pokud zobrazuje rok jako první, odpovídají jim `2026-11-01` a `2026-11-30`.
   Přepište obě předvyplněná data; neponechávejte automaticky celý rok.
4. Klikněte na **Uložit záznam** a po potvrzení na **Zavřít**.
5. Zkontrolujte název, obě data a stav **Otevřené**. Stav se nastaví automaticky,
   ve formuláři ho nevybíráte.

Období se v jedné jednotce nesmějí překrývat. Jestliže už listopad pokrývá jiné
období, pro tuto zkoušku použijte jiný volný měsíc a datum uvnitř něj. Stejný
název období a stejné datum potom používejte u obou dokladů v dalších krocích.
Období s jinými doklady nepoužívejte, protože jej na konci budete uzavírat.
Uzavřené období z předchozího pokusu znovu použít nelze.

### 5.5 Uložte první doklad s úmyslně rozdílnými částkami

Návrh neboli rozpracovaný doklad můžete opravovat. Uložení návrhu ještě není
zaúčtování a jeho řádky se zatím nezobrazují v Deníku. MD („Má dáti“) a DAL
(„Dal“) jsou dvě strany účetního zápisu. Před zaúčtováním musí být jejich
celkové částky stejné. Teď rozdíl vytvoříme schválně.

1. Klikněte na **Doklady** a potom na **Nový doklad**.
2. Vyplňte horní část formuláře:

| Pole | Hodnota |
| --- | --- |
| Účetní období | Vyberte **Živý test – listopad 2026**. |
| Datum účtování | `04.11.2026` (případně `2026-11-04`). |
| Popis dokladu | `Živý test 01 – převod do pokladny` |
| Partner dokladu | Ponechte **Bez partnera**. Pro tento test partnera nepotřebujete. |

3. Klikněte **dvakrát** na **Přidat řádek**, aby vznikly řádky 01 a 02.
   Pokud už dva řádky vidíte, další nepřidávejte.
4. Vyplňte všechna pole obou řádků podle tabulky. Částky zadávejte bez `Kč`
   a bez mezer; aplikace přijímá desetinnou tečku i čárku.

| Pole | Řádek 1 | Řádek 2 |
| --- | --- | --- |
| Účet řádku | **211 · Pokladna** | **221 · Bankovní účet** |
| Popis řádku | `Příjem hotovosti – test 01` | `Výdej z banky – test 01` |
| MD | `100.00` | `0.00` |
| DAL | `0.00` | `99.00` |

5. Dole zkontrolujte **CELKEM MD 100,00**, **CELKEM DAL 99,00** a
   **ROZDÍL 1,00**. Na každém řádku je kladná částka pouze na jedné straně;
   na opačné straně ponechte nulu.
6. Klikněte na **Uložit návrh**. Uložení musí projít i s tímto rozdílem.
7. Po potvrzení uložení zkontrolujte stav **Rozpracovaný návrh**. Tlačítko
   **Zaúčtovat doklad** má být neaktivní, protože částky nejsou stejné.
   Při prvním otevření nového formuláře se toto tlačítko ještě nezobrazuje.

Pokud jste detail zavřeli, v seznamu klikněte na **Návrh bez čísla** u popisu
`Živý test 01 – převod do pokladny`. Nezakládejte další kopii prvního dokladu.

### 5.6 Opravte první doklad a zaúčtujte jej

1. V detailu prvního dokladu změňte pouze **DAL řádek 2** z `99.00` na `100.00`.
2. Ověřte součty **MD 100,00**, **DAL 100,00**, **ROZDÍL 0,00** a zprávu
   **Doklad je vyrovnaný.**
3. Klikněte na **Uložit návrh** a počkejte na potvrzení. Tlačítko pro
   zaúčtování je do uložení změn neaktivní.
4. Klikněte na **Zaúčtovat doklad**. Přečtěte si potvrzovací okno a potvrďte
   tlačítkem **OK** (nebo odpovídajícím potvrzením vašeho prohlížeče).
5. Počkejte na zprávu **Doklad byl zaúčtován.** Ověřte:

   - V detailu je stav **Zaúčtováno** a automaticky přidělené číslo dokladu.
     Číslo si poznamenejte; ručně jej nezadáváte.
   - Pole jsou zašedlá a nelze je upravovat. Zobrazuje se
     **Záznam je pouze ke čtení.**
   - Je uveden čas zaúčtování a uživatel, který je provedl.

6. Klikněte na **Zobrazit účetní historii**. Ověřte záznam o vytvoření a změnách,
   včetně změny stavu při zaúčtování. Historie může zobrazovat také technické
   názvy polí a identifikátory uživatele; nemusíte je nikam přepisovat.
7. Klikněte na **Zavřít**.

### 5.7 Ověřte první doklad v Deníku

Deník zobrazuje jednotlivé zaúčtované řádky. Jeden dvouřádkový doklad tedy
vytvoří dva řádky Deníku.

1. Klikněte na **Deník**.
2. Ve filtru **Období** vyberte **Živý test – listopad 2026**. Filtr **Účet**
   ponechte na **Všechny účty**. Vymažte případný hledaný text a filtry **Od**
   a **Do**, aby výsledek neomezovaly.
3. Po načtení uvidíte dva řádky se stejným číslem prvního dokladu:

| Účet | MD · CZK | DAL · CZK |
| --- | --- | --- |
| 211 Pokladna | 100,00 | 0,00 |
| 221 Bankovní účet | 0,00 | 100,00 |

4. Ve filtru **Účet** vyberte **211 · Pokladna**. Musí zůstat jen řádek
   s MD 100,00. Potom vyberte **221 · Bankovní účet** a ověřte řádek s DAL 100,00.
5. Vraťte filtr na **Všechny účty**. Kliknutím na číslo dokladu můžete otevřít
   jeho uzamčený detail; poté jej zavřete.

### 5.8 Vytvořte druhý návrh a vyzkoušejte odmítnutí uzavření období

1. Přejděte do **Doklady** a klikněte na **Nový doklad**.
2. Vyplňte nový doklad podle následujících hodnot:

| Pole | Hodnota |
| --- | --- |
| Účetní období | **Živý test – listopad 2026** |
| Datum účtování | `04.11.2026` |
| Popis dokladu | `Živý test 02 – kontrola uzavření` |
| Partner dokladu | **Bez partnera** |

3. Přidejte dva řádky a vyplňte je takto:

| Pole | Řádek 1 | Řádek 2 |
| --- | --- | --- |
| Účet řádku | **211 · Pokladna** | **221 · Bankovní účet** |
| Popis řádku | `Příjem hotovosti – test 02` | `Výdej z banky – test 02` |
| MD | `50.00` | `0.00` |
| DAL | `0.00` | `49.00` |

4. Klikněte na **Uložit návrh**, počkejte na potvrzení a na **Zavřít**.
   Druhý doklad zatím **nezaúčtovávejte**.
5. Přejděte do **Období** a klikněte na název **Živý test – listopad 2026**.
6. Klikněte na **Uzavřít období** a potvrďte dialog.
7. Očekávejte hlášení **Období nelze uzavřít. Dokončete všechny rozpracované
   doklady a obnovte detail.** To je správný výsledek této zkoušky: druhý
   návrh uzavření blokuje. Období musí zůstat otevřené.
8. Klikněte na **Zavřít**.

### 5.9 Dokončete druhý doklad a uzavřete období

1. V **Dokladech** otevřete **Návrh bez čísla** s popisem
   `Živý test 02 – kontrola uzavření`.
2. Změňte **DAL řádek 2** z `49.00` na `50.00`. Součty mají být MD 50,00,
   DAL 50,00 a rozdíl 0,00.
3. Klikněte na **Uložit návrh**, počkejte na potvrzení, potom na
   **Zaúčtovat doklad** a potvrďte dialog.
4. Ověřte potvrzení zaúčtování a přidělené číslo. Musí být jiné než číslo
   prvního dokladu. Klikněte na **Zavřít**.
5. V **Obdobích** znovu otevřete **Živý test – listopad 2026**, aby se načetl
   aktuální detail. Klikněte na **Uzavřít období** a potvrďte dialog.
6. Tentokrát očekávejte **Období bylo uzavřeno.**, stav **Uzavřené období**,
   čas a uživatele uzavření. Pole již nelze upravovat.
7. Klikněte na **Zobrazit účetní historii** a ověřte změnu stavu na uzavřené.
   Potom detail zavřete. Také v seznamu musí být stav **Uzavřené**.

Pokud uzavření stále odmítá, podívejte se v **Dokladech** s filtrem tohoto
období, zda v něm nezůstal další rozpracovaný doklad z předchozího pokusu.
Nezaúčtovávejte neznámé návrhy jen proto, abyste období mohli uzavřít.

### 5.10 Ověřte zákaz dalšího pořizování do uzavřeného období

1. V **Dokladech** klikněte na **Nový doklad**.
2. Rozbalte pole **Účetní období**. Položka **Živý test – listopad 2026** má
   příponu **uzavřené** a nelze ji vybrat.
3. Tím jste ověřili zákaz v běžném formuláři. Třetí doklad neukládejte;
   nesmí se omylem uložit do jiného otevřeného období, které formulář nabídl.
4. Klikněte na **Zavřít**. Pokud se prohlížeč zeptá na zahození neuložených
   změn, potvrďte je.
5. V seznamu znovu otevřete první zaúčtovaný doklad a ověřte, že i po uzavření
   období zůstává pouze ke čtení. Potom jej zavřete.
6. V **Deníku** nastavte testovací období a **Všechny účty**. Bez dalších filtrů
   v něm nyní musí být čtyři řádky: dva z prvního a dva z druhého dokladu.
   Pro účet 211 budou dva řádky MD 100,00 a 50,00; pro účet 221 dva řádky
   DAL 100,00 a 50,00. Uzavření období tyto řádky nesmaže.

Tato ruční kontrola ověřuje chování webového formuláře. Odmítnutí zakázaného
zápisu přímo v API a databázi ověřuje automatická účetní sada; kvůli tomuto
průchodu nemusíte ručně sestavovat HTTP požadavky.

### 5.11 Ověřte oddělení jednotek a zapište výsledek

1. Zavřete všechny detaily a ve výběru účetní jednotky přepněte na **DEV**.
2. V **Obdobích** vyhledejte `Živý test – listopad 2026` a v **Dokladech**
   `Živý test 01` a potom `Živý test 02`. Záznamy právě vytvořené v UCTO_DEV
   se zde nesmějí zobrazit. Před hledáním odstraňte ostatní filtry.
3. Samotný výskyt účtu 211 nebo 221 v DEV není chyba: každá jednotka může mít
   vlastní účty se stejnými kódy. Kontrolujte konkrétní testovací období
   a doklady, nikoli pouze kód účtu.
4. Přepněte zpět na **UCTO_DEV**. Ověřte, že zde oba zaúčtované doklady
   a uzavřené testovací období stále vidíte.
5. Poznamenejte si datum provedení, použitou jednotku, název období, čísla
   obou dokladů a případný krok, který se nepodařil. Můžete použít tento vzor:

   ```text
   Datum kontroly:
   Jednotka: UCTO_DEV
   Období: Živý test – listopad 2026
   Číslo prvního dokladu:
   Číslo druhého dokladu:
   Nevyrovnaný návrh šel uložit, ale ne zaúčtovat: ANO / NE
   Zaúčtované doklady jsou uzamčené, historie je dostupná: ANO / NE
   Deník obsahuje čtyři řádky, filtr účtu funguje: ANO / NE
   Uzavření s návrhem bylo odmítnuto: ANO / NE
   Po zaúčtování obou dokladů šlo období uzavřít: ANO / NE
   Uzavřené období nelze vybrat pro nový doklad: ANO / NE
   Testovací záznamy se v DEV nezobrazují: ANO / NE
   Poznámka nebo chybové hlášení:
   ```

Tento záznam potvrzuje skutečný průchod od přihlášení přes web až po uložení
do databáze (Entra → web → API → DB). Eviduje se odděleně od automatické
zkoušky obnovené kopie. Výsledky vyplňte až podle skutečně provedených kroků.

## Rozsah zálohy

Jde o předmigrační logickou zálohu jedné DB a ověření na stejném clusteru.
Globální role a serverová konfigurace nejsou součástí `pg_dump`; existující
role se při zkoušce nemění. Přenositelná obnova celého serveru není tímto
postupem ověřená. Projektový verifier odmítne velké objekty a foreign tables,
pro které zde není implementované porovnání obsahu.

## Ověřeno a nasazeno 4. 11. 2026

Tyto kroky již byly provedené:

- Konzistentní archiv: `db/backups/playground_20261104T092841Z_13f1c4484413`.
  SHA-256 archivu i manifestu odpovídají; soukromé soubory nejsou v Gitu.
- Skutečná obnova na PostgreSQL 16: shoda všech 16 porovnávaných tabulek
  a partitions, jejich obsahu, schématu, oprávnění, sekvencí a auditu.
- Nácvik všech pěti čekajících migrací, stejné chráněné vnější transakce
  a účetních scénářů prošel. Obnovovací databáze byla odstraněná.
- Celá DB regrese: **65 testů, 665 kontrol**, bez selhání; 25 minut 21 sekund.
- Jednotková sada backendu: **85 testů, 214 kontrol**. Po doplnění kontrol
  důkazu obnovy prošla také cílená sada **15 testů, 20 kontrol**; její část
  se překrývá s původní jednotkovou sadou.
- Vývojová `playground` byla transakčně migrovaná na
  `App\Migrations\Version20261103030100`. Původní řádky byly před COMMIT
  porovnané a zachované; audit je platný. Stav migrací je Up-to-date.
- Stávající administrátor `DEV` má připravenou jednotku **UCTO_DEV**.
  Ověření skutečnou rolí `mikoucto_app` potvrdilo účetní oprávnění a přístup
  k účtům, obdobím, dokladům a deníku. Jednotka je připravená bez finančních
  záznamů; automatický scénář zaúčtování proběhl pouze na odstraněné kopii.
- Kontrola 12 s HTTP prošla: oba backendové health endpointy i web
  `http://localhost:8005` odpovídají úspěšně.

Podrobné výsledky jsou v `restore-report.json`, `deployment-report.json`
a `readiness-report.json` uvnitř uvedeného soukromého adresáře. Archiv zachycuje
stav **před** nasazením; pro další nasazení se pořizuje nová aktuální záloha.
Pro již nasazený stav používejte kontrolu 12, nikoli opětovnou aplikaci 11
se starým otiskem.

Živý Entra → web → API → DB průchod z kapitoly 5 uživatel dne 4. 11. 2026
potvrdil slovy „hotovo, funguje to“. Jde o potvrzení obsluhy; konkrétní
čísla dokladů ani vyplněný kontrolní formulář nebyly předány. Další práce
navazují [plánem etapy 8](etapa8_plan.md).

Postup vychází z dokumentace PostgreSQL 16 pro
[konzistentní pg_dump a snapshot](https://www.postgresql.org/docs/16/app-pgdump.html)
a [obnovu archivů pomocí pg_restore](https://www.postgresql.org/docs/16/app-pgrestore.html).
