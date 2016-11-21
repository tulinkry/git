<?php

namespace Tulinkry\Git;

use Nette\Caching\IStorage;
use Nette\IOException;
use Nette\DI\Container;
use Nette\Utils\FileSystem;
use Nette\Utils\Finder;
use Nette\Caching\Cache;
use Tracy\Debugger;
use Tulinkry\Application\UI\Presenter;
use Tulinkry\Git\Services\ParameterService;
use Tulinkry\Zip\ZipArchiver;

class GitController extends Presenter
{
    const TEMP_DIRECTORY = 'git-downloads';
    const DEFAULT_DOWNLOAD_FILE = 'master.zip';

    /** @var ParameterService @inject */
    public $parameterService;

    /** @var IStorage @inject */
    public $cache;

    /** @var Container @inject */
    public $container;

    /**
     * Internal wrapper for hash_equals function for php < 5.3
     * @param  string $hash1 hash 1
     * @param  string $hash2 hash 2
     * @return boolean        true if hash 1 and hash 2 are equal
     */
    private static function hash_equals($hash1, $hash2) {
        if(function_exists('hash_equals')) {
            return hash_equals($hash1, $hash2);
        }
        if(strlen($hash1) != strlen($hash2)) {
            return false;
        } else {
            $res = $hash1 ^ $hash2;
            $ret = 0;
            for($i = strlen($res) - 1; $i >= 0; $i--) {
                $ret |= ord($res[$i]);
            }
            return !$ret;
        }
        return false;
    }
    /**
     * Verifies the github signature for authenticated requests
     *
     * @param  string $key  shared key
     * @param  string $body body of the github response
     * @return boolean       true if the body is authenticated
     */
    public function verifySignature ( $key, $body ) {

        if ( ! isset( $_SERVER[ "HTTP_X_HUB_SIGNATURE" ] ) ) {
            Debugger::log("Updating from git: HTTP_X_HUB_SIGNATURE header is missing");
            return false;
        }

        list($algo, $hash) = explode( '=', $_SERVER[ 'HTTP_X_HUB_SIGNATURE' ], 2 ) + array ( '', '' );

        if ( ! in_array( $algo, hash_algos(), TRUE ) ) {
            Debugger::log("Updating from git: hash algorithm '$algo' is not supported.");
            return false;
        }

        return self::hash_equals( $hash, hash_hmac( $algo, $body, $key ) );
    }

    /**
     * Downloads single repository
     * @param array $repository repository configuration
     */
    private function downloadRepository($repository) {
        $download_url = sprintf( "https://github.com/%s/%s/archive/%s.zip",
                                    $repository -> username,
                                    $repository -> repository,
                                    $repository -> branch );

        if ( ( $content = @file_get_contents( $download_url ) ) === FALSE ) {
            throw new IOException("$download_url couldn't be downloaded.");
        }

        $tempDir = $this->container->parameters['tempDir'] . DIRECTORY_SEPARATOR . self::TEMP_DIRECTORY;
        FileSystem::createDir($tempDir);

        $file = tempnam($tempDir,
                        sprintf('%s-%s-%s.zip-',
                                $repository -> username,
                                $repository -> repository,
                                $repository -> branch));

        FileSystem::write($file, $content);

        $zip = new ZipArchiver;
        if(($res = $zip -> open( $file )) !== TRUE) {
            throw new IOException("The zipped file from $download_url couldn't be extracted.");
        }

        if(is_dir($repository->directory) && $repository->flush) {
            // remove all in the directory but not the directory itself
            foreach(Finder::find('*')->in($repository->directory) as $filename => $f) {
                FileSystem::delete($filename);
            }
        }

        FileSystem::createDir($repository->directory);

        $zip -> extractSubdirTo($repository->directory, sprintf('%s-%s', $repository -> repository, $repository -> branch));
        $zip -> close();
    }

   /**
     * Trigger before callbacks
     * @param  array $before   before callbacks
     * @param  string $postdata post data from github
     * @return boolean           return false if the sync should be aborted
     */
    private function runBefore($before, array $params = array()) {
        if($before && is_array($before)) {
            foreach($before as $callback) {
                if(call_user_func_array($callback, $params) === FALSE) {
                    throw new IOException("One of the callbacks prohibited the sync to be done");
                }
            }
        }
    }

    /**
     * Trigger after callbacks
     * @param  array $after after callbacks
     */
    private function runAfter($after, array $params = array ()) {
        if($after && is_array($after)) {
            foreach($after as $callback) {
                call_user_func_array($callback, $params);
            }
        }
    }

    /**
     * @return string maintenance content
     */
    private function getMaintenanceContent() {
        $content = <<<EOF
<?php

require __DIR__ . DIRECTORY_SEPARATOR . '.maintenance.php';

\$container = require __DIR__ . '/../app/bootstrap.php';

\$container->getByType(Nette\Application\Application::class)->run();
EOF;
        $content .= "\n\n/** @random " . md5($content) . " */\n";
        return $content;
    }

