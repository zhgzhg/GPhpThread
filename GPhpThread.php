<?php
/**
 * The MIT License (MIT)
 *
 * Copyright (c) 2024 zhgzhg
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
 *
 * @author zhgzhg @ github.com
 * @version 1.0.5
 * @copyright zhgzhg, 2024
 */

// define("DEBUG_MODE", true);

declare(ticks=30);

/**
 * Exception thrown by GPhpThreads components
 * @api
 */
class GPhpThreadException extends \Exception // {{{
{
	/**
	 * Constructor.
	 * @param string $msg Exception message.
	 * @param int $code Exception code number.
	 * @param Exception $previous The previous exception used for the exception chaining.
	 */
	public function __construct($msg, $code = 0, \Exception $previous = NULL) {
		parent::__construct($msg, $code, $previous);
	}
} // }}}


/**
 * Process intercommunication class.
 * A pipe inter-process communication method is used, based on asynchronous or synchronous communication.
 * @api
 */
class GPhpThreadIntercom // {{{
{
	/** @internal */
	private $commFilePath = '';
	/** @internal */
	private $commChanFdArr = array();
	/** @internal */
	private $success = true;
	/** @internal */
	private $autoDeletion = false;
	/** @internal */
	private $isReadMode = true;
	/** @internal */
	private $ownerPid = null;

	/**
	 * Constructor.
	 * @param string $filePath The file that is going to store the pipe.
	 * @param bool $isReadMode Indicates if the param is going to be read only
	 * @param bool $autoDeletion If it is set during the destruction of the GPhpThreadIntercom instance the pipe file will be also removed.
	 */
	public function __construct($filePath, $isReadMode = true, $autoDeletion=false) { // {{{
		$this->ownerPid = getmypid();
		if (!file_exists($filePath)) {
			if (!posix_mkfifo($filePath, 0644)) {
				$this->success = false;
				return;
			}
		}

		$commChanFd = fopen($filePath, ($isReadMode ? 'r+' : 'w+')); // + mode makes it non blocking too
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
	} // }}}

	/**
	 * Checks if the intercom is initialized and ready for use.
	 * @return bool
	 */
	public function isInitialized() { // {{{
		return $this->success;
	} // }}}

	/**
	 * Returns the state of the option automatic deletion of the pipe file.
	 * @return bool
	 */
	public function getAutoDeletionFlag() { // {{{
		return $this->autoDeletion;
	} // }}}

	/**
	 * Sets the automatic deletion option for the pipe file during the destruction of current instance.
	 * @param bool $booleanValue
	 * @return void
	 */
	public function setAutoDeletionFlag($booleanValue) { // {{{
		$this->autoDeletion = $booleanValue;
	} // }}}

	/**
	 * Destructor. May try automatically to delete the pipe file.
	 */
	public function __destruct() { // {{{
		if ($this->success && $this->ownerPid === getmypid()) {
			if (isset($this->commChanFdArr[0]) &&
				is_resource($this->commChanFdArr[0])) {
				fclose($this->commChanFdArr[0]);
			}
			if ($this->autoDeletion) @unlink($this->commFilePath);
		}
	} // }}}

	/**
	 * Sends data through the intercom.
	 * @param string $dataString The data to be sent
	 * @param int $dataLength The length of the data
	 * @return bool On success returns true otherwise false is returned.
	 */
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

	/**
	 * Receives data from the intercom.
	 * @param int $timeout The maximum time period in milliseconds during which to wait for data to arrive.
	 * @return string|null If there is no data up to 700 ms after the method is called it returns NULL otherwise returns the data string itself.
	 */
	public function receive($timeout = 700) { // {{{
		if (!$this->success || !$this->isReadMode) return false;
		if (!isset($this->commChanFdArr[0]) || !is_resource($this->commChanFdArr[0])) return false;

		$commChanFdArr = $this->commChanFdArr;

		$write = $except = null;
		$data = null;

		$timeout = abs((int)$timeout);
		$timeout *= 1000; // convert us to ms

		if (stream_select($commChanFdArr, $write, $except, 0, $timeout) == 0) return $data;

		do {
			$d = fread($this->commChanFdArr[0], 1);
			if ($d !== false) $data .= $d;
			if (!isset($this->commChanFdArr[0]) || !is_resource($this->commChanFdArr[0])) break;
			$commChanFdArr = $this->commChanFdArr;
		} while ($d !== false && stream_select($commChanFdArr, $write, $except, 0, 22000) != 0);

		//if (defined('DEBUG_MODE')) echo $data . '[' . getmypid() . "] received\n"; // 4 DEBUGGING
		return $data;
	} // }}}

	/**
	 * Checks if there is pending data for receiving.
	 * @return mixed Returns 0 or false if there is no data up to 15 ms after the method is called. Otherwise returns the number of contained stream resources (usually 1).
	 */
	public function isReceivingDataAvailable() { // {{{
		if (!$this->success || !$this->isReadMode) return false;
		if (!isset($this->commChanFdArr[0]) || !is_resource($this->commChanFdArr[0])) return false;

		$commChanFdArr = $this->commChanFdArr;
		$write = null; $except = null;
		return (stream_select($commChanFdArr, $write, $except, 0, 15000) != 0);
	} // }}}
} // }}}


/**
 * Critical section for sharing data between multiple processes.
 * It provides a slower data-safe manipulating methods and unsafe faster methods.
 * @see \GPhpThreadIntercom \GPhpThreadIntercom is used for synchronization between the different processes.
 * @api
 */
class GPhpThreadCriticalSection // {{{
{
	/** @internal */
	private $uniqueId = 0;								// the identifier of a concrete instance

	/** @internal */
	private static $uniqueIdSeed = 0;				    // the uniqueId index seed

	/** @internal */
	private static $instancesCreatedEverAArr = array(); // contain all the instances that were ever created of this class
	/** @internal */
	private static $threadsForRemovalAArr = array();    // contain all the instances that were terminated; used to make connection with $mastersThreadSpecificData

	/** @internal */
	private $creatorPid;
	/** @internal */
	private $pipeDir = '';
	/** @internal */
	private $ownerPid = false;  // the thread PID owning the critical section
	/** @internal */
	private $myPid; 			// point of view of the current instance

	/** @internal */
	private $sharedData = array('rel' => array(), 'unrel' => array()); // variables shared in one CS instance among all threads ; two sections are provided reliable one that requires locking of the critical section and unreliable one that does not require locking

	/** @internal */
	private $mastersThreadSpecificData = array(); // specific per each thread variables / the host is the master parent

	// ======== thread specific variables ========
	/** @internal */
	private $intercomWrite = null;
	/** @internal */
	private $intercomRead = null;
	/** @internal */
	private $intercomInterlocutorPid = null;
	/** @internal */
	private $dispatchPriority = 0;
	// ===========================================

	/**
	 * @internal Special variable used in PHP 5.3 to emulate a context-specific use operator with lambdas
	 */
	private $bindVariable;
	/**
	 * @internal Special variable used in PHP 5.3 to emulate a context-specific use operator with lambdas
	 */
	private static $bindStaticVariable;

	/** @internal */
	private static $ADDORUPDATESYN = '00', $ADDORUPDATEACK = '01', $ADDORUPDATENACK = '02',
				   $UNRELADDORUPDATESYN = '03', $UNRELADDORUPDATEACK = '04', $UNRELADDORUPDATENACK = '05',

				   $ERASESYN = '06', $ERASEACK = '07', $ERASENACK = '08',
				   $UNRELERASESYN = '09', $UNRELERASEACK = '10', $UNRELERASENACK = '11',

				   $READSYN = '12', $READACK = '13', $READNACK = '14',
				   $UNRELREADSYN = '15', $UNRELREADACK = '16', $UNRELREADNACK = '17',

				   $READALLSYN = '18', $READALLACK = '19', $READALLNACK = '20',

				   $LOCKSYN = '21', $LOCKACK = '22', $LOCKNACK = '23',
				   $UNLOCKSYN = '24', $UNLOCKACK = '25', $UNLOCKNACK = '26';

	/** @internal */
	private static $ADDORUPDATEACT = 1, $UNRELADDORUPDATEACT = 2,
				   $ERASEACT = 3, $UNRELERASEACT = 4,
				   $READACT = 5, $UNRELREADACT = 6, $READALLACT = 7;

	/**
	 * Constructor.
	 * @param string $pipeDirectory The directory where the pipe files for the inter-process communication will be stored.
	 */
	public function __construct($pipeDirectory = '/dev/shm') { // {{{
		$this->uniqueId = self::$uniqueIdSeed++;

		self::$instancesCreatedEverAArr[$this->uniqueId] = &$this;

		$this->creatorPid = getmypid();
		$this->pipeDir = rtrim($pipeDirectory, ' /') . '/';
	} // }}}

