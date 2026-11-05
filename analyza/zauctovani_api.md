# Část 7.3 – zaúčtování a uzamčení období

Implementace doplňuje dvě serverové operace: zaúčtování existujícího návrhu
a technické uzavření účetního období. Migrace `20261103030000` přidává stavy,
číslování, metadata, ochrany a idempotenci; `20261103030100` vynucuje otevřený
výchozí stav období i při přímém SQL INSERT. Navazuje na [rozpracované doklady](doklady_api.md).

## Automatický postup nastavení a ověření

Všechny příkazy spouštějte z kořene projektu v PowerShellu. Potřebujete
připravené závislosti backendu, `mikoucto_test` a uložená hesla projektových
rolí. Server webu/backendu ani živé přihlášení Microsoft testy nevyžadují.

1. V prvním okně ponechte běžet tunel. Pokud už běží, další nespouštějte:

   ```powershell
   .\db\google\00-tunel.ps1
   ```

2. Ve druhém okně připravte testovací databázi:

   ```powershell
   .\tools\ucto\06-zauctovani-priprava.ps1 -Target Test
   ```

   Očekávejte aktuální verzi `Version20261103030100` a zprávu
   `Zauctovani a uzavreni obdobi pripraveno: Test`.

3. Po úspěchu spusťte:

   ```powershell
   .\tools\ucto\07-zauctovani-test.ps1
   ```

   Skript provede jednotkové testy backendu, celou DB integrační sadu,
   ověření migrací a export OpenAPI. JUnit report musí obsahovat skutečně
   provedené a úspěšné AccountingTest, AccountingConcurrencyTest,
   DraftDocumentsTest, DraftConcurrencyTest, PostingTest a
   PostingConcurrencyTest. Prázdná nebo přeskočená povinná sada je chyba.
   Úspěšný závěr začíná `Cast 7.3 OK`.

Testy samy založí jednotky, účty, období a doklady a následně uklidí svá data;
audit zůstává. Celá DB sada může trvat přes dvacet minut. Běží výhradně
v `mikoucto_test`; jednotlivé celé sady se chrání společným DB zámkem.
Při selhání skript vypíše cestu k JUnit reportu v `backend/var`.
Známé logy `[critical]` z negativních partnerských testů posuzujte podle
závěrečného výsledku PHPUnit, nikoli samostatně.

### Ověření první dodávky 4. 11. 2026

- Jednotkové testy backendu: **73 testů, 188 kontrol**, bez selhání.
- Úplná databázová regrese: **63 testů, 638 kontrol**, bez selhání;
  databázová část trvala 23 minut 40 sekund.
- Celý skript `07-zauctovani-test.ps1` skončil úspěšně, včetně kontroly
  aktuálnosti migrací a exportu účetního OpenAPI s 11 cestami.
- Z toho část 7.3: sedm testů zaúčtování a pět testů skutečného souběhu
  nezávislých PHP procesů. Oba požadavky musí před uvolněním testovací
  bariéry současně čekat na databázový zámek; při selhání se vypíše jejich stav.
- Web: 13 jednotkových a 18 prohlížečových testů, kontrola typů a sestavení
  prošly. Prohlížečové testy používají simulované API.

Migrace byly provedeny pouze v `mikoucto_test`. Vývojová databáze
`playground` ani živý průchod přihlášeného uživatele nejsou tímto ověřením pokryté.

### Oprava synchronizace souběžných testů

Následný běh odhalil nepravidelné selhání
`DraftConcurrencyTest::testSimultaneousHttpPatchHonorsAggregateEtag`
na čekání procesu na zámek. Starší testy částí 7.1/7.2 ještě používaly
samostatné čekání pro každý proces s limitem 10 nebo 30 sekund.

Všechny tři souběžné sady nyní používají společnou kontrolu
`AccountingWorker::awaitBlockedBy`. Procesy se kontrolují současně během
jednoho intervalu nejvýše 45 sekund, před jejich 60sekundovým limitem
databázového zámku. Dvojice HTTP požadavků se spouštějí až po přípravě obou
procesů. `pg_blocking_pids` ověřuje, že každý proces skutečně blokuje
testovací transakce. Předčasná odpověď test ukončí chybou; při vypršení
čekání výpis obsahuje PID, stav a blokující procesy. Požadavky se automaticky
neopakují a test PATCH stále vyžaduje právě jednu odpověď 200 a jednu 412.

