/* =====================================================================
   MONSTRO admin — фронт
   ===================================================================== */
const A = window.APP;
const enc = encodeURIComponent;
const esc = s => (s==null?'':String(s)).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const svg = n => '<i data-lucide="'+n+'"></i>';
const PAL = ['#3b82f6','#06b6d4','#22d3a6','#fbbf24','#ff5d6c','#38bdf8','#60a5fa','#4ade80','#fb923c'];
const refreshIcons = () => { try{ lucide.createIcons(); }catch(e){} };

function toast(msg, ok=true){
  const t=document.getElementById('toast'); if(!t) return;
  t.className='toast show '+(ok?'ok':'bad');
  t.querySelector('.ic').setAttribute('data-lucide', ok?'check-circle':'alert-circle');
  document.getElementById('toastMsg').textContent=msg; refreshIcons();
  setTimeout(()=>t.className='toast '+(ok?'ok':'bad'), 2600);
}

/* ---------------- menu (mobile) ---------------- */
function setupMenu(){
  const side=document.getElementById('side'), scrim=document.getElementById('scrim');
  const btn=document.getElementById('menuBtn');
  const tog=()=>{ side.classList.toggle('show'); scrim.classList.toggle('show'); };
  btn&&btn.addEventListener('click',tog);
  scrim&&scrim.addEventListener('click',tog);
}

/* ---------------- cell renderers ---------------- */
// бренд-иконки (Font Awesome) для платформ и браузеров
const PLATFORM_ICON = {
  Windows:{i:'fa-windows',c:'#4cb2ff'}, Android:{i:'fa-android',c:'#3ddc84'},
  iOS:{i:'fa-apple',c:'#d6dae0'}, macOS:{i:'fa-apple',c:'#d6dae0'}, Linux:{i:'fa-linux',c:'#f6c915'}
};
const BROWSER_ICON = {
  Chrome:{i:'fa-chrome',c:'#5a8dee'}, Chromium:{i:'fa-chrome',c:'#7aa6ff'},
  YaBrowser:{i:'fa-yandex',c:'#ff3d3d'}, Firefox:{i:'fa-firefox-browser',c:'#ff7139'},
  Safari:{i:'fa-safari',c:'#1aa3ff'}, Edge:{i:'fa-edge',c:'#3bd6c6'}
};
const fa = (m)=>'<i class="fa-brands '+m.i+'" style="color:'+m.c+'"></i>';
const dash = '<span class="muted">—</span>';
// поля-выпадашки в форме (фиксированные значения вместо ручного ввода)
const FORM_SELECT = {
  platform:['Windows','Android','iOS','macOS','Linux'],
  browser:['Chrome','Chromium','YaBrowser','Firefox','Safari','Edge']
};
function optsHtml(list,cur){
  let has=false, o='<option value="">—</option>';
  list.forEach(v=>{ const s=(String(cur)===String(v))?' selected':''; if(s)has=true;
    o+='<option value="'+esc(v)+'"'+s+'>'+esc(v)+'</option>'; });
  if(cur && !has) o+='<option value="'+esc(cur)+'" selected>'+esc(cur)+'</option>';
  return o;
}
// список существующих групп (из чипов фильтра) для <select>; cur — текущее значение
function groupOptionsFromChips(cur){
  const opts=[].map.call(document.querySelectorAll('#groupChips .chip'),c=>c.dataset.g)
    .filter(g=>g && g!=='__none__');
  if(cur && opts.indexOf(String(cur))<0) opts.unshift(String(cur)); // текущее значение всегда в списке
  let o='<option value="">— без группы —</option>';
  opts.forEach(g=>{ o+='<option value="'+esc(g)+'"'+(String(cur)===String(g)?' selected':'')+'>'+esc(g)+'</option>'; });
  o+='<option value="__new__">➕ Создать группу…</option>';
  return o;
}

function parseTs(s){
  // Postgres: "2026-06-03 11:44:33.583938+05" -> валидный ISO
  let v=String(s).trim().replace(' ','T').replace(/([+-]\d{2})(\d{2})?$/,(m,h,mm)=>h+':'+(mm||'00'));
  let d=new Date(v);
  if(isNaN(d.getTime())) d=new Date(String(s).trim());
  return d;
}
function relTime(s){
  if(!s) return dash;
  const d=parseTs(s);
  if(isNaN(d.getTime())) return esc(s);
  const diff=(Date.now()-d.getTime())/1000; let t;
  if(diff<60) t = (diff < -300) ? d.toLocaleDateString('ru') : 'только что'; // допуск на рассинхрон часов
  else if(diff<3600) t=Math.floor(diff/60)+' мин назад';
  else if(diff<86400) t=Math.floor(diff/3600)+' ч назад';
  else if(diff<2592000) t=Math.floor(diff/86400)+' дн назад';
  else t=d.toLocaleDateString('ru');
  return '<span class="ago" title="'+esc(d.toLocaleString('ru'))+'">'+t+'</span>';
}
const checkCircle = '<span class="chk"><i class="fa-solid fa-check"></i></span>';
function boolIc(d){
  if(d==null||d==='') return dash;
  return d=='1' ? checkCircle : dash;
}
function renderField(c,d,row){
  const name=c.name;
  if(c.is_pk) return '<span class="mono" style="color:var(--acc);font-weight:600">'+esc(d)+'</span>';
  if(name==='party') return d?('<span class="grp">'+esc(d)+'</span>'):dash;
  if(name==='platform'){
    if(!d) return dash; const m=PLATFORM_ICON[d];
    return '<span class="cell-ic">'+(m?fa(m):svg('monitor'))+esc(d)+'</span>';
  }
  if(name==='browser'){
    if(!d) return dash; const m=BROWSER_ICON[d];
    return '<span class="cell-ic">'+(m?fa(m):'<span class="bdot" style="background:#8b94a7"></span>')+esc(d)+'</span>';
  }
  if(name==='geo') return d?('<span class="badge blue">'+svg('map-pin')+esc(d)+'</span>'):dash;
  if(name==='proxy'||name==='cookies'||name==='localstorage'||name==='fingerprints'||name==='accounts')
    return d?checkCircle:dash;
  if(name==='status') return boolIc(d);
  if(c.is_bool) return boolIc(d);
  if(c.is_ts) return relTime(d);
  if(c.is_num){ return '<span class="mono">'+esc(d==null?'':d)+'</span>'; }
  if(d==null||d==='') return dash;
  if(name==='email'||name==='tel') return '<span class="mono">'+esc(d)+'</span>';
  return esc(d);
}

/* ---------------- filters state ---------------- */
const FKEY='monstro_filt_'+(A.table||'');
let FILT = {};
try{ FILT = JSON.parse(localStorage.getItem(FKEY)||'{}'); }catch(e){ FILT={}; }
function saveFilt(){ localStorage.setItem(FKEY, JSON.stringify(FILT)); }
function currentFilters(){
  const f={group:FILT.group||''};
  ['f_platform','f_browser','f_device','f_age_min','f_age_max','f_dom_min','f_dom_max','f_grp_min','f_grp_max','f_activity'].forEach(k=>{ if(FILT[k]) f[k]=FILT[k]; });
  return f;
}

/* ===================================================================
   TABLE VIEW
   =================================================================== */
