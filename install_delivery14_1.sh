#!/bin/bash
# =====================================================================
#  LIVSI · исправление 14.1 — связь с Точкой:
#   — сертификат Минцифры для запросов к Точке (enter.tochka.com работает
#     на нём, без него «cURL error 60 … self-signed certificate»). Скачивается
#     с Госуслуг и принимается, только если совпал отпечаток. Доверие ему —
#     только у запросов сайта к Точке и CloudKassir, не у всего сервера;
#   — код компании находится по ключу сам; сообщения говорят, чего не хватает;
#   — причина отказа CloudKassir и долгая очередь чека видны в заказе;
#   — для livsipro: в карточке заказа оплата, чеки, доставка и суммы.
#  Ставится поверх обновления 14. Запускать от root.
#  Стили страницы: к нынешнему файлу стилей дописываются несколько правил
#  (старый файл остаётся для отката). Скрипты страницы не меняются.
#
#    bash install_delivery14_1.sh check        — только проверка, ничего не меняет
#    bash install_delivery14_1.sh              — установка (с бэкапом)
#    bash install_delivery14_1.sh cert         — только сертификат (повторить, если Госуслуги не ответили)
#    bash install_delivery14_1.sh rollback     — вернуть файлы из последнего бэкапа
# =====================================================================
set -uo pipefail

