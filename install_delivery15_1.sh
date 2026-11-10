#!/bin/bash
# =====================================================================
#  LIVSI · исправление 15.1 — после первой проверки возвратов:
#   — в списке заказов под номером видно «↩ Клиент просит вернуть»
#     («ждём товар», «вернуть деньги»), вкладка «Возвраты» с цифрой;
#   — Telegram: одно сообщение вместо трёх. Если Телеграм принял сообщение,
#     но не ответил вовремя, сайт больше не шлёт его повторно; фоновые
#     уведомления ждут ответа 20 секунд вместо 5;
#   — текст про обратную доставку мягче: «если она платная — удержим»,
#     при нулевой сумме в письме — «за наш счёт».
#  Ставится поверх обновления 15. Запускать от root.
#  Стили и скрипты страницы не меняются.
#
#    bash install_delivery15_1.sh check        — только проверка, ничего не меняет
#    bash install_delivery15_1.sh              — установка (с бэкапом)
#    bash install_delivery15_1.sh rollback     — вернуть файлы из последнего бэкапа
# =====================================================================
set -uo pipefail

APP=/var/www/livsi
BACKUPS=/root/livsi-backups
MIGRATIONS=()
# Собранный файл скриптов страницы (лежит в архиве ниже) и стили, которые
# дописываются к нынешнему файлу стилей сайта.
ASSET_JS="assets/app-YT4IrMBC.js"
CSS_MARK='.return-item{'
CSS_ADD='.return-item{display:flex;align-items:center;justify-content:space-between;gap:16px}.return-item-check{flex:1;min-width:0;font-size:.875rem}.return-item-qty{display:grid;flex:1;gap:8px;min-width:0}.return-item-qty select{max-width:12rem}.return-reasons .delivery-choice{min-height:0}'
MANIFEST="public/build/manifest.json"
MODE="${1:-install}"
SITE_URL="${SITE_URL:-https://livsi.shop}"
STAMP="$(date +%Y%m%d-%H%M%S)"

red()    { printf '\033[31m%s\033[0m\n' "$*"; }
green()  { printf '\033[32m%s\033[0m\n' "$*"; }
yellow() { printf '\033[33m%s\033[0m\n' "$*"; }
step()   { printf '\n\033[1m— %s\033[0m\n' "$*"; }
die()    { red "ОСТАНОВЛЕНО: $*"; exit 1; }

[ "$(id -u)" -eq 0 ] || die "запусти от root"
[ -f "$APP/artisan" ] || die "не найден проект $APP"
OWNER="$(stat -c %U "$APP/storage")"
art() { (cd "$APP" && runuser -u "$OWNER" -- php artisan "$@"); }

# Файлы, которые правка меняет, и их md5 сейчас на сервере (обновление 15)
CHANGED=(
  "86c286257d0766b0c5fa1bc9d668e5b9 app/Filament/Resources/Orders/Pages/ListOrders.php"
  "26a48b5d7a44c96358d13ca11e8aa7f5 app/Filament/Resources/Orders/Tables/OrdersTable.php"
  "a094f2c77aa6c05294e8c11cc2ea2b79 app/Jobs/SendBusinessTelegram.php"
  "0cf3e716388202f5ede2152b5b5e0b3a app/Jobs/SendPaymentTelegram.php"
  "a109ed850e9970d7e626310f150e01b2 app/Jobs/SendShipmentTelegram.php"
  "9af963b03e28d701f700cacef3fcedaa app/Monitoring/Telegram.php"
  "0727f6e6e8fe70f50d30288951b1046e resources/views/mail/return-approved.blade.php"
  "5894a9e9633c8f0b4472a27856b8476d resources/views/mail/return-requested.blade.php"
  "2fde77bb6d0615e64bdd2fa895c637ac resources/views/pages/order-return.blade.php"
  "935ef503ba602046bb8b4256f327db2f tests/Feature/OrderReturnsTest.php"
)
# Новые файлы правки
ADDED=(
  "tests/Feature/TelegramDeliveryTest.php"
  "public/build/assets/app-YT4IrMBC.js"
)
# Файлы, которые правка убирает, и их md5 сейчас на сервере
REMOVED=(
)
# Не меняются, но входят в собранный файл скриптов — должны совпадать с ожидаемыми
BUNDLED=(
  "5f8bc6a430cddd1f9c8efd32cb980228 resources/js/ozon-client-check.js"
)

