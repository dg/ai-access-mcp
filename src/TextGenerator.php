<?php declare(strict_types=1);

namespace AIAccess\Mcp;

use AIAccess\Chat\{Effort, FinishReason, Response, Usage};
use AIAccess\Media;
use AIAccess\Provider\{Gemini, OpenAI};
use Opis\JsonSchema\Exceptions\SchemaException;
use Opis\JsonSchema\Validator;
use function count, is_scalar, strlen;


/**
 * Asks another model for a text, with files read by the server and a shared context in a cache.
 */
final class TextGenerator
{
	private const MaxConversations = 16;

	private const DefaultMaxOutputTokens = 65536;

	private const OutputExtensions = ['txt', 'md', 'texy', 'json', 'neon', 'csv', 'xml', 'yaml', 'yml', 'html'];

	/** @var array<string, array{chat: Gemini\Chat|OpenAI\Chat, model: string, schema: ?mixed[], cache: ?string}> */
	private array $conversations = [];


	public function __construct(
		/** @var array<string, \Closure(): (Gemini\Client|OpenAI\Client)> prefix of the model id => client */
		private readonly array $providers,
		private readonly Sandbox $sandbox,
		private readonly CostMeter $meter,
		private readonly ContextCache $cache,
		private readonly string $defaultModel,
	) {
	}


	/**
	 * @param  list<string>  $files
	 * @param  list<string>  $context
	 * @param  ?mixed[]  $responseSchema
	 * @return array<string, mixed>
	 */
	public function generate(
		string $prompt,
		?string $instructions = null,
		?string $model = null,
		?string $effort = null,
		?int $maxOutputTokens = null,
		array $files = [],
		array $context = [],
		?array $responseSchema = null,
		?string $outputFile = null,
		?string $conversation = null,
	): array
	{
		if (
			$conversation !== null
			&& ($instructions !== null || $model !== null || $effort !== null || $context || $responseSchema !== null)
		) {
			throw new \InvalidArgumentException('A conversation keeps its instructions, model, effort, context and schema, omit them when continuing it.');
		}

		$outputPath = $outputFile === null ? null : $this->resolveOutput($outputFile);
		$parts = $this->buildParts($files);
		$state = $conversation === null
			? null
			: $this->conversations[$conversation] ?? throw new \InvalidArgumentException("Unknown conversation '$conversation', the server may have been restarted. Start a new one.");

		$reserved = $outputPath === null ? null : ReservedFile::reserve($outputPath);
		try {
			$start = $state === null ? $this->start($instructions, $model, $effort, $context, $responseSchema) : null;
			$state ??= $start['state'] ?? throw new \LogicException('A new conversation has a state.');
			if (($start['prefix'] ?? '') !== '') {
				array_unshift($parts, $start['prefix']);
			}

			$state['chat']->setOptions(maxOutputTokens: $maxOutputTokens ?? self::DefaultMaxOutputTokens);
			$response = $state['chat']->sendMessage([...$parts, $prompt]);
		} catch (\Throwable $e) {
			$reserved?->release();
			throw $e;
		}

		$id = $conversation ?? bin2hex(random_bytes(6));
		$this->remember($id, $state);
		return $this->describe($response, $id, $state, $start['cacheInfo'] ?? null, $reserved);
	}


