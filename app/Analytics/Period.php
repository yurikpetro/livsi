<?php

namespace App\Analytics;

use Illuminate\Support\Carbon;

/**
 * Период отчёта по московскому времени: так считают и Метрика (часовой пояс
 * счётчика), и Вебмастер, и заказы в админке.
 */
final class Period
{
    public const TZ = 'Europe/Moscow';

    public const OPTIONS = [
        'today' => 'Сегодня',
        'yesterday' => 'Вчера',
        '7d' => '7 дней',
        '30d' => '30 дней',
        '90d' => '3 месяца',
        '365d' => 'Год',
    ];

    private function __construct(
        public readonly string $key,
        public readonly Carbon $from,
        public readonly Carbon $to,
    ) {}

    public static function make(string $key): self
    {
        $key = isset(self::OPTIONS[$key]) ? $key : '7d';
        $today = Carbon::now(self::TZ)->startOfDay();

        [$from, $to] = match ($key) {
            'today' => [$today, $today],
            'yesterday' => [$today->copy()->subDay(), $today->copy()->subDay()],
            default => [$today->copy()->subDays(((int) $key) - 1), $today],
        };

        return new self($key, $from, $to->copy()->endOfDay());
    }

    /** Вчера и сегодня — для ленты последних визитов. */
    public static function recent(): self
    {
        $today = Carbon::now(self::TZ)->startOfDay();

        return new self('recent', $today->copy()->subDay(), $today->copy()->endOfDay());
    }

    public function label(): string
    {
        return self::OPTIONS[$this->key] ?? 'Вчера и сегодня';
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to->copy()->startOfDay()) + 1;
    }

    public function date1(): string
    {
        return $this->from->format('Y-m-d');
    }

    public function date2(): string
    {
        return $this->to->format('Y-m-d');
    }

    /** Подпись «03.11–09.11.2026» или «09.11.2026». */
    public function range(): string
    {
        return $this->date1() === $this->date2()
            ? $this->from->format('d.m.Y')
            : $this->from->format('d.m').'–'.$this->to->format('d.m.Y');
    }

    /** Все даты периода — чтобы дни без визитов были видны нулём, а не пропадали. @return list<string> */
    public function dates(): array
    {
        $dates = [];
        for ($day = $this->from->copy(); $day->lte($this->to); $day->addDay()) {
            $dates[] = $day->format('Y-m-d');
        }

        return $dates;
    }
}
