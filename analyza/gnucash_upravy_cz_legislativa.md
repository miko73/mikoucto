# Návrh úprav GnuCash pro českou legislativu

Dokument navazuje na [popis datového modelu](gnucash_datovy_model.md). Uvádí, co GnuCash 5.16/5.17 nesplňuje pro vedení účetnictví podle českých předpisů, a navrhuje nezbytné úpravy s co nejmenším zásahem do zdrojového kódu.

Datum podkladů: 29. října 2026. Sazby, lhůty a odkazy na paragrafy odpovídají stavu známému k tomuto datu. Před nasazením je ověřte v aktuálním znění předpisů a s účetním nebo daňovým poradcem. Sledujte také stav připravovaného nového zákona o účetnictví.

## 1. Výchozí předpoklady

| Předpoklad | Zvolená varianta | Dopad, pokud neplatí |
| --- | --- | --- |
| Typ účetní jednotky | Podnikatel (právnická osoba nebo OSVČ účtující podvojně) | Pro vybrané účetní jednotky (obce, městské části, příspěvkové organizace) viz kapitola 6 |
| Rozsah účetnictví | Podvojné, kategorie mikro nebo malá účetní jednotka (ZoÚ § 1b), zkrácený rozsah závěrky | Velká a střední jednotka potřebuje plný rozsah výkazů, přílohu a případně audit |
| DPH | Plátce, měsíční nebo čtvrtletní období | Neplátce nepotřebuje kapitolu 4.7 |
| Uživatelé | Jedna účetní, jedna kniha | Víc uživatelů vyžaduje PostgreSQL a evidenci, kdo záznam pořídil |
| Úložiště | SQLite (`.gnucash` přes `sqlite3://`) | XML neumožňuje navržené SQL nástroje |

Daňová evidence OSVČ (jednoduché vedení příjmů a výdajů) není předmětem dokumentu. Pro ni stačí upravená česká šablona osobních účtů.

## 2. Zvolený přístup

Úpravy jsou rozdělené do čtyř úrovní. Doporučuje se postupovat od úrovně A a zasahovat do C++ kódu (úroveň D) jen tam, kde jiné řešení nestačí.

| Úroveň | Co obsahuje | Náročnost | Údržba při aktualizaci GnuCash |
| --- | --- | --- | --- |
| **A – konfigurace** | Účtová osnova, daňové tabulky, číselné řady, nastavení knihy, konvence vyplňování polí | Nízká | Žádná |
| **B – rozšíření dat** | Nové klíče v `slots` s prefixem `cz/` a pomocné tabulky `cz_*` v SQLite | Nízká | Pomocné tabulky GnuCash ignoruje; ověřit po každé aktualizaci |
| **C – externí nástroje** | Python skripty nad SQLite: výkazy, DPH, kurzy ČNB, audit, uzávěrka, archivace | Střední | Závisí na verzích tabulek v `versions` |
| **D – úpravy zdrojů** | Nová pole v dialozích, český daňový doklad, kontrola uzamčených období v jádru | Vysoká | Vlastní větev a sestavení každé verze |

Python bindings GnuCash nejsou ve Windows sestavení k dispozici (`WITH_PYTHON=OFF`). Externí nástroje proto pracují přímo s SQLite. Platí tato pravidla:

- Čtení je možné kdykoli; databázi otevírejte v režimu `mode=ro`.
- Zápis (kurzy, sloty, uzávěrkové zápisy) jen při **zavřené** knize. Před zápisem zkontrolujte prázdnou tabulku `gnclock` a vytvořte zálohu.
- Nové GUID generujte jako `uuid.uuid4().hex`. Částky zapisujte jako celá čísla `num/denom`.
- Před spuštěním ověřte verze tabulek v `versions` proti hodnotám v datovém modelu.

## 3. Přehled nesouladů

Priorita: **P1** = bez úpravy nelze účtovat v souladu se zákonem, **P2** = nutné pro výkazy a přiznání, **P3** = usnadnění nebo vyšší průkaznost.