payload() { sed -n '/^__PAYLOAD_BELOW__$/,$p' "$0" | tail -n +2 | base64 -d; }

# Какие файлы скриптов и стилей подключает сайт (public/build/manifest.json)
manifest_get() {
    php -r '$m = json_decode((string) @file_get_contents($argv[1]), true); echo is_array($m) ? ($m[$argv[2]]["file"] ?? "") : "";' "$APP/$MANIFEST" "$1"
}
manifest_set() {   # manifest_set СТИЛИ СКРИПТЫ
    php -r '$f = $argv[1]; $m = json_decode(file_get_contents($f), true);
        $m["resources/css/app.css"]["file"] = $argv[2];
        $m["resources/js/app.js"]["file"] = $argv[3];
        unset($m["resources/js/app.js"]["imports"], $m["resources/js/app.js"]["dynamicImports"]);
        file_put_contents($f, json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");' "$APP/$MANIFEST" "$1" "$2"
}

# ------------------------------------------------------------- .env
env_get() { grep -E "^$1=" "$APP/.env" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
env_set() {
    local key="$1" value="$2" line
    if [[ "$value" =~ ^[A-Za-z0-9_.:/+=@-]*$ ]]; then line="$key=$value"; else line="$key=\"${value//\"/\\\"}\""; fi
    if grep -qE "^$key=" "$APP/.env"; then
        KEY="$key" LINE="$line" awk 'BEGIN{k=ENVIRON["KEY"]; l=ENVIRON["LINE"]} index($0, k"=")==1 {print l; next} {print}' "$APP/.env" > "$APP/.env.tmp" \
            && cat "$APP/.env.tmp" > "$APP/.env" && rm -f "$APP/.env.tmp"
    else
        printf '\n%s\n' "$line" >> "$APP/.env"
    fi
}
env_backup() {
    mkdir -p "$BACKUPS"
    cp -p "$APP/.env" "$BACKUPS/env-$STAMP" && chmod 600 "$BACKUPS/env-$STAMP"
    echo "  копия настроек: $BACKUPS/env-$STAMP"
}
provider_of() {
    case "$1" in
        cdek|сдэк) echo cdek ;;
        yandex|yataxi|яндекс) echo yataxi ;;
        ozon|ozondel|озон) echo ozondel ;;
        *) echo "" ;;
    esac
}
env_key_of() { case "$1" in cdek) echo DELIVERY_CDEK ;; yataxi) echo DELIVERY_YANDEX ;; ozondel) echo DELIVERY_OZON ;; esac; }