	/**
	 * Destructor.
	 */
	public function __destruct() { // {{{
		$this->intercomRead = null;
		$this->intercomWrite = null;
		if (self::$instancesCreatedEverAArr !== null)
			unset(self::$instancesCreatedEverAArr[$this->uniqueId]);
	} // }}}

	/**
	 * Initializes the critical section.
	 * @param int $afterForkPid The process identifier obtained after the execution of a fork() used to differentiate between different processes - pseudo threads.
	 * @param int $threadId The internal to the GPhpThread identifier of the current thread instance which is unique identifier in the context of the current process.
	 * @return bool On success returns true otherwise false.
	 */
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
			$this->mastersThreadSpecificData = null; // and any details for the threads inside cs instance simulation
			self::$threadsForRemovalAArr = null;

			// these point to the same memory location and also become
			// aliases of each other which is strange?!?
			// so we need to recreate them
			unset($this->intercomWrite);
			unset($this->intercomRead);
			unset($this->intercomInterlocutorPid);

			$this->intercomWrite = null;
			$this->intercomRead = null;
			$this->intercomInterlocutorPid = null;

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


	/**
	 * Cleans pipe files garbage left from any ungracefully terminated instances.
	 * @return int The total number of unused, cleaned pipe garbage files.
	 */
	public function cleanPipeGarbage() { // {{{
		$i = 0;
		$dirFp = @opendir($this->pipeDir);
		if ($dirFp !== false) {
			while (($fileName = readdir($dirFp)) !== false) {
				if ($fileName[0] != '.' && is_file($this->pipeDir . $fileName)) {
					if (preg_match("/^gphpthread_\d+_s(\d+)\-d(\d+)$/", $fileName, $matches)) {
						if (!posix_kill($matches[1], 0) && !posix_kill($matches[2], 0)) {
							if (@unlink($this->pipeDir . $fileName)) {
								++$i;
							}
						}
					}
				}
			}
			closedir($dirFp);
		}
		return $i;
	} // }}}

	/**
	 * Finalization of a thread instance that ended and soon will be destroyed.
	 * @param int $threadId The internal thread identifier.
	 * @return void
	 */
	public function finalize($threadId) { // {{{
		unset($this->mastersThreadSpecificData[$threadId]);
	} // }}}

	/**
	 * Confirms if the current thread has the ownership of the critical section associated with it.
	 * @return bool
	 */
	private function doIOwnIt() { // {{{
		return ($this->ownerPid !== false && $this->ownerPid == $this->myPid);
	} // }}}

	/**
	 * Encodes data in a message that will be sent to the thread process dispatcher.
	 * @param string $msg The message type identifier.
	 * @param string $name The variable name whose data is going to be sent.
	 * @param mixed $value The current value of the desired for sending variable.
	 * @return string The composed message string
	 */
	private function encodeMessage($msg, $name, $value) { // {{{
		// 2 decimal digits message code, 10 decimal digits PID,
		// 4 decimal digits name length, name, data
		return $msg . sprintf('%010d%04d', $this->myPid, ($name !== null ? strlen($name) : 0)) . $name . serialize($value);
	} // }}}

	/**
	 * Decodes encoded from GPhpThread's instance message.
	 * @param string $encodedMsg The encoded message
	 * @param string $msg The encoded message type identifier. REFERENCE type.
	 * @param int $pid The process id of the sender. REFERENCE type.
	 * @param string $name The variable name whose data was sent. REFERENCE type.
	 * @param mixed $value The variable data contained in the encoded message. REFERENCE type.
	 * @return void
	 */
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

	/**
	 * Checks if the internal intercom is broken.
	 * @return bool Returns true if the intercom is broken otherwise returns false.
	 */
	private function isIntercomBroken() { // {{{
		return (empty($this->intercomWrite) ||
				empty($this->intercomRead) ||
				empty($this->intercomInterlocutorPid) ||
				!$this->isPidAlive($this->intercomInterlocutorPid));
	} // }}}

	/**
	 * Sends data operation to the main process dispatcher.
	 * @param string $operation The operation type code.
	 * @param string $resourceName The name of resource that is holding a particular data that will be "shared".
	 * @param mixed $resourceValue The value of the resource.
	 * @return bool Returns true on success otherwise false.
	 */
	private function send($operation, $resourceName, $resourceValue) { // {{{
		if ($this->isIntercomBroken()) return false;

		$isAlive = true;

		$msg = $this->encodeMessage($operation, $resourceName, $resourceValue);

		if (defined('DEBUG_MODE')) {
			$dbgStr = '[' . getmypid() . "] is sending ";
			switch ($operation) {
				case self::$ADDORUPDATESYN: $dbgStr .= 'ADDORUPDATESYN'; break;
				case self::$ADDORUPDATEACK: $dbgStr .= 'ADDORUPDATEACK'; break;
				case self::$ADDORUPDATENACK: $dbgStr .= 'ADDORUPDATE_N_ACK'; break;
				case self::$UNRELADDORUPDATESYN: $dbgStr .= 'UNRELADDORUPDATESYN'; break;
				case self::$UNRELADDORUPDATEACK: $dbgStr .= 'UNRELADDORUPDATEACK'; break;
				case self::$UNRELADDORUPDATENACK: $dbgStr .= 'UNRELADDORUPDATE_N_ACK'; break;
				case self::$ERASESYN: $dbgStr .= 'ERASESYN'; break;
				case self::$ERASEACK: $dbgStr .= 'ERASEACK'; break;
				case self::$ERASENACK: $dbgStr .= 'ERASE_N_ACK'; break;
				case self::$UNRELERASESYN: $dbgStr .= 'UNRELERASESYN'; break;
				case self::$UNRELERASEACK: $dbgStr .= 'UNRELERASEACK'; break;
				case self::$UNRELERASENACK: $dbgStr .= 'UNRELERASE_N_ACK'; break;
				case self::$READSYN: $dbgStr .= 'READSYN'; break;
				case self::$READACK: $dbgStr .= 'READACK'; break;
				case self::$READNACK: $dbgStr .= 'READ_N_ACK'; break;
				case self::$UNRELREADSYN: $dbgStr .= 'UNRELREADSYN'; break;
				case self::$UNRELREADACK: $dbgStr .= 'UNRELREADACK'; break;
				case self::$UNRELREADNACK: $dbgStr .= 'UNRELREAD_N_ACK'; break;
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
				if ($isAlive) {
					for ($i = 0; $i < 120; ++$i) { }
					usleep(mt_rand(10000, 200000));
				}
			}

		} while ((!$isSent) && $isAlive);

		return $isSent;
	} // }}}

