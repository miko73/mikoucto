import { PublicClientApplication } from '@azure/msal-node';
import { CheckFailure, checkBackend, formatCheckError } from './diagnostics.mjs';

const guid = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const tenant = process.env.ENTRA_TENANT_ID;
const apiId = process.env.ENTRA_API_CLIENT_ID;
const clientId = process.env.ENTRA_PUBLIC_CLIENT_ID;
let phase = 'configuration';

async function main() {
  if (![tenant, apiId, clientId].every(value => guid.test(value ?? '')) || apiId === clientId) {
    throw new CheckFailure('configuration', 'Chybi platna odlisna ID API a desktop klienta. Provedte tools/entra/09 a 10.');
  }
  const base = new URL(process.env.MIKOUCTO_API_URL ?? 'http://127.0.0.1:7005');
  if (base.protocol !== 'http:' || !['127.0.0.1', 'localhost'].includes(base.hostname)
      || base.username || base.password || base.pathname !== '/' || base.search || base.hash) {
    throw new CheckFailure('local_api_required', 'Test vyzaduje mistni HTTP backend na localhost nebo 127.0.0.1.');
  }
  phase = 'backend';
  await checkBackend(base);
  if (process.argv.includes('--check-only')) {
    console.log('Predbezne kontroly OK. Microsoft prihlaseni, token ani pristup uzivatele do DB nebyly testovany.');
    return;
  }

  phase = 'microsoft_login';
  console.log('Pripravuji Microsoft prihlaseni...');
  const application = new PublicClientApplication({
    auth: { clientId, authority: `https://login.microsoftonline.com/${tenant}` },
    system: { loggerOptions: { piiLoggingEnabled: false, loggerCallback() {} } },
  });
  const result = await application.acquireTokenInteractive({
    scopes: [`api://${apiId}/access_as_user`],
    openBrowser: async url => {
      console.log('Otevri tuto prihlasovaci adresu v prohlizeci (Authorization Code + PKCE):');
      console.log(url);
    },
    successTemplate: '<html lang="cs"><body>Prihlaseni prijato. Vratte se do PowerShellu.</body></html>',
    errorTemplate: '<html lang="cs"><body>Prihlaseni selhalo. Vratte se do PowerShellu.</body></html>',
  });
  if (!result?.accessToken) { throw new CheckFailure('missing_access_token', 'Microsoft prihlaseni nevratilo access token.'); }
  // Token zustava v pameti. Nezapisuje se na disk, do logu ani argumentu procesu.
  phase = 'authenticated_api';
  const headers = { Authorization: `Bearer ${result.accessToken}` };
  const profile = await fetch(new URL('/api/v1/me', base), { headers, redirect: 'error', signal: AbortSignal.timeout(15000) });
  const units = await fetch(new URL('/api/v1/accounting-units', base), { headers, redirect: 'error', signal: AbortSignal.timeout(15000) });
  if (!profile.ok || !units.ok) {
    console.error(`API odmitlo prihlaseni nebo pristup: HTTP ${profile.status}/${units.status}. Over konfiguraci a registraci uzivatele v DB.`);
    if (profile.status === 403 || units.status === 403) {
      console.error('Over uzivatele a jeho jednotku/roli v DB, povoleneho klienta a scope; samotne Entra registrace uzivatele v DB nezakladaji.');
    }
    process.exitCode = 1;
    return;
  }
  const me = await profile.json();
  console.log(`Prihlaseni OK: ${me.display_name}, id ${me.id}.`);
  console.table((await units.json()).items);
  console.log('Zivy test Entra OK. Provedeny pouze GET pozadavky.');
}

main().catch(error => {
  console.error(formatCheckError(error, phase));
  process.exitCode = 1;
});
