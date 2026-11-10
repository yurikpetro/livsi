#!/bin/bash
# =====================================================================
#  LIVSI · обновление 16 — поиск, шаг 2:
#   — IndexNow: изменили товар, цену, линейку или документ — сайт сам
#     сообщает Яндексу, страница попадает в очередь на обход за минуты
#     (пачкой раз в 10 минут); при установке Яндексу уходит весь сайт;
#   — фид товаров для Яндекса https://livsi.shop/yml.xml — для блока
#     «Товары» в поиске; цены и наличие берутся с сайта сами;
#   — заголовок товара по умолчанию: «<товар> LIVSI — купить в официальном
#     магазине» (если в админке не задан свой);
#   — в подвале: «LIVSI (ЛИВСИ) — официальный магазин бренда».
#  Ставится поверх исправления 15.3. Запускать от root.
#  Стили и скрипты страницы не меняются.
#
#    bash install_delivery16.sh check        — только проверка, ничего не меняет
#    bash install_delivery16.sh              — установка (с бэкапом)
#    bash install_delivery16.sh rollback     — вернуть файлы из последнего бэкапа
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

# Файлы, которые правка меняет, и их md5 сейчас на сервере (исправление 15.3)
CHANGED=(
  "2df57ec73235dec7acc09dec53daff1d app/Http/Controllers/SitemapController.php"
  "ac661ea4aa787a4f20c302e58b056195 app/Providers/AppServiceProvider.php"
  "01f7a28fd02fd2fc2cb05fc42f304937 resources/views/catalog/show.blade.php"
  "0c19b5df52ae85e1ae58e007d5830208 resources/views/partials/footer.blade.php"
  "dbae61a86e9bb2e50945c8533a54f853 routes/console.php"
  "d43c0849e14d7d778ed793396f858a2a routes/web.php"
)
# Новые файлы правки
ADDED=(
  "app/Console/Commands/SeoIndexNow.php"
  "app/Http/Controllers/YmlFeedController.php"
  "app/Seo/IndexNow.php"
  "tests/Feature/SeoFeedTest.php"
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
    [ "$bad" -eq 0 ] || die "файлы на сервере не такие, как после исправления 15.3. Если 15.3 ещё не ставили — сначала поставьте его (install_delivery15_3.sh), потом это. Иначе их поменял разработчик: ничего не тронуто, пришлите этот вывод — пересоберу правку."

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

    step "Поиск: сообщаю Яндексу о страницах сайта (IndexNow)"
    appurl="$(env_get APP_URL)"
    echo "  адрес сайта (APP_URL): ${appurl:-не задан}"
    key="$(curl -s --max-time 20 "$SITE_URL/indexnow.txt")"
    case "$key" in [0-9a-f][0-9a-f][0-9a-f][0-9a-f]*) echo "  ключ IndexNow на сайте: есть" ;; *) yellow "  ключ IndexNow не открылся — Яндекс не примет оповещения, пришлите этот вывод" ;; esac
    out="$(art seo:indexnow --all 2>&1 | tail -1)"
    echo "  ${out:-нет ответа}"
    echo "  (202 — Яндекс принял и проверит ключ; дальше сайт сам шлёт изменения раз в 10 минут)"

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
    feed="$(curl -s --max-time 30 "$SITE_URL/yml.xml" | grep -c '<offer ')"
    echo "  фид товаров /yml.xml: предложений ${feed:-0}"
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
    green "ГОТОВО. Обновление 16: IndexNow, фид товаров для Яндекса, заголовки товаров с брендом."
    echo "Бэкап: $BK"
    echo "Фид для «Товаров» Яндекса: $SITE_URL/yml.xml — подключить в Вебмастере, когда раздел заработает."
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
    green "Файлы возвращены к состоянию исправления 15.3."
    ;;

*)
    die "неизвестный режим: $MODE (check | install | chek | keys | sdacha | switch | status | rollback)"
    ;;
esac
exit 0
__PAYLOAD_BELOW__
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