    /**
     * Puts the page into the maintenance mode and backups the original index file
     * @param  [type] $wwwDir path to www directory
     */
    private function shutdownPage($wwwDir) {
        $content = $this->getMaintenanceContent();

        FileSystem::copy($wwwDir . DIRECTORY_SEPARATOR . 'index.php', $wwwDir . DIRECTORY_SEPARATOR . 'index.php.backup');
        // use $mode = NULL to bypass the permission problems
        FileSystem::write($wwwDir . DIRECTORY_SEPARATOR . 'index.php', $content, $mode = NULL);
    }

    /**
     * Turns on the page from the index backup.
     * The new incoming index file is overwritten only when it does not exist or
     * has the same content as the maintenance index
     * @param  [type] $wwwDir path to www directory
     */
    private function turnonPage($wwwDir) {
        $content = $this->getMaintenanceContent();

        if(($oldIndex = @file_get_contents($wwwDir . DIRECTORY_SEPARATOR . 'index.php.backup')) === FALSE) {
            // now this is bad
            Debugger::log("Updating from git: Couldn't reproduce the old index file", Debugger::ERROR);
            throw new IOException("Couldn't read '" . $wwwDir . DIRECTORY_SEPARATOR . "index.php.backup'.");
        }

        $newIndex = @file_get_contents($wwwDir . DIRECTORY_SEPARATOR . 'index.php');


        if($newIndex === FALSE || $newIndex === $content) {
            // index has not changed (page is in maintenance)
            // or the index has disappeared (somehow)
            // => overwrite it with the old index
            //
            // use $mode = NULL to bypass the permission problems
            FileSystem::write($wwwDir . DIRECTORY_SEPARATOR . 'index.php', $oldIndex, $mode = NULL);
        }


        FileSystem::delete($wwwDir . DIRECTORY_SEPARATOR . 'index.php.backup');
    }


    /**
     * Handles the incoming request to the git route
     * 1) Reads the input from github hook
     * 2) Puts the page into maintenance
     * 3) Downloads every repository from the configuration and extracts it into its directory
     * 4) Puts the page into usual mode
     */
    public function actionDefault () {
        $down = false;
        $wwwDir = $this->container->parameters['wwwDir'];

        if ( ($postdata = file_get_contents( "php://input" )) === FALSE ) {
            Debugger::log('Updating from git: couldn\'t read input');
            $this -> error( "Couldn't read input" );
        }

        // run before callbacks
        try {
            $this->runBefore($this->parameterService->before, [ $postdata ]);
        } catch(IOException $e) {
            Debugger::log('Updating from git: ' . $e->getMessage());
            $this->error("One of the callbacks prohibited the sync to be done");
        }


        if ( ($decoded = json_decode( $postdata )) === FALSE ) {
            Debugger::log('Updating from git: Couldn\'t decode json data');
            $this -> error( "Couldn't decode json data" );
        }


        $auth = true;
        foreach($this->parameterService->repositories as $name => $repository) {
            if ( $repository->key && ! $this->verifySignature( $repository->key, $postdata ) ) {
                Debugger::log("Updating from git: The repository {$repository->repository} requested key authentication, however it failed");
                Debugger::log("Updating from git: Secret is needed to authenticate this request for repository {$repository->repository}");
                $auth = false;
            }
        }

        if(!$auth) {
            $this->error("Updating from git: Secret is needed to authenticate this request for repository {$repository->repository}");
        }

        if($this->parameterService->maintenance &&
            file_exists($wwwDir . DIRECTORY_SEPARATOR . '.maintenance.php')) {
            try {
                $this->shutdownPage($wwwDir);
                $down = true;
            } catch(IOException $e) {
                Debugger::log("Updating from git (shutdown the page): " . $e->getMessage());
            }
        }

        $errors = [];
        foreach($this->parameterService->repositories as $name => $repository) {
            try {
                $this->runBefore($repository->before, [ $postdata, $repository ]);
                $this->downloadRepository($repository);
            } catch(IOException $e) {
                Debugger::log("Updating from git: Syncing {$repository->repository} (named $name) failed");
                Debugger::log("Updating from git: " . $e->getMessage());
                $errors [$name] = $e->getMessage();
            }

            try {
                $this->runAfter($repository->after, [ $repository ]);
            } catch(IOException $e) {
                Debugger::log('Updating from git: ' . $e->getMessage());
            }

        }

        // run after callbacks
        try {
            $this->runAfter($this->parameterService->after);
        } catch(IOException $e) {
            Debugger::log('Updating from git: ' . $e->getMessage());
        }


        // clear cache
        // does not work for latte because it manages the cache in its own way

        $cache = new Cache($this->cache);
        $cache->clean(array(Cache::ALL => true));

        FileSystem::delete($this->container->parameters['tempDir'] . DIRECTORY_SEPARATOR . self::TEMP_DIRECTORY);
        FileSystem::delete($this->container->parameters['tempDir'] . DIRECTORY_SEPARATOR . "cache");
        FileSystem::createDir($this->container->parameters['tempDir'] . DIRECTORY_SEPARATOR . "cache");

        if($this->parameterService->maintenance && $down) {
            try {
                $this->turnonPage($wwwDir);
            } catch(IOException $e) {
                Debugger::log("Updating from git (turning on the page): " . $e->getMessage());
            }
        }

        $this->sendJson(array('status' => 'finished',
                              'errors' => $errors));
    }

}