APP=/var/www/livsi
BACKUPS=/root/livsi-backups
MIGRATIONS=(2026_11_05_100000_create_hero_slides_table)
# Собранный файл скриптов страницы (лежит в архиве ниже) и стили, которые
# дописываются к нынешнему файлу стилей сайта.
ASSET_JS="assets/app-YT4IrMBC.js"
CSS_MARK='--photo-ratio'
CSS_ADD='@media (max-width:700px){.product-modal-photo{height:auto;min-height:0;aspect-ratio:var(--photo-ratio,4 / 5);background:var(--color-shell,#f2f1ec)}.product-modal-photo>div:not(.gallery-thumbs) img{object-fit:contain}}.hero-photo img{object-position:var(--focus,center 47%)}@media (max-width:47.99rem){.hero-photo img{object-position:var(--focus-m,var(--focus,54% center))}}'
MANIFEST="public/build/manifest.json"
MODE="${1:-install}"
SITE_URL="${SITE_URL:-https://livsi.shop}"
# Корневой сертификат Минцифры (Russian Trusted Root CA): откуда брать и его
# отпечаток SHA-256 — опубликован Сбером (developers.sber.ru, «Сертификаты»).
CA_FILE="storage/app/certs/russian_trusted_root_ca.pem"
CA_SHA256='D2:6D:2D:02:31:B7:C3:9F:92:CC:73:85:12:BA:54:10:35:19:E4:40:5D:68:B5:BD:70:3E:97:88:CA:8E:CF:31'
CA_URLS=(https://gu-st.ru/content/lending/russian_trusted_root_ca_pem.crt https://gu-st.ru/content/Other/doc/russiantrustedca.pem)
TOCHKA_URL="https://enter.tochka.com/uapi/open-banking/v1.0/customers"
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

# Файлы, которые правка меняет, и их md5 сейчас на сервере (обновление 14)
CHANGED=(
  "58025050028686a1018f1a93a2747b79 app/Console/Commands/PaymentsCheck.php"
  "3f4847f1e3a505e4d630e5a21cd75c04 app/Console/Commands/ReceiptsSync.php"
  "b88dc35c9eeda55628f81f99ec6d20c4 app/Filament/Pages/PaymentIntegrations.php"
  "b785f82f2c660ca9a37051be2d9724de app/Http/Controllers/Livsipro/OrdersController.php"
  "ca37c6fee17ce69f750260dcdfe7678d app/Payments/CloudKassir.php"
  "f08f2fbf18963420a5ac6954e283dddf app/Payments/TochkaClient.php"
  "4d04d5ef663dfcf5d3e748bef1abd16b app/Services/ReceiptService.php"
  "1c8cfa2fc05226aa8811dc114e0e13fc tests/Feature/TochkaPaymentsTest.php"
)
# Новые файлы правки
ADDED=(
  "app/Payments/TrustedCa.php"
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
    [ "$bad" -eq 0 ] || die "файлы на сервере не такие, как после обновления 14. Если 14-е ещё не ставили — сначала поставьте его (install_delivery14.sh), потом это. Иначе их поменял разработчик: ничего не тронуто, пришлите этот вывод — пересоберу правку."

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

cert_fp() { openssl x509 -in "$1" -noout -fingerprint -sha256 2>/dev/null | sed 's/^.*=//' | tr 'a-f' 'A-F'; }

# Сертификат Минцифры: скачать, найти в файле корень с нужным отпечатком, положить.
# -k при скачивании не страшен: чужой файл не совпадёт по отпечатку и не будет принят.
install_cert() {
    step "Сертификат Минцифры для связи с Точкой"
    if [ -f "$APP/$CA_FILE" ] && [ "$(cert_fp "$APP/$CA_FILE")" = "$CA_SHA256" ]; then echo "  уже установлен"; return 0; fi
    local tmp url k part found=""
    tmp="$(mktemp -d)"
    for url in "${CA_URLS[@]}"; do
        for k in "" "-k"; do
            rm -f "$tmp"/raw "$tmp"/part-*
            curl -fsSL $k --max-time 30 -o "$tmp/raw" "$url" 2>/dev/null || continue
            if grep -q 'BEGIN CERTIFICATE' "$tmp/raw"; then
                awk -v d="$tmp" '/BEGIN CERTIFICATE/{n++} n{print > (d"/part-"n)}' "$tmp/raw"
            else
                openssl x509 -inform DER -in "$tmp/raw" -out "$tmp/part-1" 2>/dev/null || continue
            fi
            for part in "$tmp"/part-*; do
                if [ -f "$part" ] && [ "$(cert_fp "$part")" = "$CA_SHA256" ]; then found="$part"; break 3; fi
            done
        done
    done
    if [ -z "$found" ]; then
        rm -rf "$tmp"
        yellow "  НЕ получилось скачать сертификат с Госуслуг (или отпечаток не совпал) — ничего не положено."
        yellow "  Сайт работает как раньше, но связи с Точкой не будет. Повторить: bash $0 cert"
        return 1
    fi
    [ -d "$APP/storage/app/certs" ] || install -d -o "$OWNER" -g "$OWNER" -m 755 "$APP/storage/app/certs"
    openssl x509 -in "$found" -out "$tmp/root.pem"
    install -o "$OWNER" -g "$OWNER" -m 644 "$tmp/root.pem" "$APP/$CA_FILE"
    rm -rf "$tmp"
    if [ -n "${BK:-}" ] && [ -f "$BK/added-files.txt" ]; then echo "$CA_FILE" >> "$BK/added-files.txt"; fi
    echo "  положен: $CA_FILE"
    echo "  $(openssl x509 -in "$APP/$CA_FILE" -noout -subject 2>/dev/null)"
    echo "  отпечаток совпал с опубликованным"
    return 0
}

tochka_check() {
    step "Связь с Точкой"
    local code
    code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 --cacert "$APP/$CA_FILE" "$TOCHKA_URL" 2>/dev/null)"
    case "$code" in
        401|403) echo "  сертификат банка принят (ответ $code без ключа — так и надо)" ;;
        *) yellow "  ВНИМАНИЕ: Точка ответила не так, как ждали (код $code)" ;;
    esac
    art payments:check --tochka 2>&1 | sed 's/^/  /' || true
}

case "$MODE" in
cert)
    install_cert && tochka_check
    ;;

check)
    preflight
    echo; green "Можно ставить: bash $0"
    ;;