preflight() {
    step "Проверяю сервер"
    local bad=0 entry file sum now installed=0
    for entry in "${CHANGED[@]}"; do
        sum="${entry%% *}"; file="${entry#* }"
        if [ ! -f "$APP/$file" ]; then red "  нет файла: $file"; bad=1; continue; fi
        # сверяем без учёта концов строк: часть файлов на сервере сохранена с CRLF
        now="$(tr -d '\r' < "$APP/$file" | md5sum | cut -d' ' -f1)"
        if payload | tar -xzO "./$file" 2>/dev/null | md5sum | grep -q "^$now "; then echo "  уже установлено  $file"; installed=$((installed + 1))
        elif [[ "|$sum|" == *"|$now|"* ]]; then echo "  ок  $file"
        else red "  ИЗМЕНЁН разработчиком: $file"; bad=1; fi
    done
    for file in "${ADDED[@]}"; do
        # Тесты на работу сайта не влияют — их прежнюю версию просто заменяем.
        case "$file" in tests/*) continue ;; esac
        if [ -e "$APP/$file" ] && ! payload | tar -xzO "./$file" 2>/dev/null | cmp -s - "$APP/$file"; then
            red "  уже есть другой файл: $file"; bad=1
        fi
    done
    local gone=0
    for entry in "${REMOVED[@]}"; do
        sum="${entry%% *}"; file="${entry#* }"
        if [ ! -e "$APP/$file" ]; then echo "  уже убран  $file"; gone=$((gone + 1))
        elif [ "$(tr -d '\r' < "$APP/$file" | md5sum | cut -d' ' -f1)" = "$sum" ]; then echo "  ок (уберу)  $file"
        else red "  ИЗМЕНЁН разработчиком: $file"; bad=1; fi
    done
    for entry in "${BUNDLED[@]}"; do
        sum="${entry%% *}"; file="${entry#* }"
        now="$(tr -d '\r' < "$APP/$file" 2>/dev/null | md5sum | cut -d' ' -f1)"
        if [[ "|$sum|" == *"|$now|"* ]]; then echo "  ок  $file"; else red "  ИЗМЕНЁН разработчиком: $file"; bad=1; fi
    done
    CUR_CSS="$(manifest_get resources/css/app.css)"; CUR_JS="$(manifest_get resources/js/app.js)"
    if [ -z "$CUR_CSS" ] || [ -z "$CUR_JS" ] || [ ! -f "$APP/public/build/$CUR_CSS" ]; then
        red "  не нашёл собранные скрипты и стили сайта ($MANIFEST)"; bad=1
    else
        echo "  скрипты сайта: $CUR_JS, стили: $CUR_CSS"
    fi
    ALREADY=0; [ "$installed" -eq "${#CHANGED[@]}" ] && [ "$gone" -eq "${#REMOVED[@]}" ] && [ "$CUR_JS" = "$ASSET_JS" ] \
        && [ -n "$CUR_CSS" ] && grep -qF -e "$CSS_MARK" "$APP/public/build/$CUR_CSS" 2>/dev/null && ALREADY=1
    [ "$bad" -eq 0 ] || die "файлы на сервере не такие, как после обновления 15. Если 15-е ещё не ставили — сначала поставьте его (install_delivery15.sh), потом это. Иначе их поменял разработчик: ничего не тронуто, пришлите этот вывод — пересоберу правку."

    local pending
    local skip=(-e '^$') m
    for m in "${MIGRATIONS[@]}"; do skip+=(-e "$m"); done
    pending="$(art migrate:status --pending 2>&1 | grep -E '^\s+[0-9]{4}_' | grep -v "${skip[@]}" || true)"
    [ -z "$pending" ] || die "на сервере есть чужие неприменённые миграции, сначала их должен применить разработчик:
$pending"
    green "  Проверка пройдена"
}

finish_caches() {
    # Ровно те кеши, что стояли на сервере: настройки, маршруты, события, шаблоны.
    local what
    for what in config route event view; do art "$what:clear" >/dev/null 2>&1; done
    for what in config route event view; do art "$what:cache" >/dev/null || return 1; done
    local fpm
    fpm="$(systemctl list-units --type=service --state=running --no-legend 2>/dev/null | awk '{print $1}' | grep -E '^php[0-9.]+-fpm\.service$' | head -1)"
    [ -n "$fpm" ] && systemctl reload "$fpm"
    art queue:restart >/dev/null 2>&1 || true
    return 0
}

smoke() {
    local home admin api
    home="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$SITE_URL/")"
    admin="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$SITE_URL/admin/login")"
    api="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 -H 'Accept: application/json' "$SITE_URL/api/livsipro/orders")"
    echo "  главная: $home, вход в админку: $admin, дверь для livsipro: $api (401 или 503 — так и надо)"
    [ "$home" = "200" ] && [ "$admin" = "200" ] && { [ "$api" = "401" ] || [ "$api" = "503" ]; }
}

autoload_refresh() {
    # если composer собран со строгой картой классов — пересобрать её под новые/убранные файлы
    if grep -q 'setClassMapAuthoritative(true)' "$APP/vendor/composer/autoload_real.php" 2>/dev/null; then
        (cd "$APP" && runuser -u "$OWNER" -- composer dump-autoload -o --classmap-authoritative --no-scripts -q)
    fi
}

restore_files() {
    local dir="$1" file
    tar -xzf "$dir/files-before.tar.gz" -C "$APP" --same-owner
    while read -r file; do [ -n "$file" ] && rm -f "$APP/$file"; done < "$dir/added-files.txt"
    # пустые папки, оставшиеся от новых файлов (app/Delivery и т. п.)
    while read -r file; do
        d="$(dirname "$APP/$file")"
        while [ "$d" != "$APP" ] && rmdir "$d" 2>/dev/null; do d="$(dirname "$d")"; done
    done < "$dir/added-files.txt"
    autoload_refresh
    finish_caches
}

mail_note() {
    local m
    m="$(env_get MAIL_MAILER)"
    case "${m:-log}" in
        log|array) yellow "  ВНИМАНИЕ: почта сайта не настроена (MAIL_MAILER=${m:-log}) — рассылка откажется отправлять, пока не настроите почту." ;;
        *) echo "  почта сайта: $m (пробное письмо — админка → «Рассылки» → «Пробное письмо себе»)" ;;
    esac
}

scheduler_note() {
    if crontab -l -u "$OWNER" 2>/dev/null | grep -q 'schedule:run' || crontab -l 2>/dev/null | grep -q 'schedule:run' \
        || grep -rqs 'schedule:run' /etc/cron.d /etc/crontab || systemctl list-timers --all 2>/dev/null | grep -qi 'schedule'; then
        echo "  расписание сайта работает: пункты обновляются ночью, статусы — каждые 15 минут"
    else
        yellow "  ВНИМАНИЕ: не нашёл запуск расписания (schedule:run). Без него статусы служб сами не подтянутся."
        yellow "  Добавить: (crontab -l -u $OWNER; echo '* * * * * cd $APP && php artisan schedule:run >> /dev/null 2>&1') | crontab -u $OWNER -"
    fi
}

ask_secret() {   # ask_secret КЛЮЧ "что это"
    local key="$1" what="$2" now value
    now="$(env_get "$key")"
    printf '  %s%s: ' "$what" "${now:+ (уже есть — Enter, чтобы оставить)}"
    IFS= read -r -s value; echo
    value="$(printf '%s' "$value" | tr -d '\r' | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
    if [ -n "$value" ]; then env_set "$key" "$value"; echo "    записано ($key, ${#value} знаков)"; fi
}

case "$MODE" in
check)
    preflight
    echo; green "Можно ставить: bash $0"
    ;;

install)
    preflight
    if [ "$ALREADY" -eq 1 ]; then
        green "Обновление уже установлено."
        exit 0
    fi

    step "Бэкап"
    BK="$BACKUPS/delivery-$STAMP"
    mkdir -p "$BK"
    files=(); for entry in "${CHANGED[@]}"; do files+=("${entry#* }"); done
    gone_files=(); for entry in "${REMOVED[@]}"; do file="${entry#* }"; [ -e "$APP/$file" ] && gone_files+=("$file"); done
    tar -czf "$BK/files-before.tar.gz" -C "$APP" "${files[@]}" "${gone_files[@]}" "$MANIFEST" || die "не удалось сохранить файлы"
    : > "$BK/added-files.txt"
    for file in "${ADDED[@]}"; do [ -e "$APP/$file" ] || echo "$file" >> "$BK/added-files.txt"; done
    cp -p "$APP/.env" "$BK/env-before" && chmod 600 "$BK/env-before"
    DB="$(env_get DB_DATABASE)"
    if runuser -u postgres -- pg_dump "$DB" 2>"$BK/pg_dump.err" | gzip > "$BK/db-before.sql.gz" && [ -s "$BK/db-before.sql.gz" ] && [ ! -s "$BK/pg_dump.err" ]; then
        echo "  база: $BK/db-before.sql.gz ($(du -h "$BK/db-before.sql.gz" | cut -f1))"
    else
        die "не удалось сделать бэкап базы ($(head -c 300 "$BK/pg_dump.err")). Ничего не установлено."
    fi
    echo "  файлы: $BK/files-before.tar.gz"

    step "Кладу файлы"
    payload | tar -xzf - -C "$APP" --no-same-owner || die "не удалось распаковать файлы"
    for file in "${gone_files[@]}"; do rm -f "$APP/$file"; echo "  убран старый файл: $file"; done
    for file in "${ADDED[@]}" "${files[@]}"; do chown "$OWNER:$OWNER" "$APP/$file"; chmod 644 "$APP/$file"; done
    for file in "${ADDED[@]}"; do d="$(dirname "$APP/$file")"; while [ "$d" != "$APP" ]; do chown "$OWNER:$OWNER" "$d"; d="$(dirname "$d")"; done; done
    autoload_refresh
    # Ключ двери для livsipro: длинная случайная строка, создаётся один раз.
    if [ -z "$(env_get LIVSIPRO_API_TOKEN)" ]; then
        env_set LIVSIPRO_API_TOKEN "$(openssl rand -hex 32)"
        echo "  создан ключ связи с livsipro"
    fi
    echo "  готово"

    step "Скрипты и стили страницы"
    if grep -qF -e "$CSS_MARK" "$APP/public/build/$CUR_CSS"; then
        NEW_CSS="$CUR_CSS"; echo "  стили уже есть в $CUR_CSS"
    else
        tmpc="$(mktemp)"
        { cat "$APP/public/build/$CUR_CSS"; printf '\n%s\n' "$CSS_ADD"; } > "$tmpc"
        NEW_CSS="assets/app-$(md5sum "$tmpc" | cut -c1-8).css"
        install -o "$OWNER" -g "$OWNER" -m 644 "$tmpc" "$APP/public/build/$NEW_CSS"; rm -f "$tmpc"
        [ -e "$BK/css-added" ] || { echo "public/build/$NEW_CSS" >> "$BK/added-files.txt"; : > "$BK/css-added"; }
        echo "  стили: $CUR_CSS + форма возврата → $NEW_CSS"
    fi
    if ! manifest_set "$NEW_CSS" "$ASSET_JS" || [ "$(manifest_get resources/js/app.js)" != "$ASSET_JS" ]; then
        red "не удалось подключить новые скрипты — возвращаю файлы"
        restore_files "$BK"; die "установка отменена, файлы в прежнем виде. Бэкап: $BK"
    fi
    echo "  скрипты: $CUR_JS → $ASSET_JS"


    if [ "${#MIGRATIONS[@]}" -gt 0 ]; then
        step "Миграция"
        for m in "${MIGRATIONS[@]}"; do
            if ! art migrate --force --path="database/migrations/$m.php"; then
                red "миграция $m не прошла — возвращаю файлы"
                restore_files "$BK"; die "установка отменена, файлы в прежнем виде. Бэкап: $BK"
            fi
        done
    fi

    step "Кеш настроек, маршрутов и шаблонов, PHP-FPM, очередь"
    finish_caches || { red "не удалось обновить кеш — возвращаю файлы"; restore_files "$BK"; die "установка отменена. Бэкап: $BK"; }
    echo "  готово"

    step "Фото к возвратам"
    # Только смотрим, ничего не меняем: сколько сервер принимает за один раз.
    runuser -u "$OWNER" -- mkdir -p "$APP/storage/app/private/returns" 2>/dev/null
    runuser -u "$OWNER" -- test -w "$APP/storage/app/private/returns" && echo "  папка для фото: storage/app/private/returns — запись есть" \
        || yellow "  ВНИМАНИЕ: сайт не может писать в storage/app/private/returns — заявки примутся, но без фото"
    body="$(nginx -T 2>/dev/null | grep -E '^\s*client_max_body_size' | awk '{print $2}' | tr -d ';' | sort -u | tr '\n' ' ')"
    fpm_ini="$(ls /etc/php/*/fpm/php.ini 2>/dev/null | tail -1)"
    upl="$( [ -n "$fpm_ini" ] && grep -E '^\s*upload_max_filesize' "$fpm_ini" | tail -1 | cut -d= -f2 | tr -d ' ')"
    post="$( [ -n "$fpm_ini" ] && grep -E '^\s*post_max_size' "$fpm_ini" | tail -1 | cut -d= -f2 | tr -d ' ')"
    echo "  nginx client_max_body_size: ${body:-не задан (по умолчанию 1m)}; PHP upload_max_filesize: ${upl:-?}, post_max_size: ${post:-?}"
    echo "  (страница возврата сама сжимает фото с телефона до ~300 КБ — обычно хватает и 1m)"

    step "Проверяю сайт"
    sleep 2
    page="$(curl -s --max-time 20 "$SITE_URL/")"
    js_on=0; css_on=0
    case "$page" in *"build/$ASSET_JS"*) js_on=1 ;; esac
    case "$page" in *"build/$NEW_CSS"*) css_on=1 ;; esac
    js_code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$SITE_URL/build/$ASSET_JS")"
    css_code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$SITE_URL/build/$NEW_CSS")"
    echo "  новые скрипты на странице: $([ $js_on -eq 1 ] && echo да || echo НЕТ), открываются: $js_code"
    echo "  стили на странице: $([ $css_on -eq 1 ] && echo да || echo НЕТ), открываются: $css_code"
    dlv="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$SITE_URL/admin/order-returns")"
    echo "  страница «Возвраты» в админке: $dlv (302 — просит войти, так и надо)"
    ret="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$SITE_URL/order/LV-NE-SUSHCHESTVUET/return")"
    echo "  страница возврата для чужого заказа: $ret (404 — так и надо)"
    pay="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$SITE_URL/admin/payment-integrations")"
    echo "  страница «Интеграции оплаты»: $pay (302 — просит войти, так и надо)"
    hook="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 -X POST -H 'Content-Type: text/plain' --data 'check' "$SITE_URL/webhooks/tochka")"
    echo "  адрес для уведомлений Точки: $hook (200 — так и надо)"
    if ! smoke || [ "$js_on" -ne 1 ] || [ "$css_on" -ne 1 ] || [ "$js_code" != "200" ] || [ "$css_code" != "200" ] \
        || [ "$dlv" != "302" ] || [ "$ret" != "404" ] || [ "$pay" != "302" ] || [ "$hook" != "200" ] \
        || ! art delivery:mode >/dev/null 2>&1 || ! art returns:sync >/dev/null 2>&1; then
        red "сайт отвечает не так — возвращаю файлы"
        restore_files "$BK"
        smoke || true
        die "установка отменена, файлы возвращены. Бэкап: $BK"
    fi
    scheduler_note

    echo
    green "ГОТОВО. Исправление 15.1: возвраты в списке заказов, одно сообщение в Telegram."
    echo "Бэкап: $BK"
    echo "Где смотреть: «Заказы» → вкладка «Возвраты» и оранжевая строка под номером заказа."
    echo "На сайте и в админке обновите кеш браузера: Cmd+Shift+R."
    ;;

