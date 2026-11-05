# Plán etapy 8 – hlavní kniha a obratová předvaha

Stav: **etapa 8 dokončena**. Části 8.1–8.3 jsou implementované a automaticky ověřené;
živé ověření 8.4 uživatel v konverzaci potvrdil dne 4. 11. 2026.
Aktuální kontrakt a příkazy jsou v [návodu sestav API](sestavy_api.md).
Část 8.2 dodává oba CSV exporty; viz [CSV a výsledky ověření](sestavy_csv.md).
Část 8.3 dodává webové obrazovky a stažení CSV; [návod pro obsluhu](sestavy_web.md)
obsahuje také postup potvrzené živé přejímky 8.4. Dokument zachovává zadání celé
etapy. Navazuje na [etapu 7](etapa7.md), jejíž živý průchod
v UCTO_DEV uživatel v této konverzaci potvrdil jako funkční dne 4. 11. 2026.
Navazuje [plán etapy 9 – opravné a stornovací doklady](etapa9_plan.md).

## 1. Výsledek pro uživatele a hranice první verze

V aplikaci přibudou obrazovky **Hlavní kniha** a **Obratová předvaha**.
Uživatel vybere období a rozsah dat. V hlavní knize vybere také účet, uvidí
jeho zápisy, průběžný zůstatek a otevře původní doklad. Předvaha shrne všechny
účty a zkontroluje rovnost součtů MD a DAL. Obě sestavy půjdou stáhnout do CSV.

První verze pracuje s jedním vybraným obdobím a pouze s CZK. Čte existující
zaúčtované doklady; nepřidává ani nemění účetní zápisy. Otevřená i uzavřená
období lze prohlížet. Rozpracované doklady do výpočtů nevstupují.

**Důležitá hranice:** aplikace dosud neeviduje samostatné počáteční stavy ani
jejich automatický převod mezi obdobími. „Počáteční zůstatek výběru“ proto
znamená součet evidovaných pohybů od začátku vybraného období před datem Od.
Na začátku období je nulový, pokud do něj nebyly vloženy příslušné účetní
zápisy. Jiné období se nepřičítá automaticky. Na obrazovce i v CSV bude uvedeno:

> Výpočet z evidovaných zaúčtovaných pohybů vybraného období. Převod
> počátečních stavů z jiných období není zahrnut.

Převody počátečních stavů, roční závěrka, rozvaha, výsledovka, fakturace,
DPH, cizí měny a storno budou samostatné navazující dodávky. Sestavy této
etapy nebudeme označovat za hotovou účetní závěrku.

## 2. Jednotná pravidla výpočtu

Zdrojem jsou `ucto.documents` se stavem `posted`, jejich `document_lines`
a aktuální údaje o účtu z `ucto.accounts`. Všechny vazby obsahují také
`accounting_unit_id`, stejně jako dosavadní Deník. Datum výběru je
`posting_date`, nikoli čas vytvoření nebo zaúčtování dokladu.

| Parametr nebo pojem | Pravidlo |
| --- | --- |
| Období | Povinné, existující ve vybrané jednotce. |
| Od, Do | Platná data YYYY-MM-DD; obě hranice včetně, Od ≤ Do. |
| Výchozí data | Začátek a konec vybraného období. |
| Rozsah dat | Musí ležet celý uvnitř období; chybný rozsah se odmítne, nebude se potichu ořezávat. |
| Počáteční zůstatek výběru | Součet MD − DAL v daném období před Od. |
| Obrat MD / DAL | Samostatné součty stran mezi Od a Do včetně. |
| Konečný zůstatek | Počáteční zůstatek + obrat MD − obrat DAL. |
| Kladný zůstatek | Strana MD. |
| Záporný zůstatek | Strana DAL; ve sloupci DAL se zobrazí kladná absolutní částka. |
| Nulový zůstatek | Na obou stranách `0.00`, žádné záporné nuly. |
| Neaktivní nebo nyní neúčtovatelný účet | Historické zaúčtované pohyby zůstávají zahrnuté. |
| Název a kód účtu | Aktuální údaje osnovy, stejně jako v Deníku; nejde o historický snímek názvu. |

