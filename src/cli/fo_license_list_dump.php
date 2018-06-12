<?php
/***********************************************************
 Copyright (C) 2018 TNG Technology Consulting GmbH

 This program is free software; you can redistribute it and/or
 modify it under the terms of the GNU General Public License
 version 2 as published by the Free Software Foundation.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License along
 with this program; if not, write to the Free Software Foundation, Inc.,
 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 ***********************************************************/

error_reporting( E_ALL | E_STRICT );

use Fossology\Lib\Dao\ClearingDao;
use Fossology\Lib\Db\DbManager;
use Fossology\Lib\Dao\UploadDao;
use Fossology\Lib\Dao\LicenseDao;
use Fossology\Lib\Data\Tree\ItemTreeBounds;
use Fossology\Lib\Data\LicenseRef;
use Fossology\Lib\Data\AgentRef;
use Fossology\Lib\Data\LicenseMatch;
use Fossology\Lib\Auth\Auth;
use Fossology\Lib\Data\DecisionTypes;

require_once("$MODDIR/lib/php/common-cli.php");
cli_Init();

$Usage = "Usage: " . basename($argv[0]) . "
  -u upload id        :: upload id
  -t uploadtree id    :: uploadtree id
  -c sysconfdir       :: Specify the directory for the system configuration
  --username username :: username
  --password password :: password
  -h  help, this message
";
$upload = ""; // upload id
$item = ""; // uploadtree id

$writeToStdout = true;

$longopts = array("username:", "password:", "container:");
$options = getopt("c:u:t:hxX:", $longopts);
if (empty($options) || !is_array($options))
{
  print $Usage;
  return 1;
}

$user = $passwd = "";
foreach($options as $option => $value)
{
  switch($option)
  {
    case 'c': // handled in fo_wrapper
      break;
    case 'u':
      $upload = $value;
      break;
    case 't':
      $item = $value;
      break;
    case 'h':
      print $Usage;
      return 1;
    case 'username':
      $user = $value;
      break;
    case 'password':
      $passwd = $value;
      break;
    default:
      print "unknown option $option\n";
      print $Usage;
  }
}

function __outputCSV(&$vals, $key, $filehandler) {
    fputcsv($filehandler, $vals, ';', '"');
}

/*
 * based on LicenseDao::getAgentFileLicenseMatches
 */
function getAgentFileLicenseMatchesForDump(DbManager $dbManager, ItemTreeBounds $itemTreeBounds)
{
    $uploadTreeTableName = $itemTreeBounds->getUploadTreeTableName();
    $statementName = __METHOD__ . ".$uploadTreeTableName";
    $uploadId = $itemTreeBounds->getUploadId();
    $params = array($uploadId, $itemTreeBounds->getLeft(), $itemTreeBounds->getRight());

    // TODO: remove unnecessary stuff!
    $dbManager->prepare($statementName,
        "(SELECT LFR.rf_shortname AS license_shortname,
                LFR.rf_fullname AS license_fullname,
                LFR.rf_pk AS license_id,
                LFR.fl_pk AS license_file_id,
                LFR.pfile_fk as file_id,
                LFR.rf_match_pct AS percent_match,
                AG.agent_name AS agent_name,
                AG.agent_pk AS agent_id,
                AG.agent_rev AS agent_revision,
                PF.pfile_md5 AS pfile_md5,
                PF.pfile_sha1 AS pfile_sha1,
                PF.pfile_size AS pfile_size
          FROM ( SELECT mlr.rf_fullname, mlr.rf_shortname, mlr.rf_pk, license_file.fl_pk, license_file.agent_fk, license_file.pfile_fk, license_file.rf_match_pct
               FROM license_file JOIN ONLY license_ref mlr ON license_file.rf_fk = mlr.rf_pk) as LFR
          INNER JOIN $uploadTreeTableName as UT ON UT.pfile_fk = LFR.pfile_fk
          INNER JOIN agent as AG ON AG.agent_pk = LFR.agent_fk
          INNER JOIN pfile as PF ON LFR.pfile_fk = pfile_pk
          WHERE AG.agent_enabled='true' and
           UT.upload_fk=$1 AND UT.lft BETWEEN $2 and $3
          )
          ");
    $result = $dbManager->execute($statementName, $params);


    $matches = array();
    while ($row = $dbManager->fetchArray($result))
    {
        $fileNameInRepo=strtolower($row['pfile_sha1'].".".$row['pfile_md5']).".".$row['pfile_size'];
        $fileNameInRepo= substr ($fileNameInRepo , 0, 2).'/'.
            substr ($fileNameInRepo , 2, 2).'/'.
            substr ($fileNameInRepo , 4, 2).'/'.$fileNameInRepo;

        $matchData = array();
        $matchData[] = $fileNameInRepo;
        $matchData[] = '';
        $matchData[] = $row['license_shortname'];
        $matchData[] = $row['agent_name'];
        $matchData[] = $row['file_id'];
        $matchData[] = $uploadId;

        $matches[] = $matchData;
    }

    $dbManager->freeResult($result);
    return $matches;
}

