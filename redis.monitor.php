<?php
/**
 * Listen for results from a "monitor" command to a redis server and create statistics.
 */
namespace redis;
use ZMQ, ZMQSocket, ZMQContext; // 0MQ: The Intelligent Transport Layer: http://www.zeromq.org/
use qad\redis\Redis, qad\cli as std, qad\arg, qad\log; // Personal library: https://github.com/moechofe/QuickAnDirty
use Exception;

if( PHP_SAPI!='cli') die("Must be run in cli");

require_once 'qad/libraries/lib.cli.php';
require_once 'qad/libraries/lib.arg.php';
require_once 'qad/libraries/lib.log.php';
require_once 'qad/includes/inc.errors.php';
require_once 'qad/libraries/lib.redis.php';

// {{{ --errors management

log\lookup('monitor');
log\level('NONE');
set_error_handler('qad\error_handler');
set_exception_handler(function(Exception $e){
    std\err("%s at %s:%s",$e->getMessage(),$e->getFile(),$e->getLine());
});

// }}}
// {{{ --arguments

$zmq = 'tcp://127.0.0.1:5555'; // DSN for the 0MQ server.
$host = '127.0.0.1'; // Host of the redis server.
$port = '6379'; // Port of the redis server.
$auth = false; // Password af the redis server.
$id = false; // The server identifier.
$wait = 0.2; // Wait beetween two monitor commands.
$count_limit = 1000; // Catch 1000 commands and stop.
$time_limit = 0.5; // Catch any commands during 0.5s miminum.

arg\parser()
    ->arg('verbose','v')->exe(function(){log\level('TRACE');})
    ->arg('help','h')->exe(function(){std\out(
<<<out
Usage: {$GLOBALS['argv'][0]} -d=DSN -i=ID [-h=HOST] [-p=PORT] [-c=COUNT] [-t=TIME] [-a=AUTH] [-w=WAIT] [-h]

Catpure REDIS instructions and extract some statistics.
The capture will stop if the number of TIME seconds or the number of COUNT instructions is reach.

WARNING: If no instructions was catched during the TIME seconds, the script will continue until one commands was catched.
Cause the communication over the socket is in a blocking way.

The WAIT argument is used to create a pause beetween two catching operations.

Options:
  -h, --help               This help page.
  -d, --dsn=DSN            The DSN (Data Source Name) of the ZMQ server.
                           ex: tcp://127.0.0.1:5555
  -i, --id=ID              The unique ID of the monitored server.
                           ex: Redis_Master
  -o, --host=HOST          The HOST of the Redis server to monitor.
  -p, --port=PORT          The PORT of the Redis server to monitor.
  -c, --count-limit=COUNT  Limit the number of catched instructions in a row.
  -t, --time-limit=TIME    Limit the seconds when catching instructions in a row.
  -a, --auth=AUTH          The AUTH password for the Redis server.
  -w, --wait=TIME          Wait for TIME seconds before listing for the next commands.
  -v, --verbose            Display trace for debugging.


out
    );exit(1);})
    ->arg('dsn','d')->required->param($zmq)
    ->arg('host','o')->required->param($host)
    ->arg('post','p')->required->param($port)
    ->arg('auth','a')->required->param($auth)
    ->arg('id','i')->required->param($id)
    ->arg('wait','w')->required->exe(function($v)use(&$wait){$wait=1000000*(float)$v;})
    ->arg('count-limit','c')->required->exe(function($v)use(&$count_limit){$count_limit=$v;})
    ->arg('time-limit','t')->required->exe(function($v)use(&$time_limit){$time_limit=(float)$v;})
    ->do;

if( !parse_url($zmq) ) std\err('Missing or bad DSN argument');
if( !preg_match('/^\w+$/',$id) ) std\err('Missing or bad ID argument');
if( !$host ) std\err('Missing or bad HOST argument');
if( !is_numeric($port) ) std\err('Missing or bad PORT argument');
if( !is_numeric($wait) ) std\err('Missing of bar WAIT argument');

// }}}
// {{{ open, close

$redis = null;
function open()
{
    global $redis,$host,$port,$auth;
    $redis = new Redis($host,$port);
    if( $auth ) $redis->auth($auth);
}

// Force the monitor command to stop.
function close()
{
    global $redis;
    unset($redis);
    $redis = null;
}

// }}}
// {{{ --listen

$queue = new ZMQSocket(new ZMQContext(), ZMQ::SOCKET_REQ, "RedisMonitor");
$endpoints = $queue->getEndpoints();
if( !in_array($zmq, $endpoints['connect'])) $queue->connect($zmq);

$last = 0; // Last total_commands_processed value from the "info" REDIS command.
$current = 0; // Current total_commands_processed value for comparaison with $last.

open();
$start = microtime(true); // Store time at the begining of the capture process.
$catched = $count_limit; // Numbers of instructions left to catch.
$cmds = array();
$first = false; // The time of the first redis command catched.

while(true):

// Check if we need to stop the capture process.
while( $catched and microtime(true) < ($start+$time_limit) and $m = $redis->monitor )
{
    if( preg_match('/^(\d+\.\d+) (?:\(db \d+\) )?"([^"+]+)"/',$m,$m) )
    {
        // Store a new command
        $catched -= 1;
        list(,$time,$cmd) = $m;
        $cmd = strtolower($cmd);
        if( ! $first ) $first = $time;
        if( !isset($cmds[$cmd]) ) $cmds[$cmd] = 1;
        else $cmds[$cmd] += 1;
        log\trace( "CMD:%s ; CATCHED:%s", $cmd, $catched );
    }
}
$last = $time;

log\trace( 'FIRST:%s LAST:%s DIFF:%s',$first,$last, $last-$first);

close();

if( 'ok' != $queue->send('+'.json_encode(array(
    'id' => $id,
    'commands' => array_map(function($count)use($count_limit,$catched,$last,$first){
    log\trace( 'COUNT:%s FREQ:%s RATIO:%s', $count, $count / ($last-$first), $count / ($count_limit-$catched) );
    $return =  array(
        'ratio' => $count / ($count_limit - $catched),
        'freq' => false);
    if( $last - $first ) $return['freq'] = $count / ($last - $first);
    return $return;
}, $cmds) )))->recv() )
        std\err("Event is not send to the ZMQ server (or a protocol error, maybe).");

if( $wait > 0 ) usleep($wait);

open();
$start = microtime(true); // Store time at the begining of the capture process.
$catched = $count_limit; // Numbers of instructions left to catch.
$cmds = array();
$first = false; // The time of the first redis command catched.

endwhile;

// }}}

