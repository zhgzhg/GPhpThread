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

//define("DEBUG_MODE", true);

declare(ticks=30);
//declare(ticks=250) {

	class GPhpThreadException extends Exception // {{{
	{
		public function __construct($msg, $code = 0, Exception $previous = NULL) {
			parent::__construct($msg, $code, $previous);
		}
	} // }}}

	class GPhpThreadIntercom // {{{
	{
		private $commFilePath = '';
		private $commChanFdArr = array();
		private $success = true;
		private $autoDeletion = false;
		private $isReadMode = true;

		public function __construct($filePath, $isReadMode = true, $autoDeletion=false) { // {{{
			if (!file_exists($filePath)) {
				if (!posix_mkfifo($filePath, 0644)) {
					$this->success = false;
					return;
				}
			}

			$commChanFd = fopen($filePath, ($isReadMode ? 'r+' : 'w+')); // + mode make is non blocking too
			if ($commChanFd === false) {
				$this->success = false;
				return;
			}

			if (!stream_set_blocking($commChanFd, false)) {
				$this->success = false;
				fclose($commChanFd);
				if ($autoDeletion) @unlink($filePath);
				return;
			}
			$this->commChanFdArr[] = $commChanFd;

			$this->commFilePath = $filePath;
			$this->autoDeletion = $autoDeletion;
			$this->isReadMode = $isReadMode;
			$this->success;
		} // }}}

		public function isInitialized() { // {{{
			return $this->success;
		} // }}}

		public function __destruct() { // {{{
			if ($this->success) {
				if (isset($this->commChanFdArr[0]) &&
					is_resource($this->commChanFdArr[0])) {
					fclose($this->commChanFdArr[0]);
				}
				if ($this->autoDeletion) @unlink($this->commFilePath);
			}
		} // }}}

		public function send($dataString, $dataLength) { // {{{
			if ($this->success && !$this->isReadMode) {
				//if (defined('DEBUG_MODE')) echo $dataString . '[' . getmypid() . "] sending\n";
				$data = (string)$dataString;
				$read = $except = null;

				$commChanFdArr = $this->commChanFdArr;
				if (stream_select($read, $commChanFdArr, $except, 1) == 0) return false;

				while ($dataLength > 0)	{
					$bytesWritten = fwrite($this->commChanFdArr[0], $data);
					if ($bytesWritten === false) return false;
					$dataLength -= $bytesWritten;
					if ($dataLength > 0) {
						$commChanFdArr = $this->commChanFdArr;
						if (stream_select($read, $commChanFdArr, $except, 10) == 0)
							return false;
						$data = substr($data, 0, $bytesWritten);
					}
					if (!isset($this->commChanFdArr[0]) || !is_resource($this->commChanFdArr[0])) break;
				}
				if ($dataLength <= 0) return true;
			}
			return false;
		} // }}}

		public function receive() { // {{{
			if (!$this->success || !$this->isReadMode) return false;
			if (!isset($this->commChanFdArr[0]) || !is_resource($this->commChanFdArr[0])) return false;

			$commChanFdArr = $this->commChanFdArr;

			$write = $except = null;
			$data = null;

			if (stream_select($commChanFdArr, $write, $except, 0, 500000) == 0) return $data;

			do {
				$d = fread($this->commChanFdArr[0], 1);
				if ($d !== false) $data .= $d;
				if (!isset($this->commChanFdArr[0]) || !is_resource($this->commChanFdArr[0])) break;
				$commChanFdArr = $this->commChanFdArr;
			} while ($d !== false && stream_select($commChanFdArr, $write, $except, 0, 250000) != 0);

			//if (defined('DEBUG_MODE')) echo $data . '[' . getmypid() . "] received\n"; // 4 DEBUGGING
			return $data;
		} // }}}

		public function isReceiveingDataAvailable() { // {{{
			if (!$this->success || !$this->isReadMode) return false;
			if (!isset($this->commChanFdArr[0]) || !is_resource($this->commChanFdArr[0])) return false;

			$commChanFdArr = $this->commChanFdArr;
			return (stream_select($commChanFdArr, $write = null, $except = null, 0, 55000) != 0);
		} // }}}
	} // }}}
//}

//declare(ticks=500) {
class GPhpThreadCriticalSection // {{{
{
	private $uniqueId = 0;								// the identifier of a concrete instance

	private static $uniqueIdSeed = 0;				    // the uniqueId index seed

