<?php declare(strict_types=1);

use AIAccess\Mcp\McpToolCallGuard;
use Mcp\Capability\Registry\{ElementReference, ReferenceHandlerInterface};
use Mcp\Exception\ToolCallException;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


function callGuard(Throwable $thrown): void
{
	$handler = new class ($thrown) implements ReferenceHandlerInterface {
		public function __construct(
			private Throwable $thrown,
		) {
		}


		public function handle(ElementReference $reference, array $arguments): mixed
		{
			throw $this->thrown;
		}
	};
	new McpToolCallGuard($handler)->handle(new ElementReference(static fn() => null), []);
}


test('a ToolCallException passes unchanged', function () {
	$original = new ToolCallException('shaped');
	$e = Assert::exception(fn() => callGuard($original), ToolCallException::class, 'shaped');
	Assert::same($original, $e);
});


test('an API error carries the HTTP status and the message of the provider', function () {
	Assert::exception(
		fn() => callGuard(new AIAccess\ApiException('Quota exceeded', 429)),
		ToolCallException::class,
		'HTTP 429: Quota exceeded',
	);
});


test('expected errors keep their message', function () {
	foreach ([
		new AIAccess\CommunicationException('timed out'),
		new AIAccess\LogicException('no model'),
		new InvalidArgumentException('bad path'),
		new RuntimeException('cannot write'),
	] as $error) {
		Assert::exception(fn() => callGuard($error), ToolCallException::class, $error->getMessage());
	}
});


test('anything else names the class and the place', function () {
	Assert::exception(fn() => callGuard(new TypeError('bug')), ToolCallException::class, 'bug (TypeError in McpToolCallGuard.phpt:%d%)');
});
