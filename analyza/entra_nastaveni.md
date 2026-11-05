# Fáze 4: Entra ID, uživatelé, role a účetní jednotky

Pro existující webovou registraci je připravená také
[PowerShell sada nastavení → ověření](../tools/entra/README.md), která doplní
API, desktopového klienta, oprávnění a místní konfiguraci po samostatných krocích.

Web fáze 6 má navíc samostatnou SPA registraci a návratovou stránku.
Po dokončení tohoto základu pokračujte podle [návodu webu](web.md).

Backend už má připravené ověřování podpisu a claims access tokenu, databázové
identity, oprávnění po účetních jednotkách a RLS. Registrace v Entra dosud
nebyly vytvořené. Následující postup je propojí s aplikací; databáze zůstává
na Google Cloud.

## 1. Předpoklady a kontrola implementace

Používáme jeden konkrétní **workforce tenant Microsoft Entra ID**. Potřebujete
přístup k jeho správě a oprávnění pro registraci aplikací. Single-tenant
registrace zahrnuje uživatele a hosty tohoto tenantu. Samotné přihlášení
osobním Microsoft účtem nenahrazuje příslušnost k vybranému tenantu; případný
osobní účet musí mít v tomto tenantu odpovídající identitu hosta.
[Postup registrace a typy účtů](https://learn.microsoft.com/en-us/entra/identity-platform/quickstart-register-app).

Pokud tenant zatím nemáte, nejprve projděte
[vytvoření tenantu a jeho aktuální předpoklady](https://learn.microsoft.com/en-us/entra/fundamentals/create-new-tenant).
V portálu jde o **Entra ID → Overview → Manage tenants → Create → Microsoft
Entra ID**; vyplňuje se název organizace, počáteční doména a země/region.
Microsoft nyní omezuje vytváření dalších workforce tenantů podle typu účtu
a předplatného, takže samotný trial nemusí tuto možnost zpřístupnit.
Použití existujícího tenantu vyžaduje odpovídající oprávnění pro projekt.
Tyto identity se připravují odděleně od databáze na Google Cloud.

Z kořene repozitáře aktualizujte závislosti a při běžícím SSH tunelu ověřte
implementaci. Testy používají lokálně generované RSA klíče a samostatnou
`mikoucto_test`, takže registrace Entra zatím nejsou potřeba:

```powershell
.\tools\dev\02-backend-instalace.ps1
.\tools\dev\10-identita-test.ps1
```

Očekávaný konec: `Faze 4 OK`. Skript nespouští přihlášení do Microsoftu ani
nemění vývojovou `playground`.

Při přípravě prošlo 32 kryptografických/HTTP a dalších testů backendu a
18 databázových integračních scénářů na Google PostgreSQL 16. Ověřeny byly
také skutečné lokální HTTP odpovědi health a odmítnutí anonymního přístupu.
Migrační změny fáze 4 byly aplikovány pouze do `mikoucto_test`.

## 2. Registrace API

V [Microsoft Entra admin center](https://entra.microsoft.com) přepněte do
zamýšleného tenantu a otevřete **Entra ID → App registrations → New registration**.

- Název: `mikoucto-api`.
- Podporované účty: pouze vybraný organizační tenant, **Single tenant**.
- Redirect URI nevyplňujte; tato aplikace je příjemcem API tokenů.
- Po vytvoření si poznamenejte **Directory (tenant) ID** a **Application (client) ID** API.

V **Expose an API** nastavte Application ID URI `api://<API_CLIENT_ID>`
a přidejte povolený scope **`access_as_user`**. Jeho úplný název bude
`api://<API_CLIENT_ID>/access_as_user`. Popis může být „Přístup k mikoucto
jménem přihlášeného uživatele“. Kdo může udělit souhlas, zvolte podle pravidel
tenantu; pro spravované použití může souhlas udělit administrátor.
[Vystavení API a scopes](https://learn.microsoft.com/en-us/entra/identity-platform/quickstart-configure-app-expose-web-apis).

V manifestu **API registrace** nastavte `api.requestedAccessTokenVersion`
na **`2`** v aktuálním Microsoft Graph formátu manifestu. Ve starším formátu
je vlastnost zobrazená přímo jako `requestedAccessTokenVersion`. Ostatní
části manifestu zachovejte. Backend přijímá tokeny `ver=2.0`; pouhé použití
v2 přihlašovacího endpointu neurčuje verzi access tokenu pro API.
[Verze tokenu v manifestu](https://learn.microsoft.com/en-us/entra/identity-platform/reference-app-manifest#requestedaccesstokenversion-attribute).
Vnoření do `api` popisuje také [Microsoft Graph apiApplication](https://learn.microsoft.com/en-us/graph/api/resources/apiapplication?view=graph-rest-1.0).

Pro tento scénář se nevytváří client secret ani aplikační role v Entra;
aplikační role spravuje databáze mikoucto.

## 3. Registrace veřejného testovacího a desktopového klienta

Vytvořte druhou single-tenant registraci **`mikoucto-desktop`**. Poznamenejte
si její **Application (client) ID** jako `PUBLIC_CLIENT_ID`. V Authentication
přidejte platformu **Mobile and desktop applications** a redirect URI
**`http://localhost`**. Testovací MSAL klient používá lokální callback s
dynamickým portem. Klient nemá client secret.

V **API permissions → Add a permission → My APIs → mikoucto-api → Delegated
permissions** přidejte `access_as_user`. Zajistěte souhlas podle pravidel
tenantu. Pokud API v seznamu nevidíte, ověřte vlastníky registrací a správný
tenant. Pro toto API nepotřebujete oprávnění Microsoft Graph.
[Přidání oprávnění klienta k vlastnímu API](https://learn.microsoft.com/en-us/entra/identity-platform/quickstart-configure-app-access-web-apis).

Testovací klient v repozitáři používá oficiální `@azure/msal-node` a
**Authorization Code + PKCE**. MSAL vytvoří challenge, přijme lokální callback
a vymění kód za token. Token ani tokenová cache se neukládají do souboru.
[Přihlašovací toky MSAL Node](https://learn.microsoft.com/en-us/entra/msal/javascript/node/acquire-token-requests).

Samostatnou SPA registraci `mikoucto-web` přidáme při implementaci webu.
Její Client ID pak přidejte do povolených klientů pomocí kroku 4.

## 4. Konfigurace backendu

Použijte skutečné GUID z předchozích kroků:

```powershell
.\tools\dev\08-entra-konfigurace.ps1 -TenantId '<TENANT_ID>' -ApiClientId '<API_CLIENT_ID>' -PublicClientId '<PUBLIC_CLIENT_ID>'
```

Skript zachová stávající `APP_SECRET` a doplní do ignorovaného `backend/.env.local`:

| Proměnná | Význam |
| --- | --- |
| `ENTRA_TENANT_ID` | Konkrétní tenant, nikoli `common` nebo `organizations` |
| `ENTRA_API_CLIENT_ID` | Client ID příjemce tokenu, ověřuje se jako `aud` |
| `ENTRA_PUBLIC_CLIENT_ID` | Registrace MSAL testovacího/desktopového klienta |
| `ENTRA_ALLOWED_CLIENT_IDS` | Povolené hodnoty `azp`, seznam oddělený čárkami |
| `ENTRA_REQUIRED_SCOPE` | `access_as_user` |

Veřejný klient je do allowlistu přidán automaticky. Další klienty lze doplnit
parametrem `-AllowedClientIds @('<WEB_CLIENT_ID>')`. Skript obnoví vývojovou
cache; běžící backend po konfiguraci restartujte. Skutečný přístup dále
vyžaduje uživatele a členství v DB.

Backend ověřuje RS256 podpis a známý klíč, issuer, tenant, audience, časy
platnosti, verzi tokenu, `oid`, delegovaný scope a povoleného klienta.
Identifikátorem je dvojice **tenant + object ID**, aplikační oprávnění se
čtou z databáze při každém požadavku. Entra `roles`, e-mail ani klientské
hlavičky neurčují aplikačního uživatele a jeho oprávnění.
[Validace claims access tokenu](https://learn.microsoft.com/en-us/entra/identity-platform/claims-validation).

Podpisové klíče se stahují pouze z tenant endpointu Microsoftu, s ověřením
HTTPS, bez redirectů a s cache na 5 minut. Neznámý klíč se odmítne; po jeho
rotaci se nová sada načte nejpozději po vypršení cache. Backend nepracuje
s URL klíčů dodanými v tokenu. Bez konfigurace se chráněné API neotevře.

## 5. Migrace vývojové DB

Po úspěšných testech aplikujte migrace do `playground`:

```powershell
.\tools\dev\06-migrace.ps1 -Target Development -Action Status
.\tools\dev\06-migrace.ps1 -Target Development -Action Migrate
.\tools\dev\06-migrace.ps1 -Target Development -Action UpToDate
```

Verze `20261102010000` přidá uživatele, jednotky, role, oprávnění, scoped
členství, audit těchto změn a databázové politiky. Existující audit a jeho
řetězec zůstávají zachované. Historii bez identifikovatelné účetní jednotky
API po zavedení RLS nezobrazuje; správce DB k ní má nadále přístup.

## 6. První administrátor a účetní jednotka

V Entra otevřete **Users → vlastní uživatel → Overview → Object ID**.
Pro hosta použijte Object ID jeho objektu ve vybraném tenantu. Jde o ID
uživatele, nikoli Object ID registrace aplikace.

```powershell
.\tools\dev\09-uzivatel.ps1 -TenantId '<TENANT_ID>' -ObjectId '<USER_OBJECT_ID>' -DisplayName 'Michal' -UnitCode DEV -UnitName 'Vývojová jednotka' -Role administrator
```

Skript používá migrační roli a založí uživatele, jednotku a členství.
UUID v7 generuje aplikace. Uživatel je jedinečný podle tenant/object ID,
jednotka podle kódu a členství podle uživatele, jednotky a role.
Stejné spuštění nevytváří duplicity ani nové auditní události.
Existující jména nepřepisuje a deaktivované uživatele či jednotky neaktivuje.
Změny při prvním založení mají auditní kontext `setup`.

Pro další účet lze použít stejný skript s rolí `reader`, `accountant` nebo
`auditor`; výchozí role je `reader`. Nové uživatele zakládá zatím správce
tímto skriptem. Přihlášení se v této fázi samo nepoužije k registraci účtu.
Parametr `-Target Test` slouží pro ruční přípravu oddělených testovacích dat.

| Role v jednotce | Dostupné funkce fáze 4 |
| --- | --- |
| `administrator` | Jednotka, seznam uživatelů a rolí, přidělení/odebrání rolí, audit |
| `auditor` | Jednotka, katalog rolí a audit |
| `accountant` | Jednotka a katalog rolí; účetní funkce doplní další fáze |
| `reader` | Jednotka a katalog rolí |

Role platí vždy pro konkrétní jednotku. Správce jedné jednotky není správcem
ostatních. Odebrání posledního aktivního administrátora jednotky se odmítne;
role se mění pod zámkem jednotky, aby se toto pravidlo zachovalo i při souběhu.

## 7. Spuštění a skutečné přihlášení

Při běžícím SSH tunelu spusťte backend v samostatném okně:

```powershell
.\tools\dev\03-backend.ps1
```

Pro testovacího klienta potřebujete Node.js 22+ a npm. V dalším okně:

```powershell
.\tools\dev\11-entra-test.ps1 -Install
```

`-Install` nainstaluje závislosti MSAL podle lockfilu; při dalších spuštěních
stačí příkaz bez tohoto přepínače. Nejprve se ověří HTTP 401 bez tokenu.
Potom otevřete zobrazenou přihlašovací URL v prohlížeči. Přihlaste se účtem
z kroku 6 a vraťte se do PowerShellu. Klient ověří `/me` a seznam jednotek,
vypíše přihlášeného uživatele a povolené jednotky a skončí `Zivy test Entra OK`.
Přihlašovací token nevypisuje, nepředává v argumentech procesu a nepersistuje.

Tento krok zatím není ověřený proti skutečnému Entra účtu, protože registrace
dosud neexistují. Automatické testy ověřují skutečné RSA podpisy a skutečnou
Google PostgreSQL, přičemž podpisové klíče Entra nahrazují pouze uvnitř testů.

## API a izolace dat

| Metoda a cesta pod `/api/v1` | Výsledek / potřebné oprávnění |
| --- | --- |
| `GET /health`, `GET /health/database` | Veřejná kontrola běhu a DB |
| `GET /me` | Aktivní registrovaná identita |
| `GET /accounting-units` | Pouze přístupné aktivní jednotky |
| `GET /accounting-units/{unit}/permissions` | Vlastní oprávnění, `core.units.read` |
| `GET /accounting-units/{unit}/users` | `core.users.read`, nejvýše 200 položek |
| `GET /accounting-units/{unit}/roles` | `core.roles.read` |
| `POST /accounting-units/{unit}/users/{user}/roles` | `core.roles.manage`, JSON `{"role":"auditor"}` |
| `DELETE /accounting-units/{unit}/users/{user}/roles/{role}` | `core.roles.manage` |
| `GET /accounting-units/{unit}/audit` | `core.audit.read`, nejnovějších 100 položek |

Role a jejich změny jsou scoped pomocí `accounting_unit_id`. Symfony Voter
kontroluje oprávnění; DB je znovu ověřuje v příkazech pro správu rolí.
Backend každou datovou operaci obalí transakcí a nastaví `app.user_id`,
`app.accounting_unit_id` a auditní kontext pomocí parametrizovaných dotazů.
Hodnoty jsou lokální pro transakci a po jejím ukončení se nepropíšou do
dalšího požadavku. Hlavička `X-Request-Id` propojuje odpověď a audit.

RLS chrání uživatele, členství, jednotky a audit. Nové doménové tabulky musí
v dalších migracích dostat vlastní RLS politiku s `core.can_access_unit`
a odpovídajícími oprávněními; funkční příklad ověřují integrační testy.
Globální katalogy rolí a oprávnění jsou pro aplikaci pouze ke čtení.
Vlastník DB zůstává důvěryhodným správcem s obcházením RLS, aby bezpečnostní
pomocné funkce nezpůsobovaly rekurzi politik. Backend používá `mikoucto_app`.
Chráněné API před prvním čtením ověří přihlašovací i aktuální DB roli a odmítne
vlastníka, členství ve vlastnické roli nebo oprávnění umožňující obejít RLS.
SQL kontext je důvěryhodné rozhraní backendu; nenahrazuje validaci JWT.
[Chování PostgreSQL RLS](https://www.postgresql.org/docs/16/ddl-rowsecurity.html).

## Význam chyb

| HTTP | Význam |
| --- | --- |
| 400 | Neplatný vstup nebo neznámá role/uživatel pro operaci |
| 401 | Chybějící nebo neplatný access token, nesprávný tenant, API, klient nebo scope |
| 403 | Identita není registrovaná/aktivní nebo chybí oprávnění v jednotce |
| 409 | Konflikt pravidel, například odebrání posledního administrátora |
| 503 | Chybí konfigurace Entra, nejsou dostupné podpisové klíče nebo databáze |

Chyby mají formát `application/problem+json`; neobsahují tokeny, připojovací
řetězce ani interní chybové zprávy ovladače. AADSTS chyby při přihlášení
řešte podle přiděleného scope, redirect URI, souhlasu a správného tenantu.
