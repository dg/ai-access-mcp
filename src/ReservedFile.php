<?php declare(strict_types=1);

namespace AIAccess\Mcp;

use function strlen;


/**
 * A new file claimed before the paid work that produces its content, so two clients never write
 * the same file and a taken name costs nothing. Until written, it exists as an empty file.
 */
final class ReservedFile
{
	private const RenameAttempts = 25;

	/** microseconds */
	private const RenameDelay = 200_000;

	private bool $written = false;


	private function __construct(
		public readonly string $path,
	) {
	}


	public static function reserve(string $path): self
	{
		$dir = dirname($path);
		if (!is_dir($dir) && !@mkdir($dir, recursive: true) && !is_dir($dir)) { // @ - reported below
			throw new \RuntimeException("Cannot create directory '$dir'.");
		}

		$handle = @fopen($path, 'x'); // @ - reported below
		if ($handle === false) {
			throw file_exists($path)
				? new \InvalidArgumentException("File '$path' already exists, choose another name.")
				: new \RuntimeException("Cannot create file '$path'.");
		}
		fclose($handle);
		return new self($path);
	}


	/**
	 * Writes the content through a temporary file, so nobody reads it half-written. When the final
	 * rename fails, the temporary file is kept and named in the exception.
	 */
	public function write(string $data): void
	{
		$temp = $this->path . '.' . bin2hex(random_bytes(4)) . '.tmp';
		if (@file_put_contents($temp, $data) !== strlen($data)) { // @ - reported below
			@unlink($temp); // @ - a failed write may have left no file
			throw new \RuntimeException("Cannot write file '$this->path'.");
		} elseif (!self::rename($temp, $this->path)) {
			throw new \RuntimeException("Cannot write file '$this->path', the content is kept in '$temp'.");
		}
		$this->written = true;
	}


	/**
	 * Windows refuses a rename for a moment while an antivirus or the indexer reads a fresh file.
	 */
	private static function rename(string $from, string $to): bool
	{
		for ($attempt = 0; $attempt < self::RenameAttempts; $attempt++) {
			if (@rename($from, $to)) { // @ - failure is the false result
				return true;
			}
			usleep(self::RenameDelay);
		}
		return false;
	}


	/**
	 * Gives the name back when the work failed before the content was written.
	 */
	public function release(): void
	{
		if (!$this->written) {
			@unlink($this->path); // @ - may be gone already
		}
	}
}
