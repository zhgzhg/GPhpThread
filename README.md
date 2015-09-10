GPhpThread - Generic PHP Threads
================================

A heavy threads implementation written using only pure PHP. This can
come in handy when the host system does not have PHP threads module
installed and for some reason it cannot be installed (lack of
privileges, legacy system, ect.).

Features
--------

* OO thread creation and management ideology
* Thread execution control - methods like start, stop, join supporting blocking or nonblocking mode
* Thread priority control
* Support for thread exit codes
* Critical section for sharing data among the threads or for locking purposes
 * reliable containers
 * faster, unreliable containers
* Extensible and customizable

Requirements
------------

* PHP version 5.3+
* PHP shell execution context
* OS Linux family

|Status|Details|
|:-----|:------------------------------------------------------------------------:|
|Experimental|TODO: execution speed tweaking, improved examples|
