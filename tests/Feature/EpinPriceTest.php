<?php

use App\Models\EpinPrice;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

beforeEach(function () {
    // Ensure epin_prices table has test data
    $networks = [
        ['id' => '01', 'name' => 'MTN'],
        ['id' => '02', 'name' => 'Glo'],
        ['id' => '03', 'name' => '9Mobile'],
        ['id' => '04', 'name' => 'Airtel'],
    ];
    $amounts = [100, 200, 500];

    foreach ($networks as $network) {
        foreach ($amounts as $amount) {
            EpinPrice::updateOrCreate(
                ['network_id' => $network['id'], 'amount' => $amount],
                [
                    'network_name' => $network['name'],
                    'user_price' => $amount * 1.0,
                    'agent_price' => $amount * 0.98,
                    'vendor_price' => $amount * 0.96,
                ]
            );
        }
    }
});

it('can retrieve all epin prices', function () {
    $prices = EpinPrice::all();
    expect($prices)->toHaveCount(12); // 4 networks x 3 amounts
});

it('can retrieve prices for a specific network', function () {
    $prices = EpinPrice::forNetwork('01');
    expect($prices)->toHaveCount(3);
    expect($prices->first()->network_name)->toBe('MTN');
});

it('returns correct user price via getPayablePrice', function () {
    $price = EpinPrice::getPayablePrice('01', 100, 0);
    expect($price)->toBe(100.0);
});

it('returns correct agent price via getPayablePrice', function () {
    $price = EpinPrice::getPayablePrice('01', 100, 2);
    expect($price)->toBe(98.0);
});

it('returns correct vendor price via getPayablePrice', function () {
    $price = EpinPrice::getPayablePrice('01', 100, 3);
    expect($price)->toBe(96.0);
});

it('falls back to face value when record not found', function () {
    $price = EpinPrice::getPayablePrice('99', 100, 0);
    expect($price)->toBe(100.0);
});

it('returns correct discount rate', function () {
    $rate = EpinPrice::getDiscountRate('01', 100, 2);
    expect($rate)->toBe(0.98);
});

it('can update epin price', function () {
    $record = EpinPrice::where('network_id', '02')->where('amount', 200)->first();
    $record->update(['user_price' => 195.0]);
    $record->refresh();
    expect($record->user_price)->toBe(195.0);
});

it('enforces unique constraint on network_id and amount', function () {
    EpinPrice::create([
        'network_id' => '01',
        'network_name' => 'MTN',
        'amount' => 100,
        'user_price' => 99,
        'agent_price' => 97,
        'vendor_price' => 95,
    ]);
})->throws(\Illuminate\Database\QueryException::class);

it('casts numeric fields correctly', function () {
    $record = EpinPrice::where('network_id', '04')->where('amount', 500)->first();
    expect($record->amount)->toBeInt();
    expect($record->user_price)->toBeFloat();
    expect($record->agent_price)->toBeFloat();
    expect($record->vendor_price)->toBeFloat();
});

it('differentiates prices per role for same network and amount', function () {
    $user = EpinPrice::getPayablePrice('03', 500, 0);
    $agent = EpinPrice::getPayablePrice('03', 500, 2);
    $vendor = EpinPrice::getPayablePrice('03', 500, 3);

    expect($user)->toBe(500.0);
    expect($agent)->toBe(490.0);
    expect($vendor)->toBe(480.0);
});
