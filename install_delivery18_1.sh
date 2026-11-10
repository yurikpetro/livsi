#!/bin/bash
# =====================================================================
#  LIVSI · исправление 18.1 — SEO-автопилот при сбоях Яндекса:
#   — если Вебмастер не принимает изменения (режим «только чтение», ответ 504),
#     страницы уходят на обход через IndexNow, а Вебмастер пробуется снова ночью;
#   — после двух сбоев или трёх отказов подряд автопилот не перебирает
#     все адреса, а останавливается и пишет в отчёт точный ответ Яндекса.
#  Ставится поверх обновления 18. Запускать от root.
#
#    bash install_delivery18_1.sh check        — только проверка, ничего не меняет
#    bash install_delivery18_1.sh              — установка (с бэкапом)
#    bash install_delivery18_1.sh rollback     — вернуть файлы из последнего бэкапа
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

# Файлы, которые правка меняет, и их md5 сейчас на сервере (обновление 18)
CHANGED=(
  "c5d5c2c470ba517ad1c0072798da3063 app/Analytics/Webmaster.php"
  "03a11e73218e655d9a8d2bd390db1e35 app/Seo/Autopilot.php"
  "714ec93344cdb710cfa45b1dcc9848f1 tests/Feature/SeoAutopilotTest.php"
)
# Новые файлы правки
ADDED=(
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
    [ "$bad" -eq 0 ] || die "файлы на сервере не такие, как после обновления 18. Если 18 ещё не ставили — сначала поставьте его (install_delivery18.sh), потом это. Иначе их поменял разработчик: ничего не тронуто, пришлите этот вывод — пересоберу правку."

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

    step "SEO-автопилот: запуск (без сообщений в Telegram, 1–2 минуты)"
    sleep 2
    art seo:autopilot --quiet-telegram 2>&1 | sed 's/^/  /'
    if art schedule:list 2>/dev/null | grep -q 'seo:autopilot'; then
        echo "  в расписании: каждую ночь в 06:10 МСК; сводка — по понедельникам в 09:40 МСК"
    else
        yellow "  ВНИМАНИЕ: seo:autopilot не нашёлся в расписании — пришлите этот вывод"
    fi

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
    # Главное: сервер не закрывает сайт от поисковиков (так в октябре пропал весь сайт).
    xrt="$(curl -s -o /dev/null -D - --max-time 20 -A 'Mozilla/5.0 (compatible; YandexBot/3.0)' "$SITE_URL/" | tr -d '\r' | grep -i '^x-robots-tag:' | head -1)"
    case "$(printf '%s' "$xrt" | tr 'A-Z' 'a-z')" in
        *noindex*|*none*) red "  ВНИМАНИЕ: сервер отдаёт «$xrt» — сайт закрыт для поисковиков! Это настройка nginx, не сайта:"
                          red "    grep -rn 'X-Robots-Tag' /etc/nginx/   — найти строку и закомментировать (#), потом nginx -t && systemctl reload nginx" ;;
        *) echo "  заголовок X-Robots-Tag с noindex: нет — сайт открыт для поисковиков" ;;
    esac
    oldmap="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$SITE_URL/sitemap.3972363.xml.gz")"
    echo "  старая карта сайта sitemap.3972363.xml.gz: $oldmap (200 — отвечает новой картой, так и надо)"
    bl="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$SITE_URL/admin/analytics/broken-links")"
    rd="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$SITE_URL/admin/analytics/redirects")"
    echo "  «Битые ссылки» и «Переадресации» в админке: $bl, $rd (302 — просит войти, так и надо)"
    host="${SITE_URL#*://}"; host="${host%%/*}"
    case "$host" in www.*) alt="${host#www.}" ;; *) alt="www.$host" ;; esac
    mirror="$(curl -s -o /dev/null -w '%{http_code} → %{redirect_url}' --max-time 15 "https://$alt/" 2>/dev/null)"
    echo "  второй адрес https://$alt/: ${mirror:-не открывается} (301 → $SITE_URL/ — так и надо)"
    if ! smoke || [ "$js_on" -ne 1 ] || [ "$css_on" -ne 1 ] || [ "$js_code" != "200" ] || [ "$css_code" != "200" ] \
        || [ "$dlv" != "302" ] || [ "$con" != "302" ] || [ "$gate" != "403" ] || [ "$cs" -ne 1 ] || [ "$hook" != "200" ] \
        || [ "$bl" != "302" ] || [ "$rd" != "302" ] \
        || ! art delivery:mode >/dev/null 2>&1 || ! art analytics:token >/dev/null 2>&1 || ! art seo:weekly --print >/dev/null 2>&1; then
        red "сайт отвечает не так — возвращаю файлы"
        restore_files "$BK"
        smoke || true
        die "установка отменена, файлы возвращены. Бэкап: $BK"
    fi
    scheduler_note

    echo
    green "ГОТОВО. Исправление 18.1: при сбое Вебмастера страницы уходят через IndexNow."
    echo "Бэкап: $BK"
    echo "Отчёт автопилота — выше и в админке: «Аналитика сайта» → «Поиск Яндекса», блок «SEO-автопилот»."
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
    green "Файлы возвращены к состоянию обновления 18."
    ;;

*)
    die "неизвестный режим: $MODE (check | install | chek | keys | sdacha | switch | status | rollback)"
    ;;
esac
exit 0
__PAYLOAD_BELOW__
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
bkWRHQC4AQA=
