<?php

class DataContainer { // {{{
	private $dc = array();
	private $containerIndexSeed = 0;
	
	public function __construct() {
		$this->dc = array();
		$this->containerIndexSeed = 0;	
	}

	public function __destruct() {
		$this->containerIndexSeed = 0;
		$this->dc = null;				
	}

	public function addContainer() {
		$this->dc[$this->containerIndexSeed] = array();
		return $this->containerIndexSeed++;
	}

	public function removeContainer($containerIndex) {
		unset($this->dc[$containerIndex]);
	}

	public function getContainerIndexes() {
		return array_keys($this->dc);
	}

	public function setElement($containerIndex, $elementIndex, $elementValue) {
		if (!isset($this->dc[$this->containerIndex])) return false;
		$this->dc[$this->containerIndex][$elementIndex] = $elementValue;
		return true;
	}

	public function getElement($containerIndex, $elementIndex, &$elementValue) {
		if (!isset($this->dc[$this->containerIndex])) return false;
		if (!isset($this->dc[$this->containerIndex][$elementIndex]) && !array_key_exists($elementIndex, $this->dc[$this->containerIndex])) return false;
		$elementValue = $this->dc[$this->containerIndex][$elementIndex];
		return true;
	}

	public function unsetElement($containerIndex, $elementIndex) {
		if (!isset($this->dc[$this->containerIndex])) return false;
		unset($this->dc[$this->containerIndex][$elementIndex]);
		return true;
	}

	public function bindToElement(&$variable, $containerIndex, $elementIndex) {
		if (!isset($this->dc[$this->containerIndex])) return false;
		if (!isset($this->dc[$this->containerIndex][$elementIndex]) && !array_key_exists($elementIndex, $this->dc[$this->containerIndex])) return false;
		$variable = &$this->dc[$this->containerIndex][$elementIndex];
		return true;
	}

	public function getElementIndexes($containerIndex) {
		if (!isset($this->dc[$this->containerIndex])) return false;
		return array_keys($this->dc[$containerIndex]);
	}

	public function sortContainers($type, $compareFunc = null) {
		switch ($type) {
			case 0: return ksort($this->dc); break;
			case 1: return krsort($this->dc); break;

			case 2:
					if ($compareFunc === null)
						return false;
					return uksort($this->dc, $compareFunc);

			break;

			default: return false;
		}
		return true;
	}

	public function sortElements($type, $containerIndex, $compareFunc = null) {
		if (!isset($this->dc[$this->containerIndex])) return false;

		switch ($type) {
			case 0: return ksort($this->dc[$containerIndex]); break;
			case 1: return krsort($this->dc[$containerIndex]); break;

			case 2:
					if ($compareFunc === null)
						return false;
					return uksort($this->dc[$containerIndex], $compareFunc);

			break;

			default: return false;
		}
		return true;
	}

	public function resetContainer() {
		reset($this->dc);
	}

	public function nextContainer() {
		$k = key($this->dc);
		next($this->dc);
		return $k;
	}

	public function resetElement($containerIndex) {
		reset($this->dc[$containerIndex]);
	}

	public function nextElement($containerIndex) {
		if (!isset($this->dc[$containerIndex])) return false;
		$k = key($this->dc[$containerIndex]);
		next($this->dc[$containerIndex]);
		return $k;
	}
} // }}}


class CS { // {{{
	private static $instancesCreatedEverDC = null; // contain all the instances that were ever created of this class
	private static $instancesForRemovalDC = null;  // contain all the instances that were ever created of this class and now are going to be removed

	private static $dcIndex = null;				   // the container index that will be used with $instancesCreatedEverDC
	private static $dcRemovalIndex = null;		   // the container index that will be used with $instancesForRemovalDC
	
	private static $currentInstanceIdSeed = 0;	   // seed of unique identifier of the current instance of the class
	private $currentInstanceId = 0;				   // unique identifier of the current instance of the class

	private $ownerPid = false;        // the thread PID owning the critical section

	private $threadSharedDC = null;   // shared between the different threads that use instance of the critical section
	private $threadSpecificDC = null; // thread specific communication data and identification data

