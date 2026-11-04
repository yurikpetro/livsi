#!/bin/bash
# =====================================================================
#  LIVSI · обновление 13:
#   — фото товара в окне товара на телефоне целиком, без обрезки;
#   — «Баннеры на главной» в админке: тексты, цвет, картинка для компьютера
#     и для телефона, главная точка, предпросмотр на обоих экранах;
#   — внутри и исправление 12.1 (подсказка о дальнем пункте), если его
#     ещё не ставили.
#  Ставится поверх обновления 12 или 12.1. Запускать от root.
#  Стили страницы: к нынешнему файлу стилей дописываются несколько правил
#  (старый файл остаётся для отката). Скрипты страницы не меняются.
#
#    bash install_delivery13.sh check          — только проверка, ничего не меняет
#    bash install_delivery13.sh                — установка (с бэкапом)
#    bash install_delivery13.sh rollback       — вернуть файлы из последнего бэкапа
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

# Файлы, которые правка меняет, и их md5 сейчас на сервере (обновление 12 или 12.1)
CHANGED=(
  "f09f17960f6a1123878dbd5c8f479e86|b018cebba1129c10717719a1b4dd4599 app/Services/DeliveryQuoteService.php"
  "c08b748866a230ac63c4de8420fc14c0|c08b748866a230ac63c4de8420fc14c0 app/Support/Media.php"
  "1653d9f16c5bc42b8da2a51605caff23|1653d9f16c5bc42b8da2a51605caff23 resources/css/app.css"
  "ceebc3819711e68df03060b63fd2d499|ceebc3819711e68df03060b63fd2d499 resources/views/home.blade.php"
  "6b97bc50bc82273d3440394dee79016a|6b97bc50bc82273d3440394dee79016a resources/views/livewire/quick-view.blade.php"
  "ffd6b0f6d9eb0b065b253c8e0216782b|a6d4fdc8d14d0cd9515cdfc8e7b7c655 tests/Feature/CourierAndHintsTest.php"
  "fc88cd53c4a39e1831c1fc0d8d84fb38|fc88cd53c4a39e1831c1fc0d8d84fb38 tests/Feature/QuickViewTest.php"
)
# Новые файлы правки
ADDED=(
  "app/Filament/Resources/HeroSlides/HeroSlideResource.php"
  "app/Filament/Resources/HeroSlides/Pages/EditHeroSlide.php"
  "app/Filament/Resources/HeroSlides/Pages/ListHeroSlides.php"
  "app/Filament/Resources/HeroSlides/Schemas/HeroSlideForm.php"
  "app/Filament/Resources/HeroSlides/Tables/HeroSlidesTable.php"
  "app/Models/HeroSlide.php"
  "app/Support/ImageDerivatives.php"
  "database/migrations/2026_11_05_100000_create_hero_slides_table.php"
  "resources/views/components/hero-img.blade.php"
  "resources/views/filament/forms/hero-slide-preview.blade.php"
  "tests/Feature/HeroSlidesTest.php"
  "public/build/assets/app-YT4IrMBC.js"
)
# Файлы, которые правка убирает, и их md5 сейчас на сервере
REMOVED=(
)
# Не меняются, но входят в собранный файл скриптов — должны совпадать с ожидаемыми
BUNDLED=(
  "5f8bc6a430cddd1f9c8efd32cb980228|5f8bc6a430cddd1f9c8efd32cb980228 resources/js/ozon-client-check.js"
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
    ALREADY=0; [ "$installed" -eq "${#CHANGED[@]}" ] && [ "$gone" -eq "${#REMOVED[@]}" ] && [ "$CUR_JS" = "$ASSET_JS" ] \
        && [ -n "$CUR_CSS" ] && grep -qF -e "$CSS_MARK" "$APP/public/build/$CUR_CSS" 2>/dev/null && ALREADY=1
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
    if ! smoke || [ "$js_on" -ne 1 ] || [ "$css_on" -ne 1 ] || [ "$js_code" != "200" ] || [ "$css_code" != "200" ] \
        || [ "$hero" -ne 1 ] || [ "$dlv" != "302" ] || ! art delivery:mode >/dev/null 2>&1; then
        red "сайт отвечает не так — возвращаю файлы"
        restore_files "$BK"
        smoke || true
        die "установка отменена, файлы возвращены. Бэкап: $BK"
    fi
    nslides="$(cd "$APP" && runuser -u "$OWNER" -- php artisan tinker --execute='echo App\Models\HeroSlide::count();' 2>/dev/null | tail -1)"

    echo
    green "ГОТОВО. Фото товара на телефоне целиком, редактор баннеров на главной."
    echo "Бэкап: $BK"
    echo "Баннеров в редакторе: ${nslides:-?}. Админка → «Контент» → «Баннеры на главной»."
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
    # Возврат к тому, что было до обновления 13: файлы и прежние стили страницы.
    # Таблица баннеров остаётся в базе (старому коду не мешает), загруженные картинки — на месте.
    restore_files "$BK"
    smoke || true
    art delivery:mode 2>/dev/null || true
    green "Файлы и стили возвращены к состоянию до обновления 13."
    ;;

*)
    die "неизвестный режим: $MODE (check | install | chek | keys | sdacha | switch | status | rollback)"
    ;;
