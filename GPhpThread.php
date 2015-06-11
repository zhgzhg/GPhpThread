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

declare(ticks=30);
//define("DEBUG_MODE", true);

class GPhpThreadException extends Exception { /* {{{ */
	public function __construct($msg, $code = 0, Exception $previous = NULL) {
		parent::__construct($msg, $code, $previous);
	}
} /* }}} */

class GPhpThreadIntercom /* {{{ */
{
	private $commFilePath = '';
	private $commChanFdArr = array();
	private $success = true;
	private $autoDeletion = false;
	private $isReadMode = true;
	
	public function __construct($filePath, $isReadMode = true, $autoDeletion=false) { /* {{{ */
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
	} /* }}} */
	
	public function isInitialized() { /* {{{ */
		return $this->success;
	} /* }}} */
	
	public function __destruct() { /* {{{ */
		if ($this->success) {
			if (isset($this->commChanFdArr[0]) && 
				is_resource($this->commChanFdArr[0])) {
				fclose($this->commChanFdArr[0]);
			}
			if ($this->autoDeletion) @unlink($this->commFilePath);			
		}
	} /* }}} */
	
	public function send($dataString, $dataLength) { /* {{{ */
		if ($this->success && !$this->isReadMode) {
			if (defined('DEBUG_MODE')) echo $dataString . '[' . getmypid() . "] sending\n";
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
	} /* }}} */
	
	public function receive() { /* {{{ */
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

		if (defined('DEBUG_MODE')) echo $data . '[' . getmypid() . "] received\n"; // 4 DEBUGGING
		return $data;
	} /* }}} */
	
	public function isReceiveingDataAvailable() { /* {{{ */
		if (!$this->success || !$this->isReadMode) return false;
		if (!isset($this->commChanFdArr[0]) || !is_resource($this->commChanFdArr[0])) return false;

		$commChanFdArr = $this->commChanFdArr;
		return (stream_select($commChanFdArr, $write = null, $except = null, 0, 55000) != 0);
	} /* }}} */
} /* }}} */

class GPhpThreadCriticalSection /* {{{ */
{
	private $myPid; // point of view of the current instance
	private $creatorPid; // point of view of the current instance
	
	private $ownerPid = false;
	private $pipeDir = '';
	private $dataContainer = array();
	
	/* Context of the current instance of the class,
	 * that's using this instance.
	 * 'uniqueId' => array(
	 * 						'intercomWrite' => ...,
	 * 						'intercomRead => ...'
	 * 						'intercomInterlocutorPid' => ...
	 * 				);
	 */
	private $threadInstanceContext = array();
	private $threadInstanceContextListForRemoval = array();
	private $intercomWrite = null;
	private $intercomRead = null;
	private $intercomInterlocutorPid = null;
	private $dispatchPriority = 0;
	////////////////////////////////////////////////////////////////////
	
	private $uniqueId = 0;
	private static $seed = 0;
	
	private static $instancesListAArr = array(); // list of all available instances of a criticalsection 'uniqueId' => instance
	private static $instancesListForRemovalAArr = array(); // instances that are no longer running and will be removed when it's safe
	
	private static $ADDORUPDATESYN = '00', $ADDORUPDATEACK = '01', $ADDORUPDATENACK = '02',
				   $ERASESYN = '03', $ERASEACK = '04', $ERASENACK = '05',
				   
				   $READSYN = '06', $READACK = '07', $READNACK = '08',

				   $READALLSYN = '09', $READALLACK = '10', $READALLNACK = '11',

				   $LOCKSYN = '12', $LOCKACK = '13', $LOCKNACK = '14',
				   $UNLOCKSYN = '15', $UNLOCKACK = '16', $UNLOCKNACK = '17';
	
	private static $ADDORUPDATEACT = 1, $ERASEACT = 2,
				   $READACT = 3, $READALLACT = 4;
	
	public function __construct($pipeDirectory='/dev/shm') { /* {{{ */
		$this->creatorPid = getmypid();
		$this->pipeDir = rtrim($pipeDirectory, ' /') . '/';
	} /* }}} */
	
