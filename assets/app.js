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
  ['f_platform','f_browser','f_device','f_age','f_activity'].forEach(k=>{ if(FILT[k]) f[k]=FILT[k]; });
  return f;
}

/* ===================================================================
   TABLE VIEW
   =================================================================== */
function initTable(){
  // одноразовый сброс старого сохранённого состояния колонок, чтобы применились новые дефолты
  try{ if(!localStorage.getItem('monstro_state_v5')){
    Object.keys(localStorage).filter(k=>k.indexOf('DataTables_grid')>=0).forEach(k=>localStorage.removeItem(k));
    localStorage.setItem('monstro_state_v5','1');
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
}

/* ---------------- массовые действия ---------------- */
function setupBulk(table){
  if(!A.pk || A.pk.length!==1) return;       // только при одиночном PK
  const selected = new Set();
  const bar  = document.getElementById('bulkbar');
  const cnt  = document.getElementById('bulkCount');
  if(!bar) return;

  const refreshBar=()=>{ if(cnt) cnt.textContent=selected.size; bar.classList.toggle('show', selected.size>0); };
  // привести чекбоксы текущей страницы в соответствие набору + состояние «выбрать всё»
  // (при scrollX главный чекбокс есть и в видимой, и в служебной копии шапки — обновляем все)
  const syncChecks=()=>{
    const boxes=document.querySelectorAll('#grid tbody .selrow');
    boxes.forEach(b=>{ b.checked=selected.has(b.value); });
    const total=boxes.length, on=[...boxes].filter(b=>b.checked).length;
    document.querySelectorAll('.selall').forEach(all=>{
      all.checked = total>0 && on===total; all.indeterminate = on>0 && on<total;
    });
  };
  table.on('draw', syncChecks);

  // чекбокс строки
  $('#grid tbody').on('change','.selrow',function(){
    if(this.checked) selected.add(this.value); else selected.delete(this.value);
    refreshBar(); syncChecks();
  });
  // главный чекбокс — делегируем на document, т.к. при scrollX он в копии шапки вне #grid
  $(document).on('change','.selall',function(){
    const on=this.checked;
    document.querySelectorAll('#grid tbody .selrow').forEach(b=>{
      b.checked=on; if(on) selected.add(b.value); else selected.delete(b.value);
    });
    refreshBar(); syncChecks();
  });

  const clear=()=>{ selected.clear(); refreshBar(); syncChecks(); };
  const clearBtn=document.getElementById('bulkClear');
  clearBtn&&clearBtn.addEventListener('click',clear);

  // подтверждение удаления
  const cm=document.getElementById('confirmModal');
  const ctext=document.getElementById('confirmText');
  const closeCm=()=>cm.classList.remove('open');
  document.getElementById('bulkDel').addEventListener('click',()=>{
    if(!selected.size) return;
    ctext.textContent='Удалить выбранные записи: '+selected.size+'? Действие необратимо.';
    cm.classList.add('open'); refreshIcons();
  });
  document.getElementById('confirmNo').addEventListener('click',closeCm);
  cm.addEventListener('click',e=>{ if(e.target===cm) closeCm(); });
  document.addEventListener('keydown',e=>{ if(e.key==='Escape') closeCm(); });

  const yes=document.getElementById('confirmYes');
  yes.addEventListener('click',()=>{
    const ids=[...selected]; if(!ids.length) return;
    const fd=new FormData(); fd.append('table',A.table);
    ids.forEach(v=>fd.append('ids[]',v));
    yes.disabled=true;
    fetch('api.php?action=delete_bulk&table='+enc(A.table),{method:'POST',body:fd})
      .then(r=>r.json()).then(res=>{
        if(res.ok){ toast('Удалено: '+(res.deleted!=null?res.deleted:ids.length));
          clear(); closeCm(); table.ajax.reload(null,false); reloadGroups(table); }
        else toast(res.error||'Ошибка',false);
      }).catch(e=>toast(String(e),false)).finally(()=>{ yes.disabled=false; });
  });

  // ---- массовая смена группы ----
  const gsel=document.getElementById('bulkGroupSel');
  const gnew=document.getElementById('bulkGroupNew');
  const gapply=document.getElementById('bulkGroupApply');
  if(gsel){
    // заполнить селект существующими группами + пункт «Создать группу…»
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
      if(!selected.size){ toast('Ничего не выбрано',false); return; }
      let group;
      if(gsel.value==='__new__'){ group=(gnew.value||'').trim();
        if(!group){ toast('Введите название группы',false); gnew.focus(); return; } }
      else if(gsel.value===''){ toast('Выберите группу',false); return; }
      else group=gsel.value;
      const ids=[...selected];
      const fd=new FormData(); fd.append('table',A.table); fd.append('group',group);
      ids.forEach(v=>fd.append('ids[]',v));
      gapply.disabled=true;
      fetch('api.php?action=set_group_bulk&table='+enc(A.table),{method:'POST',body:fd})
        .then(r=>r.json()).then(res=>{
          if(res.ok){ toast('Перенесено в «'+group+'»: '+(res.updated!=null?res.updated:ids.length));
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
  const map={f_platform:'fPlatform',f_browser:'fBrowser',f_device:'fDevice',f_age:'fAge',f_activity:'fActivity'};
  Object.entries(map).forEach(([k,id])=>{
    const el=document.getElementById(id); if(el && FILT[k]) el.value=FILT[k];
    el&&el.addEventListener('change',()=>{ FILT[k]=el.value; saveFilt(); table.ajax.reload(); });
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
    ['fPlatform','fBrowser','fDevice','fAge','fActivity'].forEach(id=>{const e=document.getElementById(id);if(e)e.value='';});
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
}
