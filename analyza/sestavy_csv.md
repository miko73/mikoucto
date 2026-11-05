# Část 8.2 – export hlavní knihy a předvahy do CSV

Stav: **implementováno a automaticky ověřeno**, včetně úplné DB regrese.

Implementace dodává exporty API. Tlačítka pro stažení a obrazovky sestav
jsou doplněné v [části 8.3](sestavy_web.md). Výpočty a omezení počátečních stavů jsou stejné jako
u [JSON sestav 8.1](sestavy_api.md); nová databázová migrace není potřeba.

## Co export obsahuje

Soubor obsahuje celý výběr, nikoli jen zobrazenou stránku. Zahrnuje pouze
zaúčtované doklady vybrané jednotky a období. Exportovat lze i uzavřené
období. Neaktivní účty neztrácejí historické pohyby.

Začátek obsahuje název sestavy, kód/název/ID jednotky, název a ID období,
data Od a Do, čas sestavení v UTC, CZK a vysvětlení základu výpočtu.
Následuje prázdný řádek, hlavička a data. Další prázdný řádek odděluje
souhrn. Souhrny platí pro celý výběr. Prázdný výsledek má hlavičku,
nulové součty a údaj „Počet datových řádků;0“.

| Sestava | Datové sloupce |
| --- | --- |
| Hlavní kniha | Datum účtování, číslo dokladu, účet, popis dokladu, popis řádku, MD, DAL, průběžný zůstatek MD − DAL, pořadí řádku |
| Předvaha | Kód účtu, název účtu, počáteční MD/DAL, obrat MD/DAL, konečné MD/DAL |

Kladný průběžný zůstatek hlavní knihy znamená stranu MD, záporný DAL.
Předvaha má navíc v souhrnu kontrolní rozdíly a výsledek vyrovnanosti.
Počáteční zůstatky se počítají z pohybů uvnitř zvoleného období před datem Od;
převody z jiných období se automaticky nepřidávají.

Ukázkové soubory s vymyšlenými daty:

- [Hlavní kniha – ukázka](priklady/hlavni-kniha.csv).
- [Obratová předvaha – ukázka](priklady/obratova-predvaha.csv).

## Formát a přesnost

Soubor je UTF-8 s BOM, oddělovačem je středník a koncem řádku CRLF.
Částky mají dvě desetinná místa a desetinnou čárku, bez mezer a bez Kč
uvnitř čísla. Výpočty i zápis pracují s přesnými desetinnými řetězci;
nevstupuje do nich float. Kód `001` zůstává v samotném CSV `001`.

