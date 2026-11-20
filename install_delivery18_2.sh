#!/bin/bash
# =====================================================================
#  LIVSI · обновление 18.2 — SEO: значок, товары, битые ссылки:
#   — квадратный значок сайта (логотип на неоновом фоне) во всех размерах,
#     раньше favicon.ico был пустым, а значком стоял широкий логотип;
#   — заголовок и описание товара с ароматом и линейкой: шесть мультипенок
#     больше не одинаковые для Яндекса; точка между предложениями описания;
#   — нормальные адреса мультипенок (…-greypfrut-zhasmin) вместо «…multipenkaаа»,
#     со старых адресов — переадресация; сменили адрес товара в админке —
#     переадресация ставится сама; в адресе только латиница, цифры и дефис;
#   — «Битые ссылки» без сканеров (phpinfo, .env, terraform…), список почищен.
#  Ставится поверх обновления 18.1. Запускать от root.
#
#    bash install_delivery18_2.sh check        — только проверка, ничего не меняет
#    bash install_delivery18_2.sh              — установка (с бэкапом)
#    bash install_delivery18_2.sh rollback     — вернуть файлы из последнего бэкапа
# =====================================================================
set -uo pipefail

APP=/var/www/livsi
BACKUPS=/root/livsi-backups
MIGRATIONS=(2026_11_20_180000_product_slugs_with_aroma_and_junk_cleanup)
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

# Файлы, которые правка меняет, и их md5 сейчас на сервере (обновление 18.1)
CHANGED=(
  "6cab7ac7516f1ae9ee2ab0e2b4b1e7aa app/Filament/Resources/Products/Schemas/ProductForm.php"
  "46a7cc5c9167c76fbcd642ec46e18874 app/Models/Product.php"
  "8f14307faafcbdb385017209f37c6352 app/Models/SeoRedirect.php"
  "3733f54b7f22ff7415659ee07651dd8a app/Seo/MissingPages.php"
  "8d02918e3cfb2309f9a3680e17d6a220 app/Seo/SiteHealth.php"
  "d41d8cd98f00b204e9800998ecf8427e public/favicon.ico"
  "e2b222739e94ce4c6a045366d27f9b08 resources/views/catalog/show.blade.php"
  "472d0f32fa42e1e1fea7ec48dd11f41a resources/views/layouts/app.blade.php"
)
# Новые файлы правки
ADDED=(
  "database/migrations/2026_11_20_180000_product_slugs_with_aroma_and_junk_cleanup.php"
  "public/apple-touch-icon.png"
  "public/favicon-120.png"
  "public/favicon.svg"
  "tests/Feature/SeoProductsTest.php"
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
    [ "$bad" -eq 0 ] || die "файлы на сервере не такие, как после обновления 18.1. Если 18.1 ещё не ставили — сначала поставьте его (install_delivery18_1.sh), потом это. Иначе их поменял разработчик: ничего не тронуто, пришлите этот вывод — пересоберу правку."

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

    step "Новые адреса товаров — Яндексу (IndexNow)"
    art seo:indexnow 2>&1 | sed 's/^/  /'

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
    fav="$(curl -s -o /dev/null -w '%{http_code} %{size_download}' --max-time 20 "$SITE_URL/favicon.ico")"
    fsvg="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$SITE_URL/favicon.svg")"
    f120="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$SITE_URL/favicon-120.png")"
    ftouch="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$SITE_URL/apple-touch-icon.png")"
    echo "  значок: favicon.ico ${fav} байт, favicon.svg $fsvg, 120×120 $f120, для iPhone $ftouch (всё 200, ico больше 1000 байт — так и надо)"
    badloc="$(curl -s --max-time 20 "$SITE_URL/sitemap.xml" | grep -o '<loc>[^<]*' | grep -cE '%|[[:space:]]')"
    echo "  адресов с пробелами и кириллицей в карте сайта: $badloc (0 — так и надо)"
    case "$page" in *'favicon.svg'*) echo "  значок на страницах: есть" ;; *) red "  значка на страницах нет — пришлите этот вывод" ;; esac
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
        || [ "${fav%% *}" != "200" ] || [ "${fav##* }" -lt 1000 ] || [ "$fsvg" != "200" ] || [ "$f120" != "200" ] \
        || ! art delivery:mode >/dev/null 2>&1 || ! art analytics:token >/dev/null 2>&1 || ! art seo:weekly --print >/dev/null 2>&1; then
        red "сайт отвечает не так — возвращаю файлы"
        restore_files "$BK"
        smoke || true
        die "установка отменена, файлы возвращены. Бэкап: $BK"
    fi
    scheduler_note

    echo
    green "ГОТОВО. Обновление 18.2: значок сайта, заголовки и адреса товаров, «Битые ссылки» без сканеров."
    echo "Бэкап: $BK"
    echo "Новые адреса товаров и переадресации со старых — в админке: «Аналитика сайта» → «Переадресации»."
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
    green "Файлы возвращены к состоянию обновления 18.1 (новые адреса товаров и переадресации остаются — они работают и в 18.1)."
    ;;

*)
    die "неизвестный режим: $MODE (check | install | chek | keys | sdacha | switch | status | rollback)"
    ;;
esac
exit 0
__PAYLOAD_BELOW__
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
bj43n5vPzafs83+FgAv7ADACAA==
