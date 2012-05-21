<?php
namespace redis;
use ZMQ, ZMQSocket, ZMQContext;

define('default_zmq_server','tcp://127.0.0.1:5555');
define('default_list_server','6379,6380,6381,6382');

set_time_limit(1);

function connect($dsn)
{
    global $queue;
    $queue = new ZMQSocket(new ZMQContext(), ZMQ::SOCKET_REQ, "RedisMonitor");
    $endpoints = $queue->getEndpoints();
    if( !in_array($dsn, $endpoints['connect'])) $queue->connect($dsn);
}

switch(true)
{
// Retreive informations about on monitored Redis server from the ZMQ server.
case ( !empty($_GET['update']) and preg_match('/^tcp:\/\/([\w.]+):(\d+)$/',$_GET['update'])
and !empty($_GET['id']) and preg_match('/^\w+$/',$_GET['id']) ):
    header('content-type: application/json');
    connect($_GET['update']);
    $report = $queue->send("={$_GET['id']} report")->recv();
    $info = $queue->send("={$_GET['id']} info")->recv();
    $average = $queue->send("~{$_GET['id']} five")->recv();
    echo '{"info":',$info,',"report":',$report,',"average":',$average,'}';
    exit;
// Retrieve slowlog for all monitored Redis server from the ZMQ server.
case ( !empty($_GET['slowlog']) and preg_match('/^tcp:\/\/([\w.]+):(\d+)$/',$_GET['slowlog'])
and !empty($_GET['ids']) and preg_match('/^(\w+)(,\w+)*$/',$_GET['ids']) ):
    header('content-type: application/json');
    connect($_GET['slowlog']);
    $slowlog = $queue->send("!{$_GET['ids']}")->recv();
    echo $slowlog;
    exit;
}

?>
<!doctype html>
<html>
<head>
<script src="http://ajax.googleapis.com/ajax/libs/jquery/1/jquery.min.js"></script>
<script src="http://github.com/pure/pure/raw/master/libs/pure.js"></script>
<script src="https://ajax.googleapis.com/ajax/libs/jqueryui/1.8.16/jquery-ui.min.js"></script>
<link rel="stylesheet" href="http://twitter.github.com/bootstrap/assets/css/bootstrap.css" />
<script src="http://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/2.0.2/bootstrap.min.js"></script>
<script src="http://cdnjs.cloudflare.com/ajax/libs/highcharts/2.2.1/highcharts.js"></script>

