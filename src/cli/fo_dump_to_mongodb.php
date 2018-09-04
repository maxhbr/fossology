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
use Fossology\Lib\Data\DecisionScopes;
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

if (! class_exists('MongoDB\Driver\Manager')) {
  print "ERROR: MongoDB PHP Library not found

This CLI script depends on the MongoDB PHP Library which can be installed via composer
";
  return 1;
}

$Usage = "Usage: " . basename($argv[0]) . "
  -u upload id             :: upload id
  -t uploadtree id         :: uploadtree id
  -c sysconfdir            :: Specify the directory for the system configuration
  --username username      :: username
  --password password      :: password
  --mongohost host         :: host for mongo
  --mongousername username :: username for mongo db
  --mongopassword password :: password for mongo db
  --mongodb databaseName   :: name of the database in mongo db (defaults to file_raw)
  -h  help, this message
";
$upload = ""; // upload id
$item = ""; // uploadtree id
$user = $passwd = "";
$mongohost = $mongouser = $mongopasswd = "";
$mongodb = "file_raw";

$writeToStdout = true;

$longopts = array("username:", "password:", "container:", "mongohost:", "mongousername:", "mongopassword:", "mongodb");
$options = getopt("c:u:t:hxX:", $longopts);
if (empty($options) || !is_array($options))
{
  print $Usage;
  return 1;
}

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
    case "mongohost":
      $mongohost = $value;
      break;
    case "mongousername":
      $mongouser = $value;
      break;
    case "mongopassword":
      $mongopasswd = $value;
      break;
    case "mongodb":
      $mongodb = $value;
      break;
    default:
      print "unknown option $option\n";
      print $Usage;
  }
}

if($user == "" ||
   $passwd == "" ||
   $mongohost == "" ||
   $mongouser == "" ||
   $mongopasswd == ""){
  error_log("missing command line argument(s)");
  return 1;
}

function __outputToMongo(&$entry, $key, $bulk) {
  $bulk->insert($entry);
}

function writeDataToMongo(&$data, $manager){
  echo "write data with size=".count($data)." via bulk to db";
  $bulk = new MongoDB\Driver\BulkWrite;
  array_walk($data, '__outputToMongo', $bulk);
  $result = $manager->executeBulkWrite('rigel.'.$mongodb, $bulk);
  printf("Inserted %d documents\n", $result->getInsertedCount());
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

    $uploadTreeTable = $itemTreeBounds->getUploadTreeTableName();
    $sql_upload = "";
    if ('uploadtree' === $uploadTreeTable || 'uploadtree_a' === $uploadTreeTable) {
        $params[] = $itemTreeBounds->getUploadId();
        $p = "$" . count($params);
        $sql_upload = "ut.upload_fk=$p AND ";
    }else{
        error_log("WARN: UploadtreePK was empty");
    }

    $globalScope = DecisionScopes::REPO;
    $WIP = DecisionTypes::WIP;
    $IRR = DecisionTypes::IRRELEVANT;
    $decisionsCte = "WITH decision AS (
              SELECT
                cd.clearing_decision_pk AS id,
                cd.pfile_fk AS pfile_id,
                cd.decision_type AS type_id
              FROM clearing_decision cd
                INNER JOIN $uploadTreeTable ut
                  ON ut.pfile_fk = cd.pfile_fk AND cd.scope = $globalScope OR ut.uploadtree_pk = cd.uploadtree_fk
              WHERE $sql_upload $condition
                AND cd.decision_type!=$WIP AND cd.decision_type!=$IRR)";

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
            WHERE NOT ce.removed";

    $dbManager->prepare($statementName, $sql);

    $result = $dbManager->execute($statementName, $params);
    $matches = array();
    while ($row = $dbManager->fetchArray($result))
    {
        $fileNameInRepo=strtolower($row['pfile_sha1'].".".$row['pfile_md5']).".".$row['pfile_size'];
        $fileNameInRepo= substr ($fileNameInRepo , 0, 2).'/'.
            substr ($fileNameInRepo , 2, 2).'/'.
            substr ($fileNameInRepo , 4, 2).'/'.$fileNameInRepo;

        $matches[] =[
          'source' => 'CONCLUSION',
          'path' => $fileNameInRepo,
          'licenses' => [$row['shortname']]
        ];
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

function dumpBulkList($uploadTreeTableName, $mongoManager)
{
    /* @var $dbManager DbManager */
    $dbManager = $GLOBALS['container']->get('db.manager');
    $bulks = getBulkHistoryForDump($uploadTreeTableName, $dbManager);

    $matches = array();
    foreach ($bulks as &$row) {
      if(sizeof($row['addedLicenses']) > 0) {
        $matches[] = [
          'source' => 'BULK',
          'text' => $row["text"],
          'licenses' => $row['addedLicenses']
        ];
      }

      if(sizeof($row['removedLicenses']) > 0) {
        $matches[] = [
          'source' => 'BULK_REMOVE',
          'text' => $row["text"],
          'licenses' => $row['removedLicenses']
        ];
      }
    }

    writeDataToMongo($matches, $mongoManager);
}

function dumpLicenseData($mongoManager)
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

    $matches = array();
    while ($row = $dbManager->fetchArray($result))
    {
        if ($row["rf_shortname_parent"] != null)
        {
          $matches[] = [
            'source' => 'LICENSE_INDIRECT_'.$row["usage"],
            'text' => $row["rf_text"],
            'licenses' => [$row["rf_shortname_parent"]]
          ];
        }
        else
        {
          $matches[] = [
            'source' => 'LICENSE',
            'text' => $row["rf_text"],
            'licenses' => [$row["rf_shortname"]]
          ];
        }
    }
    $dbManager->freeResult($result);

    writeDataToMongo($matches, $mongoManager);
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

  return getClearedLicensesForDump($dbManager, $itemTreeBounds);
}

