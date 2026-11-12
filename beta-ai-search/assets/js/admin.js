(function () {
  'use strict';
  const config = window.BetaAiSearchAdmin || {};
  function request(action, data) {
    const body = new URLSearchParams(Object.assign({ action: action, nonce: config.nonce }, data || {}));
    return fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: body.toString() })
      .then(function (response) { return response.json(); });
  }
  function text(value) { return value === null || value === undefined || value === '' ? '—' : String(value); }
  function updateStatus(data) {
    const box = document.querySelector('[data-bas-index-status]');
    if (!box || !data) return;
    const job = data.job || {};
    const bar = box.querySelector('.bas-progress__bar');
    if (bar) bar.style.width = Math.max(0, Math.min(100, Number(data.percent) || 0)) + '%';
    const values = { percent: text(data.percent) + '%', status: text(job.status || 'idle'), processed: text(job.processed || 0), total: text(job.total || 0), remaining: text(data.remaining || 0), pending: text(data.pending || 0), indexed: text(data.indexed_products || 0), entities: text(data.indexed_entities || 0), failed: text(job.failed || 0), revision: text(data.revision) };
    Object.keys(values).forEach(function (key) { const el = box.querySelector('[data-field="' + key + '"]'); if (el) el.textContent = values[key]; });
    const model = box.querySelector('[data-field="model"]');
    if (model) model.textContent = data.active_model && data.active_model.model ? data.active_model.provider + ' / ' + data.active_model.model + ' (' + data.active_model.dimensions + 'd)' : '—';
    const warning = box.querySelector('[data-bas-rebuild-warning]');
    if (warning) warning.hidden = !data.rebuild_required;
    document.querySelectorAll('[data-index-action]').forEach(function (button) {
      const action = button.dataset.indexAction;
      if (action === 'pause') button.disabled = job.status !== 'running';
      if (action === 'resume') button.disabled = job.status !== 'paused' && job.status !== 'error';
    });
  }
  function poll() {
    if (!document.querySelector('[data-bas-index-status]')) return;
    request('bas_index_status').then(function (response) { if (response.success) updateStatus(response.data); }).catch(function () {});
  }
  document.addEventListener('click', function (event) {
    const button = event.target.closest('[data-index-action], [data-bas-action], [data-provider-test], [data-provider-models]');
    if (!button) return;
    event.preventDefault();
    if (button.dataset.indexAction) {
      const action = button.dataset.indexAction;
      if ((action === 'rebuild' || action === 'retry') && !window.confirm(action === 'rebuild' ? 'Rebuild the semantic index? Existing results remain available until the new index is ready. API usage may incur charges.' : 'Retry failed indexing items?')) return;
      button.disabled = true;
      request('bas_index_' + action).then(function (response) {
        window.alert(response.success ? (response.data.message || 'OK') : (response.data && response.data.message || 'Request failed.'));
        poll();
      }).catch(function () { window.alert('Request failed.'); }).finally(function () { button.disabled = false; poll(); });
      return;
    }
    if (button.dataset.basAction) {
      const action = button.dataset.basAction;
      if (!window.confirm('Run this administrative action?')) return;
      button.disabled = true;
      request('bas_' + action).then(function (response) { window.alert(response.success ? (response.data.message || 'OK') : (response.data && response.data.message || 'Request failed.')); }).catch(function () { window.alert('Request failed.'); }).finally(function () { button.disabled = false; });
      return;
    }
    if (button.dataset.providerTest) {
      const output = document.querySelector('[data-test-result="' + button.dataset.providerTest + '-' + button.dataset.task + '"]');
      button.disabled = true; if (output) output.textContent = 'Testing the saved configuration…';
      request('bas_test_provider', { provider: button.dataset.providerTest, task: button.dataset.task }).then(function (response) { if (output) output.textContent = response.success ? '✓ ' + response.data.message : '✕ ' + (response.data.code || 'connection_error'); }).catch(function () { if (output) output.textContent = '✕ connection_error'; }).finally(function () { button.disabled = false; });
      return;
    }
    if (button.dataset.providerModels) {
      const provider = button.dataset.providerModels;
      const output = document.querySelector('[data-models-result="' + provider + '"]');
      button.disabled = true; if (output) output.textContent = 'Fetching the provider model list…';
      request('bas_list_models', { provider: provider }).then(function (response) {
        if (!response.success) { if (output) output.textContent = response.data.code || 'connection_error'; return; }
        const list = document.querySelector('[data-model-list="' + provider + '"]');
        if (list) { list.replaceChildren(); response.data.models.forEach(function (id) { const option = document.createElement('option'); option.value = id; list.append(option); }); }
        if (output) output.textContent = response.data.models.length + ' model IDs returned. Capability metadata is not inferred from unknown IDs.';
      }).catch(function () { if (output) output.textContent = 'connection_error'; }).finally(function () { button.disabled = false; });
    }
  });
  document.querySelectorAll('[data-range-output]').forEach(function (input) {
    const output = document.querySelector('[data-range-output="' + input.name + '"]');
    function update() { if (output) output.textContent = input.value + '%'; }
    input.addEventListener('input', update); update();
  });
  poll();
  window.setInterval(poll, 3000);
})();
