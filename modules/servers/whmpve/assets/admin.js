(() => {
  'use strict';
  const root = document.getElementById('whmpve-admin');
  if (!root) return;
  const config = JSON.parse(root.dataset.config);
  const ui = {
    view: config.view,
    server: config.view === 'servers' ? config.server_id : 0, section: 'plans',
    filter: config.view === 'servers' ? 0 : config.server_id,
    page: 1, search: '', service: config.service_id || 0, modal: null, pool: 0, imageKind: '',
  };
  let data, error = '', message = '', refreshId = 0;
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const labels = {pending:'待开通',provisioning:'开通中',active:'正常',suspended:'已暂停',terminated:'已销毁',
    queued:'等待执行',running:'执行中',stopped:'已关机',success:'完成',failed:'失败',free:'空闲',reserved:'预留',held:'待付款预留',assigned:'已分配',
    unknown:'—',absent:'已销毁',error:'错误',warning:'警告',info:'信息'};
  const actions = {create:'开通',reinstall:'重装',start:'开机',shutdown:'关机',stop:'强制关机',reboot:'重启',reset:'强制重启',
    terminate:'销毁',suspend:'暂停',unsuspend:'恢复',change_package:'升级',edit_config:'编辑配置',password:'改密',log:'日志',traffic:'流量处理',
    backup:'创建备份',backup_restore:'恢复备份',backup_delete:'删除备份',lxc_password_reset:'重置密码',traffic_reset:'流量重置',addon_apply:'附加服务开通'};
  const addonTypes={traffic_reset:'流量重置包',cores:'加购 CPU',memory_mib:'加购内存',ipv4:'加购 IPv4',ipv6:'加购 IPv6'};
  const addonUnits={traffic_reset:'%',cores:' 核',memory_mib:' MiB',ipv4:' 个',ipv6:' 个'};
  const addonTerms={Monthly:'月付',Quarterly:'季付','Semi-Annually':'半年付',Annually:'年付',Biennially:'两年付',Triennially:'三年付'};
  const bandwidth = value => Number(value) ? value+' Mbps' : '不限速';
  const kindName = kind => ({lxc:'LXC',qemu:'KVM'}[kind] || '—');
  const templateSourceHelp = '*模板文件名须与 PVE 中一致；local 存储填 local:vztmpl/文件名，其他存储请将 local 替换为对应存储名称。';
  const selectedServer = () => data?.servers.find(server => Number(server.id) === Number(ui.server));
  const serverName = id => data.servers.find(server => Number(server.id) === Number(id))?.name || '系统';
  const time = value => value ? new Date(value.replace(' ','T')+'Z').toLocaleString('zh-CN',{timeZone:'Asia/Shanghai',hour12:false}) : '—';
  const badge = value => `<span class="wmp-badge ${['active','success','free'].includes(value) ? 'wmp-ok' : ['failed','error'].includes(value) ? 'wmp-error' : ['running','queued','provisioning'].includes(value) ? 'wmp-busy' : 'wmp-warn'}">${esc(labels[value] || value)}</span>`;
  const button = (label, attrs = '', primary = false) => `<button type="button" class="wmp-btn ${primary ? 'wmp-primary' : ''}" ${attrs}>${label}</button>`;
  const options = (rows, label) => rows.map(row => `<option value="${row.id}">${esc(label(row))}</option>`).join('');
  const input = (label, name, value = '', type = 'text', placeholder = '', help = '') => `<label class="wmp-field">${label}<input name="${name}" type="${type}" value="${esc(value)}" placeholder="${esc(placeholder)}" ${type === 'number' ? 'min="0" step="any"' : ''}>${help ? '<small>'+esc(help)+'</small>' : ''}</label>`;
  const select = (label, name, content) => `<label class="wmp-field">${label}<select name="${name}">${content}</select></label>`;

  function multiSelect(label, name, rows, labelFor) {
    return `<div class="wmp-field wmp-multi" data-multi="${name}">
      <span id="wmp-${name}-label">${label}</span><div class="wmp-multi-control">
        <div class="wmp-multi-tags" aria-live="polite"></div>
        <button type="button" class="wmp-multi-toggle" data-multi-toggle aria-label="选择${label}" aria-expanded="false" aria-controls="wmp-${name}-menu">请选择</button>
      </div><div class="wmp-multi-menu" id="wmp-${name}-menu" hidden>
        <input type="search" data-multi-search placeholder="搜索${label}" aria-label="搜索${label}" autocomplete="off">
        <div class="wmp-multi-options" role="group" aria-labelledby="wmp-${name}-label">
          ${rows.map(row => `<label class="wmp-multi-option"><input type="checkbox" name="${name}" value="${row.id}"><span>${esc(labelFor(row))}</span></label>`).join('')}
        </div><div class="wmp-multi-empty" ${rows.length ? 'hidden' : ''}>${rows.length ? '没有匹配项' : '暂无选项'}</div>
      </div></div>`;
  }

  function updateMulti(picker) {
    const selected = [...picker.querySelectorAll('input[type="checkbox"]:checked')];
    picker.querySelector('.wmp-multi-tags').innerHTML = selected.map(option => {
      const label = option.nextElementSibling.textContent;
      return `<span class="wmp-multi-tag"><span>${esc(label)}</span><button type="button" data-multi-remove="${option.value}" aria-label="移除${esc(label)}" title="移除">×</button></span>`;
    }).join('');
    picker.querySelector('[data-multi-toggle]').textContent = selected.length ? `已选 ${selected.length} 项` : '请选择';
  }

  function closeMulti(picker) {
    picker.querySelector('.wmp-multi-menu').hidden = true;
    picker.querySelector('[data-multi-toggle]').setAttribute('aria-expanded','false');
  }

  function filterMulti(picker) {
    const search = picker.querySelector('[data-multi-search]').value.trim().toLocaleLowerCase();
    const rows = [...picker.querySelectorAll('.wmp-multi-option')];
    for (const row of rows) row.hidden = !row.textContent.toLocaleLowerCase().includes(search);
    picker.querySelector('.wmp-multi-empty').hidden = rows.some(row => !row.hidden);
  }

  async function api(op, body = null, query = {}) {
    const params = new URLSearchParams({scope:'admin',op,...query});
    const response = await fetch(`${config.base}/api.php?${params}`, {
      method: body === null ? 'GET' : 'POST', credentials:'same-origin',
      headers: body === null ? {} : {'Content-Type':'application/json','X-Whmpve-Token':config.token},
      body: body === null ? null : JSON.stringify(body),
    });
    const result = await response.json();
    if (!response.ok || !result.ok) throw new Error(result.error || '请求失败');
    return result.data;
  }

  async function refresh() {
    const id = ++refreshId;
    try {
      const result = await api('bootstrap', null, {
        server_id: ui.view === 'servers' ? ui.server : ui.filter,
        page: ui.page, search: ui.view === 'instances' ? ui.search : '',
        service_id: ui.view === 'servers' ? 0 : ui.service, pool_id: Number(ui.pool) || 0,
      });
      if (id !== refreshId) return;
      data = result; error = '';
    } catch (failure) {
      if (id !== refreshId) return;
      error = failure.message;
    }
    render();
  }

  async function run(action, form = null) {
    const submit=form?.querySelector('[type="submit"]');
    if (submit?.disabled) return;
    if (submit) submit.disabled=true;
    error = ''; message = '';
    root.querySelectorAll('[data-error]').forEach(notice => { notice.hidden=true; notice.textContent=''; });
    try { await action(); } catch (failure) {
      const notice=form?.querySelector('[data-error]') || root.querySelector('[role="dialog"] [data-error]') || root.querySelector('[data-error]');
      notice.textContent=failure.message; notice.hidden=false; notice.scrollIntoView({block:'nearest'});
      return;
    } finally { if (submit) submit.disabled=false; }
    await refresh();
  }

  function table(headers, rows) {
    return `<div class="wmp-table-wrap"><table><thead><tr>${headers.map(h => `<th>${h}</th>`).join('')}</tr></thead>
      <tbody>${rows.length ? rows.map(row => `<tr>${row.map(cell => `<td>${cell}</td>`).join('')}</tr>`).join('')
        : `<tr><td colspan="${headers.length}" class="wmp-empty">暂无记录</td></tr>`}</tbody></table></div>`;
  }

  function serversView() {
    return table(['服务器','地址 / 节点','实例','状态','操作'], data.servers.map(server => [
      `<button class="wmp-copy" data-server="${server.id}">${esc(server.name)}</button>`,
      `${esc(server.host)}:${server.port}<div class="wmp-note">${esc(server.settings.node)}</div>`, server.instances,
      server.active ? badge('active') : '停用',
      `<div class="wmp-table-actions">${button('配置',`data-server="${server.id}" data-section="settings"`)}
        ${button('检测',`data-inspect="${server.id}"`)}${button('移除',`data-remove-server="${server.id}"`)}</div>`,
    ]));
  }

  function serverForm(server = null) {
    const settings = server?.settings || {};
    const defaults = config.server_defaults;
    return `<form data-form="server" data-id="${server?.id || 0}"><div class="wmp-form">
      <label class="wmp-field wmp-check wmp-full"><span>启用此服务器</span><input type="checkbox" name="active" ${!server || Number(server.active) ? 'checked' : ''}></label>
      <label class="wmp-field wmp-check wmp-full"><span>宿主机开机时自动启动 VPS</span><input type="checkbox" name="onboot" ${settings.onboot?'checked':''}></label>
      ${input('名称','name',server?.name)}${input('PVE 地址','host',server?.host,'text','192.168.1.10')}
      ${input('API 令牌 ID','token_id',server?.token_id,'text','user@pve!token')}
      ${input('API 密钥','token_secret','','password',server?.has_token ? '留空保留' : '')}
      ${input('PVE 节点','node',settings.node,'text','pve')}${input('默认存储名称','storage',settings.storage || 'local','text','','*请确保名称和pve内存储名称一致')}
      ${input('备份存储名称','backup_storage',settings.backup_storage || 'local','text','','填写 PVE 中允许存放备份的存储名称')}
      ${input('权限池（PVE Pool）','pve_pool',settings.pve_pool || 'whmpve')}
      ${input('CA 证书路径（可选）','ca_file',settings.ca_file,'text','/path/to/pve-ca.pem','仅在pve服务器使用自签证书时需要填写， 其余情况留空即可')}
    </div><details class="wmp-form-section"><summary>高级设置</summary><div class="wmp-form">
      ${input('API 端口','port',server?.port || 8006,'number')}
      ${input('VMID 起始','vmid_min',settings.vmid_min ?? defaults.vmid_min,'number')}${input('VMID 结束','vmid_max',settings.vmid_max ?? defaults.vmid_max,'number')}
      ${input('实例数量上限','max_instances',settings.max_instances ?? defaults.max_instances,'number','','0 表示不限')}
      ${input('宿主保留内存（MiB）','reserve_memory_mib',settings.reserve_memory_mib || 512,'number')}
    </div></details><details class="wmp-form-section"><summary>网页控制台</summary><div class="wmp-form">
      ${input('WebSocket 地址','console_url',settings.console_url,'text','wss://example.com/console')}
      ${input('PVE 用户','console_user',settings.console_user,'text','user@pve')}
      ${input('用户密码','console_password','','password',server?.has_console_password ? '留空保留' : '')}
    </div></details><div class="wmp-modal-actions"><button class="wmp-btn wmp-primary" type="submit">保存</button></div></form>`;
  }

  function plansView() {
    return `${button('新建 Plan','data-new="plan"',true)}<div class="wmp-space"></div>` + table(
      ['Plan','类型','规格','默认镜像','状态','操作'], data.plans.map(plan => [esc(plan.name),kindName(plan.spec.kind),
        `${plan.spec.cores} 核 / ${plan.spec.memory_mib} MiB / ${plan.spec.disk_gib} GiB<div class="wmp-note">${bandwidth(plan.spec.bandwidth_mbps)} · ${plan.spec.traffic_gb ? plan.spec.traffic_gb+' GB/月' : '不限流量'}</div>`,
        esc(data.images.find(image => image.id === plan.spec.default_image_id)?.name || '—'),
        plan.active ? badge('active') : '停用', recordActions('plans',plan),
      ]));
  }

  function imagesView() {
    return `<div class="wmp-toolbar">${button('添加镜像','data-new="image"',true)}${button('镜像组','data-section="groups"')}</div><div class="wmp-space"></div>
      <nav class="wmp-nav" aria-label="镜像类型">${[['','全部'],['lxc','LXC'],['qemu','KVM']].map(([kind,label]) =>
        `<button data-image-kind="${kind}" aria-current="${ui.imageKind===kind}">${label}</button>`).join('')}</nav>` + table(
      ['镜像','类型','镜像组','模板','最低配置','操作'], data.images.filter(image => !ui.imageKind || image.kind===ui.imageKind).map(image => [esc(image.name),kindName(image.kind),
        esc(data.image_groups.find(group => group.id === image.group_id)?.name || '未分组'),esc(image.source),`${image.min_memory} MiB / ${Number(image.min_disk)} GiB`,recordActions('images',image)]));
  }

  function groupsView() {
    return `<div class="wmp-toolbar">${button('返回镜像','data-section="images"')}${button('添加镜像组','data-new="group"',true)}</div><div class="wmp-space"></div>`+
      table(['镜像组','镜像数量','操作'],data.image_groups.map(group => [esc(group.name),data.images.filter(image => image.group_id === group.id).length,recordActions('image_groups',group)]));
  }

  function ipsView() {
    const pool=data.ip_pools.find(row => Number(row.id) === ui.pool);
    if (!pool && ui.pool!=='new') return `${button('创建 IP 池','data-new="pool"',true)}<div class="wmp-space"></div>`+table(
      ['IP 池','类型','网关 / 网桥','可用 / 总数','操作'],data.ip_pools.map(row => [
        `<button class="wmp-copy" data-pool="${row.id}">${esc(row.name)}</button>`,'IPv'+row.family,
        `${esc(row.gateway || '—')}<div class="wmp-note">${esc(row.bridge || '无网桥')}${row.vlan ? ' · VLAN '+row.vlan : ''}</div>`,
        row.free+' / '+row.total,`<div class="wmp-table-actions">${button('管理',`data-pool="${row.id}"`)}
        ${button('移除',`data-remove="ip_pools" data-id="${row.id}"`)}</div>`]));
    const form=`<div class="wmp-toolbar wmp-space">${button('返回 IP 池','data-pool="0"')}<strong>${pool ? esc(pool.name) : '创建 IP 池'}</strong></div>
      <div class="wmp-card wmp-space">${poolForm(pool)}</div>`;
    if (!pool) return form;
    return form + table(
      ['IP','前缀 / 网关','服务','状态','操作'],data.ips.filter(ip => Number(ip.pool_id) === ui.pool).map(ip => [esc(ip.address),`/${ip.prefix} · ${esc(ip.gateway || '—')}`,
        ip.service_id ? `<button class="wmp-copy" data-service-filter="${ip.service_id}">#${ip.service_id}</button>` : '—',badge(ip.state),
        ip.state === 'free' ? button('移除',`data-remove="ips" data-id="${ip.id}"`) : '—']));
  }

  function poolForm(pool) {
    return `<form data-form="pool" data-id="${pool?.id || 0}"><div class="wmp-notice wmp-error" data-error role="alert" hidden></div>
      <div class="wmp-form">
        ${input('名称','name',pool?.name)}
        ${select('地址类型','family','<option value="4">IPv4</option><option value="6">IPv6</option>')}
        ${input('前缀长度','prefix',pool?.prefix ?? 24,'number')}${input('网关','gateway',pool?.gateway)}
        ${input('网桥','bridge',pool?.bridge ?? 'vmbr0','text','vmbr0')}${input('VLAN','vlan',pool?.vlan,'number')}
        ${input('DNS','dns',pool?.dns,'text','1.1.1.1 2606:4700:4700::1111')}${input('MTU','mtu',pool?.mtu ?? 1500,'number')}
      </div><div class="wmp-form wmp-form-section">
        ${select('新增地址','mode','<option value="">不添加地址</option><option value="manual">手动添加</option><option value="range">起始与结束地址</option><option value="generate">生成 IPv6</option>')}
        <label class="wmp-field wmp-full" data-address-mode="manual" hidden>IP 地址<textarea name="addresses" rows="5" placeholder="每行一个地址"></textarea></label>
        <div class="wmp-full wmp-form" data-address-mode="range" hidden>${input('起始地址','start')}${input('结束地址','end')}</div>
        <div class="wmp-full wmp-form" data-address-mode="generate" hidden>${input('IPv6 前缀','subnet','','text','2001:db8:1::/64')}${input('IPv6 数量','count',50,'number')}
          <label class="wmp-field wmp-check"><span>生成连续地址</span><input type="checkbox" name="continuous"></label></div>
      </div><div class="wmp-modal-actions"><button class="wmp-btn wmp-primary" type="submit">保存</button></div></form>`;
  }

  function updatePoolFields(form) {
    const mode=form.elements.mode, ipv6=form.elements.family.value==='6';
    const range=mode.querySelector('[value="range"]'), generate=mode.querySelector('[value="generate"]');
    range.hidden=range.disabled=ipv6;
    generate.hidden=generate.disabled=!ipv6;
    if (mode.selectedOptions[0]?.disabled) mode.value='';
    for (const section of form.querySelectorAll('[data-address-mode]')) {
      section.hidden=section.dataset.addressMode!==mode.value;
      for (const input of section.querySelectorAll('input,textarea')) input.disabled=section.hidden;
    }
  }

  function recordActions(type, record) {
    return `<div class="wmp-table-actions">${button('编辑',`data-edit="${type}" data-id="${record.id}"`)}
      ${type==='plans' ? button('附加服务',`data-plan-addons="${record.id}"`) : ''}
      ${['plans','images'].includes(type) ? button(record.active ? '停用' : '启用',`data-toggle="${type}" data-id="${record.id}" data-active="${record.active ? 0 : 1}"`) : ''}
      ${button('移除',`data-remove="${type}" data-id="${record.id}"`)}</div>`;
  }

  function listFilter() {
    return `<div class="wmp-admin-actions"><select data-server-filter><option value="0">全部服务器</option>${options(data.servers,s => s.name)}</select>
      ${ui.view === 'instances' ? `<input class="wmp-search" data-search-input value="${esc(ui.search)}" placeholder="客户、邮箱、主机名、IP、VMID 或服务编号">${button('搜索','data-search')}` : ''}
      ${ui.service ? button('显示全部','data-clear-service') : ''}</div>`;
  }

  function instancesView() {
    const page = data.instances;
    const panelLink=(instance,label,attribute,tab='')=>`<a class="wmp-btn" ${attribute}="${instance.service_id}"
      href="${esc(instance.service_url+(tab?'&whmpve_tab='+tab:'')+'#whmpve-console')}">${label}</a>`;
    return listFilter() + table(['客户 / 服务','实例','服务器 / 系统','配置','IP','状态','到期日期','操作'],page.rows.map(instance => [
      `<a href="${esc(instance.client_url)}" data-client-link>${esc(instance.client_name || '客户 #'+instance.client_id)}</a>
        <div><a href="${esc(instance.service_url)}" data-service-link data-service-id="${instance.service_id}">#${instance.service_id} · ${esc(instance.product_name)}</a></div>
        <div class="wmp-note">${esc(instance.email)}</div>`,
      `<a href="${esc(instance.service_url)}" data-vps-link>${esc(instance.hostname)}</a><div class="wmp-note">${instance.kind.toUpperCase()} ${instance.vmid || '—'}</div>`,
      `${esc(instance.server_name)}<div class="wmp-note">${esc(instance.node || '—')} · ${esc(instance.image_name)}</div>`,
      `${instance.spec.cores} 核 / ${instance.spec.memory_mib} MiB / ${instance.spec.disk_gib} GiB<div class="wmp-note">${bandwidth(instance.spec.bandwidth_mbps)}</div>`,
      instance.ips.map(esc).join('<br>') || '—',
      `${badge(instance.lifecycle === 'active' ? instance.power_state === 'running' ? 'active' : instance.power_state : instance.lifecycle)}
        <div class="wmp-note">${esc({Active:'已开通',Pending:'待开通',Suspended:'已暂停',Terminated:'已终止'}[instance.service_status] || instance.service_status)}</div>`,
      esc(instance.nextduedate || '—'),
      `<div class="wmp-table-actions">${panelLink(instance,'管理','data-instance')}
        ${data.instance_permissions.edit && instance.can_edit_config && instance.vmid ? panelLink(instance,'编辑配置','data-instance-config','config') : ''}
        ${panelLink(instance,'备份','data-instance-backups','backups')}
        ${button('日志',`data-service-filter="${instance.service_id}" data-journal="1"`)}</div>`,
    ])) + `<div class="wmp-pagination"><span>共 ${page.total} 条</span>${button('上一页','data-page="'+(page.page-1)+'" '+(page.page <= 1 ? 'disabled' : ''))}
      <span>${page.page} / ${page.pages}</span>${button('下一页','data-page="'+(page.page+1)+'" '+(page.page >= page.pages ? 'disabled' : ''))}</div>`;
  }

  function journalView() {
    return listFilter() + table(['时间','服务器','服务 / 实例','操作','状态','信息','操作'],data.journal.map(entry => [
      esc(time(entry.created_at)),esc(entry.server_name || '系统'),entry.service_id ? `#${entry.service_id}<div class="wmp-note">${esc(entry.hostname)}</div>` : '—',
      esc(actions[entry.action] || entry.action),badge(entry.status),
      `<span class="wmp-log-preview">${esc(entry.message)}</span>`,
      entry.type === 'task' ? button('查看',`data-task="${entry.id}"`) : button('查看',`data-log="${entry.id}"`),
    ]));
  }

  function planForm(plan = null) {
    const spec = plan?.spec || {};
    const display = spec.connection_display || {};
    const disks = spec.data_disks || [];
    const optional = '如果你不需要whmcs的可配置选项请留空';
    return `<fieldset class="wmp-field wmp-full wmp-kind"><legend>虚拟化类型</legend><div class="wmp-kind-options">
      ${[['lxc','LXC 容器'],['qemu','KVM 虚拟机']].map(([kind,label]) => `<label><input type="radio" name="kind" value="${kind}" required ${spec.kind===kind ? 'checked' : ''} ${plan?.type_locked ? 'disabled' : ''}>${label}</label>`).join('')}
      </div>${plan?.type_locked ? '<small>更换类型请新建 Plan</small>' : ''}</fieldset>` +
      input('名称','name',plan?.name) + input('CPU 核数','cores',spec.cores || 1,'number') +
      input('内存（MiB）','memory_mib',spec.memory_mib || 1024,'number') +
      input('交换内存 Swap（MiB）','swap_mib',spec.swap_mib ?? 0,'number','','仅用于 LXC，0 表示不分配') +
      input('系统盘（GiB）','disk_gib',spec.disk_gib || 10,'number') +
      input('系统盘存储名称','system_storage',spec.system_storage || selectedServer()?.settings.storage || 'local','text','','*请确保名称和pve内存储名称一致') +
      `<section class="wmp-full wmp-disk-list"><details data-disk-panel>
        <summary><strong>数据盘</strong><span data-disk-count>${disks.length} 块</span></summary>
        <small class="wmp-muted" data-disk-agent hidden>KVM 镜像内请安装并启动 qemu-guest-agent，准备分区和 ext4 工具。</small>
        <div data-disks>${disks.map(disk=>diskForm(disk)).join('')}</div>
      </details>${button('添加数据盘','data-add-disk')}</section>` +
      input('带宽（Mbps）','bandwidth_mbps',spec.bandwidth_mbps ?? 100,'number','','0 表示不限速') +
      `<div class="wmp-full">${multiSelect('IP 池','ip_pool_ids',data.ip_pools,pool => pool.name+' · IPv'+pool.family)}</div>` +
      input('IPv4 数量','ipv4_count',spec.ipv4_count ?? 0,'number') + input('IPv6 数量','ipv6_count',spec.ipv6_count ?? 0,'number') +
      `<label class="wmp-field wmp-check wmp-full"><span>分配的是内网地址</span><input type="checkbox" name="private_address" ${display.private_address?'checked':''}></label>
      <div class="wmp-form wmp-full" data-connection-display ${display.private_address?'':'hidden'}>
        ${input('公网入口 IP','public_entry_ip',display.public_ip)}
        <label class="wmp-field wmp-full">分配的端口(给用户看的)<textarea name="port_note" rows="3" placeholder="SSH 端口：6[ipv4_3][ipv4_4:03]">${esc(display.port_note)}</textarea>
          <small>[1_ipv4_4] 取第 1 条 IPv4 的第 4 段，[ipv4_4:03] 补零到 3 位，[ipv6_8:04] 补零到 4 位。省略地址编号时取第 1 条。</small></label>
      </div>` +
      input('月流量额度（GB）','traffic_gb',spec.traffic_gb ?? 0,'number','','0 表示不限流量') +
      select('流量超额后','traffic_action','<option value="throttle">限制速度</option><option value="suspend">暂停服务器</option>') +
      input('超额速度（Kbps）','traffic_speed_kbps',spec.traffic_speed_kbps ?? 256,'number') +
      input('重装系统限制（次/月）','reinstall_limit',spec.reinstall_limit ?? 0,'number','','0 表示不限次数') +
      input('最多备份数','backup_limit',spec.backup_limit ?? 0,'number','','0 表示不允许创建备份') +
      `<label class="wmp-field wmp-check"><span>允许客户启用自动备份</span><input type="checkbox" name="auto_backup" ${spec.auto_backup?'checked':''}></label>` +
      `<div class="wmp-full">${multiSelect('允许的镜像组','image_group_ids',data.image_groups,group => group.name)}<small class="wmp-muted">留空允许所有镜像组；未分组镜像始终可用。</small></div>` +
      select('默认镜像','default_image_id','<option value="">请选择默认镜像</option>'+options(data.images.filter(image => image.active),image => image.name)) +
      `<details class="wmp-full wmp-form-section"><summary>高级设置</summary><div class="wmp-form">
        ${input('CPU 时间上限（核）','cpu_limit',spec.cpu_limit || '','number','默认使用全部核数')}
        ${input('可选 CPU 核数','option_cores',(spec.allowed_options?.cores || []).join(','),'text','1,2,4',optional)}
        ${input('可选内存（MiB）','option_memory_mib',(spec.allowed_options?.memory_mib || []).join(','),'text','1024,2048',optional)}
        ${input('可选系统盘（GiB）','option_disk_gib',(spec.allowed_options?.disk_gib || []).join(','),'text','0.5,1,2',optional)}
        ${input('可选带宽（Mbps）','option_bandwidth_mbps',(spec.allowed_options?.bandwidth_mbps || []).join(','),'text','0,100,200',optional)}
      </div></details>`;
  }


  function modalContent() {
    const modal = ui.modal;
    if (!modal) return '';
    if (modal.type === 'server') return serverForm();
    if (modal.type === 'addons') {
      return `<div class="wmp-panel-title"><strong>${esc(modal.plan.name)}</strong>${button('添加附加服务','data-add-addon',true)}</div>`
        +table(['名称','类型','数量','库存','状态','操作'],modal.items.map(item=>[
          esc(item.name),addonTypes[item.kind],item.quantity+addonUnits[item.kind],item.stock??'不限',item.active?'启用':'停用',
          `<div class="wmp-table-actions">${button('编辑',`data-edit-addon="${item.id}"`)}${button('移除',`data-remove-addon="${item.id}"`)}</div>`,
        ]))+`<p class="wmp-note">请确保设置的附加服务库存不要大于服务器容量</p>`;
    }
    if (modal.type === 'addon') return addonForm(modal.record);
    if (modal.type === 'task') {
      const task = modal.record;
      return `<div class="wmp-panel-title"><span>#${task.id} · ${esc(actions[task.action])}</span>${badge(task.status)}</div>
        <p>${esc(task.stage)} · ${task.stage_number}/${task.stage_total}</p>
        ${task.error ? `<div class="wmp-notice wmp-error">${esc(task.error)}</div>` : ''}
        ${task.logs.map(log => `<div class="wmp-log"><time>${esc(time(log.time))}</time>${esc(log.message)}</div>`).join('')}
        ${task.can_retry ? `<div class="wmp-modal-actions">${button('重试',`data-retry="${task.id}"`,true)}</div>` : ''}`;
    }
    if (modal.type === 'log') return `<pre class="wmp-log-text">${esc(modal.record.message)}</pre>`;
    const record = modal.record;
    let fields;
    if (modal.type === 'plan') fields = planForm(record);
    if (modal.type === 'image') fields = input('名称','name',record?.name) +
      select('镜像组','group_id','<option value="0">未分组</option>'+options(data.image_groups,group => group.name)) +
      `<label class="wmp-field">类型<select name="kind" ${record ? 'disabled' : ''}><option value="lxc" ${(record?.kind || modal.kind)==='qemu' ? '' : 'selected'}>LXC 容器</option><option value="qemu" ${(record?.kind || modal.kind)==='qemu' ? 'selected' : ''}>KVM 虚拟机</option></select></label>` +
      input((record?.kind || modal.kind) === 'qemu' ? 'KVM 模板 VMID' : 'LXC 模板卷名','source',record?.source,'text',(record?.kind || modal.kind) === 'qemu' ? '9000' : 'local:vztmpl/alpine.tar.xz',(record?.kind || modal.kind) === 'qemu' ? '' : templateSourceHelp) +
      input('最低内存（MiB）','min_memory',record?.min_memory || 64,'number') + input('最低磁盘（GiB）','min_disk',Number(record?.min_disk ?? 1),'number') +
      `<label class="wmp-field wmp-full">描述<textarea name="description" rows="3" maxlength="500" placeholder="填写客户选择镜像时显示的说明">${esc(record?.description)}</textarea></label>`;
    if (modal.type === 'group') fields = input('镜像组名称','name',record?.name);
    return `<form data-form="${modal.type}" data-id="${record?.id || 0}"><div class="wmp-form">${fields}</div>
      <div class="wmp-modal-actions"><button class="wmp-btn wmp-primary" type="submit">保存</button></div></form>`;
  }

  function addonForm(record) {
    const kind=record?.kind || 'traffic_reset';
    return `<form data-form="addon" data-id="${record?.id || 0}"><div class="wmp-form">
      ${input('名称','name',record?.name)}
      ${select('类型','kind',Object.entries(addonTypes).map(([id,label])=>`<option value="${id}" ${id===kind?'selected':''}>${label}</option>`).join(''))}
      ${input('重置额度（%）','quantity',record?.quantity ?? 100,'number')}
      ${input('库存','stock',record?.stock,'number','','留空表示不限，0 表示已售罄')}
      ${select('IP 池','pool_id','<option value="">请选择 IP 池</option>'+data.ip_pools.map(pool=>
        `<option value="${pool.id}" data-family="${pool.family}" ${pool.id===Number(record?.pool_id)?'selected':''}>${esc(pool.name)}</option>`).join(''))}
      <label class="wmp-field wmp-check"><span>启用</span><input type="checkbox" name="active" ${!record||record.active?'checked':''}></label>
      ${addonPrices(record,true)}${addonPrices(record,false)}
      </div><p class="wmp-note">请确保设置的附加服务库存不要大于服务器容量</p>
      <div class="wmp-modal-actions">${button('返回','data-back-addons')}<button class="wmp-btn wmp-primary" type="submit">保存</button></div></form>`;
  }

  const addonPriceField=(currency,term)=>term==='once'?'price_'+currency:`price_${currency}_${term}`;

  function addonPrices(record,once) {
    const terms=once?{once:'一次性价格'}:addonTerms;
    const matching=record&&(record.kind==='traffic_reset')===once;
    return `<section class="wmp-full" data-addon-prices="${once?'once':'recurring'}" hidden>
      <div class="wmp-table-wrap wmp-addon-prices"><table><thead><tr><th>币种</th>${Object.values(terms).map(label=>`<th>${label}</th>`).join('')}</tr></thead>
      <tbody>${data.currencies.map(({id,code})=>{
        const saved=matching?record.prices?.[id]:null;
        return `<tr><th scope="row">${esc(code)}</th>${Object.entries(terms).map(([term,label])=>
          `<td><div class="wmp-field"><input type="number" min="0.01" step="0.01" aria-label="${esc(code)} ${label}"
            name="${addonPriceField(id,term)}" value="${esc(typeof saved==='object'?saved?.[term]:saved)}"></div></td>`).join('')}</tr>`;
      }).join('')}</tbody></table></div><p class="wmp-note">${once?'留空关闭该币种购买':'填写各账期的总价。留空关闭该币种对应账期。'}</p></section>`;
  }

  function updateAddonForm(form,reset=false) {
    const {kind,quantity,pool_id:pool}=form.elements;
    const settings={traffic_reset:['重置额度（%）',100,100],cores:['增加核数',1,128],memory_mib:['增加内存（MiB）',1024,1048576],
      ipv4:['IPv4 数量',1,16],ipv6:['IPv6 数量',1,16]};
    const [label,initial,max]=settings[kind.value];
    quantity.parentElement.firstChild.textContent=label;
    quantity.min=1; quantity.max=max; quantity.step=1;
    if (reset) quantity.value=initial;
    const family=kind.value.startsWith('ipv') ? kind.value.at(-1) : '';
    pool.disabled=pool.closest('label').hidden=!family;
    for(const option of pool.querySelectorAll('[data-family]')) option.disabled=option.hidden=option.dataset.family!==family;
    if (pool.selectedOptions[0]?.disabled) pool.value='';
    for(const group of form.querySelectorAll('[data-addon-prices]')) {
      group.hidden=group.dataset.addonPrices!==(kind.value==='traffic_reset'?'once':'recurring');
      for(const price of group.querySelectorAll('input')) price.disabled=group.hidden;
    }
  }

  async function showAddons(plan) {
    const {items}=await api('addons',null,{server_id:ui.server,plan_id:plan.id});
    ui.modal={type:'addons',plan,items};
  }

  function diskSummary(storage,size,mount) {
    return [storage,size ? size+' GiB' : '',mount].filter(Boolean).join(' · ');
  }

  function diskForm(disk,open=false) {
    const id=Number(disk.id), storage=disk.storage || selectedServer()?.settings.storage || 'local';
    const size=disk.size_gib || 10, mount=disk.mountpoint || '/data'+id;
    return `<details class="wmp-disk" data-disk="${id}" ${open?'open':''}>
      <summary><strong>数据盘 ${id}</strong><span data-disk-summary>${esc(diskSummary(storage,size,mount))}</span></summary>
      <div class="wmp-disk-fields wmp-form">
        ${input('存储名称','disk_storage_'+id,storage,'text','','*请确保名称和pve内存储名称一致')}
        ${input('容量（GiB）','disk_size_'+id,size,'number')}
        ${input('挂载点','disk_mount_'+id,mount,'text','/data')}
        <label class="wmp-field wmp-check"><span>允许购买时填写挂载点</span><input type="checkbox" name="disk_custom_${id}" ${disk.allow_custom_mount?'checked':''}></label>
        <div class="wmp-full wmp-disk-actions">${button('删除','data-remove-disk')}</div>
      </div></details>`;
  }

  function updateDiskCount(panel) {
    panel.querySelector('[data-disk-count]').textContent=panel.querySelectorAll('[data-disk]').length+' 块';
  }

  function render() {
    const nav=`<nav class="wmp-nav">${[['servers','服务器'],['instances','实例管理'],['journal','日志/任务']].map(([id,label]) =>
      `<button data-view="${id}" aria-current="${ui.view === id}">${label}</button>`).join('')}</nav>`;
    if (!data) {
      root.innerHTML = `${nav}<div class="wmp-loading">${esc(error || '加载中…')} ${error ? button('刷新','data-refresh') : ''}</div>`;
      return;
    }
    const server = selectedServer();
    let title = ui.view === 'servers' ? server?.name || '服务器' : ui.view === 'instances' ? '实例管理' : '日志/任务';
    let content;
    if (ui.view !== 'servers') content = ui.view === 'instances' ? instancesView() : journalView();
    else if (!server) content = serversView();
    else content = `<nav class="wmp-nav">${[['settings','设置'],['plans','Plan'],['images','镜像'],['ips','IP 池']].map(([id,label]) =>
      `<button data-section="${id}" aria-current="${ui.section === id}">${label}</button>`).join('')}</nav>` +
      (ui.section === 'settings' ? `<div class="wmp-card">${serverForm(server)}</div>` : ui.section === 'plans' ? plansView() : ui.section === 'images' ? imagesView() : ui.section === 'groups' ? groupsView() : ipsView());
    root.innerHTML = `<div class="wmp-shell">${nav}
      <div class="wmp-head"><div class="wmp-inline">${server && ui.view === 'servers' ? button('返回','data-back') : ''}<h2>${esc(title)}</h2></div>
        <div class="wmp-toolbar">${ui.view === 'servers' && !server ? button('添加服务器','data-new="server"',true) : ''}
          ${server && ui.view === 'servers' ? button('检测连接',`data-inspect="${server.id}"`) : ''}${button('刷新','data-refresh')}</div></div>
      <div class="wmp-notice wmp-error" data-error role="alert" ${error ? '' : 'hidden'}>${esc(error)}</div>${message ? `<div class="wmp-notice" role="status">${esc(message)}</div>` : ''}
      ${content}</div>${ui.modal ? `<div class="wmp-modalback"><div class="wmp-modal wmp-wide" role="dialog" aria-modal="true">
        <div class="wmp-panel-title"><h3>${esc({server:'添加服务器',plan:'Plan',image:'镜像',group:'镜像组',task:'任务',log:'日志',addons:'附加服务',addon:'配置附加服务'}[ui.modal.type])}</h3>${button('关闭','data-close')}</div><div class="wmp-notice wmp-error" data-error role="alert" hidden></div>${modalContent()}</div></div>` : ''}`;
    const filter = root.querySelector('[data-server-filter]');
    if (filter) filter.value = String(ui.filter);
    if (ui.modal?.record && ['plan','image','group'].includes(ui.modal.type)) {
      const record = ui.modal.record;
      fillForm(root.querySelector('[data-form]'),{...record.spec,...record});
    }
    const poolForm=root.querySelector('[data-form="pool"]');
    if (poolForm) {
      const pool=data.ip_pools.find(row=>Number(row.id)===ui.pool);
      if (pool) poolForm.elements.family.value=String(pool.family);
      updatePoolFields(poolForm);
    }
    root.querySelectorAll('[data-multi]').forEach(updateMulti);
    if (ui.modal?.type==='addon') updateAddonForm(root.querySelector('[data-form="addon"]'));
    updateAllowedImages();
  }

  function fillForm(form, values) {
    if (!form) return;
    for (const [name,value] of Object.entries(values)) {
      const control = form.elements.namedItem(name);
      if (!control || control.type === 'password') continue;
      if (Array.isArray(value)) {
        const controls = control instanceof RadioNodeList ? [...control] : [control];
        for (const option of controls) option.checked = value.map(String).includes(option.value);
      }
      else if (control.type === 'checkbox') control.checked = !!value;
      else control.value = value ?? '';
    }
  }

  function updateAllowedImages() {
    const form = root.querySelector('[data-form="plan"]');
    if (!form) return;
    const groups=[...form.querySelectorAll('[name="image_group_ids"]:checked')].map(option => Number(option.value));
    const kind=form.elements.kind.value;
    const select=form.elements.default_image_id, current=select.value;
    const diskMiB=value=>Math.ceil(Number(value)*1024);
    const images=data.images.filter(image => image.active && image.kind===kind && image.min_memory<=Number(form.elements.memory_mib.value)
      && diskMiB(image.min_disk)<=diskMiB(form.elements.disk_gib.value) && (!groups.length || !image.group_id || groups.includes(image.group_id)));
    select.innerHTML='<option value="">请选择默认镜像</option>'+options(images,image => image.name);
    select.disabled=!kind || !images.length;
    if ([...select.options].some(option => option.value === current)) select.value=current;
    const kvm=kind==='qemu';
    form.querySelector('[data-disk-agent]').hidden=!kvm;
    form.elements.swap_mib.disabled=kvm;
    form.elements.swap_mib.closest('label').hidden=kvm;
    const speed=form.elements.traffic_speed_kbps;
    speed.closest('label').hidden=form.elements.traffic_action.value === 'suspend';
  }

  root.addEventListener('submit', event => {
    const form = event.target.closest('[data-form]');
    if (!form) return;
    event.preventDefault();
    run(async () => {
      const type = form.dataset.form;
      const fields = new FormData(form);
      const values = {id:Number(form.dataset.id),server_id:ui.server,...Object.fromEntries(fields)};
      if (type === 'plan' || type === 'image') values.kind=form.elements.kind.value;
      if (type === 'server') {
        values.active=fields.has('active');
        values.onboot=fields.has('onboot');
      }
      if (type === 'addon') {
        values.plan_id=ui.modal.plan.id;
        values.active=fields.has('active');
        const terms=values.kind==='traffic_reset'?['once']:Object.keys(addonTerms);
        values.prices=Object.fromEntries(data.currencies.map(({id})=>[id,
          Object.fromEntries(terms.map(term=>[term,fields.get(addonPriceField(id,term))]))]));
        for(const key of Object.keys(values)) if (key.startsWith('price_')) delete values[key];
        await api('addon.save',values);
        await showAddons(ui.modal.plan);
        return;
      }
      if (type === 'plan') {
        values.auto_backup=fields.has('auto_backup');
        values.connection_display={private_address:fields.has('private_address'),public_ip:fields.get('public_entry_ip'),port_note:fields.get('port_note')};
        delete values.private_address;
        delete values.public_entry_ip;
        delete values.port_note;
        values.data_disks=[...form.querySelectorAll('[data-disk]')].map(row => {
          const id=Number(row.dataset.disk);
          return {id,storage:fields.get('disk_storage_'+id),size_gib:Number(fields.get('disk_size_'+id)),
            mountpoint:fields.get('disk_mount_'+id),allow_custom_mount:fields.has('disk_custom_'+id)};
        });
        values.image_group_ids = fields.getAll('image_group_ids').map(Number);
        values.ip_pool_ids = fields.getAll('ip_pool_ids').map(Number);
        if (!values.cpu_limit) delete values.cpu_limit;
        values.allowed_options = {};
        values.preserve_unlisted_options = true;
        for (const name of ['cores','memory_mib','disk_gib','bandwidth_mbps']) {
          const text = String(values['option_'+name] || '').trim();
          if (!text) continue;
          const pattern=name==='disk_gib' ? /^\d+(\.\d+)?([,，\s]+\d+(\.\d+)?)*$/ : /^\d+([,，\s]+\d+)*$/;
          if (!pattern.test(text)) throw new Error(name==='disk_gib' ? '可选系统盘请填写逗号分隔的容量' : '可选规格请填写逗号分隔的整数');
          values.allowed_options[name] = text.split(/[,，\s]+/).map(Number);
        }
      }
      const result = await api(type+'.save',values);
      if (type === 'server') { ui.server = result.id; ui.section = 'settings'; }
      if (type === 'pool') { ui.pool=result.id; ui.section='ips'; }
      ui.modal = null; message = 'added' in result ? `已添加 ${result.added} 个地址，跳过 ${result.skipped} 个` : '已保存';
    },form);
  });

  root.addEventListener('change', event => {
    if (event.target.form?.dataset.form==='addon' && event.target.name==='kind') updateAddonForm(event.target.form,true);
    const picker = event.target.closest('[data-multi]');
    if (picker) updateMulti(picker);
    if (event.target.form?.dataset.form==='plan' && event.target.name==='kind') {
      event.target.form.elements.default_image_id.value='';
      if (event.target.value==='qemu') event.target.form.elements.swap_mib.value=0;
    }
    if (['default_image_id','image_group_ids','traffic_action','kind'].includes(event.target.name)) updateAllowedImages();
    if (event.target.name==='private_address') {
      event.target.form.querySelector('[data-connection-display]').hidden=!event.target.checked;
    }
    if (event.target.form?.dataset.form==='pool') {
      if (event.target.name === 'family') event.target.form.elements.prefix.value=event.target.value === '6' ? '64' : '24';
      if (['family','mode'].includes(event.target.name)) updatePoolFields(event.target.form);
    }
    if (event.target.name === 'kind' && event.target.form?.dataset.form==='image') {
      const source = event.target.form.elements.source;
      const kvm = event.target.value === 'qemu';
      source.parentElement.firstChild.textContent = kvm ? 'KVM 模板 VMID' : 'LXC 模板卷名';
      source.placeholder = kvm ? '9000' : 'local:vztmpl/alpine.tar.xz';
      const help = source.parentElement.querySelector('small');
      if (kvm) help?.remove();
      else if (!help) {
        const note = document.createElement('small'); note.textContent = templateSourceHelp; source.after(note);
      }
    }
    if (event.target.hasAttribute('data-server-filter')) { ui.filter = Number(event.target.value); ui.page = 1; refresh(); }
  });

  root.addEventListener('input', event => {
    if (event.target.hasAttribute('data-multi-search')) filterMulti(event.target.closest('[data-multi]'));
    if (event.target.form?.dataset.form==='plan' && ['memory_mib','disk_gib'].includes(event.target.name)) updateAllowedImages();
    const disk=event.target.closest('[data-disk]');
    if (disk) {
      const values=['storage','size','mount'].map(field=>event.target.form.elements[`disk_${field}_${disk.dataset.disk}`].value);
      disk.querySelector('[data-disk-summary]').textContent=diskSummary(...values);
    }
  });

  root.addEventListener('invalid',event=>{
    const disk=event.target.closest('[data-disk]');
    if (!disk) return;
    disk.closest('[data-disk-panel]').open=true;
    disk.open=true;
  },true);

  root.addEventListener('keydown', event => {
    if (event.target.hasAttribute('data-multi-search') && event.key === 'Enter') event.preventDefault();
  });

  root.addEventListener('click', event => {
    const target = event.target.closest('button');
    if (!target || target.disabled) return;
    const d = target.dataset;
    if ('addDisk' in d) {
      const panel=target.closest('.wmp-disk-list').querySelector('[data-disk-panel]'), list=panel.querySelector('[data-disks]');
      const ids=[...list.querySelectorAll('[data-disk]')].map(row => Number(row.dataset.disk));
      const id=Array.from({length:16},(_,i)=>i+1).find(id => !ids.includes(id));
      if (!id) { error='最多添加 16 块数据盘'; return; }
      panel.open=true;
      list.insertAdjacentHTML('beforeend',diskForm({id},true));
      updateDiskCount(panel);
      list.lastElementChild.querySelector('input').focus();
      return;
    }
    if ('removeDisk' in d) {
      const panel=target.closest('[data-disk-panel]');
      target.closest('[data-disk]').remove();
      updateDiskCount(panel);
      return;
    }
    if ('multiToggle' in d) {
      const picker = target.closest('[data-multi]');
      const open = target.getAttribute('aria-expanded') !== 'true';
      root.querySelectorAll('[data-multi]').forEach(closeMulti);
      if (open) {
        target.setAttribute('aria-expanded','true');
        picker.querySelector('.wmp-multi-menu').hidden = false;
        const search = picker.querySelector('[data-multi-search]');
        search.value = ''; filterMulti(picker); search.focus();
      }
    } else if ('multiRemove' in d) {
      const picker = target.closest('[data-multi]');
      const option = picker.querySelector(`input[value="${d.multiRemove}"]`);
      option.checked = false; option.dispatchEvent(new Event('change',{bubbles:true}));
      picker.querySelector('[data-multi-toggle]').focus();
    } else if ('imageKind' in d) { ui.imageKind=d.imageKind; render(); }
    else if (d.view) { ui.view = d.view; ui.page = 1; ui.service = 0; error = message = ''; refresh(); }
    else if (d.server) { ui.server = Number(d.server); ui.pool=0; ui.view = 'servers'; ui.section = d.section || 'plans'; refresh(); }
    else if ('pool' in d) { ui.pool=Number(d.pool); refresh(); }
    else if (d.section) { ui.section = d.section; render(); }
    else if ('back' in d) { ui.server = 0; refresh(); }
    else if ('refresh' in d) refresh();
    else if ('close' in d) { ui.modal = null; render(); }
    else if (d.planAddons) run(() => showAddons(data.plans.find(plan=>plan.id===Number(d.planAddons))));
    else if ('addAddon' in d || d.editAddon) {
      ui.modal={...ui.modal,type:'addon',record:ui.modal.items.find(item=>item.id===Number(d.editAddon))}; render();
    }
    else if ('backAddons' in d) { ui.modal.type='addons'; render(); }
    else if (d.removeAddon && confirm('移除此附加服务？')) run(async () => {
      await api('addon.delete',{id:Number(d.removeAddon),server_id:ui.server,plan_id:ui.modal.plan.id});
      await showAddons(ui.modal.plan);
    });
    else if (d.new==='pool') { ui.pool='new'; render(); }
    else if (d.new) { ui.modal = {type:d.new}; render(); }
    else if (d.edit) { ui.modal = {type:{plans:'plan',images:'image',image_groups:'group'}[d.edit],record:data[d.edit].find(row => row.id === Number(d.id))}; render(); }
    else if (d.toggle) run(() => api('record.toggle',{table:d.toggle,id:Number(d.id),active:Number(d.active),server_id:ui.server}));
    else if (d.remove) run(() => api('record.delete',{table:d.remove,id:Number(d.id),server_id:ui.server}));
    else if (d.removeServer) {
      if (confirm('移除该服务器及其 Plan、镜像和 IP 配置？')) run(() => api('server.delete',{id:Number(d.removeServer)}));
    } else if (d.inspect) run(async () => {
      const {backup_missing=[]}=await api('inspect',null,{server_id:d.inspect});
      if (backup_missing.length) throw new Error('连接成功，请补齐备份权限 '+backup_missing.join('、'));
      message='连接成功，备份权限正常';
    });
    else if (d.page) { ui.page = Number(d.page); refresh(); }
    else if ('search' in d) { ui.search = root.querySelector('[data-search-input]').value; ui.page = 1; refresh(); }
    else if ('clearService' in d) { ui.service = 0; ui.page = 1; refresh(); }
    else if (d.serviceFilter) { ui.service = Number(d.serviceFilter); ui.page = 1; ui.view = 'journal'; refresh(); }
    else if (d.task) run(async () => { ui.modal = {type:'task',record:await api('task',null,{task_id:d.task,server_id:ui.filter})}; });
    else if (d.log) { ui.modal = {type:'log',record:data.journal.find(row => row.type === 'log' && row.id === Number(d.log))}; render(); }
    else if (d.retry) run(async () => { await api('task.retry',{task_id:Number(d.retry),server_id:ui.modal.record.server_id}); ui.modal = null; message = '已提交'; });
  });

  document.addEventListener('click', event => {
    for (const picker of root.querySelectorAll('[data-multi]')) if (!picker.contains(event.target)) closeMulti(picker);
  });
  document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    const open = root.querySelector('[data-multi-toggle][aria-expanded="true"]');
    if (open) { event.preventDefault(); closeMulti(open.closest('[data-multi]')); open.focus(); }
    else if (ui.modal) { ui.modal = null; render(); }
  });
  refresh();
  setInterval(async () => {
    if (document.hidden) return;
    if (ui.modal?.type === 'task') {
      const id = ui.modal.record.id;
      try {
        const task = await api('task',null,{task_id:id});
        if (ui.modal?.type === 'task' && ui.modal.record.id === id) { ui.modal.record = task; render(); }
      } catch (failure) { error = failure.message; render(); }
    } else if (!ui.modal && ui.view === 'journal') refresh();
  },5000);
})();
