<?php
namespace qad\redis;
use UnexpectedValueException, RuntimeException, InvalidArgumentException;

// {{{ ProtocolException, CommandException

class ProtocolException extends UnexpectedValueException
{
	function __construct($msg='FAIL at decoding the Redis protocol. n00b!',Exception $e=null)
	{
		parent::__construct($msg,11,$e);
	}
}

class CommandException extends InvalidArgumentException
{
	function __construct($line,Exception $e=null)
	{
		if( preg_match('/^ERR (.*)$/', $line, $m) )
		{
			parent::__construct($m[1],12,$e);
		}
		else throw new ProtocolException;
	}
}

class ConnectionException extends RuntimeException
{
    function __construct()
    {
        parent::_construct('Connection error with the Redis Server');
    }
}

// }}}

class Redis
{
	// {{{ --members

	private $host = null;
	private $port = null;

	private $con = null; // socket resource

    private $infinity = false; // the instance is a monitor

    // }}}
    // {{{ set_key_val, set_key, get_key_val

    private function set_key_val(array $args, &$use_array=null)
    {
        $use_array = false;
        if( is_array($args[0]) and count($args)==1 )
        {
            $use_array = array_keys($args[0]);
            $ret = array();
            foreach( $args[0] as $k => $v) array_push($ret,$k,$v);
            return $ret;
        }
        else return $args;
    }

    private function set_key(array $args, &$use_array=null)
    {
        $use_array = false;
        if( is_array($args[0]) and count($args)==1 )
        {
            $use_array = array_values($args[0]);
            $ret = array();
            foreach( $args[0] as $v ) array_push($ret,$v);
            return $ret;
        }
        else return $args;
    }

    private function get_key_val(array $replies, $use_array=null)
    {
        if( $use_array )
        {
            $ret = array();
            foreach( $use_array as $i => $k )
            {
                assert('array_key_exists($i,$replies)');
                $ret[$k] = $replies[$i];
            }
            return $ret;
        }
        else return $replies;
    }

	// }}}
	// {{{ __construct, __destruct

	function __construct($host='localhost', $port='6379')
	{
		assert('is_string($host)');
		assert('is_numeric($port)');

		$this->host = $host;
		$this->port = $port;
	}

	// }}}
	// {{{ __get

	function __get($member)
	{
		assert('is_string($member)');

		$this->doOpen();

		switch(strtolower($member))
		{

		// Will receive a simple bulk value (+)
		case 'ping':
			if( $this->sendCommand(strtolower($member)) and $this->receiveReplies($o) and is_array($o) )
				return (strtolower($o[0])=='pong');
			break;
		case 'quit':
			if( $this->sendCommand(strtolower($member)) and $this->receiveReplies($o) )
			{ $this->doClose(); return true; }
			break;
        case 'randomkey':
			if( $this->sendCommand(strtolower($member)) and $this->receiveReplies($o) and is_array($o) )
              return $o[0];
			break;
        case 'monitor':
            if( !$this->infinity and $this->sendCommand(strtolower($member)) ) $this->infinity = true;
            if( $this->infinity and $this->receiveReplies($o) and is_array($o) )
            {
              if( $o[0]=='OK' ) if($this->receiveReplies($o) and is_array($o) ) return $o[0];
              return $o[0];
            }
            break;

		// Will receive a list of value (*/$)
		case 'info':
			if( $this->sendCommand(strtolower($member)) and $this->receiveReplies($o) and is_array($o) )
            {
              $this->receiveReplies();
			  return array_reduce($o,function($r,$v){
                  list($k,$v) = explode(':',$v);
                  return array_merge($r,array($k=>$v));}, array());
            }
			break;
		default:
            if( isset($o) and is_string($o) ) break;
			throw new ProtocolException("Command <$member> not implemented (or do not have sufficient privileges (or do not exists)).");
		}
		return false;
	}

	// }}}
	// {{{ __call