	public function __destruct() { /* {{{ */
		$this->intercomRead = null;
		$this->intercomWrite = null;
		GPhpThreadCriticalSection::$instancesListForRemovalAArr["{$this->uniqueId}"] = true;
	} /* }}} */
	
	public function finalize($contextId) { /* {{{ */
		GPhpThreadCriticalSection::$instancesListForRemovalAArr["{$this->uniqueId}"] = true;
		$this->threadInstanceContextListForRemoval[] = $contextId;
	} /* }}} */
	
	private function doIOwnIt() { /* {{{ */
		return ($this->ownerPid !== false && $this->ownerPid == $this->myPid);
	} /* }}} */	
	
	public function initialize($afterForkPid, $contextId) { /* {{{ */
		$this->uniqueId = GPhpThreadCriticalSection::$seed++;
		
		$this->myPid = getmypid();
		
		$retriesLimit = 60;
		
		if ($this->myPid == $this->creatorPid) { // parent
			$this->intercomInterlocutorPid = $afterForkPid;
			GPhpThreadCriticalSection::$instancesListAArr["{$this->uniqueId}"] = $this; // TODO TESTME, FIXME maybe
			krsort(GPhpThreadCriticalSection::$instancesListAArr);
			$i = 0;
			do {
				$this->intercomWrite = new GPhpThreadIntercom("{$this->pipeDir}gphpthread_{$this->uniqueId}_s{$this->myPid}-d{$afterForkPid}", false, true);
				if ($this->intercomWrite->isInitialized()) {
					$i = $retriesLimit;
				} else {
					++$i;
					usleep(mt_rand(5000, 80000));
				}
			} while ($i < $retriesLimit);
			
			$i = 0;
			do {
				$this->intercomRead = new GPhpThreadIntercom("{$this->pipeDir}gphpthread_{$this->uniqueId}_s{$afterForkPid}-d{$this->myPid}", true, true);
				if ($this->intercomRead->isInitialized()) {
					$i = $retriesLimit;
				} else {
					++$i;
					usleep(mt_rand(5000, 80000));
				}
			} while ($i < $retriesLimit);
		} else { // child
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
		}
		
		if (!$this->intercomWrite->isInitialized())	$this->intercomWrite = null;
		if (!$this->intercomRead->isInitialized())	$this->intercomRead = null;
		if ($this->intercomWrite == null || $this->intercomRead == null) $this->intercomInterlocutorPid = null;
		
		if ($this->intercomInterlocutorPid === null)
			unset(GPhpThreadCriticalSection::$instancesListAArr["{$this->uniqueId}"]);
			
		if ($this->intercomInterlocutorPid !== null) {
			$this->threadInstanceContext[$contextId] = array(
				'intercomWrite' => $this->intercomWrite,
				'intercomRead' => $this->intercomRead,
				'intercomInterlocutorPid' => $this->intercomInterlocutorPid,
				'dispatchPriority' => $this->dispatchPriority
			);
		}
		
	} /* }}} */
	
	private function encodeMessage($msg, $name, $value) { /* {{{ */
		// 2 decimal digits message code, 10 decimal digits PID,
		// 4 decimal digits name length, name, data
		return $msg . sprintf('%010d%04d', $this->myPid, strlen($name)) . $name . serialize($value);
	} /* }}} */
	
	private function decodeMessage($encodedMsg, &$msg, &$pid, &$name, &$value) { /* {{{ */
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
	} /* }}} */
	
	private function isIntercomBroken() { /* {{{ */
		return (empty($this->intercomWrite) || 
				empty($this->intercomRead) || 
				empty($this->intercomInterlocutorPid) ||
				!$this->isPidAlive($this->intercomInterlocutorPid));
	} /* }}} */
	
	private function send($operation, $resourceName, $resourceValue) { /* {{{ */
		if ($this->isIntercomBroken()) return false;
		
		$isSent = false;
		$isAlive = true;
		
		$msg = $this->encodeMessage($operation, $resourceName, $resourceValue);
		
		do {
			$isSent = $this->intercomWrite->send($msg, strlen($msg));
			if (!$isSent) {
				$isAlive = $this->isPidAlive($this->intercomInterlocutorPid);
				if ($isAlive) usleep(mt_rand(10000, 200000));
			}
			
		} while ((!$isSent) && $isAlive);
		
		return $isSent;		
	} /* }}} */
	
