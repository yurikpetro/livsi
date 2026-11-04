#!/bin/bash
# =====================================================================
#  LIVSI · исправление 12.1: подсказка о дорогом или долгом пункте выдачи
#  сравнивает его с пунктами города покупателя (пункт на окраине, например
#  на Старомарьевском шоссе, числится за посёлком — подсказка не появлялась).
#  Ставится поверх обновления 12. Скрипты и стили страницы не меняются.
#
#    bash install_delivery12_1.sh check          — только проверка, ничего не меняет
#    bash install_delivery12_1.sh                — установка (с бэкапом)
#    bash install_delivery12_1.sh rollback       — вернуть файлы из последнего бэкапа
# =====================================================================
set -uo pipefail

APP=/var/www/livsi
BACKUPS=/root/livsi-backups
MIGRATIONS=()
# Собранный файл скриптов страницы (лежит в архиве ниже) и стили, которые
# дописываются к нынешнему файлу стилей сайта.
ASSET_JS="assets/app-YT4IrMBC.js"
CSS_MARK='.delivery-quote-note{'
CSS_ADD='.delivery-choice[hidden]{display:none}.delivery-quote-note{border-left:3px solid var(--color-ink);background:var(--color-shell,#f2f1ec);padding:10px 12px;line-height:1.45}.delivery-quote-note[hidden]{display:none}'
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

# Файлы, которые правка меняет, и их md5 сейчас на сервере (обновление 12)
CHANGED=(
  "f09f17960f6a1123878dbd5c8f479e86 app/Services/DeliveryQuoteService.php"
  "ffd6b0f6d9eb0b065b253c8e0216782b tests/Feature/CourierAndHintsTest.php"
)
# Новые файлы правки
ADDED=(
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
    ALREADY=0; [ "$installed" -eq "${#CHANGED[@]}" ] && [ "$gone" -eq "${#REMOVED[@]}" ] && [ "$CUR_JS" = "$ASSET_JS" ] && ALREADY=1
    [ "$bad" -eq 0 ] || die "файлы на сервере не такие, как после обновления 12. Если 12-е ещё не ставили — сначала поставьте его (install_delivery12.sh), потом это. Иначе их поменял разработчик: ничего не тронуто, пришлите этот вывод — пересоберу правку."

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
    if [ "$ALREADY" -eq 1 ]; then green "Правка уже установлена — повторять не нужно."; exit 0; fi

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
    if grep -qF "$CSS_MARK" "$APP/public/build/$CUR_CSS"; then
        NEW_CSS="$CUR_CSS"; echo "  стили уже есть в $CUR_CSS"
    else
        tmpc="$(mktemp)"
        { cat "$APP/public/build/$CUR_CSS"; printf '\n%s\n' "$CSS_ADD"; } > "$tmpc"
        NEW_CSS="assets/app-$(md5sum "$tmpc" | cut -c1-8).css"
        install -o "$OWNER" -g "$OWNER" -m 644 "$tmpc" "$APP/public/build/$NEW_CSS"; rm -f "$tmpc"
        [ -e "$BK/css-added" ] || { echo "public/build/$NEW_CSS" >> "$BK/added-files.txt"; : > "$BK/css-added"; }
        echo "  стили: $CUR_CSS + подсказка и курьер → $NEW_CSS"
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
    dlv="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 -X POST -H 'Accept: application/json' "$SITE_URL/api/livsipro/orders/LV-000000/delivered")"
    echo "  отметка «Доставлен» для livsipro: $dlv (401 или 503 — так и надо)"
    if ! smoke || [ "$js_on" -ne 1 ] || [ "$css_on" -ne 1 ] || [ "$js_code" != "200" ] || [ "$css_code" != "200" ] \
        || { [ "$dlv" != "401" ] && [ "$dlv" != "503" ]; } || ! art delivery:mode >/dev/null 2>&1; then
        red "сайт отвечает не так — возвращаю файлы"
        restore_files "$BK"
        smoke || true
        die "установка отменена, файлы возвращены. Бэкап: $BK"
    fi
    courier="$(cd "$APP" && runuser -u "$OWNER" -- php artisan tinker --execute='echo App\Models\Setting::get("stavropol_courier_enabled") ? "включён" : "выключен";' 2>/dev/null | tail -1)"

    echo
    green "ГОТОВО. Подсказка о дорогом пункте сравнивает с пунктами города покупателя."
    echo "Бэкап: $BK"
    echo "Курьер сейчас: ${courier:-?}."
    echo "Проверка: оформление → Яндекс → пункт на Старомарьевском шоссе — под ценой подсказка."
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
    # Возврат к тому, что было до исправления 12.1.
    restore_files "$BK"
    smoke || true
    art delivery:mode 2>/dev/null || true
    green "Файлы возвращены к состоянию до исправления 12.1 (обновление 12 остаётся)."
    ;;

*)
    die "неизвестный режим: $MODE (check | install | chek | keys | sdacha | switch | status | rollback)"
    ;;
esac
exit 0
__PAYLOAD_BELOW__
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
Z/7Mn/kzf+bP/Jk/82f+/P98/hcNZ3tDAGgBAA==
