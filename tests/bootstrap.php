<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();
Tester\Environment::setupFunctions();


function getTempDir(): string
{
	@mkdir(__DIR__ . '/output'); // @ - exists after the first test
	$dir = __DIR__ . '/output/' . getmypid();
	Tester\Helpers::purge($dir);
	return strtr($dir, '\\', '/');
}
