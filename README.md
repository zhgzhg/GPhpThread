GPhpThread - Generic PHP Threads library
========================================

A heavy threads library implementation written using only pure PHP.
A fully functional component that might come in handy when the host
system does not have PHP threads module installed and for some reason it
cannot be installed (lack of privileges, legacy system, et cetera).

Features
--------

* OO thread creation and management ideology
* Thread execution control:
  * start
  * stop
  * join - blocking or non-blocking mode
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

How To Use
----------

In essence you need to extend class GPhpThread and implement the
abstract method run(). Here is an example:

```
<?php
require_once 'GPhpThread.php';

class SingingThread extends GPhpThread {

	public function run() {
		$j = 99;
		for ($i = $j; $i > 0; ) {
			echo $this->getPid() . " sings:\n";
			echo "{$i} bottles of beer on the wall, {$i} bottles of beer.\n";
			--$i;
			echo "Take one down and pass it around, {$i} bottles of beer on the wall.\n\n";
		}

		echo "No more bottles of beer on the wall, no more bottles of beer.\n";
		echo "Go to the store and buy some more, {$j} bottles of beer on the wall.\n\n";
	}
}

echo "PID " . getmypid() . " is the director! Let's sing!\n\n";
sleep(3);

$sharedCriticalSection = null;
$allowThreadExitCodes = false;

$st = new SingingThread($sharedCriticalSection, $allowThreadExitCodes);
$st->start(); // start the GPhpThread
$st->join();  // wait for the generic thread to finish

echo "Director " . getmypid() . " is done!\n";
?>
```

For more information see the files inside "examples" and "tests"
directories. An html documentation is available inside "Documentation"
directory.
