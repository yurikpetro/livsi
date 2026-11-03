<?php

namespace Tests\Feature;

use App\Filament\Resources\SellerProfiles\Pages\CreateSellerProfile;
use App\Filament\Resources\SellerProfiles\Pages\EditSellerProfile;
use App\Models\SellerProfile;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminSellerProfilesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    public function test_editing_preserves_leading_zeroes_and_all_digits_of_seller_details(): void
    {
        $profile = SellerProfile::where('code', 'ip')->firstOrFail();
        $details = $this->sellerDetails();

        Livewire::test(EditSellerProfile::class, ['record' => $profile->getRouteKey()])
            ->fillForm($details)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($details, $profile->fresh()->only(array_keys($details)));

        Livewire::test(EditSellerProfile::class, ['record' => $profile->getRouteKey()])
            ->assertFormSet($details)
            ->fillForm(['legal_name' => 'Новое наименование'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($details, $profile->fresh()->only(array_keys($details)));
    }

    public function test_creating_preserves_seller_details_as_strings(): void
    {
        $details = $this->sellerDetails();

        Livewire::test(CreateSellerProfile::class)
            ->fillForm([
                'code' => 'new-seller',
                'legal_name' => 'Новый продавец',
                ...$details,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $profile = SellerProfile::where('code', 'new-seller')->firstOrFail();

        $this->assertSame($details, $profile->only(array_keys($details)));
    }

    public function test_company_inn_and_ogrn_are_accepted(): void
    {
        $profile = SellerProfile::where('code', 'ip')->firstOrFail();
        $details = ['inn' => '0123456789', 'ogrn' => '0123456789012'];

        Livewire::test(EditSellerProfile::class, ['record' => $profile->getRouteKey()])
            ->fillForm($details)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($details, $profile->fresh()->only(array_keys($details)));
    }

    public function test_optional_seller_details_can_be_empty_and_cleared(): void
    {
        $profile = SellerProfile::where('code', 'ip')->firstOrFail();
        $emptyDetails = array_fill_keys(array_keys($this->sellerDetails()), null);

        Livewire::test(EditSellerProfile::class, ['record' => $profile->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $profile->update($this->sellerDetails());

        Livewire::test(EditSellerProfile::class, ['record' => $profile->getRouteKey()])
            ->fillForm(array_fill_keys(array_keys($emptyDetails), ''))
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($emptyDetails, $profile->fresh()->only(array_keys($emptyDetails)));
    }

    #[DataProvider('invalidSellerDetails')]
    public function test_invalid_seller_details_are_rejected_without_updating_the_profile(string $field, string $value): void
    {
        $profile = SellerProfile::where('code', 'ip')->firstOrFail();
        $before = $profile->getAttributes();

        Livewire::test(EditSellerProfile::class, ['record' => $profile->getRouteKey()])
            ->fillForm([$field => $value])
            ->call('save')
            ->assertHasFormErrors([$field]);

        $this->assertSame($before, $profile->fresh()->getAttributes());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidSellerDetails(): array
    {
        return [
            'inn too short' => ['inn', '123456789'],
            'inn intermediate length' => ['inn', '12345678901'],
            'inn too long' => ['inn', '1234567890123'],
            'inn exponent' => ['inn', '12345678e2'],
            'inn letter' => ['inn', '05470103155a'],
            'kpp too short' => ['kpp', '01234567'],
            'kpp too long' => ['kpp', '0123456789'],
            'kpp signed' => ['kpp', '+12345678'],
            'ogrn too short' => ['ogrn', '123456789012'],
            'ogrn intermediate length' => ['ogrn', '12345678901234'],
            'ogrn too long' => ['ogrn', '1234567890123456'],
            'ogrn exponent' => ['ogrn', '3.2126510005389E+14'],
            'ogrn decimal' => ['ogrn', '3212651000538.6'],
            'bic too short' => ['bank_bic', '04452522'],
            'bic too long' => ['bank_bic', '0445252250'],
            'bic exponent' => ['bank_bic', '1234567e2'],
            'account too short' => ['bank_account', '1234567890123456789'],
            'account too long' => ['bank_account', '123456789012345678901'],
            'account letter' => ['bank_account', '1234567890123456789a'],
            'correspondent account too short' => ['bank_corr_account', '1234567890123456789'],
            'correspondent account too long' => ['bank_corr_account', '123456789012345678901'],
            'correspondent account exponent' => ['bank_corr_account', '301018104000000002e5'],
        ];
    }

    /**
     * @return array{inn: string, kpp: string, ogrn: string, bank_bic: string, bank_account: string, bank_corr_account: string}
     */
    private function sellerDetails(): array
    {
        return [
            'inn' => '054701031550',
            'kpp' => '012345678',
            'ogrn' => '321265100053886',
            'bank_bic' => '044525225',
            'bank_account' => '01234567890123456789',
            'bank_corr_account' => '30101810400000000225',
        ];
    }
}
