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

class MyThread extends GPhpThread {
	public function run() {
		echo 'Hello, I am a thread with pid ' . $this->getPid() . "!\n";
		$this->sleep(0, 2); // 2 seconds
	}
}

echo "Master process pid is " . getmypid() . "\n";
echo "Creating threads...\n";
$thr1 = new MyThread($nothing = null, true);
$thr2 = new MyThread($nothing = null, true);

echo "\nLaunching Thread1...\n\n";

$thr1->start();
echo "Thread1 pid is: " . $thr1->getPid() . "\n";

echo "\nLaunching Thread2...\n\n";

$thr2->start();
echo "Thread2 pid is: " . $thr2->getPid() . "\n";

echo "Waiting for the threads to finish...\n";

while (!$thr1->join(false)) { // non blocking join
	echo "Thread1 is not done yet!\n";
	usleep(500000);
}
echo "Thread1 is done!\n";

$thr2->join(); // blocking join
echo "Thread2 is done!\n";
?>
