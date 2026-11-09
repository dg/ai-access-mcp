<?php declare(strict_types=1);

use AIAccess\Http;
use AIAccess\Mcp\{CostMeter, ImageGenerator, Sandbox};
use AIAccess\Provider\OpenAI;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


function createGenerator(Http\Client $http, string $dir): ImageGenerator
{
	return new ImageGenerator(
		static fn() => new OpenAI\Client('key', $http),
		new Sandbox(["$dir/input"], ["$dir/output"]),
		new CostMeter(['gpt-image-2' => [['inputText' => 1.0, 'inputImage' => 2.0, 'outputImage' => 10.0]]]),
		'gpt-image-2',
	);
}


function successResponse(string $image): Http\Response
{
	return new Http\Response(200, [], [
		'data' => [['b64_json' => base64_encode($image)]],
		'size' => '1536x1024',
		'quality' => 'high',
		'background' => 'opaque',
		'output_format' => 'png',
		'usage' => [
			'input_tokens' => 30,
			'output_tokens' => 20,
			'input_tokens_details' => ['text_tokens' => 10, 'image_tokens' => 20],
		],
	]);
}


$dir = getTempDir();
mkdir("$dir/input");
mkdir("$dir/output");
file_put_contents("$dir/input/ref.png", createPng(4, 4));
$png = createPng(1536, 1024);


test('writes the image and the prompt and describes the result', function () use ($dir, $png) {
	$http = new FakeHttp([successResponse($png)]);
	$result = createGenerator($http, $dir)->generate('a horse', "$dir/output/sub/horse.png");

	Assert::same($png, file_get_contents("$dir/output/sub/horse.png"));
	Assert::same('a horse', file_get_contents("$dir/output/sub/horse.txt"));
	Assert::same("$dir/output/sub/horse.png", $result['path']);
	Assert::same("$dir/output/sub/horse.txt", $result['promptFile']);
	Assert::same('gpt-image-2', $result['model']);
	Assert::same('1536x1024', $result['size']);
	Assert::same('high', $result['quality']);
	Assert::same(0, $result['referenceCount']);
	Assert::same(['inputText' => 10, 'inputImage' => 20, 'outputImage' => 20, 'outputText' => 0], $result['usage']);
	Assert::same(0.00025, $result['cost']);
	Assert::same(0.00025, $result['sessionCost']);

	$payload = $http->requests[0]['payload'];
	Assert::contains('images/generations', $http->requests[0]['url']);
	Assert::same('gpt-image-2', $payload['model']);
	Assert::same('png', $payload['output_format']);
});


test('the preview is a small WebP', function () use ($dir, $png) {
	$result = createGenerator(new FakeHttp([successResponse($png)]), $dir)->generate('x', "$dir/output/preview.png");
	$size = getimagesizefromstring((string) $result['preview']) ?: [];
	Assert::same([512, 341, 'image/webp'], [$size[0] ?? null, $size[1] ?? null, $size['mime'] ?? null]);
});


test('parameters reach the provider, the extension decides the format', function () use ($dir, $png) {
	$http = new FakeHttp([successResponse($png)]);
	createGenerator($http, $dir)->generate(
		'x',
		"$dir/output/params.JPG",
		["$dir/input/ref.png"],
		'1024x1024',
		'low',
		'opaque',
		'gpt-image-1',
	);

	$payload = $http->requests[0]['payload'];
	Assert::contains('images/edits', $http->requests[0]['url']);
	Assert::same('gpt-image-1', $payload['model']);
	Assert::same('1024x1024', $payload['size']);
	Assert::same('low', $payload['quality']);
	Assert::same('opaque', $payload['background']);
	Assert::same('jpeg', $payload['output_format']);
	Assert::count(1, $payload['images']);
	Assert::true(is_file("$dir/output/params.txt"));
});


test('refused before the paid call', function () use ($dir, $png) {
	$http = new FakeHttp([successResponse($png)]);
	$generator = createGenerator($http, $dir);
	file_put_contents("$dir/output/taken.png", 'x');
	file_put_contents("$dir/output/promptTaken.txt", 'x');

	Assert::exception(
		fn() => $generator->generate('x', "$dir/output/a.png", model: 'gemini-3'),
		InvalidArgumentException::class,
		"Model 'gemini-3' is not supported, available: gpt-image-*.",
	);
	Assert::exception(
		fn() => $generator->generate('x', "$dir/output/a.gif"),
		InvalidArgumentException::class,
		'%a%must end with one of: png, jpg, jpeg, webp.',
	);
	Assert::exception(fn() => $generator->generate('x', "$dir/input/a.png"), InvalidArgumentException::class, '%a%not allowed%a%');
	Assert::exception(
		fn() => $generator->generate('x', "$dir/output/a.png", ["$dir/output/none.png"]),
		InvalidArgumentException::class,
		'%a%does not exist%a%',
	);
	Assert::exception(fn() => $generator->generate('x', "$dir/output/taken.png"), InvalidArgumentException::class, '%a%already exists%a%');
	Assert::exception(
		fn() => $generator->generate('x', "$dir/output/promptTaken.png"),
		InvalidArgumentException::class,
		'%a%already exists%a%',
	);

	Assert::same([], $http->requests);
	Assert::false(file_exists("$dir/output/a.png"));
	Assert::false(file_exists("$dir/output/promptTaken.png"));
});


test('a failed call gives both names back', function () use ($dir) {
	$http = new FakeHttp([new Http\Response(400, [], ['error' => ['message' => 'Bad prompt']])]);
	Assert::exception(
		fn() => createGenerator($http, $dir)->generate('x', "$dir/output/failed.png"),
		AIAccess\ApiException::class,
		'Bad prompt',
	);
	Assert::false(file_exists("$dir/output/failed.png"));
	Assert::false(file_exists("$dir/output/failed.txt"));
});


test('a response that is not an image still saves it, without a preview', function () use ($dir) {
	$result = createGenerator(new FakeHttp([successResponse('garbage')]), $dir)->generate('x', "$dir/output/garbage.png");
	Assert::same('garbage', file_get_contents("$dir/output/garbage.png"));
	Assert::null($result['preview']);
});
