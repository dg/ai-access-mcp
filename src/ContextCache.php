<?php declare(strict_types=1);

namespace AIAccess\Mcp;

use AIAccess;
use AIAccess\Provider\Gemini;


/**
 * Keeps one explicit cache of Gemini, the context and the instructions of the current batch of calls.
 * A cache for other content replaces it, and a cache about to expire is made anew.
 */
final class ContextCache
{
	/** seconds the provider keeps a cache */
	private const Ttl = 3600;

	/** seconds before the expiry when a cache is no longer used, so it cannot vanish during a long call */
	private const ExpiryMargin = 900;

	/** @var ?array{key: string, name: string, client: Gemini\Client, createdAt: int} */
	private ?array $current = null;


	public function __construct(
		private readonly int $ttl = self::Ttl,
	) {
	}


	/**
	 * Returns the name of the cache and how it was obtained. When the provider refuses to make one
	 * (the content is too short, say), the name is null and the call goes on without it.
	 * @return array{name: ?string, status: string, error?: string}
	 */
	public function obtain(Gemini\Client $client, string $model, string $instructions, string $context): array
	{
		$key = hash('xxh128', "$model\0$instructions\0$context");
		$current = $this->current;
		if ($current !== null && $current['key'] === $key && time() < $current['createdAt'] + $this->ttl - self::ExpiryMargin) {
			return ['name' => $current['name'], 'status' => 'reused'];
		}

		$this->delete();
		try {
			$name = $client->createCachedContent($context, $instructions !== '' ? $instructions : null, $this->ttl, $model);
		} catch (AIAccess\ServiceException $e) {
			return ['name' => null, 'status' => 'failed', 'error' => $e->getMessage()];
		}

		$this->current = ['key' => $key, 'name' => $name, 'client' => $client, 'createdAt' => time()];
		return ['name' => $name, 'status' => 'created'];
	}


	/**
	 * What cannot be deleted expires by itself.
	 */
	public function delete(): void
	{
		if ($this->current !== null) {
			try {
				$this->current['client']->deleteCachedContent($this->current['name']);
			} catch (AIAccess\ServiceException) {
			}
			$this->current = null;
		}
	}
}
