<?php

class LeadTimeCalc {

	private $issues = array();
	private $events = array();
	private $openIssues = array();
	private $resolvedIssuesStack = array();

	private $WIP = 0;
	private $openSum = 0;
	private $avgOpenSum = 0;
	private $avgLeadTime = 0;
	private $currentEvent;
	private $runningTime = 0;
	private $leadTimeRunningPeriod = 2592000; //30 days in seconds

	function printHeader($file = "header.html") {
		echo file_get_contents($file);
	}

	function printFooter($file = "footer.html") {
		echo file_get_contents($file);
	}

	function createIssue($row) {
		$newIssue = array('status' => $row['status'] , 'timest' => $row['timest']);
		$this->issues[$row['issue']] = $newIssue;
		$this->openIssues[$row['issue']] = true;
	}

	function printResolvedStack() {
		foreach ($this->resolvedIssuesStack as $resolvedIssueKey => $resolvedIssueId) {
			$issueCreatedTimestamp = $this->issues[$resolvedIssueId]['timest'];
			echo "Issue $resolvedIssueId ($resolvedIssueKey) resolved at ".  $this->issues[$resolvedIssueId]['resolvedTimestamp'] . " \n";
		}	
	}

	function recalculateAvgLeadTime() {
		$leadTimeSum = 0;
		foreach ($this->resolvedIssuesStack as $resolvedIssueKey => $resolvedIssueId) {
			$issueCreatedTimestamp = $this->issues[$resolvedIssueId]['timest'];
			$issueLeadTime= round(($this->issues[$resolvedIssueId]['resolvedTimestamp'] - $issueCreatedTimestamp)/3600,0);
			$leadTimeSum += $issueLeadTime;
		}	
		if (!count($this->resolvedIssuesStack)) {
			$this->avgLeadTime	= 0;
		} else {
			$this->avgLeadTime = round($leadTimeSum/count($this->resolvedIssuesStack),0);	
		}
	}

	function recalculateOpenSum($debug = false) {
		$this->openSum = 0;
		foreach ($this->openIssues as $issueId => $issuesTemp) {
			$openIssue = $this->issues[$issueId];
			$issueOpenTime= round(($this->runningTime - $openIssue['timest'])/(3600*10),0);
			$this->openSum += $issueOpenTime;
			$this->avgOpenSum = $this->openSum/$this->WIP;
		}
	}

	function removeIssueFromResolvedStack($issueId) {
		foreach($this->resolvedIssuesStack as $resolvedIssueKey => $resolvedIssueId) {
			if ($resolvedIssueId == $issueId) unset($this->resolvedIssuesStack[$resolvedIssueKey]);
		}
	}

	function removeOldResolvementsFromResolvedStack() {
		while (true && count($this->resolvedIssuesStack)) {
			$firstResolvedIssueId = reset($this->resolvedIssuesStack);
			if ($this->issues[$firstResolvedIssueId]['resolvedTimestamp'] >= ($this->runningTime - $this->leadTimeRunningPeriod )) {
				break;
			} else {
				array_shift($this->resolvedIssuesStack);
			}
		}
	}

	function processIssueUpdate($row) {
		switch ($row['status']) {
			case 1:
			case 6:
			case 7:
			case 2:
				# Open issue
				$this->openIssues[$row['issue']] = true;
				$this->removeIssueFromResolvedStack($row['issue']);
				break;				
			default:
				# Closed issue
				if (array_key_exists($row['issue'], $this->openIssues)) {
					array_push($this->resolvedIssuesStack, $row['issue']);
					$this->issues[$row['issue']]['resolvedTimestamp'] = $row['timest'];
				}
				unset($this->openIssues[$row['issue']]);
				break;
		}
		$this->removeOldResolvementsFromResolvedStack();
		$this->WIP = count($this->openIssues);
		$this->recalculateOpenSum();
		$this->recalculateAvgLeadTime();
	}

	function stackEvents() {
		$link = new mysqli('localhost', 'root', 'password', 'redmine'); 

		$sql    = 
			"SELECT *, UNIX_TIMESTAMP(timestamp) as timest FROM 
			(SELECT id AS 'issue', created_on AS 'timestamp', 1 AS 'status' FROM issues 
				UNION SELECT journalized_id AS 'issue', created_on AS 'timestamp', value AS 'status' 
				FROM journals 
				LEFT JOIN journal_details ON journal_details.journal_id = journals.id 
				WHERE prop_key = 'status_id'
				) as unioned 
				ORDER BY timest ASC";
		$result = $link->query($sql);	
		while ($row = $result->fetch_assoc()) {
			array_push($this->events, $row);
		}
		$result->free();
		$this->events = array_reverse($this->events);
		$this->currentEvent = array_pop($this->events);
		$this->recalculateAvgLeadTime();
	}