	/**
	 * Receives data operation from the main process dispatcher.
	 * @param string $message The operation type code. REFERENCE type.
	 * @param int $pid The process id of the sender "thread". REFERENCE type.
	 * @param string $resourceName The name of resource that is holding a particular data that will be "shared". REFERENCE type.
	 * @param mixed $resourceValue The value of the resource. REFERENCE type.
	 * @param int $timeInterval Internal wait time interval in milliseconds between each data check.
	 * @return bool Returns true on success otherwise false.
	 */
	private function receive(&$message, &$pid, &$resourceName, &$resourceValue, $timeInterval = 700) { // {{{
		if ($this->isIntercomBroken()) return false;

		$data = null;
		$isAlive = true;

		do {
			$data = $this->intercomRead->receive($timeInterval);
			$isDataEmpty = empty($data);
			if ($isDataEmpty) {
				$isAlive = $this->isPidAlive($this->intercomInterlocutorPid);
				if ($isAlive) {
					for ($i = 0; $i < 120; ++$i) { }
					usleep(mt_rand(10000, 200000));
				}
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
				case self::$UNRELADDORUPDATESYN: $dbgStr .= 'UNRELADDORUPDATESYN'; break;
				case self::$UNRELADDORUPDATEACK: $dbgStr .= 'UNRELADDORUPDATEACK'; break;
				case self::$UNRELADDORUPDATENACK: $dbgStr .= 'UNRELADDORUPDATE_N_ACK'; break;
				case self::$ERASESYN: $dbgStr .= 'ERASESYN'; break;
				case self::$ERASEACK: $dbgStr .= 'ERASEACK'; break;
				case self::$ERASENACK: $dbgStr .= 'ERASE_N_ACK'; break;
				case self::$UNRELERASESYN: $dbgStr .= 'UNRELERASESYN'; break;
				case self::$UNRELERASEACK: $dbgStr .= 'UNRELERASEACK'; break;
				case self::$UNRELERASENACK: $dbgStr .= 'UNRELERASE_N_ACK'; break;
				case self::$READSYN: $dbgStr .= 'READSYN'; break;
				case self::$READACK: $dbgStr .= 'READACK'; break;
				case self::$READNACK: $dbgStr .= 'READ_N_ACK'; break;
				case self::$UNRELREADSYN: $dbgStr .= 'UNRELREADSYN'; break;
				case self::$UNRELREADACK: $dbgStr .= 'UNRELREADACK'; break;
				case self::$UNRELREADNACK: $dbgStr .= 'UNRELREAD_N_ACK'; break;
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

	/**
	 * Tries to lock the critical section.
	 * @return bool Returns true on success otherwise false.
	 */
	private function requestLock() { // {{{
		$msg = $pid = $name = $value = null;

		if (!$this->send(self::$LOCKSYN, $name, $value)) return false;

		if (!$this->receive($msg, $pid, $name, $value)) return false;

		if ($msg != self::$LOCKACK)
			return false;

		$this->ownerPid = $this->myPid;
		return true;
	} // }}}

	/**
	 * Tries to unlock the critical section.
	 * @return bool Returns true on success otherwise false.
	 */
	private function requestUnlock() { // {{{
		$msg = $pid = $name = $value = null;

		if (!$this->send(self::$UNLOCKSYN, $name, $value))
			return false;

		if (!$this->receive($msg, $pid, $name, $value))
			return false;

		if ($msg != self::$UNLOCKACK)
			return false;

		$this->ownerPid = false;
		return true;
	} // }}}

	/**
	 * Executes data operation on the internal shared data container.
	 * @param string $actionType The performed operation code.
	 * @param string $name The name of the resource.
	 * @param mixed $value The value of the resource.
	 * @return bool Returns true on success otherwise false.
	 */
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
					$this->sharedData['rel'][$name] = $value;
				}
			break;

			case self::$UNRELADDORUPDATEACT:
				if ($name === null || $name === '') break;
				if (!$this->send(self::$UNRELADDORUPDATESYN, $name, $value)) break;
				if (!$this->receive($msg, $pid, $name, $value)) break;
				if ($msg == self::$UNRELADDORUPDATEACK) {
					$result = true;
					$this->sharedData['unrel'][$name] = $value;
				}
			break;

			case self::$ERASEACT:
				if ($name === null || $name === '') break;
				if (!$this->send(self::$ERASESYN, $name, $value)) break;
				if (!$this->receive($msg, $pid, $name, $value)) break;
				if ($msg == self::$ERASEACK) {
					$result = true;
					unset($this->sharedData['rel'][$name]);
				}
			break;

			case self::$UNRELERASEACT:
				if ($name === null || $name === '') break;
				if (!$this->send(self::$UNRELERASESYN, $name, $value)) break;
				if (!$this->receive($msg, $pid, $name, $value)) break;
				if ($msg == self::$UNRELERASEACK) {
					$result = true;
					unset($this->sharedData['unrel'][$name]);
				}
			break;

			case self::$READACT:
				if ($name === null || $name === '') break;
				if (!$this->send(self::$READSYN, $name, $value)) break;
				if (!$this->receive($msg, $pid, $name, $value)) break;
				if ($msg == self::$READACK) {
					$result = true;
					$this->sharedData['rel'][$name] = $value;
				}
			break;

			case self::$UNRELREADACT:
				if ($name === null || $name === '') break;
				if (!$this->send(self::$UNRELREADSYN, $name, $value)) break;
				if (!$this->receive($msg, $pid, $name, $value)) break;
				if ($msg == self::$UNRELREADACK) {
					$result = true;
					$this->sharedData['unrel'][$name] = $value;
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

	/**
	 * Checks if specific process id is still alive.
	 * @param int $pid The process identifier.
	 * @return bool Returns true if the pid is alive otherwise returns false.
	 */
	private function isPidAlive($pid) { // {{{
		if ($pid === false) return false;
		return posix_kill($pid, 0);
	} // }}}


	/**
	 * Sort by occurred lock and dispatch priority. This is a workaround
	 * method required for PHP 5.3 and relies on an initialized
	 * $bindVariable inside this class.
	 * @param mixed $a The first key to be taken into account.
	 * @param mixed $b The second key to be taken into account.
	 * @return int -1, 0 or 1 depending on which key is more preferable.
	 */
	private function sortByLockAndDispatchPriority($a, $b) { // {{{
		$presentIndexA = false;
		if (isset($this->bindVariable->mastersThreadSpecificData[$a])) {
			if ($this->bindVariable->mastersThreadSpecificData[$a]['intercomInterlocutorPid'] === $this->bindVariable->ownerPid) return -1;
			$presentIndexA = true;
		}
		$presentIndexB = false;
		if (isset($this->bindVariable->mastersThreadSpecificData[$b])) {
			if ($this->bindVariable->mastersThreadSpecificData[$b]['intercomInterlocutorPid'] === $this->bindVariable->ownerPid) return 1;
			$presentIndexB = true;
		}
		if (!($presentIndexA && $presentIndexB)) {
			return ($presentIndexA ? -1 : (int)$presentIndexB);
		}
		return (int)($this->bindVariable->mastersThreadSpecificData[$a]['dispatchPriority'] < $this->bindVariable->mastersThreadSpecificData[$b]['dispatchPriority']);
	} // }}}

	/**
	 * Sort by occurred lock, dispatch priority and most threads using
	 * the critical section. This is workaround method required for
	 * PHP 5.3 and relies on an initialized $bindVariable inside this
	 * class.
	 * @param mixed $a The first key to be taken into account.
	 * @param mixed $b The second key to be taken into account.
	 * @return int -1, 0 or 1 depending on which key is more preferable.
	 */
	 private static function sortByLockDispatchPriorityAndMostThreadsInside($a, $b) { // {{{
		// the locker thread is with highest priority
		if (self::$bindStaticVariable[$a]->mastersThreadSpecificData['intercomInterlocutorPid'] == self::$bindStaticVariable[$a]->ownerPid) return -1;
		if (self::$bindStaticVariable[$b]->mastersThreadSpecificData['intercomInterlocutorPid'] == self::$bindStaticVariable[$b]->ownerPid) return 1;

		// deal with the case of critical sections with no threads
		if (!empty(self::$bindStaticVariable[$a]->mastersThreadSpecificData) && empty(self::$bindStaticVariable[$b]->mastersThreadSpecificData)) { return -1; }     // a
		else if (empty(self::$bindStaticVariable[$a]->mastersThreadSpecificData) && !empty(self::$bindStaticVariable[$b]->mastersThreadSpecificData)) { return 1; } // b
		else if (empty(self::$bindStaticVariable[$b]->mastersThreadSpecificData) && empty(self::$bindStaticVariable[$b]->mastersThreadSpecificData)) { return 0; }  // a

		// gather the thread dispatch priorities for the compared critical sections

		$dispPriorTableA = array(); // priority value => occurrences count
		$dispPriorTableB = array(); // priority value => occurrences count

		foreach (self::$bindStaticVariable[$a]->mastersThreadSpecificData as $thrdSpecificData)
			@$dispPriorTableA[$thrdSpecificData['dispatchPriority']] += 1;

		foreach (self::$bindStaticVariable[$b]->mastersThreadSpecificData as $thrdSpecificData)
			@$dispPriorTableB[$thrdSpecificData['dispatchPriority']] += 1;

		// both critical sections have threads

		// make the tables to have the same amount of keys (rows)
		foreach ($dispPriorTableA as $key => $value)
			@$dispPriorTableB[$key] = $dispPriorTableB[$key]; // this is done on purpose
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
	 } // }}}

	/**
	 * Dispatcher responsible for the thread intercommunication and communication with their parent process.
	 * @param bool $useBlocking On true blocks the internal execution until communication data is available for the current dispatched thread otherwise it skips it.
	 * @return void
	 */
	public static function dispatch($useBlocking = false) { // {{{
		$NULL = null;

		// prevent any threads to run their own dispatchers
		if ((self::$instancesCreatedEverAArr === null) || (count(self::$instancesCreatedEverAArr) == 0))
			return;

		// for checking SIGCHLD child signals informing that a particular "thread" was paused
		$sigSet = array(SIGCHLD);
		$sigInfo = array();


		// begin the dispatching process
		foreach (self::$instancesCreatedEverAArr as $instId => &$inst) { // loop through ALL active instances of GPhpCriticalSection

			foreach ($inst->mastersThreadSpecificData as $threadId => &$specificDataAArr) { // loop though the threads per each instance in GPhpCriticalSection
				// checking for child signals informing that a thread has exited or was paused
				while (pcntl_sigtimedwait($sigSet, $sigInfo) == SIGCHLD) {
					if ($sigInfo['code'] >= 0 && $sigInfo['code'] <= 3) { // child has exited
						self::$threadsForRemovalAArr[$sigInfo['pid']] = $sigInfo['pid'];
					} else if ($sigInfo['code'] == 5) { // stopped (paused) child
						$specificDataAArr['dispatchPriority'] = 0; // make the dispatch priority lowest
					} else if ($sigInfo['code'] == 6) { // resume stopped (paused) child
						$specificDataAArr['dispatchPriority'] = 1; // increase little bit the priority since we expect interaction with the critical section
					}
				}

				$inst->intercomInterlocutorPid = &$specificDataAArr['intercomInterlocutorPid'];

				if (isset(self::$threadsForRemovalAArr[$inst->intercomInterlocutorPid])) {
					unset($inst->mastersThreadSpecificData[$threadId]);
					continue;
				}

				$inst->intercomRead = &$specificDataAArr['intercomRead'];
				$inst->intercomWrite = &$specificDataAArr['intercomWrite'];
				$inst->dispatchPriority = &$specificDataAArr['dispatchPriority'];

				if (!$useBlocking && !$inst->intercomRead->isReceivingDataAvailable()) {
					$inst->dispatchPriority = 0;
					if ($inst->isIntercomBroken()) {
						unset($inst->mastersThreadSpecificData[$threadId]); // remove the thread from the dispatching list as soon as we can
					}
					continue;
				}

				self::dataDispatch($inst, $threadId);

				$mostPrioritizedThreadId = NULL;
				if ($inst->dispatchPriority !== 2) {
					foreach ($inst->mastersThreadSpecificData as $threadId2 => &$specificDataAArr2) {
						if ($specificDataAArr2['dispatchPriority'] === 2) {
							$mostPrioritizedThreadId = $threadId2;
						}
					}
				} else {
					$mostPrioritizedThreadId = $threadId;
				}

				if ($mostPrioritizedThreadId !== NULL && $mostPrioritizedThreadId !== $threadId) {
					$inst->intercomInterlocutorPid = &$inst->mastersThreadSpecificData[$mostPrioritizedThreadId]['intercomInterlocutorPid'];
					$inst->intercomRead = &$inst->mastersThreadSpecificData[$mostPrioritizedThreadId]['intercomRead'];
					$inst->intercomWrite = &$inst->mastersThreadSpecificData[$mostPrioritizedThreadId]['intercomWrite'];
					$inst->dispatchPriority = &$inst->mastersThreadSpecificData[$mostPrioritizedThreadId]['dispatchPriority'];

					if (!$useBlocking && !$inst->intercomRead->isReceivingDataAvailable()) {
						$inst->dispatchPriority = 0;
						if ($inst->isIntercomBroken()) {
							unset($inst->mastersThreadSpecificData[$mostPrioritizedThreadId]); // remove the thread from the dispatching list as soon as we can
						}
						continue;
					}
					self::dataDispatch($inst, $threadId);
				}
			}

			// rearrange the threads in the current critical section
			// instance using their new dispatch priority number
			// if a lock has already occurred that thread will have the
			// highest priority

			$inst->bindVariable = &$inst;
			uksort($inst->mastersThreadSpecificData, array($inst, 'sortByLockAndDispatchPriority'));
			//uksort($inst->mastersThreadSpecificData,
			//	function ($a, $b) use ($inst) {
			//		if ($inst->mastersThreadSpecificData[$a]['intercomInterlocutorPid'] == $inst->ownerPid) return -1;
			//		if ($inst->mastersThreadSpecificData[$b]['intercomInterlocutorPid'] == $inst->ownerPid) return 1;
			//		return $inst->mastersThreadSpecificData[$a]['dispatchPriority'] < $inst->mastersThreadSpecificData[$b]['dispatchPriority'];
			//	}
			//);

			$inst->intercomInterlocutorPid = &$NULL;
			$inst->intercomRead = &$NULL;
			$inst->intercomWrite = &$NULL;
			$inst->dispatchPriority = &$NULL;
		}

		// make sure that no terminated threads are left in the internal thread
		// dispatching list that all instances of GPhpThreadCriticalSection have
		foreach (self::$instancesCreatedEverAArr as $instId => &$inst) {
			foreach ($inst->mastersThreadSpecificData as $threadId => &$specificDataAArr) {
				$inst->intercomInterlocutorPid = &$specificDataAArr['intercomInterlocutorPid'];
				if (isset(self::$threadsForRemovalAArr[$inst->intercomInterlocutorPid]))
					unset($inst->mastersThreadSpecificData[$threadId]);
			}
			$inst->intercomInterlocutorPid = &$NULL;
		}
		self::$threadsForRemovalAArr = array();

		// rearrange the active instances of GPhpThreadCriticalSection in the
		// following priority order (the higher the number the bigger the priority):

		// 2. the instance with the thread that has currently locked the critical section
		// 1. instances with threads with the highest dispatch priority
		// 0. instances with the most threads inside

		self::$bindStaticVariable = &self::$instancesCreatedEverAArr;
		uksort(self::$bindStaticVariable,
			array('GPhpThreadCriticalSection', 'sortByLockDispatchPriorityAndMostThreadsInside'));

		//$instCrtdEver = &self::$instancesCreatedEverAArr;
		//uksort($instCrtdEver,
		//	function ($a, $b) use ($instCrtdEver) {
		//		// the locker thread is with highest priority
		//		if ($instCrtdEver[$a]->mastersThreadSpecificData['intercomInterlocutorPid'] == $instCrtdEver[$a]->ownerPid) return -1;
		//		if ($instCrtdEver[$b]->mastersThreadSpecificData['intercomInterlocutorPid'] == $instCrtdEver[$b]->ownerPid) return 1;
		//
		//		// deal with the case of critical sections with no threads
		//		if (!empty($instCrtdEver[$a]->mastersThreadSpecificData) && empty($instCrtdEver[$b]->mastersThreadSpecificData)) { return -1; }     // a
		//		else if (empty($instCrtdEver[$a]->mastersThreadSpecificData) && !empty($instCrtdEver[$b]->mastersThreadSpecificData)) { return 1; } // b
		//		else if (empty($instCrtdEver[$b]->mastersThreadSpecificData) && empty($instCrtdEver[$b]->mastersThreadSpecificData)) { return 0; }  // a
		//
		//		// gather the thread dispatch priorities for the compared critical sections
		//
		//		$dispPriorTableA = array(); // priority value => occurrences count
		//		$dispPriorTableB = array(); // priority value => occurrences count
		//
		//		foreach ($instCrtdEver[$a]->mastersThreadSpecificData as $thrdSpecificData)
		//			@$dispPriorTableA[$thrdSpecificData['dispatchPriority']] += 1;
		//
		//		foreach ($instCrtdEver[$b]->mastersThreadSpecificData as $thrdSpecificData)
		//			@$dispPriorTableB[$thrdSpecificData['dispatchPriority']] += 1;
		//
		//		// both critical sections have threads
		//
		//		// make the tables to have the same amount of keys (rows)
		//		foreach ($dispPriorTableA as $key => $value)
		//			@$dispPriorTableB[$key] = $dispPriorTableB[$key]; // this is done on purpose
		//		foreach ($dispPriorTableB as $key => $value)
		//			@$dispPriorTableA[$key] = $dispPriorTableA[$key];
		//
		//		ksort($dispPriorTableA);
		//		ksort($dispPriorTableB);
		//
		//		// compare the tables while taking into account the priority
		//		// and the thread count that have it per critical section
		//
		//		foreach ($dispPriorTableA as $key => $value) {
		//			if ($value < $dispPriorTableB[$key]) { return 1; } // b
		//			else if ($value > $dispPriorTableB[$key]) { return -1; } // a
		//		}
		//
		//		return 0; // a
		//	}
		//);

	} // }}}

	/**
	 * Operations on the transferred data dispatch helper.
	 * @param GPhpThreadCriticalSection $inst The instance of the critical section to work with
	 * @param int $threadId The identifier of the thread whose critical section is currently dispatched.
	 * @return void
	 */
	private static function dataDispatch(&$inst, $threadId) { // {{{

		$msg = $pid = $name = $value = null;

		// Optimize the data receive timeout for each thread
		// based on its:
		// 2. priority
		// 1. threads count for the current critical section
		// 0. total critical sections count

		$dataReceiveTimeoutMs = 700; // default timeout

		$dataReceiveTimeoutReducer = 2 - $inst->dispatchPriority;
		$dataReceiveTimeoutReducer += (count($inst->mastersThreadSpecificData) - 1);
		$dataReceiveTimeoutReducer += ((count(self::$instancesCreatedEverAArr) * 2) - 1);

		$dataReceiveTimeoutMs /= $dataReceiveTimeoutReducer;
		$dataReceiveTimeoutMs = (int)$dataReceiveTimeoutMs;
		if ($dataReceiveTimeoutMs == 0) $dataReceiveTimeoutMs = 1;

		// Receive some data

		if (!$inst->receive($msg, $pid, $name, $value, $dataReceiveTimeoutMs))	{
			$inst->dispatchPriority = 0;
			if ($inst->isIntercomBroken()) unset($inst->threadInstanceContext[$threadId]); // remove the thread from the dispatching list as soon as we can
			return;
		}

		switch ($msg) {
			case GPhpThreadCriticalSection::$LOCKSYN:
				$inst->dispatchPriority = 1;
				if ($inst->ownerPid !== false && $inst->ownerPid != $pid && $inst->isPidAlive($inst->ownerPid)) {
					$inst->send(GPhpThreadCriticalSection::$LOCKNACK, null, $pid);
					return;
				}
				if (!$inst->send(GPhpThreadCriticalSection::$LOCKACK, null, $pid)) return;
				$inst->ownerPid = $pid;
				$inst->dispatchPriority = 2;
			break;

			case GPhpThreadCriticalSection::$UNLOCKSYN:
				$inst->dispatchPriority = 1;
				if ($inst->ownerPid === false) {
					if (!$inst->send(GPhpThreadCriticalSection::$UNLOCKACK, null, $pid)) return;
				}
				$isOwnerAlive = $inst->isPidAlive($inst->ownerPid);
				if (!$isOwnerAlive || $inst->ownerPid == $pid) {
					if (!$isOwnerAlive) $inst->ownerPid = false;
					if (!$inst->send(GPhpThreadCriticalSection::$UNLOCKACK, null, $pid)) return;
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
					return;
				}
				if (!$inst->send(GPhpThreadCriticalSection::$ADDORUPDATEACK, $name, null)) return;
				$inst->dispatchPriority = 2;
				$inst->sharedData['rel'][$name] = $value;
			break;

			case GPhpThreadCriticalSection::$UNRELADDORUPDATESYN:
				$inst->dispatchPriority = 1;
				if (!$inst->send(GPhpThreadCriticalSection::$UNRELADDORUPDATEACK, $name, null)) return;
				$inst->dispatchPriority = 2;
				$inst->sharedData['unrel'][$name] = $value;
			break;

			case GPhpThreadCriticalSection::$ERASESYN:
				$inst->dispatchPriority = 1;
				if ($inst->ownerPid !== $pid) {
					$inst->send(GPhpThreadCriticalSection::$ERASENACK, null, null);
					return;
				}
				if (!$inst->send(GPhpThreadCriticalSection::$ERASEACK, $name, null)) return;
				$inst->dispatchPriority = 2;
				unset($inst->sharedData['rel'][$name]);
			break;

			case GPhpThreadCriticalSection::$UNRELERASESYN:
				$inst->dispatchPriority = 1;
				if (!$inst->send(GPhpThreadCriticalSection::$ERASEACK, $name, null)) return;
				$inst->dispatchPriority = 2;
				unset($inst->sharedData['unrel'][$name]);
			break;

			case GPhpThreadCriticalSection::$READSYN:
				$inst->dispatchPriority = 1;
				if ($inst->ownerPid !== $pid) {
					$inst->send(GPhpThreadCriticalSection::$READNACK, null, null);
					return;
				}
				$inst->send(GPhpThreadCriticalSection::$READACK, $name, (isset($inst->sharedData['rel'][$name]) || array_key_exists($name, $inst->sharedData['rel']) ? $inst->sharedData['rel'][$name] : null));
				$inst->dispatchPriority = 2;
			break;

			case GPhpThreadCriticalSection::$UNRELREADSYN:
				$inst->dispatchPriority = 1;
				$inst->send(GPhpThreadCriticalSection::$UNRELREADACK, $name, (isset($inst->sharedData['unrel'][$name]) || array_key_exists($name, $inst->sharedData['unrel']) ? $inst->sharedData['unrel'][$name] : null));
				$inst->dispatchPriority = 2;
			break;

			case GPhpThreadCriticalSection::$READALLSYN:
				$inst->dispatchPriority = 1;
				if ($inst->ownerPid !== $pid) {
					$inst->send(GPhpThreadCriticalSection::$READALLNACK, null, null);
					return;
				}
				$inst->send(GPhpThreadCriticalSection::$READALLACK, null, $inst->sharedData);
				$inst->dispatchPriority = 2;
			break;
		}
	} // }}}

	/**
	 * Tries to lock the associated with the instance critical section.
	 * @param bool $useBlocking If it's set to true the method will block until a lock is successfully established.
	 * @return bool Returns true on success otherwise returns false.
	 */
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
						for ($i = 0; $i < 120; ++$i) { }
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

			if ($useBlocking) {
				for ($i = 0; $i < 120; ++$i) { }
				usleep(mt_rand(10000, 200000));
			}

		} while ($useBlocking && !$this->doIOwnIt());

		return true;
	} // }}}

	/**
	 * Tries to unlock the associated with the instance critical section.
	 * @param bool $useBlocking If it's set to true the method will block until an unlock is successfully established.
	 * @return bool Returns true on success otherwise returns false.
	 */
	public function unlock($useBlocking = false) { // {{{
		if ($this->doIOwnIt() || $this->ownerPid === false) {
			if ($this->myPid == $this->creatorPid) { // local unlock request
				$this->ownerPid = false;
				return true;
			}

			$res = null;
			while ((($res = $this->requestUnlock()) === false) && $useBlocking) {
				for ($i = 0; $i < 120; ++$i) { }
				usleep(mt_rand(10000, 200000));
			}
			return $res;
		}
		return true;
	} // }}}

	/**
	 * Adds or updates shared resource in a reliable, slower way. A lock of the critical section is required.
	 * @param string $name The name of the resource.
	 * @param mixed $value The value of the resource.
	 * @return bool Returns true on success otherwise returns false.
	 */
	public function addOrUpdateResource($name, $value) { // {{{
		if ($this->doIOwnIt()) {
			if ($this->myPid == $this->creatorPid) { // local resource add/update request
				$this->sharedData['rel'][$name] = $value;
				return true;
			}
			if (!$this->updateDataContainer(self::$ADDORUPDATEACT, $name, $value)) return false;
			return true;
		}
		return false;
	} // }}}

	/**
	 * Adds or updates shared resource in an unreliable, faster way. A lock of the critical section is NOT required.
	 * @param string $name The name of the resource.
	 * @param mixed $value The value of the resource.
	 * @return bool Returns true on success otherwise returns false.
	 */
	public function addOrUpdateUnrelResource($name, $value) { // {{{
		if ($this->myPid == $this->creatorPid) { // local resource add/update request
			$this->sharedData['unrel'][$name] = $value;
			return true;
		}
		if (!$this->updateDataContainer(self::$UNRELADDORUPDATEACT, $name, $value)) return false;
		return true;
	} // }}}

	/**
	 * Removes shared resource in a reliable, slower way. A lock of the critical section is required.
	 * @param string $name The name of the resource.
	 * @return bool Returns true on success otherwise returns false.
	 */
	public function removeResource($name) { // {{{
		if ($this->doIOwnIt() &&
			isset($this->sharedData['rel'][$name]) ||
			array_key_exists($name, $this->sharedData['rel'])) {

			if ($this->myPid == $this->creatorPid) { // local resource remove request
				unset($this->sharedData['rel'][$name]);
				return true;
			}

			if (!$this->updateDataContainer(self::$ERASEACT, $name, null)) return false;
			return true;
		}
		return false;
	} // }}}

	/**
	 * Removes shared resource in an unreliable, faster way. A lock of the critical section is NOT required.
	 * @param string $name The name of the resource.
	 * @return bool Returns true on success otherwise returns false.
	 */
	public function removeUnrelResource($name) { // {{{
		if (isset($this->sharedData['unrel'][$name]) ||
			array_key_exists($name, $this->sharedData['unrel'])) {

			if ($this->myPid == $this->creatorPid) { // local resource remove request
				unset($this->sharedData['unrel'][$name]);
				return true;
			}

			if (!$this->updateDataContainer(self::$UNRELERASEACT, $name, null)) return false;
			return true;
		}
		return false;
	} // }}}

	/**
	 * Returns an reliable resource without trying to ask for it the dispatcher.
	 * @param string $name The name of the resource.
	 * @return mixed Returns the resource value or null on failure or if the resource name was not found.
	 */
	public function getResourceValueFast($name) { // {{{
		return (isset($this->sharedData['rel'][$name]) || array_key_exists($name, $this->sharedData['rel']) ? $this->sharedData['rel'][$name] : null);
	} // }}}

	/**
	 * Returns an unreliable resource without trying to ask for it the dispatcher.
	 * @param string $name The name of the resource.
	 * @return mixed Returns the resource value or null on failure or if the resource name was not found.
	 */
	public function getUnrelResourceValueFast($name) { // {{{
		return (isset($this->sharedData['unrel'][$name]) || array_key_exists($name, $this->sharedData['unrel']) ? $this->sharedData['unrel'][$name] : null);
	} // }}}

	/**
	 * Returns an reliable resource value by asking the dispatcher for it. An ownership of the critical section is required.
	 * @throws \GPhpThreadException If the critical section ownership is not obtained.
	 * @param string $name The name of the desired resource.
	 * @return mixed The resource value or null if the resource was not found.
	 */
	public function getResourceValue($name) { // {{{
		if (!$this->doIOwnIt())
			throw new \GPhpThreadException('[' . getmypid() . '][' . $this->uniqueId . '] Not owned critical section!');

		if ($this->myPid == $this->creatorPid) { // local resource read request ; added to keep a consistency with getResourceValueFast
			return $this->getResourceValueFast($name);
		}

		if (!$this->updateDataContainer(self::$READACT, $name, null))
			throw new \GPhpThreadException('[' . getmypid() . '][' . $this->uniqueId . '] Error while retrieving the value!');

		return $this->sharedData['rel'][$name];
	} // }}}

	/**
	 * Returns an unreliable resource value by asking the dispatcher for it. An ownership of the critical section is required.
	 * @throws \GPhpThreadException If the critical section ownership is not obtained.
	 * @param string $name The name of the desired resource.
	 * @return mixed The resource value or null if the resource was not found.
	 */
	public function getUnrelResourceValue($name) { // {{{
		if ($this->myPid == $this->creatorPid) { // local resource read request ; added to keep a consistency with getResourceValueFast
			return $this->getUnrelResourceValueFast($name);
		}

		if (!$this->updateDataContainer(self::$UNRELREADACT, $name, null))
			throw new \GPhpThreadException('[' . getmypid() . '][' . $this->uniqueId . '] Error while retrieving the value!');

		return $this->sharedData['unrel'][$name];
	} // }}}

	/**
	 * Returns the names of all reliable shared resources.
	 * @return array An array of (0 => 'resource name1', 1 => 'resource name2', ...)
	 */
	public function getResourceNames() { // {{{
		return array_keys($this->sharedData['rel']);
	} // }}}

	/**
	 * Returns the names of all unreliable shared resources.
	 * @return array An array of (0 => 'resource name1', 1 => 'resource name2', ...)
	 */
	public function getUnrelResourceNames() { // {{{
		return array_keys($this->sharedData['unrel']);
	} // }}}
} // }}}


/**
 * A data container not allowed to be cloned.
 * Once created, only REFERENCEs to it are allowed.
 * It is NOT protected against fork or direct import()/export(). If that is desired it should be wrapped.
 * @throws \GPhpThreadException When caught clone attempts.
 */
final class GPhpThreadNotCloneableContainer implements \Serializable // {{{
{
	/** @internal */
	private static $seed = 0;

