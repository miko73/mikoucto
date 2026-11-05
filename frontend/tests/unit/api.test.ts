import { afterEach, describe, expect, it, vi } from 'vitest';
vi.mock('../../src/auth',()=>({auth:{token:async()=> 'test-token'},SessionExpired:class extends Error{}}));
import { ApiError, request, downloadCsv } from '../../src/api';
afterEach(()=>vi.unstubAllGlobals());
describe('API klient',()=>{
  it('nečitelná úspěšná odpověď zápisu zůstává nejistým výsledkem',async()=>{
    vi.stubGlobal('fetch',vi.fn().mockResolvedValue(new Response('{broken',{headers:{'X-Request-Id':'partial'}})));
    await expect(request('x',{method:'POST',body:'{}'})).rejects.toMatchObject({status:0,mutationUncertain:true,requestId:'partial'});
  });
  it('posílá Bearer, If-Match a merge-patch ve stejném originu bez cookies',async()=>{
    const fetch = vi.fn().mockResolvedValue(new Response(JSON.stringify({name:'Nový'}),{headers:{ETag:'"id:2"'}}));vi.stubGlobal('fetch',fetch);
    const result=await request('accounting-units/u/partners/id',{method:'PATCH',headers:{'If-Match':'"id:1"','Content-Type':'application/merge-patch+json'},body:'{"name":"Nový"}'});
    expect(fetch).toHaveBeenCalledWith('/api/v1/accounting-units/u/partners/id',expect.objectContaining({credentials:'omit',redirect:'manual',headers:expect.objectContaining({Authorization:'Bearer test-token','If-Match':'"id:1"'})}));
    expect(result.etag).toBe('"id:2"');
  });
  it('zachová validaci a referenci serverové chyby',async()=>{
    vi.stubGlobal('fetch',vi.fn().mockResolvedValue(new Response(JSON.stringify({violations:[{propertyPath:'name',message:'Chyba'}]}),{status:422,headers:{'X-Request-Id':'ref'}})));
    await expect(request('x')).rejects.toMatchObject({status:422,requestId:'ref',violations:[{propertyPath:'name',message:'Chyba'}]});
  });
  it('rozlišuje nejistý síťový výsledek od zamítnutí serverem',async()=>{
    vi.stubGlobal('fetch',vi.fn().mockRejectedValue(new TypeError('network')));
    await expect(request('x')).rejects.toEqual(new ApiError(0));
  });
  it('chyba čtení nehlásí nejistý zápis, chyba vytvoření jej zachová',async()=>{
    vi.stubGlobal('fetch',vi.fn().mockRejectedValue(new TypeError('network')));
    await expect(request('x')).rejects.not.toHaveProperty('message',expect.stringContaining('Výsledek zápisu'));
    await expect(request('x',{method:'POST',body:'{}'})).rejects.toMatchObject({mutationUncertain:true,message:expect.stringContaining('Výsledek zápisu může být nejistý')});
  });
  it('časový limit rozliší od přerušeného spojení',async()=>{
    vi.stubGlobal('fetch',vi.fn().mockRejectedValue(new DOMException('timeout','TimeoutError')));
    await expect(request('x')).rejects.toMatchObject({failureReason:'timeout',message:expect.stringContaining('20 sekund')});
  });
  it('přesměrování odmítne a požadavek s tokenem nikam neopakuje',async()=>{
    const fetch=vi.fn().mockResolvedValue(new Response(null,{status:302,headers:{Location:'https://other.example'}}));vi.stubGlobal('fetch',fetch);
    await expect(request('x')).rejects.toMatchObject({failureReason:'redirect'});expect(fetch).toHaveBeenCalledTimes(1);
  });
});

describe('CSV klient',()=>{
  it('stahuje blob s Bearer tokenem, bezpečným názvem a bez cookies',async()=>{
    const fetch=vi.fn().mockResolvedValue(new Response('\uFEFFÚčet;MD\r\n001;100,00\r\n',{headers:{'Content-Type':'text/csv; charset=UTF-8','Content-Disposition':'attachment; filename="../../report.csv"'}}));vi.stubGlobal('fetch',fetch);
    const file=await downloadCsv('accounting-units/u/reports/general-ledger.csv?periodId=p');
    expect(file.filename).not.toContain('/');expect(file.blob.size).toBeGreaterThan(0);
    expect(fetch).toHaveBeenCalledWith(expect.stringContaining('/api/v1/accounting-units/u/reports/'),expect.objectContaining({credentials:'omit',redirect:'manual',headers:{Accept:'text/csv',Authorization:'Bearer test-token'}}));
  });
  it('zachová JSON chybu limitu a nikdy ji nevydává za CSV',async()=>{
    vi.stubGlobal('fetch',vi.fn().mockResolvedValue(new Response(JSON.stringify({violations:[{propertyPath:'export',message:'Zúžte rozsah dat.'}]}),{status:422,headers:{'Content-Type':'application/problem+json'}})));
    await expect(downloadCsv('x')).rejects.toMatchObject({status:422,violations:[{propertyPath:'export',message:'Zúžte rozsah dat.'}]});
  });
  it('odmítne redirect a úspěšnou odpověď jiného formátu',async()=>{
    const fetch=vi.fn().mockResolvedValueOnce(new Response(null,{status:302})).mockResolvedValueOnce(new Response('{}',{headers:{'Content-Type':'application/json'}}));vi.stubGlobal('fetch',fetch);
    await expect(downloadCsv('x')).rejects.toMatchObject({failureReason:'redirect'});expect(fetch).toHaveBeenCalledTimes(1);
    await expect(downloadCsv('x')).rejects.toThrow('Server nevrátil soubor CSV');
  });
  it('rozliší delší časový limit i přerušení při čtení těla',async()=>{
    vi.stubGlobal('fetch',vi.fn().mockResolvedValue({ok:true,status:200,headers:new Headers({'Content-Type':'text/csv'}),blob:()=>Promise.reject(new DOMException('timeout','TimeoutError'))}));
    await expect(downloadCsv('x')).rejects.toMatchObject({failureReason:'timeout',message:expect.stringContaining('120 sekund')});
    const abort=new AbortController();abort.abort();await expect(downloadCsv('x',abort.signal)).rejects.toHaveProperty('name','AbortError');
  });
  it('přerušení přenosu souboru nehlásí nejistý zápis',async()=>{
    vi.stubGlobal('fetch',vi.fn().mockResolvedValue({ok:true,status:200,headers:new Headers({'Content-Type':'text/csv'}),blob:()=>Promise.reject(new DOMException('network','NetworkError'))}));
    await expect(downloadCsv('x')).rejects.toMatchObject({status:0,failureReason:'connection',mutationUncertain:false});
  });
});