chek)
    n="${2:-}"
    [ -n "$n" ] || die "так: bash $0 chek LV-123456  (номер заказа с сайта)"
    art receipts:sync --show="$n" || true
    ;;

keys)
    step "Ключи служб доставки"
    echo "  Ввод скрыт — так и должно быть. Вставили, нажали Enter. Пусто + Enter — пропустить."
    env_backup
    echo; echo "  СДЭК (личный кабинет → Интеграция → ключ):"
    ask_secret CDEK_CLIENT_ID "Account"
    ask_secret CDEK_CLIENT_SECRET "Secure password"
    echo; echo "  Яндекс Доставка (кабинет → Интеграция → токен):"
    ask_secret YANDEX_DELIVERY_TOKEN "OAuth-токен"
    echo; echo "  Ozon Доставка для бизнеса (кабинет Ozon Доставки → частные приложения → ключик):"
    ask_secret OZON_APP_CLIENT_ID "client_id приложения"
    ask_secret OZON_APP_CLIENT_SECRET "client_secret приложения"
    [ -n "$(env_get LIVSIPRO_API_TOKEN)" ] || env_set LIVSIPRO_API_TOKEN "$(openssl rand -hex 32)"
    art config:cache >/dev/null || die "настройки не применились — верните копию: cp $BACKUPS/env-$STAMP $APP/.env"

    for p in cdek yataxi ozondel; do echo; art delivery:check "$p" || true; done
    echo
    green "Ключи записаны. Службы всё ещё на ApiShip — ничего не переключено."
    echo "Дальше:"
    echo "  1) выбрать пункт сдачи посылок из списков выше:"
    echo "       bash $0 sdacha cdek КОД      bash $0 sdacha yandex КОД"
    echo "  2) или всё то же — в админке сайта: «Интеграции доставки»"
    echo "  3) переключить службу: bash $0 switch cdek direct"
    ;;