function initTable(){
  // одноразовый сброс старого сохранённого состояния колонок, чтобы применились новые дефолты
  try{ if(!localStorage.getItem('monstro_state_v6')){
    Object.keys(localStorage).filter(k=>k.indexOf('DataTables_grid')>=0).forEach(k=>localStorage.removeItem(k));
    localStorage.setItem('monstro_state_v6','1');
  }}catch(e){}

  const hasSel = A.pk.length===1;   // колонка чекбоксов — только при одиночном PK
  const dtCols = [];
  if(hasSel) dtCols.push({
    data:null, orderable:false, searchable:false, className:'selcol dt-center',
    title:'<input type="checkbox" class="selall" title="Выбрать всё на странице">',
    render:(d,t,row)=> '<input type="checkbox" class="selrow" value="'+esc(row[A.pk[0]])+'">'
  });
  A.cols.forEach(c=>dtCols.push({
    data:c.name, title:c.title, name:c.name, visible:c.visible,
    render:(d,type,row)=> type==='display' ? renderField(c,d,row) : d
  }));
  if(A.pk.length) dtCols.push({
    data:null, title:'Действия', orderable:false, searchable:false, className:'rowact',
    render:()=> '<button class="btn sm" data-act="edit" title="Изменить">'+svg('pencil')+'</button>'+
                '<button class="btn sm danger" data-act="del" title="Удалить">'+svg('trash-2')+'</button>'
  });

  const table = new DataTable('#grid', {
    serverSide:true, processing:true, responsive:false, scrollX:true, // горизонтальный скролл
    stateSave:true, stateDuration:0,
    pageLength:A.perPage, lengthMenu:[[25,50,100,200],[25,50,100,200]],
    ajax:{ url:'api.php?action=data&table='+enc(A.table), type:'POST', data:d=>Object.assign(d,currentFilters()) },
    columns:dtCols,
    order:(function(){var i=A.cols.findIndex(c=>c.name===A.sortCol); return i>=0?[[i+(hasSel?1:0), A.sortDir||'desc']]:[];})(),
    layout:{ topStart:{buttons:[{extend:'colvis',text:'⚙ Колонки',columns:':not(.selcol):not(.rowact)'}]}, topEnd:'search',
             bottomStart:['pageLength','info'], bottomEnd:'paging' },
    language:{ search:'', searchPlaceholder:'Поиск…', lengthMenu:'_MENU_', info:'_START_–_END_ из _TOTAL_',
      infoFiltered:'(фильтр из _MAX_)', infoEmpty:'Нет записей', zeroRecords:'Ничего не найдено',
      loadingRecords:'', processing:'', thousands:' ',
      paginate:{first:'«',last:'»',next:'›',previous:'‹'} }
  });

  // оверлей загрузки: показан по умолчанию (в HTML), прячем на draw, показываем на processing
  const ov=document.getElementById('loadOverlay');
  const showOv=()=>{ if(ov) ov.classList.add('show'); };
  const hideOv=()=>{ if(ov) ov.classList.remove('show'); };
  table.on('processing.dt', (e,settings,on)=>{ on?showOv():hideOv(); });

  table.on('draw', ()=>{
    hideOv();
    refreshIcons();
  });

  setupFilters(table);
  setupCrud(table);
  setupBulk(table);
  setupExportImport(table);
}

/* выбранные строки — общий доступ для массовых действий и экспорта «выбранных» */
const EXPORT = { selected: null };

/* ---------------- массовые действия ---------------- */
function setupBulk(table){
  if(!A.pk || A.pk.length!==1) return;       // только при одиночном PK
  const selected = new Set();
  EXPORT.selected = selected;
  const bar  = document.getElementById('bulkbar');
  const cnt  = document.getElementById('bulkCount');
  const allLink = document.getElementById('bulkAllLink');
  if(!bar) return;

  let total = 0;            // всего по текущему фильтру (recordsDisplay)
  let allFiltered = false;  // режим «выбраны все по фильтру» (операция идёт scope=filter)

  // сколько реально будет затронуто
  const effCount = ()=> allFiltered ? total : selected.size;

  const refreshBar=()=>{
    if(cnt) cnt.textContent = effCount();
    bar.classList.toggle('show', effCount()>0);
    if(!allLink) return;
    if(allFiltered){
      allLink.style.display='';
      allLink.className='bulk-alllink on';
      allLink.textContent='✕ сбросить (выбрано все '+total+')';
    } else {
      const boxes=document.querySelectorAll('#grid tbody .selrow');
      const on=[...boxes].filter(b=>b.checked).length;
      const pageFull = boxes.length>0 && on===boxes.length;
      // предлагаем «выбрать все по фильтру», только если за пределами страницы есть ещё
      if(pageFull && total>selected.size){
        allLink.style.display='';
        allLink.className='bulk-alllink';
        allLink.textContent='Выбрать все '+total+' по фильтру →';
      } else { allLink.style.display='none'; }
    }
  };

  // привести чекбоксы текущей страницы в соответствие состоянию
  const syncChecks=()=>{
    const boxes=document.querySelectorAll('#grid tbody .selrow');
    boxes.forEach(b=>{ b.checked = allFiltered || selected.has(b.value); });
    const tot=boxes.length, on=[...boxes].filter(b=>b.checked).length;
    document.querySelectorAll('.selall').forEach(all=>{
      all.checked = tot>0 && on===tot;
      all.indeterminate = !allFiltered && on>0 && on<tot;
    });
  };

  const clear=()=>{ selected.clear(); allFiltered=false; refreshBar(); syncChecks(); };

  // на каждой перерисовке — обновить общее число и состояние
  table.on('draw', ()=>{ total = table.page.info().recordsDisplay; syncChecks(); refreshBar(); });

  // чекбокс строки: ручной клик выводит из режима «все по фильтру»
  $('#grid tbody').on('change','.selrow',function(){
    if(allFiltered){
      allFiltered=false; selected.clear();
      document.querySelectorAll('#grid tbody .selrow').forEach(b=>{ if(b.checked) selected.add(b.value); });
    } else {
      if(this.checked) selected.add(this.value); else selected.delete(this.value);
    }
    refreshBar(); syncChecks();
  });
  // главный чекбокс страницы (в т.ч. копия шапки при scrollX)
  $(document).on('change','.selall',function(){
    const on=this.checked;
    if(allFiltered && !on){ allFiltered=false; selected.clear(); }
    document.querySelectorAll('#grid tbody .selrow').forEach(b=>{
      b.checked=on; if(on && !allFiltered) selected.add(b.value); else if(!on) selected.delete(b.value);
    });
    refreshBar(); syncChecks();
  });

  // ссылка «выбрать все по фильтру» / «сбросить»
  allLink&&allLink.addEventListener('click',e=>{
    e.preventDefault();
    if(allFiltered){ clear(); }
    else { allFiltered=true; refreshBar(); syncChecks(); }
  });

  const clearBtn=document.getElementById('bulkClear');
  clearBtn&&clearBtn.addEventListener('click',clear);

  // дописать в FormData либо ids[], либо scope=filter + текущие фильтры
  const appendScope=(fd)=>{
    if(allFiltered){
      fd.append('scope','filter');
      const f=currentFilters();
      Object.entries(f).forEach(([k,v])=>{ if(v!=='' && v!=null) fd.append(k,v); });
    } else {
      [...selected].forEach(v=>fd.append('ids[]',v));
    }
  };

  // ---- подтверждение удаления ----
  const cm=document.getElementById('confirmModal');
  const ctext=document.getElementById('confirmText');
  const closeCm=()=>cm.classList.remove('open');
  document.getElementById('bulkDel').addEventListener('click',()=>{
    const n=effCount(); if(!n) return;
    ctext.textContent = allFiltered
      ? ('Удалить ВСЕ '+n+' профилей по текущему фильтру? Действие необратимо.')
      : ('Удалить выбранные записи: '+n+'? Действие необратимо.');
    cm.classList.add('open'); refreshIcons();
  });
  document.getElementById('confirmNo').addEventListener('click',closeCm);
  cm.addEventListener('click',e=>{ if(e.target===cm) closeCm(); });
  document.addEventListener('keydown',e=>{ if(e.key==='Escape') closeCm(); });

  const yes=document.getElementById('confirmYes');
  yes.addEventListener('click',()=>{
    if(!effCount()) return;
    const fd=new FormData(); fd.append('table',A.table); appendScope(fd);
    yes.disabled=true;
    fetch('api.php?action=delete_bulk&table='+enc(A.table),{method:'POST',body:fd})
      .then(r=>r.json()).then(res=>{
        if(res.ok){ toast('Удалено: '+(res.deleted!=null?res.deleted:''));
          clear(); closeCm(); table.ajax.reload(null,false); reloadGroups(table); }
        else toast(res.error||'Ошибка',false);
      }).catch(e=>toast(String(e),false)).finally(()=>{ yes.disabled=false; });
  });

  // ---- массовая смена группы ----
  const gsel=document.getElementById('bulkGroupSel');
  const gnew=document.getElementById('bulkGroupNew');
  const gapply=document.getElementById('bulkGroupApply');
  if(gsel){
    const fillGroups=()=>fetch('api.php?action=groups&table='+enc(A.table)).then(r=>r.json()).then(rows=>{
      gsel.innerHTML='<option value="">— группа —</option>'+
        rows.filter(r=>r.g && r.g!=='__none__').map(r=>'<option value="'+esc(r.g)+'">'+esc(r.g)+'</option>').join('')+
        '<option value="__new__">➕ Создать группу…</option>';
      gnew.style.display='none'; gnew.value='';
    });
    fillGroups();
    gsel.addEventListener('change',()=>{ const isNew=gsel.value==='__new__';
      gnew.style.display=isNew?'':'none'; if(isNew) gnew.focus(); });

    gapply.addEventListener('click',()=>{
      if(!effCount()){ toast('Ничего не выбрано',false); return; }
      let group;
      if(gsel.value==='__new__'){ group=(gnew.value||'').trim();
        if(!group){ toast('Введите название группы',false); gnew.focus(); return; } }
      else if(gsel.value===''){ toast('Выберите группу',false); return; }
      else group=gsel.value;
      const fd=new FormData(); fd.append('table',A.table); fd.append('to_group',group); appendScope(fd);
      gapply.disabled=true;
      fetch('api.php?action=set_group_bulk&table='+enc(A.table),{method:'POST',body:fd})
        .then(r=>r.json()).then(res=>{
          if(res.ok){ toast('Перенесено в «'+group+'»: '+(res.updated!=null?res.updated:''));
            clear(); table.ajax.reload(null,false); reloadGroups(table); fillGroups(); }
          else toast(res.error||'Ошибка',false);
        }).catch(e=>toast(String(e),false)).finally(()=>{ gapply.disabled=false; });
    });
  }
}

