(function () {
  'use strict';
  const config = window.BetaAiSearchConfig || {};
  const texts = config.texts || {};
  const apiUrl = config.apiUrl || '/wp-json/beta-ai-search/v1/';
  const states = new WeakMap();
  const gridSnapshots = new WeakMap();

  function post(path, body, signal) {
    return fetch(apiUrl + path, {
      method: 'POST', credentials: 'same-origin', signal,
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(body)
    }).then(async function (response) {
      const data = await response.json().catch(function () { return {}; });
      if (!response.ok) throw new Error(data.message || texts.error || 'Search failed.');
      return data;
    });
  }

  function isVisible(element) {
    return !!(element.offsetWidth || element.offsetHeight || element.getClientRects().length);
  }

  function candidates(root) {
    const widgetConfig = JSON.parse(root.dataset.basConfig || '{}');
    let elements = [];
    if (widgetConfig.target) {
      try { elements = Array.from(document.querySelectorAll(widgetConfig.target)); } catch (_) { elements = []; }
    } else {
      const loops = Array.from(document.querySelectorAll('.elementor-widget-loop-grid'));
      const loopContainers = loops.map(function (widget) {
        const item = widget.querySelector('.e-loop-item');
        const container = widget.querySelector('.elementor-loop-container, .e-loop-grid, .e-loop__items') || (item ? item.parentElement : null);
        return container ? { kind: 'loop', root: widget, container: container } : null;
      }).filter(Boolean);
      const woo = Array.from(document.querySelectorAll('ul.products')).filter(function (list) {
        return !list.closest('.elementor-widget-loop-grid') && !list.closest('.bas-search');
      }).map(function (list) {
        return { kind: 'woo', root: list.closest('.elementor-widget') || list.parentElement, container: list };
      });
      elements = loopContainers.concat(woo);
    }
    const unique = new Map();
    elements.forEach(function (entry) {
      const target = entry.container ? entry : detect(entry, root);
      if (!target || !target.container || root.contains(target.container) || target.container.closest('.bas-search')) return;
      if (!unique.has(target.container)) unique.set(target.container, target);
    });
    let result = Array.from(unique.values()).sort(function (a, b) {
      return (a.root.compareDocumentPosition(b.root) & Node.DOCUMENT_POSITION_FOLLOWING) ? -1 : 1;
    });
    const following = result.filter(function (target) {
      return !!(root.compareDocumentPosition(target.root) & Node.DOCUMENT_POSITION_FOLLOWING);
    });
    if (!widgetConfig.target && following.length) result = following;
    return widgetConfig.scope === 'all' ? result : result.slice(0, 1);
  }

  function detect(element, root) {
    if (!element || element === root) return null;
    if (element.matches && element.matches('.elementor-widget-loop-grid')) {
      const item = element.querySelector('.e-loop-item');
      const container = element.querySelector('.elementor-loop-container, .e-loop-grid, .e-loop__items') || (item ? item.parentElement : null);
      return container ? { kind: 'loop', root: element, container: container } : null;
    }
    const container = element.matches && element.matches('ul.products') ? element : element.querySelector('ul.products');
    if (container) return { kind: 'woo', root: element, container: container };
    const item = element.querySelector('.e-loop-item');
    if (item) return { kind: 'loop', root: element, container: item.parentElement };
    return null;
  }

  function targetData(target) {
    const data = { ids: [], page: 1, page_size: 12, columns: 4, context: {} };
    if (target.kind === 'loop') {
      const script = target.root.querySelector('.bas-grid-context');
      if (script) {
        try { data.context = JSON.parse(script.textContent || '{}'); } catch (_) {}
      }
      data.page_size = Math.max(1, Math.min(48, target.container.querySelectorAll(':scope > .e-loop-item').length || 6));
    } else {
      data.page_size = Math.max(1, Math.min(48, target.container.querySelectorAll(':scope > li.product, :scope > li.type-product').length || 12));
      const columns = (target.container.className || '').match(/columns-(\d+)/);
      data.columns = columns ? Number(columns[1]) : 4;
    }
    return data;
  }

  function snapshot(target) {
    if (target.saved) return;
    if (gridSnapshots.has(target.container)) {
      target.saved = gridSnapshots.get(target.container);
      return;
    }
    target.saved = {
      html: target.container.innerHTML,
      className: target.container.className,
      display: target.container.style.display,
      hidden: [], nav: null, empty: null
    };
    gridSnapshots.set(target.container, target.saved);
    const scope = target.root || target.container.parentElement;
    const selectors = '.woocommerce-pagination, .elementor-pagination, .e-loop-grid__pagination, .woocommerce-ordering, .woocommerce-result-count, .elementor-widget-woocommerce-products .elementor-pagination';
    scope.querySelectorAll(selectors).forEach(function (element) {
      if (element === target.container || element.contains(target.container)) return;
      target.saved.hidden.push({ element: element, display: element.style.display });
      element.style.display = 'none';
    });
    target.root.classList.add('bas-grid-active');
  }

  function removeExtras(target) {
    const saved = target.saved;
    if (!saved) return;
    if (saved.nav) saved.nav.remove();
    if (saved.empty) saved.empty.remove();
    saved.nav = null;
    saved.empty = null;
  }

  function renderPagination(target, state, result) {
    if (target.saved && target.saved.nav) target.saved.nav.remove();
    if (target.saved) target.saved.nav = null;
    if (!result.pages || result.pages < 2) return;
    const nav = document.createElement('nav');
    nav.className = 'bas-pagination';
    nav.setAttribute('aria-label', texts.label || 'Search results pagination');
    const prev = document.createElement('button');
    prev.type = 'button'; prev.className = 'bas-pagination__button'; prev.textContent = texts.previous || 'Previous';
    prev.disabled = result.page <= 1 || state.busy;
    const next = document.createElement('button');
    next.type = 'button'; next.className = 'bas-pagination__button'; next.textContent = texts.next || 'Next';
    next.disabled = result.page >= result.pages || state.busy;
    const label = document.createElement('span');
    label.className = 'bas-pagination__label'; label.textContent = result.page + ' / ' + result.pages;
    prev.addEventListener('click', function () { changePage(target, state, result.page - 1); });
    next.addEventListener('click', function () { changePage(target, state, result.page + 1); });
    nav.append(prev, label, next);
    target.container.insertAdjacentElement('afterend', nav);
    if (target.saved) target.saved.nav = nav;
  }

  function commit(target, state, result) {
    snapshot(target);
    removeExtras(target);
    target.container.className = target.saved.className;
    target.container.innerHTML = result.html || '';
    target.page = result.page;
    target.pages = result.pages;
    target.page_size = result.page_size;
    if (!result.total) {
      target.container.style.display = 'none';
      const empty = document.createElement('div');
      empty.className = 'bas-grid-empty'; empty.setAttribute('role', 'status');
      empty.textContent = texts.empty || 'No products match your request.';
      target.container.insertAdjacentElement('afterend', empty);
      if (target.saved) target.saved.empty = empty;
    } else {
      target.container.style.display = target.saved.display;
      renderPagination(target, state, result);
    }
    target.container.removeAttribute('aria-busy');
    reinitialize(target.container);
  }

  function reinitialize(container) {
    try {
      if (window.elementorFrontend && window.elementorFrontend.elementsHandler) {
        container.querySelectorAll('.elementor-element').forEach(function (element) {
          window.elementorFrontend.elementsHandler.runReadyTrigger(element);
        });
      }
    } catch (_) {}
    document.dispatchEvent(new CustomEvent('beta-ai-search:updated', { detail: { container: container } }));
    if (window.jQuery) window.jQuery(document.body).trigger('bas_grid_updated', [container]);
  }

  function restore(target) {
    removeExtras(target);
    if (target.saved) {
      target.container.innerHTML = target.saved.html;
      target.container.className = target.saved.className;
      target.container.style.display = target.saved.display;
      target.saved.hidden.forEach(function (entry) { entry.element.style.display = entry.display; });
      gridSnapshots.delete(target.container);
      target.saved = null;
    }
    target.root.classList.remove('bas-grid-active');
    target.container.removeAttribute('aria-busy');
    reinitialize(target.container);
  }

  function setBusy(root, state, busy) {
    state.busy = busy;
    root.classList.toggle('is-loading', busy);
    root.querySelector('.bas-search__submit').disabled = busy;
    root.querySelector('.bas-search__spinner').hidden = !busy;
    root.querySelector('.bas-search__input').readOnly = busy;
    root.setAttribute('aria-busy', busy ? 'true' : 'false');
    (state.targets || []).forEach(function (target) {
      const nav = target.saved && target.saved.nav;
      if (!nav) return;
      const buttons = nav.querySelectorAll('.bas-pagination__button');
      if (buttons[0]) buttons[0].disabled = busy || target.page <= 1;
      if (buttons[1]) buttons[1].disabled = busy || target.page >= target.pages;
    });
  }

  async function run(root, state, query) {
    if (state.busy || !query.trim()) return;
    if (document.body.classList.contains('elementor-editor-active') || root.closest('.elementor-editor-preview')) return;
    const targets = candidates(root);
    const status = root.querySelector('.bas-search__status');
    const retry = root.querySelector('.bas-search__retry');
    retry.hidden = true;
    if (!targets.length) {
      status.dataset.kind = 'error'; status.textContent = texts.error || 'Search could not be completed.';
      return;
    }
    state.lastQuery = query;
    state.controller = new AbortController();
    const timeout = setTimeout(function () { state.controller.abort(); }, 90000);
    setBusy(root, state, true);
    status.dataset.kind = 'loading'; status.textContent = texts.loading || 'Searching…';
    targets.forEach(function (target) { target.container.setAttribute('aria-busy', 'true'); });
    try {
      const result = await post('search', { query: query }, state.controller.signal);
      if (!Array.isArray(result.ids)) throw new Error(texts.error);
      const renders = await Promise.all(targets.map(function (target) {
        const data = targetData(target);
        data.ids = result.ids;
        data.page = 1;
        state.ids = result.ids;
        return post('render', data, state.controller.signal).then(function (rendered) { return { target: target, rendered: rendered, data: data }; });
      }));
      // Nothing is committed until every requested existing grid has rendered successfully.
      renders.forEach(function (entry) {
        entry.target.ids = result.ids;
        entry.target.columns = entry.data.columns;
        entry.target.context = entry.data.context;
        commit(entry.target, state, entry.rendered);
      });
      state.targets = targets;
      state.active = true;
      root.querySelector('.bas-search__reset').hidden = false;
      status.dataset.kind = result.total ? 'success' : 'empty';
      status.textContent = result.total ? (texts.results || '{count} products').replace('{count}', String(result.total)) : (texts.empty || 'No products match your request.');
    } catch (error) {
      targets.forEach(function (target) { target.container.removeAttribute('aria-busy'); });
      status.dataset.kind = 'error';
      status.textContent = error.name === 'AbortError' ? (texts.error || 'Search timed out.') : (texts.error || 'Search could not be completed.');
      retry.hidden = false;
    } finally {
      clearTimeout(timeout);
      setBusy(root, state, false);
      state.controller = null;
    }
  }

  async function changePage(target, state, page) {
    if (state.busy || page < 1 || page > target.pages) return;
    setBusy(targetRoot(state), state, true);
    const controller = new AbortController();
    state.controller = controller;
    const timeout = setTimeout(function () { controller.abort(); }, 45000);
    try {
      const rendered = await post('render', { ids: target.ids || state.ids, page: page, page_size: target.page_size, columns: target.columns, context: target.context || {} }, controller.signal);
      commit(target, state, rendered);
    } catch (_) {
      const root = targetRoot(state);
      const status = root.querySelector('.bas-search__status');
      status.dataset.kind = 'error'; status.textContent = texts.error || 'Search could not be completed.';
      root.querySelector('.bas-search__retry').hidden = false;
    } finally {
      clearTimeout(timeout);
      setBusy(targetRoot(state), state, false);
      state.controller = null;
    }
  }

  function targetRoot(state) { return state.root; }

  function init(root) {
    if (states.has(root)) return;
    const state = { root: root, busy: false, active: false, targets: [], lastQuery: '', ids: [] };
    states.set(root, state);
    const form = root.querySelector('.bas-search__form');
    const input = root.querySelector('.bas-search__input');
    const clear = root.querySelector('.bas-search__clear-text');
    const reset = root.querySelector('.bas-search__reset');
    const retry = root.querySelector('.bas-search__retry');
    form.addEventListener('submit', function (event) { event.preventDefault(); run(root, state, input.value); });
    input.addEventListener('input', function () { clear.hidden = !input.value; });
    clear.addEventListener('click', function () { input.value = ''; clear.hidden = true; input.focus(); });
    reset.addEventListener('click', function () {
      if (state.controller) state.controller.abort();
      (state.targets.length ? state.targets : candidates(root)).forEach(restore);
      state.active = false; state.targets = []; state.ids = [];
      reset.hidden = true; retry.hidden = true;
      root.querySelector('.bas-search__status').textContent = '';
    });
    retry.addEventListener('click', function () { run(root, state, state.lastQuery || input.value); });
  }

  function initAll(scope) { (scope || document).querySelectorAll('.bas-search').forEach(init); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { initAll(); });
  else initAll();
  const observer = new MutationObserver(function (mutations) {
    mutations.forEach(function (mutation) {
      mutation.addedNodes.forEach(function (node) {
        if (node.nodeType !== 1) return;
        if (node.matches && node.matches('.bas-search')) init(node);
        else initAll(node);
      });
    });
  });
  observer.observe(document.documentElement, { childList: true, subtree: true });
  if (window.jQuery) {
    window.jQuery(window).on('elementor/frontend/init', function () {
      if (window.elementorFrontend && window.elementorFrontend.hooks) {
        window.elementorFrontend.hooks.addAction('frontend/element_ready/beta-ai-search.default', function ($scope) { initAll($scope[0]); });
      }
    });
  }
  window.BetaAiSearch = { init: initAll, reset: function (root) { const state = states.get(root); if (state) root.querySelector('.bas-search__reset').click(); } };
})();
