<?php

require_once("QueryResultsQueue.php");

class QueueResultsAggregator {
	private $queues = array();

	public function registerQueue($sqlResult) {
		$queue = new QueryResultsQueue($sqlResult);
		$queue->goToNextResult();
		array_push($this->queues, $queue);
	}

	public function getNextEvent() {
		$result = null;
		$resultQueue = null;
		foreach ($this->queues as $queue) {
			if ($queue->endOfQueueReached()) continue;
			$currentQueueResult = $queue->getCurrentResult();
			if ($result == null || ($result['timest'] < $currentQueueResult['timest'])) {
				$result = $currentQueueResult;
				$resultQueue=$queue;
			}
		}
		if ($resultQueue) $resultQueue->goToNextResult();
		return $result;
	}
}