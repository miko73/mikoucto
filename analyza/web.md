# Etapa 6: web a partneři

Vue 3 + TypeScript + Vite běží na `http://localhost:8005`. Požadavky `/api`
proxy předává Symfony na `127.0.0.1:7005`; PHP používá PostgreSQL na Google
přes SSH tunel. Web neobsahuje databázové heslo ani client secret.

## 1. Příprava

Potřebujete Node.js alespoň 22.12 (doporučená aktuální LTS), backend a migrace
fáze 5. Z kořene projektu:

```powershell
.\tools\dev\15-web-priprava.ps1
```

Skript instaluje přesné závislosti z lockfile a generuje TypeScript typy
z `shared/openapi/partners.json`. Nepřipojuje se k databázi. Při chybě `ENOSPC`
nejprve uvolněte místo na disku a přípravu zopakujte.

## 2. Registrace webového klienta v Entra

**Aktuální stav:** registraci `mikoucto-web` už máte vytvořenou v **Default
Directory**. Na poslední obrazovce Authentication je správná platforma
**Jednostránková aplikace** a adresa `http://localhost:8005/redirect.html`.
Další nastavení proveďte podle kroku **2.0** níže. Kapitoly 2.1–2.7 se
screenshoty slouží jako reference pro práci v portálu; existující web znovu
nezakládejte.

### 2.0 Doporučený postup: nastavení → ověření po jednotlivých skriptech

