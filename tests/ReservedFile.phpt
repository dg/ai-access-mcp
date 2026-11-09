<?php declare(strict_types=1);

use AIAccess\Mcp\ReservedFile;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


$dir = getTempDir();


test('reserving creates an empty file and its directory, writing fills it', function () use ($dir) {
	$file = ReservedFile::reserve("$dir/new/image.png");
	Assert::same('', file_get_contents("$dir/new/image.png"));

	$file->write("data\r\n");
	Assert::same("data\r\n", file_get_contents("$dir/new/image.png"));
	Assert::same(['image.png'], array_values(array_diff(scandir("$dir/new"), ['.', '..'])));

	$file->release();
	Assert::true(is_file("$dir/new/image.png"));
});


test('a taken name is refused', function () use ($dir) {
	file_put_contents("$dir/taken.png", 'x');
	Assert::exception(
		fn() => ReservedFile::reserve("$dir/taken.png"),
		InvalidArgumentException::class,
		"File '%a%taken.png' already exists, choose another name.",
	);

	ReservedFile::reserve("$dir/reserved.png");
	Assert::exception(fn() => ReservedFile::reserve("$dir/reserved.png"), InvalidArgumentException::class, '%a%already exists%a%');
});


test('releasing an unwritten reservation gives the name back', function () use ($dir) {
	$file = ReservedFile::reserve("$dir/failed.png");
	$file->release();
	Assert::false(file_exists("$dir/failed.png"));
});
