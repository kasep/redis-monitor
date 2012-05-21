<?php
/**
 * Listen for results from a "info" and "config get" commands to a redis server and store it.
 */
namespace redis;
use ZMQ, ZMQSocket, ZMQContext; // 0MQ: The Intelligent Transport Layer: http://www.zeromq.org/
use qad\redis\Redis, qad\cli as std, qad\arg, qad\log; // Personal library: https://github.com/moechofe/QuickAnDirty
use Exception, qad\redis\ProtocolException;

if( PHP_SAPI!='cli') die("Must be run in cli");

require_once 'qad/libraries/lib.cli.php';
require_once 'qad/libraries/lib.arg.php';
require_once 'qad/libraries/lib.log.php';
require_once 'qad/includes/inc.errors.php';
require_once 'qad/libraries/lib.redis.php';

// {{{ --errors management

log\lookup('info');
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
$wait = 5000; // Wait beetween two info/config commands.
arg\parser()
    ->arg('verbose','v')->exe(function(){log\level('TRACE');})
    ->arg('help','h')->exe(function(){std\out(
<<<out
Usage: {$GLOBALS['argv'][0]} -d=DSN -i=ID -h=HOST -p=PORT [-a=AUTH] [-w=WAIT] [-h]

Options:
  -h, --help        This help page.
  -d, --dsn=DSN     The DSN (Data Source Name) of the ZMQ server.
                    ex: tcp://127.0.0.1:5555
  -i, --id=ID       The unique ID of the monitored server.
                    ex: Redis_Master
  -o, --host=HOST   The HOST of the Redis server to monitor.
  -p, --port=PORT   The PORT of the Redis server to monitor.
  -a, --auth=AUTH   The AUTH password for the Redis server.
  -w, --wait=TIME   Wait for TIME seconds before listing for the next infos.
  -v, --verbose     Display trace for debugging. (not used)

out
    );exit(1);})
    ->arg('dsn','d')->required->param($zmq)
    ->arg('host','o')->required->param($host)
    ->arg('post','p')->required->param($port)
    ->arg('auth','a')->required->param($auth)
    ->arg('id','i')->required->param($id)
    ->arg('wait','w')->required->exe(function($v)use(&$wait){$wait=1000000*(float)$v;})
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

open();
while(true)
{
    // Catch info.
    $i = array_intersect_key($infos = $redis->info,
        array_flip(array('uptime_in_days','used_memory_human','used_memory_peak_human','mem_fragmentation_ratio','total_connections_received','total_commands_processed','expired_keys','evicted_keys','used_cpu_user_children','used_cpu_user','connected_clients','process_id','connected_slaves','blocked_clients','changes_since_last_save','bgsave_in_progress','last_save_time','role','used_memory','uptime_in_seconds')));

    // Compute database infos.
    $i['db'] = array();
    foreach( $infos as $k => $v ) if( preg_match('/^db(\d+)$/',$k,$m) )
        $i['db'] += array($m[1] => array_reduce(explode(',',$v),function($r,$v){
            list($k,$v) = explode('=',$v);
            return $r + array($k=>$v);
        }, array()));

    // Catch config.
    $i += array_intersect_key($redis->config_get('*'),
        array_flip(array('maxmemory','maxmemory-policy','save')));

    // Catch showlog.
    try
    {
        $log = $redis->slowlog('get','10');
        foreach( $log as $k=>$v )
        {
            $log[$k][0] = (int)$log[$k][0];
            $log[$k][1] = (int)$log[$k][1];
            $log[$k][2] = (int)$log[$k][2];
            $log[$k][3] = implode(' ',$v[3]);
        }
    }
    catch( ProtocolException $e )
    {
        // my lib.redis.php script is a shit, don't use it.
    }

    close();

    // Send all infos.
    if( 'ok' != $queue->send(sprintf('§%s %s',$id,json_encode($i)))->recv() )
        std\err("Event is not send to the ZMQ server (or a protocol error, maybe).");
    if( 'ok' != $queue->send(sprintf('#%s %s',$id,json_encode($log)))->recv() )
        std\err("Event is not send to the ZMQ server (or a protocol error, maybe).");

    if( $wait > 0 ) usleep($wait);

    open();
}

// }}}

