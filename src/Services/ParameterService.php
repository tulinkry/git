<?php

namespace Tulinkry\Git\Services;

use Nette\Object;

class ParameterService extends Object
{

    /** @var array */
    public $params = array ();
    /** @var null */
    private $nullptr = NULL;

    public function __construct ( $config = array () ) {
        $this -> params = $config;
    }

    /**
     * @param string
     */
    public function &__get ( $name ) {
        if ( $name != "params" && isset( $this -> params[ $name ] ) ) {
            // avoid recursion
            return $this -> params[ $name ];
        }
        return $this->nullptr;
    }

}
