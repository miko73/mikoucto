# Část 7.4 – účetní web

Web obsahuje sekce **Partneři, Účty, Období, Doklady a Deník**. Navigace
je dostupná také na mobilu. Sekce používají oprávnění aktuální jednotky;
čtenář může otevřít detail, ale nemá formulářové akce zápisu.

## 1. Automatické ověření bez DB a přihlášení

Z kořene projektu spusťte v PowerShellu:

```powershell
.\tools\ucto\08-web-test.ps1
```

Potřebujete nainstalované závislosti frontendu. Skript generuje TypeScript
z obou uložených OpenAPI kontraktů, spustí jednotkové testy, kontrolu typů,
sestavení a všechny prohlížečové testy. Používá testovací web na portu 8006
a náhradní API. Backend, tunel ani přihlášený účet Microsoft nejsou nutné.
Web na portu 8005 může dál běžet.

Výchozí prohlížeč je Microsoft Edge. Pro nainstalovaný Playwright Chromium:

```powershell
$env:PLAYWRIGHT_CHANNEL = 'chromium'
.\tools\ucto\08-web-test.ps1
```

Pokud Chromium ještě není nainstalované, při prvním spuštění přidejte
`-InstallBrowser`. Očekávaný poslední řádek začíná **`Cast 7.4 OK`**.
Při chybě pokračování nepovažujte za ověřené; Playwright ponechá diagnostiku
a snímky v `frontend/test-results`.

## 2. Ověření skutečného API deníku

Při běžícím tunelu spustíte cílené DB testy:

```powershell
.\tools\dev\07-db-test.ps1 -Filter JournalTest
```

Testy zakládají vlastní doklady ve dvou jednotkách a ověřují skutečné
zaúčtované řádky, přesné částky, stránkování, hledání, filtry a oprávnění.
Pracují pouze v `mikoucto_test`. Úplná DB regrese zůstává skript
`tools/ucto/07-zauctovani-test.ps1` a obsahuje i nové testy deníku.

Nový endpoint je GET `/api/v1/accounting-units/{unit}/journal`.
Vyžaduje `core.ucto.documents.read`; používá stávající RLS a identitní
transakci. Obsahuje pouze řádky zaúčtovaných dokladů, včetně čísla,
data, účtu a obou částek jako řetězců. Filtry `periodId`, `accountId`,
`from`, `to` a `search` se uplatňují na serveru před stránkováním.
Počet filtrovaných řádků vrací `X-Total-Count`; velikost stránky je nejvýše 100.
Účetní OpenAPI nyní obsahuje 12 cest. Nová migrace pro deník není potřeba.

Jednotkové testy používají vlastní cache. DB vstupní bod obnoví integrační
cache až po získání společného zámku celé sady, aby nové endpointy byly
dostupné a současně se nemazaly soubory jiným běžícím DB testům.

## 3. Postup obsluhy účetních obrazovek

Živý postup předpokládá vývojovou DB s účetními migracemi 7.1–7.3 a vlastní
testovací jednotku. Jejich nasazení do `playground` je samostatný krok
s přípravou zálohy a ověřením obnovy podle kapitoly 6 v [etapě 7](etapa7.md).
Automatické webové testy tuto databázi nemigrují.

1. Ponechte běžet tunel, backend a web podle začátku README. Přihlaste se
   přes Microsoft a vyberte samostatnou testovací účetní jednotku.
2. V **Účtech** zvolte **Nový účet**. Vyplňte kód jako text (např. `211`)
   a název. Označte jej jako aktivní a účtovatelný a uložte. Založte také
   účet `221`. Nadřazený účet je volitelný; úvodní nuly v kódu se zachovávají.
3. V **Obdobích** vytvořte období, které pokrývá datum testovacího dokladu.
   Datum konce nesmí předcházet začátku. Překryv období odmítá API.
4. V **Dokladech** zvolte **Nový doklad**, vyberte období, datum a popis.
   Partner je volitelný. Tlačítkem **Přidat řádek** přidejte dvě položky:
   na první účet MD `100.00`, DAL `0.00`, na druhý MD `0.00`, DAL `99.00`.
   Na každém řádku musí být kladná právě jedna strana. Návrh uložte.
   Součty ukazují rozdíl `1,00`; zaúčtování zatím není dostupné.
