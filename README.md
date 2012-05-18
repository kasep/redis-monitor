Pretty Simple REDIS monitor tools
=================================

This collections of tools allow to monitor activities and status of multiple instances of *Redis* server.

- `redis.server.php` A *0MQ* server that store the status and activities for all instances. (in *PHP* RAM)
- `redis.info.php` Grab informations from the `info` and `config get` commands of one instance.
- `redis.monitor.php` Grab informations from the `monitor` command of one instance and create statistics.
- `redis.ui.php` An *HTML* page that display the status and activities for all instances.

> **README:**<br>
> It was designed before the Redis release 2.6, so the `redis.monitor.php` could be useless.<br>
> Never tried in a production environment yet. Soon!<br>
> `INFO commandstats` is not implements yet.

![Screenshot showing the UI](http://prettysimple.github.com/redis-monitor/screenshot.png)

----

Requirement
-----------

- PHP 5.3+ (web + cli)
- [0MQ extension for PHP](https://github.com/mkoppanen/php-zmq)

To run the PHP daemons, I'm using [*daemontools*](http://cr.yp.to/daemontools.html) but *init.d* or a *screen* can do the work.

Setup
-----

1. Run the `redis.server.php`
2. Run one `redis.info.php` and one `redis.monitor.php` per *Redis* instance identified by an `ID`.
3. Put the `redis.ui.php` script on a accessible path from your Web server.
4. Browse to the `redis.ui.php` script.
5. In the toolbar, write a coma-separated list of the instances `ID`. *(or modify the `redis.ui.php` script)*.

Example with deamontools
------------------------

1. Using *deamontools*: just edit the `examples/daemontools/*/run` files.
2. To run all the daemons, use: `svscan examples/daemontools`.

Usage
-----

    php redis.server.php -d=DSN [-h]
    php redis.info.php -d=DSN -i=ID -h=HOST -p=PORT [-a=AUTH] [-w=WAIT] [-h]
    php redis.monitor.php -d=DSN -i=ID [-h=HOST] [-p=PORT] [-c=COUNT] [-t=TIME] [-a=AUTH] [-w=WAIT] [-h]

Troubleshooting
---------------

If *0MQ* do not respond correctly, restart your *php-fpm*.