	/** @internal */
	private $id = 0;

	/** @var mixed $variable The container holding the user's value */
	private $variable;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id = self::$seed++;
	}

	/**
	 * Generates object id.
	 * @return string The id of the current instance.
	 */
	public final function __toString() {
		return "{$this->id}";
	}

	/**
	 * Sets the value desired to be held.
	 * @param mixed $value The value to be held inside this container.
	 * @return void
	 * @throws \GPhpThreadException If an instance of \GPhpThreadNotCloneableContainer is passed.
	 */
	public function import($value) {
		if ($value instanceof self) {
			throw new \GPhpThreadException("Not allowed cloning of GPhpThreadNotCloneableContainer!");
		}
		$this->variable = $value;
	}

	/**
	 * Returns the contained value.
	 * @return mixed The contained value.
	 */
	public function export() {
		return $this->variable;
	}

	/** @internal */
	private function __clone() {
		throw new GPhpThreadException("Not allowed cloning of GPhpThreadNotCloneableContainer!");
	}

	/**
	 * @internal
	 * @throws \GPhpThreadException Does that every time.
	 * @return string Always empty string
	 */
	public final function serialize() {
		throw new GPhpThreadException("Not allowed cloning of GPhpThreadNotCloneableContainer!");
		return ''; // unlikely
	}