5. Změňte druhý DAL na `100,00` a znovu uložte návrh. Čárka se normalizuje
   na desetinnou tečku pro API. Součty i rozdíl se počítají přesně pomocí
   celočíselných haléřů, bez převodu finančních částek na plovoucí číslo.
6. Zvolte **Zaúčtovat doklad** a potvrďte akci. Úspěch zobrazí číslo a stav
   zaúčtování, autora a čas. Hlavička a řádky se přepnou pouze ke čtení.
   **Zobrazit účetní historii** zpřístupní změny podle oprávnění, po stránkách.
7. Zavřete detail a otevřete **Deník**. Vyberte období a účet; přehled
   obsahuje odpovídající zaúčtované řádky. Kliknutí na číslo otevře doklad.
   Datum a hledání se kombinují s filtry. Počet označuje řádky, nikoli doklady.
8. V **Obdobích** otevřete období a zvolte **Uzavřít období**. Akci má
   administrátor a vyžaduje potvrzení. Zbývající návrhy uzavření odmítnou;
   nejdříve je dokončete a zaúčtujte. Uzavřené období zůstane jen ke čtení.
9. Přepněte do druhé jednotky. Předchozí záznamy, formuláře a historie
   se zahodí; opožděné odpovědi předchozí jednotky se nezobrazí.

## 4. Konflikt a nejistý výsledek zápisu

Pokud API vrátí 412, web načte aktuální detail a porovná místní změny
s uloženými hodnotami. **Použít aktuální verzi** načte serverový stav.
**Ponechat moje změny** přenese pouze vědomě upravená pole; řádky tvoří
jeden celek. Zachovají se změny jiného uživatele v ostatních polích.
Do již zaúčtovaného dokladu či uzavřeného období vlastní změny přenést nelze.

Přerušené spojení, nečitelná úspěšná odpověď nebo chyba 5xx zápisu neznamenají
úspěch. Formulář i navigace se zablokují a nabídnou **Opakovat původní
požadavek** se stejným tělem, cílem, klíčem i If-Match. Vytvoření a příkazy
tak využijí idempotenci serveru. Pokud opakovaný PATCH vrátí starou verzi,
je nutné výsledek prohlédnout a výslovně načíst aktuální stav.

## 5. Stav ověření

Ověřeno 4. 11. 2026:

- Celý `08-web-test.ps1`: generování obou kontraktů, TypeScript a sestavení
  prošly; **18 jednotkových a 38 prohlížečových testů** bez selhání.
- Prohlížečové testy běžely v Chromium na desktopu i mobilu. Zahrnují
  původní partnery, účetní formuláře, změny řádků, deník, historii, uzavření,
  konflikty, ztracené odpovědi zápisů, odebrání oprávnění, vypršení přihlášení,
  výpadek podkladů formuláře a opožděnou odpověď předchozí jednotky.
- Snímky formuláře návrhu na desktopu a mobilu byly zkontrolovány vizuálně;
  navigace i akce zůstávají dostupné v mobilním rozměru.
- Jednotkové testy backendu: **74 testů, 198 kontrol**, bez selhání.
- Cílené testy skutečného deníku v `mikoucto_test`: **2 testy, 32 kontrol**,
  bez selhání. Zahrnují pozitivní data dvou jednotek, izolaci, přesné maximální
  částky, filtry a oprávnění. Celá DB regrese se při této změně znovu
  nespouštěla; skript 07 nyní požaduje také úspěšné provedení `JournalTest`.
- Export účetního OpenAPI s 12 cestami prošel. Migrace zůstávají na verzi
  `Version20261103030100`; nová migrace nevznikla.

Lokální testy používají náhradní API pro prohlížeč a samostatné reálné
DB testy pro deník. Záloha, obnova a [vývojové nasazení](vyvoj_nasazeni.md)
do `playground` proběhly 4. 11. 2026; UCTO_DEV je připravená.
Živé přihlášení a účetní zápisy v prohlížeči zbývají podle kapitoly 6
v [plánu](etapa7.md). Teprve jejich potvrzení uzavírá celou etapu 7.
