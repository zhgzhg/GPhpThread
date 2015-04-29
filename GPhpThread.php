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

define("DEBUG_MODE", true);

declare(ticks=1);

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
			stream_select($read, $commChanFdArr, $except, 10);

			while ($dataLength > 0)	{
				$bytesWritten = fwrite($this->commChanFdArr[0], $data);
				if ($bytesWritten === false) return false;
				$dataLength -= $bytesWritten;
				if ($dataLength > 0) {
					$commChanFdArr = $this->commChanFdArr;
					if (stream_select($read, $commChanFdArr, $except, 0, 200000) == 0)
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
		
		if (stream_select($commChanFdArr, $write, $except, 10) == 0) return $data;
		
		do {
			$d = fread($this->commChanFdArr[0], 1);
			if ($d !== false) $data .= $d;
			if (!isset($this->commChanFdArr[0]) || !is_resource($this->commChanFdArr[0])) break;
			$commChanFdArr = $this->commChanFdArr;
		} while ($d !== false && stream_select($commChanFdArr, $write, $except, 0, 200000) != 0);

		if (defined('DEBUG_MODE')) echo $data . '[' . getmypid() . "] received\n"; // 4 DEBUGGING1
		return $data;
	} /* }}} */
	
	public function isReceiveingDataAvailable() { /* {{{ */
		if (!$this->success || !$this->isReadMode) return false;
		if (!isset($this->commChanFdArr[0]) || !is_resource($this->commChanFdArr[0])) return false;
		
		$_commChanFdArr = $this->commChanFdArr;
		$write = $except = null;
		return (stream_select($_commChanFdArr, $write, $except, 0, 12000) != 0);
	} /* }}} */
} /* }}} */

class GPhpThreadCriticalSection /* {{{ */
{
	private $myPid;
	private $creatorPid;
	private $ownerPid = false;
	private $pipeDir = '';
	private $dataContainer = array();
	
	private $intercomWrite = null;
	private $intercomRead = null;
	
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
	
	public function finalize() { /* {{{ */
		GPhpThreadCriticalSection::$instancesListForRemovalAArr["{$this->uniqueId}"] = true;
	} /* }}} */
	
	private function doIOwnIt() { /* {{{ */
		return ($this->ownerPid !== false && $this->ownerPid == $this->myPid);
	} /* }}} */	
	