	private static $instancesCreatedEverAArr = array(); // contain all the instances that were ever created of this class
	private static $threadsForRemovalAArr = array();    // contain all the instances that were terminated; used to make connection with $mastersThreadSpecificData

	private $creatorPid;
	private $ownerPid = false;  // the thread PID owning the critical section
	private $myPid;				// point of view of the current instance

	private $sharedData = array(); // variables shared in one CS instance among all threads

	private $mastersThreadSpecificData = array(); // specific per each thread variables / the host is the master parent

	// ======== thread specific variables ========
	private $intercomWrite = null;
	private $intercomRead = null;
	private $intercomInterlocutorPid = null;
	private $dispatchPriority = 0;
	// ===========================================

	private static $ADDORUPDATESYN = '00', $ADDORUPDATEACK = '01', $ADDORUPDATENACK = '02',
				   $ERASESYN = '03', $ERASEACK = '04', $ERASENACK = '05',

				   $READSYN = '06', $READACK = '07', $READNACK = '08',

				   $READALLSYN = '09', $READALLACK = '10', $READALLNACK = '11',

				   $LOCKSYN = '12', $LOCKACK = '13', $LOCKNACK = '14',
				   $UNLOCKSYN = '15', $UNLOCKACK = '16', $UNLOCKNACK = '17';

	private static $ADDORUPDATEACT = 1, $ERASEACT = 2,
				   $READACT = 3, $READALLACT = 4;

	public function __construct($pipeDirectory = '/dev/shm') { // {{{
		$this->uniqueId = self::$uniqueIdSeed++;

		self::$instancesCreatedEverAArr[$this->uniqueId] = &$this;

		$this->creatorPid = getmypid();
		$this->pipeDir = rtrim($pipeDirectory, ' /') . '/';
	} // }}}

	public function __destruct() { // {{{
		$this->intercomRead = null;
		$this->intercomWrite = null;
		if (self::$instancesCreatedEverAArr !== null)
			unset(self::$instancesCreatedEverAArr[$this->uniqueId]);
	} // }}}

	public function initialize($afterForkPid, $threadId) { // {{{
		$this->myPid = getmypid();

		$retriesLimit = 60;

		if ($this->myPid == $this->creatorPid) { // parent
			$i = 0;
			do {
				$intercomWrite = new GPhpThreadIntercom("{$this->pipeDir}gphpthread_{$this->uniqueId}_s{$this->myPid}-d{$afterForkPid}", false, true);
				if ($intercomWrite->isInitialized()) {
					$i = $retriesLimit;
				} else {
					++$i;
					usleep(mt_rand(5000, 80000));
				}
			} while ($i < $retriesLimit);

			$i = 0;
			do {
				$intercomRead = new GPhpThreadIntercom("{$this->pipeDir}gphpthread_{$this->uniqueId}_s{$afterForkPid}-d{$this->myPid}", true, true);
				if ($intercomRead->isInitialized()) {
					$i = $retriesLimit;
				} else {
					++$i;
					usleep(mt_rand(5000, 80000));
				}
			} while ($i < $retriesLimit);

			if ($intercomWrite->isInitialized() && $intercomRead->isInitialized()) {
				$this->mastersThreadSpecificData[$threadId] = array(
					'intercomRead' => $intercomRead,
					'intercomWrite' => $intercomWrite,
					'intercomInterlocutorPid' => $afterForkPid,
					'dispatchPriority' => 0
				);
				return true;
			}
			return false;
		} else { // child
			self::$instancesCreatedEverAArr = null;  // the child must not know for its neighbours
			$this->mastersThreadSpecificData = null; // and any defails for the threads inside cs instance simulation
			self::$threadsForRemovalAArr = null;

			$this->intercomInterlocutorPid = $this->creatorPid;
			$i = 0;
			do {
				$this->intercomWrite = new GPhpThreadIntercom("{$this->pipeDir}gphpthread_{$this->uniqueId}_s{$this->myPid}-d{$this->creatorPid}", false, true);
				if ($this->intercomWrite->isInitialized()) {
					$i = $retriesLimit;
				} else {
					++$i;
					usleep(mt_rand(5000, 80000));
				}
			} while ($i < $retriesLimit);

			$i = 0;
			do {
				$this->intercomRead = new GPhpThreadIntercom("{$this->pipeDir}gphpthread_{$this->uniqueId}_s{$this->creatorPid}-d{$this->myPid}", true, false);
				if ($this->intercomRead->isInitialized()) {
					$i = $retriesLimit;
				} else {
					++$i;
					usleep(mt_rand(5000, 80000));
				}
			} while ($i < $retriesLimit);

			if (!$this->intercomWrite->isInitialized())	$this->intercomWrite = null;
			if (!$this->intercomRead->isInitialized())	$this->intercomRead = null;
			if ($this->intercomWrite == null || $this->intercomRead == null) {
				$this->intercomInterlocutorPid = null;
				return false;
			}
			return true;
		}
		return false;
	} // }}}

