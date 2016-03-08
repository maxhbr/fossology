<?php
/*
 Copyright (C) 2012 Hewlett-Packard Development Company, L.P.

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
 */

use Fossology\Lib\Test\TestPgDb;
use Fossology\Lib\Test\TestInstaller;
// require_once(dirname(dirname(dirname(dirname(__FILE__)))).'/cli/tests/test_common.php');

/**
 * \brief test delagent cli
 */

class ft_DelagentTest extends PHPUnit_Framework_TestCase {

  // public $SYSCONF_DIR = "/usr/local/etc/fossology/";
  public $DB_NAME;
  public $PG_CONN;
  public $DB_COMMAND;

  /** @var TestPgDb */
  private $testDb;
  /** @var TestInstaller */
  private $testInstaller;
  /** @var DbManager */
  private $dbManager;
  /** @var String */
  private $sys_conf;

  /**
   * \brief upload testdata
   *    prepare testdata for delagent, upload one tar file and schedule all agents
   */
  function upload_testdata($sys_conf){
    $auth = "--username fossy --password fossy -c " . $sys_conf;
    /**  upload a tar file to one specified path */
    $out = "";
    $pos = 0;
    $upload_path = "upload_path";
    $fileToUpload = dirname(dirname(dirname(dirname(__FILE__))))."/pkgagent/agent_tests/testdata/fossology-1.2.0-1.el5.i386.rpm";
    $tmpFile = "/tmp/fossology-1.2.0-1.el5.i386.rpm";
    copy($fileToUpload,$tmpFile);
    $command = "cp2foss $auth $tmpFile -f $upload_path -d upload_des -q all -v";
    $last = exec("$command 2>&1", $out, $rtn);
    print_r($out);
    sleep(10);
    // print_r($out);
    $repo_string = "Uploading to folder: '/$upload_path'";
    $repo_pos = strpos($out[7], $repo_string);
    $output_msg_count = count($out);
    $this->assertGreaterThan(0, $repo_pos);
    $scheduled_agent_info_1 = "agent_pkgagent is queued to run on";
    $scheduled_agent_info_2 = "agent_nomos is queued to run on";
    $scheduled_agent_info_3 = "agent_mimetype is queued to run on";
    $scheduled_agent_info_4 = "agent_copyright is queued to run on";
    $pos = false;
    $pos = strpos($out[$output_msg_count - 1], $scheduled_agent_info_1);
    $this->assertEquals(0, $pos);
    $pos = false;
    $pos = strpos($out[$output_msg_count - 2], $scheduled_agent_info_2);
    $this->assertEquals(0, $pos);
    $pos = false;
    $pos = strpos($out[$output_msg_count - 3], $scheduled_agent_info_3);
    $this->assertEquals(0, $pos);
    $pos = false;
    $pos = strpos($out[$output_msg_count - 4], $scheduled_agent_info_4);
    $this->assertEquals(0, $pos);
    $upload_id = 0;
    /** get upload id that you just upload for testing */
    if ($out && $out[11]) {
      $upload_id = get_upload_id($out[11]);
    } else $this->assertFalse(TRUE);
    $agent_status = 0;
    $agent_status = check_agent_status("ununpack", $upload_id);
    $this->assertEquals(1, $agent_status);
    $agent_status = 0;
    $agent_status = check_agent_status("copyright", $upload_id);
    $this->assertEquals(1, $agent_status);
    $agent_status = 0;
    $agent_status = check_agent_status("nomos", $upload_id);
    $this->assertEquals(1, $agent_status);
    $agent_status = 0;
    $agent_status = check_agent_status("mimetype", $upload_id);
    $this->assertEquals(1, $agent_status);
    $agent_status = 0;
    $agent_status = check_agent_status("pkgagent", $upload_id);
    $this->assertEquals(1, $agent_status);
  }