sdacha)
    p="$(provider_of "${2:-}")"; code="${3:-}"
    [ -n "$p" ] && [ -n "$code" ] || die "так: bash $0 sdacha cdek КОД  (yandex — номер пункта, ozon — ID метода отгрузки)"
    art delivery:check "$p" --origin="$code" || die "пункт не сохранён"
    ;;

switch)
    p="$(provider_of "${2:-}")"; to="${3:-}"
    [ -n "$p" ] && { [ "$to" = "direct" ] || [ "$to" = "apiship" ]; } || die "так: bash $0 switch cdek direct  (службы: cdek, yandex, ozon; direct или apiship)"
    key="$(env_key_of "$p")"
    if [ "$to" = "direct" ]; then
        step "Загружаю пункты выдачи службы (бывает несколько минут)"
        art delivery:points "$p" || die "пункты не загрузились — служба остаётся как была"
        step "Проверка перед переключением"
        out="$(art delivery:check "$p" 2>&1)"; echo "$out"
        if [ "$p" != "ozondel" ] && echo "$out" | grep -q 'пункт сдачи в настройках: не выбран'; then
            die "не выбран пункт сдачи посылок: bash $0 sdacha ${2} КОД"
        fi
        if [ "$p" = "ozondel" ] && echo "$out" | grep -q 'метод отгрузки в настройках: не выбран'; then
            die "не указан метод отгрузки Ozon: админка → «Интеграции доставки»"
        fi
    fi
    art delivery:mode "$p" "$to" || die "не переключилось"
    smoke || yellow "  сайт ответил не так, как ждали — проверьте оформление заказа"
    green "Готово. Откат: bash $0 switch ${2} $([ "$to" = direct ] && echo apiship || echo direct). То же можно в админке: «Интеграции доставки»."
    ;;

status)
    step "Как работают службы"
    art delivery:mode
    [ -n "$(env_get LIVSIPRO_API_TOKEN)" ] && echo "  ключ связи с livsipro: есть" || echo "  ключ связи с livsipro: нет"
    if art list --raw 2>/dev/null | grep -q '^receipts:sync'; then
        echo "  вторые чеки и возвраты: установлены (включение — «Настройки сайта» → «Налоги и чеки»)"
    fi
    if art list --raw 2>/dev/null | grep -q '^mailings:send'; then
        echo "  клиенты, промокоды, рассылки: установлены"
    fi
    scheduler_note
    mail_note
    ;;

rollback)
    BK="$(ls -d "$BACKUPS"/delivery-* 2>/dev/null | sort | tail -1)"
    [ -n "$BK" ] || die "бэкапов delivery-* не найдено"
    step "Возвращаю файлы из $BK"
    restore_files "$BK"
    smoke || true
    art delivery:mode 2>/dev/null || true
    green "Файлы возвращены к состоянию обновления 15."
    ;;

*)
    die "неизвестный режим: $MODE (check | install | chek | keys | sdacha | switch | status | rollback)"
    ;;
esac
exit 0
__PAYLOAD_BELOW__
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
