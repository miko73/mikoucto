import type { components } from './generated/accounting';
export type Account = Required<components['schemas']['Account']>;
export type Period = Required<components['schemas']['Period']>;
export interface Line { accountId:string; text:string; md:string; dal:string }
export type Document = Omit<Required<components['schemas']['Document']>,'lines'> & {lines:Line[]};
export type Summary = Required<components['schemas']['Document.DocumentSummary']>;
export type Journal = Required<components['schemas']['JournalEntry']>;
export type Section = 'accounts'|'periods'|'documents'|'journal';
export type Kind = Exclude<Section,'journal'>;
export type RecordItem = Account|Period|Document|Summary;
export interface Form {
  code:string; name:string; active:boolean; postable:boolean; parentId:string;
  startsOn:string; endsOn:string; periodId:string; postingDate:string; text:string; partnerId:string; lines:Line[];
}
export function blank(): Form {
  const now=new Date(), year=now.getFullYear();
  return {code:'',name:'',active:true,postable:true,parentId:'',startsOn:`${year}-01-01`,endsOn:`${year}-12-31`,periodId:'',
    postingDate:`${year}-${String(now.getMonth()+1).padStart(2,'0')}-${String(now.getDate()).padStart(2,'0')}`,text:'',partnerId:'',lines:[]};
}
export function formOf(record?:RecordItem): Form {
  const form=blank(); if (!record) return form;
  for (const key of Object.keys(form) as (keyof Form)[]) {
    if (key==='lines') continue;
    if (key in record) Object.assign(form,{[key]:structuredClone((record as unknown as Record<string,unknown>)[key]) ?? ''});
  }
  if ('lines' in record) form.lines=record.lines.map(l=>({accountId:l.accountId,text:l.text,md:l.md,dal:l.dal}));
  return form;
}
// Exact integer cents, including amounts above JavaScript's safe integer range.
export function cents(value:string): bigint|null {
  const text=value.trim().replace(',','.');
  if (!/^\d{1,16}(\.\d{1,2})?$/.test(text)) return null;
  const [whole,part='']=text.split('.'); return BigInt(whole!)*100n+BigInt(part.padEnd(2,'0'));
}
export function decimal(value:bigint): string {
  const sign=value<0n?'-':''; const n=value<0n?-value:value;
  return `${sign}${n/100n}.${String(n%100n).padStart(2,'0')}`;
}
export function money(value:string): string {
  const match=/^(-?)(\d+)(?:\.(\d{1,2}))?$/.exec(value); if (!match) return value;
  return `${match[1]}${match[2]!.replace(/\B(?=(\d{3})+(?!\d))/g,'\u00a0')},${(match[3]??'').padEnd(2,'0')}`;
}
export function totals(lines:Line[]) {
  let md=0n,dal=0n,valid=true;
  for (const line of lines) { const m=cents(line.md),d=cents(line.dal); if (m===null||d===null) valid=false; else {md+=m;dal+=d;} }
  return {md:decimal(md),dal:decimal(dal),difference:decimal(md-dal),balanced:valid&&md===dal,valid};
}
export function payload(kind:Kind,form:Form): Record<string,unknown> {
  if (kind==='accounts') return {code:form.code.trim(),name:form.name.trim(),active:form.active,postable:form.postable,parentId:form.parentId||null};
  if (kind==='periods') return {name:form.name.trim(),startsOn:form.startsOn,endsOn:form.endsOn};
  return {periodId:form.periodId,postingDate:form.postingDate,text:form.text.trim(),partnerId:form.partnerId||null,
    lines:form.lines.map(l=>({accountId:l.accountId,text:l.text.trim(),md:cents(l.md)===null?l.md:decimal(cents(l.md)!),dal:cents(l.dal)===null?l.dal:decimal(cents(l.dal)!)}))};
}
export function diff(base:Record<string,unknown>,next:Record<string,unknown>) {
  return Object.fromEntries(Object.entries(next).filter(([key,value])=>JSON.stringify(base[key])!==JSON.stringify(value)));
}
export function validDate(value:string) { return /^\d{4}-\d{2}-\d{2}$/.test(value)&&!Number.isNaN(Date.parse(value))&&new Date(value).toISOString().slice(0,10)===value; }
export function validate(kind:Kind,form:Form): Record<string,string> {
  const errors:Record<string,string>={};
  if (kind!=='documents' && (!form.name.trim()||form.name.trim().length>200)) errors.name='Vyplňte název do 200 znaků.';
  if (kind==='accounts' && !/^\d{1,20}$/.test(form.code.trim())) errors.code='Kód musí obsahovat 1 až 20 číslic. Úvodní nuly se zachovají.';
  if (kind==='periods') {
    if (!validDate(form.startsOn)) errors.startsOn='Vyplňte platný začátek období.';
    if (!validDate(form.endsOn)||form.endsOn<form.startsOn) errors.endsOn='Konec musí být platné datum po začátku období.';
  }
  if (kind==='documents') {
    if (!form.periodId) errors.periodId='Vyberte otevřené období.';
    if (!validDate(form.postingDate)) errors.postingDate='Vyplňte platné datum.';
    if (!form.text.trim()||form.text.trim().length>2000) errors.text='Vyplňte popis do 2 000 znaků.';
    if (form.lines.length>500) errors.lines='Doklad může mít nejvýše 500 řádků.';
    form.lines.forEach((l,i)=>{
      const md=cents(l.md),dal=cents(l.dal);
      if (!l.accountId||md===null||dal===null||((md===0n)===(dal===0n))||l.text.length>500)
        errors[`lines.${i}`]='Vyberte účet a vyplňte kladnou částku právě na jedné straně (nejvýše 16 celých a 2 desetinné číslice).';
    });
  }
  return errors;
}
export interface FrozenWrite {path:string;method:'POST'|'PATCH';body:string;headers:Record<string,string>}
export class WriteAttempt {
  current?:FrozenWrite;
  uncertain=false;
  prepare(path:string,method:'POST'|'PATCH',data:Record<string,unknown>,etag=''): FrozenWrite {
    const body=JSON.stringify(data), previous=this.current;
    const same=previous?.path===path&&previous.method===method&&previous.body===body&&(previous.headers['If-Match']??'')===etag;
    if (this.uncertain&&!same) throw new Error('Nejdříve ověřte výsledek předchozího zápisu.');
    if (!same) this.current={path,method,body,headers:{'Content-Type':method==='PATCH'?'application/merge-patch+json':'application/json',
      ...(etag?{'If-Match':etag}:{}),...(method==='POST'?{'Idempotency-Key':crypto.randomUUID()}:{})}};
    return this.current!;
  }
  reset() {this.current=undefined;this.uncertain=false;}
}
