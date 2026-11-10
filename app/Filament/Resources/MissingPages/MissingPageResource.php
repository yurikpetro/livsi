<?php

namespace App\Filament\Resources\MissingPages;

use App\Filament\Pages\Analytics\AnalyticsPage;
use App\Filament\Resources\MissingPages\Pages\ListMissingPages;
use App\Models\MissingPage;
use App\Models\SeoRedirect;
use App\Seo\LegacyMatcher;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * «Битые ссылки»: адреса, где человек или робот получил 404. Старые
 * ссылки из выдачи, соцсетей, с других сайтов. Одной кнопкой —
 * переадресация на нужную страницу (301), без программиста.
 */
class MissingPageResource extends Resource
{
    protected static ?string $model = MissingPage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLinkSlash;

    protected static string|\UnitEnum|null $navigationGroup = AnalyticsPage::GROUP;

    protected static ?int $navigationSort = 9;

    protected static ?string $navigationLabel = 'Битые ссылки';

    protected static ?string $modelLabel = 'битая ссылка';

    protected static ?string $pluralModelLabel = 'Битые ссылки';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $slug = 'analytics/broken-links';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** Сколько ссылок за неделю ведут в пустоту — в меню. */
    public static function getNavigationBadge(): ?string
    {
        $count = self::open()->where('last_seen_at', '>=', now()->subDays(7))->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /** Не скрытые и ещё не перенаправленные. */
    public static function open(): Builder
    {
        return MissingPage::query()->where('ignored', false)
            ->whereNotIn('path', SeoRedirect::query()->select('from_path'));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_seen_at', 'desc')
            ->description('Адреса, по которым люди или поисковые роботы попадали на страницу «не найдено». Нажмите «Перенаправить» — сайт будет вести этот адрес на нужную страницу. Старые ссылки прежнего магазина (/magazin/…, /product-category/…) сайт и так ведёт на товар или в каталог — их здесь нет.')
            ->columns([
                TextColumn::make('path')->label('Адрес')->searchable()->wrap()
                    ->url(fn (MissingPage $record): string => url($record->path), shouldOpenInNewTab: true)
                    ->description(fn (MissingPage $record): ?string => ($target = SeoRedirect::map()[$record->path] ?? null) ? '→ '.$target : null),
                TextColumn::make('hits')->label('Люди')->numeric()->sortable(),
                TextColumn::make('bot_hits')->label('Роботы')->numeric()->sortable(),
                TextColumn::make('last_seen_at')->label('Последний раз')->dateTime('d.m.Y H:i', 'Europe/Moscow')->sortable(),
                TextColumn::make('last_referer')->label('Откуда пришли')->limit(40)->placeholder('—')->toggleable(),
            ])
            ->filters([
                TernaryFilter::make('ignored')->label('Скрытые')->placeholder('Без скрытых')
                    ->trueLabel('Только скрытые')->falseLabel('Все')
                    ->queries(
                        true: fn (Builder $q) => $q->where('ignored', true),
                        false: fn (Builder $q) => $q,
                        blank: fn (Builder $q) => $q->where('ignored', false),
                    ),
                TernaryFilter::make('redirected')->label('Переадресация')->placeholder('Все')->default(false)
                    ->trueLabel('Уже перенаправлены')->falseLabel('Ещё не перенаправлены')
                    ->queries(
                        true: fn (Builder $q) => $q->whereIn('path', SeoRedirect::query()->select('from_path')),
                        false: fn (Builder $q) => $q->whereNotIn('path', SeoRedirect::query()->select('from_path')),
                        blank: fn (Builder $q) => $q,
                    ),
            ])
            ->recordActions([
                Action::make('redirect')->label('Перенаправить')->icon(Heroicon::OutlinedArrowRight)
                    ->modalHeading(fn (MissingPage $record): string => 'Куда вести '.$record->path.'?')
                    ->fillForm(fn (MissingPage $record): array => [
                        'to_url' => SeoRedirect::map()[$record->path] ?? LegacyMatcher::suggest($record->path),
                    ])
                    ->schema([
                        TextInput::make('to_url')->label('На какую страницу')->required()->maxLength(500)
                            ->helperText('Адрес на этом сайте, например /catalog или /product/multipenka-dlya-ruk-i-stop. Подставлено самое похожее.')
                            ->rule(fn (MissingPage $record) => function (string $attribute, $value, $fail) use ($record): void {
                                if (SeoRedirect::normalize((string) $value) === $record->path) {
                                    $fail('Адрес не может вести сам на себя.');
                                }
                            }),
                    ])
                    ->action(function (MissingPage $record, array $data): void {
                        SeoRedirect::query()->updateOrCreate(['from_path' => $record->path], ['to_url' => self::target($data['to_url'])]);
                        Notification::make()->title('Готово: '.$record->path.' ведёт на '.self::target($data['to_url']))->success()->send();
                    }),
                Action::make('ignore')->label(fn (MissingPage $record): string => $record->ignored ? 'Вернуть' : 'Скрыть')
                    ->icon(Heroicon::OutlinedEyeSlash)->color('gray')
                    ->action(fn (MissingPage $record) => $record->update(['ignored' => ! $record->ignored])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('ignoreMany')->label('Скрыть выбранные')->icon(Heroicon::OutlinedEyeSlash)
                        ->action(fn (Collection $records) => $records->each->update(['ignored' => true])),
                    BulkAction::make('suggestMany')->label('Перенаправить по подсказке')->icon(Heroicon::OutlinedArrowRight)
                        ->requiresConfirmation()
                        ->modalDescription('Каждый выбранный адрес будет вести на похожий товар, а если похожего нет — в каталог.')
                        ->action(function (Collection $records): void {
                            $records->each(fn (MissingPage $record) => SeoRedirect::query()->updateOrCreate(
                                ['from_path' => $record->path], ['to_url' => LegacyMatcher::suggest($record->path)]));
                            Notification::make()->title('Перенаправлено: '.$records->count())->success()->send();
                        }),
                ]),
            ]);
    }

    /** Адрес на этом сайте — путём, чтобы не зависеть от домена. */
    public static function target(string $value): string
    {
        $value = trim($value);
        $host = parse_url($value, PHP_URL_HOST);
        $ours = parse_url((string) config('app.url'), PHP_URL_HOST);
        if ($host !== null && preg_replace('/^www\./', '', (string) $host) === preg_replace('/^www\./', '', (string) $ours)) {
            $value = (string) (parse_url($value, PHP_URL_PATH) ?: '/').(($q = parse_url($value, PHP_URL_QUERY)) ? '?'.$q : '');
        }

        return $host === null ? '/'.ltrim($value, '/') : $value;
    }

    public static function getPages(): array
    {
        return ['index' => ListMissingPages::route('/')];
    }
}
