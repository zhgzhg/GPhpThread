GPhpThread - Generic PHP Threads library
========================================

A heavy threads library implementation written using only pure PHP.
A fully component that might come in handy when the host system does
not have PHP threads module installed and for some reason it cannot
be installed (lack of privileges, legacy system, etcetera).

Features
--------

* OO thread creation and management ideology
* Thread execution control:
 * start
 * stop
 * join - blocking or nonblocking mode
 * pause
 * resume
 * sleep with interruption detection
* Thread priority and niceness control
* Support for thread exit codes
* Critical section for sharing data among the threads or for locking purposes
 * reliable containers
 * faster, unreliable containers
* Extensible and customizable
* Distributed under MIT license

Requirements/Dependencies
-------------------------

* PHP version 5.3+
* PHP shell execution context
* PHP pcntl
* PHP POSIX
* OS Linux family

Installation
------------

You can use composer to integrate the library in you project:

	php composer.phar require zhgzhg/gphpthread:@dev

Alternatively you can also manually download GPhpThread.php file and
place it in your project's directory.

TODOes
------

|Status|Details|
|:-----|:------------------------------------------------------------------------:|
|Close to stable|TODO: improved examples|
