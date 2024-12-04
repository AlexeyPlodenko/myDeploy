<?php declare(strict_types=1);

namespace App;

use App\Exceptions\FileException;
use RuntimeException;
use Symfony\Component\Process\Process;

class DockerApp extends AbstractApp
{
    protected string $fromDockerImage;

    /**
     * @var string[]
     */
    protected array $commands = [];

    protected bool $combineRunCommands = false;

    protected string $runCommandsDelimiter = " \\\n && ";

    protected bool $dockerCacheBustEnabled = false;

    protected string $insideDockerAppPath = '/app/';

    /**
     * @param string $fromDockerImage Use an image name from the https://hub.docker.com
     */
    public function __construct(string $fromDockerImage, string $sourceCodePath = './')
    {
        parent::__construct();

        $this->setSourcePath($sourceCodePath);
        $this->setFromDockerImage($fromDockerImage);
    }

    public function getInsideDockerAppPath(): string
    {
        return $this->insideDockerAppPath;
    }

    public function setCombineRunCommands(bool $flag = true): void
    {
        $this->combineRunCommands = $flag;
    }

    public function addCommand(string $command): void
    {
        $this->commands[] = $command;
    }

    public function prependCommand(string $command): void
    {
        array_unshift($this->commands, $command);
    }

    public function addRunCommand(string $command): void
    {
        // escaping new lines for Dockerfile
        $command = str_replace("\r\n", "\n", $command);
        $command = str_replace("\r", "\n", $command);
        $command = preg_replace("/\n/", "\\\n", $command);

        $this->addCommand("RUN $command");
    }

    public function keepDockerContainerRunning(): void
    {
        $this->addCommand('CMD ["sleep", "infinity"]');
    }

    public function addWorkdirCommand(string $path): void
    {
        $this->addCommand("WORKDIR $path");
    }

    public function setFromDockerImage(string $image): void
    {
        assert(strlen($image) > 0);

        $this->fromDockerImage = $image;
    }

    public function build(): void
    {
        $dockerFileRows = $this->getDockerFile();
        $dockerFile = implode("\n", $dockerFileRows);

        $this->writeDockerfile($dockerFile);
        $this->buildDockerImage();
    }

    public function buildWithoutCache(): void
    {
        $this->dockerCacheBustEnabled = true;
        $this->prependCommand('ARG CACHEBUST');

        $dockerFileRows = $this->getDockerFile();
        $dockerFile = implode("\n", $dockerFileRows);

        $this->writeDockerfile($dockerFile);
        $this->buildDockerImageNoCache();
    }

    public function buildDockerImage(): void
    {
        $cmd = [];

        $cmd[] = 'docker';
        $cmd[] = 'build';
        $cmd[] = '--progress=plain';

        if ($this->dockerCacheBustEnabled) {
            $cmd[] = '--build-arg';
            $cmd[] = 'CACHEBUST=' . time();
        }

        $cmd[] = '.';

        $cmd = $this->beforeExecProcess($cmd);

        $this->execProcess($cmd);
    }

    public function buildDockerImageNoCache(): void
    {
        $cmd = [];

        $cmd[] = 'docker';
        $cmd[] = 'build';
        $cmd[] = '--progress=plain';
        $cmd[] = '--no-cache';

        if ($this->dockerCacheBustEnabled) {
            $cmd[] = '--build-arg';
            $cmd[] = 'CACHEBUST=' . time();
        }

        $cmd[] = '.';

        $cmd = $this->beforeExecProcess($cmd);

        $this->execProcess($cmd);
    }

    protected function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    protected function isLinux(): bool
    {
        return PHP_OS_FAMILY === 'Linux';
    }

    /**
     * After calling this method, the rest of the Dockerfile commands will be executed without Docker cache.
     *
     * @return void
     */
    public function disableDockerCacheAfterThisLine(): void
    {
        $this->dockerCacheBustEnabled = true;
        $this->addCommand('ARG CACHEBUST');
    }

    public function cleanup(): void
    {
        $this->cleanUp->cleanUp();
    }