/* ---------------- filters UI ---------------- */
function setupFilters(table){
  if(!A.groupc) return;
  // restore selects
  const map={f_platform:'fPlatform',f_browser:'fBrowser',f_device:'fDevice',f_activity:'fActivity'};
  Object.entries(map).forEach(([k,id])=>{
    const el=document.getElementById(id); if(el && FILT[k]) el.value=FILT[k];
    el&&el.addEventListener('change',()=>{ FILT[k]=el.value; saveFilt(); table.ajax.reload(); });
  });
  // числовые диапазоны «от»/«до»: возраст (дни) и кол-во доменов
  [['f_age_min','fAgeMin'],['f_age_max','fAgeMax'],['f_dom_min','fDomMin'],['f_dom_max','fDomMax'],['f_grp_min','fGrpMin'],['f_grp_max','fGrpMax']].forEach(([k,id])=>{
    const el=document.getElementById(id); if(!el) return;
    if(FILT[k]) el.value=FILT[k];
    el.addEventListener('change',()=>{
      const v=el.value.trim();
      if(v==='' || isNaN(v)) { delete FILT[k]; el.value=''; } else FILT[k]=String(Math.max(0,parseInt(v,10)));
      if(FILT[k]) el.value=FILT[k];
      saveFilt(); table.ajax.reload();
    });
  });
  // groups
  fetch('api.php?action=groups&table='+enc(A.table)).then(r=>r.json()).then(rows=>{
    const box=document.getElementById('groupChips');
    rows.forEach(r=>{
      const c=document.createElement('span'); c.className='chip'; c.dataset.g=r.g;
      c.innerHTML=esc(r.g==='__none__'?'(без группы)':r.g)+' <b>'+r.n+'</b>'; box.appendChild(c);
    });
    syncChips();
    box.addEventListener('click',e=>{
      const ch=e.target.closest('.chip'); if(!ch) return;
      FILT.group=ch.dataset.g||''; saveFilt(); syncChips(); table.ajax.reload();
    });
  });
  // facets
  fetch('api.php?action=facets&table='+enc(A.table)).then(r=>r.json()).then(f=>{
    fillSel('fPlatform', f.platform, 'Платформа: все');
    fillSel('fBrowser',  f.browser,  'Браузер: все');
  });
  // reset
  document.getElementById('resetF').addEventListener('click',()=>{
    FILT={}; saveFilt();
    ['fPlatform','fBrowser','fDevice','fActivity','fAgeMin','fAgeMax','fDomMin','fDomMax','fGrpMin','fGrpMax'].forEach(id=>{const e=document.getElementById(id);if(e)e.value='';});
    syncChips(); table.ajax.reload();
  });
}
function fillSel(id, items, ph){
  const el=document.getElementById(id); if(!el||!items) return;
  items.forEach(v=>{ const o=document.createElement('option'); o.value=v; o.textContent=v; el.appendChild(o); });
  const k=el.id==='fPlatform'?'f_platform':'f_browser';
  if(FILT[k]) el.value=FILT[k];
}
function syncChips(){
  document.querySelectorAll('#groupChips .chip').forEach(c=>
    c.classList.toggle('active',(c.dataset.g||'')===(FILT.group||'')));
}

