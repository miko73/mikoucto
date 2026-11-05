import { auth, SessionExpired } from './auth';
type FailureReason = 'connection' | 'timeout' | 'redirect';
export class ApiError extends Error {
  constructor(public status: number, public violations: {propertyPath: string; message: string}[] = [], public requestId = '', public failureReason: FailureReason = 'connection', public mutationUncertain = false, timeoutSeconds = 20) {
    const networkMessage = failureReason === 'timeout' ? `Server neodpověděl do ${timeoutSeconds} sekund. Zkuste požadavek zopakovat.`
      : failureReason === 'redirect' ? 'Server požadavek přesměroval. Obnovte aplikaci a zkuste to znovu.'
        : 'Spojení se serverem se přerušilo. Zkuste to znovu.';
    super(status === 0 ? networkMessage + (mutationUncertain ? ' Výsledek zápisu může být nejistý.' : '') : ({401:'Přihlášení vypršelo.',403:'Pro tuto akci nemáte oprávnění.',404:'Záznam nebyl nalezen.',409:'Záznam nebo klíč požadavku je v konfliktu.',412:'Partnera mezitím upravil jiný uživatel.',422:'Zkontrolujte označená pole.',503:'Služba je dočasně nedostupná.'} as Record<number,string>)[status] ?? `Požadavek selhal (${status}).`);
  }
}
export async function request<T>(path: string, options: RequestInit = {}) {
  const token = await auth.token();
  const signal = options.signal ? AbortSignal.any([options.signal,AbortSignal.timeout(20000)]) : AbortSignal.timeout(20000);
  const mutation = ['POST','PATCH','PUT','DELETE'].includes((options.method ?? 'GET').toUpperCase());
  let response: Response;
  try {
    response = await fetch(`/api/v1/${path}`, { ...options, signal, credentials:'omit', redirect:'manual',
      headers: { Accept:'application/json', ...options.headers, Authorization:`Bearer ${token}` } });
  } catch (error) {
    if (options.signal?.aborted) throw error;
    const timeout = signal.reason?.name === 'TimeoutError' || error instanceof DOMException && error.name === 'TimeoutError';
    throw new ApiError(0, [], '', timeout ? 'timeout' : 'connection', mutation);
  }
  // Refuse redirects without forwarding the bearer token to another location.
  if (response.type === 'opaqueredirect' || response.status >= 300 && response.status < 400) throw new ApiError(0, [], '', 'redirect', mutation);
  if (!response.ok) {
    const problem = await response.json().catch(() => ({}));
    throw new ApiError(response.status, problem.violations ?? [], response.headers.get('X-Request-Id') ?? '');
  }
  let data:T;
  try { data=await response.json() as T; }
  catch(error) {if(options.signal?.aborted)throw error;throw new ApiError(0,[],response.headers.get('X-Request-Id')??'','connection',mutation);}
  return { data, etag: response.headers.get('ETag') ?? '', total: Number(response.headers.get('X-Total-Count') ?? 0) };
}
export { SessionExpired };

class CsvFormatError extends Error {}

/** Download preparation may take longer; keep credentials out of links and redirects. */
export async function downloadCsv(path:string, external?:AbortSignal) {
  const token=await auth.token();
  const signal=external?AbortSignal.any([external,AbortSignal.timeout(120000)]):AbortSignal.timeout(120000);
  try {
    signal.throwIfAborted();
    const response=await fetch(`/api/v1/${path}`,{method:'GET',signal,credentials:'omit',redirect:'manual',headers:{Accept:'text/csv',Authorization:`Bearer ${token}`}});
    if(response.type==='opaqueredirect'||response.status>=300&&response.status<400)throw new ApiError(0,[],'','redirect');
    if(!response.ok){
      const problem=await response.json().catch(()=>({}));
      const violations=Array.isArray(problem.violations)?problem.violations:[];
      throw new ApiError(response.status,violations,response.headers.get('X-Request-Id')??'');
    }
    if(!/^text\/csv(?:;|$)/i.test(response.headers.get('Content-Type')??''))throw new CsvFormatError('Server nevrátil soubor CSV. Zkuste export zopakovat.');
    const blob=await response.blob(); signal.throwIfAborted();
    if(!blob.size)throw new CsvFormatError('Server vrátil prázdný soubor. Zkuste export zopakovat.');
    const disposition=response.headers.get('Content-Disposition')??'';
    const match=/filename=(?:"([^"]+)"|([^;]+))/i.exec(disposition);
    let filename=(match?.[1]??match?.[2]??'sestava.csv').trim().replace(/[\\/:\x00-\x1f<>"|?*]/g,'_').slice(0,180);
    if(!/\.csv$/i.test(filename))filename='sestava.csv';
    return {blob,filename};
  }catch(error){
    if(external?.aborted||error instanceof ApiError||error instanceof CsvFormatError)throw error;
    const timeout=signal.reason?.name==='TimeoutError'||error instanceof DOMException&&error.name==='TimeoutError';
    throw new ApiError(0,[],'',timeout?'timeout':'connection',false,120);
  }
}
