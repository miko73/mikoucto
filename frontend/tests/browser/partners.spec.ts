import { test, expect, type Page } from '@playwright/test';
const unit='11111111-1111-4111-8111-111111111111', second='22222222-2222-4222-8222-222222222222';
const sample={id:'33333333-3333-4333-8333-333333333333',accountingUnitId:unit,name:'Lípa studio s.r.o.',registrationNumber:'12345678',vatNumber:'CZ12345678',countryCode:'CZ',street:'Dlouhá 12',city:'Praha',postalCode:'110 00',email:'info@lipa.example',phone:'+420 123 456 789',active:true,version:1,createdAt:'2026-11-02 10:00:00+00',updatedAt:'2026-11-02 10:00:00+00'};
async function setup(page:Page,{write=true,history=true}={}) {
  // Test-only network substitution: production auth code contains no fake login or token path.
  await page.route('**/src/auth.ts',route=>route.fulfill({contentType:'application/javascript',body:'export class SessionExpired extends Error {} export const auth = { configured:true, initialize:async()=>{}, login:async()=>{}, logout:async()=>{}, token:async()=>"browser-test-only" };'}));
  let record={...sample}; const writes:{method:string;body:Record<string,unknown>;headers:Record<string,string>}[]=[];
  let conflict=false, failCreate=false, invalidCreate=false;
  await page.route('**/api/v1/**',async route=>{
    const req=route.request(),url=new URL(req.url()),path=url.pathname;
    const json=(value:unknown,status=200,headers:Record<string,string>={})=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(value),headers});
    if(path.endsWith('/me')) return json({id:'test-user',display_name:'Michal Novák'});
    if(path.endsWith('/accounting-units')) return json({items:[{id:unit,code:'LIPA',name:'Lípa studio',version:1},{id:second,code:'DRUHA',name:'Druhá jednotka',version:1}]});
    if(path.endsWith('/permissions')) return json({items:['core.partners.read',...(write?['core.partners.write']:[]),...(history?['core.partners.history']:[])]});
    if(path.endsWith('/history')) return json({items:[{id:'event1',occurred_at:sample.createdAt,user_id:'test-user',operation:'I',changed_cols:['name'],old_data:null,new_data:{name:record.name}}],total:1,page:1,itemsPerPage:10});
    if(req.method()==='POST') {
      writes.push({method:'POST',body:req.postDataJSON(),headers:req.headers()});
      if(failCreate){failCreate=false;return route.abort('failed');}
      if(invalidCreate){invalidCreate=false;return json({violations:[{propertyPath:'email',message:'Neplatný kontakt.'}]},422);}
      record={...record,...req.postDataJSON(),version:1};return json(record,201,{ETag:`"${record.id}:1"`});
    }
    if(req.method()==='PATCH') {
      writes.push({method:'PATCH',body:req.postDataJSON(),headers:req.headers()});
      if(conflict){conflict=false;record={...record,email:'jiny@example.cz',version:2};return json({},412);}
      record={...record,...req.postDataJSON(),version:record.version+1};return json(record,200,{ETag:`"${record.id}:${record.version}"`});
    }
    if(path.endsWith('/partners')) {
      const query=url.searchParams.get('search') ?? '',active=url.searchParams.get('active');
      const items=path.includes(second) ? [] : record.name.toLowerCase().includes(query.toLowerCase()) && (active===null || String(record.active)===active) ? [record] : [];
      return json(items,200,{'X-Total-Count':String(items.length)});
    }
    return json(record,200,{ETag:`"${record.id}:${record.version}"`});
  });
  await page.goto('/');await page.getByRole('button',{name:'Přihlásit přes Microsoft'}).click();
  await expect(page.getByRole('button',{name:sample.name,exact:false}).first()).toBeVisible();
  return {writes,conflict:()=>{conflict=true;},failCreate:()=>{failCreate=true;},invalidCreate:()=>{invalidCreate=true;}};
}
test('adresář, hledání, filtr a přepnutí jednotky',async({page})=>{
  await setup(page);await page.getByRole('searchbox').fill('neexistuje');await expect(page.getByText('Žádný partner neodpovídá filtru')).toBeVisible();
  await page.getByRole('searchbox').fill('');await page.getByLabel('Stav partnera').selectOption('false');await expect(page.getByText('Žádný partner neodpovídá filtru')).toBeVisible();
  await page.getByLabel('Stav partnera').selectOption('');await page.getByLabel('Účetní jednotka',{exact:true}).selectOption(second);await expect(page.getByText('Adresář čeká na prvního partnera')).toBeVisible();
  await expect(page.getByText(sample.email,{exact:true})).toHaveCount(0);
  expect(await page.evaluate(()=>document.documentElement.scrollWidth <= (visualViewport?.width ?? innerWidth)+1)).toBe(true);
});
test('vytvoření, validace a opakování se stejným klíčem',async({page})=>{
  const server=await setup(page);await page.getByRole('button',{name:'Nový partner'}).click();await page.getByRole('button',{name:'Uložit partnera'}).click();await expect(page.getByText('Vyplňte název partnera.')).toBeVisible();
  await page.getByLabel('Název partnera').fill('Nový kontakt');server.invalidCreate();await page.getByRole('button',{name:'Uložit partnera'}).click();await expect(page.getByText('Neplatný kontakt.')).toBeVisible();
  await page.getByLabel('E-mail',{exact:true}).fill('kontakt@example.cz');server.failCreate();await page.getByRole('button',{name:'Uložit partnera'}).click();await expect(page.getByRole('button',{name:'Opakovat stejný požadavek'})).toBeVisible();await expect(page.getByLabel('Název partnera')).toBeDisabled();
  await page.getByRole('button',{name:'Opakovat stejný požadavek'}).click();await expect(page.getByText('Partner byl uložen.',{exact:false})).toBeVisible();
  expect(server.writes[1].headers['idempotency-key']).toBe(server.writes[2].headers['idempotency-key']);expect(server.writes[1].body).toEqual(server.writes[2].body);
});
test('konflikt zachová cizí kontakt, historii a deaktivaci',async({page})=>{
  const server=await setup(page);await page.getByRole('button',{name:`Otevřít ${sample.name}`}).click();await expect(page.getByLabel('Město',{exact:true})).toBeEnabled();
  await page.getByLabel('Město',{exact:true}).fill('Brno');server.conflict();await page.getByRole('button',{name:'Uložit partnera'}).click();await expect(page.getByText('Partner se mezitím změnil')).toBeVisible();
  await page.getByRole('button',{name:'Ponechat moje změny'}).click();await expect(page.getByLabel('E-mail',{exact:true})).toHaveValue('jiny@example.cz');await page.getByRole('button',{name:'Uložit partnera'}).click();
  expect(server.writes[1].headers['if-match']).toBe(`"${sample.id}:2"`);expect(server.writes[1].body).toEqual({city:'Brno'});
  await page.getByRole('button',{name:`Otevřít ${sample.name}`}).click();await page.getByRole('button',{name:'Zobrazit historii'}).click();await expect(page.getByText('Partner vytvořen')).toBeVisible();
  page.once('dialog',dialog=>dialog.accept());await page.getByRole('button',{name:'Deaktivovat',exact:true}).click();await expect(page.locator('.status.inactive')).toBeVisible();expect(server.writes.at(-1)?.body).toEqual({active:false});
});
test('čtenář nemá zápis ani historii, dialog lze zavřít klávesnicí',async({page})=>{
  await setup(page,{write:false,history:false});await expect(page.getByRole('button',{name:'Nový partner'})).toHaveCount(0);await page.getByRole('button',{name:`Otevřít ${sample.name}`}).click();await expect(page.getByLabel('Název partnera')).toBeDisabled();await expect(page.getByRole('button',{name:'Zobrazit historii'})).toHaveCount(0);await page.keyboard.press('Escape');await expect(page.getByRole('dialog')).not.toBeVisible();
});
test('snímek pracovního prostoru',async({page},testInfo)=>{
  await setup(page);await page.screenshot({path:testInfo.outputPath('partneri.png'),fullPage:true});
});
test('rozložení formuláře',async({page},testInfo)=>{
  await setup(page);await page.getByRole('button',{name:'Nový partner'}).click();
  expect(await page.evaluate(()=>document.querySelector('.editor')!.getBoundingClientRect().width <= (visualViewport?.width ?? innerWidth)+1)).toBe(true);
  await page.screenshot({path:testInfo.outputPath('formular.png'),fullPage:true});
});
test('vypršení přihlášení vymaže partnera i jednotku',async({page})=>{
  await setup(page);
  await page.route('**/api/v1/**/permissions',route=>route.fulfill({status:401,contentType:'application/problem+json',body:'{"status":401}'}));
  await page.getByRole('button',{name:'Obnovit seznam'}).click();
  await expect(page.getByRole('button',{name:'Přihlásit přes Microsoft'})).toBeVisible();
  await expect(page.getByText(sample.email,{exact:true})).toHaveCount(0);
  await expect(page.getByLabel('Účetní jednotka',{exact:true})).toHaveCount(0);
});
test('výpadek načtení oprávnění nehlásí ztrátu přístupu ani nejistý zápis a lze jej obnovit',async({page})=>{
  await setup(page);
  let fail=true;
  await page.route('**/api/v1/**/permissions',route=>{
    if(fail){fail=false;return route.abort('failed');}
    return route.fallback();
  });
  await page.getByRole('button',{name:'Obnovit seznam'}).click();
  await expect(page.getByText('Adresář se nepodařilo načíst',{exact:true})).toBeVisible();
  await expect(page.getByText('Oprávnění neověřena',{exact:true})).toBeVisible();
  await expect(page.getByText('Výsledek zápisu může být nejistý.',{exact:false})).toHaveCount(0);
  await page.getByRole('button',{name:'Zkusit znovu'}).click();
  await expect(page.getByRole('button',{name:'Nový partner',exact:true})).toBeVisible();
  await expect(page.getByRole('button',{name:`Otevřít ${sample.name}`})).toBeVisible();
});
test('opožděná odpověď ze staré jednotky se nezobrazí',async({page})=>{
  await setup(page);
  let release!:()=>void;
  const gate = new Promise<void>(resolve=>{release=resolve;});
  let arrived!:()=>void;
  const pending = new Promise<void>(resolve=>{arrived=resolve;});
  await page.route(`**/accounting-units/${unit}/partners?**`,async route=>{
    arrived();await gate;
    await route.fulfill({contentType:'application/json',body:JSON.stringify([{...sample,name:'Stará odpověď'}]),headers:{'X-Total-Count':'1'}}).catch(()=>{});
  });
  await page.getByRole('searchbox').fill('Stará');await pending;
  await page.getByLabel('Účetní jednotka',{exact:true}).selectOption(second);
  await expect(page.getByText('Žádný partner neodpovídá filtru')).toBeVisible();release();
  await expect(page.getByRole('button',{name:'Stará odpověď',exact:false})).toHaveCount(0);
});
