<?php

namespace Tulinkry\DI;

use Nette\Application\IRouter;
use Nette\Application\Routers\Route;
use Nette\Application\Routers\RouteList;
use Nette\DI\CompilerExtension;
use Nette\Utils\AssertionException;
use Nette\Utils\Validators;

class GitExtension extends CompilerExtension
{

    private $defaults = array (
        'repositories' => array(),
        'url' => 'git'
    );

    private $repositoryDefault = array (
        'url' => null,
        'username' => null,
        'repository' => null,
        'branch' => 'master',
        'directory' => null,
        'key' => null,
        'flush' => false,
    );



    public function loadConfiguration () {
        $config = $this->getConfig($this -> defaults);
        $builder = $this->getContainerBuilder();

        Validators::assertField( $config, 'url', 'string:1..', 'configuration of \'%\' in the git extension' );

        if(count($config['repositories']) === 0) {
            // single provider
            $config['repositories'] ['default'] = $this->validateConfig($this->repositoryDefault, $this->config);

            if(!isset($$config['repositories']['default']['directory'])) {
                $config['repositories'] ['default']['directory'] = $builder->parameters['appDir'] . DIRECTORY_SEPARATOR . '..';
            }

            unset($config['repositories']['default']['repositories']);
            foreach($this->repositoryDefault as $key => $value) {
                if($key !== 'url') {
                    unset($config[$key]);
                }
            }

        } else {
            foreach($this->repositoryDefault as $key => $value) {
                if($key !== 'url' && isset($config[$key])) {
                    throw new AssertionException("Configuration of '$key' in the git extension can't " .
                                                 "be specified when using multiple repositories");
                }
            }
        }

        $config = $this->validateConfig($this->defaults, $config);

        foreach($config['repositories'] as $name => &$repository) {

            $repository = $this->validateConfig($this->repositoryDefault, $repository);

            Validators::assertField( $repository, 'username', 'string:1..', 'configuration of \'%\' in the git extension' );
            Validators::assertField( $repository, 'repository', 'string:1..', 'configuration of \'%\' in the git extension' );
            Validators::assertField( $repository, 'flush', 'boolean', 'configuration of \'%\' in the git extension' );
            Validators::assertField( $repository, 'directory', 'string:1..', 'configuration of \'%\' in the git extension' );

            if(!isset($repository['branch']) || empty($repository['branch'])) {
                $repository['branch'] = 'master';
            }

            if(!isset($repository['url']) || empty($repository['url'])) {
                $repository['url'] = $this->config['url'];
            }

            $repository = (object) $repository;
        }

        ksort($config['repositories']);

        $builder -> addDefinition( $this -> prefix( "parameters" ) )
                -> setClass( "Tulinkry\Git\Services\ParameterService", [$config] );
    }

    public function beforeCompile () {
        $builder = $this -> getContainerBuilder();

        $urls = array ( $this->config['url'] );
        foreach($this->config['repositories'] as $name => &$repository) {
            if(isset($repository['url']) &&
                !empty($repository['url']) &&
                !in_array($repository['url'], $urls)) {
                $urls [] = $repository['url'];
            }
        }

        $router = $builder -> getByType( 'Nette\Application\IRouter' ) ?: 'router';
        if ( $builder -> hasDefinition( $router ) ) {
            foreach($urls as $url) {
                $builder -> getDefinition( $router )
                        -> addSetup( '\Tulinkry\DI\GitExtension::modifyRouter(?, ?)', [ $url, '@self' ] );
            }
        }

        $presenterFactory = $builder -> getByType( 'Nette\Application\IPresenterFactory' ) ?: 'nette.presenterFactory';
        if ( $builder -> hasDefinition( $presenterFactory ) ) {
            $builder -> getDefinition( $presenterFactory )
                    -> addSetup( 'setMapping', array (
                        array ( 'Git' => 'Tulinkry\Git\*Controller' ) // nette 2.4 autoloads presenters and autowires them
                    ) );
        }
    }

    public static function modifyRouter ( $url, IRouter &$router ) {
        if ( ! $router instanceof RouteList ) {
            throw new AssertionException( 'Your router should be an instance of Nette\Application\Routers\RouteList' );
        }

        $router[] = $newRouter = new Route( $url,
            array ( 'module' => 'Git',
                    'presenter' => "Git",
                    'action' => 'default' ) );

        $lastKey = count( $router ) - 1;
        foreach ( $router as $i => $route ) {
            if ( $i === $lastKey ) {
                break;
            }
            $router[ $i + 1 ] = $route;
        }

        $router[ 0 ] = $newRouter;
    }

}
