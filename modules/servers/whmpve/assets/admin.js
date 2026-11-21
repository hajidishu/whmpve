(() => {
  'use strict';
  const root = document.getElementById('whmpve-admin');
  if (!root) return;
  const config = JSON.parse(root.dataset.config);
  const {t:tr,locale,language} = window.whmpveI18n(config.i18n);
  if (locale) root.lang=locale;
  const ui = {
    view: config.view,
    server: config.view === 'servers' ? config.server_id : 0, section: 'plans',
    filter: config.view === 'servers' ? 0 : config.server_id,
    page: 1, search: '', service: config.service_id || 0, modal: null, pool: 0, imageKind: '',
  };
  let data, error = '', message = '', refreshId = 0;
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const labels = {pending:tr('ui.pending_provisioning'),provisioning:tr('ui.provisioning'),active:tr('ui.active'),suspended:tr('ui.suspended'),terminated:tr('ui.deleted'),
    queued:tr('ui.queued'),running:tr('ui.in_progress'),stopped:tr('ui.stopped'),success:tr('ui.completed'),failed:tr('ui.failed'),free:tr('ui.available'),reserved:tr('ui.reserved'),held:tr('ui.reserved_pending_payment'),assigned:tr('ui.assigned'),
    unknown:'—',absent:tr('ui.deleted'),error:tr('ui.error'),warning:tr('ui.warning'),info:tr('ui.info')};
  const actions = {create:tr('ui.provision'),reinstall:tr('ui.reinstall'),start:tr('ui.start'),shutdown:tr('ui.shut_down'),stop:tr('ui.force_shutdown'),reboot:tr('ui.restart'),reset:tr('ui.force_restart'),
    terminate:tr('ui.destroy'),suspend:tr('ui.suspend'),unsuspend:tr('ui.unsuspend'),change_package:tr('ui.upgrade'),edit_config:tr('ui.edit_configuration'),password:tr('ui.change_password'),log:tr('ui.log'),traffic:tr('ui.traffic_enforcement'),
    backup:tr('ui.create_backup'),backup_restore:tr('ui.restore_backup'),backup_delete:tr('ui.delete_backup'),lxc_password_reset:tr('ui.reset_password'),traffic_reset:tr('ui.traffic_reset'),addon_apply:tr('ui.apply_add_on')};
  const addonTypes={traffic_reset:tr('ui.traffic_reset_pack'),cores:tr('ui.extra_cpu_cores'),memory_mib:tr('ui.extra_memory'),ipv4:tr('ui.extra_ipv4_addresses'),ipv6:tr('ui.extra_ipv6_addresses')};
  const addonUnits={traffic_reset:'%',cores:tr('ui.cores'),memory_mib:' MiB',ipv4:tr('ui.addresses'),ipv6:tr('ui.addresses')};
  const addonTerms={Monthly:tr('ui.monthly'),Quarterly:tr('ui.quarterly'),'Semi-Annually':tr('ui.semiannually'),Annually:tr('ui.annually'),Biennially:tr('ui.biennially'),Triennially:tr('ui.triennially')};
  const bandwidth = value => Number(value) ? value+' Mbps' : tr('ui.unlimited_speed');
  const kindName = kind => ({lxc:'LXC',qemu:'KVM'}[kind] || '—');
  const templateSourceHelp = tr('ui.use_the_exact_template_filename_shown_in');
  const selectedServer = () => data?.servers.find(server => Number(server.id) === Number(ui.server));
  const serverName = id => data.servers.find(server => Number(server.id) === Number(id))?.name || tr('ui.system');
  const time = value => value ? new Date(value.replace(' ','T')+'Z').toLocaleString(locale,{timeZone:'Asia/Shanghai',hour12:false}) : '—';
  const badge = value => `<span class="wmp-badge ${['active','success','free'].includes(value) ? 'wmp-ok' : ['failed','error'].includes(value) ? 'wmp-error' : ['running','queued','provisioning'].includes(value) ? 'wmp-busy' : 'wmp-warn'}">${esc(labels[value] || value)}</span>`;
  const button = (label, attrs = '', primary = false) => `<button type="button" class="wmp-btn ${primary ? 'wmp-primary' : ''}" ${attrs}>${label}</button>`;
  const options = (rows, label) => rows.map(row => `<option value="${row.id}">${esc(label(row))}</option>`).join('');
  const input = (label, name, value = '', type = 'text', placeholder = '', help = '') => `<label class="wmp-field">${label}<input name="${name}" type="${type}" value="${esc(value)}" placeholder="${esc(placeholder)}" ${type === 'number' ? 'min="0" step="any"' : ''}>${help ? '<small>'+esc(help)+'</small>' : ''}</label>`;
  const select = (label, name, content) => `<label class="wmp-field">${label}<select name="${name}">${content}</select></label>`;

  function multiSelect(label, name, rows, labelFor) {
    return `<div class="wmp-field wmp-multi" data-multi="${name}">
      <span id="wmp-${name}-label">${label}</span><div class="wmp-multi-control">
        <div class="wmp-multi-tags" aria-live="polite"></div>
        <button type="button" class="wmp-multi-toggle" data-multi-toggle aria-label="${esc(tr('ui.select_field',{field:label}))}" aria-expanded="false" aria-controls="wmp-${name}-menu">${esc(tr('ui.select_an_option'))}</button>
      </div><div class="wmp-multi-menu" id="wmp-${name}-menu" hidden>
        <input type="search" data-multi-search placeholder="${esc(tr('ui.search_field',{field:label}))}" aria-label="${esc(tr('ui.search_field',{field:label}))}" autocomplete="off">
        <div class="wmp-multi-options" role="group" aria-labelledby="wmp-${name}-label">
          ${rows.map(row => `<label class="wmp-multi-option"><input type="checkbox" name="${name}" value="${row.id}"><span>${esc(labelFor(row))}</span></label>`).join('')}
        </div><div class="wmp-multi-empty" ${rows.length ? 'hidden' : ''}>${rows.length ? tr('ui.no_matches') : tr('ui.no_options_available')}</div>
      </div></div>`;
  }

  function updateMulti(picker) {
    const selected = [...picker.querySelectorAll('input[type="checkbox"]:checked')];
    picker.querySelector('.wmp-multi-tags').innerHTML = selected.map(option => {
      const label = option.nextElementSibling.textContent;
      return `<span class="wmp-multi-tag"><span>${esc(label)}</span><button type="button" data-multi-remove="${option.value}" aria-label="${esc(tr('ui.remove_option',{option:label}))}" title="${esc(tr('ui.remove'))}">×</button></span>`;
    }).join('');
    picker.querySelector('[data-multi-toggle]').textContent = selected.length ? tr('ui.selected_count',{count:selected.length}) : tr('ui.select_an_option');
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
      headers: body === null ? {} : {'Content-Type':'application/json','X-Whmpve-Token':config.token,'X-Whmpve-Language':language},
      body: body === null ? null : JSON.stringify(body),
    });
    const result = await response.json();
    if (!response.ok || !result.ok) throw new Error(result.error || tr('ui.request_failed'));
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
        : `<tr><td colspan="${headers.length}" class="wmp-empty">${esc(tr('ui.no_records'))}</td></tr>`}</tbody></table></div>`;
  }

  function serversView() {
    return table([tr('ui.servers'),tr('ui.address_node'),tr('ui.instances'),tr('ui.status'),tr('ui.actions')], data.servers.map(server => [
      `<button class="wmp-copy" data-server="${server.id}">${esc(server.name)}</button>`,
      `${esc(server.host)}:${server.port}<div class="wmp-note">${esc(server.settings.node)}</div>`, server.instances,
      server.active ? badge('active') : tr('ui.disabled'),
      `<div class="wmp-table-actions">${button(tr('ui.configuration'),`data-server="${server.id}" data-section="settings"`)}
        ${button(tr('ui.test'),`data-inspect="${server.id}"`)}${button(tr('ui.remove'),`data-remove-server="${server.id}"`)}</div>`,
    ]));
  }

  function serverForm(server = null) {
    const settings = server?.settings || {};
    const defaults = config.server_defaults;
    return `<form data-form="server" data-id="${server?.id || 0}"><div class="wmp-form">
      <label class="wmp-field wmp-check wmp-full"><span>${esc(tr('ui.enable_this_server'))}</span><input type="checkbox" name="active" ${!server || Number(server.active) ? 'checked' : ''}></label>
      <label class="wmp-field wmp-check wmp-full"><span>${esc(tr('ui.start_vpss_automatically_at_host_boot'))}</span><input type="checkbox" name="onboot" ${settings.onboot?'checked':''}></label>
      ${input(tr('ui.name'),'name',server?.name)}${input(tr('ui.pve_address'),'host',server?.host,'text','192.168.1.10')}
      ${input(tr('ui.api_token_id'),'token_id',server?.token_id,'text','user@pve!token')}
      ${input(tr('ui.api_token_secret'),'token_secret','','password',server?.has_token ? tr('ui.leave_blank_to_keep_current_value') : '')}
      ${input(tr('ui.pve_node'),'node',settings.node,'text','pve')}${input(tr('ui.default_storage_name'),'storage',settings.storage || 'local','text','',tr('ui.use_the_exact_storage_name_shown_in'))}
      ${input(tr('ui.backup_storage_name'),'backup_storage',settings.backup_storage || 'local','text','',tr('ui.enter_the_name_of_a_pve_storage'))}
      ${input(tr('ui.pve_resource_pool'),'pve_pool',settings.pve_pool || 'whmpve')}
      ${input(tr('ui.ca_certificate_path_optional'),'ca_file',settings.ca_file,'text','/path/to/pve-ca.pem',tr('ui.enter_a_path_if_the_pve_server'))}
    </div><details class="wmp-form-section"><summary>${esc(tr('ui.advanced_settings'))}</summary><div class="wmp-form">
      ${input(tr('ui.api_port'),'port',server?.port || 8006,'number')}
      ${input(tr('ui.first_vmid'),'vmid_min',settings.vmid_min ?? defaults.vmid_min,'number')}${input(tr('ui.last_vmid'),'vmid_max',settings.vmid_max ?? defaults.vmid_max,'number')}
      ${input(tr('ui.maximum_number_of_instances'),'max_instances',settings.max_instances ?? defaults.max_instances,'number','',tr('ui.0_means_unlimited'))}
      ${input(tr('ui.host_memory_reserve_mib'),'reserve_memory_mib',settings.reserve_memory_mib || 512,'number')}
    </div></details><details class="wmp-form-section"><summary>${esc(tr('ui.web_console'))}</summary><div class="wmp-form">
      ${input(tr('ui.websocket_url'),'console_url',settings.console_url,'text','wss://example.com/console')}
      ${input(tr('ui.pve_user'),'console_user',settings.console_user,'text','user@pve')}
      ${input(tr('ui.user_password'),'console_password','','password',server?.has_console_password ? tr('ui.leave_blank_to_keep_current_value') : '')}
    </div></details><div class="wmp-modal-actions"><button class="wmp-btn wmp-primary" type="submit">${esc(tr('ui.save'))}</button></div></form>`;
  }

  function plansView() {
    return `${button(tr('ui.new_plan'),'data-new="plan"',true)}<div class="wmp-space"></div>` + table(
      [tr('ui.plan'),tr('ui.type'),tr('ui.specifications'),tr('ui.default_image'),tr('ui.status'),tr('ui.actions')], data.plans.map(plan => [esc(plan.name),kindName(plan.spec.kind),
        `${plan.spec.cores} ${esc(tr('ui.cores_2'))} ${plan.spec.memory_mib} MiB / ${plan.spec.disk_gib} GiB<div class="wmp-note">${bandwidth(plan.spec.bandwidth_mbps)} · ${plan.spec.traffic_gb ? plan.spec.traffic_gb+tr('ui.gb_month') : tr('ui.unlimited_traffic')}</div>`,
        esc(data.images.find(image => image.id === plan.spec.default_image_id)?.name || '—'),
        plan.active ? badge('active') : tr('ui.disabled'), recordActions('plans',plan),
      ]));
  }

  function imagesView() {
    return `<div class="wmp-toolbar">${button(tr('ui.add_image'),'data-new="image"',true)}${button(tr('ui.image_groups'),'data-section="groups"')}</div><div class="wmp-space"></div>
      <nav class="wmp-nav" aria-label="${esc(tr('ui.image_type'))}">${[['',tr('ui.all')],['lxc','LXC'],['qemu','KVM']].map(([kind,label]) =>
        `<button data-image-kind="${kind}" aria-current="${ui.imageKind===kind}">${label}</button>`).join('')}</nav>` + table(
      [tr('ui.images'),tr('ui.type'),tr('ui.image_groups'),tr('ui.template'),tr('ui.minimum_requirements'),tr('ui.actions')], data.images.filter(image => !ui.imageKind || image.kind===ui.imageKind).map(image => [esc(image.name),kindName(image.kind),
        esc(data.image_groups.find(group => group.id === image.group_id)?.name || tr('ui.ungrouped')),esc(image.source),`${image.min_memory} MiB / ${Number(image.min_disk)} GiB`,recordActions('images',image)]));
  }

  function groupsView() {
    return `<div class="wmp-toolbar">${button(tr('ui.back_to_images'),'data-section="images"')}${button(tr('ui.add_image_group'),'data-new="group"',true)}</div><div class="wmp-space"></div>`+
      table([tr('ui.image_groups'),tr('ui.image_count'),tr('ui.actions')],data.image_groups.map(group => [esc(group.name),data.images.filter(image => image.group_id === group.id).length,recordActions('image_groups',group)]));
  }

  function ipsView() {
    const pool=data.ip_pools.find(row => Number(row.id) === ui.pool);
    if (!pool && ui.pool!=='new') return `${button(tr('ui.create_ip_pool'),'data-new="pool"',true)}<div class="wmp-space"></div>`+table(
      [tr('ui.ip_pool'),tr('ui.type'),tr('ui.gateway_bridge'),tr('ui.available_total'),tr('ui.actions')],data.ip_pools.map(row => [
        `<button class="wmp-copy" data-pool="${row.id}">${esc(row.name)}</button>`,'IPv'+row.family,
        `${esc(row.gateway || '—')}<div class="wmp-note">${esc(row.bridge || tr('ui.no_bridge'))}${row.vlan ? ' · VLAN '+row.vlan : ''}</div>`,
        row.free+' / '+row.total,`<div class="wmp-table-actions">${button(tr('ui.manage'),`data-pool="${row.id}"`)}
        ${button(tr('ui.remove'),`data-remove="ip_pools" data-id="${row.id}"`)}</div>`]));
    const form=`<div class="wmp-toolbar wmp-space">${button(tr('ui.back_to_ip_pools'),'data-pool="0"')}<strong>${pool ? esc(pool.name) : tr('ui.create_ip_pool')}</strong></div>
      <div class="wmp-card wmp-space">${poolForm(pool)}</div>`;
    if (!pool) return form;
    return form + table(
      ['IP',tr('ui.prefix_gateway'),tr('ui.service'),tr('ui.status'),tr('ui.actions')],data.ips.filter(ip => Number(ip.pool_id) === ui.pool).map(ip => [esc(ip.address),`/${ip.prefix} · ${esc(ip.gateway || '—')}`,
        ip.service_id ? `<button class="wmp-copy" data-service-filter="${ip.service_id}">#${ip.service_id}</button>` : '—',badge(ip.state),
        ip.state === 'free' ? button(tr('ui.remove'),`data-remove="ips" data-id="${ip.id}"`) : '—']));
  }

  function poolForm(pool) {
    return `<form data-form="pool" data-id="${pool?.id || 0}"><div class="wmp-notice wmp-error" data-error role="alert" hidden></div>
      <div class="wmp-form">
        ${input(tr('ui.name'),'name',pool?.name)}
        ${select(tr('ui.address_type'),'family','<option value="4">IPv4</option><option value="6">IPv6</option>')}
        ${input(tr('ui.prefix_length'),'prefix',pool?.prefix ?? 24,'number')}${input(tr('ui.gateway'),'gateway',pool?.gateway)}
        ${input(tr('ui.bridge'),'bridge',pool?.bridge ?? 'vmbr0','text','vmbr0')}${input('VLAN','vlan',pool?.vlan,'number')}
        ${input('DNS','dns',pool?.dns,'text','1.1.1.1 2606:4700:4700::1111')}${input('MTU','mtu',pool?.mtu ?? 1500,'number')}
      </div><div class="wmp-form wmp-form-section">
        ${select(tr('ui.add_addresses'),'mode',`<option value="">${esc(tr('ui.do_not_add_addresses'))}</option><option value="manual">${esc(tr('ui.enter_addresses_manually'))}</option><option value="range">${esc(tr('ui.address_range'))}</option><option value="generate">${esc(tr('ui.generate_ipv6_addresses'))}</option>`)}
        <label class="wmp-field wmp-full" data-address-mode="manual" hidden>${esc(tr('ui.ip_address'))}<textarea name="addresses" rows="5" placeholder="${esc(tr('ui.one_address_per_line'))}"></textarea></label>
        <div class="wmp-full wmp-form" data-address-mode="range" hidden>${input(tr('ui.start_address'),'start')}${input(tr('ui.end_address'),'end')}</div>
        <div class="wmp-full wmp-form" data-address-mode="generate" hidden>${input(tr('ui.ipv6_prefix'),'subnet','','text','2001:db8:1::/64')}${input(tr('ui.ipv6_count'),'count',50,'number')}
          <label class="wmp-field wmp-check"><span>${esc(tr('ui.generate_consecutive_addresses'))}</span><input type="checkbox" name="continuous"></label></div>
      </div><div class="wmp-modal-actions"><button class="wmp-btn wmp-primary" type="submit">${esc(tr('ui.save'))}</button></div></form>`;
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
    return `<div class="wmp-table-actions">${button(tr('ui.edit'),`data-edit="${type}" data-id="${record.id}"`)}
      ${type==='plans' ? button(tr('ui.add_ons'),`data-plan-addons="${record.id}"`) : ''}
      ${['plans','images'].includes(type) ? button(record.active ? tr('ui.disabled') : tr('ui.enabled'),`data-toggle="${type}" data-id="${record.id}" data-active="${record.active ? 0 : 1}"`) : ''}
      ${button(tr('ui.remove'),`data-remove="${type}" data-id="${record.id}"`)}</div>`;
  }

  function listFilter() {
    return `<div class="wmp-admin-actions"><select data-server-filter><option value="0">${esc(tr('ui.all_servers'))}</option>${options(data.servers,s => s.name)}</select>
      ${ui.view === 'instances' ? `<input class="wmp-search" data-search-input value="${esc(ui.search)}" placeholder="${esc(tr('ui.instance_search_hint'))}">${button(tr('ui.search'),'data-search')}` : ''}
      ${ui.service ? button(tr('ui.show_all'),'data-clear-service') : ''}</div>`;
  }

  function instancesView() {
    const page = data.instances;
    const panelLink=(instance,label,attribute,tab='')=>`<a class="wmp-btn" ${attribute}="${instance.service_id}"
      href="${esc(instance.service_url+(tab?'&whmpve_tab='+tab:'')+'#whmpve-console')}">${label}</a>`;
    return listFilter() + table([tr('ui.client_service'),tr('ui.instances'),tr('ui.server_system'),tr('ui.configuration'),'IP',tr('ui.status'),tr('ui.due_date'),tr('ui.actions')],page.rows.map(instance => [
      `<a href="${esc(instance.client_url)}" data-client-link>${esc(instance.client_name || tr('ui.client_2')+instance.client_id)}</a>
        <div><a href="${esc(instance.service_url)}" data-service-link data-service-id="${instance.service_id}">#${instance.service_id} · ${esc(instance.product_name)}</a></div>
        <div class="wmp-note">${esc(instance.email)}</div>`,
      `<a href="${esc(instance.service_url)}" data-vps-link>${esc(instance.hostname)}</a><div class="wmp-note">${instance.kind.toUpperCase()} ${instance.vmid || '—'}</div>`,
      `${esc(instance.server_name)}<div class="wmp-note">${esc(instance.node || '—')} · ${esc(instance.image_name)}</div>`,
      `${instance.spec.cores} ${esc(tr('ui.cores_2'))} ${instance.spec.memory_mib} MiB / ${instance.spec.disk_gib} GiB<div class="wmp-note">${bandwidth(instance.spec.bandwidth_mbps)}</div>`,
      instance.ips.map(esc).join('<br>') || '—',
      `${badge(instance.lifecycle === 'active' ? instance.power_state === 'running' ? 'active' : instance.power_state : instance.lifecycle)}
        <div class="wmp-note">${esc({Active:tr('ui.provisioned'),Pending:tr('ui.pending_provisioning'),Suspended:tr('ui.suspended'),Terminated:tr('ui.terminated')}[instance.service_status] || instance.service_status)}</div>`,
      esc(instance.nextduedate || '—'),
      `<div class="wmp-table-actions">${panelLink(instance,tr('ui.manage'),'data-instance')}
        ${data.instance_permissions.edit && instance.can_edit_config && instance.vmid ? panelLink(instance,tr('ui.edit_configuration'),'data-instance-config','config') : ''}
        ${panelLink(instance,tr('ui.backup'),'data-instance-backups','backups')}
        ${button(tr('ui.log'),`data-service-filter="${instance.service_id}" data-journal="1"`)}</div>`,
    ])) + `<div class="wmp-pagination"><span>${esc(tr('ui.total_records',{count:page.total}))}</span>${button(tr('ui.previous_page'),'data-page="'+(page.page-1)+'" '+(page.page <= 1 ? 'disabled' : ''))}
      <span>${page.page} / ${page.pages}</span>${button(tr('ui.next_page'),'data-page="'+(page.page+1)+'" '+(page.page >= page.pages ? 'disabled' : ''))}</div>`;
  }

  function journalView() {
    return listFilter() + table([tr('ui.time'),tr('ui.servers'),tr('ui.service_instance'),tr('ui.actions'),tr('ui.status'),tr('ui.info'),tr('ui.actions')],data.journal.map(entry => [
      esc(time(entry.created_at)),esc(entry.server_name || tr('ui.system')),entry.service_id ? `#${entry.service_id}<div class="wmp-note">${esc(entry.hostname)}</div>` : '—',
      esc(actions[entry.action] || entry.action),badge(entry.status),
      `<span class="wmp-log-preview">${esc(entry.message)}</span>`,
      entry.type === 'task' ? button(tr('ui.view'),`data-task="${entry.id}"`) : button(tr('ui.view'),`data-log="${entry.id}"`),
    ]));
  }

  function planForm(plan = null) {
    const spec = plan?.spec || {};
    const display = spec.connection_display || {};
    const disks = spec.data_disks || [];
    const optional = tr('ui.leave_blank_if_you_do_not_use');
    return `<fieldset class="wmp-field wmp-full wmp-kind"><legend>${esc(tr('ui.virtualization_type'))}</legend><div class="wmp-kind-options">
      ${[['lxc',tr('ui.lxc_container')],['qemu',tr('ui.kvm_virtual_machine')]].map(([kind,label]) => `<label><input type="radio" name="kind" value="${kind}" required ${spec.kind===kind ? 'checked' : ''} ${plan?.type_locked ? 'disabled' : ''}>${label}</label>`).join('')}
      </div>${plan?.type_locked ? `<small>${esc(tr('ui.create_a_new_plan_to_choose_a'))}</small>` : ''}</fieldset>` +
      input(tr('ui.name'),'name',plan?.name) + input(tr('ui.cpu_cores'),'cores',spec.cores || 1,'number') +
      input(tr('ui.memory_mib'),'memory_mib',spec.memory_mib || 1024,'number') +
      input(tr('ui.swap_mib'),'swap_mib',spec.swap_mib ?? 0,'number','',tr('ui.for_lxc_only_set_to_0_to')) +
      `<div class="wmp-field wmp-full" data-plan-nesting hidden>
        <label class="wmp-inline wmp-check"><input type="checkbox" name="nesting" aria-describedby="wmp-plan-nesting-help" ${(spec.nesting ?? true) ? 'checked' : ''}>${esc(tr('ui.allow_nesting'))}</label>
        <small id="wmp-plan-nesting-help">${esc(tr('ui.some_systemd_based_images_such_as_debian'))}</small>
      </div>` +
      input(tr('ui.system_disk_gib'),'disk_gib',spec.disk_gib || 10,'number') +
      input(tr('ui.system_disk_storage_name'),'system_storage',spec.system_storage || selectedServer()?.settings.storage || 'local','text','',tr('ui.use_the_exact_storage_name_shown_in')) +
      `<section class="wmp-full wmp-disk-list"><details data-disk-panel>
        <summary><strong>${esc(tr('ui.data_disks'))}</strong><span data-disk-count>${esc(tr('ui.disk_count',{count:disks.length}))}</span></summary>
        <small class="wmp-muted" data-disk-agent hidden>${esc(tr('ui.for_kvm_images_install_and_start_qemu'))}</small>
        <div data-disks>${disks.map(disk=>diskForm(disk)).join('')}</div>
      </details>${button(tr('ui.add_data_disk'),'data-add-disk')}</section>` +
      input(tr('ui.bandwidth_mbps'),'bandwidth_mbps',spec.bandwidth_mbps ?? 100,'number','',tr('ui.0_means_no_speed_limit')) +
      `<div class="wmp-full">${multiSelect(tr('ui.ip_pool'),'ip_pool_ids',data.ip_pools,pool => pool.name+' · IPv'+pool.family)}</div>` +
      input(tr('ui.ipv4_count'),'ipv4_count',spec.ipv4_count ?? 0,'number') + input(tr('ui.ipv6_count'),'ipv6_count',spec.ipv6_count ?? 0,'number') +
      `<label class="wmp-field wmp-check wmp-full"><span>${esc(tr('ui.assign_private_ip_addresses'))}</span><input type="checkbox" name="private_address" ${display.private_address?'checked':''}></label>
      <div class="wmp-form wmp-full" data-connection-display ${display.private_address?'':'hidden'}>
        ${input(tr('ui.public_ingress_ip'),'public_entry_ip',display.public_ip)}
        <label class="wmp-field wmp-full">${esc(tr('ui.assigned_ports'))}(${esc(tr('ui.shown_to_customers'))})<textarea name="port_note" rows="3" placeholder="${esc(tr('ui.ssh_port_6'))}[ipv4_3][ipv4_4:03]">${esc(display.port_note)}</textarea>
          <small>${esc(tr('ui.port_variable_hint'))}</small></label>
      </div>` +
      input(tr('ui.monthly_traffic_quota_gb'),'traffic_gb',spec.traffic_gb ?? 0,'number','',tr('ui.0_means_unlimited_traffic')) +
      select(tr('ui.when_quota_is_exceeded'),'traffic_action',`<option value="throttle">${esc(tr('ui.throttle_connection'))}</option><option value="suspend">${esc(tr('ui.suspend_vps'))}</option>`) +
      input(tr('ui.throttled_speed_kbps'),'traffic_speed_kbps',spec.traffic_speed_kbps ?? 256,'number') +
      input(tr('ui.reinstall_limit_per_month'),'reinstall_limit',spec.reinstall_limit ?? 0,'number','',tr('ui.0_means_unlimited_2')) +
      input(tr('ui.maximum_backups'),'backup_limit',spec.backup_limit ?? 0,'number','',tr('ui.0_disables_backups')) +
      `<label class="wmp-field wmp-check"><span>${esc(tr('ui.allow_customers_to_enable_automatic_backups'))}</span><input type="checkbox" name="auto_backup" ${spec.auto_backup?'checked':''}></label>` +
      `<div class="wmp-full">${multiSelect(tr('ui.allowed_image_groups'),'image_group_ids',data.image_groups,group => group.name)}<small class="wmp-muted">${esc(tr('ui.leave_blank_to_allow_all_image_groups'))} ${esc(tr('ui.ungrouped_images_are_always_available'))}</small></div>` +
      select(tr('ui.default_image'),'default_image_id',`<option value="">${esc(tr('ui.select_a_default_image'))}</option>`+options(data.images.filter(image => image.active),image => image.name)) +
      `<details class="wmp-full wmp-form-section"><summary>${esc(tr('ui.advanced_settings'))}</summary><div class="wmp-form">
        ${input(tr('ui.cpu_limit_cores'),'cpu_limit',spec.cpu_limit || '','number',tr('ui.all_cores_are_available_by_default'))}
        ${input(tr('ui.configurable_cpu_cores'),'option_cores',(spec.allowed_options?.cores || []).join(','),'text','1,2,4',optional)}
        ${input(tr('ui.configurable_memory_mib'),'option_memory_mib',(spec.allowed_options?.memory_mib || []).join(','),'text','1024,2048',optional)}
        ${input(tr('ui.configurable_system_disk_gib'),'option_disk_gib',(spec.allowed_options?.disk_gib || []).join(','),'text','0.5,1,2',optional)}
        ${input(tr('ui.configurable_bandwidth_mbps'),'option_bandwidth_mbps',(spec.allowed_options?.bandwidth_mbps || []).join(','),'text','0,100,200',optional)}
      </div></details>`;
  }


  function modalContent() {
    const modal = ui.modal;
    if (!modal) return '';
    if (modal.type === 'server') return serverForm();
    if (modal.type === 'addons') {
      return `<div class="wmp-panel-title"><strong>${esc(modal.plan.name)}</strong>${button(tr('ui.add_an_add_on'),'data-add-addon',true)}</div>`
        +table([tr('ui.name'),tr('ui.type'),tr('ui.number'),tr('ui.stock'),tr('ui.status'),tr('ui.actions')],modal.items.map(item=>[
          esc(item.name),addonTypes[item.kind],item.quantity+addonUnits[item.kind],item.stock??tr('ui.unlimited'),item.active?tr('ui.enabled'):tr('ui.disabled'),
          `<div class="wmp-table-actions">${button(tr('ui.edit'),`data-edit-addon="${item.id}"`)}${button(tr('ui.remove'),`data-remove-addon="${item.id}"`)}</div>`,
        ]))+`<p class="wmp-note">${esc(tr('ui.keep_add_on_stock_within_the_servers'))}</p>`;
    }
    if (modal.type === 'addon') return addonForm(modal.record);
    if (modal.type === 'task') {
      const task = modal.record;
      return `<div class="wmp-panel-title"><span>#${task.id} · ${esc(actions[task.action])}</span>${badge(task.status)}</div>
        <p>${esc(task.stage)} · ${task.stage_number}/${task.stage_total}</p>
        ${task.error ? `<div class="wmp-notice wmp-error">${esc(task.error)}</div>` : ''}
        ${task.logs.map(log => `<div class="wmp-log"><time>${esc(time(log.time))}</time>${esc(log.message)}</div>`).join('')}
        ${task.can_retry ? `<div class="wmp-modal-actions">${button(tr('ui.retry'),`data-retry="${task.id}"`,true)}</div>` : ''}`;
    }
    if (modal.type === 'log') return `<pre class="wmp-log-text">${esc(modal.record.message)}</pre>`;
    const record = modal.record;
    let fields;
    if (modal.type === 'plan') fields = planForm(record);
    if (modal.type === 'image') fields = input(tr('ui.name'),'name',record?.name) +
      select(tr('ui.image_groups'),'group_id',`<option value="0">${esc(tr('ui.ungrouped_2'))}</option>`+options(data.image_groups,group => group.name)) +
      `<label class="wmp-field">${esc(tr('ui.type'))}<select name="kind" ${record ? 'disabled' : ''}><option value="lxc" ${(record?.kind || modal.kind)==='qemu' ? '' : 'selected'}>${esc(tr('ui.lxc_container'))}</option><option value="qemu" ${(record?.kind || modal.kind)==='qemu' ? 'selected' : ''}>${esc(tr('ui.kvm_virtual_machine'))}</option></select></label>` +
      input((record?.kind || modal.kind) === 'qemu' ? tr('ui.kvm_template_vmid') : tr('ui.lxc_template_volume'),'source',record?.source,'text',(record?.kind || modal.kind) === 'qemu' ? '9000' : 'local:vztmpl/alpine.tar.xz',(record?.kind || modal.kind) === 'qemu' ? '' : templateSourceHelp) +
      input(tr('ui.minimum_memory_mib'),'min_memory',record?.min_memory || 64,'number') + input(tr('ui.minimum_disk_size_gib'),'min_disk',Number(record?.min_disk ?? 1),'number') +
      `<label class="wmp-field wmp-full">${esc(tr('ui.description'))}<textarea name="description" rows="3" maxlength="500" placeholder="${esc(tr('ui.enter_the_description_customers_see_when_choosing'))}">${esc(record?.description)}</textarea></label>`;
    if (modal.type === 'group') fields = input(tr('ui.mirror_group_name'),'name',record?.name);
    return `<form data-form="${modal.type}" data-id="${record?.id || 0}"><div class="wmp-form">${fields}</div>
      <div class="wmp-modal-actions"><button class="wmp-btn wmp-primary" type="submit">${esc(tr('ui.save'))}</button></div></form>`;
  }

  function addonForm(record) {
    const kind=record?.kind || 'traffic_reset';
    return `<form data-form="addon" data-id="${record?.id || 0}"><div class="wmp-form">
      ${input(tr('ui.name'),'name',record?.name)}
      ${select(tr('ui.type'),'kind',Object.entries(addonTypes).map(([id,label])=>`<option value="${id}" ${id===kind?'selected':''}>${label}</option>`).join(''))}
      ${input(tr('ui.quota_restored'),'quantity',record?.quantity ?? 100,'number')}
      ${input(tr('ui.stock'),'stock',record?.stock,'number','',tr('ui.leave_blank_for_unlimited_stock_0_means'))}
      ${select(tr('ui.ip_pool'),'pool_id',`<option value="">${esc(tr('ui.select_an_ip_pool'))}</option>`+data.ip_pools.map(pool=>
        `<option value="${pool.id}" data-family="${pool.family}" ${pool.id===Number(record?.pool_id)?'selected':''}>${esc(pool.name)}</option>`).join(''))}
      <label class="wmp-field wmp-check"><span>${esc(tr('ui.enabled'))}</span><input type="checkbox" name="active" ${!record||record.active?'checked':''}></label>
      ${addonPrices(record,true)}${addonPrices(record,false)}
      </div><p class="wmp-note">${esc(tr('ui.keep_add_on_stock_within_the_servers'))}</p>
      <div class="wmp-modal-actions">${button(tr('ui.back'),'data-back-addons')}<button class="wmp-btn wmp-primary" type="submit">${esc(tr('ui.save'))}</button></div></form>`;
  }

  const addonPriceField=(currency,term)=>term==='once'?'price_'+currency:`price_${currency}_${term}`;

  function addonPrices(record,once) {
    const terms=once?{once:tr('ui.one_time_price')}:addonTerms;
    const matching=record&&(record.kind==='traffic_reset')===once;
    return `<section class="wmp-full" data-addon-prices="${once?'once':'recurring'}" hidden>
      <div class="wmp-table-wrap wmp-addon-prices"><table><thead><tr><th>${esc(tr('ui.currency'))}</th>${Object.values(terms).map(label=>`<th>${label}</th>`).join('')}</tr></thead>
      <tbody>${data.currencies.map(({id,code})=>{
        const saved=matching?record.prices?.[id]:null;
        return `<tr><th scope="row">${esc(code)}</th>${Object.entries(terms).map(([term,label])=>
          `<td><div class="wmp-field"><input type="number" min="0.01" step="0.01" aria-label="${esc(code)} ${label}"
            name="${addonPriceField(id,term)}" value="${esc(typeof saved==='object'?saved?.[term]:saved)}"></div></td>`).join('')}</tr>`;
      }).join('')}</tbody></table></div><p class="wmp-note">${once?tr('ui.leave_blank_to_disable_purchase_in_this'):tr('ui.enter_the_total_price_for_each_billing')}</p></section>`;
  }

  function updateAddonForm(form,reset=false) {
    const {kind,quantity,pool_id:pool}=form.elements;
    const settings={traffic_reset:[tr('ui.quota_restored'),100,100],cores:[tr('ui.additional_cores'),1,128],memory_mib:[tr('ui.additional_memory_mib'),1024,1048576],
      ipv4:[tr('ui.ipv4_count'),1,16],ipv6:[tr('ui.ipv6_count'),1,16]};
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
      <summary><strong>${esc(tr('ui.data_disks'))} ${id}</strong><span data-disk-summary>${esc(diskSummary(storage,size,mount))}</span></summary>
      <div class="wmp-disk-fields wmp-form">
        ${input(tr('ui.storage_name'),'disk_storage_'+id,storage,'text','',tr('ui.use_the_exact_storage_name_shown_in'))}
        ${input(tr('ui.capacity_gib'),'disk_size_'+id,size,'number')}
        ${input(tr('ui.mount_point'),'disk_mount_'+id,mount,'text','/data')}
        <label class="wmp-field wmp-check"><span>${esc(tr('ui.allow_customers_to_specify_the_mount_point'))}</span><input type="checkbox" name="disk_custom_${id}" ${disk.allow_custom_mount?'checked':''}></label>
        <div class="wmp-full wmp-disk-actions">${button(tr('ui.delete'),'data-remove-disk')}</div>
      </div></details>`;
  }

  function updateDiskCount(panel) {
    panel.querySelector('[data-disk-count]').textContent=tr('ui.disk_count',{count:panel.querySelectorAll('[data-disk]').length});
  }

  function render() {
    const nav=`<nav class="wmp-nav">${[['servers',tr('ui.servers')],['instances',tr('ui.instances_2')],['journal',tr('ui.tasks_logs')]].map(([id,label]) =>
      `<button data-view="${id}" aria-current="${ui.view === id}">${label}</button>`).join('')}</nav>`;
    if (!data) {
      root.innerHTML = `${nav}<div class="wmp-loading">${esc(error || tr('ui.loading'))} ${error ? button(tr('ui.refresh'),'data-refresh') : ''}</div>`;
      return;
    }
    const server = selectedServer();
    let title = ui.view === 'servers' ? server?.name || tr('ui.servers') : ui.view === 'instances' ? tr('ui.instances_2') : tr('ui.tasks_logs');
    let content;
    if (ui.view !== 'servers') content = ui.view === 'instances' ? instancesView() : journalView();
    else if (!server) content = serversView();
    else content = `<nav class="wmp-nav">${[['settings',tr('ui.settings')],['plans',tr('ui.plans')],['images',tr('ui.images')],['ips',tr('ui.ip_pool')]].map(([id,label]) =>
      `<button data-section="${id}" aria-current="${ui.section === id}">${label}</button>`).join('')}</nav>` +
      (ui.section === 'settings' ? `<div class="wmp-card">${serverForm(server)}</div>` : ui.section === 'plans' ? plansView() : ui.section === 'images' ? imagesView() : ui.section === 'groups' ? groupsView() : ipsView());
    root.innerHTML = `<div class="wmp-shell">${nav}
      <div class="wmp-head"><div class="wmp-inline">${server && ui.view === 'servers' ? button(tr('ui.back'),'data-back') : ''}<h2>${esc(title)}</h2></div>
        <div class="wmp-toolbar">${ui.view === 'servers' && !server ? button(tr('ui.add_server'),'data-new="server"',true) : ''}
          ${server && ui.view === 'servers' ? button(tr('ui.test_connection'),`data-inspect="${server.id}"`) : ''}${button(tr('ui.refresh'),'data-refresh')}</div></div>
      <div class="wmp-notice wmp-error" data-error role="alert" ${error ? '' : 'hidden'}>${esc(error)}</div>${message ? `<div class="wmp-notice" role="status">${esc(message)}</div>` : ''}
      ${content}</div>${ui.modal ? `<div class="wmp-modalback"><div class="wmp-modal wmp-wide" role="dialog" aria-modal="true">
        <div class="wmp-panel-title"><h3>${esc({server:tr('ui.add_server'),plan:tr('ui.plan'),image:tr('ui.images'),group:tr('ui.image_groups'),task:tr('ui.task'),log:tr('ui.log'),addons:tr('ui.add_ons'),addon:tr('ui.configure_add_on')}[ui.modal.type])}</h3>${button(tr('ui.close'),'data-close')}</div><div class="wmp-notice wmp-error" data-error role="alert" hidden></div>${modalContent()}</div></div>` : ''}`;
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
    select.innerHTML=`<option value="">${esc(tr('ui.select_a_default_image'))}</option>`+options(images,image => image.name);
    select.disabled=!kind || !images.length;
    if ([...select.options].some(option => option.value === current)) select.value=current;
    const kvm=kind==='qemu';
    form.querySelector('[data-disk-agent]').hidden=!kvm;
    form.elements.swap_mib.disabled=kvm;
    form.elements.swap_mib.closest('label').hidden=kvm;
    form.elements.nesting.disabled=kind!=='lxc';
    form.querySelector('[data-plan-nesting]').hidden=kind!=='lxc';
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
        if (values.kind==='lxc') values.nesting=fields.has('nesting');
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
          if (!pattern.test(text)) throw new Error(name==='disk_gib' ? tr('ui.enter_comma_separated_sizes_for_the_configurable') : tr('ui.enter_comma_separated_integers_for_configurable_resources'));
          values.allowed_options[name] = text.split(/[,，\s]+/).map(Number);
        }
      }
      const result = await api(type+'.save',values);
      if (type === 'server') { ui.server = result.id; ui.section = 'settings'; }
      if (type === 'pool') { ui.pool=result.id; ui.section='ips'; }
      ui.modal = null; message = 'added' in result ? tr('ui.addresses_added',{added:result.added,skipped:result.skipped}) : tr('ui.saved');
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
      source.parentElement.firstChild.textContent = kvm ? tr('ui.kvm_template_vmid') : tr('ui.lxc_template_volume');
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
      if (!id) { error=tr('ui.add_up_to_16_data_disks'); return; }
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
    else if (d.removeAddon && confirm(tr('ui.remove_this_add_on'))) run(async () => {
      await api('addon.delete',{id:Number(d.removeAddon),server_id:ui.server,plan_id:ui.modal.plan.id});
      await showAddons(ui.modal.plan);
    });
    else if (d.new==='pool') { ui.pool='new'; render(); }
    else if (d.new) { ui.modal = {type:d.new}; render(); }
    else if (d.edit) { ui.modal = {type:{plans:'plan',images:'image',image_groups:'group'}[d.edit],record:data[d.edit].find(row => row.id === Number(d.id))}; render(); }
    else if (d.toggle) run(() => api('record.toggle',{table:d.toggle,id:Number(d.id),active:Number(d.active),server_id:ui.server}));
    else if (d.remove) run(() => api('record.delete',{table:d.remove,id:Number(d.id),server_id:ui.server}));
    else if (d.removeServer) {
      if (confirm(tr('ui.remove_this_server_and_its_plans_images'))) run(() => api('server.delete',{id:Number(d.removeServer)}));
    } else if (d.inspect) run(async () => {
      const {backup_missing=[]}=await api('inspect',null,{server_id:d.inspect});
      if (backup_missing.length) throw new Error(tr('ui.connected_add_the_missing_backup_permissions')+backup_missing.join(', '));
      message=tr('ui.connected_backup_permissions_are_valid');
    });
    else if (d.page) { ui.page = Number(d.page); refresh(); }
    else if ('search' in d) { ui.search = root.querySelector('[data-search-input]').value; ui.page = 1; refresh(); }
    else if ('clearService' in d) { ui.service = 0; ui.page = 1; refresh(); }
    else if (d.serviceFilter) { ui.service = Number(d.serviceFilter); ui.page = 1; ui.view = 'journal'; refresh(); }
    else if (d.task) run(async () => { ui.modal = {type:'task',record:await api('task',null,{task_id:d.task,server_id:ui.filter})}; });
    else if (d.log) { ui.modal = {type:'log',record:data.journal.find(row => row.type === 'log' && row.id === Number(d.log))}; render(); }
    else if (d.retry) run(async () => { await api('task.retry',{task_id:Number(d.retry),server_id:ui.modal.record.server_id}); ui.modal = null; message = tr('ui.submitted'); });
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
