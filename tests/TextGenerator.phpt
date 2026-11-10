<?php declare(strict_types=1);

use AIAccess\Http;
use AIAccess\Mcp\{ContextCache, CostMeter, Sandbox, TextGenerator};
use AIAccess\Provider\{Gemini, OpenAI};
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


$dir = getTempDir();
mkdir("$dir/in");
mkdir("$dir/out");
file_put_contents("$dir/in/page.texy", "line\r\nčeský text");
file_put_contents("$dir/in/shared.texy", 'shared');
file_put_contents("$dir/in/picture.png", createPng(2, 2));
file_put_contents("$dir/in/binary.bin", "\x00\x01\x02");


function createTextGenerator(FakeHttp $http, string $dir, int $ttl = 3600): TextGenerator
{
	return new TextGenerator(
		[
			'gemini-' => static fn() => new Gemini\Client('key', $http),
			'gpt-' => static fn() => new OpenAI\Client('key', $http),
		],
		new Sandbox(["$dir/in"], ["$dir/out"]),
		new CostMeter(['gemini-x' => [['input' => 1.0, 'cachedInput' => 0.1, 'output' => 10.0, 'cacheStorageHour' => 2.0]]]),
		new ContextCache($ttl),
		'gemini-x',
	);
}


/**
 * @param  array<string, int>  $usage
 */
function answer(string $text, string $finish = 'STOP', array $usage = []): Http\Response
{
	return new Http\Response(200, [], [
		'candidates' => [['content' => ['parts' => [['text' => $text]], 'role' => 'model'], 'finishReason' => $finish]],
		'usageMetadata' => $usage + ['promptTokenCount' => 100, 'candidatesTokenCount' => 10, 'thoughtsTokenCount' => 5],
	]);
}


function cacheCreated(string $name): Http\Response
{
	return new Http\Response(200, [], ['name' => $name]);
}


function payloadOf(FakeHttp $http, int $index): mixed
{
	return json_decode((string) json_encode($http->requests[$index]['payload']), associative: true);
}


test('a plain call returns the text, usage and cost', function () use ($dir) {
	$http = new FakeHttp([answer('hello')]);
	$result = createTextGenerator($http, $dir)->generate('Say hello', instructions: 'Be brief', effort: 'low');

	Assert::same('hello', $result['text']);
	Assert::same('gemini-x', $result['model']);
	Assert::same('STOP', $result['finishReason']);
	Assert::false(isset($result['problem']), 'no problem');
	Assert::false(isset($result['cache']), 'no cache');
	Assert::same(['inputTokens' => 100, 'cacheReadTokens' => null, 'outputTokens' => 10, 'reasoningTokens' => 5], $result['usage']);
	Assert::same(0.00025, $result['cost']); // 100 * 1 + 15 * 10

	$payload = payloadOf($http, 0);
	Assert::contains('models/gemini-x:generateContent', $http->requests[0]['url']);
	Assert::same('Be brief', $payload['systemInstruction']['parts'][0]['text']);
	Assert::same(65536, $payload['generationConfig']['maxOutputTokens']);
});


test('files are tagged by the given path, unchanged and in order, media go as parts', function () use ($dir) {
	$http = new FakeHttp([answer('ok')]);
	createTextGenerator($http, $dir)->generate('Do it', files: ["$dir/in/page.texy", "$dir/in/picture.png"]);

	$parts = payloadOf($http, 0)['contents'][0]['parts'];
	Assert::same("<file path=\"$dir/in/page.texy\">\nline\r\nčeský text\n</file>", $parts[0]['text']);
	Assert::same("<file path=\"$dir/in/picture.png\">attached below</file>", $parts[1]['text']);
	Assert::same('image/png', $parts[2]['inlineData']['mimeType']);
	Assert::same('Do it', $parts[3]['text']);
});


