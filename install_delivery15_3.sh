#!/bin/bash
# =====================================================================
#  LIVSI · исправление 15.3 — чтобы по запросу «livsi» Яндекс показывал сайт:
#   — один адрес сайта: www.livsi.shop постоянно (301) ведёт на адрес из
#     APP_URL — для Яндекса это одно зеркало, а не два;
#   — robots.txt: карта сайта, служебные страницы (корзина, оформление,
#     кабинет, заказы) закрыты, ссылки с метками рекламы склеиваются;
#   — главная: заголовок «LIVSI — официальный интернет-магазин…», разметка
#     бренда (LIVSI, ЛИВСИ, ссылки на VK, Telegram, WB, Ozon);
#   — «Настройки сайта» → «Поиск»: код подтверждения Яндекс Вебмастера,
#     ссылки на VK, Wildberries, Ozon (VK — и в подвале);
#   — адреса старого сайта (/magazin/…) из выдачи Яндекса — 301 на товар
#     или в каталог, а не 404;
#   — боты, которые шлют в Livewire загрузку файла, получают короткий отказ
#     и больше не попадают в «Ошибки» Telegram.
#  Ставится поверх исправления 15.2. Запускать от root.
#  Стили и скрипты страницы не меняются.
#
#    bash install_delivery15_3.sh check        — только проверка, ничего не меняет
#    bash install_delivery15_3.sh              — установка (с бэкапом)
#    bash install_delivery15_3.sh rollback     — вернуть файлы из последнего бэкапа
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

# Файлы, которые правка меняет, и их md5 сейчас на сервере (исправление 15.2)
CHANGED=(
  "c26d46f194a95e758cd9c73b3c251621 .gitignore"
  "d49524f71cbeea6002f85a56214bab2b app/Filament/Pages/Settings.php"
  "60d07f9c81da48320f345aa3578fec1d bootstrap/app.php"
  "a4269011bce07eadbe2588e6146809ac resources/views/home.blade.php"
  "12f15f51e67239a059fa3d1613307b78 resources/views/layouts/app.blade.php"
  "c250c24236c6d70dcb9b0b2ac4cf46a5 resources/views/partials/footer.blade.php"
  "7ebf48247296d76430edc96035d77d7c routes/web.php"
)
# Новые файлы правки
ADDED=(
  "app/Console/Commands/SeoRobots.php"
  "app/Http/Controllers/LegacyRedirectController.php"
  "app/Http/Controllers/RobotsController.php"
  "app/Http/Middleware/CanonicalHost.php"
  "tests/Feature/SeoTest.php"
  "public/build/assets/app-YT4IrMBC.js"
)
# Файлы, которые правка убирает, и их md5 сейчас на сервере
REMOVED=(
  "b6216d61c03e6ce0c9aea6ca7808f7ca public/robots.txt"
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
    [ "$bad" -eq 0 ] || die "файлы на сервере не такие, как после исправления 15.2. Если 15.2 ещё не ставили — сначала поставьте его (install_delivery15_2.sh), потом это. Иначе их поменял разработчик: ничего не тронуто, пришлите этот вывод — пересоберу правку."

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

    step "Поиск: адрес сайта и robots.txt"
    appurl="$(env_get APP_URL)"
    echo "  адрес сайта (APP_URL): ${appurl:-не задан}"
    case "$appurl" in https://*) ;; *) yellow "  ВНИМАНИЕ: APP_URL не начинается с https:// — пришлите этот вывод, поправим" ;; esac
    if art seo:robots >/dev/null 2>&1; then
        chown "$OWNER":"$OWNER" "$APP/public/robots.txt" 2>/dev/null
        echo "  robots.txt записан: $(grep -m1 '^Sitemap:' "$APP/public/robots.txt")"
    else
        yellow "  robots.txt не записался — сайт отдаст его сам по адресу /robots.txt"
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
    dlv="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$SITE_URL/admin/order-returns")"
    echo "  страница «Возвраты» в админке: $dlv (302 — просит войти, так и надо)"
    ret="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$SITE_URL/order/LV-NE-SUSHCHESTVUET/return")"
    echo "  страница возврата для чужого заказа: $ret (404 — так и надо)"
    pay="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$SITE_URL/admin/payment-integrations")"
    echo "  страница «Интеграции оплаты»: $pay (302 — просит войти, так и надо)"
    hook="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 -X POST -H 'Content-Type: text/plain' --data 'check' "$SITE_URL/webhooks/tochka")"
    echo "  адрес для уведомлений Точки: $hook (200 — так и надо)"
    old="$(curl -s -o /dev/null -w '%{http_code} → %{redirect_url}' --max-time 20 "$SITE_URL/magazin/tag/uhod-za-nogami/")"
    echo "  старый адрес /magazin/tag/uhod-za-nogami/: $old (301 → каталог — так и надо)"
    rob="$(curl -s --max-time 20 "$SITE_URL/robots.txt" | grep -m1 '^Sitemap:')"
    echo "  robots.txt: ${rob:-НЕТ строки Sitemap — пришлите этот вывод}"
    host="${SITE_URL#*://}"; host="${host%%/*}"
    case "$host" in www.*) alt="${host#www.}" ;; *) alt="www.$host" ;; esac
    mirror="$(curl -s -o /dev/null -w '%{http_code} → %{redirect_url}' --max-time 15 "https://$alt/" 2>/dev/null)"
    echo "  второй адрес https://$alt/: ${mirror:-не открывается} (301 → $SITE_URL/ — так и надо)"
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
    green "ГОТОВО. Исправление 15.3: один адрес сайта, robots.txt с картой, разметка бренда, старые адреса → новые."
    echo "Бэкап: $BK"
    echo "Дальше — Яндекс Вебмастер: код подтверждения вставьте в «Настройки сайта» → «Поиск»."
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
    green "Файлы возвращены к состоянию исправления 15.2."
    ;;

*)
    die "неизвестный режим: $MODE (check | install | chek | keys | sdacha | switch | status | rollback)"
    ;;
esac
exit 0
__PAYLOAD_BELOW__
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