SQL agregace a průběžné součty používají přesný `numeric`. JSON vrací částky
jako desetinné řetězce se dvěma místy, včetně součtů větších než limit jedné
částky řádku. PHP ani TypeScript je nesmějí převádět na float nebo JS Number.
Pro zobrazení se použijí existující přesné pomocné funkce, případně jejich
rozšíření pro podepsané součty bez omezení vstupu na jeden řádek dokladu.

Zaúčtování v otevřeném období může výsledky později změnit, a to i při
starším datu účtování. Každá odpověď proto obsahuje `generatedAt` a konkrétní
parametry výběru. Jedna odpověď musí mít konzistentní řádky, počet i součty
ze stejného DB snapshotu. Mezi samostatnými požadavky se snapshot neuchovává;
web ani CSV nesmějí slibovat totožné výsledky při mezitím provedeném zápisu.

## 3. Hlavní kniha

Pro jednu jednotku, období, rozsah a účet vrací:

- Účet, období, datum Od a Do, CZK a čas sestavení.
- Počáteční zůstatek výběru, obrat MD, obrat DAL a konečný zůstatek.
- Stránkované řádky: datum účtování, číslo dokladu, popis dokladu, popis
  řádku, MD, DAL a průběžný zůstatek.
- ID dokladu pro otevření jeho stávajícího detailu pouze ke čtení.

Pořadí je **datum vzestupně, ID dokladu vzestupně, číslo řádku vzestupně**.
Průběžný zůstatek počítá SQL nad všemi vybranými řádky před stránkováním;
na druhé stránce nesmí začínat od nuly. Součty patří celému výběru,
nikoli právě zobrazené stránce. Existující účet bez pohybů vrací nulové
součty a prázdný seznam. Neexistující nebo cizí účet se nevydává za prázdný.

V první verzi nebude textový filtr řádků hlavní knihy. Skrytí části zápisů
by znejasnilo návaznost průběžného zůstatku. K hledání podle popisu dál
slouží Deník. Výběr účtu nabídne i neaktivní účty s jejich označením.

## 4. Obratová předvaha

Jeden řádek odpovídá jednomu konkrétnímu účtu. Sloupce:

| Skupina | Sloupce |
| --- | --- |
| Účet | Kód, název |
| Počáteční zůstatek výběru | MD, DAL |
| Obraty za výběr | MD, DAL |
| Konečný zůstatek | MD, DAL |

Výchozí zobrazení zahrne účty s nenulovým počátečním zůstatkem nebo alespoň
jedním pohybem ve výběru. Přepínač **Zobrazit i účty bez pohybů** zahrne
celou osnovu. Účet s vyrovnanými obraty se nesmí ztratit jen kvůli nulovému
konečnému zůstatku. Řazení podle kódu zachová úvodní nuly a bude deterministické
(kód jako text, následně ID). Stejné pořadí použije web a export.

Nadřazené účty nebudou automaticky sčítat podřízené. Každý zápis se započítá
právě jednou na účet, na kterém byl zaúčtován. Řádek nadřazeného účtu
zobrazuje pouze jeho vlastní případné pohyby. Hierarchické mezisoučty
a syntetické sestavy se doplní později s vlastním pravidlem agregace.

Souhrnný blok platí pro celý výběr bez ohledu na stránku:

- Celkem počáteční MD a DAL, obrat MD a DAL, konečné MD a DAL.
- Rozdíly MD − DAL pro všechny tři skupiny a příznak `balanced`.
- Zpráva o rovnosti, nebo viditelné upozornění při rozdílu; žádné automatické
  dorovnávání částek. Rozdíl je výsledek kontroly, ne důvod skrýt sestavu.

