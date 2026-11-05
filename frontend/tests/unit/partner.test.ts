import { describe, expect, it } from 'vitest';
import { changes, CreationAttempt, date, draftOf, normalize, rebase, validate, type Partner } from '../../src/partner';
const original: Partner = {id:'a',accountingUnitId:'u',name:'Původní',countryCode:'CZ',registrationNumber:null,vatNumber:null,street:null,city:'Praha',postalCode:null,email:null,phone:null,active:true,version:1,createdAt:'2026-11-02 10:00:00+00',updatedAt:'2026-11-02 10:00:00+00'};
describe('formulář partnera',()=>{
  it('normalizuje prázdné kontakty, zemi a DIČ',()=>{
    const value = normalize({...draftOf(original),name:' Firma ',email:'  ',countryCode:' cz ',vatNumber:' cz1234 '});
    expect(value).toMatchObject({name:'Firma',email:null,countryCode:'CZ',vatNumber:'CZ1234'});
  });
  it('neposílá serverová pole ani nezměněné hodnoty',()=>{
    const base = draftOf(original);
    expect(changes(base,{...base,name:' Původní '})).toEqual({});
    expect(changes(base,{...base,city:''})).toEqual({city:null});
  });
  it('při sloučení zachová cizí změny a přenese pouze vlastní',()=>{
    const next = {...original,name:'Jiný název',email:'server@example.cz',city:'Brno',version:2};
    const merged = rebase(draftOf(original),{...draftOf(original),city:'Olomouc'},next);
    expect(merged).toMatchObject({name:'Jiný název',email:'server@example.cz',city:'Olomouc'});
    expect(changes(draftOf(next),merged)).toEqual({city:'Olomouc'});
  });
  it('kontroluje povinná pole a české IČO',()=>{
    expect(validate({...draftOf(),registrationNumber:'123',email:'x'})).toHaveProperty('name');
    expect(validate({...draftOf(original),registrationNumber:'123'})).toHaveProperty('registrationNumber');
    expect(validate({...draftOf(original),registrationNumber:'12345678'})).toEqual({});
  });
  it('nejisté vytvoření opakuje s totožným klíčem a tělem',()=>{
    const attempt = new CreationAttempt(), value = normalize(draftOf(original));
    const first = attempt.prepare(value); attempt.uncertain = true;
    expect(attempt.prepare({...value})).toEqual(first);
    expect(()=>attempt.prepare({...value,name:'Jiné'})).toThrow();
  });
  it('změněné potvrzené zadání dostane nový klíč',()=>{
    const attempt = new CreationAttempt(), value = normalize(draftOf(original));
    expect(attempt.prepare(value).key).not.toBe(attempt.prepare({...value,name:'Jiné'}).key);
  });
  it('formátuje čas z PostgreSQL bez Invalid Date',()=>{
    expect(date(original.createdAt)).not.toContain('Invalid');
    expect(date(original.createdAt)).toContain('2026');
  });
});
