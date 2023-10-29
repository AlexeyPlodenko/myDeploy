<?php declare(strict_types=1);

namespace App;

use App\Exceptions\FileException;

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

    /**
     * @param int|null $seconds Set this value to 0 or NULL to set it to infinity.
     * @return void
     */
    public function setTimeout(?int $seconds): void
    {
        $this->timeoutS = $seconds;
    }

    public function setVariable(string $name, string|int|float $value): void
    {
        $this->variables[$name] = $value;

        // flushing the cache
        unset($this->variableNames, $this->variableValues);
    }

    abstract public function build(): void;

    abstract public function applyVariablesToFile(string $filePath): void;

    /**
     * @throws FileException
     */
    protected function makeTmpDirectory(): void
    {
        $path = __DIR__ . '/../tmp/';
        if (is_file($path)) {
            throw new FileException("There is a file \"$path\", where the script needs to create a directory.");
        }
        if (is_dir($path)) {
            return;
        }
        mkdir($path);
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

        $this->variableNames = array_keys($this->variables);
        $this->variableValues = array_values($this->variables);
    }
}