	private function resolveOutput(string $path): string
	{
		if (!in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::OutputExtensions, true)) {
			throw new \InvalidArgumentException("Path '$path' must end with one of: " . implode(', ', self::OutputExtensions) . '.');
		}
		return $this->sandbox->resolveWritable($path);
	}


	/**
	 * Files in the given order: a text under a tag naming its path, anything else as a media part.
	 * @param  list<string>  $files
	 * @return list<string|Media>
	 */
	private function buildParts(array $files): array
	{
		$parts = [];
		foreach ($files as $file) {
			$media = Media::fromFile($this->sandbox->resolveReadable($file));
			if (self::isText($media->getData())) {
				$parts[] = self::tagFile($file, $media->getData());
			} elseif ($media->isImage() || $media->getMimeType() === 'application/pdf') {
				$parts[] = "<file path=\"$file\">attached below</file>";
				$parts[] = $media;
			} else {
				throw new \InvalidArgumentException("File '$file' is neither a text, an image nor a PDF.");
			}
		}
		return $parts;
	}


	/**
	 * @param  list<string>  $context
	 * @param  ?mixed[]  $schema
	 * @return array{state: array{chat: Gemini\Chat|OpenAI\Chat, model: string, schema: ?mixed[], cache: ?string}, prefix: string, cacheInfo: ?array{name: ?string, status: string, error?: string}}
	 */
	private function start(?string $instructions, ?string $model, ?string $effort, array $context, ?array $schema): array
	{
		$model ??= $this->defaultModel;
		$client = $this->createClient($model);
		$chat = $client->createChat($model);
		$contextText = implode("\n", array_map($this->readContextFile(...), $context));

		$cacheInfo = $contextText !== '' && $client instanceof Gemini\Client
			? $this->cache->obtain($client, $model, $instructions ?? '', $contextText)
			: null;

		$cacheName = $cacheInfo['name'] ?? null;
		if ($cacheName !== null && $chat instanceof Gemini\Chat) { // the API refuses instructions beside a cache, they are stored in it
			$chat->setCachedContent($cacheName);
			$contextText = '';
		} elseif ($instructions !== null) {
			$chat->setSystemInstruction($instructions);
		}

		if ($effort !== null) {
			$chat->setEffort(Effort::from($effort));
		}
		if ($schema !== null) {
			$chat->setResponseSchema($schema);
		}

		return [
			'state' => ['chat' => $chat, 'model' => $model, 'schema' => $schema, 'cache' => $cacheName],
			'prefix' => $contextText,
			'cacheInfo' => $cacheInfo,
		];
	}


	private function createClient(string $model): Gemini\Client|OpenAI\Client
	{
		foreach ($this->providers as $prefix => $factory) {
			if (str_starts_with($model, $prefix)) {
				return $factory();
			}
		}
		throw new \InvalidArgumentException("Model '$model' is not available, available: " . implode('*, ', array_keys($this->providers)) . '*.');
	}


	private function readContextFile(string $file): string
	{
		$data = Media::fromFile($this->sandbox->resolveReadable($file))->getData();
		return self::isText($data)
			? self::tagFile($file, $data)
			: throw new \InvalidArgumentException("Context file '$file' must be a text.");
	}


	/**
	 * @param  array{chat: Gemini\Chat|OpenAI\Chat, model: string, schema: ?mixed[], cache: ?string}  $state
	 */
	private function remember(string $id, array $state): void
	{
		unset($this->conversations[$id]);
		$this->conversations[$id] = $state;
		if (count($this->conversations) > self::MaxConversations) {
			array_shift($this->conversations);
		}
	}


	/**
	 * @param  array{chat: Gemini\Chat|OpenAI\Chat, model: string, schema: ?mixed[], cache: ?string}  $state
	 * @param  ?array{name: ?string, status: string, error?: string}  $cacheInfo
	 * @return array<string, mixed>
	 */
	private function describe(Response $response, string $id, array $state, ?array $cacheInfo, ?ReservedFile $output): array
	{
		$text = $response->getText();
		$usage = $response->getUsage();
		$model = $state['model'];
		$problems = array_filter([self::findFinishProblem($response), self::findSchemaProblem($text, $state['schema'])]);

		$result = ['model' => $model, 'conversation' => $id, 'finishReason' => $response->getRawFinishReason()];
		if ($output === null) {
			$result['text'] = $text;
		} else {
			try {
				$output->write($text);
				$result += ['outputFile' => $output->path, 'bytes' => strlen($text)];
			} catch (\RuntimeException $e) {
				$output->release();
				$result['text'] = $text;
				$problems[] = $e->getMessage();
			}
		}

		if ($state['cache'] !== null || $cacheInfo !== null) {
			$result['cache'] = $this->describeCache($model, $cacheInfo ?? ['status' => 'reused'], $usage);
		}
		if ($problems) {
			$result['problem'] = implode(' ', $problems);
		}

		return $result
			+ ['usage' => [
				'inputTokens' => $usage?->inputTokens,
				'cacheReadTokens' => $usage?->cacheReadTokens,
				'outputTokens' => $usage?->outputTokens,
				'reasoningTokens' => $usage?->reasoningTokens,
			]]
			+ $this->meter->record($model, self::priceTokens($usage, $model));
	}


	/**
	 * @param  array{status: string, error?: string, name?: ?string}  $info
	 * @return array<string, mixed>
	 */
	private function describeCache(string $model, array $info, ?Usage $usage): array
	{
		unset($info['name']);
		$read = (int) $usage?->cacheReadTokens;
		return $info + ['readTokens' => $read, 'storageCostPerHour' => $this->meter->estimate($model, ['cacheStorageHour' => $read])];
	}


	/**
	 * Both providers count cached tokens into the input; only Gemini leaves the reasoning out of the output.
	 * @return ?array<string, int>
	 */
	private static function priceTokens(?Usage $usage, string $model): ?array
	{
		return $usage === null
			? null
			: [
				'input' => max(0, (int) $usage->inputTokens - (int) $usage->cacheReadTokens),
				'cachedInput' => (int) $usage->cacheReadTokens,
				'output' => (int) $usage->outputTokens + (str_starts_with($model, 'gemini-') ? (int) $usage->reasoningTokens : 0),
			];
	}


	private static function findFinishProblem(Response $response): ?string
	{
		return $response->getFinishReason() === FinishReason::Complete
			? null
			: 'The model did not finish the answer: ' . self::stringify($response->getRawFinishReason()) . '.';
	}


	/**
	 * @param  ?mixed[]  $schema
	 */
	private static function findSchemaProblem(string $text, ?array $schema): ?string
	{
		if ($schema === null) {
			return null;
		}

		$data = json_decode($text);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return 'The answer is not valid JSON: ' . json_last_error_msg() . '.';
		}

		try {
			$error = (new Validator)->validate($data, (object) json_decode((string) json_encode($schema)))->error();
		} catch (SchemaException $e) {
			return 'The schema could not be checked: ' . $e->getMessage();
		}
		return $error === null ? null : 'The answer does not match the schema: ' . $error->message();
	}


	private static function stringify(mixed $value): string
	{
		return is_scalar($value) ? (string) $value : (string) json_encode($value);
	}


	private static function tagFile(string $file, string $content): string
	{
		return "<file path=\"$file\">\n$content\n</file>";
	}


	private static function isText(string $data): bool
	{
		return !str_contains($data, "\0") && preg_match('//u', $data) === 1;
	}
}