| # | Požadavek | Předpis | Stav v GnuCash | Návrh | Úroveň | Priorita |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | Účtový rozvrh podle směrné účtové osnovy | ZoÚ § 14, vyhl. 500/2002 Sb. | České šablony jsou jen pro osobní finance | Nová šablona osnovy (4.1) | A | P1 |
| 2 | Náležitosti účetního dokladu | ZoÚ § 11 | Chybí okamžik uskutečnění, odpovědné osoby, druh dokladu | Konvence polí a sloty `cz/doklad/*` (4.2) | A, B | P1 |
| 3 | Číselné řady dokladů | ZoÚ § 11, 33a | Řady jen pro faktury; ruční transakce mají volné `num` | Řady v `counter_formats`, kontrola úplnosti skriptem (4.2) | A, C | P1 |
| 4 | Účetní knihy: deník, hlavní kniha, analytická evidence, saldokonto | ZoÚ § 13 | Hlavní kniha a deník existují jako sestavy; chybí české členění | SQL sestavy (4.3) | C | P1 |
| 5 | Oprava záznamu se zachováním původního obsahu, kdo a kdy | ZoÚ § 35 | Úpravy a mazání přepisují data bez stopy | Auditní log v triggerech, zákaz změn v uzavřeném období (4.4) | B, C | P1 |
| 6 | Účetní období, uzavírání a otevírání účetních knih | ZoÚ § 3, 17 | „Close Book“ převede výsledek na jeden účet; chybí 701/702/710 | Skript uzávěrky a otevření (4.5) | C | P1 |
| 7 | Účetní závěrka: rozvaha, výkaz zisku a ztráty, příloha | ZoÚ § 18, vyhl. 500/2002 Sb. přílohy 1–2 | Jen sestavy podle amerických typů účtů | Mapování účtů na řádky výkazů a generátor (4.6) | C | P2 |
| 8 | Sazby DPH, daňový doklad, DUZP, evidence pro DPH | ZDPH § 29, 47, 100 | Daňové tabulky ano; DUZP, DIČ, evidenční číslo partnera chybí | Daňové tabulky a konvence polí (4.7) | A, B | P1 |
| 9 | Přiznání k DPH, kontrolní hlášení, souhrnné hlášení | ZDPH § 101, 101c, 102 | Neexistuje | Generátor XML pro EPO (4.7) | C | P2 |
| 10 | Režim přenesení daňové povinnosti, plnění z EU | ZDPH § 92a, 24, 25 | Nelze přímo; daň se musí přiznat i odečíst | Daňová tabulka se dvěma řádky nebo ruční zápis (4.7) | A | P2 |
| 11 | IČO, DIČ, spolehlivost plátce, zveřejněné účty | ZDPH § 106a, 109 | Pole pro IČO a DIČ partnera chybí | Sloty `cz/ico`, `cz/dic`, kontrola ARES a ADIS (4.8) | B, C | P2 |
| 12 | Přepočet cizí měny kurzem ČNB | ZoÚ § 24, ZDPH § 4 odst. 5 | Kurzy ručně nebo přes Finance::Quote bez ČNB | Import denních kurzů ČNB (4.9) | C | P2 |
| 13 | Archivace a ochrana účetních záznamů | ZoÚ § 31, 32; ZDPH § 35 | Jen soubor knihy | Roční archivní export s kontrolním součtem (4.10) | C | P2 |
| 14 | Český daňový doklad, QR platba, ISDOC | ZDPH § 29; praxe | Šablona faktury je obecná | Vlastní tisk faktur (4.11) | C, D | P3 |
| 15 | Párování bankovních výpisů podle variabilního symbolu | praxe | Import CSV/OFX/MT940; párování jen podle podobnosti | Převodník formátů GPC/camt.053 a párování VS (4.11) | C | P3 |
| 16 | Dlouhodobý majetek a odpisy, mzdy, sklad | ZoÚ § 28; ZDP § 26–33 | Neexistuje | Vedení mimo GnuCash, souhrnné zápisy (5) | – | P3 |

## 4. Navrhované úpravy

### 4.1 Účtová osnova podle vyhlášky 500/2002 Sb.

Vytvořte šablonu `acctchrt_podnikatel.gnucash-xea` a uložte ji do `gnucash/data/accounts/cs/` a pro okamžité použití do `GnuCashPortable/App/Gnucash/share/gnucash/accounts/cs/`. Formát odpovídá stávajícím šablonám (XML s prvky `gnc-act:title`, `gnc-act:short-description`, `gnc:account`). Šablona se pak nabídne v průvodci novou knihou.

Pravidla struktury:

1. Úroveň 1: **účtová třída** 0–7 jako `placeholder`, `code` = číslo třídy, např. `0`.
2. Úroveň 2: **účtová skupina** jako `placeholder`, `code` = `01`, `02`…
3. Úroveň 3: **syntetický účet**, `code` = trojmístné číslo, např. `221`. Pokud má analytiky, je `placeholder`.
4. Úroveň 4: **analytický účet**, `code` = `221.100`, `343.121` apod.
5. `name` obsahuje jen název účtu; číslo je pouze v `code`. V GnuCash zapněte zobrazení sloupce „Kód účtu“ a řazení podle kódu.
6. Účty kurzových rozdílů, zaokrouhlení a počátečního stavu nastavte explicitně, aby GnuCash nevytvářel vlastní anglické účty `Orphan-CZK` a `Imbalance-CZK`.

Mapování na typy GnuCash:

| Účty | Obsah | Typ GnuCash | Poznámka |
| --- | --- | --- | --- |
| 01x–06x | Dlouhodobý majetek | `ASSET` | |
| 07x–09x | Oprávky, opravné položky k DM | `ASSET` | Zůstatek Dal se zobrazí záporně; ve výkazu se vykazuje jako korekce |
| 1xx | Zásoby | `ASSET` | |
| 211 | Pokladna | `CASH` | Analytika pro každou pokladnu a měnu |
| 213 | Ceniny | `ASSET` | |
| 221 | Bankovní účty | `BANK` | Analytika pro každý účet; cizoměnový účet má komoditu dané měny |
| 231, 232 | Krátkodobé úvěry | `LIABILITY` | |
| 25x | Krátkodobý finanční majetek | `ASSET` nebo `STOCK` | |
| 261 | Peníze na cestě | `ASSET` | |
| 311, 315 | Pohledávky z obchodních vztahů | `RECEIVABLE` | Samostatná analytika pro každou měnu, např. `311.100` CZK a `311.200` EUR. GnuCash vyžaduje saldokontní účet v měně faktury |
| 314 | Poskytnuté zálohy | `ASSET` | |
| 321, 325 | Závazky z obchodních vztahů | `PAYABLE` | Analytika pro každou měnu |
| 324 | Přijaté zálohy | `LIABILITY` | |
| 331, 333, 336 | Zaměstnanci, sociální a zdravotní pojištění | `LIABILITY` | |
| 341, 342 | Daň z příjmů, ostatní přímé daně | `LIABILITY` | |
| 343 | DPH | `LIABILITY` | Analytiky v 4.7; zůstatek může být na obou stranách |
| 35x | Pohledávky ke společníkům | `ASSET` | |
| 36x | Závazky ke společníkům | `LIABILITY` | |
| 381, 385 | Náklady a příjmy příštích období | `ASSET` | |
| 383, 384 | Výdaje příštích období, výnosy příštích období | `LIABILITY` | |
| 388, 389 | Dohadné účty | `ASSET` / `LIABILITY` | |
| 395 | Vnitřní zúčtování | `ASSET` | |
| 41x–43x | Základní kapitál, fondy, výsledek hospodaření | `EQUITY` | 431 = výsledek hospodaření ve schvalovacím řízení |
| 45x | Rezervy | `LIABILITY` | |
| 46x, 47x | Dlouhodobé úvěry a závazky | `LIABILITY` | |
| 48x | Odložená daň | `LIABILITY` | |
| 5xx | Náklady | `EXPENSE` | Druhové členění podle osnovy |
| 6xx | Výnosy | `INCOME` | |
| 701, 702 | Počáteční a konečný účet rozvažný | `EQUITY` | Jen pro uzávěrkové zápisy (4.5) |
| 710 | Účet zisků a ztrát | `EQUITY` | Jen pro uzávěrkové zápisy |
| 75x–79x | Podrozvahové účty | `ASSET` pod samostatnou třídou `7P` | Protiúčet 799. Výkazy GnuCash je zahrnou; české výkazy podle 4.6 je vynechají |
| 8xx, 9xx | Vnitropodnikové účetnictví | Volitelně | Jen pokud jej jednotka vede |

Kontrola: skript po vytvoření knihy ověří, že každý účet úrovně 3 a 4 má `code` ve tvaru `^\d{3}(\.\d{1,6})?$` a že typ odpovídá tabulce.

### 4.2 Účetní doklady a číselné řady

ZoÚ § 11 vyžaduje u každého dokladu: označení, obsah a účastníky, částku nebo cenu a množství, okamžik vyhotovení, okamžik uskutečnění účetního případu (pokud se liší) a podpisové záznamy osoby odpovědné za účetní případ a za jeho zaúčtování.

Mapování na stávající pole:

| Náležitost | Faktury (obchodní modul) | Ostatní transakce | Chybí |
| --- | --- | --- | --- |
| Označení dokladu | `invoices.id` | `transactions.num` | Druh dokladu u ostatních transakcí |
| Obsah a účastníci | `entries.description`, vlastník | `transactions.description`, `splits.memo` | Partner u ostatních transakcí |
| Částka | `entries`, `splits.value` | `splits.value` | – |
| Okamžik vyhotovení | `invoices.date_opened` | `transactions.enter_date` (systémové) | Datum vyhotovení u ostatních |
| Okamžik uskutečnění | `invoices.date_posted` → `transactions.post_date` | `transactions.post_date` | – |
| Odpovědné osoby | – | – | Vždy |

**Konvence (úroveň A):**

- Druh dokladu je prefix čísla. Řady faktur nastavte v *Soubor → Vlastnosti → Čítače*. Příklad: `FV%04li` faktury vydané, `FP%04li` faktury přijaté, `VD%04li` výdajové doklady. Rok řadu nečíslujte uvnitř formátu, ale na začátku roku nastavte počáteční hodnotu čítače, např. `260001`.
- Pro ostatní doklady použijte prefixy `BV` (bankovní výpis), `PPD`/`PVD` (pokladní doklad příjmový/výdajový), `ID` (interní doklad). Číslo zapisujte do pole „Číslo“ transakce.
- U faktury přijaté vyplňte do `billing_id` (v UI „Číslo faktury dodavatele“) **evidenční číslo dokladu dodavatele**. Je povinné pro kontrolní hlášení.

**Rozšíření (úroveň B), sloty na transakci nebo faktuře:**