	public function finalize($threadId) { // {{{
		unset($this->mastersThreadSpecificData[$threadId]);
	} // }}}

	private function doIOwnIt() { // {{{
		return ($this->ownerPid !== false && $this->ownerPid == $this->myPid);
	} // }}}

	private function encodeMessage($msg, $name, $value) { // {{{
		// 2 decimal digits message code, 10 decimal digits PID,
		// 4 decimal digits name length, name, data
		return $msg . sprintf('%010d%04d', $this->myPid, strlen($name)) . $name . serialize($value);
	} // }}}

	private function decodeMessage($encodedMsg, &$msg, &$pid, &$name, &$value) { // {{{
		// 2 decimal digits message code, 10 decimal digits PID,
		// 4 decimal digits name length, name, data
		$msg = substr($encodedMsg, 0, 2);

		$pid = substr($encodedMsg, 2, 10);
		$pid = (int)preg_filter("/^0{1,9}/", '', $pid); // make the pid decimal number

		$nlength = substr($encodedMsg, 12, 4);
		$nlength = (int)preg_filter("/^0{1,3}/", '', $nlength); // make the length decimal number

		if ($nlength == 0) $name = null;
		else $name = substr($encodedMsg, 16, $nlength);

		if (strlen($encodedMsg) > 16) $value = unserialize(substr($encodedMsg, 16 + $nlength));
		else $value = null;
	} // }}}

	private function isIntercomBroken() { // {{{
		return (empty($this->intercomWrite) ||
				empty($this->intercomRead) ||
				empty($this->intercomInterlocutorPid) ||
				!$this->isPidAlive($this->intercomInterlocutorPid));
	} // }}}

	private function send($operation, $resourceName, $resourceValue) { // {{{
		if ($this->isIntercomBroken()) return false;

		$isSent = false;
		$isAlive = true;

		$msg = $this->encodeMessage($operation, $resourceName, $resourceValue);

		if (defined('DEBUG_MODE')) {
			$dbgStr = '[' . getmypid() . "] is sending ";
			switch ($operation) {
				case self::$ADDORUPDATESYN: $dbgStr .= 'ADDORUPDATESYN'; break;
				case self::$ADDORUPDATEACK: $dbgStr .= 'ADDORUPDATEACK'; break;
				case self::$ADDORUPDATENACK: $dbgStr .= 'ADDORUPDATE_N_ACK'; break;
				case self::$ERASESYN: $dbgStr .= 'ERASESYN'; break;
				case self::$ERASEACK: $dbgStr .= 'ERASEACK'; break;
				case self::$ERASENACK: $dbgStr .= 'ERASE_N_ACK'; break;
				case self::$READSYN: $dbgStr .= 'READSYN'; break;
				case self::$READACK: $dbgStr .= 'READACK'; break;
				case self::$READNACK: $dbgStr .= 'READ_N_ACK'; break;
				case self::$READALLSYN: $dbgStr .= 'READALLSYN'; break;
				case self::$READALLACK: $dbgStr .= 'READALLACK'; break;
				case self::$READALLNACK: $dbgStr .= 'READALL_N_ACK'; break;
				case self::$LOCKSYN: $dbgStr .= 'LOCKSYN'; break;
				case self::$LOCKACK: $dbgStr .= 'LOCKACK'; break;
				case self::$LOCKNACK: $dbgStr .= 'LOCK_N_ACK'; break;
				case self::$UNLOCKSYN: $dbgStr .= 'UNLOCKSYN'; break;
				case self::$UNLOCKACK: $dbgStr .= 'UNLOCKACK'; break;
				case self::$UNLOCKNACK: $dbgStr .= 'UNLOCK_N_ACK'; break;
			}
			echo "{$dbgStr}, {$resourceName}, " . serialize($resourceValue) . " to {$this->intercomInterlocutorPid} /// {$msg}\n";
		}

		do {
			$isSent = $this->intercomWrite->send($msg, strlen($msg));
			if (!$isSent) {
				$isAlive = $this->isPidAlive($this->intercomInterlocutorPid);
				if ($isAlive) usleep(mt_rand(10000, 200000));
			}

		} while ((!$isSent) && $isAlive);

		return $isSent;
	} // }}}

