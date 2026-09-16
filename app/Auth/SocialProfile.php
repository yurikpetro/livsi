<?php

namespace App\Auth;

/**
 * Что мы узнали о человеке у провайдера.
 *
 * Приводится к одному виду сразу на границе: дальше по коду не должно
 * быть видно, чьи это поля — `default_email` Яндекса или что-то своё
 * у VK, которое добавится следующим.
 */
final readonly class SocialProfile
{
    public function __construct(
        public string $provider,
        public string $id,
        public ?string $email,
        public ?string $name,
        public ?string $phone,
    ) {
    }
}
