<?php declare(strict_types=1);

namespace AIAccess\Mcp;

use Mcp\Capability\Attribute\{McpTool, Schema};
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\ToolAnnotations;


final readonly class ImageTools
{
	public const Instructions = <<<'TEXT'
		generate_image creates an image with an OpenAI image model and saves it to the absolute path you name.
		Show the final prompt to the user first. Only the user judges the quality of an image: show the result
		and ask, never decide on your own that it is good enough or generate another one unasked.
		An existing file is never overwritten, so a new version needs a new name. The prompt is saved beside
		the image in a .txt file of the same name, which must not exist either.
		TEXT;


	public function __construct(
		private ImageGenerator $generator,
	) {
	}


	/**
	 * Generate an image with an AI model and save it to a file. Every call costs money: make it only
	 * with the explicit consent of the user, after showing the final prompt.
	 * Without reference images it creates a new picture, with them it edits them or works from them.
	 * High quality with references can take minutes. Returns the description of the result with its cost in USD
	 * and a small preview.
	 *
	 * @param string $prompt  Complete description of the image
	 * @param string $path  Absolute path of a new file ending in .png, .jpg, .jpeg or .webp, which also decides the format
	 * @param string[] $referenceImages  Absolute paths of PNG, JPEG or WebP images to edit or work from, at most 16
	 * @param ?string $size  WIDTHxHEIGHT, e.g. 1536x1024 (default: chosen by the model)
	 * @param ?string $quality  Cost and detail grow with it (default: chosen by the model)
	 * @param ?string $background  transparent needs .png or .webp (default: chosen by the model)
	 * @param ?string $model  Model id starting with gpt-image- (default: configured on the server)
	 * @return array<mixed>
	 */
	#[McpTool(
		name: 'generate_image',
		title: 'Generate an image',
		annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true),
	)]
	public function generateImage(
		string $prompt,
		string $path,
		array $referenceImages = [],
		?string $size = null,
		#[Schema(enum: ['low', 'medium', 'high', 'auto'])]
		?string $quality = null,
		#[Schema(enum: ['transparent', 'opaque', 'auto'])]
		?string $background = null,
		?string $model = null,
	): array
	{
		$result = $this->generator->generate($prompt, $path, array_values($referenceImages), $size, $quality, $background, $model);
		$preview = $result['preview'];
		unset($result['preview']);
		return $preview === null
			? $result
			: [$result, ImageContent::fromString($preview, 'image/webp')];
	}
}
