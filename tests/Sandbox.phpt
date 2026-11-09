<?php declare(strict_types=1);

use AIAccess\Mcp\Sandbox;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


$dir = getTempDir();
foreach (['docs', 'libs/src', 'keys'] as $sub) {
	mkdir("$dir/$sub", recursive: true);
}
file_put_contents("$dir/docs/page.texy", 'page');
file_put_contents("$dir/libs/src/Lib.php", 'lib');
file_put_contents("$dir/libs/.env", 'secret');
file_put_contents("$dir/keys/openai.txt", 'key');
file_put_contents("$dir/outside.txt", 'outside');

$sandbox = new Sandbox(
	readRoots: ["$dir/libs", "$dir/keys"],
	writeRoots: ["$dir/docs"],
	denied: ["$dir/keys"],
);


test('reading under a read root or a write root', function () use ($sandbox, $dir) {
	Assert::same("$dir/libs/src/Lib.php", $sandbox->resolveReadable("$dir/libs/src/Lib.php"));
	Assert::same("$dir/docs/page.texy", $sandbox->resolveReadable("$dir/docs/page.texy"));
	Assert::same("$dir/libs/src/Lib.php", $sandbox->resolveReadable("$dir/docs/../libs/src/Lib.php"));
});


test('reading is refused outside the roots, in denied paths and for .env', function () use ($sandbox, $dir) {
	Assert::exception(
		fn() => $sandbox->resolveReadable("$dir/outside.txt"),
		InvalidArgumentException::class,
		"Reading '%a%outside.txt' is not allowed.%a%",
	);
	Assert::exception(fn() => $sandbox->resolveReadable("$dir/keys/openai.txt"), InvalidArgumentException::class, '%a%not allowed%a%');
	Assert::exception(fn() => $sandbox->resolveReadable("$dir/libs/.env"), InvalidArgumentException::class, '%a%not allowed%a%');
	Assert::exception(fn() => $sandbox->resolveReadable("$dir/docs/../outside.txt"), InvalidArgumentException::class, '%a%not allowed%a%');
});


test('reading needs an existing file and an absolute path', function () use ($sandbox, $dir) {
	Assert::exception(fn() => $sandbox->resolveReadable("$dir/libs/missing.php"), InvalidArgumentException::class, '%a%does not exist%a%');
	Assert::exception(fn() => $sandbox->resolveReadable("$dir/libs"), InvalidArgumentException::class, '%a%does not exist%a%');
	Assert::exception(fn() => $sandbox->resolveReadable('libs/src/Lib.php'), InvalidArgumentException::class, '%a%must be absolute%a%');
});


test('writing under a write root, also into directories that do not exist yet', function () use ($sandbox, $dir) {
	Assert::same("$dir/docs/out.texy", $sandbox->resolveWritable("$dir/docs/out.texy"));
	Assert::same("$dir/docs/a/b/out.png", $sandbox->resolveWritable("$dir/docs/a/b/out.png"));
	Assert::same("$dir/docs/out.texy", $sandbox->resolveWritable(strtr("$dir/docs/out.texy", '/', '\\')));
});


test('writing is refused under a read root, outside the roots and with dot segments', function () use ($sandbox, $dir) {
	Assert::exception(
		fn() => $sandbox->resolveWritable("$dir/libs/out.txt"),
		InvalidArgumentException::class,
		"Writing '%a%' is not allowed.%a%",
	);
	Assert::exception(fn() => $sandbox->resolveWritable("$dir/elsewhere/out.txt"), InvalidArgumentException::class, '%a%not allowed%a%');
	Assert::exception(fn() => $sandbox->resolveWritable("$dir/docs/.env"), InvalidArgumentException::class, '%a%not allowed%a%');
	Assert::exception(fn() => $sandbox->resolveWritable("$dir/docs/../outside.txt"), InvalidArgumentException::class, "%a%'.' or '..'%a%");
	Assert::exception(fn() => $sandbox->resolveWritable('docs/out.txt'), InvalidArgumentException::class, '%a%must be absolute%a%');
});


test('a root must be an existing directory', function () use ($dir) {
	Assert::exception(
		fn() => new Sandbox(["$dir/missing"], []),
		InvalidArgumentException::class,
		"Root '%a%missing' is not an existing directory.",
	);
	Assert::exception(fn() => new Sandbox([], ["$dir/outside.txt"]), InvalidArgumentException::class, '%a%not an existing directory%a%');
});


test('nothing is allowed without roots', function () use ($dir) {
	$sandbox = new Sandbox([], []);
	Assert::exception(fn() => $sandbox->resolveReadable("$dir/docs/page.texy"), InvalidArgumentException::class, '%a%not allowed%a%');
	Assert::exception(fn() => $sandbox->resolveWritable("$dir/docs/out.texy"), InvalidArgumentException::class, '%a%not allowed%a%');
});


test('a root given for reading and writing is listed once', function () use ($dir) {
	$sandbox = new Sandbox(["$dir/docs"], ["$dir/docs"]);
	Assert::exception(
		fn() => $sandbox->resolveReadable("$dir/outside.txt"),
		InvalidArgumentException::class,
		"Reading '%a%' is not allowed. Readable roots: $dir/docs.",
	);
});