	function processCurrentEvent() {
		if (array_key_exists($this->currentEvent['issue'], $this->issues)) {
			$this->processIssueUpdate($this->currentEvent);
		} else {
			$this->createIssue($this->currentEvent);
		}
		$this->currentEvent = array_pop($this->events);
	}

	function recalculateLeadTimeDistribution() {
		$resolvedTimes = array();
		$result = array();
		foreach ($this->resolvedIssuesStack as $resolvedIssueKey => $resolvedIssueId) {
			$issueCreatedTimestamp = $this->issues[$resolvedIssueId]['timest'];
			$issueLeadTime= round(($this->issues[$resolvedIssueId]['resolvedTimestamp'] - $issueCreatedTimestamp)/3600,0);
			array_push($resolvedTimes, $issueLeadTime);
		}
		for ($i = 0; $i <= 3; $i++) {
			$result[$i] = 0;
		}
		foreach($resolvedTimes as $issue) {
			if ($issue <= 1) {
				$result[0]++;
			} elseif ($issue > 1 && $issue <= 3*24) {
				$result[1]++;
			} elseif ($issue > 3*24 && $issue <= 30*24) {
				$result[2]++;
			} else {
				$result[3]++;
			}
		}	
		$correctionSum = 0;
		for ($i = 0; $i <= 3; $i++) {
			$result[$i] = count($resolvedTimes) ? round(($result[$i]/count($resolvedTimes))*100,0) : 0;
			$correctionSum+=$result[$i];
		}
		$result[3] += (100-$correctionSum);
		return $result;
	}

	function recalculateFinalLeadTimeDistribution() {
		$resolvedTimes = array();
		$result = array();
		foreach ($this->issues as $issue) {
			$issueLeadTime = array_key_exists('resolvedTimestamp',$issue) ? round(($issue['resolvedTimestamp'] - $issue['timest'])/3600,0) : -1;
			if ($issueLeadTime >= 0 && $issueLeadTime <= 300) {
				array_push($resolvedTimes, $issueLeadTime);
			}
		}
		asort($resolvedTimes);
		$maxTime = array_pop($resolvedTimes);
		$result = array();
		$currentIssue = array_shift($resolvedTimes);
		for($h = 0; $h <= $maxTime; $h++) {
			$result[$h] = !$h ? 0 : $result[$h-1];
			while ($h >= $currentIssue) {
				$result[$h]++;
				$currentIssue = array_shift($resolvedTimes);
				if ($currentIssue === null) {
					$currentIssue = $maxTime+1;
				}
			}
		}	
		return $result;
	}


	function run() {
		$this->printHeader();
		$this->stackEvents();

		for ($this->runningTime = (1332932400-3600); $this->runningTime < time(); $this->runningTime += 3600) {
			$timeString = date("c", $this->runningTime);
			while ($this->currentEvent['timest'] <= $this->runningTime && count($this->events) != 0) {
				$this->processCurrentEvent();
			} 
			echo "[new Date(\"$timeString\"), $this->avgOpenSum, " . count($this->openIssues) . ", $this->avgLeadTime, ". count($this->resolvedIssuesStack) . "],\n"; 
		} 
		$this->printFooter();
	}

	function runDistributionAnalysis() {
		$this->printHeader("header2.html");
		$this->stackEvents();

		for ($this->runningTime = (1332932400-3600); $this->runningTime < time(); $this->runningTime += 3600) {
			$timeString = date("c", $this->runningTime);
			while ($this->currentEvent['timest'] <= $this->runningTime && count($this->events) != 0) {
				$this->processCurrentEvent();
			} 
			if ($this->runningTime % (3600*24) == 0) {
				echo "[new Date(\"$timeString\"),";
				echo implode(", ", $this->recalculateLeadTimeDistribution());
				echo "],\n";
			}
		} 
		$this->printFooter("footer2.html");

	}	

	function runFinalDistributionAnalysis() {
		$this->printHeader("header3.html");
		$this->stackEvents();

		for ($this->runningTime = (1332932400-3600); $this->runningTime < time(); $this->runningTime += 3600) {
			$timeString = date("c", $this->runningTime);
			while ($this->currentEvent['timest'] <= $this->runningTime && count($this->events) != 0) {
				$this->processCurrentEvent();
			} 
		} 
		$result = $this->recalculateFinalLeadTimeDistribution(); 
		foreach ($result as $hour => $ticketsNumber) {
			$probability = round((100* $ticketsNumber/count($this->issues)),2);
			echo "[$hour, $probability],";		
		}
		$this->printFooter("footer3.html");
	}	

}

$calc = new LeadTimeCalc();
$calc->run();
// $calc->runDistributionAnalysis();
// $calc->runFinalDistributionAnalysis();