import { describe,expect,it } from 'vitest';
import {blank,cents,decimal,diff,money,payload,totals,validate,WriteAttempt} from '../../src/accounting';
describe('účetní formuláře a přesné částky',()=>{
  it('sčítá i maximální částky bez ztráty haléřů',()=>{
    expect(decimal(cents('9999999999999999.99')!)).toBe('9999999999999999.99');
    expect(totals([{accountId:'a',text:'',md:'9999999999999999.99',dal:'0'},{accountId:'b',text:'',md:'0',dal:'9999999999999999,98'}])).toMatchObject({difference:'0.01',balanced:false});
    expect(money('-123456789.01')).toBe('-123\u00a0456\u00a0789,01');
    for(const value of ['-1','1e3','0.001','10000000000000000','NaN'])expect(cents(value)).toBeNull();
  });
  it('zachová vedoucí nuly a odmítne obě strany či nulu na obou stranách',()=>{
    const form=blank();form.code='001';form.name='Banka';expect(payload('accounts',form).code).toBe('001');
    form.periodId='p';form.text='Převod';form.lines=[{accountId:'a',text:'',md:'0',dal:'0'}];expect(validate('documents',form)['lines.0']).toBeTruthy();
    form.lines[0]!.md='1,10';expect(validate('documents',form)).toEqual({});expect((payload('documents',form).lines as {md:string}[])[0]!.md).toBe('1.10');
    form.lines[0]!.dal='1';expect(validate('documents',form)['lines.0']).toBeTruthy();
  });
  it('při konfliktu přenáší pouze místně změněná pole a řádky jako celek',()=>{
    expect(diff({text:'A',partnerId:'x',lines:[1]},{text:'B',partnerId:'x',lines:[2]})).toEqual({text:'B',lines:[2]});
  });
  it('po výpadku zachová původní klíč, tělo i ETag a odmítne změnu cíle',()=>{
    const attempt=new WriteAttempt(),write=attempt.prepare('u/documents/d/post','POST',{},'"d:1"');attempt.uncertain=true;
    expect(attempt.prepare('u/documents/d/post','POST',{},'"d:1"')).toBe(write);
    expect(()=>attempt.prepare('v/documents/d/post','POST',{},'"d:1"')).toThrow();
    expect(()=>attempt.prepare('u/documents/d/post','POST',{},'"d:2"')).toThrow();
  });
});