	private function receive(&$message, &$pid, &$resourceName, &$resourceValue) { // {{{
		if ($this->isIntercomBroken()) return false;

		$data = null;
		$isDataEmpty = false;
		$isAlive = true;

		do {
			$data = $this->intercomRead->receive();
			$isDataEmpty = empty($data);
			if ($isDataEmpty) {
				$isAlive = $this->isPidAlive($this->intercomInterlocutorPid);
				if ($isAlive) usleep(mt_rand(10000, 200000));
			}
		} while ($isDataEmpty && $isAlive);

		if (!$isDataEmpty)
			$this->decodeMessage($data, $message, $pid, $resourceName, $resourceValue);

		if (defined('DEBUG_MODE')) {
			$dbgStr = '[' . getmypid() . "] received   ";
			switch ($message) {
				case self::$ADDORUPDATESYN: $dbgStr .= 'ADDORUPDATESYN'; break;
				case self::$ADDORUPDATEACK: $dbgStr .= 'ADDORUPDATEACK'; break;
				case self::$ADDORUPDATENACK: $dbgStr .= 'ADDORUPDATE_N_ACK'; break;
				case self::$ERASESYN: $dbgStr .= 'ERASESYN'; break;
				case self::$ERASEACK: $dbgStr .= 'ERASEACK'; break;
				case self::$ERASENACK: $dbgStr .= 'ERASE_N_ACK'; break;
				case self::$READSYN: $dbgStr .= 'READSYN'; break;
				case self::$READACK: $dbgStr .= 'READACK'; break;
				case self::$READNACK: $dbgStr .= 'READ_N_ACK'; break;
				case self::$READALLSYN: $dbgStr .= 'READALLSYN'; break;
				case self::$READALLACK: $dbgStr .= 'READALLACK'; break;
				case self::$READALLNACK: $dbgStr .= 'READALL_N_ACK'; break;
				case self::$LOCKSYN: $dbgStr .= 'LOCKSYN'; break;
				case self::$LOCKACK: $dbgStr .= 'LOCKACK'; break;
				case self::$LOCKNACK: $dbgStr .= 'LOCK_N_ACK'; break;
				case self::$UNLOCKSYN: $dbgStr .= 'UNLOCKSYN'; break;
				case self::$UNLOCKACK: $dbgStr .= 'UNLOCKACK'; break;
				case self::$UNLOCKNACK: $dbgStr .= 'UNLOCK_N_ACK'; break;
			}
			echo "{$dbgStr}, {$resourceName}, " . serialize($resourceValue) . " from {$pid} /// {$data}\n";
		}

		return !$isDataEmpty;
	} // }}}

	private function requestLock() { // {{{
		$msg = $pid = $name = $value = null;

		if (!$this->send(self::$LOCKSYN, $name, $value)) return false;

		if (!$this->receive($msg, $pid, $name, $value)) return false;

		if ($msg != self::$LOCKACK)
			return false;

		$this->ownerPid = $this->myPid;
		return true;
	} // }}}

	private function requestUnlock() { // {{{
		$msg = $pid = $name = $value = null;

		if (!$this->send(self::$UNLOCKSYN, $name, $value))
			return false;

		if (!$this->receive($msg, $pid, $name, $value))
			return false;

		if ($msg != self::$UNLOCKACK)
			return false;

		$this->ownerPid = $this->myPid;
		return true;
	} // }}}

	private function updateDataContainer($actionType, $name, $value) { // {{{
		$result = false;

		$msg = null;
		$pid = null;

		switch ($actionType) {
			case self::$ADDORUPDATEACT:
				if ($name === null || $name === '') break;
				if (!$this->send(self::$ADDORUPDATESYN, $name, $value)) break;
				if (!$this->receive($msg, $pid, $name, $value)) break;
				if ($msg == self::$ADDORUPDATEACK) {
					$result = true;
					$this->sharedData[$name] = $value;
				}
			break;

			case self::$ERASEACT:
				if ($name === null || $name === '') break;
				if (!$this->send(self::$ERASESYN, $name, $value)) break;
				if (!$this->receive($msg, $pid, $name, $value)) break;
				if ($msg == self::$ERASEACK) {
					$result = true;
					unset($this->sharedData[$name]);
				}
			break;

			case self::$READACT:
				if ($name === null || $name === '') break;
				if (!$this->send(self::$READSYN, $name, $value)) break;
				if (!$this->receive($msg, $pid, $name, $value)) break;
				if ($msg == self::$READACK) {
					$result = true;
					$this->sharedData[$name] = $value;
				}
			break;

			case self::$READALLACT:
				if (!$this->send(self::$READALLSYN, $name, $value)) break;
				if (!$this->receive($msg, $pid, $name, $value)) break;
				if ($msg == self::$READALLACK) {
					$result = true;
					$this->sharedData = $value;
				}
			break;
		}

		return $result;
	} // }}}

