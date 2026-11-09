<?php declare(strict_types=1);

use AIAccess\Http;
use AIAccess\Mcp\{CostMeter, ImageGenerator, ImageTools, Sandbox};
use AIAccess\Provider\OpenAI;
use Mcp\Capability\Discovery\Discoverer;
use Mcp\Capability\Formatter\ToolResultFormatter;
use Mcp\Schema\Content\{ImageContent, TextContent};
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


$tools = (new Discoverer)->discover(__DIR__ . '/..', ['src'])->getTools();


test('generate_image is discovered with its annotations', function () use ($tools) {
	Assert::same(['generate_image'], array_keys($tools));
	$tool = $tools['generate_image']->tool;
	Assert::same('Generate an image', $tool->title);
	Assert::same([false, false, false, true], [
		$tool->annotations->readOnlyHint,
		$tool->annotations->destructiveHint,
		$tool->annotations->idempotentHint,
		$tool->annotations->openWorldHint,
	]);
	Assert::contains('explicit consent', $tool->description);
});


test('schema', function () use ($tools) {
	$schema = $tools['generate_image']->tool->inputSchema;
	Assert::same(['prompt', 'path'], $schema['required']);
	Assert::same(['low', 'medium', 'high', 'auto'], $schema['properties']['quality']['enum']);
	Assert::same(['transparent', 'opaque', 'auto'], $schema['properties']['background']['enum']);
	Assert::same('array', $schema['properties']['referenceImages']['type']);
});


function createTools(string $image, string $dir): ImageTools
{
	$http = new FakeHttp([new Http\Response(200, [], ['data' => [['b64_json' => base64_encode($image)]]])]);
	return new ImageTools(new ImageGenerator(
		static fn() => new OpenAI\Client('key', $http),
		new Sandbox([], [$dir]),
		new CostMeter([]),
		'gpt-image-2',
	));
}


test('the result is a text description and a preview', function () {
	$dir = getTempDir();
	$content = (new ToolResultFormatter)->format(createTools(createPng(8, 8), $dir)->generateImage('x', "$dir/image.png"));
	Assert::count(2, $content);
	Assert::type(TextContent::class, $content[0]);
	Assert::contains('"path"', $content[0]->text);
	Assert::notContains('preview', $content[0]->text);
	Assert::type(ImageContent::class, $content[1]);
	Assert::same('image/webp', $content[1]->mimeType);
});


test('without a preview the result is only the text', function () {
	$dir = getTempDir();
	$content = (new ToolResultFormatter)->format(createTools('garbage', $dir)->generateImage('x', "$dir/image.png"));
	Assert::count(1, $content);
	Assert::type(TextContent::class, $content[0]);
});
