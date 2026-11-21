(() => {
  'use strict';
  const root=document.getElementById('whmpve-console'); if(!root) return;
  const {t:tr,locale,language}=window.whmpveI18n(JSON.parse(root.dataset.i18n));
  if(locale)root.lang=locale;
  const base=root.dataset.base, token=root.dataset.token;
  const admin=root.dataset.scope==='admin', shortcut=admin ? root.dataset.tab : '';
  const nativeForm=admin ? root.closest('form') : null;
  const formHost=nativeForm ? document.body.appendChild(document.createElement('div')) : root;
  if(nativeForm){formHost.hidden=true;if(locale)formHost.lang=locale;}
  let state=JSON.parse(root.dataset.state), metrics={}, busy=false, error='', tab=['network','reinstall','backups','history'].includes(shortcut)?shortcut:'overview';
  let dialog=null, chosen=null, confirmation='', terminal=null, socket=null, ping=null, deferredRender=false;
  let consoleLease=null, consoleGeneration=0, consoleOpening=false;
  let activeVnc=null, sendTimer=null, savingBackup=false, backupRevision=0;
  const consoleNames={xterm:'xterm.js',novnc:'noVNC'};
  const ranges={hour:[3600,tr('ui.last_hour')],day:[86400,tr('ui.last_24_hours')],week:[604800,tr('ui.last_7_days')],month:[2678400,tr('ui.last_month')]};
  const seriesColors=['#245cff','#078d80','#a55a25'];
  const charts=new Map();
  let chartHover=null;
  const labels={pending:tr('ui.awaiting_provisioning'),provisioning:tr('ui.provisioning'),active:tr('ui.active'),suspended:tr('ui.suspended'),terminated:tr('ui.deleted'),queued:tr('ui.queued'),running:tr('ui.in_progress'),success:tr('ui.succeeded'),failed:tr('ui.failed'),stopped:tr('ui.stopped'),missing:tr('ui.instance_missing'),absent:tr('ui.awaiting_provisioning')};
  const actions={start:tr('ui.start'),shutdown:tr('ui.graceful_shutdown'),reboot:tr('ui.restart'),stop:tr('ui.force_shutdown'),reset:tr('ui.force_restart'),reinstall:tr('ui.reinstall_os'),create:tr('ui.provision'),suspend:tr('ui.suspend'),unsuspend:tr('ui.unsuspend'),terminate:tr('ui.destroy'),change_package:tr('ui.change_plan'),edit_config:tr('ui.adjust_configuration'),traffic:tr('ui.traffic_enforcement'),traffic_reset:tr('ui.traffic_reset'),addon_apply:tr('ui.apply_add_on'),password:tr('ui.reset_password'),lxc_password_reset:tr('ui.reset_password'),backup:tr('ui.create_backup'),backup_restore:tr('ui.restore_backup'),backup_delete:tr('ui.delete_backup')};
  const terms={Monthly:tr('ui.monthly_2'),Quarterly:tr('ui.quarterly_2'),'Semi-Annually':tr('ui.every_six_months'),Annually:tr('ui.annually_2'),Biennially:tr('ui.every_two_years'),Triennially:tr('ui.every_three_years'),
    'Free Account':tr('ui.free_account'),'One Time':tr('ui.one_time')};
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const requestId=()=>Array.from(crypto.getRandomValues(new Uint8Array(16)),x=>x.toString(16).padStart(2,'0')).join('');
  let timeframe='hour', refreshing=false;
  const size=n=>n===null||n===undefined?tr('ui.waiting_for_data'):n>=1073741824?(n/1073741824).toFixed(2)+' GiB':(n/1048576).toFixed(1)+' MiB';
  const badge=(value,label)=>`<span class="wmp-badge ${['active','success'].includes(value)?'wmp-ok':['failed','missing'].includes(value)?'wmp-error':['running','queued','provisioning'].includes(value)?'wmp-busy':'wmp-warn'}">${esc(label||labels[value]||value)}</span>`;
  const pending=()=>state.tasks?.find(t=>['queued','running'].includes(t.status));
  const canOperate=()=>admin?state.admin_permissions?.operate===true:state.can_manage===true;
  const canPurchase=()=>!admin&&state.addons?.can_purchase===true&&canOperate();
  const canAct=()=>canOperate()&&state.module_enabled!==false&&state.lifecycle==='active'&&state.service_status==='Active'&&!pending()&&!busy;
  const canEdit=()=>admin&&state.admin_permissions?.edit===true&&state.has_instance&&state.can_edit_config===true
    &&state.module_enabled!==false&&!pending()&&!busy;
  const row=(k,v)=>`<div class="wmp-row"><span>${esc(k)}</span><span>${v}</span></div>`;
  async function api(op,body=null,extra={},keepalive=false) {
    const scope=admin?{scope:'admin',server_id:state.server_id||0}:{};
    const query=new URLSearchParams({op:admin&&op==='action'?'service.action':op,service_id:state.service_id,...scope,...extra});
    const response=await fetch(`${base}/api.php?${query}`,{method:body?'POST':'GET',credentials:'same-origin',keepalive,headers:body?{'Content-Type':'application/json','X-Whmpve-Token':token,'X-Whmpve-Language':language}:{},body:body?JSON.stringify({...body,service_id:state.service_id}):null});
    let data; try{data=await response.json();}catch{throw Error(tr('ui.your_session_may_have_expired_refresh_the'));}
    if(!response.ok||!data.ok){const failure=Error(data.error||tr('ui.request_failed'));failure.status=response.status;throw failure;} return data.data;
  }
  const number=new Intl.NumberFormat(locale,{maximumFractionDigits:3}).format;
  const tickNumber=new Intl.NumberFormat(locale,{maximumFractionDigits:4}).format;
  const hoverNumber=new Intl.NumberFormat(locale,{maximumSignificantDigits:6}).format;
  const hoverTime=new Intl.DateTimeFormat(locale,{year:'numeric',month:'2-digit',day:'2-digit',
    hour:'2-digit',minute:'2-digit',second:'2-digit',hourCycle:'h23'});
  const metricValue=(value,unit,format)=>Number.isFinite(value)?format(value)+(unit==='%'?'%':' '+unit):'—';
  const sample=(p,key,factor=1)=>p[key]==null ? null : Number(p[key])*factor;
  const percent=(p,value,max)=>p[max]>0 && p[value]!=null ? 100*p[value]/p[max] : null;
  function memoryUsage(point) {
    const used=sample(point,'memory'), total=sample(point,'max_memory');
    if(!Number.isFinite(used))return '';
    return size(used)+(total>0 ? ' / '+size(total) : '');
  }
  function metricChart(title,series,unit,fixedMax,usage) {
    const end=metrics.updated_at || Date.now()/1000, start=end-ranges[timeframe][0];
    const points=(metrics.timeframe===timeframe ? metrics.history||[] : []).filter(p=>p.time>=start&&p.time<=end)
      .map(p=>({time:p.time,values:series.map(([,read])=>read(p)),usage:usage?.(p)}));
    const hasData=points.some(p=>p.usage || p.values.some(Number.isFinite));
    if(hasData)charts.set(title,{points,start,end,unit,names:series.map(([name])=>name)});
    const latest=series.map((_,index)=>points.findLast(p=>p.usage || Number.isFinite(p.values[index])));
    const peak=Math.max(0,...points.flatMap(p=>p.values.filter(Number.isFinite)));
    const step=10**Math.floor(Math.log10(Math.max(peak,0.001)));
    const max=fixedMax || (peak ? Math.max(.001,Math.ceil(peak/step)*step) : 1);
    const width=560,height=140;
    const x=t=>(t-start)/(end-start)*width;
    const y=v=>height*(1-Math.min(max,Math.max(0,v))/max);
    const clock={hour:'2-digit',minute:'2-digit',hourCycle:'h23'};
    const dates=new Intl.DateTimeFormat(locale,timeframe==='hour' ? clock
      : {month:'numeric',day:'numeric',...(timeframe==='day' ? clock : {})});
    const levels=[1,.5,0], ticks=timeframe==='day' ? [0,.5,1] : [0,1/3,2/3,1];
    const horizontal=levels.map(f=>`<path d="M0 ${y(max*f)}H${width}"/>`).join('');
    const vertical=ticks.map(f=>`<path d="M${f*width} 0V${height}"/>`).join('');
    const scaleLabels=levels.map(f=>`<span>${tickNumber(max*f)}${unit==='%'?'%':''}</span>`).join('');
    const timeLabels=ticks.map(f=>`<span style="--tick:${f*100}%">${dates.format((start+f*(end-start))*1000)}</span>`).join('');
    const traces=series.map((_,index)=>{
      let previous=false, path='', markers='';
      for(const [position,point] of points.entries()) {
        const value=point.values[index];
        if(!Number.isFinite(value)) {previous=false;continue;}
        path+=(previous?'L':'M')+`${x(point.time).toFixed(1)} ${y(value).toFixed(1)}`;
        if(!previous&&!Number.isFinite(points[position+1]?.values[index])) {
          const offset=x(point.time)<width/2?0.1:-0.1;
          markers+=`<path class="wmp-plot-dot" style="--series:${seriesColors[index]}" d="M${x(point.time)} ${y(value)}h${offset}"/>`;
        }
        previous=true;
      }
      return path ? `<path class="wmp-plot-line" style="--series:${seriesColors[index]}" d="${path}"/>${markers}` : '';
    }).join('');
    const format=unit==='%'?number:tickNumber;
    const legend=series.map(([name],index)=>{
      const point=latest[index];
      return `<span><i style="background:${seriesColors[index]}"></i>${name} <strong>${metricValue(point?.values[index],unit,format)}</strong></span>`
        +(point?.usage?`<span><strong>${esc(point.usage)}</strong></span>`:'');
    }).join('');
    return `<section class="wmp-card wmp-metric"><div class="wmp-panel-title"><h3>${title}</h3><div class="wmp-plot-legend">${legend}</div></div>
      <div class="wmp-chart"><div class="wmp-axis-y">${scaleLabels}</div>
        <div class="wmp-plot-area" ${hasData?`data-chart="${esc(title)}"`:''}>
          <svg class="wmp-plot" viewBox="0 0 ${width} ${height}" preserveAspectRatio="none" role="img"
            aria-label="${title}${esc(tr('ui.time_series'))}">${horizontal}${vertical}${traces}</svg>
          ${hasData?`<div class="wmp-plot-hover" hidden><span class="wmp-plot-guide"></span><div class="wmp-plot-tooltip"></div></div>`:
            `<span class="wmp-plot-empty">${esc(tr('ui.no_data_yet'))}</span>`}</div>
        <div class="wmp-axis-x">${timeLabels}</div>
      </div></section>`;
  }
  function hideChartHover() {
    if(chartHover){chartHover.layer.hidden=true;chartHover=null;}
  }
  function showChartHover(plot,clientX,clientY) {
    if(chartHover?.plot!==plot)hideChartHover();
    const chart=plot&&charts.get(plot.dataset.chart);
    if(!chart||!root.contains(plot))return;
    const {left,top,width,height}=plot.getBoundingClientRect();
    const time=chart.start+(clientX-left)/width*(chart.end-chart.start);
    const point=chart.points.reduce((nearest,current)=>Math.abs(current.time-time)<Math.abs(nearest.time-time)?current:nearest);
    const layer=plot.querySelector('.wmp-plot-hover'), tip=layer.querySelector('.wmp-plot-tooltip');
    const position=(point.time-chart.start)/(chart.end-chart.start);
    layer.style.setProperty('--sample-x',position*100+'%');
    if(chartHover?.point!==point) {
      const date=new Date(point.time*1000);
      tip.innerHTML=`<time datetime="${date.toISOString()}">${esc(hoverTime.format(date))}</time>
        ${chart.names.map((name,i)=>`<div><span><i style="background:${seriesColors[i]}"></i>${esc(name)}</span>
          <strong>${esc(metricValue(point.values[i],chart.unit,hoverNumber))}</strong></div>`).join('')}
        ${point.usage?`<div><span>${esc(tr('ui.usage'))}</span><strong>${esc(point.usage)}</strong></div>`:''}`;
    }
    layer.hidden=false;
    const x=position*width, gap=10, right=x+gap;
    const tipLeft=right+tip.offsetWidth<=width?right:Math.max(0,x-tip.offsetWidth-gap);
    tip.style.left=tipLeft+'px';
    tip.style.top=Math.max(0,Math.min(height-tip.offsetHeight,clientY-top+gap))+'px';
    chartHover={plot,layer,point,x:clientX,y:clientY};
  }
  function monitoring() {
    const load=metrics.load;
    return `<section class="wmp-monitor"><div class="wmp-panel-title"><h3>${esc(tr('ui.resource_monitoring'))}</h3>
      <label class="wmp-inline">${esc(tr('ui.time_range'))} <select name="metric_range" class="wmp-btn">${Object.entries(ranges).map(([value,[,label]])=>`<option value="${value}" ${timeframe===value?'selected':''}>${label}</option>`).join('')}</select></label></div>
      <div class="wmp-grid">
        ${metricChart(tr('ui.cpu_usage'),[['CPU',p=>sample(p,'cpu',100)]],'%',100)}
        ${metricChart(tr('ui.memory_usage'),[[tr('ui.memory'),p=>percent(p,'memory','max_memory')]],'%',100,memoryUsage)}
        ${metricChart(tr('ui.network_speed'),[[tr('ui.inbound'),p=>sample(p,'netin',8/1e6)],[tr('ui.outbound'),p=>sample(p,'netout',8/1e6)]],'Mbps')}
        ${metricChart(tr('ui.disk_i_o'),[[tr('ui.read'),p=>sample(p,'diskread',1/1048576)],[tr('ui.write'),p=>sample(p,'diskwrite',1/1048576)]],'MiB/s')}
      </div>
      ${load?`<section class="wmp-card"><h3>${esc(tr('ui.load_average'))}</h3><dl class="wmp-load-values">${[1,5,15].map((minutes,index)=>
        `<div><dt>${minutes} ${esc(tr('ui.min'))}</dt><dd>${load[index].toFixed(2)}</dd></div>`).join('')}</dl></section>`:''}</section>`;
  }
  function taskBox() {
    const t=pending(); if(!t) return '';
    return `<div class="wmp-task" role="status" data-task-id="${t.id}"><div class="wmp-task-line"><strong>${esc(actions[t.action]||t.action)} · #${t.id}</strong>${badge(t.status)}</div><div class="wmp-progress"><span style="width:${Math.max(4,(t.stage_number||0)/(t.stage_total||1)*100)}%"></span></div><div class="wmp-task-line"><span>${esc(t.stage)}</span><small>${esc(tr('ui.stage'))} ${t.stage_number} / ${t.stage_total}</small></div></div>`;
  }
  function connectionRows() {
    const display=state.connection_display;
    if(!display)return '';
    return (display.public_ip?row(tr('ui.public_ingress_ip'),esc(display.public_ip)):'')+
      (display.port_note?row(tr('ui.assigned_ports'),`<span class="wmp-port-note">${esc(display.port_note)}</span>`):'');
  }
  function overview() {
    const s=state.spec;
    if(!s) return `<div class="wmp-card wmp-empty">${esc(tr('ui.awaiting_provisioning_2'))}</div>`;
    const card=(title,value,note='')=>`<div class="wmp-card"><div class="wmp-label">${title}</div><div class="wmp-value">${value}</div>${note ? '<div class="wmp-note">'+note+'</div>' : ''}</div>`;
    const addresses=(state.ips||[]).map(ip=>`${esc(ip.address)}<button type="button" class="wmp-copy" data-copy="${esc(ip.address)}" aria-label="${esc(tr('ui.copy_ip'))}">${esc(tr('ui.copy'))}</button>`).join('<br>');
    return `<div class="wmp-cards">
      ${card('CPU',s.cores+' vCPU',metrics.cpu==null ? tr('ui.waiting_for_data') : tr('ui.current_usage_value',{usage:number(metrics.cpu*100)+'%'}))}
      ${card(tr('ui.memory'),s.memory_mib>=1024 ? s.memory_mib/1024+' GiB' : s.memory_mib+' MiB',tr('ui.memory_used',{size:size(metrics.memory)})+(s.swap_mib ? ' · Swap '+s.swap_mib+' MiB' : ''))}
      ${card(tr('ui.system_disk'),s.disk_gib+' GiB')}
      ${(s.data_disks || []).map(disk=>card(tr('ui.numbered_data_disk',{number:disk.id}),Number(disk.size_gib)+' GiB',esc(disk.mountpoint))).join('')}
      ${card(tr('ui.network_speed_limit'),s.bandwidth_mbps ? s.bandwidth_mbps+' Mbps' : tr('ui.unlimited_speed'))}
      </div>${monitoring()}${trafficBox()}<div class="wmp-grid">
      <div class="wmp-card"><h3>${esc(tr('ui.connection_details'))}</h3>${row(state.connection_display?tr('ui.private_ip'):tr('ui.ip_address'),addresses||tr('ui.unassigned'))}${connectionRows()}${row(tr('ui.username'),'root')}${row(tr('ui.operating_system'),esc(state.image_name))}${row(tr('ui.network_mode'),state.network_mode==='none'?tr('ui.no_network_interface_configured'):tr('ui.static_network'))}</div>
      <div class="wmp-card"><h3>${esc(tr('ui.service_details'))}</h3>${row(tr('ui.service_id'),'#'+state.service_id)}${row(tr('ui.service_status'),badge(state.lifecycle))}
        ${admin?row('VMID',state.vmid||'—')+row(tr('ui.pve_node'),esc(state.node||'—')):row(tr('ui.billing_cycle'),esc(terms[state.billing?.cycle]||state.billing?.cycle))+row(tr('ui.renewal_price'),esc(state.billing?.amount))+row(tr('ui.next_due_date'),esc(state.billing?.next_due))}
        ${row(tr('ui.plan'),esc(state.plan_name))}</div></div>`;
  }
  function trafficBox() {
    const t=state.traffic;
    if (!t) return '';
    const used=(t.incoming+t.outgoing)/1e9, percent=t.limit_gb ? Math.min(100,used/t.limit_gb*100) : 0;
    const status=t.restriction==='throttled' ? `${esc(tr('ui.limited_to'))} ${t.speed_kbps} Kbps` : t.restriction==='suspended' ? tr('ui.suspended_for_exceeding_traffic_quota') : '';
    return `<div class="wmp-card wmp-space" data-traffic><div class="wmp-panel-title"><h3>${esc(tr('ui.traffic_this_period'))}</h3><strong>${used.toFixed(3)} GB / ${t.limit_gb ? t.limit_gb+' GB' : tr('ui.unlimited_traffic')}</strong></div>
      <div class="wmp-progress"><span style="width:${percent}%"></span></div><div class="wmp-task-line"><small>${esc(tr('ui.traffic_direction_totals',{incoming:number(t.incoming/1e9),outgoing:number(t.outgoing/1e9)}))}</small><small>${esc(tr('ui.next_reset'))} ${new Date(t.reset_at*1000).toLocaleString(locale)}</small></div>
      ${status ? '<div class="wmp-note">'+status+'</div>' : ''}</div>`;
  }
  function invoiceUrl(id) {return new URL(`../../../viewinvoice.php?id=${Number(id)}`,base+'/').href;}
  function addonEffect({kind,quantity}) {
    const descriptions={traffic_reset:tr('ui.restore_quota_percent',{percent:quantity}),cores:`+${quantity} ${tr('ui.cpu_cores_2')}`,memory_mib:`+${quantity} ${tr('ui.mib_memory')}`,
      ipv4:`+${quantity} ${esc(tr('ui.ipv4_addresses'))}`,ipv6:`+${quantity} ${esc(tr('ui.ipv6_addresses'))}`};
    return descriptions[kind];
  }
  const addonRestartNote=tr('ui.restart_your_vps_to_apply_the_new');
  const addonNeedsRestart=({kind})=>state.kind==='qemu'&&['cores','memory_mib'].includes(kind);
  function addons() {
    const {items=[],pending:invoice,purchased=[]}=state.addons||{};
    return `<section class="wmp-card"><div class="wmp-panel-title"><h3>${esc(tr('ui.add_ons'))}</h3>
      ${invoice&&canPurchase()?`<a class="wmp-btn" href="${esc(invoiceUrl(invoice.invoice_id))}">${invoice.paid?tr('ui.view_paid_invoice'):tr('ui.pay_add_on_invoice')}</a>`:''}</div>
      ${items.length?`<div class="wmp-table-wrap"><table><thead><tr><th>${esc(tr('ui.name'))}</th><th>${esc(tr('ui.benefit'))}</th><th>${esc(tr('ui.price'))}</th><th>${esc(tr('ui.actions'))}</th></tr></thead>
        <tbody>${items.map(item=>`<tr><td>${esc(item.name)}</td><td>${esc(addonEffect(item))}</td>
        <td>${item.price?esc(item.price)+' · '+(item.kind==='traffic_reset'?tr('ui.one_time'):terms[item.cycle]||'—'):'—'}</td><td>${item.reason?esc(item.reason):canPurchase()?
          `<button type="button" class="wmp-btn" data-buy-addon="${item.id}" data-addon-cycle="${esc(item.cycle)}" ${invoice?'disabled':''}>${esc(tr('ui.buy'))}</button>`:'—'}</td></tr>`).join('')}</tbody></table></div>`:''}
      ${items.some(addonNeedsRestart)?`<p class="wmp-note">${addonRestartNote}</p>`:''}
      ${items.length&&!admin&&!canPurchase()?`<p class="wmp-note">${state.addons.on_behalf?tr('ui.this_administrator_cannot_buy_on_behalf_of'):tr('ui.use_a_customer_account_with_service_management')}</p>`:''}
      ${purchased.length?`<h3 class="wmp-space">${esc(tr('ui.purchased'))}</h3>${purchased.map(item=>row(item.name,esc(item.price)+' · '+(terms[item.cycle]||esc(item.cycle)))).join('')}`:''}</section>`;
  }
  async function purchaseAddon(button) {
    const {id,amount,period,currency,revision,cycle}=dialog.addon;
    const notice=root.querySelector('[data-addon-purchase-error]');
    button.disabled=true; notice.hidden=true;
    try {
      const invoice=await api('addon.purchase',{addon_id:id,amount,period,currency,revision,cycle});
      window.location.assign(invoiceUrl(invoice.invoice_id));
    } catch(e) {
      notice.textContent=e.message;notice.hidden=false;button.disabled=false;
    }
  }
  function network() {
    return `<div class="wmp-card"><div class="wmp-panel-title"><h3>${esc(tr('ui.network_configuration'))}</h3>
      ${admin&&state.admin_permissions?.edit?`<button type="button" class="wmp-btn" data-edit-config ${canEdit()?'':'disabled'}>${esc(tr('ui.edit_vps_configuration'))}</button>`:''}</div>${state.network_mode==='none' ?
      `<div class="wmp-empty">${esc(tr('ui.no_network_interface_configured_2'))}</div>` :
      state.ips.map(ip => {
        const family=ip.address.includes(':')?'IPv6':'IPv4';
        return row(state.connection_display?tr('ui.private_ip_family',{family}):family,esc(ip.address)+'/'+ip.prefix)+row(tr('ui.gateway'),esc(ip.gateway||'—'));
      }).join('')}
      ${connectionRows()}${row(tr('ui.bandwidth'),state.spec.bandwidth_mbps ? state.spec.bandwidth_mbps+' Mbps' : tr('ui.unlimited_speed'))}</div>${trafficBox()}`;
  }
  function backups() {
    const {items,limit,automatic_allowed,automatic_enabled}=state.backups;
    const canSetAutomatic=canOperate()&&state.module_enabled&&state.has_instance&&['active','suspended'].includes(state.lifecycle)
      && ['Active','Suspended'].includes(state.service_status);
    return `<section class="wmp-card"><div class="wmp-panel-title"><div class="wmp-inline"><h3>${esc(tr('ui.backup'))}</h3><small class="wmp-muted">${items.length} / ${limit}</small></div>
      <button type="button" class="wmp-btn wmp-primary" data-action="backup" ${!canAct()||items.length>=limit?'disabled':''}>${esc(tr('ui.create_backup'))}</button></div>
      ${admin&&automatic_allowed?`<p class="wmp-note">${esc(tr('ui.automatic_backups'))} ${automatic_enabled?tr('ui.enabled_2'):tr('ui.disabled_2')}</p>`:''}
      ${!admin&&automatic_allowed&&canSetAutomatic?`<label class="wmp-inline wmp-space"><input type="checkbox" data-auto-backup
        ${automatic_enabled?'checked':''} ${savingBackup?'disabled':''}>${esc(tr('ui.automatic_backup_schedule',{time:'03:00'}))}</label>`:''}
      <div class="wmp-table-wrap wmp-space"><table><thead><tr><th>${esc(tr('ui.created'))}</th><th>${esc(tr('ui.type'))}</th><th>${esc(tr('ui.size'))}</th><th>${esc(tr('ui.actions'))}</th></tr></thead><tbody>
      ${items.map(item=>`<tr><td>${esc(new Date(item.created_at.replace(' ','T')+'Z').toLocaleString(locale))}</td><td>${item.automatic?tr('ui.automatic'):tr('ui.manual')}</td><td>${size(item.size)}</td><td><div class="wmp-toolbar">
        <button type="button" class="wmp-btn" data-action="backup_restore" data-backup-id="${item.id}" ${canAct()?'':'disabled'}>${esc(tr('ui.restore'))}</button>
        <button type="button" class="wmp-btn" data-action="backup_delete" data-backup-id="${item.id}" ${!canOperate()||busy||pending()?'disabled':''}>${esc(tr('ui.delete'))}</button></div></td></tr>`).join('')||`<tr><td colspan="4">${esc(tr('ui.no_backups_yet'))}</td></tr>`}</tbody></table></div></section>`;
  }
  async function setAutomaticBackup(toggle) {
    if(!canOperate())return;
    savingBackup=true; ++backupRevision; toggle.disabled=true; error='';
    try {
      const result=await api('backups.automatic',{enabled:toggle.checked});
      state.backups.automatic_enabled=result.automatic_enabled;
    } catch(e) { error=e.message; }
    finally { savingBackup=false; if(!dialog)render(); }
  }
  function passwordFields(disabled,optional) {
    const attributes=(disabled?'disabled ':'')+(optional?'':'required');
    return `<div class="wmp-grid wmp-reinstall-fields"><label class="wmp-field">${esc(tr('ui.new_root_password'))}<input type="password" name="new_password" autocomplete="new-password" minlength="5" ${attributes}>${optional?`<small>${esc(tr('ui.leave_blank_to_keep_the_current_password'))}</small>`:''}</label>
      <label class="wmp-field">${esc(tr('ui.confirm_new_password'))}<input type="password" name="confirm_password" autocomplete="new-password" ${attributes}></label></div>`;
  }
  function resetPassword() {
    const downtime=state.kind==='lxc'?`<label class="wmp-field wmp-check wmp-space"><span>${esc(tr('ui.i_understand_the_vps_will_shut_down'))}</span><input type="checkbox" name="confirm_downtime" value="1" required></label>`:'';
    return `<form data-password><div class="wmp-notice wmp-error" role="alert" hidden></div>${passwordFields(false,false)}${downtime}
      <button type="submit" class="wmp-btn wmp-primary">${esc(tr('ui.reset_password'))}</button></form>`;
  }
  function reinstall() {
    if(!chosen) chosen=state.image_id;
    const disabled=!canAct() || !state.images?.length;
    return `<form class="wmp-card" data-reinstall><h3>${esc(tr('ui.reinstall_os'))}</h3>
      <div class="wmp-notice wmp-warn wmp-space">${esc(tr(state.spec.data_disks?.length?'ui.reinstall_keep_data_disks':'ui.reinstall_erase_system_disk'))}</div>
      <div class="wmp-notice wmp-error" data-reinstall-error role="alert" hidden></div>
      <div class="wmp-image-grid">${(state.images||[]).map(i=>`<label class="wmp-image"><input type="radio" name="image_id" value="${i.id}" ${Number(chosen)===Number(i.id)?'checked':''} ${disabled?'disabled':''} required>${esc(i.name)}${i.description?`<span class="wmp-note wmp-image-description">${esc(i.description)}</span>`:''}</label>`).join('')}</div>
      ${passwordFields(disabled,true)}
      <label class="wmp-field">${esc(tr('ui.confirm_hostname',{hostname:state.hostname}))}<input name="confirm_hostname" autocomplete="off" value="${esc(confirmation)}" placeholder="${esc(tr('ui.full_hostname'))}" ${disabled?'disabled':''} required></label>
      <div class="wmp-modal-actions"><button type="submit" class="wmp-btn wmp-danger wmp-primary" data-action="reinstall" ${disabled?'disabled':''}>${esc(tr('ui.erase_and_reinstall'))}</button></div></form>`;
  }
  function history() {
    return `<div class="wmp-card"><h3>${esc(tr('ui.tasks_logs_2'))}</h3><div class="wmp-table-wrap wmp-space"><table><thead><tr><th>${esc(tr('ui.task'))}</th><th>${esc(tr('ui.status'))}</th><th>${esc(tr('ui.time'))}</th>
      ${admin?`<th>${esc(tr('ui.requested_by'))}</th><th>${esc(tr('ui.action'))}</th>`:''}</tr></thead><tbody>${(state.tasks||[]).map(t=>`<tr><td>#${t.id} ${esc(actions[t.action]||t.action)}</td>
        <td>${badge(t.status)}</td><td>${esc(taskTime(t.updated_at))}</td>${admin?`<td>${esc(taskActor(t.actor))}</td>
        <td><button type="button" class="wmp-btn" data-task-detail="${t.id}">${esc(tr('ui.view'))}</button></td>`:''}</tr>`).join('')
        ||`<tr><td colspan="${admin?5:3}">${esc(tr('ui.no_tasks_yet'))}</td></tr>`}</tbody></table></div></div>`;
  }
  const taskTime=value=>value?new Date(value.replace(' ','T')+'Z').toLocaleString(locale,{timeZone:'Asia/Shanghai',hour12:false}):'—';
  function taskActor(actor='') {
    if(actor.startsWith('admin:'))return tr('ui.administrator')+actor.slice(6);
    if(actor.startsWith('client:'))return tr('ui.client_2')+actor.slice(7);
    return ({module:'WHMCS',payment:tr('ui.payment'),'backup-scheduler':tr('ui.automatic_backups'),traffic:tr('ui.traffic_enforcement')})[actor]||tr('ui.system');
  }
  function taskDetails(task) {
    return `<div class="wmp-panel-title"><strong>#${task.id} · ${esc(actions[task.action]||task.action)}</strong>${badge(task.status)}</div>
      <p>${esc(taskActor(task.actor))} · ${esc(task.stage)}</p>
      ${task.error?`<div class="wmp-notice wmp-error wmp-space">${esc(task.error)}</div>`:''}
      <div class="wmp-space">${task.logs.map(entry=>`<div class="wmp-log"><time>${esc(taskTime(entry.time))}</time>${esc(entry.message)}</div>`).join('')}</div>`;
  }
  function instanceIpRow(ip,owner='') {
    const form=owner?`form="${esc(owner)}"`:'';
    return `<tr data-instance-ip><td><label class="wmp-field">
      <input name="addresses" type="text" aria-label="${esc(tr('ui.ip_address'))}" value="${esc(ip.address)}" maxlength="45"
        autocomplete="off" spellcheck="false" required ${form}></label></td>
      ${[ip.prefix,ip.gateway].map(value=>`<td data-ip-meta="${esc(value??'')}">${esc(value??'—')||'—'}</td>`).join('')}
      <td><button type="button" class="wmp-btn wmp-danger" data-remove-ip ${form}>${esc(tr('ui.delete'))}</button></td></tr>`;
  }
  function instanceConfig(instance) {
    const spec=instance.spec, net=spec.network||{}, kvm=instance.kind==='qemu';
    const field=(label,name,value,type='number',help='')=>`<label class="wmp-field">${label}
      <input type="${type}" name="${name}" value="${esc(value)}" ${type==='number'?'min="0" step="any"':''}>
      ${help?`<small>${esc(help)}</small>`:''}</label>`;
    const inherit=tr('ui.leave_blank_to_inherit_the_ip_pool');
    const optional=key=>net[key]===''?'-':net[key];
    return `<form data-instance-config><div class="wmp-notice wmp-error" data-error role="alert" hidden></div>
      <div class="wmp-form">
        ${field(tr('ui.cpu_cores'),'cores',spec.cores)}${field(tr('ui.cpu_limit_cores_2'),'cpu_limit',spec.cpu_limit??spec.cores)}
        ${field(tr('ui.memory_mib'),'memory_mib',spec.memory_mib,'number',kvm?tr('ui.changes_may_require_a_full_shutdown_and'):'')}
        ${kvm?'':field(tr('ui.swap_mib'),'swap_mib',spec.swap_mib??0)}
        ${field(tr('ui.system_disk_gib'),'disk_gib',spec.disk_gib,'number',tr('ui.enter_the_new_total_capacity'))}
        ${(spec.data_disks||[]).map(disk=>field(tr('ui.data_disk_capacity',{number:disk.id}),'disk_size_'+Number(disk.id),disk.size_gib,'number',disk.mountpoint)).join('')}
        ${field(tr('ui.bandwidth_mbps'),'bandwidth_mbps',spec.bandwidth_mbps,'number',tr('ui.0_means_no_speed_limit'))}
        ${field(tr('ui.monthly_traffic_quota_gb'),'traffic_gb',spec.traffic_gb??0,'number',tr('ui.0_means_unlimited_traffic'))}
        <section class="wmp-full wmp-form-section"><div class="wmp-panel-title"><strong>${esc(tr('ui.network'))}</strong>
          <button type="button" class="wmp-btn" data-add-ip>${esc(tr('ui.add_ip_address'))}</button></div><div class="wmp-form">
          <div class="wmp-full"><div class="wmp-table-wrap wmp-ip-editor"><table><thead><tr>
            <th>${esc(tr('ui.ip_address'))}</th><th>${esc(tr('ui.prefix_length'))}</th><th>${esc(tr('ui.gateway'))}</th><th>${esc(tr('ui.actions'))}</th></tr></thead>
            <tbody>${instance.ips.map(ip=>instanceIpRow(ip)).join('')}</tbody></table></div>
            <p class="wmp-note">${esc(tr('ui.use_addresses_from_this_servers_ip_pool'))}</p></div>
          ${field(tr('ui.bridge'),'bridge',net.bridge,'text',inherit)}${field('VLAN','vlan',net.vlan,'number',tr('ui.leave_blank_to_inherit_the_ip_pool_2'))}
          ${field(tr('ui.ipv4_prefix_length'),'prefix4',net.prefix4,'number',inherit)}${field(tr('ui.ipv4_gateway'),'gateway4',optional('gateway4'),'text',tr('ui.enter_to_clear_the_gateway_leave_blank'))}
          ${field(tr('ui.ipv6_prefix_length'),'prefix6',net.prefix6,'number',inherit)}${field(tr('ui.ipv6_gateway'),'gateway6',optional('gateway6'),'text',tr('ui.enter_to_clear_the_gateway_leave_blank'))}
          ${field('MTU','mtu',net.mtu,'number',inherit)}${field('DNS','dns',optional('dns'),'text',tr('ui.separate_addresses_with_spaces_enter_to_clear'))}
        </div></section>
      </div><div class="wmp-modal-actions"><button class="wmp-btn wmp-primary" type="submit">${esc(tr('ui.save'))}</button></div></form>`;
  }
  function consoleMenu(available) {
    if(!available) return `<button type="button" class="wmp-btn wmp-primary" disabled>${esc(tr('ui.open_console'))}</button>`;
    const modes=state.kind==='qemu' ? ['novnc','xterm'] : ['xterm','novnc'];
    const open=root.querySelector('[data-console-menu]')?.open && !dialog;
    return `<details class="wmp-action-menu" data-console-menu ${open?'open':''}>
      <summary class="wmp-btn wmp-primary">${esc(tr('ui.open_console_2'))} ▾</summary>
      <div class="wmp-menu">${modes.map(mode=>`<button type="button" data-console="${mode}">${consoleNames[mode]}</button>`).join('')}</div>
    </details>`;
  }
  function consoleContent() {
    return `<div id="wmp-terminal" class="wmp-terminal">${esc(tr('ui.connecting'))}…</div>
      <p class="wmp-note" data-console-status role="status"></p>
      ${dialog.mode==='novnc'?`<details class="wmp-console-send" data-console-send>
        <summary class="wmp-btn">${esc(tr('ui.send_to_vps'))}</summary>
        <label class="wmp-field">${esc(tr('ui.enter_text_to_send'))}<textarea rows="3" data-console-text spellcheck="false" autocomplete="off"></textarea></label>
        <div class="wmp-inline"><button type="button" class="wmp-btn wmp-primary" data-send-keys disabled>${esc(tr('ui.send'))}</button>
          <button type="button" class="wmp-btn" data-stop-keys hidden>${esc(tr('ui.stop_sending'))}</button>
          <span class="wmp-note" data-send-status role="status">${esc(tr('ui.each_line_break_sends_an_enter_keystroke'))}</span></div>
      </details>`:`<p class="wmp-note wmp-terminal-hint">${esc(tr('ui.if_the_terminal_is_blank_press_enter'))}</p>`}`;
  }
  function mount(html) {
    if(!nativeForm){root.innerHTML=html;return;}
    // The panel stays in the service row. Its controls are owned by forms outside WHMCS's form.
    const template=document.createElement('template');
    template.innerHTML=html;
    const content=template.content, owners=[];
    const controls='button,input,select,textarea';
    for(const control of content.querySelectorAll(controls))control.setAttribute('form','');
    for(const form of content.querySelectorAll('form')){
      const section=document.createElement('div'), owner=document.createElement('form');
      owner.id=root.id+'-form-'+owners.length;
      for(const attr of form.attributes)section.setAttribute(attr.name,attr.value);
      section.dataset.formOwner=owner.id;
      for(const control of form.querySelectorAll(controls))control.setAttribute('form',owner.id);
      section.append(...form.childNodes);
      form.replaceWith(section);
      owners.push(owner);
    }
    formHost.replaceChildren(...owners);
    root.replaceChildren(content);
  }
  function render() {
    const menuOpen=!!root.querySelector('[data-power-menu][open]');
    const pointer=chartHover;
    hideChartHover(); charts.clear();
    root.dataset.lifecycle=state.lifecycle||''; root.dataset.power=metrics.status||'unknown';
    if(state.error) {mount(`<div class="wmp-notice wmp-error">${esc(state.error)}</div>`);return;}
    const running=metrics.status==='running', disabled=!canAct();
    const tabs=[['overview',tr('ui.overview')],['network',tr('ui.network')],['reinstall',tr('ui.reinstall_os')]];
    if((admin&&state.has_instance)||state.backups?.limit||state.backups?.items.length||tab==='backups')tabs.push(['backups',tr('ui.backup')]);
    if(state.addons?.items?.length||state.addons?.purchased?.length||state.addons?.pending||tab==='addons')tabs.push(['addons',tr('ui.add_ons')]);
    tabs.push(['history',tr('ui.tasks_logs_2')]);
    const panels={overview,network,reinstall,backups,addons,history};
    mount(`<div class="wmp-shell">
      <div class="wmp-head"><div><div class="wmp-title"><h2>${esc(state.hostname||state.product_name)}</h2>
        ${badge(pending()?'running':state.lifecycle==='active'?(running?'active':'stopped'):state.lifecycle,
          pending()?tr('ui.working'):state.lifecycle==='active'?(running?tr('ui.running'):metrics.status==='stopped'?tr('ui.stopped'):tr('ui.reading_status')):null)}</div>
        <div class="wmp-sub">${esc(state.server_name||tr('ui.cloud_server'))} · ${esc(state.kind==='qemu'?'KVM':(state.kind||'').toUpperCase())}
          · ${esc(state.image_name||tr('ui.awaiting_provisioning'))}</div></div>
      ${canOperate()?`<div class="wmp-toolbar">${consoleMenu(!disabled&&running&&state.console_available)}
        ${state.has_instance?`<button type="button" class="wmp-btn" data-reset-password ${disabled||(state.kind==='qemu'&&!running)?'disabled':''}>${esc(tr('ui.reset_password'))}</button>`:''}
        <details class="wmp-action-menu" data-power-menu ${menuOpen?'open':''}><summary class="wmp-btn">${esc(tr('ui.power_actions'))} ▾</summary>
          <div class="wmp-menu">${['start','shutdown','reboot','stop','reset'].map(action=>
            `<button type="button" data-action="${action}" ${disabled||(action==='start'?running:!running)?'disabled':''}>${actions[action]}</button>`).join('')}</div>
        </details></div>`:''}</div>
      ${error?`<div class="wmp-notice wmp-error" role="alert">${esc(error)}</div>`:''}${taskBox()}
      <nav class="wmp-tabs" role="tablist">${tabs.map(([id,label])=>
        `<button type="button" class="wmp-tab" role="tab" aria-selected="${tab===id}" data-tab="${id}">${label}</button>`).join('')}</nav>
      <div role="tabpanel">${(!state.spec?overview:panels[tab])()}</div>
      <div class="wmp-footer"><span>${esc(metrics.updated_at?tr('ui.last_sample')+new Date(metrics.updated_at*1000).toLocaleTimeString(locale):tr('ui.waiting_for_node_status'))}</span>
        ${!admin?`<a href="clientarea.php?action=cancel&amp;id=${state.service_id}">${esc(tr('ui.request_cancellation'))}</a>
          <a href="upgrade.php?type=package&amp;id=${state.service_id}">${esc(tr('ui.upgrade_plan'))}</a>`:''}
        <button type="button" class="wmp-copy" data-refresh>${esc(tr('ui.refresh_status'))}</button></div>
    </div>${dialog?panelDialog():''}`);
    if(pointer)showChartHover(document.elementFromPoint(pointer.x,pointer.y)?.closest('[data-chart]'),pointer.x,pointer.y);
  }
  function panelDialog() {
    const {console:consoleOpen,config,task,password,addon,action}=dialog;
    const content=consoleOpen?consoleContent():config?instanceConfig(config):task?taskDetails(task):
      password?resetPassword():`<p>${esc(dialog.text)}</p>`;
    return `<div class="wmp-modalback"><div class="wmp-modal ${consoleOpen||config?'wmp-wide':''}" role="dialog" aria-modal="true" aria-labelledby="wmp-dialog-title">
      <h3 id="wmp-dialog-title">${esc(dialog.title)}</h3>${content}
      ${addon&&addonNeedsRestart(addon)?`<p class="wmp-note">${addonRestartNote}</p>`:''}
      ${dialog.error?`<p class="wmp-notice wmp-error wmp-space" role="alert">${esc(dialog.error)}</p>`:''}
      ${addon?'<div class="wmp-notice wmp-error" data-addon-purchase-error role="alert" hidden></div>':''}
      <div class="wmp-modal-actions"><button type="button" class="wmp-btn" data-close>${consoleOpen?tr('ui.close_console'):task?tr('ui.close'):tr('ui.cancel')}</button>
        ${addon?`<button type="button" class="wmp-btn wmp-primary" data-addon-purchase-confirm>${esc(tr('ui.proceed_to_payment'))}</button>`:''}
        ${task?.can_retry?`<button type="button" class="wmp-btn wmp-primary" data-task-retry="${task.id}" ${busy?'disabled':''}>${esc(tr('ui.retry'))}</button>`:''}
        ${action?`<button type="button" class="wmp-btn wmp-primary ${['stop','reset','backup_restore','backup_delete'].includes(action)?'wmp-danger':''}" data-confirm="${action}">${esc(tr('ui.confirm_action',{action:actions[action]}))}</button>`:''}
      </div></div></div>`;
  }
  async function refresh(withMetrics=true) {
    if(refreshing||savingBackup)return;
    const revision=backupRevision;
    refreshing=true;
    let loaded=false;
    const before=JSON.stringify([state,metrics,error,dialog?.task]);
    try {
      const freshState=await api('overview');
      if(revision!==backupRevision)return;
      state=freshState; loaded=true;
      if(withMetrics&&state.has_instance) {
        const requested=timeframe;
        const fresh=await api('metrics',null,{timeframe:requested});
        if(requested===timeframe)metrics={...fresh,timeframe:requested};
      }
      const detail=dialog;
      if(detail?.task&&!busy) {
        const task=await api('task',null,{task_id:detail.task.id});
        if(dialog===detail)detail.task=task;
      }
    }
    catch(e){
      error=e.message;
      if(admin&&!loaded&&[401,403,404,409].includes(e.status)) {
        state={...state,error:e.message}; dialog=null; render();
      }
    }
    finally{refreshing=false;}
    if(dialog?.console||dialog?.password||dialog?.addon||dialog?.config||busy||savingBackup)return;
    const form=root.querySelector('[data-reinstall]');
    if(form && (busy||form.matches(':focus-within')||form.querySelector('[name=new_password]').value||form.querySelector('[name=confirm_password]').value
      || !form.querySelector('[data-reinstall-error]').hidden)){deferredRender=true;return;}
    if(deferredRender||before!==JSON.stringify([state,metrics,error,dialog?.task])){deferredRender=false;render();}
  }
  async function submit(action,payload={}) {
    if(!canOperate())return;
    busy=true; dialog=null;error='';render();
    try {const task=await api('action',{action,request_id:requestId(),...payload}); state.tasks=[task,...(state.tasks||[])];}
    catch(e){error=e.message;}
    busy=false;render();await refresh(false);
  }
  async function submitForm(form,section,action) {
    const editing=action==='edit_config';
    if(editing?!canEdit():!canAct())return;
    const fields=new FormData(form), data=Object.fromEntries(fields);
    if(editing)data.addresses=fields.getAll('addresses').join('\n');
    const notice=section.querySelector('[role=alert]');
    const button=section.querySelector('[type=submit]');
    notice.hidden=true;
    busy=true;button.disabled=true;
    try {
      const payload=editing?{...data,revision:dialog.config.config_revision}:{...data,action};
      const task=await api(editing?'instance_config.save':'action',{...payload,request_id:requestId()});
      state.tasks=[task,...(state.tasks||[])];
    } catch(e) {
      busy=false;button.disabled=editing?!canEdit():!canAct();
      notice.textContent=e.message;notice.hidden=false;notice.scrollIntoView({block:'nearest'});
      return;
    }
    busy=false;dialog=null;confirmation='';error='';render();await refresh(false);
  }
  async function showAdminDialog(type,options={}) {
    try {
      const config=type==='config';
      const detail=await api(config?'instance_config':'task',null,options);
      dialog={title:config?tr('ui.edit_vps_configuration'):tr('ui.task_details'),[type]:detail};error='';
    } catch(e) { error=e.message; }
    render();
  }
  async function retryAdminTask(id) {
    const detail=dialog;
    busy=true; detail.error=''; render();
    try {
      await api('task.retry',{task_id:id});
      detail.task=await api('task',null,{task_id:id});
    } catch(e) { detail.error=e.message; }
    finally { busy=false; render(); }
  }
  function stopConsoleLease(id=null){
    if(!consoleLease||(id&&consoleLease.id!==id))return;
    const lease=consoleLease;consoleLease=null;clearInterval(lease.timer);
    api('console.close',{connection_id:lease.id},{},true).catch(()=>{});
  }
  function startConsoleLease(connection){
    const lease={id:connection.connection_id,renewing:false,timer:null};consoleLease=lease;
    lease.timer=setInterval(async()=>{
      if(consoleLease!==lease||lease.renewing)return;
      lease.renewing=true;
      try{await api('console.renew',{connection_id:lease.id});}
      catch(failure){if(consoleLease===lease&&[401,403,404].includes(failure.status)){error=tr('ui.console_authorization_expired_reconnect_to_continue');closeConsole();}}
      finally{lease.renewing=false;}
    },10000);
  }
  function stopVncTyping(message='') {
    clearTimeout(sendTimer);
    sendTimer=null;
    const send=root.querySelector('[data-send-keys]');
    if(!send) return;
    send.disabled=!activeVnc;
    root.querySelector('[data-stop-keys]').hidden=true;
    if(message) root.querySelector('[data-send-status]').textContent=message;
  }
  function sendVncText() {
    const vnc=activeVnc;
    if(!vnc||sendTimer!==null) return;
    const text=root.querySelector('[data-console-text]').value.replace(/\r\n?/g,'\n');
    const status=root.querySelector('[data-send-status]');
    if(!text){status.textContent=tr('ui.enter_the_text_you_want_to_send');return;}
    if(/[\x00-\x08\x0b-\x1f\x7f-\x9f]/.test(text)){status.textContent=tr('ui.remove_invisible_control_characters');return;}
    const chars=[...text];
    let sent=0;
    root.querySelector('[data-send-keys]').disabled=true;
    root.querySelector('[data-stop-keys]').hidden=false;
    status.textContent=tr('ui.sending');
    function typeNext() {
      if(activeVnc!==vnc) return;
      const char=chars[sent++];
      const keysym=char==='\n' ? 0xff0d : char==='\t' ? 0xff09 : vnc.keys.lookup(char.codePointAt(0));
      vnc.rfb.sendKey(keysym);
      if(sent<chars.length) sendTimer=setTimeout(typeNext,100);
      else stopVncTyping(tr('ui.characters_sent',{count:sent}));
    }
    sendTimer=setTimeout(typeNext,0);
  }
  function closeConsole(){++consoleGeneration;consoleOpening=false;activeVnc=null;stopVncTyping();stopConsoleLease();socket?.close();socket=null;terminal?.dispose();terminal=null;if(ping)clearInterval(ping);ping=null;dialog=null;root.dataset.console='closed';render();}
  function loadScript(src){return new Promise((resolve,reject)=>{const e=document.createElement('script');e.src=src;e.onload=resolve;e.onerror=()=>reject(Error(tr('ui.could_not_load_the_console_component')));document.head.append(e);});}
  async function openConsole(mode){
    if(!canAct()||consoleOpening||dialog?.console)return;
    consoleOpening=true;const generation=++consoleGeneration;
    error='';
    root.querySelector('[data-console-menu]').open=false;
    try {
      const connection=await api('console',{mode});
      if(generation!==consoleGeneration){api('console.close',{connection_id:connection.connection_id},{},true).catch(()=>{});return;}
      startConsoleLease(connection);dialog={title:consoleNames[connection.mode]+' · '+state.hostname,console:true,mode:connection.mode};
      root.dataset.console='connecting';render();
      const target=document.getElementById('wmp-terminal');
      if(connection.mode==='novnc'){
        const [{default:RFB},{default:keys}]=await Promise.all([
          import(`${base}/assets/vendor/novnc/core/rfb.js`),
          import(`${base}/assets/vendor/novnc/core/input/keysymdef.js`),
        ]);
        if(generation!==consoleGeneration)return;
        target.textContent='';
        const rfb=new RFB(target,connection.url,{credentials:{password:connection.ticket}});terminal={dispose:()=>rfb.disconnect()};rfb.scaleViewport=true;
        const status=root.querySelector('[data-console-status]');
        rfb.addEventListener('connect',()=>{
          if(generation!==consoleGeneration)return;
          activeVnc={rfb,keys};root.dataset.console='connected';stopVncTyping();
        });
        rfb.addEventListener('securityfailure',()=>{
          if(generation!==consoleGeneration)return;
          root.dataset.console='failed';status.textContent=tr('ui.console_authentication_failed_reconnect_to_continue');
        });
        rfb.addEventListener('disconnect',event=>{
          stopConsoleLease(connection.connection_id);
          if(generation!==consoleGeneration)return;
          activeVnc=null;stopVncTyping(tr('ui.connection_lost'));
          if(root.dataset.console!=='failed'){
            root.dataset.console=event.detail.clean?'closed':'failed';
            status.textContent=event.detail.clean?tr('ui.connection_closed'):tr('ui.console_connection_failed_reconnect_to_continue');
          }
        });
        return;
      }
      if(!window.Terminal) await loadScript(`${base}/assets/vendor/xterm/lib/xterm.js`);
      if(generation!==consoleGeneration)return;
      target.textContent='';
      if(!document.querySelector('link[data-wmp-xterm]')){const l=document.createElement('link');l.rel='stylesheet';l.href=`${base}/assets/vendor/xterm/css/xterm.css`;l.dataset.wmpXterm='1';document.head.append(l);}
      terminal=new window.Terminal({cols:100,rows:25,cursorBlink:true,screenReaderMode:true,theme:{background:'#101727',foreground:'#e0e9fb'}});terminal.open(target);terminal.focus();
      const ws=new WebSocket(connection.url);socket=ws;ws.binaryType='arraybuffer';let ready=false;const decoder=new TextDecoder();
      ws.onopen=()=>ws.send(`${connection.user}:${connection.ticket}\n`);
      ws.onmessage=event=>{if(generation!==consoleGeneration)return;const text=typeof event.data==='string'?event.data:decoder.decode(event.data);if(!ready){if(text.startsWith('OK')){ready=true;root.dataset.console='connected';ws.send('1:100:25:');terminal.write(text.slice(2));terminal.onData(data=>{if(generation===consoleGeneration&&ws.readyState===1)ws.send(`0:${new TextEncoder().encode(data).length}:${data}`);});ping=setInterval(()=>{if(ws.readyState===1)ws.send('2');},20000);}else{terminal.write(text);root.dataset.console='failed';}}else terminal.write(text);};
      ws.onerror=()=>{if(generation===consoleGeneration){root.dataset.console='failed';terminal?.write(tr('ui.console_connection_failed_reconnect_to_continue_2'));}};
      ws.onclose=()=>{stopConsoleLease(connection.connection_id);if(generation!==consoleGeneration)return;if(ping)clearInterval(ping);ping=null;if(terminal)terminal.write(tr('ui.connection_closed_2'));if(root.dataset.console==='connected')root.dataset.console='closed';};
    }catch(e){if(generation===consoleGeneration){stopConsoleLease();error=e.message;dialog=null;render();}}
    finally{if(generation===consoleGeneration)consoleOpening=false;}
  }
  root.addEventListener('pointermove',e=>showChartHover(e.target.closest('[data-chart]'),e.clientX,e.clientY));
  root.addEventListener('pointerleave',hideChartHover);
  root.addEventListener('input',e=>{
    if(e.target.name==='confirm_hostname')confirmation=e.target.value;
    if(e.target.name==='addresses'){
      for(const cell of e.target.closest('[data-instance-ip]').querySelectorAll('[data-ip-meta]')){
        cell.textContent=e.target.value.trim()===e.target.defaultValue ? cell.dataset.ipMeta||'—' : '—';
      }
    }
  });
  root.addEventListener('change',e=>{
    if(e.target.name==='image_id')chosen=Number(e.target.value);
    if(e.target.name==='metric_range'){timeframe=e.target.value;refresh();}
    if(e.target.hasAttribute('data-auto-backup'))setAutomaticBackup(e.target);
  });
  formHost.addEventListener('submit',e=>{
    const section=nativeForm ? root.querySelector(`[data-form-owner="${e.target.id}"]`) : e.target;
    if(!section?.matches('[data-reinstall],[data-password],[data-instance-config]'))return;
    e.preventDefault();
    const action=section.hasAttribute('data-instance-config')?'edit_config':
      section.hasAttribute('data-password')?(state.kind==='lxc'?'lxc_password_reset':'password'):'reinstall';
    submitForm(e.target,section,action);
  });
  root.addEventListener('click',e=>{
    const b=e.target.closest('button');if(!b||b.disabled)return;
    if(b.dataset.tab){tab=b.dataset.tab;error='';render();}
    else if(b.hasAttribute('data-edit-config')&&canEdit())showAdminDialog('config');
    else if(b.hasAttribute('data-add-ip')&&!busy){
      const form=b.closest('[data-instance-config]'), rows=form.querySelector('tbody');
      rows.insertAdjacentHTML('beforeend',instanceIpRow({},form.dataset.formOwner));
      rows.lastElementChild.querySelector('input').focus();
    }
    else if(b.hasAttribute('data-remove-ip')&&!busy)b.closest('[data-instance-ip]').remove();
    else if(b.dataset.taskDetail&&admin)showAdminDialog('task',{task_id:b.dataset.taskDetail});
    else if(b.dataset.taskRetry&&admin&&!busy)retryAdminTask(Number(b.dataset.taskRetry));
    else if(b.hasAttribute('data-reset-password')){dialog={password:true,title:tr('ui.reset_password')};render();}
    else if(b.dataset.buyAddon&&canPurchase()) {
      const offer=state.addons.items.find(item=>item.id===Number(b.dataset.buyAddon)&&(item.cycle||'')===b.dataset.addonCycle);
      if(!offer||offer.reason||state.addons.pending)return;
      const price=offer.price+(offer.taxed?tr('ui.excl_tax'):'');
      const billing=offer.cycle?tr('ui.recurring_purchase_price',{price,cycle:terms[offer.cycle]}):tr('ui.one_time_purchase_price',{price});
      const start=offer.starts_billing?tr('ui.after_provisioning_this_vps_will_renew_on'):'';
      const restart=offer.kind.startsWith('ipv')?tr('ui.applying_ip_changes_briefly_shuts_down_and'):'';
      dialog={addon:offer,title:state.addons.on_behalf?tr('ui.buy_on_behalf_of_the_customer')+offer.name:offer.name,text:[addonEffect(offer),billing,start,restart].filter(Boolean).join(' ')};render();
    }
    else if(b.hasAttribute('data-addon-purchase-confirm')&&canPurchase())purchaseAddon(b);
    else if(b.dataset.action){
      const a=b.dataset.action;
      if(a==='reinstall')return;
      if(['start','shutdown','reboot','backup'].includes(a)){submit(a);return;}
      const text={backup_restore:tr('ui.restoring_overwrites_the_system_and_data_disks'),backup_delete:tr('ui.this_backup_will_no_longer_be_available')};
      dialog={action:a,title:actions[a],text:text[a]||tr('ui.forcing_this_action_may_discard_unsaved_data'),backupId:b.dataset.backupId};
      render();
    }
    else if(b.dataset.confirm)submit(b.dataset.confirm,dialog?.backupId?{backup_id:Number(dialog.backupId)}:{});
    else if(b.hasAttribute('data-close'))closeConsole();
    else if(b.hasAttribute('data-refresh'))refresh();
    else if(b.dataset.console)openConsole(b.dataset.console);
    else if(b.hasAttribute('data-send-keys'))sendVncText();
    else if(b.hasAttribute('data-stop-keys'))stopVncTyping(tr('ui.sending_stopped'));
    else if(b.dataset.copy)navigator.clipboard?.writeText(b.dataset.copy).catch(()=>{});
  });
  document.addEventListener('keydown',e=>{if(e.key==='Escape'&&dialog)closeConsole();});
  window.addEventListener('pagehide',()=>{if(consoleLease)closeConsole();});
  render();
  if(shortcut==='config'&&canEdit()){tab='network';showAdminDialog('config');}
  if(admin&&location.hash==='#whmpve-console')root.scrollIntoView();
  refresh();setInterval(()=>{if(!document.hidden&&!dialog?.console)refresh(!pending());},3000);
})();
