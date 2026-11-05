import { PublicClientApplication, InteractionRequiredAuthError, BrowserCacheLocation } from '@azure/msal-browser';
const tenant = import.meta.env.VITE_ENTRA_TENANT_ID ?? '';
const api = import.meta.env.VITE_ENTRA_API_CLIENT_ID ?? '';
const client = import.meta.env.VITE_ENTRA_SPA_CLIENT_ID ?? '';
const guid = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
export const auth = {
  configured: [tenant, api, client].every(v => guid.test(v) && !/^0{8}-/.test(v)) && api !== client,
  async initialize() { if (this.configured) await instance().initialize(); },
  async login() {
    const result = await instance().loginPopup({ scopes, prompt: 'select_account' });
    instance().setActiveAccount(result.account);
  },
  async token() {
    const account = instance().getActiveAccount();
    if (!account) throw new SessionExpired();
    try { return (await instance().acquireTokenSilent({ scopes, account })).accessToken; }
    catch (error) { if (error instanceof InteractionRequiredAuthError) throw new SessionExpired(); throw error; }
  },
  async logout() {
    const account = instance().getActiveAccount();
    await instance().clearCache(account ? { account } : undefined);
    instance().setActiveAccount(null);
  },
};
export class SessionExpired extends Error {}
const scopes = [`api://${api}/access_as_user`];
let application: PublicClientApplication | undefined;
function instance() {
  return application ??= new PublicClientApplication({
    auth: { clientId: client, authority: `https://login.microsoftonline.com/${tenant}`, redirectUri: `${location.origin}/redirect.html` },
    cache: { cacheLocation: BrowserCacheLocation.MemoryStorage },
  });
}
