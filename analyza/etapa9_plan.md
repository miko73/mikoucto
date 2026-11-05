# Plán etapy 9 – opravné a stornovací doklady

Stav: **části 9.1 a 9.2 jsou implementované a automaticky ověřené dne
5. 11. 2026**, části 9.3–9.4 jsou plánované. Výsledky a opakování testů
uvádějí navazující návody.
Aktuální kontrakt a příkazy uvádí [návod stornovacího API](storno_api.md).
Navazující návrh popisuje [návod opravného API](opravny_navrh_api.md).
Navazuje na dokončenou
[etapu 8](etapa8_plan.md); její živé ověření uživatel potvrdil dne 4. 11. 2026.
Plán níže zachovává zadání etapy; stav ověření je uveden v navazujícím návodu.

## 1. Co bude možné udělat

Zaúčtovaný doklad zůstane neměnný. Volba **Stornovat** vytvoří nový doklad,
který přesně obrátí původní řádky záměnou MD a DAL a rovnou se zaúčtuje.
Obsluha vybere otevřené období, datum a napíše důvod. Oba doklady budou mít
vlastní číslo a vzájemný odkaz. Původní doklad zůstane ve stavu „zaúčtovaný“.

Volba **Připravit opravný návrh** následně nabídne kopii původních hodnot,
kterou obsluha upraví a výslovně zaúčtuje obvyklým postupem. Samotné
uložení tohoto návrhu ještě žádný nový účetní pohyb nevytvoří.
Pro úplné zrušení původního zápisu postačí stornovací protizápis.

První verze zachová CZK a kladné částky právě na jedné straně řádku.
Záporné částky na původní straně („červené storno“), automatické převody
zůstatků mezi obdobími, DPH a opravy již opravných dokladů do ní nepatří.
Jde o vymezení chování aplikace, nikoli o rozšíření jejího dosavadního účetního rozsahu.

## 2. Pravidla společná pro API, databázi a web

- Zdroj musí být zaúčtovaný běžný doklad ve stejné účetní jednotce.
  Rozpracovaný návrh se opravuje přímo. Stornovací ani opravný doklad nelze
  v této verzi použít jako další zdroj opravy.
- K jednomu zdroji může vzniknout nejvýše jeden stornovací protizápis.
  Toto omezení platí trvale, také po vypršení klíče opakování požadavku.
- Zdrojové období smí být uzavřené. Nový zápis musí mít datum v cílovém
  otevřeném období, nejdříve k datu původního dokladu. Uzavřené období se neotevírá.
- Kopírují se účty, partner, texty, pořadí a přesné desetinné částky.
  Každý nový řádek dostane nové ID a prohozené MD/DAL. Počítá se bez `float`.
- Účty musí být při novém zaúčtování aktivní a účtovatelné. Nesplnění
  této podmínky operaci odmítne; funkce sama nemění nastavení účtů.
- Důvod je povinný, po oříznutí má 1–1000 znaků. Autor a čas pocházejí ze serveru.
- Vytvoření nového dokladu, zaúčtování, přidělení čísla, vazba, audit a záznam
  opakování tvoří jednu transakci. Při chybě se vše vrátí včetně čítače čísel.
- Původní řádky, číslo, stav, verze a ETag se nemění.

### Dopad do sestav

Protizápis ruší čistý pohyb; obraty zůstávají viditelné. Příklad ve stejném období:

| Doklad | Účet 211 | Účet 221 |
| --- | --- | --- |
| Původní | MD 100,00 | DAL 100,00 |
| Stornovací | DAL 100,00 | MD 100,00 |
| Opravný, po zaúčtování | MD 80,00 | DAL 80,00 |

Po samotném stornu mají oba účty čistý pohyb nula; součet všech MD je 200,00
a DAL také 200,00. Po zaúčtování opravy má 211 MD 180,00 / DAL 100,00,
221 MD 100,00 / DAL 180,00; celkové MD i DAL jsou 280,00.
Původní záznamy se ze sestav ani CSV neodfiltrují.

Při původním dokladu v lednu a stornu s opravou v únoru zůstane leden
beze změny. Únor sám ukáže pohyb −20,00 na 211 a +20,00 na 221.
Aplikace dosud automaticky nepřenáší počáteční stavy; únorový výsledek proto
nelze vydávat za celkový zůstatek 80,00.

## 3. Část 9.1 – stornovací protizápis a JSON API

### Databáze a oprávnění

Přidat novou migraci; staré migrace se neupravují. Tabulka
`ucto.document_reversals` bude obsahovat vlastní UUID, jednotku, původní
a stornovací doklad, důvod, autora a čas. Obě vazby použijí složené cizí
klíče s jednotkou. Unikátní omezení nad původním i stornovacím dokladem
zabrání dvojímu stornu a opakovanému použití protizápisu.

Databázový příkaz ověří typ zdroje, jeho verzi, přesné obrácení řádků,
období i oprávnění. Vazbu nebude možné měnit nebo mazat aplikační rolí.
Použije se stávající audit, RLS a zápis přes `SECURITY DEFINER` s bezpečným
`search_path`. Přímý zápis aplikační role do účetních tabulek zůstane zakázaný.
Úklid izolovaných testovacích dat musí respektovat stávající pravidla vlastníka.

