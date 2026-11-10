<?php declare(strict_types=1);

use AIAccess\Mcp\CostMeter;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


$prices = [
	'model' => [['input' => 2.0, 'output' => 10.0]],
	'dated' => [
		['until' => '2026-12-31', 'input' => 1.0],
		['input' => 2.0],
	],
	'expired' => [['until' => '2026-01-01', 'input' => 1.0]],
];


test('the cost is the sum of the kinds of tokens', function () use ($prices) {
	$meter = new CostMeter($prices);
	Assert::same(['cost' => 0.0102, 'sessionCost' => 0.0102], $meter->record('model', ['input' => 100, 'output' => 1000]));
});


test('the costs add up, unknown ones are left out', function () use ($prices) {
	$meter = new CostMeter($prices);
	$meter->record('model', ['input' => 1_000_000]);
	Assert::same(['cost' => null, 'sessionCost' => 2.0], $meter->record('unknown', ['input' => 5]));
	Assert::same(['cost' => 4.0, 'sessionCost' => 6.0], $meter->record('model', ['input' => 2_000_000]));
});


test('the first period that has not passed applies', function () use ($prices) {
	Assert::same(1.0, new CostMeter($prices, '2026-12-31')->record('dated', ['input' => 1_000_000])['cost']);
	Assert::same(2.0, new CostMeter($prices, '2027-01-01')->record('dated', ['input' => 1_000_000])['cost']);
});


test('the cost is unknown without a valid period, a price or a usage', function () use ($prices) {
	$meter = new CostMeter($prices, '2026-11-09');
	Assert::null($meter->record('expired', ['input' => 1])['cost']);
	Assert::null($meter->record('model', ['other' => 1])['cost']);
	Assert::null($meter->record('model', null)['cost']);
});


test('a dated snapshot of a model uses the prices of the model, an exact entry wins', function () {
	$meter = new CostMeter([
		'model' => [['input' => 2.0]],
		'model-2026-04-21' => [['input' => 5.0]],
	]);
	Assert::same(2.0, $meter->record('model-2026-09-08', ['input' => 1_000_000])['cost']);
	Assert::same(5.0, $meter->record('model-2026-04-21', ['input' => 1_000_000])['cost']);
});


test('only a trailing date is stripped from the model', function () use ($prices) {
	$meter = new CostMeter($prices);
	Assert::null($meter->record('model-2026-09-08-beta', ['input' => 1])['cost']);
	Assert::null($meter->record('unknown-2026-09-08', ['input' => 1])['cost']);
});


test('a kind of tokens without a price does not matter when there are none', function () use ($prices) {
	Assert::same(0.000002, new CostMeter($prices)->record('model', ['input' => 1, 'other' => 0])['cost']);
});


test('the price list of the package is readable and priced in numbers', function () {
	$meter = CostMeter::fromFile(__DIR__ . '/../prices.neon');
	Assert::same(30.0, $meter->record('gpt-image-2', ['outputImage' => 1_000_000])['cost']);
});