	function __call($member, $args)
	{
		assert('is_string($member)');
		assert('is_array($args)');

		$this->doOpen();

		switch(strtolower($member))
		{

		// Will receive a simple bulk value (+)
		case 'echo': case 'get': case 'getrange': case 'getset': case 'type':
			if( $this->sendCommand(strtolower($member),$args) and $this->receiveReplies($o) and is_array($o) )
				return $o[0];
			break;

		// Will receive an OK value (+)
        case 'mset':
			$args = $this->set_key_val( $args );
		case 'select': case 'auth': case 'set': case 'setex': case 'rename':
			if( $this->sendCommand(strtolower($member),$args) and $this->receiveReplies($o) and is_array($o) )
				return (strtolower($o[0])=='ok');
			break;

		// Will receive 1 or 0 (+/:)
        case 'msetnx':
			$args = $this->set_key_val($args, $use_array);
		case 'exists': case 'expire': case 'setnx': case 'expire': case 'expireat': case 'move':
        case 'renamenx':
			if( $this->sendCommand(strtolower($member),$args) and $this->receiveReplies($o) and is_array($o) )
				return (strtolower($o[0])=='1');
			break;

		// Will receive an integer value (:)
        case 'del':
			$args = $this->set_key($args, $use_array);
		case 'append': case 'decr': case 'decrby': case 'incr': case 'incrby': case 'strlen':
		case 'setbit': case 'getbit': case 'ttl': case 'setrange': case 'persist': case 'sadd':
        case 'zadd':
			if( $this->sendCommand(strtolower($member),$args) and $this->receiveReplies($o) and is_array($o) )
				return $o[0];
			break;

		// Will receive a list of value (*/$)
		case 'mget': case 'keys': case 'smembers':
			$args = $this->set_key($args, $use_array);
			if( $this->sendCommand(strtolower($member),$args) and $this->receiveReplies($o) and is_array($o) )
				return $this->get_key_val($o, $use_array);
			break;
        case 'config_get':
            array_unshift($args,'get');
            if( $this->sendCommand('config',$args) and $this->receiveReplies($o) and is_array($o) )
            {
			  return array_reduce($o,function($r,$v){
                  end($r);
                  if( current($r)===false and key($r) ) {
                      $k = key($r); $r[$k] = (string)$v; }
                  else
                      $r[$v] = false;
                  end($r);
                  return $r;}, array());
            }
            break;

		default:
            if( isset($o) and is_string($o) ) break;
			throw new ProtocolException("Command <$member> not implemented (or do not have sufficient privileges (or do not exists)).");
		}
	}

	// }}}
	// {{{ doOpen, doClose

	private function doOpen()
	{
		if( ! is_resource($this->con) )
		{
			if( ! $this->con = fsockopen($this->host, $this->port, $errno, $errmsg) )
				throw new RuntimeException($errmsg,$errno);
		}
	}

	private function doClose()
	{
		if( is_resource($this->con) )
			$this->con = null;
	}

	// }}}
	// {{{ sendCommand

	private function sendCommand($cmd, array $args=array())
	{
		assert('$this->con');
		assert('is_string($cmd)');
		assert('is_array($args)');
		assert('$args===array() or array_filter($args,"is_scalar")');
		$args = array_map('strval',$args);
		array_unshift($args, $cmd);

		if( $this->con )
		{
			$cmd = array_reduce($args,function($a,$b){
				return sprintf("%s$%d\r\n%s\r\n",$a,strlen($b),$b);
			}, sprintf("*%d\r\n",count($args)));
			for( $o=0,$l=strlen($cmd); $o<$l; $o+=$w )
				if( false === ($w = fwrite($this->con, substr($cmd, $o))) ) throw new ConnectionException;
			return true;
		}
		return false;

	}

	// }}}
	// {{{ receiveReplies