	private function receive(&$message, &$pid, &$resourceName, &$resourceValue) { /* {{{ */
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
		
		return !$isDataEmpty;
	} /* }}} */
	
	private function requestLock() { /* {{{ */
		$msg = $pid = $name = $value = null;
		
		if (!$this->send(GPhpThreadCriticalSection::$LOCKSYN, $name, $value)) return false;
		
		if (!$this->receive($msg, $pid, $name, $value)) return false;

		if ($msg != GPhpThreadCriticalSection::$LOCKACK)
			return false;

		$this->ownerPid = $this->myPid;
		return true;
	} /* }}} */
	
	private function requestUnlock() { /* {{{ */
		$msg = $pid = $name = $value = null;
		
		if (!$this->send(GPhpThreadCriticalSection::$UNLOCKSYN, $name, $value))
			return false;
			
		if (!$this->receive($msg, $pid, $name, $value))
			return false;
		
		if ($msg != GPhpThreadCriticalSection::$UNLOCKACK)
			return false;

		$this->ownerPid = $this->myPid;
		return true;
	} /* }}} */
	
	private function updateDataContainer($actionType, $name, $value) { /* TESTME {{{ */
		$result = false;
		
		$msg = null;
		$pid = null;
		
		switch ($actionType) {
			case GPhpThreadCriticalSection::$ADDORUPDATEACT:
				if ($name === null || $name === '') break;
				if (!$this->send(GPhpThreadCriticalSection::$ADDORUPDATESYN, $name, $value)) break;
				if (!$this->receive($msg, $pid, $name, $value)) break;
				if ($msg == GPhpThreadCriticalSection::$ADDORUPDATEACK) { 
					$result = true;
					$this->dataContainer[$name] = $value;
				}
			break;

			case GPhpThreadCriticalSection::$ERASEACT:
				if ($name === null || $name === '') break;
				if (!$this->send(GPhpThreadCriticalSection::$ERASESYN, $name, $value)) break;
				if (!$this->receive($msg, $pid, $name, $value)) break;
				if ($msg == GPhpThreadCriticalSection::$ERASEACK) {
					$result = true;
					unset($this->dataContainer[$name]);
				}
			break;

			case GPhpThreadCriticalSection::$READACT:
				if ($name === null || $name === '') break;
				if (!$this->send(GPhpThreadCriticalSection::$READSYN, $name, $value)) break;
				if (!$this->receive($msg, $pid, $name, $value)) break;
				if ($msg == GPhpThreadCriticalSection::$READACK) {
					$result = true;
					$this->dataContainer[$name] = $value;
				}
			break;
			
			case GPhpThreadCriticalSection::$READALLACT:
				if (!$this->send(GPhpThreadCriticalSection::$READALLSYN, $name, $value)) break;
				if (!$this->receive($msg, $pid, $name, $value)) break;
				if ($msg == GPhpThreadCriticalSection::$READALLACK) {
					$result = true;
					$this->dataContainer = $value;
				}
			break;
		}
		
		return $result;
	} /* }}} */
	
	private function isPidAlive($pid) { /* {{{ */
		if ($pid === false) return false;
		return posix_kill($pid, 0);
	} /* }}} */
		
	public static function signalCHLDHandler($signo) { /* {{{ */
		$_mypid = getmypid();
		foreach (GPhpThreadCriticalSection::$instancesListAArr as &$instance) {
			if ($instance->creatorPid != $_mypid) return; // prevent any threads to run their own CHILD signal handlers
		}

		$sigInfo = array();
		$sigSet = array(SIGCHLD);
		pcntl_sigwaitinfo($sigSet, $sigInfo);

		var_dump($sigInfo);
		exit;

		foreach (GPhpThreadCriticalSection::$instancesListAArr as &$instance) {
			
		}
	} /* }}} */