/*
 * based on ClearingDao::getClearedLicenses
 */
function getClearedLicensesForDump(DbManager $dbManager, ItemTreeBounds $itemTreeBounds)
{
    /* @var $clearingDao ClearingDao */
    $clearingDao = $GLOBALS['container']->get("dao.clearing");

    $statementName = __METHOD__;

    $uploadId = $itemTreeBounds->getUploadId();
    $params = array($itemTreeBounds->getLeft(), $itemTreeBounds->getRight());
    $condition = "ut.lft BETWEEN $1 AND $2";

    $groupId = Auth::getGroupId();

    // TODO: calls private method
//    $decisionsCte = $clearingDao->getRelevantDecisionsCte($itemTreeBounds, $groupId, $onlyCurrent=true, $statementName, $params, $condition);
    $reflector = new ReflectionObject($clearingDao);
    $method = $reflector->getMethod('getRelevantDecisionsCte');
    $method->setAccessible(true);
    $decisionsCte = $method->invokeArgs($clearingDao,
        array($itemTreeBounds, $groupId, $onlyCurrent=true, &$statementName, &$params, $condition));

    $params[] = DecisionTypes::IRRELEVANT;
    $sql = "$decisionsCte
            SELECT
              lr.rf_pk AS license_id,
              lr.rf_shortname AS shortname,
              lr.rf_fullname AS fullname,
              decision.pfile_id AS file_id,
              PF.pfile_md5 AS pfile_md5,
              PF.pfile_sha1 AS pfile_sha1,
              PF.pfile_size AS pfile_size
            FROM decision
              INNER JOIN clearing_decision_event cde ON cde.clearing_decision_fk = decision.id
              INNER JOIN clearing_event ce ON ce.clearing_event_pk = cde.clearing_event_fk
              INNER JOIN license_ref lr ON lr.rf_pk = ce.rf_fk
              INNER JOIN pfile as PF ON decision.pfile_id = pfile_pk
            WHERE NOT ce.removed AND type_id!=$".count($params);

    $dbManager->prepare($statementName, $sql);

    $result = $dbManager->execute($statementName, $params);
    $matches = array();
    while ($row = $dbManager->fetchArray($result))
    {
        $fileNameInRepo=strtolower($row['pfile_sha1'].".".$row['pfile_md5']).".".$row['pfile_size'];
        $fileNameInRepo= substr ($fileNameInRepo , 0, 2).'/'.
            substr ($fileNameInRepo , 2, 2).'/'.
            substr ($fileNameInRepo , 4, 2).'/'.$fileNameInRepo;

        $matchData = array();
        $matchData[] = $fileNameInRepo;
        $matchData[] = $row['shortname'];
        $matchData[] = '';
        $matchData[] = 'CONCLUSION';
        $matchData[] = $row['file_id'];
        $matchData[] = $uploadId;

        $matches[] = $matchData;
    }

    $dbManager->freeResult($result);
    return $matches;
}

/*
 * based on ClearingDao::getBulkHistory
 */
