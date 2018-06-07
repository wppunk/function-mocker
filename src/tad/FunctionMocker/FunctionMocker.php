<?php

namespace tad\FunctionMocker;

use Prophecy\Prophecy\MethodProphecy;
use Prophecy\Prophecy\ProphecyInterface;
use Prophecy\Prophet;

/**
 * Class FunctionMocker
 *
 * @package tad\FunctionMocker
 */
class FunctionMocker {

	/**
	 * @var \tad\FunctionMocker\FunctionMocker
	 */
	protected static $instance;

	/** @var  bool */
	protected $didInit = false;

	/**
	 * @var object[]
	 */
	protected $revealed = [];

	/**
	 * @var ProphecyInterface[]
	 */
	protected $prophecies = [];

	/**
	 * @var MethodProphecy[]
	 */
	protected $methodProphecies = [];

	/**
	 * @var \Prophecy\Prophet
	 */
	protected $prophet;

	/**
	 * @var string
	 */
	protected $currentReplacementNamespace;

	/**
	 * @var bool Whether checks made in the `tearDown` method should be skipped or not.
	 */
	protected $_skipChecks = false;

	/**
	 * FunctionMocker constructor.
	 *
	 * @param \Prophecy\Prophet $prophet
	 */
	protected function __construct( Prophet $prophet ) {
		$this->prophet = $prophet;
	}

	/**
	 * Sets up the class to redefine, stub and mock functions, use in the `setUp` method of the test case and in conjunction
	 * with the `tad\FunctionMocker\FunctionMocker\tearDown` method.
	 *
	 * Example usage:
	 *
	 *      use \tad\FunctionMocker\FunctionMocker;
	 *
	 *      class MyTestCase extends \PHPUnit\Framework\TestCase {
	 *          public function setUp(){
	 *              FunctionMocker::setUp();
	 *          }
	 *
	 *          public function test_something_requiring_function_mocking(){
	 *              // ...
	 *          }
	 *
	 *          public function tearDown(){
	 *              FunctionMocker::tearDown($this);
	 *          }
	 *      }
	 *
	 * @see \tad\FunctionMocker\FunctionMocker::tearDown()
	 */
	public static function setUp() {
		if ( ! self::instance()->didInit ) {
			self::init();
		}
		static::instance()->prophet = new Prophet();
	}

	protected static function instance() {
		if ( static::$instance === null ) {
			static::$instance = new static( new Prophet() );
		}

		return static::$instance;
	}

	/**
	 * Initializes the mocking engine including the Patchwork library and configuring it.
	 *
	 * @param array|null $options An array of options to init the Patchwork library.
	 *                            ['include'|'whitelist']     array|string A list of absolute paths that should be included in the patching.
	 *                            ['exclude'|'blacklist']     array|string A list of absolute paths that should be excluded in the patching.
	 *                            ['cache-path']              string The absolute path to the folder where Patchwork should cache the wrapped files.
	 *                            ['redefinable-internals']   array A list of internal PHP functions that are available for replacement.
	 *                            ['env']                     array|string|bool Specifies one or more environment setup files to load immediately
	 *                                                        after including Patchwork.
	 *
	 * @see \Patchwork\configure()
	 */
	public static function init( array $options = null ) {
		$function_mocker = static::instance();

		if ( $function_mocker->didInit ) {
			return;
		}

		$envs = readEnvsFromOptions( $options );
		$options = whitelistEnvs( $options, $envs );
		writePatchworkConfig( $options );

		/** @noinspection PhpIncludeInspection */
		includePatchwork();

		require_once __DIR__ . '/utils.php';

		includeEnvs( $envs );

		$function_mocker->didInit = true;
	}

	/**
	 * Tears down the class artifacts after a test, use in the `tearDown` method of the test case and in conjunction
	 * with the `tad\FunctionMocker\FunctionMocker\setUp` method.
	 *
	 * Example usage:
	 *
	 *      use \tad\FunctionMocker\FunctionMocker;
	 *
	 *      class MyTestCase extends \PHPUnit\Framework\TestCase {
	 *          public function setUp(){
	 *              FunctionMocker::setUp();
	 *          }
	 *
	 *          public function test_something_requiring_function_mocking(){
	 *              // ...
	 *          }
	 *
	 *          public function tearDown(){
	 *              FunctionMocker::tearDown($this);
	 *          }
	 *      }
	 *
	 * @see \tad\FunctionMocker\FunctionMocker::setUp()
	 *
	 * @param null|\PHPUnit\Framework\TestCase $testCase If the method is running in
	 *                                                   the context of a PHPUnit `tearDown`
	 *                                                   method then the the current test case should
	 *                                                   be passed to this method.
	 */
	public static function tearDown( $testCase = null ) {
		\Patchwork\restoreAll();

		$instance = static::instance();

		if ( ! $instance->_skipChecks ) {
			$instance->checkPredictions( $testCase );
		}

		$instance->resetProperties();
	}

