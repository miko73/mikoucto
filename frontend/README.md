# mikoucto web

Spouštění a ověření: [etapa 6](../analyza/web.md).

Vue 3, TypeScript a Vite 7. API typy jsou generované z verzovaného exportu
OpenAPI 3.1. Generátor `openapi-typescript` je záměrně připnutý na 6.7.6:
podporuje 3.1 a nevyžaduje problematickou podzávislost `minimatch` z řady 7
generátoru. Jeho HTTP klient je přepsaný na opravený `undici` 6.29.1;
generování používá pouze místní verzovaný soubor. Při aktualizaci ověřte
generování, typecheck a `npm audit`.

MSAL Browser 5 používá samostatnou `redirect.html`; musí být součástí buildu.
Do proměnných `VITE_*` patří pouze veřejná ID. Tokeny se neukládají na disk.

`tests/browser` nahrazuje autentizační modul a API pouze pomocí síťových
intercepcí Playwright. Ve zdrojích aplikace není testovací přihlášení.
