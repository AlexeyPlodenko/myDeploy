<?php declare(strict_types=1);

namespace App;

use Symfony\Component\Process\Process;

class DockerApp extends AbstractApp
{
    protected string $fromDockerImage;

    /**
     * @var string[]
     */
    protected array $commands = [];

    protected CleanUp $cleanUp;

    protected bool $combineRunCommands = false;

    protected string $runCommandsDelimiter = " \\\n && ";

    /**
     * @param string $fromDockerImage Use an image name from the https://hub.docker.com
     */
    public function __construct(string $fromDockerImage)
    {
        $this->setFromDockerImage($fromDockerImage);

        $this->cleanUp = new CleanUp(__DIR__ .'/../');
    }

    public function setCombineRunCommands(bool $flag = true): void
    {
        $this->combineRunCommands = $flag;
    }

    public function addCommand(string $command): void
    {
        $this->commands[] = $command;
    }

    public function addRunCommand(string $command): void
    {
        // escaping new lines for Dockerfile
        $command = str_replace("\r\n", "\n", $command);
        $command = str_replace("\r", "\n", $command);
        $command = preg_replace("/\n/", "\\\n", $command);

        $this->commands[] = "RUN $command";
    }

    public function addWorkdirCommand(string $command): void
    {
        $this->commands[] = "WORKDIR $command";
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
        $dockerFileRows = $this->getDockerFile();
        $dockerFile = implode("\n", $dockerFileRows);

        $this->writeDockerfile($dockerFile);
        $this->buildDockerImageNoCache();
    }

    public function buildDockerImage(): void
    {
        $this->execProcess(['docker', 'build', '.']);
    }

    public function buildDockerImageNoCache(): void
    {
        $this->execProcess(['docker', 'build', '--no-cache', '.']);
    }

    public function cleanup(): void
    {
        $this->cleanUp->cleanUp();
    }

    public function applyVariablesToFile(string $filePathInDocker): void
    {
        // make a temporary directory, where to put the temporary file
        $this->makeTmpDirectory();

        // create a file copy with a unique name, to insert the variables into it
        $id = uniqid('vars', true);
        $tmpFilePath = __DIR__ . "/../tmp/applyVariablesToFile-$id.php";
        $this->cleanUp->deleteLocalFile($tmpFilePath);
        copy(__DIR__ . '/../resources/php/applyVariablesToFile.php', $tmpFilePath);

        // inserting the variables and the file path into the PHP script
        $tmpVars = var_export($this->variables, true);
        $tmpFileData = file_get_contents($tmpFilePath);
        $tmpFileData = str_replace('$vars = [];', "\$vars = $tmpVars;", $tmpFileData);
        $tmpFileData = str_replace('$filePath = \'\';', "\$filePath = '$filePathInDocker';", $tmpFileData);
        file_put_contents($tmpFilePath, $tmpFileData);

        // add the PHP script file to the Docker image, to be run during the build stage
        $this->addRunCommand('mkdir -p /myDeploy/scripts/');
        $this->addCommand("ADD ./tmp/applyVariablesToFile-$id.php /myDeploy/scripts/applyVariablesToFile-$id.php");
        $this->addRunCommand("chmod +x /myDeploy/scripts/applyVariablesToFile-$id.php");
        $this->addRunCommand("php /myDeploy/scripts/applyVariablesToFile-$id.php");
    }

    protected function execProcess(array $command)
    {
        $dockerBuild = new Process($command);
        $dockerBuild->setTimeout($this->timeoutS);
        // we do not want the process to freeze doing something. So let's error if it "stuck"
        $dockerBuild->setIdleTimeout($this->timeoutS);
        $dockerBuild->start();

        foreach ($dockerBuild as $data) {
            echo rtrim($data), "\n";
        }
    }

    protected function getDockerFile(): array
    {
        $dockerFile = [];

        $fromDockerImage = $this->applyVariables($this->fromDockerImage);
        $dockerFile[] = "FROM $fromDockerImage";

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
        $dockerFilePath = __DIR__ . '/../Dockerfile';
        file_put_contents($dockerFilePath, $dockerFile);
        $this->cleanUp->deleteLocalFile($dockerFilePath);
    }
}