Předvaha nebude mít filtr na jednotlivý účet ani textový filtr, který by
měnil celkovou kontrolu rovnosti. Kliknutím na účet se přejde do hlavní knihy
se stejným obdobím a daty. Nulové účty nemění součty ani výsledek kontroly.

## 5. Návrh API, oprávnění a technické provedení

Základ: `/api/v1/accounting-units/{unit}/reports`.

| Metoda a cesta | Parametry | Výsledek |
| --- | --- | --- |
| GET `/general-ledger` | `periodId`, `accountId`, `from`, `to`, `page`, `itemsPerPage` | Metadata, součty, počet a řádky hlavní knihy. |
| GET `/trial-balance` | `periodId`, `from`, `to`, `includeZero`, `page`, `itemsPerPage` | Metadata, kontrolní součty, počet a účty předvahy. |
| GET `/general-ledger.csv` | Stejné filtry bez stránkování | Kompletní výběr hlavní knihy. |
| GET `/trial-balance.csv` | Stejné filtry bez stránkování | Kompletní výběr předvahy. |

`periodId` je povinné; vynechané Od a Do server doplní z období. Stránka
začíná 1, velikost má výchozí hodnotu 20 a maximum 100. `includeZero` má
výchozí hodnotu false a přijímá pouze `true` nebo `false`. Neplatná UUID,
data a parametry vracejí 400 s konkrétním polem chyby. Neexistující nebo cizí
období či účet vracejí 404 po kontrole přístupu k jednotce. Cizí jednotka
nebo chybějící oprávnění se řídí stávajícím bezpečnostním kontraktem 401/403.

Odpověď JSON bude vlastní objekt s `filters`, `generatedAt`, `currency`,
`totals`, `items`, `total`, `page`, `itemsPerPage` a u předvahy kontrolou
rovnosti. OpenAPI musí přesně popsat strukturu a desetinné řetězce; web
použije generované typy. Podrobné názvy všech peněžních polí se uzavřou
v prvním implementačním kroku společně s ukázkami JSON a CSV.

Použije se stávající Entra přihlášení, `IdentitySession`, aplikační role,
RLS a oprávnění **`core.ucto.documents.read`**, stejně jako v Deníku.
Pro přístup jsou potřeba také současná oprávnění ke čtení jednotky
(`core.units.read`), účtů a období;
provider jejich dostupnost ověří, aby částečná oprávnění nevytvořila falešně
vyrovnanou či nulovou sestavu. CSV má stejné podmínky jako JSON.

Výpočty budou společné pro JSON i CSV v novém repository/service pro sestavy;
nebudou měnit dosavadní pořadí a kontrakt Deníku. Konzistenci jedné odpovědi
zajistí jeden SQL příkaz s CTE, agregacemi a window funkcemi, nebo pro export
výslovná read-only transakce REPEATABLE READ se správně nastavenou identitou.
Při volbě transakce je nutné respektovat existující `IdentitySession`;
izolace se nastaví před prvním datovým dotazem a nezmění režim zápisů dokladů.

Sestavy nevyžadují nové tabulky ani nové zapisovací funkce. Potřeba indexu
se rozhodne podle EXPLAIN na reprezentativní testovací sadě. Pokud bude
potřebný, dodá se nová migrace a její ověření; staré migrace se neupravují.
Všechna data se čtou pod aplikační rolí, nikoli pod vlastníkem DB.

## 6. CSV a webové obrazovky

CSV se stahuje přes autorizovaný API klient, nikoli veřejným odkazem.
Použije UTF-8 s BOM, středník, CRLF a české popisky. Částky mají dvě místa
s desetinnou čárkou, bez oddělovačů tisíců a bez `Kč` uvnitř číselného pole.
Úvodní řádky uvedou jednotku, období, rozsah, čas sestavení, CZK a omezení
počátečních stavů; následovat budou hlavička, datové řádky a souhrn.
Konkrétní struktura bude popsána ukázkovým souborem.

