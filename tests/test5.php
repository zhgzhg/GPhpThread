<?php
/**
 * The MIT License (MIT)
 *
 * Copyright (c) 2016 zhgzhg
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

// Simple Demonstration how use GPhpThread

require_once __DIR__ . '/../GPhpThread.php';

class MyStuff {
	private $postfix;
	private $inThread;

	public function __construct($postfix) {
		$this->postfix = $postfix;
		$this->inThread = new GPhpThreadNotCloneableContainer();
		GPhpThread::isInGPhpThread($this->inThread);
		echo "__constr My Stuff - {$this->postfix} " . getmypid() . "\n";
	}

	public function __destruct() {
		if ($this->inThread->export()) {
			exit;
		}
		echo "__deconstr My Stuff - {$this->postfix} " . getmypid() . "\n";
	}
}

$ms0 = new MyStuff('main 0thread');
unset($ms0);

$ms1 = new MyStuff('main thread');

class MyThreadThread extends GPhpThread {
	public function run() {
		echo 'Hello, I am a thread launched from a thread - id ' . $this->getPid() . "!\n";

		$lock = new GPhpThreadLockGuard($this->criticalSection);
		echo "=### locked " . $this->getPid() . "\n";
		$this->criticalSection->addOrUpdateResource('IAM', getmypid());
		echo "=### unlocked " . $this->getPid() . "\n";

		$ms3 = new MyStuff('most inner thread thread');
	}
}

class MyThread extends GPhpThread {
	public function run() {
		echo 'Hello, I am a thread with id ' . $this->getPid() . "!\nTrying to lock the critical section\n";

		$lock = new GPhpThreadLockGuard($this->criticalSection);
		echo "=--- locked " . $this->getPid() . "\n";
		$this->criticalSection->addOrUpdateResource('IAM', getmypid());

		$criticalSection2 = new GPhpThreadCriticalSection();

		$a = new MyThreadThread($criticalSection2, true);
		$b = new MyThreadThread($criticalSection2, true);

		$a->start();
		$b->start();

		$a->join();
		$b->join();

		$criticalSection2->lock();
		echo "=###= Last internal cs value: " . $criticalSection2->getResourceValue('IAM') . "\n";
		$criticalSection2->unlock();

		$this->criticalSection->addOrUpdateResource('IAMNOT', '0xdead1');
		$this->criticalSection->removeResource('IAMNOT');
		echo "=--- unlocked " . $this->getPid() . "\n";

		GPhpThread::resetThreadIdGenerator();
		$ms2 = new MyStuff('heavy thread 1');
	}
}

echo "Master main EP " . getmypid() . "\n";

$criticalSection = new GPhpThreadCriticalSection();

echo "\nLaunching Thread1...\n\n";

$thr1 = new MyThread($criticalSection, true);
$thr1->start();
echo "Thread1 pid is: " . $thr1->getPid() . "\n";
$thr1->join();
?>