test('refused before the paid call', function () use ($dir) {
	$http = new FakeHttp([]);
	$generator = createTextGenerator($http, $dir);
	file_put_contents("$dir/out/taken.md", 'x');

	Assert::exception(
		fn() => $generator->generate('x', model: 'claude-x'),
		InvalidArgumentException::class,
		"Model 'claude-x' is not available, available: gemini-*, gpt-*.",
	);
	Assert::exception(
		fn() => $generator->generate('x', files: ["$dir/in/binary.bin"]),
		InvalidArgumentException::class,
		'%a%neither a text, an image nor a PDF.',
	);
	Assert::exception(
		fn() => $generator->generate('x', context: ["$dir/in/picture.png"]),
		InvalidArgumentException::class,
		'%a%must be a text.',
	);
	Assert::exception(
		fn() => $generator->generate('x', outputFile: "$dir/out/run.php"),
		InvalidArgumentException::class,
		'%a%must end with one of%a%',
	);
	Assert::exception(fn() => $generator->generate('x', outputFile: "$dir/in/a.md"), InvalidArgumentException::class, '%a%not allowed%a%');
	Assert::exception(
		fn() => $generator->generate('x', outputFile: "$dir/out/taken.md"),
		InvalidArgumentException::class,
		'%a%already exists%a%',
	);
	Assert::same([], $http->requests);
});


test('the answer goes to a file byte by byte and does not come back', function () use ($dir) {
	$http = new FakeHttp([answer("a\r\nb\n")]);
	$result = createTextGenerator($http, $dir)->generate('x', outputFile: "$dir/out/answer.md");

	Assert::same("a\r\nb\n", file_get_contents("$dir/out/answer.md"));
	Assert::same("$dir/out/answer.md", $result['outputFile']);
	Assert::same(5, $result['bytes']);
	Assert::false(isset($result['text']), 'no text');
});


test('a failed call gives the output name back', function () use ($dir) {
	$http = new FakeHttp([new Http\Response(400, [], ['error' => ['message' => 'Bad']])]);
	Assert::exception(
		fn() => createTextGenerator($http, $dir)->generate('x', outputFile: "$dir/out/failed.md"),
		AIAccess\ApiException::class,
		'Bad',
	);
	Assert::false(file_exists("$dir/out/failed.md"));
});


test('an unfinished answer is reported', function () use ($dir) {
	$result = createTextGenerator(new FakeHttp([answer('cut', 'MAX_TOKENS')]), $dir)->generate('x');
	Assert::same('cut', $result['text']);
	Assert::same('The model did not finish the answer: MAX_TOKENS.', $result['problem']);
});


test('an answer outside the schema is reported', function () use ($dir) {
	$schema = [
		'type' => 'object',
		'properties' => ['lines' => ['type' => 'array', 'items' => [
			'type' => 'object',
			'properties' => ['line' => ['type' => 'integer'], 'text' => ['type' => 'string']],
			'required' => ['line', 'text'],
		]]],
		'required' => ['lines'],
	];

	$result = createTextGenerator(new FakeHttp([answer('{"lines":[{"line":1,"text":"a"}]}')]), $dir)->generate(
		'x',
		responseSchema: $schema,
	);
	Assert::false(isset($result['problem']), 'valid');

	$result = createTextGenerator(new FakeHttp([answer('{"lines":[{"line":"one","text":"a"}]}')]), $dir)->generate(
		'x',
		responseSchema: $schema,
	);
	Assert::contains('does not match the schema', $result['problem']);

	$result = createTextGenerator(new FakeHttp([answer('{broken')]), $dir)->generate('x', responseSchema: $schema);
	Assert::contains('not valid JSON', $result['problem']);
});


