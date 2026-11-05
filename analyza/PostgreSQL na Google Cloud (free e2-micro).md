# PostgreSQL na Google Cloud (free e2-micro)

Nov 1, 2026 · @Michal

PostgreSQL 16 běží na bezplatné VM e2-micro v Google Cloud a připojuji se k němu z Windows přes SSH tunel na `localhost:5432`.

| Položka | Hodnota |
| --- | --- |
| Google účet | michal.kocandrle@gmail.com |
| Projekt (ID / název / číslo) | `pg-free-michal` / mikoucto / 565891031127 |
| VM | `pg-free`, e2-micro, zóna `us-central1-a` |
| Disk | 30 GB Standard persistent disk (pd-standard) |
| OS | Ubuntu 24.04 LTS |
| PostgreSQL | 16.15, DB `playground`, uživatel `michal` |
| Linux uživatel na VM | `michal_kocandrle` |
| Připojení | SSH tunel → `localhost:5432`, port 5432 není otevřený do internetu |

## Krok 1: Instalace gcloud a wrapper

gcloud se nainstaloval přes winget, ale nepřidal se do PATH, proto se všechny příkazy spouštějí přes skript `gcloud.ps1` ve složce `C:\Users\KočandrleMichal`.

1. Instalace (v PowerShellu):

   ```powershell
   winget install Google.CloudSDK
   ```

   Bez admin práv lze místo toho použít instalátor v režimu Single User nebo ZIP archiv s přibaleným Pythonem.
2. Ulož skript `gcloud.ps1`, který najde `gcloud.cmd` v instalačních cestách a předá mu všechny parametry:

   ```powershell
   $candidates = @(
       "$env:LOCALAPPDATA\Google\Cloud SDK\google-cloud-sdk\bin\gcloud.cmd",
       "${env:ProgramFiles(x86)}\Google\Cloud SDK\google-cloud-sdk\bin\gcloud.cmd",
       "$env:ProgramFiles\Google\Cloud SDK\google-cloud-sdk\bin\gcloud.cmd",
       "$env:USERPROFILE\tools\google-cloud-sdk\bin\gcloud.cmd"
   )
   if ($env:GCLOUD_PATH) { $candidates = @($env:GCLOUD_PATH) + $candidates }
   $gcloud = $candidates | Where-Object { $_ -and (Test-Path $_) } | Select-Object -First 1
   if (-not $gcloud) { Write-Error "gcloud.cmd nenalezen"; exit 1 }
   & $gcloud @args
   exit $LASTEXITCODE
   ```
3. Pokud PowerShell skript zablokuje, povol skripty pro svůj účet:

   ```powershell
   Set-ExecutionPolicy -Scope CurrentUser RemoteSigned
   ```

Příkazy Google Cloud v tomto návodu používají wrapper `./gcloud.ps1` ze složky uživatele. Z jiné složky ho lze spustit jako `& "$env:USERPROFILE\gcloud.ps1"`. Projektové skripty v kroku 6 se spouštějí z kořene repozitáře `mikoucto`.

## Krok 2: Přihlášení, projekt a billing

Projekt se nastavuje podle **PROJECT\_ID** (`pg-free-michal`) nebo čísla projektu, nikdy podle názvu (`mikoucto`).

1. Přihlášení soukromým Google účtem (otevře prohlížeč):

   ```powershell
   ./gcloud.ps1 auth login
   ```
2. Založení projektu (ID musí být celosvětově unikátní):

   ```powershell
   ./gcloud.ps1 projects create pg-free-michal --name="mikoucto"
   ```
3. Nastavení jako výchozího projektu:

   ```powershell
   ./gcloud.ps1 config set project pg-free-michal
   ```
4. Připojení billing účtu, pokud ho projekt ještě nemá (jde i v konzoli: Billing → Account management → My projects):

   ```powershell
   ./gcloud.ps1 billing accounts list
   ./gcloud.ps1 billing projects link pg-free-michal --billing-account=<ACCOUNT_ID>
   ```
