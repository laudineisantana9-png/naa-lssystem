/* ============================================================================
   NAA 7.6.0 · COMPARAÇÃO ENTRE MODALIDADES
   SABE 2025 ↔ CNCA / Anos Finais 2026
   --------------------------------------------------------------------------
   Recurso isolado em modal. Não altera cálculos, uploads, dashboards ou banco.
   Compara somente indicadores homólogos (participação e faixas pedagógicas).
   Proficiência SABE e acerto CNCA/Finais são exibidos lado a lado, sem subtração,
   porque usam escalas/instrumentos diferentes.
   ============================================================================ */
(function(){
  'use strict';
  if(window.NaaCrossModalCompare)return;

  const VERSION='7.6.0';
  const CYCLES=['Ciclo I','Ciclo II','Ciclo III'];
  const LAYERS=['municipio','escola','turma'];
  const LAYER_LABEL={municipio:'Município',escola:'Escola',turma:'Turma'};
  const SEG_LABEL={sabe:'SABE',iniciais:'CNCA · Anos Iniciais',finais:'Anos Finais'};
  const COMPONENTS=[
    {value:'core',label:'Síntese · Língua Portuguesa + Matemática'},
    {value:'leitura',label:'Língua Portuguesa / Leitura'},
    {value:'matematica',label:'Matemática'}
  ];
  const PAIRS=[
    {
      key:'2-3',title:'Progressão 2º → 3º ano',tag:'mesma progressão escolar',
      left:{segment:'sabe',refYear:2025,grade:2},
      right:{segment:'iniciais',refYear:2026,grade:3},
      note:'Compara o 2º ano do SABE 2025 com o 3º ano do CNCA 2026. O ciclo do CNCA é escolhido abaixo.'
    },
    {
      key:'5-6',title:'Progressão 5º → 6º ano',tag:'transição Anos Iniciais → Finais',
      left:{segment:'sabe',refYear:2025,grade:5},
      right:{segment:'finais',refYear:2026,grade:6},
      note:'Compara o 5º ano do SABE 2025 com o 6º ano dos Anos Finais 2026. O ciclo dos Anos Finais é escolhido abaixo.'
    },
    {
      key:'9-9',title:'9º ano · comparação de etapa',tag:'não representa a mesma coorte',
      left:{segment:'sabe',refYear:2025,grade:9},
      right:{segment:'finais',refYear:2026,grade:9},
      note:'O estudante que estava no 9º ano em 2025 já não está no 9º em 2026. Portanto este bloco compara a etapa/rede, não a evolução dos mesmos estudantes.'
    }
  ];
  const ui={};

  function n(v){const x=Number(v);return Number.isFinite(x)?x:0}
  function clamp100(v){return Math.max(0,Math.min(100,n(v)))}
  function gradeNum(v){const m=String(v||'').match(/(?:^|\D)([1-9])\s*(?:º|°|o)?/i);return m?Number(m[1]):0}
  function cycle(v){
    const s=(typeof norm==='function'?norm(String(v||'')):String(v||'').toLowerCase()).replace(/[^a-z0-9]+/g,' ').trim();
    if(['ciclo iii','iii','ciclo 3','3'].includes(s))return'Ciclo III';
    if(['ciclo ii','ii','ciclo 2','2'].includes(s))return'Ciclo II';
    if(['ciclo i','i','ciclo 1','1'].includes(s))return'Ciclo I';
    return String(v||'').trim();
  }
  function layerOf(d,segment){
    if(!d||d.segment!==segment)return'';
    let direct='';
    if(segment==='sabe')direct=String(d.sabeLayer||'');
    else if(segment==='iniciais')direct=String(d.cncaLayer||'');
    else if(segment==='finais')direct=String(d.finalsLayer||'');
    if(['municipio','escola','turma','habilidades'].includes(direct))return direct;
    const id=String(d.id||'');
    const rx=segment==='sabe'?/\|(municipio|escola|turma|habilidades)$/i:segment==='iniciais'?/\|cnca:(municipio|escola|turma|habilidades)$/i:/\|finais:(municipio|escola|turma|habilidades)$/i;
    return id.match(rx)?.[1]?.toLowerCase()||'';
  }
  function dataset(sel){
    const cyc=sel.segment==='sabe'?'Ciclo Único':cycle(sel.cycle);
    return (importedDatasets||[]).find(d=>d?.segment===sel.segment&&Number(d.refYear)===Number(sel.refYear)&&layerOf(d,sel.segment)===sel.layer&&(sel.segment==='sabe'||cycle(d.cycle)===cyc))||null;
  }
  function hasLayer(segment,refYear,cyc,layer){return !!dataset({segment,refYear,cycle:cyc,layer})}
  function bestLayer(segment,refYear,cyc){return LAYERS.find(l=>hasLayer(segment,refYear,cyc,l))||'municipio'}
  function componentKind(row){
    const raw=String(row?.component||'').toLowerCase();
    if(raw==='sabe_lp')return'leitura';
    if(raw==='sabe_mt')return'matematica';
    // Os relatórios IRC do SABE são complementares e não entram novamente na síntese,
    // para não duplicar Língua Portuguesa/Matemática.
    if(raw==='sabe_lp_irc'||raw==='sabe_mt_irc')return'complementar';
    let v=raw;
    try{if(typeof normalizeComponent==='function')v=String(normalizeComponent(row?.componentLabel||row?.component||'')||raw)}catch{}
    const s=(typeof norm==='function'?norm(`${v} ${row?.componentLabel||''}`):`${v} ${row?.componentLabel||''}`.toLowerCase());
    if(s.includes('matemat'))return'matematica';
    if(s.includes('leitura')||s.includes('lingua portuguesa')||s==='lp')return'leitura';
    if(s.includes('escrita'))return'escrita';
    if(s.includes('fluencia'))return'fluencia';
    if(s.includes('ciencia'))return'ciencias';
    return v;
  }
  function entityKey(r,layer){
    const school=String(r.school||r.escola||'municipio').trim().toLowerCase();
    const klass=String(r.turma||r.classCode||'').trim().toLowerCase();
    if(layer==='turma')return`${school}|${klass}|${gradeNum(r.year)}`;
    if(layer==='escola')return`${school}|${gradeNum(r.year)}`;
    return`municipio|${gradeNum(r.year)}`;
  }
  function selectedRows(sel){
    const d=dataset(sel);if(!d)return[];
    const wanted=sel.component||'core';
    return (d.rows||[]).filter(r=>{
      if(gradeNum(r.year)!==Number(sel.grade))return false;
      const c=componentKind(r);
      if(c==='complementar')return false;
      if(wanted==='core')return c==='leitura'||c==='matematica';
      return c===wanted;
    });
  }
  function compAggregate(rows,segment,comp){
    const rs=rows.filter(r=>componentKind(r)===comp);if(!rs.length)return null;
    let expected=0,evaluated=0,hasCounts=false,invalidPairs=0;
    const entities=new Map();
    for(const r of rs){
      const e=n(r.previstos),a=n(r.avaliados),valid=e>0&&a>=0&&a<=e;
      if(e>0&&a>e)invalidPairs++;
      const k=entityKey(r,ui.__layerForCalc||'municipio');
      if(valid){
        hasCounts=true;const old=entities.get(k);
        if(!old||e>old.e||(e===old.e&&a>old.a))entities.set(k,{e,a});
      }
    }
    for(const x of entities.values()){expected+=x.e;evaluated+=x.a}
    const partRows=rs.map(r=>clamp100(r.participacao)).filter(v=>v>0);
    const participation=hasCounts&&expected>0?clamp100(evaluated/expected*100):(partRows.length?partRows.reduce((a,b)=>a+b,0)/partRows.length:0);
    const weighted=(field)=>{
      let sum=0,w=0;
      for(const r of rs){const v=n(r[field]);if(!Number.isFinite(v))continue;const ww=(n(r.avaliados)>0&&n(r.avaliados)<=n(r.previstos))?n(r.avaliados):1;sum+=v*ww;w+=ww}
      return w?sum/w:0;
    };
    let def=0,inter=0,adeq=0;
    if(segment==='sabe'){
      def=weighted('abaixo_basico');inter=weighted('basico');adeq=weighted('adequado')+weighted('avancado');
    }else{
      def=weighted('defasagem');inter=weighted('intermediario');adeq=weighted('adequado');
    }
    const total=def+inter+adeq;
    if(total>0&&Math.abs(total-100)>1){def=def/total*100;inter=inter/total*100;adeq=adeq/total*100}
    let own=0,ownLabel='';
    if(segment==='sabe'){own=weighted('proficiencia');ownLabel='Proficiência média'}
    else{own=weighted('acertoTotal');ownLabel='Acerto médio';if(!own){own=adeq;ownLabel='Aprendizado adequado'}}
    return{component:comp,expected,evaluated,hasCounts,participation,def:clamp100(def),inter:clamp100(inter),adeq:clamp100(adeq),own,ownLabel,invalidPairs};
  }
  function metrics(sel){
    const rows=selectedRows(sel);if(!rows.length)return{ok:false,rows:0};
    ui.__layerForCalc=sel.layer;
    const comps=(sel.component==='core'?['leitura','matematica']:[sel.component]).map(c=>compAggregate(rows,sel.segment,c)).filter(Boolean);
    delete ui.__layerForCalc;
    if(!comps.length)return{ok:false,rows:rows.length};
    const avg=f=>comps.reduce((a,x)=>a+n(x[f]),0)/comps.length;
    // Para LP+Matemática, o universo de estudantes é deduplicado entre componentes.
    const entityMax=new Map();let invalidPairs=0;
    for(const r of rows){
      const e=n(r.previstos),a=n(r.avaliados);if(e>0&&a>e){invalidPairs++;continue}if(!(e>0&&a>=0&&a<=e))continue;
      const k=entityKey(r,sel.layer),old=entityMax.get(k);if(!old||e>old.e||(e===old.e&&a>old.a))entityMax.set(k,{e,a});
    }
    let expected=0,evaluated=0;for(const x of entityMax.values()){expected+=x.e;evaluated+=x.a}
    return{
      ok:true,rows:rows.length,components:comps.length,
      expected,evaluated,hasCounts:expected>0,
      participation:avg('participation'),def:avg('def'),inter:avg('inter'),adeq:avg('adeq'),
      own:avg('own'),ownLabel:comps.every(x=>x.ownLabel===comps[0].ownLabel)?comps[0].ownLabel:'Indicador próprio',
      invalidPairs
    };
  }
  function fmtPct(v,d=1){return `${n(v).toLocaleString('pt-BR',{minimumFractionDigits:d,maximumFractionDigits:d})}%`}
  function fmtNum(v,d=0){return n(v).toLocaleString('pt-BR',{minimumFractionDigits:d,maximumFractionDigits:d})}
  function delta(v){const x=n(v);return`${x>0?'+':''}${x.toLocaleString('pt-BR',{minimumFractionDigits:1,maximumFractionDigits:1})} p.p.`}
  function layerOptions(sel){return LAYERS.map(l=>`<option value="${l}" ${sel.layer===l?'selected':''}>${LAYER_LABEL[l]}${hasLayer(sel.segment,sel.refYear,sel.cycle,l)?' · disponível':' · sem base'}</option>`).join('')}
  function cycleOptions(sel){return CYCLES.map(c=>`<option value="${c}" ${cycle(sel.cycle)===c?'selected':''}>${c}${hasLayer(sel.segment,sel.refYear,c,sel.layer)?' · com dados':''}</option>`).join('')}
  function sourceCard(sel,m,side){
    const title=`${SEG_LABEL[sel.segment]} ${sel.refYear}`;
    if(!m.ok)return`<article class="xmod-source-card missing"><div class="xmod-source-head"><div><small>${side}</small><h4>${title} · ${sel.grade}º ano</h4></div><span>${LAYER_LABEL[sel.layer]}</span></div><div class="xmod-empty"><b>Base sem dados para este recorte</b><p>Envie/abra a base ${LAYER_LABEL[sel.layer]} correspondente ou escolha outra fonte no seletor acima.</p></div></article>`;
    const ownText=sel.segment==='sabe'?fmtNum(m.own,1):(m.ownLabel==='Acerto médio'?fmtPct(m.own,1):fmtPct(m.own,1));
    return`<article class="xmod-source-card"><div class="xmod-source-head"><div><small>${side}</small><h4>${title} · ${sel.grade}º ano</h4></div><span>${LAYER_LABEL[sel.layer]}</span></div><div class="xmod-hero"><div><small>Faixa adequada ou superior</small><strong>${fmtPct(m.adeq,1)}</strong></div><div><small>Participação</small><strong>${fmtPct(m.participation,1)}</strong></div></div><div class="xmod-stack" aria-label="Distribuição pedagógica"><i class="def" style="width:${clamp100(m.def)}%"></i><i class="inter" style="width:${clamp100(m.inter)}%"></i><i class="adeq" style="width:${clamp100(m.adeq)}%"></i></div><div class="xmod-legend"><span><i class="def"></i>Defasagem <b>${fmtPct(m.def,0)}</b></span><span><i class="inter"></i>Intermediário <b>${fmtPct(m.inter,0)}</b></span><span><i class="adeq"></i>Adequado+ <b>${fmtPct(m.adeq,0)}</b></span></div><div class="xmod-own"><span>${m.ownLabel}</span><b>${ownText}</b></div>${m.hasCounts?`<div class="xmod-counts"><span>Previstos <b>${fmtNum(m.expected)}</b></span><span>Avaliados <b>${fmtNum(m.evaluated)}</b></span></div>`:''}${m.invalidPairs?`<div class="xmod-quality">${m.invalidPairs} linha(s) com avaliados &gt; previstos foram ignoradas nos quantitativos.</div>`:''}</article>`;
  }
  function pairState(spec){
    if(ui[spec.key])return ui[spec.key];
    const rightCycle=CYCLES.find(c=>LAYERS.some(l=>hasLayer(spec.right.segment,spec.right.refYear,c,l)))||'Ciclo I';
    const leftCycle='Ciclo Único';
    ui[spec.key]={component:'core',leftLayer:bestLayer(spec.left.segment,spec.left.refYear,leftCycle),rightCycle,rightLayer:bestLayer(spec.right.segment,spec.right.refYear,rightCycle)};
    return ui[spec.key];
  }
  function renderPair(spec){
    const s=pairState(spec);
    const left={...spec.left,cycle:'Ciclo Único',layer:s.leftLayer,component:s.component};
    const right={...spec.right,cycle:s.rightCycle,layer:s.rightLayer,component:s.component};
    const ml=metrics(left),mr=metrics(right);
    const canDelta=ml.ok&&mr.ok;
    return`<section class="xmod-pair" data-xmod-pair="${spec.key}"><div class="xmod-pair-title"><div><span>${spec.tag}</span><h3>${spec.title}</h3><p>${spec.note}</p></div><div class="xmod-controls"><label>Componente<select data-xmod-field="component">${COMPONENTS.map(x=>`<option value="${x.value}" ${s.component===x.value?'selected':''}>${x.label}</option>`).join('')}</select></label><label>Fonte SABE<select data-xmod-field="leftLayer">${layerOptions(left)}</select></label><label>Ciclo destino<select data-xmod-field="rightCycle">${cycleOptions(right)}</select></label><label>Fonte destino<select data-xmod-field="rightLayer">${layerOptions(right)}</select></label></div></div><div class="xmod-pair-grid">${sourceCard(left,ml,'Origem')}<div class="xmod-delta"><span>${typeof icon==='function'?icon('compare',22):'↔'}</span><small>Variação</small>${canDelta?`<strong class="${mr.adeq-ml.adeq>=0?'up':'down'}">${delta(mr.adeq-ml.adeq)}</strong><em>adequado+</em><b>${delta(mr.participation-ml.participation)}</b><em>participação</em>`:'<strong>—</strong><em>aguardando as duas bases</em>'}</div>${sourceCard(right,mr,'Destino')}</div></section>`;
  }
  function renderBody(root){
    if(!root)return;root.innerHTML=`<div class="xmod-wrap"><div class="xmod-intro"><div><b>Comparação longitudinal entre modalidades</b><span>SABE 2025 × avaliações municipais 2026</span></div><p>Os percentuais de participação e as faixas pedagógicas são harmonizados apenas para leitura lado a lado. <strong>Proficiência SABE e acerto CNCA/Anos Finais não são a mesma escala</strong>, por isso não calculamos diferença entre esses dois indicadores.</p></div>${PAIRS.map(renderPair).join('')}</div>`;
    root.querySelectorAll('[data-xmod-pair]').forEach(section=>{
      const key=section.dataset.xmodPair,s=ui[key];
      section.querySelectorAll('[data-xmod-field]').forEach(el=>el.addEventListener('change',()=>{
        s[el.dataset.xmodField]=el.value;
        // Se o ciclo mudou e a fonte escolhida não existe nele, seleciona a primeira disponível.
        if(el.dataset.xmodField==='rightCycle'){
          const spec=PAIRS.find(x=>x.key===key);
          if(spec&&!hasLayer(spec.right.segment,spec.right.refYear,s.rightCycle,s.rightLayer))s.rightLayer=bestLayer(spec.right.segment,spec.right.refYear,s.rightCycle);
        }
        renderBody(root);
      }));
    });
  }
  function openCompare(){
    modal('Comparar modalidades','SABE × CNCA × Anos Finais · análise em uma janela separada',`<div id="naa-xmod-root"></div>`,{wide:true,onOpen:modalRoot=>renderBody(modalRoot.querySelector('#naa-xmod-root'))});
  }

  // Ação isolada: não altera dashboards nem filtros existentes.
  if(typeof action==='function'){
    const baseAction=action;
    action=async function(a,el){if(a==='compare-modalities'){openCompare();return}return baseAction.apply(this,arguments)};
  }

  // Um único atalho no cabeçalho, dentro do grupo de ações rápidas.
  if(typeof renderHeader==='function'){
    const baseHeader=renderHeader;
    renderHeader=function(){
      let html=baseHeader.apply(this,arguments);
      if(!html||html.includes('data-action="compare-modalities"'))return html;
      const button=`<button class="naa-header-icon-v702 xmod-header-btn" data-action="compare-modalities" title="Comparar modalidades" aria-label="Comparar modalidades">${typeof icon==='function'?icon('compare',18):'↔'}</button>`;
      html=html.replace('<div class="naa-header-icon-group-v702" aria-label="Ações rápidas">',`<div class="naa-header-icon-group-v702" aria-label="Ações rápidas">${button}`);
      return html;
    };
  }

  const style=document.createElement('style');style.textContent=`
    .xmod-wrap{display:grid;gap:16px}.xmod-intro{display:grid;grid-template-columns:minmax(230px,.65fr) minmax(0,1.35fr);gap:14px;align-items:center;padding:14px 16px;border:1px solid color-mix(in srgb,var(--primary) 22%,var(--line));border-radius:16px;background:linear-gradient(135deg,color-mix(in srgb,var(--primary) 7%,var(--card)),var(--card))}.xmod-intro b{display:block;font-size:.88rem}.xmod-intro span{display:block;margin-top:3px;color:var(--primary);font-size:.66rem;font-weight:900;text-transform:uppercase;letter-spacing:.04em}.xmod-intro p{margin:0;color:var(--muted);font-size:.7rem;line-height:1.55}.xmod-intro strong{color:var(--text)}
    .xmod-pair{border:1px solid var(--line);border-radius:20px;background:var(--card);box-shadow:var(--shadow-soft);padding:16px;overflow:hidden}.xmod-pair-title{display:grid;grid-template-columns:minmax(230px,.75fr) minmax(0,1.25fr);gap:16px;align-items:end;margin-bottom:14px}.xmod-pair-title>div>span{font-size:.57rem;color:var(--primary);font-weight:950;text-transform:uppercase;letter-spacing:.06em}.xmod-pair-title h3{margin:3px 0 4px;font-size:1rem}.xmod-pair-title p{margin:0;color:var(--muted);font-size:.62rem;line-height:1.45}.xmod-controls{display:grid;grid-template-columns:1.25fr repeat(3,minmax(110px,1fr));gap:7px}.xmod-controls label{font-size:.53rem;color:var(--muted);font-weight:900;text-transform:uppercase;letter-spacing:.03em}.xmod-controls select{width:100%;height:36px;margin-top:4px;border:1px solid var(--line);border-radius:10px;background:var(--card-2);color:var(--text);padding:0 9px;font:inherit;font-size:.63rem;text-transform:none;letter-spacing:0;font-weight:800}
    .xmod-pair-grid{display:grid;grid-template-columns:minmax(0,1fr) 116px minmax(0,1fr);gap:10px;align-items:stretch}.xmod-source-card{border:1px solid var(--line);border-radius:17px;background:linear-gradient(160deg,var(--card),var(--card-2));padding:14px;min-width:0}.xmod-source-card.missing{display:grid;align-content:start;opacity:.76}.xmod-source-head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}.xmod-source-head small{display:block;color:var(--muted);font-size:.53rem;text-transform:uppercase;font-weight:900}.xmod-source-head h4{margin:3px 0 0;font-size:.78rem}.xmod-source-head>span{white-space:nowrap;padding:4px 7px;border-radius:999px;background:color-mix(in srgb,var(--primary) 7%,var(--card));border:1px solid color-mix(in srgb,var(--primary) 18%,var(--line));color:var(--primary);font-size:.53rem;font-weight:900}.xmod-hero{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:14px 0 11px}.xmod-hero>div{padding:10px;border-radius:12px;background:var(--card);border:1px solid var(--line)}.xmod-hero small{display:block;color:var(--muted);font-size:.55rem}.xmod-hero strong{display:block;margin-top:4px;font-size:1.45rem;letter-spacing:-.04em}.xmod-stack{height:25px;border-radius:9px;overflow:hidden;display:flex;background:var(--panel);border:1px solid var(--line)}.xmod-stack i{display:block;height:100%}.xmod-stack .def,.xmod-legend i.def{background:#ff8a18}.xmod-stack .inter,.xmod-legend i.inter{background:#ffc928}.xmod-stack .adeq,.xmod-legend i.adeq{background:#777eea}.xmod-legend{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-top:8px}.xmod-legend span{display:grid;grid-template-columns:7px 1fr;gap:5px;align-items:center;color:var(--muted);font-size:.52rem}.xmod-legend i{width:7px;height:7px;border-radius:50%}.xmod-legend b{grid-column:2;color:var(--text);font-size:.61rem}.xmod-own,.xmod-counts{display:flex;justify-content:space-between;gap:9px;align-items:center;margin-top:10px;padding-top:9px;border-top:1px solid var(--line)}.xmod-own span,.xmod-counts span{color:var(--muted);font-size:.56rem}.xmod-own b,.xmod-counts b{color:var(--text);font-size:.67rem}.xmod-counts{justify-content:flex-start;gap:18px}.xmod-quality{margin-top:8px;padding:7px 8px;border-radius:9px;background:#ef444410;color:#b42318;font-size:.52rem;line-height:1.4}.xmod-empty{display:grid;place-items:center;text-align:center;min-height:185px;padding:18px;color:var(--muted)}.xmod-empty b{color:var(--text);font-size:.7rem}.xmod-empty p{font-size:.59rem;line-height:1.5;max-width:280px}.xmod-delta{display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;border:1px dashed var(--line);border-radius:16px;background:var(--card-2);padding:10px 6px}.xmod-delta>span{width:36px;height:36px;border-radius:50%;display:grid;place-items:center;background:var(--card);color:var(--primary);border:1px solid var(--line);margin-bottom:6px}.xmod-delta small{font-size:.5rem;color:var(--muted);text-transform:uppercase;font-weight:900}.xmod-delta strong{font-size:.82rem;margin-top:4px}.xmod-delta strong.up{color:#0b8a52}.xmod-delta strong.down{color:#c4433d}.xmod-delta em{font-style:normal;color:var(--muted);font-size:.49rem;margin:1px 0 7px}.xmod-delta b{font-size:.65rem}.modal.wide:has(.xmod-wrap){width:min(1480px,calc(100vw - 24px))}.modal.wide:has(.xmod-wrap) .modal-body{padding:14px}
    @media(max-width:1100px){.xmod-pair-title{grid-template-columns:1fr}.xmod-controls{grid-template-columns:repeat(2,1fr)}.xmod-pair-grid{grid-template-columns:1fr}.xmod-delta{min-height:88px;display:grid;grid-template-columns:auto auto auto auto;gap:5px 8px}.xmod-delta>span{grid-row:1/3;margin:0}.xmod-delta em{margin:0}.xmod-intro{grid-template-columns:1fr}}@media(max-width:620px){.xmod-controls{grid-template-columns:1fr}.xmod-legend{grid-template-columns:1fr 1fr 1fr}.xmod-source-card{padding:11px}.xmod-pair{padding:11px}.xmod-hero strong{font-size:1.22rem}.xmod-header-btn{display:none!important}}
  `;document.head.appendChild(style);

  window.NaaCrossModalCompare={open:openCompare,version:VERSION};
})();
