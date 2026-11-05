# Entra: nastavení → ověření → další nastavení

Sada navazuje na existující registraci `mikoucto-web` s návratovou adresou
`http://localhost:8005/redirect.html`. Skripty se spouštějí z kořene projektu
ve Windows PowerShellu 5.1 nebo novějším. Změny v Entra provádějí pouze liché
kroky 03, 05 a 07; sudé kroky čtou aktuální stav. Skript 09 mění místní
konfiguraci. Skripty nevytvářejí heslo ani client secret a nepoužívají placené
cloudové prostředky.

| Nastavení | Následné ověření | Výsledek |
| --- | --- | --- |
| `01-prihlaseni.ps1` | `02-overeni-prihlaseni.ps1` | Správný tenant, účet a existující SPA |
| `03-api-nastaveni.ps1` | `04-overeni-api.ps1` | API, URI, scope a v2 tokeny |
| `05-klienti-nastaveni.ps1` | `06-overeni-klientu.ps1` | SPA a desktop, návratové adresy a požadovaná oprávnění |
| `07-predautorizace.ps1` | `08-overeni-predautorizace.ps1` | Předautorizace klientů a kontrola podnikových aplikací |
| `09-projekt-nastaveni.ps1` | `10-overeni-projektu.ps1` | Konfigurace backendu a webu |

## Nejdříve: nástroje a dvě ID

Otevřete PowerShell:

```powershell
Set-Location -LiteralPath 'C:\Users\KočandrleMichal\Projects\mikoucto'
```

