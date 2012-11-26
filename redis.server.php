<?php
/**
 * Server for storing stats, info and config from redis.monitor.php and redis.info.php
 */
namespace redis;
use ZMQ, ZMQException, ZMQPoll, ZMQPollException, ZMQContext; // 0MQ: The Intelligent Transport Layer: http://www.zeromq.org/
use qad\cli as std, qad\arg, qad\log; // Personal library: https://github.com/moechofe/QuickAnDirty
use Exception;

if( PHP_SAPI!='cli') die("Must be run in cli");

require_once 'qad/libraries/lib.cli.php';
require_once 'qad/libraries/lib.arg.php';
require_once 'qad/libraries/lib.log.php';
require_once 'qad/includes/inc.errors.php';

// {{{ --errors management

log\lookup('server');
log\level('NONE');
set_error_handler('qad\error_handler');
set_exception_handler(function(Exception $e){
    std\err("%s at %s:%s",$e->getMessage(),$e->getFile(),$e->getLine());
});

// }}}
// {{{ --arguments

$zmq = 'tcp://127.0.0.1:5555'; // DSN for the 0MQ server.
arg\parser()
    ->arg('verbose','v')->exe(function(){log\level('TRACE');})
    ->arg('help','h')->exe(function(){std\out(
<<<out
Usage: {$GLOBALS['argv'][0]} -d=DSN [-h]

Options:
  -h, --help        This help page.
  -d, --dsn         The DSN (Data Source Name) for the ZMQ server.
                    ex: tcp://127.0.0.1:5555
  -v, --verbose     Display trace for debugging.

out
    );exit(1);})
    ->arg('dsn','d')->required->param($zmq)
    ->do;

// }}}
// {{{ process

/**
 * Receive a command from a ZMQ client and process it.
 * Param: ZMQSocket
 */
