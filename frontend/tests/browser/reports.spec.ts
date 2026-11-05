import { test, expect, type Page } from '@playwright/test';
import { readFile } from 'node:fs/promises';
const unit='11111111-1111-4111-8111-111111111111',second='22222222-2222-4222-8222-222222222222';
const aid='33333333-3333-4333-8333-333333333333',bid='44444444-4444-4444-8444-444444444444',pid='55555555-5555-4555-8555-555555555555',nextPid='66666666-6666-4666-8666-666666666666';
const amount=(c:bigint)=>`${c/100n}.${String(c%100n).padStart(2,'0')}`;
const csv=Buffer.from('\uFEFFSestava;Hlavní kniha\r\nŘádek;Částka\r\ndata-1;1,00\r\ndata-21;2,00\r\n','utf8');
const nav=(page:Page,name:string)=>page.getByRole('navigation',{name:'Sekce aplikace'}).getByRole('button',{name,exact:true}).click();
async function setup(page:Page,{allowed=true}={}) {
  const state={reportStatus:200,csvStatus:200,empty:false,delayReport:false,delayCsv:false,releaseReport:undefined as (()=>void)|undefined,releaseCsv:undefined as (()=>void)|undefined,finishedReports:0,reportRequests:[] as URL[],csvRequests:[] as {url:URL;headers:Record<string,string>}[],writes:0};
  const meta={accountingUnitId:unit,version:1,createdAt:'2026-11-04T08:00:00Z',updatedAt:'2026-11-04T08:00:00Z'};
  const accounts=[{...meta,id:aid,code:'211',name:'Pokladna',active:true,postable:true,parentId:null},{...meta,id:bid,code:'221',name:'Banka',active:false,postable:false,parentId:null}];
  const periods=[{...meta,id:pid,name:'Živý test – listopad 2026',startsOn:'2026-11-01',endsOn:'2026-11-30',status:'closed',closedAt:meta.createdAt,closedBy:'user'},
    {...meta,id:nextPid,name:'Prosinec 2026',startsOn:'2026-12-01',endsOn:'2026-12-31',status:'open',closedAt:null,closedBy:null}];
  await page.route('**/src/auth.ts',r=>r.fulfill({contentType:'application/javascript',body:'export class SessionExpired extends Error {} export const auth={configured:true,initialize:async()=>{},login:async()=>{},logout:async()=>{},token:async()=>"browser-only"};'}));
  await page.route('**/api/v1/**',async route=>{
    const req=route.request(),url=new URL(req.url()),path=url.pathname;
    const json=(data:unknown,status=200,headers:Record<string,string>={})=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(data),headers});
    if(req.method()!=='GET')state.writes++;
    if(path.endsWith('/me'))return json({id:'user',display_name:'Test uživatel'});
    if(path.endsWith('/accounting-units'))return json({items:[{id:unit,name:'Lípa studio',code:'LIPA',version:1},{id:second,name:'Druhá jednotka',code:'DRUHA',version:1}]});
    if(path.endsWith('/permissions'))return json({items:['core.units.read','core.partners.read',...(allowed?['accounts','periods','documents'].flatMap(k=>[`core.ucto.${k}.read`,`core.ucto.${k}.write`,`core.ucto.${k}.history`]):[]),'core.ucto.documents.post']});
    if(path.endsWith('/partners'))return json([],200,{'X-Total-Count':'0'});
    if(path.endsWith('/accounts'))return json(path.includes(second)?[]:accounts,200,{'X-Total-Count':path.includes(second)?'0':'2'});
    if(path.endsWith('/periods'))return json(path.includes(second)?[]:periods,200,{'X-Total-Count':path.includes(second)?'0':'2'});
    if(path.includes('/reports/')){
      const ledger=path.includes('general-ledger'),isCsv=path.endsWith('.csv');
      if(isCsv){
        state.csvRequests.push({url,headers:req.headers()});
        const intentionallyDelayed=state.delayCsv;
        if(intentionallyDelayed)await new Promise<void>(resolve=>state.releaseCsv=resolve);
        if(state.csvStatus!==200)return json({violations:[{propertyPath:'export',message:'Výběr překračuje limit. Zúžte rozsah dat.'}]},state.csvStatus);
        try { await route.fulfill({contentType:'text/csv; charset=UTF-8',body:csv,headers:{'Content-Disposition':'attachment; filename="sestava_test.csv"'}}); }
        catch(error){if(!intentionallyDelayed)throw error;}
        return;
      }
      state.reportRequests.push(url);
      if(state.delayReport)await new Promise<void>(resolve=>state.releaseReport=resolve);
      if(state.reportStatus!==200)return json({},state.reportStatus);
      const period=periods.find(p=>p.id===url.searchParams.get('periodId'))??periods[0]!;
      const account=url.searchParams.get('accountId')??aid;
      const filters={periodId:period.id,periodName:period.name,periodStartsOn:period.startsOn,periodEndsOn:period.endsOn,
        from:url.searchParams.get('from'),to:url.searchParams.get('to'),includeZero:url.searchParams.get('includeZero')==='true',accountId:ledger?account:null,accountCode:ledger?(account===aid?'211':'221'):null,accountName:ledger?'Účet':null};
      const md='19999999999999999.98',negative=account===bid;
      const rows=Array.from({length:21},(_,i)=>({id:`line-${i}`,accountId:account,documentId:`doc-${i}`,number:`INT/20261101/${String(i+1).padStart(6,'0')}`,postingDate:'2026-11-04',lineNo:1,documentText:'Test převodu',text:'Pohyb '+(i+1),
        md:negative?'0.00':i===20?'0.18':'999999999999999.99',dal:negative?(i===20?'0.18':'999999999999999.99'):'0.00',runningBalance:(negative?'-':'')+(i===20?md:amount(BigInt(i+1)*99999999999999999n))}));
      const trial=[{accountId:aid,accountCode:'211',accountName:'Pokladna',openingMd:'0.00',openingDal:'0.00',turnoverMd:md,turnoverDal:'0.00',closingMd:md,closingDal:'0.00'},
        {accountId:bid,accountCode:'221',accountName:'Banka',openingMd:'0.00',openingDal:'0.00',turnoverMd:'0.00',turnoverDal:md,closingMd:'0.00',closingDal:md}];
      const p=Number(url.searchParams.get('page')??1);
      await json({accountingUnitId:unit,filters,generatedAt:'2026-11-04T12:00:00Z',currency:'CZK',basis:'posted_movements_within_period_no_carry_forward',
        totals:ledger?{openingBalance:'0.00',turnoverMd:negative?'0.00':md,turnoverDal:negative?md:'0.00',closingBalance:(negative?'-':'')+md}:
          {openingMd:'0.00',openingDal:'0.00',turnoverMd:md,turnoverDal:md,closingMd:md,closingDal:md,openingDifference:'0.00',turnoverDifference:'0.00',closingDifference:'0.00',balanced:true},
        items:state.empty?[]:ledger?rows.slice((p-1)*20,p*20):trial,total:state.empty?0:ledger?21:2,page:p,itemsPerPage:20}).catch(()=>{});
      state.finishedReports++;return;
    }
    if(path.endsWith('/history'))return json({items:[{id:'h',occurred_at:meta.createdAt,user_id:'user',operation:'U',changed_cols:['status'],old_data:{status:'draft'},new_data:{status:'posted'}}],total:1,page:1,itemsPerPage:10});
    if(path.includes('/documents/'))return json({...meta,id:path.split('/').at(-1),periodId:pid,postingDate:'2026-11-04',text:'Test převodu',partnerId:null,status:'posted',number:'INT/20261101/000001',postedAt:meta.createdAt,postedBy:'user',currency:'CZK',totalMd:'100.00',totalDal:'100.00',difference:'0.00',balanced:true,
      lines:[{accountId:aid,text:'MD',md:'100.00',dal:'0.00'},{accountId:bid,text:'DAL',md:'0.00',dal:'100.00'}]},200,{ETag:'"doc:1"'});
    return json({},404);
  });
  await page.clock.setFixedTime(new Date('2026-11-04T12:00:00Z'));
  await page.goto('/');await page.getByRole('button',{name:'Přihlásit přes Microsoft'}).click();await expect(page.getByRole('heading',{name:/Partneři/})).toBeVisible();
  return state;
}