	public function initialize($afterForkPid) { /* {{{ */
		$this->uniqueId = GPhpThreadCriticalSection::$seed++;
		GPhpThreadCriticalSection::$instancesListAArr["{$this->uniqueId}"] = $this;
		
		$this->myPid = getmypid();
		
		$retriesLimit = 60;
		
		if ($this->myPid == $this->creatorPid) { // parent
			$i = 0;
			do {
				$this->intercomWrite = new GPhpThreadIntercom("{$this->pipeDir}gphpthread_s{$this->myPid}-d{$afterForkPid}", false, true);
				if ($this->intercomWrite->isInitialized()) {
					$i = $retriesLimit;
				} else {
					++$i;
					usleep(mt_rand(5000, 80000));
				}
			} while ($i < $retriesLimit);
			
			$i = 0;
			do {
				$this->intercomRead = new GPhpThreadIntercom("{$this->pipeDir}gphpthread_s{$afterForkPid}-d{$this->myPid}", true, false);
				if ($this->intercomRead->isInitialized()) {
					$i = $retriesLimit;
				} else {
					++$i;
					usleep(mt_rand(5000, 80000));
				}
			} while ($i < $retriesLimit);
		} else { // child
			$i = 0;
			do {
				$this->intercomWrite = new GPhpThreadIntercom("{$this->pipeDir}gphpthread_s{$this->myPid}-d{$this->creatorPid}", false, true);
				if ($this->intercomWrite->isInitialized()) {
					$i = $retriesLimit;
				} else {
					++$i;
					usleep(mt_rand(5000, 80000));
				}
			} while ($i < $retriesLimit);
			
			$i = 0;
			do {
				$this->intercomRead = new GPhpThreadIntercom("{$this->pipeDir}gphpthread_s{$this->creatorPid}-d{$this->myPid}", true, false);
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
		
		if ($this->intercomRead === null || $this->intercomWrite === null)
			unset(GPhpThreadCriticalSection::$instancesListAArr["{$this->uniqueId}"]);
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
	
	private function requestLock() { /* {{{ */
		if ($this->intercomWrite === null || $this->intercomRead === null) return false;

		$pid = $name = $value = null;

		$msg = $this->encodeMessage(GPhpThreadCriticalSection::$LOCKSYN, $name, $value);		
		if (!$this->intercomWrite->send($msg, strlen($msg))) return false;

		if (empty($ans = $this->intercomRead->receive())) return false;
		$this->decodeMessage($ans, $msg, $pid, $name, $value);

		if ($msg != GPhpThreadCriticalSection::$LOCKACK) return false;

		$this->ownerPid = $this->myPid;
		return true;
	} /* }}} */
	
	private function requestUnlock() { /* {{{ */
		if ($this->intercomWrite === null || $this->intercomRead === null) return false;

		$pid = $name = $value = null;

		$msg = $this->encodeMessage(GPhpThreadCriticalSection::$UNLOCKSYN, $name, $value);
		if (!$this->intercomWrite->send($msg, strlen($msg))) return false;

		if (empty($ans = $this->intercomRead->receive())) return false;
		$this->decodeMessage($ans, $msg, $pid, $name, $value);
		
		if ($msg != GPhpThreadCriticalSection::$UNLOCKACK) return false;

		$this->ownerPid = false;
		return true;
	} /* }}} */
	
	private function updateDataContainer($actionType, $name, $value) { /* TESTME {{{ */
		$result = false;
		if ($this->intercomWrite === null || $this->intercomRead === null) return $result;
		
		$msg = null;
		$pid = null;
		
		switch ($actionType) {
			case GPhpThreadCriticalSection::$ADDORUPDATEACT:
				if ($name === null || $name === '') break;
				$addreq = $this->encodeMessage(GPhpThreadCriticalSection::$ADDORUPDATESYN, $name, $value);
				if (!$this->intercomWrite->send($addreq, strlen($addreq))) break;
				if (empty($resp = $this->intercomRead->receive())) break;
				$this->decodeMessage($resp, $msg, $pid, $name, $_value = null);
				if ($msg == GPhpThreadCriticalSection::$ADDORUPDATEACK) { 
					$result = true;
					$this->dataContainer[$name] = $value;
				}
			break;

			case GPhpThreadCriticalSection::$ERASEACT:
				if ($name === null || $name === '') break;
				$addreq = $this->encodeMessage(GPhpThreadCriticalSection::$ERASESYN, $name, $value);
				if (!$this->intercomWrite->send($addreq, strlen($addreq))) break;
				if (empty($resp = $this->intercomRead->receive())) break;
				$this->decodeMessage($resp, $msg, $pid, $name, $value);
				if ($msg == GPhpThreadCriticalSection::$ERASEACK) {
					$result = true;
					unset($this->dataContainer[$name]);
				}
			break;

			case GPhpThreadCriticalSection::$READACT:
				if ($name === null || $name === '') break;
				$addreq = $this->encodeMessage(GPhpThreadCriticalSection::$READSYN, $name, $value);
				if (!$this->intercomWrite->send($addreq, strlen($addreq))) break;
				if (empty($resp = $this->intercomRead->receive())) break;
				$this->decodeMessage($resp, $msg, $pid, $name, $value);
				if ($msg == GPhpThreadCriticalSection::$READACK) {
					$result = true;
					$this->dataContainer[$name] = $value;
				}
			break;
			
			case GPhpThreadCriticalSection::$READALLACT:
				$addreq = $this->encodeMessage(GPhpThreadCriticalSection::$READALLSYN, $name, $value);
				if (!$this->intercomWrite->send($addreq, strlen($addreq))) break;
				$resp = $this->intercomRead->receive();
				if (empty($resp)) break;
				$this->decodeMessage($resp, $msg, $pid, $name, $value);
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
		if (file_exists("/proc/$pid")) return true;
		return false;
	} /* }}} */
		
	public static function dispatch($useBlocking = false) { /* TODO TESTME {{{ */	
		foreach (GPhpThreadCriticalSection::$instancesListForRemovalAArr as $inst) {
			unset(GPhpThreadCriticalSection::$instancesListAArr[$inst]);
		}
		GPhpThreadCriticalSection::$instancesListForRemovalAArr = array();
			
		foreach (GPhpThreadCriticalSection::$instancesListAArr as &$instance) {
		
			if (!$useBlocking) {
				if (!$instance->intercomRead->isReceiveingDataAvailable()) continue;
			}		
			
			$req = $instance->intercomRead->receive();
			if (!$req) continue;
			
			$msg = $pid = $name = $value = null;
			$instance->decodeMessage($req, $msg, $pid, $name, $value);
			
			switch ($msg) {
				case GPhpThreadCriticalSection::$LOCKSYN:
					if ($instance->ownerPid !== false && $instance->ownerPid != $pid && $instance->isPidAlive($instance->ownerPid)) {
						$resp = $instance->encodeMessage(GPhpThreadCriticalSection::$LOCKNACK, null, $pid);
						$r = $instance->intercomWrite->send($resp, strlen($resp));
						continue;
					}
					$resp = $instance->encodeMessage(GPhpThreadCriticalSection::$LOCKACK, null, $pid);
					if (!$instance->intercomWrite->send($resp, strlen($resp))) continue;
					$instance->ownerPid = $pid;
				break;
				
				case GPhpThreadCriticalSection::$UNLOCKSYN:
					if ($instance->ownerPid === false) {
						$resp = $instance->encodeMessage(GPhpThreadCriticalSection::$UNLOCKACK, null, $pid);
						if (!$instance->intercomWrite->send($resp, strlen($resp))) continue;
					}
					$isOwnerAlive = $instance->isPidAlive($instance->ownerPid);
					if (!$isOwnerAlive || $instance->ownerPid == $pid) {
						if (!$isOwnerAlive) $instance->ownerPid = false;
						$resp = $instance->encodeMessage(GPhpThreadCriticalSection::$UNLOCKACK, null, $pid);
						if (!$instance->intercomWrite->send($resp, strlen($resp))) continue;
						$instance->ownerPid = false;
					} else {
						$resp = $instance->encodeMessage(GPhpThreadCriticalSection::$UNLOCKNACK, null, null);
						$instance->intercomWrite->send($resp, strlen($resp));
						continue;
					}
				break;
				
				case GPhpThreadCriticalSection::$ADDORUPDATESYN:
					if ($instance->ownerPid !== $pid) {
						$resp = $instance->encodeMessage(GPhpThreadCriticalSection::$ADDORUPDATENACK, null, null);
						$instance->intercomWrite->send($resp, strlen($resp));
						continue;
					}
					$resp = $instance->encodeMessage(GPhpThreadCriticalSection::$ADDORUPDATEACK, $name, null);
					if (!$instance->intercomWrite->send($resp, strlen($resp))) continue;
					$instance->dataContainer[$name] = $value;
				break;
				
				case GPhpThreadCriticalSection::$ERASESYN:
					if ($instance->ownerPid !== $pid) {
						$resp = $instance->encodeMessage(GPhpThreadCriticalSection::$ERASENACK, null, null);
						$instance->intercomWrite->send($resp, strlen($resp));
						continue;
					}
					$resp = $instance->encodeMessage(GPhpThreadCriticalSection::$ERASEACK, $name, null);
					if (!$instance->intercomWrite->send($resp, strlen($resp))) continue;
					unset($instance->dataContainer[$name]);
				break;
				
				case GPhpThreadCriticalSection::$READSYN:
					if ($instance->ownerPid !== $pid) {
						$resp = $instance->encodeMessage(GPhpThreadCriticalSection::$READNACK, null, null);
						$instance->intercomWrite->send($resp, strlen($resp));
						continue;
					}
					$resp = $instance->encodeMessage(GPhpThreadCriticalSection::$READACK, $name, $instance->dataContainer[$name]);
					if (!$instance->intercomWrite->send($resp, strlen($resp))) continue;
				break;
				
				case GPhpThreadCriticalSection::$READALLSYN:
					if ($instance->ownerPid !== $pid) {
						$resp = $instance->encodeMessage(GPhpThreadCriticalSection::$READALLNACK, null, null);
						$instance->intercomWrite->send($resp, strlen($resp));
						continue;
					}
					$resp = $instance->encodeMessage(GPhpThreadCriticalSection::$READALLACK, null, $instance->dataContainer);
					if (!$instance->intercomWrite->send($resp, strlen($resp))) continue;
				break;
			}
		}
	} /* }}} */

	public function lock($useBlocking = true) { /* {{{ */
		if ($this->doIOwnIt()) return true;
		
		if ($this->ownerPid === false || !$this->isPidAlive($this->ownerPid)) {
			if ($this->myPid == $this->creatorPid) { // local lock request
				$this->ownerPid = $this->myPid;
				return true;
			}
			
			do {
				echo "REQUESTVAME (" . getmypid() . ") !!!\n";
				$res = $this->requestLock();

				if ($useBlocking && !$res) {
					if (!$this->isPidAlive($this->creatorPid)) return false;
					usleep(mt_rand(70000, 250000));
				}
				if ($res) break;
			} while ($useBlocking);
			
			if (!$res) return false;

			if (!$this->updateDataContainer(self::$READALLACT, null, null)) {
				$this->unlock();
				return false;
			}
			return true;
		}
		return false;
	} /* }}} */
	
	public function unlock() { /* {{{ */
		if ($this->doIOwnIt() || $this->ownerPid === false) {
			if ($this->myPid == $this->creatorPid) { // local unlock request
				$this->ownerPid = false;
				return true;
			}
			if (!$this->isPidAlive($this->creatorPid)) return true;
			if (!$this->requestUnlock()) return false;
			return true;
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
	private $childPid = null;
	private $exitCode = null;
	
	private static $isCriticalSectionDispatcherRegistered = false;
	
	public function __construct(&$criticalSection) {/* {{{ */
		$this->criticalSection = $criticalSection;
	} /* }}} */
	
	public function __destruct() { /* {{{ */
		$this->stop();
	} /* }}} */
	
	public final function getExitCode() { /* {{{ */
		return $this->exitCode;
	} /* }}} */
	
	private function amIParent() { /* {{{ */
		if ($this->childPid > 0 || $this->childPid == -1) return true;
		return false;
	} /* }}} */
	
	abstract public function run();	
	
	public final function start() { /* {{{ */
		$this->childPid = pcntl_fork();
		if ($this->childPid == -1) return false;
		if ($this->criticalSection !== null) $this->criticalSection->initialize($this->childPid);
		if (!$this->amIParent()) { // child
			if (GPhpThread::$isCriticalSectionDispatcherRegistered) { // no dispatchers needed in the childs; this means that no threads withing threads creation is possible
				unregister_tick_function('GPhpThreadCriticalSection::dispatch');
				GPhpThread::$isCriticalSectionDispatcherRegistered = false;
			}			
			$this->childPid = -99;
			$this->run();
			$this->stop();
		} else if ($this->criticalSection !== null)	{ // parent
			if (!GPhpThread::$isCriticalSectionDispatcherRegistered) {
				if (register_tick_function('GPhpThreadCriticalSection::dispatch')) {
					GPhpThread::$isCriticalSectionDispatcherRegistered = true;
				}
			}
		}
	} /* }}} */
	
	public final function stop($force = false) { /* {{{ */
		if ($this->amIParent() && $this->childPid !== null) { // parent
			$r = posix_kill($this->childPid, ($force == false ? 15 : 9));
			if ($r) {
				if ($this->join()) $this->childPid = null;
				if ($this->criticalSection !== null) {
					$this->criticalSection->finalize();
				}
			}
			return $r;
		}
		
		// child
		if ($this->childPid == -1) return false;
		
		if ($this->childPid !== null)
			exit(0);
	} /* }}} */
	
	public final function join($useBlocking = true) {	/* {{{ */
		if ($this->amIParent() && $this->childPid !== null) {
			$status = null;
			if ($useBlocking) {
				$res = 0;
				while (($res = pcntl_waitpid($this->childPid, $status, WNOHANG)) == 0) usleep(mt_rand(80000, 250000));
				if ($res > 0 && pcntl_wifexited($status)) {
					$this->exitCode = pcntl_wexitstatus($status);
					if ($this->criticalSection !== null) $this->criticalSection->finalize();
				} else {
					$this->exitCode = false;
				}
				
				if ($res > 0 && $this->criticalSection !== null) $this->criticalSection->finalize();
				return $res;
			}
			$res = pcntl_waitpid($this->childPid, $status, WNOHANG);
			if ($res > 0 && $this->criticalSection !== null) $this->criticalSection->finalize();
			if ($res > 0 && pcntl_wifexited($status)) {
				$this->exitCode = pcntl_wexitstatus($status);
				if ($this->criticalSection !== null) $this->criticalSection->finalize();
			} else if ($res == -1) {
				$this->exitCode = false;
			}
			return $res;
		}		
		return false;
	} /* }}} */
} /* }}} */
?>
