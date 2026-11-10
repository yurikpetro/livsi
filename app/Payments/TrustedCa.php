<?php

namespace App\Payments;

use Throwable;

/**
 * Корневой сертификат Минцифры (Russian Trusted Root CA) для запросов к Точке.
 *
 * enter.tochka.com работает на сертификате Национального удостоверяющего
 * центра Минцифры. В обычном наборе корневых сертификатов (ОС, PHP) его нет,
 * и любой запрос к Точке падает: «cURL error 60: self-signed certificate in
 * certificate chain».
 *
 * Сертификат кладёт установщик (скачивает с Госуслуг и сверяет отпечаток)
 * в storage/app/certs. Доверие ему добавляется только запросам к Точке —
 * остальной сайт и сервер работают со своим обычным набором.
 *
 * Набор для Точки = системные корни + корень Минцифры: если банк когда-нибудь
 * вернётся на обычный сертификат, запросы не сломаются.
 */
final class TrustedCa
{
    public const ROOT = 'certs/russian_trusted_root_ca.pem';

    private const BUNDLE = 'certs/ca-bundle-tochka.pem';

    /** Есть ли сертификат Минцифры на сервере. */
    public static function installed(): bool
    {
        return is_readable(storage_path('app/'.self::ROOT));
    }

    /**
     * Значение опции verify для HTTP-клиента: путь к набору или true
     * (обычная проверка), если сертификата Минцифры нет.
     */
    public static function verify(): string|bool
    {
        return self::bundle(rootOnlyIsEnough: true);
    }

    /**
     * Для остальных провайдеров (CloudKassir — тоже банковская инфраструктура
     * и может перейти на сертификат Минцифры): только полный набор, иначе
     * обычная проверка — один корень Минцифры сломал бы им обычные сертификаты.
     */
    public static function combined(): string|bool
    {
        return self::bundle(rootOnlyIsEnough: false);
    }

    private static function bundle(bool $rootOnlyIsEnough): string|bool
    {
        $root = storage_path('app/'.self::ROOT);
        if (! is_readable($root)) {
            return true;
        }
        $system = self::systemBundle();
        if ($system === null) {
            return $rootOnlyIsEnough ? $root : true;
        }
        $bundle = storage_path('app/'.self::BUNDLE);
        try {
            if (! is_file($bundle) || filemtime($bundle) < max((int) filemtime($root), (int) filemtime($system))) {
                $tmp = $bundle.'.'.getmypid().'.tmp';
                file_put_contents($tmp, rtrim((string) file_get_contents($system))."\n".file_get_contents($root));
                rename($tmp, $bundle);
            }
        } catch (Throwable) {
            // Не смогли собрать набор (нет записи) — Точке хватит и одного корня Минцифры.
            return $rootOnlyIsEnough ? $root : true;
        }

        return is_readable($bundle) ? $bundle : ($rootOnlyIsEnough ? $root : true);
    }

    /** Системный набор корневых сертификатов, которым пользуется PHP. */
    private static function systemBundle(): ?string
    {
        $locations = function_exists('openssl_get_cert_locations') ? openssl_get_cert_locations() : [];
        $candidates = [
            ini_get('curl.cainfo'),
            ini_get('openssl.cafile'),
            $locations['default_cert_file'] ?? null,
            '/etc/ssl/certs/ca-certificates.crt',
            '/etc/pki/tls/certs/ca-bundle.crt',
            '/etc/ssl/cert.pem',
        ];
        foreach ($candidates as $path) {
            if (is_string($path) && $path !== '' && is_readable($path) && (int) filesize($path) > 10000) {
                return $path;
            }
        }

        return null;
    }
}