/* ---------------- экспорт / импорт ---------------- */
function dlBlob(blob, name){
  const u=URL.createObjectURL(blob); const a=document.createElement('a');
  a.href=u; a.download=name; document.body.appendChild(a); a.click();
  setTimeout(()=>{ URL.revokeObjectURL(u); a.remove(); }, 1000);
}
function setupExportImport(table){
  const expM=document.getElementById('exportModal'), impM=document.getElementById('importModal');
  if(!expM||!impM) return;
  const fmtSel=()=>expM.querySelector('input[name=exp_format]:checked').value;

  // ----- экспорт -----
  document.getElementById('exportBtn').addEventListener('click',()=>{
    const n = EXPORT.selected ? EXPORT.selected.size : 0;
    document.getElementById('expSelCnt').textContent = n ? '('+n+')' : '(нет выбранных)';
    const idsR=expM.querySelector('input[value=ids]'); idsR.disabled=!n;
    if(!n && idsR.checked) expM.querySelector('input[value=all]').checked=true;
    expM.classList.add('open'); refreshIcons();
  });
  const closeExp=()=>expM.classList.remove('open');
  document.getElementById('exportCancel').addEventListener('click',closeExp);
  expM.addEventListener('click',e=>{ if(e.target===expM) closeExp(); });
  document.getElementById('exportGo').addEventListener('click',()=>{
    const fmt=fmtSel(), scope=expM.querySelector('input[name=exp_scope]:checked').value;
    if(scope==='ids'){
      const ids = EXPORT.selected ? [...EXPORT.selected] : [];
      if(!ids.length){ toast('Ничего не выбрано',false); return; }
      const fd=new FormData(); fd.append('format',fmt); fd.append('scope','ids');
      ids.forEach(v=>fd.append('ids[]',v));
      const go=document.getElementById('exportGo'); go.disabled=true;
      fetch('api.php?action=export&table='+enc(A.table),{method:'POST',body:fd})
        .then(r=>r.blob()).then(b=>{ dlBlob(b, A.table+'.'+fmt); closeExp(); })
        .catch(e=>toast(String(e),false)).finally(()=>{ go.disabled=false; });
    } else {
      let url='api.php?action=export&table='+enc(A.table)+'&format='+fmt+'&scope='+scope;
      if(scope==='filter') url+='&'+new URLSearchParams(currentFilters()).toString();
      window.location=url; closeExp();
    }
  });

  // ----- импорт -----
  const impErr=document.getElementById('importErr');
  const impProg=document.getElementById('importProg');
  const impBar=document.getElementById('importBar');
  const impPct=document.getElementById('importProgPct');
  const impLbl=document.getElementById('importProgLbl');
  const impFile=document.getElementById('importFile');
  const impGo=document.getElementById('importGo');

  // сброс всей индикации модалки в исходное
  const impReset=()=>{
    impErr.className='err'; impErr.textContent='';
    impProg.classList.remove('show'); impBar.style.width='0%';
    impGo.disabled=false; impGo.classList.remove('busy');
  };
  // показать прогресс с подписью и процентом (pct=null → неопределённый/обработка)
  const impShow=(label,pct)=>{
    impErr.className='err';
    impProg.classList.add('show'); impLbl.textContent=label;
    if(pct===null){ impProg.classList.add('indet'); impPct.textContent=''; impBar.style.width='100%'; }
    else { impProg.classList.remove('indet'); impPct.textContent=Math.round(pct)+'%'; impBar.style.width=Math.round(pct)+'%'; }
  };

  document.getElementById('importBtn').addEventListener('click',()=>{
    impReset(); impM.classList.add('open'); refreshIcons();
  });
  const closeImp=()=>impM.classList.remove('open');
  document.getElementById('importCancel').addEventListener('click',closeImp);
  impM.addEventListener('click',e=>{ if(e.target===impM) closeImp(); });
  // выбрал новый файл — старая ошибка/прогресс больше не актуальны
  impFile.addEventListener('change',impReset);

  impGo.addEventListener('click',()=>{
    const f=impFile.files[0];
    if(!f){ impErr.textContent='Выберите CSV-файл'; impErr.className='err show'; return; }
    const mode=impM.querySelector('input[name=imp_mode]:checked').value;
    const fd=new FormData(); fd.append('file',f); fd.append('mode',mode);

    impGo.disabled=true; impGo.classList.add('busy');
    impShow('Загрузка файла…',0);

    const xhr=new XMLHttpRequest();
    xhr.open('POST','api.php?action=import&table='+enc(A.table));
    // прогресс самой выгрузки файла на сервер
    xhr.upload.onprogress=e=>{
      if(e.lengthComputable){
        const p=e.loaded/e.total*100;
        impShow('Загрузка файла… '+fmtBytes(e.loaded)+' / '+fmtBytes(e.total),p);
        // долил весь файл — дальше сервер парсит и пишет в БД
        if(p>=100) impShow('Обработка на сервере…',null);
      }
    };
    xhr.upload.onload=()=>impShow('Обработка на сервере…',null);
    xhr.onload=()=>{
      impGo.disabled=false; impGo.classList.remove('busy');
      let res; try{ res=JSON.parse(xhr.responseText); }catch(_){ res=null; }
      if(xhr.status===200 && res && res.ok){
        impProg.classList.remove('show');
        toast('Импорт: добавлено '+res.imported+', пропущено '+res.skipped+(res.bad?', без PID '+res.bad:''));
        closeImp(); table.ajax.reload(null,false); reloadGroups(table);
      } else {
        impProg.classList.remove('show');
        const msg=(res&&res.error) ? res.error : ('Ошибка сервера ('+xhr.status+')');
        impErr.textContent=msg; impErr.className='err show';
      }
    };
    xhr.onerror=()=>{
      impGo.disabled=false; impGo.classList.remove('busy');
      impProg.classList.remove('show');
      impErr.textContent='Сбой сети при загрузке'; impErr.className='err show';
    };
    xhr.send(fd);
  });
}

// Человекочитаемый размер
function fmtBytes(n){
  if(n<1024) return n+' Б';
  if(n<1048576) return (n/1024).toFixed(0)+' КБ';
  if(n<1073741824) return (n/1048576).toFixed(1)+' МБ';
  return (n/1073741824).toFixed(2)+' ГБ';
}

