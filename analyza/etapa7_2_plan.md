# Příprava části 7.2 – rozpracovaný doklad a řádky MD/DAL

Stav: **implementováno a automaticky ověřeno v mikoucto_test; vývojové nasazení neprovedeno**. Aktuální příkazy a výsledky jsou v [návodu dokladů](doklady_api.md).
Navazuje na [hotovou část 7.1](ucto_api.md) a [postup etapy 7](etapa7.md).
Tento dokument stanoví výchozí kontrakt, pořadí programování a ověřování.
Dokument zachovává původní implementační zadání. Migrace a ověření v testovací DB jsou součástí dodávky; playground se nemigrovala.

## Cíl a hranice dodávky

Administrátor a účetní uloží rozpracovaný interní doklad, načtou jej a změní
hlavičku nebo řádky. Vše se ukládá atomicky, s auditem a jedinou verzí celého
dokladu. Návrh smí být nevyrovnaný a postupně rozpracovaný.

Tato část nepřidává zaúčtování, přidělení čísla, uzavírání období, storno,
fakturaci, DPH ani webové formuláře. Ty zůstávají v 7.3, 7.4 a dalších etapách.
Stav nového dokladu je výhradně `draft`, měna výhradně `CZK`, konečné číslo null.

## 1. Vstupní kontrakt dokladu

Základ URL: `/api/v1/accounting-units/{unit}/documents`.

| Operace | Cesta | Výsledek |
| --- | --- | --- |
| POST | základní cesta | Nový návrh, 201, Location, ETag a Idempotency-Replayed |
| GET | základní cesta | Stránkovaný seznam hlaviček a součtů, bez všech řádků |
| GET | `/{id}` | Hlavička, řádky a přesné součty |
| PATCH | `/{id}` | Změna celku, 200 a nový ETag, pokud se data změnila |
| GET | `/{id}/history` | Stránkovaná historie hlavičky i řádků |

DELETE, samostatné zapisovací endpointy řádků ani endpoint `post` nejsou
součástí 7.2. Odebrání řádku se provádí změnou celého návrhu.

| Vstupní pole | Pravidlo |
| --- | --- |
| `periodId` | Povinné UUID otevřeného období stejné jednotky |
| `postingDate` | Povinné platné datum YYYY-MM-DD uvnitř období, obě hranice včetně |
| `text` | Povinný řetězec 1–2000 znaků po oříznutí krajních mezer |
| `partnerId` | Volitelné UUID partnera stejné jednotky nebo null; výchozí null |
| `lines` | Pole 0–500 řádků, výchozí prázdné; návrh tedy může mít 0 nebo 1 řádek |
| Řádek: `accountId` | Povinné UUID aktivního a účtovatelného účtu stejné jednotky |
| Řádek: `text` | Volitelný řetězec 0–500 znaků po oříznutí, výchozí prázdný |
| Řádek: `md`, `dal` | Povinné desetinné řetězce; právě jedna strana musí být kladná, druhá nulová |

Partner musí existovat ve stejné jednotce. Jeho pozdější deaktivace nesmaže
vazbu a sama neblokuje změnu návrhu; aktivita partnera není účetní invariant.
Naopak každý uložený řádek se při ukládání návrhu kontroluje proti aktuálním
příznakům účtu. Doklad s mezitím deaktivovaným účtem lze opravit odebráním
nebo nahrazením dotčeného řádku; nelze bez opravy potvrdit další změnu celku.

Server určuje ID dokladu a řádků (UUID v7), jednotku, pořadí řádků od 1,
verzi od 1, autora v auditu, časy, stav, měnu a součty. Klient nesmí zadávat
`id`, `accountingUnitId`, `version`, `status`, `number`, `currency`, součty
ani auditní údaje. Neznámá pole se odmítají, nevypouštějí potichu.

### Částky a součty bez zaokrouhlování

API přijímá například `"0"`, `"0.00"`, `"1.2"`, `"100.00"`. Normalizuje je
na řetězec se dvěma desetinnými místy. Odmítá JSON čísla, záporné hodnoty,
čárku, exponent, mezery, počáteční nuly typu `"001.00"` a více než dvě
místa, včetně `"1.000"`. Maximální částka strany řádku je
`"9999999999999999.99"`; obě nuly i obě kladné strany se odmítnou.