Uvozovky, středníky a nové řádky v popisech se zapisují standardním CSV
escapováním. Text začínající jako vzorec se označí úvodním apostrofem,
včetně vzorce za úvodními mezerami; při importu tak nemá být vyhodnocen
jako příkaz tabulkového procesoru. Záporné peněžní částky zůstávají
číselnými hodnotami, apostrof se k nim nepřidává. Export proto může
u textů zobrazit ochranný apostrof, který nebyl v původním popisu.
Technické podklady: [PHP fputcsv](https://www.php.net/fputcsv)
a [OWASP CSV Injection](https://owasp.org/www-community/attacks/CSV_Injection).

## Jak ukázku otevřít v Excelu

1. Stáhněte si ukázkový CSV soubor výše a otevřete prázdný sešit Excelu.
2. Použijte **Data → Z textu/CSV** a vyberte stažený soubor.
3. Zvolte kódování **UTF-8** a oddělovač **středník**. Názvy voleb se podle
   verze Excelu mohou mírně lišit.
4. Pokud chcete pracovat jen s tabulkou pohybů, zvolte **Transformovat data**
   a odstraňte pouze informační řádky před datovou hlavičkou; hlavičku ponechte. Souhrn na
   konci souboru je samostatná část, nezapočítávejte jej znovu jako pohyb.
5. Sloupec **Účet** nebo **Kód účtu** nastavte jako **Text**, aby se zachovaly
   úvodní nuly. Případný automatický krok změny typu upravte ještě před
   načtením, jinak už mohl číslice převést.
6. Částky můžete načíst jako desetinná čísla s českým místním nastavením.
   Pro zachování všech číslic mimo rozsah přesnosti Excelu je importujte
   jako **Text**. Přesné kontrolní součty nadále poskytuje aplikace a CSV.

Excel automaticky převádí číselné sloupce a má limit 15 platných číslic.
Import textových kódů a velmi velkých částek popisuje také
[Microsoft – zachování úvodních nul a velkých čísel](https://support.microsoft.com/en-gb/excel/keeping-leading-zeros-and-large-numbers).

## API a oprávnění

Základ: `/api/v1/accounting-units/{unit}/reports`.

| GET | Povinné parametry | Volitelné parametry |
| --- | --- | --- |
| `/general-ledger.csv` | `periodId`, `accountId` | `from`, `to` |
| `/trial-balance.csv` | `periodId` | `from`, `to`, `includeZero` |

Pravidla dat, UUID a `includeZero` odpovídají JSON API. Parametry `page`
a `itemsPerPage` se u CSV odmítají chybou 400, aby nemohly vyvolat dojem
exportu jediné stránky. Je potřeba Bearer token stávajícího Entra přihlášení
a čtecí práva k jednotce, dokladům, účtům i obdobím; kontrola je společná
s JSON providerem. Cizí jednotka vrací 403 a cizí/neexistující období nebo
účet 404. Chybové odpovědi zůstávají JSON `application/problem+json`,
nikoli soubor s chybou uvnitř CSV.

Úspěch vrací `text/csv; charset=UTF-8`, `Content-Disposition: attachment`
s bezpečným názvem souboru, délku souboru, `X-Content-Type-Options: nosniff`
a `Cache-Control: private, no-store`. Název souboru používá typ sestavy,
UUID jednotky a obě data; neobsahuje uživatelský text.

## Limit a konzistentní výsledek

Maximum je **50 000 datových řádků**, metadata a souhrn se do limitu nepočítají.
Při překročení přijde 422 s výzvou zúžit rozsah ještě před zahájením
stahování. Export nikdy potichu neořízne výsledek.

Identita a čtení se nastaví v samostatné transakci **REPEATABLE READ READ ONLY**
před prvním datovým dotazem. Počet a součty používají stejný SQL základ jako
JSON. Po kontrole limitu se řádky čtou kurzorem po nejvýše 1 000 a zapisují
do dočasného streamu; od 2 MiB se jeho obsah odkládá na disk. Neagregují se
všechny řádky do jednoho PHP pole ani se neskládají stránky JSON přes HTTP.

Kurzorem se čte stejný snapshot i při mezitím provedeném zaúčtování.
Při chybě se kurzor zavře a transakce vrátí zpět. Celý soubor se připraví
před odesláním odpovědi a databázová transakce skončí před stahováním;
pomalé stahování ji tedy nedrží otevřenou. Dočasný stream se po odeslání
nebo chybě uzavře. Následující běžné zápisy nadále používají dosavadní
režim READ COMMITTED. Princip kurzoru bez HOLD popisuje
[PostgreSQL 16 – DECLARE](https://www.postgresql.org/docs/16/sql-declare.html).

## Automatické ověření

Při běžícím databázovém tunelu spusťte z kořene projektu:

```powershell
.\tools\ucto\14-sestavy-test.ps1
```

Skript zahrnuje jednotkové testy, celou DB regresi, povinné ReportsTest
i ReportsCsvTest, kontrolu migrací a export OpenAPI. Úspěšný závěr začíná
`Cast 8.1/8.2 OK`. Pracuje výhradně v `mikoucto_test`, nemění playground.

Nové scénáře porovnávají CSV s JSON, zkoušejí všechny čtecí role,
odmítnutí cizích ID, prázdný výsledek, uzavřené období, velké i záporné
částky, textové kódy, escapování, čtení přes více kurzorových dávek,
souběžné zaúčtování, chybu při zápisu a uvolnění transakce/kurzoru.
Jednotkový test zapisuje a znovu čte všech 50 000 řádků a ověřuje odmítnutí
počtu 50 001. HTTP test stejnou limitní větev ověřuje nad skutečnými daty
s testovacím limitem dva řádky, aby nemusel zakládat desítky tisíc účtů.
Tento limit se nedá změnit parametrem API.

## Závěrečné ověření 4. 11. 2026

| Kontrola | Výsledek |
| --- | --- |
| Jednotkové testy backendu | **114 testů / 342 kontrol**, úspěch. |
| Úplná DB regrese včetně 8.1 i CSV | **75 testů / 917 kontrol**, úspěch, **33 minut 11 sekund**. |
| Povinné nové sady | ReportsTest: všech 6 testů; ReportsCsvTest: všechny 4 testy; žádné přeskočení. |
| OpenAPI a generované typy | 16 účetních cest, dva CSV endpointy, popsané filtry, souborová odpověď a chyba 422. |
| Web | TypeScript, produkční build a **18 jednotkových testů** prošly. |
| DB migrace | Up-to-date na `App\Migrations\Version20261103030100`; bez nové migrace. |

První cílený běh zachytil dvě chyby testovací obsluhy: BrowserKit už
spotřeboval StreamedResponse a test jej zkoušel odeslat podruhé; v přímém
testu snapshotu se řádky sbíraly do lokální kopie pole v uzávěru. Testy
nyní čtou zachycenou interní odpověď BrowserKitu a sdílený sběrač řádků.
Závěrečný společný běh proběhl po těchto opravách a zahrnuje i dosavadní
účetní, bezpečnostní, partnerské a souběžné scénáře.

Veškeré DB testy pracovaly v `mikoucto_test`. Ukázkové CSV soubory obsahují
vymyšlená data a vznikly stejným zapisovačem jako API. Živé stažení přes
tlačítka a otevření konkrétního souboru v Excelu se tímto automatickým
během nepotvrzuje; ruční ověření 8.4 popisuje [návod webu](sestavy_web.md).
