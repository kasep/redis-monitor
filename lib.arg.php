<?php
namespace qad\arg;
use Closure;

function parser()
{
    return new Arguments;
}

class Arguments
{
	private $args = array();
	private $current_arg = '';
	function arg($long,$short=null)
	{
		assert('is_string($long) and preg_match("/^\w+(?:-\w+)*$/",$long)');
		assert('is_null($short) or preg_match("/^\w$/",$short)');
		if( isset($this->args[$long]) )
			$this->current_arg = $long;
		else
		{
			$arg = array();
			if( $short ) $arg['short'] = $short;
			if( strlen($long)==1 and ! empty($arg['sort']) )
				$arg['short'] = $long;
			else
				$arg['long'] = $long;
			if( $short )
				$this->args[$short] = &$arg;
			$this->args[$long] = &$arg;
			$this->current_arg = $long;
		}
		return $this;
	}

	function set( &$var )
	{
		assert('$this->current_arg');
		$this->args[$this->current_arg]['set'] = &$var;
		return $this;
	}

	function param( &$var )
	{
		assert('$this->current_arg');
		assert('strlen($this->args[$this->current_arg]["long"])>1');
		$this->args[$this->current_arg]['param'] = &$var;
		return $this;
	}

	function exe(Closure $func)
	{
		$this->args[$this->current_arg]['exe'] = $func;
		return $this;
	}

	function __get( $member )
	{
		assert('$this->current_arg');
		assert('is_string($member)');
		switch( strtolower($member) )
		{
		case'required':
			$this->args[$this->current_arg]['required'] = true;
		break;
		case'do':
			$shorts = '';
			$longs = array();
			asort($this->args);
			$last = null;
			foreach( $this->args as $arg )
			{
				if( $last == $arg ) continue;
				if( ! empty($arg['short']) )
					$shorts .= $arg['short'].(!isset($arg['param'])?(!empty($arg['required'])?':':''):':'.(!empty($arg['required'])?'':':'));
				if( ! empty($arg['long']) )
					$longs[] = $arg['long'].(!isset($arg['param'])?(!empty($arg['required'])?':':''):':'.(!empty($arg['required'])?'':':'));
				$last = $arg;
			}
			foreach( getopt($shorts,$longs) as $opt => $val )
				if( isset($this->args[$opt]) )
				{
					if( isset($this->args[$opt]['exe']) )
						$this->args[$opt]['exe']($val);
					if( isset($this->args[$opt]['set']) )
					{
						if( is_array($this->args[$opt]['set']) )
							$this->args[$opt]['set'][] = $val;
						elseif( $this->args[$opt]['set']===false )
							$this->args[$opt]['set'] = true;
						else
							$this->args[$opt]['set'] = $this->args[$opt]['long'];
					}
					if( isset($this->args[$opt]['param']) )
					{
						if( is_array($this->args[$opt]['param']) and is_array($val) )
							$this->args[$opt]['param'] = array_merge($this->args[$opt]['param'],$val);
						elseif( is_array($val) )
							$this->args[$opt]['param'] = end($val);
						elseif( is_array($this->args[$opt]['param']) and $val )
							$this->args[$opt]['param'][] = $val;
						elseif( $val )
							$this->args[$opt]['param'] = $val;
					}
				}
			break;
		default:
			throw new BadMemberException(sprintf('Access deny to member %s::%s',get_class($this),$member));
		}
		return $this;
	}
}