  private function setUpTables()
  {
    $this->testDb->createPlainTables(array('upload','upload_reuse','uploadtree','folder','foldercontents','uploadtree_a','license_ref','license_ref_bulk','clearing_decision','clearing_decision_event','clearing_event','license_file','highlight','highlight_bulk','agent','pfile','ars_master','users','groups','group_user_member','license_map'),false);
    $this->testDb->createSequences(array('agent_agent_pk_seq','pfile_pfile_pk_seq','upload_upload_pk_seq','nomos_ars_ars_pk_seq','license_file_fl_pk_seq','license_ref_rf_pk_seq','license_ref_bulk_lrb_pk_seq','clearing_decision_clearing_decision_pk_seq','clearing_event_clearing_event_pk_seq','FileLicense_pkey'),false);
    $this->testDb->createViews(array('license_file_ref'),false);
    $this->testDb->createConstraints(array('agent_pkey','pfile_pkey','upload_pkey_idx','clearing_event_pkey'),false);
    $this->testDb->alterTables(array('agent','pfile','upload','ars_master','license_ref_bulk','clearing_event','clearing_decision','license_file','highlight'),false);
    $this->testDb->createInheritedTables();
    // $this->testDb->createInheritedArsTables(array('nomos','monk'));

    $this->testDb->insertData(array('pfile','upload','uploadtree_a','users','group_user_member','agent','license_file','nomos_ars','monk_ars'), false);
    $this->testDb->insertData_license_ref();

    $this->testDb->resetSequenceAsMaxOf('agent_agent_pk_seq', 'agent', 'agent_pk');

    // $this->dbManager->queryOnce("DELETE FROM license_file");
  }

  /* initialization */
  protected function setUp() {
    // global $DB_COMMAND;
    // global $DB_NAME;
    $this->agentDir = dirname(dirname(__DIR__));

    $this->testDb = new TestPgDb("delagent".time());
    $this->setUpTables();
    $this->sys_conf = $this->testDb->getFossSysConf();
    // $last = exec("mkdir -p " . $this->agentDir . '/../www', $out, $rtn);
    // $last = exec("cp -r " . $this->agentDir . '/../www' . " " . $this->sys_conf . '/inst', $out, $rtn);
    // $last = exec("cp -r " . $this->agentDir . '/../lib' . " " . $this->sys_conf . '/inst', $out, $rtn);
    // $last = exec("cp -r " . $this->agentDir . '/../vendor' . " " . $this->sys_conf . '/inst', $out, $rtn);
    $this->testInstaller = new TestInstaller($this->sys_conf);
    $this->testInstaller->init();
    $this->testInstaller->cpRepo();
    $this->testInstaller->install($this->agentDir);
    $this->dbManager = $this->testDb->getDbManager();

    print_r($this->sys_conf);

    $this->upload_testdata($this->sys_conf);
  }

  /**
   * \brief test delagent -u
   */
  function test_delagentu(){
    global $EXE_PATH;
    global $PG_CONN;

    $expected = "";

    $sql = "SELECT upload_pk, upload_filename FROM upload ORDER BY upload_pk;";
    $result = pg_query($PG_CONN, $sql);
    if (pg_num_rows($result) > 0){
      $row = pg_fetch_assoc($result);
      $expected = $row["upload_pk"] . " :: ". $row["upload_filename"];
    }
    pg_free_result($result);
    /** the file is one executable file */
    $command = "$EXE_PATH -u -n fossy -p fossy";
    exec($command, $out, $rtn);
    //print_r($out);
    $this->assertStringStartsWith($expected, $out[1]);
  }

  /**
   * \brief test delagent -u with wrong user
   */
  function test_delagentu_wronguser(){
    global $EXE_PATH;
    global $PG_CONN;

    $expected = "";

    add_user("testuser", "testuser");
    $sql = "SELECT upload_pk, upload_filename FROM upload ORDER BY upload_pk;";
    $result = pg_query($PG_CONN, $sql);
    if (pg_num_rows($result) > 0){
      $row = pg_fetch_assoc($result);
      $expected = $row["upload_pk"] . " :: ". $row["upload_filename"];
    }
    pg_free_result($result);
    /** the file is one executable file */
    $command = "$EXE_PATH -u -n testuser -p testuser";
    exec($command, $out, $rtn);
    //print_r($out);
    $this->assertStringStartsWith($expected, $out[1]);
  }

  /**
   * \brief clean the env
   */
  protected function tearDown() {
    $this->testInstaller->uninstall($this->agentDir);
    $this->testInstaller->rmRepo();
    $this->testInstaller->clear();
    $this->testDb->fullDestruct();
    print "End up functional test for cp2foss \n";
  }

}

?>