| Klíč | Typ | Význam |
| --- | --- | --- |
| `cz/doklad/druh` | string | `FV`, `FP`, `BV`, `PPD`, `PVD`, `ID`, `DV` (dobropis vydaný), `DP` (dobropis přijatý) |
| `cz/doklad/vyhotoveno` | gdate | Okamžik vyhotovení, pokud se liší od data zaúčtování |
| `cz/doklad/odpovedna_osoba` | string | Osoba odpovědná za účetní případ |
| `cz/doklad/zauctoval` | string | Osoba odpovědná za zaúčtování |
| `cz/doklad/priloha` | string | Cesta nebo URI naskenovaného originálu, pokud nestačí vestavěný `assoc_uri` |

GnuCash tyto sloty v UI nezobrazí, ale zachová je. Vyplňuje je externí nástroj (dávkově podle prefixu čísla) nebo úroveň D. Podpisový záznam lze nahradit evidencí ve slotu a interní směrnicí, která určí odpovědné osoby podle druhu dokladu.

**Kontrola úplnosti řad (úroveň C):** skript `kontrola_rad.py` pro každý prefix a rok vypíše chybějící a duplicitní čísla a transakce bez čísla dokladu.

### 4.3 Účetní knihy a sestavy

Sestavy čtou SQLite jen pro čtení a vytvářejí XLSX a PDF. Základ dotazů je v kapitole 9 datového modelu.

| Kniha | Obsah | Zdroj |
| --- | --- | --- |
| Účetní deník | Chronologicky: datum, číslo dokladu, text, účet MD, účet Dal, částka | `transactions` + `splits` |
| Hlavní kniha | Za každý syntetický účet počáteční stav, obraty MD a Dal za měsíc, konečný stav | Agregace podle prvních tří znaků `accounts.code` |
| Analytická evidence | Totéž podle úplného `code` | `accounts.code` |
| Obratová předvaha | Všechny účty k datu | |
| Saldokonto odběratelů a dodavatelů | Otevřené položky podle partnera, splatnosti a dní po splatnosti | `lots` (`is_closed = 0`) + `invoices` + slot `trans-date-due` |
| Pokladní kniha | Řádky účtů 211 s průběžným zůstatkem; kontrola záporného zůstatku | |
| Kniha podrozvahových účtů | Účty 75x–79x | |

Deník a hlavní kniha se podle ZoÚ musí doplňovat nejméně jednou měsíčně. Skript proto zároveň upozorní na měsíce bez zápisů.

### 4.4 Průkaznost a opravy (auditní stopa)

ZoÚ § 35 vyžaduje, aby u opravy šlo zjistit, kdo ji provedl, jaký byl původní obsah a kdy byla provedena. GnuCash data přepisuje a umožňuje transakce mazat.

**Organizační pravidla (úroveň A):**

- Zaúčtované doklady neupravovat ani nemazat. Opravu provádět opravným zápisem: *Transakce → Přidat opačnou transakci* (vznikne slot `reversed-by`) a nový správný zápis s vlastním interním dokladem.
- Storno faktury přes dobropis, nikoli „Zrušit zaúčtování“.
- Po uzávěrce měsíce nastavit v *Soubor → Vlastnosti → Účty* hodnotu „Day Threshold for Read-Only Transactions“. GnuCash pak starší transakce neumožní upravit v UI.

**Auditní log v SQLite (úroveň B):**

Do souboru knihy přidejte pomocné tabulky a triggery. GnuCash načítá jen své tabulky a ostatní ponechá beze změny. Před nasazením to ověřte testem: otevřít, upravit, uložit, zavřít a zkontrolovat, že tabulky a triggery zůstaly.

```sql
CREATE TABLE cz_audit_log (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    ts_utc     TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f', 'now')),
    os_user    TEXT,               -- doplňuje externí nástroj nebo úroveň D
    tbl        TEXT    NOT NULL,
    op         TEXT    NOT NULL,   -- INSERT / UPDATE / DELETE
    row_guid   TEXT    NOT NULL,
    old_row    TEXT,               -- JSON původního řádku
    new_row    TEXT,               -- JSON nového řádku
    prev_hash  TEXT,
    row_hash   TEXT                -- řetězení SHA-256 doplní skript
);

CREATE TABLE cz_obdobi (
    obdobi     TEXT PRIMARY KEY,   -- 'YYYY-MM'
    uzavreno   INTEGER NOT NULL DEFAULT 0,
    uzavrel    TEXT,
    uzavreno_ts TEXT
);

-- Příklad pro splits; obdobně pro transactions, invoices, entries a slots
CREATE TRIGGER cz_audit_splits_upd AFTER UPDATE ON splits
BEGIN
    INSERT INTO cz_audit_log (tbl, op, row_guid, old_row, new_row)
    VALUES ('splits', 'UPDATE', OLD.guid,
        json_object('tx_guid', OLD.tx_guid, 'account_guid', OLD.account_guid,
                    'memo', OLD.memo, 'value_num', OLD.value_num, 'value_denom', OLD.value_denom,
                    'quantity_num', OLD.quantity_num, 'quantity_denom', OLD.quantity_denom,
                    'reconcile_state', OLD.reconcile_state),
        json_object('tx_guid', NEW.tx_guid, 'account_guid', NEW.account_guid,
                    'memo', NEW.memo, 'value_num', NEW.value_num, 'value_denom', NEW.value_denom,
                    'quantity_num', NEW.quantity_num, 'quantity_denom', NEW.quantity_denom,
                    'reconcile_state', NEW.reconcile_state));
END;

-- Zákaz změn v uzavřeném období
CREATE TRIGGER cz_lock_tx_upd BEFORE UPDATE ON transactions
WHEN EXISTS (SELECT 1 FROM cz_obdobi
             WHERE uzavreno = 1 AND obdobi = substr(OLD.post_date, 1, 7))
BEGIN
    SELECT RAISE(ABORT, 'Období je uzavřeno');
END;
```