Nové oprávnění `core.ucto.documents.reverse` získá administrátor a účetní.
API i SQL současně vyžadují stávající práva pro čtení, vytvoření a zaúčtování
dokladu a přístup k jednotce. Čtenář a auditor mohou pouze prohlížet vazby.

### Navržený kontrakt

`POST /api/v1/accounting-units/{unit}/documents/{id}/reverse`

```json
{
  "periodId": "UUID otevřeného cílového období",
  "postingDate": "2027-02-05",
  "reason": "Původní částka 100 Kč byla chybná; správně je 80 Kč."
}
```

Vyžadovat `If-Match` původního dokladu a `Idempotency-Key`. Jiná vstupní
pole odmítnout. První úspěch vrátí 201, nový zaúčtovaný doklad, jeho ETag
a `Location`. ETag v odpovědi patří novému dokladu; vstupní ETag patří zdroji.
Neplatné hodnoty vracejí 422, zastaralá verze 412, konflikt stavu 409,
chybějící oprávnění 403 a nedostupný doklad 404 podle stávajících pravidel API.

Samostatné `GET /api/v1/accounting-units/{unit}/documents/{id}/relations`
vrátí vazbu `reversal` nebo `reverses`, číslo a ID souvisejícího dokladu,
datum, období, důvod a autora. Později přibude opravný návrh. Odpověď bude
bez ukládání do mezipaměti. Dosavadní tvar dokladu i jeho ETag se zachovají;
staré uložené odpovědi opakovaných požadavků musí zůstat čitelné.
Historie obou dokladů zobrazí vznik vazby jako samostatnou událost.

### Opakování a souběh

Nová tabulka požadavků uchová původní odpověď 24 hodin pro jednotku,
uživatele, operaci a klíč. Otisk zahrne zdroj, verzi a normalizované hodnoty.
Stejný klíč se stejnými hodnotami vrátí uloženou odpověď včetně původního
statusu; s jinými hodnotami vrátí 409. Oprávnění se ověřují i před opakováním.
Zdroj požadavku a ID vráceného dokladu jsou různá ID; staré schéma požadavků
se proto nesmí slepě zkopírovat.

Trvalá vazba chrání i opakování s novým klíčem nebo jiným uživatelem:
stejný požadovaný výsledek vrátí existující protizápis se statusem 200,
jiné hodnoty vrátí 409 s odkazem na již existující storno. Nevznikne nové
číslo ani další audit zaúčtování. Web umí po ztrátě odpovědi načíst vazby.

Zamykat ve stávajícím pořadí: účetní jednotka, klíč požadavku, dotčená
období v pořadí UUID, doklady v pořadí UUID. Zachovat `READ COMMITTED`
a ochranu souběhu s uzavřením období i deaktivací účtu. Při uzavření období
jako prvním nový příkaz selže bez zápisu; při dokončení storna jako prvního
lze období následně uzavřít, protože nevznikl rozpracovaný návrh.

### Automatické ověření 9.1

1. Jednotkové testy normalizace, odmítnutí cizích polí a přesného převrácení
   částek včetně nejvyšší podporované hodnoty.
2. Skutečná DB a HTTP: role, jiné jednotky, otevřené a uzavřené období,
   datum před zdrojem, neaktivní účty, neměnnost původního dokladu a ETag,
   nové číslo, vazby, audit a zákaz dalšího storna.
3. Stejný i jiný klíč, jiný uživatel, vypršení uložené odpovědi a konflikt
   obsahu; vždy nejvýše jeden protizápis a jedno přidělené číslo.
4. Vynucená pozdní chyba v testovací DB vrátí doklad, řádky, číslo, vazbu,
   audit i požadavek. Testovací mechanismus nebude součástí běžné aplikace.
5. Dva nezávislé procesy: dvojí storno, storno proti uzavření období
   a deaktivaci účtu. Čekání prokázat databázovou blokací, nikoli délkou spánku.
6. JSON i CSV sestavy musí odpovídat příkladu výše, také při opravě v jiném
   období. Spustit celou stávající backendovou sadu a ověřit OpenAPI i webové typy.

Připravit skripty `tools/ucto/16-storno-priprava.ps1` a
`tools/ucto/17-storno-test.ps1`. Příprava standardně míří pouze na testovací
databázi. Předání 9.1 zahrnuje jejich skutečný běh a zaznamenané výsledky.

## 4. Část 9.2 – navazující opravný návrh

Přidat příkaz pro založení opravného návrhu po úspěšném stornu. Vyžaduje
otevřené cílové období a právo vytvořit doklad. Kopie původních hodnot
je pouze výchozí obsah; obsluha může upravit účty, částky, text i partnera.
Vazba označí zdroj, protizápis a opravný návrh. K jednomu původnímu dokladu
lze založit nejvýše jeden takto propojený návrh.