/* ---------------- CRUD ---------------- */
function setupCrud(table){
  const modal=document.getElementById('modal');
  const close=()=>modal.classList.remove('open');
  const open=()=>{ modal.classList.add('open'); const e=document.getElementById('formErr'); e.className='err'; refreshIcons(); };

  function fieldHtml(c,val){
    if(!c.form) return ''; // в форме показываем только редактируемые поля
    const v=val==null?'':val;
    const req = c.required ? ' required' : '';
    const star = c.required ? '<span class="req">*</span>' : '';
    const tag=(c.is_num?'<span class="tag">число</span>':'')+
             (c.maxlen?'<span class="tag">≤'+c.maxlen+'</span>':'');
    const ml = c.maxlen ? ' maxlength="'+c.maxlen+'"' : '';
    let input;
    if(c.is_bool){
      input='<label class="switch"><input type="checkbox" name="'+c.name+'"'+req+' '+
            (v==1||v===true||v==='t'?'checked':'')+'><span class="tr"></span></label>';
    }else if(FORM_SELECT[c.name]){
      input='<select class="finput" name="'+c.name+'"'+req+'>'+optsHtml(FORM_SELECT[c.name],v)+'</select>';
    }else if(c.name==='party'){
      input='<select class="finput party-sel" name="party">'+groupOptionsFromChips(v)+'</select>'+
            '<input type="text" class="finput party-new" name="party_new" placeholder="название новой группы" style="display:none;margin-top:8px"'+ml+'>';
    }else if(c.is_long){
      input='<textarea name="'+c.name+'"'+req+ml+'>'+esc(v)+'</textarea>';
    }else if(c.is_num){
      input='<input type="number" step="any" name="'+c.name+'" value="'+esc(v)+'"'+req+'>';
    }else{
      input='<input type="text" name="'+c.name+'" value="'+esc(v)+'"'+req+ml+'>';
    }
    const full = c.is_long || c.name==='party'; // party на всю ширину, чтобы пары шли ровно
    return '<div class="field'+(full?' full':'')+'" data-col="'+c.name+'">'+
           '<label>'+esc(c.title)+star+tag+'</label>'+input+'</div>';
  }
  function build(values,isEdit){
    const grid=document.getElementById('formGrid');
    grid.innerHTML=A.cols.map(c=>fieldHtml(c, values?values[c.name]:null)).join('');
    A.cols.forEach(c=>{
      const w=grid.querySelector('[data-col="'+c.name+'"]'); if(!w) return;
      const el=w.querySelector('input,textarea');
      if((c.is_auto||c.is_pk)&&!isEdit) w.style.display='none'; // PK генерится сервером
      if(c.is_pk&&isEdit&&el) el.setAttribute('disabled','disabled');
    });
    // поле группы: пункт «Создать группу…» показывает ввод названия
    const psel=grid.querySelector('.party-sel');
    if(psel){ const pnew=grid.querySelector('.party-new');
      psel.addEventListener('change',()=>{ const isNew=psel.value==='__new__';
        pnew.style.display=isNew?'':'none'; if(isNew) pnew.focus(); });
    }
    document.getElementById('f_edit').value=isEdit?'1':'0';
    document.getElementById('modalTitle').innerHTML=
      isEdit ? svg('pencil')+' Редактировать '+esc(A.entity)+
               ' <span class="pid-badge">#'+esc(values?values[A.pk[0]]:'')+'</span>'
             : svg('plus')+' Новый '+esc(A.entity);
    modal._pk={}; if(isEdit&&values) A.pk.forEach(k=>modal._pk[k]=values[k]);
    refreshIcons();
  }

  const addBtn=document.getElementById('addBtn');
  addBtn&&addBtn.addEventListener('click',()=>{ build(null,false); open(); });
  document.getElementById('cancelBtn').addEventListener('click',close);

  $('#grid tbody').on('click','button[data-act]',function(){
    const row=table.row($(this).closest('tr')).data(); if(!row) return;
    const act=this.dataset.act;
    if(act==='edit'){
      const q=new URLSearchParams({action:'row',table:A.table});
      A.pk.forEach(k=>q.append('pk['+k+']',row[k]));
      fetch('api.php?'+q).then(r=>r.json()).then(full=>{
        if(full.error){toast(full.error,false);return;} build(full,true); open();
      });
    }else if(act==='del'){
      if(!confirm('Удалить запись?')) return;
      const fd=new FormData(); fd.append('table',A.table);
      A.pk.forEach(k=>fd.append('pk['+k+']',row[k]));
      fetch('api.php?action=delete&table='+enc(A.table),{method:'POST',body:fd})
        .then(r=>r.json()).then(res=>{
          if(res.ok){ toast('Удалено'); table.ajax.reload(null,false); reloadGroups(table); }
          else toast(res.error||'Ошибка',false);
        });
    }
  });

  document.getElementById('form').addEventListener('submit',e=>{
    e.preventDefault();
    const form=e.target;
    const errEl=document.getElementById('formErr'); errEl.className='err';
    // подсветка и проверка перед отправкой
    form.querySelectorAll('.field.bad').forEach(f=>f.classList.remove('bad'));
    const bad=[];
    A.cols.forEach(c=>{
      const el=form.querySelector('[name="'+c.name+'"]'); if(!el||el.disabled||c.is_bool) return;
      const val=(el.value||'').trim();
      if(c.required && val===''){ bad.push(c.title); el.closest('.field').classList.add('bad'); return; }
      if(c.is_num && val!=='' && isNaN(Number(val))){ bad.push(c.title+' (число)'); el.closest('.field').classList.add('bad'); }
    });
    if(bad.length){ errEl.textContent='Проверьте поля: '+bad.join(', '); errEl.className='err show'; return; }

    const fd=new FormData(form); fd.append('table',A.table);
    // поле группы: если выбрано «Создать группу…», берём введённое название
    if(fd.get('party')==='__new__') fd.set('party',(fd.get('party_new')||'').trim());
    fd.delete('party_new');
    const isEdit=document.getElementById('f_edit').value==='1';
    if(isEdit) A.pk.forEach(k=>fd.append('pk['+k+']',modal._pk[k]));
    A.cols.forEach(c=>{ if(c.is_bool&&!fd.has(c.name)) fd.append(c.name,'0'); });
    fetch('api.php?action=save&table='+enc(A.table),{method:'POST',body:fd})
      .then(r=>r.json()).then(res=>{
        if(res.ok){ close(); toast(isEdit?'Сохранено':'Создано'); table.ajax.reload(null,false); reloadGroups(table); }
        else { errEl.textContent='Ошибка: '+(res.error||'?'); errEl.className='err show'; }
      }).catch(err=>{ errEl.textContent=String(err); errEl.className='err show'; });
  });

  modal.addEventListener('click',e=>{ if(e.target===modal) close(); });
  document.addEventListener('keydown',e=>{ if(e.key==='Escape') close(); });
}
function reloadGroups(table){
  if(!A.groupc) return;
  fetch('api.php?action=groups&table='+enc(A.table)).then(r=>r.json()).then(rows=>{
    const box=document.getElementById('groupChips'); if(!box) return;
    box.querySelectorAll('.chip[data-g]:not([data-g=""])').forEach(e=>e.remove());
    rows.forEach(r=>{ const c=document.createElement('span'); c.className='chip'; c.dataset.g=r.g;
      c.innerHTML=esc(r.g==='__none__'?'(без группы)':r.g)+' <b>'+r.n+'</b>'; box.appendChild(c); });
    syncChips();
  });
}

/* ===================================================================
   DASHBOARD
   =================================================================== */