Omezení:

- GnuCash při uložení transakce zapisuje i nezměněné řádky. Log proto bude obsahovat i řádky bez věcné změny; skript je při zobrazení odfiltruje porovnáním `old_row` a `new_row`.
- `RAISE(ABORT)` vyvolá v GnuCash chybu ukládání. Jde o žádoucí zábranu, ale uživatel uvidí obecné hlášení.
- *Uložit jako* vytvoří nový soubor bez triggerů. Skript `instaluj_audit.py` proto triggery do knihy přidá znovu a před každým exportem zkontroluje jejich přítomnost.
- Kdo změnu provedl, SQLite nezjistí. Pro jednu účetní stačí směrnice. Pro víc uživatelů použijte PostgreSQL s přihlášením jednotlivých uživatelů a `current_user` v triggeru.
- Skript `audit_hash.py` po každé relaci doplní řetězení `row_hash = SHA-256(prev_hash || řádek)`. Případný zásah do logu se tak projeví porušením řetězce.

### 4.5 Účetní období, uzávěrka a otevření knih

Vestavěná funkce *Akce → Uzavřít knihu* převede zůstatky výnosů a nákladů na jeden účet vlastního kapitálu. Nevytváří zápisy na konečný a počáteční účet rozvažný, které česká praxe používá.

Skript `uzaverka.py <rok>` spuštěný nad zavřenou knihou:

1. Ověří předpoklady: období roku jsou uzavřena, nejsou nespárované řádky na 261 a 395, není nevyrovnaný účet `Imbalance-*` a čísla dokladů jsou úplná.
2. Vytvoří interní doklady s datem 31. 12.:
   - převod zůstatků účtů třídy 5 a 6 na **710 Účet zisků a ztrát**,
   - výsledek hospodaření z 710 na **431** (nebo 702 podle zvolené metodiky),
   - převod zůstatků rozvahových účtů na **702 Konečný účet rozvažný**.
3. Vytvoří zápisy s datem 1. 1. následujícího roku: otevření rozvahových účtů proti **701 Počáteční účet rozvažný**.
4. Do `cz_obdobi` zapíše uzavření všech měsíců roku a nastaví stav `f` (zmrazeno) u řádků roku.
5. Vytvoří archivní export (4.10).

Uzávěrkové zápisy dostanou slot `cz/doklad/druh = UZ` a vlastní číselnou řadu. Sestavy výkazů je mohou z výpočtu vynechat, aby se výsledek nezapočítal dvakrát.

Alternativa bez rozvažných účtů: ponechat jednu knihu za všechna léta bez uzavíracích zápisů a výkazy počítat k datu. Podle ZoÚ § 17 se však účetní knihy uzavírají ke konci účetního období. Variantu zvolte jen po dohodě s účetní nebo auditorem.

### 4.6 Účetní závěrka

Generátor `vykazy.py` vytvoří rozvahu a výkaz zisku a ztráty ve struktuře vyhlášky 500/2002 Sb. (přílohy 1 a 2, zkrácený nebo plný rozsah). Výstupem bude XLSX, PDF pro sbírku listin a CSV.

Mapování je v konfiguraci, nikoli v kódu. Lze ho držet v souboru `cz_vykaz_mapovani.csv` nebo v tabulce SQLite stejné struktury:

| Sloupec | Příklad | Význam |
| --- | --- | --- |
| `vykaz` | `ROZVAHA` / `VZZ` | |
| `rozsah` | `ZKRACENY` / `PLNY` | |
| `radek` | `B.II.` | Označení řádku podle vyhlášky |
| `sloupec` | `BRUTTO` / `KOREKCE` / `NETTO` / `BEZNE` / `MINULE` | |
| `ucet_od`, `ucet_do` | `311`, `315` | Rozsah syntetických účtů nebo konkrétní analytika |
| `strana` | `MD` / `DAL` / `ZUSTATEK` / `JEN_KLADNY` / `JEN_ZAPORNY` | Pravidlo pro účty s oboustranným zůstatkem, například 343 a 395 |
| `znamenko` | `+1` / `-1` | |

Kontroly: aktiva = pasiva, výsledek hospodaření ve VZZ = výsledek v rozvaze, každý účet s nenulovým zůstatkem je namapován právě jednou (kromě 701, 702, 710 a podrozvahy).

