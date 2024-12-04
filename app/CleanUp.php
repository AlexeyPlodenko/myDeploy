<?php declare(strict_types=1);

namespace App;

use InvalidArgumentException;
use RuntimeException;

class CleanUp
{
    /**
     * @var string[]
     */
    protected array $deleteLocalFiles = [];

    /**
     * @var string[]
     */
    protected array $deleteLocalDirs = [];

    protected int $baseDirPathLength;

    public function __construct(protected string $baseDirPath)
    {
        if (!$this->baseDirPath) {
            throw new InvalidArgumentException('The $baseDir is empty.');
        }

        $this->baseDirPath = realpath($this->baseDirPath);
        if (!$this->baseDirPath) {
            throw new RuntimeException('The path, supplied in the $baseDir, does not exist.');
        }
    }

    public function deleteLocalFile(string $filePath): void
    {
        $this->deleteLocalFiles[] = $filePath;
    }

    public function deleteLocalDirs(string $dirPath): void
    {
        $this->deleteLocalDirs[] = $dirPath;
    }

    public function cleanUp(): void
    {
        $this->baseDirPathLength = mb_strlen($this->baseDirPath);

        foreach ($this->deleteLocalFiles as $filePath) {
            $filePath = realpath($filePath);
            if (!$filePath) {
                // the file does not exist
                continue;
            }

            $this->ensurePathIsInsideBasePath($filePath);

            unlink($filePath);
        }

        foreach ($this->deleteLocalDirs as $dirPath) {
            $dirPath = realpath($dirPath);
            if (!$dirPath) {
                // the file does not exist
                continue;
            }

            $this->ensurePathIsInsideBasePath($dirPath);

            $this->deleteDirectory($dirPath);
        }
    }

    /**
     * @throws RuntimeException
     */
    protected function ensurePathIsInsideBasePath(string $path): void
    {
        if (mb_substr($path, 0, $this->baseDirPathLength) !== $this->baseDirPath) {
            throw new RuntimeException(
                  'The file/directory is outside of the project\'s directory. '
                . 'For security reasons accessing files outside of the project is prohibited.'
            );
        }
    }

    /**
     * @author https://stackoverflow.com/a/1653776
     */
    protected function deleteDirectory(string $dir): bool
    {
        if (!file_exists($dir)) {
            return true;
        }

        if (!is_dir($dir)) {
            return unlink($dir);
        }

        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            if (!$this->deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) {
                return false;
            }

        }

        return rmdir($dir);
    }
}
