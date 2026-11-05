import { describe, expect, it } from 'vitest';
import { reportErrors, reportParams, type ReportSelection } from '../../src/reports';
const selection:ReportSelection={periodId:'p',accountId:'a',from:'2026-11-01',to:'2026-11-30',includeZero:true};
const period={startsOn:'2026-11-01',endsOn:'2026-11-30'};
describe('filtry sestav',()=>{
  it('CSV zachovává filtry a vynechává stránkování',()=>{
    const csv=reportParams(selection,true);expect(csv.get('accountId')).toBe('a');expect(csv.has('page')).toBe(false);expect(csv.has('itemsPerPage')).toBe(false);
    const json=reportParams(selection,true,2);expect(json.get('page')).toBe('2');expect(json.get('itemsPerPage')).toBe('20');expect(json.get('from')).toBe(csv.get('from'));
    const trial=reportParams(selection,false);expect(trial.has('accountId')).toBe(false);expect(trial.get('includeZero')).toBe('true');
  });
  it('validuje hranice, pořadí, kalendář a povinný účet',()=>{
    expect(reportErrors(selection,true,period)).toEqual([]);
    expect(reportErrors({...selection,from:'2026-10-31'},true,period)).toContain('Obě data musí ležet ve vybraném období.');
    expect(reportErrors({...selection,from:'2026-11-30',to:'2026-11-01'},true,period)).toContain('Datum Od nesmí být pozdější než Do.');
    expect(reportErrors({...selection,to:'2026-02-30'},true,period)).toContain('Vyplňte platná data Od a Do.');
    expect(reportErrors({...selection,accountId:''},true,period)).toContain('Vyberte účet.');
    expect(reportErrors({...selection,accountId:''},false,period)).toEqual([]);expect(reportErrors(selection,true)).toContain('Vyberte účetní období.');
  });
});
