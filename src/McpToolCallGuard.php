<?php declare(strict_types=1);

namespace AIAccess\Mcp;

use AIAccess;
use Mcp\Capability\Registry\{ElementReference, ReferenceHandlerInterface};
use Mcp\Exception\ToolCallException;


/**
 * Converts anything a tool throws into a ToolCallException, which the SDK returns as a result
 * with isError=true, so the model sees the message instead of an opaque JSON-RPC -32603.
 */
final readonly class McpToolCallGuard implements ReferenceHandlerInterface
{
	public function __construct(
		private ReferenceHandlerInterface $handler,
	) {
	}


	/**
	 * @param array<string, mixed> $arguments
	 */
	public function handle(ElementReference $reference, array $arguments): mixed
	{
		try {
			return $this->handler->handle($reference, $arguments);
		} catch (ToolCallException $e) {
			throw $e;
		} catch (AIAccess\ApiException $e) {
			throw new ToolCallException(sprintf('HTTP %d: %s', $e->getCode(), $e->getMessage()), 0, $e);
		} catch (AIAccess\ServiceException|AIAccess\LogicException|\InvalidArgumentException|\RuntimeException $e) {
			throw new ToolCallException($e->getMessage(), 0, $e);
		} catch (\Throwable $e) {
			throw new ToolCallException(
				sprintf('%s (%s in %s:%d)', $e->getMessage(), $e::class, basename($e->getFile()), $e->getLine()),
				0,
				$e,
			);
		}
	}
}