Vytvoření návrhu a vazby musí být atomické a bezpečně opakovatelné.
Zaúčtování nadále probíhá samostatným existujícím příkazem s jeho validacemi.
Nevyrovnaný návrh se uloží, ale nezaúčtuje a blokuje uzavření svého období.
Pouhé uložení ani neúspěšné zaúčtování nesmějí měnit sestavy.
Obyčejné návrhy i příkazy musejí odmítat podvržená pole vazeb.

Před realizací této části doplnit konkrétní DTO, SQL funkci, tabulku vazby
a testovací skript podle ověřených rozhraní 9.1. Testy pokryjí duplicitní
založení, úpravu návrhu, audit, povolené zaúčtování a uzavření období.

### Konkrétní kontrakt 9.2

- `POST /api/v1/accounting-units/{unit}/documents/{id}/correction-draft`
  nad původním dokladem, s `If-Match` originálu a `Idempotency-Key`.
  DTO `CorrectionInput` obsahuje právě `periodId` a `postingDate`.
  Datum musí ležet v otevřeném cílovém období a nesmí předcházet originálu.
  Oprávnění: přístup k jednotce, `core.units.read`, čtení a zápis dokladů.
  Právo stornovat ani zaúčtovat se při založení návrhu nevyžaduje.
- SQL `ucto.create_correction_draft(uuid,uuid,integer,uuid,date)` vrátí UUID
  nového návrhu. Zkopíruje původní strany MD/DAL bez změny a bez čísla.
  Vyžaduje existující storno a běžný zaúčtovaný originál. Vše je jedna transakce.
- Nová migrace zavede auditovanou a neměnnou `ucto.document_corrections`
  se složenými FK na jednotku, původní storno a opravný doklad. Unikátní
  omezení chrání všechny tři doklady. Ukládá také původně požadované období
  a datum, aby opakování nebylo ovlivněné pozdější úpravou návrhu.
- První úspěch: 201, návrh, jeho ETag a Location. Stejný klíč a obsah
  po 24 hodin vrací původní odpověď. Nový klíč / uživatel / vypršení
  vrátí současný stav téhož opravného dokladu se statusem 200, pokud původně
  zadané období a datum souhlasí; jiné hodnoty vrátí 409 s Location.
  Trvalá vazba platí i po úpravě nebo zaúčtování opravy a uzavření období.
- GET relations rozšíří `correction` a `corrects`; z opravného dokladu bude
  dostupný i jeho protizápis. Číslo vazby je u návrhu null a vrací se také stav.
  Historie všech tří dokladů zahrne vložení vazby. Tvar Document se nemění.
  Opravný doklad nelze znovu stornovat ani použít jako zdroj další opravy.
- Skripty `18-opravny-navrh-priprava.ps1` a `19-opravny-navrh-test.ps1`
  pracují s `mikoucto_test`; ověří migraci, jednotkové testy, úplnou DB/HTTP
  regresi včetně souběhu, OpenAPI a webové typy.

## 5. Část 9.3 – webové obrazovky

V detailu zaúčtovaného dokladu nabídnout **Stornovat** oprávněné obsluze.
Dialog ukáže číslo zdroje, částky, cílové období, datum, důvod a výslovné
potvrzení nového zaúčtování. Po úspěchu otevře protizápis. U původního
dokladu zobrazí odkaz na storno a možnost připravit opravný návrh.
Vzájemné odkazy a důvody musí fungovat i v detailu otevřeném ze sestav.

Při nejistém výsledku uchovat klíč požadavku, zabránit dalšímu odeslání
s jinými hodnotami a nabídnout opakování nebo načtení existující vazby.
Ošetřit ztrátu oprávnění, změnu jednotky, zastaralou verzi a uzavření období.
Prohlížečové testy pokryjí desktop i mobil, čtenáře, dvojí kliknutí,
ztracenou odpověď, odkazy a navazující opravný návrh. Spustit celý webový balík.

## 6. Část 9.4 – nasazení a živá přejímka

Migraci nejprve ověřit nad `mikoucto_test` a obnovovanou kopií. Pro změnu
vývojové databáze použít [existující postup zálohy, obnovy a nasazení](vyvoj_nasazeni.md)
včetně skriptů 09–12 a **nové zálohy aktuálních dat**. Starší záloha před
živým ověřením není podkladem pro nové nasazení. Prověřit zachování původních
řádků, auditů a nových oprávnění při migrační zkoušce i nasazení.

Při dokončení webu dodat samostatný návod pro laiky s hodnotou každého pole,
každým kliknutím a očekávaným výsledkem. Použít UCTO_DEV a nová oddělená
období, například leden a únor 2027; neměnit doklady již ověřené v etapách 7–8.
Scénář zahrne původní doklad 100,00, uzavření jeho období, protizápis
v následujícím období, opravný návrh 80,00, jeho zaúčtování, historii,
sestavy a CSV. Očekávané výsledky uvést pro každé období samostatně.

Etapa je hotová po dokončení všech čtyř částí, úspěšných automatických
kontrolách a zvlášť zaznamenaném potvrzení živého průchodu obsluhou.
První následující implementační krok: **9.3 – webové obrazovky**.
