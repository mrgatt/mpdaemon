PHP Multiprocess Daemon Framework
=================================
Introduction
------------
A lot of applications these days are dependent on long running processes performing various asynchronous background tasks.
This is a framework specialised to help build multi-process worker daemons. Uses for such daemons can include:

  * Queue processing and dispatching
  * Worker pools for job services such as [Gearman](http://gearman.org/) or [Beanstalkd](http://kr.github.com/beanstalkd/)

A daemon can also be set to only spawn one child worker. This means you can also use this as a general purpose single-worker daemon, with the added benefit that the process doing the actual heavy work is being monitored by a parent process. As the child is the one more likely to encounter problems after running a long time this can make regular daemons more robust too.

Requirements
------------
  * Linux
  * PHP 5.3.x with pcntl and posix support
  * PHP command line binary in the PATH

**Optional:** If the ['proctitle'](http://pecl.php.net/package/proctitle/) extension is installed the child workers get nicer process titles displayed in tools such as ps and top. The process title includes when the worker was started and its current memory usage. This will be updated every 2 minutes.

Features
--------
  * Robust child process management
  * Simple configuration system
  * Simple API for implementing parents and workers
  * Automated init.d script generation and installation
  * Automated logrotate configuration generation
  * Optional no-daemon mode for debugging or for use with a process monitor tool such as [supervisord](http://supervisord.org/)
  * Only allows one of the particular daemon to run at a time
  * Light weight parent and children processes

Implementation
--------------
We make use of the [System_Daemon](https://github.com/kvz/system_daemon) PEAR library (bundled) which makes a lot of the low level process handling easier, as well as giving some extra features such as switching to a different user and init.d file writing.

A simple concrete demo implementation is included. You can use the code for this demo daemon as the basis for new daemons.

You will need a ''config/daemon.ini'' file that currently just specifies what system user ID the daemon should run as. You may need to edit the UID in this file to a more appropriate UID. Each service group will also have its own configuration file specifying options specific to the daemons for that particular service. To run the demo daemon:

`sudo /path/to/demo/bin/demodaemon.php`

Then `tail -f /tmp/demodaemon.log` to see the child activity.

Kill off children and watch the parent restart them. Kill the parent nicely and watch all the children get killed. `kill -9` the parent and watch the children panic and commit suicide.

Add a `-h` to get the list of command line arguments.

The `init()` method in the worker class is where you would load anything the worker needs to carry out its jobs, as well as perform any DB connections. Any DB or other connections created in the parent process will not be available to the worker anyway as it is in a different process.

To build a new daemon you would:
  * Create a directory named after your service group
  * create the bin script
  * create the parent and worker classes in the daemon directory
  * create a config file for your daemon in the `config` directory
  * deploy and execute `sudo <daemon-name>.php --write-init`

The bulk of the development required for a new daemon involves concrete implementations of the **Daemon** and **Worker** classes.

To ensure your pool of processes stays running you can either use a monitoring tool such as Monit or simply add a cron entry to run the daemon periodically. The daemon will not run more than once anyway. Some monitoring tools, such as [supervisor](http://supervisord.org) do not want the process daemonized as they take care of that themself. In those instances you can include the `--no-daemon` argument

It is better to keep the daemon file name short -- because start-stop-daemon used by the init script on Ubuntu has a restriction of max 15 characters for process name.

Don't forget to make your deamon bin file executible with `chmod a+x`.

FAQ
---
**Q: My init script isn't working, what's wrong?**  
**A:** This has only been tested on RHEL5, CentOS and Fedora. Most often cause for this is that the PHP command line binary is not in the current search path. The init scripts use a different environment and it has been observed under CentOS 5 that the `/usr/local/bin` path is not in the search path by default. The bin script for our daemons use `/usr/bin/env` to try and find the PHP binary so we don't have to hardcode a path. An easy solution is to simply create a symlink in `/usr/bin`. If using Ubuntu it could be because your daemon name is too long.

**Q: My Daemon logs too much, how do I fix it?**  
**A:** Edit the ini file for your daemon and set the loglevel option to `6` or lower. `7` is the *debug* log level and the daemon may log a lot more depending on its implementation. You can also specify a `--loglevel` argument on the command line.

**Q: I'm debugging a new daemon. What's an easy way to start and stop it?**  
**A:** Use the `-n` (`--no-daemon`) command line argument when starting your daemon. This keeps the parent process in the foreground and then you can just press `CTRL + c` to stop it. All log messages other than DEBUG level ones will also be emitted to the console when run in the foreground.

**Q: Make the daemon start on boot?**  
**A:** For Ubuntu, try: `sudo update-rc.d mydaemon defaults`
For Fedora/RHEL/CentOS: `sudo /sbin/chkconfig --level 35 mydaemon on`

**Q: I am tired of making it start on boot!**  
**A:** For Ubuntu, try: `sudo update-rc.d mydaemon remove`
For Fedora/RHEL/CentOS: `sudo /sbin/chkconfig --del mydaemon`

Authors
-------
[Marcus Gatt](https://github.com/mrgatt)

Contributors
------------
[Lindsey Smith](https://github.com/praxxis)  
[Wei Feng](https://github.com/windix)

License
-------
© 2012 Luxbet Pty Ltd.
Released under [The MIT License](http://www.opensource.org/licenses/mit-license.php)