function handleUpload($uploadtree_pk, $upload_pk, $user, $mongoManager)
{
    $return_value = read_permission($upload_pk, $user); // check if the user has the permission to read this upload
    if (empty($return_value))
    {
        error_log("ERR: The user '$user' has no permission to read the information of upload $upload_pk\n");
        return;
    }

    /* @var $dbManager DbManager */
    $dbManager = $GLOBALS['container']->get('db.manager');

    if (empty($uploadtree_pk))
    {
        $uploadtreeRec = $dbManager->getSingleRow('SELECT uploadtree_pk FROM uploadtree WHERE parent IS NULL AND upload_fk=$1',
            array($upload_pk),
            __METHOD__.'.find.uploadtree.to.use.in.browse.link' );
        $uploadtree_pk = $uploadtreeRec['uploadtree_pk'];
    }

    if (empty($uploadtree_pk))
    {
        error_log("ERR: Failed to determine uploadtree_pk for upload_pk=[$upload_pk]");
        return;
    }


    $clearedLicenses = getLicenseList($uploadtree_pk, $upload_pk, $dbManager);
    writeDataToMongo($clearedLicenses, $mongoManager);
}

function handleAllUploads($user, $mongoManager)
{
    /* @var $dbManager DbManager */
    $dbManager = $GLOBALS['container']->get('db.manager');

    $sql="SELECT upload_pk FROM upload";;

    $dbManager->prepare("getAllUploads", $sql);

    touch("already_done_upload_tree_pks");
    $alreadyDoneString = file_get_contents("already_done_upload_tree_pks");

    $result = $dbManager->execute("getAllUploads", array());
    try
    {
        while ($row = $dbManager->fetchArray($result))
        {
            $upload_pk = $row['upload_pk'];

            if (strpos($alreadyDoneString, "[$upload_pk]") !== false)
            {
                echo "Skip upload_pk=[$upload_pk]";
                continue;
            }

            echo "Handle upload_pk=[$upload_pk] (uploadtree_pk=[])";
            try
            {
                handleUpload('', $upload_pk, $user, $mongoManager);
                file_put_contents("already_done_upload_tree_pks", "[$upload_pk]", FILE_APPEND | LOCK_EX);
            }
            catch ( Exception $e )
            {
                error_log("ERR: failed to handle upload_pk=[$upload_pk]");
            }
        }
    }
    finally
    {
        $dbManager->freeResult($result);
    }
}

function dumpAllFiles($mongoManager)
{
  $stmt = __METHOD__ . time();
  /* @var $dbManager DbManager */
  $dbManager = $GLOBALS['container']->get('db.manager');

  $sql = "SELECT * FROM pfile INNER JOIN mimetype ON pfile.pfile_mimetypefk = mimetype.mimetype_pk";

  $dbManager->prepare($stmt, $sql);
  $result = $dbManager->execute($stmt, array());

  $matches = array();
  while ($row = $dbManager->fetchArray($result))
  {
    $fileNameInRepo=strtolower($row['pfile_sha1'].".".$row['pfile_md5']).".".$row['pfile_size'];
    $fileNameInRepo= substr ($fileNameInRepo , 0, 2).'/'.
                   substr ($fileNameInRepo , 2, 2).'/'.
                   substr ($fileNameInRepo , 4, 2).'/'.$fileNameInRepo;

    $matches[] = [
      'source' => 'FILE',
      'path' => $fileNameInRepo,
      'size' => $row['pfile_size'],
      'mimetype' => $row['mimetype_name']
    ];

  }
  $dbManager->freeResult($result);

  writeDataToMongo($matches, $mongoManager);
}

/** get upload id through uploadtree id */
if (is_numeric($item) && !is_numeric($upload)) $upload = GetUploadID($item);

account_check($user, $passwd); // check username/password

$mongourl = "mongodb://${mongouser}:${mongopasswd}@${mongohost}/rigel";
$mongoManager = new MongoDB\Driver\Manager($mongourl);

if (!is_numeric($upload) || (!empty($item) && !is_numeric($item)))
{
  handleAllUploads($user, $mongoManager);
  dumpBulkList("uploadtree_a", $mongoManager);
  dumpLicenseData($mongoManager);
  dumpAllFiles($mongoManager);
}
else
{
  handleUpload($item, $upload, $user, $mongoManager);
}

return 0;
