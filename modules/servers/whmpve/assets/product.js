(() => {
  'use strict';
  const configElement = document.getElementById('whmpve-product-config');
  if (!configElement) return;
  const config = JSON.parse(configElement.textContent);
  const initialized = new WeakSet();
  let pending;

  function initialize() {
    const module = document.querySelector('#inputModule');
    const server = document.querySelector('[name="packageconfigoption[1]"]');
    const plan = document.querySelector('[name="packageconfigoption[2]"]');
    if (module?.value !== 'whmpve' || !server || !plan || initialized.has(server)) return;
    initialized.add(server);
    const group = document.querySelector('#inputServerGroup');
    if (group) group.closest('tr').hidden = true;

    async function load(selected = 0) {
      pending?.abort();
      pending = new AbortController();
      plan.replaceChildren(new Option(server.value === '0' ? '先选择服务器' : '加载中…', '0'));
      plan.disabled = true;
      if (server.value === '0') return;
      try {
        const query = new URLSearchParams({scope:'admin',op:'plans',server_id:server.value});
        const response = await fetch(`${config.base}/api.php?${query}`, {credentials:'same-origin',signal:pending.signal});
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.error || '无法加载 Plan');
        plan.replaceChildren(new Option('选择 Plan', '0'));
        for (const item of result.data.plans) {
          const option = new Option(item.name + ' · ' + (item.spec.kind==='lxc' ? 'LXC' : 'KVM') + (item.active ? '' : '（停用）'), String(item.id));
          option.disabled = !item.active && item.id !== Number(selected);
          plan.add(option);
        }
        plan.value = [...plan.options].some(option => option.value === String(selected)) ? String(selected) : '0';
        plan.disabled = false;
      } catch (error) {
        if (error.name !== 'AbortError') plan.replaceChildren(new Option(error.message, '0'));
      }
    }
    server.addEventListener('change', () => load());
    load(Number(plan.value) || (Number(server.value) === config.server ? config.plan : 0));
  }

  new MutationObserver(initialize).observe(document.body, {childList:true,subtree:true});
  document.addEventListener('change', event => {
    if (event.target.id !== 'inputModule') return;
    const row = document.querySelector('#inputServerGroup')?.closest('tr');
    if (row) row.hidden = event.target.value === 'whmpve';
    initialize();
  });
  initialize();
})();
