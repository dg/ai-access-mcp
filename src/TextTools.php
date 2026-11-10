<?php declare(strict_types=1);

namespace AIAccess\Mcp;

use Mcp\Capability\Attribute\{McpTool, Schema};
use Mcp\Schema\ToolAnnotations;


final readonly class TextTools
{
	public const Instructions = <<<'TEXT'
		generate_text asks another model for a text. Pass files by path so their content does not go through
		your context, and use outputFile for a long answer. Check a result with `problem` before using it.
		Its answer is a proposal: verify factual claims against the sources.
		TEXT;


	public function __construct(
		private TextGenerator $generator,
	) {
	}


	/**
	 * Ask another AI model for a text. Every call costs money: make it only with the explicit consent of the user.
	 * The server reads `files` and `context` itself. Each text file reaches the model as
	 * <file path="PATH AS GIVEN">, the content unchanged, </file>, in the given order, so the prompt can name it.
	 * `context` is shared by the calls of a batch: with Gemini it is stored together with the instructions in a cache,
	 * which later calls with the same model, instructions and context reuse; another content replaces the cache.
	 * The result has `conversation` to continue with; a continuation keeps the cache, schema, model and effort,
	 * so send only the prompt and optionally files. `problem` reports an unfinished answer, a mismatch with the schema or a failed write.
	 * A call can take minutes.
	 *
	 * @param string $prompt  The request
	 * @param ?string $instructions  System instruction
	 * @param ?string $model  Model id starting with gemini- or gpt- (default: configured on the server)
	 * @param ?string $effort  How hard the model thinks
	 * @param ?int $maxOutputTokens  Limit of the answer, thinking included (default: 65536)
	 * @param string[] $files  Absolute paths of texts, images or PDFs for this call
	 * @param string[] $context  Absolute paths of text files shared by the calls of a batch
	 * @param ?array<string, mixed> $responseSchema  JSON Schema of the answer
	 * @param ?string $outputFile  Absolute path of a new file for the answer, which then does not come back in the result
	 * @param ?string $conversation  The `conversation` of an earlier result, to continue it
	 * @return array<string, mixed>
	 */
	#[McpTool(
		name: 'generate_text',
		title: 'Ask another AI model',
		annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true),
	)]
	public function generateText(
		string $prompt,
		?string $instructions = null,
		?string $model = null,
		#[Schema(enum: ['none', 'low', 'medium', 'high'])]
		?string $effort = null,
		#[Schema(minimum: 1)]
		?int $maxOutputTokens = null,
		array $files = [],
		array $context = [],
		?array $responseSchema = null,
		?string $outputFile = null,
		?string $conversation = null,
	): array
	{
		return $this->generator->generate(
			$prompt,
			$instructions,
			$model,
			$effort,
			$maxOutputTokens,
			array_values($files),
			array_values($context),
			$responseSchema,
			$outputFile,
			$conversation,
		);
	}
}
