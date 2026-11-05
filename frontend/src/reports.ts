import type { components } from './generated/accounting';
import { validDate } from './accounting';
export type ReportSection='general-ledger'|'trial-balance';
export type LedgerReport=components['schemas']['GeneralLedgerReport'];
export type TrialReport=components['schemas']['TrialBalanceReport'];
export type ReportSelection={periodId:string;accountId:string;from:string;to:string;includeZero:boolean};
export const isReportSection=(value:string):value is ReportSection=>value==='general-ledger'||value==='trial-balance';
export const reportPermissions=['core.units.read','core.ucto.documents.read','core.ucto.accounts.read','core.ucto.periods.read'];
export function reportErrors(selection:ReportSelection,ledger:boolean,period?:{startsOn:string;endsOn:string}):string[] {
  const errors:string[]=[];
  if(!period)errors.push('Vyberte účetní období.');
  if(ledger&&!selection.accountId)errors.push('Vyberte účet.');
  if(!validDate(selection.from)||!validDate(selection.to))errors.push('Vyplňte platná data Od a Do.');
  else if(selection.from>selection.to)errors.push('Datum Od nesmí být pozdější než Do.');
  else if(period&&(selection.from<period.startsOn||selection.to>period.endsOn))errors.push('Obě data musí ležet ve vybraném období.');
  return errors;
}
export function reportParams(selection:ReportSelection,ledger:boolean,page?:number):URLSearchParams {
  const q=new URLSearchParams({periodId:selection.periodId,from:selection.from,to:selection.to});
  if(ledger)q.set('accountId',selection.accountId);else q.set('includeZero',String(selection.includeZero));
  if(page!==undefined){q.set('page',String(page));q.set('itemsPerPage','20');}
  return q;
}
