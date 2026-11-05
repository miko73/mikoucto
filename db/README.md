# Databáze mikoucto

Aktuálně používáme **PostgreSQL 16 na Google Cloud VM**, databázi `playground`
přes SSH tunel na `localhost:5432`. Test připojení a instalace připraveného
schématu jsou v [db/google/README.md](google/README.md).

Níže zůstává původní postup pro Azure jako alternativní varianta.

## Původní instalace v Azure

Postup vytvoří vývojové prostředí databáze podle [návrhu technologického řešení](../analyza/techstack_navrh.md): Azure Database for PostgreSQL – Flexible Server (PostgreSQL 17, Burstable B1ms), databázi `mikoucto`, databázové role a auditní infrastrukturu. Aplikační tabulky do databáze přidají až migrace backendu.

## Obsah adresáře

| Soubor | Účel |
| --- | --- |
| `azure/config.example.ps1` | Vzor konfigurace; zkopírujte na `config.ps1` |
| `azure/common.ps1` | Společné funkce skriptů |
| `azure/00-nastroje.ps1` | Stáhne přenosné `psql`, `pg_dump`, `pg_restore` do `tools/pgsql` |
| `azure/01-server.ps1` | Skupina prostředků, server, parametry serveru, databáze |
| `azure/02-firewall.ps1` | Povolí IP tohoto počítače a otestuje port 5432 |
| `azure/03-inicializace-db.ps1` | Spustí SQL skripty, kontrolu a vytvoří připojovací řetězce |
| `azure/server.ps1` | Stav, spuštění a zastavení serveru |
| `azure/99-odstraneni.ps1` | Odstranění celého prostředí |
| `sql/010_role_a_schemata.sql` | Role `mikoucto_owner`, `mikoucto_app`, `mikoucto_read`, oprávnění, schéma `core` |
| `sql/020_rozsireni.sql` | Schéma `ext` a rozšíření `pgcrypto` |
| `sql/030_audit.sql` | Auditní log, triggery, partitions, řetězení kontrolních součtů |
| `sql/090_kontrola.sql` | Kontrola inicializace; nic v databázi neponechá |

Všechny skripty jsou idempotentní: lze je spustit opakovaně a existující prostředky přeskočí.

## Předpoklady

