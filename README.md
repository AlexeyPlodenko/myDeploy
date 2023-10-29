# myDeploy

This tool purpose is to run a build process in the local/development environment using a Docker container, for isolation, and without relying on 3rd party services.

## To start

Create a `/deploy.php` file in the root of the project and use the following code as boilerplate for that file. Here I am building a Laravel project on PHP 8.1 from GitHub, using Ubuntu as a build OS, installing Composer and NPM packages, and deploying the files to the hosting environment via an FTPs connection.

```php
<?php
require 'vendor/autoload.php';

// 'ubuntu' is the name of the FROM image for Dockerfile
$app = new \App\DockerApp('ubuntu');
$app->setTimeout(3 * 3600); // set the PHP script execution time limit to 3 hours
$app->setCombineRunCommands(true); // set this to false, while developing/debugging this script, to speed up the builds
try {
    $app->setVariable('$ENV', 'production');
    $app->setVariable('$LARAVEL_DB_HOST', '');
    $app->setVariable('$LARAVEL_DB_DATABASE', '');
    $app->setVariable('$LARAVEL_DB_USERNAME', '');
    $app->setVariable('$LARAVEL_DB_PASSWORD', '');
    $app->setVariable('$GIT_USERNAME', '');
    $app->setVariable('$GIT_TOKEN', '');
    $app->setVariable('$FTP_HOST', 'ftp://HOSTING:21');
    $app->setVariable('$FTP_USERNAME', '');
    $app->setVariable('$FTP_PASSWORD', '');

    // Get updates
    $app->addRunCommand('apt update');

    // Install the packages required for the next steps
    $app->addRunCommand('apt install -y software-properties-common curl git zip lftp');

    // Upgrade the ssl-cert library for lftp
    $app->addRunCommand('apt-get upgrade -y ssl-cert');

    // Install PHP 8.1
    $app->addRunCommand('add-apt-repository ppa:ondrej/php');
    $app->addRunCommand('DEBIAN_FRONTEND=noninteractive TZ=Etc/UTC apt install -y php8.1-cli php8.1-dom');

    // Create the app. directory
    $app->addRunCommand('mkdir -p /app/');

    // Clone the Git repository to the /app/ directory
    $app->addRunCommand(
        'git clone -b main https://$GIT_USERNAME:$GIT_TOKEN@'
        . 'github.com/AUTHOR/REPOSITORY.git /app/'
    );
    $app->addWorkdirCommand('/app/');

    // Install Composer
    $app->addRunCommand(
        'curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer'
    );

    // Install PHP packages
    $app->addRunCommand('composer install --optimize-autoloader --no-dev');

    // Install NodeJS 18
    $app->addRunCommand('curl https://deb.nodesource.com/gpgkey/nodesource.gpg.key | apt-key add -');
    $app->addRunCommand('apt-add-repository "deb https://deb.nodesource.com/node_18.x $(lsb_release -sc) main"');
    $app->addRunCommand('apt update');
    $app->addRunCommand('apt install -y nodejs');

    // Install Node packages
    $app->addRunCommand('cd /app/');
    $app->addRunCommand('npm ci');

    // Build the static assets (JS, CSS, Images,..)
    $app->addRunCommand('npm run build');

    // Make Laravel stuff
    $app->addRunCommand('cp /app/.env.$ENV /app/.env');
    // try to keep the Dockerfile COPY/ADD commands (applyVariablesToFile())
    // as close to the end of the Dockerfile as possible, to prevent the Docker layers cache invalidations
    $app->applyVariablesToFile('/app/.env');

    $app->addRunCommand('php artisan config:cache');
    $app->addRunCommand('php artisan event:cache');
    $app->addRunCommand('php artisan route:cache');
    $app->addRunCommand('php artisan view:cache');

    // Upload the files to the server
    $lftpCommands = [];
    $lftpCommands[] = 'set ftp:ssl-protect-data true';
    $lftpCommands[] = 'set ftp:ssl-force true';
    $lftpCommands[] = 'set ftp:ssl-auth TLS';
    $lftpCommands[] = 'set ssl:verify-certificate no';
    $lftpCommands[] = 'set ftps:initial-prot P';
    $lftpCommands[] = 'open $FTP_HOST';
    $lftpCommands[] = 'user $FTP_USERNAME $FTP_PASSWORD';
    $lftpCommands[] = 'lcd /app/';
    $lftpCommands[] = 'ls -R';
    $lftpCommands[] = 'mirror --reverse --depth-first --parallel=10 --verbose=1 --no-symlinks --no-perm --exclude /app/.git/ --exclude /app/.docker/ --exclude /app/storage/ /app/ /';
    $lftpCommandsString = implode('; ', $lftpCommands);
    $app->addRunCommand("lftp -c \"$lftpCommandsString\"");

    $app->build();
    // use the build without cache, when you want to avoid the Docker cache
//    $app->buildWithoutCache();
} finally {
    // comment this line out to be able to debug the created Dockerfile and file copy scripts in the /tmp/ directory
    $app->cleanup();
}
```
