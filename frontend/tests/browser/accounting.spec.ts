import {test,expect,type Page} from '@playwright/test';
const unit='11111111-1111-4111-8111-111111111111',second='22222222-2222-4222-8222-222222222222';
const aid='33333333-3333-4333-8333-333333333333',bid='44444444-4444-4444-8444-444444444444',pid='55555555-5555-4555-8555-555555555555';
const meta={accountingUnitId:unit,version:1,createdAt:'2026-11-04 08:00:00+00',updatedAt:'2026-11-04 08:00:00+00'};
async function setup(page:Page,{write=true,close=true,history=true}={}) {
  await page.route('**/src/auth.ts',r=>r.fulfill({contentType:'application/javascript',body:'export class SessionExpired extends Error {} export const auth={configured:true,initialize:async()=>{},login:async()=>{},logout:async()=>{},token:async()=>"browser-only"};'}));
  const accounts:any[]=[{...meta,id:aid,code:'211',name:'Pokladna',parentId:null,active:true,postable:true},{...meta,id:bid,code:'221',name:'Banka',parentId:null,active:true,postable:true}];
  const periods:any[]=[{...meta,id:pid,name:'Rok 2026',startsOn:'2026-01-01',endsOn:'2026-12-31',status:'open',closedAt:null,closedBy:null}];
  const documents:any[]=[],writes:{path:string;method:string;headers:Record<string,string>;body:any}[]=[];
  const saved=new Map<string,any>();let conflict=false,dropPost=false,dropCreate=false;
  await page.route('**/api/v1/**',async route=>{
    const req=route.request(),url=new URL(req.url()),path=url.pathname,method=req.method(),headers=req.headers();
    const json=(data:unknown,status=200,extra:Record<string,string>={})=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(data),headers:extra});
    if(path.endsWith('/me'))return json({id:'user',display_name:'Michal Novák'});
    if(path.endsWith('/accounting-units'))return json({items:[{id:unit,name:'Lípa studio',code:'LIPA',version:1},{id:second,name:'Druhá jednotka',code:'DRUHA',version:1}]});
    if(path.endsWith('/permissions'))return json({items:['core.partners.read',...['accounts','periods','documents'].flatMap(k=>[`core.ucto.${k}.read`,...(write?[`core.ucto.${k}.write`]:[]),...(history?[`core.ucto.${k}.history`]:[])]),...(write?['core.ucto.documents.post']:[]),...(close&&write?['core.ucto.periods.close']:[])]});
    if(path.endsWith('/partners'))return json([],200,{'X-Total-Count':'0'});
    const parts=path.split('/'),resource=parts[5]!,id=parts[6],command=parts[7];
    const collection=resource==='accounts'?accounts:resource==='periods'?periods:documents;
    if(command==='history')return json({items:[{id:'h',occurred_at:meta.createdAt,user_id:'user',operation:'U',changed_cols:['status'],old_data:{status:'draft'},new_data:{status:'posted'}}],total:1,page:1,itemsPerPage:10});
    if(method==='GET'&&!id){
      let data:any[];
      if(path.includes(second))data=[];
      else if(resource==='journal')data=documents.filter(d=>d.status==='posted').flatMap(d=>d.lines.map((l:any,i:number)=>({id:d.id+'-'+i,documentId:d.id,accountingUnitId:unit,periodId:d.periodId,number:d.number,postingDate:d.postingDate,lineNo:i+1,accountId:l.accountId,accountCode:accounts.find(a=>a.id===l.accountId)?.code,accountName:'Účet',documentText:d.text,...l}))).filter(l=>(!url.searchParams.get('accountId')||l.accountId===url.searchParams.get('accountId'))&&(!url.searchParams.get('periodId')||l.periodId===url.searchParams.get('periodId')));
      else data=collection.filter(r=>(r.name??r.text??'').toLowerCase().includes((url.searchParams.get('search')??'').toLowerCase())&&(!url.searchParams.get('periodId')||r.periodId===url.searchParams.get('periodId')));
      const size=Number(url.searchParams.get('itemsPerPage')??20),p=Number(url.searchParams.get('page')??1);
      return json(data.slice((p-1)*size,p*size),200,{'X-Total-Count':String(data.length)});
    }
    const record=collection.find(r=>r.id===id);
    if(method==='GET')return record?json(record,200,{ETag:`"${id}:${record.version}"`}):json({},404);
    const body=req.postDataJSON();writes.push({path,method,headers,body});
    const key=path+':'+headers['idempotency-key'];
    if(method==='POST'&&saved.has(key)){const cached=saved.get(key);return json(cached,command?200:201,{ETag:`"${cached.id}:${cached.version}"`});}
    if(id&&headers['if-match']!==`"${id}:${record?.version}"`)return json({},412);
    if(command==='post'){
      if(record.totalMd!==record.totalDal||record.lines.length<2)return json({},409);
      Object.assign(record,{status:'posted',number:'INT/20260101/000001',postedAt:meta.createdAt,postedBy:'user',version:record.version+1});saved.set(key,structuredClone(record));
      if(dropPost){dropPost=false;return route.abort('failed');}
    }else if(command==='close'){
      if(documents.some(d=>d.periodId===id&&d.status==='draft'))return json({},409);
      Object.assign(record,{status:'closed',closedAt:meta.createdAt,closedBy:'user',version:record.version+1});saved.set(key,structuredClone(record));
    }else if(method==='PATCH'){
      if(conflict){conflict=false;Object.assign(record,{text:'Změna ze serveru',partnerId:null,version:record.version+1});return json({},412);}
      Object.assign(record,body,{version:record.version+1});
    }else{
      const created={...meta,id:`66666666-6666-4666-8666-${String(documents.length+accounts.length+periods.length).padStart(12,'0')}`,status:resource==='documents'?'draft':'open',number:null,postedAt:null,postedBy:null,closedAt:null,closedBy:null,currency:'CZK',...body};
      if(resource==='documents')refresh(created);
      collection.push(created);saved.set(key,structuredClone(created));
      if(dropCreate){dropCreate=false;return route.abort('failed');}
      return json(created,201,{ETag:`"${created.id}:1"`});
    }
    if(resource==='documents')refresh(record);
    return json(record,200,{ETag:`"${id}:${record.version}"`});
  });
  function refresh(d:any){d.totalMd=d.lines.reduce((s:number,l:any)=>s+Number(l.md),0).toFixed(2);d.totalDal=d.lines.reduce((s:number,l:any)=>s+Number(l.dal),0).toFixed(2);d.difference=(Number(d.totalMd)-Number(d.totalDal)).toFixed(2);d.balanced=d.difference==='0.00';}
  await page.goto('/');await page.getByRole('button',{name:'Přihlásit přes Microsoft'}).click();await expect(page.getByRole('heading',{name:/Partneři/})).toBeVisible();
  return {accounts,periods,documents,writes,conflict:()=>{conflict=true;},dropPost:()=>{dropPost=true;},dropCreate:()=>{dropCreate=true;}};
}
const nav=(page:Page,name:string)=>page.getByRole('navigation',{name:'Sekce aplikace'}).getByRole('button',{name,exact:true}).click();
async function createDraft(page:Page,dal='99.00'){
  await nav(page,'Doklady');await page.getByRole('button',{name:'Nový doklad'}).click();
  await page.getByRole('button',{name:'Uložit návrh'}).click();await expect(page.getByText('Vyplňte popis do 2 000 znaků.',{exact:false})).toBeVisible();
  await page.getByRole('combobox',{name:'Účetní období',exact:true}).selectOption(pid);await page.getByLabel('Datum účtování').fill('2026-11-04');await page.getByLabel('Popis dokladu').fill('Převod do banky');
  await page.getByRole('button',{name:'Přidat řádek'}).click();await page.getByRole('combobox',{name:'Účet řádku 1',exact:true}).selectOption(aid);await page.getByLabel('MD řádek 1',{exact:true}).fill('100.00');
  await page.getByRole('button',{name:'Přidat řádek'}).click();await page.getByRole('combobox',{name:'Účet řádku 2',exact:true}).selectOption(bid);await page.getByLabel('DAL řádek 2',{exact:true}).fill(dal);
  await page.getByRole('button',{name:'Uložit návrh'}).click();await expect(page.getByRole('dialog').getByText('Záznam byl uložen.',{exact:true})).toBeVisible();
}
test('návrh, úprava řádku, zaúčtování, deník, historie a uzavření',async({page},info)=>{
  const server=await setup(page);await createDraft(page);await expect(page.getByRole('button',{name:'Zaúčtovat doklad'})).toBeDisabled();
  await page.screenshot({path:info.outputPath('rozpracovany-doklad.png'),fullPage:true});
  await page.getByLabel('DAL řádek 2',{exact:true}).fill('100,00');await page.getByRole('button',{name:'Uložit návrh'}).click();await expect(page.getByRole('button',{name:'Zaúčtovat doklad'})).toBeEnabled();
  page.once('dialog',d=>d.accept());await page.getByRole('button',{name:'Zaúčtovat doklad'}).click();await expect(page.locator('.record-state')).toContainText('INT/20260101/000001');await expect(page.getByLabel('Popis dokladu')).toBeDisabled();
  await page.getByRole('button',{name:'Zobrazit účetní historii'}).click();await expect(page.getByRole('dialog').getByText('draft',{exact:true})).toBeVisible();
  await page.screenshot({path:info.outputPath('zauctovany-doklad.png'),fullPage:true});
  await page.getByRole('button',{name:'Zavřít účetní detail'}).click();await nav(page,'Deník');await expect(page.getByRole('button',{name:/INT\/20260101\/000001/})).toHaveCount(2);
  await page.getByLabel('Filtr účtu').selectOption(aid);await expect(page.getByRole('button',{name:/INT\/20260101\/000001/})).toHaveCount(1);
  await page.getByRole('button',{name:/INT\/20260101\/000001/}).click();await expect(page.getByLabel('Popis dokladu')).toHaveValue('Převod do banky');await page.getByRole('button',{name:'Zavřít účetní detail'}).click();
  await nav(page,'Období');await page.getByRole('button',{name:'Rok 2026',exact:true}).click();page.once('dialog',d=>d.accept());await page.getByRole('button',{name:'Uzavřít období'}).click();await expect(page.locator('.record-state')).toHaveText('Uzavřené období');
  expect(server.documents[0].status).toBe('posted');expect(server.periods[0].status).toBe('closed');
  expect(await page.evaluate(()=>document.documentElement.scrollWidth<=(visualViewport?.width??innerWidth)+1)).toBe(true);
});
test('nejisté zaúčtování zachová klíč i verzi a blokuje změnu jednotky',async({page})=>{
  const server=await setup(page);await createDraft(page,'100.00');server.dropPost();page.once('dialog',d=>d.accept());await page.getByRole('button',{name:'Zaúčtovat doklad'}).click();
  await expect(page.getByRole('button',{name:'Opakovat původní požadavek'})).toBeVisible();await expect(page.getByLabel('Účetní jednotka',{exact:true})).toBeDisabled();await expect(page.getByLabel('Popis dokladu')).toBeDisabled();
  await page.getByRole('button',{name:'Opakovat původní požadavek'}).click();await expect(page.locator('.record-state')).toContainText('Zaúčtováno');
  const posts=server.writes.filter(w=>w.path.endsWith('/post'));expect(posts).toHaveLength(2);expect(posts[0]!.headers['idempotency-key']).toBe(posts[1]!.headers['idempotency-key']);expect(posts[0]!.headers['if-match']).toBe(posts[1]!.headers['if-match']);expect(posts[0]!.body).toEqual({});expect(server.documents).toHaveLength(1);
});
test('ztracená odpověď vytvoření nevytvoří druhý návrh',async({page})=>{
  const server=await setup(page);await nav(page,'Doklady');await page.getByRole('button',{name:'Nový doklad'}).click();
  await page.getByRole('combobox',{name:'Účetní období',exact:true}).selectOption(pid);await page.getByLabel('Datum účtování').fill('2026-11-04');await page.getByLabel('Popis dokladu').fill('Nejistý návrh');server.dropCreate();await page.getByRole('button',{name:'Uložit návrh'}).click();
  await expect(page.getByRole('button',{name:'Opakovat původní požadavek'})).toBeVisible();await expect(page.getByRole('button',{name:'Zavřít účetní detail'})).toBeDisabled();
  await page.getByRole('button',{name:'Opakovat původní požadavek'}).click();await expect(page.getByRole('dialog').getByText('Záznam byl uložen.',{exact:true})).toBeVisible();
  expect(server.documents).toHaveLength(1);expect(server.writes[0]!.headers['idempotency-key']).toBe(server.writes[1]!.headers['idempotency-key']);expect(server.writes[0]!.body).toEqual(server.writes[1]!.body);
});
test('formulář období validuje rozsah a účetní nemá uzavření',async({page})=>{
  await setup(page,{close:false});await nav(page,'Období');await page.getByRole('button',{name:'Nové období'}).click();await page.getByLabel('Název období').fill('Rok 2027');await page.getByLabel('Začátek období').fill('2027-01-01');await page.getByLabel('Konec období').fill('2026-12-31');await page.getByRole('button',{name:'Uložit záznam'}).click();
  await expect(page.getByText('Konec musí být platné datum po začátku období.',{exact:false})).toBeVisible();await page.getByLabel('Konec období').fill('2027-12-31');await page.getByRole('button',{name:'Uložit záznam'}).click();await expect(page.getByRole('dialog').getByText('Záznam byl uložen.',{exact:true})).toBeVisible();await expect(page.getByRole('button',{name:'Uzavřít období'})).toHaveCount(0);
});
test('konflikt verze porovná změny a přenese vědomou úpravu s novým ETag',async({page})=>{
  const server=await setup(page);await createDraft(page,'100.00');await page.getByLabel('Popis dokladu').fill('Moje změna');server.conflict();await page.getByRole('button',{name:'Uložit návrh'}).click();
  await expect(page.getByRole('heading',{name:'Záznam se mezitím změnil'})).toBeVisible();await expect(page.getByText('Změna ze serveru',{exact:true})).toBeVisible();await page.getByRole('button',{name:'Ponechat moje změny'}).click();await page.getByRole('button',{name:'Uložit návrh'}).click();
  expect(server.writes.at(-1)!.headers['if-match']).toContain(':2"');expect(server.writes.at(-1)!.body).toEqual({text:'Moje změna'});
});
test('účty zachovají úvodní nuly a čtenář nemá zápis',async({page},info)=>{
  const server=await setup(page);await nav(page,'Účty');await page.getByRole('button',{name:'Nový účet'}).click();await page.getByLabel('Kód účtu',{exact:true}).fill('001');await page.getByLabel('Název účtu',{exact:true}).fill('Pomocný účet');await page.getByRole('button',{name:'Uložit záznam'}).click();await expect(page.getByRole('dialog').getByText('Záznam byl uložen.',{exact:true})).toBeVisible();expect(server.accounts.at(-1).code).toBe('001');
  await page.getByRole('button',{name:'Zavřít účetní detail'}).click();await page.screenshot({path:info.outputPath('uctova-osnova.png'),fullPage:true});
  await page.unrouteAll({behavior:'wait'});await setup(page,{write:false,close:false,history:false});await nav(page,'Účty');await expect(page.getByRole('button',{name:'Nový účet'})).toHaveCount(0);await page.getByRole('button',{name:/211 Pokladna/}).click();await expect(page.getByLabel('Název účtu')).toBeDisabled();await expect(page.getByRole('button',{name:'Zobrazit účetní historii'})).toHaveCount(0);await page.keyboard.press('Escape');await expect(page.getByRole('dialog')).not.toBeVisible();
});
test('odmítnuté uzavření a opožděná odpověď ze staré jednotky',async({page})=>{
  await setup(page);await createDraft(page);await page.getByRole('button',{name:'Zavřít účetní detail'}).click();await nav(page,'Období');await page.getByRole('button',{name:'Rok 2026',exact:true}).click();page.once('dialog',d=>d.accept());await page.getByRole('button',{name:'Uzavřít období'}).click();await expect(page.getByText('Období nelze uzavřít.',{exact:false})).toBeVisible();await page.getByRole('button',{name:'Zavřít účetní detail'}).click();
  await nav(page,'Doklady');let release!:()=>void;const gate=new Promise<void>(r=>{release=r;});let arrived!:()=>void;const pending=new Promise<void>(r=>{arrived=r;});
  await page.route(`**/accounting-units/${unit}/documents?**`,async r=>{arrived();await gate;await r.fulfill({contentType:'application/json',body:'[]',headers:{'X-Total-Count':'0'}}).catch(()=>{});});
  await page.getByRole('searchbox',{name:'Hledat: Doklady'}).fill('stará');await pending;await page.getByLabel('Účetní jednotka',{exact:true}).selectOption(second);await expect(page.getByRole('heading',{name:'Žádné odpovídající záznamy'})).toBeVisible();release();await expect(page.getByRole('button',{name:/Návrh bez čísla/})).toHaveCount(0);
});
test('vypršení přihlášení z účetní sekce vymaže data i jednotku',async({page})=>{
  await setup(page);await createDraft(page,'100.00');await page.getByRole('button',{name:'Zavřít účetní detail'}).click();
  await page.route(`**/accounting-units/${unit}/documents?**`,r=>r.fulfill({status:401,contentType:'application/problem+json',body:'{"status":401}'}));
  await page.getByRole('button',{name:'Obnovit účetní seznam'}).click();await expect(page.getByRole('button',{name:'Přihlásit přes Microsoft'})).toBeVisible();await expect(page.getByLabel('Účetní jednotka',{exact:true})).toHaveCount(0);await expect(page.getByText('Převod do banky',{exact:true})).toHaveCount(0);
});
test('odebrání oprávnění během nejistého zápisu uvolní bezpečný odchod',async({page})=>{
  const server=await setup(page);await createDraft(page,'100.00');server.dropPost();page.once('dialog',d=>d.accept());await page.getByRole('button',{name:'Zaúčtovat doklad'}).click();await expect(page.getByRole('button',{name:'Opakovat původní požadavek'})).toBeVisible();
  await page.route('**/documents/*/post',r=>r.fulfill({status:403,contentType:'application/problem+json',body:'{"status":403}'}));await page.getByRole('button',{name:'Opakovat původní požadavek'}).click();
  await expect(page.getByRole('dialog')).not.toBeVisible();await expect(page.getByText('Pro tuto sekci nemáte oprávnění ke čtení.')).toBeVisible();await expect(page.getByLabel('Účetní jednotka',{exact:true})).toBeEnabled();await expect(page.getByRole('button',{name:'Odhlásit z aplikace ↗'})).toBeEnabled();
  await expect(page.locator('dialog textarea')).toHaveValue('');
});
test('výpadek podkladů formuláře dovolí jejich nové načtení',async({page})=>{
  await setup(page);let fail=true;await page.route('**/accounts?**',r=>{if(fail)return r.abort('failed');return r.fallback();});await nav(page,'Doklady');await expect(page.getByRole('main').getByText('Podklady formuláře se nepodařilo načíst.',{exact:false})).toBeVisible();await expect(page.getByRole('button',{name:'Nový doklad'})).toBeDisabled();
  fail=false;await page.getByRole('button',{name:'Načíst podklady',exact:true}).click();await expect(page.getByRole('button',{name:'Nový doklad'})).toBeEnabled();
});
