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

function getLicenseList($uploadtree_pk, $upload_pk)
{
  /* @var $dbManager DbManager */  
  $dbManager = $GLOBALS['container']->get('db.manager');
  /* @var $uploadDao UploadDao */
  $uploadDao = $GLOBALS['container']->get("dao.upload");

  if (empty($uploadtree_pk)) {
      $uploadtreeRec = $dbManager->getSingleRow('SELECT uploadtree_pk FROM uploadtree WHERE parent IS NULL AND upload_fk=$1',
              array($upload_pk),
              __METHOD__.'.find.uploadtree.to.use.in.browse.link' );
      $uploadtree_pk = $uploadtreeRec['uploadtree_pk'];
  }

  if (empty($uploadtree_pk)) {
    return;
  }


  $uploadtreeTablename = GetUploadtreeTableName($upload_pk);
  /** @var ItemTreeBounds */
  $itemTreeBounds = $uploadDao->getItemTreeBounds($uploadtree_pk, $uploadtreeTablename);


  $scannerLicenses = getAgentFileLicenseMatchesForDump($dbManager, $itemTreeBounds);
  $clearedLicenses = getClearedLicensesForDump($dbManager, $itemTreeBounds);

  $outstream = fopen("php://output", 'w');
  array_walk($scannerLicenses, '__outputCSV', $outstream);
  array_walk($clearedLicenses, '__outputCSV', $outstream);
  fclose($outstream);
}

function handleUpload($item, $upload, $user)
{
    $return_value = read_permission($upload, $user); // check if the user has the permission to read this upload
    if (empty($return_value))
    {
        echo _("The user '$user' has no permission to read the information of upload $upload\n");
        return;
    }

    GetLicenseList($item, $upload);
}

function handleAllUploads($user)
{
    /* @var $dbManager DbManager */
    $dbManager = $GLOBALS['container']->get('db.manager');

    $sql="SELECT upload_pk FROM upload";;

    $dbManager->prepare("getAllUploads", $sql);

    $result = $dbManager->execute("getAllUploads", array());
    while ($row = $dbManager->fetchArray($result))
    {
        handleUpload('', $row['upload_pk'],$user);
    }

    $dbManager->freeResult($result);
}

/** get upload id through uploadtree id */
if (is_numeric($item) && !is_numeric($upload)) $upload = GetUploadID($item);

account_check($user, $passwd); // check username/password

/** check if parameters are valid */
if (!is_numeric($upload) || (!empty($item) && !is_numeric($item)))
{
    handleAllUploads($user);
}
else
{
    handleUpload($item, $upload, $user);
}

return 0;
