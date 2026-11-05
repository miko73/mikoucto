<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { ApiError, request, SessionExpired } from './api';
import { date, display, type History, type Partner } from './partner';
import { blank, formOf, payload, diff, validate, totals, money, WriteAttempt, type Account, type Period, type Document, type Summary, type Journal, type RecordItem, type Section, type Kind } from './accounting';
const props=defineProps<{unit:string;unitCode:string;section:Section;permissions:string[];detailOnly?:boolean}>();
const emit=defineEmits<{locked:[boolean];failure:[unknown]}>();
const names={accounts:'Účty',periods:'Období',documents:'Doklady',journal:'Deník'};
const singular={accounts:'účet',periods:'období',documents:'doklad'};
const kind=computed<Kind>(()=>props.section==='journal'?'documents':props.section);
const permitted=(action:string,k:Kind=kind.value)=>props.permissions.includes(`core.ucto.${k}.${action}`);
const canRead=computed(()=>permitted('read')),canWrite=computed(()=>!props.detailOnly&&permitted('write')),canHistory=computed(()=>permitted('history'));
const canPost=computed(()=>!props.detailOnly&&permitted('post','documents')),canClose=computed(()=>!props.detailOnly&&permitted('close','periods'));
const rows=ref<RecordItem[]>([]),journal=ref<Journal[]>([]),total=ref(0),page=ref(1),loading=ref(false),error=ref(''),notice=ref('');
const search=ref(''),periodFilter=ref(''),accountFilter=ref(''),from=ref(''),to=ref('');
const accounts=ref<Account[]>([]),periods=ref<Period[]>([]),partners=ref<Partner[]>([]),lookupsReady=ref(false),lookupError=ref('');
const form=reactive(blank()),base=ref<Record<string,unknown>>({}),selected=ref<RecordItem>(),etag=ref('');
const editor=ref<HTMLDialogElement>(),open=ref(false),detailBusy=ref(false),saving=ref(false),uncertain=ref(false),dialogError=ref(''),errors=ref<Record<string,string>>({});
const detailFailed=ref(false);
const latest=ref<{item:RecordItem;etag:string}>(),history=ref<History>(),historyPage=ref(1),historyBusy=ref(false),historyError=ref('');
const attempt=new WriteAttempt(),life=new AbortController();
let disposed=false,sequence=0,editorSequence=0,lookupSequence=0,listAbort:AbortController|undefined,timer:ReturnType<typeof setTimeout>;
const root=()=>`accounting-units/${encodeURIComponent(props.unit)}`;
const pages=computed(()=>Math.max(1,Math.ceil(total.value/20)));
const dirty=computed(()=>open.value&&Object.keys(diff(base.value,payload(kind.value,form))).length>0);
const readonly=computed(()=>!canWrite.value||selected.value&&('status' in selected.value)&&(selected.value.status==='posted'||selected.value.status==='closed'));
const locked=computed(()=>saving.value||uncertain.value);
const sum=computed(()=>totals(form.lines));
const conflictKeys=computed(()=>latest.value?Object.keys(diff(base.value,payload(kind.value,form))):[]);
const labels:Record<string,string>={code:'Kód',name:'Název',active:'Aktivní',postable:'Účtovatelný',parentId:'Nadřazený účet',startsOn:'Od',endsOn:'Do',
  periodId:'Období',postingDate:'Datum',text:'Popis',partnerId:'Partner',lines:'Řádky MD/DAL',status:'Stav',number:'Číslo',posted_at:'Zaúčtováno',closed_at:'Uzavřeno',md:'MD',dal:'DAL'};