	public static function dispatch($useBlocking = false) { /* TODO TESTME {{{ */
		$NULL = null;
		$_mypid = getmypid();
		foreach (GPhpThreadCriticalSection::$instancesListAArr as &$instance) {
			if ($instance->creatorPid != $_mypid) return; // prevent any threads to run their own dispatchers
		}
		
		foreach (GPhpThreadCriticalSection::$instancesListForRemovalAArr as $inst) {
			unset(GPhpThreadCriticalSection::$instancesListAArr[$inst]);
		}
		GPhpThreadCriticalSection::$instancesListForRemovalAArr = array();

		foreach (GPhpThreadCriticalSection::$instancesListAArr as &$instance) {
			
			if (!empty($instance->threadInstanceContextListForRemoval)) {
				foreach ($instance->threadInstanceContextListForRemoval as $cid)
					unset($instance->threadInstanceContextList[$cid]);
				$instance->threadInstanceContextListForRemoval = array();
			}
			
			$intercomReadBak = $instance->intercomRead;
			$intercomWriteBak = $instance->intercomWrite;
			$intercomInterlocutorPidBak = $instance->intercomInterlocutorPid;
			$dispatchPriorityBak = $instance->dispatchPriority;
			
			// var_dump($instance->threadInstanceContext);

			$intercomOperationPerformed = false;
			
			foreach ($instance->threadInstanceContext as $contextId => $_data) {

				if ($intercomOperationPerformed) {
					break;
				}

				if (empty($instance->threadInstanceContext[$contextId])) continue;
				$instance->intercomWrite = $instance->threadInstanceContext[$contextId]['intercomWrite'];
				$instance->intercomRead = $instance->threadInstanceContext[$contextId]['intercomRead'];
				$instance->intercomInterlocutorPid = $instance->threadInstanceContext[$contextId]['intercomInterlocutorPid'];
				$instance->dispatchPriority = &$instance->threadInstanceContext[$contextId]['dispatchPriority'];

				echo "Dispatching {$instance->intercomInterlocutorPid}\n";
				
				//echo "Dispatch " . getmypid() . " {$instance->intercomInterlocutorPid} {" . mt_rand(0, 4000000) . "\n";
			
				if (!$useBlocking) {
					if (!$instance->intercomRead->isReceiveingDataAvailable()) {
						$instance->dispatchPriority = 0;
						if ($instance->isIntercomBroken()) unset($instance->threadInstanceContext[$contextId]); // remove the thread from the dispatching list as sonn as we cam
						continue;
					}
				}

				$msg = $pid = $name = $value = null;

				if (!$instance->receive($msg, $pid, $name, $value))	{
					$instance->dispatchPriority = 0;
					if ($instance->isIntercomBroken()) unset($instance->threadInstanceContext[$contextId]); // remove the thread from the dispatching list as sonn as we cam
					continue;
				}

				$intercomOperationPerformed = true;
				
				switch ($msg) {
					case GPhpThreadCriticalSection::$LOCKSYN:
						$instance->dispatchPriority = 1;
						if ($instance->ownerPid !== false && $instance->ownerPid != $pid && $instance->isPidAlive($instance->ownerPid)) {
							$instance->send(GPhpThreadCriticalSection::$LOCKNACK, null, $pid);
							continue;
						}
						if (!$instance->send(GPhpThreadCriticalSection::$LOCKACK, null, $pid)) continue;
						$instance->ownerPid = $pid;
						$instance->dispatchPriority = 2;
					break;
					
					case GPhpThreadCriticalSection::$UNLOCKSYN:
						$instance->dispatchPriority = 1;
						if ($instance->ownerPid === false) {
							if (!$instance->send(GPhpThreadCriticalSection::$UNLOCKACK, null, $pid)) continue;
						}
						$isOwnerAlive = $instance->isPidAlive($instance->ownerPid);
						if (!$isOwnerAlive || $instance->ownerPid == $pid) {
							if (!$isOwnerAlive) $instance->ownerPid = false;
							if (!$instance->send(GPhpThreadCriticalSection::$UNLOCKACK, null, $pid)) continue;
							$instance->dispatchPriority = 0;
							$instance->ownerPid = false;
						} else {
							$instance->send(GPhpThreadCriticalSection::$UNLOCKNACK, null, null);
						}
					break;
					
					case GPhpThreadCriticalSection::$ADDORUPDATESYN:
						$instance->dispatchPriority = 1;
						if ($instance->ownerPid !== $pid) {
							$instance->send(GPhpThreadCriticalSection::$ADDORUPDATENACK, null, null);
							continue;
						}
						if (!$instance->send(GPhpThreadCriticalSection::$ADDORUPDATEACK, $name, null)) continue;
						$instance->dispatchPriority = 2;
						$instance->dataContainer[$name] = $value;
					break;
					
					case GPhpThreadCriticalSection::$ERASESYN:
						$instance->dispatchPriority = 1;
						if ($instance->ownerPid !== $pid) {
							$instance->send(GPhpThreadCriticalSection::$ERASENACK, null, null);
							continue;
						}
						if (!$instance->send(GPhpThreadCriticalSection::$ERASEACK, $name, null)) continue;
						$instance->dispatchPriority = 2;
						unset($instance->dataContainer[$name]);
					break;
					
					case GPhpThreadCriticalSection::$READSYN:
						$instance->dispatchPriority = 1;
						if ($instance->ownerPid !== $pid) {
							$instance->send(GPhpThreadCriticalSection::$READNACK, null, null);
							continue;
						}
						$instance->send(GPhpThreadCriticalSection::$READACK, $name, $instance->dataContainer[$name]);
						$instance->dispatchPriority = 2;
					break;
					
					case GPhpThreadCriticalSection::$READALLSYN:
						$instance->dispatchPriority = 1;
						if ($instance->ownerPid !== $pid) {
							$instance->send(GPhpThreadCriticalSection::$READALLNACK, null, null);
							continue;
						}
						$instance->send(GPhpThreadCriticalSection::$READALLACK, null, $instance->dataContainer);
						$instance->dispatchPriority = 2;
					break;
				}
			}
			
			$instance->intercomRead = $intercomReadBak;
			$instance->intercomWrite = $intercomWriteBak;
			$instance->intercomInterlocutorPid = $intercomInterlocutorPidBak;
			$instance->dispatchPriority = &$NULL;
			$instance->dispatchPriority = $dispatchPriorityBak;

			// rearrange the the critical sections intercoms according
			// to their priority starting with the highest one
		
			uksort($instance->threadInstanceContext,
				function ($a, $b) use ($instance) {
					return $instance->threadInstanceContext[$a]['dispatchPriority'] < $instance->threadInstanceContext[$b]['dispatchPriority'];
				}
			);				
		}
	} /* }}} */

