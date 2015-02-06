<?php
namespace udokmeci\yii2beanstalk;

use Yii;
use yii\console\Controller;
use yii\helpers\Console;

class BeanstalkController extends Controller {
	const DELETE = "bury";
	const DELETE = "delete";
	const DELAY = "delay";
	const DELAY_PIRORITY = "1000";
	const DELAY_TIME = 5;
	private $lasttimereconnect=null;
	private $tubeActions = [];

	public function listenTubes() {
		return [];
	}

	public function getTubeAction($tube) {

		return isset($this->tubeActions[$tube->tube]) ? $this->tubeActions[$tube->tube] : false;
	}

	public function getTubes() {
		return array_unique(array_merge((array)\Yii::$app->beanstalk->listTubes(), $this->listenTubes()));
	}

	public function actionIndex() {

	}

	public function beforeAction($action) {
		if ($action->id == "index") {
			try {
				foreach ($this->getTubes() as $key => $tube) {
					$methodName = 'action' . str_replace(' ', '', ucwords(implode(' ', explode('-', $tube))));
					if ($this->hasMethod($methodName)) {
						$this->tubeActions[$tube] = $methodName;
						fwrite(STDOUT, Console::ansiFormat("Listening $tube tube." . "\n", [Console::FG_GREEN]));
						$bean = \Yii::$app->beanstalk->watch($tube);
						if (!$bean) {
							fwrite(STDERR, Console::ansiFormat("Check beanstalkd!" . "\n", [Console::FG_RED]));
							return \Yii::$app->end();
						}

					} else {
						fwrite(STDOUT, Console::ansiFormat("Not Listening $tube tube since there is no action defined. $methodName" . "\n", [Console::FG_YELLOW]));
					}
				}

				if (count($this->tubeActions) == 0) {
					fwrite(STDERR, Console::ansiFormat("No tube found to listen!" . "\n", [Console::FG_RED]));
					return \Yii::$app->end();

				}

				while (true) {
					try {
						if($this->lasttimereconnect==null){
							$this->lasttimereconnect=time();
						}

						if(time()-$this->lasttimereconnect > 60*60){
							Yii::$app->db->close();
							Yii::$app->db->open();
							Yii::$app->db->exec("SET @@session.wait_timeout = 31536000");

							Yii::info("Reconnecting to the DB");
							$this->lasttimereconnect=time();
						}

						$job = $bean->reserve();
						$methodName = $this->getTubeAction($bean->statsJob($job));

						if (!$methodName) {
							fwrite(STDERR, Console::ansiFormat("No method found for job's tube!" . "\n", [Console::FG_RED]));
							break;
						}

						switch (
								call_user_func_array(
									[
										$this, $methodName,

									]
									,

									[
										"job" => $job,
									]
								)
							) {
							case self::BURY:
								\Yii::$app->beanstalk->delete($job);
								break;
							case self::DELETE:
								\Yii::$app->beanstalk->delete($job);
								break;
							case self::DELAY:
								\Yii::$app->beanstalk->release($job, self::DELAY_PIRORITY, self::DELAY_TIME);
								break;

							default:
								\Yii::$app->beanstalk->bury($job);
								break;
						}

					} catch (\yii\base\ErrorException $e) {
						fwrite(STDERR, Console::ansiFormat($e->getMessage() . "\n", [Console::FG_RED]));
					}

					if (\Yii::$app->beanstalk->sleep) {
						usleep(\Yii::$app->beanstalk->sleep);
					}
				}
			} catch (\Pheanstalk\Exception\ServerException $e) {

				fwrite(STDERR, Console::ansiFormat($e . "\n", [Console::FG_RED]));
			}

		}
	}

}