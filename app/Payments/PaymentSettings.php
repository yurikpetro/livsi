<?php

namespace App\Payments;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Настройки приёма оплаты из админки («Интеграции оплаты»).
 *
 * Кто принимает деньги — ЮKassa или Точка Банк — переключается здесь же,
 * без выката: ключи ЮKassa остаются в .env, ключи Точки и CloudKassir
 * вводит владелец. Пока переключатель не тронут, работает ЮKassa, как раньше.
 *
 * Секреты (JWT Точки, API Secret CloudKassir) хранятся одной записью,
 * зашифрованной ключом приложения. В форму они не возвращаются — только
 * «задан / не задан».
 */
final class PaymentSettings
{
    public const YOOKASSA = 'yookassa';

    public const TOCHKA = 'tochka';

    /** Способы оплаты по ссылке Точки и как их назвать владельцу. */
    public const TOCHKA_MODES = [
        'sbp' => 'СБП (QR-код и банковские приложения)',
        'card' => 'Банковская карта',
        'tinkoff' => 'T-Pay',
        'dolyame' => 'Долями',
    ];

    /** Системы налогообложения: код Точки → код CloudKassir. */
    public const TAX_SYSTEMS = [
        'osn' => ['label' => 'ОСН — общая', 'cloudkassir' => 0],
        'usn_income' => ['label' => 'УСН «доходы»', 'cloudkassir' => 1],
        'usn_income_outcome' => ['label' => 'УСН «доходы минус расходы»', 'cloudkassir' => 2],
        'esn' => ['label' => 'ЕСХН', 'cloudkassir' => 4],
        'patent' => ['label' => 'Патент', 'cloudkassir' => 5],
    ];

    private const SECRETS = ['tochka_jwt', 'cloudkassir_secret'];

    public static function provider(): string
    {
        return Setting::get('payment_provider') === self::TOCHKA ? self::TOCHKA : self::YOOKASSA;
    }

    public static function setProvider(string $provider): void
    {
        Setting::put('payment_provider', $provider === self::TOCHKA ? self::TOCHKA : self::YOOKASSA);
    }

    public static function providerLabel(?string $provider = null): string
    {
        return ($provider ?? self::provider()) === self::TOCHKA ? 'Точка Банк' : 'ЮKassa';
    }

    // ---------------------------------------------------------------- секреты

    public static function secret(string $key): ?string
    {
        $value = self::secrets()[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Записать секреты; пустое значение — оставить как было. @param array<string, ?string> $values */
    public static function setSecrets(array $values): void
    {
        $all = self::secrets();
        foreach (self::SECRETS as $key) {
            // JWT копируют из кабинета с переносами строк и пробелами по краям.
            $value = preg_replace('/\s+/', '', (string) ($values[$key] ?? ''));
            if ($value !== '') {
                $all[$key] = $value;
            }
        }
        Setting::put('payment_credentials', Crypt::encryptString(json_encode($all, JSON_THROW_ON_ERROR)));
    }

    /** @return array<string, string> */
    private static function secrets(): array
    {
        $raw = Setting::get('payment_credentials');
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        try {
            $data = json_decode(Crypt::decryptString($raw), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    // ------------------------------------------------------- обычные параметры

    public static function option(string $key, mixed $default = null): mixed
    {
        $value = ((array) Setting::get('payment_options', []))[$key] ?? null;

        return $value === null || $value === '' || $value === [] ? $default : $value;
    }

    /** @param array<string, mixed> $values */
    public static function setOptions(array $values): void
    {
        Setting::put('payment_options', array_merge((array) Setting::get('payment_options', []), $values));
    }

    public static function customerCode(): ?string
    {
        $value = preg_replace('/\D/', '', (string) self::option('tochka_customer_code', ''));

        return $value !== '' ? $value : null;
    }

    public static function merchantId(): ?string
    {
        $value = preg_replace('/\D/', '', (string) self::option('tochka_merchant_id', ''));

        return $value !== '' ? $value : null;
    }

    public static function clientId(): ?string
    {
        $value = trim((string) self::option('tochka_client_id', ''));

        return $value !== '' ? $value : null;
    }

    /** @return list<string> */
    public static function paymentModes(): array
    {
        $modes = array_values(array_intersect(array_keys(self::TOCHKA_MODES), (array) self::option('tochka_modes', [])));

        return $modes ?: ['sbp', 'card'];
    }

    public static function taxSystem(): ?string
    {
        $value = self::option('tax_system');

        return is_string($value) && isset(self::TAX_SYSTEMS[$value]) ? $value : null;
    }

    public static function cloudKassirPublicId(): ?string
    {
        $value = trim((string) self::option('cloudkassir_public_id', ''));

        return $value !== '' ? $value : null;
    }

    public static function inn(): ?string
    {
        $value = preg_replace('/\D/', '', (string) self::option('cloudkassir_inn', ''));

        return $value !== '' ? $value : null;
    }

    /**
     * Чеки возврата пробивает сайт (через CloudKassir).
     *
     * Выключено по умолчанию: если связка Точка — CloudKassir сама пробивает
     * чек возврата, второй такой же от сайта задвоил бы возврат в налоговой.
     * Включают, когда проверено, что связка этого не делает (тестовый возврат
     * 10 ₽ на этой же странице).
     */
    public static function siteRefundReceipts(): bool
    {
        return (bool) self::option('cloudkassir_refund_receipts', false);
    }

    /** Все секреты — чтобы вычистить их из текста ошибок. @return list<string> */
    public static function allSecrets(): array
    {
        try {
            return array_values(array_filter(self::secrets(), fn ($value) => is_string($value) && $value !== ''));
        } catch (Throwable) {
            return [];
        }
    }
}