Přílohu k účetní závěrce (ZoÚ § 18, vyhl. 500/2002 Sb. § 39 a násl.) generovat nelze; připravte ji jako šablonu dokumentu, do které generátor doplní vybraná čísla.

### 4.7 DPH

**Účty (analytika 343):**

| Účet | Význam |
| --- | --- |
| `343.121` | DPH na výstupu 21 % |
| `343.112` | DPH na výstupu 12 % |
| `343.221` | DPH na vstupu 21 % |
| `343.212` | DPH na vstupu 12 % |
| `343.321`, `343.312` | DPH na výstupu – přenesení daňové povinnosti a plnění z EU (21 %, 12 %) |
| `343.421`, `343.412` | Nárok na odpočet – přenesení daňové povinnosti a plnění z EU |
| `343.900` | Zúčtování s finančním úřadem (vlastní daňová povinnost / nadměrný odpočet) |

Od 1. 1. 2024 platí sazby 21 % a 12 %. Při další změně sazeb založte nové daňové tabulky a nové analytiky a původní ponechte kvůli historickým dokladům.

**Daňové tabulky (úroveň A),** *Obchod → Prodejní daňové tabulky*:

| Název (max. 50 znaků) | Řádky (`taxtable_entries`) |
| --- | --- |
| `DPH 21 % výstup` | 21 %, účet `343.121` |
| `DPH 12 % výstup` | 12 %, účet `343.112` |
| `DPH 21 % vstup` | 21 %, účet `343.221` |
| `DPH 12 % vstup` | 12 %, účet `343.212` |
| `DPH 21 % přenesení` | +21 % `343.421` a −21 % `343.321` |
| `Osvobozeno` / `Mimo DPH` | Bez řádků. Druh plnění rozliší slot `cz/dph/kod_plneni` |

GnuCash daňovou tabulku se zápornou sazbou nemusí přijmout. Pokud ji odmítne, zaúčtujte fakturu v režimu přenesení bez daně a samotnou daň zapište interním dokladem `343.421` MD / `343.321` Dal.

**Konvence polí:**

| Údaj podle ZDPH | Pole GnuCash |
| --- | --- |
| DUZP (faktura vydaná) | `invoices.date_posted` = DUZP |
| DUZP (faktura přijatá) | slot `cz/dph/duzp`; `date_posted` = datum zaúčtování a uplatnění odpočtu |
| Datum vystavení | `invoices.date_opened` |
| Evidenční číslo dokladu dodavatele | `invoices.billing_id` |
| DIČ partnera | slot `cz/dic` u odběratele nebo dodavatele (4.8) |
| Kurz pro přepočet | kurz ČNB k DUZP v `prices` (4.9); `splits.value/quantity` |

**Sloty pro DPH na faktuře (úroveň B):**

| Klíč | Typ | Význam |
| --- | --- | --- |
| `cz/dph/duzp` | gdate | DUZP, pokud se liší od `date_posted` |
| `cz/dph/kod_plneni` | string | Kód předmětu plnění pro přenesení daňové povinnosti (např. stavební práce), dodání do EU, osvobozené plnění |
| `cz/dph/kh_oddil` | string | Ruční přepis oddílu KH (`A1`–`A5`, `B1`–`B3`); jinak se určí automaticky |
| `cz/dph/obdobi` | string | Období uplatnění odpočtu `YYYY-MM`, pokud se liší od data zaúčtování |
| `cz/dph/koef` | numeric | Krácený odpočet nebo poměrný nárok |

**Generátor přiznání a hlášení (úroveň C),** `dph.py <období>`:

1. Načte zaúčtované faktury (`invoices.date_posted` v období), jejich řádky a daňové tabulky. Doplní ostatní transakce s řádky na `343.*` (bankovní poplatky, interní doklady). Ty musí mít ve slotech DIČ a evidenční číslo.
2. Spočítá základ a daň podle sazby v CZK. Daň na dokladech v cizí měně přepočte kurzem z transakce.
3. Určí oddíl kontrolního hlášení:
   - A.1 přenesení na výstupu, A.2 přijatá plnění s povinností přiznat daň, A.4 uskutečněná plnění nad 10 000 Kč včetně DPH, A.5 ostatní uskutečněná plnění,
   - B.1 přijatá plnění v režimu přenesení, B.2 přijatá plnění nad 10 000 Kč, B.3 ostatní přijatá plnění.
4. Vytvoří XML pro EPO Finanční správy: přiznání k DPH (`DPHDP3`), kontrolní hlášení (`DPHKH1`), souhrnné hlášení (`DPHSHV`) při dodání do EU. Strukturu a verzi XSD převezměte z aktuální dokumentace EPO.
5. Ověří, že součet daní podle dokladů odpovídá obratům účtů `343.1*`, `343.2*`, `343.3*` a `343.4*` za období. Rozdíl vypíše po dokladech.
6. Vytvoří zápis zúčtování DPH na `343.900` pro následné uložení (při zavřené knize).

Přiznání i kontrolní hlášení se podávají do 25. dne po skončení zdaňovacího období. Nástroj proto má umět i opravné a následné KH.