Rozsah odpovídá `numeric(18,2)`, ale **samotný typ numeric(18,2) zaokrouhlí
vstup dříve, než jej zkontroluje CHECK nebo trigger**. Pro skutečné odmítnutí
nadbytečné přesnosti navrhujeme uložit strany jako `numeric` bez typmodu a
přidat CHECK na konečnost, nezápornost, horní mez, `scale(hodnota) <= 2`
a právě jednu kladnou stranu. Databázová zapisovací funkce navíc ověří
textový vstup ještě před převodem. Rozšíření jako BC Math nejsou nyní
požadované závislosti; jejich přítomnost nelze předpokládat.

Výpočty v PHP budou nad celými desetinnými řetězci, případně řetězci haléřů,
nikdy float. Součty a rozdíl nejsou omezeny rozsahem jednoho řádku; test musí
ověřit také součet dvou maximálních částek bez přetečení. Výstup vrací
`totalMd`, `totalDal`, `difference` (= MD − DAL) jako řetězce se dvěma místy
a `balanced` jako boolean. Rozdíl smí být záporný, vstupní částka řádku ne.
Prázdný návrh má součty 0.00 a balanced=true; to neznamená způsobilost
k zaúčtování, kde budou v 7.3 vyžadované alespoň dva řádky.

### Konkrétní příklad vytvoření a úpravy

Následující JSON ukazuje vstup implementovaného API.
Zástupné hodnoty nahradí automatická fixture skutečnými UUID své jednotky:

```json
{
  "periodId": "<UUID_OBDOBI>",
  "postingDate": "2026-11-03",
  "text": "Zkušební rozpracovaný doklad",
  "partnerId": null,
  "lines": [
    {"accountId": "<UUID_UCTU_A>", "text": "MD", "md": "100.00", "dal": "0.00"},
    {"accountId": "<UUID_UCTU_B>", "text": "DAL", "md": "0.00", "dal": "99.00"}
  ]
}
```

Očekávané uložení: status=draft, totalMd=100.00, totalDal=99.00,
difference=1.00 a balanced=false. Nevyrovnanost není důvod odmítnutí návrhu.

POST vyžaduje Idempotency-Key. PATCH vyžaduje If-Match z posledního načtení.
Chybějící pole PATCH zachová původní hodnotu; `partnerId:null` vazbu odstraní.
Je-li přítomné `lines`, jde o **celou novou podobu řádků**, nikoli připojení
nebo částečnou úpravu jednoho řádku. `lines:[]` všechny řádky odstraní.
Null místo pole nebo povinného řetězce se odmítá.

Řádky ve vstupu nemají ID. Jejich skutečná změna nahradí sadu serverovými
řádky s novými UUID; výstup a audit zachovají identifikaci původních řádků.
Při změně pouze hlavičky se řádky nepřepisují. Při stejné normalizované
hlavičce a řádcích v témže pořadí se nemění ID, verze, čas změny ani audit.
Celý dokument má jeden ETag; samostatné verze řádků nepřidáváme.

## 2. Oprávnění a chybové odpovědi

Nová oprávnění: `core.ucto.documents.read`, `.write`, `.history`.

| Role v jednotce | Čtení | Zápis návrhu | Historie |
| --- | --- | --- | --- |
| administrator | ano | ano | ano |
| accountant | ano | ano | ne |
| auditor | ano | ne | ano |
| reader | ano | ne | ne |

Použije se existující Entra ověřování, IdentitySession a prefix `core.`
pro UnitPermissionVoter. Nevznikne testovací přihlášení v běžné aplikaci.

Chyby: 400 pro formát/typy/serverová pole a chybějící idempotentní klíč;
401 bez přihlášení; 403 bez oprávnění; 404 pro nedostupný doklad;
422 pro neplatnou částku, chybějící hodnotu nebo řádek; 409 pro konflikt
vazeb/pravidel a idempotence; 412 pro starý ETag; 428 pro chybějící If-Match;
503 pro nedostupnou DB či přerušenou transakci. Cizí vazba se odmítne bez
vrácení detailů cizí jednotky. Přesné mapování názvů DB omezení bude v API testu.