function getBulkHistoryForDump($uploadTreeTableName, DbManager $dbManager)
{
    $stmt = __METHOD__ . "." . $uploadTreeTableName . time();

    $sql = "WITH alltried AS (
            SELECT lr.lrb_pk, ce.clearing_event_pk ce_pk, lr.rf_text, ce.uploadtree_fk AS tried
            FROM license_ref_bulk lr
              LEFT JOIN highlight_bulk h ON lrb_fk = lrb_pk
              LEFT JOIN clearing_event ce ON ce.clearing_event_pk = h.clearing_event_fk
              LEFT JOIN $uploadTreeTableName ut ON ut.uploadtree_pk = ce.uploadtree_fk
              INNER JOIN $uploadTreeTableName ut2 ON ut2.uploadtree_pk = lr.uploadtree_fk
            ), aggregated_tried AS (
            SELECT DISTINCT ON(lrb_pk) lrb_pk, ce_pk, rf_text AS text, tried
            FROM (
              SELECT DISTINCT ON(lrb_pk) lrb_pk, ce_pk, rf_text, tried FROM alltried 
            ) AS result ORDER BY lrb_pk)
            SELECT lrb_pk, text, rf_shortname, removing, tried, ce_pk
            FROM aggregated_tried
              INNER JOIN license_set_bulk lsb ON lsb.lrb_fk = lrb_pk
              INNER JOIN license_ref lrf ON lsb.rf_fk = lrf.rf_pk";

    $dbManager->prepare($stmt, $sql);
    $res = $dbManager->execute($stmt, array());

    $bulks = array();
    while ($row = $dbManager->fetchArray($res))
    {
        $bulkRun = $row['lrb_pk'];
        if (!array_key_exists($bulkRun, $bulks))
        {
            $bulks[$bulkRun] = array(
                "bulkId" => $row['lrb_pk'],
                "id" => $row['ce_pk'],
                "text" => $row['text'],
                "removedLicenses" => array(),
                "addedLicenses" => array());
        }
        $key = $dbManager->booleanFromDb($row['removing']) ? 'removedLicenses' : 'addedLicenses';
        $bulks[$bulkRun][$key][] = $row['rf_shortname'];
    }

    $dbManager->freeResult($res);
    return $bulks;
}

function getItemTreeBounds($uploadtree_pk, $upload_pk)
{
    /* @var $uploadDao UploadDao */
    $uploadDao = $GLOBALS['container']->get("dao.upload");

    $uploadtreeTablename = GetUploadtreeTableName($upload_pk);
    /** @var ItemTreeBounds */
    return $uploadDao->getItemTreeBounds($uploadtree_pk, $uploadtreeTablename);
}

function getLicenseList($uploadtree_pk, $upload_pk, DbManager $dbManager)
{

    /** @var ItemTreeBounds */
    $itemTreeBounds = getItemTreeBounds($uploadtree_pk, $upload_pk);

  $scannerLicenses = getAgentFileLicenseMatchesForDump($dbManager, $itemTreeBounds);
  $clearedLicenses = getClearedLicensesForDump($dbManager, $itemTreeBounds);

  $outstream = null;
  if(false)
  {
      $outstream = fopen("php://output", 'w');
  }
  else
  {
      $filename = "./dump-$upload_pk-$uploadtree_pk.csv";
      error_log("... write to file=[$filename] into CWD");
      $outstream = fopen($filename, "w");
  }
  array_walk($scannerLicenses, '__outputCSV', $outstream);
  array_walk($clearedLicenses, '__outputCSV', $outstream);
  fclose($outstream);
}

function dumpBulkList($uploadTreeTableName)
{
    $bulkDir="./bulkFiles";
    mkdir($bulkDir);

    /* @var $dbManager DbManager */
    $dbManager = $GLOBALS['container']->get('db.manager');
    $bulks = getBulkHistoryForDump($uploadTreeTableName, $dbManager);

    $csvFile = "./bulkFiles.csv";
    $csvRemoveFile = "./bulkFilesRemove.csv";
    $outstream = fopen($csvFile, "w");
    $outstreamRemove = fopen($csvRemoveFile, "w");
    foreach ($bulks as &$row) {
        $hash = hash("sha256", $row["text"]);
        $file = "$bulkDir/$hash";
        if (! file_exists($file)) {
            file_put_contents($file, $row["text"]);
        }

        foreach ($row['addedLicenses'] as &$addedLicense) {
            $matchData = array();
            $matchData[] = $file;
            $matchData[] = $addedLicense;
            $matchData[] = '';
            $matchData[] = 'BULK';
            $matchData[] = "";
            $matchData[] = "";
            __outputCSV($matchData, array(), $outstream);
        }

        foreach ($row['removedLicenses'] as &$removedLicense) {
            $matchData = array();
            $matchData[] = $file;
            $matchData[] = $removedLicense;
            $matchData[] = '';
            $matchData[] = 'BULK_REMOVE';
            $matchData[] = "";
            $matchData[] = "";
            __outputCSV($matchData, array(), $outstreamRemove);
        }
        echo "\n";
    }
    fclose($outstream);
    fclose($outstreamRemove);
}