### 4.8 Partneři: IČO, DIČ, spolehlivost plátce

Model nemá pole pro IČO ani DIČ partnera. Návrh:

- Sloty na `customers`, `vendors` a `employees`: `cz/ico` (string, 8 číslic), `cz/dic` (string, `CZ` + 8–10 číslic), `cz/ucty` (seznam bankovních účtů, GLIST stringů), `cz/spolehlivy_platce` (string `ANO`/`NE`/`NEPLATCE`), `cz/overeno` (gdate).
- Dokud chybí UI (úroveň D), použijte konvenci: **ID zákazníka nebo dodavatele = IČO** (pole lze přepsat ručně) a DIČ jako první řádek poznámky ve tvaru `DIČ: CZ12345678`. Skript `partneri.py` konvenci přenese do slotů.
- Skript ověří IČO kontrolním součtem a v ARES, DIČ a stav spolehlivého plátce v registru plátců DPH (ADIS) a zveřejněné bankovní účty. U platby dodavateli na nezveřejněný účet nad zákonný limit nebo nespolehlivému plátci vypíše varování kvůli ručení podle ZDPH § 109.

### 4.9 Kurzy ČNB

GnuCash umí kurzy stahovat přes Finance::Quote (vyžaduje Perl), zdroj ČNB však neobsahuje. Návrh:

- Skript `kurzy_cnb.py` stáhne denní kurzový lístek ČNB (`denni_kurz.txt?date=DD.MM.YYYY`) pro měny použité v knize a vloží řádky do `prices`: `commodity_guid` = měna, `currency_guid` = CZK, `source = 'user:cnb'`, `type = 'last'`, `value` = kurz / množství, `date` = den vyhlášení `10:59:00 UTC`.
- Spouští se při zavřené knize nebo naplánovaně. Duplicitní kurzy (stejná měna a den) přeskočí.
- Pro DPH platí kurz ke dni DUZP (ZDPH § 4 odst. 5). Při zadání cizoměnové faktury proto zkontrolujte, že GnuCash v dialogu převodu nabídl kurz ze správného dne.
- Pevný kurz podle ZoÚ § 24 odst. 6 (pokud jej jednotka používá) zapisujte se zdrojem `user:pevny-kurz`. Kurzové rozdíly k rozvahovému dni řeší skript přecenění (563/663) jako součást uzávěrky.

### 4.10 Archivace a ochrana záznamů

| Doklad | Lhůta | Předpis |
| --- | --- | --- |
| Účetní závěrka a výroční zpráva | 10 let od konce účetního období | ZoÚ § 31 |
| Účetní doklady, knihy, účtový rozvrh, inventurní soupisy | 5 let od konce účetního období | ZoÚ § 31 |
| Daňové doklady a evidence pro DPH | 10 let od konce zdaňovacího období | ZDPH § 35 |

Skript `archiv.py <rok>` vytvoří adresář s:

- kopií knihy ve formátu SQLite a XML (nezávislé na verzi GnuCash),
- deníkem, hlavní knihou, předvahou, saldokonty a výkazy v PDF a CSV,
- exportem `cz_audit_log`,
- soupisem připojených dokladů (`assoc_uri`) včetně kopií souborů,
- souborem `SHA256SUMS` s kontrolními součty všech souborů.

Archiv ukládejte na úložiště mimo pracovní počítač, s omezeným zápisem a zálohou podle ZoÚ § 32.

### 4.11 Fakturace a banka

- **Daňový doklad:** vestavěná sestava „Tax Invoice“ neuvádí DIČ obou stran, DUZP, rozpis základu a daně podle sazeb v CZK ani text o přenesení daňové povinnosti. Doporučení: tisk faktur skriptem `faktura.py <id>` ze SQLite do PDF podle české šablony, včetně **QR platby (formát SPAYD)** a volitelného exportu **ISDOC**. Úprava sestavy v Scheme (úroveň D) je možná, ale náročnější na údržbu.
- **Bankovní výpisy:** české banky nabízejí formáty GPC (ABO), camt.053 a CSV. Skript `banka.py` je převede na CSV pro vestavěný import a doplní variabilní symbol do pole „Číslo“. Párování úhrad s fakturami podle VS (`invoices.id` nebo `billing_id`) navrhne skript; úhradu pak potvrdí uživatel v dialogu *Zpracovat platbu*.

### 4.12 Úpravy zdrojového kódu (úroveň D, volitelné)

Úroveň D zvažte, až budou úrovně A–C ověřené v provozu. Kandidáti:

1. Záložka „CZ“ v dialozích zákazníka, dodavatele a faktury pro sloty `cz/ico`, `cz/dic`, `cz/dph/duzp`, `cz/dph/kod_plneni`. Dotčené soubory: `gnucash/gnome/dialog-customer.c`, `dialog-vendor.c`, `dialog-invoice.c` a odpovídající `gtkbuilder/*.glade`.
2. Kontrola uzamčených období v jádru (`Transaction.cpp`, `xaccTransIsReadonlyByPostedDate`) podle tabulky období místo počtu dní.
3. Zápis jména přihlášeného uživatele do slotu `cz/doklad/zauctoval` při commitu transakce.
4. Doplnění českých překladů v `po/cs.po`, kde se zobrazují anglické texty.