function killChart(el){ const ex=Chart.getChart(el); if(ex) ex.destroy(); }
function initDashboard(){
  Chart.defaults.color='#cbd5e1';   // светлее — текст легенд/осей читается
  Chart.defaults.font.family='Inter, system-ui, sans-serif';
  Chart.defaults.borderColor='rgba(255,255,255,.05)';

  const btn=document.getElementById('refreshBtn');
  if(btn) btn.addEventListener('click', ()=>loadStats(true));
  loadStats(false);
}
function loadStats(isRefresh){
  const btn=document.getElementById('refreshBtn');
  if(btn) btn.classList.add('spin');
  cardSkeletons(); chartSkeletons();
  fetch('api.php?action=stats&table='+enc(A.table)).then(r=>r.json()).then(s=>{
    renderCards(s); renderCharts(s);
    if(isRefresh) toast('Данные обновлены',true);
  }).catch(e=>toast('Ошибка загрузки статистики',false))
    .finally(()=>{ if(btn) btn.classList.remove('spin'); });
}
function cardSkeletons(){
  var box=document.getElementById('cards'); if(!box) return;
  box.innerHTML = Array.from({length:6}).map(()=>
    '<div class="card skel-card"><div class="skel s1"></div><div class="skel s2"></div><div class="skel s3"></div></div>').join('');
}
// спиннер-оверлей поверх каждого графика (renderCharts удалит его после отрисовки)
function chartSkeletons(){
  document.querySelectorAll('.chart .canvas-wrap').forEach(w=>{
    if(w.querySelector('.ph')) return;
    const d=document.createElement('div'); d.className='ph';
    d.innerHTML='<div class="spinner"></div>';
    w.appendChild(d);
  });
}
function card(k,icon,v,sub,cls){
  return '<div class="card '+(cls||'')+'"><div class="k">'+svg(icon)+esc(k)+'</div>'+
    '<div class="v">'+v+'</div>'+(sub?'<div class="sub">'+sub+'</div>':'')+
    '<div class="badge-ic">'+svg(icon)+'</div></div>';
}
function renderCards(s){
  const n=x=>(x==null?0:x).toLocaleString('ru');
  const top = s.groups&&s.groups[0] ? ('топ: '+esc(s.groups[0].label)) : '';
  const warmSub = '≥'+(s.warm_days||10)+' дн · ≥'+(s.warm_domains||10)+' доменов';
  const html=[
    card('Всего профилей','users', n(s.total), 'в базе', 'accent'),
    card('Создано сегодня','calendar-plus', n(s.created_today), 'за сутки', 'blue'),
    card('Прогретые профили','flame', n(s.warmed), warmSub, 'fire'),
    card('Группы','layers', n(s.groups?s.groups.length:0), top, 'purple'),
    card('Среднее кол-во доменов','globe', (s.avg_domaincount!=null?s.avg_domaincount:'—'), 'на профиль', 'warn'),
  ].join('');
  document.getElementById('cards').innerHTML=html; refreshIcons();
}
function baseOpts(extra){
  return Object.assign({responsive:true,maintainAspectRatio:false,
    plugins:{legend:{labels:{boxWidth:12,boxHeight:12,padding:14,usePointStyle:true}}}}, extra||{});
}
// тултип с процентом: "Chrome: 2 425 (82%)"
const pctTip = {callbacks:{label:function(ctx){
  const ds=ctx.chart.data.datasets[0].data, sum=ds.reduce((a,b)=>a+(+b||0),0)||1;
  // ctx.raw — исходное число для любого типа (у polarArea значение в .r, не в .y/.x)
  let v=ctx.raw;
  if(v==null||isNaN(+v)) v=(typeof ctx.parsed==='number')?ctx.parsed
    :(ctx.parsed.r!=null?ctx.parsed.r:(ctx.parsed.y!=null?ctx.parsed.y:ctx.parsed.x));
  v=+v||0;
  return ' '+v.toLocaleString('ru')+' ('+Math.round(v/sum*100)+'%)';
}}};
// легенда с процентом (для пончиков)
function legendPct(chart){
  const d=chart.data, ds=d.datasets[0].data, sum=ds.reduce((a,b)=>a+(+b||0),0)||1, bg=d.datasets[0].backgroundColor;
  return d.labels.map(function(l,i){
    return {text:l+' ('+Math.round(ds[i]/sum*100)+'%)', fillStyle:bg[i], strokeStyle:bg[i], fontColor:'#e8ecf3', pointStyle:'circle', index:i};
  });
}
function doughnut(id,rows,colors){
  const el=document.getElementById(id); if(!el||!rows) return;
  killChart(el);
  new Chart(el,{
    type:'doughnut',
    data:{labels:rows.map(r=>r.label),datasets:[{data:rows.map(r=>+r.n),backgroundColor:colors||PAL,borderColor:'#141822',borderWidth:2,hoverOffset:6}]},
    options:baseOpts({cutout:'62%',plugins:{tooltip:pctTip,legend:{position:'right',labels:{usePointStyle:true,boxWidth:12,padding:10,color:'#e8ecf3',generateLabels:legendPct}}}})
  });
}
function polarArea(id,rows,colors){
  const el=document.getElementById(id); if(!el||!rows) return;
  killChart(el);
  const cols=colors||PAL;
  new Chart(el,{
    type:'polarArea',
    data:{labels:rows.map(r=>r.label),datasets:[{data:rows.map(r=>+r.n),backgroundColor:cols.map(c=>c+'bb'),borderColor:cols,borderWidth:1.5}]},
    options:baseOpts({plugins:{tooltip:pctTip,legend:{position:'right',labels:{usePointStyle:true,boxWidth:12,padding:10,color:'#e8ecf3',generateLabels:legendPct}}},
      scales:{r:{ticks:{color:'#8b94a7',backdropColor:'transparent',z:1},grid:{color:'rgba(255,255,255,.07)'},angleLines:{color:'rgba(255,255,255,.07)'}}}})
  });
}
function barChart(id,rows,horizontal,colors){
  const el=document.getElementById(id); if(!el||!rows) return;
  killChart(el);
  new Chart(el,{
    type:'bar',
    data:{labels:rows.map(r=>r.label),datasets:[{data:rows.map(r=>+r.n),backgroundColor:colors||rows.map((_,i)=>PAL[i%PAL.length]),borderRadius:8,maxBarThickness:46}]},
    options:baseOpts({indexAxis:horizontal?'y':'x',plugins:{legend:{display:false},tooltip:pctTip},scales:{x:{grid:{display:!horizontal}},y:{grid:{display:horizontal}}}})
  });
}
function lineChart(id,rows){
  const el=document.getElementById(id); if(!el||!rows) return;
  killChart(el);
  const ctx=el.getContext('2d');
  const g=ctx.createLinearGradient(0,0,0,240);
  g.addColorStop(0,'rgba(59,130,246,.35)'); g.addColorStop(1,'rgba(59,130,246,0)');
  new Chart(el,{type:'line',
    data:{labels:rows.map(r=>r.label),datasets:[{data:rows.map(r=>+r.n),
      borderColor:'#3b82f6',backgroundColor:g,fill:true,tension:.35,pointRadius:3,
      pointBackgroundColor:'#06b6d4',borderWidth:2}]},
    options:baseOpts({plugins:{legend:{display:false}},scales:{y:{beginAtZero:true}}})});
}
const ACT_COLOR = {'Без активности':'#3b82f6','Низкая':'#22c55e','Средняя':'#fb923c','Высокая':'#ef4444'};
function renderCharts(s){
  const dev = s.device ? [{label:'Мобильные',n:s.device.mobile},{label:'Десктоп',n:s.device.desktop}] : null;
  doughnut('chDevices', dev, ['#22d3a6','#3b82f6']);
  doughnut('chPlatforms', s.platforms);
  doughnut('chBrowsers', s.browsers);
  barChart('chGroups', s.groups, true);
  barChart('chAge', s.age, false);
  polarArea('chActivity', s.activity, s.activity ? s.activity.map(r=>ACT_COLOR[r.label]||'#8b94a7') : null);
  lineChart('chCreated', s.created);
  document.querySelectorAll('.canvas-wrap .ph').forEach(e=>e.remove()); // убрать спиннеры графиков
}

/* ---------------- boot ---------------- */
document.addEventListener('DOMContentLoaded', ()=>{
  refreshIcons(); setupMenu();
  if(A.dbErr && A.page!=='settings') return;   // БД недоступна — показана карточка ошибки, инициализировать нечего
  if(A.page==='dashboard') initDashboard();
  else if(A.page==='settings') initSettings();
  else if(A.page==='rules') initRules();
  else initTable();
});

/* ---------------- настройки ---------------- */
function initSettings(){
  const f=document.getElementById('settingsForm'); if(!f) return;
  f.addEventListener('submit',e=>{
    e.preventDefault();
    const err=document.getElementById('settingsErr'); err.className='err';
    const btn=f.querySelector('button[type=submit]'); btn.disabled=true;
    fetch('api.php?action=save_settings',{method:'POST',body:new FormData(f)})
      .then(r=>r.json()).then(res=>{
        if(res.ok){ toast('Настройки сохранены'); f.querySelectorAll('input[type=password]').forEach(i=>i.value=''); }
        else { err.textContent=res.error||'Ошибка'; err.className='err show'; }
      }).catch(e=>{ err.textContent=String(e); err.className='err show'; })
      .finally(()=>{ btn.disabled=false; });
  });

  // включение/отключение отслеживания времени в группе (миграция party_since)
  const gt=document.getElementById('gtrackToggle');
  if(gt){
    gt.addEventListener('click',()=>{
      const wrap=document.getElementById('gtrack');
      const on=wrap.dataset.enabled==='1';
      const err=document.getElementById('gtrackErr'); err.className='err';
      if(on && !confirm('Отключить отслеживание? Будут удалены триггер, функция и колонка party_since (история времени в группе сбросится).')) return;
      gt.disabled=true; gt.classList.add('busy');
      const act=on?'gtrack_disable':'gtrack_enable';
      fetch('api.php?action='+act+'&table='+enc(A.table),{method:'POST',body:new FormData()})
        .then(r=>r.json()).then(res=>{
          if(res.ok){ toast(on?'Отслеживание отключено':'Отслеживание включено'); setTimeout(()=>location.reload(),600); }
          else { err.textContent=res.error||'Ошибка'; err.className='err show'; gt.disabled=false; gt.classList.remove('busy'); }
        }).catch(e=>{ err.textContent=String(e); err.className='err show'; gt.disabled=false; gt.classList.remove('busy'); });
    });
  }
}

