<?php

namespace App\Delivery;

use App\Models\Order;

/**
 * Служба доставки, подключённая напрямую, без ApiShip.
 *
 * Деньги — в копейках, вес — в граммах, размеры посылки — в сантиметрах
 * (так их уже считает DeliveryQuoteService). Перевод в единицы конкретной
 * службы — забота её класса.
 *
 * Любая ошибка связи или отказ службы — исключение. Текст исключения
 * человеку не показывается: наверху его заменяют понятной фразой.
 */
interface Carrier
{
    /** Ключ службы, как он хранится в заказах и приходит с карты: cdek, yataxi, ozondel. */
    public function key(): string;

    /** Человеческое название: «СДЭК», «Яндекс Доставка», «Ozon Доставка». */
    public function title(): string;

    /** Заданы ли ключи доступа в .env. */
    public function isConfigured(): bool;

    /**
     * Все пункты выдачи по стране — для ночной синхронизации.
     *
     * Каждый элемент: external_id, type (pvz|postamat), name, address, city,
     * region, lat, lng, timetable, limits (массив или null).
     *
     * @return iterable<int, array<string, mixed>>
     */
    public function allPoints(): iterable;

    /**
     * Тарифы до пункта выдачи, от дешёвого к дорогому — выбирает вызывающий.
     *
     * @param  array{weight: int, length: int, width: int, height: int}  $parcel
     * @param  array<string, mixed>  $point  пункт в формате DeliveryPoint::toPublic()
     * @param  array<string, mixed>  $context  assessed (копейки), phone (для Ozon), items
     * @return list<array{tariff_id: string, tariff_name: string, cost: int, days_min: ?int, days_max: ?int, extra?: array}>
     */
    public function tariffs(array $parcel, array $point, array $context): array;

    /**
     * Создать отправление у службы для оплаченного заказа.
     *
     * @param  array<string, mixed>  $sender  name, phone, email
     * @return array{external_id: string, tracking: ?string, status_code: ?string, status_name: ?string, data: array}
     */
    public function createShipment(Order $order, array $sender): array;

    /**
     * Найти отправление заказа у службы, если ответ на создание потерялся.
     * Возвращает null, если служба такого заказа не знает.
     *
     * @return array{external_id: string, tracking: ?string, status_code: ?string, status_name: ?string, data: array}|null
     */
    public function findShipment(Order $order): ?array;

    /**
     * Текущее состояние отправления.
     *
     * fulfillment — статус доставки сайта (App\Support\Fulfillment), если
     * статус службы однозначно ему соответствует, иначе null.
     *
     * @return array{tracking: ?string, status_code: ?string, status_name: ?string, fulfillment: ?string, data: array}
     */
    public function shipmentInfo(Order $order): array;

    /**
     * Этикетка отправления — PDF как есть, от службы.
     *
     * size — рулон на принтере, «75x120» и т. п.: служба, которая умеет
     * разные размеры, отдаёт ближайший; остальные — свой стандартный.
     */
    public function label(Order $order, string $size = '75x120'): string;

    /** Отменить отправление у службы. */
    public function cancel(Order $order): void;
}