	/**
	 * @internal
	 * @throws \GPhpThreadException Does that every time.
	 * @param string $data The serialized string.
	 * @param array $options Any options to be provided to unserialize(), as an associative array (introduced in PHP 7).
	 * @return bool Always false
	 */
	public final function unserialize($data, $options = array()) {
		throw new GPhpThreadException("Not allowed cloning of GPhpThreadNotCloneableContainer!");
		return false;
	}
	
	/**
	 * PHP 7.4+ alternative serialization technique
	 * @internal
	 * @throws \GPhpThreadException Does that every time.
	 * @return string Always an empty array.
	 */
	public final function __serialize() {
		$this->serialize();
		return array(); // unlikely
	}

	/**
	 * PHP 7.4+ alternative unserialization technique
	 * @throws \GPhpThreadException Does that every time.
	 * @internal
	 * @return void
	 */
	public final function __unserialize($dataArr) {
		$this->unserialize(null);
	}

	/**
	 * @internal
	 * @throws \GPhpThreadException Does that every time.
	 * @param mixed $name Variable name.
	 * @param mixed $value variable value.
	 * @return void
	 */
	public final function __set($name, $value) {
		throw new GPhpThreadException("Not allowed use of magic method __set()!");
	}

	/**
	 * @internal
	 * @throws \GPhpThreadException Does that every time.
	 * @param mixed $name Variable name.
	 * @param mixed $value variable value.
	 * @return NULL Always NULL.
	 */
	public final function __get($name) {
		throw new GPhpThreadException("Not allowed use of magic method __get()!");
		return null;
	}

