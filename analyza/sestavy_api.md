# Část 8.1 – výpočty a JSON API účetních sestav

Implementace navazuje na [plán etapy 8](etapa8_plan.md). Dodává dva čtecí
endpointy. [CSV exporty 8.2](sestavy_csv.md) jsou doplněné samostatně;
webové obrazovky 8.3 jsou popsané v [návodu pro obsluhu](sestavy_web.md).
Nová databázová migrace není potřeba.

## Výpočty

Sestavy zahrnují pouze zaúčtované řádky vybrané jednotky a jednoho období.
Používají datum účtování, nikoli čas zaúčtování. Otevřené i uzavřené období
lze číst. Historické pohyby neaktivních nebo nyní neúčtovatelných účtů
zůstávají zahrnuté. Názvy a kódy účtů odpovídají současné osnově.

Počáteční zůstatek výběru je součet MD − DAL od začátku období před datem Od.
Obraty jsou součty mezi Od a Do včetně. Konečný zůstatek je počáteční
zůstatek + obrat MD − obrat DAL. Převod stavů z jiných období se neprovádí.
Pole `basis` proto vždy obsahuje `posted_movements_within_period_no_carry_forward`.
Jde o přehled evidovaných pohybů, nikoli automatickou roční závěrku.

Všechny částky jsou přesné desetinné řetězce se dvěma místy, nikoli JSON
čísla. Součty mohou překročit limit částky jednoho řádku. Podepsaný zůstatek
hlavní knihy má kladnou stranu MD a zápornou DAL. Předvaha rozděluje zůstatky
do dvou nezáporných sloupců MD a DAL.

## Endpointy a filtry

Základ: `/api/v1/accounting-units/{unit}/reports`.

| GET | Povinné parametry | Volitelné parametry |
| --- | --- | --- |
| `/general-ledger` | `periodId`, `accountId` | `from`, `to`, `page`, `itemsPerPage` |
| `/trial-balance` | `periodId` | `from`, `to`, `page`, `itemsPerPage`, `includeZero` |

Vynechaná data se doplní z hranic období; zadaná data musí být uvnitř něj.
Výchozí stránka je 1, velikost 20; maximum stránky 100000, velikosti 100.
`includeZero` přijímá přesně `true` nebo `false`, výchozí je false.
Neznámé nebo nesprávně typované parametry se odmítají, stejně jako obrácená
nebo neplatná data. Volání vyžaduje Bearer token stávajícího Entra přihlášení
a oprávnění `core.units.read`, `core.ucto.documents.read`, `core.ucto.accounts.read`
a `core.ucto.periods.read` v dané jednotce. Používá skutečnou aplikační DB roli
a RLS. Neexistující nebo cizí ID období/účtu vrací 404 po ověření přístupu
k jednotce; chybějící oprávnění 403, nepřihlášený požadavek 401, vadné filtry 400.

## Tvar odpovědi

Obě odpovědi mají objektový obal, na rozdíl od ploché kolekce Deníku:

| Pole | Obsah |
| --- | --- |
| `accountingUnitId` | Vybraná jednotka |
| `filters` | ID a název období, jeho hranice, skutečná data `from`/`to`, `includeZero`, ID/kód/název účtu (u předvahy null) |
| `generatedAt` | UTC čas sestavení SQL odpovědi |
| `currency` | `CZK` |
| `basis` | Uvedené omezení na evidované pohyby jednoho období |
| `totals` | Součty celého výběru, nezávislé na stránce |
| `items` | Datové řádky aktuální stránky |
| `total` | Celkový počet datových řádků před stránkováním |
| `page`, `itemsPerPage` | Použité stránkování |

Hlavní kniha má v `totals` pole `openingBalance`, `turnoverMd`, `turnoverDal`
a `closingBalance`. Řádek obsahuje `id`, `accountId`, `documentId`, `number`,
`postingDate`, `lineNo`, `documentText`, `text`, `md`, `dal`, `runningBalance`.
Řadí se vzestupně podle data, ID dokladu a pořadí řádku. Průběžný zůstatek
zahrnuje i předcházející stránky. Účet bez pohybů má prázdné řádky a nulové
součty; výběr po posledním pohybu může mít nenulový počáteční a konečný stav.

Předvaha má v řádku `accountId`, `accountCode`, `accountName`, `openingMd`,
`openingDal`, `turnoverMd`, `turnoverDal`, `closingMd`, `closingDal`.
Součty obsahují šest stejných peněžních polí a navíc `openingDifference`,
`turnoverDifference`, `closingDifference` (= MD − DAL) a `balanced`.
Nadřazený účet automaticky nesčítá potomky. Výchozí výběr zachovává účty
s obraty i při nulovém konečném stavu. `includeZero=true` přidá ostatní účty
osnovy; součty tím nezmění. Řazení je podle textového kódu v kolaci C a ID.