Exporter správně escapuje uvozovky, středníky a nové řádky. Textová pole,
která by tabulkový procesor vyhodnotil jako vzorec, bezpečně označí jako text;
záporné částky zůstanou číselné. Odpověď použije `text/csv`, bezpečný název
souboru a `Cache-Control: private, no-store`, stejně jako JSON sestav.
Test ověří i zachování textového kódu účtu s úvodními nulami v samotném CSV;
automatické převody při otevření v Excelu vysvětlí návod k importu.

Export načítá celý výběr na serveru, neskládá stránky JSON z prohlížeče.
První verze má limit **50 000 datových řádků**. Překročení vrátí 422 ještě
před zahájením stahování, s výzvou zúžit rozsah. Žádný potichu zkrácený soubor.
Počet i export pocházejí ze stejného snapshotu. Řádky lze číst kurzorem,
aby exporter nemusel držet celý výběr v paměti; transakce a kurzor se uklidí
i při chybě nebo přerušeném spojení.

Web přidá navigační položky, jasné popisky zůstatků, souhrny nezávislé na
stránkování a stav načítání. Při změně filtrů nebo jednotky odstraní staré
výsledky a zruší předchozí požadavek; stará odpověď nesmí přepsat nový výběr.
Tlačítko **Stáhnout CSV** je dostupné až pro platný výběr. Chyba stahování
se ukáže na obrazovce. Detail zaúčtovaného dokladu se znovu použije ze
stávajícího webu bez nabídky úprav. Ověří se desktop i mobilní rozměr.

## 7. Pořadí implementace a přejímka částí

| Část | Práce | Hotovo, když |
| --- | --- | --- |
| 8.1 Výpočty a JSON API | Přesný kontrakt, validace, repository, providery, oprávnění, generování OpenAPI typů. | Projdou nové skutečné DB testy, jednotkové testy a dosavadní účetní regrese. |
| 8.2 CSV | Společný zdroj výpočtů, úplný export, limit a bezpečné formátování. | Export odpovídá JSON pro stejný nezměněný dataset; projdou testy oprávnění, přesnosti a escapování. |
| 8.3 Web | Dvě obrazovky, filtry, souhrny, odkaz na doklad, stažení souboru. | Projdou TypeScript, build, dosavadní i nové prohlížečové testy na desktopu a mobilu. |
| 8.4 Živé ověření | Podrobný návod s hodnotami všech polí a kontrola v UCTO_DEV. | Uživatel potvrdí sestavy nad skutečnými doklady, export a izolaci jednotek. |

Části 8.1–8.3 jsou dodané; nejbližším krokem je živé ověření 8.4 podle návodu webu.
Změny schématu do playground se provedou pouze tehdy, budou-li potřeba,
po aktuální záloze a ověření obnovy stávajícím postupem. Živé doklady ani
uzavřené období z předchozí zkoušky se nebudou měnit nebo znovu otevírat.

Skripty:

- `tools/ucto/13-sestavy-priprava.ps1`: dostupný; příprava a kontrola testovací DB,
  jediný cíl Test. Část 8.1 novou migraci nepotřebuje.
- `tools/ucto/14-sestavy-test.ps1`: dostupný; jednotkové testy, skutečné DB scénáře,
  dosavadní regrese a kontrola, že nové povinné testy opravdu proběhly.
- `tools/ucto/15-sestavy-web-test.ps1`: dostupný; typy, build a všechny
  webové scénáře části 8.3 včetně dosavadních regresí, desktop a mobil.

## 8. Povinné automatické scénáře

1. Do výsledku vstupují jen zaúčtované doklady správné jednotky a období;
   návrh se stejnými částkami nic nezmění. Jiná jednotka obsahuje záměrně
   podobná data, aby izolaci neprokázal jen prázdný dataset.
2. Pohyby před Od vytvoří počáteční zůstatek, pohyby na hranicích se započítají,
   pohyby po Do a z jiných období ne. Celé období má správně nulový základ
   před prvním evidovaným pohybem. Prázdné období i účet vracejí nuly.