function describe(key:string,value:unknown):string {
  if (key==='lines'&&Array.isArray(value)) return value.map(l=>`${accountName(l.accountId)} · MD ${money(l.md)} / DAL ${money(l.dal)} · ${l.text}`).join('; ')||'Bez řádků';
  if (key==='periodId') return periods.value.find(p=>p.id===value)?.name??display(value);
  if (key==='parentId'||key==='account_id') return value?accountName(String(value)):'—';
  if (key==='partnerId') return partners.value.find(p=>p.id===value)?.name??display(value);
  return display(value);
}
function accountName(id:string) {const a=accounts.value.find(a=>a.id===id);return a?`${a.code} · ${a.name}`:id;}
function failed(e:unknown,command='') {
  if (e instanceof SessionExpired||e instanceof ApiError&&[401,403].includes(e.status)) {rows.value=[];journal.value=[];emit('failure',e);}
  if (e instanceof ApiError&&e.status===409) return command==='post'?'Doklad nelze zaúčtovat. Ověřte vyrovnané řádky, účty a otevřené období.':command==='close'?'Období nelze uzavřít. Dokončete všechny rozpracované doklady a obnovte detail.':'Změna odporuje účetním pravidlům. Zkontrolujte vazby, kód účtu a stav období.';
  if (e instanceof ApiError&&e.status===412) return 'Záznam se mezitím změnil. Načtěte aktuální verzi a porovnejte změny.';
  return e instanceof ApiError?`${e.message}${e.requestId?` Reference: ${e.requestId}`:''}`:'Akci se nepodařilo dokončit. Zkuste ji znovu.';
}
async function all<T>(resource:string):Promise<T[]> {
  const items:T[]=[]; let p=1;
  while (!disposed) {
    const result=await request<T[]>(`${root()}/${resource}?page=${p}&itemsPerPage=100`,{signal:life.signal});
    items.push(...result.data); if (items.length>=result.total||result.data.length===0) break; p++;
  }
  return items;
}
async function loadLookups() {
  const own=++lookupSequence;accounts.value=[];periods.value=[];partners.value=[];
  lookupError.value='';lookupsReady.value=false;
  try {
    const [a,p,c]=await Promise.all([
      permitted('read','accounts')?all<Account>('accounts'):Promise.resolve([]),
      permitted('read','periods')?all<Period>('periods'):Promise.resolve([]),
      kind.value==='documents'&&canWrite.value&&props.permissions.includes('core.partners.read')?all<Partner>('partners'):Promise.resolve([]),
    ]);
    if (disposed||own!==lookupSequence) return; accounts.value=a;periods.value=p;partners.value=c;lookupsReady.value=true;
  } catch(e) {if (!disposed&&own===lookupSequence) lookupError.value=failed(e);}
}
async function loadList() {
  if(props.detailOnly)return;
  listAbort?.abort();const abort=listAbort=new AbortController(),own=++sequence;
  rows.value=[];journal.value=[];total.value=0;error.value='';
  if (!props.unit||!canRead.value) return;
  if ((from.value&&!/^\d{4}-\d{2}-\d{2}$/.test(from.value))||(to.value&&from.value>to.value)) {error.value='Zkontrolujte rozsah dat.';return;}
  loading.value=true;
  try {
    const params=new URLSearchParams({page:String(page.value),itemsPerPage:'20',search:search.value.trim()});
    if (['documents','journal'].includes(props.section)) {
      if(periodFilter.value)params.set('periodId',periodFilter.value);if(from.value)params.set('from',from.value);if(to.value)params.set('to',to.value);
      if(props.section==='journal'&&accountFilter.value)params.set('accountId',accountFilter.value);
    }
    const result=await request<RecordItem[]|Journal[]>(`${root()}/${props.section}?${params}`,{signal:AbortSignal.any([life.signal,abort.signal])});
    if(disposed||own!==sequence)return;
    if(props.section==='journal')journal.value=result.data as Journal[];else rows.value=result.data as RecordItem[];
    total.value=result.total;
    if(page.value>pages.value){page.value=pages.value;await loadList();}
  } catch(e) {if(!disposed&&!abort.signal.aborted&&own===sequence)error.value=failed(e);}
  finally{if(!disposed&&own===sequence)loading.value=false;}
}
watch([search,periodFilter,accountFilter,from,to],()=>{clearTimeout(timer);timer=setTimeout(()=>{page.value=1;void loadList();},300);});
watch(locked,value=>emit('locked',value),{immediate:true});
watch(canRead,value=>{if(!value){rows.value=[];journal.value=[];selected.value=undefined;notice.value='';editorSequence++;attempt.reset();uncertain.value=false;Object.assign(form,blank());base.value={};etag.value='';latest.value=undefined;history.value=undefined;editor.value?.close();open.value=false;}});
watch(()=>props.permissions,()=>{void loadList();void loadLookups();});
async function show(item?:RecordItem|string) {
  if(locked.value)return;
  const own=++editorSequence;selected.value=typeof item==='string'?undefined:item;etag.value='';latest.value=undefined;history.value=undefined;historyPage.value=1;historyError.value='';errors.value={};dialogError.value='';notice.value='';detailFailed.value=false;attempt.reset();uncertain.value=false;
  Object.assign(form,formOf(typeof item==='string'?undefined:item));
  if(!item&&kind.value==='documents') {form.periodId=periods.value.find(p=>p.status==='open'&&p.startsOn<=form.postingDate&&p.endsOn>=form.postingDate)?.id??'';}
  base.value=payload(kind.value,form);open.value=true;detailBusy.value=!!item;
  await nextTick();if(disposed||own!==editorSequence)return;editor.value?.showModal();
  if(!item)return;
  try {
    const id=typeof item==='string'?item:item.id;
    const result=await request<RecordItem>(`${root()}/${kind.value}/${id}`,{signal:life.signal});
    if(disposed||own!==editorSequence||!open.value)return;
    selected.value=result.data;etag.value=result.etag;Object.assign(form,formOf(result.data));base.value=payload(kind.value,form);
  }catch(e){if(!disposed&&own===editorSequence){detailFailed.value=true;dialogError.value=failed(e);}}
  finally{if(!disposed&&own===editorSequence)detailBusy.value=false;}
}
function close(force=false) {
  if(!force&&(locked.value||dirty.value&&!confirm('Zahodit neuložené účetní změny?')))return false;
  editorSequence++;editor.value?.close();open.value=false;return true;
}
function canLeave() {return close();}
defineExpose({canLeave,openDocument:(id:string)=>show(id)});
async function submit(command:''|'post'|'close'='') {
  if(saving.value||detailBusy.value||detailFailed.value||!lookupsReady.value||latest.value)return;
  if(command==='post'&&!canPost.value||command==='close'&&!canClose.value||!command&&readonly.value)return;
  if(command&&!selected.value)return;
  if(!command&&!uncertain.value){errors.value=validate(kind.value,form);if(Object.keys(errors.value).length)return;}
  if(command&&!uncertain.value&&!confirm(command==='post'?'Zaúčtovat uložený doklad? Číslo se přidělí automaticky a doklad již nebude možné změnit.':'Uzavřít období? Další zápisy do něj již nebudou povoleny.'))return;
  if(command&&dirty.value){dialogError.value='Nejprve uložte změny návrhu.';return;}
  const path=`${root()}/${kind.value}${selected.value?`/${selected.value.id}`:''}${command?`/${command}`:''}`;
  const method=selected.value&&!command?'PATCH':'POST';
  const data=command?{}:selected.value?diff(base.value,payload(kind.value,form)):payload(kind.value,form);
  if(selected.value&&!etag.value){dialogError.value='Chybí verze záznamu. Načtěte detail znovu.';return;}
  const write=uncertain.value?attempt.current!:attempt.prepare(path,method,data,selected.value?etag.value:'');
  await send(write,command);
}
async function send(write:NonNullable<WriteAttempt['current']>,command:string) {
  saving.value=true;dialogError.value='';errors.value={};
  try {
    const result=await request<RecordItem>(write.path,{method:write.method,headers:write.headers,body:write.body,signal:life.signal});
    if(disposed)return;
    attempt.reset();uncertain.value=false;selected.value=result.data;etag.value=result.etag;
    Object.assign(form,formOf(result.data));base.value=payload(kind.value,form);history.value=undefined;
    notice.value=command==='post'?'Doklad byl zaúčtován.':command==='close'?'Období bylo uzavřeno.':'Záznam byl uložen.';
    await loadList();
  }catch(e){
    if(disposed)return;
    dialogError.value=failed(e,command);
    if(e instanceof ApiError){
      for(const violation of e.violations)errors.value[violation.propertyPath]=violation.message;
      if(e.status===0||e.status>=500){attempt.uncertain=true;uncertain.value=true;}
      else if(e.status===412&&selected.value){
        try {const current=await request<RecordItem>(`${root()}/${kind.value}/${selected.value.id}`,{signal:life.signal});if(!disposed)latest.value={item:current.data,etag:current.etag};}
        catch(loadError){if(!disposed)dialogError.value=failed(loadError);}
      }
    }
  }finally{if(!disposed)saving.value=false;}
}
function retry() {if(attempt.current&&!saving.value)void send(attempt.current,attempt.current.path.endsWith('/post')?'post':attempt.current.path.endsWith('/close')?'close':'');}
function resolveConflict(keep:boolean) {
  if(!latest.value)return;
  const next=payload(kind.value,form),current=latest.value;
  const merged=keep?{...payload(kind.value,formOf(current.item)),...diff(base.value,next)}:payload(kind.value,formOf(current.item));
  Object.assign(form,formOf(current.item),merged,{parentId:merged.parentId??'',partnerId:merged.partnerId??''});
  selected.value=current.item;etag.value=current.etag;base.value=payload(kind.value,formOf(current.item));latest.value=undefined;dialogError.value='';errors.value={};attempt.reset();uncertain.value=false;
}
async function loadHistory(delta=0) {
  if(!selected.value||!canHistory.value||historyBusy.value)return;
  const own=editorSequence,target=historyPage.value+delta;historyBusy.value=true;historyError.value='';
  try {const result=await request<History>(`${root()}/${kind.value}/${selected.value.id}/history?page=${target}&itemsPerPage=10`,{signal:life.signal});if(!disposed&&own===editorSequence){history.value=result.data;historyPage.value=target;}}
  catch(e){if(!disposed&&own===editorSequence)historyError.value=failed(e);}finally{if(!disposed)historyBusy.value=false;}
}
function addLine(){form.lines.push({accountId:'',text:'',md:'0.00',dal:'0.00'});}
function beforeUnload(e:BeforeUnloadEvent){if(dirty.value||uncertain.value){e.preventDefault();e.returnValue='';}}
onMounted(()=>{void loadList();void loadLookups();window.addEventListener('beforeunload',beforeUnload);});
onBeforeUnmount(()=>{disposed=true;life.abort();listAbort?.abort();clearTimeout(timer);window.removeEventListener('beforeunload',beforeUnload);emit('locked',false);});
</script>