esac
exit 0
__PAYLOAD_BELOW__
H4sIAAAAAAAAA+z963Ybx5koDM9vX0Wblg0gBkAAPIOmZEWWY01sS2PJmZmP5tBNoEEiwslogBQj
cS3LihPntcfezmS+7JU9Oe89714ra95Ny6JNUwev5bkB8hbmBr5b+J5DVXVVdXUDkGQn846ZGYvs
rq7jU8/5UJz2e73py0F/u1kLwukXglZzO+jv/s2wOwjE02Jvq/dXj/JTgp/52Vn6F36sf+fmZubm
/qo8V1mozFdm4P/+qlReKM/M/pVXeqRRx/wZhgO/73l/1e92B2ntRr3/T/rz3Bk43See6PjtIOz5
tcA72+u9IcFh+YknhiE/OtfthN1WAP+2236nHr4hIeXicNBqBn1oK5vKN+qX7/mDYMffdbS41G12
Bq/i2NHLV7r1oBW+cc7vD2IPLweDQbOzGT2/POz1uv0BvO8EYoALrdaw3ezAmOrti37NrwfYZ20r
GN2qv9sbxFr9wG816/6g2e1ov56/Vgt6+As3v7LV7+74Gy0Y44layw9Dz3Wfnrj+hAc/veFGq1nz
GsNODXvw1tdrsMeD/rA2yFIDatRvbsPoXj/w691OaxcW3by81eydgz3vDLxTPv+ZT/7gUr/b7oqR
vVM9/CtMaW6dmXdqk3/hT3Le9b0nnLMPOrjuejZX9Ta63Ra1ua6G6QeDYb/jZfFVzhOnWK1uBoNs
pi5GXK/5rdqwRfu6LrrL5L2G3wqD3DJ1lTD2IAixv8c1NnanBlbd4M8zz3hwQOuIMQbh+k5zsJXN
wgPoL+fB4TWam9kMdBg2i7vd7lU4f78YBjWYwPrVYDeTy3sZ7Hs9Yy5n+jvf8Z4Xs/T7fX/3+k7Q
3NwaVD24HHmvFXQ2B1vij51mXf2+FbXa874z7dyant+vBa0sXibvVA3+C3tEY1ibdKrn1+uwDm8l
aYdEg/V2W06fvoOtCNI+ql31N/EzXtL6pv5xs+Flo4FXVrzOsNXybtyQvepPZLPnvFLUAv7IaYvg
CW01w8Lpht9sZTPHvz4+8E5uHd853j++e/zg5ObJB97J28f78MvNk58eH568A7+/Aw+hBb6FP49v
Hx+d3Cp6x789fnD8JTR+cPwJ9PAFvDvwjr+EPz8//uz4oKivQ5wjDc/rhB3JwrHkeJ7advFhwutT
W7LhKTpT+KUUtWt04ULWtmB78MwKp5uDoB16fuidwt/sNdM+4gtoGK5vNhsDhNQn5V7wncd334NX
r3da3dpVuKmi63C4MegO/FY2l7P7xR8AazjYobYGXrGx59t+v+l3aDE8DfFgOTbN7JOqdeF0vdkO
OiGAabgO0NJsNIO6MXF1r3N45ICHs2JTVQ8SqvKe/YY3GqA1/or22/mGzwRe5QC0yq7teDzghQ/u
nnzkwZMH8GSfwOy2h/93BC3fBhg7PL4PUDYRGDoORkDjsyuOPfO+I0/rrcGu2UsEpm3/Wlb8lXft
rjV8BNX0If+Vd+6w9SFfAnOi4pwSJqrdOoE8V40uM7zQjLdyWm5E3mzAq6AGDAm1AI5Vrtd71qvg
0ALz5Lxpr1zKWV3QHOM9iNWM0cFWNEmjB7GRI7pYixMSwPh+m3H8c0yb8nB1rgV12IU6XSiictyF
RXisD0QbN2WRFDPQiEueuzEGSiA4V0NgubJGQ5MwKCygOIt0VP87unc/Pfn45B3rvh0fws2CK3Rw
fA/+/z7874GHt0u2gnv1Jb6YFOmreTRgz4I+sFKMAsW0ooeMam3Cpy19VaOYyBGvt4PBVreeWSMa
KFmyy0EroL2vVi9fOfuD1y5euvjy+rmLr7924fxr9t6IUxVzqXWHfeDRs+KQ9KHzxvTd6wMCst2s
B33PORf59vvB7niLih10s7NOIJJVI+W91UytHlwFNiyz6w/8a038rfujbgf6zKzlPWCTg1SA0CYN
W0iftrqbmRhCP+ONAJ2L8OXY8PPzk/ePPzk+gMaHAnbg5X1A+e9gF+8jpUDCAKOcvA1vPgXI+sI7
uQnE4BYA2Ccn7xfjM6zGZ0i04x+Jepiff43zSgCNrgH0QlgAngOfR8xxKlw063iiCU1qzcEuvY5A
I6FlP9iEB3BnzpwhtjGOT2i4dCzyb7ipuCtJGzTm/mr7mLR1b6FIGG0d/UnycMI11TaAFpJ4c7nn
1UwH/osoJFpi/1V4NH731A90bNNZfvHQpCe9IUNUGllSFEnIrookaVuYTpPyHveor1q0EmuX7/Ud
dhMyE9OqC9AMX2j2AUVGiMgUJc8o3CzpqGz/MIdvdF2NdS2UBo8CWH9J5xxb1zd32qeAEesHYZgs
8nb7zU0kaNzOkJV7zdrVYQ8vZJw4IV0DoKjA6TEfmCRRUx/rg91eYIvST0azA3HJIKv0ERDVct6r
jEM9vznJ2aQkFzoX6iO3llqGwBCsruVW1VYqvG/rF+RWP2lsNciZ6jzgRQUfICsSrocAXj7wSmpC
Od7OGu75eh2moBO2qNUj8aepNP2bYz4SjoaUSBEhEUolm6s9tdGt70athGpSQ0NZAkfgA/ubQVb0
mreltka/2yZxCDjA7rAz6O+e69YDemKq2CRYFEWz9Rq2QyWbuAWX6YxY/hOP1izpa9B9xKGIPaER
CBBWM5JfyQh2RH8nORR7FvT64nBwoa7JgfIj4o6s9hHHHfL8o1sAIzNYXwFoVW/pCb6TK9HeVuzu
/TCErQrq57qhkJ5tTRGJoSVcfjdqhX83O7XWsB68GIjOE26fOWCxWBz/mp6Bw1J3LrZd+HANUChg
Bk1IzlkKy2ajgQh8dc2lcEMgjrjKK13iJ5irXF0jFdxmvzvsuXRwWX61qh+Qxo/S0iJ24CGVbdFM
xWBiQcYU+ZlTgVWD84ouKTfEM8yKjzSGwNLRyGUilgwD9YGcwQUU7Ug3y0MI7W2sC/xJoE+qRx2E
xboE1Rqju4rWkQnvY3dlro9Bqx6K9WlDWXRA3FZP4Dm/l81Ag20fIN5z9ZZAidPBIQ4SdK4CDFaR
4V+FK6WOM0OXdr0W3Wb4bS0JxPZslkL0m07cfqGTr0+RRCnac7xP9EqjekSy7ouGxEggxfrx8f4I
QSqJoCWQrSFrmMT8816j42UF2+crBnAjR/YTphGr+latec/hww3zYRyT0F0Se1/StjW6aPKWaN3E
ZSqLCpINimZlqcITSIGJbfHQaVSUrbmX2FWN2qDtlzGphGb7aqOFli9PJmPPAGgD6pYs+IpNs1kL
9PXUhuGgC4zAJXyh5BL80u6/7u+G6+1mx1wH8BRBp+73X4C3rzQ1qT8vv/CvpXwBL6MvrKkSWyL2
U7AoGU2a4DeGeMFXWiP1VpeaWMGNtAfwdXCtB6JfuO7z2053J5sD9qleh4UN4eCz5RL8PWi2YUy/
3UvU/k57hUf48ZB7h8v2Ad4+w84Kow4MzS9rE88/tMUXOtzud3tdvA3U1Vjm3oRpnAOGC+fAsJsw
C7eRVrF1QrAg3g0O5Pj3zJqTDIOCy92TDxw22+NfRRsGCIkEnEPSHSEC/JQMOQ8Agd1CJvsIxKh9
xFjef7z9C3iAwhR8c//4EA1AyN5rn7C4dESIch/xIKPXmx61v1e0dPIJO4NGf4CeM1K6xeWlHlYY
tBrVqn28yIM1mq0WmQyxC3wQOW7AB/AQlb8RMeRmyAG42hnD8PHFNleaJ8w9po2NH86HVe/kJyQd
Aa05PP4ct2mfKA22OTg+yntEVG5C+7u0oSRFfcnkh7b+M5aX+FAO6azu5eUkQGp7Bz84tAW3fTLZ
RVQpi33SoJ/mPSED38OTxEPNFY3V0MdAxD6H/ycIQMi5B798Cv9P9r+iGF+Zalid4taVPKJxJ6Zi
ERaDcTUrozUnzE64QSydudA2jS7SPtyF2EHAm4PjL4Ad2OdrcmBxG07mYiJ+wrEAccNSVdeK/R97
kTouIYYK0A+CkAOxZIqOy1TMPPJaJfOCVtxS3qmRiiNxJvJ5r7y0VCrlHHrjJB6HcD5SWdKAGo9E
3/QMeeU09kc1jrE/jjeK6cnEUYxzV/+cbI+LsXGwLqTw551qtiN0POrc6LM8snY570x1BE8kpzIu
Q+To7S+THWKU8jaLJShsIBVgjA+/fAp/6gKIxgX8AgDnPcA3t6HZgVsGIoTkESn4jBE90gRE86RE
BTT1kffVn0hMuonASK+IRkVzArr/1V1iHrgzpi63CSt+hESsaCNzcif0Xr145fz6i69dfAWucwX9
bZfjRPa3hE5uijnBf4F6IbmkDbjNCOMTmtb94/vwuyGKRRpEImkwlc9Zyfk5ElFcrrkUIrX65sIG
wP5p1JZJ8u1I+Ds0ENjJ+160x9SbQokwgywRhSMPvWz+D3xwBxkAmCPT6hjzcHxQ9ZbKi95/vHMf
ZiXnsIRTQyLyBc8DKbQl0+L+H3rlOfmpN0PfnHx08i59I8kTIm/cs4PcY6Dn6V/QrRuzLdnK0nkA
aS98VOuK+IutiVXvjJNTlxRHaPOECdPAsrpwjagQhWvRTmFGIrfmU106tGwDNOZzgqZHFwVNATwN
GuY5bzbBvcLsMiKfg/6uTeyH4dDXVOj059j2ONZbRNsi8LGujdjzav4A1YLKB3nCOQt1D01swk9h
/n6f3EN4R0+vuLbUdhivVpuh+J1PIi9GR228rWtpdZFE4Ah0JKgWFm3xbzhg68xOK0gymz3rzWi9
NvpBEIM5JtrseqO5ZJ4KhQ/1ivRNYe7h90AA/u34V5qnCj/XcI/uu0Iv0acks2bZrrArhUgz2sC0
ghcEwJvLga+g76L5tJhRHAw++Ntuv551bUYOXUsycTatzYBEqkkbjMVJs5cmb99ptTUwsI7/bwti
aLlTaHQgU8zKk4WVeBZFyND8ctgrmhLfB8IgtIbI36ZQh7gPTdGYoiTSusgNfRBtxdlmihROUK32
hxtZCyaL0XEAn21ZMhSYPsSeyFXHFlvFA67TeInnin8Lvv9r3ZeHBLT4TtWDhj9sDeIc4p6p+hE0
ydZwqOFQd3uqk6T+OdXuojn5VMd7Gg1Wk4E6fgtopFwmbIN/PQd/zfLtFtyBff48EKGOsmx4AOT/
A6thZPrgL/LeaiXvzeS9WWmcUMOcfJS8da557CWqUn6j4AolaE1jwuqoB8g0adbgKv4FHBzze3eQ
yz35mFjBL1jLgvoVgOp3NYjDPyIuiXyaNd5MsXjk5Yy34G3uLeIv77pUXx/kDYfpL6S6hnis94GH
OwJB1jv+p0h9RpIvzPaeU5MG3e2rqdw3bsnxftXjXUE9w9skOiMnibts8vRsmL/JPCJcWdIsIct3
mCdEZjD/+8Tx0woPLVlAY3sPmeVWIgFy0HKZH9My4W7mcUvv6KYR166zxUXywnic6MaBJyo1aEI5
tc+iLy3lCIUMNZ/3aNybOJKbcUaF1m/g8W0WaZDd/5L6xCWSQotXgcuf91g1g7NbjoQLU3agjXkg
e2Q1XAQz2PdthhqcJ8kJ5BjxOV45HhaEp3f+EthsaHBaOU44tXHXkZ6IQB7EZsAXYxzPjchkmsSW
M+v4mJhyniFy5U5vJ1RhAQI19Qmj9V1SlaB9YrhHiDYaj3M1wHFUXwVaZDVT3PLDrWwm3PIrc/PA
6vwwpNgwdL/Irmprc6mZWQudV1Ycl/ItzV0vn+SGxGoq4Y3EbkiJbW1vMEDvf3354qvrV1567eLf
rsMv51977eJrhoNCDYMUkWxRtCJ3iPtjeXs1Q0lC+IOYflE6JvJrycBISQgdEMVI1RhjH02mO9gC
Vj2SW+hv2u2QN9ghr8Td0kQvmiEhBg5uFWna8DHbu8tV2J6Q7tXUxDAo6Jut9dt+axiEwkVp2Gm+
NZT+SjDnAcgoqyW61/XmdpbcgLJidnD4Ffh/85lX8MqWXw3an4ldaYIgcE0YYohVpAfIa5SI1eA/
n7N6zBlAshGQtGweXOQaItZGAWM0mnNjcV+591VuZnkFxMVY+nak47LLw9uwL/Oo7C2BMlmyAEwN
8yayoHMcKe7izzj+NKQI4O3U4w2dCgg4FGqqpAHX9oijEW00DyVnl0Kty4rQCbUZYzhxCBzSGzIO
yYvpoRtMND/qS73SNK4vdYf9MDuPgqJTDevyDcdOkljQiQir6LDVDAfPuT4YYcHSUYVuAR1FGtPd
vB24QqAHZwSEC0kCGqmUSjnhCBQ5JcWQhZgVLURDF85bRL5lTm+kFNvqv0oJIMEd1cU7VwFJCd0n
6qqB7bpHHCJIFu9JppwV1vsOoytLEsZgajaHgiOMGU5ZIr3v6BDYvQ/jwZVZ+OC+hxwqMJaHQuq+
Sbr1mzDKIfKYP6Zp3qNG96HRR0o7m2QQNSw3OvdFOJ1MOMTO2SwURd++KBRN7A2RRf9K6DSbgctZ
u9odDtapVREVKusSd2ZyCBjSHyJ2z6J+KRzaiPTtB22/2QFQuEIt0A23h95D8ZBf1nLBpS9h2ACu
YjJ3f6YFCSGCg+7VoJPV1b/R5RNbcKE+wnWDkjFUq8Dz4S/s0ps12MAM9awhUECpond6aLGQ2sjp
zNifI6jSr2E+CYWvaAPz3ri8vr6nE8dcOnTWdX/gA9TSbtcD2m1xHPCXdhw8T+HNmPcaLX8T5BnX
1lpBv4qTwJHkQVpUjVlIPMT14C0QCtDcTq3lGcMpJh9x3MlTI+6apRG9/SSNU6bFtG91Ayhj3zgg
uL/UzZ/iy6SIUVc38bhYV6tsVrEbwvS9FmGT2Af4c8Z7FOcGZ5dVbRrCtq/NQl+6Qw5T2nhh4V9z
j2HvitbTJfHpWbhb24GrR3fKgwniNoAA3RTKFKCORU+0ViEyIvqFGj1ArYXwSfkAVSA/O/nYY1uv
I4zfKcppcXfUKsYEe6cCmZHmEaJRvo5V7bnUupGpUQN9I1dJgq2QyFir69ezGUqNURRJA4pwuPUh
wJmDYqapEsydEjk36nlPT78BdBPQ53d3s8hWAZpA925k0zglh8C4FFERhyhOXyAmty4mu04jqMwG
6neRwyNvJdPA74H3yCf17kiKYb/RkmLEPlJJMew3KmXD6JEdCT3Mj9Zy2JqYZxQmWi3br+ahGZn8
xNlOzIGTWDIR6WO0HSNDTqJCyJURZ6zebU1S8gh6hOAoxZSQQcabQmKSpHzcTd1ytfV7zRAOr4hm
UMxz1eHsAagZSB17tKuwvcIkvzRrmARP4Hof/03bXtkiXe/n0hGq8cflO5VoZGBr1K1/SU4/90j+
Yf/iKIjPUn/rVmVh0UFJ6nOpo8dn6G94qIVioG3jjhXFsaxbVRItQ3kynKDJIRLsWFoUsbqGzcBy
40HPWzQTSYdbXNnb0dJx/pHOX/SHfrboAnxy0zAUsN8ROobySbAR80sZPPmFQwYktyS2P0hHYP0N
+ls6tv8btjY8vEOvFWb+DYZMjxs+ykmukuLy/lMkwqLpcQhWJrw6ZKHUIpLwHCRUEGWgPxE8KhSE
GiuguY4aL01eYM3mr2gSUYRjsrYq1RgiNJSaLUQGCoZR2K4EhFUVL+qOFcWoUNyVaC/CtVyisksE
LkbaLjhHkodUGI9hxaBYBPudVKRLW4MZy0TpRtT30URycbPFf6nQNyvobdxwN5vLHjuOzXD3HuXa
nRzZFuk8Hcc8SYwbNxk7yM2EuYfz+zb7GC/wLW4FSIx4M80IDkfs4Nqg75vf8COnSvo/cWTcKBWe
AO0k2qkFSRtoKk4lc2zGj8mrRsx1G53LsrEQ5XMmZnO5quoWqqiDRhCEFzjwXkdt1Nrl2aQMw0rb
y3tljOeOxx/TMVT9Oj3N+bOaITR9C6jQIKh7mDcVBCwP570Mux30/Gbd6/braOrd8rcDOGjgV8It
oLktkWYMGxc1VqETDvt+pxYk7qpq8WIQOHf1FI7wYpDcA74XeXNVHyWXJj6azJPyfJAqyf7VwzMC
EJ7VP3k2aqjb4ZMUJTxHglvMlAiE3Q1yYm5APTtDwDvNWla0xqnBU7hnQIqz2Uar63MKTPmSfyUi
ahz1GcGI9LvDTt36EDjTcqmUG2sFRDblvYGLG/qbuIZOAHfAWsQA1VqeI9tytYow9Ap/HGY1taRm
CxB9rymxau+JP3ey7b/AnyLnf+cU2NOvBPWm/8j53u2f9Pzv5dLcvJ3/fWah/G3+92/kx53/ncEB
cB1pISjISfGvHkr3bKI9PP6cdAd3KMLlHrmFkvsyis+fSdMq+SzqHp8H5IcIzO4hNUQG+c1Or+31
h4Cx2nil3wRczwP/b2iAvpUPcAhPdn7yM/j/D4WDoREjCU8+ZCUCmaKFQzO6cH4pVAcHMMc7qifo
/AFpCsj0/Gq3HrCp+dJLl3D8LJmgP6XIIorqQS9OUqewZgDjozC2GyaD/o3veJt1acnGlTRrV9kZ
FAb5CPUb0icSnSU/pZ4+5zhi6W56SOLB0RNsEf/co6BxdqmEOeakvypOmlaDKbYO2JucdRboxgsi
wscYLCsWRroPpeSnYyDvcqFn4SRT5DpawP88IZU2D0Tw22fSMRcdVEXar3sUDIayC9rVbVWO0q3g
aPvqMP9ZxpQ5wAedWFlYoumSryupcu6TB/U7rJlBuYe0YDCVk3cV6H2BI+4ff4HTFPqnAzZJ4NjT
TwC581sep+QnHGfm4OdIuRcvvvbK2SuXSXj3t5sNDK3YCTZ6Kl2H5Y1+RjCDbb/TbOj+WHrXtue6
bJxNCZNmM1zUr+QgErgvq3kSIzbsocEkLKLhgw/zPan/I4uOUOrxXdVAXgfAAx1yP+M7aMLu0ckt
fdAsqh0pbRmqDt/PLasN4HlwMgS6hG/TRYghgghqoiMWZ6sPxJfzE4Sqk3fzHgdGsp9w1P2BxkJq
p+ZUOAEL2d6cBp4UGIv6tL55CBf6O2NFGU7A1GjGPdJAaKKc2gwZ6/hXlhvGLd7EorUoS9pgy22l
1BZg5FWTz/OG4V7Jvdjt+mYwQC38ICAXJRpDxh2coexZyZ5lNotpA+uKZ0FiehaPLT9yz6J5pCWo
4LxMPGJ0kVbpw7Vk9fk/S+skx7MCjCDO+xlCswG6IjlHHDtxygHhoPQu4d6PPEabGh6CBke2OvhN
XF/uzcit/adS624E9pOjv4VS4xSMvbK++hP7ZaGJlXNH8OKYSh6pVYsQW8KpX9312CnqvgiXhb4E
Lapq90oLUGbvKdH1be9NPr43MXQidkfVkGz91eOTPyXC/ZEMabCcrNwg4W+DiIBGnvEBg0EC95rb
ooAsr5Bx3fg6xbQDb4b9GoDWmyrOxKBfVdhz/cJfK8yWSkUkDR78spMHqPnXr+6OysrCQxhr0tTo
3X7bHyRHxwq9Lyp1E8B/VfQhdDeanEryfNTBCiK8SQV58V4I5FF3plNGZBQXpHGb4ph8Tqe2vZqB
PaBwMAwlgz85e/5aMbOTsTtqtgHjAt5CZJt8s3+vzFBAzA45iIki9f/60vnvKVYAGB/iqm4LF0Q8
7Ter4kZ8SbHv7/I9ihg0ZjORPTtgb8TE4CXJkHFuHpnJ5T6avH5w4cU8//q3wcalsYC/4bdaG37t
qg37Dw0WmR/2NjNxoJCGJHE0JnSgqoKIVdULQNiPTlueYDwX0u8Jp95kLCfSBBwIRxCyxr3twJYy
G5LGhhr7Ljb3DnKzlBfnrsHaYrbZ9N2MHBLs/XTHuwSY3TN5M6MwcttUwB/qyQPpiQLxvCcfiIIP
a0nxGqOu4KpWcyI2iF5Owh4wphNVAP1bGdUFG/sTEmrowiA0H7F7MOLEc5cvA7j04PoX+qiO8bJf
/ak8Xyp505RX4qu7sKtsYbUI2cmt6O7sa4klRgsBX3LI2j4RJiSBklRRnuFfMyEVjwQs4faNddFo
DTGocF+zCIwUbGiQxR/HrEfaR1Lnhgwd/tdmAgWThzHsp8Lmj/D988CiMXmFvwWbGI+PMSZmQAZ+
tlqyQYKeltfW0gDMmrj2pwI077QIVdHfSTjDl5bm0NUHEIFpimx2dGF87lAqEsb5g85AqfDQA+GV
dpvCVxGbIPY/ZD4GZZcj4KgkrbitZzSxoHYUamm0huEWSnHb3WbdApg4T6wv4S9KD1mc7gdhd9jH
4o+1MERtYBH+faxjpOr/KrPzlUrZ0v/NwuNv9X/fxM/zwGh1+wM0lDZbO81OHQ4fc0I8z0ABN7Q4
Df+3DXxAtz/d8vv+dtCabvT9drDT7V+dBn5gOqqSOH0JfdtIWa/B1XYz2Amnv1PcaPl1qiYKA1j9
h4NuH5Cd1rH8iJs/UWwFfr2ALK63utWs14POmrhw9WbYa/m7GKLUCbwneT1UfAywRRH9lAuI1Xui
OTJXm2TEgKvr97OFAjC13X6h5/co47H5UWFjOBjAbbeGanZazU5QaLSCa3yr/VZzs1Mg74aqVwPK
G/T5xabfq3oLPdEMdqkgy/bNVORT4ThYlelGNsgWFv2pzXjQ9zthz+8HsrgaTd5cSbNzVVCjBoj4
BcT5Va9ckqPRQ1lhcKEkBmkFA5hzAXXANJViaS5oiyGG/RDHIAMursu5RdWt7nbQ9667ZrTZD4JO
btlTH2JT2Eh5knmPH9ebfqu7qR1w8uFCX/o3hW4vgFPysONGq7tT9biPqJ0YEtr0umGTrEheA+1o
y3CawLHhdns/KlAcZJWyRUSDb/ab0Ax+rwXWGUdnV8ENVqPhmdX73Z4xnL8RdlvDQaCPqB9uf3Mj
W/Lwf9Pe7PzTOXuRAgqjDvsBemNuC58kURsS6+TB9J/Oe3PzMCcBCm3/moI8bLFU2gbxE45ftZBb
V4AF+8NB14LNmVkJQBI8y71rHiyoWTfOGi9GTm8I3GK9OYQtq0Q9jLiD/PG1Qrjl1/EsS/SttwiT
NTapUnmabqwBCbVWNwxiW6X2np4PunAry/NyPn3el+iBefLcleP09W2P9kdd8EfesbnS0+NvmHbZ
Kwq1OO6usVlXMVC4L3ar7fcBe1dpc8tq8sn3OYZjyk4cs5iIY8oViWMGwbVBgZAboviqN+zBwmp+
GNgz9rYqcKeimc7AmdF68TJpc6m1/HYvW1nsXct789s7eW+WIN0jvC1PqBxDIzW/U0drc2AMAtuB
Oxrb4jgujfXX7e1qXeGuYn/Lrl1tA3jWc8YoPKw55+LcnHsYHwaifUSVL4k3MCKADOA96CD2jU98
bKgjWaJn9N/CTh8JF/53mWkYERCxkALdH7rPdq/NTm84EPAkbgYiI/NmzDlIn3b70q9LRN6s21JW
vXYxrVgnYKrxUDeoPId9udZWbXRrQ9w1MXoKuTNRGP4P4VRv2Qm6nRiaLwDnNGwNQgU3vN18AOOg
EufGpJBGa1g3y7OB3rLL7nNVp4gjib1zMTI8rY0u9N8eBxuOcWYE8MR9VQG/NAbxk5wZFxea66+2
/HBQqG01W/XosOXU4xfd+piYIZOtsZsoOEpYZbgVoMOSPRDGddRt0KAD1thIbecT2cc4nklDHvbm
2dPqdXtDkA7GxSaLMWRSciAT2amESA3QkBWgozWWajEctKpR+xvbhnGXavG9SeO473jQ73f7zmN0
IJS6j16RrrnuPfF8G+3ZXhYZPMmKLPUj33iTgdT4VSKcGk6Jtk0chf21ohinowPRUQGpNp5QsTRw
d7pNEP9sRBKxVPhbARiqHsYl4FKH7U6IXG0v8AdYxgUYVZGButzoS7MNU6MK4+fYcPZoCSIaQSXV
cY91astpS3FiVV6cnLPToGNnqykZUadsZS0pSRwieWralb5XWDXM6JkHx/eqSYkYXQl5Y4kIRUr7
Q+EIwm4rekJ7UplFsydfvAJml5bcuHl7K+YmFhB9A/COIvpp1znvPdWoNMpBTTS2OKfZOUL6jhmm
7rF9IGRoZGYAGlM4mrxtXeRtB/Bxcc48YBgEyBSQXxA4XYes9UkRiFGXyXyGzglFnAbJloLfKFv8
Bn/hujYgynfRJTr+og3TTmAGnB1p7XXkNpciTwjO13lkzlEEX63uqkBDJUuSZbLjd3Z3toJ+4O6J
kVoKtxPjwWIcRsUp9ijVSgpZjd6rZ4Vuo0HKgZkEDKdBCrEQhe1m2NQSICnmtzLiJomGKQO2/d4o
Vj56Yui25tVG6ff1qWAxWKg3eBSOU01SbbBqhh4qZYkhpEYqCjG3pdL2jjm3pVJ9eytSf4h2kUyg
60S0tknquJHiSAKKH/fiJmk5iuVF1nIYO7aKGq+1BH6LQ2toI5msEv9hfF+tajqqJC1Ucb6Si38K
m+bX3SNLrJlEbL0fDsNBswGwzN5HVY+8TgsbwWAnQJmECfG8wc8iuRXS5gQiRHzaUtkR0+PybZc3
XXZuTgKPZiGNnxR8nq1zjetCHLrWxaCdtM2k74grNkqo2KigYgN1HDnnMCYmLS+naFkSBu9ZHOpc
AoOaqLqIdSu0c0prRpscoY2eBUfjibuxi2doaubjOhQ3k++Y6QTcvfk11UK9bquQeWkwfALrKxje
yiId78wc6qwsLnjZQLQlfeiwWQ82EmUwB06wepJygNik8e4WB5RGJ4qBxqR/9gpk4sgtR9ov61qX
JRf4UFoNmLCkYCk6GFsToM1cBPUlaMAkBLrlV8QGpCLVtIS4PBZsEyRxx9CtYDPoaOK8xD2zE1y0
snNZDjTHizPpNH7rMl5Fhqv4FqbcHTm4UAMqvmFRv+blRWPjlintFYyRcozjDmmyRGgQSueGZP8u
XkgfA1Nx6tKzOO1EiX0MhIiJDfHgzTuoMESkriNOh/4Oa/1uqwXEcsvfbuKQSEX9pkHd8Rj1sxe0
zRDULX3dvFuNwhzvhIg3po9LOzqcq1KV6c/sUxxDR6Z9XmyGhTBAR0XSlBkcaKPUmAcO1CExsWre
bbbUey+o0Mj4Lo+JiMpuvmAs8kxTkHXe4zPQqfWs8/ZabMHMXKz3LUz7OU7fkyCo1GHb3X5ga5Mi
IE1Q800Gn2Op/uL2nBToxUlPzh+ATEXkxLAMK0OuKc3GCPTY10CNYglzycwDfiGdT+OMi9P2bciA
mmZxFGzoOEL2lUKn9Zml6Wlk+w3Us3Q72iUZdQdmlxN1Bo5+5fXQr8P8pHQgPoP4QIzDrJFMBmNp
TBaKJM5RQJokqT7iPTGXY+N76+3kmN+lAZ8na6/UgMc10kn8NzDYSvNt6Sc05LS9s2zoDuARKg88
+2+D9Yv3KcXoCNHNKmrsbG/LFMzPyVEl4w1DA+u9VGbWO85dxAWBQh+Dr5nM6MNG8oQy3M49PQn6
NeSJ2C5omFBxPXvWiSUgLb0bJYAoMaOkbSOryB+lMLL1g2Mf/4LcltHv+H6B3EtvciHZ433v5Qs/
uHyBPJCPf0mRQz+VcbDs00xBnZ9Q7CYWiML4TdSkV0qVefKf3hclQN6mdF34S9GbWZyeLU/PLuY9
ClVC5f6npNv/sfCilw3nyv/x9j/NzfLwbBZ4m8Li7ovoz0Mu90IK/buyIIfyguXyGypRzV1j5vVu
LZwuVQoAe51iu+599X/P0DiPcW/RfvDE84OtoC1NBmze0PfEsXfCUdfguIDVmyv7S7NCh6djOnhV
L9Xrc/Pmq42g34dL9dRGMB9szJnv+qgKAOZxHiSVuvmq20fjHOo2a0ulSsV82QraNF6jEcyWrD5r
3VrXh3e12mJtZtZ8F/rMrM4FlY0F8xVwn3C/6CrAMgK/0Yi/32gNcUZLS/VSY0HVe8QgjQN2cyYw
OuJyyffJa/pt9Hs+/gK97Yve8cfmHntWsDHXqsEaOAAyT5VKpWlYYUPANyXoZtfsz7E9xYWfvKuS
swij1LtkebqjMqxRaRqZd5SjyXGqR5TIDTOAZ58qncX/edPeUy+++OILLy7mKE4Bo8vQBfy2p4/r
gJOiDShA3Ko0/VLJAhPyMKBzwx/zHZF0eDfvw/+sM2VR86lgLpgNrC6JYFWlfUo7lH8V5W6wRhB6
plMQ65En8rBS2Pq7IhTXXpK6r7f41h7fxpR0WvQE2fNE5U6MrmGzoI5lVHCGpx0y9eHEEoeiDvm+
KsVDydnjdzLrQBdoQCx6s7nYQbB9GwG6PLNRCayNG9aAZofahRavmSvxkW5nvhc0KR/l2T6QyryH
T4Gj6DcbqjWrjVBbAIenrBPqea+Jp7O0tIRv1PgkXaOqFjhfICwLZBpHmvI8Dd7wlcFZ/N1utnbV
dHiIsF8DUa7fymamsVE4TS8LP8CYKuBxXoRn6ztwn4uDQSOT8ziKL5vBaFzO4emwLQGZ95Yk0PI+
DHZb5PAEX7e054pZCHf8Hk8d/gR6ueErL8nVa6js9K+uaSEMyV7V+FbEYWwN2i097MFWT4Ttbnew
ZXzCPIz6JGLvXNof3dFnpCkldgrcTEGJ1gz2ceNqcyDe0SSJAcOUfCCXwM7UjUnjNbVRItF/lY7g
HobLypLzwmR+QMf0tKqmQNXCTt71qMIvRyMCxi16IoUcheTeFGT3C46GkjPmqHtKxvCAEk48UNX6
6J6evCuq8+IMH6hyaVbk46ey4P0R41mJIhQKxZyfaCVH/KGGPpRcjPYtR2nJdNBUfBjTetqcg8z0
oWGRqNo9xVm60ESEH7bKjJy2KuLfGT07ULLLqAEOO8rCp1XzNMSvUnFpzoTrsjaOba0plIqlGem3
Kj+ojPigYn8wM/4H0Z2tdeEadjBoX4o2iJgiPKX1qTmDy+uiIzNM0Ksp6ivoMKRdECFwst5Yt7WK
6SuRK9JWLMwv9ow6Q8lzm3x+s9b89uzb+SsC8C8lT+NksbOSS56dy4Gg2kR3EcLF3ne7rTry1pjb
VuYTMWB9H8slMpk+UNBZ3Bh0XBgzFhyCP4kBIvgzwiiq2rFxtFiZ60vooMPSFfrGGyFZqnMsF80v
Nbm+VFxwvIv5MrjAFaB1Vv9yjItJb4XuqzxXaodeYLyPuUVpx43bDhxD/6qTjKRRhxEUxu5f6vac
ozxVbuD/4l+ihDHGzFjlMh5ls7tPn1htpl6enY9/KUwc+mdjOjZMNEFpcEmb4yMfUbAbbGBuuOtu
UJ6vPDwsRzEJY8LyCH8mOeXalgpBw59HwBbyrldS73qpuOi60WqLnG/Hve/zE+7RCC3e+AyegTgq
JuLQdzr9igQLIOOX4l+RM9h2MNn1dXQwNvDHu3lcyirFvv2WZOIopUlRxPtzFWrUJlUlH0h+nRgn
fUQ5zm+SbEfp4VE/8C6Iip6s/YpCIMnl94kZ/YIzPdzjP+TYT/m+v+RX2G+UEqZTTgjMaXz35P2I
4HJ6nSOdhOtZ2Lm6r61Mk6L/yftKfr2JyccOKZuASMRsrJOSwu1rCTss4ZgqjBGrevy5ShT1WA5D
cQyNZoDhBHEsoPkbSniitoWWvxG0RnyBP5b/QCmN0j8Sevx6sCMvFvkfECHHWG7MH1HcHYwsYu0H
VqS7SUe/LxVDH7GSyIKKiKHDH8VI2VyWZvoH1Fp2baHcXxu12qbPCfDdOCEw1C5GysXls5to7jNO
pKp7G2sYNq8ZzBMRr3GI1SrZDLeAtTZQoVjyU4tzi3MLDRv3Hf9PLjmId9l1Vny2KOc+EIIt4Q5U
cXMSEdKGv8NF108+jC68oaVnFl9wKkXzdqr5i/gcNXHlSVKK7enoYxwzEszejd8IheARJ7A0sn6x
ZvU+y+VaAkwpqLC/vy67SOyNqoT9qMr4fS6yDv1+lLAXq6irgiVtYyreFVJNZdZijKRrG2Tkit0U
XUNUuIpUxplwJCJkbAxHZqdSceYxMTej5izmhJjO7wd+IqKS1zsSlWnOmmi2kIBO4sikH/BCgIcY
NGu+mfuE7aSJ8/ABBfuUWlr3dKadj1RtlFimSnjJR3sJoATgMbOzc/VgM68HiWEEcj4G1/jUylZv
d1We4b5cn8ZGcOK7QuQdoWsChPSbIxcS+MXUE5T1V85eeWfn4PbNoYul+MXZlOOQcBvFr3FmW5g2
Kxq1/br4uN+z/Yu4n58gu/I5oj5O+FqVNxv1ffuYIfABGQH+FXP1UnK6vEeqxUNi6o5YdbhPH93O
x7WLh4r9ibFdrFyJcVaMl++SsvBQ1BJC9IRJpX5KdkeKQnqbzYwGWmZsdZeeq2EpcSGX8SFDqDZf
wcBRJl6Y2nuE699//Azb13OM/w9FUKGq96eUCeuIywvdYbJHeuT7yNQWMcGR0kBR3JXInEYVbfEU
SMF1h1IasSlm38oWHG2nmUiSLcEc8CW12DKZMufcQjL5qcgGKznmI1biinxch18Th9wKNv1WwbIS
aJ4ds/PJaH/JJgoW47UQQ/tPzfjwP0tZok3B0OtK/wK+7egCmjSVctHJH8YSIjjmqDGHLrVwKvtt
4vuxNDbGWquNZj+KeXYT3uTPZxxbVWatIvHMyQTbsY9OWp2+3pis8vBb0XMspWQcuOOjoSivoj8z
SLPdU1yTa0ceOUZpNeMnkyT0oYexNAyCIFVL7BSmafRrfBjUmm2LB9G/5cBBWxM3Lq+Vegy+be1J
CaJTbRIC6ZKGiKlqkl14beb8d4ifqWjd52RLf1+a3feFMuRQ5uqNVCvTovKepKjHB0ibNWr4JVHx
92RCfcxPj4T+J8jtVyPyiGZEYX5D1J03cmjeFyVaDzmV932ilAeiAvuB0uEULaSLFVqaaHwJXUAb
Y+FH+n859lwb4jSA5LZL0o/iw/GHTB6l5IsihG3H/MZOM5Fs0zLcCMdbBs05wbmwQpe+oaly1Qpt
ywz+GEpgNNeb0L6Xvr/1wdehHadItUSE/PD6n/j04xTIAMJUhcs4iOaxc3YfR4nuBYMcpSt9H6bU
vdoMviamiTsvkK9mdEdcsbT4o5KcLWn7KYJsUXxlDcVy/GaaNoqEoDLz5pbd1zZG+R6LdYg6MjQt
BXTY8ebtiFrEJyMvf9yg7dxm11b0uzspV9m25+DPuBZgtbGVGLqwN9dqYqILbSlOZkdXLI/Fe8eU
GPGkVUkT+LrIvD7EOGTeZdNRXUTpssa5FzZvKqhYTEvgGsBIv0QnoCmRKjYbHTPxf10mv0lN/E4T
Pn2bYvvXuKvfkugv9LuUVpoTVH+ESl4QYpHX+upPZhkF1gt/IgVlNjh9dbfKNTOpCogQcG9RSum7
VI4BFRsfKjR+X4rjd7nIj8wQ/w75TB6RfUmURTgyvZugF6FvvSnKJwgWr+hA1Oq4OVgN7n3d7+/G
dKo6+4IYrDI358n/Rzw2OyF2TEslmgKTxiTj1s1E9a/Ldp/Qfa8PYkbf7Rv48K4T7p2c/NZrM5zY
+0KDZnYt+kdWXQFsCY9BodUzdHroUYf1YK02yN87DSO24zWKBQqo2cvvFjkVv4P/cr1tPZV+Xibr
v0u6qchFz7QucOrsQzaWKqUjzEoqHX/yc7gYWjWMYs/fRELQ747J7HOCgxnK3DiHCQ4WFlWOUvyR
GI/kj4V5XXkrX0nWf7HkVu2mOyGMJT/E+MmxOAjXXtDCHyoTlvzRCcS8uWaT6qvh9fw5+MP8CaUt
N9gTDYJ/YWsoPSVfEm48Es9knfTbGtQdCd0ylYeqekJDifgZ+uMpK40xKSRpHIB4BcAin9WPGeij
6BdAuYImPJDKyk+oPDwx4h9LH1lxX27KP26JLPEuYTjapI7fN52MbPiam7d4jsmAID5ACizE5EYz
KMrFCkaZTGbHgYlBc9AKHMwgZaVJ4AgTdXoiTUlxhvlCgFsgVpSuhDkY22l7tJduGYTPZA7FUI4+
3GnY6zf2YJGiwyvGRjqXLBwHAHcVZwl9zQrx1Lq09tpmrdcun+BKMkMfLaMV1F2n6D7Amfk0N7Fy
Gkc/HyPBT8024H/+csLEagOd03d6cMQyQj7CcZrDaUNGCbIrSyWDtMSmkHxvACUCxfsN4BJB834H
eORzptHH+6jUg9c/J/sUeSTfAXT0Ebf8J4HEmBuQlRbRSRkLdUWIqN2tB62C5bOZIHQ8Fvfi2P7M
LJr7o8JADRqj79iMSXFHagpdnNrYlNeC1nQX5sUR8k1lbkIBx7oOlclh1XnAsUXZ99O891ofG26F
H+CWVI3f3JxpbWDIfhjQpbATIu0oiB2gUpqcVFIvigveccuCOK7QMtuXTMBM1W86DvuhKEXy/OJT
SD00DsV0W+Nxs35FZctkeE9UpHZflPKbjhqhiGs1Ob6nb2o4CGSqGfeFLTsYaINa6MYgy6Nkgus9
UeCIY872vGcrySxN9L1h1UoSOtz81iwF4+tMVyqOjfLWRdusJ5EtObe5vGCyVl+j6JGwKbGJppK8
419Lp4wIaPeVT17ke0Z19qqGL1qS2IqoBKOA77IfhyFuKvZf9wWJRE9pVvsIy4YlSggfkbeC40LA
PpxGUthx480RhpJR9GTRVvVFAztN5aiZXtD3fgIUvvDwDgXpBp3ovj8cqBkrjS3KVhkn3eIRGupJ
fG+lGhp/JNc6X5ovzZlcK4L6Lw2nqAes7UOPHC6RJzR9um4GAO5dS4ejoI5NA4kylns5UoiSMtRs
cV6IFPwkl7jO0nz6sZfj4Clm6BQfBDtcMdm+SIqI2XMm8stxSBJzAfxv0T6Tx2I1U0jlYy6YG53Y
UcqxAsX9PVdPR83vl6Js8H3yaXuAJNdjc/+vWCFCXx9Jk7sa0ip5KOplPkCCPa6nXTUKtdhXWpZD
1lMfor8Ye/Pq0Yz3IkewT+itKJgOM6VK2fcpfwCHS96n8ql6GhCqYyMjP4QP/R3E64Ze52t0CPPr
BQGcGlziDf25i6AItqnAumcv+1RjplEJNnJcaRQ35UtjB2Dn/0inji7nRvoVHXElxJTmPSqUfuDx
YCt6ZowvtN0mzZReyhn3l7zUjYLpnscWBVksWiV1IO9t0lMawQoTajQtRu2p2hL8z5RbeLuBS+oO
B2OwTpq+ltxpFxE7AW5SzPkkZMM9tDzs/wG7dVtGCkVgt+9RJXpRcHqf7tUXIgLEBR7FxUqjH704
hFZAiBYbfWNjaXGpKVpnZihFa4n6M3K0Um9pscq/JpfLz2PkRVbwxnutoyTBXu2bcQ8PSACj23+X
UqkcmR49sJfxKEl34uM4X4ps6dI4unPLFvOXFGbkci2TJQQfVihwyAORUiWqJqHuhkkxjQx5WhGJ
tA0eHejz6KKBa1/SNyHBiwkvvrUuY90z6cLF73VjKnmJGzGBh2aR7wONJH3OtJoKtn9I1gfAnB9q
1iquHUwBkOhbbVwTWrkIqEDBPh97B2ccwhnHXrCpfhXTtKxkwuFGuznIrMU/1+JZVvvdFrT1W0F/
YITQ0F7zFsMOg4hfKCcBb0JkYMwZ1Hn/EjUUI1Vi8QSek90+KmsBuL3ABRJtL4eHUWxaVVvwx61W
1g1kCZK4yoQ78VWjto8twNlZz8o6vrJxj2wRRxttHB8MOwVPklOG8wDHz3+a3MWEKZs5JWVCwubE
URJC2MYIXXP32qxR/aNx0qSyQxDK91TyNQox1fJ2GiUfH2ozaULh9mZ60m3npwhzIstilHo2JSGq
owthyDQypwuExGV9OIl1rIBiUgoXYndSkbhoc49iPw9JY3Sf9UyHwonnk+OoltEMJYXkT+5w4A0x
41/GmKZkxJ5mvHo4cjtqGG0ozShTNnXfMTIaV1zglqBi/k4Sj2ntgVtLIZQASwlKqm9SWcGiQoqm
YjZJU1H6utUUklkYTdqEU6A1aGqZsticR2i7UgmP7odNTdKS7shVUZZ9AbGEAja61wyYdbk/yusS
0/hp1UnspEZGtG9M/ZlcMeCbcPQWeiMUIDkYW4QlqvwRnG/ti+IT6qNfkQb7S7J9oU6JNDxRgsv9
qo6vhJTLOiZMJ3cUeRXeVuiRlTAy6Rxrrw7V41tUCu4wUj6he5gQkfcd6iZSj9ykyeBoeCKN/jTI
tyDN5q1R0IWysjRDaqHPyWkMPSuRhTJCDnFjWM526OWlrm3mq305tsjnMUYiD5j/f2dPJCpCpyIT
YUhzAhwZCf1+KMMotX7MqBnDG44Jy2d4jKj2eR+PXKVCIQXRBzRNEjrYNdVI/qedvJbf8zbG2Es/
qc/Z1HHEOfuUtoJUzAQQFPMfC7QlBcChsoIgjEC7A1YCImipgfEb4cwXhXfiYbhyo2Eu2fnZhqkZ
EXlyPXHmnDCFVZpR/DeJZuxPRWs/JP3MPfnwkJOrPBCpXT+WE7Hm8DVpD/vBdjPYCUWq7YeV+ouo
M4KrkGgNJDwddGzV2Nycw09ClXix/VvMydqBRfEyd/ZnKeoeS8/jrNmtXoxhVUoVGxe+nhAhucw0
5sTUPcSYk1nS2c0WqW7WvMOFYCwvtEry6slLy+ZWWFVNOA8j8ySnKnIM3pO6S+uay/y6+5TrSGHf
/Xi0vgzQNiypkfqa0IVuic3zI1G786ZAtPeEglz3p/+SsN0BZw/HNsXYxeLTCNoxG2o8H6121s0O
yBLNQWyb/rfG6hsJVmn4OzSdz0jPAyiommRSRoT+fxFry1shMzqSY6q6Rxqh4FV+wr79yvFZkctD
2up77gtqXExvT0+divLKO5KGYT6i92SM5wFxAD8m5CxSs598UORkXYpmcYIXpqMHUaZsLST/PhmC
5BkdGgkUVNZWyfIjDv4Jmxzw9G8zQiY/2XekkBX57ODWRuzJQfzgMUDHiPt9aAWOyaxLZzV2RjZw
aqQ/LTndJsaLSNU+mDRE1F786SSfq4VSooIGLQbpCGTBlnasUZ1pBCeLzbPMzokDpflHlA3i5lDh
z+h0YLywansGseByHdknekskk7TZcUjaCJKVNFWt7q0907k/z0wT9j2NuCIH9Ah8UlkwSganZLYG
DsWIbCA5Qrualk7XnCClb3fGuKoKSikME/5EZdW4ktC46l4bgYxhrrGmPuj2xpy5CtDVEMXX5ffr
mCRe+XzsaZJ36Td52fj4iZml0t2PsJ9Jjjdy8vNzyRzgCPwdZZDV08enC/e6BYtTtT3Qg79Y7lbi
+aFNjgttv9mxrUl9hHI0JWkuYBEJXUq4YdSTc4v1+koLCU778yO0g+RKUgEmGTnwGZMDt+vVzibP
77TXbG86T9/fgLs7HGinL3J0FWae1u86E/7CkvZQxrSUtWcqedui9rC78UMgrIVGcxBVYIzWTOWI
qh5WVhaR6FlR2ZmCSKw49IoezKXdg353ANgyO1MPNhPS36rG6MHtNNhoOybsNPa+xQcs5fgh4uq/
zxYWbU8O0WngzjQQ332502ZgANsEzGePC+sxASm7wZv3YDSFcxlybECZMayFRklVJ6YsxV+4hSRT
Cp+LMbp2UHAETrOLSaf1dTONWFEkgUJUHk3DMBGFgGmkMWKz45/N10DFkA78kdSyLhpQ1WsORMre
vNOn2XSCQmVCeYmiKA0lr0UkQjNqYWLXFx7DiWOwazuoNI0km8Uik3pMokNa+EKSwqe8kCiBAWmZ
Sx7SheAkEjPdeRiJJS6ATYHjbYebK3V1WK36jYGVyUJwd5nMsmOwOEoWqV50tKJxs3YmTyNX59zT
eQvjzMzm3OwuL98kN07nDKOKaAKN3U5yXhibCOLZCiKYNkPtDoc1vxVkMTJzLmZQOv5n1rcLB152
nH2gR0Cw6fVA18IfsstsdOkPUClj6lnY2e9zNEbAf++gMeSWd+m1i7G7jJxwsdfvJvLoKiZzfr6+
WHHuid1HjDiazhFGRmlbcGx0u2OYPEcozWMCS7yJkVHXFbuUEDxqTtR/RL3VRAVbTK83hz2/YrDB
+grnLI97w4BQdnsylSw3pmSJ9zGaAR5OE5GWnmS8GhDRF15lLkpWzo5MlVSUoEAhiW+Z0bfEjXTG
G2Gc5EPjuZM5kpU4h9pwozTF2f9ddtZm7DlLyV0qhvAeIqOqbg7Wi4yhZppV5HnUG+8Lnx1KZnuX
sjeYNb4QxRkZIBQyc1TuhTth59xLsOHhz7i5EljPbMime0/ER4jptPHHVEhbzhiuXiwdWnyetgps
pjTCo+cPbNXhgIWqzTkesqUCuEXiD3+mm3tvo0fCA7KpfK5SdZAFCjPEeCLq7h1KCizSDJOn0JdC
HXHAdiE5Nc70Qelj8lwm5CippsLxL6ga5buGXZyNWQqq2AFgYY5ds4yCchr76oCShUmAJMkKij8W
Sh0NIq4kEXE1x5IKhpiZi2UYsO2JCw8BlIaz09yYcG2aK6yZz5dH9eKA6wTaiT8shhskCn+UW+E1
O1c+/ogKkmHH7xXQx6jqXYOldur+oNvfdZ6c1O6YqSrwx8rRXrZCDdWvrJ3DnDHsbbXVB1zscL/h
srXvAPb7NGYo5OKJHPt+YNRYZCOluGaRMw7+mLmURFLzT1XcGbvmYIjRoWUAFgVsdNvsIU+liE70
n0k1Iprx7kRRtLRrUik+6vpqwTAJuvcR5z9Km2DMBkMI52xQibJNxKBIhxPhLD0SMds6Ug1G4A6N
gP4UtSgtxCBgTzshlS1BM/ZSdJfY2XEmYevw8EfegllraKXnnLdeiMnO2R+oPdc/cMwkjCdAGJsW
O2ihpVxIWr4lUaveSNNdSfs2Vn/SLdLjjxDrK6PQoSXx4I8hM4SDfjCobZmdxwUHVytDlCmPpEkx
kQZ/RoGjihNJ5jseiyuW7B35AUAuKikiobV/PPkx1R0/EiUPkot8Kf7ECoZltEsYVzjWsNdZ5Alx
RDj2Npe3P/TIZeJLEeV5i0Rskrmx4MEDdo2hCXwqEkrGSoXxc71UmBrqN9DmZxxA+6XwY/mJqLDG
Wcn+2t/2L9f6zd4gFmPscJHj1GTKPwT5tdtcPIIcYL6IqnT81qjv8NWfWA1IZX33pW7iq7vMxbOn
3kfyFNCD6C4wjD+VhiflTUkAFvitgt+Hm0cE44HwTXk/KvIm3EhuU10K8qvHhX+JOckVrSJvJXbC
uSkclETNCm8VaLtf4IHWvr4iHlwGT9RHEY6Wn1JWdCpowY6gKvL6dlTyLpZ+UYvpZyLU63c3+0EY
OlV9VuplqXqD/3Gm5eiVMn+UdXlbWRUMUZ8dtAuwaZ1B6MjSYE9tTKXhiGJrLIjKPvOeUbjHrcUb
M2xUF65pMG8R5WpWRTqk1Aeac5coMq0lZqCrJw/5CP1jSdwwfZgUXGub0kUr7mA3XhvNkp7/Pjtr
cvXa/E1mlzvEXCGwnNpwo1krbAQ/agb9bKlYoehh/A/8VrbqKUUqhnG+jWkE1Oowb6mKrIovtOxc
aAyeODzHkjmjvAHsHUxngoj2Pcaews03ijchFAxi6GcySJPYZlKEilI2Wt2h/41OjoYPoMSJ3Pie
Urp+AdiCro5KvrvP6NHm4Q+kh6MSYSMakcwKw3YMtkRNFOburYRMjBgpD8B+TL+rRjAzP0gfUJVD
QZQD4nQQEaBqpyePFfjczU3S1H4nbzdytNGTfHaabV9aIKgdciriXopPCvUhZ97Os0I9NwLykI/S
mBc1RKEeECah4lzmCJjXJU8XPAfT43d0ftCpZUt4/mqw2+j77SA0Zqyp6/rdNkYMRhc34cJWSMcq
OBwC9y7857p+E2I3IK6H+aMd80ERAQ+4jpLBubiUNISKAKQoOZrHPpjWjfhcOHS+w43iXpfI7tqn
HG1H/Bir3gIWa3SrDF2dJYBMoQOnQHAjJcFC02mlSuy1qq5RtpKDnY+BShkRnQovHL+3GXdvi6I3
ByBpS3gYWGK3lUeEpcfC4Wj8nyu9dNVtOha25TsirQgCXeQM/oDz2RB43iOkfFdiWowpEtCKHxqo
zU1vj39P0PyziOd3FjL768uMek3UfvL+ybuwTe3uMAwK31GjHSKUM3uzyyzml1HIFTPKnnCDZ4Y2
Is5fU4yJlc9ZThljfnWuTT7fjT2Xy9FeyEtFmUx7W91B18lexo27FGxA8Ur62XIYARJsFsDMyCc+
QDj8O0TBP2XXcmG7PCJ2/zapjYVXvMye/AXXcgb6ZeiLBJNbmJlz2L2iao3Pkpe0g4VMaZJiFRav
tKqRZLPzZnVXNdeFnqkLAiWOLZfXqZY4sxzMRZAxcVi5HNKryEqsKoqocWLWoqW5cVk53jAYpraF
UehaJzG+7J8U9O+LZO3SpfCTqCCN9DA0WKnbZrlA4HQKBS56i7de+piAsIyiJ4pH2E8+ukMct9+W
rVkGl1YKjCmj/2ognCsad0bA9dfhIhADBj47mnFegwyXCcwWLgReksGIEXqVkqSoovgpkffPTUnD
eX81RozeUzlWJEbl+URmf2HOxXPFyZvZYQp1K1bmll3uDuXKKNq2F8dQ5PmTyG2qJjilpTHkmfgK
zWFG8hJLOiuR9GkC47Awzrezzm/JHOxiOowdeBiWY+5xsK+/JkShhRJ5Mn5W41Rl0NJtLmNHVuD7
QuN0hAj/Nl1zdmgxrCCHkrP4FHtUBJtSqwEx/1nUlqyDTrVdan3ZhVIJE97eVKkfsOP3SA77ROMP
5oxm0iMn7wny9pmxei1+ijR7Fs9jFAbod+tDwCrhlqH4TRL+I7y/kHyrE8R/diLGlTjdC1Cl0pPe
TGnTSvJkisaAt/4AhQVqsBhDiI+FVVKH83NMuSFKMXABiSjV3fuaItWZ2S9qK4xxGm9yYOkHBIuJ
nCzaZpiAMDs0M8fgodc4ppQgyDhilN5Nys53i0CRwYM9HryzrR7lpXEHZlvjUGXe+yIhIAcQygGz
ZUygnsubG2Blz5bpAr/QJ/W+VcnigQij/ly7W18Pk9vw32LhOxy2rTo9lLpLJJC8ywjkllcPBn6z
FSKXcdlv+P0moo5PyODJbLkRacprNbhId9ppeQPs6VSrhZ1g42oTi4fRwAV4etUQjpV2c3RvhhNc
mqf2KK+gUZ5IcuDVbi/orDmHj7voU5n29JxSCRVLfxPBGpsHfhpV+dE09ywA3ibYv0leiYhMFRQy
5y/VbMgTPSc2/XRSxWn67I5E9iTZqSLUBwjiB9yMo1pJSrkv4OHrQUR/JKyhkQLK7ssZC1ACZcWi
oF1sev+CVIhf/UlUwOG8QiZNOfnoq7ujsJjYFWleIsJElEeph9hSJToGYQstSgciewPpU/cFrSS7
FasU+aBIBjDlciLT9zlN5hFpqJhq02ylicZc5L44a2HXuRv5e3Fw8F0RkSzSPHBoOOm/mGbqZikN
GZMNz7I/fcFCgh3grKN32riDnDKPyRkd36uqXBhHMs+ImfFEZIKC726xbeoegfDdrwdFSjelXj9o
BP2w0A+ALAd1kCClzhX/tp2WIvta9Hgcra/VML1dJIXYDx2MO/5ozDtiS+/JZrvX7Q/8juZhYeO/
1IYRo5reV8S/Ott9vVq0f1N12eACMAOxr13n/8mpxzAHNF+9WBAflRb8iXCpextrqknLgjQHf8R0
+zZ7wb1NZtObnDd8X6V8OBQlnw+PD6OSz/ejDMJf2MbjA+k+x2brEy7k9ildFOGNiXqxPKuCKJU+
5yHgu/sJ56Z5wEWjtRX/nlE0MEZc2dqRhuePNMz7gqBwxcUq9cYpBj5GFKE7uIuEA3xxqUKYFKxv
ChT4qRS+AUGpqUQTl0pGphECYwMieTOeSe/NXN6mRLQ3qHu8hTTtE+LulEYENl4OqOXm2ffeLIhe
SW38pm0KFymx6dgAg94vGKREWIxuczFJJG53Tz4kX4d9BDLaS8UaGpvAaYZo65nmRIhY7lysnDiJ
YXz+D9j/i3N2E7y9G7NPaYf9ByZrKksRF39xCmMSqg9Y31r13ixx7le0i3pPleinMvum8KzAJH5U
3U3nDqg2nEiGdJsLnwtI/ZApniecUEna/Jq4Wn8IEn1YQ27pEWMCzRp825pNTBVjQ6N61Zs1/BAj
50NK/3VE+T+QPdIzsHN2qBi2IYxgKt0o79eXEZcd+V/LTDKa9krHvmzDPRAeEe/IrCuyxDx6n9wU
MWt48NZcDMY9VpF23FrPeq5UySDTAVmOh/EyXgtmesHIq6/oKIT8eDItuxOKSNzDLTWckVih2nFt
TNVePKPkLOYwNPgI1y4ZGzFD21BMK7dBfaTlVnKX1xKeiw9Vm0R77sqjNEEaKWMRKXkhU6ojjypg
Hc/+OCKnhp3TNEZwJYuhk0sr3WnciHwY5zIifvuPrBF4n7MKAWF6s7gx6LzpzJ4kqQQxyOxHLDwy
BK6QGaFEBU0mJCD+/OH4n2Fdvz/+A5bz0ktN0ea3g8FWd9wUHU5lf8xF0hngHU9vbeOdmVixbW2C
In+vY5YJ8ZKiW07G6So0w+69+kJU1suFpKSXdhdO3W9hruSwU/yODu+O0MW+I4t+3DQrI6HPIBfK
5rgOrktIkTokMcWOr97cbtbdapNJQ/DjW2QELaSkJn+UugGTYA38GTM/jr451epGAP3JPPHmq7Ec
7ThHatkBEuOUBIjnpZIF5QR2OXnf5FBleSwZPHDyjkg1dkRAtC/rbsmgBVVKV6WVky6iMpvavePD
iDOPcBw+rwosgowbl8A5lOlQ9bYHQjyQzDGhn8+QRZeeo18KXRPlQDs8eU+VFWYAl+hMjycT/FzX
uNpj4v9Jc/+mZdLVk7g7yinzNDvJUe0VJ2LjFVhEfOLUZyOqADwGovd7PVaFhAcmQFppB64T/XGU
6IBV9eyuwLLyPRJoVRRaXhV2llIyycHkO8jO4wYMNGAdvX6zk1T2MAUQvo49eSxSSySWE+jfoQ06
Uu7otrOjbsWLWASmG2zVeF8ldjWtglKv6J27fNnpdD9b+vdfzpa87MzCv/9yZsEtieT0XIRHx7Ja
T2Xm339ZmfGyFeiiUgL5/E1dhyQdZouV8M28F1cNAkMzP/9m1DPO6Z7QfKgQ/LiT+5tP1SqVYG7u
za9JjJQmuIa/3e03B2Mk2Rgnv8ys4Sa0pfKRjCrYKfO0mPVlhDSTkPXBzNc9Mj45NVV4bDdOc10C
e32VGdf6Kolh1xI4SgAdpgT9G01W1hw3HjhyPpPS6g9Ual6o4KJ7pCqE3RMpQ2V5e/SNU/gH2Xeq
3oYMuWG2VWmZD6RCi3NB3GUSbAnNaAOJbAEgDJCWjuV2vSiZ9OFTDLtQsd1npx5k/D4m0L/nnAsu
4TOJDu7L3DN3Rbboj6Tf3pfCYsmDf3wsy8QRqsWqU3d1i9ADu/TUoR4lzFeTtF2RovFtESCCvCjv
ZFzhh+MdCoc+ciM/4rp6UWCwVGnQ0oAnIa3JPU8iHUMR4c7EVaKyIpjEpDI358n/x1QmS3YmExuQ
VRItE5w1n5r5+fQeiq3mVSPkQKbWYPw01sf28LDMVhVvJKZoOYf9uRUIUYji7EJxacnWIaTgMO3O
ziwkRWnG30g8lBhJNxJRaANXYjGgCmFYYYPaKI+d8v7K0sHpNJZA9xM2nEm9sDDWo8SNDzVtqyv9
fgrNzUcZf6PAM4nIiJP+KMHZ9yPThOdg24kzVxM/NnLuq6mTmkJ4u9lk2aGTMHzbhcVZl0sV3jS9
i35KESUfSB7wE8JTn5lORi6HorzLGMPmbKEpV5kVDR8JwatGQXjmgZr+r2a4t5WFG5asR5eIIgw/
YfncRISo9VYT+JxT+osSnke04KOR1QPuE348THJFebNQaPSDcOtNqb0R2f3zHhvZ99k5ShUT1OP5
jt2VLmUtgKJgP9lILSLeyWxshwTdY+0WuoTAn9mnNjY2coZZRY14nwUCJC4UBcHx7si/cTxiYmwO
3xo2AivD/YG4J0RAvsC9iOSgr5sBpKiCVrOR4B+m+YgAE7MYuhF1pOmdn03C0mqgWG4ZNWRckbSQ
nGVEdYyOKGNUEJgsv9gETr0uhnYi3jU53VEa0wqU4loBPZGFpwrbP57rNWuDYT847TYjCjlKc60m
fwjiSQ4xWb5IqKOFJiiYNxGcHu9MN+GI0cKXbB5mtH/b8vUT+Aah/tDjGqhWFJKhp3HRFEOWpiWh
ue5jmSvfqqFCmNnhohMHILFvefNVu1v3W8IbWrQYA9TGBB4nID9u33LXICk6v6mph70xOnwLw5DM
h0vTS4pUjYlKM2Ha9oypAY/rtjlJgvFIBTBr6YYfS241l6lVHuWcW0aujKNHtUTa0enJ0vOlTZzM
1VE6ZX5C7fW4EctkRMhjSM7TE4QtE41KikuOE7BE4jTiutjRyBN15zXzccC2Sko2UwYbtWNO48uv
mVMETFvgYChGoSJ+l7x5LB6ZZVbKqmYywsIzwiq8gnTlPeKd9ok1N4otpcsYiVzrfXJ43Gd/OlFZ
xFmXK5Z4i7ad6ayTGYnhEvxxuNvyLlp7+XHqatJDGCk9bizw0KKcor0jLa5Jl8j9wLseS94QYecI
xS0tLlu4zYnTEnGZ5qJAZTkd6H7B58ekvZCqjI3WsJ/VY0HMNRizV+ZWu0hpYvWG0kwDbtfSQgMm
qDtZlCtUNwkpZC4qKIqvFhbpjYzYq29veQVvbh4mmFvWHOKrrhbLcVbN3IdGo7FseUtg6C3OI3KX
mF1wb0Wh1uqGgbEhiq4xPcO0OjJxEuUp0ymY0sEaNVT5r4gdjc0XfoJ5o8wpBUGaRKACRMD2hIgx
p+5FiUgy5ykbjIzhj1QaudX1ej11RKp1VO10B9nipt9qBf3dwmBr2N4Ic/kR7J1z/6Mb9VBjMltn
sHPW6uPsmxxJdtXrB9v56M8Ol8BNBBYqy5tmn9fT1z8aatC1/1E+rooFeZqcZByllq55WWkX6SYR
OxFdJTQWLol75Jsgi2nIxgZQfUNhB5kxpALVdguxx/LKuZrwAScchMp7O0/z44Hm9Vs8b9zi+EGQ
XwLqKeNpCBNmIsuUa0WcTZQwTwdjnEbMb0xgho0Z86h2tpq4LMWRFBdG77IxLa5p3cNsPEF9ZQpr
Wk9FNa31+tlPlcvlZUcQp90vXyxLEnu4i2ZhY0oNb59HQo5AqxK12tw58pXDVTGIqkPclYeIT0SA
9kaw5W83cS9liQ45M57R1WaN4oPsOY1I7SxSyZa0U48SMi9oBb3jtcVQA7YcEyJiYkCxNAtSgHOy
XIzLpCaLpdJyiqjg5hO2Kli4TujnyftQZI2O5zKdoVyms5jLdI6qgcQlGkc957hHnyjjbNcV3+gH
/tXCDuxZfBV6GKvYiI2gEwCw0eyVPYMTRGr1KWKFAOKFkc1ua1vdZi1QeyJqC+gYymgny6LFboor
Q3f8xOMbOPr8jPEVVlKMyayJhUS1dwcqKpUcbIs5x0p8jvhREmpyzKzo15AngQkaFMjmctO6qMLW
+iC81fUo5+Ks2Kp6UOvKBDd0tIMt6HdzK5ol8A0FwG3dnaBuDdOAyYX62aVzxcgMG74589olt9xu
+IY7RuuNPx4VqqdROYPuogXa6pCRgEb0JhHfOGYjgFeezcLCggNIxwVJ7nJDoiUdhkZcuo3h7tjb
QtnygCzArc9WhDjS6OcSeKuorKR5dIuxC41zOK3KDDJJJqRFmhxEUcbWUKrleA+6qmJAlANIkFUa
1CKj2onGuiP9yunolk9IoZxbYg6ulxiYN1AHnpwgBRq3KUEljj3YsDQKgYwiUnaBtblR6Ca2TYn4
YjRCiG+5Dc1L7lPnX98a+p0BqsuujzyZ+L47MDRdR/agHWNQBSRG3wT6kjrOx2Yvg7NNeleupGA2
YiCdncD1Yf4N2XYFRoKnGIcjcva6NZOXj4A97LbbAWYBh1FwwK0ZjX1BnnxxNJ0lEIzzWpU5ZEoc
8Dcu/pMT7ulTim7M3NycObMZN2Y0MA+w9GSutIayN8I8PnKcSv/A2LnoPCpjfMi1ji1kPTas2P3F
UVsq9scsUBL7l/IOxC+zKFsYV78Ti4mC2mgaGueXdA9flERH4auEDaBc3omArh7jbtQL5DO41W3V
TbHFFNIWDLUBL9qYfhAEaWLbiOki68A6mcS55caX8xJ1IFw3h+i3WxREzUV5NMCLWnCJrM6Smx2P
1fsca3eQasT1bAQp0eOg1Wr2wma4PJLXcPJQFZuJiq1YsjKGJGOjx9E3XlBV4V1g3YBKoxIo5OTw
W1golbE8h9+pu2u7xNXWhlK5kqJU3kvQLERKAsQVM/I/9IHObo7BX0oMw/w3U097rWmlSNxmBXfp
KwMh7SX0Ma5KZmkW9srUu4tHcU1JNBYaYf6ndDEVDkuc26HKPgHCG+F25CqK7gBYwP4jyuMRuSxo
USNHZHy6Qzabd7yZpZLXu2ZWlXBrtuXEeZaWFtsPe4i2SPJT+ajxQ36U92a9aW8ut5weYponCC4H
tVzylo+rg3aVgtU7TVc364VEI1ne1sNUFqLbmqJyldCeoG4tJXSiVK4mOyA6rejq1Up6F4autLDh
A5YTpnjPZEwTN92+y7hy4iGFMKeDsTDuptwaQ8k1M8sM6Zy1uULJhRhnsTi3vZOfYduUS3/l4BTL
xvi2jkpHuXPWym31QAJiWpyX6oD4OJMitFjRIHefmjysQ6ENQAnCUz5dRIpVt3N3OZ6Y455SIrOZ
sFXzi3Hu0igzuDjJMI/A0kmvP8PAoI++98TeE0888TwQAmgPHfe6HawAIIiPltHlum6/lQiQsBPC
NBChXF5Ls1ogY1MutmxDyU1Qs6DrGSN2WT8InoUIJJfTgOsgmbzlWLGc+HWcpzLh88Uyap0XZ/lC
WvYoxwVlWS7OmscnlqC+jTUstIGLo4XINTNeLM5pKnqtuV9DDjYvnvn9bpvKC5rORWELi+Vy05zZ
Sz0IqToIS9O6y/6spuLWSlwaKGY2xs3OOrhZFEg1tSz81ANzFmgkDCymzy4Lu1SqB5t5VAht0H8X
vPm5p+m3RWtNvAuWqmHRBhhuVQx2g41+d8dxNuaU/bq59FJs6fNzTnl/IXZy0SklaPMZ8sfU5Fub
PZOuSYjPbyY+v5pT2o9dudrAt/AjWyp1d7lFU9mHlFX+v9D5xRgnC1qVJnAR/zdySxYd0tTceOvG
9dj3lLUEruA5zUA7o2u/+A8VpDKb4G1TnjVZLofsZc6uZ5xMVOwu1ih+LMwEacfCvK77VOgIo/KV
LoXhYlALGr61LPlQndcS/s8hBTrFUNcipPFRY+4o5scIDTLDgZw7bRkbFmJAs1RyA41jUoUQ6CsQ
V8xHaVcrjpucGHXgURtt0vsVy3Z0VLFAAqec5Lug55AQGidRf8wk9nQk4ynFRP3QubxgXcpIIoh9
ybn8GxyeBw5YItWd7kERX6Bip1JdzSbxfHFZ2Bdtg6ZudhPCSTTteqleqmnQTstwrHgMtV1CdeHi
TCiTeaJPdeK+VBvNfjgQ9WKumxMupXwmc0o43wmPWSOZxBhgJvdMcGllHdIqNq5yzcaG+/oC/C8F
KKKZxk29dj7iv8uWctrTQhekzKY6FnfQTHG2krL1TncY54a6tFpM7Qb+hoPWCdw50cDRXsRXXs6l
AZDhu3zduI3d4YCPNubawy8K3UaDiy/Mus+Xy60xlxe0gtpgZYoixchvSF++Ztobq5cQ7u7A1Utj
xp+vBWP2suP3285OGkG55Lw+jk6AOAXOTsr12sbimJ0AIXD1IclqIlqMu4ZormeE1Jw4Md6fVF1r
fEIlpseNc1zzMeIpAhJcQ7jE/JlYl4suTrogWbYxx8KyTeNM3RFK4R5CR+WkoJKj6oeJvOvaKqvi
1/J6/FTQasBZyVf6ednJR+VihJVTibYxqRXZ7fxcsYxaJK5bYs68uLTk3keN87XKTDsGQSI/W6zA
GPOOMbAQiVs2XoxGeWq4WUseYQaXgf5ec3PO/mdSZG957t12UGj4b42zaTO0njnctPnKJAvSB6Tz
tKTnmPLN6ndWZyl9pNIdf9vlxhJ3hYrbh+ICTUmfXw/rcUR6AoDEFgyp/iC3EWOL1N1zCFClWfeG
xPaDowbCODIiHp797wx+3/zMqXObNc2o7K0055ZM4lmI4uoKW8ZKEZbjwnaSzdW1jiRnWUHkJUus
8y3MRZpShW7b5mFwBxO4Ycv3krEO0hT+UrHyFTfPj4x7gc2G0mIaPVcfkx5PKvJkrT29/xw8L2Ot
PQTnnDftudooe1fF7R6dXpldvN7w+5JWse+mrSnULbbGrkT5+1VPNk5O2M7THlyeZo2ujmksTiwS
bp6f32eFU6Lv/wzqKBUXNht56BumjdlYII+SsvQIlkVhzCCuuLIcJ73pME2T1X2diE9stsjdSZqc
jS8MI1HB0FFwA9NOZLYwQvBM64xQ5M0mtXZgfM3DWF3jBYdjGJ+2A8WVFh0mmBn3+NjKGt7B2kzm
lzYz5x5LWHzy1uN2QLqfEThPJ0Suz+3W7sa9Pjswp6tWFtNVK0aPWFWc5Bdd7WioqvjRYhIEiA6k
75DejS7d8xMdjkLAKUGjjxOnpLaYxHQM50yH/nth3sG6OtlK3geD0hm2DiVTl5RCzrgpxFL14fYn
ODU9BC5HjJITBu1Hwc02El60ps2Jg50EbFyEOiKlsz4aJ5hIVx65nByS8CL22fKHndpWGg6n+K0o
aD0l5FeEc42jcpYOloYKb86AbR4yrnVmDWKjZKmsEuyttEi2HIrti/sZ2S2N3YhFsmrmQYqeyEeg
x/42DHzf8ZYAAMvzmrlwewfeIgWTIZ4Gwdd9T+QTNsBbSm7T/25EeGKlXF4oNZYtXs1ecbUq42Ud
CqgNoz0KWYTbXGBoepksTZfnHecwMrzU4Tqh8xATwNeMAV8zSXxGIiClshXainwpOY2hWawYmsXS
orG8SlIsDot8SJfyMyDvVWbj8UQuCoV2phQi7QhsXIwCG8WCC8E2msotVjKChUmVrKiueBrNTcup
ZlItHJPNpLkxZsT3l39FfzNFWMzYO/cXggRrTo+6yGI1rsGsedV93e1Kd/5J6sWRZ+H6BPrJio3d
jIVa8urjEkcc4YHYpR0gaM1nEtkEPyWtk6eHjEZRF+MQU/1NyFxXa8ffDU2ijWvJiyNnhxArItqF
xpL9fo1+UmRZrR1mvO7ao44bHir7SUM3tE8TIErXDNLwHo0/gYk5hd67EgFEDvKyLETJyRikEf3o
Nk+MlCs2Th7HNqcxtoaOx0lxDHCUiOShCGJFj/Jl/J3IMI2hnxuJYUfK/HOGzF8ZEfs/CTE3fT3H
5gxT4VgX8W3u3hTv7beT6BNstPj4kL+E8tNmZgdrr2juIzCK6ogDFcwywTLtvoWJHd+7vIFGwJ1L
0kxSWWgitz6qNPmk+SHZ/qtley8DONGke+jCFyx9I7gbMYDOC580rtL3ywKWwgomjqHlaxbxBJ9Y
zWaARTHhhiGD3htDfRIlgKn5A7/V3SzARRnYamsTpS3IGAHysTFya6Sqr5P2xDBhxfwbrekNul3B
Q1iBWKXEpkXYvZ69JMOzyRKvWC9ve5KL/UvQh1hjS3cWw29Pp09xq4XO5WPARn4euPyleT1fEX2k
ashaqG+2MRM0zKaIRVVwgNzVmXl7GjxZqWqySm5VEpr7RZr1RjcGmDqarG0FtauAwgpoxNENN05z
VgVtgLDqxZKxakcf5NFw3QVSUdmm8SFTDMQ6twLSoWSH50pxvtGX/kOzwuPZ7CDZOrgkrYOJfvKV
igPllZTbSEqe0zFMJaj+TO5osTR2R/OOjrSoniWVInvMTbW30/yMA0lM3yDqiL+HHZoulFNnpAVP
Gd5mKhENa1DjRndbUElw5nLqOfdGuiS4mjncCkQI9UN7PlmUS4yauFnzM9rx6arZUTpXUkl4095M
LpqtsHsYvvGzo2bgzLHucNDXvfArVMbOGIgvHUfEyaYhKejIWJ5frDCeMXTpCdlpYiEfUVlYucPI
m5U0n85ods9yjEBEcByvYiYcq4ysPjhGnf0hStYdFTI/kpULYqUNVYp0yrZ7j1JPU2rpQoFZ0bYo
94lFEO9ROkVRQObkQ5lGmpN9c/FmMxxN2wwjtCvioxjriqHy+p95b272acHpo1pUpusL/cGwj5pl
NNrP5UjR0AdmCP+uzOXihyHRbtzk4nYEKWOExOxiYsSSExPPJoybFOwQi/tKD1XQ1b6Ca4jxIS5/
jLn4GLGggHjypGTvI7ubDWtf5128EOyZKyHArGtyTr98zWHbDtuzvPTdYeqzSwajisKw/H8+B+o6
IXeE02M/fc+c3isl97xdHuBzugf4nKGojokq8VSISyXHUJHXPAtsxQWvLLdJy6ZiWgPdJ5/mu67j
uRkjan7GjitLdDmnDjdRhGZDtMsFXetnpDcUMnz5xeIixiEm3+p51602IDTJw2vGKQAUEqDe7HO0
bxpGKORp7nOJc3eOs2Du9vjeY7ie/BKOuDQZDlw0RkxmepM3bHSvkzh9PdwIY0drlRdVuBb8zAde
6Wn5R7kB8pH6a8H3ZqO/6vPA6ai/gkWZAtaeguRFzQyNsYnuNHtBoQ97sGt959Slp31ouaLIcWed
F9fN6ZKpyA5HHo+LTetf+f9GDr9ucmSRDZs3S/CBsNn0WOIbm1bH+klz0UjwC2RoHukKZG/+CL57
ofK06xKOJWVVZNBLSQa8aB0l6g0W7U0eSxdgw0iKLB8FrkfqF/4yUU6YmTekOhcKWpKixl5K7C8q
zXDNSgVHT8LdNqxIC+eIjJvPZiKZX3y7ija9tbF7+I/3fj5JH971uIAQ9xO/nmS7izUd0ywXc0wy
ErTxFWQ+18gD6/xA8yRn7N0FZn4SH3LZq4xr16YytnVGRQ+aisBRIyj8Z+QXTnR4imVIGpVj2HY7
KMdz68zFbfujE18nLGRkvrcdvznGvm9Y6gzaAkf51hF+ZoslXXcrIcPlgiPtX4uGrc70nyhXSpNY
64ip11CK4akj0lkY8adl07xHGf+WYoYoitoyONl0X0oKuDadXSkL3Iwsbj9rKASibYJle8WZudAL
/DDAPdly7KUo2D1hKHDFcNGt6AnupE2WLH3OlIZG0sNYsLnA6FeD3UbfbwPmMhcEKBzIvA6cpSQj
YpnDHvY8olV6kmZ3+1IuXXWp9IQGJCYmdDENxjFBPjFHlz2FHvAZQT8EPg2uWlAvtLvShQD/jk8o
rhwiEvdX3/58kz/F6X4Qdof9WhBOk3g3jeJXcaMFQkWxt9V7HGOQzDE7+1csRpSsf2dKs7Pzf1We
qyxU5isz8H9/BVLifGn+r7zS4xh81M8QvW4876/6wH6mtRv1/j/pz/NA64JOPcxmAKsCVxsW/V4v
kwNOUwjf2Yy4+fCQrjsARaTFncZasftUe1lUlBZ1VqhcGNWduW3qaw+87Fd/sj/SylqKWvFf3c2p
Wn3eKVJphd6K98bZXu+NV7r1oBW+8RKwgpfxRbX61jDo72ZzhdPhVneng79sBoOsKNzzPKxPTvr5
ZsPLiu4Kp5vhq93B+XZvAN/y6p6TuUxrLT8MV6aQ3VROUJEfGrN83sYmezhPeSrm0GvWV6aIP53y
KOKo5W8ErZWp4/9hlEp8+cIPLl+YOq1W+Dxy135tS03O80Ox7JxqFNt++bN6qgZbkvdOkYEJ/mUd
Ffwy2Gr262uwcavcWeG0aCn+InljXX2mP1R9GE9Zrbi27JiDphGGr0jKhX+JihozMNuJh7K5/JOG
WeePzcH045Q/z6H8asV9rkxdv+7Rxnh7e1N88k96p1rdbq9wmlacEx4u1CW8prDTlSlTg1r1uBuM
/Id+TscWTmNr4MJS51TUG5kIuJtoB66CzPwivsjmoNtlTxkWjIbt7kazFUTtHMPTFK7xutFwURWL
5y6mvGqr6yOjB0+0pXtnvEwA8ms/44Ek1/J/tJuBpg1MotrrN7Ea7278gy1gG6g9SkrQftqxGdOw
G2PsESutpGeKMO+Vpk6P+z0pIDCRVZTXKrqmyP+xLgOOLWJgiG9h5p4qu03RXaXudEhxbzEjDh14
nM1osltlY650caZO4xi8lXt7z5GSKNZIWGC4Ld8/bDyNrU8nfcNgyt/Qddc+md4qJywHEGiQsoLK
X84KKkkrwDvrvg49YxAN3/AYuukKR+ol3Cob6AhHTZnLELYp7phAaGVlxcuAwJnBK3P8W6pn9mOq
m34TSMADqsOGhd5EiXmqBnfyLv6X7tbx/+Lqw8dHaADFem77lHXzHleHz2h7s0GDsjoRn24kXR+1
mudp1tnVjGHBiu4Lav0yeS8DgmVhJuOtnBZrelKuCd7Z6Y+0Zmrpazn3JOjg6CaZ7ZMvE03d97ZA
uCCEDkLaIACehJ2UiiQxw6xWM0hzaSrYYSG82uzALAjzi5NCBaoyZekWnanTl1676F3+/oVXPT5a
ottMGkSg8On/+MkvFUT6yWt7yNm2QRaqDftBoQeiFP4y7tSNPyLLFK/olbOvXjj3+mvnvWnv0vkX
+NdHXWA63ph8+QQHyYudOn38e1WB/IAK0EK34aA/6FIACMMRfp62sp+Pt7JkfJJAlOKPrUfYp2Ds
1LPnMMuAWKpFwCLdpsU7/hxQxSeYdVdW3D50sJA04LhsJM1EqAQxfgK2nf6Y8uKpUDR+RHFTND2h
RAs1Iupsp4LusYXFU+AZEd5r+ABZGfoohQ9zsIcpbJGgOui2dJrhZr3n1yUZh83nGjJ5rwJ4rQSQ
efnKa+uXzr6w/vL5F6/kGNPSx8+xNQg7aW+s6/CnLVh8wC0lyAXt09q8B/7GOh0qNYV3DqDigzgd
43ljgDQNkMTNYDAWW04rgQdA2SnQWCDXHhQqs+LQ+8F24Lf4d8N9LpqLThIV5RPgeUhUiykaFiH9
2cmtk59Flaitq6H3BJOYtYiQRyo9TJGt+DeRHwyVnoV568Sxt9Ma12IYjmGGv0VvIM5QfXITfYEe
RIWvn9von6Zqop9y7VWuoL2PyKZI7EfETsTTjMDU51hnVmjXmbdsA86rw5h/ZKKe14Yjio+PsUS3
lI9jNbaLVGAWBMU7WNb+JkjED04+5Dqob2PxceAebuUNtsBDnymuKH775H3onHkLfICVvJHhKCKb
48JY2jkYaTOmAHvjLUS1aM/CR/8TBgIWhsrNUm3W22I2tx03McJJq5mNIBww2Tv+mFgi2APy8rrL
mgDkLzrBjmjya+pZuHnhK7iK4tXP8TttbPh0jXAd8xVAWGiubr4iBfHpW2AJkHFUFvEwNDE3NqPr
H9151+1OuuF8tc1rk3yLtGwkAJXlkn1FnIs2vuWomChthLEnFGRiQAFeKvSq+5wvC0PgATCz78P/
490/tI5oypNGGyDK/829F/qKTB9eRk+FcOBvbqLwps2NrcyAXInDWJkqTcVneocpKLkbTgCzp8Qk
mJI2CbTEowTgulbQTeVeVfwF4rT4DQRtbe5I76N3qJO61O82AMoAvfgtkPrPCGmiGk2mcBq/OiPo
7BlokcnJXjf8+qbRIT3QiKowMiFYNgmwHWL8mND4EHCF4UjG2VF8UipciWLPCFMfuqCKOTwbmOyb
0tMHDVDnJ7U/Etp24dKIYOK2icp/7p38Iw0p5TUFSwf84EjUPQBUbMJW0ZAuLaK3yERPEjjYcxAu
R7HPMV4Z/r8ATP9VQeaMG3mbkOwH5P1KhcbvYiHtcXllbQs1DkPTo6KHh0OJSh9MzHdEMAAIYmN3
ZUr5sNmYLInOkz5H++r4D6hbFpxyMa5K0I8jclbBg7Hwxz/Rzh0QenMz3hbSgN4YXwAX40YTqMfN
nmLT2Qo3LJwWBRVewaf6ZhqTlumOtIkjohGbKf9MFnRSL6yM7NZ6o4wTDCt+2Ov2hr2VKZgfQGRM
DIjC6UkY4EU16xH5lBv6G1minsH002h/Tz4UqIm+9VsgJAA5pS3PJHP6am3XWAfa8wdbgAPFBNr+
ZrCOjwBHQo/qBfwOeLH5owCmrkeRlEscBlApYf75BfivsT8UduhEm8ZUdC1RlKRkynnt/r+fiWuX
fGqJnIO2dj2YsIprFEtNnexzfJj6VClDQHSdko5UgQhnFHBeYJFVIvbxiM2zbibPQBtR3JT0Xqin
UdCupfPQrxCl9zBg9pekk3PD7NTpf//l6BNKWp0WVKtNYRRTI2v2IO+PXPIdQE4PHuXyyB8NjzF+
QkxGTg7parq0JQpjhFoc50hIljB+xcWDvEhjoKR1VPZQGMh1dEYbdgZimin2kcSVEhWjpa1mEEJA
iCB+nlIbjNBKjrNszpQw5V0r4MqBqKPDyi653ZCEkId/mnBbxJ/epAtQ43IuhmsFYh3ojymsIoi2
n5UpdK+YopFD9hv1cEvRN0lKOTDhAPbRxpfMa1wrdDuAUYchnJWYPpAsmrB8CU/1d4TV+BWFI69M
8TLVK/2s4wB7/DsS1w+Pj7xJYde5Oezf4IX9mr5Ycey4TqluZSQhNEVoFV/faQ62sqTvwcqhffsr
kEmLO8FGO0NsOu37NP2NMiD/2e7NPsL8n5umXh7y41G4D08Mzwmt5itTT8oDfOYZ70k+MHGItVaz
dhVoCUBXWKQJFbFtNofBwrWtLEgpIBdd94xT9vZy1qW2VbnEItM5j0BbyfyqIpzjY1/nTvX0bSGY
jfYl2gqgCv7V08e/RgnkFiBc4qeZwUYlzU1RIA2Qk+C830ENjlpl0VCOJ4OhnArg/T5eV+BkLYZJ
4Xtm0xMMWKkrTrYUJf2M1v07BzJZMmO53yQ/Fl9Nor7fuYzx9ytJeH6EbolQmcTutFcejz7FGFGR
q0XndkT2Fr6tzW1E9N0WHMrU6bI37SK0o9hVYwKj0BDrB2QmE21eDp1TTMX0BYd2ArOQjkTG0D09
hgVo6g2VfMXCgaYuY9zpu5UcST9jAvdzWzMOVpg59oj9T2ThHZRbl7jZVL01MwZD/JCiC32bfoPw
NYo3CRaiaSFNP4JCNtH0cv16oeAR5v4c4PX2yftV7/gT0sAcoU6LQ573j+8Li8k9lC+IMt7K03ui
H/uy7iZ6xh0m+tQVCnvCG7fZqbWG9SALKBaWBkyajNtDpz41rRfP/o3+DbHB/luPoszxZNgeAw79
oql3kjUvFPCDRp1yxWttVmVWhLCwWm7018uYq2Itwe1qtN+GDB1ubxTmUJGH5UvJI+MmAyrJULdp
199Pc55INi39HLr62KNqp5+R6yIFqpNR6Q4bwoThhi4+GcMcqig1UE/TD0rL0rWQ1ZHyX1JLehh4
hMFX6PZ0DdWUiXeAYFAsUvha3jY3AuZ5JwI4hKi8hxAr9JzA7twUiBeb3Fb2pgTPFrRLAYfEBrDj
g2LC3XOwLeIMYs/5Lv3ueB+GEnwQKYLh6kROowd5GeavitWSXQ2bfonx/rARcol0GAAqXL39dNW9
ebD+A5A0/9rf9i+TzQ8zFdxnqKFp0LlLUx5t0j7cdjTP3cc8BPjnJ9SaWnF9XG3KZAH7IGHsO4gi
eLInH7FumRaPqzl5B1bzc9iGm8RR3xLzQs7zHpnS+APOhnCTjwG+/Iwq9T5AKyJCacLArs3TMrt6
qF8m7ve+d/JTAOibhNTu5Qks7ggXBeHepO6XnuNBbVkxQkIGFGg3GH5F0/muJ34h+VUWnlf1lZBL
ZnQDiCfBYSxSa2BwcWTOSVbS8lwYROR8VKAdqS283i4gFod3oEdqUyYHoxSFIlZODoA2AY5iKoig
KGENFx6MToO412qGgwJJ+mPQW50lpPF4AKycQ/uLXc6NKbIanRF+WsUImTXDhKJ7XzS9Z71yqs/F
+KxlbOyw7alATp1DeWsYhMrhb7wBxm7X1GFDBDvSZFqb+ia4BNjnppuj2BsBHg6kaLTTrkyUQC26
E+LBGKvRKdCsoECVay2v11L2sRGESOMLOY1bipNltM5RbJzA1InOoEki12g3LTcPh/+I5/8vDkqK
x/+g/LfT7AfTbw2btauken/EcKD0+J9KuTQza8b/VEpz8+Vv43++iR8VTzIMAw+Day4H/e0mQMMb
50B+EH8smy2GPYxrfoPMlAmvgA7tLjPGOrWNeK8zQDOnkPDOFE6Lh6Ggl3+7FfRBZmnWgSDIDy7U
c+jboH1UDxr+sDX4Ab+X8T2nKOAiNPsnJTb0Puw0AfVnWeGUw3FbQAmy1DNIGejhKPvBYKZfuxJP
ERt3jz1HfiytLp8Qm/UxMVmk4vuSPLh+Qr6ZwBLtE6P1KfNf94nvwg4oiRVzlWbSq6K2ltfQ0EHr
oZWJPSJfENr0apVMIVn7feE0a+2rXmfYaondqfmds3VUykb7ihpe+RfuVbiOvtzbgf4ckPc2IFxU
mWRR21QS3amS7FGHMK2s3+tlNYipVomIwIyAk8FA8Yv9V2FK2dwZsuS01ZwVEm50PDZA5BRTBsvh
81wXA62DpIj2mWiOTVrLk0oxEa5vNhsD6hSHegvmCQddwi2BBcjQoSci4fH5QdAKEGazmY1ufVez
+RBFZcONSVOkXvgUYskiWcvtBoO+3yuibDwodrqcniC1PSmW7YfdTvVqsFvv7nSKQVjze0Fxp9mp
RyOTjTKbszrDV6ywLwIz11iZYtOm2chysGLFD4eymQ3ZQifM/eYr4mboS8HMOF5LrdfHKKmg5l/E
PLDkKc0Bus/Mvu3RTJx1oseVOKQRB2IcjKwrSsw0Tl0+8epDtjAWVmcWS+1wjQLSC6u14UazBtz2
j5pBP1sqVvKl4mIe/y3n1sYeqEDJ3cVwlNNkF5h2EexdKHFl0MJqqbi0OEGnAM9mlyXVZbkkO4Xf
Enp0gcEUQxBA3spUxAEonzbk7SJftRRTvltpGkGnBEw3LIp3aQb4T1JhyrTIj9QTmaOLCD7NaosP
MKWADCTU8HQucq2jhzLjPu1UhM0pBnGEOKi7HTJNQymVfh0RIaNfA856g3hSs56jD0FyHKTAQGPJ
qzSeadThdZ82XGzEsySbzkKpjCaduUUqPkPZKh5V1TuO3UU7vBDdJ9GqMYYtRQKzVFmy94kwV5gQ
rhtMxUl4Wf7l2QgmtMELXhkOJuc97Xwbc52yLSAHyKAIrmTq9H+8/cV4VoKEFbH9YoIVlceeuGn5
sKd9NOa0dcWxmPNga9jesENmhHfM8adIU2Ag4sqSyYwTVh72Ntp7PHo7XRf1WgE9f6qm23n63U53
o5Wsq9unZur0c3ilLScJ7WaLI8XLPeWpoGIMHZ6awPo+rnF0jLueHq01IcrHgOIUmDA8d+mDq3CA
QV/4Jpw2yKJwzuZcXsB+ZnXv7nX4HZ1FLr12kbxE2FSmKb5GaUK2KuZoPAyZ2hKMCvRZz5y+KFk4
ZfYFBKQ/WHfEx45CqLKDSKxzW5LG29vaVhdkiHEVmaePfwPMwP918jEGGY2pstO1ZWbgEao+K5N7
ysV2gJBFlzZxAre5kUwTB+VJCRgPj8cQzFjOYN+2C1JQijVM4AjTFisN6Mg9qL6U6HjjRvRUlxyf
G9dPwRhLxilz5xwqGomvJANGy1nLOYJzElsnxelMNEl9P/nf9W3MURigGVy9iZIvvoxImajiBB4n
k/goPGank/HQ7wTosgEnGY7YZD1S7Pi/ReFtxvGfURvOge+43+ryiSwimeN/O/kxmiYxiD6jx+wU
TgeNBlwgDhbjsCzuvDUcw72Vsd2YjWkjehqBiKLA9Ph96k3F74/hyjWGc8c40JBGJ+l97Aw3hmkk
kr6J4mRJBVit9ocbWe3oqEytESg7mjSQCYPzMGJAFf0p8VrTvO1nTN90qaviNkpzpfnYudI8WiiU
XrnGGIPMUF5Q6i0cbrSbwDr59fqVLirKpCCpZnUa1VRGQpqH8U7iccQSBJdW9AeDPmpxGH+LlwO/
vxlYM9JRPOsNc97zw04L8CrmbBBPogMZdhSqpzlz0/FRKUVRsn4Sk2X8Qlm8D4WfIwfPvg1M7OHx
/ZNbnCHj12xPvu2xxwE0/iny9xlKA7DhMrP9/+69j9drvP0c349/Gs93Mm4jMRMlXzH5eErbZAUh
FsAgZbUyGKU51D8QO8V+qbdRMa3JQ56TpxzXBJwcUMv1fgYBBg0FtX7QDjqSe7Fm+Ef0XBC1GcTx
H8UnDbLiexM4wvHsdBSC4qnL0dHCEOODy1hrb3ZGrP022RwIklPW/uwkwPmoRH5yAiFstKPIPIJx
o4lRQhrfzJzeOqCrfkBJy8MRYgNNQRpun9uaQTHgS/JGoZofxwfkexjJPVQwgOoFIKPILiOm9BOf
ghB/LANx4sLGIMw6i/P7yOXKYlIovzNrevMiidAhXw/2ITs+sD7wez2QD0iWt1kbtNRPuJMms0K7
yMmZ0J2V/Dz/O1xU6de2j0YvdH+6RQ5DmH3go8jBJ9L7HH+BljWPMDkrbm8Bf8YGM674cgsOcJ8g
n3zQipnJ9/9RuR7Gsf0ATgCua512dDJp1vKV5JthdWirxji++BC95vTw4XseKfuOiBHGg8ddPDi+
Nw7rgRdi0n7H9NYd3xM+ko+tDSAYpYRtQf2xisdb3R2SikXfkVicFn96RJHkDLxHJ7fihFH1NiFh
VBshoEr0AlwwuvRcQBs1wJNL8ZbQdrQqjqI0DL8oCYHcIWVM3uq26ugd5PRIIuFkwvAIOh0r3U60
BlshxsovLb3OxmnnLpNI5BYp9P2pBS+C3Acb6UjA859F3B6J4B5LfiqrE60FvpGm8CfE8z+3b8q3
P1//T3EamKJBOP1igLXKgulz3WG/GfTPduovNYEBugIvHzkNdLr/12x5pjxj5X9eKC1UvvX/+iZ+
njtDDjEdTGGPvLGHJx6+IcBh+YknpGvXiyDmowDzxiV0rHrjcjAYANUJl1UDkZUZNQmxhxfRS9v9
9MVhC2UB7Po8lut2t7owCNqxN5f83bbri0vMFQtRy/W63X1t2Apib8SiYs9fD/XZuz3kYm9fCFDA
7O9eJo094HZHG23xyR2JZcYbCGc7rZP4S+GJh49fAJkUPbyh5wC2FOdPiWX4T25zodUatpsdIK1v
vIjlEIhbewNhAvbljdeCRj8It2RHsU9eGgx6b5xroej0xosgTXX7u6ltXgvILTvWRq3Mr/l12AH8
ZmSjV0D0TGx0eRBfoFwV/vsaQD+wpWJJLwsX2DfkL/yYrwb+9xyt/onp73znCe873vGvQPR5++QD
TtdKwVy/Z7FOOgYe3z35MC9zvd4UZlkufMmRN2+zryBw5phajeMoUP4Xz74E/vw+Z4TluJw7lKTu
kEY/5GixdzmaTAqIIn5HPLwvfBG1nigS5QEnVxIRLWwtjgJAyIXxSAXxfGf6CWIpPQeR8EQeeU9u
zxPXlWdoDGzoDTBu23AMWFAIPiczKLCF61cufv/8q96Kl4HbEzYxtSkSqMKgezXoFErlyszs3PzC
4lLB36jVg8bmVvOHV1vtTreXsboFNhBj2U+hEuYKfqzedwdwH4O61xh2WFIDhvv1XjZX9ba7zTo1
imq2cpmbalW0iVKRY6ZiEAwxQcC5PlygDgXbOVqEcL2yxlWTzpFRU4F4qtXecJDNwPzXO93Beg+T
R2TyFE2f2Lgu0Mw6ln0ZtujCrgcdUuOO/W2339xsdtb9eh1NaJhqzwHBH+RRQked1E8QSP6HgLT7
mFWxnBk9SA8ktGFvnfJswBdjfMAlatbbbWw/1ge1q/4mfsJFadY34cOlkvahwv3V6g67HIvpZABE
g/Vwq9nrwffoKTzs1bF062pmsIXQCxIT6VzKgnsprWndAgw3mpvZVQbaot9rYk9FAlrOWBgBMQ5m
NhN6J4BMjHDqc0bl1QzA91VsvAuwc62ZWcsbrLzoY7fbvQqw5Bdhhr31Js8xA9ckGiZqggrYwfrV
YDea0zo/zDg7l1dQWwj6lFar5m3Nx9o3e2IRa/ouIYauVhv+1cBxTfAxEFTEK4636hYDZgBcjg7Q
nTrIfDMVrS3SY7hbIJXxualJ253ghH1YO218c7C77nNWyk53J5tbS7jksIvNICtOOhNyhr5ijR5n
cnlPPBHFJy7UQR7VOoqc4E3uRMDhS36Yzbw17A58ODdyhX6L/aDfgtEZUEFQ7tZQ5sWTPQ3/mcvl
hAPfxf6LsLVZx3gaGNebwCHgDMN1uCzNRjNgYEEMAT2qG4PPKnMl3KOgsznYwuuHz+bL2Aq99+ST
2TlKB07fiUflxXL6/hk+4ucuXvz+hfP52PmYGiGQ8bvhIKuy4fUHRUAMuOWrmcgx3DASo9oH5vbW
gCG9IuckysER3cYfLEKNulWLLv4fVImR9fimIIcxbHh8UMUXX/1ptvLVXUpRCB18htYryrp3/DmH
BiA5XSov5ueWvP94BwM0vSUk7VQIJK/mcNtTmmCZGV6Uu8bvy3Pw/Yz8foa+P/nIy8pY10+g+V2R
uTaKPLjn0RJgorKQilrz80DU/Da6aPf93eeYUOb5r+slLDjW9QeAcKseEPe90xiUgDqWMP1z/hdb
15qDZhB62p6evG9lwT1QgaNRnRiMtGD2w7HZx/fE6NMGkVckXMceNC85aSw8kpmtMDaCkyjOLQFJ
WAPgmOUE96uwvcW5mbw3Qw9n7YdrYmfUuqDDNTevcKrf3cH31H697feyeJMlI9Ksw1fcFaF3CbIE
qRL3f1/iZoH04RVZrPq750AU4VevvW5ja3T4FunUaY6r0OkaKdpcZBw77QebsG0ima6jDTGpnJ2C
0zpT+l3BHiR+ldfO3MsUcWnmRJW1+GJPeB7y/YTOJcsiUZLcd9rHDGzitt/KyGdAwsKsOOGcjmdR
UgBmbQe+6QQ7nhBCYg3QcRdFqgF0JoQQg3XjVkSpVs0VfGcaA23DaYrMDb9D01VQmBV9oSqTfskR
/5slwMhpkCJ/YB7A3lAOpcx09kz1H24s55r1lewb9Wdz07BaATw5Uh5Tj6sZTk6cWRN5XwF7Uge5
5bhRAwj7sN8Rq+kLEQdIAU6HZs67KWKi+A/unqecj/WIPwjU4kpAIwDqjW6XTE1PiqmQLxi8Ihjn
jGr8YhWIA+bMagdA5/gWDIDkcVJnkWKGtspgG/BnL28fg2R3u/0xz0BAi+MUsHwS1T+q+7vhGseR
YdtVtf3R7tO5XxwCfc+srU2w5ZJHvdK9hD3w6lfT7v0ASFmjIfioVedJiDYX+NYsqI9e9dsCV6AX
MKZ44Ozn2K2cyLmuSMUtFp9pBEF4gZOHaIyBe1xm5a8A6yzmV17TutaeV/A57dmFuniUhT8SdzRh
PIxjwcoWL8ABvdIUTB2eVt5651/T3sX6WsOfkZDVY30LTzd2kJLFrrEMvC5a49aGA38wFOixh5p2
ECTik8gQC9lvR+hv1Xi0PuyLLOdbMHZYnZ4OrvntXisoIr+Os4N9ykX9WrxNjDQyYhUiO26+AG+A
c2DzSiU3LTMlLFjZdr/b67bW5bLHlDDjH9LYGOXJrmDjfwgSMQlqwhoumCy0Zd6k5P0PBF8kFSif
E3XCbHP7xcyIPULOO5BIrU56IToL2BtdO2TtkbjrD8vkMl/71yHIDIK3lReoSPMh3K5NxnvWs4hR
JPtLBiCR4KumD0H5xwY1cVR/o+8mJmfri+p9xDiNvaF8KFoHsH5N1ke0sQ6UZKvLNzKmb61WL185
+4PXLl66+PL6uYuvv3bh/Gv6RnAH8jpnRq2NmvPKDI5uwuVMuIK/P/vqC+f/bv3SxQuvXkmcPHJ7
o05GFC1/9FN5SDBX4puYCBemRxAXXF7Q34wxW52Ijv0LWeMPTz5CYO5tdWX5pGcXlqRCBpOJZII2
cJj8qo2y4PM68mR2GlYoCHCmbHPS3/SNMgd9BGg2eHNU0xVtDV2JZLDbRW9Ov9F5DRZyI4BIqnfU
BeD55pWuFW6/kpbQA+6hgOulwAeGKJs5O4TO+80fMZ2EtX438PtBH+QKlw4KwOyHiEnVnDLTfq85
LacMwghNjqZlKQOmvcIj/uDJKguA2LzhRqtZi/aOdG2SlDXDdcIG9XUsEr0eClsaZZuFu7HuY/my
zXV/vSYVYm55kzfNJPC6HCDvoo6WYaPQxaQ/uHg1a+tZ+AVSpEv+AEQSSauZT4i/Fx1LJmBEb4+D
gOMPs2Tc+6tdwEAgNjRcjH8kmWDuIZGvLlSiVOE0cFpZlEd0YSKnb+BjUBc71GHJ55F8BCXrbo4A
r37QAKmnvo4VfeFqr0fslN+pr+9sNbHAK4AboGNo1GikQ5h7wq934GKBmBRypE8c7mKAmdbtqhP3
/gtZqI7QGSsZ5+qN1pKnN/Ho3vGnRU96aOoY3dOGwWMb/2goXdn61SDoheuDrUCibToVODa0+iB1
Wh90H/r2R++FvnwMqCPUKRTmLgQiuQdtmwiBres6dvw12pfXgnqzj9lVksUYYyTaGZgtmfur1SS9
Nk+Ih7gM7EF2PELJ3WMGGYPUpvY8mozGuxXnmdovYVPHt7ZAZHxJ2VPinwg20NhJIZHiyYv2UqLF
o1bmkU34Z8eXagdhI8JLBvii3YPD7dQCZStSmhAyvgyHzXo2Z3ExmvQrPBboJK68fnn98uvnzp0/
/8L5F1CJ2EY9D4OMmB7pgJB3uzYI+h2/pZhymLe8ORkdj2KyGdMrIso3A1zf1Ut+E13LuUUcygqn
+2yRTocsAYliDZfOXnghOjRebNLnL2JUYTaL1CcXfQLEAuezjtWTUdSvjXXW6jMcMhgD1QgzK9/P
mt8RluT1RrePOEUiiseCUzShCPXpXz9WucxGtpf88DwmaQ+z1qeZpD2VzgfnSMuYoc0Nx6Srkolc
R+/iUOFyHXcj1IXrW/grstP0TM4saa8lxpN7CUBLIDcmSlt9SObfvkhxH6ToMpErMs0z72ntqtWz
ly+ff+W7L9ONxjgG4Whyl5JnIcmErXW6d8FSYAHnzq9L9t0BGErOyHzv/BXESNN8XNPAxYtb0Rm2
N4L+JLwsfVgUZ1fUPB1G7ddkHbNomheCablcrlRmZsbsQxRUln0xexnp2B6qDwEj1AsFO6fu96WL
l1M3vJiZ1jtczWzsxuTz8TmwicaNNmPEqGPDQiMCzSIW0stYMH75pQuXLp1/4dH33dKQfs27/tjW
/8J5uKPnX4MdmABF1ruBYGMBV7LMVRuEwPcSilQ4kaiR4G+ZKQ4fB5o898L577Oy7HEgOQEAJLKK
6m93HgOSk8aEaE0Ph/BGoCLoB7MAJrI4xNCB/HxOyMX8IJvR8VY+mi15tWBbFLdz3xw4O5HIOKKW
0qr4fZB1/W2pbfHr7WYnnf/By9zZPBtm0fEX7cBkRTa4Z0x8gR1F9mpDcSAdR6tVnFNWektLoLPu
JwLPi91+G/pNtrRovjpJVhXaRRAvMs42pH8RWkpT4YKJ6ivlaqkE+25NreYDL5rB7Yu4O+C+Xu3i
fAUP5iAp3PAKTDer7DoAP2mWJAdMxYWmUZ3xPqR3lbL80QPQJlqK0ofRIJpuv1/E3H4POaedcKZI
AfeG3xdiIMwYgHJ9C39HTeL6MByCMEVbwgypv5uIZR38fMXm55N0U0vlxbml+HtLly+1f5pzleEq
dVsUWeWMqtKLRG3TZxQ6KjaIgsQPOIMq1bvmfcSc+B7WUoHPPxX1ASIfJM8aWhZ1vYfF/HiQO9Lv
6gE5sGgBZ+W5xbmZXBFWIB2wii6ZJl0gStCIamg6clDjXLQqNNFeym1Smn5BW4D1BLjYVNVjCkVF
GEwQ8sgr7DOuIyCz9lOsObtffa4cwk8+Jm8Bzuj/Ke1JtKcn7+q+cg84CcMBliOQrlnpQIreM4CJ
qbYVAGs43Bj2N1D1hSHPgKnrDLqoG9sY7gL5IH1cOtTqzl/sAFWNnL6QqsCsqaD6XSq1fvP4iISg
b4H/EYF/FCXmwmkGGhI6TsyFgKwA0mRgBFtdYDzGPmKXP59Dyz7ZcZpXMW34+EiDPpDHVrZcRvVT
szMcBK44gIeaD9o1zkjzGj5LMmpErkrYCkFNgx2bYLlPCy0aij8nBXR0bZG1Jdfhbqe1S7czhZg8
JqvJ47mfDrvVY7ubiXexqrn5Pt57OdEFtG6eH15Faw/qdXuAfWvk+Q58AeDdGmYne1jOYCJQ184V
E66zYHWbcvhSqs6bJz/Fba8KT2ZjywkD3kZCpdGjZSSG74i9+oJ2inYQieARp183+4nKEZ3SYmbQ
E0I6e9VQXqk/ZqOitUe8I6yOnM2bU3lMBmp776yYrhTAkebDHt7kdVQOaDar2lY3DDpSz0xnnQ47
j8tlhGZDfv/mDY5myRT/1YuXXz/30rmLL5w3bIbp3hWXLpz7/uuXhKdNgn/GY/PYQd4wfZAt6SQ6
2tF6dsZY5ijPlcj3I26rs3ecUstmcinqd23vHSj7IU4e5a5R00hUcV0O4DP0GsJsU9bGT3nk57wy
NTszpZShE/bCR696yhRHAEAxM85QorcxDjrq7bG6sHBkKmZO/xnJDUfHh0lecEr7ZvsAJTmWUesk
nZ4wsQmtSponGGmOGKxf/kEhU2Q7ZA/oWFaPB5vP2WbJ2jAcdNsouUfOZP+NImL3mfq8Q/stHAxU
a9vFLNKIRW00XzO/04lczUCUGcfFK3JVmvzump2y6Uvrk3zuxzEjGR3izxnyRmYFoqZxkn7qhu8z
bYbQ4dakO3l5CXZrLdYvSmRWJyK8MdbDDKp67GhHKhqyHkULlBcWSSGUcTzSLNEuG24GgdgI+Suc
Bjn0JVhUzKgtvAWN1mjErqFGUrNT6jA4u5gb4WBHdyA5IjDB90ElQtBiHNmfRPqBCjWrjCiySqe4
IuTCq0PrMTyx9oCy8UhZGq1lwPSKqoBUfZQujsjJSgmWhB6yVEKdw118O+w0B5qOcnGJD0rm21NB
QPZhGmKAdBUUqzQs+HtP7H2bruZhfuz8L3+DJT9+0Ax2HkvmF/5Jz/8yUy7NVKz8L3MLM3Pf5n/5
Jn7Gzv+icmIoCEnKuzI6rco3l5xk0kweExQqomqrHilRSfcKuPDT431Mk0E9/YbrsXJ+PSwGi6rS
OyqiVcvTceTxkJy38uQnGB2rjcNFYyk69jYVd8XRHoB8fMv67uR9lHWxsu8XNAOqKo7t8x5Vij6i
xVCFWurnHc5AcuhFvYqMu1LlTKIzZm3cL3roZcnKZf7sPsU7k7cESOzvsAZESzrChW2/w6oRSs7P
MiitHrVSCSl+87xWWT2Y/YE/N7Ke6KlIDHz1MElIHi0biC3wjEr2kaatoTx9pArH4kf19Y3ddVHw
LmE2lpFRbUVk2pZCBno89Sj1hEN6cE6G4mPX8SMW+nlySjcvuIskuV9Wcle8jdIEhq0hJuXItGFZ
Tej9ql+ot3b9Qn94tdAsAGPdy8SzGoy9YkvGqjcBp1FwbVTJCgYXs7tQrxq1rJIktGjzkt1wUI6z
0ienCHx65lfvq88Zz1gJXun5g6h4fCbBQvR4qhWaBQqj7+5L5WascHOauYeqxqw3+kBU1gf+1YBB
iJ/20J7aJzXXNwQ9Ub/+YAszCzXbm8T1FLT6ZhSNXux1NjPRB1RDEh5l6RfmuREEat1Wt5+dRRYW
RBWQB3gT1rH/LI1iMPoRiFFNShQ3gCwGA9MlytFKcflUvJIT/sJvKH+0pHJI1Dkiiz9sKz012eZB
f9eKeZ7oFj36TbLA/6VBGz0KnDXTYE+9adzUKd2Rc89rANVvtex1YGb6ZudqNnX790aiOoFkI0kJ
a/QEXGgFsPkEQNpEw4MsAIOQ+FjAVsxPq2+aWD41lIuJvM7+7LhTFWLJ5NVa6JM4PvtnNkp7gvU5
RM5GVbG/RZrru8AAML/zobAXCIRG9ejzjN7QT4sYEWiG2PCA9P+apYGMY8g8YcZpqlSfjtDCboti
aWKwAuS0Fa5v+LWrZDHrrKsMF+vA2z407BQ5FdBfBgSpbrqAxfuTdhJ3OlUwQIvUbIBRhqM4FvuL
gF7agDjsOkFGQkgNgGIjCrb686OTx3OK3+h5CCc0vfSVeRyPdn6Ee35uoBwQaVC8EQWcDalPJHFE
rTxmvEcZBpk2NDd+PooxkkDRbQCuoNlIThqpTnOz0+1PBiKPAyAwjzUMPRHohVf7/gYPMghafqz7
GFCZwhLVN+hwkNJoKWZC4NGWthHg4hDY1YhsYdLgwj2xRKATu0UjJUMaD5ycdexX6LMFYERisS0C
ezL6lVLUI+QxBbsXSeAnt6oeBytggRAGytvE47+rVcaNkn4aaozjfTWPBzIRvvAh47q6Utq+DUOQ
jI9SuDZFUkocUquT9zj3GH3Fxnki4g/IsZKt9JyZTNnsD2Q+spSLIjI/Ak1F2yBLxLogim4LcIEw
i50wSkOzb3HrN4Bbub0qGpUQ2hJXOTibvSCmDkiPEvsVMLFf3lNXqToZzR12rna6Ox2FU1UwAs4k
gp6HVKdMvO1L9DPuBo1eHghBKlPoQ6xvDAIyCq275FXFvqlakJyQCxf1F8HCTbjLiteXuyxiKDco
WvsROLhEpn70rqtRRhUcFX/YWc4i894YPLf8aO/Pf3Q6TSXXzBSdWlJVuIdDYK923cgpmaT/nl31
gEZ+Rsq0B0iXNZINBJO8+ETWztv4EosdoSL+wE7RGZW09UxJltVvpCQ/JLdgIuiHqLLb5/SlwBjs
R7Uz8+hQqGphShU8swasFgQBmoj/O+S8+IWawZ1IR4mTL3pUUfoOffoJtWW3REo+Lhrm3apM0Qxe
YbpPkt8fsMe5MEfc5imx56PKi6rbFT5JN8xoc0X3d/Jo1z+5NwbPwZwFx/jubPkDgVDJy42e/pfQ
duNVUsCXSN2xlV6hNa2dXos1rd0vDIDbH9V2RKXIr139Hl18wWqj9vyrPz1M51/dFVckStUos/ka
Bj6zMlUMZ/yOhFflkXxf3C8MeFCChGTzXRfPQw/kIzIOHFJwCdkWjng1RY9qSH5Jpd7gl8+F8EFd
akiD3HOlQRG5fszx63GwlZRHDnkd9DvgsGWygdIUUPihYIf46i2zqHLKVthCTRYlozvCd1tN5YBW
hwbYI1IlYqnJd8hScihsoG4LKaDEMXCHqHy43u/urLcCv46+QbqhbL1H5bu+WfxxamvQbo0hYT9W
PIJjmrPAwF+SijjzZzjo97phliaHS6PqZG1KBqexl+iVMdC/oY/C4Qb8Kr8t5WXn6JP1nJn7wd+E
r8323Kn6yCuIJ4nxixwSexnbhH9LvNxzvofSEfQuaiSomy/0ywcyjzc++kQUmNMuNoBTYo4KHs6K
x1Vpz8mmjGn+dzh7Jp1AjiczcZDvcwJgsStxEIQ+NIzgsUKBDP1EUav6FSOb4QPpjH+LgtAOScAX
LIrLXvlbk/MggyTTajLx3+cwNmoC41SFEgSnRbzckSfu7UfM6h2wdo78+9P1blFYiwwzwXgzEJ6M
/GsJ99NMTGqEsET9ySxpJTMVz//bOIOPbYaRKK86JlFJxoweE/vi0rlK5hbB4oBTbT1Aim5GcEQu
JCZvy2ovUbUG3W2Qjt0RulhiZj0FT4zIU4GkttXFQBlmABF3b3dbw3ZAIU9/ftVS5C6aqFwy0W68
AoTw0eRliQSYi5GTpnjtw5XwhXX5UzKmfQE8zI/xbgMDt/YXSGBG4lB9mTTnSfGlMQXVG7Fx8S0y
3WcZvZod2IAfATirT4+o5MGhFGR0kOdAMAR2BftSRSxx6ahboE0wmakVfgW0vH1g3w+5RMO+VSxV
hqYd0UhfMiUcrX/O62Lwl5IDlV7FqYytclZ5oGpfWPR1DNaZfepiQuRtCouGnTP04+ZektR+lyuV
y/Gi1ROSOf4DvCZ2gI1D+xRNeCfy+HMcnbArcQXrkw+i2iAxNnjfskdFKnwDCr4UIjblF6DAZ2E1
kBF0CLWisvihrL9BuP0Bi+lqBhQZjnM7RLBD2ARSPAZPXPP7deH0E/mR0Y1fxxtPistxWWSBaMRl
JQvOtGCJ7EBQPU2JFWz4MTMdqFQxdu3kw5OfUbg8MTifKv+nCPpRZPkJl2jn0PiqdIvap1v8jmBQ
NBJGmigcTJ8Cn6OU0Y6EOHaTIBJ3/h8jMegBgxVNFaMiSdLVrEHEomkymJi8kgu9kay3RLKkIP3P
wHxzhV6NAXdhKY0z1XjxiVHEZFz6lCSBRSdxmzLY9NTsNQzUdBnW+wGmSQj1S8NbkKiKevR7MgY1
HeElPR6RnXwDtT51JvJXk5Ee4dPIeVY+Y5KG6PwzdllGIsB2fdKqRlAja9QrEElnJmNHJlMfdAJK
gNHsGLiP/Ni+vjOF69ukua3YOGDUWTqt6XFMIAewMe6/0M7eQXwWoSpWNSXvLOljDJrKyPYjJEXs
78rk2GNxG0/t4ORjzWZOnMRB0Q1/3yO3yv6VLb9jMnYS0YmFIlrzPRDwGpmcd6YK6xzVeto/7Wya
mRxII7lGGeYTAZN0h//IDrt3mVNkloLoC1nxEbYxQzn5FpgarpNbLobw23Cq/xo/xWm/15uWxZ2n
XwvC7rBfC8Lpl4J+93ILoxajX+XbCePC0uO/SrOlyrwZ/1WpzMzNfhv/9U38xOK/jFrfChzeiMDB
VRPc1U4UCj9fbw7Uw+WJPn25GQ70ccf79nJtK2j72iPMVDfux1fQdVd/Qg9iEW3Wgr7r164G9fOd
oRjHMYb8zWogJ8v/2i9FmNwFjEKmMZs1WUZcNRIz1uaphcmpqLrzre5bQ2xOC9AD3kR5aBSM3ldO
0tLx8R9FyRLikj4V1ITk4KqQMKVd554g8RQQx4lvkLQUMUjlASk7DpSIicajOyR1Se0vTYXDvkQC
s4/UW9IvkHDMSgNkJ4hV+SSausYI3BZU7w7TWm02ktEwF7JsdEQT4URp91m3LPiJkw/0qLMYRlSR
Z/KBiDyL4sswKB24RJXMqY0HgTlnZFdCWRYLTBMf8nc3IlC7gQ4R3qmOv93cpEhIBBPRIcJJtXpx
OAC+Mqi/Bh35nc1W8L1+d9gbMcAbr3eaA2f/9DXGz5Ah8D4xYvDfTFKHZ6jWWPT95S4JleVS4gcq
0ZX65mWMJ6cxLUCNH2TyPIwtj3rUjn30t73WsO+3XjF7eAxz2gBmtF7rD9sbE/bIQofoLqqDillE
GZmAfE3/glTBDyy5QlYq1NEkwCDVNx72g6z83CWz2sMOKDkr4SAUdOEfrPqD/44YlPGrMSx/Ps6o
Nb9zjkOkRFYm92Dk+DVmfy9wYBadM+Z8wkxQj6dzEM0ucWCXqALr7s/Ke8KZb1AZbxLDalXYBacz
dsqKAAgufWFQ3uiD67yqvWlqqKeq+Jb3/6/3Mw7/T3A7bYDTRBLACP5/Bt7a/P8cPPqW//8Gfibn
/5k5H1sKiLFK8TQQFzDi9YWAck01tyWn7+g2kiheIxwGk2B+zIBNxYtFDZO4MUmF+5KNW4mzdhZf
Zin7AK1fwWhvROvcmxuvG6TdyxTbG+vQfNDlFFZR9k6l5OOJUxgwEKNkQ91vT24Jo8OBzK5wEBnp
DilDz6FywyLbxfGXJx8ge028/r4sNM/6uUN0GdUc8YR7HUdVAlMM332IoSfQ4F3B/B8eH6nZ3GPv
DkOhKsrSs2BxwJZ+w7x/myxvnGj47snHwPAccaJh6Yv2RV7LHXEgHT9kTgz88lCldRxR5b7dvBbU
sUo9FgJUTcUhuZqadq54uoj2EOvyIPOEwtZ3SRd42d+OqsliwUE3yad3WMYlvDro9tYp8BvrZXvZ
hDdnzrAXNKoYXZBitHdkTsJeXVkEWO/6kQ02+9IrkfSFKOCp02EY49OJoEtXbH4hrI/kE/Q5AaGe
TiR5Q/3GIOjTDibow0O65CuOHYh01cpP3t5E8jjbwPJ3clPRbb7RDFp122m+2YCDoMEKp3f88NwW
yFBBPSsbP/OMh3n78YlodJ1f7eVcRcZtJFettrG8fOzbZePLvSfM3/ZseUJHRDKN4+t9CgE5k4aP
EI6+5fe+/Rmf/zMFkEkYwBH8X7lUnrP5v4Xyt/m/vpGfPxf/l8jdIZgxPg8Ve2eCnuLvtKZfK4N3
ebixFfgYkDoSrWbMhNZKf5qgPdVUUIn609+yQw67rkttKLsav8PO/VRMd5+di7Bog661ND5A1Sgw
cD+VVlRsXsx8Swb+C/+Mg/+FkWLaUBVOQADS8X+lMjNr5X+szJS+tf99Mz8Pgf8FOGgUwG0VMzI9
Amtqm7cQisI3zsmMC/hrq9u/1KxdlQkfk5vCm+D1Xqvr10e1fKlZrwe20SzW6grQFB9EhnHaXej0
hoORDbubmy2b1sVaoZP0i8j3J5gFtabf6zfHakUWnlHNLnNC6DGMkZox8Qc+nC3n3Ix+PX+tFvSi
vlS+TZFANJQQEB0YbiJMpO/3d/lBUMeXmk3yd6LQ3hGXdTDIJMa3UoKJA/bwUxEpaC45jCk+8iLu
lCNDo+xQMddfUlJE5s4DmohLW8LBa3rKvX0ycu6zwoNF4/tpY+m6FY0vOLmFKa2EXzVwA0C4aRK3
KWRNJdJARyeNrxB6GeHuo60deAeXwRJBUDJLCVYQZYqZyI4kWqG6CiCmE2bLpLqSIGcldEdoFhIw
SuicCoPyPoOA3tqkX+ewQAH3an3NPQCkyy5ib/Hnssx6Tm3QammAhhUhqv+QJqXWbxJgZ9neacBB
1avMzZf+/Zfl2dmSly3PV5dyIm+piFBiB9/yUgUblRZLwilcAU3Vw6fwDlp42aVqed7ZwVIJ28yX
4Pu/vvS9vHfp1e/JSnF/G2xc4tJ15RL62X3MnF6ChkaqXvAX8mcXLmIieCp1LxKPQP8Jg1YDE/Lj
hc7GdS7W5u+nKSSpJiTuKX5YqZZF7P9a8hyjuW4FrV7QRywNx/Z/R+Hjn1OkJzngR1zxfbq09/AR
7Na//1Ll3ZFKK37B7sR4PYtairq4ilRqJ79kjSxGnWuq1Puaa75Spn7kULoVbYte+lYb2qyUnbaR
Fu0yAh5+NFOdfehd/jX06/TOLJLDPQf2EdYyZhBFQbixVxqE5M2cpPcF8jRRpSFwKcR6fJi6v2sJ
72xc8oeI7GTSsJT8UXyD7IFSxK5T2BZ2QHnzod9fGgEbsJp85JOzT87tUcgpfodVh5qUM6xwuu1f
eznobA62svNY9AojHrPdzndbw36VU/klLztheiEI1526Pr+fi0pLrrk8/vGxzELH2J8/sNwLwGCO
72VPfkLWBVE7y3D7yT2G2SFzKCenEQd9br/h4B0pidPxYNaIGWP02dIjbw5H6Gkj/y8OWSCGibyh
tIgx+2zKlUnH12h1ZRxYxx+NlVezpsNcp+S5+uT/lc/NOjMTuMfASf1gM7iWzUz/w1OrpcKSX2ic
Lby4dn1+79R0Rq53BF6NA6G/IQpaaNP9rR6mJTg93efsIGMd90NgG5oNCRByKlFuJWsqWhhZZG2z
XIYSRnGNvpaTDNzlnt8B3kzwYhVX44diwThq7xalynlHGQfZLnQwAU/2a4N4m6GJkoRwgoeoiuld
CqB7oIrtslxxhARF96GPdm+fCSdTDsysQ2EGRlLsz+Q6DqMguYg/fwyclZIR5S72+gHFxMBRbZFw
Sw5pbP/b2q33MZtT1pF0zT0H7CqbaQgZsIgOZGFxC8SFAlum1Gjpl4fFbA1DEv/X6NaG4fq1R/l4
d7KPBTv0UAMb36aOO8nVmbEb63+vxQOahN0aC+UKUzR5Yp72TlGS7DAynXJtLlt+E4yh1HyTWTGv
FOGEO1S1Lu4RJLtIPHdLd9F7abdkK6ixMImZeBD7HXGo8bptNdQfsArgym4vCDGRHLac/mEvoHB5
/gszs6s/doKNXrxUeb0ZXoXbwcnAOYt6LAsQG0ixmDtlgd/wO52gH2+23QybAAtYeVh0GGsCKP5y
80dBtlyqAI53rRXdT7r92Iq1d2fDHszmNTyEiz1OiS+PxPoIC7Dr2pLXQ7RGsCQQQk9Btlgs5mLH
QVTP9E5MgBruJDpmLRVq3nMqbBC0yM/TaQ+Ryfb1jKr2IrLcg5Zwv+FlnxSf3rjhPWmnbX0BTxiu
WXANLkcoc71bxvbBFnBdnkNDxakZXgnCkPww41jXGu0yOnZQwdgcZ0P4teagTnLdB5z87F0VREoU
2FbDcOI0WaNU0CvDN+Lk1jJ5sEvJ8j349hMW3oT4KDOl5GX9Bk72+gF3pmUzx1RKwJ3sU6zZgdDx
TGuwXszY2MiZ8U9qdXCLE32PfkMx0ULMTHI5EvmSRTzbkXTkuZOoIEPONdWZ5/pOsz7YqnpNhM2t
oLm5NRB/tJuddf0l/m00EHUGGGT30JkEbqDTDej6ACBBtsx7A2AO1XeEkE3HILcuDQMOwzCq4Izw
rpAvDp3narKnUEXa95swwzPSb6gJ9DgUhWnJ66cq3lk3jdxUsGP0SAE+sRHdLHySPRUCmoI+ngeQ
ZqyIaIsbxC7P6inaPoy2pW1DbyTqwHRLObWxO6ASjxwhjn1pveqVGwJgQ2iG0XpGjZkX3ePYqzyA
9vVqhlpn1vKe4x33gC9La/Y0rGFjjjAM5GqJO9QVrp8XexpDF2YX5xbmvTOYBCpT5KKUWMG87Q+y
otm0bJUnXWYeW2ZyxQzp6DJe1ZMNT3sl2RHQkmxZrqePpa303iqzOfr+V/x9Ro/JXT3l43bhVonq
mX4PSFtnsO23oOvgGqDbOrA2VQzKRoBbzdAVAOpp5C1uNLCHjTCb5cOAgcVp5ODX7CkfH2zkvIJX
dpTka2O8tpclOdLOPCohG2FR9H1a9k1F3DMDVWlzw8c8Shm8a/RgKq7M4qLYh4BJHogEbqxtQk49
e50H2Pv3X14XI+zlONWHWwemVbES2a5oqCOjV6FWvXXyU1LJXhfbKKCQxuInEvb2ilNW9conrW0Q
qz8tN2TybUia7MgtcNs0tG1I2+DHsBVixc9JaFQYO7OGJF9ujfFe9mbv047f71gb9S8iwJzEYayS
5tgPNteg9CcLuKOzqR7rxknUlYx6DyOtRcQ5K47FdnFyDrUP2lq0vdBXsJd/5P3Dqwqoo1ieG2M7
fqcCvd/20F+XCO17lOokCVLuq4B0o0YUCb3XTRSyJ3L/CH1gJBJTmPpNzsVxKLbu4PgAOaE484IA
fIey71A2+YPYklX1EnO53avWYv+XWp/73K8LlG5u6l4id/PfZP28iJVJYFj2uUyzYP84F+8XeNBW
tP3bJ+8XEzy2Y07YahoWB2iwnCcfk13U6bubZWWF5aktoxuFxi1y005yyVbzcHtms+KEs3nekeUx
jNxGqI4VzU8+zMUYO4PnKin3KqCe2u8VyfsksX57e+OwY8QakVs3JZ8ZAC+mWDMUTuoRq+X016ZC
3dAGtXH4Psu95ICOg+SPGfTlE5WRDQg2PzKlHNFVswPvOrWg23DLWDHZJlbLS6PCq9xp4fRAdYWO
wHlPPicXYb/FIo0w91juxl6NSfkbV1CKoki8lAGxh4m6d/FZQlCEPeWjEHtDzKvYpidXVoDtgV0W
f1fFcTklR3vC5mSj/665uD7sR/LQjrJiSX0jnz8QjVzl4Bwj2jwUechUq1u+lGuxFLd4iFWmsMaU
0VtefhLxv+L9GlbbTliulY7TIZkZCAv1p4p7eo+SXd2VvFMiNrQz48R0DUBERsZBSjUgIXctjPGF
85e/f+XiJeXHb79/5eJ3L7x8/ttYxjF/xvH/4xQD01bQ7vgOgKPi/+YWZmz/v0p54Vv/v2/i5yH8
/xgcHtn97ywhAw7nO+tyRxOZLc6xPxFlweDfR7RDHcc4DdHeN1Y7ssSltRTJN2xfK74n43pbjR89
z20sXW8/6PbrQZ8C8bkUaEz3zXw0pmFIaNHzN8nVz2k+ijy74ppTbc9tKw47pmhWy5gzFb6kVi8R
P5edm3XbrQqniZHKNjpeNgr2jIL0JSuHdVL5oRURBxQ1RlCdDZFrIz4wbuVxrNTwwNF9FXRlw9e5
SH0CaWvU26klJsyi1wJkgImtgz4shZyXmNVXNk8tcfcE0aYuq1p0EeWWYsirvpX/Q3MNIH8G1rmR
hp5NIbhfipUnFlxqa3GrzGhb0QAzM/NhZDYwg/dYMyPHlFSPnQSL76iDjSYLv7SjQ9MchYoZL1O0
XrCLjusN+1vkXDCsMKkFwpiLZFdf3O8pHfxHiT5k0BT114HfSfDRGLVq0n5jtSgRSekEVdfBaDh5
Uh8J+6BjhkSehKBPDmwXUSwxtDbef2fOWVqD4mP9hTGmzP8xBZ9+uPQOI39G5X+bq5RM/q88O1P+
Nv7jG/lx8n8MDoLDS00n9t1hE0nEoyUemyDtmMoGNjLzmJdtYGmGvBfuBMEg7+34/XYeR8ZpUbL5
HJW0Gdst2Z2DFFV/CcaFaKqfCY9rKltdpeV/kuIZm+LTnz7B8fxfXWEBKqaSjkmwrKQk+B2FDT4Q
SA1LfRyQQvxAVc3hdOzCTkBvTJVnXuwCtRPlfYTuAC3WYrUiPTqu7bZHvnT3xKkc2PoETIc38IQi
AI2EQoNOjmpz86W8JxXoFFEwO4tPIuU8PVyqyId609IiPmX1NtdHQDf0NTM0lIdnPYM1uuhA7zIa
KGq2VHINPl+yBifv7LVYCrFTm0MfWH1UVq463tb8cICm2YhuWZUZM4Jka44ALA7Qy2ZnEGwGff2l
7c01XrvdxHaWj9ZYzZy9ydVrblMCIT2nIBv4ZEBA/d2kbM1hrdsLLm91dzpZ8a34ICH7BL1TNT2i
nRXl0sWbV7uDV4dYZzAmBpGU9t1dKYFpD5qO6oLeucuXve7GD+FsCyqJcwoOqHpf/Wmh8rQ3O/f0
V3cTM1SLSb2IOzsqcQ37GMmxsyLphgUSec/5fDc5bc0vnDiTYruY5YzcLvOab8znhBmIMizrBZCj
1q5tEZUbLDFFziVLX7D5goen4mdJUo3DiIyoOq/QNiI5Mr3o6JhMJF94omSjxMW59DIKfAPSj4ms
Cbz55r0irTnlcERFuqPFrmqRoNZ2H705St7ddbpTkfGFFFszc6WnPfh/9HEwoUlswFh+bGq2nH0S
5se/7I4Ac3TBKJHDULaMePjUNcy2M1dC14unhYuG8X5Xe/+fOISe+X+hp5u2k9Q8Hjkgnf8v4/8s
/n++XPpW//uN/Dj5fwEOQgD4Xv0C55PCP5RdUGPl/6jHLFI01vtWfqhYVK7m//gZf8PokdRJOvN3
COhZz5LxPtxjDn089P760vnvcYgs4+CbNCTbsMml4z2qmnsoXDqkpRrZakLDPyaHAVkml4rNcnpi
rdqu92an1/b6w47HvnNv5g1LuRzeqBPEuT+EE4CHvpd1vFJBfZp9s8PiD8NuJ2/Ut5Whn2hkEzUD
RXmoIypkwDv9a7lf3qWXLmFBy++9YETZRQXxMNZTzGqfS2HGHAFEBSaZOVnk+jJLDalQaK2uAPuh
Wv4CtP0U0By51RywqNEAkbDlscBhIxhTOc6c9euXXr549oXLmA83ae9GZMH1t/1mizTh6blbRft1
4U/M3uU1SimLhQGZXmQ41ZezLTmip7wnH/ZEJkg6tZKrP1Cp09I9KhzHmYE0Tyo9sY8OlMJ1lPtA
EWARSVW5Qv8l8QLFozX3ppxSGXLi9mvb0M6cgbbN7K6tvE+5K/E0yQNVNEoyqmuZdXn75G8pPqqq
TdxR4hTzGTAL5ylHKRhxBesw0/UaV1IJo5ma3gzC54D71Tw5BL5McZywlmYujyYL48IKTOFSLlsX
L5XLbIZAkXyk1uCvH/Y2xR+WC8YpVH/gGfeDzfV+QGr+bGb6jeLqPxTfmF579tQ0OazmPbUfZsun
/gHv5FOikXCLMIdQuffYI5VcNkLxx7DTBAEqG/mqomKYGLRwEPRy5MtDCnvgs+hRXiw7J38JEWAw
Zx994NhjzPUEwFAnqIA26EvITodnBA6v+Qij9HveE6MYvrfCCfE7ooPpaA4XXvne+ncvnHsd/p9c
e0SmRXsOAjTkTMYDDvxBmGt2ho4u9+ILxSO3sWSmSCdczBTgN5x9MVMk0Ij3eAqAZJzPEZbiXz/f
vlpv9rPw/8g7mM4yOGAOdqu0MDcn5GLHHkkEmpX7ZHnOUC95b2Ex6WPEriIGptv5263mIFBd2V44
sAZ4tFhxdMV3bZUv0Nrqmn3rGD4yYb8mbiG0W0vuBndrjF6gWbJzlPTCSnTCSkKR6lfelX7QDtBF
PSvoA89R2wNpKmwNw62sw7Ebjy7uN/RHLnzH7o6fU6yJiIZ9ELn6fQQcly5ZxzWZWk4GFu75Hywm
TDzIXZSPR3n0dftYEEsng269jXtDSPhN3g5r4chvqjIVOHOu20gurZ/TjvwU+Vjmp6qON7rDJ6by
0FNDsEr4rsgaIcqUAYf4Meov8GlxVOCfvAMCxQjkBNshHtj0vuZ3tn1kEzSCiAdOEdpZRpXXBJ6E
y8MPduUDnSfAB2jBy4o+qSZYSSJV7h479Vutbg3NgapdBRGE/E+sz1q3txu1lRi7pP3fGLOMaR+4
v6S4S1cOYFEKg4nyiFNQIGayZjKsh2+gW7VIQTwm+8VgKzhibXss7MtxNwkI99SW36lTzw2s9JgV
UUiZ2rOZeOwdt3XjGxcz1oATvZoVn+W9ly+e+/76+b/TR2/7nWYjCDGSBln39XqA1v2I34J/A79t
cVxiGmItmO94dc2cq2SRElRYww55ZMrBV+kY1mKhSTaTaLVHHoLGcS4d5tZhcJbLL2kD9IOdJjET
vBQtN/FOv6l/RLsSdGhX1ATy3l9fvvjq+qXXzl+58vfwz4VXr3g3+Nnrr56/fO7spfMvrF9++ezl
l85fjr94/dUL5y6+cD5XnHqjM6UPzajNMSfHMb7+qt6g1uqGgfXlf0aF17c/xk9xui5MtdPt5iYZ
nzrhdKVUmV8vl9dLc+tlUtKtM3lYx8QA65QYIFwfjOsFOkr/N1ex8//OzFa+zf/7jfwI/V+S+f4V
BRPRr8nGfs4P98Z3QeAD8tgZxFpKf9AX/ZqPrqQvfHdkE5mM8DFULDOLL1PpcGQ7b0d5e7DD24aj
e57tPaKaN01ApBPEGtnsA2fbq73sV38aXcnpq7vogPBrmMV9UvvJ2uDU7wdRzl7mKNVgptaONWwy
lDXYEWo2Zc2XZ2bq2bRcEQlJ9nnXq1W+9tmMdu9B7FffZ9VRS8dVm6DS08LpppGdX38j1C/s8pf3
ZjDFkNASJHwBpL252Qnql9vAT15gg3BkThVertlSwtfSX810F5NfOWRVe6J65q68N580jtlcZNKi
9oXTyLCw3mycb0UWrAm/1ZNU5T3OOjXut5xgijSHk3ylJ3jKe0vatmaeqpeC+dlGZtSCVcYlnPJk
qzXN7ON+aDvvjvpOAt+VZmdXwV4s3U208rkk+Bijo129o9mFSTqy0+A85MLsjDijuxk028C9+u1e
qLfYM6pRd7o7GGjX3dGbcCEM03EFfzLkxsXKTHZIZd8oQn8UXY9KhigtHT25HWU6R9m6SA9/c/Kz
k1snPxMaBxSuSW1BFiGRPVbGOHLMJWWP9bTutXLNqOB4h4NlRcwm/UuY+w5bw9gBTOg2Yo04cSH5
DPP05NIwqPKrz9XsyJgEf5Nd5Q5mgoclQBv8Rt4q+JUcF8TnGSuANkMucNEWylRlopeTd830cfTs
lrAV7fPk/uXkI5VWVMu5e4uJ2yEHpSodx30qWc2pPORuS6eVe9KXjOt95z3d++Jd6iVKlInqE6Lt
d0VyFawjhIoS08wk9k9bF+3gj8m+eMQDqX08RLsjpszHJ7SLjXlAXXWxi/rm2PuIPoRyG/9AXAHw
CDYMwpLpiRW3KyeJa0GC/1NpJ6SUMDDP/eO7XrTteQ3uuNzpbd4MgvKfRRW+H7BD3T1xQEL7RCok
OqhPZHDxAwv8yYflHXnAfwDI/5LUT7x3N2MT/epzebDvi17uRzvYCGZLc7SD70QdxfYPmUS5fx9z
efOkG6wyEvD0fkvZCOjK8W6wp80dMnrellspdk95VyLUvBfZRd8jtZ2saiQilYET48/zqNW7T7UU
kGe7qXk0fkHI4TYBn4BUUgDSCVMDebpWn66LnDwKb+VcUNlYoO4+oS0SsBvbzF6/G8HiA131mLCn
dMc/oacaVqSI/JMf02IwWp98mnRfTmElh2nfFVjySwr3vEv7L1MtHGifiIjQd8Re0Gfq9hNmINb2
ATLOmE2b2NxP7K4kR29hImutBJbWeLTLlByWJv0BXcN3aHf9xfpiQAhTfJS0cGO/NdXT9LQnpnDE
Gf9kGlpVXtjyDzuQfgcHkY/BuHIIESF8WYzIZG+r2wnIuhARxrnZvI7k6U+Fq+YwDY26eXPz+Qh0
5itrOgHm+rwlTekjLXmSOKPdDRl1grtTxAOjBY2YW/iFOUD4RWM+8TGyk3n8EjjDPLEKa7ag8MJ3
q1WuImvIGhg11QmD/sARhsEiA1lT8Le85sXKa3kWM/bkbfdXZPHjASUGW09diNWZDDx3LtZr8uf0
SuxAvHudHaeWxhYJjpu74N0y2WmxSto/jUumx/Cne0CNI2bn2fbmdA1k9VZ3c7rV3A6baOTDrStm
ipRfL7kX3Vd3DvfU5es7u+DoweHsyzC8SkOv5Z2OvjCG47xJGK2v+2KzgYOEr4e9euyp8a2Z4Yz/
65SG6+gEnC4P1/vd3oXGeeHjYUCqUoku/2fSiRan+yrkG/N8htNRzv5pSgMKUFPcaPmPEBQ0Kv67
Up619H+ludmZb/V/38TP80ALejLILUOwLPBABt2t0P+JcEfL/9GufNEIBrWtXr/Z7TcHfF0pzcQT
a7knnrh+vVCwgntsLZz08I5lrElysM6a/t+62+B+XPuW86Rrd9IALsf5onf8G42WjxtMw6z1bXLT
J8mFOuDhCwXCaN60/K3QZg6B0+sCEhx0i/Bub++J51EJi98kBM7Tu1MC55JjC1eNtAp+UivGploj
XcUh2kgmIpNt+9cKIqfOwvxC71pOuHqY+QcdmUbE0ID2ng86dVIiP9dr1rDoC9dMfZ6sdDx4FGH5
vOIpRJ8vXnztlbNXLnMZTorqNcMxuZuwXwuDQTQT/lt2n1efxqNQnxNubW38cGXq+nW5+r29KW+w
2wtWpjipLL7hXugVj8AfiNHpcfNHQbgyVS6VtnemTpszhX1oNp7Q/xSLfWKsyWgjJqySnEty6Hgu
Es6I3U2amTajifY9fc/FySdtulzjY9tbfRXWnj4HxAl70zctinuXIIqdR1PkxbHvU9LKeKNzxkTx
i9gs7UP3/BY3t2LWvZcv/ODyhVjdZ3lFqcSza5p61ky6pjwXRzpMmhs7rsXbqAx62MiessDz/JX4
wzUXA+3nPONP/th45ByLjP80mB/udmpwys9NK6zx56aF/xV/4vyfTAU/Tangp+Op4CfmBkfYf4Hl
i9l/Z76t//rN/MQ4j7Fq/71h1BNbdrIutqt/1Mph7b086EsuRzgow5NqdYPKqb/sh4CczYzcmDZX
ylyrEnG/3m/lFZv0IjMG4i/M1b6mV3/FiQOxIHd1dCG0TUh5maXijJ0cRg7KxJfHFJF6PCT/MXpE
0/Yk+qUUm2H8O5E0TTTqdDn/ssPFGgYYAGmJ9CVWRyIntbVN1H2UdA3EcnIuNTdQS2YRrd4g/meS
RjM2SAwmEritsZeXsXEmS1FNz3Mq8pQ8TNSmUs59c4WptEh87/h/qGSwduExtBE5svxSf+ibbW6h
YgOKmX//peu15ACKcG00zWa8Kr0e1HHGixKdj3sUv+YoWnbKIHlIbrXYGqmDPrIDw7C8cfZ7L+Sq
iVXs2CdWVbDDfZdJ8aHLf+TiT9K1WIqJorQMFtAT9fIoFbfaBpUJWyjvZHAEJbV2FxTSoiP4Jpu1
jXDjXM8xebc02UkkEtOhDraa/TqnPjfHMCqGxWZgFuxKeC3raQmt7yk8SLK4YjJdOr6nynOLpRmy
hdHZ8sON2bmZEpXjw9TU4tlSuVauZWRXjT5QDZQoZQButR+0CKaWu9tBv9Hq7lS5Ws3yBkW8F/rA
aA7D6mLv2jKy65sUe1F9KlgI5uqN5dqwH3b71Vq/G4ZbfrO/LOVS9DM2xvE3wm5rOAiWUVE8qJaW
WZwF/vzpZZEhln4XUfSN5qBawykt97qYTaBfCLZR2VXtwGaoUWDMepA+jDbpVrMT+P3CJi4J+sou
lerBZv4pYG426L8L3vzc0/TbYi512Hp34B5UrAk3S64Jf2/7/c1mp1pY6l3zSvA//MXa3zlYOj+p
zkAr6K9Z955qNBrGrlfm5meCDWh4rYBLh8MqUYdl+IRWkDrtoN0b7KbvVr0Z9lr+brXRCq4t+63m
ZqfQHATtsIoQCYfxQ+DDmo3dgnABlo97fh3FhWq5AgtDLFOgb+XrBrQuoEBWLcPilumeVZ+a34D/
zePsItVEvbntoadjgXUvzMmKJ35YYETJUoyibBiUXqaU+6VMJBBdK+BX0FThxfq1KkYI9YMizMrv
bKIRAzsiTmZvr5hSDam+O8mnRk2i9ohRkyshtUcMmlwHyR9k6fxt6w0HbgL0ABBQgyLc3z78e8XH
+Iwi/P93EdDgKM+18Iq8hrmSLRcS7qQG7CequLIiCzAgm1eA4ytyaBb9qoLw+S+OzOfmdiyazCZL
vWazYm40hb/zCjjjYitoUJED/J3jxL7jQe+AQx0f/b34iNQK/I0KFsOP9BS/0bbB8mXODaCq19Ws
MMtBsS4yDQAXRH9SYoHZhbVlu4fuTucVOhm9D/qmbSZ70J/BpgUNQE/16MWuo/Gu2Tg2dlsNbGwv
6gWoAzW3nLm49rW8HGHNeTL08hQKV0W8VoAsinAfXxE6TMz7XMbEz6u4P3MlzGzMu8S7Gd9teUvD
wS7eZ4l2NrF69qbfI7Q5dZpFDUQKkZqJUIT4LBWvMNItbHQHg267Oo/9xZNakg06KtNMhYJ7156b
jg9JOEggpIJU8V4rABIFkKtdXZlarcMm1nepxgfIQXwBp+RUSe9CxHdvz6d6UgUucoNDTi8slmwt
JeuVlMDk0JjqSjWtJSl0SL81BfPbaALdkHMQKWouSfQvoX0VTuxZDxNowD/yWZmfAUo1F4GEHYY4
HZ+QdjKkMiTijE2t/aT1YVjHyC6YYmEXnMDRLPg7AcfvnIGp87KGj9NIxEDV+aeXYXOIXA/6fidE
7UuVfgM2Kvj7bAHe5CQkIvkmGOUEltXFUmkZmZCCZA6gzwFSa8zpgPSzUCxVgrZGLxm3lWd71/KV
4uz2Tn6m1LvmZk6I7EZzIs1lDW5qwkmd5sMENhX2l7fHWD5T9AItujgXtKfoA+ZcnV/wmrGRYNDx
2PBPYpXVJ+aGOx7BXnRigEvTsMEVkznBWYyCWOTWGAqxa+1aO255HLFUkhALDs1YhcqU3COB9VD5
8qi8FgcSq8QNXSAVSRlUOtOZpTjYCofS5buiSsVN8kr6/ORWXrix3GKnFqz2gN42t6B7rIlypHJp
cFa3T4SwfA+k2d+TU9Bt6QRDPoJV03h2oBdAoQIrb0clKG5K3/rj/aK2ieLXJ+zNNHhKQu6wbQZz
iW8KqOAbLNOvO31ohf+ZMo/ndOJtnZgSGFl/kQrMLDkwvxrGwP7CdGcgf6Sg7XGQvxARFkq4Bzoh
gAlMLyyVpBQwF0kB5Qb+zxIYKhWikPbV1s15xBuf8VKpCK3QoCRJ305CV7gPg6yIRxNTFfukR1IW
2gUndXF1pVGYXzv0SMeHiSPEqIfdfQIFWXp6uU/YfwklPgLJMvw6LskolmZ1eQqv0iNSAznvvwiK
QJNJePxwezuj9Auzlg6jQT9LS64NTAStMWZREpMoqTlUIoUA3lupECh5tloFj3+i2aRQS/MSSmKZ
fg+TaKXjWNLIJyBxYG5AABROC5UKors4l49PLdRtAvvs3FQy0jewPNNhu9a0qTM2czl9KX2/sYaS
DAkgBlJL0YQ+MB9qtJEUu1I3fHKrmHgi4VZ3Z2VKCVywpRtDAIaOMLjzH1MGEWlfE4WNloGUiF+n
rMUKDRBdb7TSctxYFUXCPm4dCTkUenbfnWTx4LlpHnucM8afyCVBmDPQFQF/c4gkMVKJ7Riy8Deh
k2b7tolWUMHoETwIMkcwHKnCCG2gHnTV6Am7Mi/QfGOuUV6OUE/CR4yIxFNUi6+58ZHLPWQMOIy7
FxPf9hkA0IcEQFSyTIswody8eEgaT8bu7FrR+sMoeTI0qeqV0jnRF3pmv53CunHBMOIn2elBJrp4
QHXE7muF3kzezoIN8av4589tHP0v8FOchps3CKdfDHx0wtCL/MDzbyT/43x5fi6e/7H8rf3/m/iJ
5X/EYw/fEOCgFflJ9wu4hFlXqJSPowrQOJ++3AyjT8PlMWoLiTevhzL9fLrzQUpJoih+PQiAQoRv
nGMnef4zFqH+IhIEoo5v4GYBjYGFUTCG7Cj2yUuDQe8NvbDfyLD3qNHLMH9U1r8hfxGJOOmc8L/n
aMTHEB8/OrI9nxbiIvx6aRIxz1sRBvngIXxsnQnrCT2pOHe5DSLMHfcndiT0hhL9bMOFp5yLbCM9
baf0Eal62mwCdORYj5KHB4PXk0Loez4aQKpV0UYLtOHkxiFAV9aAtGqVFjleQirHbAaB338hOYYh
YrdE/mVcH/JcrqqJCH/VKtywYOCshOhMiCMH0MrxUbp0LJCCZmiRuA/f2OOpb1edvs15pzfzWuL0
8YcUFlRd8plnMMvQOimCwvWdplxGnmNiNvxOB679dCaWinLy3dB/4s4VIl2ZnVQzcajNVnfDGM9I
1qfmXcR3zU6jK5d16eyVly68+uLF9RcvvHz+1bOvnM8VM4XvkGcCZlJyDG2mF3QV6nTBIv5IOI+g
z4RTK8gG2Y11YR1cb8A61sUy1qGf9XYXFrY+6K4PtoJ1GHDQ7SdAswBi9C/qDy5jEiwZEadi4WQU
nIx/E5Fva3nPBaAh1QDIYemrYe1qVIPKb7Wyufj11QZ2BFZREkWKqdLHEkUDRKoLnhSMQKbLmAPa
GNu41W0H6zjvkPYKdw4wYigst36nvk7e5evCwzZ9J2nYrHEhcJACdUFJIPOivgXWtNA2JHmBfB6Y
0INitKwYvljIHToPsahB8aqx6LqMCio/EtGgrvC0hUpSeJoVU2b636kIucTFu0LZZkru+LWKNtba
WHtFkKptlRW1SN54a0YaBT42cu+ZJkAliLx4NWvXsxKgGsBo2g4ntnpp0G5lMyJmpepROYnS08tR
FEsVlv00LPHpUX1Y4Q7xYJMoFCBT5JCGtCMAJDaVMOQL3U40bOTnQTRjZQr3dtIvAyznrD4dfRf5
pjW6rRZeRwl/pHIjitMdDtabcDeT6TNTON3zMxFj0J252H/Rb7YcnIWOmubmuaiCTUBFTQUZCH1A
GoSPjLobDp9JTM+uopKFlK9lKSJt102ELTuvhwbXrju7mBRSGr9f16KAM+fNo1rHjnuib8oigO+M
sSmEqRD5G7uTtox05HGNAHZtxNmU7LNJnUYa9DGpFLRA6MiIAKD+c92vbzdrI9A/4prO5tkwiwIV
RtHUoEMijCIHFGOkOsgrKox6Lb5DjwF8HYQoxFRPkgZROvPFUmkcIEs4nqg/A1SkhIXMTDjImjKp
5M51RCt/P+d3AIdQydbXyGk81Hlgm4OwhjGkZjFK3lvNsPu5CDnn9WGJdUAkwfcDOJlY3cKxCIBQ
4qIp9w7bpe6zx67wk03G6fDxv1BaXpmo4UGVDuPffwmHkfrZQ7tnj4N4h0KsFnzkOhaSx+LwhHXX
W6jvX691e81AcESIkcO/2LvwtQMJGkTRNd+VUCF+a3SdBW4EF7sUN5Q3XFxLLryGHgmOcp1xbJna
cQLLOWZ+goVEYjLnJiazSUzc3IhCoTqM/y9O/nPyHl4L3Ix//yVuRuxe1BAbZBBCE67MS374ahdP
6Hy/3+2HBlDII+4HglIk0RdSbFwmefdvm1JuVPJi3h1AnNQbnpFIeWDIvs4+UkneKp4NHsMs1aIA
SSwm7kd1pZwvdmOagNgH5kkaCD5JhjVmegVus4yS3fLDh1rnyIqEsHRHUPVkI9EhnwP+1W92wssi
oV0BC31Qyn8q+bGjBytY4a5uRQsnwk8cNWl3zIq9sezax//dLD/DKD4eKnKPE1oxXeLsOGRgypr1
ZtwBIjmpiHxw8q7Qcqpkb8r4xXmHYi5+90UjzLBG2ZGSirIR0YH7CzsqxO2rQdALSWMhBO11tLCb
XH+/iaXVW1wf5S+V+CTe/he6ASClASGB8XDAN0jHHHqE35A7G/Ep+Uzs00dDwY7Lbo6Xd2XaUQoh
Hecpdl9bgUMYMDZ3bQyWaMOvc8wRMkNAK4ZhUP+LBbpvElJiOZWgt7pu5h/2W9lruYcDGQ1grJHs
MxN2BnVozPaYBQMop66o+EO/MwFxnyJzUUBGAPm8AFtIpydItrNCC6ut7XoLVFDFWQ3CKrlkl1ex
teCahYFKoVCDb3PG/wX+FKf5IKc3sPjtNCnhQqwJWfj7K7MX+q9891zxh+EjjpFq/y+XZxcWZmL2
//n5b+3/38SPwkGngC253goGXrBSB84Z7fVFso1cJk0o4ICpVVKP1gOgaFgD+a1hdxAUAGOuTeWW
m43sk8GNG4GK4qG3rwV+fTcnqoe43q1MlacwVsHzV4JxhoOh8t0Vf6yZYWWWYYhfNMf8oiNGqK1k
r+ZWTl/HRTXl9JtF5PbOcXziytUbN6am8s0ix7SuPHl1L9+BJUD/uHMYoMQ9r1PP64Pu1aCTD0fO
A1C336oNMeQDJzIYcRa1raB2FShfYdAFZgO/GI77hRoSqFGNButN/Kncr8tJhwcsDJzDVrPXA8oG
Lc8oEMA3l8WLlRUEg/zuyuUzU8e/0JwnSUr4hLJ/fUkB3+9QBtqPit7xz0/epzdvS6fMLymv6hEK
Dpgv9Q7nWzYcMymGgbOHymzCbE2iuHBt1MPiVHVq3CGUCuttym2KFZve4VxjVpf5TQYrTNN/oTNo
FV8dYqGgFykvUnaqPyy89vpU/jr5/lWn2ERa253Ky9+qU6+9/t2pvVyRMyllr05TmGIDes2fFeA6
zA1NMMWL2cv1jIdn9/Ib9FH+BfiskcVt/9ja5QdT1av5y2deqJ7N5dsrpfx2/q38yytPlvPnVihz
TxYHxIt7deXZZ9vL22eK/ka3P8jC5WkFfv8KCLUAKtm3cvxVqC5KKd8pksFzBe5PLTuFUG7MbjPL
+5KNIgIpmOMKgngul9/ITqFHvdzvm5Rfl8to7U/lxz62arwDqtxtHBvHxlCa20/YnVcV9z7yhDCJ
DpYiX7jmK4n/FnE648Cz8g/GT98TPsLkv3nEWZK/AYDPERo+68Rh7QAk2bo4NrisgFm3+91etwX8
7bDfDPpThP+zZ2/cWJ0CGfjqUFi+YANq9eCq+mPX79SDa+rP7o+6HfHHWrHZqbWGIOpkR80A+Nbr
XQNkRn0B8P1vovIv533mKr+xXfAovfaBqEonkrsfyFaw31/ii/EPA9DI7jKTjz2YWjNEA0Ed7gPt
dX3lIkW3FLEK6fkOcP+w+NUptYJacwC3P/q7H2wCodafsHWRVxp/3qzDrmKNzewluKyrl/LRNq1e
WuOdQRkOju5srl509uo663ysbRPaMQicfeaZJx2v7QNTu7K9gtjwLKIOfNfvtlpBH1FGKa9vWClv
fj8BofijvAKo1oHbm3gF/uPtf0Ws/zsdKcBR3k5ADNiccFHCB/R+Kj78O4QdDk9+DHP7rUykPYJ4
7HNMwUcinkC45sG/mIX8HqnAPNKB8dIPHiviGXsDEYcM+rtEFS6t+Dt+c8BJ4rIR5ych42+QK8LU
SdcZzqpTly5evjKVxxoUfqu6XeRf8lvAJAb9sHp9Shx94cpuL4BzAgkFpBbywpzGAmpT+bMgcfcG
rjdTf1c4d/m1FwtXLn7//KtTVQ1bMF/GVwGIYre+W8VKakUWxZuN3Ww9t5fL74jVXKLi01m6Mlef
XFlpK94QQP9SsXs1N8CKnR5pAbLidovkTDvFgHQDN25cRxIOHF42t1paAzaSqRma48i4RpU0bdok
o0gMEGQIAoTDoaGMinRIUm6Xt218dYedLrnoehLyyi1LUr1TZAZ2Ekr97E6RWEvgULLiV6AdJTev
IRpwWuv8TlHgGmj8Kyoy8QGnkqWI09/zDhAUUpDByYeIktV9QKJt3gdPKwhyQNnVI0ItctpbF44Z
wMfcJZPYiyvXux1g8DinE1GaO4QTRKUBUT1kKt8IdtytMCT3Dt7HqXzb7+ymNkKr61S+O9gK+q52
sqQJd7eXf2VFHOkOHOluuN5udnJwatZD/1ruTPR79c1T16Pme//x9j9Ff/vX9t7Mf28lev3kCjpo
PPNM1ICfnHkTY2VPXX9lD/5zcVXxyJdaw77fem3YgisELPJUrsieOdn4lHJre29WgQ6ZpCKCpTd1
WKp6OEkN6PYQhvQjpbL2KRBQfLP6po1k472euv69eM/7o3oGxninSKFQ+bdW4F5Jdpq4bo1/fryc
8x8kaULG8aagVAfHdzk3slBwu7BMjI2SXkF39HUBrnmshMmeEyHQ+7IGxQNFqPjV2DPMPTyv8YvE
CZgbSqnfzKDCr/5krieiAbTHX90tRsxJwq7HDm3iEYDn0lc+lfrBlCbUlffy86TNy+1R6WrgOFEa
vQqoo/3MM5eKqIAGejlFjB5Rx6ncKGj8BQOAOh2qoierwsSErxh5pD3+WsnjYwRlBwdApO0OdMKm
0TtRJKklnwIm+Uch3ERT/dpZA+uSXCq2gzBE+7ENQtY0UoGogSbS1q4CnZzQHWiS097eclD06/Xz
GMOMDlpBBxDeFBnZqkoxxTSCpKVzOegg/gFFxtJbV3fhcKPdBAlVqQFfvnHjSYF3c1eL6GAH7V8Q
pdnszXiZMQZA6h3eVJ2lS9YPTaJyEkqJiUZIRcvdYlhD6QuIbvcHzWAne30DSwJXpzjTGog6wZa/
3ewCFxG2u8BPTO0hZ3wum9tTKuRLQTbI+3Tzn4xYfhTyz6HqENNECH4Z+aAuiO1UVDgcZKdQpUWK
2m7EnJMXUL6WoJU822pJLSOOwNpJTefbSdJJaq1hNv1dbByulPKD/DDfy1/O7yLMba5kpQJ4+h9W
n32j/kaYzRULa8+emi6SdbIpdRGy9iZGV+Oy2iviVbEf9Fp+LchOv/HC9GZ+ipXk0/+w9Eb9+tKe
7Kedy7VX3lw4db299ya/X4T35ZKjQTFsoRdnOQctedTpf1hYnZldXFqzujzz5rPUYRVntYcawjYq
8GBhuKjdlXa+hkrE8z5g6uzL+OxlA4C34WQ76l6+lfezuznSGbZXSlLvtw3sBq1o+5lnsttwW3s3
buA/l3P6Ib+18uyzIbYaJCgIh6h3Jm/ey/zPk9u567Dr5YgC2GB8EQ7Q40w0WO9OXBSuV8Z1wBz5
XClyWZWXYvlZVLaSyVclOoaDEtoJMY/fsjpP+oZoygBSCd1PpEA8Vakf5vykn7JWiYrg4YVFPQFq
0LfzQ53Ti5SsA5eKhDb35ZVB/pzNHr6s9rk8hwRZieRXE0Ry/X4miOODr1Mc704ijl8npFDdJuRz
VqzoqiaWvwVsRqiL5VdBLL9xA3MjdBve2WLN76xvBOuCUgR15EpESdgpQ3qforMjPOENOyorLgBH
D85KAGsjG+8xH390hjuLk/Tju2ODEnM9itIrCEK6wb3fl/cgaYiIZ0jqyzNg/YMTke5CvBbxoEfk
PHWLKjgeCBAfwUxwcoubeAvhyn5ycgsumeAUrxK9eGsFj03tKl07J1MUTS+JIcIJOdkf42NW7U88
6/yTwOJKNsVAZOdye3v5dm5vuengJ5qd3hDZCbifG1nglEEYczWrbfmdzUC2yyESTuRcCDcI1Alt
95AJSWFk2hHe7o7QltO12RVYXdfRM4I3FfzPPOPqTlchR2YDrSsganEuKoFgs8d2od5Edyqg1sw1
ZHELGxwgsZejLcC43u+v7DQ79e5OkdI3k59idioLQzUAbwHBrw9rQb3Q7nI6Kf47B5daMTFXRtnB
NcYDF+BH5u9iK+hsDrY07PN9nkYQAvuYncKyuv2QGdOLG2HQh70C0PB4xrnc9SCiyl04r26RnJ7w
NPFoAYzCwnYzbBIaUkRq1Ec8yYLfbwd1/Iyt7ULBEpsPd3K9G3XaVJbwYjOMPulsaoZxzvKaMtu8
Xxx2ujxGVn4A57aXvw5INwi3uq16tVQsV/LoZ/EKZzGaKmFaY/j/wuLTwG4uWyv1i7LDbk7jQi+O
68jADG+h1+9uwgyIafRHfIKhYeq4n3nmSd9kaEvAwNK9BAJQC1pnO802Eb4XMUUbzDLfXekH0HE4
sF4pa6rG7cpfzvPtEvz5S+R/VRBA3kSHbn6Ecwp44Z2V2unSGdGEP/v76dp3yqVStQSbSAbmIpD+
S/1uL+gPdrNTBbULU/k3T13v7D39Zm4POvRhjQqGc777U7GPu/SpStBrDv8dOtsFQJ69a2/iuS83
4QKLNg60RV9N5Zv56z2AKUAq1SdLeykfwNSbPwpiH0RQcWFcqDCO+MaNaPUaT+FCMVuYU7zq0T85
z+/UvazIt1X1MKcuIBm7K76JdPYJp9LuDsOgcA14JrgGU4ghU5rtymZuEZkaYXw1UIOuhLcmSEkJ
yZnRCybblcmSC01OlDzd5CTJhVJxLvedCohZUaO/h0aYGBnasJugaJQEc2p1ADcAnjMEGyPXiPD5
nSUBR07pnZoCbcal+nCpdRn1tQnQe8N/a82rBwNg/UILxzsAg09zqjbcaNYKG8GPmjCVUrGS90rF
RfwP/FbOgaC9km3ma3L/Oyuw++e6bWAPgvplXDfgW4HZvVqx22jANvAFf7bnA+Z9sdX1B9lOUWS3
ugLb7XzxXUoQR6BgYnLGMk0b+MNhu+33d8Uya4pjBjaFUBWp7+QuTulr7jAPEgqJWZyeTPqPWmpu
sbdcS+FmBoLKDOJsQSfXKTJCxT9WlKQ9XAFQaBp7hFCBC4jPgoVZ4N/gg17QyfE/5I6yotaYvS7y
w60O886+14Be1YciNdrMXCkf+CHmGPNJYO524KI3w62VcBnTRHrJPXcJBuI9lpJ6FHvLcy7nkedB
ESiC6xfHxW8NfxvLcwVhoQaXfoCoLuUioF7G/A4ZMAVRPk7LT+E7WYwVLIV9ssuMgWI+cSKFXSS6
1gzRFcajNdvCaiScmgJm3OcO1dErLGzicohBTRZYYaf5UtgG3lqR1U05cQ94kjVNGm1qPNGgu4mZ
9qdazavAiMHh0i/IxQKInR2AiAvrhvd+v0lp7sIQm4mwHtk6oTnZmlSfZ6aO/8ilVlhCwqht/I8o
v3Jfmv1Q4/iLyLVJJXKzmx4g5ckFhoqoUyTwyQeOFcq7Jtqg1VdIewArDBkx+H1hXPitdbtXm0Fh
w+8rKm1hYNIFr3O7dSwqEKCzUTfql18VQzjnQXZq2UOIBorOCBJRhwqb85+dWgGe+YxsC3+sltdw
1C4KNCAFglBEv3aCGirAEYMqd1el2LYlG43GiOXUtrrshWnz3SMEQInNpTKHuztHvQFmmynPzaBh
ZtlaO2JOf28FyO7esoe++SvTyx5mZ/A3g+gjD8NbLsOdX3nZv/ZmPlpQic4OxS0/EPutZL8iui0V
t8tT+R31jh4Bh9nuAZWjd80gkrh+FEjG1V8R4B4URUrPV2EG6GmbkyeQJzaql8120JOyCM3bKCh3
V3x2pmA3pO6NG9NY+52yGO7fELXeb5L/1tHJOzekJpzaPDg+Ei0oMdWdGxjDSUXhD25QnDRGnZFh
/wYpzR+c/IwyGX5wgxJjHVHfeFu+QHXjjSil1Q3z6SekcCf1zg2RheuLGzSLm6Tn/4RyMB7hfPdZ
dSFUL8IxQPU73WRFbzd344avtMLFsIuSBO7L9D+8UX929Xi/cPKRX/jR2plT8otOjj+JWqL9mdwD
fwJjYxAdTOmW2AcRtX2bVEo3San0QOsppvpG7b1Sev9D9kwVkQzt650bIinqx7zf8M6LXsL6j2R+
sndoGvu5Mzdw/7H1DZFq8oCypH50Qyh77ouzxQTmrObJvRE+O91ERbsAC+BgfXGzea1y063DeJgz
iL1nU5/RsbHr1x1+Kx8AAnY8pf04pGh5nvBU/vhfCH6PEA7hI+0vNEP+nvYDALtw/FuMXaT9+gSd
HY4/RSSf+j5//Cs2HNFKyC+kOhV/5pgU2qMfyJDKAm5BgejJ/ZNbbKxWL+nj+Abh2Kx0R48X+iR2
EB65JbzDU+CTn9pbbfJdFxDYPHMdUUy1mWcfyWptj00fCsec101TEnBBvGg3QzTUhN3WdpDFT4ik
CPluFxBNqKA82JEfsPpBbwRd+PVdEuO75tfsX9lUYwLiU7+tmH3mmw4tAAdiCR0ACcbN3oDMW/jx
66+9DOR2MOiF1elpv9cs0GTYy7bYH05XiuVpppIdYBv8fm3rkt/32yHyEMCJ+J1NOMX+cP2117FL
RxPo82oAQlcAYlTO1SAcbm5iyKFs6OeAzceU74CbuwKbw0UEThbj/YmP1ffnzJi7WG1mhVr+7KUL
KnrX6Z4L9JrGI3c7GnCyb9XWIz9ZBG4wAAQCzHquyApr5maZD8QzROGnC9x6M4jA7YdjczMAtsJa
3e2Po4GiD1AM0HQUthoqIciEPiVOkWNiEgbKMHts+SGvZRCdJmhoqecaCiv9NqqDAppesgmW2291
uyDRR83D1OYMehwUk9YOLjMIFyHHwqQ17HV7wxZxkfle+sj+dlBAcbieGutCbQnosNkuA8ZmvrFS
ym/wH20W46RUU18h4xZJ79lWt+a3LkN3wIGhRubCIGhn/QAIxxRl61ZKgfozzwjRvE58Ffvj4z2b
euYZfiTIn0CKxjOJIFUX/LfWyRn5rDo1JbAos+4atd/by29ra9GnHoqp7wT5qbI08lyHD96Siq4o
EMR3iTLBtR6iL/Rnp5xnU9qNRIugJm/0SZ0FDDHuPJsH6GrAJ9uw1I0zyj6Qf3klWxcaBjmD3EZ0
AzjRmsCyy1ojZN5HTROjZFNmSbpwxxRrcgiyQYrbMxXZ3fkxXZKpuGmkqPVITpFumbAV1DcAK9dX
tCHOTBn3lWLPgV7rl5Kf5cR3PIdcKLeTtRtp12A3CFmWEdt/DrY/f2kFWDNB4XZWJHAyXAoutpQv
Y54XAYCXXC/3WANL65doL+bxCe+WE0HTD/KWVXknpwBVkB0UP/ABiV5ZJLXnhuGg2+a/hbuRhrwR
EK6zqrC6swdU5C1c9lUBdZeN+dXzlxV01ffyZ8VNkqzzua1mq94POlkMjIouSzjeZdlbdqljdJHx
LHR8leKuXs6qs92LWd90GZUgA41uhoCKS6snD/aWTkvjrYBbgI3uwKTkxawX4RkC3Pmw5vcCQGdP
qrv6VtYxRRfIpa8dRtnNncuykJrfFZhuVNed7sieta0EupO4k4LmULeT7CUOcQ42SIr5op9zuIrY
09d4UTCR3qgeQ+nFlGxZcYK6OjPz5mHkD14B2l6MbIj5symZj6OIVONcMiWJX1f5ZXRrAd3vEVKK
9cI0QlJTRY7cToGamd407m/m8uraiNgtsXWSrl5aefbZBqKmocLfwlRweqUCBxFdZPn8uYpEX5sJ
jj+CT5DRJyjDqLNGtvj7wW4+eiI4cXjIjOEOMIbOt4aXC/lJsAS+zwW/4owxyL4qdiHLc9mRHQJe
vy5Yrmq5ApiPtTOvwAKy19H7vPpKntBa9UcBPMUGokB59jo/f2UP2r4SIflFmv4loH4NjCcXCEBs
FVyYLEzE7Pl7e8DKXGSG+0qi6KS0yleKRqWU/BULs/a7QPvyU90emzrgvQ7jr+iqKXg34oZ9j1HN
9ySqQaQuZIorZGKNcPxFARijUL1sd4Y5j6rik1QPuZ7GvgDQHv+e/F6+pGTunBHpC5Fhr4jFsB+Q
U/UD4QkTKY5vo7MMQMfHwqv9C+0CU6wkOdVR6Rrl0INY4RJg8UZsEnFIIxXPF+SXd9MJdo9zdnv5
ylwpl3Dz06gR+b8CMZInBQx4kXLYCNAigp2rxw1XjmZFAg4kZnSfXlhps6HgBTtC8gVmYoSPB6oQ
zhgIx27fNN7K7xCFwLdO4WJHOa9KrDg+gikOtoBBYVQlN+zJuolZxhC2lxm9CZxSh6l1cap473Ca
gCH63e0mZsYXkcNTeX846L4G+wwy0/eCLqYWqgJ4DRh5YlGz0h7q66EnDr8LqaMSP2qjv2+RLK7d
fp0KL4S8/0+exZz/xWZI/wJDeOPGjrhMgIgqFs7Eaq8PhHvdfRWbkII13Z6Xm7wAh9+lMmWleFGO
9MC0zGBJ4j6M7AuRvxb2GwUyi6G8f6YoinGjqi3BHRNLvA+GcAg7q6W1fKsLj8Wf5TU0EtDeXrQN
Z1PH/xRdVHJbfCCyEN8h99yPCTiEwHlR2NP2BNQpgAM4v3HDuhYRxYCr+2RZko3J7gHe/jPMluyu
6EK0FEykiMwbk6rkALBt1jmbh3FfVy0edE2Sxe+yM2qu+MNus5OdynuCURfCmxC+DNZdKqbkRYR9
AHRl7cwzzyhaanwcKa2+O3EGGPbMc6Z/kW0uURPO9UJpPuLZYFxN9bQwCiPRFQl1S5C1Z/kuHJfK
N0CK718c/9vxr6byeuIBeP5/KAKSEgJO5SO3RPakhSNtmv08VW4s1efm7G6eCmZnNioNs4enKrPz
s7UAOqmZnUxz6QaxdfimGG5v2n2arYQml9rpg5it8A21wRw0q8ViMdmgH1PuieQBcN3X8uG45y5O
IjVDTbIaMTFFTewTkfEAP0pMThP7iH1PtyipRAb1dWMuCpgC6Qa4O+IbCiHpdAcicc7miObCgTXS
YDZGfUAetlgMnNT9iB7gq43xvmLnCPygnaSqlBNSwTLbqS0LTdJ8vjXOMpWQiF+8nN4tgxE2PDdi
psr3N391REvJP2DbsyPaMvbAli/QtUnWgIgPan4fI4nhi7V8fUTn0rsGJMMRMwYmFNvtjGjX7vYJ
2i6Oauf3sNkro5sVQHBoYfJDbP+9Ee2bHSD7QwVbV1il/Tf8z9/yP6/zPz9aWV3L/5B/f2mllP9r
coEQppJQ/LsVYMxVgK/+jjRgHWGq7cPv/SIFXgT1nCBi+ctjEyUR7k3AEqxQd8IO1WdPgkH3ZeQD
gpe7O0Efq0aJwHJluj75GOO10O1FN2cff/pG8cyNSMQgc7NubX4roHUEmjMGzeR8h4MXifyh8KCT
5UhJ8MwzV4OseIkx91d1blx0hQofQE88kMWLrVodJywzPxyr2Vou//+hUdih178Gkjn9WguarWx2
Kyi8AFxNsdPdAVkEE1FhTMM5OM6EwKIaz3l8ESP/Ip9dloho1cgjtAvNrzWrVjohHA+wRFWPM6CH
ABrGw73car8oRYu1fMeCEu3UV5957vRUZg3AIbtFc5l6BljtZ/x2bxn4j+fw99YAfz2Nv27ir5mp
DPyKWd/weQafPzWztIyDbsGmtnGw/JZg1bassAhg284pd6ZtETnRLzbr+ZfFH1twyfpFQauUzqQy
N4faPm7yev4tg9VEf0MYe48TLKiP9/B3FJwGCJ4y/4L2iLIp7L0JvUVyfENTZG1Ykb7/HaR6zpwk
XMmi4NX/+MnP4dTktyhSGYd0ToV0bEqbQT4YTwfvCvm9vjHcgPmH5AKe28u3GPaIt+2vJN0/5GGj
YOR+vhNpZ1viuFpaJDIgk1ZaRiw4yrdwRdE3uZbEahjHDJxJkLsc2ZmwMU7hb6NASYFVneiTMefr
zzzTU5N4PddTmSGwJ4GNt6N0ES9Hv9o4+pLD/HA2ar6jnXtb+/0t7feGASc6bPhSYxW7+2dQR3xT
y9AgRWeM/Yoir94XVYc5ffgDvZDEAcdNj9XHbS5A+DORxwj9nUgfdSA8IY04MsoR9qlwVrklPvEE
hKOk+hkpth8c35uCqyCXLpcaux0/N5VX1t24qO3jK9rvf3NGaLCRGQEEy87L6r6QjLS18nfZ3P+/
vWtpbuPKzlnnV4CwhtUtXkAAx4ptUCBKoqAhZyTaJjRyNCgUCQJNoG0QDXUDFCkAVVM1m2TlSrKY
Taq8zCKb1KRmJuNknKr5BdJfyC/JOeee++hGd4N0ZG9CLCR2931038c5557Hd1YCs0RT3U5BazMG
qKZoJfmp1IIjFXw180NYrU2zdeGVzJIdip+lT7PU9cn4XAqX/ebtP779bYH8gDgB9q/pj/+Q2T4Y
q+JPKppdhv2hqxDr9awweM4+zZNvYvhi81mWXk/W1KfEwn+nkbUoB7KEZ6FUyYiwxSGEfyAnNOlu
9jUDEMXb0qD1pKNcyfz9O5UrmxaOgv/CN/jWCjum2ML3SPIC5mlhedSdKswduBgP1IWEiin70RN/
7E89BR1DFVw37/l4gJY97oAUDl8uFhuBB9fKy6l6r8JLM95u6ct2pePeJUHis4N71Y8rsEzjTUOR
aqLIqE5XkT92hve23bt3t7ekKBJEDjZoF+aa+CjWc0oZbK8p21O6pur2Rx9uy6dAp8bbjiz4Csjx
yBXmolpC45N4GmMsD68nfw3rj7UJBBmL5grAMSxmIe0osoCUCWGo3sRqDg1m4hO4oUMCC2GjGStI
EgAW6SC6krS4yb+ULHCSKRXrLmB2yxEyJmckDqFRHt5Ddi9Jrw70J3RLXHS0vqhbay7FS3tUnzJf
3K/r0LJQ0dj9xQJmU/RjVOjnMI5vDCX6LWyvfyefzT8oJD9NTYgnGFgNQgA8QRlI1l4awhWjLnfm
qv1dVRTEJ3KzN4+U9JTGWDdUNalvG2barCZFd2cofUkOScvBZ3w6XBbFMP7hTHdjwCBCEpnECMhE
7Ohr+W9JUlys/cpxGydAPeX3EtLfNWExU2KuZWw13Cgg9hU1g6MEfSyl+efb8klt4zG7JDfxiGDO
fqsGZEwhAl1+XZBUneQByat1kHY6sso3lPyY3Iv/i5CSVG76d1/HlsRbyg6iTGMxkVaF19MquHnw
eBoi8GfKFjh0l2zaLAwLwVkh1KL9visXSbP+BEoBLVxr3xwl7JsjawGd8AJC++md+YWWHYGUAP15
qq+bjWLBj4zFn9YykfTDbN/UCWIo7BymrFfsrqROXEVxGFu3QbvZEYccsUXpBuo+3KLejtb1BpTh
KKtDJm5FcRTrb6ioHowMD/+hOHJxN6mjjxzy43WdH2f1PARBC/o9TvSr2zc9H7vLUb4NGfbFUDSB
5erFMnKXWh7f361rciWeeQzODCdM0bR9CEYqOImpaVMeMmCT2U78eMSRn36kjHIjZaVy2tio8urG
VI9fF3ONFsYtAI3+NzXIxe1xRyCQHJXR7HflJAQTLXMcLS37zcZwsUj/xuSIfA+j3HsEP/lBjHJk
sQqVsWqIJm+J49Jkg9wIDXLJ6I3Duppxjl/jAiuCYFkZ/dKkxLK2AbqNtikqrAedbK/SM89CF8c3
5QOP5d4cJlZySpEhCQ1qbQYelXveKJ92R6MgGDe0tuHz9Hv2iQvP+SBOEHuCltz5M+uUa53alMXd
PryZxxLwWcpvqOM8Rhd3D/7b3Dy23XNzZKPrafeQQTQXC+4HXlgc1kE6b2sJDGVhYa5A4O6IIyzS
bFS3a5/QiD53n1N0QVh+BjLop2IuMcBqh+JNEJzXjkRP4hBFtXYR7zAsUbEDK+1zrrk3goOLF0Iv
c4xl9GAz+NGoO+5HH/hj2MewLV/4QDSmXPAAA/WKoievHslZ4X32tHuFxv0iP/yA5ww2GRINNNih
k45FYdDl9nN09OnJ5GiwQs/86XMCNptI3crnVGgopexjJW5eApM9dsUBf8RnKLedd8OvHDWAx7EB
POYBnKO1iV+2RsL2pVK3jWGWFb9xQRzkb9jrkllnTeHT2DDsE/2pYfnEk0dIBU4e9P2LAjEkzYy4
mOaEu8k+HtyDSrsnW3DPUgXmtCQ5m2rHMEzVUg0dS+Nv9yQIcPWcPJACSSEmnWR0IwWPYsE2bKln
iDbO/ft9d5leSMGX35lfLovwgScJBQyP+4N78j12T5Zi7sNyUWutL714PqBsSEWBjw7wz/3QO6v1
cAr0rZb/xqu1tz8S2x9Zdz+l0PFau1T9afm+oH/tOsPuxKvNcShqxT0/7KHLWc8wwVq7IiodEXb7
/gw4KFReUuU9FI9qPr66JtAHZfI7itjTPCY5HItLoAkH6JFMbm57tJNJ3lkCg4hT27913I2kGmmx
SNWXPtjGsJME4I0kz1dKkbm56aEoCQQ0ov8tBI1foQPTuUVJz+Maa9TdgITxd1J1UqD4uu8McKYK
58QcC2tOHXyCyz58iJfGXcroXpmPpCGvfVEfojY2RL2srQxNfsSNjp6JUxieRJllxIcfyHvcfgP0
/ZnnNMVIDBmkzQV63u6I43pFub0weDlHu2Emsy7RPTn5l4r4HXCNfkCXL3SEmRGOZiAVsaewdtQa
wo6gCX+REh6mchNcuiLtsUxx0ITPctMeq4wHI3cn7bFEZ9Ax8gcMbgQHGFu0ewHkea071VKJSGoU
paQ08zjEH9bvh9ufSNn41FN42PCYmyahtniE4mzp4Rk6DMLLSJenoVfXNrShJ4zZbCspyjqnKFKd
eruVxqlX+5uKe5fsakI5TV1/UxThVAFvOlAjAS9qQAk24CrhkDXwFH4rem1/k7VIU1001SFsgjlV
y2UYibI0GQPd2arjF4gNuDfsRsdowl4sDnbrVe++ewpHqq+Wr4eYEHNDDbsW2Rkd7gWFbL5IBRA+
htaXSNlWKjOhIcMJ0J5jwp0Pa2ycPtK6MVz6TxykkG9YtXUpDtAT34ObJfj3wF0sHFSdXbqdrUvN
NmnxjzzEbemGHhU4gAIH+mDJsth+/eOK+CpGFUYNYPlN4vdMmu7MR8uTmrm74jP/ZwrEZb+4FI04
wTv8d8y59ru338YSwShtNy4U5TyrMWM13SFamKBiWpN2Yqm2rFdvnJBO3G7m3W8Kf/lX/Kq//CdC
pP9u5Qvk8jUql99T+SaWX5bvzI8bxcLbf1ExwpYOZZ2/sITtL8JoKhXWj8VESMFEmsI/84yRMUMm
BjCaojVKNOnw/CNbHYjkK3rpKCdu65SDx+kvmY4cZu41LIQ+0oiKZh+1/OjTCeo8aR//MLsMF+3z
zU0+B9pizpeiuu2yWgAax1e4TCUlSc4dU35q49W1daA/4vTZmNlfoCLPJarHpmNlNX6JeCvi0tOo
xiikQWkPyDH/GVkwKhi7NjGF/69yoXVK3ytHw+D1s6DfHXEalbhXbELaics6WeomJJsY/mBr/qFv
2cFLMmQrrwL9GNe67TdrL3h3uWMZ/8nKFa5B6yTvAFQfhMYnIDZe7sSjELbUhuIRallNoAFPqcaT
OSGomrEj8WhTnwKDHpYUd5Ib8DQz1v5L+a5pNWIf/R6CJaWyKjHtrLmKzz6x+lC+2Q2j1ZShMt4N
nknsGLTNzVnmc47eMQt6qp1zrBbUek3WE7Q00qz0Ln/QIDcmJdRTHItJcenWStjJxkAvk4HxOKlA
H6sTxVOkZjVuRpau5rxw91LekAEO+S1J3+wROlBq6ddDD0GqrHIJdMe0SmYQ7ObTUP/ULgrVeWYI
06CRSRlnvp2uUFAu7LGjrFHXjYx32Ah1DmQw0VGX1BhrrA5g96GhNbvEMzoRye6aqruUWQzh5BFM
EAGxO6CDCopmeNJ74jTR9rxRWQeYqyN280PUlOZTHp4umOyT6PoZw3c+Dyb1CvHZNWTzYTaNufBc
8ThOVfOI6gUGpaY1t35XrA4mUow1dHd/Cz/4pY7MOib/qoe2l0O4WBx7yPdIgVXXmIIWVcBalXR/
RqlRbMkblGFhin592kry8dKN6XFYAybIJsoWizcw//KkiVamosvafB3lwtQnQYw0FUKyLt4DzR4Z
SrzcSWMSxG3lwKKaXsbtPPRiKwfpi7LFhDdDwsA5bzDJ3ojTdNfQ5QRBtiJ22EyEPT+qz/1+je2g
gu09bAYVqNWtsU8dwQ5kkvHNzUdkQA3ajzhMpYO3sAFXGVkf2W6cqpj4ZV0W096bfJniwqmbZm2x
LPlDOGiqnpJ+mu/NQYkifBGSSe6uPZgg1rg9ZpIrPQFSocOx8tAd2r6YcCxRjC6kPRHaxuxYbdzK
6S6RGZ6U7EaZ5el4Mz+/uCPd+3L3uvAYHiHhXenm7L4dKdinLOUsB1kpq+hwsV+kQGoxAFF+MAUi
LJeikd9H5tSReH0pmmT/Ws3QfQrI6KUivyOoIQEW1wofflT+5JPQO3cJmsjyaw9ozR2gL7njnMHw
bKgJBjoXialcADOcM+SDLRvFKgYTEGHokJLHWyiK9QwAvIUFbyCmqBc4Ms2Ar2D4HBusIicdmgaI
YLACHxCpD08cCgbOeKvqCsoCssSMNmfilFPAjOFiSw/6T9RfnMAmaI87einhMLdwsgj10jDwC/EK
W7pQCxoTb4yXEpRfFZEFrgFvehHvjiaVEkARtL/bdc7dnStHYr9YHeBczc/yOfvAiU3vKdw6Xf04
DFhPeQcXxawsmOlpMOsNCSwUejpTUoI142dlKuJFJpK46s55OV0ZswMpleu6dLvS2ZkSXzoFlgFd
+me+F4pLuGQwbnGl/34phkHov8FlM6phHrYWHvnicnXmyzMauH73qb3/Tmn/6bfqMGHGWX9lvReZ
pn0WaE9v9tHnchd2TyNHf1tpWr7EgLSVJy/hCUNbwBTpj97cvNitbuO/sFxS+sFAYSpwvntxt1re
vu/atdFyL0EYrAbPGG6aDJtnq9KkNbzVvOH1xv21oyvl3f7zmwyy3c55PTZyMHCx8Up+nDupWwYI
IA1UQA6aHvFzd7d+v7K5ad/Qf1+4chSRujjnDyqNaq1UpX0ij4tXN1h/cpjV2U5P3XXqqx2uRtd8
1YNJypwBy145TqkzVIb86gOfbeU8D5TmZJX6XolKPvgR5cXwRyChqvPOFQKCrVG3WCPkil/klL7K
0ZDA93tAsj36uNxSqKmjttYlLsnOJdI+65BqCYhbPHeI/AidwMPD5B4JyDTJ31/7E69EuJAYl2vL
H7+8YUYH0WWsxnq7s0PCi0Mw/N16T1h8jXCFHSL6axMETMKgP+tNS9HQG53F8JwsgP9eRvYRrFOa
hhx+GeWXw/Usw61zi41BFJXIh4RNDeIKLRwrg+KYj/RPvbPpg20xTX+yNWYi8gWKTrv6GV2Wtpc7
OSkVZSynLP8IISKgvVop1qBJJqiFokYRA0y7IIBaCQYzVJA5/XzPbtLyLKkcJJMENcLVfkTpRvQ6
n5iVPJbqAykjzseZS0fO18gfxzHVrrCWPiR0UaaiaI0ro1LAik+hHjzrLhazDcYht8ucdvsDfD5b
IlXJWS/e+UQCkalTycY4rzi9bm0cTJ22rNBxJW4sa4l4EipWskg13PAmExTgAmknbq282MrY6Ojs
ldHJ1+TM6onBekINfZ/+Btje4DoC7AA49BURjZYj1e0TJ5uEaIgQ+AcRIdEqBh038hhAz1K4a98L
XJeDsdNTek4ZKAL97ns6vUIOFSvPBr0SYll5YQFds4MiZy2woxN6ObmiJAi7Hq9IiTpRMlVUpN5w
0p1F8rQaT/yEB9I4EZ73rE2VukPxzZXZZPUlItw80LHpcZzUA+PnD7ro1XNFw7+awAq7QFRUWT2N
T84iT9Gia7eugEvtDmKr5ToSgzpnJg6TbmIYx2oAcFka7okLZL6WwcU/wTTsKfbWzUINwJqaua3T
CGBhrRAQfl6bihFmYhFjIcUGMwGIsRDF0EicgMhORWtKnKOZUnLdqJFWoNcN+zS1mZ0wUjMw5Ipi
xuhWVE9G1WNYkzqFl6ryXohJmZyuxZbvwZXF3xaLqoswjz7MuWbjM3R2wV3cc3vxu6Z9AgR3x0kV
42yruizcQ9dIHcO0Y0lGA6GE7TPkLe4gZT6ZiphK8rxtLUFUnyBd7Sa5xpohGbju3e734e47fipd
NRyj5czgBANLan25LSw3XVMOxC3LyG02dTKhW4La6BWnAIJXRiibr0JZaeGe88GnRTWlwBK0K52V
JcoT1SgjAXISukGMGMhZ1MqwtGYcIm1Zwtayiw54VQ2YR1AqUF15mVk7UJR3nkZZVlYidjPQK/E6
06LI9MrMTBvZw73EycgzWyU/V8pxG+3iwzAMXuM2Lwr59xFmjIKL/eAcASmb436xY+IxB2j20smW
B6tH3pYsghpeaqFRqQ2MqaxfbJjdVZttmcJW3/Jwn/VNGRIyyR1X9dhm3UmRmrUvCo6AXdhNVF7d
CbO7V6l7YWcSO1Z2WQwzbO+LawPzd8NpaRp0JTaPzJfZ8qYiwBSPc3aWrEUimvUw8U9tShZQFn+0
OjymHQ6yUYZ1ZyVuOQkXF12jKvqHJ+tNG8X/+ed/AnK4URQ2pnmQTBttA7MvxU/vk864V5fS1Fo5
gd6CuaHZcSqnW+ROtUT+agYzhbD1UdvcvECZejw96HcWiwrndmMX29Wa7s7KlMns8eoDZrsVkTer
qiHr3DN7UM2tosCcYmM7S1ahAVFhmGYYJjgME8OLu+gL66R8vbtYTPCZddgwrxDLQU3OOSnHktNZ
dKXPJNn9SEY8Zu//SEyVODerR6vFUVqQbc00uSFygMnbe44x42ZitMWHUnnSy2wKfKR5CtTjtR96
DakBnWB4oP15+Kzm95XNu7WKyPnu12//KPE4U11D4WgjfRhb7WlHYQPMNIS0bzZ1euZpQqPgkGGy
7H1runz3G3b8k1mkMGfUn6QP6e/f/f27fyhQwpw/lotLkzu6i7CHHnyZHMPlcidTF0fDJtHmgH3g
hvSdSENgZ+vw5LKZIPpknyv2dEW1nXwvyldV6gzSkVokQI0y/GpsMiAV9cDYEhO8WOja8aVOM8y9
6XUWpaSDdKaiCO/5PNhD08uac5MSMq779lBvwm8/daGrvE8UZm/h7RZUxbfp2VrKQ2Q4yHUEJpYW
mJxYYC5agepLgdlIBaa+E5i/UWDaGIEwnOIOSj724Y5UoK0pzCWyaMylQ6fGnC9//OkzFT8HpXEJ
HHqso8eX2vnrv7r93f5uf7e/29/t7/Z3+7v93f5uf7e/29/t7/b3/+P3v2OBabYAEAQA
