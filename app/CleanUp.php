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

    public function cleanUp(): void
    {
        $baseDirPathLength = mb_strlen($this->baseDirPath);

        foreach ($this->deleteLocalFiles as $filePath) {
            $filePath = realpath($filePath);
            if (!$filePath) {
                // the file does not exist
                continue;
            }

            if (mb_substr($filePath, 0, $baseDirPathLength) !== $this->baseDirPath) {
                throw new RuntimeException(
                    "The file is outside of the project's directory. "
                    . "For security reasons accessing files outside of the project is prohibited."
                );
            }

            unlink($filePath);
        }
    }
}
