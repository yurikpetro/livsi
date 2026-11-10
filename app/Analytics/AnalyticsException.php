<?php

namespace App\Analytics;

use RuntimeException;

/** Понятная владельцу причина, почему блок аналитики пуст. Текст показывается на странице как есть. */
class AnalyticsException extends RuntimeException {}
