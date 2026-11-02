export function initOzonClientCheck(root, onChange) {
    if (!root.dataset.ozonCheckUrl) return;
    const form = root.closest('form');
    const phone = form.elements.phone;
    const statuses = document.querySelectorAll('[data-ozon-check-status]');
    const retry = root.querySelector('[data-ozon-check-retry]');
    let sequence = 0;
    let controller;
    let timer;
    let checkedPhone;
    let checkingPhone;
    let allowed = false;

    const normalize = () => {
        if (!/^[+\d\s().-]+$/.test(phone.value)) return null;
        let digits = phone.value.replace(/\D/g, '');
        if (/^9\d{9}$/.test(digits)) digits = `7${digits}`;
        if (/^8\d{10}$/.test(digits)) digits = `7${digits.slice(1)}`;
        return /^7[3489]\d{9}$/.test(digits) ? `+${digits}` : null;
    };
    const setState = (canDeliver, message, canRetry = false) => {
        allowed = canDeliver;
        statuses.forEach((status) => { status.textContent = message; });
        retry.hidden = !canRetry;
        onChange(allowed);
    };
    const refresh = (delay = 0) => {
        const normalized = normalize();
        if (normalized && (normalized === checkedPhone || normalized === checkingPhone)) return;
        const current = ++sequence;
        controller?.abort();
        clearTimeout(timer);
        checkedPhone = null;
        checkingPhone = null;
        if (!normalized) {
            setState(false, 'Для доставки Ozon укажите полный телефон получателя в форме заказа.');
            return;
        }
        setState(false, 'Проверяем доступность доставки Ozon для этого номера…');
        checkingPhone = normalized;
        timer = setTimeout(async () => {
            controller = new AbortController();
            const requestController = controller;
            const timeout = setTimeout(() => requestController.abort(), 15000);
            try {
                const response = await fetch(root.dataset.ozonCheckUrl, {
                    method: 'POST', signal: controller.signal,
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': form.elements._token.value },
                    body: JSON.stringify({ phone: normalized }),
                });
                const data = await response.json();
                if (current !== sequence) return;
                if (!response.ok || typeof data.can_be_delivered !== 'boolean') throw new Error('Ozon check unavailable');
                checkedPhone = normalized;
                checkingPhone = null;
                setState(data.can_be_delivered, data.can_be_delivered
                    ? 'Ozon подтвердил доступность доставки на этот номер.'
                    : 'Ozon не подтвердил доставку на этот номер. Проверьте номер в аккаунте Ozon или выберите другую службу.');
            } catch (error) {
                if (current === sequence) {
                    checkingPhone = null;
                    setState(false, 'Не удалось проверить доставку Ozon. Повторите проверку или выберите другую службу.', true);
                }
            } finally {
                clearTimeout(timeout);
            }
        }, delay);
    };
    phone.addEventListener('input', () => refresh(600));
    phone.addEventListener('change', () => refresh());
    retry.addEventListener('click', () => { checkedPhone = null; refresh(); });
    form.addEventListener('submit', (event) => {
        const method = form.elements.delivery_method.value;
        if (!allowed && (method === 'ozon_point' || (method === 'pickup_point' && form.elements.delivery_point_method.value === 'ozon_point'))) {
            event.preventDefault();
            root.querySelector('[data-points-dialog]').close();
            phone.focus();
        }
    });
    refresh();
}