Seznam: page, itemsPerPage (výchozí 20, max. 100), search v textu,
periodId a datum od/do s kontrolou platnosti a pořadí. Stabilní řazení
postingDate DESC, id DESC. Neznámé parametry se odmítají. Historie podporuje
jen stránkování a vrací hlavičku i události řádků se společným request ID.
Nevydáváme jeden zápis hlavičky za jedinou auditní událost celé změny:
reálná změna řádků může vytvořit více auditních událostí v jedné transakci.

## 3. Databáze a ochrana celého dokladu

Nová migrace v `backend/migrations` a odpovídající SQL. Již spuštěnou
`Version20261103010000` ani předchozí migrace nepřepisovat. Číslo nové migrace
určit při programování podle aktuálního stavu repozitáře.

Plánované tabulky:

- `ucto.documents`: jednotka, UUID, period_id, posting_date, text, partner_id,
  status=draft, currency=CZK, verze a časy. Součty vypočítá server z řádků.
- `ucto.document_lines`: jednotka, UUID, document_id, line_no, account_id,
  text, md, dal. Jedinečné pořadí řádku v dokladu.
- `ucto.document_create_requests`: idempotentní klíč, uživatel, jednotka,
  operace, normalizovaný hash, vazba na doklad, původní odpověď a expirace.

Vazby dokument→období, dokument→partner, řádek→doklad a řádek→účet musí
ověřovat také jednotku složeným cizím klíčem. Hlavička i řádky se připojí
k `core.audit_attach`; každý auditní záznam ponese jednotku pro současnou RLS.

**Samotný ETag hlavičky neochrání přímý UPDATE řádku v DB.** Proto navrhujeme:

1. Aplikační role dostane na hlavičky a řádky SELECT s RLS, nikoli samostatný
   INSERT/UPDATE/DELETE. Roli `mikoucto_read` zůstane pouze kontextové čtení.
2. Zápis bude dostupný pouze přes úzce vymezenou funkci `ucto.save_draft`,
   která kontroluje práva z aktuálního kontextu, jednotku, verzi, vstup,
   vazby, datum, účty a atomicky změní hlavičku i řádky. Nevezme identitu
   autora jako důvěryhodný parametr klienta.
3. Pokud funkce použije SECURITY DEFINER, musí mít pevný bezpečný search_path,
   vlastníka `mikoucto_owner`, revokaci EXECUTE pro PUBLIC a explicitní
   kontrolu `core.can_access_unit` i konkrétního `.write` oprávnění.
   Nespoléhat na RLS vlastníka, který ji může obcházet; ve všech dotazech
   explicitně omezit jednotku. Žádné obecné dynamické SQL nebo jméno tabulky
   z požadavku. Pomocné funkce nedávat aplikaci samostatně ke spuštění.
4. Funkce je součástí transakce IdentitySession. Jediná chyba musí vrátit
   zpět hlavičku, řádky, zvýšení verze, audit i evidenci idempotence.
5. Vytvoření přes API uloží klíč a původní odpověď v téže transakci. Zámek
   klíče a jeho rozsah zůstanou jako v 7.1: jednotka, uživatel, operace,
   24 hodin, stejné normalizované tělo→stejná odpověď; jiné→409.

Důvěryhodný migrační vlastník zůstává správcem. Test přímého zápisu používá
neprivilegovanou `mikoucto_app`, nikoli obcházení pravidel rolí vlastníka.

## 4. Souběh a pravidla změn použitých objektů

Pevné pořadí zámků pro zápis návrhu:

1. Stávající advisory zámek `ucto.accounts:<unit>` (stejný jako správa účtů).
2. U POST zámek idempotentního klíče; u PATCH ověření formátu ETag.
3. Řádky původního a nového období v deterministickém pořadí podle UUID.
4. Hlavička existujícího dokladu, porovnání očekávané verze a nové načtení
   aktuálního celku. Je-li při získávání zámků doklad změněný, vrátit 412.