<style>
.instructions:after, .reads:after, .writes:after, .others:after { content: "/s" }
.uptime:after { content: "d" }
.memory:after, .peak:after { content: "o" }
.cpu:after { content: "%" }
.process:before { content: "PID:" }
.client:before { content: "clients:" }
.navbar a { cursor: default; }
dt { cursor: default; }
.item .navbar-inner { background-color: #07c; background-image: -moz-linear-gradient(top, #08c, #05c); background-image: -ms-linear-gradient(top, #08c, #05c); background-image: -webkit-gradient(linear, 0 0, 0 100%, from(#08c), to(#05c)); background-image: -webkit-linear-gradient(top, #08c, #05c); background-image: -o-linear-gradient(top, #08c, #05c); background-image: linear-gradient(top, #08c, #05c); background-repeat: repeat-x; filter: progid:DXImageTransform.Microsoft.gradient(startColorstr='#08c', endColorstr='#05c', GradientType=0); }
.item .navbar .nav > li > a { color: #9bf; }
.item .row .well { padding: 7px; text-align: center; }
header { margin-top: 3em; }
.no-well { background-color: inherit; border-color: white; box-shadow: none; }
.no-well h6 { text-align: right; }
#refresh, #auto { cursor: pointer; }
.navbar .brand { color: white; }
</style>
</head>
<body class=container>

<header class=row>

<div class="span12 navbar"><div class=navbar-inner><div class=container>
    <a class=brand>Redis monitor</a>
    <div class=nav-collapse>
        <form class="navbar-form pull-left">
        <input id=zmq class=span2 value="<?php echo default_zmq_server ?>" placeholder="<?php echo default_zmq_server ?>" rel=tooltip title="The DSN of the ZMQ server" />
        <input id=list class=span2 value="<?php echo default_list_server ?>" rel=tooltip title="The list of server's identifier" />
        </form>
    </div>
    <ul class="nav nav-tab">
        <li class="divider-vertical"></li>
        <li class=active><a href="#dashboard">Stats</a></li>
        <li><a href="#slowlog">Slow log</a></li>
    </ul>
    <ul class="nav pull-right">
        <li><a id=refresh rel=tooltip title="Refresh the data">Refresh</a></li>
        <li><a id=auto rel=tooltip title="Auto refresh data during 20 seconds">Auto</a></li>
    </ul>
</div></div></div>

</header>

<div class="tab-content">
<div class="tab-pane active" id="dashboard">

<article>

<div class=row id=servers>
    <div class="span6 item" id=server>
        <div class=navbar><div class=navbar-inner><div class=container>
            <a class="brand id" href=#></a>
            <ul class=nav>
                <li><a class=process rel=tooltip title="Process ID"></a></li>
                <li><a rel=tooltip title="Role"><span class="badge badge-warning role"></span></a></li>
            </ul>
            <ul class="nav pull-right">
                <li><a class=client rel=tooltip title="Actual connected clients"></a></li>
                <li><a class=uptime rel=tooltip title="UP time in days"></a></li>
            </ul>
        </div></div></div>
        <div class="alert alert-danger" style="display:none">
            <strong>Alert:&nbsp;</strong><span class="badge badge-important evicted">?</span> records was evicted!
        </div>
        <div class="alert" style="display:none">
            <strong>Warning:&nbsp;</strong><span class="badge badge-warning blocked">?</span> clients was blocked!
        </div>
        <div class=row>
            <div class=span2><div class="well no-well"><h6>Totals:</h6></div></div>
            <div class=span2><div class=well rel=tooltip title="Total connections"><span class=connections>?</span> cnxs</div></div>
            <div class=span2><div class=well rel=tooltip title="Total commands"><span class=commands>?</span> cmds</div></div>
            <div class=span2><div class="well no-well"><h6>Average:</h6></div></div>
            <div class=span2><div class=well rel=tooltip title="Average connections from last restart"><span class=ave-connections>?</span> cnxs/s</div></div>
            <div class=span2><div class=well rel=tooltip title="Average commands from last restart"><span class=ave-commands>?</span> cmds/s</div></div>
            <div class=span2><div class="well no-well"><h6>Last 5 minutes:</h6></div></div>
            <div class=span2><div class=well rel=tooltip title="Average connections from last 5 minutes"><span class=five-connections>?</span> cnxs/s</div></div>
            <div class=span2><div class=well rel=tooltip title="Average commands from last 5 minutes"><span class=five-commands>?</span> cmds/s</div></div>
        </div>
        <h4>Memory: <small>Used:&nbsp;<span class=memory>?</span> &#8210; Peak:&nbsp;<span class=peak>?</span> &#8210; Max&nbsp;<span class=max>?</span></small></h4>
        <div class=progress>
            <div class="bar memory-bar"></div>
        </div>
        <h4>Records:</h4>
        <div class=row>
            <div class=span3><div class=well rel=tooltip title="Actual number of records"><span class=records>?</span> actual records</div></div>
            <div class=span3><div class=well rel=tooltip title="Number of expired records"><span class=expired>?</span> expired records</div></div>
        </div>
        <h4>Save: <small>Config:&nbsp;<span class=save>?</span></small></h4>
        <div class=row>
            <div class=span4><div class=well rel=tooltip title="Last background save"><span class=last>?</span></div></div>
            <div class=span2><div class="save-progress alert alert-info" style="display:none">In progress</div></div>
        </div>
        <h4>Commands per seconds:</h4>
        <div class=rw></div>
    </div>
</div>

</article>

</div>
<div class="tab-pane active" id="slowlog">

<article>

<div class=row id=tablelog><div class=span12>
<table class="table table-condensed">
    <thead>
        <tr>
            <th>#</th>
            <th>Date</th>
            <th>Duration</th>
            <th>Command</th>
        </tr>
    </thead>
    <tbody>
        <tr class=item>
            <td class=id></td>
            <td class=date></td>
            <td class=duration></td>
            <td class=cmd></td>
        </tr>
    </tbody>
</table>
</div></div>

</article>

</div>
</div>

</body>
<script>
// {{{ --helpers

// Convert an epoch time into a human readable date/time.
function mtd(milliseconds)
{
    var d = new Date(milliseconds * 1000);
    return d.toLocaleDateString() + " " + d.toLocaleTimeString();
}

// Convert a number of bytes into an human readable format.
function bts(bytes)
{
    var sizes = ['n/a','b','Ko','Mo','Go','To','Po','Eo','Zo','Yo'];
    var i = +Math.floor(Math.log(bytes) / Math.log(1024));
    return (bytes / Math.pow(1024, i)).toFixed(2) + '' + sizes[ isNaN( bytes )?0:i+1 ];
};

// }}}
// {{{ update

function update(both)
{
    if( $("#dashboard:visible").length || both ) update_stats();
    else if( $("#slowlog:visible").length || both ) update_slowlog();
}

// }}}
// {{{ update_stats

var item = $("#server").remove();

function update_stats() {
    $("#servers .item").each(function(k,v){
    var server = $("#zmq").val();
    if(!server) server = $("#zmq").attr("placeholder");
    $.getJSON('', {
        update: server,
        id: $(this).attr("id").substr(7) },function(data) {
            var memory_usage = (parseInt(data.info.used_memory) / parseInt(data.info.maxmemory) * 100);
            $(".process",v).text(data.info.process_id);
            $(".role",v).text(data.info.role);
            $(".uptime",v).text(data.info.uptime_in_days);
            $(".blocked",v).text(data.info.blocked_clients).closest(".alert").toggle( (data.info.blocked_clients > 0) );
            $(".memory",v).text(data.info.used_memory_human);
            $(".max",v).text(bts(data.info.maxmemory));
            $(".peak",v).text(data.info.used_memory_peak_human);
            $(".memory-bar",v).width( memory_usage.toFixed(1) + '%' ).closest(".progress").removeClass("progress-success progress-danger progress-warning").addClass( "progress-" + ((memory_usage > 90)?'danger':(memory_usage > 70)?'warning':'success') )
            $(".connections",v).text(data.info.total_connections_received);
            $(".commands",v).text(data.info.total_commands_processed);
            $(".ave-connections",v).text( (data.info.total_connections_received / data.info.uptime_in_seconds).toFixed(2) );
            $(".ave-commands",v).text( (data.info.total_commands_processed / data.info.uptime_in_seconds).toFixed(2) );
            $(".five-connections",v).text(data.average.cnx.toFixed(2));
            $(".five-commands",v).text(data.average.cmd.toFixed(2));
            $(".expired",v).text(data.info.expired_keys);
            $(".evicted",v).text(data.info.evicted_keys).closest(".alert").toggle( (data.info.evicted_keys > 0) );
            $(".client",v).text(data.info.connected_clients);
            $(".save",v).html(function(){return data.info.save.replace(/(\d+) (\d+)/g,'-$2rec / $1s').substr(1).replace(/-/g,'&#8210; ');});
            $(".last",v).text(mtd(data.info.last_save_time));
            $(".save-progress",v).toggle( (data.info.bgsave_in_progress > 0) );
            $(".records",v).text(function(){var c=0;
                if( data.info.db )
                $.each(data.info.db,function(k,v){c+=parseInt(v.keys);});
                return c; });
            var chart = $(".rw",v).data('chart');
            chart.get("read").addPoint(data.report.reads,true,(chart.get("read").data.length>19)?true:false);
            chart.get("write").addPoint(data.report.writes,true,(chart.get("write").data.length>19)?true:false);
            chart.get("other").addPoint(data.report.others,true,(chart.get("other").data.length>19)?true:false);
    }).error(function(event,jqXHR,ajaxSettings,thrownError){
        console.error("Connection with the server aborted. Please refresh");
        window.clearInterval(update_interval);
    });
    });
}

// }}}
// {{{ update_slowlog

var slow_log_item = $("#tablelog .item").remove();
function update_slowlog()
{
    var list = $("#tablelog tbody").empty().clone();
    var server = $("#zmq").val();
    if(!server) server = $("#zmq").attr("placeholder");
    $.getJSON('', {
        slowlog: server,
        ids: $("#list").val() },function(data) {
            $.each(data,function(k,v){
                var item = slow_log_item.clone();
                $(".id",item).text(v.id+" #"+v.num);
                $(".date",item).text(mtd(v.time));
                $(".duration",item).text(v.duration / 1000);
                $(".cmd",item).text(v.cmd);
                $(item).appendTo(list);
            });
            $("#tablelog tbody").replaceWith(list);
        });
}

// }}}
// {{{ update_server_list

function update_server_list(callback)
{
    $("#servers").empty();
    var list = $("#list").val();
    if(!list) list = $("#list").attr("placeholder");
    $.each(list.split(","), function(k,v){
        var el = item.clone().attr("id","server-"+v);
        $(".rw",el).attr("id","rw-"+v);
        $(".id",el).text(v);
        el.appendTo($("#servers"));
        var chart = new Highcharts.Chart({
            chart:{renderTo: "rw-"+v,animation:false,height:200},
            title:{text:null},
            xAxis:{labels:false},
            yAxis:{title:false},
            plotOptions:{area:{stacking:'normal',lineWidth:1,marker:{enabled:false}},series:{enableMouseTracking:false}},
            series:[
                {type:"area",id:"read",name:"read",data:[]},
                {type:"area",id:"write",name:"write",data:[]},
                {type:"area",id:"other",name:"other",data:[],color:"#aaa"}]
        });
        $(".rw",el).data('chart',chart);
        if(callback)callback();
    });
    localStorage["list"] = list;
}

// }}}
// {{{ --init

// Get config from local
//if(localStorage["zmq"]) $("#zmq").val(localStorage["zmq"]);
if(localStorage["list"]) $("#list").val(localStorage["list"]);

// Enable update every second.
var update_interval = null;

$(function(){

// Enable server
$("#zmq").on("keypress blur",function(e){if(e.type="blur" || e.which==13) localStorage["zmq"] = $("#zmq").val();});

// Enable folding
$("dt").each(function(){
    var id = $(this).attr("id");
    if( id ) $("dt."+id).toggle()
});

// Enable tooltips
$("[rel=tooltip]").tooltip();

// Enable tabs
$('.nav-tab a').click(function(e){e.preventDefault();$(this).tab('show');update();});

// Enable refresh
$("#refresh").click(update);

// Enable auto refresh
auto_off = function(){
    $("#auto").closest("li").removeClass("active");
    window.clearInterval(update_interval);
    if( $("#auto").data('unauto') ) window.clearTimeout($("#auto").data('unauto'));
};
auto_on = function(){
    $("#auto").closest("li").addClass("active");
    update_interval = window.setInterval(update,1000);
    $("#auto").data('unauto',window.setTimeout(function(){$("#auto").click();},20000));
};
$("#auto").toggle(auto_on,auto_off);

// Enable servers list update
$("#list").on("keypress blur",function(e){if(e.type=="blur" || e.which==13) update_server_list();});

// Refresh now
update_server_list(update);

});

// }}}
</script>
</html>

