<?php
include '../bigml/bigml.php';
include '../bigml/ensemble.php';
include '../bigml/cluster.php';
include '../bigml/fields.php';

class BigMLTest extends PHPUnit_Framework_TestCase
{
    protected static $username; # "you_username"
    protected static $api_key; # "your_api_key"

    protected static $api;

    public static function setUpBeforeClass() {
       self::$api =  new BigML(self::$username, self::$api_key, true);
       ini_set('memory_limit', '512M');
    }
    /*
     Successfully creating a sample from a dataset
    */

    public function test_scenario1() {
      $data = array(array('filename' => 'data/iris.csv', 'sample_name' => 'my new sample name' ));


      foreach($data as $item) {
          print "I create a data source uploading a ". $item["filename"]. " file\n";
          $source = self::$api->create_source($item["filename"], $options=array('name'=>'local_test_source'));
          $this->assertEquals(BigMLRequest::HTTP_CREATED, $source->code);
          $this->assertEquals(1, $source->object->status->code);

          print "check local source is ready\n";
          $resource = self::$api->_check_resource($source->resource, null, 20000, 30);
          $this->assertEquals(BigMLRequest::FINISHED, $resource["status"]);

          print "create dataset with local source\n";
          $dataset = self::$api->create_dataset($source->resource);
          $this->assertEquals(BigMLRequest::HTTP_CREATED, $dataset->code);
          $this->assertEquals(BigMLRequest::QUEUED, $dataset->object->status->code);

          print "check the dataset is ready " . $dataset->resource . " \n";
          $resource = self::$api->_check_resource($dataset->resource, null, 20000, 30);
          $this->assertEquals(BigMLRequest::FINISHED, $resource["status"]);

          print "I create a sample from a dataset\n";
	  $sample = self::$api->create_sample($dataset->resource, array('name'=> 'new sample'));
	  $this->assertEquals(BigMLRequest::HTTP_CREATED, $sample->code);
	  $this->assertEquals(BigMLRequest::QUEUED, $sample->object->status->code);

          print "check the sample is ready " . $sample->resource . " \n";
	  $resource = self::$api->_check_resource($sample->resource, null, 20000, 30);
	  $this->assertEquals(BigMLRequest::FINISHED, $resource["status"]);
          
	  print "I update the sample name to " . $item["sample_name"] . "\n";
          $sample = self::$api->update_sample($sample->resource, array('name'=> $item["sample_name"]));
          $this->assertEquals(BigMLRequest::HTTP_ACCEPTED, $sample->code);
         
	  print "When I wait until the sample is ready\n";
	  $resource = self::$api->_check_resource($sample->resource, null, 20000, 30);
	  $this->assertEquals(BigMLRequest::FINISHED, $resource["status"]);
	 
	  $sample = self::$api->get_sample($sample->resource);

          $this->assertEquals($sample->object->name, $item["sample_name"]);
      } 
    }
}    
