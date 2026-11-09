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


function createPng(int $width, int $height): string
{
	$image = imagecreatetruecolor($width, $height);
	ob_start();
	imagepng($image);
	return (string) ob_get_clean();
}


/**
 * Plays back the given outcomes and remembers the requests.
 */
final class FakeHttp implements AIAccess\Http\Client
{
	/** @var list<array{url: string, payload: mixed}> */
	public array $requests = [];


	public function __construct(
		/** @var list<AIAccess\Http\Response|Throwable> */
		private array $outcomes,
	) {
	}


	public function fetch(
		string $url,
		string|array|AIAccess\Http\FormData|null $payload = null,
		array $headers = [],
		?string $method = null,
		?Closure $onChunk = null,
	): AIAccess\Http\Response
	{
		$this->requests[] = ['url' => $url, 'payload' => $payload];
		$outcome = array_shift($this->outcomes) ?? throw new LogicException('Unexpected request');
		return $outcome instanceof Throwable ? throw $outcome : $outcome;
	}
}
