<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';
import './reports.css';
import AccountingWorkspace from './AccountingWorkspace.vue';
import { ApiError, downloadCsv, request, SessionExpired } from './api';
import { money, type Account, type Period } from './accounting';
import { date } from './partner';
import { reportErrors, reportParams, reportPermissions, type LedgerReport, type TrialReport, type ReportSection, type ReportSelection } from './reports';

const props=defineProps<{unit:string;unitCode:string;permissions:string[];section:ReportSection;initialSelection?:ReportSelection}>();
const emit=defineEmits<{failure:[unknown];ledger:[ReportSelection]}>();
const ledger=computed(()=>props.section==='general-ledger');
const canRead=computed(()=>reportPermissions.every(p=>props.permissions.includes(p)));
const accounts=ref<Account[]>([]),periods=ref<Period[]>([]),ready=ref(false),lookupBusy=ref(false),lookupError=ref('');
const selection=reactive<ReportSelection>({periodId:'',accountId:'',from:'',to:'',includeZero:false});
const data=ref<LedgerReport|TrialReport>(),loading=ref(false),error=ref(''),downloadError=ref(''),downloadNotice=ref(''),downloading=ref(false),page=ref(1);
const viewer=ref<InstanceType<typeof AccountingWorkspace>>();
const viewerActive=ref(false);
const period=computed(()=>periods.value.find(p=>p.id===selection.periodId));
const validation=computed(()=>reportErrors(selection,ledger.value,period.value));
const ledgerData=computed(()=>data.value&&'openingBalance' in data.value.totals?data.value as LedgerReport:undefined);
const trialData=computed(()=>data.value&&'balanced' in data.value.totals?data.value as TrialReport:undefined);
const pages=computed(()=>Math.max(1,Math.ceil((data.value?.total??0)/20)));
const canDownload=computed(()=>canRead.value&&ready.value&&!!data.value&&!validation.value.length&&!loading.value&&!downloading.value&&!error.value);
let disposed=false,sequence=0,lookupSequence=0,timer:ReturnType<typeof setTimeout>;
let listAbort:AbortController|undefined,lookupAbort:AbortController|undefined,downloadAbort:AbortController|undefined;
const urls=new Map<string,ReturnType<typeof setTimeout>>();
const root=()=>`accounting-units/${encodeURIComponent(props.unit)}`;
function cancelResult() {
  ++sequence;listAbort?.abort();downloadAbort?.abort();loading.value=false;downloading.value=false;
  data.value=undefined;error.value='';downloadError.value='';downloadNotice.value='';
}
function failure(e:unknown):string {
  if(e instanceof SessionExpired||e instanceof ApiError&&[401,403].includes(e.status)) {
    cancelResult();lookupAbort?.abort();++lookupSequence;ready.value=false;accounts.value=[];periods.value=[];viewer.value?.canLeave();emit('failure',e);
  }
  if(e instanceof ApiError) {
    if(e.status===422)return e.violations.map(v=>v.message).join(' ')||'Export je příliš velký. Zúžte rozsah dat.';
    return `${e.message}${e.violations.length?' '+e.violations.map(v=>v.message).join(' '):''}${e.requestId?' Reference: '+e.requestId:''}`;
  }
  return e instanceof Error?e.message:'Akci se nepodařilo dokončit. Zkuste ji znovu.';
}
async function all<T>(resource:string,signal:AbortSignal):Promise<T[]> {
  const items:T[]=[];
  for(let p=1;!disposed;++p){
    const result=await request<T[]>(`${root()}/${resource}?page=${p}&itemsPerPage=100`,{signal});items.push(...result.data);
    if(items.length>=result.total||!result.data.length)return items;
  }
  return items;
}
async function initialize() {
  cancelResult();clearTimeout(timer);lookupAbort?.abort();ready.value=false;accounts.value=[];periods.value=[];lookupError.value='';
  if(!canRead.value)return;
  const own=++lookupSequence,abort=lookupAbort=new AbortController();lookupBusy.value=true;
  try {
    const [a,p]=await Promise.all([all<Account>('accounts',abort.signal),all<Period>('periods',abort.signal)]);
    if(disposed||own!==lookupSequence||abort.signal.aborted)return;
    accounts.value=a;periods.value=p;
    const today=new Date().toLocaleDateString('sv-SE');
    const initial=props.initialSelection;
    const selected=p.find(row=>row.id===(initial?.periodId||selection.periodId))??p.find(row=>row.startsOn<=today&&row.endsOn>=today)??p[0];
    selection.periodId=selected?.id??'';selection.from=selected?.startsOn??'';selection.to=selected?.endsOn??'';
    selection.accountId=a.find(row=>row.id===(initial?.accountId||selection.accountId))?.id??a[0]?.id??'';
    if(initial&&selected?.id===initial.periodId){selection.from=initial.from;selection.to=initial.to;selection.includeZero=initial.includeZero;}
    ready.value=true;page.value=1;await loadReport();
  }catch(e){if(!disposed&&own===lookupSequence&&!abort.signal.aborted)lookupError.value=failure(e);}
  finally{if(!disposed&&own===lookupSequence)lookupBusy.value=false;}
}
watch(()=>selection.periodId,()=>{if(ready.value){selection.from=period.value?.startsOn??'';selection.to=period.value?.endsOn??'';}},{flush:'sync'});
watch(()=>[selection.periodId,selection.accountId,selection.from,selection.to,selection.includeZero],()=>{
  cancelResult();page.value=1;clearTimeout(timer);
  if(ready.value&&canRead.value)timer=setTimeout(()=>void loadReport(),300);
},{flush:'sync'});
watch(canRead,allowed=>{if(allowed)void initialize();else {cancelResult();lookupAbort?.abort();++lookupSequence;ready.value=false;accounts.value=[];periods.value=[];}},{immediate:true});
async function loadReport() {
  listAbort?.abort();downloadAbort?.abort();downloading.value=false;downloadError.value='';downloadNotice.value='';data.value=undefined;error.value='';
  if(!ready.value||!canRead.value||validation.value.length)return;
  const own=++sequence,abort=listAbort=new AbortController();loading.value=true;
  const q=reportParams({...selection},ledger.value,page.value);
  try {
    const result=await request<LedgerReport|TrialReport>(`${root()}/reports/${props.section}?${q}`,{signal:abort.signal});
    if(disposed||own!==sequence||abort.signal.aborted)return;
    data.value=result.data;
    if(page.value>pages.value){page.value=pages.value;await loadReport();}
  }catch(e){if(!disposed&&own===sequence&&!abort.signal.aborted)error.value=failure(e);}
  finally{if(!disposed&&own===sequence)loading.value=false;}
}
function changePage(delta:number){cancelResult();page.value+=delta;void loadReport();}
async function exportCsv() {
  if(!canDownload.value)return;
  downloadAbort?.abort();const abort=downloadAbort=new AbortController();const own=sequence;
  downloading.value=true;downloadError.value='';downloadNotice.value='';
  try {
    const file=await downloadCsv(`${root()}/reports/${props.section}.csv?${reportParams({...selection},ledger.value)}`,abort.signal);
    if(disposed||abort.signal.aborted||own!==sequence||!canRead.value)return;
    const url=URL.createObjectURL(file.blob),link=document.createElement('a');link.href=url;link.download=file.filename;
    document.body.append(link);link.click();link.remove();
    urls.set(url,setTimeout(()=>{URL.revokeObjectURL(url);urls.delete(url);},1000));
    downloadNotice.value='Soubor CSV byl předán prohlížeči ke stažení.';
  }catch(e){if(!disposed&&!abort.signal.aborted&&own===sequence)downloadError.value=failure(e);}
  finally{if(!disposed&&downloadAbort===abort)downloading.value=false;}
}
function openAccount(id:string){emit('ledger',{...selection,accountId:id});}
async function openDocument(id:string){viewerActive.value=true;await nextTick();if(!disposed&&canRead.value)await viewer.value?.openDocument(id);}
function canLeave(){return viewer.value?.canLeave()??true;}
defineExpose({canLeave});
onBeforeUnmount(()=>{
  disposed=true;clearTimeout(timer);listAbort?.abort();lookupAbort?.abort();downloadAbort?.abort();
  for(const [url,timeout] of urls){clearTimeout(timeout);URL.revokeObjectURL(url);}urls.clear();
});
</script>