test('the context goes to a cache together with the instructions and is reused', function () use ($dir) {
	$http = new FakeHttp([
		cacheCreated('cachedContents/1'),
		answer('a', usage: ['cachedContentTokenCount' => 80]),
		answer('b', usage: ['cachedContentTokenCount' => 80]),
	]);
	$generator = createTextGenerator($http, $dir);

	$first = $generator->generate('one', instructions: 'Rules', context: ["$dir/in/shared.texy"]);
	Assert::same('created', $first['cache']['status']);
	Assert::same(80, $first['cache']['readTokens']);
	Assert::same(0.00016, $first['cache']['storageCostPerHour']);
	Assert::same(0.000178, $first['cost']); // 20 * 1 + 80 * 0.1 + 15 * 10

	$payload = payloadOf($http, 0);
	Assert::contains('cachedContents', $http->requests[0]['url']);
	Assert::same('Rules', $payload['systemInstruction']['parts'][0]['text']);
	Assert::same("<file path=\"$dir/in/shared.texy\">\nshared\n</file>", $payload['contents'][0]['parts'][0]['text']);

	$request = payloadOf($http, 1);
	Assert::same('cachedContents/1', $request['cachedContent']);
	Assert::false(isset($request['systemInstruction']), 'instructions are in the cache');
	Assert::same('one', $request['contents'][0]['parts'][0]['text']);

	$second = $generator->generate('two', instructions: 'Rules', context: ["$dir/in/shared.texy"]);
	Assert::same('reused', $second['cache']['status']);
	Assert::count(3, $http->requests);
});


test('another content replaces the cache', function () use ($dir) {
	file_put_contents("$dir/in/other.texy", 'other');
	$http = new FakeHttp([
		cacheCreated('cachedContents/1'),
		answer('a'),
		new Http\Response(200, [], []),
		cacheCreated('cachedContents/2'),
		answer('b'),
	]);
	$generator = createTextGenerator($http, $dir);

	$generator->generate('one', context: ["$dir/in/shared.texy"]);
	$generator->generate('two', context: ["$dir/in/other.texy"]);
	Assert::contains('cachedContents/1', $http->requests[2]['url']);
	Assert::same('cachedContents/2', payloadOf($http, 4)['cachedContent']);
});


test('without a cache the call goes on and says so', function () use ($dir) {
	$http = new FakeHttp([new Http\Response(400, [], ['error' => ['message' => 'Too small']]), answer('a')]);
	$result = createTextGenerator($http, $dir)->generate('one', instructions: 'Rules', context: ["$dir/in/shared.texy"]);

	Assert::same('failed', $result['cache']['status']);
	Assert::same('Too small', $result['cache']['error']);
	$payload = payloadOf($http, 1);
	Assert::same('Rules', $payload['systemInstruction']['parts'][0]['text']);
	Assert::contains('shared', $payload['contents'][0]['parts'][0]['text']);
	Assert::same('one', $payload['contents'][0]['parts'][1]['text']);
});


test('a conversation goes on with its cache, schema and history', function () use ($dir) {
	$schema = ['type' => 'object'];
	$http = new FakeHttp([cacheCreated('cachedContents/1'), answer('{"a":1}'), answer('[]')]);
	$generator = createTextGenerator($http, $dir);

	$first = $generator->generate('one', effort: 'high', context: ["$dir/in/shared.texy"], responseSchema: $schema);
	$second = $generator->generate('fix it', conversation: $first['conversation']);

	Assert::same($first['conversation'], $second['conversation']);
	Assert::same('reused', $second['cache']['status']);
	Assert::contains('does not match the schema', $second['problem']);

	$payload = payloadOf($http, 2);
	Assert::same('cachedContents/1', $payload['cachedContent']);
	Assert::count(3, $payload['contents']);
});


test('a conversation cannot be changed and an unknown one is an error', function () use ($dir) {
	$http = new FakeHttp([answer('a')]);
	$generator = createTextGenerator($http, $dir);
	$id = $generator->generate('one')['conversation'];

	Assert::exception(
		fn() => $generator->generate('x', model: 'gemini-y', conversation: $id),
		InvalidArgumentException::class,
		'A conversation keeps%a%',
	);
	Assert::exception(
		fn() => $generator->generate('x', conversation: 'nope'),
		InvalidArgumentException::class,
		"Unknown conversation 'nope'%a%",
	);
});