/* ---------------- планировщик ---------------- */
function initRules(){
  const listEl=document.getElementById('rulesList');
  const modal=document.getElementById('ruleModal');
  if(!listEl||!modal) return;
  let groups=[], rulesCache=[];

  const F={ id:'ruleId',name:'ruleName',from:'ruleFrom',to:'ruleTo',toNew:'ruleToNew',
            ageMin:'ruleAgeMin',ageMax:'ruleAgeMax',domMin:'ruleDomMin',domMax:'ruleDomMax',
            grpMin:'ruleGrpMin',grpMax:'ruleGrpMax',
            ageOn:'ruleAgeOn',domOn:'ruleDomOn',grpOn:'ruleGrpOn',
            enabled:'ruleEnabled',err:'ruleErr',preview:'rulePreview',title:'ruleModalTitle' };
  const el=k=>document.getElementById(F[k]);
  const matchBox=document.getElementById('ruleMatch');
  const getMatch=()=>{ const a=matchBox&&matchBox.querySelector('.rm-opt.active'); return a?a.dataset.v:'all'; };
  const setMatch=v=>{ if(!matchBox) return; matchBox.querySelectorAll('.rm-opt').forEach(b=>b.classList.toggle('active', b.dataset.v===(v==='any'?'any':'all'))); };
  // вкл/выкл строки условия → серость инпутов
  const syncCondRow=(onKey,cond)=>{
    const cb=el(onKey); if(!cb) return;
    const row=matchBox && document.querySelector('.rule-cond-row[data-cond="'+cond+'"]');
    if(row) row.classList.toggle('off', !cb.checked);
  };

  const loadGroups=()=>fetch('api.php?action=groups&table='+enc(A.table)).then(r=>r.json()).then(rows=>{ groups=rows||[]; });
  const load=()=>fetch('api.php?action=rules_list&table='+enc(A.table)).then(r=>r.json()).then(res=>{
    if(!res.ok) return;
    rulesCache=res.rules||[];
    const cu=document.getElementById('cronUrl'); if(cu) cu.textContent=res.cron_url||'';
    renderList();
    renderLog(res.log||[]);
  });

  function renderLog(log){
    const wrap=document.getElementById('rulesLogWrap'), box=document.getElementById('rulesLog');
    if(!wrap||!box) return;
    if(!log.length){ wrap.style.display='none'; return; }
    wrap.style.display='';
    box.innerHTML=log.map(e=>{
      const t=fmtWhen(e.time)||'';
      const src=e.source==='cron'?'<span class="rl-src cron">cron</span>':'<span class="rl-src">вручную</span>';
      let name, body;
      if(e.kind==='run'){
        name='Прогон';
        body = 'правил '+(e.rules||0)+' · перенесено <b>'+(e.moved||0)+'</b>'+
               (e.errors?' · <span class="rl-err">ошибок '+e.errors+'</span>':'');
      } else {
        name=esc(e.name||'(без имени)');
        body = e.ok
          ? ('перенесено <b>'+(e.moved!=null?e.moved:0)+'</b>')
          : ('<span class="rl-err">ошибка: '+esc(e.error||'')+'</span>');
      }
      return '<div class="rl-row'+(e.ok?'':' bad')+'">'+
        '<span class="rl-time">'+esc(t)+'</span>'+src+
        '<span class="rl-name">'+name+'</span>'+
        '<span class="rl-body">'+body+'</span>'+
      '</div>';
    }).join('');
    refreshIcons();
  }

  const grpName=v=> v===''?'любая':(v==='__none__'?'без группы':v);
  function condText(r){
    const c=[];
    const on=k=>(r[k]===undefined||r[k]); // флаг отсутствует → включено
    if(on('age_on')&&(r.age_min!=null||r.age_max!=null)) c.push('возраст'+(r.age_min!=null?' от '+r.age_min:'')+(r.age_max!=null?' до '+r.age_max:'')+' дн');
    if(on('dom_on')&&(r.dom_min!=null||r.dom_max!=null)) c.push('доменов'+(r.dom_min!=null?' от '+r.dom_min:'')+(r.dom_max!=null?' до '+r.dom_max:''));
    if(on('grp_on')&&(r.grp_min!=null||r.grp_max!=null)) c.push('в группе'+(r.grp_min!=null?' от '+r.grp_min:'')+(r.grp_max!=null?' до '+r.grp_max:'')+' дн');
    if(!c.length) return 'без условий (вся группа)';
    return c.join(r.match==='any'?'  ИЛИ  ':'  И  ');
  }
  function fmtWhen(iso){ if(!iso) return null; try{ return new Date(iso).toLocaleString('ru-RU'); }catch(e){ return iso; } }

  function renderList(){
    if(!rulesCache.length){ listEl.innerHTML='<div class="rules-empty">Правил пока нет. Создайте первое — кнопка «Новое правило».</div>'; return; }
    listEl.innerHTML=rulesCache.map(r=>{
      const w=fmtWhen(r.last_run);
      const last = w ? ('Запуск: '+w+(r.last_moved!=null?' · перенесено '+r.last_moved:'')) : 'Ещё не запускалось';
      const errB = r.last_error ? '<span class="rule-errbadge" title="'+esc(r.last_error)+'">ошибка</span>' : '';
      return '<div class="rule-card'+(r.enabled?'':' off')+'" data-id="'+esc(r.id)+'">'+
        '<div class="rule-main">'+
          '<div class="rule-top"><b>'+esc(r.name||'(без названия)')+'</b>'+errB+'</div>'+
          '<div class="rule-flow"><span class="rg">'+esc(grpName(r.from_group))+'</span>'+svg('arrow-right')+'<span class="rg to">'+esc(r.to_group)+'</span></div>'+
          '<div class="rule-cond-txt">'+esc(condText(r))+'</div>'+
          '<div class="rule-last">'+esc(last)+'</div>'+
        '</div>'+
        '<div class="rule-actions">'+
          '<label class="switch sm" title="Вкл/выкл"><input type="checkbox" class="r-en"'+(r.enabled?' checked':'')+'><span class="tr"></span></label>'+
          '<button class="btn sm" data-act="run" title="Запустить сейчас">'+svg('play')+'</button>'+
          '<button class="btn sm" data-act="edit" title="Изменить">'+svg('pencil')+'</button>'+
          '<button class="btn sm danger" data-act="del" title="Удалить">'+svg('trash-2')+'</button>'+
        '</div>'+
      '</div>';
    }).join('');
    refreshIcons();
  }

  listEl.addEventListener('click',e=>{
    const card=e.target.closest('.rule-card'); if(!card) return;
    const r=rulesCache.find(x=>x.id===card.dataset.id); if(!r) return;
    const btn=e.target.closest('[data-act]'); if(!btn) return;
    const act=btn.dataset.act;
    if(act==='edit') openModal(r);
    else if(act==='del'){ if(confirm('Удалить правило «'+(r.name||'')+'»?')) del(r.id); }
    else if(act==='run') runOne(r.id,btn);
  });
  listEl.addEventListener('change',e=>{
    const cb=e.target.closest('.r-en'); if(!cb) return;
    const card=e.target.closest('.rule-card');
    const r=rulesCache.find(x=>x.id===card.dataset.id); if(!r) return;
    r.enabled=cb.checked; save(r,true);
  });

  function fillSelects(fromVal,toVal){
    const opt=(v,t,sel)=>'<option value="'+esc(v)+'"'+(String(v)===String(sel)?' selected':'')+'>'+esc(t)+'</option>';
    let gs=groups.filter(g=>g.g && g.g!=='__none__').map(g=>({g:g.g,n:g.n}));
    if(toVal && toVal!=='__new__' && !gs.some(g=>g.g===toVal)) gs=gs.concat([{g:toVal,n:0}]);
    let fromOpts=opt('','любая группа',fromVal)+opt('__none__','без группы',fromVal);
    if(fromVal && fromVal!=='__none__' && !gs.some(g=>g.g===fromVal)) fromOpts+=opt(fromVal,fromVal,fromVal);
    el('from').innerHTML=fromOpts+gs.map(g=>opt(g.g,g.g+(g.n?' ('+g.n+')':''),fromVal)).join('');
    el('to').innerHTML=gs.map(g=>opt(g.g,g.g,toVal)).join('')+opt('__new__','➕ Создать новую…',toVal);
  }
  function openModal(r){
    el('err').className='err'; el('preview').textContent='';
    el('id').value=r?r.id:'';
    el('name').value=r?(r.name||''):'';
    document.getElementById(F.title).textContent=r?'Изменить правило':'Новое правило';
    fillSelects(r?r.from_group:'', r?r.to_group:'');
    el('toNew').style.display='none'; el('toNew').value='';
    el('ageMin').value=r&&r.age_min!=null?r.age_min:'';
    el('ageMax').value=r&&r.age_max!=null?r.age_max:'';
    el('domMin').value=r&&r.dom_min!=null?r.dom_min:'';
    el('domMax').value=r&&r.dom_max!=null?r.dom_max:'';
    if(el('grpMin')) el('grpMin').value=r&&r.grp_min!=null?r.grp_min:'';
    if(el('grpMax')) el('grpMax').value=r&&r.grp_max!=null?r.grp_max:'';
    el('enabled').checked=r?!!r.enabled:true;
    // И/ИЛИ + флаги вкл условий (у старых правил флаг отсутствует → включено)
    setMatch(r&&r.match==='any'?'any':'all');
    if(el('ageOn')){ el('ageOn').checked = r ? (r.age_on!==false) : true; syncCondRow('ageOn','age'); }
    if(el('domOn')){ el('domOn').checked = r ? (r.dom_on!==false) : true; syncCondRow('domOn','dom'); }
    if(el('grpOn')){ el('grpOn').checked = r ? (r.grp_on!==false) : true; syncCondRow('grpOn','grp'); }
    modal.classList.add('open'); refreshIcons(); preview();
  }
  const closeModal=()=>modal.classList.remove('open');
  document.getElementById('ruleNew').addEventListener('click',()=>openModal(null));
  document.getElementById('ruleCancel').addEventListener('click',closeModal);
  modal.addEventListener('click',e=>{ if(e.target===modal) closeModal(); });
  el('to').addEventListener('change',()=>{ const isNew=el('to').value==='__new__';
    el('toNew').style.display=isNew?'':'none'; if(isNew) el('toNew').focus(); preview(); });

  function ruleFromForm(){
    let to=el('to').value; if(to==='__new__') to=el('toNew').value.trim();
    return { id:el('id').value, name:el('name').value, enabled:el('enabled').checked?1:'',
             from_group:el('from').value, to_group:to,
             age_min:el('ageMin').value, age_max:el('ageMax').value,
             dom_min:el('domMin').value, dom_max:el('domMax').value,
             grp_min:el('grpMin')?el('grpMin').value:'', grp_max:el('grpMax')?el('grpMax').value:'',
             match:getMatch(),
             age_on:el('ageOn')&&el('ageOn').checked?1:'', dom_on:el('domOn')&&el('domOn').checked?1:'',
             grp_on:el('grpOn')&&el('grpOn').checked?1:'' };
  }
  let pvTimer=null;
  function preview(){
    clearTimeout(pvTimer);
    pvTimer=setTimeout(()=>{
      const d=ruleFromForm();
      if(!d.to_group){ el('preview').textContent=''; return; }
      const p=new URLSearchParams(d);
      fetch('api.php?action=rules_preview&table='+enc(A.table)+'&'+p.toString()).then(r=>r.json()).then(res=>{
        if(res.ok && res.count!=null) el('preview').innerHTML='Под условие сейчас подпадает <b>'+res.count+'</b>';
        else el('preview').textContent='';
      }).catch(()=>{});
    },300);
  }
  ['name','ageMin','ageMax','domMin','domMax','grpMin','grpMax','from','toNew'].forEach(k=>{
    const e=el(k); if(e){ e.addEventListener('input',preview); e.addEventListener('change',preview); }
  });
  // переключатель И/ИЛИ
  matchBox&&matchBox.querySelectorAll('.rm-opt').forEach(b=>b.addEventListener('click',()=>{ setMatch(b.dataset.v); preview(); }));
  // чекбоксы вкл/выкл условий
  [['ageOn','age'],['domOn','dom'],['grpOn','grp']].forEach(([k,c])=>{
    const e=el(k); if(e) e.addEventListener('change',()=>{ syncCondRow(k,c); preview(); });
  });

  document.getElementById('ruleSave').addEventListener('click',()=>{
    const d=ruleFromForm();
    if(!d.to_group){ el('err').textContent='Выберите или создайте целевую группу'; el('err').className='err show'; return; }
    const btn=document.getElementById('ruleSave'); btn.disabled=true;
    const fd=new FormData(); Object.entries(d).forEach(([k,v])=>fd.append(k,v));
    fetch('api.php?action=rules_save&table='+enc(A.table),{method:'POST',body:fd}).then(r=>r.json()).then(res=>{
      if(res.ok){ toast('Правило сохранено'); closeModal(); loadGroups().then(load); }
      else { el('err').textContent=res.error||'Ошибка'; el('err').className='err show'; }
    }).catch(e=>{ el('err').textContent=String(e); el('err').className='err show'; }).finally(()=>btn.disabled=false);
  });

  function save(rule,silent){
    const d={ id:rule.id,name:rule.name,enabled:rule.enabled?1:'',from_group:rule.from_group,to_group:rule.to_group,
              age_min:rule.age_min??'',age_max:rule.age_max??'',dom_min:rule.dom_min??'',dom_max:rule.dom_max??'',
              grp_min:rule.grp_min??'',grp_max:rule.grp_max??'',
              match:rule.match||'all',
              age_on:(rule.age_on===undefined||rule.age_on)?1:'',dom_on:(rule.dom_on===undefined||rule.dom_on)?1:'',grp_on:(rule.grp_on===undefined||rule.grp_on)?1:'' };
    const fd=new FormData(); Object.entries(d).forEach(([k,v])=>fd.append(k,v));
    fetch('api.php?action=rules_save&table='+enc(A.table),{method:'POST',body:fd}).then(r=>r.json()).then(res=>{
      if(!silent){ if(res.ok) toast('Сохранено'); else toast(res.error||'Ошибка',false); }
      load();
    });
  }
  function del(id){
    const fd=new FormData(); fd.append('id',id);
    fetch('api.php?action=rules_delete&table='+enc(A.table),{method:'POST',body:fd}).then(r=>r.json()).then(res=>{
      if(res.ok){ toast('Правило удалено'); load(); } else toast(res.error||'Ошибка',false);
    });
  }
  function runOne(id,btn){
    if(btn){ btn.classList.add('busy'); btn.disabled=true; }
    const fd=new FormData(); fd.append('id',id);
    fetch('api.php?action=rules_run&table='+enc(A.table),{method:'POST',body:fd}).then(r=>r.json()).then(res=>{
      if(res.ok) toast('Перенесено: '+res.moved); else toast(res.error||'Ошибка',false);
      load();
    }).catch(e=>toast(String(e),false)).finally(()=>{ if(btn){ btn.classList.remove('busy'); btn.disabled=false; } });
  }
  document.getElementById('rulesRunAll').addEventListener('click',()=>{
    const b=document.getElementById('rulesRunAll'); b.classList.add('busy'); b.disabled=true;
    const fd=new FormData(); fd.append('all','1');
    fetch('api.php?action=rules_run&table='+enc(A.table),{method:'POST',body:fd}).then(r=>r.json()).then(res=>{
      if(res.ok) toast('Готово. Перенесено всего: '+res.moved_total); else toast(res.error||'Ошибка',false);
      load();
    }).catch(e=>toast(String(e),false)).finally(()=>{ b.classList.remove('busy'); b.disabled=false; });
  });
  const logClear=document.getElementById('rulesLogClear');
  logClear&&logClear.addEventListener('click',()=>{
    if(!confirm('Очистить журнал запусков?')) return;
    fetch('api.php?action=rules_log_clear&table='+enc(A.table),{method:'POST',body:new FormData()})
      .then(r=>r.json()).then(()=>load());
  });

  document.querySelectorAll('.rc-copy').forEach(b=>b.addEventListener('click',()=>{
    const t=document.getElementById(b.dataset.copy); if(!t) return;
    if(navigator.clipboard) navigator.clipboard.writeText(t.textContent).then(()=>toast('Скопировано')).catch(()=>{});
  }));

  loadGroups().then(load);
}
