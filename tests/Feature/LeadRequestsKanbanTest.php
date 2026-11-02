<?php

namespace Tests\Feature;

use App\Filament\Resources\LeadRequests\LeadRequestResource;
use App\Filament\Resources\LeadRequests\Pages\ListLeadRequests;
use App\Models\LeadRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LeadRequestsKanbanTest extends TestCase
{
    use RefreshDatabase;

    public function test_switching_from_new_requests_shows_all_status_columns_and_remembers_the_view(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $new = $this->createLead(['name' => 'Новый партнёр']);
        $working = $this->createLead(['name' => 'Ведём переговоры', 'status' => LeadRequest::STATUS_IN_PROGRESS]);
        $done = $this->createLead(['name' => 'Согласованный партнёр', 'status' => LeadRequest::STATUS_DONE]);
        $rejected = $this->createLead(['name' => 'Отказавшийся партнёр', 'status' => LeadRequest::STATUS_REJECTED]);

        $page = Livewire::test(ListLeadRequests::class)
            ->assertSet('activeTab', 'new')
            ->call('setDisplayMode', 'kanban')
            ->assertSet('activeTab', 'all')
            ->assertSee('Новый партнёр')
            ->assertSee('Ведём переговоры')
            ->assertSee('Согласованный партнёр')
            ->assertSee('Отказавшийся партнёр')
            ->assertSee(LeadRequestResource::getUrl('edit', ['record' => $working]), false);

        $columns = $page->instance()->getKanbanColumns();
        $this->assertSame([$new->id], $columns['new']['records']->modelKeys());
        $this->assertSame([$working->id], $columns['in_progress']['records']->modelKeys());
        $this->assertSame([$done->id], $columns['done']['records']->modelKeys());
        $this->assertSame([$rejected->id], $columns['rejected']['records']->modelKeys());
        $this->assertSame('kanban', session('lead-requests.layout'));

        Livewire::test(ListLeadRequests::class)
            ->assertSet('displayMode', 'kanban')
            ->assertSet('activeTab', 'all')
            ->call('setDisplayMode', 'table')
            ->assertSet('displayMode', 'table')
            ->assertCanSeeTableRecords([$new, $working, $done, $rejected]);

        $this->assertSame('table', session('lead-requests.layout'));
    }

    public function test_kanban_uses_the_same_type_tabs_search_and_filters_as_the_table(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $match = $this->createLead(['type' => LeadRequest::TYPE_CONTRACT, 'name' => 'Искомый клиент', 'status' => LeadRequest::STATUS_IN_PROGRESS]);
        $this->createLead(['name' => 'Искомый оптовик', 'status' => LeadRequest::STATUS_IN_PROGRESS]);
        $new = $this->createLead(['type' => LeadRequest::TYPE_CONTRACT, 'name' => 'Искомый новый клиент']);
        $this->createLead(['type' => LeadRequest::TYPE_CONTRACT, 'name' => 'Другой клиент', 'status' => LeadRequest::STATUS_IN_PROGRESS]);

        Livewire::test(ListLeadRequests::class)
            ->set('activeTab', 'contract')
            ->searchTable('Искомый')
            ->call('setDisplayMode', 'kanban')
            ->assertSet('activeTab', 'contract')
            ->assertSee('Искомый клиент')
            ->assertDontSee('Искомый оптовик')
            ->assertSee('Искомый новый клиент')
            ->assertDontSee('Другой клиент')
            ->call('setDisplayMode', 'table')
            ->assertCanSeeTableRecords([$match, $new]);
    }

    public function test_contact_search_and_type_filter_work_in_kanban(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->createLead(['name' => 'Подходящий оптовик', 'contact' => '@matching']);
        $this->createLead(['name' => 'Другое производство', 'contact' => '@matching', 'type' => LeadRequest::TYPE_CONTRACT]);

        Livewire::test(ListLeadRequests::class)
            ->call('setDisplayMode', 'kanban')
            ->searchTable('@matching')
            ->filterTable('type', LeadRequest::TYPE_WHOLESALE)
            ->assertSee('Подходящий оптовик')
            ->assertDontSee('Другое производство');
    }

    public function test_kanban_applies_type_filter_immediately_and_shows_all_types_after_reset(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->createLead(['name' => 'Оптовая заявка', 'status' => LeadRequest::STATUS_DONE]);
        $this->createLead(['name' => 'Заявка производства', 'type' => LeadRequest::TYPE_CONTRACT]);

        Livewire::test(ListLeadRequests::class)
            ->call('setDisplayMode', 'kanban')
            ->set('tableFilters.type.value', LeadRequest::TYPE_WHOLESALE)
            ->assertSee('Оптовая заявка')
            ->assertDontSee('Заявка производства')
            ->assertDontSee('Применить фильтры')
            ->set('tableFilters.type.value', LeadRequest::TYPE_CONTRACT)
            ->assertSee('Заявка производства')
            ->assertDontSee('Оптовая заявка')
            ->set('tableFilters.type.value', '')
            ->assertSee('Оптовая заявка')
            ->assertSee('Заявка производства');
    }

    public function test_old_status_filter_does_not_hide_cards_after_its_removal(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->createLead(['name' => 'Снова видимая новая заявка']);
        $this->createLead(['name' => 'Снова видимая обработанная заявка', 'status' => LeadRequest::STATUS_DONE]);

        Livewire::test(ListLeadRequests::class)
            ->call('setDisplayMode', 'kanban')
            ->set('tableFilters.status.values', [LeadRequest::STATUS_REJECTED])
            ->assertSee('Снова видимая новая заявка')
            ->assertSee('Снова видимая обработанная заявка');
    }

    public function test_unknown_view_cannot_replace_saved_preference(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        session()->put('lead-requests.layout', 'table');

        Livewire::test(ListLeadRequests::class)
            ->call('setDisplayMode', 'unknown')
            ->assertStatus(422);

        $this->assertSame('table', session('lead-requests.layout'));
    }

    public function test_empty_columns_remain_visible(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        Livewire::test(ListLeadRequests::class)
            ->call('setDisplayMode', 'kanban')
            ->assertSee('Новая')
            ->assertSee('В работе')
            ->assertSee('Обработана')
            ->assertSee('Отклонена')
            ->assertSee('Нет заявок');
    }

    public function test_each_column_loads_twenty_newest_requests_then_can_load_more(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $oldest = $this->createLead(['name' => 'Самая старая заявка', 'created_at' => now()->subDay()]);

        for ($index = 1; $index <= 20; $index++) {
            $this->createLead(['name' => 'Партнёр '.$index]);
        }

        Livewire::test(ListLeadRequests::class)
            ->call('setDisplayMode', 'kanban')
            ->assertSee('Показать ещё')
            ->assertDontSee($oldest->name)
            ->call('loadMoreKanbanRecords', 'new')
            ->assertSee($oldest->name)
            ->assertDontSee('Показать ещё');
    }

    public function test_kanban_escapes_customer_fields(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->createLead([
            'name' => '<script>alert(1)</script>',
            'contact' => '<img src=x onerror=alert(2)>',
            'city' => '<svg onload=alert(3)>',
            'sales_format' => '<script>alert(4)</script>',
        ]);

        Livewire::test(ListLeadRequests::class)
            ->call('setDisplayMode', 'kanban')
            ->assertSee('<script>alert(1)</script>')
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('<img src=x onerror=alert(2)>')
            ->assertDontSee('<img src=x onerror=alert(2)>', false)
            ->assertSee('<svg onload=alert(3)>')
            ->assertDontSee('<svg onload=alert(3)>', false)
            ->assertSee('<script>alert(4)</script>')
            ->assertDontSee('<script>alert(4)</script>', false);
    }

    public function test_view_url_overrides_saved_preference_and_preserves_explicit_tab(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        session()->put('lead-requests.layout', 'table');
        $this->createLead(['name' => 'Новая по ссылке']);
        $this->createLead(['name' => 'Обработанная скрыта', 'status' => LeadRequest::STATUS_DONE]);

        $this->get('/admin/lead-requests?view=kanban&tab=new')
            ->assertSee('Заявки по статусам')
            ->assertSee('Новая по ссылке')
            ->assertDontSee('Обработанная скрыта');
    }

    public function test_unknown_view_falls_back_to_table(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $this->get('/admin/lead-requests?view=unknown')
            ->assertOk()
            ->assertDontSee('Заявки по статусам');
    }

    public function test_kanban_redirects_guests_to_admin_login(): void
    {
        $this->get('/admin/lead-requests?view=kanban')->assertRedirect('/admin/login');
    }

    public function test_kanban_forbids_customers(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        $this->get('/admin/lead-requests?view=kanban')->assertForbidden();
    }

    /** @return array<string, array{string, int}> */
    public static function targetStatuses(): array
    {
        return [
            'same column' => ['new', 1],
            'in progress' => ['in_progress', 0],
            'done' => ['done', 0],
            'rejected' => ['rejected', 0],
        ];
    }

    #[DataProvider('targetStatuses')]
    public function test_moving_a_card_saves_only_status_and_refreshes_column_counts(string $status, int $newCount): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $lead = $this->createLead(['manager_comment' => 'Ждёт звонка', 'city' => 'Москва']);
        $original = $lead->only(['name', 'contact', 'city', 'manager_comment']);
        $consentAt = $lead->consent_at->toIso8601String();

        $page = Livewire::test(ListLeadRequests::class)
            ->call('setDisplayMode', 'kanban')
            ->call('moveKanbanRecord', $lead->id, $status)
            ->assertHasNoErrors();

        $this->assertSame($status, $lead->fresh()->status);
        $this->assertSame($original, $lead->fresh()->only(array_keys($original)));
        $this->assertSame($consentAt, $lead->fresh()->consent_at->toIso8601String());
        $columns = $page->instance()->getKanbanColumns();
        $this->assertSame([$lead->id], $columns[$status]['records']->modelKeys());
        $this->assertSame($newCount, $columns['new']['total']);
        $this->assertSame((string) $newCount, $page->instance()->getCachedTabs()['new']->getBadge());
    }

    public function test_moved_status_is_visible_after_reopening_the_board_and_table(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $lead = $this->createLead();

        Livewire::test(ListLeadRequests::class)
            ->call('setDisplayMode', 'kanban')
            ->call('moveKanbanRecord', $lead->id, LeadRequest::STATUS_DONE);

        $page = Livewire::test(ListLeadRequests::class);
        $this->assertSame([$lead->id], $page->instance()->getKanbanColumns()['done']['records']->modelKeys());
        $page->call('setDisplayMode', 'table')->assertCanSeeTableRecords([$lead]);
        $this->assertDatabaseHas('lead_requests', ['id' => $lead->id, 'status' => 'done']);
    }

    public function test_sortable_handler_uses_target_stage_instead_of_item_position(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $lead = $this->createLead();

        Livewire::test(ListLeadRequests::class)
            ->call('moveSortedKanbanRecord', $lead->id, 0, LeadRequest::STATUS_DONE)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('lead_requests', ['id' => $lead->id, 'status' => 'done']);
    }

    /** @return array<string, array{string}> */
    public static function invalidStatuses(): array
    {
        return ['unknown' => ['deleted'], 'empty' => ['']];
    }

    #[DataProvider('invalidStatuses')]
    public function test_invalid_destination_does_not_change_the_request(string $status): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $lead = $this->createLead();

        Livewire::test(ListLeadRequests::class)
            ->call('moveKanbanRecord', $lead->id, $status)
            ->assertHasErrors(['status']);

        $this->assertDatabaseHas('lead_requests', ['id' => $lead->id, 'status' => 'new']);
    }

    public function test_customers_cannot_move_requests(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $lead = $this->createLead();

        Livewire::test(ListLeadRequests::class)
            ->call('moveKanbanRecord', $lead->id, LeadRequest::STATUS_DONE)
            ->assertForbidden();

        $this->assertDatabaseHas('lead_requests', ['id' => $lead->id, 'status' => 'new']);
    }

    public function test_record_edit_policy_is_checked_before_moving(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Gate::policy(LeadRequest::class, KanbanLeadRequestPolicy::class);
        $lead = $this->createLead();

        Livewire::test(ListLeadRequests::class)
            ->call('moveKanbanRecord', $lead->id, LeadRequest::STATUS_DONE)
            ->assertForbidden();

        $this->assertDatabaseHas('lead_requests', ['id' => $lead->id, 'status' => 'new']);
    }

    public function test_deleted_request_cannot_be_moved(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $lead = $this->createLead();
        $lead->delete();

        $this->expectException(ModelNotFoundException::class);

        Livewire::test(ListLeadRequests::class)
            ->call('moveKanbanRecord', $lead->id, LeadRequest::STATUS_DONE);
    }

    /** @param array<string, mixed> $attributes */
    private function createLead(array $attributes = []): LeadRequest
    {
        return LeadRequest::create(array_merge([
            'type' => LeadRequest::TYPE_WHOLESALE,
            'status' => LeadRequest::STATUS_NEW,
            'name' => 'Партнёр',
            'contact' => '@partner',
            'consent_at' => now(),
        ], $attributes));
    }
}

class KanbanLeadRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function update(User $user, LeadRequest $lead): bool
    {
        return false;
    }
}
