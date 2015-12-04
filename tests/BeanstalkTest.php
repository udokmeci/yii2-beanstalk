<?php
namespace tests;

use tests\models\Model;
use tests\commands\WorkerController;
use Pheanstalk\Job;
use Pheanstalk\Exception\ServerException;
use Yii;

/**
 * SelectizeDropDownListTest
 */
class BeanstalkComponentTest extends \PHPUnit_Framework_TestCase
{
	public $actionDos=[
		WorkerController::NO_ACTION => true,
		WorkerController::RELEASE => true,
		WorkerController::BURY => false,
		WorkerController::DECAY => true,
		WorkerController::DELETE => false,
		WorkerController::DELAY => true,
	];

	public function getData($do=null){
		$data= json_decode(file_get_contents(__DIR__ . '/data/test-data.json'));
		if($do && $data){
			$data->do=$do;
		}
		return $data;
	}

	public function putTestJob($do=null)
	{
		$data=$this->getData($do);
		return Yii::$app->beanstalk->putInTube('test',$data, $data->priority , $data->delay);
	}
    public function testComponentPut()
    {
        $job_id=$this->putTestJob();
        $this->assertNotFalse($job_id);      
    }

    public function testComponentReserve()
    {
    	Yii::$app->beanstalk->watch('test');
        $job=Yii::$app->beanstalk->reserve();
        var_dump($job);
        $this->assertEquals($this->getData(),$job->getData());
        Yii::$app->beanstalk->release($job);
    }

    public function testComponentDelete()
    {
    	Yii::$app->beanstalk->watch('test');
        $job=Yii::$app->beanstalk->reserve();
        $res=Yii::$app->beanstalk->delete($job);
        $this->assertNotFalse($res);
    }

    public function testController()
    {
    	foreach ($this->actionDos as $do => $delete){
    		$job_id=$this->putTestJob($do);
    		Yii::$app->runAction('worker');
    		$job=new Job($job_id,'');
			if($delete){
				$this->assertNotFalse(Yii::$app->beanstalk->delete($job));
			} else {
				$res=false;
				try{
					$res=Yii::$app->beanstalk->delete($job);
				}catch (ServerException $e){
					continue;
				}
				$this->assertFalse($res);
			}
    	}

    }
    
}
