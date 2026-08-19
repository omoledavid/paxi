<?php

use App\Http\Middleware\CheckPostNoDebit;
use App\Http\Middleware\DetectTransactionBurst;
use App\Http\Middleware\EnforceDailyTransactionLimit;
use App\Models\ApiConfig;
use App\Models\Epin;
use App\Models\EpinPrice;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;

uses(DatabaseTransactions::class);

const ADMIN_SECRET = 'manual-epin-test-secret';

beforeEach(function () {
    config(['services.admin_secret' => ADMIN_SECRET]);

    EpinPrice::updateOrCreate(
        ['network_id' => '01', 'amount' => 200],
        ['network_name' => 'MTN', 'user_price' => 200, 'agent_price' => 200, 'vendor_price' => 200]
    );
});

function manualAdd(array $payload)
{
    return test()->withHeader('X-Admin-Secret', ADMIN_SECRET)
        ->postJson('/api/admin/epin/manual-add', $payload);
}

/**
 * Tests run against the shared dev database, so fixed PIN values would collide
 * with real inventory and fail on the unique index. Each call mints its own.
 */
function newPin(string $grouping = '4-4-4-5'): string
{
    $parts = [];
    foreach (explode('-', $grouping) as $length) {
        $parts[] = str_pad((string) random_int(0, (10 ** (int) $length) - 1), (int) $length, '0', STR_PAD_LEFT);
    }

    return implode('-', $parts);
}

function slipPin(string $pinCode, array $overrides = []): array
{
    return array_merge([
        'pin_code' => $pinCode,
        'serial_number' => (string) random_int(10000000000000000, 99999999999999999),
        'network' => '01',
        'amount' => 200,
    ], $overrides);
}

function bareDigits(string $value): string
{
    return preg_replace('/[^0-9]/', '', $value);
}

/**
 * Take every other available MTN 200 pin out of the running so a purchase test
 * is sold the pin it just added rather than whatever real stock happens to sit
 * at the front of the queue.
 */
function reserveOtherMtnStock(): void
{
    Epin::whereIn('source', ['admin_bulk', 'admin_manual'])
        ->whereNull('user_id')
        ->where('network', '01')
        ->where('amount', 200)
        ->update(['status' => 'held-by-test']);
}

it('adds manually entered pins to inventory', function () {
    $first = newPin();
    $second = newPin();

    $response = manualAdd([
        'entry_method' => 'manual',
        'admin_id' => 7,
        'pins' => [slipPin($first), slipPin($second)],
    ]);

    $response->assertOk();
    expect($response->json('data.inserted'))->toBe(2);
    expect($response->json('data.batch_reference'))->toStartWith('MANUAL-');
    expect($response->json('data.skipped'))->toBe([]);

    // Slips print PINs hyphen-grouped; storage strips the decoration.
    $pin = Epin::where('pin_code', bareDigits($first))->first();
    expect($pin)->not->toBeNull();
    expect($pin->source)->toBe('admin_manual');
    expect($pin->entry_method)->toBe('manual');
    expect($pin->status)->toBe('unused');
    expect($pin->user_id)->toBeNull();
    expect($pin->transaction_id)->toBeNull();
    expect($pin->purchased_by_admin_id)->toBe(7);
    expect((float) $pin->purchase_cost)->toBe(200.0);
    expect($pin->batch_reference)->toBe($response->json('data.batch_reference'));
});

it('records the slip print date without treating it as an expiry', function () {
    $code = newPin('5-5-5');

    manualAdd([
        'entry_method' => 'scan',
        'pins' => [slipPin($code, ['printed_at' => '2022-03-19 12:12:00'])],
    ])->assertOk();

    $pin = Epin::where('pin_code', bareDigits($code))->first();
    expect($pin->printed_at->format('Y-m-d H:i'))->toBe('2022-03-19 12:12');
    expect($pin->expiry_date)->toBeNull();
    expect($pin->entry_method)->toBe('scan');
});

it('skips a pin repeated within the same submission', function () {
    $code = newPin();

    $response = manualAdd([
        'pins' => [
            slipPin($code),
            slipPin(str_replace('-', ' ', $code)),
        ],
    ]);

    $response->assertOk();
    expect($response->json('data.inserted'))->toBe(1);
    expect($response->json('data.skipped.0.reason'))->toContain('Duplicate of row 1');
    expect(Epin::where('pin_code', bareDigits($code))->count())->toBe(1);
});