	private function isPidAlive($pid) { // {{{
		if ($pid === false) return false;
		return posix_kill($pid, 0);
	} // }}}

	public static function dispatch($useBlocking = false) { // {{{
		$_mypid = getmypid();

		// prevent any threads to run their own dispatchers
		if ((self::$instancesCreatedEverAArr === null) || (count(self::$instancesCreatedEverAArr) == 0))
			return;

		// for checking child signals informing that a particular "thread" exited
		$sigSet = array(SIGCHLD);
		$sigInfo = array();

		// begin the dispatching process
		foreach (self::$instancesCreatedEverAArr as $instId => &$inst) { // loop through ALL active instances of GPhpCriticalSection

			foreach ($inst->mastersThreadSpecificData as $threadId => &$specificDataAArr) { // loop though the threads per each instance in GPhpCriticalSection

				while (pcntl_sigtimedwait($sigSet, $sigInfo) == SIGCHLD) { // checking for child signals informing that a thread has exited
					self::$threadsForRemovalAArr[$sigInfo['pid']] = $sigInfo['pid'];
				}

				$inst->intercomInterlocutorPid = &$specificDataAArr['intercomInterlocutorPid'];

				if (isset(self::$threadsForRemovalAArr[$inst->intercomInterlocutorPid])) {
					unset($inst->mastersThreadSpecificData[$threadId]);
					unset(self::$threadsForRemovalAArr[$inst->intercomInterlocutorPid]);
					continue;
				}

				$inst->intercomRead = &$specificDataAArr['intercomRead'];
				$inst->intercomWrite = &$specificDataAArr['intercomWrite'];
				$inst->dispatchPriority = &$specificDataAArr['dispatchPriority'];

				if (!$useBlocking && !$inst->intercomRead->isReceiveingDataAvailable()) {
					$inst->dispatchPriority = 0;
					if ($inst->isIntercomBroken()) unset($inst->mastersThreadSpecificData[$threadId]); // remove the thread from the dispatching list as soon as we can
					continue;
				}

				$msg = $pid = $name = $value = null;

				if (!$inst->receive($msg, $pid, $name, $value))	{
					$inst->dispatchPriority = 0;
					if ($inst->isIntercomBroken()) unset($inst->threadInstanceContext[$threadId]); // remove the thread from the dispatching list as sonn as we cam
					continue;
				}

				$intercomOperationPerformed = true;

				switch ($msg) {
					case GPhpThreadCriticalSection::$LOCKSYN:
						$inst->dispatchPriority = 1;
						if ($inst->ownerPid !== false && $inst->ownerPid != $pid && $inst->isPidAlive($inst->ownerPid)) {
							$inst->send(GPhpThreadCriticalSection::$LOCKNACK, null, $pid);
							continue;
						}
						if (!$inst->send(GPhpThreadCriticalSection::$LOCKACK, null, $pid)) continue;
						$inst->ownerPid = $pid;
						$inst->dispatchPriority = 2;
					break;

					case GPhpThreadCriticalSection::$UNLOCKSYN:
						$inst->dispatchPriority = 1;
						if ($inst->ownerPid === false) {
							if (!$inst->send(GPhpThreadCriticalSection::$UNLOCKACK, null, $pid)) continue;
						}
						$isOwnerAlive = $inst->isPidAlive($inst->ownerPid);
						if (!$isOwnerAlive || $inst->ownerPid == $pid) {
							if (!$isOwnerAlive) $inst->ownerPid = false;
							if (!$inst->send(GPhpThreadCriticalSection::$UNLOCKACK, null, $pid)) continue;
							$inst->dispatchPriority = 0;
							$inst->ownerPid = false;
						} else {
							$inst->send(GPhpThreadCriticalSection::$UNLOCKNACK, null, null);
						}
					break;

					case GPhpThreadCriticalSection::$ADDORUPDATESYN:
						$inst->dispatchPriority = 1;
						if ($inst->ownerPid !== $pid) {
							$inst->send(GPhpThreadCriticalSection::$ADDORUPDATENACK, null, null);
							continue;
						}
						if (!$inst->send(GPhpThreadCriticalSection::$ADDORUPDATEACK, $name, null)) continue;
						$inst->dispatchPriority = 2;
						$inst->sharedData[$name] = $value;
					break;

					case GPhpThreadCriticalSection::$ERASESYN:
						$inst->dispatchPriority = 1;
						if ($inst->ownerPid !== $pid) {
							$inst->send(GPhpThreadCriticalSection::$ERASENACK, null, null);
							continue;
						}
						if (!$inst->send(GPhpThreadCriticalSection::$ERASEACK, $name, null)) continue;
						$inst->dispatchPriority = 2;
						unset($inst->sharedData[$name]);
					break;

					case GPhpThreadCriticalSection::$READSYN:
						$inst->dispatchPriority = 1;
						if ($inst->ownerPid !== $pid) {
							$inst->send(GPhpThreadCriticalSection::$READNACK, null, null);
							continue;
						}
						$inst->send(GPhpThreadCriticalSection::$READACK, $name, $inst->sharedData[$name]);
						$inst->dispatchPriority = 2;
					break;

					case GPhpThreadCriticalSection::$READALLSYN:
						$inst->dispatchPriority = 1;
						if ($inst->ownerPid !== $pid) {
							$inst->send(GPhpThreadCriticalSection::$READALLNACK, null, null);
							continue;
						}
						$inst->send(GPhpThreadCriticalSection::$READALLACK, null, $inst->sharedData);
						$inst->dispatchPriority = 2;
					break;
				}
			}

			// rearrange the threads in the current critical section
			// instance using their new dispatch priority number
			// if a lock has already occurred that thread will have the
			// highest priority

			uksort($inst->mastersThreadSpecificData,
				function ($a, $b) use ($inst) {
					if ($inst->mastersThreadSpecificData[$a]['intercomInterlocutorPid'] == $inst->ownerPid) return -1;
					if ($inst->mastersThreadSpecificData[$b]['intercomInterlocutorPid'] == $inst->ownerPid) return 1;
					return $inst->mastersThreadSpecificData[$a]['dispatchPriority'] < $inst->mastersThreadSpecificData[$b]['dispatchPriority'];
				}
			);
		}

		// rearrange the active instances of GPhpCriticalSection in the
		// following priority order (the higher the number the bigger the priority):

		// 2. the instance with the thread that has currently locked the critical section
		// 1. instances with threads with the highest dispatch priority
		// 0. instances with the most threads inside

		$instCrtdEver = &self::$instancesCreatedEverAArr;
		uksort($instCrtdEver,
			function ($a, $b) use ($instCrtdEver) {
				// the locker thread is with highest priority
				if ($instCrtdEver[$a]->mastersThreadSpecificData['intercomInterlocutorPid'] == $instCrtdEver[$a]->ownerPid) return -1;
				if ($instCrtdEver[$b]->mastersThreadSpecificData['intercomInterlocutorPid'] == $instCrtdEver[$b]->ownerPid) return 1;

				// deal with the case of critical sections with no threads
				if (!empty($instCrtdEver[$a]->mastersThreadSpecificData) && empty($instCrtdEver[$b]->mastersThreadSpecificData)) { return -1; }     // a
				else if (empty($instCrtdEver[$a]->mastersThreadSpecificData) && !empty($instCrtdEver[$b]->mastersThreadSpecificData)) { return 1; } // b
				else if (empty($instCrtdEver[$b]->mastersThreadSpecificData) && empty($instCrtdEver[$b]->mastersThreadSpecificData)) { return 0; }  // a

				// gather the thread dispatch priorities for the compared critical sections

				$dispPriorTableA = array(); // priority value => occurences count
				$dispPriorTableB = array(); // priority value => occurences count

				foreach ($instCrtdEver[$a]->mastersThreadSpecificData as $thrdSpecificData)
					@$dispPriorTableA[$thrdSpecificData['dispatchPriority']] += 1;

				foreach ($instCrtdEver[$b]->mastersThreadSpecificData as $thrdSpecificData)
					@$dispPriorTableB[$thrdSpecificData['dispatchPriority']] += 1;

				// both critical sections have threads

				// make the tables to have the same ammount of keys (rows)
				foreach ($dispPriorTableA as $key => $value)
					@$dispPriorTableB[$key] = $dispPriorTableB[$key];
				foreach ($dispPriorTableB as $key => $value)
					@$dispPriorTableA[$key] = $dispPriorTableA[$key];

				ksort($dispPriorTableA);
				ksort($dispPriorTableB);

				// compare the tables while taking into account the priority
				// and the thread count that have it per critical section

				foreach ($dispPriorTableA as $key => $value) {
					if ($value < $dispPriorTableB[$key]) { return 1; } // b
					else if ($value > $dispPriorTableB[$key]) { return -1; } // a
				}

				return 0; // a
			}
		);

	} // }}}

