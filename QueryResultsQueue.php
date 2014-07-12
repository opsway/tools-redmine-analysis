<?php
class QueryResultsQueue {

	private $sqlResult;
	private $currentResult;
	private $endReached = false;

	public function __construct($sqlResult) {
		$this->sqlResult = $sqlResult;
	}

	public function endOfQueueReached() {
		return $this->endReached;
	}

	public function goToNextResult() {	
		$tempResult = $this->endReached ? null : $this->sqlResult->fetch_assoc();
		if (!$tempResult) {
			$this->endReached = true;
		} else {
			$this->currentResult = $tempResult;
		}
	}

	public function getCurrentResult() {
		return $this->currentResult;
	}
}