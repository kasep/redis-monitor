<?php
namespace qad\log;
use qad\cli as std;
use Closure;

require_once 'lib.cli.php';

/**
 * Select and return the current Logger.
 * If the $group name is passed, then the linked Logger is returned. When called the first time, $writer and $formatter variable is used to specify the Logger.
 * If no $group is passed, then the root Logger is returned.
 * Params:
 *   string $group = The group name.
 *   null $group = The previous group nam is returned.
 *   string,null $writer = The wanted writer closure used to write the log message.
 *   string,null $formatter = The wanted formatter closure used to construct the log message.
 * Returns:
 *   Logger = The selected Logger instance.
 */
function lookup( $group = null, $writer = null, $formatter = null )
{
	assert('is_string($group) or is_null($group)');

	static $groups = array();
	static $last = null;
	if( is_null($group) and is_null($last) ) $group = '';
	if( is_string($group) and ! isset($groups[$group]) )
	{
		if( is_null($writer) ) $writer = write();
		if( is_null($formatter) ) $formatter = format();
		assert('$writer instanceof Closure');
		assert('$formatter instanceof Closure');
		$groups[$group] = $last = new logger( $group, $writer, $formatter );
	}
	elseif( is_string($group) )
		$last = $groups[$group];

	assert('$last instanceof qad\log\Logger');
	return $last;
}

function fatal( $msg )
{
	assert('is_string($msg)');
	$args = func_get_args();
	array_shift($args);
	lookup()->log( 'FATAL', $msg, $args );
}

function error( $msg )
{
	assert('is_string($msg)');
	$args = func_get_args();
	array_shift($args);
	lookup()->log( 'ERROR', $msg, $args );
}

function warn( $msg )
{
	assert('is_string($msg)');
	$args = func_get_args();
	array_shift($args);
	lookup()->log( 'WARN', $msg, $args );
}

function info( $msg )
{
	assert('is_string($msg)');
	$args = func_get_args();
	array_shift($args);
	lookup()->log( 'INFO', $msg, $args );
}

function trace( $msg )
{
	assert('is_string($msg)');
	$args = func_get_args();
	array_shift($args);
	lookup()->log( 'TRACE', $msg, $args );
}

function format( $group = null, $level = null, $message = null )
{
	static $formatter = null;
	assert('is_string($group) or $group instanceof Closure or is_null($group)');
	if( $group instanceof Closure ) { $formatter = $group; return; }
	elseif( is_null($formatter) ) { $formatter = function( $group, $level, $message )
	{
		assert('is_string($group)');
		assert('is_string($level)');
		assert('is_string($message)');
		return sprintf('%s %s %s %s', date('c'),$group,$level,strtr($message,array("\r"=>' ',"\n"=>' ')));
	}; }
	if( is_null($group) ) return $formatter;

	assert('is_string($level) or is_null($level)');
	assert('is_string($message) or is_null($message)');
	return $formatter( $group, $level, $message );
}

function write( $message = null )
{
	static $writer = null;
	assert('is_string($message) or $message instanceof Closure or is_null($message)');
	if( $message instanceof Closure ) { $writer = $message; return; }
	elseif( is_null($writer) ) $writer = function( $message )
	{
		std\out($message);
	};
	if( is_null($message) ) return $writer;

	$writer($message);
}

class Logger
{
	private $group = '';
	private $writer = null;
	private $formatter = null;

	function __construct( $group, $writer, $formatter )
	{
		assert('is_string($group)');
		assert('$writer instanceof Closure');
		assert('$formatter instanceof Closure');
		$this->group = $group;
		$this->writer = $writer;
		$this->formatter = $formatter;
	}

	function log(	$level, $msg, $args )
	{
		assert('in_array($level,array("FATAL","ERROR","WARN","INFO","TRACE"))');
		assert('is_string($msg)');
		assert('is_array($args)');

		call_user_func( $this->writer, call_user_func( $this->formatter, $this->group, $level, @vsprintf($msg,$args) ) );
	}

	function fatal( $msg )
	{
		assert('is_string($msg)');
		$args = func_get_args();
		array_shift($args);
		$this->log( 'fatal', $msg, $args );
	}

	function error( $msg )
	{
		assert('is_string($msg)');
		$args = func_get_args();
		array_shift($args);
		$this->log( 'error', $msg, $args );
	}

	function warn( $msg )
	{
		assert('is_string($msg)');
		$args = func_get_args();
		array_shift($args);
		$this->log( 'warn', $msg, $args );
	}

	function info( $msg )
	{
		assert('is_string($msg)');
		$args = func_get_args();
		array_shift($args);
		$this->log( 'info', $msg, $args );
	}

	function trace( $msg )
	{
		assert('is_string($msg)');
		$args = func_get_args();
		array_shift($args);
		$this->log( 'trace', $msg, $args );
	}
}