	/**
	 * @internal
	 * @throws \GPhpThreadException Does that every time.
	 * @param mixed $name Variable name.
	 * @return void
	 */
	public final function __unset($name) {
		throw new GPhpThreadException("Not allowed use of magic method __unset()!");
	}

	/**
	 * @internal
	 * @throws \GPhpThreadException Does that every time.
	 * @param mixed $name Variable name.
	 * @return NULL Always NULL.
	 */
	public static final function __set_state($name) {
		throw new GPhpThreadException("Not allowed use of magic method __set_state()!");
		return null;
	}
} // }}}


/**
 * A wrapper providing RAII mechanism for locking and unlocking
 * purposes of a critical section objects and a similar ones.
 * @see \GPhpThreadCriticalSection
 * @throws \GPhpThreadException When improperly passed/configured a critical section or clone attempts are made.
 * @api
 */
class GPhpThreadLockGuard implements \Serializable // {{{
{
	/** @var object $criticalSectionObject The critical section that will be un/locked. REFERENCE type. */
	private $criticalSectionObj = NULL;
	/** @var string $unlockMethod The unlock method name of the critical section that will be called during an unlock. */
	private $unlockMethod = 'unlock';
	/** @var string $unlockMethodsParams The parameters passed to the critical section's unlock method during an unlock. */
	private $unlockMethodsParams = array();

	/**
	* Constructor. Immediately locks the passed critical section.
	* @throws \GPhpThreadException Exception is thrown in case an uninitialized critical section is passed or the specified lock or unlock methods are missing.
	* @param object $criticalSectionObj An initialized critical section object or similar. A REFERENCE type.
	* @param string $lockMethod The name of the $criticalSectionObj's lock method that will be called.
	* @param array $lockMethodParams Parameter that will be passed to the lock method of $criticalSectionObj.
	* @param string $unlockMethod The name of the $criticalSectionObj's unlock method that will be called.
	* @param array $unlockMethodsParams Parameter that will be passed to the unlock method of $criticalSectionObj.
	*/
	public final function __construct(&$criticalSectionObj, $lockMethod = 'lock', array $lockMethodParams = array(),
			$unlockMethod = 'unlock', array $unlockMethodsParams = array()) {

		$this->criticalSectionObj = &$criticalSectionObj;

		if (!is_object($this->criticalSectionObj)) {
			throw new \GPhpThreadException('Uninitialized critical section passed to GPhpThreadLockGuard!');
		}
		if (empty($lockMethod) || empty($unlockMethod) ||
			!method_exists($this->criticalSectionObj, $lockMethod) ||
			!method_exists($this->criticalSectionObj, $unlockMethod)) {
			throw new \GPhpThreadException('Not existing lock/unlock methods in &$criticalSectionObj!');
		}

		$this->unlockMethod = $unlockMethod;
		$this->unlockMethodsParams = $unlockMethodsParams;

		call_user_func_array(array($this->criticalSectionObj, $lockMethod), $lockMethodParams);
	}