	private $dcThreadSharedIndex = null;   // container index used with $threadSharedDC
	private $dcThreadSpecificIndex = null; // container index used with $threadSpecificDC
	

	private $pipeDir = '';

	private $myPid; 	 // point of view of the current instance
	private $creatorPid; // point of view of the current instance

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
		if (self::$instancesCreatedEverDC === null) {
			self::$instancesCreatedEverDC = new DataContainer();
			self::dcIndex = self::$instancesCreatedEverDC->addContainer();

			self::$instancesForRemovalDC = new DataContainer();
			self::$dcRemovalIndex = self::$instancesForRemovalDC->addContainer();
		}

		$this->currentInstanceId = self::$currentInstanceIdSeed++;
		self::$instancesCreatedEverDC->setElement(self::dcIndex, $this->currentInstanceId, $this);

		$this->threadSharedDC = new DataContainer();
		$this->dcThreadSharedIndex = $this->threadSharedDC->addContainer();
		
		$this->threadSpecificDC = new DataContainer();

		$this->creatorPid = getmypid();		
		$this->pipeDir = rtrim($pipeDirectory, ' /') . '/';	
	} // }}}

	public function __destruct() { // {{{
		$this->intercomRead = null;
		$this->intercomWrite = null;
		self::$instancesForRemovalDC->setElement(self::dcRemovalIndex, $this->currentInstanceId, $this->currentInstanceId);
	} // }}}

	public function finalize($contextId) { // {{{
		self::$instancesForRemovalDC->setElement(self::dcRemovalIndex, $this->currentInstanceId, $this->currentInstanceId);
		$NULL = null;
		$this->threadSpecificDC->bindToElement($NULL, $this->dcThreadSpecificIndex, 'intercomWrite');
		$this->threadSpecificDC->bindToElement($NULL, $this->dcThreadSpecificIndex, 'intercomRead');
		$this->threadSpecificDC->bindToElement($NULL, $this->dcThreadSpecificIndex, 'intercomInterlocutorPid');
		$this->threadSpecificDC->bindToElement($NULL, $this->dcThreadSpecificIndex, 'dispatchPriority');
		$this->threadSpecificDC->removeContainer($this->dcThreadSpecificIndex);
	} // }}}

	private function doIOwnIt() { // {{{
		return ($this->ownerPid !== false && $this->ownerPid == $this->myPid);
	} // }}}

	public function initialize($afterForkPid, $threadContextId) { // {{{ TODO REMOVE $threadContextId parameter
		$this->dcThreadSpecificIndex = $this->threadSpecificDC->addContainer();

		$this->threadSpecificDC->setElement($this->dcThreadSpecificIndex, 'intercomWrite', null);
		$this->threadSpecificDC->setElement($this->dcThreadSpecificIndex, 'intercomRead', null);
		$this->threadSpecificDC->setElement($this->dcThreadSpecificIndex, 'intercomInterlocutorPid', null);
		$this->threadSpecificDC->setElement($this->dcThreadSpecificIndex, 'dispatchPriority', null);

		$this->threadSpecificDC->bindToElement($this->intercomWrite, $this->dcThreadSpecificIndex, 'intercomWrite');
		$this->threadSpecificDC->bindToElement($this->intercomRead, $this->dcThreadSpecificIndex, 'intercomRead');
		$this->threadSpecificDC->bindToElement($this->intercomInterlocutorPid, $this->dcThreadSpecificIndex, 'intercomInterlocutorPid');
		$this->threadSpecificDC->bindToElement($this->dispatchPriority, $this->dcThreadSpecificIndex, 'dispatchPriority');

		$this->myPid = getmypid();

		$retriesLimit = 60;

		if ($this->myPid == $this->creatorPid) { // parent
			$this->intercomInterlocutorPid = $afterForkPid;

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
			self::$instancesCreatedEverDC = null; // the child must not know for its neighbours
			self::$instancesForRemovalDC = null;			
			
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
			self::$instancesCreatedEverDC->unsetElement(self::dcIndex, $this->currentInstanceId);
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

		return !$isDataEmpty;
	} // }}}

	
} // }}}

$cs = new CS();
?>