	public function lock($useBlocking = true) { // {{{
		if ($this->doIOwnIt()) return true;

		do {
			if (!$this->doIOwnIt() || !$this->isPidAlive($this->ownerPid)) {
				if ($this->myPid == $this->creatorPid) { // local lock request
					$this->ownerPid = $this->myPid;
					return true;
				}

				do {
					$res = $this->requestLock();

					if ($useBlocking && !$res) {
						if ($this->isIntercomBroken()) return false;
						usleep(mt_rand(10000, 200000));
					}
				} while ($useBlocking && !$res);

				if (!$res) return false;

				if (!$this->updateDataContainer(self::$READALLACT, null, null)) {
					$this->unlock();
					return false;
				}
				return true;
			}

			if ($useBlocking) usleep(mt_rand(10000, 200000));

		} while ($useBlocking && !$this->doIOwnIt());
	} // }}}

	public function unlock() { // {{{
		if ($this->doIOwnIt() || $this->ownerPid === false) {
			if ($this->myPid == $this->creatorPid) { // local unlock request
				$this->ownerPid = false;
				return true;
			}
			return $this->requestUnlock();
		}
		return false;
	} // }}}

	public function addOrUpdateResource($name, $value) { // {{{
		if ($this->doIOwnIt()) {
			if ($this->myPid == $this->creatorPid) { // local resource add/update request
				$this->sharedData[$name] = $value;
				return true;
			}
			if (!$this->updateDataContainer(self::$ADDORUPDATEACT, $name, $value)) return false;
			return true;
		}
		return false;
	} // }}}

