# Část 8.3 – hlavní kniha, předvaha a stažení CSV ve webu

Stav: **implementováno, automaticky ověřeno a potvrzeno obsluhou**.
Dokončení živé přejímky 8.4 uživatel potvrdil v konverzaci dne 4. 11. 2026.
Jde o potvrzení obsluhy; konkrétní čísla dokladů ani soubory nebyly předány.

Web doplňuje dvě sekce **Hlavní kniha** a **Obratová předvaha** nad
[JSON API](sestavy_api.md) a [CSV exporty](sestavy_csv.md).
Nová migrace ani nové účetní zápisy nejsou potřeba. Živé ověření v této
kapitole slouží ke čtení již připravených dokladů v UCTO_DEV.

## 1. Spusťte aplikaci a vyberte jednotku

1. Z kořene projektu spusťte `.\spustit.ps1`, nebo použijte ruční postup
   ze začátku [README](../README.md#ruční-spuštění-aplikace-krok-za-krokem).
2. Otevřete [http://localhost:8005/](http://localhost:8005/) a přihlaste se
   svým stávajícím účtem Microsoft.
3. Ve výběru **Účetní jednotka** zvolte **UCTO_DEV**.
4. V navigaci najdete nové položky **Hlavní kniha** a **Obratová předvaha**.
   Na mobilu lze lištu sekcí posunout vodorovně.
5. Pokud nové položky nevidíte, obnovte stránku až po dokončení všech
   rozpracovaných nebo nepotvrzených zápisů. V případě potřeby restartu webu
   stiskněte Ctrl+C v jeho okně a spusťte `.\tools\dev\17-web.ps1`.

Pro sestavy potřebujete čtení jednotky, účtů, období i dokladů. Při
nedostatečných právech se zobrazí vysvětlení a data ani export se nenabídnou.
Oprávnění k zápisu nejsou pro sestavy potřeba.

## 2. Zobrazte hlavní knihu pokladny

Následující hodnoty navazují na dva doklady za 100 Kč a 50 Kč z
[živého účetního průchodu](vyvoj_nasazeni.md#5-proveďte-živý-průchod-z-prohlížeče).
Pokud jste tehdy použili jiný měsíc, vyberte své skutečné testovací období
a jeho datumové hranice. Kvůli sestavám období znovu neotevírejte a doklady
nezakládejte podruhé.

1. Klikněte na **Hlavní kniha**.
2. Ověřte nadpis a jednotku UCTO_DEV v horní části obrazovky.
3. Vyplňte filtry podle tabulky:

| Pole | Hodnota pro původní listopadový scénář |
| --- | --- |
| Účetní období | **Živý test – listopad 2026 · uzavřené** |
| Účet | **211 · Pokladna** |
| Od | `01.11.2026` (případně `2026-11-01`) |
| Do | `30.11.2026` (případně `2026-11-30`) |

4. Po změně filtru se výsledek načte automaticky. Pro opakované načtení
   stejného výběru klikněte na **Obnovit sestavu**.
5. Pokud v období nejsou jiné zaúčtované doklady, očekávejte:

| Souhrn | Částka |
| --- | --- |
| Počáteční zůstatek výběru · MD − DAL | **0,00** |
| Obrat MD | **150,00** |
| Obrat DAL | **0,00** |
| Konečný zůstatek · MD − DAL | **150,00** |

6. V tabulce mají být dva pohyby s MD 100,00 a 50,00 a čísly vašich
   zaúčtovaných dokladů. Konečný průběžný zůstatek má být 150,00.
   Při stejném datu závisí pořadí dokladů na jejich ID; první průběžný
   zůstatek tedy může být 100,00 nebo 50,00.
7. Souhrny platí pro celý výběr, nikoli pouze zobrazenou stránku. Při více
   než 20 pohybech můžete použít **Další** a **Předchozí**; souhrny
   zůstávají za celý výběr a průběžný zůstatek pokračuje i na další stránce.

Jsou-li v období další doklady, částky se o ně správně zvýší nebo sníží.
Nemažte ani neupravujte doklady jen proto, aby se součty shodovaly s ukázkou.

## 3. Otevřete původní doklad a jeho historii

1. V tabulce hlavní knihy klikněte na **číslo dokladu**.
2. Otevře se detail se stejnou hlavičkou a řádky jako v sekci Doklady.
3. Pole musejí být pouze ke čtení. Nejsou zde tlačítka **Uložit návrh**
   ani **Zaúčtovat doklad**, a to ani u administrátora.
4. Máte-li oprávnění k historii, klikněte na **Zobrazit účetní historii**.
   Zkontrolujte vytvoření, úpravy a zaúčtování. Čtenář bez tohoto oprávnění
   tlačítko nemá.
5. Klikněte na **Zavřít** nebo na křížek v záhlaví detailu. Vrátíte se
   ke stejným filtrům a stránce sestavy.

## 4. Ověřte bankovní účet a význam záporného zůstatku

1. V hlavní knize ponechte stejné období, Od a Do.
2. V poli **Účet** vyberte **221 · Bankovní účet**.
3. Pro původní dvojici dokladů očekávejte obrat MD **0,00**, obrat DAL
   **150,00** a konečný zůstatek **−150,00** (ve webu může být běžný znak `-`).
4. Záporné číslo v této sestavě znamená stranu DAL. Jde o výsledek
   evidovaných pohybů v tomto období, bez automaticky převzatého počátečního
   stavu banky z jiného období.

Ve výběru se nabízejí i neaktivní nebo nyní neúčtovatelné účty. Historické
zaúčtované pohyby se z nich neztrácejí; pro prohlížení je lze normálně vybrat.

## 5. Zobrazte obratovou předvahu

1. Klikněte na **Obratová předvaha**.
2. Vyberte stejné testovací období a nastavte Od/Do na celý měsíc.
3. Volbu **Zobrazit i účty bez pohybů** pro první kontrolu nechte nezaškrtnutou.
4. Pro původní dva doklady mají být výsledky:

| Účet | Počáteční MD / DAL | Obrat MD | Obrat DAL | Konečné MD | Konečné DAL |
| --- | --- | --- | --- | --- | --- |
| 211 Pokladna | 0,00 / 0,00 | 150,00 | 0,00 | 150,00 | 0,00 |
| 221 Bankovní účet | 0,00 / 0,00 | 0,00 | 150,00 | 0,00 | 150,00 |

5. Souhrnná tabulka nad účty ukazuje počáteční zůstatky, obraty a konečné
   zůstatky za celý výběr. Ve všech třech kontrolních rozdílech očekávejte
   **0,00** a zprávu **Součty MD a DAL jsou vyrovnané.**
6. Zaškrtněte **Zobrazit i účty bez pohybů**. Pokud v osnově takové účty
   existují, přidají se s nulami. Pokud máte jen účty 211 a 221 s pohyby,
   seznam se nezmění; kvůli této kontrole nemusíte zakládat další účet.
7. Klikněte na **kód a název účtu** v tabulce. Otevře se jeho hlavní kniha
   se stejným obdobím a daty Od/Do.

Nadřazené účty zobrazují vlastní pohyby. Podřízené účty se do jejich
řádku automaticky nesčítají, takže se zápisy nezapočítají dvakrát.

## 6. Vyzkoušejte užší rozsah a neplatné datumové rozmezí

Pokud oba původní doklady mají datum 4. 11. 2026:

1. V hlavní knize účtu 211 nastavte **Od 05.11.2026**, **Do 30.11.2026**.
2. Pokud pozdější pohyby neexistují, tabulka bude prázdná. Počáteční
   a konečný zůstatek výběru bude **150,00** a oba obraty **0,00**.
   Prázdná tabulka tedy nemusí znamenat nulový zůstatek.
3. Nastavte **Od 15.11.2026**, **Do 10.11.2026**. Očekávejte vysvětlení,
   že Od nesmí být později než Do. Původní souhrny se skryjí a
   **Stáhnout CSV** není dostupné, dokud rozsah neopravíte.
4. Vraťte **Od 01.11.2026**, **Do 30.11.2026**. Výsledek se znovu načte.

Při jiném původním datu použijte odpovídající rozsah uvnitř svého období.
Pokud doklady leží na jeho posledním dni, první variantu s pozdějším datem
přeskočte. Změna vybraného období nastaví Od/Do automaticky na jeho hranice.

## 7. Stáhněte CSV a porovnejte souhrny

1. V hlavní knize nebo předvaze nejprve vyberte platné filtry a počkejte,
   až se zobrazí načtená sestava.
2. Klikněte na **Stáhnout CSV**. Tlačítko se dočasně změní na
   **Připravuji CSV…**. Příprava může trvat až dvě minuty.
3. Po přípravě se soubor předá prohlížeči ke stažení. Uložte jej nebo jej
   najděte v seznamu stažených souborů prohlížeče. Název obsahuje typ
   sestavy, identifikátor jednotky a obě data; identifikátor nemusíte opisovat.
4. Export zahrnuje **všechny stránky** stejného výběru. Nezávisí na tom,
   na které stránce tabulky jste právě byli.
5. V souboru ověřte jednotku, období, Od/Do, měnu, počet datových řádků
   a souhrny na konci. Za nezměněných dat musejí odpovídat obrazovce.
6. Pro import do Excelu použijte [podrobný postup CSV](sestavy_csv.md#jak-ukázku-otevřít-v-excelu).
   Obsahuje i ukázkové soubory a zachování kódů s úvodními nulami.

CSV se sestavuje z aktuálních dat při stažení. Pokud někdo mezitím zaúčtuje
další doklad, může se lišit od staršího zobrazení; použijte **Obnovit sestavu**.
Při více než 50 000 řádcích server vyzve ke zúžení rozsahu a nevrátí zkrácený
soubor. Chyba spojení nebo serveru se zobrazí na stránce, neuloží se jako CSV.

Změna filtrů, jednotky nebo odchod do jiné sekce zruší rozpracované čtení
a přípravu souboru. Opožděná odpověď nesmí zobrazit údaje předchozí jednotky
ani zahájit její stažení. Výpadek přihlášení vrátí aplikaci k přihlášení.

## 8. Zkontrolujte oddělení jednotek a poznamenejte výsledek

1. Zavřete detail dokladu a přepněte z **UCTO_DEV** na **DEV**.
2. Sestavy musejí nabídnout účty a období DEV, nikoli předchozí výběr UCTO_DEV.
   Pokud DEV zatím období nemá, zobrazí se návod k jejich vytvoření.
3. Kvůli zkoušce zde nové období nevytvářejte; přepněte zpět na UCTO_DEV.
4. Poznamenejte si výsledek skutečně provedených kontrol:

```text
Datum:
Jednotka: UCTO_DEV
Testovací období:
Hlavní kniha 211: počáteční / MD / DAL / konečný:
Hlavní kniha 221: počáteční / MD / DAL / konečný:
Předvaha: součty vyrovnané ANO / NE
Přechod z předvahy zachoval účet, období a data: ANO / NE
Detail dokladu byl pouze ke čtení: ANO / NE
CSV obsahuje správný výběr, počet řádků a souhrny: ANO / NE
Po přepnutí na DEV nejsou zobrazena data UCTO_DEV: ANO / NE
Poznámka nebo chyba:
```

Toto je živá přejímka části 8.4. Výsledek zatím není potvrzený automatickými
testy webu: ty používají náhradní API a nenahrazují skutečné přihlášení,
živý export ani otevření souboru ve vašem Excelu.

## Automatické ověření pro vývoj

Z kořene projektu spusťte:

```powershell
.\tools\ucto\15-sestavy-web-test.ps1
```

Skript generuje typy, provede jednotkové testy, TypeScript, build a všechny
prohlížečové scénáře partnerů, účetnictví a sestav. Prohlížeč běží na portu
8006 s testovacími odpověďmi API, na desktopu i v mobilním rozměru.
Backend ani databázový tunel pro tento běh nejsou potřeba. Skript nepřepisuje
živá data a neprovádí migrace. Výchozí prohlížeč je Edge; pro dříve
nainstalovaný Chromium lze před příkazem nastavit `$env:PLAYWRIGHT_CHANNEL='chromium'`.

Pokud test stažení CSV selže, vypíše viditelnou chybu aplikace a dostupné
síťové hlášení místo samotného čekání na událost `download`. Testovací CSV
se předává jako UTF-8 bajty v Bufferu včetně BOM a stažený soubor se porovnává
bajt po bajtu. Tím se ověřuje také diakritika a úplnost souboru. Tento formát
testovací odpovědi podporuje [Playwright Route.fulfill](https://playwright.dev/docs/api/class-route#route-fulfill).

Po této úpravě prošlo deset opakování původně selhaného desktopového scénáře
a následně celá sada: 25 jednotkových testů, TypeScript, build a všech
50 prohlížečových testů na desktopu i mobilu. Globální timeout testu zůstává
30 sekund; případná chyba přenosu se nepřekrývá opakováním požadavku.

Pro deset opakování samotného desktopového scénáře spusťte z kořene projektu:

```powershell
$env:PLAYWRIGHT_CHANNEL='chromium'
Push-Location -LiteralPath .\frontend
try {
    npm.cmd run test:e2e -- tests/browser/reports.spec.ts --project=desktop --grep 'hlavní kniha:' --repeat-each=10
} finally {
    Pop-Location
}
```

## Záznam automatického ověření 4. 11. 2026

- **25 jednotkových testů** prošlo, včetně parametrů exportu, hranic období,
  dlouhých částek, chybového CSV, přesměrování, timeoutu a přerušení přenosu.
- Prošlo generování typů, kontrola TypeScriptu a produkční build.
- **50 prohlížečových testů** prošlo v jednom úplném běhu v Chromium:
  25 na desktopu a 25 v mobilním rozměru. Obsahují všechny původní
  partnerské i účetní scénáře a 12 nových běhů sestav.
- Nové scénáře ověřují součty nezávislé na stránce, přesné částky, uzavřené
  období, neaktivní účet, přechod z předvahy, detail pouze ke čtení,
  historii, obsah staženého CSV včetně BOM a všech stránek, chyby 422/503,
  prázdný výsledek, ztrátu práv 403, vypršení přihlášení 401 a opožděné odpovědi.
- Snímky hlavní knihy a předvahy byly vizuálně zkontrolované na desktopu
  i mobilu. Široké tabulky mají vlastní vodorovné posouvání, dlouhé částky
  jsou zachované a stránka se neroztahuje mimo viewport.

Změny této části jsou ve webu a jeho testech; backendový kontrakt ani
migrace se neměnily. Poslední úplná DB regrese z 8.2 má 75 testů / 917
kontrol; kvůli samotné změně webu nebyla znovu spouštěná. Automatické webové
ověření používá náhradní API, skutečný Entra → web → API → DB průchod
sestavami zůstává samostatným krokem 8.4.
