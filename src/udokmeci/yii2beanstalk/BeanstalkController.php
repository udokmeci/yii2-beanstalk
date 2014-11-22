<?php
namespace udokmeci\yii2beanstalk;

use yii\console\Controller;
use yii\helpers\Console;
use Yii;

class BeanstalkController extends Controller
{
	const DELETE="delete";
	const DELAY="delay";
	const DELAY_PIRORITY="1000";
	const DELAY_TIME=5;



	public function beforeAction($action)
    {
		if($action->id !="default") {
			fwrite(STDOUT, Console::ansiFormat("Listening $action->id tube."."\n", [Console::FG_GREEN]));
			try {
	        	while (true) {
	        		try {

	        			$bean=\Yii::$app->beanstalk->watch( $action->id );
	        			if(!$bean){
	            			fwrite(STDERR, Console::ansiFormat("Check beanstalkd!"."\n", [Console::FG_RED]));
	            			return \Yii::$app->end();
	        			}

						if($bean){
							$job=$bean->ignore('default')->reserve();

							switch (call_user_func_array(array($this, "action".ucfirst($action->id)), ["job"=>$job])) {
								case self::DELETE:
									\Yii::$app->beanstalk->delete($job);
									break;
								case self::DELAY:
									\Yii::$app->beanstalk->release($job, self::DELAY_PIRORITY,self::DELAY_TIME);
									break;
								
								default:
									\Yii::$app->beanstalk->release($job);
									break;
							}

						}
					} catch (\yii\base\ErrorException $e){
	            		fwrite(STDERR, Console::ansiFormat($e."\n", [Console::FG_RED]));
					}
					
					if(\Yii::$app->beanstalk->sleep)
						usleep(\Yii::$app->beanstalk->sleep);
				}
			} catch (\Pheanstalk\Exception\ServerException $e) {
	            
	            fwrite(STDERR, Console::ansiFormat($e."\n", [Console::FG_RED]));
	        }


		}
	}

}