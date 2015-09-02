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
* Support for thread exit codes
* Critical section for sharing data among the threads or for locking purposes
* Extensible and customizable

Requirements
------------

* PHP version 5.3+
* OS Linux family (maybe more... to be checked...)

|Status|Details|
|:-----|:------------------------------------------------------------------------:|
|Experimental|TODO: execution speed tweaking, addition of some more extra features, improved examples|
