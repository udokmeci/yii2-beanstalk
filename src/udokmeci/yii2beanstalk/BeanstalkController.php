<?php
namespace udokmeci\yii2beanstalk;

use yii\console\Controller;
use yii\helpers\Console;
use Yii;

class BeanstalkController extends Controller
{
	const DELETE="delete";


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
						if($bean->ignore('default')->reserve()){

							switch (call_user_func_array(array($this, "action".ucfirst($action->id)), ["job"=>$job])) {
								case self::DELETE:
									\Yii::$app->beanstalk->delete($job);
									break;
								
								default:
									# code...
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