	private function receiveReplies(&$replies=null, &$count=null, &$src=null)
	{
		assert('$this->con');

		$replies = array();
		$count = null;

		if( $this->con )
		{
			$line = rtrim($src=fgets($this->con),"\r\n");
			$status = substr($line,0,1);
			if( strpos('+-:$*',$status)!==false)
				$line = substr($line,1);

			switch( $status )
			{
			case '+': array_push($replies,$line); return true;
			case '-': throw new CommandException($line);
			case ':': array_push($replies,$line); return true;
			case '$':
				assert('is_numeric($line)');
				if( $line=='-1' ) { $replies = null; return true; }
                while( $line > 0 )
                    if( $this->receiveReplies($reply,$c,$source) )
                    {
                        array_push($replies,$reply);
                        $line -= (strlen($source));
                    }
					else assert('false && "Shouldn\'t be here!"');
                return true;
			case '*':
				assert('is_numeric($line)');
				$count = (int)$line;
				while( $line-- )
					if( $this->receiveReplies($reply) )
						array_push($replies,$reply[0]);
					else assert('false && "Shouldn\'t be here!"');
				return true;
			default:
				$replies = $line;
				return true;
			}
		}

		return false;
	}

	// }}}
}

// {{{ --unittests

if( PHP_SAPI=='cli' and basename($argv[0])==basename(__FILE__) )
{
	require_once __DIR__.'/lib.test.php';

	$r = new Redis('192.168.1.3','6379');
	\qad\test\plan(48);
	\qad\test\ok( $r->ping );
	\qad\test\is( $r->echo('Hello World!'), 'Hello World!' );
	\qad\test\ok( $r->select(0) );
	\qad\test\ok( $r->set('foo','foo') );
	\qad\test\is( $r->strlen('foo'), 3 );
	\qad\test\is( $r->append('foo','bar'), 6 );
	\qad\test\is( $r->get('foo'), 'foobar' );
	\qad\test\is( $r->setrange('foo',3,'foo'), 6 );
	\qad\test\is( $r->getrange('foo',3,5), 'foo' );
	\qad\test\ok( $r->exists('foo') );
	\qad\test\is( $r->del('foo'), 1 );
	\qad\test\notok( $r->exists('foo') );
	\qad\test\is( $r->incr('foo'), 1 );
    \qad\test\is( $r->type('foo'), 'string' );
	\qad\test\is( $r->incrby('foo',3), 4 );
	\qad\test\is( $r->decr('foo'), 3 );
	\qad\test\is( $r->decrby('foo',2), 1 );
	\qad\test\is( $r->getset('foo',0), 1 );
	\qad\test\ok( $r->mset('foo','foo','bar','bar') );
	\qad\test\is( $r->mget('foo','bar'), array('foo','bar') );
	\qad\test\ok( $r->mset(array('foo'=>'foo','bar'=>'bar')) );
	\qad\test\is( $r->mget(array('foo','bar')), array('foo'=>'foo','bar'=>'bar') );
	\qad\test\is( $r->setbit('foo',1,0), 1 );
	\qad\test\is( $r->getbit('foo',1), 0 );
	\qad\test\ok( $r->setex('foo',10,'bar') );
	\qad\test\is( $r->ttl('foo'), 10 );
    \qad\test\ok( $r->expire('foo', 5) );
	\qad\test\is( $r->ttl('foo'), 5 );
    \qad\test\ok( $r->persist('foo') );
    \qad\test\is( $r->ttl('foo'), -1 );
    \qad\test\ok( $r->expireat('foo',1) );
    \qad\test\notok( $r->exists('foo') );
    \qad\test\ok( $r->set('foo','bar') );
	\qad\test\notok( $r->setnx('foo','foo') );
	\qad\test\ok( $r->del('foo') );
	\qad\test\ok( $r->setnx('foo','foo') );
	\qad\test\ok( $r->del(array('foo')) );
	\qad\test\notok( $r->msetnx(array('foo'=>'foo','bar'=>'bar')) );
	\qad\test\ok( $r->del(array('bar')) );
	\qad\test\ok( $r->msetnx(array('foo'=>'foo','bar'=>'bar')) );
	\qad\test\is( $r->mget('foo','bar'), array('foo','bar') );
    \qad\test\is( $r->keys('foo'), array('foo') );
    \qad\test\isastring( $r->randomkey );
    \qad\test\ok( $r->rename('foo','bar') );
    \qad\test\ok( $r->renamenx('bar','foo') );
    \qad\test\is( $r->mget(array('foo','bar')), array('foo'=>'foo','bar'=>null) );
    \qad\test\isanarray( $r->config_get('*') );
	\qad\test\ok( $r->quit );
}

// }}}