test('hlavní kniha: přesné součty, stránkování, doklad pouze ke čtení a celý CSV',async({page},info)=>{
  const state=await setup(page);await nav(page,'Hlavní kniha');
  const summary=page.getByRole('region',{name:'Součty hlavní knihy'});
  await expect(summary).toContainText('19\u00a0999\u00a0999\u00a0999\u00a0999\u00a0999,98');
  await expect(page.getByLabel('Účetní období',{exact:true})).toHaveValue(pid);
  await page.getByRole('button',{name:'Další',exact:true}).click();await expect(page.locator('.pagination')).toContainText('2 / 2');
  await expect(summary).toContainText('19\u00a0999\u00a0999\u00a0999\u00a0999\u00a0999,98');
  await page.getByRole('button',{name:/INT\/20261101\/000021/}).click();
  await expect(page.getByRole('dialog')).toBeVisible();await expect(page.getByLabel('Popis dokladu')).toBeDisabled();
  await expect(page.getByRole('button',{name:'Uložit návrh'})).toHaveCount(0);await expect(page.getByRole('button',{name:'Zaúčtovat doklad'})).toHaveCount(0);
  await page.getByRole('button',{name:'Zobrazit účetní historii'}).click();await expect(page.getByRole('dialog').locator('.history-item')).toContainText('posted');
  await page.getByRole('button',{name:'Zavřít účetní detail'}).click();
  const transferFailures:string[]=[];
  page.on('requestfailed',request=>{if(request.url().includes('/reports/')&&request.url().includes('.csv'))transferFailures.push(request.failure()?.errorText??'Unknown transfer error');});
  const downloadPromise=Promise.race([
    page.waitForEvent('download'),
    page.getByRole('alert').waitFor({state:'visible'}).then(async()=>{throw new Error(`CSV nebylo staženo: ${await page.getByRole('alert').innerText()}; přenos: ${transferFailures.join(', ')||'bez síťového hlášení'}`);}),
  ]);
  const [file]=await Promise.all([downloadPromise,page.getByRole('button',{name:'Stáhnout CSV'}).click()]);
  expect(file.suggestedFilename()).toBe('sestava_test.csv');const bytes=await readFile((await file.path())!);expect(bytes).toEqual(csv);expect([...bytes.subarray(0,3)]).toEqual([239,187,191]);expect(bytes.toString('utf8')).toContain('data-21');expect(bytes.toString('utf8')).toContain('data-1;');
  expect(state.csvRequests).toHaveLength(1);expect(state.csvRequests[0]!.url.searchParams.has('page')).toBe(false);expect(state.csvRequests[0]!.url.searchParams.has('itemsPerPage')).toBe(false);expect(state.csvRequests[0]!.headers.authorization).toBe('Bearer browser-only');
  expect(state.writes).toBe(0);await page.screenshot({path:info.outputPath('hlavni-kniha.png'),fullPage:true});
  expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);
});

