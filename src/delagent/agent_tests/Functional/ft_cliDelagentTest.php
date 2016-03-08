<?php

/*
 Copyright (C) 2011-2013 Hewlett-Packard Development Company, L.P.

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

/**
 * \brief test the delagent agent thu command line.
 */
//require_once '/usr/share/php/PHPUnit/Framework.php';

/**
 * \class ft_cliDelagentTest - functioin test delagent agent from cli
 */
class ft_cliDelagentTest extends PHPUnit_Framework_TestCase {

  public $EXE_PATH = "";
  public $PG_CONN;
  public $DB_COMMAND = "";
  public $DB_NAME = "";

  protected function setUpDB(&$db_conf)
  {
    global $DB_COMMAND;
    global $DB_NAME;
    $DB_COMMAND  = dirname(dirname(dirname(dirname(__FILE__))))."/testing/db/createTestDB.php -e";
    exec($DB_COMMAND, $dbout, $rc);
    if (0 != $rc)
    {
      print_r($dbout);
      print "Can not create database for this testing sucessfully!\n";
      exit;
    }
    preg_match("/(\d+)/", $dbout[0], $matches);
    $test_name = $matches[1];
    $db_conf = $dbout[0];

    $DB_NAME = "fosstest" . $test_name;
  }

  protected function getTestDataPath()
  {
    $relativePart = '../testdata/testdb_all.tar';
    return realpath(dirname(__FILE__)) . '/' . $relativePart;
  }

  protected function restoreTestData()
  {
    exec("pg_restore -Ufossy -d $DB_NAME " . $this->getTestDataPath());
  }

  /* initialization */
  protected function setUp()
  {
    global $EXE_PATH;
    global $DB_NAME;
    global $PG_CONN;

    $EXE_PATH = dirname(dirname(dirname(__FILE__))) . '/agent/delagent';
    if(!file_exists($EXE_PATH))
    {
      $this->assertFileExists($EXE_PATH,
      $message = 'FATAL: cannot find executable file, stop testing\n');
      exit;
    }

    $db_conf = "";
    $this->setUpDB($db_conf);
    $EXE_PATH = $EXE_PATH." -c $db_conf";

    $PG_CONN = pg_connect("host=localhost port=5432 dbname=" . $DB_NAME . " user=fossy password=fossy")
               or die("Could not connect");
  }

  /**
   * \brief test delagent -h
   */
  function testDelagentHelp(){
    global $EXE_PATH;
    $usage = 'Usage: ';
    exec("$EXE_PATH -h 2>&1", $out, $rtn);
    $this->assertStringStartsWith($usage, $out[0]); // check if executable file delagent is exited
  }

  /**
   * \brief test delagent -u
   */
  function testDelagentu(){
    global $EXE_PATH;
    global $PG_CONN;
    global $DB_NAME;

    $expected = "";

    $this->restoreTestData();

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
   * \brief test delagent -f
   */
  function testDelagentf(){
    global $EXE_PATH;
    global $PG_CONN;
    global $DB_NAME;
    $expected = "";

    $this->restoreTestData();

    $sql = "SELECT folder_pk,parent,name,description,upload_pk FROM folderlist ORDER BY name,parent,folder_pk;";
    $result = pg_query($PG_CONN, $sql);
    if (pg_num_rows($result) > 0){
      $row = pg_fetch_assoc($result);
      $expected = "      " . $row["folder_pk"] . " :: " . $row["name"] . " (" . $row["description"]. ")";
    }
    pg_free_result($result);
    $command = "$EXE_PATH -f -n fossy -p fossy";
    exec($command, $out, $rtn);
    #print $expected . "\n";
    #print $out[1] . "\n";
    $this->assertStringStartsWith($expected, $out[2]);
  }

  /**
   * \brief test delagent -U 85
   */
  function testDelagentUpload(){
    global $EXE_PATH;
    global $PG_CONN;
    global $DB_NAME;
    $expected = "The upload '85' is deleted by the user 'fossy'.";

    $this->restoreTestData();
    $sql = "UPDATE upload SET user_fk = 2;";
    $result = pg_query($PG_CONN, $sql);
    pg_free_result($result);

    $command = "$EXE_PATH -U 85 -n fossy -p fossy";
    exec($command, $out, $rtn);
    #print $expected . "\n";
    #print $out[1] . "\n";
    $sql = "SELECT upload_fk, uploadtree_pk FROM bucket_container, uploadtree WHERE uploadtree_fk = uploadtree_pk AND upload_fk = 85;";
    $result = pg_query($PG_CONN, $sql);
    if (pg_num_rows($result) > 0){
      $this->assertFalse("bucket_container records not deleted!");
    }
    pg_free_result($result);

    $this->assertStringStartsWith($expected, $out[0]);
  }


  /**
   * \brief clean the env
   */
  protected function tearDown() {
    global $PG_CONN;
    global $DB_COMMAND;
    global $DB_NAME;

    pg_close($PG_CONN);
    exec("$DB_COMMAND -d $DB_NAME");
    print "Ending test functional delagent agent \n";
  }
}

?>
