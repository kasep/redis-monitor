<?php
namespace qad\cli;

function out($msg)
{
	$args = func_get_args();
	array_shift($args);
	if( isset($args[0]) and is_array($args[0]) and count($args)==1 )
		fwrite(STDOUT,@vsprintf($msg,$args[0]));
	else
		fwrite(STDOUT,@vsprintf($msg,$args));
	fwrite(STDOUT,PHP_EOL);
}

function err($msg)
{
	$args = func_get_args();
	array_shift($args);
	fwrite(STDERR,@vsprintf($msg,$args));
	fwrite(STDERR,PHP_EOL);
	fwrite(STDERR,PHP_EOL);
	exit(1);
}

function exe( $cmd, $in=null, &$out=null, &$err=null, &$ret=null )
{
	assert('is_string($cmd)');
	assert('is_string($in) or is_null($in)');

	$args = func_get_args(); array_shift($args); array_shift($args); array_shift($args); array_shift($args); array_shift($args);

	if( $proc = proc_open( escapeshellcmd($cmd).array_reduce($args,function($r,$a){
		return $r.' '.escapeshellarg($a);
	}, ''), array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')), $pipes ) )
	{
		if( is_string($in) )
		{
			fwrite($pipes[0],$in);
			fclose($pipes[0]);
		}

		$out = stream_get_contents($pipes[1]);
		fclose($pipes[1]);

		$err = stream_get_contents($pipes[2]);
		fclose($pipes[2]);

		$ret = proc_close($proc);
		return $ret==0;
	}
	else
		return false;
}

function vexe( $cmd, &$out=null, &$ret=null, $args )
{
	assert('is_string($cmd)');
	assert('is_array($args)');
	exec( escapeshellcmd($cmd).array_reduce($args,function($r,$a){
		return $r.' '.escapeshellarg($a);
	}, ''), $out, $ret );
	return $ret==0;
}