	public function lock($useBlocking = true) { /* {{{ */
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
		}
		while ($useBlocking && !$this->doIOwnIt());
		
	} /* }}} */
	
	public function unlock() { /* {{{ */
		if ($this->doIOwnIt() || $this->ownerPid === false) {
			if ($this->myPid == $this->creatorPid) { // local unlock request
				$this->ownerPid = false;
				return true;
			}
			return $this->requestUnlock();
		}
		return false;
	} /* }}} */
	
	public function addOrUpdateResource($name, $value) { /* {{{ */
		if ($this->doIOwnIt()) {
			if ($this->myPid == $this->creatorPid) { // local resource add/update request
				$this->dataContainer[$name] = $value;
				return true;
			}
			if (!$this->updateDataContainer(self::$ADDORUPDATEACT, $name, $value)) return false;
			return true;
		}
		return false;
	} /* }}} */
	
	public function removeResource($name) { /* {{{ */
		if ($this->doIOwnIt() &&
			isset($this->dataContainer[$name]) || 
			array_key_exists($name, $this->dataContainer)) {
			
			if ($this->myPid == $this->creatorPid) { // local resource remove request
				unset($this->dataContainer[$name]);
				return true;
			}
			
			if (!$this->updateDataContainer(self::$ERASEACT, $name, null)) return false;
			return true;
		}
		return false;	
	} /* }}} */
	