Potřebujete **Azure CLI**, který je na tomto počítači už dostupný jako `az`.
Na jiném počítači postupujte podle
[instalace Azure CLI pro Windows](https://learn.microsoft.com/en-us/cli/azure/install-azure-cli-windows).
Microsoft Graph PowerShell moduly ani Azure předplatné pro tyto skripty
nepotřebujete. Potřebujete však existující tenant a oprávnění ke správě
registrací a jejich podnikových aplikací.

V portálu otevřete **Registrace aplikací → mikoucto-web → Přehled**.
Zkopírujte **ID adresáře (tenanta)** a **ID aplikace (klienta)**.
Nepoužívejte Object ID registrace ani ID ze školního adresáře.

Projděte vždy nastavení i jeho ověření. Při chybě nepokračujte k dalšímu
nastavení; opravte příčinu a zopakujte příslušnou dvojici.

## 0. Ověření skriptů bez Entra

```powershell
.\tools\entra\00-test-skriptu.ps1
```

Používá náhradní Graph a dočasnou kopii konfigurace. Ověřuje zachování jiných
scope, oprávnění, návratových adres a místních nastavení, opakování bez duplicit,
odmítnutí špatného tenantu a chybné konfigurace. Nepřihlašuje se, nevolá skutečný
Graph, nemění skutečné `.env.local` ani DB. Testovací soubory po sobě odstraní.

## 1–2. Přihlášení do správného tenantu → ověření

Nahraďte obě zástupné hodnoty skutečnými GUID z přehledu webu:

```powershell
.\tools\entra\01-prihlaseni.ps1 -TenantId '<TENANT_ID>' -SpaClientId '<SPA_CLIENT_ID>'
.\tools\entra\02-overeni-prihlaseni.ps1
```

Skript 01 otevře přihlášení Microsoftu. Použijte účet spravující registrace
v **Default Directory** a dokončete případné MFA. Pokud běžné přihlášení
nefunguje, zopakujte 01 se stejnými ID a přepínačem `-UseDeviceCode`; řiďte
se adresou a jednorázovým kódem vypsaným Azure CLI.

Při nabídce jediné položky `N/A(tenant level account)` vyberte `1` nebo
potvrďte Enterem. Pro správu Entra je přihlášení bez předplatného v pořádku.
Na Windows s MSI instalací skripty volají přímo její přibalený Python
(`python.exe -IBm azure.cli`), aby se Graph URL se závorkami a `&` neposílaly
přes dávkový obal `az.cmd`.

Používá se `az login --tenant … --allow-no-subscriptions`. Sada má oddělený
CLI profil v ignorované složce `.local/azure`; předchozí přihlášení z jiného
terminálu se nepřebírá. Relaci a její cache spravuje Azure CLI, skripty tokeny
neexportují ani nevypisují. Proměnná `AZURE_CONFIG_DIR` se po každém volání
obnoví. Přihlašovací údaje aplikace mikoucto zůstávají oddělené od této
správcovské relace.
[Interaktivní přihlášení Azure CLI](https://learn.microsoft.com/en-us/cli/azure/authenticate-azure-cli-interactively).

Očekávaný výsledek: **02 OK**, správný tenant, jméno uživatele, jeho Object ID
a existující single-tenant web. Object ID uživatele využijete při založení
administrátora v DB.

Máte-li už API nebo desktop registraci, můžete jejich Client ID předat
skriptu 01 jako `-ApiClientId` a `-DesktopClientId`. Jinak je skripty vyhledají
přesným názvem, nebo vytvoří. Při více shodách skončí chybou, aby nevybraly
náhodnou aplikaci. Skript 01 zachová už zjištěná ID při opakování. Konfigurace
pro jiný tenant/web se nepřepisuje.

## 3–4. API a scope → ověření

```powershell
.\tools\entra\03-api-nastaveni.ps1
.\tools\entra\04-overeni-api.ps1
```

Nastaví samostatnou registraci **`mikoucto-api`**, její URI
`api://<API_CLIENT_ID>`, v2 access tokeny, aktivní delegovaný scope
**`access_as_user`** a service principal (podnikovou aplikaci). Nový scope
má typ `Admin`. Existující Scope ID, ostatní scope, URI a předautorizace se
zachovávají. Žádné Azure RBAC role se nepřidělují.

Očekávaný výsledek: **04 OK** a vypsané API Client ID i úplný scope.
ID se uloží do ignorovaného `.local/config.json` pro další kroky.
[Nastavení API v Microsoft Graph](https://learn.microsoft.com/en-us/graph/api/resources/apiapplication?view=graph-rest-1.0).

## 5–6. Web a desktop, platformy a oprávnění → ověření

```powershell
.\tools\entra\05-klienti-nastaveni.ps1
.\tools\entra\06-overeni-klientu.ps1
```

Použije váš existující web a doplní SPA redirect URI
`http://localhost:8005/redirect.html`. Vypne implicitní vydávání tokenů webu.
Připraví také **`mikoucto-desktop`** s public-client redirect URI
`http://localhost` pro přihlašovací test. Oběma klientům přidá deklarované
delegované oprávnění `access_as_user` pro vaše API. Ostatní oprávnění a adresy
zachová; nevytváří duplicitní web.

Očekávaný výsledek: **06 OK**. Tento krok ověřuje **požadovaná oprávnění**;
jejich skutečné předběžné schválení se provede další dvojicí.

## 7–8. Předautorizace vlastního API → ověření

```powershell
.\tools\entra\07-predautorizace.ps1
.\tools\entra\08-overeni-predautorizace.ps1
```

Do **mikoucto-api → Zpřístupnit rozhraní API → Autorizované klientské aplikace**
přidá obě Client ID pro scope `access_as_user`. Jde o předautorizaci dvou
konkrétních klientů k vašemu vlastnímu API. Neuděluje se plošný souhlas pro
Microsoft Graph ani další API. Ostatní předautorizace zůstávají zachované.
[Význam předautorizovaných klientů](https://learn.microsoft.com/en-us/graph/api/resources/preauthorizedapplication?view=graph-rest-1.0).

Očekávaný výsledek: **08 OK**. Ověří i to, že podnikové aplikace nejsou
zakázané. Vyžadují-li přiřazení uživatele, ověří přímé přiřazení přihlášeného
účtu. Chybějící přiřazení přidá správce v **Podnikové aplikace → konkrétní
aplikace → Uživatelé a skupiny → Přidat uživatele/skupinu**. Skript požadavek
na přiřazení nevypíná. Členství přes skupinu tato kontrola nevyhodnocuje.

Předautorizace neobchází MFA, Conditional Access ani aplikační role mikoucto.
Získání skutečného tokenu a jeho přijetí backendem ověří až živý test níže.

## 9–10. Nastavení projektu → ověření

Nejprve musí být připravený backend (jednorázově
`tools/dev/02-backend-instalace.ps1`). Potom spusťte:

```powershell
.\tools\entra\09-projekt-nastaveni.ps1
.\tools\entra\10-overeni-projektu.ps1
```

Skript 09 nejprve znovu provede cloudové kontroly. Pak zavolá existující
skripty `dev/08-entra-konfigurace.ps1` a `dev/16-web-konfigurace.ps1` se
zjištěnými ID. Doplní nastavení backendu a webu, zachová `APP_SECRET`, DB
nastavení i další povolené klienty. Do webu se kopírují pouze veřejná ID.

Očekávaný výsledek: **10 OK**. Kontrola souborů nevypisuje jejich obsah ani
tajemství. Sama neověřuje běžící procesy; **restartujte backend i Vite**.

## Skutečné přihlášení a další nastavení DB

Cloudová registrace nezaloží uživatele ani účetní jednotku v PostgreSQL.
Máte-li už migrace i administrátora z fáze 4 hotové, tyto kroky přeskočte.
Jinak při běžícím SSH tunelu spusťte:

```powershell
.\tools\dev\12-partneri-priprava.ps1 -Target Development
.\tools\dev\09-uzivatel.ps1 -TenantId '<TENANT_ID>' -ObjectId '<USER_OBJECT_ID_Z_KROKU_02>' -DisplayName 'Michal' -UnitCode DEV -UnitName 'Vyvojova jednotka' -Role administrator
```

Toto jsou explicitní změny vývojové DB `playground`. Ověření přihlášení pak
používá pouze čtení:

```powershell
# Samostatne okno s tunelem, pokud jeste nebezi:
.\db\google\00-tunel.ps1
```

```powershell
# Dalsi okno: backend zustava spusteny.
.\tools\dev\03-backend.ps1
```

```powershell
# Dalsi okno: skutecne prihlaseni desktopoveho klienta a GET /me + jednotek.
.\tools\dev\11-entra-test.ps1 -Install -CheckOnly
.\tools\dev\11-entra-test.ps1 -Install
```

Očekávejte **Zivy test Entra OK**. Potom spusťte
`tools/dev/17-web.ps1`, otevřete `http://localhost:8005` a přihlaste se i přes
SPA. Úspěch desktopového klienta neověřuje samostatný popup tok webového
klienta. Automatické prohlížečové testy s náhradním API se spouštějí skriptem
`tools/dev/18-web-test.ps1` a nenahrazují toto živé ověření.

`-CheckOnly` ověřuje backend, DB health a anonymní HTTP 401; nezískává token
ani nepotvrzuje práva uživatele. Při následném plném testu otevřete vypsanou
adresu v prohlížeči a PowerShell nechte běžet. Po první instalaci lze
`-Install` vynechat. Diagnostika rozlišuje výpadek spojení, timeout, stav DB,
neočekávané HTTP odpovědi a chyby přípravy Microsoft přihlášení; nezobrazuje
tokeny, surové chybové zprávy ani odpovědi MSAL.

## Při chybě

| Problém | Další krok |
| --- | --- |
| `?$select was unexpected at this time` po úspěšném přihlášení | Použít aktualizované `common.ps1` a `01-prihlaseni.ps1`; odstraněno volání Graph přes `az.cmd`. Nedokončený krok 01 zopakovat, potom ověřit 02. |
| Azure CLI nenalezeno | Nainstalovat CLI, otevřít nový terminál, zopakovat 01. |
| Chybí přihlášení nebo je jiný tenant | Zopakovat 01 se stejnými ID; případně `-UseDeviceCode`. |
| Graph vrací 403 | Správce zkontroluje roli, vlastnictví registrací a pravidla tenantu; skripty oprávnění samy nezvyšují. |
| Registrace nenalezena | Použít Application (client) ID z Přehledu, ne Object ID ani část URL portálu. |
| Více registrací stejného názvu | Předat správné `-ApiClientId` nebo `-DesktopClientId` skriptu 01. |
| Kontrola těsně po změně nenajde nový stav | Chvíli počkat a zopakovat pouze ověřovací krok; Graph může změny propagovat se zpožděním. |
| Živý test vrací 403 | Prověřit DB uživatele a jednotku, `azp` allowlist, přiřazení účtu a politiku přihlašování. |

Skripty jsou určené pro postupné spouštění v jednom terminálu, nikoli pro
souběžné změny stejných registrací. Před zapnutím je připraven offline test;
skutečné operace v Entra se během přípravy sady nespouštějí.

Při přípravě prošlo **22 offline kontrol** ve Windows PowerShellu 5.1 a bylo
ověřeno spuštění Azure CLI 2.91.0 s odděleným profilem. Přihlášení a skutečné
změny Microsoft Graph nebyly spuštěné; ověřují se postupným průchodem 01–10.

Po opravě předávání argumentů bylo navíc ověřené skutečné čtení registrace
`mikoucto-web` přes Microsoft Graph. Regresní test pro Windows PowerShell
ověří průchod speciálních znaků i cesty s mezerami a diakritikou bez síťového
požadavku:

```powershell
.\tools\entra\tests\01-nativni-argumenty.ps1
```