function process($socket)
{
    global $commands, $infos, $log, $fives, $slowlog;
    $msg = $socket->recv();
    log\trace('MSG: %s',$msg);
    switch(true)
    {

    // Store stats for a command.
    // Format of the ZMQ command: "+<data>"
    // With <data> is a JSON encoded associative array containing informations.
    // Format of <data>: {id:ID, cmd:CMD, ratio:RATIO, freq:FREQ}
    // Will return "ok" to the ZMQ client.
    case ($msg[0]=='+' and is_object($data=json_decode(substr($msg,1)))):
        log\trace("Add stats commands for server: %s",$data->id);
        log\trace($msg);
        $commands[$data->id] = $data->commands;
        $socket->send('ok');
        break;

    // Return the actual informations about a redis server.
    // Format of the ZMQ command: "=<id> info".
    // With <id> is the Redis name unique ID.
    // Will return a JSON encoded object.
    case (preg_match('/^=(\w+) info$/',$msg,$m)):
        list(,$id) = $m;
        log\trace("Getting info for server: %s",$id);
        if( isset($infos[$id]) ) $socket->send($infos[$id]);
        else $socket->send(json_encode(false));
        break;

    // Return a computed report for commands sent to a Redis server.
    // Format of the ZMQ command: "=<id> report"
    // With <id> is the Redis name unique ID.
    // Will return a JSON encoded object.
    case (preg_match('/^=(\w+) report$/',$msg,$m)):
        list(,$id) = $m;
        log\trace("Getting report for server: %s",$id);
        if( ! isset($commands[$id]) ) { $socket->send(json_encode(false)); break; }
        $reports = array('reads'=>0,'writes'=>0,'others'=>0,'totals'=>0);
        foreach( $commands[$id] as $cmd => $stat )
        {
            switch($cmd)
            { // {{{ --group
            case 'append': case 'decr': case 'decrby': case 'del': case 'expire': case'expireat':
            case 'flushall': case 'fluchdb': case 'getbit': case 'getrange': case 'hdel':
            case 'hincrby': case 'hmset': case 'hset': case 'hsetnx': case 'incr': case 'incrby':
            case 'linsert': case 'lpush': case 'lpushx': case 'lrem': case 'lset': case 'ltrim':
            case 'move': case 'mset': case 'msetnx': case 'persist': case 'rename': case 'renamenx':
            case 'rpush': case 'rpushx': case 'sadd': case 'sdiffstore': case 'set': case 'setbit':
            case 'setex': case 'setrange': case 'sinterstore': case 'smove': case 'sort': case 'srem':
            case 'sunionstore': case 'zadd': case 'zincrby': case 'zinterstore': case 'zrem':
            case 'zremrangebyrank': case 'zremrangebyscore': case 'zunionstore':
            case 'restore': case 'psetex':
                $read = false;
                $write = true;
                $other = false;
                break;

            case 'exists': case 'get': case 'getbit': case 'getrange': case 'getset': case 'hexists':
            case 'hgetall': case 'hlen': case 'hmget': case 'hvals': case 'keys': case 'lindex':
            case 'llen': case 'lrange': case 'mget': case 'randomkey': case 'scard': case 'sdiff':
            case 'sinter': case 'sismember': case 'smembers': case 'srandommember': case 'strlen':
            case 'sunion': case 'ttl': case 'type': case 'zcard': case 'zcount': case 'zrandebyscore':
            case 'zrank': case 'zrevrank': case 'zscore':
            case 'bitop': case 'bitcount':
            case 'pexpire': case 'pexpireat': case 'pttl':
            case 'dump':
                $read = true;
                $write = false;
                $other = false;
                break;

            case 'lpop': case 'rpop': case 'rpoplpush': case 'spop':
                $read = true;
                $write = true;
                $other = false;
                break;

            default:
                $read = false;
                $write = false;
                $other = true;
                break;
            } // }}}
            if( $read ) $reports['reads']+=$stat->freq;
            if( $write ) $reports['writes']+=$stat->freq;
            if( $other ) $reports['others']+=$stat->freq;
            $reports['totals'] += $stat->freq;
        }
        $socket->send(json_encode($reports));
        break;

    // Store informations about the server.
    // Format of the ZMQ command: "§<data>";
    // With <data> is a JSON encoded associative array containing informations.
    // Format of <data>: {total_commands_processed,:NUM, total_connections_received:NUM}
    // Will return "ok" to the ZMQ client.
    case (preg_match('/§(\w+) (.*)$/',$msg,$m)):
        list(,$id,$data) = $m;
        log\trace("Add infos for server: %s",$id);
        $infos[$id] = $data;
        $socket->send('ok');

        // Extract number of connections and commands.
        $data = json_decode($data);
        $time = time();
        if( !isset($fives[$id]) ) $fives[$id] = array();
        $fives[$id][ ceil($time / 10) ] = array('cmd'=>$data->total_commands_processed, 'cnx'=>$data->total_connections_received);
        unset($fives[$id][ ceil($time / 10) - 32]);
        break;

    // Get an average number of commands and connections per seconds from a given number of last five minutes
    // Format of the ZMQ command: "~<id> five"
    // With <id> is the Redis name unique ID.
    case (preg_match('/~(\w+) five/',$msg,$m)):
        list(,$id) = $m;
        // Work on a copy
        $time = time();
        $first_time = ceil($time / 10) - 31;
        $last_time = ceil($time / 10) - 1;
        if( !isset($fives[$id]) ) $fives[$id] = array();
        if( isset($fives[$id][$first_time]) ) $first = $fives[$id][$first_time];
        else $first = false;
        if( isset($fives[$id][$last_time]) ) $last = $fives[$id][$last_time];
        else $last = false;
        if( !$first or !$last ) $socket->send( json_encode(array( 'cmd'=>0, 'cnx'=>0 )) );
        else $socket->send( json_encode( array(
            'cmd' => ($last['cmd'] - $first['cmd']) / (($last_time - $first_time) * 10),
            'cnx' => ($last['cnx'] - $first['cnx']) / (($last_time - $first_time) * 10) ) ) );
        break;

    // Store the slowlog for a server.
    // Format of the ZMQ command: "#<id> <data>"
    // With <data> is a JSON encoded associative array containing informations.
    // Format of <data>: {num:NUM, time:TIME, duration:DURATION, cmd:CMD}
    case (preg_match('/\#(\w+) (.+)/',$msg,$m)):
        list(,$id,$data) = $m;
        log\trace("Add slowlog infos for server: %s",$id);
        log\trace($data);
        $data = json_decode($data);
        if($data) $slowlog[$id] = $data;
        $socket->send('ok');
        break;

    // Return infos about the slowlog.
    // Format of the ZMQ command: "!<id>,<id>,..."
    case ($msg[0]=='!'):
        $ids = explode(',',substr($msg,1));
        log\trace("Return slowlog for ids: %s",json_encode($ids));
        $return = array();
        foreach( $slowlog as $id => $server )
            foreach( $server as $log )
                if( in_array($id,$ids) ) $return[] = array('id'=>$id,'num'=>$log[0],'time'=>$log[1],'duration'=>$log[2],'cmd'=>$log[3]);
        usort($return,function($a,$b){
            return $a['time'] < $b['time'] ? -1 : 1;
        });
        $socket->send(json_encode($return));
        break;

    default:
        log\error('Unknow instruction: %s',$msg);
        $socket->send('error: unknow command');
    }
}

// }}}
// {{{ --poll

$context = new ZMQContext();
$server = $context->getSocket(ZMQ::SOCKET_REP);
$server->bind($zmq);

$poll = new ZMQPoll();
$poll->add($server, ZMQ::POLL_IN);

$reads = array();
$writes = array();

$commands = array(); // List of all Commands object for each monitored server.
$infos = array(); // List of infos array from server.
$fives = array(); // Store number of connections and commands every five minutes for each server.
$slowlog = array(); // Store information about slow commands for each server.

$last = 0; // The last local time (not the redis time) when the stats has been updated.
while(true)
{
    $start = microtime(true);
    $events = 0;

    try
    {
        $events = $poll->poll($reads, $writes, -1);
        $errors = $poll->getLastErrors();
        if( $errors ) foreach( $errors as $e ) std\err($e);
    }
    catch( ZMQPollException $e ) { std\err('Poll failed: %s',$e->getMessage()); }

    if( $events > 0 )
    {
        foreach( $reads as $r )
            try { process($r); }
            catch( ZMQException $e ) { std\err('Receive failed: %s',$e->getMessage()); }
    }
}

// }}}

