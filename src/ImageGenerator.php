<?php declare(strict_types=1);

namespace AIAccess\Mcp;

use AIAccess\Media;
use AIAccess\Provider\OpenAI;


/**
 * Generates an image into a file named by the caller, with the prompt in a text file beside it.
 */
final readonly class ImageGenerator
{
	private const Formats = ['png' => 'png', 'jpg' => 'jpeg', 'jpeg' => 'jpeg', 'webp' => 'webp'];

	private const ModelPrefix = 'gpt-image-';

	private const PreviewSize = 512;


	public function __construct(
		/** @var \Closure(): OpenAI\Client */
		private \Closure $clientFactory,
		private Sandbox $sandbox,
		private string $defaultModel,
	) {
	}


	/**
	 * @param  list<string>  $references
	 * @return array{path: string, promptFile: string, model: string, size: ?string, quality: ?string, background: ?string, referenceCount: int, usage: mixed, preview: ?string}
	 */
	public function generate(
		string $prompt,
		string $path,
		array $references = [],
		?string $size = null,
		?string $quality = null,
		?string $background = null,
		?string $model = null,
	): array
	{
		$model ??= $this->defaultModel;
		if (!str_starts_with($model, self::ModelPrefix)) {
			throw new \InvalidArgumentException("Model '$model' is not supported, available: " . self::ModelPrefix . '*.');
		}

		$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		$format = self::Formats[$extension]
			?? throw new \InvalidArgumentException("Path '$path' must end with one of: " . implode(', ', array_keys(self::Formats)) . '.');

		$media = array_map(fn(string $reference) => Media::fromFile($this->sandbox->resolveReadable($reference)), $references);
		$target = $this->sandbox->resolveWritable($path);
		$promptTarget = substr($target, 0, -strlen($extension)) . 'txt';

		$reserved = [];
		try {
			$reserved[] = $imageFile = ReservedFile::reserve($target);
			$reserved[] = $promptFile = ReservedFile::reserve($promptTarget);
			$image = ($this->clientFactory)()->generateImage($prompt, $model, $media, $size, $quality, $background, $format);
			$imageFile->write($image->getData());
			$promptFile->write($prompt);
		} catch (\Throwable $e) {
			foreach ($reserved as $file) {
				$file->release();
			}
			throw $e;
		}

		$response = $image->getRawResponse() ?? [];
		return [
			'path' => $target,
			'promptFile' => $promptTarget,
			'model' => $model,
			'size' => $response['size'] ?? $size,
			'quality' => $response['quality'] ?? $quality,
			'background' => $response['background'] ?? $background,
			'referenceCount' => count($references),
			'usage' => $response['usage'] ?? null,
			'preview' => self::createPreview($image->getData()),
		];
	}


	/**
	 * A small WebP of the image, or null when GD cannot read it or was built without WebP.
	 */
	private static function createPreview(string $data): ?string
	{
		$image = @imagecreatefromstring($data); // @ - GD reports an unreadable image by a warning too
		if ($image === false || !function_exists('imagewebp')) {
			return null;
		}

		$scale = min(1, self::PreviewSize / max(imagesx($image), imagesy($image)));
		$preview = imagescale($image, max(1, (int) round(imagesx($image) * $scale)));
		imagesavealpha($preview, true);
		ob_start();
		imagewebp($preview, quality: 75);
		return (string) ob_get_clean();
	}
}
