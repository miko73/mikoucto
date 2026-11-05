export class CheckFailure extends Error {
  constructor(code, detail) {
    super(code);
    this.code = code;
    this.detail = detail;
  }
}

const safeCode = value => typeof value === 'string' && /^[a-zA-Z0-9_.-]{1,100}$/.test(value) ? value : '';

export function formatCheckError(error, phase) {
  if (error instanceof CheckFailure) {
    return `Prihlasovaci test selhal [${error.code}]: ${error.detail} Token se nevypisuje.`;
  }
  // Never print raw MSAL/fetch messages, response bodies, stacks, or token data.
  const code = safeCode(error?.errorCode) || safeCode(error?.code) || safeCode(error?.cause?.code);
  const name = safeCode(error?.name);
  const hint = phase === 'microsoft_login'
    ? 'Overte dostupnost login.microsoftonline.com, registraci desktop klienta a jeho opravneni.'
    : phase === 'authenticated_api'
      ? 'Overte backend, DB uzivatele, jednotku a povolena Client ID.'
      : 'Overte backend na portu 7005 a zopakujte test s -CheckOnly.';
  return `Prihlasovaci test selhal v kroku ${phase}${name ? ` (${name})` : ''}${code ? ` [${code}]` : ''}. ${hint} Token se nevypisuje.`;
}

export async function checkBackend(base, { fetchImpl = fetch, log = console.log } = {}) {
  async function get(path) {
    try {
      return await fetchImpl(new URL(path, base), { redirect: 'error', signal: AbortSignal.timeout(10000) });
    } catch (error) {
      const code = safeCode(error?.cause?.code) || safeCode(error?.code);
      const timeout = error?.name === 'TimeoutError' || error?.name === 'AbortError';
      throw new CheckFailure(timeout ? 'backend_timeout' : 'backend_connection',
        timeout
          ? 'Backend neodpovedel do 10 sekund. Zkontrolujte jeho okno a SSH tunel.'
          : `Nelze se spojit s backendem na portu ${base.port || '80'}${code ? ` (${code})` : ''}. V samostatnem okne spustte tools/dev/03-backend.ps1.`);
    }
  }
  log(`Kontrola backendu: ${base.origin}`);
  const health = await get('/api/v1/health');
  if (health.status !== 200) {
    throw new CheckFailure('backend_http', `GET /api/v1/health vratil HTTP ${health.status}. Overte backend a jeho port.`);
  }
  const service = await health.json().catch(() => null);
  if (service?.status !== 'ok' || service?.service !== 'mikoucto-api') {
    throw new CheckFailure('backend_response', 'Port nevraci ocekavany mikoucto-api health JSON. Overte, ktery server na portu bezi.');
  }
  log('Backend OK (HTTP 200).');
  const database = await get('/api/v1/health/database');
  if (database.status !== 200) {
    throw new CheckFailure('database_http', `Kontrola DB vratila HTTP ${database.status}. Overte SSH tunel localhost:5432 a DB konfiguraci backendu.`);
  }
  const databaseBody = await database.json().catch(() => null);
  if (databaseBody?.status !== 'ok' || databaseBody?.database !== 'ok') {
    throw new CheckFailure('database_response', 'Kontrola DB nevraci ocekavany JSON. Overte backend.');
  }
  log('Databaze OK (HTTP 200).');
  const anonymous = await get('/api/v1/me');
  if (anonymous.status !== 401) {
    throw new CheckFailure('anonymous_status', `GET /api/v1/me bez tokenu vratil HTTP ${anonymous.status}, ocekavano 401. Overte konfiguraci a log backendu.`);
  }
  log('Ochrana API OK (bez tokenu HTTP 401).');
}