test('předvaha: kontrolní součty, nulové účty a přechod na účet se stejnými filtry',async({page},info)=>{
  const state=await setup(page);await nav(page,'Obratová předvaha');await expect(page.getByText('Součty MD a DAL jsou vyrovnané.')).toBeVisible();
  await page.getByLabel('Od',{exact:true}).fill('2026-11-04');await page.getByLabel('Do',{exact:true}).fill('2026-11-10');await page.getByLabel('Zobrazit i účty bez pohybů').check();
  await expect.poll(()=>state.reportRequests.at(-1)?.searchParams.get('includeZero')).toBe('true');
  await expect(page.getByRole('button',{name:'Stáhnout CSV'})).toBeEnabled();await page.screenshot({path:info.outputPath('predvaha.png'),fullPage:true});
  await page.getByRole('button',{name:'221 Banka',exact:true}).click();await expect(page.getByRole('heading',{name:'Hlavní kniha',exact:true})).toBeVisible();
  await expect(page.getByLabel('Účet',{exact:true})).toHaveValue(bid);await expect(page.getByLabel('Od',{exact:true})).toHaveValue('2026-11-04');await expect(page.getByLabel('Do',{exact:true})).toHaveValue('2026-11-10');
  await expect(page.getByRole('region',{name:'Součty hlavní knihy'})).toContainText('-19\u00a0999\u00a0999\u00a0999\u00a0999\u00a0999,98');
  expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);
});