	/**
	* Destructor. Immediately unlocks the passed critical section.
	*/
	public final function __destruct() {
		if (!is_object($this->criticalSectionObj)) {
			throw new \GPhpThreadException('Uninitialized &$criticalSectionObj attempted to be unlocked via GPhpThreadLockGuard!');
		}
		call_user_func_array(array($this->criticalSectionObj, $this->unlockMethod), $this->unlockMethodsParams);
	}

	/** @internal */
	private function __clone() {
		throw new \GPhpThreadException('Attempted to clone GPhpThreadLockGuard!');
	}

	/**
	 * @internal
	 * @return string Always an empty string.
	 */
	public final function serialize() {
		return '';
	}

	/**
	 * @internal
	 * @param string $data The serialized string.
	 * @param array $options Any options to be provided to unserialize(), as an associative array (introduces in PHP 7).
	 * @return bool Always false.
	 */
	public final function unserialize($data, $options = array()) {
		return false;
	}

	/**
	 * PHP 7.4+ alternative serialization technique
	 * @internal
	 * @return string Always an empty array.
	 */
	public final function __serialize() {
		return array();
	}

	/**
	 * PHP 7.4+ alternative unserialization technique
	 * @internal
	 * @return void
	 */
	public final function __unserialize($dataArr) {
		// shall always return nothing
	}

	/**
	 * @internal
	 * @throws \GPhpThreadException Does that every time.
	 * @param mixed $name Variable name.
	 * @param mixed $value variable value.
	 * @return void
	 */
	public final function __set($name, $value) {
		throw new GPhpThreadException("Not allowed use of magic method __set()!");
	}

	/**
	 * @internal
	 * @throws \GPhpThreadException Does that every time.
	 * @param mixed $name Variable name.
	 * @param mixed $value variable value.
	 * @return NULL Always NULL.
	 */
	public final function __get($name) {
		throw new GPhpThreadException("Not allowed use of magic method __get()!");
		return null;
	}

	/**
	 * @internal
	 * @throws \GPhpThreadException Does that every time.
	 * @param mixed $name Variable name.
	 * @return void
	 */
	public final function __unset($name) {
		throw new GPhpThreadException("Not allowed use of magic method __unset()!");
	}

	/**
	 * @internal
	 * @throws \GPhpThreadException Does that every time.
	 * @param mixed $name Variable name.
	 * @return NULL Always NULL.
	 */
	public static final function __set_state($name) {
		throw new GPhpThreadException("Not allowed use of magic method __set_state()!");
		return null;
	}

	/**
	 * @internal
	 * @return string 'GPhpThreadLockGuard' always.
	 */
	public final function __toString() {
		return 'GPhpThreadLockGuard';
	}
} // }}}


/**
 * A heavy thread creation and manipulation class.
 *
 * Provides purely implemented in php instruments for "thread" creation
 * and manipulation. A shell access, pcntl, posix, linux OS and PHP 5.3+
 * are required.
 * @api
 */
abstract class GPhpThread // {{{
{
	/** @internal */
	protected $criticalSection = null;
	/** @internal */
	private $parentPid = null;
	/** @internal */
	private $childPid = null;
	/** @internal */
	private $_childPid = null;
	/** @internal */
	private $allowThreadExitCodes = true;
	/** @internal */
	private $exitCode = null;

	/** @internal */
	private $amIStarted = false;

	/** @internal */
	private $uniqueId = 0;
	/** @internal */
	private static $seed = 0;

	/** @internal */
	private static $originPid = 0;
	/** @internal */
	private static $originDynamicDataArr = array();

	/** @internal */
	private static $isCriticalSectionDispatcherRegistered = false;

	/**
	 * Constructor.
	 * @param GPhpThreadCriticalSection|null $criticalSection Instance of the critical section that is going to be associated with the created thread. Variable with null value is also valid. REFERENCE type.
	 * @param bool $allowThreadExitCodes Activates the support of thread exit codes. In that case the programmer needs to make sure that before the creation of a heavy thread there are no initialized objects whose destructors and particularly their multiple execution (in all the children and once in the parent) will affect the execution of the program in an undefined way.
	 */
	public function __construct(&$criticalSection, $allowThreadExitCodes) {// {{{
		if (GPhpThread::$seed === 0) {
			self::$originPid = getmypid();
		}
		$this->uniqueId = GPhpThread::$seed++;
		$this->allowThreadExitCodes = $allowThreadExitCodes;
		$this->criticalSection = &$criticalSection;
		$this->parentPid = getmypid();
	} // }}}

	/**
	 * Destructor (default). It will not be called in the heavy thread if thread exit codes are disabled!
	 */
	public function __destruct() { // {{{
	} // }}}

	/**
	 * Resets the internal thread id generator. This can be used to allow creation of threads from another thread.
	 *
	 * Not recommended to be used especially in a more complex cases.
	 * @return void
	 */
	public static function resetThreadIdGenerator() {
		self::$seed = 0;
	}

	/**
	 * Execution protector. Recommended to be used everywhere where execution is not desired.
	 * For e.g. destructors which should be executed only in the master process.
	 * Indicates whether the place from which this method is called is a heavy thread or not.
	 *
	 * Can be used in 2 different ways. A direct way where the supplied parameter points to NULL.
	 * In this case the result is immediately returned, but the user will not be able to ignore
	 * the effect in any "inner" threads without using resetThreadIdGenerator() method which on the
	 * other hand will affect any previously created object instances (before the thread was launched (forked)).
	 *
	 * The other way is by subscribing a variable (most useful when is done in the constructor of your
	 * affected class). When subscribed initially its value will be set to false, but it will be
	 * updated after any calls to start()/stop().
	 *
	 * @throws \GPhpThreadException If the supplied parameter is not of a GPhpThreadNotCloneableContainer or NULL type.
	 * @param null|\GPhpThreadNotCloneableContainer $isInsideGPhpThread A REFERENCE type. If it's set to null, the function will immediately return the result. Otherwise the variable will be subscribed for the result during the program's execution and its initial value will be set to false.
	 * @return null|bool Null if a variable is subscribed for the result. Else if the method caller is part ot a heavy thread returns true otherwise false.
	 */
	public static function isInGPhpThread(&$isInsideGPhpThread) { // {{{
		if ($isInsideGPhpThread === NULL) {
			return self::$seed !== 0 && self::$originPid !== 0 && self::$originPid !== getmypid();
		}
		if (!($isInsideGPhpThread instanceof \GPhpThreadNotCloneableContainer)) {
			throw new \GPhpThreadException("Not supported parameter type - must be NULL or GPhpThreadNotCloneableContainer");
		}
		$isInsideGPhpThread->import(false);
		self::$originDynamicDataArr[((string)$isInsideGPhpThread)] = $isInsideGPhpThread;
		return NULL;
	}  // }}}

	/**
	 * Specifies the running thread's exit code when it terminates.
	 * @param int $exitCode The exit code with which the thread will quit.
	 * @return void
	 */
	protected final function setExitCode($exitCode) { // {{{
		$this->exitCode = $exitCode;
	} // }}}

	/**
	 * Returns the thread's exit code.
	 * @return int
	 */
	public final function getExitCode() { // {{{
		return $this->exitCode;
	} // }}}

	/**
	 * Marks the start of a code block with high execution priority.
	 * @return void
	 */
	public static final function BGN_HIGH_PRIOR_EXEC_BLOCK() {
		GPhpThread::$isCriticalSectionDispatcherRegistered = true;
		unregister_tick_function('GPhpThreadCriticalSection::dispatch');
	}

	/**
	 * Marks the end of a code block with high execution priority.
	 * @return void
	 */
	public static final function END_HIGH_PRIOR_EXEC_BLOCK() {
		register_tick_function('GPhpThreadCriticalSection::dispatch');
	}

	/**
	 * Returns if the current process is a parent (has created thread).
	 * @return bool If it is a parent returns true otherwise returns false.
	 */
	private function amIParent() { // {{{
		return ($this->childPid === null || $this->childPid > 0 ? true : false);
	} // }}}

	/**
	 * Notifies the parent of the current thread that the thread has exited.
	 * @return void
	 */
	private function notifyParentThatChildIsTerminated() { // {{{
		posix_kill($this->parentPid, SIGCHLD);
	} // }}}

	/**
	 * Returns the current thread's (process) id.
	 * @return int|bool On success returns a number different than 0. Otherwise returns false.
	 */
	public function getPid() { // {{{
		if ($this->amIParent()) { // I am parent
			if ($this->amIStarted) return $this->childPid;
			else return false;
		}
		return $this->_childPid; // I am child
	} // }}}