5. V konzoli nastav rozpočet s upozorněním: Billing → Budgets & alerts, např. 5 €.

## Krok 3: Vytvoření VM

VM musí být e2-micro v regionu us-central1, us-west1 nebo us-east1 se Standard diskem do 30 GB, jinak nespadá do Always Free.

1. Vytvoření VM (celý příkaz na jednom řádku, PowerShell nezná bashové `\` na konci řádku):

   ```powershell
   ./gcloud.ps1 compute instances create pg-free --zone=us-central1-a --machine-type=e2-micro --image-family=ubuntu-2404-lts-amd64 --image-project=ubuntu-os-cloud --boot-disk-type=pd-standard --boot-disk-size=30GB
   ```
2. Při prvním spuštění se gcloud zeptá na povolení Compute API, odpověz `y`. Pokud spadne s chybou `Retry`, spusť příkaz znovu, API se mezitím dopovolí.
3. Varování o disku pod 200 GB a o disku větším než image jsou neškodná, Ubuntu si oddíl zvětší samo.

## Krok 4: Instalace PostgreSQL na VM

Postgres 16 z Ubuntu repozitáře, swap 1 GB kvůli malé RAM a nastavení paměti pro 1 GB stroj.

1. Připojení na VM (první spuštění vygeneruje SSH klíč; na dotaz PuTTY „Store key in cache?“ odpověz `y`):

   ```powershell
   ./gcloud.ps1 compute ssh pg-free --zone=us-central1-a
   ```
2. Na VM: instalace Postgresu a swapu:

   ```bash
   sudo apt update && sudo apt install -y postgresql
   sudo fallocate -l 1G /swapfile && sudo chmod 600 /swapfile
   sudo mkswap /swapfile && sudo swapon /swapfile
   echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
   ```
3. Uživatel a databáze (heslo se zadá interaktivně, nezůstane v historii):

   ```bash
   sudo -u postgres createuser michal --pwprompt
   sudo -u postgres createdb playground -O michal
   ```
4. Ověření:

   ```bash
   psql -h localhost -U michal -d playground -c "select current_user, version();"
   ```
5. Nastavení paměti, každý `ALTER SYSTEM` jako samostatné `-c`, pak restart:

   ```bash
   sudo -u postgres psql -c "ALTER SYSTEM SET shared_buffers='128MB';" -c "ALTER SYSTEM SET work_mem='4MB';" -c "ALTER SYSTEM SET max_connections=20;"
   sudo systemctl restart postgresql
   sudo -u postgres psql -c "SHOW max_connections;" -c "SHOW work_mem;"
   ```

## Krok 5: SSH tunel a připojení klienta

Tunel přesměruje `localhost:5432` na Windows na Postgres ve VM; okno s tunelem musí zůstat otevřené.

1. Spuštění tunelu (SSH volby přes `--ssh-flag`, protože PowerShell spolkne samotné `--`):

   ```powershell
   ./gcloud.ps1 compute ssh pg-free --zone=us-central1-a --ssh-flag="-L 5432:localhost:5432" --ssh-flag="-N"
   ```

   Poslední řádek zůstane `Waiting for SSH key to propagate.` a dál se nic nevypíše. To je správně, tunel běží.
2. Kontrola z druhého okna PowerShellu, očekáváno `TcpTestSucceeded : True`:

   ```powershell
   Test-NetConnection localhost -Port 5432
   ```
3. Připojení klientem (DBeaver, pgAdmin, psql): host `localhost`, port `5432`, databáze `playground`, uživatel `michal`, SSL není potřeba.
4. Ověření, že jsi na VM: `select version();` vrátí `16.15 (Ubuntu ...)`.

## Krok 6: Test připojení a inicializace mikoucto

Okno s SSH tunelem z kroku 5 ponech otevřené po celou dobu testu i instalace. Následující projektové příkazy spouštěj v druhém PowerShellu z kořene repozitáře:

```powershell
cd "$env:USERPROFILE\Projects\mikoucto"
```

### 6.1 Test běžného účtu michal

```powershell
.\db\google\01-test-pripojeni.ps1
```

Zadej heslo databázového účtu `michal`, které vzniklo v kroku 4. Heslo se zadává skrytě a neukládá se. Test vypíše databázi, přihlášený účet, verzi serveru a oprávnění; při úspěchu skončí zprávou `Pripojeni OK. Test nemenil data ani schema.`

Tento test už úspěšně prošel. Úspěch účtu `michal` ovšem nepotvrzuje přihlášení administrátora `postgres`: jde o dva samostatné databázové účty s vlastními hesly. Účtu `postgres` jsme v kroku 4 heslo pro připojení přes TCP ještě nenastavili.

### 6.2 Nastavení hesla administrátora postgres

Instalátor vytváří projektové role a mění vlastníka databáze, proto používá administrátora `postgres`. Běžnému účtu `michal` kvůli instalaci nezvyšujeme oprávnění.

1. V dalším okně PowerShellu otevři běžnou SSH relaci na VM (okno s tunelem nech běžet):

   ```powershell
   & "$env:USERPROFILE\gcloud.ps1" compute ssh michal_kocandrle@pg-free --project=pg-free-michal --zone=us-central1-a
   ```

2. Na Linux VM otevři konzoli PostgreSQL pod systémovým účtem `postgres`:

   ```bash
   sudo -u postgres psql
   ```

3. V konzoli PostgreSQL spusť:

   ```text
   \password postgres
   ```

   Dvakrát zadej nové heslo administrátora. Heslo nepiš do příkazu ani do dokumentace. Heslo účtu `michal` se tím nezmění.

4. Ukonči konzoli PostgreSQL:

   ```text
   \q
   ```

5. Ve Windows, z kořene repozitáře, otestuj nové heslo přes tunel:

   ```powershell
   .\db\google\01-test-pripojeni.ps1 -User postgres
   ```

### 6.3 Instalace připraveného schématu

Po úspěšném testu administrátora spusť ve Windows:

```powershell
.\db\google\02-inicializace-db.ps1
```

Na výzvu `Heslo PostgreSQL pro postgres` zadej heslo nastavené v kroku 6.2.

Skript v existující databázi `playground`:

- provede SQL `010_role_a_schemata.sql`, `020_rozsireni.sql` a `030_audit.sql` v jedné transakci;
- vytvoří role `mikoucto_owner`, `mikoucto_app`, `mikoucto_read`, schémata `core` a `ext`, rozšíření `pgcrypto` a auditní infrastrukturu;
- změní vlastníka databáze na `mikoucto_owner` a nastaví připravená oprávnění; účtu `michal` ponechá připojení a dočasné tabulky;
- po potvrzení instalace spustí `090_kontrola.sql`, který ověří oprávnění a audit a své testovací změny vrátí zpět.

Úspěšný výsledek obsahuje `Kontrola OK` a `Instalace a kontrola OK`. Pokud selže následná kontrola, instalované schéma už v databázi zůstává. Aplikační účetní tabulky zatím připravené SQL neobsahují.

Hesla projektových rolí se generují automaticky a ukládají šifrovaně pomocí Windows DPAPI do `db\google\.secrets\playground.roles.clixml`. Soubor je v `.gitignore`; opakovaná instalace použije stejná hesla. Soubor nemaž, protože při příštím spuštění by vznikla nová hesla rolí.

Podrobnosti jsou v [návodu projektových skriptů](../db/google/README.md). Původní inicializace narazila na nesprávné heslo účtu `postgres`. Databázový základ byl následně ověřen: schémata `core` a `ext`, `pgcrypto`, audit, oprávnění a přihlášení všech tří projektových rolí jsou dostupné. Prošla také HTTP kontrola připojení nového backendu jako `mikoucto_app`; další vývojové kroky jsou v [hlavním README](../README.md).

## Vypínání a startování VM

Zastavená VM se neúčtuje za výpočet a uvolní se jí veřejná IP; zůstává jen disk, který se do 30 GB Standard vejde do free tieru. Data v Postgresu zůstávají na disku.

1. Zastavení:

   ```powershell
   ./gcloud.ps1 compute instances stop pg-free --zone=us-central1-a
   ```
2. Spuštění (Postgres naběhne sám, je povolený v systemd):

   ```powershell
   ./gcloud.ps1 compute instances start pg-free --zone=us-central1-a
   ```
3. Stav (`RUNNING` / `TERMINATED` = zastaveno):

   ```powershell
   ./gcloud.ps1 compute instances list
   ```
4. Po startu znovu spusť tunel z kroku 5. Veřejná IP se může změnit, gcloud se ale připojuje podle názvu VM, takže to nevadí.

   ```powershell
   ./gcloud.ps1 compute ssh pg-free --zone=us-central1-a --ssh-flag="-L 5432:localhost:5432" --ssh-flag="-N"
   ```

| Položka | VM běží | VM zastavená |
| --- | --- | --- |
| Výpočet e2-micro | zdarma v rámci free tieru (1 VM po celý měsíc) | nic |
| Disk 30 GB Standard | zdarma (limit 30 GB) | zdarma (limit 30 GB) |
| Veřejná IP (ephemeral) | může se účtovat, ověř v Billing → Reports | uvolněna, nic |
| Odchozí data | zdarma do 1 GB měsíčně, pak za GB | nic |

Co neudělat, aby se nic neúčtovalo: nerezervovat statickou IP (zastavená VM s ní se platí), neměnit disk na SSD nebo Balanced, nezvětšovat ho nad 30 GB, nespouštět druhou e2-micro (limit je hodinový a sčítá se), nepřesouvat VM mimo US free regiony. Rozpočet s upozorněním z kroku 2 je pojistka.

## Úskalí, na která jsme narazili

| Chyba | Příčina | Řešení |
| --- | --- | --- |
| `gcloud : The term 'gcloud' is not recognized` | gcloud není v PATH | spouštět `./gcloud.ps1` |
| `Missing expression after unary operator '--'` | víceřádkový příkaz s `\` z bashe | v PowerShellu celý příkaz na jeden řádek |
| `You do not currently have an active account selected` | gcloud nepřihlášen | `./gcloud.ps1 auth login` |
| `The required property [project] is not currently set` | nenastavený projekt | `config set project pg-free-michal`, ne název `mikoucto` |
| `ERROR: ... Retry` při vytváření VM | Compute API se ještě povolovalo | spustit příkaz znovu |
| `-bash: !': event not found` | bash bere `!` v uvozovkách jako historii | `createuser --pwprompt` nebo jednoduché uvozovky |
| `ALTER SYSTEM cannot run inside a transaction block` | více příkazů v jednom `-c` | každý příkaz jako samostatné `-c` |
| `unrecognized arguments: -L ... -N` | PowerShell spolkne `--` | `--ssh-flag="..."` |
| `Invalid characters in local username [KočandrleMichal]` | „č“ ve Windows jménu | neškodné, použije se `michal_kocandrle` |
| Klíč „AzureAD\\KočandrleMichal@DIA-38641“ | jen komentář SSH klíče | neškodné |
| `Connection refused` na `localhost:5432` | SSH tunel neběží nebo skončil | spustit tunel podle kroku 5 a ponechat okno otevřené |
| `no password supplied` | klient nemá zadané databázové heslo | spustit test bez `-NoPasswordPrompt` a zadat heslo skrytě v konzoli |
| `password authentication failed for user "postgres"` při funkčním účtu `michal` | účty mají samostatná hesla; heslo `postgres` nebylo nastavené nebo bylo zadáno chybně | na VM nastavit `\password postgres` podle kroku 6.2 a použít nové heslo při inicializaci |