test('validace filtrů, změna období a chyba exportu bez falešného souboru',async({page})=>{
  const state=await setup(page);await nav(page,'Hlavní kniha');await expect(page.getByRole('button',{name:'Stáhnout CSV'})).toBeEnabled();
  await page.getByLabel('Od',{exact:true}).fill('2026-10-31');await expect(page.getByRole('alert')).toContainText('Obě data musí ležet');await expect(page.getByRole('button',{name:'Stáhnout CSV'})).toBeDisabled();
  await page.getByLabel('Účetní období',{exact:true}).selectOption(nextPid);await expect(page.getByLabel('Od',{exact:true})).toHaveValue('2026-12-01');await expect(page.getByLabel('Do',{exact:true})).toHaveValue('2026-12-31');
  await expect(page.getByRole('button',{name:'Stáhnout CSV'})).toBeEnabled();state.csvStatus=422;
  const downloads:string[]=[];page.on('download',d=>downloads.push(d.suggestedFilename()));await page.getByRole('button',{name:'Stáhnout CSV'}).click();
  await expect(page.getByRole('alert')).toContainText('Zúžte rozsah dat.');expect(downloads).toEqual([]);
  state.reportStatus=503;await page.getByRole('button',{name:'Obnovit sestavu'}).click();await expect(page.getByRole('alert')).toContainText('dočasně nedostupná');await expect(page.getByRole('region',{name:'Součty hlavní knihy'})).toHaveCount(0);
  state.reportStatus=200;state.empty=true;await page.getByRole('button',{name:'Zkusit znovu'}).click();
  await expect(page.getByText('Ve vybraném rozsahu nejsou žádné zaúčtované pohyby účtu.')).toBeVisible();await expect(page.getByRole('button',{name:'Stáhnout CSV'})).toBeEnabled();
  state.csvStatus=403;await page.getByRole('button',{name:'Stáhnout CSV'}).click();await expect(page.getByText('Pro sestavy potřebujete oprávnění',{exact:false})).toBeVisible();await expect(page.getByRole('region',{name:'Součty hlavní knihy'})).toHaveCount(0);
});

test('opožděná sestava nepřepíše nový filtr a změna jednotky zruší CSV',async({page})=>{
  const state=await setup(page);await nav(page,'Hlavní kniha');await expect(page.getByRole('button',{name:'Stáhnout CSV'})).toBeEnabled();
  state.delayReport=true;await page.getByRole('button',{name:'Obnovit sestavu'}).click();await expect.poll(()=>!!state.releaseReport).toBe(true);
  state.delayReport=false;await page.getByLabel('Účet',{exact:true}).selectOption(bid);
  await expect(page.getByRole('region',{name:'Součty hlavní knihy'})).toContainText('-19\u00a0999\u00a0999\u00a0999\u00a0999\u00a0999,98');
  state.releaseReport!();await expect.poll(()=>state.finishedReports).toBeGreaterThanOrEqual(3);await expect(page.getByRole('region',{name:'Součty hlavní knihy'})).toContainText('-19\u00a0999\u00a0999\u00a0999\u00a0999\u00a0999,98');
  state.delayCsv=true;const downloads:string[]=[];page.on('download',d=>downloads.push(d.suggestedFilename()));await page.getByRole('button',{name:'Stáhnout CSV'}).click();await expect.poll(()=>state.csvRequests.length).toBe(1);
  await page.getByLabel('Účetní jednotka',{exact:true}).selectOption(second);state.releaseCsv!();
  await expect(page.getByText('Nejprve vytvořte účetní období v sekci Období.')).toBeVisible();await expect(page.getByRole('region',{name:'Součty hlavní knihy'})).toHaveCount(0);await expect(page.getByRole('button',{name:'Stáhnout CSV'})).toBeDisabled();expect(downloads).toEqual([]);
});

test('chybějící práva a vypršení přihlášení skryjí účetní data',async({page})=>{
  const state=await setup(page);await nav(page,'Hlavní kniha');await expect(page.getByRole('button',{name:'Stáhnout CSV'})).toBeEnabled();
  state.csvStatus=401;await page.getByRole('button',{name:'Stáhnout CSV'}).click();await expect(page.getByRole('button',{name:'Přihlásit přes Microsoft'})).toBeVisible();await expect(page.locator('#reports')).toHaveCount(0);
});

test('bez čtecích oprávnění nelze načíst ani exportovat sestavy',async({page})=>{
  const state=await setup(page,{allowed:false});await nav(page,'Obratová předvaha');await expect(page.getByText('Pro sestavy potřebujete oprávnění', {exact:false})).toBeVisible();
  await expect(page.getByRole('button',{name:'Stáhnout CSV'})).toHaveCount(0);expect(state.reportRequests).toHaveLength(0);expect(state.csvRequests).toHaveLength(0);
});