Při chybě se ponechají soubory `backend/var/accounting-worker-<id>.*`.
Soubor `.exception` zachytí příčinu HTTP výjimky i tehdy, když ji veřejná
odpověď 500 skrývá. Cestu k diagnostice test vypíše při předčasné odpovědi.

Skript `tools/dev/04-backend-test.ps1` má nyní vlastní cache v
`backend/var/cache/unit/test`. Její čištění tak neodstraní kontejner právě
běžící DB sady v `backend/var/cache/test`. Po jednotkových testech se
proměnné `APP_CACHE_DIR` a `APP_BUILD_DIR` obnoví, aby integrační sada
i její podprocesy dál používaly společnou DB cache.

Rychlé ověření všech souběžných scénářů při běžícím tunelu:

```powershell
.\tools\dev\07-db-test.ps1 -Filter ConcurrencyTest
```

Úplné ověření zůstává `07-zauctovani-test.ps1`. Tato oprava mění pouze
testovací podporu a synchronizaci testů; novou migraci nevyžaduje.

Ověření opravy 4. 11. 2026:

- Jednotkové testy s oddělenou cache: **73 testů, 188 kontrol**, bez selhání.
  Během nich se DB kontejner nezměnil a proměnné cache se správně obnovily.
- Úplná DB regrese se společným čekáním: **63 testů, 633 kontrol**,
  bez chyb, selhání nebo přeskočení. Zahrnuje všech 13 souběžných scénářů.
  Nižší počet kontrol oproti první dodávce odpovídá sloučení jednotlivých
  kontrol čekání do jedné společné kontroly všech procesů.
- PATCH se starým ETag a souběh uzavření období s novým dokladem prošly
  následně ve **dvou samostatných cílených bězích**, každý 2 testy / 8 kontrol.
  Oba scénáře tak prošly v úplné regresi i dvakrát po ní.

## Kontrakt příkazů

| Příkaz | URL | Oprávnění |
| --- | --- | --- |
| Zaúčtování | POST `/api/v1/accounting-units/{unit}/documents/{id}/post` | administrator nebo accountant, `core.ucto.documents.post` |
| Uzavření období | POST `/api/v1/accounting-units/{unit}/periods/{id}/close` | administrator, `core.ucto.periods.close` |

Oba příkazy vyžadují existující Entra Bearer token, `Content-Type:
application/json`, tělo **`{}`**, silný `If-Match` načteného objektu a
`Idempotency-Key` podle pravidel částí 7.1/7.2. Neznámá pole, pole místo
objektu, prázdné tělo a query parametry se odmítají. Stav, číslo, autora
ani čas klient nezadává. Úspěch vrací 200, detail objektu, ETag a
Idempotency-Replayed. Nevytváří se další doklad.

Chybějící klíč/špatný formát/tělo → 400; bez přihlášení → 401;
bez práva/jednotky → 403; nedostupný objekt → 404; nesplněná účetní pravidla
nebo konflikt klíče → 409; starý ETag → 412; chybějící If-Match → 428;
nedostupná DB či přerušená transakce → 503. Neúspěch nepotvrzuje zápis.

## Zaúčtování návrhu

V jedné transakci se ověří:

- Oprávnění do jednotky a očekávaná verze dokladu.
- Otevřené období a datum uvnitř jeho hranic.
- Nejméně dva řádky a přesná rovnost součtů MD a DAL.
- Aktivní a účtovatelné účty; vazby na jednotku chrání také cizí klíče.

Návrh 100.00/99.00 se odmítne a zůstane draft, číslo se nespotřebuje.
Po změně na 100.00/100.00 lze zaúčtovat. Prázdný návrh s balanced=true
se nezaúčtuje. Částky a součty zůstávají přesné desetinné hodnoty.

Úspěch nastaví status=posted, číslo, postedAt a postedBy a zvýší verzi jednou.
Řádky se nepřepisují. Zaúčtování má jedinou auditní změnu hlavičky;
operativní čítač ani evidence opakování nepředstírají další zaúčtování.

### Číslo a řada

