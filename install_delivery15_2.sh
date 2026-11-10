#!/bin/bash
# =====================================================================
#  LIVSI · исправление 15.2 — «Честный знак» после возврата:
#   — в карточке заказа пошаговая подсказка, как вернуть код в оборот
#     вручную: какой чек искать («Возврат прихода», дата, сумма), какой вид
#     возврата выбрать, что вписать в документ-основание; коды — списком;
#   — кнопка «Коды вернул в оборот» — отметка остаётся в заказе;
#   — пока не отмечено, в списке заказов под номером «↩ Возврат: код в
#     оборот (ЧЗ)», заказ виден во вкладке «Возвраты».
#  Ставится поверх исправления 15.1. Запускать от root.
#  Стили и скрипты страницы не меняются.
#
#    bash install_delivery15_2.sh check        — только проверка, ничего не меняет
#    bash install_delivery15_2.sh              — установка (с бэкапом)
#    bash install_delivery15_2.sh rollback     — вернуть файлы из последнего бэкапа
# =====================================================================
set -uo pipefail

APP=/var/www/livsi
BACKUPS=/root/livsi-backups
MIGRATIONS=(2026_11_09_170000_add_marks_return_to_order_returns)
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

# Файлы, которые правка меняет, и их md5 сейчас на сервере (исправление 15.1)
CHANGED=(
  "64dc97e9b33318db32a8e1a9df1f87b7 app/Filament/Resources/Orders/Pages/ListOrders.php"
  "b32394a12d79b5e96db2171fbda810c4 app/Filament/Resources/Orders/Tables/OrdersTable.php"
  "87814bb53fe53d464e419a0add099d8c app/Filament/Support/ReturnActions.php"
  "fdd02ccaff1b619eff383584f563f521 app/Models/OrderReturn.php"
  "06f657a6a91445033579bde8ed8ecdab app/Services/RefundService.php"
  "29cd7ea5795e1219cdcbb9c48bda2df0 resources/views/filament/orders/return.blade.php"
  "0001c308c39ba54c454862a625a346c8 tests/Feature/OrderReturnsTest.php"
)
# Новые файлы правки
ADDED=(
  "database/migrations/2026_11_09_170000_add_marks_return_to_order_returns.php"
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
    [ "$bad" -eq 0 ] || die "файлы на сервере не такие, как после исправления 15.1. Если 15.1 ещё не ставили — сначала поставьте его (install_delivery15_1.sh), потом это. Иначе их поменял разработчик: ничего не тронуто, пришлите этот вывод — пересоберу правку."

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
    green "ГОТОВО. Исправление 15.2: подсказка по «Честному знаку» и отметка «Коды вернул в оборот»."
    echo "Бэкап: $BK"
    echo "Где смотреть: карточка заказа с возвратом — блок «Возврат по заявке покупателя», строка «Честный знак»."
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
    # Столбцы отметки «коды в обороте» убираем, пока файл миграции на месте:
    # старый код о них не знает, а повторная установка добавит их заново.
    if [ -f "$APP/database/migrations/2026_11_09_170000_add_marks_return_to_order_returns.php" ]; then
        art migrate:rollback --force --path=database/migrations/2026_11_09_170000_add_marks_return_to_order_returns.php >/dev/null 2>&1 \
            && echo "  отметки «коды в обороте» убраны" || yellow "  отметки «коды в обороте» не убрались — старому коду это не мешает"
    fi
    restore_files "$BK"
    smoke || true
    art delivery:mode 2>/dev/null || true
    green "Файлы возвращены к состоянию исправления 15.1."
    ;;

*)
    die "неизвестный режим: $MODE (check | install | chek | keys | sdacha | switch | status | rollback)"
    ;;
esac
exit 0
__PAYLOAD_BELOW__
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