	protected function checkPredictions( $testCase = null ) {
		if ( $this->prophet === null ) {
			return;
		}

		$prophet = $this->prophet;
		$this->prophet = null;

		if ( class_exists( '\PHPUnit\Framework\TestCase' ) && $testCase instanceof \PHPUnit\Framework\TestCase ) {
			$testCase->addToAssertionCount( count( $prophet->getProphecies() ) );
		} elseif ( class_exists( 'PHPUnit_Framework_TestCase' ) && $testCase instanceof PHPUnit_Framework_TestCase ) {
			/** @noinspection PhpUndefinedMethodInspection */
			$testCase->addToAssertionCount( count( $prophet->getProphecies() ) );
		} else {
			$prophet->checkPredictions();
		}
	}

	protected function resetProperties() {
		unset( $this->prophet );
		$this->prophecies = [];
		$this->methodProphecies = [];
		$this->revealed = [];
	}

	/**
	 * Magic method to offer a flexible function-mocking API.
	 *
	 * Example usage:
	 *
	 *      use tad\FunctionMocker\FunctionMocker;
	 *
	 *      class MyTestCase extends \PHPUnit\Framework\TestCase {
	 *          public function setUp(){
	 *              FunctionMocker::setUp();
	 *          }
	 *
	 *          public function test_something_requiring_function_mocking(){
	 *              FunctionMocker::update_option('foo', ['bar'])
	 *                  ->shouldBeCalled();
	 *              FunctionMocker::get_option(Argument::type('string'))
	 *                  ->willReturn([]);
	 *
	 *              $logger = new OptionLogger($option = 'foo');
	 *              $logger->log('bar');
	 *          }
	 *
	 *          public function tearDown(){
	 *              FunctionMocker::tearDown($this);
	 *          }
	 *      }
	 *
	 * @param string $function  Handled by PHP; if calling `FunctionMocker::update_option` then this
	 *                          will be `update_option`; see usage example above.
	 * @param array  $arguments Handled by PHP
	 *
	 * @return \Prophecy\Prophecy\MethodProphecy
	 * @throws \Exception If the function could not be created.
	 */
	public static function __callStatic( $function, array $arguments ) {
		return self::replace( $function, ...$arguments );
	}

	/**
	 * Replaces a function, be it defined or not, with Patchwork.
	 *
	 * This method is the one used by the `__callStatic` implementation and is the
	 * one that should be used to replace namespaced functions.
	 *
	 * Example usage:
	 *
	 *      use tad\FunctionMocker\FunctionMocker;
	 *
	 *      class MyTestCase extends \PHPUnit\Framework\TestCase {
	 *          public function setUp(){
	 *              FunctionMocker::setUp();
	 *          }
	 *
	 *          public function test_something_requiring_function_mocking(){
	 *              FunctionMocker::replace('MyNamespace\\SubSpace\\update_option', 'foo', ['bar'])
	 *                  ->shouldBeCalled();
	 *              FunctionMocker::replace('MyNamespace\\SubSpace\\get_option', Argument::type('string'))
	 *                  ->willReturn([]);
	 *
	 *              $logger = new OptionLogger($option = 'foo');
	 *              $logger->log('bar');
	 *          }
	 *
	 *          public function tearDown(){
	 *              FunctionMocker::tearDown($this);
	 *          }
	 *      }
	 *
	 * @param string $function     The name of the function to replace, including the namespace.
	 * @param mixed  ...$arguments Arguments that are expected to be used to call the function.
	 *                             The arguments can be scalar or instances of the `Prophecy\Argument`
	 *                             class.
	 *
	 * @return \Prophecy\Prophecy\MethodProphecy
	 * @throws \Exception If the function could not be created.
	 */
	public static function replace( $function, ...$arguments ) {
		$instance = self::instance();

		list( $function, $namespace, $functionFQN ) = $instance->extractFunctionAndNamespace( $function );

		if ( $instance->currentReplacementNamespace !== null ) {
			$namespace = rtrim( '\\' . $instance->currentReplacementNamespace . '\\' . ltrim( $namespace, '\\' ), '\\' );
			$functionFQN = $namespace . '\\' . trim( $function, '\\' );
		}

		if ( ! function_exists( $functionFQN ) ) {
			\tad\FunctionMocker\createFunction( $function, $namespace );
		}

		if ( $prophecy = $instance->buildNewProphecyFor( $function, $functionFQN ) ) {
			$instance->redefineFunctionWithPatchwork( $function, $functionFQN );
		}

		return $instance->buildMethodProphecy( $function, $arguments, $instance->prophecies[ $functionFQN ] );
	}