it('skips a pin already held in inventory', function () {
    $held = newPin();
    $fresh = newPin();

    manualAdd(['pins' => [slipPin($held)]])->assertOk();

    $response = manualAdd(['pins' => [slipPin($held), slipPin($fresh)]]);

    $response->assertOk();
    expect($response->json('data.inserted'))->toBe(1);
    expect($response->json('data.skipped.0.reason'))->toBe('Already in inventory');
});

it('rejects a batch where every pin is already held', function () {
    $code = newPin();

    manualAdd(['pins' => [slipPin($code)]])->assertOk();
    manualAdd(['pins' => [slipPin($code)]])->assertStatus(409);
});

it('rejects a denomination that has no configured price', function () {
    $code = newPin();

    manualAdd(['pins' => [slipPin($code, ['amount' => 350])]])->assertStatus(422);

    expect(Epin::where('pin_code', bareDigits($code))->exists())->toBeFalse();
});

it('refuses to accept pins without the admin secret', function () {
    $this->postJson('/api/admin/epin/manual-add', [
        'pins' => [slipPin(newPin())],
    ])->assertStatus(403);
});

it('reports which pin codes are already in inventory', function () {
    $held = newPin();
    $absent = newPin();

    manualAdd(['pins' => [slipPin($held)]])->assertOk();

    $response = $this->withHeader('X-Admin-Secret', ADMIN_SECRET)
        ->postJson('/api/admin/epin/check-duplicates', [
            'pin_codes' => [$held, $absent],
        ]);

    $response->assertOk();
    expect($response->json('data.duplicates'))->toBe([$held]);
});

it('counts manual pins in the admin inventory stats', function () {
    $before = $this->withHeader('X-Admin-Secret', ADMIN_SECRET)
        ->getJson('/api/admin/epin/stats')->json('data.available');

    manualAdd(['pins' => [slipPin(newPin())]])->assertOk();

    $after = $this->withHeader('X-Admin-Secret', ADMIN_SECRET)
        ->getJson('/api/admin/epin/stats')->json('data.available');

    expect($after)->toBe($before + 1);
});

function buyOneManualPin(User $user, string $pinCode): void
{
    ApiConfig::updateOrCreate(['name' => 'epinSourceMode'], ['value' => 'database']);

    manualAdd(['pins' => [slipPin($pinCode)]])->assertOk();

    Sanctum::actingAs($user);

    test()->withoutMiddleware([
        CheckPostNoDebit::class,
        DetectTransactionBurst::class,
        EnforceDailyTransactionLimit::class,
    ])->postJson('/api/v1/nellobytes/epin/buy', [
        'mobile_network' => '01',
        'value' => 200,
        'quantity' => 1,
        'pin' => 1234,
    ])->assertOk();
}

it('sells a manually added pin when the source mode is database', function () {
    reserveOtherMtnStock();

    $code = newPin();
    $user = User::factory()->create(['sWallet' => 5000, 'sPin' => 1234]);

    buyOneManualPin($user, $code);

    $pin = Epin::where('pin_code', bareDigits($code))->first();
    expect($pin->user_id)->toBe($user->sId);
    expect($pin->disbursed_at)->not->toBeNull();
});

it('shows a manually added pin in the buyer history', function () {
    reserveOtherMtnStock();

    $code = newPin();
    $user = User::factory()->create(['sWallet' => 5000, 'sPin' => 1234]);

    buyOneManualPin($user, $code);

    $history = $this->getJson('/api/v1/nellobytes/epin/history');
    $history->assertOk();

    $entry = collect($history->json('data.data'))->firstWhere('pin_code', bareDigits($code));

    expect($entry)->not->toBeNull();
    expect($entry['network'])->toBe('MTN');
    expect($entry['status'])->toBe('unused');
    expect($entry['serial_number'])->not->toBeNull();
});

it('keeps inventory bookkeeping out of the buyer-facing history', function () {
    reserveOtherMtnStock();

    $code = newPin();
    $user = User::factory()->create(['sWallet' => 5000, 'sPin' => 1234]);

    buyOneManualPin($user, $code);

    $entry = collect($this->getJson('/api/v1/nellobytes/epin/history')->json('data.data'))
        ->firstWhere('pin_code', bareDigits($code));

    // What the business paid per pin, and which admin loaded it, are not the
    // buyer's business — purchase_cost discloses margin outright.
    foreach (['purchase_cost', 'purchased_by_admin_id', 'batch_reference', 'source', 'entry_method'] as $internal) {
        expect($entry)->not->toHaveKey($internal);
    }
});
