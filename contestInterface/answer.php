<?php
/* Copyright (c) 2012 Association France-ioi, MIT License http://opensource.org/licenses/MIT */
// means that connect.php won't make any sql connection if in dynamoDB mode
$noSQL = true;
$noSessions = true;
use Aws\DynamoDb\Exception;
require_once("../shared/connect.php");
require_once("../shared/tinyORM.php");

$tinyOrm = new tinyOrm();
$testMode = $config->db->testMode;

if (get_magic_quotes_gpc()) {
    function stripslashes_gpc(&$value)
    {
        $value = stripslashes($value);
    }
    array_walk_recursive($_GET, 'stripslashes_gpc');
    array_walk_recursive($_POST, 'stripslashes_gpc');
    array_walk_recursive($_COOKIE, 'stripslashes_gpc');
    array_walk_recursive($_REQUEST, 'stripslashes_gpc');
}

// The encoding used for multi-bytes string in always UTF-8
mb_internal_encoding("UTF-8");

function handleAnswers($db, $tinyOrm) {
   global $testMode;
   $teamID = $_POST["teamID"];
   $teamPassword = $_POST["teamPassword"];
   try {
      $rows = $tinyOrm->select('team', array('password', 'startTime', 'nbMinutes'), array('ID' => $teamID));
  } catch (Aws\DynamoDb\Exception\DynamoDbException $e) {
      error_log($e->getAwsErrorCode() . " - " . $e->getAwsErrorType());
      error_log('DynamoDB error trying to get record: teamID: '.$teamID);
      echo json_encode((object)array("success" => false, 'error' => 'DynamoDB', 'message' => $e->getMessage()));
      return;
   }
   if ($testMode == false && (!count($rows) || $teamPassword != $rows[0]['password'])) {
      echo json_encode(array("success" => false, "message" => "Requête invalide (password)"));
      return;
   }
   $row = $rows[0];
   $answers = $_POST["answers"];
   $curTime = new DateTime(null, new DateTimeZone("UTC"));
   $startTime = new DateTime($row['startTime'], new DateTimeZone("UTC"));
   $nbMinutes = intval($row['nbMinutes']);
   // We leave 2 extra minutes to handle network lag. The interface already prevents trying to answer after the end.
   if ((($curTime->getTimestamp() - $startTime->getTimestamp()) > ((intval($nbMinutes) + 2) * 60)) && !$testMode) { 
      echo json_encode(array("success" => false, 'error' => 'invalid', "message" => "La réponse a été envoyée après la fin de l'épreuve"));
      error_log("submission by team ".$teamID.
		  " after the time limit of the contest! curTime : ".$curTime->format(DateTime::RFC850).
		  " startTime :".$startTime->format(DateTime::RFC850).
		  " nbMinutes : ".$nbMinutes);
   } else {
      $curTimeDB = new DateTime(null, new DateTimeZone("UTC"));
      $curTimeDB = $curTimeDB->format('Y-m-d H:i:s');
      $items = array();
      foreach ($answers as $questionID => $answerObj) {
         $items[] = array('teamID' => $teamID, 'questionID' => $questionID, 'answer'  => $answerObj["answer"], 'ffScore' => $answerObj['score'], 'date' => $curTimeDB);
      }
      try {
         $tinyOrm -> batchWrite('team_question', $items, array('teamID', 'questionID', 'answer', 'ffScore', 'date'), array('answer', 'ffScore', 'date'));
      } catch (Aws\DynamoDb\Exception\DynamoDbException $e) {
         error_log($e->getAwsErrorCode() . " - " . $e->getAwsErrorType());
         error_log('DynamoDB error trying to write records: teamID: '.$teamID.', answers: '.json_encode($items).', items: '.json_encode($items));
         echo json_encode((object)array("success" => false, 'error' => 'DynamoDB', 'message' => $e->getAwsErrorCode()));
         return;
      }
      echo json_encode((object)array("success" => true));
   }
}

header("Content-Type: application/json");
header("Connection: close");

if (!isset($_POST["answers"]) || !isset($_POST["teamID"]) || !isset($_POST["teamPassword"])) {
   echo json_encode(array("success" => false, 'error' => 'invalid', "message" => "Requête invalide"));
   error_log("answers, teamID or teamPassword is not set : ".json_encode($_REQUEST));
} else {
   handleAnswers($db, $tinyOrm);
}
if (isset($db)) {
   unset($db);
}

