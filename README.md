GPhpThread - Generic PHP Threads
================================

A heavy threads implementation written only in pure PHP. This can come
in handy when the host system does not have PHP threads module installed
and for some reason it cannot be installed (lack of privilleges, legacy
system, ect.).

Features:

* OO thread creation and management ideology
* Basic thread execution control - start, stop, join -> non/blocking
* Support for thread exit codes
* Critical section

Compatability:

* OS: Linux family (maybe more... to be checked...)
* PHP: 5.3+

Status: In development (currently tweaking the execution speed), to be released...
----------------------------------------------------------------------------------