	/**
	 * Sets the execution priority of the thread. A super user privileges are required.
	 * @param int $priority The priority number in the interval [-20; 20] where the lower value means higher priority.
	 * @return bool Returns true on success otherwise returns false.
	 */
	public function setPriority($priority) { // {{{ super user privileges required
		if (!is_numeric($priority)) return false;
		if ($this->amIParent()) { // I am parent
			if ($this->amIStarted)
				return @pcntl_setpriority($priority, $this->childPid, PRIO_PROCESS);
			else return false;
		}

		return @pcntl_setpriority($priority, $this->_childPid, PRIO_PROCESS); // I am child
	} // }}}

	/**
	 * Returns the current execution priority of the thread.
	 * @return int|bool On success the priority number in the interval [-20; 20] where the lower value means higher priority. On failure returns false.
	 */
	public function getPriority() { // {{{
		if ($this->amIParent()) { // I am parent
			if ($this->amIStarted)
				return @pcntl_getpriority($this->childPid, PRIO_PROCESS);
			else return false;
		}

		return @pcntl_getpriority($this->_childPid, PRIO_PROCESS); // I am child
	} // }}}

	/**
	 * Checks if the thread is alive.
	 * @return bool Returns true if the thread is alive otherwise returns false.
	 */
	public function isAlive() { // {{{
		return ($this->getPriority() !== false);
	} // }}}

	/**
	 * Checks if the creator of the heavy thread is alive.
	 * @return bool Returns true if the parent is alive otherwise returns false.
	 */
	public function isParentAlive() { // {{{
		return @pcntl_getpriority(@posix_getppid(), PRIO_PROCESS) !== false;
	} // }}}

	/**
	 * Suspends the thread execution for a specific amount of time, redirecting the CPU resources to somewhere else. The total delay is the sum of all passed parameters.
	 * @param int $microseconds The delay in microseconds.
	 * @param int $seconds (optional) The delay in seconds.
	 * @see \GPhpThread::milliSleep() Another similar method is milliSleep().
	 * @return bool Returns true after all of the specified delay time elapsed. If the sleep was interrupted returns false.
	 */
	protected function sleep($microseconds, $seconds = 0) { // {{{
		if ($this->amIParent()) return false;
		$microtime = microtime(true);
		usleep($microseconds + ($seconds * 1000000));
		$elapsedMicrotime = microtime(true);

		if (($elapsedMicrotime - ($microseconds + ($seconds * 1000000))) >= $microtime) // the sleep was not interrupted
			return true;
		return false;
	} // }}}

	/**
	 * Suspends the thread execution for a specific amount of milliseconds, redirecting the CPU resources to somewhere else.
	 * @param int $milliseconds The delay in milliseconds.
	 * @see \GPhpThread::sleep() Another similar method is sleep().
	 * @return bool Returns true after all of the specified delay time elapsed. If the sleep was interrupted returns false.
	 */
	protected function milliSleep($milliseconds) { // {{{
		return $this->sleep($milliseconds * 1000);
	} // }}}

	/**
	 * At process level decreases the niceness of a heavy "thread" making its priority higher. Multiple calls of the method will accumulate and increase the effect.
	 * @see \GPhpThread::makeUnfriendlier() A method with the opposite effect is GPhpThread::makeUnfriendlier().
	 * @return bool Returns true on success or false in case of error or lack of privileges.
	 */
	protected function makeNicer() { // {{{ increases the priority
		if ($this->amIParent()) return false;
		return proc_nice(-1);
	} // }}}

	/**
	 * At process level increases the niceness of a heavy "thread" making its priority lower. Multiple calls of the method will accumulate and increase the effect.
	 * @see \GPhpThread::makeNicer() A method with the opposite effect is GPhpThread::makeNicer().
	 * @return bool Returns true on success or false in case of error or lack of privileges.
	 */
	protected function makeUnfriendlier() { // {{{ decreases the priority
		if ($this->amIParent()) return false;
		return proc_nice(1);
	} // }}}

	/**
	 * Abstract method, the entry point of a particular GPhpThread inheritor implementation.
	 * @return void
	 */
	abstract public function run();

	/**
	 * Starts the thread.
	 * @return bool On successful execution returns true otherwise returns false.
	 */
	public final function start() { // {{{
		if (!$this->amIParent()) return false;
		if ($this->amIStarted) return false;

		$this->childPid = pcntl_fork();
		if ($this->childPid == -1) return false;
		$this->_childPid = getmypid();
		$this->amIStarted = true;

		$csInitializationResult = null;
		if ($this->criticalSection !== null) {
			$csInitializationResult = $this->criticalSection->initialize($this->childPid, $this->uniqueId);
		}

		if (!$this->amIParent()) { // child
			// no dispatchers needed in the children; this means that no threads withing threads creation is possible
			unregister_tick_function('GPhpThreadCriticalSection::dispatch');

			// flag any subscribed variables indicating that the current
			// instance is located in a GPhpThread
			foreach (self::$originDynamicDataArr as &$o) {
				if ($o instanceof \GPhpThreadNotCloneableContainer) {
					$o->import(true);
				}
			}

			if ($csInitializationResult === false) $this->stop(); // don't execute the thread body if critical section is required, but missing

			pcntl_sigprocmask(SIG_UNBLOCK, array(SIGCHLD));
			$this->run();
			if ($this->criticalSection !== null) $this->notifyParentThatChildIsTerminated();
			$this->stop();
		} else { // parent
			if ($this->childPid != -1 && $this->criticalSection !== null) {

				if ($csInitializationResult === false) { // don't add the thread to the dispatch queue if missing but required critical section is the case (actually this is done in the initialize method above)
					$this->childPid = -1;
					$this->_childPid = null;
					$this->amIStarted = false;
					return false;
				}

				if (!GPhpThread::$isCriticalSectionDispatcherRegistered)
					GPhpThread::$isCriticalSectionDispatcherRegistered = register_tick_function('GPhpThreadCriticalSection::dispatch');

				pcntl_sigprocmask(SIG_BLOCK, array(SIGCHLD)); // SIGCHLD will wait in the queue until it's processed
			}
			return true;
		}
		return true;
	} // }}}

	/**
	 * Stops executing thread.
	 * @param bool $force If it is set to true if performs forced stop (termination).
	 * @return bool True if the stop request was sent successfully otherwise false.
	 */
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

		// Manually nullify all contained in this instance objects
		// so hopefully their destructors will be called
		$vars = get_object_vars($this);
		foreach ($vars as &$v) {
			if (is_object($v) || is_array($v)) {
				$v = null;
			}
		}

		if (!$this->allowThreadExitCodes) {
			// prevent the execution on any object's destructors
			for ($i = 0; $i < 60; ++$i) {
				posix_kill($this->getPid(), 9);
				$this->sleep(0, 1);
			}
		}
		exit((int)$this->getExitCode());
	} // }}}

	/**
	 * Waits for executing thread to return.
	 * @param bool $useBlocking If is set to true will block the until the thread returns.
	 * @return bool True if the thread has joined otherwise false.
	 */
	public final function join($useBlocking = true) { // {{{
		if (!$this->amIStarted) return false;
		if ($this->amIParent()) {
			$status = null;
			$res = 0;
			if ($useBlocking) {
				GPhpThread::BGN_HIGH_PRIOR_EXEC_BLOCK();

				while (($res = pcntl_waitpid($this->childPid, $status, WNOHANG)) == 0) {
					GPhpThreadCriticalSection::dispatch();
					usleep(mt_rand(10000, 40000));
				}

				if ($res > 0 && pcntl_wifexited($status)) $this->exitCode = pcntl_wexitstatus($status);
				else $this->exitCode = false;

				if ($this->criticalSection !== null) $this->criticalSection->finalize($this->uniqueId);
				$this->childPid = null;
				$this->_childPid = null;
				$this->amIStarted = false;

				GPhpThread::END_HIGH_PRIOR_EXEC_BLOCK();
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
					$this->_childPid = null;
				}
			}
			return $res;
		}
		if ($this->childPid == -1) return false;
		exit(255);
	} // }}}

	/**
	 * Pauses the execution of the thread.
	 * @return bool True if the pause request was successfully sent otherwise false.
	 */
	public final function pause() { // {{{
		if (!$this->amIParent() || !$this->amIStarted) return false;
		return posix_kill($this->childPid, SIGSTOP);
	} // }}}

	/**
	 * Resumes the execution of a paused thread.
	 * @return bool True if the execution resume request was successfully sent otherwise false.
	 */
	public final function resume() { // {{{
		if (!$this->amIParent() || !$this->amIStarted) return false;
		return posix_kill($this->childPid, SIGCONT);
	} // }}}
} // }}}
?>