Úpravy udržujte jako sadu patchů nad větví `stable`. Sestavení pro Windows popisuje [instalační dokument](gnucash_instalace_cs.md).

## 5. Mimo rozsah GnuCash

| Oblast | Proč | Doporučení |
| --- | --- | --- |
| Dlouhodobý majetek, účetní a daňové odpisy | Chybí karta majetku, odpisový plán a daňové skupiny | Evidence v tabulce nebo samostatném nástroji; měsíční souhrnný zápis 551/08x interním dokladem |
| Mzdy | Výpočet mezd, odvodů, přehledy pro ČSSZ a ZP | Mzdový program; měsíční souhrnný zápis 521, 524/331, 336, 342 |
| Skladová evidence | GnuCash nevede zásoby v množství s oceňováním | Účtovat zásoby způsobem B nebo vést sklad externě |
| Daň z příjmů | Přiznání a úpravy základu daně | Podklad z VZZ a z daňově neuznatelných nákladů (samostatné analytiky 5xx) |

## 6. Vybrané účetní jednotky (obce, městské části)

Pro územní samosprávné celky a příspěvkové organizace platí vyhláška 410/2009 Sb., České účetní standardy č. 701–710, rozpočtová skladba (vyhláška 412/2021 Sb.), předávání účetních záznamů do CSÚIS (vyhláška 383/2009 Sb.) a pomocný analytický přehled. Tyto předpisy vyžadují vazbu každého zápisu na rozpočtovou skladbu (paragraf, položka), účelový znak, organizaci a další dimenze. Model GnuCash má jedinou dimenzi, strom účtů. Je proto **pro tyto jednotky nevhodný** a navržené úpravy pro ně nestačí. GnuCash lze nanejvýš použít pro podpůrnou evidenci, například projektových nebo majetkových toků, bez nároku na zákonné účetnictví.

## 7. Plán realizace

| Fáze | Výstup | Ověření |
| --- | --- | --- |
| 1 | Šablona osnovy `acctchrt_podnikatel.gnucash-xea`, daňové tabulky, čítače, směrnice konvencí | Nová kniha z šablony; zkušební měsíc s fakturami v CZK a EUR |
| 2 | Auditní tabulky a triggery, `instaluj_audit.py`, `audit_hash.py` | Test otevření, úpravy, uložení a zavření v GnuCash 5.16; zablokovaná změna v uzavřeném období |
| 3 | Sestavy knih (4.3), kontrola řad (4.2), kurzy ČNB (4.9) | Shoda předvahy s vestavěnou sestavou GnuCash |
| 4 | DPH: `dph.py`, XML pro EPO | Validace XML proti XSD Finanční správy; kontrola proti obratům 343 |
| 5 | Partneři (4.8), banka a faktury (4.11) | Ověření IČO a DIČ v ARES a ADIS |
| 6 | Uzávěrka (4.5), výkazy (4.6), archiv (4.10) | Rozvaha a VZZ ze zkušebního roku; aktiva = pasiva |
| 7 | Úroveň D podle potřeby | Sestavení pro Windows, regresní testy `ctest` |

Nástroje úrovně C budou v Pythonu 3.12 se standardní knihovnou `sqlite3`. Navržená struktura adresáře `nastroje/` v kořeni projektu: `cz_gnucash/db.py` (připojení, převody `num/denom`, GUID, sloty), samostatné skripty podle kapitol a `konfigurace/` s mapováním výkazů a DPH.

## 8. Otevřené otázky

1. Pro jaký typ a kategorii účetní jednotky bude kniha vedena? Na odpovědi závisí rozsah výkazů a použitelnost GnuCash vůbec (kapitola 6).
2. Je jednotka plátcem DPH a s jakým zdaňovacím obdobím?
3. Bude knihu používat více osob současně? Pokud ano, přejít na PostgreSQL (4.4).
4. Uzávěrka s rozvažnými účty 701/702, nebo bez nich (4.5)?
5. Postačí tisk faktur externím skriptem, nebo je nutný přímo z GnuCash (4.11, úroveň D)?

## Předpisy

- Zákon č. 563/1991 Sb., o účetnictví (ZoÚ)
- Vyhláška č. 500/2002 Sb., pro podnikatele účtující v soustavě podvojného účetnictví
- České účetní standardy pro podnikatele č. 001–024
- Zákon č. 235/2004 Sb., o dani z přidané hodnoty (ZDPH)
- Zákon č. 586/1992 Sb., o daních z příjmů (ZDP)
- Pro vybrané účetní jednotky: vyhláška č. 410/2009 Sb., ČÚS č. 701–710, vyhláška č. 412/2021 Sb., vyhláška č. 383/2009 Sb.

Aktuální znění předpisů: [e-Sbírka](https://www.e-sbirka.cz). Formáty elektronických podání: [daňový portál Finanční správy](https://adisspr.mfcr.cz).