function dumpLicenseData()
{
    /* @var $dbManager DbManager */
    $dbManager = $GLOBALS['container']->get('db.manager');
    $sql = "WITH enriched_license_map AS (
              SELECT rf_fk, rf_parent, usage, rf_shortname AS rf_shortname_parent
                FROM license_map
                JOIN license_ref on rf_parent = rf_pk
               WHERE rf_detector_type != 2
            )
            SELECT rf_pk, rf_shortname, rf_text, rf_shortname_parent, rf_parent, usage
              FROM license_ref
            LEFT JOIN enriched_license_map as map ON rf_pk = rf_fk
              WHERE rf_active = TRUE
                AND rf_detector_type != 2";
    $statementName = __METHOD__;
    $dbManager->prepare($statementName, $sql);
    $result = $dbManager->execute($statementName, array());


    $licensesDir="./licenses";
    $csvFile = "./licenses.csv";
    $outstream = fopen($csvFile, "w");
    mkdir($licensesDir);
    while ($row = $dbManager->fetchArray($result))
    {
        $hash = hash("sha256", $row["rf_text"]);
        if ($row["rf_shortname_parent"] != null)
        {
            $file = $licensesDir."_".$row["rf_shortname_parent"]."_".$row["rf_shortname"]."_".$hash;
            $matchData = array();
            $matchData[] = $file;
            $matchData[] = $row["rf_shortname_parent"];
            $matchData[] = '';
            $matchData[] = 'LICENSE_INDIRECT_'.$row["usage"];
            $matchData[] = "";
            $matchData[] = "rf_pk=[".$row["rf_pk"]."]";
            __outputCSV($matchData, array(), $outstream);
        }
        else
        {
            $file = $licensesDir."/".$row["rf_shortname"]."_".$hash;
            $matchData = array();
            $matchData[] = $file;
            $matchData[] = $row["rf_shortname"];
            $matchData[] = '';
            $matchData[] = 'LICENSE';
            $matchData[] = "";
            $matchData[] = "rf_pk=[".$row["rf_pk"]."]";
            __outputCSV($matchData, array(), $outstream);
        }
        if (! file_exists($file)) {
            file_put_contents($file, $row["rf_text"]);
        }
    }
    $dbManager->freeResult($result);
}

function handleUpload($uploadtree_pk, $upload_pk, $user)
{
    $return_value = read_permission($upload_pk, $user); // check if the user has the permission to read this upload
    if (empty($return_value))
    {
        error_log("The user '$user' has no permission to read the information of upload $upload_pk\n");
        return;
    }

    /* @var $dbManager DbManager */
    $dbManager = $GLOBALS['container']->get('db.manager');

    if (empty($uploadtree_pk)) {
        $uploadtreeRec = $dbManager->getSingleRow('SELECT uploadtree_pk FROM uploadtree WHERE parent IS NULL AND upload_fk=$1',
            array($upload_pk),
            __METHOD__.'.find.uploadtree.to.use.in.browse.link' );
        $uploadtree_pk = $uploadtreeRec['uploadtree_pk'];
        error_log("... determined uploadtree_pk=[$uploadtree_pk])");
    }

    if (empty($uploadtree_pk)) {
        error_log("ERR: Failed to determine uploadtree_pk for upload_pk=[$upload_pk]");
        return;
    }

    getLicenseList($uploadtree_pk, $upload_pk, $dbManager);
}

function handleAllUploads($user)
{
    /* @var $dbManager DbManager */
    $dbManager = $GLOBALS['container']->get('db.manager');

    $sql="SELECT upload_pk FROM upload";;

    $dbManager->prepare("getAllUploads", $sql);


    $alreadyDoneString = file_get_contents("already_done_upload_tree_pks");

    $result = $dbManager->execute("getAllUploads", array());
    try {
        while ($row = $dbManager->fetchArray($result))
        {
            $upload_pk = $row['upload_pk'];

            if (strpos($alreadyDoneString, "[$upload_pk]") !== false) {
                error_log("Skip upload_pk=[$upload_pk]");
                continue;
            }

            error_log("Handle upload_pk=[$upload_pk] (uploadtree_pk=[])");
            try {
                handleUpload('', $upload_pk, $user);
                file_put_contents("already_done_upload_tree_pks", "[$upload_pk]", FILE_APPEND | LOCK_EX);
            }catch ( Exception $e ) {
                error_log("... failed to handle upload_pk=[$upload_pk]");
            }
        }
    } finally {
        $dbManager->freeResult($result);
    }
}
/** get upload id through uploadtree id */
if (is_numeric($item) && !is_numeric($upload)) $upload = GetUploadID($item);

account_check($user, $passwd); // check username/password

/** check if parameters are valid */
if (!is_numeric($upload) || (!empty($item) && !is_numeric($item)))
{
    handleAllUploads($user);
    dumpBulkList("uploadtree_a");
    dumpLicenseData();
}
else
{
    handleUpload($item, $upload, $user);
}

return 0;