1. **Azure CLI** 2.60 nebo novější (`az version`). Bez práv správce lze použít [ZIP balíček](https://aka.ms/installazurecliwindowszipx64), který stačí rozbalit a přidat složku `bin` do proměnné `PATH`.
2. **Oprávnění v Azure:** role *Contributor* (Přispěvatel) na předplatném, nebo na předem vytvořené skupině prostředků. Pokud předplatné omezuje Azure Policy (povolené regiony, povinné štítky), upravte `Location` a `Tags` v konfiguraci.
3. **Volba předplatného:** projekt založte v předplatném, ze kterého se má hradit. Neukládejte ho automaticky do předplatného jiného projektu.
4. **Síť:** odchozí TCP 5432 na `*.postgres.database.azure.com`. Ověří ho skript `02-firewall.ps1`.
5. **Spouštění skriptů PowerShell** v aktuální relaci:

   ```powershell
   Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass
   ```

## Postup

Všechny příkazy spouštějte ve složce `db\azure`.

### 1. Konfigurace

```powershell
cd db\azure
Copy-Item config.example.ps1 config.ps1
notepad config.ps1
```

Vyplňte alespoň `Subscription`, ověřte `ServerName` (musí být globálně unikátní) a štítek `vlastnik`. Soubor `config.ps1` je v `.gitignore`.

### 2. Klientské nástroje

```powershell
.\00-nastroje.ps1
```

Stáhne cca 330 MB archiv PostgreSQL a rozbalí z něj jen `bin` do `tools\pgsql`. Pokud už `psql` máte v `PATH`, krok lze přeskočit.

S volbou `-SeServerem` rozbalí i `lib` a `share` pro lokální testovací server. Lokální **server** PostgreSQL ve Windows nelze spustit z cesty s diakritikou (např. `C:\Users\Kočandrle…`): `initdb` skončí chybou `invalid byte sequence for encoding "UTF8"`. Pro testovací server zkopírujte `tools\pgsql` do cesty bez diakritiky, např. `C:\pg\pgsql`. Klient `psql` diakritiku v cestě zvládá.

### 3. Server a databáze

```powershell
.\01-server.ps1
```

Skript:

- zaregistruje poskytovatele `Microsoft.DBforPostgreSQL` (jednou za předplatné),
- vytvoří skupinu prostředků se štítky,
- vygeneruje heslo administrátora a uloží ho do `db\azure\.secrets\<server>.json` (přístup jen pro aktuálního uživatele Windows),
- vytvoří server: PostgreSQL 17, Burstable B1ms, 32 GB bez automatického zvětšování, zálohy 7 dní, bez geo-redundance a bez vysoké dostupnosti, veřejný přístup jen přes firewall pravidla,
- nastaví parametry: povolené rozšíření `PGCRYPTO`, časové pásmo `UTC`, povinné TLS, logování dotazů delších než 2 s,
- vytvoří databázi `mikoucto` (UTF-8).

Vytvoření serveru trvá obvykle 5–15 minut.

### 4. Firewall

```powershell
.\02-firewall.ps1
```

Přidá pravidlo pro aktuální veřejnou IP (název `dev-<uživatel>-<ip>`), odstraní vaše starší pravidla a otestuje port 5432. Po změně sítě (domov, kancelář, VPN) skript spusťte znovu. Pokud test selže, síť nejspíš blokuje port 5432; požádejte IT o výjimku.

### 5. Inicializace databáze

```powershell
.\03-inicializace-db.ps1
```

Skript:

1. Ověří spojení.
2. Vygeneruje hesla rolí (pokud ještě nejsou v souboru tajemství).
3. Spustí `sql\010`–`030`, každý soubor v jedné transakci.
4. Spustí `sql\090_kontrola.sql`. Při úspěchu vypíše `Kontrola OK`.
5. Uloží připojovací řetězce do `db\azure\.secrets\<server>.env`. Hodnoty později zkopírujete do `backend\.env.local`.

### 6. Úspora nákladů

```powershell
.\server.ps1 Stop     # na konci dne
.\server.ps1 Start    # před prací
.\server.ps1          # stav
```

Za zastavený server se platí jen úložiště a zálohy. Azure zastavený server po 7 dnech sám spustí.

Doporučeno nastavit v portálu rozpočet s upozorněním: *Cost Management → Budgets → Add*, rozsah = skupina prostředků `rg-mikoucto-dev`, např. 25 USD měsíčně, upozornění na 80 % a 100 %.

## Co inicializace vytvoří

| Objekt | Popis |
| --- | --- |
| `mikoucto_owner` | Vlastník databáze a schémat; používají ho jen migrace |
| `mikoucto_app` | Backend: `SELECT/INSERT/UPDATE` na nové tabulky, `DELETE` jen výslovně; časové limity dotazů a transakcí |
| `mikoucto_read` | Pouze čtení; relace jsou vždy read-only |
| Schéma `core` | Jádro aplikace a auditní log |
| Schéma `ext` | Rozšíření (`pgcrypto`) |
| `core.audit_log` | Log změn rozdělený po měsících; aplikace jej může jen číst |
| `core.set_context(user, request, client)` | Backend volá na začátku každé zapisující transakce; bez něj trigger auditu změnu odmítne |
| `core.audit_attach(tabulka[, klíč])` | Připojí tabulku k auditu a zakáže `TRUNCATE`; volá se v migracích |
| `core.audit_ensure_partitions(n)` | Vytvoří partitions na `n` měsíců dopředu; backend volá denně |
| `core.audit_seal()`, `core.audit_verify()` | Řetězení kontrolních součtů a jeho ověření |

## Řešení potíží

| Příznak | Příčina a řešení |
| --- | --- |
| `01-server.ps1`: chyba `RequestDisallowedByPolicy` | Azure Policy omezuje region, SKU nebo vyžaduje štítky. Upravte `config.ps1` podle zprávy |
| `01-server.ps1`: název serveru je obsazený | `ServerName` musí být unikátní v celém Azure; přidejte např. zkratku organizace |
| `02-firewall.ps1`: port 5432 nedostupný | Blokuje firemní síť nebo proxy, případně se změnila veřejná IP. Spusťte skript znovu z cílové sítě nebo požádejte IT |
| `psql`: `password authentication failed` | Heslo v `.secrets` neodpovídá serveru. Heslo administrátora lze nastavit: `az postgres flexible-server update -g <rg> -n <server> --admin-password <heslo>` a stejné uložit do `.secrets\<server>.json` |
| `020_rozsireni.sql`: rozšíření není povolené | Parametr `azure.extensions` nebyl nastaven; spusťte znovu `01-server.ps1` |
| Server nereaguje | Je zastavený: `.\server.ps1 Start` |

## Přechod na jiného poskytovatele

SQL skripty v `sql\` používají jen standardní PostgreSQL 17 a rozšíření `pgcrypto`. U jiného poskytovatele:

1. Vytvořte server a databázi jeho nástroji.
2. Spusťte `010`, `020`, `030` a `090` přes `psql` jako administrátor s proměnnými `db_name`, `owner_pwd`, `app_pwd`, `read_pwd` (viz hlavička `010_role_a_schemata.sql`).
3. Data přeneste přes `pg_dump` / `pg_restore` (role `mikoucto_owner`) a změňte připojovací řetězce backendu.

## Zálohy a tajemství

- Azure dělá automatické zálohy s obnovou k libovolnému okamžiku za posledních 7 dní (`BackupRetentionDays`).
- Soubor `.secrets\<server>.json` je jediné místo s hesly. Zálohujte ho do správce hesel; při ztrátě lze hesla rolí obnovit opětovným spuštěním `03-inicializace-db.ps1` po smazání příslušné položky ze souboru.