	protected function extractFunctionAndNamespace( $function ) {
		$function = '\\' . ltrim( $function, '\\' );
		$namespaceFrags = array_filter( explode( '\\', $function ) );
		$function = array_pop( $namespaceFrags );
		$namespace = implode( '\\', $namespaceFrags );
		$functionFQN = $namespace . '\\' . $function;

		if ( $function === ltrim( $functionFQN, '\\' ) ) {
			$functionFQN = $function;
		}

		return array( $function, $namespace, $functionFQN );
	}

	protected function buildNewProphecyFor( $function, $functionFQN ) {
		if ( array_key_exists( $functionFQN, $this->prophecies ) ) {
			return false;
		}

		$class = $this->createClassForFunction( $function, $functionFQN );
		$prophecy = $this->prophet->prophesize( $class );
		$this->prophecies[ $functionFQN ] = $prophecy;

		return $prophecy;
	}

	protected function createClassForFunction( $function, $functionFQN ) {
		$uniqid = md5( uniqid( 'function-', true ) );
		$functionSlug = str_replace( '\\', '_', $functionFQN );
		$className = "_{$functionSlug}_{$uniqid}";

		eval( "class {$className}{ public function {$function}(){}}" );

		return $className;
	}

	protected function redefineFunctionWithPatchwork( $function, $functionFQN ) {
		\Patchwork\redefine( $functionFQN, function () use ( $functionFQN, $function ) {
			$prophecy = FunctionMocker::instance()->getRevealedProphecyFor( $functionFQN );
			$args = func_get_args();

			if ( empty( $args ) ) {
				return $prophecy->$function();
			}

			return call_user_func_array( [ $prophecy, $function ], $args );
		} );
	}

	protected function getRevealedProphecyFor( $function ) {
		if ( ! array_key_exists( $function, $this->revealed ) ) {
			$this->revealed[ $function ] = $this->prophecies[ $function ]->reveal();
		}

		return $this->revealed[ $function ];
	}

	protected function buildMethodProphecy( $function, array $arguments, ProphecyInterface $prophecy ) {
		if ( empty( $arguments ) ) {
			$methodProphecy = $prophecy->$function();
		} else {
			$methodProphecy = call_user_func_array( [ $prophecy, $function ], $arguments );
		}

		return $methodProphecy;
	}

	/**
	 * Localizes all function redefinitions in a namespace.
	 *
	 * Example usage:
	 *
	 *      use \tad\FunctionMocker\FunctionMocker;
	 *
	 *      class MyTestCase extends \PHPUnit\Framework\TestCase {
	 *          public function setUp(){
	 *              FunctionMocker::setUp();
	 *          }
	 *
	 *          public function test_something_requiring_function_mocking(){
	 *              FunctionMocker::inNamespace('Acme', function(){
	 *                  FunctionMocker::aFunction('foo')->willReturn('bar');
	 *                  FunctionMocker::anotherFunction('bar')->shouldBeCalled();
	 *              });
	 *
	 *              $this->assertEquals('bar', \Acme\aFunction('foo'));
	 *              \Acme\anotherFunction('bar');
	 *          }
	 *
	 *          public function tearDown(){
	 *              FunctionMocker::tearDown($this);
	 *          }
	 *      }
	 *
	 * @param string   $namespace     The namespace the redefinitions should happen into.
	 * @param callable $redefinitions A closure detailing the redefinitions that should happen
	 *                                in the namespace.
	 */
	public static function inNamespace( $namespace, callable $redefinitions ) {
		self::instance()->currentReplacementNamespace = trim( $namespace, '\\' );

		$redefinitions();

		self::instance()->currentReplacementNamespace = null;
	}

	/**
	 * Starts spying a function for its calls.
	 *
	 * @param $function
	 *
	 * @return \Prophecy\Prophecy\MethodProphecy
	 * @throws \Exception If the function could not be created.
	 */
	public static function spy( $function ) {
		return static::replace( $function );
	}

	/**
	 * Avoids checks on predictions from being performed at `tearDown` time
	 */
	public static function _skipChecks() {
		static::instance()->_skipChecks = true;
	}
}
