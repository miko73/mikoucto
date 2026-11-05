import { test } from 'node:test';
import assert from 'node:assert/strict';
import { checkBackend, CheckFailure, formatCheckError } from './diagnostics.mjs';

const base = new URL('http://127.0.0.1:7005');
const response = (status, body) => new Response(JSON.stringify(body), {status});
function run(responses) {
  const calls = [];
  const fetchImpl = async url => { calls.push(url.pathname); const next = responses.shift(); if (next instanceof Error) throw next; return next; };
  return { calls, promise:checkBackend(base,{fetchImpl,log(){}}) };
}
test('kontrola projde health, DB a skutecnou ochranu bez tokenu',async()=>{
  const {calls,promise} = run([response(200,{status:'ok',service:'mikoucto-api'}),response(200,{status:'ok',database:'ok'}),response(401,{})]);
  await promise;
  assert.deepEqual(calls,['/api/v1/health','/api/v1/health/database','/api/v1/me']);
});
test('nedostupny backend hlasi spojeni a konkretni napravu',async()=>{
  const error = new TypeError('raw private message',{cause:{code:'ECONNREFUSED'}});
  const {calls,promise}=run([error]);
  await assert.rejects(promise,e=>e.code==='backend_connection' && e.detail.includes('ECONNREFUSED') && e.detail.includes('03-backend.ps1') && !e.detail.includes('private'));
  assert.equal(calls.length,1);
});
test('timeout ma vlastni diagnostiku',async()=>{
  await assert.rejects(run([new DOMException('private','TimeoutError')]).promise,{code:'backend_timeout'});
});
test('nefunkcni DB zastavi test pred prihlasenim',async()=>{
  const {calls,promise}=run([response(200,{status:'ok',service:'mikoucto-api'}),response(503,{detail:'private DSN'})]);
  await assert.rejects(promise,e=>e.code==='database_http' && e.detail.includes('503') && e.detail.includes('SSH') && !e.detail.includes('DSN'));
  assert.equal(calls.length,2);
});
test('neocekavany server na portu se odmitne',async()=>{
  await assert.rejects(run([response(200,{status:'ok',service:'other'})]).promise,{code:'backend_response'});
});
test('chybny HTTP stav neprojde jako zdravy backend',async()=>{
  await assert.rejects(run([response(500,{})]).promise,{code:'backend_http'});
});
test('neocekavany stav anonymni identity je viditelny',async()=>{
  await assert.rejects(run([response(200,{status:'ok',service:'mikoucto-api'}),response(200,{status:'ok',database:'ok'}),response(200,{})]).promise,e=>e.code==='anonymous_status' && e.detail.includes('200'));
});
test('MSAL diagnostika vypise bezpecny kod, fazi a zadny token ani syrovou zpravu',()=>{
  const error=Object.assign(new Error('Bearer TOP_SECRET https://private.example'),{errorCode:'invalid_client',accessToken:'TOP_SECRET'});
  const message=formatCheckError(error,'microsoft_login');
  assert.match(message,/microsoft_login/);assert.match(message,/invalid_client/);assert.doesNotMatch(message,/TOP_SECRET|private\.example|Bearer/);
});
test('nebezpecny retezec misto kodu se nevypise',()=>{
  const message=formatCheckError({name:'Error',errorCode:'Bearer SECRET\nprivate'},'microsoft_login');
  assert.doesNotMatch(message,/SECRET|private/);
  assert.match(formatCheckError(new CheckFailure('backend_http','HTTP 500'),'backend'),/HTTP 500/);
});
