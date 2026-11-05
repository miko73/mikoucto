import type { components } from './generated/api';
export type Partner = Required<components['schemas']['Partner']>;
export interface Unit { id: string; code: string; name: string; version: number }
export interface User { id: string; display_name: string }
export const fields = [
  ['name','Název partnera',200], ['countryCode','Kód země',2],
  ['registrationNumber','IČO / registrační číslo',32], ['vatNumber','DIČ',32],
  ['street','Ulice a číslo',200], ['city','Město',120], ['postalCode','PSČ',20],
  ['email','E-mail',254], ['phone','Telefon',40],
] as const;
export type TextField = typeof fields[number][0];
export type Draft = Record<TextField,string> & { active: boolean };
export type Payload = Record<TextField,string|null> & { active: boolean };
export const labels: Record<string,string> = Object.fromEntries([...fields.map(([k,v])=>[k,v]),['active','Aktivní']]);
export function draftOf(partner?: Partner): Draft {
  return { ...Object.fromEntries(fields.map(([key])=>[key,partner?.[key] ?? (key === 'countryCode' ? 'CZ' : '')])), active: partner?.active ?? true } as Draft;
}
export function normalize(draft: Draft): Payload {
  return { ...Object.fromEntries(fields.map(([key])=>[key,(key === 'countryCode' || key === 'vatNumber' ? draft[key].trim().toUpperCase() : draft[key].trim()) || (['name','countryCode'].includes(key) ? '' : null)])), active: draft.active } as Payload;
}
export function changes(base: Draft, next: Draft): Partial<Payload> {
  const a = normalize(base), b = normalize(next);
  return Object.fromEntries(Object.entries(b).filter(([key,value])=>value !== a[key as keyof Payload]));
}
// Only deliberate local changes are reapplied. Fields changed solely on the server survive.
export function rebase(base: Draft, mine: Draft, latest: Partner): Draft {
  const merged = { ...normalize(draftOf(latest)), ...changes(base,mine) };
  return { ...Object.fromEntries(fields.map(([key])=>[key,merged[key] ?? ''])), active: merged.active } as Draft;
}
export function validate(draft: Draft) {
  const errors: Record<string,string> = {};
  const value = normalize(draft);
  if (!value.name) errors.name = 'Vyplňte název partnera.';
  if (!/^[A-Z]{2}$/.test(value.countryCode ?? '')) errors.countryCode = 'Použijte dvoupísmenný kód země (např. CZ).';
  for (const [key,,max] of fields) if ((value[key]?.length ?? 0) > max) errors[key] = `Nejvýše ${max} znaků.`;
  if (value.countryCode === 'CZ' && value.registrationNumber && !/^\d{8}$/.test(value.registrationNumber)) errors.registrationNumber = 'České IČO musí mít osm číslic.';
  if (value.vatNumber && !/^[A-Z]{2}[A-Z0-9]{2,30}$/.test(value.vatNumber)) errors.vatNumber = 'Zadejte kód země a daňové číslo.';
  if (value.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value.email)) errors.email = 'Zkontrolujte e-mail.';
  return errors;
}
export interface HistoryItem { id:string; occurred_at:string; user_id:string; operation:string; changed_cols:string[]; old_data:Record<string,unknown>|null; new_data:Record<string,unknown>|null }
export interface History { items: HistoryItem[]; total:number; page:number; itemsPerPage:number }
export function date(value: string) {
  const d = new Date(value.replace(' ','T').replace(/([+-]\d{2})$/,'$1:00'));
  return Number.isNaN(d.getTime()) ? value : new Intl.DateTimeFormat('cs-CZ',{dateStyle:'medium',timeStyle:'short'}).format(d);
}
export function historyLabel(key:string) { return labels[key.replace(/_([a-z])/g,(_,c:string)=>c.toUpperCase())] ?? ({created_at:'Vytvoření',updated_at:'Aktualizace',version:'Verze'} as Record<string,string>)[key] ?? key; }
export function display(value:unknown) { return value == null || value === '' ? '—' : typeof value === 'boolean' ? (value ? 'Ano' : 'Ne') : String(value); }
export class CreationAttempt {
  key = ''; body = ''; uncertain = false;
  prepare(payload: Payload) {
    const body = JSON.stringify(payload);
    if (this.uncertain && body !== this.body) throw new Error('Nejprve ověřte výsledek předchozího pokusu.');
    if (body !== this.body || !this.key) { this.body = body; this.key = crypto.randomUUID(); }
    return { key: this.key, body: this.body };
  }
}