	public function getResourceValueFast($name) { /* {{{ */
		if (isset($this->dataContainer[$name]) || 
			array_key_exists($name, $this->dataContainer)) {

			return $this->dataContainer[$name];
		}
		return null;
	} /* }}} */
	
	public function getResourceValue($name) { /* {{{ */
		if (!$this->doIOwnIt())
			throw new GPhpThreadException('[' . getmypid() . '][' . $this->uniqueId . '] Not owned critical section!');
		
		if ($this->myPid == $this->creatorPid) { // local resource read request ; added to keep a consistency with getResourceValueFast
			return $this->getResourceValueFast($name);
		}
			
		if (!$this->updateDataContainer(self::$READACT, $name, null))
			throw new GPhpThreadException('[' . getmypid() . '][' . $this->uniqueId . '] Error while retrieving the value!');
		return $this->dataContainer[$name];
	} /* }}} */
	
	public function getResourceNames() { /* {{{ */
		return array_keys($this->dataContainer);
	} /* }}} */
} /* }}} */

abstract class GPhpThread /* {{{ */
{	protected $criticalSection = null;
	private $parentPid = null;
	private $childPid = null;
	private $exitCode = null;
	
	private $amIStarted = false;
	
	private $uniqueId = 0;
	private static $seed = 0;
	
	private static $isCriticalSectionDispatcherRegistered = false;
	private static $isSignalCHLDHandlerInstalled = false;
	
	public function __construct(&$criticalSection) {/* {{{ */
		$this->uniqueId = GPhpThread::$seed++;
		$this->criticalSection = $criticalSection;
		$this->parentPid = getmypid();
	} /* }}} */
	
	public function __destruct() { /* {{{ */
	} /* }}} */
	
	public final function getExitCode() { /* {{{ */
		return $this->exitCode;
	} /* }}} */
	
	private function amIParent() { /* {{{ */
		return ($this->childPid > 0 ? true : false);
	} /* }}} */

	private function notifyParentThatChildIsTerminated() { /* {{{ */
		if (!$this->amIParent) {
			posix_kill($this->parentPid, SIGCHLD);
		}
	} /* }}} */
	
	abstract public function run();	
	
	public final function start() { /* {{{ */
		if ($this->childPid !== null) exit(0);

		$this->childPid = pcntl_fork();
		if ($this->childPid == -1) return false;
		$this->amIStarted = true;
		if ($this->criticalSection !== null) $this->criticalSection->initialize($this->childPid, $this->uniqueId);
		if (!$this->amIParent()) { // child
			// no dispatchers needed in the childs; this means that no threads withing threads creation is possible
			unregister_tick_function('GPhpThreadCriticalSection::dispatch');
			pcntl_signal(SIGCHLD, SIG_DFL);
			$this->run();
			$this->stop();
			if ($this->criticalSection != null) $this->notifyParentThatChildIsTerminated();
		} else { // parent
			if ($this->childPid != -1 && $this->criticalSection !== null) {

				if (!GPhpThread::$isCriticalSectionDispatcherRegistered)				
					GPhpThread::$isCriticalSectionDispatcherRegistered = register_tick_function('GPhpThreadCriticalSection::dispatch');

				if (!GPhpThread::$isSignalCHLDHandlerInstalled)
					GPhpThread::$isSignalCHLDHandlerInstalled = pcntl_signal(SIGCHLD, 'GPhpThreadCriticalSection::signalCHLDHandler');
			}
		}
	} /* }}} */
	
	public final function stop($force = false) { /* {{{ */
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
	} /* }}} */
	
	public final function join($useBlocking = true) { /* {{{ */		
		if (!$this->amIStarted) return false;
		if ($this->amIParent()) {
			$status = null;
			$res = 0;
			if ($useBlocking) {
				while (($res = pcntl_waitpid($this->childPid, $status, WNOHANG)) == 0) usleep(mt_rand(60000, 200000));

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
	} /* }}} */
} /* }}} */
?>