<template>
  <main v-if="!detailOnly" id="accounting" class="accounting">
    <div class="breadcrumb">{{unitCode}} <span>/</span> Účetnictví <span>/</span> {{names[section]}}</div>
    <div class="heading"><div><p class="eyebrow">ÚČETNICTVÍ V SOUVISLOSTECH</p><h1>{{names[section]}} <span class="count">{{total}}</span></h1><p>{{section==='journal'?'Zaúčtované řádky s dohledatelným dokladem.':section==='documents'?'Od rozpracovaného návrhu k zaúčtovanému dokladu.':section==='accounts'?'Účtová osnova vaší účetní jednotky.':'Otevřená a uzavřená účetní období.'}}</p></div>
      <button v-if="canWrite&&section!=='journal'" class="primary" :disabled="!lookupsReady||locked" @click="show()">{{kind==='accounts'?'Nový účet':kind==='periods'?'Nové období':'Nový doklad'}}</button></div>
    <p v-if="notice" role="status" class="success">{{notice}}</p>
    <p v-if="error" role="alert" class="alert">{{error}} <button @click="loadList">Zkusit znovu</button></p>
    <p v-if="lookupError" role="alert" class="alert">Podklady formuláře se nepodařilo načíst. {{lookupError}} <button @click="loadLookups">Načíst podklady</button></p>
    <section v-if="canRead" class="directory" :aria-label="names[section]">
      <div class="toolbar accounting-filters"><label class="search"><input v-model="search" type="search" maxlength="100" :aria-label="`Hledat: ${names[section]}`" placeholder="Hledat v záznamech…"></label>
        <template v-if="section==='documents'||section==='journal'"><label class="filter">Období<select v-model="periodFilter" aria-label="Filtr období"><option value="">Všechna období</option><option v-for="p in periods" :key="p.id" :value="p.id">{{p.name}}</option></select></label>
          <label v-if="section==='journal'" class="filter">Účet<select v-model="accountFilter" aria-label="Filtr účtu"><option value="">Všechny účty</option><option v-for="a in accounts" :key="a.id" :value="a.id">{{a.code}} · {{a.name}}</option></select></label>
          <label class="filter">Od<input v-model="from" type="date" aria-label="Datum od"></label><label class="filter">Do<input v-model="to" type="date" aria-label="Datum do"></label></template>
        <button :disabled="loading||locked" aria-label="Obnovit účetní seznam" @click="loadList();loadLookups()">↻</button></div>
      <div class="table-scroll" :aria-busy="loading"><table class="accounting-table"><thead>
        <tr v-if="section==='accounts'"><th>Kód a název</th><th>Nadřazený účet</th><th>Účtovatelný</th><th>Stav</th></tr>
        <tr v-else-if="section==='periods'"><th>Období</th><th>Od</th><th>Do</th><th>Stav</th></tr>
        <tr v-else-if="section==='documents'"><th>Doklad</th><th>Datum</th><th>MD / DAL · CZK</th><th>Stav</th></tr>
        <tr v-else><th>Doklad / datum</th><th>Účet</th><th>Popis řádku</th><th>MD · CZK</th><th>DAL · CZK</th></tr>
      </thead><tbody v-if="!loading">
        <template v-for="row in rows" :key="row.id">
          <tr v-if="'code' in row"><td><button class="record-link" @click="show(row)"><strong>{{row.code}}</strong> {{row.name}}</button></td><td>{{row.parentId?accountName(row.parentId):'—'}}</td><td>{{row.postable?'Ano':'Ne'}}</td><td><span class="status" :class="{inactive:!row.active}">{{row.active?'Aktivní':'Neaktivní'}}</span></td></tr>
          <tr v-else-if="'startsOn' in row"><td><button class="record-link" @click="show(row)">{{row.name}}</button></td><td>{{row.startsOn}}</td><td>{{row.endsOn}}</td><td><span class="status" :class="{inactive:row.status==='closed'}">{{row.status==='closed'?'Uzavřené':'Otevřené'}}</span></td></tr>
          <tr v-else><td><button class="record-link" @click="show(row)">{{row.number??'Návrh bez čísla'}}<small>{{row.text}}</small></button></td><td>{{row.postingDate}}</td><td class="numeric">{{money(row.totalMd)}}<small>{{money(row.totalDal)}}</small></td><td><span class="status" :class="{inactive:row.status==='draft'}">{{row.status==='posted'?'Zaúčtovaný':'Rozpracovaný'}}</span></td></tr>
        </template>
        <tr v-for="row in journal" :key="row.id"><td><button class="record-link" @click="show(row.documentId)">{{row.number}}<small>{{row.postingDate}} · řádek {{row.lineNo}}</small></button></td><td>{{row.accountCode}}<small>{{row.accountName}}</small></td><td>{{row.text||row.documentText}}</td><td class="numeric">{{money(row.md)}}</td><td class="numeric">{{money(row.dal)}}</td></tr>
      </tbody></table></div>
      <div v-if="loading" class="empty" role="status">Načítám účetní záznamy…</div><div v-else-if="!rows.length&&!journal.length" class="empty"><h3>{{error?'Záznamy nejsou dostupné':'Žádné odpovídající záznamy'}}</h3><p>{{section==='journal'?'Deník obsahuje pouze zaúčtované řádky.':'Změňte filtr nebo vytvořte první záznam.'}}</p></div>
      <footer class="pagination"><span>{{total}} záznamů</span><div><button :disabled="page<=1||loading" @click="page--;loadList()">Předchozí</button><span>{{page}} / {{pages}}</span><button :disabled="page>=pages||loading" @click="page++;loadList()">Další</button></div></footer>
    </section><p v-else class="empty">Pro tuto sekci nemáte oprávnění ke čtení.</p>
    <p class="footnote">Částky jsou v CZK. Údaje a oprávnění patří pouze vybrané účetní jednotce.</p>
  </main>
  <dialog ref="editor" class="editor accounting-editor" aria-labelledby="accounting-title" @cancel.prevent="close()">
    <header class="editor-header"><div><p class="eyebrow">{{unitCode}} / {{names[kind]}}</p><h2 id="accounting-title">{{selected?'Detail: '+singular[kind]:kind==='accounts'?'Nový účet':kind==='periods'?'Nové období':'Nový doklad'}}</h2><p v-if="selected&&'status' in selected" class="record-state">{{selected.status==='posted'?'Zaúčtováno · '+('number' in selected?selected.number:''):selected.status==='closed'?'Uzavřené období':selected.status==='draft'?'Rozpracovaný návrh':'Otevřené období'}}</p></div><button aria-label="Zavřít účetní detail" :disabled="locked" @click="close()">×</button></header>
    <form @submit.prevent="submit()" novalidate><div class="editor-body">
      <p v-if="detailBusy" role="status">Načítám detail…</p><p v-if="notice" role="status" class="success">{{notice}}</p><p v-if="dialogError" role="alert" class="alert">{{dialogError}}</p>
      <p v-if="lookupError" role="alert" class="alert">Podklady formuláře se nepodařilo načíst. <button type="button" :disabled="locked" @click="loadLookups">Načíst podklady formuláře</button></p>
      <p v-if="uncertain" role="alert" class="alert">Výsledek zápisu zatím není ověřený. Neprovádějte nový zápis; zopakujte původní požadavek.</p>
      <div v-if="latest" class="conflict" role="alert"><h3>Záznam se mezitím změnil</h3><p>Porovnejte svoje změny s uloženou verzí.</p><div class="table-scroll"><table><thead><tr><th>Pole</th><th>Vaše změna</th><th>Uložená hodnota</th></tr></thead><tbody><tr v-for="key in conflictKeys" :key="key"><td>{{labels[key]??key}}</td><td>{{describe(key,payload(kind,form)[key])}}</td><td>{{describe(key,payload(kind,formOf(latest.item))[key])}}</td></tr></tbody></table></div>
        <button type="button" @click="resolveConflict(false)">Použít aktuální verzi</button><button v-if="!uncertain&&(!('status' in latest.item)||!['posted','closed'].includes(latest.item.status))" type="button" @click="resolveConflict(true)">Ponechat moje změny</button></div>
      <ul v-if="Object.keys(errors).length" class="field-error" role="alert"><li v-for="(value,key) in errors" :key="key">{{labels[key]??key}}: {{value}}</li></ul>
      <fieldset :disabled="!!readonly||saving||uncertain||detailBusy||detailFailed||!lookupsReady||!!latest">
        <div v-if="kind==='accounts'" class="form-grid"><label>Kód účtu<input v-model="form.code" maxlength="20" inputmode="numeric" :aria-invalid="!!errors.code"></label><label>Název účtu<input v-model="form.name" maxlength="200" :aria-invalid="!!errors.name"></label>
          <label class="wide">Nadřazený účet<select v-model="form.parentId"><option value="">Bez nadřazeného účtu</option><option v-for="a in accounts.filter(a=>a.id!==selected?.id)" :key="a.id" :value="a.id">{{a.code}} · {{a.name}}</option></select></label>
          <label class="checkbox"><input v-model="form.active" type="checkbox">Aktivní účet</label><label class="checkbox"><input v-model="form.postable" type="checkbox">Účtovatelný účet</label></div>
        <div v-else-if="kind==='periods'" class="form-grid"><label class="wide">Název období<input v-model="form.name" maxlength="200"></label><label>Začátek období<input v-model="form.startsOn" type="date"></label><label>Konec období<input v-model="form.endsOn" type="date"></label></div>
        <template v-else><div class="form-grid"><label>Účetní období<select v-model="form.periodId"><option value="">Vyberte období</option><option v-for="p in periods" :key="p.id" :value="p.id" :disabled="p.status==='closed'">{{p.name}}{{p.status==='closed'?' · uzavřené':''}}</option></select></label><label>Datum účtování<input v-model="form.postingDate" type="date"></label>
          <label class="wide">Popis dokladu<textarea v-model="form.text" maxlength="2000" rows="2"></textarea></label><label class="wide">Partner dokladu<select v-model="form.partnerId"><option value="">Bez partnera</option><option v-if="form.partnerId&&!partners.some(p=>p.id===form.partnerId)" :value="form.partnerId">{{form.partnerId}}</option><option v-for="p in partners" :key="p.id" :value="p.id" :disabled="!p.active">{{p.name}}{{!p.active?' · neaktivní':''}}</option></select></label></div>
          <div class="section-heading line-heading"><h3>Řádky MD / DAL</h3><button v-if="!readonly" type="button" :disabled="form.lines.length>=500" @click="addLine">Přidat řádek</button></div>
          <div class="document-lines"><section v-for="(line,index) in form.lines" :key="index" class="document-line" :aria-label="`Řádek ${index+1}`"><div class="line-number">{{String(index+1).padStart(2,'0')}}</div><div class="form-grid"><label class="wide">Účet řádku {{index+1}}<select v-model="line.accountId"><option value="">Vyberte účet</option><option v-for="a in accounts" :key="a.id" :value="a.id" :disabled="!a.active||!a.postable">{{a.code}} · {{a.name}}{{!a.active||!a.postable?' · nelze účtovat':''}}</option></select></label><label class="wide">Popis řádku {{index+1}}<input v-model="line.text" maxlength="500"></label><label>MD řádek {{index+1}}<input v-model="line.md" inputmode="decimal" autocomplete="off"></label><label>DAL řádek {{index+1}}<input v-model="line.dal" inputmode="decimal" autocomplete="off"></label></div><button v-if="!readonly" type="button" :aria-label="`Odstranit řádek ${index+1}`" @click="form.lines.splice(index,1)">×</button></section></div>
        </template>
      </fieldset>
      <div v-if="kind==='documents'" class="totals" aria-live="polite"><div><small>CELKEM MD · CZK</small><strong>{{money(sum.md)}}</strong></div><div><small>CELKEM DAL · CZK</small><strong>{{money(sum.dal)}}</strong></div><div :class="{'unbalanced':!sum.balanced}"><small>ROZDÍL · CZK</small><strong>{{sum.valid?money(sum.difference):'Zkontrolujte částky'}}</strong></div><p>{{sum.balanced&&form.lines.length>=2?'Doklad je vyrovnaný.':'Nevyrovnaný nebo prázdný návrh lze uložit. Zaúčtování vyžaduje alespoň dva vyrovnané řádky.'}}</p></div>
      <p v-if="readonly" class="form-note">Záznam je pouze ke čtení.</p>
      <div v-if="selected" class="record-meta"><span>Verze {{selected.version}}</span><span>Aktualizace {{date(selected.updatedAt)}}</span></div>
      <p v-if="selected&&'postedAt' in selected&&selected.postedAt" class="form-note">Zaúčtováno {{date(selected.postedAt)}} · uživatel {{selected.postedBy}}</p>
      <p v-if="selected&&'closedAt' in selected&&selected.closedAt" class="form-note">Uzavřeno {{date(selected.closedAt)}} · uživatel {{selected.closedBy}}</p>
      <section v-if="selected&&canHistory" class="history"><div class="section-heading"><h3>Historie změn</h3><button type="button" :disabled="historyBusy||locked" @click="loadHistory()">Zobrazit účetní historii</button></div><p v-if="historyError" role="alert" class="alert">{{historyError}}</p><p v-if="historyBusy" role="status">Načítám historii…</p>
        <article v-for="entry in history?.items" :key="entry.id" class="history-item"><strong>{{entry.operation==='I'?'Vytvoření':entry.operation==='D'?'Odstranění':'Změna'}}</strong><small>{{date(entry.occurred_at)}} · {{entry.user_id}}</small><dl><template v-for="key in entry.changed_cols" :key="key"><dt>{{labels[key]??key}}</dt><dd><del>{{describe(key,entry.old_data?.[key])}}</del> → {{describe(key,entry.new_data?.[key])}}</dd></template></dl></article>
        <div v-if="history" class="history-pages"><button type="button" :disabled="historyPage<=1||historyBusy" @click="loadHistory(-1)">Starší stránka zpět</button><span>{{historyPage}} / {{Math.max(1,Math.ceil(history.total/10))}}</span><button type="button" :disabled="historyPage*10>=history.total||historyBusy" @click="loadHistory(1)">Další historie</button></div></section>
    </div><footer class="editor-footer">
      <button type="button" :disabled="locked" @click="close()">Zavřít</button>
      <button v-if="uncertain&&!latest" type="button" class="primary" :disabled="saving" @click="retry">Opakovat původní požadavek</button>
      <template v-else-if="!uncertain"><button v-if="selected&&canPost&&kind==='documents'&&'status' in selected&&selected.status==='draft'" type="button" :disabled="saving||detailBusy||!lookupsReady||dirty||!etag||!sum.balanced||form.lines.length<2||!!latest" @click="submit('post')">Zaúčtovat doklad</button>
        <button v-if="selected&&canClose&&kind==='periods'&&'status' in selected&&selected.status==='open'" type="button" :disabled="saving||detailBusy||!lookupsReady||dirty||!etag||!!latest" @click="submit('close')">Uzavřít období</button>
        <button v-if="!readonly" class="primary" :disabled="saving||detailBusy||detailFailed||!lookupsReady||!!latest||!!selected&&(!etag||!dirty)" type="submit">{{saving?'Ukládám…':kind==='documents'?'Uložit návrh':'Uložit záznam'}}</button></template>
    </footer></form>
  </dialog>
</template>
