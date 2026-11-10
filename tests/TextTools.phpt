<?php declare(strict_types=1);

use Mcp\Capability\Discovery\Discoverer;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


$tools = (new Discoverer)->discover(__DIR__ . '/..', ['src'])->getTools();


test('both tools are discovered', function () use ($tools) {
	$names = array_keys($tools);
	sort($names);
	Assert::same(['generate_image', 'generate_text'], $names);
});


test('generate_text says what it costs and how files reach the model', function () use ($tools) {
	$tool = $tools['generate_text']->tool;
	Assert::same('Ask another AI model', $tool->title);
	Assert::same([false, false, false, true], [
		$tool->annotations?->readOnlyHint,
		$tool->annotations?->destructiveHint,
		$tool->annotations?->idempotentHint,
		$tool->annotations?->openWorldHint,
	]);
	Assert::contains('explicit consent', (string) $tool->description);
	Assert::contains('<file path="PATH AS GIVEN">', (string) $tool->description);
});


test('schema', function () use ($tools) {
	$schema = json_decode((string) json_encode($tools['generate_text']->tool->inputSchema), associative: true);
	Assert::same(['prompt'], $schema['required']);
	Assert::same(['none', 'low', 'medium', 'high'], $schema['properties']['effort']['enum']);
	Assert::same('array', $schema['properties']['files']['type']);
	Assert::same('array', $schema['properties']['context']['type']);
});