3. Neaktivní účet a účet s následně vypnutou účtovatelností neztratí historické
   pohyby. Nadřazené účty nezpůsobí dvojí započtení. Nulový konečný stav
   nezakryje účet s obraty.
4. Stejné datum, více dokladů a více řádků na jednom účtu má stabilní pořadí;
   zůstatek pokračuje přes hranici stránky a součty se stránkováním nemění.
5. Maximální částky, jejich součet přesahující limit řádku a záporný zůstatek
   projdou bez zaokrouhlení či float. Předvaha má stejné součty jako nezávisle
   vypočtená očekávaná fixture a její účty odpovídají hlavní knize.
6. Kontrola `balanced` má test i pro nerovné vstupní součty na úrovni čisté
   výpočetní funkce; skutečná DB fixture neobchází ochranu vyrovnaných dokladů.
7. Administrátor, účetní, čtenář a auditor s potřebnými čtecími právy získají
   sestavy; anonymní, cizí jednotka, cizí ID a neúplná oprávnění se odmítnou.
   JSON i CSV mají shodnou ochranu.
8. Souběžné zaúčtování mezi dílčími čteními nevyrobí nesouhlasný počet,
   součty a řádky v jedné odpovědi. Test použije skutečnou synchronizaci
   dvou spojení; nebude odhadovat souběh pouhým čekáním pevný počet sekund.
9. CSV má všechny stránky, správné součty, české znaky, uvozovky, středníky,
   nové řádky, bezpečný text podobný vzorci a jednoznačný prázdný výsledek.
   Ověří se limit 50 000 i odmítnutí dalšího řádku bez částečného souboru.
10. Web ověří filtry, souhrny, stránkování, detail dokladu, obsah staženého CSV,
    chybu API, vypršení přihlášení, změnu jednotky i opožděnou odpověď.

Nové testy poběží výhradně v `mikoucto_test`. Pro automatické webové testy
se použije současný testovací server s náhradním API; tento běh nenahrazuje
skutečný DB test ani ruční ověření přihlášení.

## 9. Živá přejímka a návod pro laiky

Po implementaci vznikne samostatný návod se stejnou podrobností jako
[kapitola 5 živého průchodu](vyvoj_nasazeni.md#5-proveďte-živý-průchod-z-prohlížeče):
umístění tlačítek, všechna pole, konkrétní hodnoty, očekávané součty a záznam
výsledku. Návod nebude vyžadovat psaní SQL ani ručních HTTP požadavků.

Pro čistý scénář se dvěma doklady z předchozího návodu očekáváme:

| Účet | Počáteční MD / DAL | Obrat MD | Obrat DAL | Konečné MD | Konečné DAL |
| --- | --- | --- | --- | --- | --- |
| 211 Pokladna | 0,00 / 0,00 | 150,00 | 0,00 | 150,00 | 0,00 |
| 221 Bankovní účet | 0,00 / 0,00 | 0,00 | 150,00 | 0,00 | 150,00 |
| Celkem | 0,00 / 0,00 | 150,00 | 150,00 | 150,00 | 150,00 |

Platí při výběru celého testovacího období a bez dalších zaúčtovaných dokladů.
Skutečné číslo období a použité datum se převezmou z uživatelova průchodu,
nebudou se domýšlet. V hlavní knize 211 budou pohyby MD 100 a 50 s průběžnými
zůstatky 100 a 150 (při opačném pořadí dokladů 50 a 150). Pro účet 221 se
zůstatky zobrazí na straně DAL. Export musí odpovídat zobrazeným souhrnům.

Další automatická fixture ověří užší rozsah: při pohybu 100 před Od a pohybu
50 uvnitř výběru má účet 211 počáteční MD 100, obrat MD 50 a konečné MD 150.
Do živého uzavřeného období se kvůli této zkoušce žádné zápisy nepřidávají.
Přepnutí na DEV nesmí zobrazit data UCTO_DEV. Výsledek této přejímky se
zaznamená až po skutečném provedení, odděleně od automatických testů.