<template>
  <main id="reports" class="accounting reports">
    <div class="breadcrumb">{{unitCode}} <span>/</span> Účetnictví <span>/</span> Sestavy</div>
    <div class="heading"><div><p class="eyebrow">PŘEHLED ZAÚČTOVANÝCH POHYBŮ</p><h1>{{ledger?'Hlavní kniha':'Obratová předvaha'}}</h1><p>{{ledger?'Pohyby a průběžný zůstatek vybraného účtu.':'Zůstatky a obraty všech účtů ve vybraném období.'}}</p></div>
      <button v-if="canRead" class="primary" :disabled="!canDownload" @click="exportCsv">{{downloading?'Připravuji CSV…':'Stáhnout CSV'}}</button></div>
    <p v-if="!canRead" class="empty" role="status">Pro sestavy potřebujete oprávnění ke čtení jednotky, účtů, období a dokladů.</p>
    <template v-else>
      <div v-if="lookupError" class="alert" role="alert">{{lookupError}} <button @click="initialize">Načíst podklady znovu</button></div>
      <p v-if="lookupBusy&&!ready" role="status">Načítám účty a období…</p>
      <fieldset class="report-filters" :disabled="!ready">
        <label>Účetní období<select v-model="selection.periodId" aria-label="Účetní období"><option value="">Vyberte období</option><option v-for="p in periods" :key="p.id" :value="p.id">{{p.name}}{{p.status==='closed'?' · uzavřené':''}}</option></select></label>
        <label v-if="ledger">Účet<select v-model="selection.accountId" aria-label="Účet"><option value="">Vyberte účet</option><option v-for="a in accounts" :key="a.id" :value="a.id">{{a.code}} · {{a.name}}{{!a.active?' · neaktivní':''}}{{!a.postable?' · neúčtovatelný':''}}</option></select></label>
        <label>Od<input v-model="selection.from" type="date" :min="period?.startsOn" :max="period?.endsOn"></label>
        <label>Do<input v-model="selection.to" type="date" :min="period?.startsOn" :max="period?.endsOn"></label>
        <label v-if="!ledger" class="checkbox"><input v-model="selection.includeZero" type="checkbox">Zobrazit i účty bez pohybů</label>
        <button :disabled="!!validation.length||loading" @click="loadReport">Obnovit sestavu</button>
      </fieldset>
      <ul v-if="ready&&validation.length" class="field-error" role="alert"><li v-for="message in validation" :key="message">{{message}}</li></ul>
      <p class="report-basis">Výpočet z evidovaných zaúčtovaných pohybů vybraného období. Převod počátečních stavů z jiných období není zahrnut.</p>
      <p v-if="error" class="alert" role="alert">{{error}} <button @click="loadReport">Zkusit znovu</button></p>
      <p v-if="downloadError" class="alert" role="alert">{{downloadError}}</p><p v-if="downloadNotice" class="success" role="status">{{downloadNotice}}</p>
      <p v-if="downloading" role="status">Připravuji celý výběr do CSV. Může to trvat až dvě minuty.</p>
      <p v-if="loading" class="empty" role="status">Načítám sestavu…</p>
      <template v-if="data">
        <section v-if="ledgerData" class="report-summary" aria-label="Součty hlavní knihy">
          <div><small>Počáteční zůstatek výběru · MD − DAL</small><strong>{{money(ledgerData.totals.openingBalance)}}</strong></div>
          <div><small>Obrat MD</small><strong>{{money(ledgerData.totals.turnoverMd)}}</strong></div><div><small>Obrat DAL</small><strong>{{money(ledgerData.totals.turnoverDal)}}</strong></div>
          <div><small>Konečný zůstatek · MD − DAL</small><strong>{{money(ledgerData.totals.closingBalance)}}</strong></div>
        </section>
        <p v-if="ledgerData" class="footnote">Kladný zůstatek znamená MD, záporný DAL. Součty zahrnují celý výběr, nikoli jen tuto stránku. Částky jsou v CZK.</p>
        <section v-if="trialData" class="directory report-summary-table" aria-label="Součty předvahy"><div class="table-scroll"><table><thead><tr><th>Celý výběr · CZK</th><th>MD</th><th>DAL</th><th>Rozdíl MD − DAL</th></tr></thead><tbody>
          <tr><th>Počáteční zůstatky výběru</th><td class="numeric">{{money(trialData.totals.openingMd)}}</td><td class="numeric">{{money(trialData.totals.openingDal)}}</td><td class="numeric">{{money(trialData.totals.openingDifference)}}</td></tr>
          <tr><th>Obraty</th><td class="numeric">{{money(trialData.totals.turnoverMd)}}</td><td class="numeric">{{money(trialData.totals.turnoverDal)}}</td><td class="numeric">{{money(trialData.totals.turnoverDifference)}}</td></tr>
          <tr><th>Konečné zůstatky</th><td class="numeric">{{money(trialData.totals.closingMd)}}</td><td class="numeric">{{money(trialData.totals.closingDal)}}</td><td class="numeric">{{money(trialData.totals.closingDifference)}}</td></tr>
        </tbody></table></div></section>
        <p v-if="trialData" :class="trialData.totals.balanced?'success':'alert'" :role="trialData.totals.balanced?'status':'alert'">{{trialData.totals.balanced?'Součty MD a DAL jsou vyrovnané.':'Součty MD a DAL nejsou vyrovnané. Zkontrolujte účetní data.'}}</p>
        <p class="footnote">Pro další sloupce můžete tabulku posunout vodorovně.</p>
        <section class="directory" :aria-label="ledger?'Pohyby účtu':'Účty předvahy'"><div class="table-scroll" tabindex="0" :aria-label="ledger?'Tabulka pohybů účtu':'Tabulka předvahy'"><table class="accounting-table report-table"><thead>
          <tr v-if="ledger"><th>Doklad / datum</th><th>Popis</th><th>MD · CZK</th><th>DAL · CZK</th><th>Zůstatek MD − DAL</th></tr>
          <tr v-else><th>Účet</th><th>Počáteční MD</th><th>Počáteční DAL</th><th>Obrat MD</th><th>Obrat DAL</th><th>Konečné MD</th><th>Konečné DAL</th></tr>
        </thead><tbody>
          <tr v-for="row in ledgerData?.items??[]" :key="row.id"><td><button class="record-link" @click="openDocument(row.documentId)">{{row.number}}<small>{{row.postingDate}} · řádek {{row.lineNo}}</small></button></td><td>{{row.text||row.documentText}}</td><td class="numeric">{{money(row.md)}}</td><td class="numeric">{{money(row.dal)}}</td><td class="numeric">{{money(row.runningBalance)}}</td></tr>
          <tr v-for="row in trialData?.items??[]" :key="row.accountId"><td><button class="record-link" @click="openAccount(row.accountId)"><strong>{{row.accountCode}}</strong> {{row.accountName}}</button></td><td v-for="amount in [row.openingMd,row.openingDal,row.turnoverMd,row.turnoverDal,row.closingMd,row.closingDal]" class="numeric">{{money(amount)}}</td></tr>
        </tbody></table></div>
          <div v-if="!data.items.length" class="empty" role="status">{{ledger?'Ve vybraném rozsahu nejsou žádné zaúčtované pohyby účtu.':'Ve vybraném rozsahu nejsou žádné odpovídající účty.'}}</div>
          <footer class="pagination"><span>{{data.total}} záznamů · součty za celý výběr</span><div><button :disabled="page<=1||loading" @click="changePage(-1)">Předchozí</button><span>{{page}} / {{pages}}</span><button :disabled="page>=pages||loading" @click="changePage(1)">Další</button></div></footer>
        </section>
        <p class="footnote">Sestaveno {{date(data.generatedAt)}}. Export se sestaví z aktuálních dat při stažení a zahrnuje všechny stránky. Limit je 50 000 datových řádků.</p>
        <p v-if="trialData" class="footnote">Nadřazené účty zobrazují pouze vlastní pohyby; podřízené účty se k nim automaticky nepřičítají.</p>
      </template>
      <p v-else-if="ready&&!periods.length" class="empty">Nejprve vytvořte účetní období v sekci Období.</p>
    </template>
    <AccountingWorkspace v-if="canRead&&viewerActive" ref="viewer" :unit="unit" :unit-code="unitCode" section="documents" :permissions="permissions" detail-only @failure="failure($event)" />
  </main>
</template>
