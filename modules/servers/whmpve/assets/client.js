(() => {
  'use strict';
  const root=document.getElementById('whmpve-console'); if(!root) return;
  const base=root.dataset.base, token=root.dataset.token;
  const admin=root.dataset.scope==='admin', shortcut=admin ? root.dataset.tab : '';
  const nativeForm=admin ? root.closest('form') : null;
  const formHost=nativeForm ? document.body.appendChild(document.createElement('div')) : root;
  if(nativeForm)formHost.hidden=true;
  let state=JSON.parse(root.dataset.state), metrics={}, busy=false, error='', tab=['network','reinstall','backups','history'].includes(shortcut)?shortcut:'overview';
  let dialog=null, chosen=null, confirmation='', terminal=null, socket=null, ping=null, deferredRender=false;
  let consoleLease=null, consoleGeneration=0, consoleOpening=false;
  let activeVnc=null, sendTimer=null, savingBackup=false, backupRevision=0;
  const consoleNames={xterm:'xterm.js',novnc:'noVNC'};
  const ranges={hour:[3600,'最近 1 小时'],day:[86400,'最近 24 小时'],week:[604800,'最近 7 天'],month:[2678400,'最近 1 个月']};
  const seriesColors=['#245cff','#078d80','#a55a25'];
  const charts=new Map();
  let chartHover=null;
  const labels={pending:'等待开通',provisioning:'开通中',active:'正常',suspended:'已暂停',terminated:'已销毁',queued:'等待执行',running:'执行中',success:'成功',failed:'失败',stopped:'已关机',missing:'实例缺失',absent:'等待开通'};
  const actions={start:'开机',shutdown:'正常关机',reboot:'重启',stop:'强制关机',reset:'强制重启',reinstall:'重装系统',create:'开通',suspend:'暂停',unsuspend:'恢复',terminate:'销毁',change_package:'变更套餐',edit_config:'调整配置',traffic:'流量处理',traffic_reset:'流量重置',addon_apply:'附加服务开通',password:'重置密码',lxc_password_reset:'重置密码',backup:'创建备份',backup_restore:'恢复备份',backup_delete:'删除备份'};
  const terms={Monthly:'每月',Quarterly:'每季','Semi-Annually':'每半年',Annually:'每年',Biennially:'每两年',Triennially:'每三年'};
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const requestId=()=>Array.from(crypto.getRandomValues(new Uint8Array(16)),x=>x.toString(16).padStart(2,'0')).join('');
  let timeframe='hour', refreshing=false;
  const size=n=>n===null||n===undefined?'等待采集':n>=1073741824?(n/1073741824).toFixed(2)+' GiB':(n/1048576).toFixed(1)+' MiB';
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
    const response=await fetch(`${base}/api.php?${query}`,{method:body?'POST':'GET',credentials:'same-origin',keepalive,headers:body?{'Content-Type':'application/json','X-Whmpve-Token':token}:{},body:body?JSON.stringify({...body,service_id:state.service_id}):null});
    let data; try{data=await response.json();}catch{throw Error('登录状态可能已失效，请刷新页面');}
    if(!response.ok||!data.ok){const failure=Error(data.error||'请求失败');failure.status=response.status;throw failure;} return data.data;
  }
  const number=new Intl.NumberFormat('zh-CN',{maximumFractionDigits:3}).format;
  const tickNumber=new Intl.NumberFormat('zh-CN',{maximumFractionDigits:4}).format;
  const hoverNumber=new Intl.NumberFormat('zh-CN',{maximumSignificantDigits:6}).format;
  const hoverTime=new Intl.DateTimeFormat('zh-CN',{year:'numeric',month:'2-digit',day:'2-digit',
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
    const dates=new Intl.DateTimeFormat('zh-CN',timeframe==='hour' ? clock
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
            aria-label="${title}时间曲线">${horizontal}${vertical}${traces}</svg>
          ${hasData?`<div class="wmp-plot-hover" hidden><span class="wmp-plot-guide"></span><div class="wmp-plot-tooltip"></div></div>`:
            '<span class="wmp-plot-empty">暂无数据</span>'}</div>
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
        ${point.usage?`<div><span>用量</span><strong>${esc(point.usage)}</strong></div>`:''}`;
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
    return `<section class="wmp-monitor"><div class="wmp-panel-title"><h3>资源监控</h3>
      <label class="wmp-inline">时间范围 <select name="metric_range" class="wmp-btn">${Object.entries(ranges).map(([value,[,label]])=>`<option value="${value}" ${timeframe===value?'selected':''}>${label}</option>`).join('')}</select></label></div>
      <div class="wmp-grid">
        ${metricChart('CPU 使用率',[['CPU',p=>sample(p,'cpu',100)]],'%',100)}
        ${metricChart('内存使用率',[['内存',p=>percent(p,'memory','max_memory')]],'%',100,memoryUsage)}
        ${metricChart('网络速率',[['入站',p=>sample(p,'netin',8/1e6)],['出站',p=>sample(p,'netout',8/1e6)]],'Mbps')}
        ${metricChart('磁盘 I/O',[['读取',p=>sample(p,'diskread',1/1048576)],['写入',p=>sample(p,'diskwrite',1/1048576)]],'MiB/s')}
      </div>
      ${load?`<section class="wmp-card"><h3>平均负载</h3><dl class="wmp-load-values">${[1,5,15].map((minutes,index)=>
        `<div><dt>${minutes} 分钟</dt><dd>${load[index].toFixed(2)}</dd></div>`).join('')}</dl></section>`:''}</section>`;
  }
  function taskBox() {
    const t=pending(); if(!t) return '';
    return `<div class="wmp-task" role="status" data-task-id="${t.id}"><div class="wmp-task-line"><strong>${esc(actions[t.action]||t.action)} · #${t.id}</strong>${badge(t.status)}</div><div class="wmp-progress"><span style="width:${Math.max(4,(t.stage_number||0)/(t.stage_total||1)*100)}%"></span></div><div class="wmp-task-line"><span>${esc(t.stage)}</span><small>阶段 ${t.stage_number} / ${t.stage_total}</small></div></div>`;
  }
  function connectionRows() {
    const display=state.connection_display;
    if(!display)return '';
    return (display.public_ip?row('公网入口 IP',esc(display.public_ip)):'')+
      (display.port_note?row('分配的端口',`<span class="wmp-port-note">${esc(display.port_note)}</span>`):'');
  }
  function overview() {
    const s=state.spec;
    if(!s) return '<div class="wmp-card wmp-empty">等待开通</div>';
    const card=(title,value,note='')=>`<div class="wmp-card"><div class="wmp-label">${title}</div><div class="wmp-value">${value}</div>${note ? '<div class="wmp-note">'+note+'</div>' : ''}</div>`;
    const addresses=(state.ips||[]).map(ip=>`${esc(ip.address)}<button type="button" class="wmp-copy" data-copy="${esc(ip.address)}" aria-label="复制 IP">复制</button>`).join('<br>');
    return `<div class="wmp-cards">
      ${card('CPU',s.cores+' vCPU',metrics.cpu==null ? '等待采集' : '当前使用 '+(metrics.cpu*100).toFixed(1)+'%')}
      ${card('内存',s.memory_mib>=1024 ? s.memory_mib/1024+' GiB' : s.memory_mib+' MiB','已用 '+size(metrics.memory)+(s.swap_mib ? ' · Swap '+s.swap_mib+' MiB' : ''))}
      ${card('系统盘',s.disk_gib+' GiB')}
      ${(s.data_disks || []).map(disk=>card('数据盘 '+Number(disk.id),Number(disk.size_gib)+' GiB',esc(disk.mountpoint))).join('')}
      ${card('网络限速',s.bandwidth_mbps ? s.bandwidth_mbps+' Mbps' : '不限速')}
      </div>${monitoring()}${trafficBox()}<div class="wmp-grid">
      <div class="wmp-card"><h3>连接信息</h3>${row(state.connection_display?'内网 IP':'IP 地址',addresses||'未分配')}${connectionRows()}${row('登录用户','root')}${row('操作系统',esc(state.image_name))}${row('网络模式',state.network_mode==='none'?'未配置网卡':'静态网络')}</div>
      <div class="wmp-card"><h3>服务信息</h3>${row('服务编号','#'+state.service_id)}${row('服务状态',badge(state.lifecycle))}
        ${admin?row('VMID',state.vmid||'—')+row('PVE 节点',esc(state.node||'—')):row('付费周期',esc(state.billing?.cycle))+row('续费金额',esc(state.billing?.amount))+row('到期时间',esc(state.billing?.next_due))}
        ${row('Plan',esc(state.plan_name))}</div></div>`;
  }
  function trafficBox() {
    const t=state.traffic;
    if (!t) return '';
    const used=(t.incoming+t.outgoing)/1e9, percent=t.limit_gb ? Math.min(100,used/t.limit_gb*100) : 0;
    const status=t.restriction==='throttled' ? `已限速至 ${t.speed_kbps} Kbps` : t.restriction==='suspended' ? '流量超额，已暂停' : '';
    return `<div class="wmp-card wmp-space" data-traffic><div class="wmp-panel-title"><h3>本期流量</h3><strong>${used.toFixed(3)} GB / ${t.limit_gb ? t.limit_gb+' GB' : '不限流量'}</strong></div>
      <div class="wmp-progress"><span style="width:${percent}%"></span></div><div class="wmp-task-line"><small>入站 ${(t.incoming/1e9).toFixed(3)} GB · 出站 ${(t.outgoing/1e9).toFixed(3)} GB</small><small>下次重置 ${new Date(t.reset_at*1000).toLocaleString('zh-CN')}</small></div>
      ${status ? '<div class="wmp-note">'+status+'</div>' : ''}</div>`;
  }
  function invoiceUrl(id) {return new URL(`../../../viewinvoice.php?id=${Number(id)}`,base+'/').href;}
  function addonEffect({kind,quantity}) {
    const descriptions={traffic_reset:`恢复额度的 ${quantity}%`,cores:`+${quantity} 核 CPU`,memory_mib:`+${quantity} MiB 内存`,
      ipv4:`+${quantity} 个 IPv4`,ipv6:`+${quantity} 个 IPv6`};
    return descriptions[kind];
  }
  const addonRestartNote='更改配置后需要重新启动vps才能生效';
  const addonNeedsRestart=({kind})=>state.kind==='qemu'&&['cores','memory_mib'].includes(kind);
  function addons() {
    const {items=[],pending:invoice,purchased=[]}=state.addons||{};
    return `<section class="wmp-card"><div class="wmp-panel-title"><h3>附加服务</h3>
      ${invoice&&canPurchase()?`<a class="wmp-btn" href="${esc(invoiceUrl(invoice.invoice_id))}">${invoice.paid?'查看已付款账单':'支付附加服务账单'}</a>`:''}</div>
      ${items.length?`<div class="wmp-table-wrap"><table><thead><tr><th>名称</th><th>效果</th><th>价格</th><th>操作</th></tr></thead>
        <tbody>${items.map(item=>`<tr><td>${esc(item.name)}</td><td>${esc(addonEffect(item))}</td>
        <td>${item.price?esc(item.price)+' · '+(item.kind==='traffic_reset'?'一次性':terms[item.cycle]||'—'):'—'}</td><td>${item.reason?esc(item.reason):canPurchase()?
          `<button type="button" class="wmp-btn" data-buy-addon="${item.id}" data-addon-cycle="${esc(item.cycle)}" ${invoice?'disabled':''}>购买</button>`:'—'}</td></tr>`).join('')}</tbody></table></div>`:''}
      ${items.some(addonNeedsRestart)?`<p class="wmp-note">${addonRestartNote}</p>`:''}
      ${items.length&&!admin&&!canPurchase()?`<p class="wmp-note">${state.addons.on_behalf?'当前管理员无权代客户购买。':'请使用有服务管理和付款权限的客户账号购买。'}</p>`:''}
      ${purchased.length?`<h3 class="wmp-space">已加购</h3>${purchased.map(item=>row(item.name,esc(item.price)+' · '+(terms[item.cycle]||esc(item.cycle)))).join('')}`:''}</section>`;
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
    return `<div class="wmp-card"><div class="wmp-panel-title"><h3>网络配置</h3>
      ${admin&&state.admin_permissions?.edit?`<button type="button" class="wmp-btn" data-edit-config ${canEdit()?'':'disabled'}>编辑 VPS 配置</button>`:''}</div>${state.network_mode==='none' ?
      '<div class="wmp-empty">未配置网卡</div>' :
      state.ips.map(ip => `${row((state.connection_display?'内网 ':'')+(ip.address.includes(':')?'IPv6':'IPv4'),esc(ip.address)+'/'+ip.prefix)}${row('网关',esc(ip.gateway||'—'))}`).join('')}
      ${connectionRows()}${row('带宽',state.spec.bandwidth_mbps ? state.spec.bandwidth_mbps+' Mbps' : '不限速')}</div>${trafficBox()}`;
  }
  function backups() {
    const {items,limit,automatic_allowed,automatic_enabled}=state.backups;
    const canSetAutomatic=canOperate()&&state.module_enabled&&state.has_instance&&['active','suspended'].includes(state.lifecycle)
      && ['Active','Suspended'].includes(state.service_status);
    return `<section class="wmp-card"><div class="wmp-panel-title"><div class="wmp-inline"><h3>备份</h3><small class="wmp-muted">${items.length} / ${limit}</small></div>
      <button type="button" class="wmp-btn wmp-primary" data-action="backup" ${!canAct()||items.length>=limit?'disabled':''}>创建备份</button></div>
      ${admin&&automatic_allowed?`<p class="wmp-note">自动备份 ${automatic_enabled?'已开启':'已关闭'}</p>`:''}
      ${!admin&&automatic_allowed&&canSetAutomatic?`<label class="wmp-inline wmp-space"><input type="checkbox" data-auto-backup
        ${automatic_enabled?'checked':''} ${savingBackup?'disabled':''}>启用自动备份（每天 03:00）</label>`:''}
      <div class="wmp-table-wrap wmp-space"><table><thead><tr><th>创建时间</th><th>类型</th><th>大小</th><th>操作</th></tr></thead><tbody>
      ${items.map(item=>`<tr><td>${esc(new Date(item.created_at.replace(' ','T')+'Z').toLocaleString('zh-CN'))}</td><td>${item.automatic?'自动':'手动'}</td><td>${size(item.size)}</td><td><div class="wmp-toolbar">
        <button type="button" class="wmp-btn" data-action="backup_restore" data-backup-id="${item.id}" ${canAct()?'':'disabled'}>恢复</button>
        <button type="button" class="wmp-btn" data-action="backup_delete" data-backup-id="${item.id}" ${!canOperate()||busy||pending()?'disabled':''}>删除</button></div></td></tr>`).join('')||'<tr><td colspan="4">暂无备份</td></tr>'}</tbody></table></div></section>`;
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
    return `<div class="wmp-grid wmp-reinstall-fields"><label class="wmp-field">新 root 密码<input type="password" name="new_password" autocomplete="new-password" minlength="5" ${attributes}>${optional?'<small>留空沿用原密码</small>':''}</label>
      <label class="wmp-field">确认新密码<input type="password" name="confirm_password" autocomplete="new-password" ${attributes}></label></div>`;
  }
  function resetPassword() {
    const downtime=state.kind==='lxc'?'<label class="wmp-field wmp-check wmp-space"><span>确认重置期间会停机</span><input type="checkbox" name="confirm_downtime" value="1" required></label>':'';
    return `<form data-password><div class="wmp-notice wmp-error" role="alert" hidden></div>${passwordFields(false,false)}${downtime}
      <button type="submit" class="wmp-btn wmp-primary">重置密码</button></form>`;
  }
  function reinstall() {
    if(!chosen) chosen=state.image_id;
    const disabled=!canAct() || !state.images?.length;
    return `<form class="wmp-card" data-reinstall><h3>重装系统</h3>
      <div class="wmp-notice wmp-warn wmp-space">重装将清空系统盘${state.spec.data_disks?.length ? '，保留数据盘' : ''}，请确保已备份重要数据。</div>
      <div class="wmp-notice wmp-error" data-reinstall-error role="alert" hidden></div>
      <div class="wmp-image-grid">${(state.images||[]).map(i=>`<label class="wmp-image"><input type="radio" name="image_id" value="${i.id}" ${Number(chosen)===Number(i.id)?'checked':''} ${disabled?'disabled':''} required>${esc(i.name)}${i.description?`<span class="wmp-note wmp-image-description">${esc(i.description)}</span>`:''}</label>`).join('')}</div>
      ${passwordFields(disabled,true)}
      <label class="wmp-field">输入主机名 ${esc(state.hostname)} 以确认<input name="confirm_hostname" autocomplete="off" value="${esc(confirmation)}" placeholder="完整主机名" ${disabled?'disabled':''} required></label>
      <div class="wmp-modal-actions"><button type="submit" class="wmp-btn wmp-danger wmp-primary" data-action="reinstall" ${disabled?'disabled':''}>确认清空并重装</button></div></form>`;
  }
  function history() {
    return `<div class="wmp-card"><h3>任务/日志</h3><div class="wmp-table-wrap wmp-space"><table><thead><tr><th>任务</th><th>状态</th><th>时间</th>
      ${admin?'<th>发起人</th><th>操作</th>':''}</tr></thead><tbody>${(state.tasks||[]).map(t=>`<tr><td>#${t.id} ${esc(actions[t.action]||t.action)}</td>
        <td>${badge(t.status)}</td><td>${esc(taskTime(t.updated_at))}</td>${admin?`<td>${esc(taskActor(t.actor))}</td>
        <td><button type="button" class="wmp-btn" data-task-detail="${t.id}">查看</button></td>`:''}</tr>`).join('')
        ||`<tr><td colspan="${admin?5:3}">暂无任务</td></tr>`}</tbody></table></div></div>`;
  }
  const taskTime=value=>value?new Date(value.replace(' ','T')+'Z').toLocaleString('zh-CN',{timeZone:'Asia/Shanghai',hour12:false}):'—';
  function taskActor(actor='') {
    if(actor.startsWith('admin:'))return '管理员 #'+actor.slice(6);
    if(actor.startsWith('client:'))return '客户 #'+actor.slice(7);
    return ({module:'WHMCS',payment:'付款','backup-scheduler':'自动备份',traffic:'流量处理'})[actor]||'系统';
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
      <input name="addresses" type="text" aria-label="IP 地址" value="${esc(ip.address)}" maxlength="45"
        autocomplete="off" spellcheck="false" required ${form}></label></td>
      ${[ip.prefix,ip.gateway].map(value=>`<td data-ip-meta="${esc(value??'')}">${esc(value??'—')||'—'}</td>`).join('')}
      <td><button type="button" class="wmp-btn wmp-danger" data-remove-ip ${form}>删除</button></td></tr>`;
  }
  function instanceConfig(instance) {
    const spec=instance.spec, net=spec.network||{}, kvm=instance.kind==='qemu';
    const field=(label,name,value,type='number',help='')=>`<label class="wmp-field">${label}
      <input type="${type}" name="${name}" value="${esc(value)}" ${type==='number'?'min="0" step="any"':''}>
      ${help?`<small>${esc(help)}</small>`:''}</label>`;
    const inherit='留空沿用 IP 池设置';
    const optional=key=>net[key]===''?'-':net[key];
    return `<form data-instance-config><div class="wmp-notice wmp-error" data-error role="alert" hidden></div>
      <div class="wmp-form">
        ${field('CPU 核数','cores',spec.cores)}${field('CPU 上限（核）','cpu_limit',spec.cpu_limit??spec.cores)}
        ${field('内存（MiB）','memory_mib',spec.memory_mib,'number',kvm?'调整可能需要关机再开机':'')}
        ${kvm?'':field('交换内存 Swap（MiB）','swap_mib',spec.swap_mib??0)}
        ${field('系统盘（GiB）','disk_gib',spec.disk_gib,'number','填写扩容后的总容量')}
        ${(spec.data_disks||[]).map(disk=>field('数据盘 '+Number(disk.id)+'（GiB）','disk_size_'+Number(disk.id),disk.size_gib,'number',disk.mountpoint)).join('')}
        ${field('带宽（Mbps）','bandwidth_mbps',spec.bandwidth_mbps,'number','0 表示不限速')}
        ${field('月流量额度（GB）','traffic_gb',spec.traffic_gb??0,'number','0 表示不限流量')}
        <section class="wmp-full wmp-form-section"><div class="wmp-panel-title"><strong>网络</strong>
          <button type="button" class="wmp-btn" data-add-ip>添加 IP 地址</button></div><div class="wmp-form">
          <div class="wmp-full"><div class="wmp-table-wrap wmp-ip-editor"><table><thead><tr>
            <th>IP 地址</th><th>前缀长度</th><th>网关</th><th>操作</th></tr></thead>
            <tbody>${instance.ips.map(ip=>instanceIpRow(ip)).join('')}</tbody></table></div>
            <p class="wmp-note">填写本服务器 IP 池中的地址。修改 IP 或网卡前请关机。</p></div>
          ${field('网桥','bridge',net.bridge,'text',inherit)}${field('VLAN','vlan',net.vlan,'number','留空沿用 IP 池设置，0 表示无标签')}
          ${field('IPv4 前缀长度','prefix4',net.prefix4,'number',inherit)}${field('IPv4 网关','gateway4',optional('gateway4'),'text','填 - 清除网关，留空沿用 IP 池设置')}
          ${field('IPv6 前缀长度','prefix6',net.prefix6,'number',inherit)}${field('IPv6 网关','gateway6',optional('gateway6'),'text','填 - 清除网关，留空沿用 IP 池设置')}
          ${field('MTU','mtu',net.mtu,'number',inherit)}${field('DNS','dns',optional('dns'),'text','多个地址用空格分隔，填 - 清除')}
        </div></section>
      </div><div class="wmp-modal-actions"><button class="wmp-btn wmp-primary" type="submit">保存</button></div></form>`;
  }
  function consoleMenu(available) {
    if(!available) return '<button type="button" class="wmp-btn wmp-primary" disabled>打开控制台 ▾</button>';
    const modes=state.kind==='qemu' ? ['novnc','xterm'] : ['xterm','novnc'];
    const open=root.querySelector('[data-console-menu]')?.open && !dialog;
    return `<details class="wmp-action-menu" data-console-menu ${open?'open':''}>
      <summary class="wmp-btn wmp-primary">打开控制台 ▾</summary>
      <div class="wmp-menu">${modes.map(mode=>`<button type="button" data-console="${mode}">${consoleNames[mode]}</button>`).join('')}</div>
    </details>`;
  }
  function consoleContent() {
    return `<div id="wmp-terminal" class="wmp-terminal">连接中…</div>
      <p class="wmp-note" data-console-status role="status"></p>
      ${dialog.mode==='novnc'?`<details class="wmp-console-send" data-console-send>
        <summary class="wmp-btn">发送到虚拟机</summary>
        <label class="wmp-field">输入要发送的内容<textarea rows="3" data-console-text spellcheck="false" autocomplete="off"></textarea></label>
        <div class="wmp-inline"><button type="button" class="wmp-btn wmp-primary" data-send-keys disabled>发送</button>
          <button type="button" class="wmp-btn" data-stop-keys hidden>停止发送</button>
          <span class="wmp-note" data-send-status role="status">换行会发送回车。</span></div>
      </details>`:'<p class="wmp-note wmp-terminal-hint">如果终端无输出请按回车激活终端</p>'}`;
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
    const tabs=[['overview','概览'],['network','网络'],['reinstall','重装系统']];
    if((admin&&state.has_instance)||state.backups?.limit||state.backups?.items.length||tab==='backups')tabs.push(['backups','备份']);
    if(state.addons?.items?.length||state.addons?.purchased?.length||state.addons?.pending||tab==='addons')tabs.push(['addons','附加服务']);
    tabs.push(['history','任务/日志']);
    const panels={overview,network,reinstall,backups,addons,history};
    mount(`<div class="wmp-shell">
      <div class="wmp-head"><div><div class="wmp-title"><h2>${esc(state.hostname||state.product_name)}</h2>
        ${badge(pending()?'running':state.lifecycle==='active'?(running?'active':'stopped'):state.lifecycle,
          pending()?'操作中':state.lifecycle==='active'?(running?'运行中':metrics.status==='stopped'?'已关机':'读取状态中'):null)}</div>
        <div class="wmp-sub">${esc(state.server_name||'云服务器')} · ${esc(state.kind==='qemu'?'KVM':(state.kind||'').toUpperCase())}
          · ${esc(state.image_name||'等待开通')}</div></div>
      ${canOperate()?`<div class="wmp-toolbar">${consoleMenu(!disabled&&running&&state.console_available)}
        ${state.has_instance?`<button type="button" class="wmp-btn" data-reset-password ${disabled||(state.kind==='qemu'&&!running)?'disabled':''}>重置密码</button>`:''}
        <details class="wmp-action-menu" data-power-menu ${menuOpen?'open':''}><summary class="wmp-btn">电源操作 ▾</summary>
          <div class="wmp-menu">${['start','shutdown','reboot','stop','reset'].map(action=>
            `<button type="button" data-action="${action}" ${disabled||(action==='start'?running:!running)?'disabled':''}>${actions[action]}</button>`).join('')}</div>
        </details></div>`:''}</div>
      ${error?`<div class="wmp-notice wmp-error" role="alert">${esc(error)}</div>`:''}${taskBox()}
      <nav class="wmp-tabs" role="tablist">${tabs.map(([id,label])=>
        `<button type="button" class="wmp-tab" role="tab" aria-selected="${tab===id}" data-tab="${id}">${label}</button>`).join('')}</nav>
      <div role="tabpanel">${(!state.spec?overview:panels[tab])()}</div>
      <div class="wmp-footer"><span>${metrics.updated_at?'最近采集 '+new Date(metrics.updated_at*1000).toLocaleTimeString():'等待节点状态'}</span>
        ${!admin?`<a href="clientarea.php?action=cancel&amp;id=${state.service_id}">申请取消服务</a>
          <a href="upgrade.php?type=package&amp;id=${state.service_id}">升级套餐</a>`:''}
        <button type="button" class="wmp-copy" data-refresh>刷新状态</button></div>
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
      <div class="wmp-modal-actions"><button type="button" class="wmp-btn" data-close>${consoleOpen?'关闭控制台':task?'关闭':'取消'}</button>
        ${addon?'<button type="button" class="wmp-btn wmp-primary" data-addon-purchase-confirm>去付款</button>':''}
        ${task?.can_retry?`<button type="button" class="wmp-btn wmp-primary" data-task-retry="${task.id}" ${busy?'disabled':''}>重试</button>`:''}
        ${action?`<button type="button" class="wmp-btn wmp-primary ${['stop','reset','backup_restore','backup_delete'].includes(action)?'wmp-danger':''}" data-confirm="${action}">确认${actions[action]}</button>`:''}
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
      dialog={title:config?'编辑 VPS 配置':'任务详情',[type]:detail};error='';
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
      catch(failure){if(consoleLease===lease&&[401,403,404].includes(failure.status)){error='控制台授权已失效，请重新连接。';closeConsole();}}
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
    if(!text){status.textContent='请输入要发送的内容。';return;}
    if(/[\x00-\x08\x0b-\x1f\x7f-\x9f]/.test(text)){status.textContent='请移除不可见的控制字符。';return;}
    const chars=[...text];
    let sent=0;
    root.querySelector('[data-send-keys]').disabled=true;
    root.querySelector('[data-stop-keys]').hidden=false;
    status.textContent='正在发送…';
    function typeNext() {
      if(activeVnc!==vnc) return;
      const char=chars[sent++];
      const keysym=char==='\n' ? 0xff0d : char==='\t' ? 0xff09 : vnc.keys.lookup(char.codePointAt(0));
      vnc.rfb.sendKey(keysym);
      if(sent<chars.length) sendTimer=setTimeout(typeNext,100);
      else stopVncTyping(`已发送 ${sent} 个字符`);
    }
    sendTimer=setTimeout(typeNext,0);
  }
  function closeConsole(){++consoleGeneration;consoleOpening=false;activeVnc=null;stopVncTyping();stopConsoleLease();socket?.close();socket=null;terminal?.dispose();terminal=null;if(ping)clearInterval(ping);ping=null;dialog=null;root.dataset.console='closed';render();}
  function loadScript(src){return new Promise((resolve,reject)=>{const e=document.createElement('script');e.src=src;e.onload=resolve;e.onerror=()=>reject(Error('控制台组件加载失败'));document.head.append(e);});}
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
          root.dataset.console='failed';status.textContent='控制台认证失败，请重新连接。';
        });
        rfb.addEventListener('disconnect',event=>{
          stopConsoleLease(connection.connection_id);
          if(generation!==consoleGeneration)return;
          activeVnc=null;stopVncTyping('连接已断开。');
          if(root.dataset.console!=='failed'){
            root.dataset.console=event.detail.clean?'closed':'failed';
            status.textContent=event.detail.clean?'连接已关闭。':'控制台连接失败，请重新连接。';
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
      ws.onerror=()=>{if(generation===consoleGeneration){root.dataset.console='failed';terminal?.write('\r\n控制台连接失败，请重新连接。');}};
      ws.onclose=()=>{stopConsoleLease(connection.connection_id);if(generation!==consoleGeneration)return;if(ping)clearInterval(ping);ping=null;if(terminal)terminal.write('\r\n连接已关闭。');if(root.dataset.console==='connected')root.dataset.console='closed';};
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
    else if(b.hasAttribute('data-reset-password')){dialog={password:true,title:'重置密码'};render();}
    else if(b.dataset.buyAddon&&canPurchase()) {
      const offer=state.addons.items.find(item=>item.id===Number(b.dataset.buyAddon)&&(item.cycle||'')===b.dataset.addonCycle);
      if(!offer||offer.reason||state.addons.pending)return;
      const price=offer.price+(offer.taxed?'（未税）':'');
      const billing=offer.cycle?`本次 ${price}，以后${terms[offer.cycle]}续费增加 ${price}。`:`一次性支付 ${price}。`;
      const start=offer.starts_billing?'开通后，此 VPS 将按所选账期续费。':'';
      const restart=offer.kind.startsWith('ipv')?'应用 IP 时，运行中的 VPS 会短暂关机并重新启动。':'';
      dialog={addon:offer,title:state.addons.on_behalf?'代客户购买 · '+offer.name:offer.name,text:`${addonEffect(offer)}。${billing}${start}${restart}`};render();
    }
    else if(b.hasAttribute('data-addon-purchase-confirm')&&canPurchase())purchaseAddon(b);
    else if(b.dataset.action){
      const a=b.dataset.action;
      if(a==='reinstall')return;
      if(['start','shutdown','reboot','backup'].includes(a)){submit(a);return;}
      const text={backup_restore:'恢复会覆盖系统盘和数据盘。',backup_delete:'删除后无法使用这份备份。'};
      dialog={action:a,title:actions[a],text:text[a]||'强制操作可能导致未保存的数据丢失，请确认。',backupId:b.dataset.backupId};
      render();
    }
    else if(b.dataset.confirm)submit(b.dataset.confirm,dialog?.backupId?{backup_id:Number(dialog.backupId)}:{});
    else if(b.hasAttribute('data-close'))closeConsole();
    else if(b.hasAttribute('data-refresh'))refresh();
    else if(b.dataset.console)openConsole(b.dataset.console);
    else if(b.hasAttribute('data-send-keys'))sendVncText();
    else if(b.hasAttribute('data-stop-keys'))stopVncTyping('已停止发送。');
    else if(b.dataset.copy)navigator.clipboard?.writeText(b.dataset.copy).catch(()=>{});
  });
  document.addEventListener('keydown',e=>{if(e.key==='Escape'&&dialog)closeConsole();});
  window.addEventListener('pagehide',()=>{if(consoleLease)closeConsole();});
  render();
  if(shortcut==='config'&&canEdit()){tab='network';showAdminDialog('config');}
  if(admin&&location.hash==='#whmpve-console')root.scrollIntoView();
  refresh();setInterval(()=>{if(!document.hidden&&!dialog?.console)refresh(!pending());},3000);
})();