    /**
     * @throws FileException
     */
    public function applyVariablesToFile(string $filePathInDocker): void
    {
        // make a temporary directory, where to put the temporary file
        $this->makeTmpDirectory();
        $absTmpPath = $this->getAbsoluteTmpPath();

        // create a file copy with a unique name, to insert the variables into it
        $id = uniqid('vars', true);
        $tmpFilePath = "$absTmpPath/applyVariablesToFile-$id.php";
        $this->cleanUp->deleteLocalFile($tmpFilePath);
        copy(__DIR__ . '/../resources/php/applyVariablesToFile.php', $tmpFilePath);

        // inserting the variables and the file path into the PHP script
        $tmpVars = var_export($this->variables, true);
        $tmpFileData = file_get_contents($tmpFilePath);
        $tmpFileData = str_replace('$vars = [];', "\$vars = $tmpVars;", $tmpFileData);
        $tmpFileData = str_replace('$filePath = \'\';', "\$filePath = '$filePathInDocker';", $tmpFileData);
        file_put_contents($tmpFilePath, $tmpFileData);

        // add the PHP script file to the Docker image, to be run during the build stage
        $buildDirRelTmpPath = $this->getBuildDirRelativeTmpPath();

        $this->addRunCommand('mkdir -p /myDeploy/scripts/');
        $this->addCommand("ADD $buildDirRelTmpPath/applyVariablesToFile-$id.php /myDeploy/scripts/applyVariablesToFile-$id.php");
        $this->addRunCommand("chmod +x /myDeploy/scripts/applyVariablesToFile-$id.php");
        $this->addRunCommand("php /myDeploy/scripts/applyVariablesToFile-$id.php");
    }

    protected function execProcess(array $command): void
    {
        $dockerBuild = new Process($command);

        $cwd = $this->getSourCodeAbsolutePath();
        $dockerBuild->setWorkingDirectory($cwd);

        $dockerBuild->setTimeout($this->timeoutS);
        // we do not want the process to freeze doing something. So let's error if it "stuck"
        $dockerBuild->setIdleTimeout($this->timeoutS);


        $processCmd = $dockerBuild->getCommandLine();
        echo "Executing the command\n$processCmd\n\n";

        $dockerBuild->start();

        foreach ($dockerBuild as $output) {
            echo rtrim($output), "\n";
        }
    }

    protected function getDockerFile(): array
    {
        $dockerFile = [];

        $fromDockerImage = $this->applyVariables($this->fromDockerImage);
        $dockerFile[] = "FROM $fromDockerImage";

        $this->prependCommand("WORKDIR $this->insideDockerAppPath");
        $this->prependCommand("ADD ./ $this->insideDockerAppPath");
        $this->prependCommand("RUN mkdir $this->insideDockerAppPath");

        if ($this->combineRunCommands) {
            $commands = $this->combineRunCommands($this->commands);
        } else {
            $commands = $this->commands;
        }

        foreach ($commands as $command) {
            $dockerFile[] = $this->applyVariables($command);
        }

        return $dockerFile;
    }

    protected function combineRunCommands(array $commands): array
    {
        $res = [];
        $runCommands = [];
        foreach ($commands as $command) {
            $command = ltrim($command);
            if (preg_match('/^RUN\s+/', $command, $matches)) {
                // if the command contains a RUN instruction, let's stack with the rest of the following RUN commands
                $instructionLength = mb_strlen($matches[0]);
                $runCommands[] = mb_substr($command, $instructionLength);

            } else {
                // if this is not a RUN command, we need to merge the stacked previously RUN commands and apply them...
                if ($runCommands) {
                    $res[] = 'RUN ' . implode($this->runCommandsDelimiter, $runCommands);
                    $runCommands = [];
                }

                // ...and then apply the current command
                $res[] = $command;
            }
        }

        // if there are more RUN commands left in the stack,
        // then they were the last one in the list of commands and we need to apply them too
        if ($runCommands) {
            $res[] = 'RUN ' . implode($this->runCommandsDelimiter, $runCommands);
        }

        return $res;
    }

    protected function writeDockerfile(string $dockerFile): void
    {
        $dockerFilePath = $this->getDockerfilePath();
        file_put_contents($dockerFilePath, $dockerFile);
        $this->cleanUp->deleteLocalFile($dockerFilePath);
    }

    protected function beforeExecProcess(array $cmd): array
    {
        return $cmd;
    }

    protected function getSourCodePathDockerStyle(): string
    {
        $path = $this->getSourCodeAbsolutePath();

        if ($this->isWindows()) {
            if (!preg_match('/^[a-z]:/i', $path)) {
                throw new RuntimeException("Real source path '$path' does not start with a disk name.");
            }

            // making the disk lower case https://stackoverflow.com/questions/40213524/using-absolute-path-with-docker-run-command-not-working#comment67699400_40214650
            $pathSegments = explode(':', $path, 2);
            $pathSegments[0] = strtolower($pathSegments[0]);
            $path = implode(':', $pathSegments);

            // replaces Windows slashes with Linux ones
//            $path = str_replace(DIRECTORY_SEPARATOR, '/', $path);

//            $path = "\\$path";
        }

        return $path;
    }

    protected function getDockerfilePath(): string
    {
        return "{$this->sourceCodePath}/Dockerfile";
    }

    protected function getDockerfilePathDockerStyle(): string
    {
        $path = $this->getSourCodePathDockerStyle();
        return "{$path}\\Dockerfile";
    }
}
