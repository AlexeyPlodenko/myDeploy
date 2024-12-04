<?php declare(strict_types=1);

namespace App;

use App\Exceptions\FileException;
use RuntimeException;

abstract class AbstractApp
{
    /**
     * @var string|int|float[]
     */
    protected array $variables = [];

    /**
     * @var string[]
     */
    protected array $variableNames;

    /**
     * @var string|int|float[]
     */
    protected array $variableValues;

    protected ?int $timeoutS;

    protected CleanUp $cleanUp;

    protected string $sourceCodePath;

    protected string $tmpPath;

    protected string $tmpDirName = 'my_deploy_tmp';

    public function __construct()
    {
        $this->cleanUp = new CleanUp(__DIR__ .'/../');
    }

    abstract public function build(): void;

    abstract public function applyVariablesToFile(string $filePath): void;

    public function setSourcePath(string $sourceCodePath): void
    {
        if (!is_dir($sourceCodePath)) {
            throw new RuntimeException("Source code path '$sourceCodePath' is not a directory.");
        }

        $this->sourceCodePath = rtrim($sourceCodePath, '/\\') .'/';
    }

    protected function getSourCodeAbsolutePath(): string
    {
        return realpath($this->sourceCodePath);
    }

    /**
     * @param int|null $seconds Set this value to 0 or NULL to set it to infinity.
     * @return void
     */
    public function setTimeout(?int $seconds): void
    {
        $this->timeoutS = $seconds;
    }

    public function getVariable(string $name): string|int|float|null
    {
        return $this->variables[$name] ?? null;
    }

    public function setVariable(string $name, string|int|float $value): void
    {
        $this->variables[$name] = $value;

        // flushing the cache
        unset($this->variableNames, $this->variableValues);
    }

    public function copyFile(string $srcPath, string $dstPath): void
    {
        copy($srcPath, $dstPath);
    }

    public function copyFileToTmpDir(string $srcPath): string
    {
        $tmpPath = $this->getAbsoluteTmpPath();

        $fileName = basename($srcPath);
        $dstPath = "$tmpPath/$fileName";
        $this->copyFile($srcPath, $dstPath);

        $this->cleanUp->deleteLocalFile($dstPath);

        $tmpRelPath = $this->getBuildDirRelativeTmpPath();
        return "$tmpRelPath/$fileName";
    }

    /**
     * @throws FileException
     */
    protected function makeTmpDirectory(): void
    {
        $path = $this->getAbsoluteTmpPath();

        if (is_file($path)) {
            throw new FileException("There is a file \"$path\", where the script needs to create a temp. directory.");
        }

        $this->cleanUp->deleteLocalDirs($path);

        if (is_dir($path)) {
            return;
        }
        mkdir($path);
    }

    protected function getAbsoluteTmpPath(): string
    {
        if (!isset($this->tmpPath)) {
            $this->tmpPath = $this->getSourCodeAbsolutePath();
            $this->tmpPath .= "/$this->tmpDirName";
        }

        return $this->tmpPath;
    }

    protected function getBuildDirRelativeTmpPath(): string
    {
        return "./$this->tmpDirName";
    }

    protected function applyVariables(string $string): string
    {
        $this->buildVariables();

        return str_replace($this->variableNames, $this->variableValues, $string);
    }

    protected function buildVariables(): void
    {
        if (isset($this->variableNames)) {
            return;
        }

        $vars = [];
        foreach (getenv() as $key => $value) {
            $vars["\${$key}"] = $value;
        }
        $vars += $this->variables;

        $this->variableNames = array_keys($vars);
        $this->variableValues = array_values($vars);
    }
}
