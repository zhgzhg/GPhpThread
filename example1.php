<?php
/**
 * The MIT License (MIT)
 *
 * Copyright (c) 2015 zhgzhg
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

require_once 'GPhpThread.php';

class MyThread extends GPhpThread {
	public function run() {
		echo 'Hello, I am a thread with id ' . $this->getPid() . "!\nTrying to lock the critical section\n";
		if ($this->criticalSection->lock()) {
			echo "=--- locked " . $this->getPid() . "\n";
			$this->criticalSection->addOrUpdateResource('IAM', getmypid());
			$this->criticalSection->addOrUpdateResource('IAMNOT', '0xdead1');
			$this->criticalSection->removeResource('IAMNOT');
			while (!$this->criticalSection->unlock()) $this->sleep(200000);
			echo "=--- unlocked " . $this->getPid() . "\n";
		}
	}
}

echo "Master main EP " . getmypid() . "\n";

$criticalSection = new GPhpThreadCriticalSection();

echo "\nLaunching Thread1...\n\n";

$thr1 = new MyThread($criticalSection);
$thr1->start();
$thr1->join();

echo "\n---Thread1 id was: " . $criticalSection->getResourceValueFast('IAM') . "---\n";
echo "Master after the join of Thread1.\n\n";

echo "\nLaunching Thread2...\n\n";

$thr2 = new MyThread($criticalSection);
$thr2->start();
$thr2->join();

echo "---Thread2 id was: " . $criticalSection->getResourceValueFast('IAM') . "---\n";
echo "Master after the join of Thread2.\n\n";

echo "\n\nThe resources that left in the critical section:\n";
var_dump($criticalSection->getResourceNames());

$thr1 = null;
$thr2 = null;
?>
