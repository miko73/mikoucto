# PostgreSQL na Google Cloud přes SSH tunel

Používáme existující PostgreSQL 16 na VM `pg-free` v projektu `pg-free-michal`,
zóna `us-central1-a`. Cílová databáze je **`playground`**, běžné připojení
`michal@localhost:5432`. PostgreSQL SSL je vypnuté, přenos chrání SSH tunel.
Skripty nevytvářejí cloudové prostředky ani novou databázi.

Výchozí instalace serveru je popsaná v
[PostgreSQL na Google Cloud (free e2-micro)](../../analyza/PostgreSQL%20na%20Google%20Cloud%20%28free%20e2-micro%29.md).

## 1. SSH tunel

Z kořene repozitáře spusťte v samostatném PowerShellu:

```powershell
.\db\google\00-tunel.ps1
```

Okno ponechte otevřené. Skript používá již přihlášený Google Cloud SDK,
explicitní projekt i Linux účet z instalačního návodu. Pokud přihlášení chybí,
přihlaste se pomocí `gcloud auth login` podle původního návodu.
Při prvním spojení může PuTTY vyžadovat ověření a uložení hostitelského
SSH klíče. Tento krok proveďte interaktivně po ověření klíče serveru.

## 2. První test připojení

V druhém okně, opět z kořene repozitáře:

```powershell
.\db\google\01-test-pripojeni.ps1
```

Heslo účtu `michal` se zadává skrytě v konzoli a neukládá se.
Test ověří přihlášení, vypíše databázi, verzi serveru, vlastníka, kódování,
oprávnění účtu a stav PostgreSQL SSL. Používá transakci pouze pro čtení.
Úspěch končí zprávou `Pripojeni OK`.

Pro automatické spuštění lze nastavit `PGPASSWORD` v prostředí a použít
`-NoPasswordPrompt`; bez hesla se v tomto režimu použije standardní libpq
soubor `pgpass.conf`, pokud existuje. Hesla nevkládejte do příkazové historie.

## 3. Administrátor pro instalaci

Účet `michal` byl v původním návodu založen pomocí `createuser` bez
`CREATEROLE`. Vlastnictví databáze samo o sobě nestačí pro vytváření
aplikačních rolí. Instalační skript proto vyžaduje připojení superuživatele,
výchozí je `postgres`; oprávnění účtu `michal` nezvyšuje.

Pokud účet `postgres` ještě nemá heslo pro TCP připojení, otevřete SSH relaci
na VM podle původního návodu a přímo na VM spusťte:

```bash
sudo -u postgres psql
```

V konzoli psql nastavte heslo interaktivně:

```text
\password postgres
\q
```

Potom z Windows ověřte administrátorské připojení přes běžící tunel:

```powershell
.\db\google\01-test-pripojeni.ps1 -User postgres
```

## 4. Instalace připraveného schématu

```powershell
.\db\google\02-inicializace-db.ps1
```

Skript se skrytě zeptá na heslo `postgres`, ověří PostgreSQL 16+,
UTF-8 a administrátorská práva. Potom:

1. Spustí existující SQL `010`, `020`, `030` v jedné společné transakci.
2. Vytvoří role `mikoucto_owner`, `mikoucto_app`, `mikoucto_read`, schémata
   `core`, `ext`, rozšíření `pgcrypto` a auditní infrastrukturu.
3. Změní vlastníka `playground` na `mikoucto_owner` a upraví oprávnění dle
   připraveného SQL. Účtu `michal` ponechá připojení a dočasné tabulky;
   přístup k aplikačním objektům používejte přes aplikační role.
4. Spustí `090_kontrola.sql`: kontrolu auditu, oprávnění, partitions
   a kontrolních součtů. Tato kontrola vlastní testovací změny vrátí zpět.

Při chybě SQL během kroků `010`–`030` se celá instalační transakce vrátí.
Následná kontrola běží až po potvrzení instalace: pokud selže, schéma již
zůstává nainstalované a je potřeba vyřešit hlášenou chybu.

Instalace nemaže existující tabulky, ale mění databázová oprávnění a nastavuje
hesla tří projektových rolí, které jsou společné celému PostgreSQL serveru.
Je určená pro vyhrazenou vývojovou databázi. Aplikační účetní tabulky dosud
v připravených SQL nejsou; doplní je budoucí migrace backendu.

Hesla rolí se generují jednou a ukládají do ignorovaného
`.secrets/playground.roles.clixml` jako `SecureString` chráněný Windows DPAPI.
Opakované spuštění použije stejná hesla. Soubor lze dešifrovat pouze pod
stejným Windows účtem na stejném počítači. Soubor nemažte; jeho ztráta při
další instalaci způsobí vygenerování a nastavení nových hesel.

Pro pozdější konfiguraci klienta načtěte například aplikační heslo skrytě
do schránky správce hesel či klienta podle jeho možností. V PowerShellu lze
získat objekt přihlašovacích údajů bez vypsání hesla:

```powershell
$roles = Import-Clixml .\db\google\.secrets\playground.roles.clixml
$credential = New-Object System.Management.Automation.PSCredential('mikoucto_app', $roles['app_pwd'])
```

Připojení aplikace: host `localhost`, port `5432`, databáze `playground`,
uživatel `mikoucto_app`, SSL `disable`. Migrace používají `mikoucto_owner`,
reporty `mikoucto_read`. Hesla se nevypisují a neposílají v argumentech procesu.

Všechny skripty podporují jiný místní port pomocí `-Port`; test a instalace
také `-Database` a `-User`.

## Stav prvního pokusu

Pro další přípravu je dostupná také [etapa 3](../../analyza/implementace.md#etapa-3-migrace-a-testovací-db):
`tools/dev/05-test-db.ps1` připraví oddělenou `mikoucto_test`,
`06-migrace.ps1` provádí verzované migrace a `07-db-test.ps1` ověřuje
zápis, audit a rollback skutečnými databázovými rolemi. Globální role
a jejich hesla se při přípravě testovací DB nemění.

Následné ověření: SSH tunel a přihlášení všech tří projektových rolí nyní
fungují. Kontrolní skript `tools/dev/01-kontrola.ps1` ověřil schémata,
oprávnění a integritu auditu. Symfony backend se přes HTTP úspěšně připojil
jako `mikoucto_app`. Navazující kroky jsou v [hlavním README](../../README.md).
Níže je zachovaný popis původních potíží při prvním připojení.

První přímý pokus pomocí přenosného `psql` v tomto pracovním prostředí
skončil `Connection refused` na IPv4 i IPv6 `localhost:5432`.
Tunel v okamžiku testu nebyl dostupný. Přihlášení a instalace na vzdáleném
serveru tedy zatím nebyly ověřeny ani provedeny; je nutné spustit tunel
a zadat vlastní databázová hesla.

Google Cloud SDK má aktivní očekávaný účet. Dodatečný pokus o automatické
spuštění tunelu přes `plink -batch` skončil na neuloženém hostitelském
SSH klíči VM (`Cannot confirm a host key in batch mode`). Nezůstal spuštěný
žádný tunel z tohoto pokusu. Spusťte krok 1 interaktivně.