	public function removeResource($name) { // {{{
		if ($this->doIOwnIt() &&
			isset($this->sharedData[$name]) ||
			array_key_exists($name, $this->sharedData)) {

			if ($this->myPid == $this->creatorPid) { // local resource remove request
				unset($this->sharedData[$name]);
				return true;
			}

			if (!$this->updateDataContainer(self::$ERASEACT, $name, null)) return false;
			return true;
		}
		return false;
	} // }}}

	public function getResourceValueFast($name) { // {{{
		return (isset($this->sharedData[$name]) || array_key_exists($name, $this->sharedData) ? $this->sharedData[$name] : null);
	} // }}}

	public function getResourceValue($name) { // {{{
		if (!$this->doIOwnIt())
			throw new GPhpThreadException('[' . getmypid() . '][' . $this->uniqueIdSeed . '] Not owned critical section!');

		if ($this->myPid == $this->creatorPid) { // local resource read request ; added to keep a consistency with getResourceValueFast
			return $this->getResourceValueFast($name);
		}

		if (!$this->updateDataContainer(self::$READACT, $name, null))
			throw new GPhpThreadException('[' . getmypid() . '][' . $this->uniqueIdSeed . '] Error while retrieving the value!');

		return $this->sharedData[$name];
	} // }}}

	public function getResourceNames() { // {{{
		return array_keys($this->sharedData);
	} // }}}
} // }}}
//}

abstract class GPhpThread // {{{
{	protected $criticalSection = null;
	private $parentPid = null;
	private $childPid = null;
	private $exitCode = null;

	private $amIStarted = false;

	private $uniqueId = 0;
	private static $seed = 0;

	private static $isCriticalSectionDispatcherRegistered = false;
	private static $isSignalCHLDHandlerInstalled = false;

	public function __construct(&$criticalSection) {// {{{
		$this->uniqueId = GPhpThread::$seed++;
		$this->criticalSection = &$criticalSection;
		$this->parentPid = getmypid();
	} // }}}

	public function __destruct() { // {{{
	} // }}}


