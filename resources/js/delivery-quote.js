export function initDeliveryQuote() {
    const form = document.querySelector('[data-delivery-quote-url]');
    if (!form || form.dataset.quoteReady) return;
    form.dataset.quoteReady = '1';
    const root = form.querySelector('[data-delivery-quote]');
    const status = root.querySelector('[data-delivery-quote-status]');
    const token = form.elements.delivery_quote_token;
    const button = root.querySelector('[data-delivery-recalculate]');
    const total = document.querySelector('[data-checkout-total]');
    const deliveryPrice = document.querySelector('[data-checkout-delivery-price]');
    const deliveryNote = document.querySelector('[data-checkout-delivery-note]');
    const freeShipping = form.querySelector('[data-free-shipping]')?.dataset.freeShipping === '1';
    const selectionMessage = freeShipping
        ? 'Доставка бесплатная. Выберите пункт выдачи, чтобы уточнить срок доставки.'
        : 'Выберите пункт выдачи для расчёта доставки.';
    const money = (kopecks) => new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB' }).format(kopecks / 100);
    const updateSummary = (price, note) => {
        if (deliveryPrice) deliveryPrice.textContent = price;
        if (deliveryNote) deliveryNote.textContent = note;
    };
    const updateUnquotedSummary = (price, note, freeNote) => updateSummary(
        freeShipping ? 'Бесплатно' : price,
        freeShipping ? freeNote : note,
    );
    let sequence = 0;
    let controller;
    let expiry;
    let pending = false;

    const refresh = async () => {
        const current = ++sequence;
        controller?.abort();
        clearTimeout(expiry);
        pending = false;
        button.hidden = true;
        token.value = '';
        total.textContent = money(Number(total.dataset.itemsTotal));
        updateUnquotedSummary('Не рассчитана', 'Выберите пункт выдачи: рассчитаем доставку и добавим её к стоимости товаров.',
            'Доставка бесплатная для вашего заказа. Выберите пункт выдачи, чтобы уточнить срок доставки.');
        const pickup = ['pickup_point', 'cdek_point', 'yandex_point', 'ozon_point'].includes(form.elements.delivery_method.value);
        if (!pickup) {
            status.textContent = form.elements.delivery_method.value
                ? 'Этот способ доставки временно недоступен. Выберите пункт выдачи.'
                : selectionMessage;
            return;
        }
        button.disabled = false;
        const destination = Object.fromEntries(['delivery_city', 'delivery_region', 'delivery_point_method', 'delivery_point_id']
            .map((name) => [name, form.elements[name].value]));
        if (!destination.delivery_point_id) {
            status.textContent = selectionMessage;
            return;
        }
        controller = new AbortController();
        pending = true;
        button.disabled = true;
        status.textContent = freeShipping ? 'Доставка бесплатная. Уточняем срок доставки…' : 'Рассчитываем доставку…';
        updateUnquotedSummary('Рассчитываем…', 'Уточняем тариф. После расчёта доставка появится в сумме к оплате.',
            'Доставка бесплатная для вашего заказа. Уточняем срок доставки.');
        try {
            const response = await fetch(form.dataset.deliveryQuoteUrl, {
                method: 'POST', signal: controller.signal,
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': form.elements._token.value },
                body: JSON.stringify(destination),
            });
            const data = await response.json();
            if (current !== sequence) return;
            if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || 'Не удалось рассчитать доставку. Повторите расчёт или выберите другой пункт выдачи.');
            token.value = data.token;
            total.textContent = money(Number(total.dataset.itemsTotal) + data.price);
            updateSummary(data.price === 0 ? 'Бесплатно' : data.price_label, 'В сумму к оплате включены товары и доставка.');
            const dayForms = { one: 'календарный день', few: 'календарных дня', many: 'календарных дней', other: 'календарного дня' };
            const duration = Number(data.days_min) === Number(data.days_max) ? data.days_max : `${data.days_min}–${data.days_max}`;
            const days = data.days_min != null && data.days_max != null
                ? ` · ${duration} ${dayForms[new Intl.PluralRules('ru').select(Number(data.days_max))]}` : '';
            status.textContent = `Доставка: ${data.price_label}${days}. Включена в сумму к оплате.`;
            expiry = setTimeout(() => {
                token.value = '';
                total.textContent = money(Number(total.dataset.itemsTotal));
                updateUnquotedSummary('Не рассчитана', 'Тариф устарел. Обновите расчёт доставки перед оплатой.',
                    'Доставка бесплатная для вашего заказа. Обновите данные о сроке доставки перед оплатой.');
                status.textContent = freeShipping
                    ? 'Доставка бесплатная. Данные о сроке устарели. Нажмите «Обновить расчёт».'
                    : 'Расчёт доставки устарел. Нажмите «Обновить расчёт».';
                button.textContent = 'Обновить расчёт';
                button.hidden = false;
            }, 10 * 60 * 1000);
        } catch (error) {
            if (current === sequence && error.name !== 'AbortError') {
                updateUnquotedSummary('Не рассчитана', 'Для оплаты нужна стоимость доставки. Повторите расчёт или выберите другой пункт выдачи.',
                    'Доставка бесплатная для вашего заказа. Не удалось подтвердить доставку в этот пункт. Повторите расчёт или выберите другой пункт выдачи.');
                status.textContent = error.message;
                button.textContent = 'Повторить расчёт';
                button.hidden = false;
            }
        } finally {
            if (current === sequence) { pending = false; button.disabled = false; }
        }
    };
    form.addEventListener('livsi:delivery-selection', refresh);
    button.addEventListener('click', refresh);
    form.addEventListener('submit', (event) => {
        if (pending || !token.value) {
            event.preventDefault();
            status.textContent = pending ? 'Дождитесь расчёта доставки.' : 'Выберите пункт выдачи и дождитесь расчёта доставки перед оплатой.';
            status.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }
    });
    refresh();
}
