<?php declare(strict_types=1);

namespace AIAccess\Mcp;


/**
 * Decides which files the tools may read and write. A writable root is readable too. Denied paths
 * (key files and their directories) and `.env` files are refused under any root.
 */
final readonly class Sandbox
{
	/** @var list<string> */
	private array $readRoots;

	/** @var list<string> */
	private array $writeRoots;

	/** @var list<string> */
	private array $denied;


	/**
	 * @param  list<string>  $readRoots  existing directories
	 * @param  list<string>  $writeRoots  existing directories
	 * @param  list<string>  $denied  files or directories that are never accessible
	 */
	public function __construct(array $readRoots, array $writeRoots, array $denied = [])
	{
		$this->writeRoots = array_map(self::resolveRoot(...), $writeRoots);
		$this->readRoots = array_values(array_unique([...array_map(self::resolveRoot(...), $readRoots), ...$this->writeRoots]));
		$this->denied = array_map(fn(string $path) => self::normalize(realpath($path) ?: $path), $denied);
	}


	/**
	 * Returns the real path of an existing file the tools may read.
	 */
	public function resolveReadable(string $path): string
	{
		if (!self::isAbsolute($path)) {
			throw new \InvalidArgumentException("Path '$path' must be absolute.");
		}
		$real = realpath($path);
		if ($real === false || !is_file($real)) {
			throw new \InvalidArgumentException("File '$path' does not exist.");
		}

		$real = strtr($real, '\\', '/');
		if (!$this->isAllowed($real, $this->readRoots)) {
			throw new \InvalidArgumentException("Reading '$path' is not allowed. Readable roots: " . implode(', ', $this->readRoots) . '.');
		}
		return $real;
	}


	/**
	 * Returns the path of a file the tools may create, resolved through the directories that already exist.
	 */
	public function resolveWritable(string $path): string
	{
		$path = strtr($path, '\\', '/');
		if (!self::isAbsolute($path)) {
			throw new \InvalidArgumentException("Path '$path' must be absolute.");
		} elseif (array_intersect(explode('/', $path), ['.', '..'])) {
			throw new \InvalidArgumentException("Path '$path' must not contain '.' or '..' segments.");
		}

		$missing = [basename($path)];
		$dir = dirname($path);
		while (!is_dir($dir) && dirname($dir) !== $dir) {
			array_unshift($missing, basename($dir));
			$dir = dirname($dir);
		}
		$resolved = rtrim(strtr((string) realpath($dir), '\\', '/'), '/') . '/' . implode('/', $missing);

		if (!$this->isAllowed($resolved, $this->writeRoots)) {
			throw new \InvalidArgumentException("Writing '$path' is not allowed. Writable roots: " . implode(', ', $this->writeRoots) . '.');
		}
		return $resolved;
	}


	/**
	 * @param  list<string>  $roots
	 */
	private function isAllowed(string $path, array $roots): bool
	{
		$path = self::normalize($path);
		if (str_starts_with(basename($path), '.env')) {
			return false;
		}
		foreach ($this->denied as $denied) {
			if (self::isWithin($path, $denied)) {
				return false;
			}
		}
		foreach ($roots as $root) {
			if (self::isWithin($path, self::normalize($root))) {
				return true;
			}
		}
		return false;
	}


	private static function resolveRoot(string $root): string
	{
		$real = realpath($root);
		return $real !== false && is_dir($real)
			? rtrim(strtr($real, '\\', '/'), '/')
			: throw new \InvalidArgumentException("Root '$root' is not an existing directory.");
	}


	private static function isWithin(string $path, string $dir): bool
	{
		return $path === $dir || str_starts_with($path, $dir . '/');
	}


	/**
	 * Form for comparison: forward slashes, no trailing slash, case folded where the file system ignores case.
	 */
	private static function normalize(string $path): string
	{
		$path = rtrim(strtr($path, '\\', '/'), '/');
		return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
	}


	private static function isAbsolute(string $path): bool
	{
		return (bool) preg_match('#^([a-z]:)?[/\\\]#i', $path);
	}
}