	public final function getExitCode() { // {{{
		return $this->exitCode;
	} // }}}

	public static final function BGN_HIGH_PRIOR_EXEC_BLOCK() {
		GPhpThread::$isCriticalSectionDispatcherRegistered = true;
		unregister_tick_function('GPhpThreadCriticalSection::dispatch');
	}

	public static final function END_HIGH_PRIOR_EXEC_BLOCK() {
		register_tick_function('GPhpThreadCriticalSection::dispatch');
	}

	private function amIParent() { // {{{
		return ($this->childPid > 0 ? true : false);
	} // }}}

	private function notifyParentThatChildIsTerminated() { // {{{
		posix_kill($this->parentPid, SIGCHLD);
	} // }}}

	abstract public function run();

	public final function start() { // {{{
		if ($this->childPid !== null) exit(0);

		$this->childPid = pcntl_fork();
		if ($this->childPid == -1) return false;
		$this->amIStarted = true;

		$csInitializationResult = null;
		if ($this->criticalSection !== null)
			$csInitializationResult = $this->criticalSection->initialize($this->childPid, $this->uniqueId);


		if (!$this->amIParent()) { // child
			// no dispatchers needed in the childs; this means that no threads withing threads creation is possible
			unregister_tick_function('GPhpThreadCriticalSection::dispatch');

			if ($csInitializationResult === false) $this->stop(); // don't execute the thread body if critical section is required, but missing

			pcntl_sigprocmask(SIG_UNBLOCK, array(SIGCHLD));
			$this->run();
			if ($this->criticalSection !== null) $this->notifyParentThatChildIsTerminated();
			$this->stop();
		} else { // parent
			if ($this->childPid != -1 && $this->criticalSection !== null) {

				if ($csInitializationResult === false) { // don't add the thread to the dispatch queue if missing but required critical section is the case
					$this->childPid = -1;
					$this->amIStarted = false;
					return false;
				}

				if (!GPhpThread::$isCriticalSectionDispatcherRegistered)
					GPhpThread::$isCriticalSectionDispatcherRegistered = register_tick_function('GPhpThreadCriticalSection::dispatch');

				pcntl_sigprocmask(SIG_BLOCK, array(SIGCHLD)); // SIGCHLD will wait in the queue untill it's processed
			}
			return true;
		}
	} // }}}

	public final function stop($force = false) { // {{{
		if (!$this->amIStarted) return false;
		if ($this->amIParent() && $this->childPid !== null) { // parent
			$r = posix_kill($this->childPid, ($force == false ? 15 : 9));
			if ($r) {
				if ($this->join()) $this->childPid = null;
				if ($this->criticalSection !== null)
					$this->criticalSection->finalize($this->uniqueId);
				$this->amIStarted = false;
			}
			return $r;
		}
		// child
		if ($this->childPid == -1) return false;
		exit(0);
	} // }}}

	public final function join($useBlocking = true) { // {{{
		if (!$this->amIStarted) return false;
		if ($this->amIParent()) {
			$status = null;
			$res = 0;
			if ($useBlocking) {
				while (($res = pcntl_waitpid($this->childPid, $status, WNOHANG)) == 0) {
					for ($i = 0, $j = 0; $i < 2; ++$i) {
						$j++;
					}
					echo "da\n";
					//usleep(mt_rand(60000, 200000));
				}

				if ($res > 0 && pcntl_wifexited($status)) {
					$this->exitCode = pcntl_wexitstatus($status);
				} else {
					$this->exitCode = false;
				}

				if ($this->criticalSection !== null) $this->criticalSection->finalize($this->uniqueId);
				$this->childPid = null;
				$this->amIStarted = false;
			} else {
				$res = pcntl_waitpid($this->childPid, $status, WNOHANG);
				if ($res > 0 && $this->criticalSection !== null) $this->criticalSection->finalize($this->uniqueId);
				if ($res > 0 && pcntl_wifexited($status)) {
					$this->exitCode = pcntl_wexitstatus($status);
					if ($this->criticalSection !== null) $this->criticalSection->finalize($this->uniqueId);
					$this->amIStarted = false;
				} else if ($res == -1) {
					$this->exitCode = false;
				}

				if ($res != 0) {
					if ($this->criticalSection !== null) $this->criticalSection->finalize($this->uniqueId);
					$this->childPid = null;
				}
			}
			return $res;
		}
		if ($this->childPid == -1) return false;
		exit(255);
	} // }}}
} // }}}
?>
