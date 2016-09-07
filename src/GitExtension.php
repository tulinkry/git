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

    public $defaults = array (
        "username" => "tulinkry",
        "repository" => null,
        "branch" => "master",
        "file" => "master.zip",
        "key" => null,
    );

    public function loadConfiguration () {
        $config = $this -> validateConfig( $this -> defaults );
        $builder = $this -> getContainerBuilder();

        Validators::assertField( $config, 'username', 'string:1..', 'configuration of \'%s\' in the git extension' );
        Validators::assertField( $config, 'repository', 'string:1..', 'configuration of \'%s\' in the git extension' );
        Validators::assertField( $config, 'file', 'string:1..', 'configuration of \'%s\' in the git extension' );
        Validators::assertField( $config, 'branch', 'string:1..', 'configuration of \'%s\' in the git extension' );

        $builder -> addDefinition( $this -> prefix( "parameters" ) )
                -> setClass( "Tulinkry\Git\Services\ParameterService", [$config ] );
    }

    public function beforeCompile () {
        $builder = $this -> getContainerBuilder();

        $router = $builder -> getByType( 'Nette\Application\IRouter' ) ?: 'router';
        if ( $builder -> hasDefinition( $router ) ) {
            $builder -> getDefinition( $router )
                    -> addSetup( '\Tulinkry\DI\GitExtension::modifyRouter(?)', [ '@self' ] );
        }

        $presenterFactory = $builder -> getByType( 'Nette\Application\IPresenterFactory' ) ?: 'nette.presenterFactory';
        if ( $builder -> hasDefinition( $presenterFactory ) ) {
            $builder -> getDefinition( $presenterFactory )
                    -> addSetup( 'setMapping', array (
                        array ( 'Git' => 'Tulinkry\Git\*Controller' ) // nette 2.4 autoloads presenters and autowires them
                    ) );
        }
    }

    public static function modifyRouter ( IRouter &$router ) {
        if ( ! $router instanceof RouteList ) {
            throw new AssertionException( 'Your router should be an instance of Nette\Application\Routers\RouteList' );
        }

        $router[] = $newRouter = new Route( "git", array ( 'module' => 'Git',
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