Všechny následující příkazy spouštějte z kořene projektu v jednom okně
PowerShellu. Azure CLI je na tomto počítači připravený. Na jiném počítači
nejprve postupujte podle [instalace Azure CLI](https://learn.microsoft.com/en-us/cli/azure/install-azure-cli-windows).
Podrobnosti, oprávnění a řešení chyb obsahuje také [návod skriptů Entra](../tools/entra/README.md).

```powershell
Set-Location -LiteralPath 'C:\Users\KočandrleMichal\Projects\mikoucto'
```

**Pravidlo postupu:** spusťte nastavení, potom jeho ověření. K další dvojici
pokračujte až po výsledku `02 OK`, `04 OK`, `06 OK`, `08 OK` nebo `10 OK`.
Při chybě opravte příčinu a zopakujte příslušný krok. Celý seznam příkazů
nespouštějte najednou bez kontroly výsledků.

| Nastavení | Ověření | Co má být připravené |
| --- | --- | --- |
| `01-prihlaseni.ps1` | `02-overeni-prihlaseni.ps1` | Přihlášení do správného tenantu a nalezený existující web |
| `03-api-nastaveni.ps1` | `04-overeni-api.ps1` | API, jeho URI, scope `access_as_user` a verze tokenu 2 |
| `05-klienti-nastaveni.ps1` | `06-overeni-klientu.ps1` | Webový i desktopový klient, návratové adresy a požadovaná oprávnění |
| `07-predautorizace.ps1` | `08-overeni-predautorizace.ps1` | Předautorizace obou klientů pro vlastní API |
| `09-projekt-nastaveni.ps1` | `10-overeni-projektu.ps1` | Místní nastavení backendu a webu |

**A. Nejprve otestujte samotné skripty bez přihlášení:**

```powershell
.\tools\entra\00-test-skriptu.ps1
```

Očekávejte `Entra skripty OK` a 22 kontrol. Tento test používá náhradní Graph
a dočasné soubory; nemění skutečné Entra registrace, místní konfiguraci
aplikace ani DB.

**B. Přihlášení a ověření existujícího webu:**

V portálu otevřete **Registrace aplikací → mikoucto-web → Přehled**. Připravte
si **ID adresáře (tenanta)** a **ID aplikace (klienta)** tohoto webu. Do
PowerShellu je vložíte na vyžádání; nepoužívejte Object ID ani údaje z URL
prohlížeče:

```powershell
$tenantId = Read-Host 'Vlozte ID adresare (tenanta) z prehledu mikoucto-web'
$spaClientId = Read-Host 'Vlozte ID aplikace (klienta) registrace mikoucto-web'
.\tools\entra\01-prihlaseni.ps1 -TenantId $tenantId -SpaClientId $spaClientId
.\tools\entra\02-overeni-prihlaseni.ps1
```

Dokončete Microsoft přihlášení a případné MFA. Pokud běžné přihlášení
nefunguje, zopakujte příkaz 01 s přepínačem `-UseDeviceCode`. Výsledek
**02 OK** musí patřit vašemu **Default Directory** a registraci `mikoucto-web`.
Poznamenejte si také vypsané **Object ID uživatele** pro případné založení
administrátora v DB.

Pokud Azure CLI nabídne výběr předplatného a zobrazí jedinou položku
**`N/A(tenant level account)`**, zvolte `1` nebo potvrďte výchozí volbu Enterem.
Je to přihlášení na úrovni tenantu bez předplatného; pro konfiguraci Entra
je to v pořádku. Po výběru musí doběhnout kontrola registrace a výpis `01 OK`.

Sada má vlastní ignorovaný profil Azure CLI v `tools/entra/.local/azure`;
přihlášení provedené jinde se do něj automaticky nepřebírá. Vyžaduje přístup
k existujícímu tenantu a oprávnění ke správě registrací. Nevyžaduje vytvoření
nového předplatného. Zjištěná veřejná ID ukládá do `tools/entra/.local/config.json`,
takže je dalším krokům nemusíte znovu předávat.

**C. Nastavení API a jeho ověření:**

```powershell
.\tools\entra\03-api-nastaveni.ps1
.\tools\entra\04-overeni-api.ps1
```

Očekávejte **04 OK**. Vznikne nebo se doplní samostatná registrace
`mikoucto-api`, její URI a aktivní scope. Pokud už API nebo desktop existují
pod jiným názvem, jejich Client ID předejte skriptu 01 přes `-ApiClientId`
a `-DesktopClientId` podle [podrobného návodu](../tools/entra/README.md).

**D. Nastavení klientů a jejich ověření:**

```powershell
.\tools\entra\05-klienti-nastaveni.ps1
.\tools\entra\06-overeni-klientu.ps1
```

Očekávejte **06 OK**. Použije se existující `mikoucto-web`, doplní se
`mikoucto-desktop` pro přihlašovací test a oba klienti dostanou deklarované
delegované oprávnění k API. Další návratové adresy a oprávnění se zachovají.

**E. Předautorizace přístupu k API a její ověření:**

```powershell
.\tools\entra\07-predautorizace.ps1
.\tools\entra\08-overeni-predautorizace.ps1
```

Očekávejte **08 OK**. Předautorizují se oba konkrétní klienti pro vlastní
scope `access_as_user`. Nejde o plošný souhlas k Microsoft Graph. Při povinném
přiřazení do podnikové aplikace musí správce přihlášený účet přímo přiřadit;
skripty tento požadavek nevypínají. Členství přes skupiny tato kontrola
nevyhodnocuje.

**F. Místní konfigurace projektu a její ověření:**

Pokud jste ještě nepřipravili backend, nejprve spusťte
`tools/dev/02-backend-instalace.ps1`. Potom:

```powershell
.\tools\entra\09-projekt-nastaveni.ps1
.\tools\entra\10-overeni-projektu.ps1
```

Očekávejte **10 OK**. Krok 09 znovu zkontroluje cloudové nastavení a zavolá
dosavadní konfigurační skripty 08 a 16 ze složky `tools/dev`. Zachová ostatní
nastavení i další povolené klienty. Do webu ukládá pouze veřejná ID.

**Další postup:** pokračujte kapitolou **3**. Po dvojici 09–10 přeskočte ruční
konfigurační příkaz v kroku 3.2. Zkontrolujte uživatele v DB, spusťte tunel,
backend i web a ověřte skutečné přihlášení. Úspěšné kontroly registrací ještě
nepotvrzují získání tokenu ani průchod do Google DB.

### 2.1 Jak otevřít správu Entra

**Pro váš aktuální stav:** na zaslané obrazovce už máte dostupný adresář
**Default Directory** a jste v něm přihlášený. Nový tenant proto pro tento
postup nezakládejte. Pro soukromý projekt zůstaňte v tomto adresáři; školní
adresář VŠE použijte jen tehdy, pokud by šlo o projekt spravovaný školou
a měli jste k tomu její oprávnění.

![Správa tenantů v Microsoft Entra s aktuálním adresářem Default Directory](images/entra/Screenshot%202026-11-02%20195838.png)

*Obrázek 1: Existující Default Directory je aktuální tenant. K registraci
aplikace pokračujte přes horní vyhledávání „Registrace aplikací“;
tlačítko „Vytvořit“ na této obrazovce zakládá další tenant.*

Entra se spravuje v prohlížeči. Kvůli registraci nemusí běžet web, PHP ani
SSH tunel. Postupujte takto:

1. Otevřete Edge nebo Chrome.
2. Do adresního řádku vložte [https://entra.microsoft.com](https://entra.microsoft.com)
   a stiskněte Enter. Případně z PowerShellu otevřete stejnou adresu:

   ```powershell
   Start-Process 'https://entra.microsoft.com'
   ```

3. Přihlaste se účtem, který má přístup k zamýšlenému organizačnímu adresáři
   a právo registrovat aplikace. Pokud vybíráte mezi osobním a pracovním účtem,
   použijte identitu patřící do tohoto adresáře.
4. V levé nabídce otevřete **Entra ID**. Je-li nabídka sbalená, nejprve ji
   rozbalte tlačítkem nabídky vlevo nahoře.
5. V **Overview / Přehled** zkontrolujte název adresáře. „Tenant“ zde znamená
   organizační adresář Microsoftu, který bude ověřovat uživatele mikoucto.
6. Máte-li přístup k více adresářům, použijte v horní liště **Settings /
   Nastavení → Directories + subscriptions / Adresáře a předplatná** a přepněte
   do adresáře určeného pro mikoucto.

**Pokud právě vidíte stránku „Spravovat tenanty“:** v horním vyhledávacím poli
**„Hledat prostředky, služby a dokumenty“** napište **Registrace aplikací**
(případně anglicky **App registrations**). Otevřete nalezenou službu a klikněte
na **Nová registrace**. Tlačítko **Vytvořit** na stránce tenantů slouží k tvorbě
dalšího adresáře; pro registraci aplikace je nepoužívejte.

Databáze dál zůstává na Google Cloud. V tomto portálu nastavujete přihlášení.
[Oficiální postup Microsoftu](https://learn.microsoft.com/en-us/entra/identity-platform/quickstart-register-app).

### 2.2 Pokud máte pouze soukromý Microsoft účet

Váš snímek už existenci **Default Directory** potvrzuje. Následující odstavec
řeší případ, kdy někdo jiný adresář dosud nemá; ve vašem současném postupu
pokračujte rovnou krokem 2.3.

Samotný soukromý účet například pro Outlook nebo Microsoft 365 Personal
nenahrazuje členství v organizačním Entra adresáři. Dosavadní implementace
mikoucto je **single tenant**: přijímá uživatele a hosty jednoho konkrétního
adresáře. Soukromý účet lze použít jako hosta po pozvání do tohoto adresáře;
pro jeho správu a registraci aplikací potřebujete také odpovídající oprávnění.

Pokud portál hlásí, že účet v adresáři neexistuje, nebo nemáte žádný dostupný
adresář, nejprve je potřeba získat přístup k existujícímu workforce tenantu
nebo připravit vlastní. Podmínky vytvoření se liší podle účtu a předplatného.
Microsoft nyní omezuje vytváření **dalších** workforce tenantů pro bezplatné
a zkušební účty. Aktuální možnosti včetně odkazu na založení Azure účtu jsou
v [návodu k vytvoření tenantu](https://learn.microsoft.com/en-us/entra/fundamentals/create-new-tenant).

Máte-li tvorbu zpřístupněnou, cesta je **Entra ID → Overview / Přehled →
Manage tenants / Spravovat tenanty → Create / Vytvořit → Microsoft Entra ID**.
Zadává se název organizace, dostupný počáteční název domény a země/region.
Pro současnou aplikaci potřebujete workforce tenant; External ID není
zaměnitelnou konfigurací. Po vytvoření přepněte do nového adresáře.

Pokud možnost chybí nebo je zakázaná, pokračování registrací SPA tento problém
nevyřeší. Nejdříve vyřešte adresář a oprávnění podle uvedeného návodu nebo se
správcem adresáře, který chcete používat.

### 2.3 Zkontrolujte předchozí registrace

V levé nabídce přejděte na **Entra ID → App registrations / Registrace aplikací**.
Přepněte seznam na **All applications / Všechny aplikace**.

Celý projekt používá tyto tři samostatné registrace:

| Registrace | Účel | ID používané ve skriptech |
| --- | --- | --- |
| `mikoucto-api` | Backend, pro který se vydávají přístupové tokeny | `API_CLIENT_ID` |
| `mikoucto-desktop` | Dosavadní přihlašovací test skriptem 11 | `PUBLIC_CLIENT_ID` |
| `mikoucto-web` | Web, který vytvoříte v následujícím kroku | `SPA_CLIENT_ID` |

Pokud první dvě registrace chybí, připraví je skripty v kroku **2.0 C–D**.
Při ručním postupu **webovou registraci můžete vytvořit už nyní** podle kroku
2.4; před nastavením přístupu k API a spuštěním konfiguračního skriptu 16
ale dokončete [fázi 4, kroky 2 a 3](entra_nastaveni.md#2-registrace-api).
API musí mít scope `access_as_user`, URI `api://<API_CLIENT_ID>` a verzi access
tokenu 2. Potom podle fáze 4 nastavte backend skriptem 08 a připravte prvního
uživatele. Web má vlastní Client ID, odlišné od API i desktopového klienta.

### 2.4 Vytvořte registraci webu

Na zaslané obrazovce **„Zaregistrovat aplikaci“** už máte předvyplněný název
`mikoucto` a typ účtů **„Pouze jeden tenant: Default Directory“**. Formulář
dokončete takto:

![Rozpracovaný formulář Zaregistrovat aplikaci s názvem mikoucto, jedním tenantem a dosud nevyplněnou platformou a návratovou adresou](images/entra/Screenshot%202026-11-02%20200121.png)

*Obrázek 2: Snímek zachycuje rozpracovaný formulář. Před potvrzením upravte
název na `mikoucto-web`, vyberte platformu SPA a doplňte návratovou adresu
podle následující tabulky.*

| Pole na obrazovce | Hodnota pro web mikoucto |
| --- | --- |
| **Název** | `mikoucto-web` |
| **Podporované typy účtů** | **Pouze jeden tenant: Default Directory** |
| **Identifikátor URI pro přesměrování → Vyberte platformu** | **Jednostránková aplikace (SPA)** / **Single-page application (SPA)** |
| **Adresa vedle volby platformy** | `http://localhost:8005/redirect.html` |
| **Potvrzení** | Tlačítko **Zaregistrovat** vlevo dole |

1. V **App registrations / Registrace aplikací** klikněte na **New registration /
   Nová registrace**, pokud už formulář nemáte otevřený.
2. Do **Name / Název** napište `mikoucto-web`; na vašem formuláři tedy doplňte
   k dosavadnímu `mikoucto` příponu `-web`.
3. V **Supported account types / Podporované typy účtů** vyberte **Accounts in
   this organizational directory only / Účty pouze v tomto organizačním
   adresáři (Single tenant)**. Musí to být stejný adresář jako u `mikoucto-api`.
4. V části **Identifikátor URI pro přesměrování (nepovinné)** otevřete seznam
   **Vyberte platformu** a vyberte **Jednostránková aplikace (SPA)**.
   Do sousedního textového pole vložte `http://localhost:8005/redirect.html`.
5. Klikněte na **Register / Zaregistrovat**.
6. Na otevřené stránce **Overview / Přehled** zkopírujte:
   - **Application (client) ID / ID aplikace (klienta)** → vaše `SPA_CLIENT_ID`;
   - **Directory (tenant) ID / ID adresáře (tenanta)** → vaše `TENANT_ID`.

Kopírujte příslušné GUID tlačítkem vedle hodnoty. **Object ID / ID objektu**
této registrace se jako Client ID nepoužívá. Název registrace ani vaše
e-mailová adresa také nejsou ID pro konfigurační skript.

Pokud už jste aplikaci s názvem `mikoucto` zaregistroval, nezakládejte kvůli
názvu další kopii. Otevřete tuto registraci, případně upravte zobrazovaný název
na `mikoucto-web` a nastavte platformu i adresu podle kroku 2.5. Client ID
zůstává identifikátorem této stejné registrace.

### 2.5 Nastavte návratovou adresu webu

Jestli jste adresu zadal už ve formuláři při registraci, zde ji pouze
**zkontrolujte**. Druhý stejný záznam nepřidávejte. Pokud jste pole ponechal
prázdné, doplňte je následujícím postupem:

1. Zůstaňte uvnitř registrace **`mikoucto-web`**.
2. V její nabídce otevřete **Authentication / Ověřování**.
3. V novějším rozhraní otevřete **Redirect URI configuration → Add Redirect URI**.
   Ve starším rozhraní se příslušné tlačítko jmenuje **Add a platform / Přidat
   platformu**.
4. Vyberte dlaždici **Single-page application (SPA) / Jednostránková aplikace**.
5. Do **Redirect URI** vložte přesně:

   ```text
   http://localhost:8005/redirect.html
   ```

6. Potvrďte **Configure / Konfigurovat**, případně následné **Save / Uložit**.
7. Ověřte, že je tato adresa uvedená pod platformou **Single-page application**.

Použijte `localhost`, port `8005` a cestu `/redirect.html`. Přihlášení
spouštějte také z `http://localhost:8005`, aby návratová adresa odpovídala.
Platforma **Web** je jiný typ klienta. Pro tuto SPA nevytvářejte client secret
a nezapínejte implicit grant; aplikace používá Authorization Code + PKCE.
[Nastavení redirect URI podle Microsoftu](https://learn.microsoft.com/en-us/entra/identity-platform/how-to-add-redirect-uri).

### 2.6 Povolte webu přístup k mikoucto API

1. Stále v registraci **`mikoucto-web`** otevřete **API permissions / Oprávnění API**.
2. Klikněte na **Add a permission / Přidat oprávnění → My APIs / Moje rozhraní API**.
3. Vyberte **`mikoucto-api`**.
4. Zvolte **Delegated permissions / Delegovaná oprávnění** a zaškrtněte
   **`access_as_user`**.
5. Potvrďte **Add permissions / Přidat oprávnění**. V přehledu musí být
   `mikoucto-api` a jeho delegované oprávnění `access_as_user`.
6. Vyžaduje-li tenant souhlas správce, požádejte správce o jeho udělení.
   Máte-li příslušnou roli, lze použít **Grant admin consent for … / Udělit
   souhlas správce pro …** a potvrdit dialog. Zašedlé tlačítko nemusí být chyba
   registrace; může chybět oprávnění správce.

Jestli API nevidíte, zkontrolujte správný adresář, existenci registrace API,
její vlastníky a vystavený scope podle fáze 4. Mikoucto pro tento průchod
nepotřebuje přidávat další oprávnění Microsoft Graph.
[Postup přidání oprávnění k vlastnímu API](https://learn.microsoft.com/en-us/entra/identity-platform/quickstart-configure-app-access-web-apis).

Pokud správce v podnikové aplikaci API vyžaduje přiřazení uživatelů, musí vám
také přístup přiřadit. Volitelné předběžné schválení SPA se nastavuje v registraci
**`mikoucto-api` → Expose an API / Zpřístupnit API → Authorized client applications**:
přidá se `SPA_CLIENT_ID` a scope `access_as_user`.

### 2.7 Co máte mít hotové před konfigurací

- `TENANT_ID`: ID společného adresáře.
- `API_CLIENT_ID`: Application (client) ID registrace **`mikoucto-api`**.
- `SPA_CLIENT_ID`: Application (client) ID registrace **`mikoucto-web`**.
- Návratová adresa webu je pod platformou SPA a web má delegované oprávnění
  `access_as_user` pro API.
- Backend je nastavený podle fáze 4 a váš uživatel má v DB přiřazenou jednotku
  a roli. Microsoft přihlášení samo o sobě tyto aplikační role nezakládá.

Nyní pokračujte krokem 3. Tato tři ID jsou veřejné identifikátory; heslo
Microsoft účtu ani client secret se do skriptů nezadávají.

**Doporučení pro tento projekt:** zachovejte tři oddělené registrace ve stejném
adresáři. Web má platformu SPA, backendové API při registraci přesměrování
nepotřebuje a desktopový klient má vlastní nastavení podle fáze 4. Pro web
nevytvářejte client secret ani nezapínejte implicitní tok. Přihlášení zkoušejte
z `http://localhost:8005`; jeho návratovou adresu nezaměňujte za port backendu
`7005` nebo adresu databáze na Google.

MSAL Browser používá popup a Authorization Code + PKCE. Návratová stránka
je samostatný build vstup a předává výsledek hlavnímu oknu přes redirect bridge.
Na `redirect.html` nenastavujte `Cross-Origin-Opener-Policy`.
Viz [návod Microsoftu k redirect bridge](https://learn.microsoft.com/en-us/entra/msal/javascript/browser/redirect-bridge).

## 3. Konfigurace a spuštění

### 3.1 Otevřete PowerShell ve složce projektu

Ve Windows otevřete nabídku Start, napište **PowerShell** a spusťte jej.
Případně použijte **Terminal → Windows PowerShell**. Správce není potřeba.
Přejděte do projektu:

```powershell
Set-Location -LiteralPath 'C:\Users\KočandrleMichal\Projects\mikoucto'
```

Stejnou složku použijte ve všech dalších terminálech. Přípravu webu z kroku 1
stačí provést jednou; po změně závislostí ji zopakujte.

### 3.2 Uložte ID registrací

**Po úspěšném kroku 2.0 F (skripty `tools/entra/09` a `10`) už jsou ID
uložená v backendu i webu. Následující ruční příkaz přeskočte.**
Příkaz 16 níže je alternativou pro dokončení konfigurace přes portál;
předpokládá již nastavený backend z fáze 4.

Nahraďte zástupné hodnoty skutečnými GUID (bez úhlových závorek):

```powershell
.\tools\dev\16-web-konfigurace.ps1 `
  -TenantId '<TENANT_ID>' `
  -ApiClientId '<API_CLIENT_ID>' `
  -SpaClientId '<SPA_CLIENT_ID>'
```

Skript zkontroluje shodu s backendem, přidá SPA do `ENTRA_ALLOWED_CLIENT_IDS`,
zachová desktopového klienta a ostatní nastavení. Do ignorovaného
`frontend/.env.local` uloží pouze tři veřejná ID. Žádné tajemství se nekopíruje
do proměnných `VITE_*`, které jsou veřejnou součástí buildu. Po konfiguraci
restartujte backend i Vite. Opakované spuštění skriptu 08 musí zachovat SPA
v `-AllowedClientIds`, jinak se její přístup do API opět odebere.

Před skutečným přihlášením musí být připravené také **vývojové migrace,
uživatel a účetní jednotka v DB**. Pokud už jsou hotové z předchozích etap,
znovu je nezakládejte. Jinak při běžícím SSH tunelu proveďte:

```powershell
.\tools\dev\12-partneri-priprava.ps1 -Target Development
.\tools\dev\09-uzivatel.ps1 -TenantId '<TENANT_ID>' -ObjectId '<USER_OBJECT_ID>' -DisplayName 'Michal' -UnitCode DEV -UnitName 'Vyvojova jednotka' -Role administrator
```

`USER_OBJECT_ID` je **Object ID uživatele**, které vypsalo ověření
`tools/entra/02-overeni-prihlaseni.ps1`, nikoli Object ID registrace aplikace.
Tyto dva příkazy mění vývojovou databázi `playground`. Podrobnosti jsou ve
[fázi 4](entra_nastaveni.md#6-první-administrátor-a-účetní-jednotka).

### 3.3 Spusťte tunel, backend a web

Potřebujete **tři současně otevřená okna nebo panely PowerShellu**.
Každý server zůstává ve svém okně spuštěný, proto tyto příkazy nezadávejte
postupně do jediného okna. V každém nejprve přejděte do složky projektu
příkazem z kroku 3.1.

**Okno 1 – SSH tunel.** Pokud už běží, ponechte jej a další tunel nespouštějte:

```powershell
.\db\google\00-tunel.ps1
```

**Okno 2 – backend.** Používá databázovou roli `mikoucto_app`:

```powershell
.\tools\dev\03-backend.ps1
```

Nechte okno otevřené. Backend poslouchá na portu `7005`.

**Okno 3 – web:**

```powershell
.\tools\dev\17-web.ps1
```

Počkejte na výpis adresy `http://localhost:8005` a také toto okno ponechte
otevřené. Příslušný proces ukončíte klávesami **Ctrl+C** v jeho okně.

### 3.4 Otevřete aplikaci a přihlaste se

Pro samostatné ověření Microsoft přihlášení a přístupu k API můžete při
běžícím tunelu a backendu nejprve v dalším okně ověřit dostupnost:

```powershell
.\tools\dev\11-entra-test.ps1 -CheckOnly
```

Očekávejte `Backend OK`, `Databaze OK` a `Ochrana API OK`. Tento režim
ověřuje HTTP 200 u health endpointů a HTTP 401 u `/me` bez tokenu;
nepřihlašuje se do Microsoftu a neověřuje uživatelova oprávnění. Pokud klient
ještě nemá závislosti, přidejte jednorázově `-Install`.

Potom spusťte celé přihlášení:

```powershell
.\tools\dev\11-entra-test.ps1
```

Očekávejte `Zivy test Entra OK`. Tento test ověří desktopového klienta
a čtecí požadavky na `/me` a dostupné jednotky. Samostatně pak ověřte i webový
popup tok následujícím přihlášením v prohlížeči.

Skript vypíše přihlašovací adresu; **zkopírujte ji do prohlížeče a přihlaste
se, zatímco PowerShell zůstává spuštěný**. Následně se vraťte do terminálu.
Po již provedené instalaci stačí příště spouštět skript bez `-Install`.
Při chybě se vypíše konkrétní fáze, HTTP stav nebo bezpečný chybový kód,
nikoli token ani surová odpověď přihlašovací služby.

V prohlížeči otevřete [http://localhost:8005](http://localhost:8005) a klikněte
na **Přihlásit přes Microsoft**. Vyberte účet, který máte připravený ve fázi 4.
Po úspěšném přihlášení se zobrazí účetní jednotka a adresář partnerů. Povolení
vyskakovacího okna může být nutné. Tokeny jsou pouze v paměti, po obnovení
stránky se přihlaste znovu. Odhlášení maže místní relaci aplikace, neodhlašuje
ostatní Microsoft služby. Data se neukládají do localStorage. Podrobnosti:
[MSAL caching](https://learn.microsoft.com/en-us/entra/msal/javascript/browser/caching).

Pokud se něco nepodaří:

| Co vidíte | Co ověřit |
| --- | --- |
| Azure CLI nabízí `N/A(tenant level account)` | Vyberte `1` nebo Enter. Pro tento postup není potřeba cloudové předplatné. |
| Při čtení registrace chyba `?$select was unexpected at this time` | Jde o zpracování URL dávkovým obalem Windows, nikoli neúspěšné přihlášení. Aktualizované skripty používají přímo Python přibalený k Azure CLI. Pokud krok 01 nedokončil uložení konfigurace, zopakujte jej a potom spusťte ověření 02. |
| Stránku localhost nelze otevřít | Běží skript 17 a vypsal adresu s portem 8005? |
| Web žádá nastavení registrace | Prošly skripty `tools/entra/09` a `10`, případně ruční skript 16? Restartovali jste potom skript 17? |
| Microsoft hlásí chybnou návratovou adresu | V registraci webu má být SPA a přesně `http://localhost:8005/redirect.html`; aplikaci otevírejte přes localhost. |
| Po přihlášení není přístup do API | Běží backend? Prošly kontroly `tools/entra/06`, `08` a `10`? Je účet založený v DB podle fáze 4? |
| Skript 11 po Microsoft přihlášení vrátí HTTP `403/403` | Ověřte aktivního DB uživatele pro správné Tenant ID a Object ID uživatele. V nové DB ho spolu s jednotkou a rolí založí `tools/dev/09-uzivatel.ps1` podle kroku 3.2. Potom zopakujte živý test; samotné Entra skripty DB identity nezakládají. |
| Skript 11 selže ještě před vypsáním přihlašovací adresy | Spusťte jej s `-CheckOnly`. Hlášení rozliší nedostupný backend, DB, chybnou anonymní odpověď a chybu při přípravě Microsoft přihlášení. |
| API hlásí nedostupnou databázi | Běží SSH tunel v okně 1 a jsou provedené vývojové migrace? |
| Přihlášení a jednotka jsou vidět, ale selhalo načtení adresáře | Klikněte na „Zkusit znovu“. Do načtení oprávnění se zobrazuje „Oprávnění neověřena“. Síťová chyba čtení neznamená zamítnutí role ani nejistý zápis; hlášení rozliší spojení, limit 20 sekund a přesměrování. |

## 4. Automatické ověření

```powershell
.\tools\dev\18-web-test.ps1
```

Zahrnuje generování typů, jednotkové testy, kontrolu TypeScriptu, produkční
build a Playwright testy v desktopovém i mobilním rozměru. Výchozí prohlížeč
je nainstalovaný Microsoft Edge. Jiný lze zvolit proměnnou
`$env:PLAYWRIGHT_CHANNEL='chrome'`. Druhá ověřená varianta používá Chromium
stažený Playwrightem; při prvním spuštění jej nainstalujte:

```powershell
$env:PLAYWRIGHT_CHANNEL='chromium'
.\tools\dev\18-web-test.ps1 -InstallBrowser
```

Po instalaci pro další běhy stačí:

```powershell
$env:PLAYWRIGHT_CHANNEL='chromium'
.\tools\dev\18-web-test.ps1
```

Nastavení `PLAYWRIGHT_CHANNEL` platí pro aktuální okno PowerShellu a procesy
z něj spuštěné. V novém okně jej nastavte znovu, chcete-li použít Chromium.
Pro návrat k výchozímu Edge v aktuálním okně proměnnou odstraňte:

```powershell
Remove-Item Env:\PLAYWRIGHT_CHANNEL -ErrorAction SilentlyContinue
```

Testy startují vlastní Vite na portu **8006** a po skončení jej ukončují.
Nevyžadují běžící backend, tunel ani Entra: síťový testovací adaptér pouze
v Playwright nahrazuje modul přihlášení a odpovědi API. Aplikace ani backend
neobsahují přepínač obcházející přihlášení. Tyto testy **nepotvrzují** skutečné
přihlášení, konfiguraci oprávnění ani průchod do Google DB.

## 5. Ruční ověření proti Google DB

Použijte připravenou vývojovou jednotku a účet fáze 4. Migrace partnerů
musí být aplikovaná v `playground` (skript 12 s `-Target Development`).

1. Přihlaste se; ověřte jméno uživatele a dostupné jednotky.
2. Jako administrátor nebo účetní vytvořte partnera s jedinečným testovacím
   názvem; ověřte nalezení názvem/IČO a filtr aktivity.
3. Upravte kontakt; v historii jako administrátor ověřte změněné pole,
   čas a autora. Cizí autoři se zobrazují zkráceným interním UUID.
4. Otevřete stejného partnera ve dvou oknech. V prvním změňte e-mail, ve druhém
   město a uložte. Druhé okno musí zobrazit konflikt. „Ponechat moje změny“
   převezme nový e-mail a zachová rozepsané město; zápis vyžaduje nové kliknutí
   na uložení. „Načíst uloženou verzi“ rozepsané údaje zahodí.
5. Deaktivujte partnera, zkontrolujte filtr Neaktivní a historii; potom jej
   případně znovu aktivujte. Fyzické mazání není součástí rozhraní.
6. Ověřte další jednotku a účet s rolí reader: bez zápisu a historie.
   Auditor má historii, účetní má zápis. Oprávnění vždy vynucuje také API.
7. Testovací záznam ponechte deaktivovaný nebo jej označte podle pravidel
   vývojových dat. Tento průchod záměrně zapisuje do vývojové DB.

Při validaci se zobrazují chyby u polí. Země je dvoupísmenný ISO kód,
platnost kódu ověřuje server. Registr ani kontrolní součet IČO se neověřuje.
Při změně jednotky nebo zavření formuláře se potvrzuje zahození rozepsaných
změn. Při 401 se data relace vymažou, při 403 se odebere místní možnost zápisu;
obnovte kontext tlačítkem „Obnovit seznam“.

Po síťové chybě vytváření zůstává stejný `Idempotency-Key` i tělo v paměti;
opakujte původní požadavek. Dokud není výsledek potvrzený, formulář nelze
měnit ani zavřít. Neobnovujte stránku a nezakládejte druhého partnera.
Po ztrátě relace/obnovení stránky nejprve vyhledejte původní záznam.
Idempotenci garantuje API po dobu 24 hodin.

Web je určen pro místní vývoj; tento krok nic nepublikuje do cloudu.
Nasazení bude vyžadovat HTTPS, odpovídající SPA redirect URI a reverse proxy
pro API. CORS ani veřejné vystavení databáze není k lokálnímu běhu potřeba.

## 6. Úspěšné kroky doložené výpisem PowerShellu

Následující přehled vychází z uživatelem dodaného výpisu `Pasted text.txt`.
Zachycuje skutečně dokončené kroky a jejich výsledky. Výpis není potřeba
kopírovat do repozitáře; přihlašovací URL, parametry PKCE a osobní UUID zde
neopakujeme. Ověření je doplněné 2. listopadu 2026.

### 6.1 Živé přihlášení a přístup k jednotce DEV

Ve výpisu už byl připravený přihlašovací klient a běžel backend i spojení
s databází. Úspěšný příkaz z kořene projektu:

```powershell
.\tools\dev\11-entra-test.ps1
```

Doložený výsledek, zkrácený o jednorázový přihlašovací odkaz a interní ID:

```text
Backend OK (HTTP 200).
Databaze OK (HTTP 200).
Ochrana API OK (bez tokenu HTTP 401).
Prihlaseni OK: Michal.
Jednotka: DEV / Vyvojova jednotka / verze 1.
Zivy test Entra OK. Provedeny pouze GET pozadavky.
```

Test tak potvrdil dostupnost backendu a DB, odmítnutí anonymního požadavku,
skutečné přihlášení přes Entra a čtení identity i seznamu dostupných jednotek.
Samotný tento příkaz partnery nevytváří ani nemění.

### 6.2 Kompletní lokální ověření webu

```powershell
.\tools\dev\18-web-test.ps1
```

Ve výpisu je potvrzený celý řetězec: vygenerování typů z OpenAPI, jednotkové
testy, `vue-tsc --noEmit`, produkční build včetně `index.html` a `redirect.html`
a testy prohlížeče v desktopovém i mobilním rozměru.

| Kontrola | Doložený výsledek |
| --- | --- |
| Jednotkové testy | 2 soubory, **13 testů prošlo**: 7 formulářových a 6 API klienta |
| TypeScript | Bez chyby; skript pokračoval k produkčnímu buildu |
| Produkční build | Úspěšný, **1,01 s** v tomto běhu |
| Prohlížečové testy | **18 prošlo**, 9 desktopových a 9 mobilních, **25,6 s** |
| Závěr skriptu | `Etapa 6 OK (lokalni testy)` |

### 6.3 Instalace Chromium a opakované ověření

Ve stejném okně PowerShellu následovala tato úspěšná dvojice příkazů:

```powershell
$env:PLAYWRIGHT_CHANNEL='chromium'
.\tools\dev\18-web-test.ps1 -InstallBrowser
```

Výpis potvrzuje stažení prohlížeče Chromium (Chrome for Testing), FFmpeg
a Chrome Headless Shell. Po instalaci znovu proběhla celá kontrola webu:

| Kontrola | Doložený výsledek |
| --- | --- |
| Jednotkové testy | **13 prošlo** ve 2 souborech |
| TypeScript a generování typů | Bez chyby |
| Produkční build | Úspěšný, **1,25 s** v tomto běhu |
| Prohlížečové testy s kanálem Chromium | **18 prošlo**, 9 desktopových a 9 mobilních, **32,1 s** |
| Závěr skriptu | `Etapa 6 OK (lokalni testy)` |

Časy jsou záznamem těchto konkrétních běhů, nikoli limitem nebo požadovanou
dobou dalších testů. Oba běhy ověřily stejných 18 scénářů; nejde o 36 různých
testů.

### 6.4 Co tento výpis samostatně nedokládá

Řádek `.\tools\dev\17-web.ps1^C` zachycuje zrušený příkaz a neobsahuje
výpis běžícího Vite na portu 8005. Není proto použitý jako doklad úspěšného
spuštění webu. Pro běžné spuštění platí krok 3.3.

Automatické testy skriptem 18 používají náhradní API na portu 8006. Skutečné
vytvoření a změna partnera, historie, deaktivace a dohledání neaktivního
partnera byly potvrzené uživatelem při ručním průchodu kapitoly 5 v předchozí
konverzaci. Tyto zápisy nejsou obsahem dodaného terminálového výpisu.

## Stav ověření při přípravě (2. listopadu 2026)

Skript `18-web-test.ps1` po doplnění diagnostiky spojení prošel: 13 jednotkových
testů, kontrola TypeScriptu, produkční build včetně `redirect.html` a 18
prohlížečových testů v Edge (9 desktop, 9 mobilní rozměr). Testy zahrnují
obnovu po krátkém výpadku načítání oprávnění. Zkontrolované jsou i snímky
seznamu a formuláře.
Dodatečný uživatelský výpis dokládá úspěšné opakování celé sady také
s instalovaným Chromium; přesné příkazy a výsledky jsou v kapitole 6.
`npm audit` po instalaci hlásí 0 známých zranitelností. Testovací token ani
náhrada přihlášení nejsou součástí výsledného buildu. Testovací Vite se ukončil.

Sada `tools/entra/00-test-skriptu.ps1` prošla 22 offline kontrolami. Změny
registrací v Microsoft Graph se při přípravě skriptů nespouštěly. Podle
zaslané obrazovky je již vytvořený `mikoucto-web` se správnou SPA návratovou
adresou; kompletní API a oprávnění ověří postup 2.0.

Navazující živé ověření: podle uživatele prošly všechny Entra skripty,
desktopový přihlašovací test i přihlášení ve webu. V `playground` je připravený
první administrátor jednotky `DEV`; aplikační rolí bylo ověřeno rozpoznání
identity, dostupnost jednotky a oprávnění partnerů. Uživatel následně potvrdil
načtení adresáře, vytvoření partnera, změnu kontaktu, zobrazení historie,
deaktivaci a dohledání neaktivního partnera. **Etapa 6 je tímto ověřená i živým
průchodem web → API → Google PostgreSQL.** Potvrzení se vztahuje k uvedenému
scénáři v jednotce `DEV`; další automatické scénáře jsou popsané výše.

Další je účetní základ podle [postupu etapy 7 krok za krokem](etapa7.md)
a [implementačního plánu](implementace.md#následující-etapa-7).