Metadata, řádky, počet a součty pocházejí z jediného SQL snapshotu.
Úspěšná odpověď má `Cache-Control: private, no-store`. Každý další požadavek
vytvoří nový výsledek: mezitím zaúčtovaný doklad může změnit i starší datum.
Prázdná stránka za koncem seznamu nesmí vymazat počet ani součty.
Přesné schéma obou odpovědí je v generovaném `shared/openapi/accounting.json`.

## Automatické ověření

Ponechte běžet databázový tunel. Backend ani web pro testovací sadu nejsou
potřeba. V PowerShellu z kořene projektu spusťte:

```powershell
.\tools\ucto\13-sestavy-priprava.ps1
.\tools\ucto\14-sestavy-test.ps1
```

Skript 13 připravuje pouze `mikoucto_test` stávajícími migracemi; cíl
Development nenabízí. Skript 14 spustí jednotkové a úplné DB regresní testy,
ověří skutečný běh povinné nové třídy `ReportsTest` i předchozích účetních sad,
aktuálnost migrací a export OpenAPI. Po doplnění CSV úspěšný závěr začíná
`Cast 8.1/8.2 OK`.
Při chybě se ponechá JUnit report a nenulový návratový kód. Playground se nemění.

API doplňují webové obrazovky 8.3. [Návod pro laiky](sestavy_web.md)
obsahuje všechna pole, očekávané součty i stažení CSV. Automatická sada
ověřuje výpočty a oprávnění bez ručního zadávání tokenů.

## Izolace testovací cache

Při prvním úplném běhu se ve stávajícím HTTP testu souběžného zápisu
objevil TypeError v `PropertyInfoCacheExtractor::isWritable` (očekávaný bool,
z cache vrácené pole). Selhání nastalo před účetní operací. Testovací
konfigurace proto používá `cache.adapter.array` pro fond `cache.property_info`:
každý proces drží vlastní hodnoty tohoto fondu a nezapisuje je souběžně
do PHP souborů. Ostatní metadatové cache zůstávají beze změny.
Databázové testy stále používají skutečné DB role, transakce,
zámky a účetní pravidla. Vývojová konfigurace cache se nemění.
Volbu adaptéru podporuje [konfigurace Symfony](https://symfony.com/doc/current/reference/configuration/framework.html#cache).

## Záznam ověření 4. 11. 2026

Následuje historický záznam samotné části 8.1. Po doplnění 8.2 prošel
nový **společný úplný běh 75 DB testů / 917 kontrol**, včetně všech zde
uvedených oprav. Aktuální jednotková sada má 114 testů / 342 kontrol
a OpenAPI 16 účetních cest; viz [závěrečné ověření CSV](sestavy_csv.md#závěrečné-ověření-4-11-2026).

| Ověření | Výsledek |
| --- | --- |
| Celá jednotková sada backendu s konečným nastavením cache | **109 testů, 304 kontrol**, úspěch. |
| Původní úplný DB regresní běh | **71 testů, 767 kontrol, dvě selhání**, 29 minut 6 sekund. |
| Celé ReportsTest a DraftConcurrencyTest po opravách | **10 testů, 170 kontrol**, úspěch, 8 minut 17 sekund. |
| Samostatná konečná kontrola částečných oprávnění | **1 test, 4 kontroly**, úspěch; odděleně chybějící právo k účtům, obdobím i jednotce. |
| OpenAPI | 14 účetních cest včetně obou sestav, povinné parametry a přesné výstupní typy. |
| Webové typy a sestavení | Generování OpenAPI typů, TypeScript a produkční build prošly. |
| Jednotkové testy webu | **18 testů**, úspěch. |
| Migrace mikoucto_test | Up-to-date, `App\Migrations\Version20261103030100`; žádná nová migrace 8.1. |

Úplný DB běh zachytil chybu metadatové cache popsanou výše a scénář
částečných oprávnění. V něm chybělo právo `core.units.read`, takže RLS
skrylo období a API odpovědělo 404. Provider nyní výslovně ověřuje také
toto právo a při jeho absenci vrací 403. Oba původně selhané scénáře byly
podle názvu i třídy dohledány v úspěšném opakovaném JUnit reportu;
všech šest testů sestav i všechny čtyři testy souběhu skutečně proběhly.

Jde o úplný původní běh a následné cílené ověření oprav, **nikoli o nový
společný zelený běh všech 71 testů**. Pro úplné opakování použijte skript 14.
Místní diagnostika zůstává v ignorovaném `backend/var`: původní
`accounting-tests-bf8ee6bf54cb44758bf4af45f25c455a.xml` a úspěšný
`reports-final.xml`; poslední samostatná kontrola je v
`reports-permissions-final.xml`. Počty kontrol mezi běhy se nesčítají,
část se překrývá.

Automatické DB ověření proběhlo výhradně v `mikoucto_test`. Playground,
živé zaúčtované doklady a uzavřené období v UCTO_DEV nebyly měněny.
Nové webové obrazovky ani živý průchod sestavami tento záznam nepotvrzuje;
patří do následujících částí etapy.