5. Validace celého výsledku, zápis a společný commit.

Databázová funkce musí používat stejné pořadí i při přímém volání. Izolace
READ COMMITTED a čerstvé čtení po čekání navazují na ochranu účtů v 7.1.
Timeout/deadlock znamená rollback, žádné hlášení úspěchu; opakování celé
operace respektuje původní idempotentní klíč či nové načtení ETag.

Nová migrace současně doplní ochrany pro změny dosavadních objektů:

- Kód účtu se nesmí změnit, pokud na účet aktuálně odkazuje řádek dokladu.
  Název lze měnit. Deaktivace nebo odebrání účtovatelnosti se dovolí, ale
  další ukládání návrhu vyžaduje opravu jeho řádků podle pravidel výše.
- Hranice období s dokladem se nesmějí měnit, jeho název ano. Období se
  zamyká také při ukládání dokumentu; nestačí předběžný SELECT v API.
- Odstranění vazeb chrání FK. Partnera lze nadále deaktivovat. Jednotku
  partnera, účtu ani období nemůže aplikace přepsat.

Ochrany musí fungovat i při souběhu změny účtu/období a uložení návrhu.
Při programování ověřit viditelnost nových dat v triggerech po čekání,
interakci s již existujícími zámky a také skutečný rollback po deadlocku.
7.3 nad tím zavede zaúčtování a uzavírání, nebude je simulovat v 7.2.

## 5. Pořadí programování a konkrétní soubory

| Krok | Výstup v projektu | Podmínka pokračování |
| --- | --- | --- |
| A | Jednotkové testy a pomocník desetinných částek v `Modules/Accounting/Application` | Přesná validace, normalizace a součty včetně velkých hodnot bez float |
| B | Nová migrace/SQL, zapisovací funkce, RLS, audit a ochrana použitých účtů/období | Přímý DB zápis a volání funkce nemohou obejít práva, verzi ani vazby |
| C | `Document`, vstupní DTO a historie v `Accounting/ApiResource`; samostatná služba/repository/providers/processors | Vytvoření, detail a změna přes celý Symfony request projdou atomicky |
| D | OpenAPI, chybová mapování a testy kontraktu | Popsané vstupy odpovídají skutečnosti a stávající API dál funguje |
| E | Fixture, integrační a souběžné testy, PowerShell příprava/ověření | Nová sada skutečně proběhne, prázdný či přeskočený test není úspěch |
| F | Dokumentace, zaznamenané výsledky a předání | Jasně odlišený testovací stav od nasazení do playground |

Konkrétní návaznosti, které nelze přeskočit:

- `AccountingService::kind()` a další části 7.1 rozlišují jen Account/Period.
  Nový dokument nepřipojit tak, že omylem spadne do větve `periods`.
  Pro zápis celku použít samostatnou dokumentovou službu.
- `tools/ucto/03-openapi.ps1` dnes filtruje jen accounts/periods a požaduje
  šest cest. Doplnit documents a kontrolu devíti cest i povinných operací.
- Zachovat partnerský export a ověřit generování frontendových typů nad
  rozšířeným OpenAPI. Webový formulář dokladu se tím ještě nevytváří.
- `02-osnova-obdobi-test.ps1` vyžaduje jen dosavadní účetní testy. Pro novou
  sadu kontrolovat také `DraftDocumentsTest` a `DraftConcurrencyTest`.
  Společný běh testů lze oddělit do interního helperu, aby nové ověření
  nespouštělo celou DB regresi zbytečně dvakrát.

## 6. Automatické scénáře a podmínky dokončení

Nové podpůrné soubory: `DraftFixture` se dvěma jednotkami, obdobími, aktivními
účty, neúčtovatelným/neaktivním účtem, partnerem a všemi rolemi. Využít
`TestDatabase`, `TestTokens` a ověřený mechanismus subprocessů z 7.1.
Nepředávat hesla ani tokeny v argumentech procesu či veřejných logách.
Testovací data a procesy uklidit i při neúspěchu, audit se nemaže.

