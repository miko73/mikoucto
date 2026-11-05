<script setup lang="ts">
import { computed, nextTick, onMounted, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { auth } from './auth';
import AccountingWorkspace from './AccountingWorkspace.vue';
import ReportsWorkspace from './ReportsWorkspace.vue';
import { isReportSection, type ReportSection, type ReportSelection } from './reports';
import type { Section } from './accounting';
import { ApiError, request, SessionExpired } from './api';
import { fields, labels, draftOf, normalize, changes, rebase, validate, date, display, historyLabel, CreationAttempt, type Draft, type Partner, type Unit, type User, type History } from './partner';

const ready = ref(false), busy = ref(false), signed = ref(false), user = ref<User>();
const section=ref<'partners'|Section|ReportSection>('partners'),accounting=ref<InstanceType<typeof AccountingWorkspace>>(),accountingLocked=ref(false);
const reports=ref<InstanceType<typeof ReportsWorkspace>>(),reportSelection=ref<ReportSelection>();
const navigation=[['partners','Partneři'],['accounts','Účty'],['periods','Období'],['documents','Doklady'],['journal','Deník'],['general-ledger','Hlavní kniha'],['trial-balance','Obratová předvaha']] as const;
function navigate(next:typeof section.value) {if(accountingLocked.value||!close()||accounting.value&&!accounting.value.canLeave()||reports.value&&!reports.value.canLeave())return;reportSelection.value=undefined;section.value=next;}
function openLedger(selection:ReportSelection){navigate('general-ledger');if(section.value==='general-ledger')reportSelection.value=selection;}
const units = ref<Unit[]>([]), unitId = ref(''), permissions = ref<string[]>([]);
const partners = ref<Partner[]>([]), total = ref(0), page = ref(1), search = ref(''), active = ref(''), loading = ref(false);
const error = ref(''), notice = ref(''), dialogError = ref(''), saving = ref(false);
const dialog = ref<HTMLDialogElement>(), help = ref<HTMLDialogElement>();
const selected = ref<Partner>(), base = ref<Draft>(draftOf()), draft = reactive<Draft>(draftOf());
const etag = ref(''), latest = ref<{partner:Partner;etag:string}>(), errors = ref<Record<string,string>>({});
const history = ref<History>(), historyPage = ref(1), historyBusy = ref(false), historyError = ref('');
const open = ref(false), detailBusy = ref(false);
let attempt = new CreationAttempt();
const uncertain = ref(false);
let generation = 0, listSequence = 0, controller: AbortController | undefined, timer: ReturnType<typeof setTimeout>;
const currentUnit = computed(()=>units.value.find(u=>u.id === unitId.value));
const canWrite = computed(()=>permissions.value.includes('core.partners.write'));
const canHistory = computed(()=>permissions.value.includes('core.partners.history'));
const canRead = computed(()=>permissions.value.includes('core.partners.read'));
const accessLabel = computed(()=>permissions.value.length ? (section.value==='partners'?canWrite.value:section.value!=='journal'&&permissions.value.includes(`core.ucto.${section.value}.write`)) ? 'Čtení a zápis' : 'Pouze čtení' : loading.value ? 'Načítám oprávnění' : error.value ? 'Oprávnění neověřena' : 'Pouze čtení');
const dirty = computed(()=>Object.keys(changes(base.value,draft)).length > 0);
const pages = computed(()=>Math.max(1,Math.ceil(total.value / 20)));
const root = ()=>`accounting-units/${encodeURIComponent(unitId.value)}`;
const partnerRoot = ()=>`${root()}/partners`;
const conflictFields = computed(()=>latest.value ? Object.keys(changes(base.value,draft)) as (keyof Draft)[] : []);

function message(e: unknown) {
  if (e instanceof SessionExpired || e instanceof ApiError && e.status === 401) {
    signed.value = false; units.value = []; unitId.value = ''; permissions.value = []; partners.value = []; user.value = undefined;
    generation++; controller?.abort(); close(true);
    void auth.logout().catch(()=>{});
    return 'Přihlášení vypršelo. Přihlaste se znovu.';
  }
  if (e instanceof ApiError && e.status === 403) { permissions.value = []; partners.value = []; total.value = 0; }
  return e instanceof ApiError ? `${e.message}${e.requestId ? ` Reference: ${e.requestId}` : ''}` : 'Akci se nepodařilo dokončit. Zkuste ji zopakovat.';
}
async function login() {
  if (busy.value) return;
  busy.value = true; error.value = '';
  try {
    await auth.login();
    const [me, list] = await Promise.all([request<User>('me'), request<{items:Unit[]}>('accounting-units')]);
    user.value = me.data; units.value = list.data.items; signed.value = true;
    unitId.value = units.value[0]?.id ?? '';
  } catch (e) { error.value = message(e); } finally { busy.value = false; }
}
async function logout() {
  if (saving.value || accountingLocked.value || !close() || accounting.value&&!accounting.value.canLeave() || reports.value&&!reports.value.canLeave()) return;
  busy.value = true;
  try { await auth.logout(); signed.value = false; user.value = undefined; units.value = []; unitId.value = ''; error.value = ''; }
  catch (e) { error.value = message(e); } finally { busy.value = false; }
}
async function loadContext() {
  const own = ++generation; controller?.abort(); listSequence++;
  partners.value = []; total.value = 0; permissions.value = []; error.value = ''; notice.value = ''; page.value = 1;
  if (!unitId.value) return;
  loading.value = true;
  try {
    const result = await request<{items:string[]}>(`${root()}/permissions`);
    if (own !== generation) return;
    permissions.value = result.data.items;
    if (canRead.value) await loadList();
  } catch (e) { if (own === generation) error.value = message(e); }
  finally { if (own === generation) loading.value = false; }
}
async function loadList() {
  controller?.abort(); const abort = controller = new AbortController();
  const own = generation, sequence = ++listSequence;
  if (!unitId.value || !canRead.value) return;
  loading.value = true; error.value = ''; partners.value = [];
  try {
    const params = new URLSearchParams({page:String(page.value),itemsPerPage:'20',search:search.value.trim()});
    if (active.value) params.set('active',active.value);
    const result = await request<Partner[]>(`${partnerRoot()}?${params}`,{signal:abort.signal});
    if (own !== generation || sequence !== listSequence) return;
    partners.value = result.data; total.value = result.total;
    if (page.value > pages.value) { page.value = pages.value; await loadList(); }
  } catch (e) { if (!abort.signal.aborted && own === generation && sequence === listSequence) error.value = message(e); }
  finally { if (own === generation && sequence === listSequence) loading.value = false; }
}
function changeUnit(event: Event) {
  const select = event.target as HTMLSelectElement;
  if (accountingLocked.value || !close() || accounting.value&&!accounting.value.canLeave() || reports.value&&!reports.value.canLeave()) { select.value = unitId.value; return; }
  reportSelection.value=undefined;
  unitId.value = select.value;
}
watch(unitId,loadContext);
watch([search,active],()=>{ clearTimeout(timer); timer = setTimeout(()=>{page.value=1;void loadList();},300); });
function paginate(delta:number) { page.value += delta; void loadList(); }

async function show(partner?: Partner) {
  selected.value = partner; latest.value = undefined; history.value = undefined; historyError.value = ''; historyPage.value = 1;
  dialogError.value = ''; errors.value = {}; uncertain.value = false; attempt = new CreationAttempt(); etag.value = '';
  base.value = draftOf(partner); Object.assign(draft,base.value); open.value = true;
  await nextTick(); dialog.value?.showModal();
  if (!partner) return;
  const own = generation; detailBusy.value = true;
  try {
    const result = await request<Partner>(`${partnerRoot()}/${partner.id}`);
    if (own !== generation || !open.value || selected.value?.id !== partner.id) return;
    selected.value = result.data; base.value = draftOf(result.data); Object.assign(draft,base.value); etag.value = result.etag;
  } catch (e) { if (own === generation && open.value) dialogError.value = message(e); }
  finally { detailBusy.value = false; }
}
function close(force = false) {
  if (!force && (saving.value || uncertain.value)) return false;
  if (!force && open.value && dirty.value && !confirm('Zahodit neuložené změny?')) return false;
  dialog.value?.close(); open.value = false; return true;
}
async function save() {
  if (saving.value || detailBusy.value || !canWrite.value || latest.value) return;
  errors.value = validate(draft); if (Object.keys(errors.value).length) return;
  saving.value = true; dialogError.value = '';
  try {
    let result;
    if (selected.value) {
      if (!etag.value) throw new Error('Chybí verze. Otevřete záznam znovu.');
      result = await request<Partner>(`${partnerRoot()}/${selected.value.id}`, {method:'PATCH',headers:{'Content-Type':'application/merge-patch+json','If-Match':etag.value},body:JSON.stringify(changes(base.value,draft))});
    } else {
      const prepared = attempt.prepare(normalize(draft));
      result = await request<Partner>(partnerRoot(),{method:'POST',headers:{'Content-Type':'application/json','Idempotency-Key':prepared.key},body:prepared.body});
    }
    uncertain.value = false; attempt.uncertain = false; base.value = draftOf(result.data); Object.assign(draft,base.value);
    close(true); notice.value = 'Partner byl uložen.'; await loadList();
  } catch (e) {
    if (e instanceof ApiError && e.status === 412 && selected.value) {
      try { const current = await request<Partner>(`${partnerRoot()}/${selected.value.id}`); latest.value = {partner:current.data,etag:current.etag}; }
      catch (loadError) { dialogError.value = message(loadError); }
    } else {
      dialogError.value = message(e);
      if (e instanceof ApiError) {
        for (const violation of e.violations) errors.value[violation.propertyPath] = violation.message;
        if (!selected.value && (e.status === 0 || e.status >= 500)) { uncertain.value = true; attempt.uncertain = true; }
      }
    }
  } finally { saving.value = false; }
}
function resolveConflict(keep:boolean) {
  if (!latest.value) return;
  const current = latest.value;
  Object.assign(draft,keep ? rebase(base.value,draft,current.partner) : draftOf(current.partner));
  base.value = draftOf(current.partner); selected.value = current.partner; etag.value = current.etag; latest.value = undefined; history.value = undefined;
  dialogError.value = ''; errors.value = {};
}
async function toggleActive() {
  if (!confirm(draft.active ? 'Deaktivovat partnera? Zůstane v historii i existujících dokladech.' : 'Znovu aktivovat partnera?')) return;
  draft.active = !draft.active; await save();
}
async function loadHistory(delta=0) {
  if (!selected.value || !canHistory.value) return;
  const own = generation, id = selected.value.id; historyBusy.value = true; historyError.value = '';
  const target = historyPage.value + delta;
  try {
    const result = await request<History>(`${partnerRoot()}/${id}/history?page=${target}&itemsPerPage=10`);
    if (own === generation && open.value && selected.value?.id === id) {history.value=result.data;historyPage.value=target;}
  } catch (e) { if (own === generation && open.value) historyError.value=message(e); }
  finally { historyBusy.value=false; }
}
function unload(e: BeforeUnloadEvent) { if (dirty.value && open.value || uncertain.value) { e.preventDefault(); e.returnValue=''; } }
onMounted(async()=>{window.addEventListener('beforeunload',unload);try {await auth.initialize();}catch{error.value='Inicializace přihlášení se nezdařila.';}finally{ready.value=true;}});
onBeforeUnmount(()=>{controller?.abort();clearTimeout(timer);window.removeEventListener('beforeunload',unload);});
</script>

<template>
  <div v-if="!signed" class="welcome">
    <section class="welcome-art"><a class="brand" href="/">m<span>•</span> mikoucto</a><div><p class="eyebrow">PŘEHLED V KAŽDÉM KROKU</p><h1>Vaše účetnictví.<br>Na jednom místě.</h1><p>Partneři, účetní jednotky a dohledatelná historie změn.</p></div><small>Jasné údaje. Dohledatelné změny.</small></section>
    <main class="welcome-form"><span class="section-number">01 / VSTUP DO APLIKACE</span><h2>Vítejte v mikoucto</h2><p>Přihlaste se pracovním účtem Microsoft přiřazeným k vaší účetní jednotce.</p><p v-if="error" role="alert" class="alert">{{error}}</p><button :disabled="!ready || busy || !auth.configured" class="primary login" @click="login">{{busy ? 'Přihlašuji…' : 'Přihlásit přes Microsoft'}} <span aria-hidden="true">↗</span></button><p v-if="!auth.configured" class="setup">Nejprve nastavte registraci webu podle <code>analyza/web.md</code> a spusťte konfigurační skript 16.</p><small>Přístup k datům řídí role v jednotlivých účetních jednotkách.</small></main>
  </div>
  <div v-else class="shell">
    <aside class="sidebar"><a class="brand" href="/">m<span>•</span> mikoucto</a><span class="nav-label">PRACOVNÍ PROSTOR</span><nav class="workspace-nav" aria-label="Sekce aplikace"><button v-for="[key,label] in navigation" :key="key" :class="['nav-item',{selected:section===key}]" :aria-current="section===key?'page':undefined" :disabled="saving||uncertain||accountingLocked" @click="navigate(key)">{{label}}<span v-if="section===key" class="nav-dot"></span></button></nav><div class="sidebar-bottom"><button class="nav-item" @click="help?.showModal()"><span aria-hidden="true">ⓘ</span> Jak začít</button><div class="profile"><span class="avatar">{{user?.display_name?.slice(0,1) ?? 'U'}}</span><div><strong>{{user?.display_name}}</strong><small>Přihlášeno přes Microsoft</small></div></div><button class="logout" :disabled="busy || saving || uncertain || accountingLocked" @click="logout">Odhlásit z aplikace ↗</button></div></aside>
    <div class="workspace"><header class="topbar"><label class="unit-picker"><span>ÚČETNÍ JEDNOTKA</span><select aria-label="Účetní jednotka" :value="unitId" :disabled="saving || uncertain || accountingLocked" @change="changeUnit"><option v-if="!units.length" value="">Žádná dostupná jednotka</option><option v-for="unit in units" :key="unit.id" :value="unit.id">{{unit.name}} · {{unit.code}}</option></select></label><span class="access-tag">{{accessLabel}}</span></header>
    <ReportsWorkspace v-if="isReportSection(section)" ref="reports" :key="unitId+':'+section" :unit="unitId" :unit-code="currentUnit?.code??''" :section="section" :permissions="permissions" :initial-selection="reportSelection" @ledger="openLedger" @failure="error=message($event)" />
    <AccountingWorkspace v-else-if="section!=='partners'" ref="accounting" :key="unitId+':'+section" :unit="unitId" :unit-code="currentUnit?.code??''" :section="section" :permissions="permissions" @locked="accountingLocked=$event" @failure="error=message($event)" />
    <main v-else id="partners"><div class="breadcrumb">{{currentUnit?.code ?? 'Pracovní prostor'}} <span>/</span> Adresář</div><div class="heading"><div><p class="eyebrow">VAŠE OBCHODNÍ KONTAKTY</p><h1>Partneři <span class="count">{{total}}</span></h1><p>Firmy a lidé, se kterými spolupracujete.</p></div><button v-if="canWrite" class="primary" @click="show()"><span aria-hidden="true">＋</span> Nový partner</button></div>
    <p v-if="notice" role="status" class="success">✓ {{notice}}</p><div v-if="error" role="alert" class="alert">{{error}} <button @click="loadContext">Zkusit znovu</button></div>
    <section class="directory" aria-label="Adresář partnerů"><div class="toolbar"><label class="search"><span aria-hidden="true">⌕</span><input v-model="search" type="search" maxlength="100" placeholder="Hledat název nebo IČO…" aria-label="Hledat partnera"></label><label class="filter"><span>Stav</span><select v-model="active" aria-label="Stav partnera"><option value="">Všichni partneři</option><option value="true">Aktivní</option><option value="false">Neaktivní</option></select></label><button class="refresh" :disabled="loading" aria-label="Obnovit seznam" @click="loadContext">↻</button></div>
    <div class="table-scroll" :aria-busy="loading"><table><thead><tr><th>PARTNER</th><th>IČO / DIČ</th><th>KONTAKT</th><th>STAV</th><th><span class="sr-only">Detail</span></th></tr></thead><tbody v-if="!loading"><tr v-for="partner in partners" :key="partner.id"><td><button class="partner-link" @click="show(partner)"><span class="company-avatar" aria-hidden="true">{{partner.name?.slice(0,2).toUpperCase()}}</span><span><strong>{{partner.name}}</strong><small>{{[partner.city,partner.countryCode].filter(Boolean).join(' · ')}}</small></span></button></td><td><span>{{partner.registrationNumber || '—'}}</span><small>{{partner.vatNumber || 'DIČ neuvedeno'}}</small></td><td><span>{{partner.email || '—'}}</span><small>{{partner.phone || 'Telefon neuveden'}}</small></td><td><span :class="['status', {inactive:!partner.active}]"><i></i>{{partner.active ? 'Aktivní' : 'Neaktivní'}}</span></td><td><button class="row-open" :aria-label="`Otevřít ${partner.name}`" @click="show(partner)">↗</button></td></tr></tbody></table>
    <div v-if="loading" class="empty" role="status"><span class="spinner"></span> Načítám partnery…</div><div v-else-if="!partners.length" class="empty"><span class="empty-icon" aria-hidden="true">◫</span><h3>{{error ? 'Adresář se nepodařilo načíst' : !units.length ? 'Zatím nemáte účetní jednotku' : !canRead ? 'Přístup k adresáři není dostupný' : search || active ? 'Žádný partner neodpovídá filtru' : 'Adresář čeká na prvního partnera'}}</h3><p>{{error ? 'Obnovte načtení tlačítkem Zkusit znovu. Aktuální seznam partnerů není dostupný.' : !units.length ? 'Správce vám musí přiřadit účetní jednotku a roli.' : search || active ? 'Zkuste jiný název, IČO nebo stav.' : 'Přidejte kontakt a mějte jeho údaje přehledně po ruce.'}}</p><button v-if="canWrite && !search && !active" @click="show()">Přidat partnera →</button></div></div>
    <footer class="pagination"><span>{{total ? `${(page-1)*20+1}–${Math.min(page*20,total)} z ${total} partnerů` : '0 partnerů'}}</span><div><button :disabled="page<=1 || loading" aria-label="Předchozí stránka" @click="paginate(-1)">←</button><span>{{page}} / {{pages}}</span><button :disabled="page>=pages || loading" aria-label="Další stránka" @click="paginate(1)">→</button></div></footer></section><p class="footnote"><span aria-hidden="true">◷</span> Změny se verzují. Historie je dostupná podle vašich oprávnění.</p></main></div>
  </div>

  <dialog ref="dialog" class="editor" aria-labelledby="editor-title" @cancel.prevent="close()"><template v-if="open"><header class="editor-header"><div><p class="eyebrow">ADRESÁŘ / {{selected ? 'DETAIL' : 'NOVÝ ZÁZNAM'}}</p><h2 id="editor-title">{{selected ? selected.name : 'Nový partner'}}</h2></div><button aria-label="Zavřít formulář" :disabled="saving || uncertain" @click="close()">✕</button></header><form novalidate @submit.prevent="save"><div class="editor-body"><p v-if="detailBusy" role="status">Načítám aktuální verzi…</p><p v-if="dialogError" class="alert" role="alert">{{dialogError}}</p><div v-if="uncertain" class="alert" role="alert">Výsledek vytvoření není potvrzený. Opakujte stejný požadavek tlačítkem níže. Údaje jsou do potvrzení uzamčené. Po obnovení stránky už nelze zaručit opakování stejného požadavku.</div>
    <section v-if="latest" class="conflict" role="alert"><h3>Partner se mezitím změnil</h3><p>Porovnejte údaje. Vaše změny zůstaly zachované a zatím nejsou uložené.</p><div class="table-scroll"><table><thead><tr><th>Pole</th><th>Původně</th><th>Na serveru</th><th>Vaše změna</th></tr></thead><tbody><tr v-for="key in conflictFields" :key="key"><th>{{labels[key]}}</th><td>{{display(base[key])}}</td><td>{{display(draftOf(latest.partner)[key])}}</td><td>{{display(draft[key])}}</td></tr></tbody></table></div><button type="button" @click="resolveConflict(false)">Načíst uloženou verzi</button><button type="button" class="primary" @click="resolveConflict(true)">Ponechat moje změny</button><small>Po sloučení zkontrolujte formulář a znovu jej uložte.</small></section>
    <fieldset :disabled="!canWrite || saving || uncertain || detailBusy || !!latest || (!!selected && !etag)"><legend>Údaje partnera</legend><div class="form-grid"><label v-for="[key,label,max] in fields" :key="key" :class="{wide:key==='name' || key==='street'}" :for="`field-${key}`">{{label}}<span v-if="key==='name' || key==='countryCode'" aria-hidden="true"> *</span><input :id="`field-${key}`" :aria-label="label" v-model="draft[key]" :maxlength="max" :required="key==='name' || key==='countryCode'" :type="key==='email' ? 'email' : key==='phone' ? 'tel' : 'text'" :aria-invalid="!!errors[key]" :aria-describedby="errors[key] ? `error-${key}` : undefined" :autocomplete="key==='name' ? 'organization' : key==='email' ? 'email' : 'off'"><small v-if="errors[key]" :id="`error-${key}`" class="field-error">{{errors[key]}}</small></label></div><label class="checkbox"><input v-model="draft.active" type="checkbox"> Aktivní partner</label></fieldset><p class="form-note">* Povinné údaje. IČO ani DIČ se neověřují ve veřejném registru.</p>
    <section v-if="selected" class="record-meta"><span>Verze {{selected.version}}</span><span>Upraveno {{date(selected.updatedAt ?? '')}}</span></section>
    <section v-if="selected && canHistory" class="history"><div class="section-heading"><h3>Historie změn</h3><button type="button" :disabled="historyBusy" @click="loadHistory()">{{historyBusy ? 'Načítám…' : history ? 'Obnovit' : 'Zobrazit historii'}}</button></div><p v-if="historyError" class="alert" role="alert">{{historyError}}</p><template v-if="history"><article v-for="item in history.items" :key="item.id" class="history-item"><strong>{{item.operation==='I' ? 'Partner vytvořen' : item.operation==='D' ? 'Partner odstraněn' : 'Údaje upraveny'}}</strong><small>{{date(item.occurred_at)}} · {{item.user_id===user?.id ? 'Vy' : `Uživatel ${item.user_id?.slice(0,8)}`}}</small><dl><template v-for="key in (item.changed_cols ?? Object.keys(item.new_data ?? {})).filter(k=>!['id','accounting_unit_id','created_at','updated_at','version'].includes(k))" :key="key"><dt>{{historyLabel(key)}}</dt><dd><del v-if="item.old_data">{{display(item.old_data[key])}} → </del>{{display(item.new_data?.[key])}}</dd></template></dl></article><p v-if="!history.items.length">Žádné změny.</p><div class="history-pages"><button type="button" :disabled="historyPage<=1 || historyBusy" @click="loadHistory(-1)">← Novější</button><span>{{historyPage}} / {{Math.max(1,Math.ceil(history.total/10))}}</span><button type="button" :disabled="historyPage*10>=history.total || historyBusy" @click="loadHistory(1)">Starší →</button></div></template></section>
    </div><footer class="editor-footer"><button v-if="selected && canWrite" type="button" class="danger" :disabled="saving || dirty || !etag || !!latest || detailBusy" @click="toggleActive">{{draft.active ? 'Deaktivovat' : 'Aktivovat'}}</button><button type="button" :disabled="saving || uncertain" @click="close()">{{canWrite ? 'Zrušit' : 'Zavřít'}}</button><button v-if="canWrite" class="primary" :disabled="saving || detailBusy || !!latest || (!!selected && (!etag || !dirty))" type="submit">{{saving ? 'Ukládám…' : uncertain ? 'Opakovat stejný požadavek' : 'Uložit partnera'}}</button></footer></form></template></dialog>
  <dialog ref="help" class="help" aria-labelledby="help-title"><h2 id="help-title">Jak začít</h2><p>Vyberte účetní jednotku a otevřete partnera v adresáři. Tlačítka pro zápis a historii se zobrazují podle oprávnění.</p><p>Při konfliktu porovnejte změny, zvolte uloženou verzi nebo ponechte vlastní úpravy a formulář znovu uložte.</p><p>Pro změnu role nebo přístup k další jednotce kontaktujte správce.</p><button class="primary" @click="help?.close()">Rozumím</button></dialog>
</template>
