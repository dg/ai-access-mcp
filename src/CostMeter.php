<?php declare(strict_types=1);

namespace AIAccess\Mcp;

use Nette\Neon\Neon;


/**
 * Prices the calls by a price list and adds them up for the life of the server process.
 */
final class CostMeter
{
	private float $total = 0.0;


	public function __construct(
		/** @var array<string, list<array<string, float|string>>> model => periods of prices */
		private readonly array $prices,
		private readonly ?string $today = null,
	) {
	}


	public static function fromFile(string $file): self
	{
		return new self(Neon::decodeFile($file));
	}


	/**
	 * @param  ?array<string, int>  $tokens  kind of tokens => count, null when the provider reported none
	 * @return array{cost: ?float, sessionCost: float}
	 */
	public function record(string $model, ?array $tokens): array
	{
		$cost = $tokens === null ? null : $this->calculate($model, $tokens);
		$this->total += $cost ?? 0.0;
		return ['cost' => $cost, 'sessionCost' => round($this->total, 6)];
	}


	/**
	 * @param  array<string, int>  $tokens
	 */
	private function calculate(string $model, array $tokens): ?float
	{
		$prices = $this->findPrices($model);
		if ($prices === null) {
			return null;
		}

		$sum = 0.0;
		foreach ($tokens as $kind => $count) {
			if ($count > 0) {
				if (!isset($prices[$kind])) {
					return null;
				}
				$sum += $count * (float) $prices[$kind];
			}
		}
		return round($sum / 1_000_000, 6);
	}


	/**
	 * @return ?array<string, float|string>
	 */
	private function findPrices(string $model): ?array
	{
		$today = $this->today ?? date('Y-m-d');
		return array_find($this->prices[$model] ?? [], fn($period) => !isset($period['until']) || $period['until'] >= $today);
	}
}