| Scénář | Co musí test prokázat |
| --- | --- |
| Prázdný a jednořádkový návrh | Uložit, načíst, později doplnit; draft není posted |
| MD 100.00, DAL 99.00 | Uložit nevyrovnaný draft a vrátit rozdíl 1.00 |
| Změna DAL na 100.00 | Verze se zvýší jednou, balanced=true, bez zaúčtování |
| Částky 0.10 + 0.20 a maximální částky | Přesné součty i nad rozsahem jednoho řádku, žádný float/přetečení |
| Záporné, tři desetinná místa, číslo místo řetězce, obě/nulové strany | Odmítnutí bez zaokrouhlení a bez částečného zápisu |
| Příliš mnoho řádků a chybný poslední řádek | Odmítnout celý celek; nevznikne ani hlavička nebo její audit |
| Cizí účet, partner nebo období | Odmítnout přes HTTP i zapisovací DB funkci |
| Neaktivní/neúčtovatelný účet, datum mimo období | Odmítnout; oprava odebráním neplatného řádku funguje |
| Chybějící pole PATCH, partnerId:null, lines:[] | Zachování hlavičky / zrušení partnera / skutečné odebrání řádků |
| Bezezměnový PATCH | Stejná verze, čas, ID řádků a počet auditních událostí |
| Dva PATCH se stejným ETag | Jeden uspěje, druhý nepřepíše první; kontrola také při současném běhu |
| Opakování POST a dva souběžné POST | Jeden doklad, původní odpověď, žádný duplicitní audit |
| Jiná data se stejným klíčem a expirace | Chování podle 7.1; po expiraci může vzniknout nový návrh |
| Reader/auditor zapisuje, accountant čte historii | Odmítnout; RLS bez kontextu a cizí jednotka nepropouštějí data |
| Přímý INSERT/UPDATE/DELETE hlavičky nebo řádku aplikační rolí | Zamítnout; změny smí jen přes chráněnou funkci s oprávněním a verzí |
| Změna kódu použitého účtu, hranic použitého období | Odmítnout i přes přímé SQL a při souběhu se založením dokladu |
| Deaktivace účtu současně s uložením | Konzistentní výsledek podle pořadí potvrzení; žádné obejití kontroly |
| Chyba po změně hlavičky během výměny řádků | Rollback hlavičky, verze, řádků, auditu i klíče |
| Opakovaná migrace a dosavadní regrese | Žádná ztráta dat, 7.1 i partneři nadále funkční |

Souběh ověřit dvěma nezávislými DB relacemi/procesy, bariérou a doloženým
čekáním na zámek, nikoli dvěma postupnými požadavky. Historie musí seskupit
události aktuálních i dříve nahrazených řádků pod správný doklad a jednotku.

## 7. Dodané příkazy pro obsluhu

Následující skripty jsou dodané; podrobný aktuální postup obsahuje návod dokladů.

```powershell
# Okno 1: existujici tunel, pouze pokud jeste nebezi.
.\db\google\00-tunel.ps1
```

```powershell
# Okno 2: dodane prikazy z korene projektu.
.\tools\ucto\04-doklady-priprava.ps1 -Target Test
.\tools\ucto\05-doklady-test.ps1
```

Nastavení bude mít výchozí cíl Test. Testovací skript přijme pouze
`mikoucto_test`, zkontroluje všechny nové povinné třídy v reportu, provede
jednotkové a integrační testy včetně dosavadní regrese a aktualizuje export.
Tunel je potřeba, běžící web/backend ani živé Entra přihlášení nikoli.
Závěr začíná `Cast 7.2 OK`; výsledky skutečného běhu zaznamenává návod dokladů.

Předání musí obsahovat nové soubory, kontrakt, příkazy, skutečné výsledky,
verzi migrace a známá omezení. Část 7.2 je dokončená na testovací DB teprve
po úspěchu nových i dosavadních testů; nikoli pouhým `Up-to-date`.

Migrace `playground`, ověření zálohy/obnovy a živé použití jsou oddělené
kroky. Web dokladů a jejich zaúčtování nepovažujeme za hotové úspěchem API
sady 7.2. Po této části naváže 7.3 nad skutečně dodaným schématem.