install)
    preflight
    if [ "$ALREADY" -eq 1 ]; then
        green "Правка уже установлена."
        install_cert && tochka_check
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
        echo "  стили: $CUR_CSS + фото на телефоне и баннеры → $NEW_CSS"
    fi
    if ! manifest_set "$NEW_CSS" "$ASSET_JS" || [ "$(manifest_get resources/js/app.js)" != "$ASSET_JS" ]; then
        red "не удалось подключить новые скрипты — возвращаю файлы"
        restore_files "$BK"; die "установка отменена, файлы в прежнем виде. Бэкап: $BK"
    fi
    echo "  скрипты: $CUR_JS → $ASSET_JS"

    step "Папки для картинок баннеров"
    for d in public/img/banners public/img/derived/banners; do
        [ -d "$APP/$d" ] || { install -d -o "$OWNER" -g "$OWNER" -m 755 "$APP/$d"; echo "$d" >> "$BK/added-dirs.txt"; }
    done
    runuser -u "$OWNER" -- test -w "$APP/public/img/banners" && echo "  картинки баннеров: public/img/banners — запись есть" \
        || yellow "  ВНИМАНИЕ: сайт не может писать в public/img/banners — загрузка баннеров не сработает"
    if runuser -u "$OWNER" -- test -w "$APP/public/img/derived" && runuser -u "$OWNER" -- php -r 'exit(function_exists("imagewebp") ? 0 : 1);'; then
        echo "  лёгкие копии картинок (GD): сделаются автоматически"
    else
        yellow "  лёгкие копии картинок не сделаются (нет GD или записи в public/img/derived) — баннеры будут работать, но грузить JPG лучше до 1 МБ"
    fi

    CERT_OK=0; install_cert && CERT_OK=1

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
    hero=0; case "$page" in *"--focus: "*) hero=1 ;; esac
    echo "  баннеры на главной из админки: $([ $hero -eq 1 ] && echo да || echo НЕТ)"
    dlv="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$SITE_URL/admin/hero-slides")"
    echo "  страница «Баннеры на главной»: $dlv (302 — просит войти, так и надо)"
    pay="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$SITE_URL/admin/payment-integrations")"
    echo "  страница «Интеграции оплаты»: $pay (302 — просит войти, так и надо)"
    hook="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 -X POST -H 'Content-Type: text/plain' --data 'check' "$SITE_URL/webhooks/tochka")"
    echo "  адрес для уведомлений Точки: $hook (200 — так и надо)"
    if ! smoke || [ "$js_on" -ne 1 ] || [ "$css_on" -ne 1 ] || [ "$js_code" != "200" ] || [ "$css_code" != "200" ] \
        || [ "$hero" -ne 1 ] || [ "$dlv" != "302" ] || [ "$pay" != "302" ] || [ "$hook" != "200" ] || ! art delivery:mode >/dev/null 2>&1; then
        red "сайт отвечает не так — возвращаю файлы"
        restore_files "$BK"
        smoke || true
        die "установка отменена, файлы возвращены. Бэкап: $BK"
    fi
    if [ "$CERT_OK" -eq 1 ]; then tochka_check; fi

    echo
    green "ГОТОВО. Исправление 14.1: связь с Точкой, причины отказа кассы, подробности заказа для livsipro."
    echo "Бэкап: $BK"
    [ "$CERT_OK" -eq 1 ] || yellow "Сертификат не положен — повторите: bash $0 cert"
    echo "Дальше — админка → «Настройки» → «Интеграции оплаты» → «Проверить связь»."
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
    # Возврат к обновлению 14: файлы и сертификат Минцифры (он в списке добавленных).
    restore_files "$BK"
    smoke || true
    art delivery:mode 2>/dev/null || true
    green "Файлы возвращены к состоянию обновления 14."
    ;;

*)
    die "неизвестный режим: $MODE (check | install | cert | chek | keys | sdacha | switch | status | rollback)"
    ;;
esac
exit 0
__PAYLOAD_BELOW__
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
