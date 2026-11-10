#!/bin/bash
# =====================================================================
#  LIVSI · исправление 17.1 — «Аналитика сайта» → «Поиск Яндекса»:
#   — если у Яндекса нет свежей статистики запросов, страница так и пишет
#     (с какого дня нет) и показывает последнюю неделю, за которую данные есть;
#     дни без данных на графике — пунктиром, а не «0 показов»;
#   — «серьёзные проблемы» и «исключено из поиска» открываются списками
#     с понятными причинами по-русски.
#  Ставится поверх обновления 17. Запускать от root.
#
#    bash install_delivery17_1.sh check        — только проверка, ничего не меняет
#    bash install_delivery17_1.sh              — установка (с бэкапом)
#    bash install_delivery17_1.sh rollback     — вернуть файлы из последнего бэкапа
# =====================================================================
set -uo pipefail

APP=/var/www/livsi
BACKUPS=/root/livsi-backups
MIGRATIONS=()
# Собранный файл скриптов страницы (лежит в архиве ниже) и стили, которые
# дописываются к нынешнему файлу стилей сайта.
ASSET_JS="assets/app-VrEkeHjb.js"
CSS_MARK='[data-cookie-settings]{'
CSS_ADD='[data-cookie-settings]{cursor:pointer;text-transform:inherit;letter-spacing:inherit}'
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

# Файлы, которые правка меняет, и их md5 сейчас на сервере (обновление 17)
CHANGED=(
  "f8668c7d779376c81acc02ba0ab82610 app/Analytics/Webmaster.php"
  "6bcfc627342d5761f205e494d9b134b6 app/Filament/Pages/Analytics/AnalyticsSearch.php"
  "5d310d4f31a11803df3dda9d30287cda resources/views/filament/analytics/partials/styles.blade.php"
  "10e74b3e59b474a40e9351b8583be946 resources/views/filament/analytics/search.blade.php"
  "03d101a415a92175280b1ff30394dbf7 tests/Feature/AnalyticsTest.php"
)
# Новые файлы правки
ADDED=(
  "resources/views/filament/analytics/partials/queries.blade.php"
  "public/build/assets/app-VrEkeHjb.js"
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
# Сайт проверяем по его главному адресу (APP_URL): второй адрес теперь отвечает переадресацией.
APP_URL_ENV="$(env_get APP_URL 2>/dev/null)"
case "$APP_URL_ENV" in https://*) [ "$SITE_URL" = "https://livsi.shop" ] && SITE_URL="${APP_URL_ENV%/}" ;; esac
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
    [ "$bad" -eq 0 ] || die "файлы на сервере не такие, как после обновления 17. Если 17 ещё не ставили — сначала поставьте его (install_delivery17.sh), потом это. Иначе их поменял разработчик: ничего не тронуто, пришлите этот вывод — пересоберу правку."

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
        echo "  стили: $CUR_CSS + «Настройки cookie» → $NEW_CSS"
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

    step "Аналитика"
    art analytics:token 2>&1 | sed 's/^/  /'

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
    dlv="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$SITE_URL/admin/analytics/traffic")"
    echo "  «Аналитика сайта» в админке: $dlv (302 — просит войти, так и надо)"
    con="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$SITE_URL/admin/analytics/connection")"
    echo "  «Подключение» в админке: $con (302 — просит войти, так и надо)"
    gate="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$SITE_URL/admin/analytics/yandex/connect")"
    echo "  кнопка «Подключить Яндекс» без входа: $gate (403 — так и надо)"
    case "$page" in *'data-cookie-settings'*) cs=1 ;; *) cs=0 ;; esac
    echo "  «Настройки cookie» в подвале: $([ $cs -eq 1 ] && echo да || echo НЕТ)"
    case "$page" in *'mc.yandex.ru'*) echo "  счётчик Метрики на странице: есть (ждёт согласия посетителя)" ;; *) echo "  счётчик Метрики: ещё не выбран — «Аналитика сайта» → «Подключение»" ;; esac
    case "$page" in *'yandex-verification'*) echo "  метатег Вебмастера: есть" ;; *) echo "  метатег Вебмастера: нет (права и так подтверждены записью DNS)" ;; esac
    hook="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 -X POST -H 'Content-Type: text/plain' --data 'check' "$SITE_URL/webhooks/tochka")"
    echo "  адрес для уведомлений Точки: $hook (200 — так и надо)"
    feed="$(curl -s --max-time 30 "$SITE_URL/yml.xml" | grep -c '<offer ')"
    echo "  фид товаров /yml.xml: предложений ${feed:-0}"
    rob="$(curl -s --max-time 20 "$SITE_URL/robots.txt" | grep -m1 '^Sitemap:')"
    echo "  robots.txt: ${rob:-НЕТ строки Sitemap — пришлите этот вывод}"
    host="${SITE_URL#*://}"; host="${host%%/*}"
    case "$host" in www.*) alt="${host#www.}" ;; *) alt="www.$host" ;; esac
    mirror="$(curl -s -o /dev/null -w '%{http_code} → %{redirect_url}' --max-time 15 "https://$alt/" 2>/dev/null)"
    echo "  второй адрес https://$alt/: ${mirror:-не открывается} (301 → $SITE_URL/ — так и надо)"
    if ! smoke || [ "$js_on" -ne 1 ] || [ "$css_on" -ne 1 ] || [ "$js_code" != "200" ] || [ "$css_code" != "200" ] \
        || [ "$dlv" != "302" ] || [ "$con" != "302" ] || [ "$gate" != "403" ] || [ "$cs" -ne 1 ] || [ "$hook" != "200" ] \
        || ! art delivery:mode >/dev/null 2>&1 || ! art analytics:token >/dev/null 2>&1; then
        red "сайт отвечает не так — возвращаю файлы"
        restore_files "$BK"
        smoke || true
        die "установка отменена, файлы возвращены. Бэкап: $BK"
    fi
    scheduler_note

    echo
    green "ГОТОВО. Исправление 17.1: «Поиск Яндекса» — свежесть данных, проблемы и исключённые страницы."
    echo "Бэкап: $BK"
    echo "Откройте админку → «Аналитика сайта» → «Поиск Яндекса» и нажмите «Обновить»."
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
    green "Файлы возвращены к состоянию обновления 17."
    ;;

*)
    die "неизвестный режим: $MODE (check | install | chek | keys | sdacha | switch | status | rollback)"
    ;;
esac
exit 0
__PAYLOAD_BELOW__
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
n6vP1efqs+nzf4ZNZi8AuAEA
