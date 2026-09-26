(() => {
  'use strict';

  const root = document.querySelector('[data-sg-app]');
  if (!root) return;

  const faDigits = (value) => String(value).replace(/[0-9]/g, (digit) => '۰۱۲۳۴۵۶۷۸۹'[Number(digit)]);
  const formatMoney = (value) => `${faDigits(new Intl.NumberFormat('en-US').format(Math.max(0, Number(value) || 0)))} تومان`;
  const normalize = (value) => String(value || '').trim().toLocaleLowerCase('fa-IR');

  const search = root.querySelector('[data-sg-search]');
  const noResults = root.querySelector('[data-sg-no-results]');
  const items = Array.from(root.querySelectorAll('[data-sg-item]'));
  const categories = Array.from(root.querySelectorAll('.sg-category'));

  const filterMenu = () => {
    const needle = normalize(search?.value);
    let visible = 0;
    items.forEach((item) => {
      const matches = needle === '' || normalize(item.dataset.search).includes(needle);
      item.hidden = !matches;
      if (matches) visible += 1;
    });
    categories.forEach((category) => {
      category.hidden = category.querySelectorAll('[data-sg-item]:not([hidden])').length === 0;
    });
    if (noResults) noResults.hidden = visible !== 0;
  };
  search?.addEventListener('input', filterMenu);

  const quantity = new Map();
  const basketTotal = root.querySelector('[data-sg-basket-total]');
  const submit = root.querySelector('[data-sg-order-submit]');
  const orderMessage = root.querySelector('[data-sg-order-message]');

  const quantityOutput = (id) => root.querySelector(`[data-sg-qty-value="${CSS.escape(String(id))}"]`);
  const itemButton = (id) => root.querySelector(`[data-sg-qty-inc="${CSS.escape(String(id))}"]`);
  const refreshBasket = () => {
    let total = 0;
    let count = 0;
    quantity.forEach((qty, id) => {
      const button = itemButton(id);
      total += qty * Number(button?.dataset.itemPrice || 0);
      count += qty;
      const output = quantityOutput(id);
      if (output) output.textContent = faDigits(qty);
    });
    if (basketTotal) basketTotal.textContent = formatMoney(total);
    if (submit) submit.disabled = submit.dataset.busy === '1' || count === 0 || root.dataset.orderEndpoint === '';
  };

  root.addEventListener('click', (event) => {
    const inc = event.target.closest('[data-sg-qty-inc]');
    const dec = event.target.closest('[data-sg-qty-dec]');
    if (inc) {
      const id = inc.dataset.sgQtyInc;
      quantity.set(id, Math.min(99, (quantity.get(id) || 0) + 1));
      refreshBasket();
      return;
    }
    if (dec) {
      const id = dec.dataset.sgQtyDec;
      const next = Math.max(0, (quantity.get(id) || 0) - 1);
      if (next === 0) quantity.delete(id); else quantity.set(id, next);
      refreshBasket();
    }
  });

  const stableToken = (key) => {
    try {
      const existing = localStorage.getItem(key);
      if (existing && existing.length >= 16) return existing;
      const generated = (globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random()}-${Math.random()}`).replace(/[^A-Za-z0-9._:-]/g, '');
      localStorage.setItem(key, generated);
      return generated;
    } catch (_) {
      return `${Date.now()}-${Math.random()}-${Math.random()}`.replace(/[^A-Za-z0-9._:-]/g, '');
    }
  };

  const postJson = async (url, payload) => {
    const response = await fetch(url, {
      method: 'POST',
      headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
      credentials: 'same-origin',
      body: JSON.stringify(payload),
    });
    let body = {};
    try { body = await response.json(); } catch (_) {}
    return {response, body};
  };

  submit?.addEventListener('click', async () => {
    if (!root.dataset.orderEndpoint || quantity.size === 0) return;
    const lines = [];
    quantity.forEach((qty, id) => {
      if (qty > 0) lines.push({item_id: Number(id), quantity: qty, fulfillment_mode: 'dine_in'});
    });
    if (lines.length === 0) return;

    submit.dataset.busy = '1';
    submit.disabled = true;
    if (orderMessage) {
      orderMessage.classList.remove('is-error');
      orderMessage.textContent = 'در حال ثبت سفارش…';
    }

    try {
      const {response, body} = await postJson(root.dataset.orderEndpoint, {
        installation_id: root.dataset.installationId,
        table_token: root.dataset.tableToken,
        device_token: stableToken('sokna.guest.device'),
        client_token: stableToken(`sokna.guest.order.${root.dataset.tableToken}`),
        items: lines,
      });
      if (!response.ok || body.success !== true) {
        throw new Error(typeof body.message === 'string' && body.message !== '' ? body.message : 'ثبت سفارش انجام نشد.');
      }
      quantity.clear();
      refreshBasket();
      if (orderMessage) orderMessage.textContent = 'سفارش با موفقیت ثبت شد.';
    } catch (error) {
      if (orderMessage) {
        orderMessage.classList.add('is-error');
        orderMessage.textContent = error instanceof Error ? error.message : 'ثبت سفارش انجام نشد.';
      }
    } finally {
      submit.dataset.busy = '0';
      refreshBasket();
    }
  });

  const waiter = root.querySelector('[data-sg-waiter]');
  waiter?.addEventListener('click', async () => {
    if (!root.dataset.waiterEndpoint) return;
    waiter.disabled = true;
    const previous = waiter.textContent;
    waiter.textContent = 'در حال ارسال…';
    try {
      const {response, body} = await postJson(root.dataset.waiterEndpoint, {
        installation_id: root.dataset.installationId,
        action: 'create',
        table_token: root.dataset.tableToken,
        device_token: stableToken('sokna.guest.device'),
        client_token: stableToken(`sokna.guest.waiter.${root.dataset.tableToken}`),
      });
      if (!response.ok || body.success !== true) throw new Error('ارسال فراخوان انجام نشد.');
      waiter.textContent = 'گارسون مطلع شد';
    } catch (_) {
      waiter.textContent = 'ارسال نشد؛ دوباره تلاش کنید';
    } finally {
      globalThis.setTimeout(() => {
        waiter.textContent = previous;
        waiter.disabled = false;
      }, 2500);
    }
  });

  refreshBasket();
})();