První dodávka má jedinou řadu `INT`. Čítač je samostatný pro jednotku,
období a řadu. Formát je `INT/<začátek období YYYYMMDD>/<pořadí>`:
například `INT/20260101/000001`. Pořadí má nejméně šest číslic; po 999999
se nerozkrátí. Rozsah je kladný bigint. Číslo je jedinečné v jednotce;
shodná data období jiných jednotek mají vlastní čítače.

Čítač se změní ve stejné transakci jako stav, audit a odpověď. Rollback
vrátí také čítač. Současné zaúčtování dvou dokladů nevytvoří stejné číslo.
Začátek období s doklady je již od 7.2 neměnný, takže prefix zůstane stabilní.
Nejde o automatickou roční závěrku ani úplnou podporu dokladových řad.

## Uzavření období

Uzavření odmítne období, které obsahuje byť jediný návrh. Nejdříve je nutné
návrhy dokončit a zaúčtovat. Prázdné období uzavřít lze.

Úspěch nastaví status=closed, closedAt, closedBy a zvýší verzi jednou.
Do uzavřeného období nelze založit nový doklad ani změnit existující zápis.
Období se nepřejmenovává, nemění se jeho hranice ani se neotevírá zpět.
Zaúčtované doklady, seznamy a historie zůstávají dostupné podle rolí.

Technické uzavření nepřidává závěrkové účetní zápisy, výkazy ani automatickou
roční závěrku. Znovuotevření a opravný/storno tok nejsou operace této části.

## Neměnnost a opakování

Zaúčtovaný doklad odmítne i bezezměnový PATCH. Chrání jej obal kolem
`ucto.save_draft`, který kontroluje stav, a databázové triggery hlavičky
a řádků. Starší interní funkce save_draft_72 není aplikační roli spustitelná.
Aplikace stále nemá přímý INSERT/UPDATE/DELETE dokladových tabulek.
Přímá změna statusu období aplikační rolí musí použít chráněný příkaz;
nové období vždy začíná otevřené.

Migrační vlastník zůstává důvěryhodným správcem a může provést auditovanou
údržbu/odstranění dat. Výjimka pro DELETE kontroluje session_user,
ne current_user; nelze ji převzít přes SECURITY DEFINER runtime funkci.
Testovací fixture tak uklidí vlastní data bez vypnutí auditních triggerů.

Idempotentní klíč má rozsah jednotka, uživatel a operace a platnost 24 hodin.
Hash příkazu obsahuje cílové ID a očekávanou verzi. Stejný čerstvý klíč,
ID a původní If-Match vracejí původní odpověď i po ztrátě odpovědi ze sítě.
Jiné ID nebo ETag se stejným čerstvým klíčem znamená 409.

Po expiraci nebo s novým klíčem platí aktuální ETag. Starý ETag znamená 412
a nevytvoří další číslo ani audit. Příkaz nad již posted/closed objektem
s jeho aktuálním ETag je úspěšná bezezměnová operace: zůstává číslo, verze,
autor, čas i audit. To platí i pro posted doklad v uzavřeném období.

## Zámky a ochrana v databázi

Příkazy a ukládání návrhů sdílejí jednotkový advisory zámek účtů. Dále se
zamyká idempotentní klíč, období a při zaúčtování hlavička. Pořadí období
před dokladem je stejné jako v ukládání návrhu. Funkce vyžadují READ COMMITTED,
bezpečný search_path a explicitní kontrolu práv i jednotky pod SECURITY DEFINER.
Pomocné funkce nejsou veřejným zapisovacím rozhraním.

Při souběhu uzavření a zaúčtování nastane konzistentní výsledek: buď se
zaúčtuje před úspěšným uzavřením, nebo uzavření odmítne zbývající draft.
Při souběhu založení návrhu a uzavření buď vznikne draft v otevřeném období
a uzavření se odmítne, nebo se období uzavře a návrh se neuloží.
Při deadlocku nebo timeoutu se celá transakce vrátí a výsledek není úspěch.

Účetní OpenAPI obsahuje nyní 12 cest v `shared/openapi/accounting.json`,
včetně deníku dodaného v části 7.4.
Vývojové nasazení do `playground`, záloha a ověřená obnova i živé účetní
použití jsou samostatné kroky. Webová tlačítka a účetní deník jsou dodané
v [části 7.4](ucetni_web.md).
