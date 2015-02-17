<?php
namespace udokmeci\yii2beanstalk;

use Yii;
use yii\console\Controller;
use yii\helpers\Console;

class BeanstalkController extends Controller {
	const BURY = "bury";
	const DELETE = "delete";
	const DELAY = "delay";
	const DECAY = "decay";
	const RELEASE = "release";
	const NO_ACTION = "noAction";
	const DELAY_PIRORITY = "1000";
	const DELAY_TIME = 5;
	const DELAY_MAX = 3;

	private $_lasttimereconnect = null;
	private $_inProgress = false;
	private $_willTerminate = false;

	/**
	 * Collection of tube name and action method name key value pair
	 */
	private $tubeActions = [];
	/**
	 * Controller specific tubes to listen if they do not exists.
	 * return array Collection of tube names to listen.
	 */
	public function listenTubes() {
		return [];
	}
	/**
	 * Returns the matching action method for the job.
	 * 
	 * @param object stats-job response from deamon.
	 * @return string Method name proper to yii2 matching to tube name
	 */
	public function getTubeAction($statsJob)
	{

		return isset($this->tubeActions[$statsJob->tube]) ? $this->tubeActions[$statsJob->tube] : false;
	}
	/**
	 * Discovers tubes from deamon and merge them with user forced ones.
	 * 
	 * @return array Collection of tube names.
	 */
	public function getTubes() {
		return array_unique(array_merge((array) Yii::$app->beanstalk->listTubes(), $this->listenTubes()));
	}
	/**
	 * {@inheritDoc}
	 */
	public function actionIndex() {

	}

	public function setDBSessionTimout() {
		try {
			$this->mysqlSessionTimeout();
		} catch (\Exception $e) {
			Yii::error("DB wait timeout did not succeeded ");
		}

	}

	public function mysqlSessionTimeout() {
		try {
			$command = Yii::$app->db->createCommand('SET @@session.wait_timeout = 31536000');
			$command->execute();
		} catch (\Exception $e) {
			Yii::error("Mysql session.wait_timeout command did not succeeded ");
		}
	}

	public function decayJob($job){
		$jobStats = Yii::$app->beanstalk->statsJob($job);
		if ($jobStats->delay >= static::DELAY_MAX) {
			Yii::$app->beanstalk->delete($job);
			fwrite(STDERR, Console::ansiFormat(Yii::t('udokmeci.beanstalkd', 'Decaying Job Deleted!') . "\n", [Console::FG_RED]));
		} else {
			Yii::$app->beanstalk->release($job, static::DELAY_PIRORITY, static::DELAY_TIME^($jobStats->delay + 1));
		}
	}


	public function signalHandler(){
		if (!extension_loaded('pcntl'))
			return;
		pcntl_signal(SIGINT, function ($signal) {
			fwrite(STDOUT, Console::ansiFormat("Exiting\n", [Console::FG_RED]));
			if (!$this->_inProgress)
				return Yii::$app->end();
			$this->_willTerminate = true;
		});
		declare(ticks = 1);
	}

	/**
	 * @param $action
	 */
	public function beforeAction($action) {
		if ($action->id == "index") {
			try {
				$this->signalHandler();
				foreach ($this->getTubes() as $key => $tube) {
					$methodName = 'action' . str_replace(' ', '', ucwords(implode(' ', explode('-', $tube))));
					if ($this->hasMethod($methodName)) {
						$this->tubeActions[$tube] = $methodName;
						fwrite(STDOUT, Console::ansiFormat("Listening $tube tube." . "\n", [Console::FG_GREEN]));
						$bean = Yii::$app->beanstalk->watch($tube);
						if (!$bean) {
							fwrite(STDERR, Console::ansiFormat("Check beanstalkd!" . "\n", [Console::FG_RED]));
							return Yii::$app->end();
						}

					} else {
						fwrite(STDOUT, Console::ansiFormat("Not Listening $tube tube since there is no action defined. $methodName" . "\n", [Console::FG_YELLOW]));
					}
				}

				if (count($this->tubeActions) == 0) {
					fwrite(STDERR, Console::ansiFormat("No tube found to listen!" . "\n", [Console::FG_RED]));
					return Yii::$app->end();
				}

				while (!$this->_willTerminate) {
					try {
						if ($this->_lasttimereconnect == null) {
							$this->_lasttimereconnect = time();
							$this->setDBSessionTimout();

						}

						if (time() - $this->_lasttimereconnect > 60 * 60) {
							Yii::$app->db->close();
							Yii::$app->db->open();
							Yii::info("Reconnecting to the DB");
							$this->setDBSessionTimout();
							$this->_lasttimereconnect = time();
						}

						$job = $bean->reserve();
						$jobStats = $bean->statsJob($job);
						$methodName = $this->getTubeAction($jobStats);

						if (!$methodName) {
							fwrite(STDERR, Console::ansiFormat("No method found for job's tube!" . "\n", [Console::FG_RED]));
							break;
						}
						$this->_inProgress=true;
						switch (
								call_user_func_array(
									[
										$this, $methodName,

									],
									[
										"job" => $job,
									]
								)
							) {
							case self::NO_ACTION:
								break;
							case self::RELEASE:
								Yii::$app->beanstalk->release($job);
								break;
							case self::BURY:
								Yii::$app->beanstalk->delete($job);
								break;
							case self::DECAY:
								$this->decayJob($job);
								break;
							case self::DELETE:
								Yii::$app->beanstalk->delete($job);
								break;
							case self::DELAY:
								Yii::$app->beanstalk->release($job, static::DELAY_PIRORITY, static::DELAY_TIME);
								break;

							default:
								Yii::$app->beanstalk->bury($job);
								break;
						}

					} catch (Yii\db\Exception $e) {
						$this->decayJob($job);
						fwrite(STDERR, Console::ansiFormat($e->getMessage() . "\n", [Console::FG_RED]));
						fwrite(STDERR, Console::ansiFormat(Yii::t('udokmeci.beanstalkd', 'DB Error job is decaying.') . "\n", [Console::FG_RED]));
					} catch (Yii\base\ErrorException $e) {
						fwrite(STDERR, Console::ansiFormat($e->getMessage() . "\n", [Console::FG_RED]));
					}
					$this->_inProgress=false;
					if (Yii::$app->beanstalk->sleep) {
						usleep(Yii::$app->beanstalk->sleep);
					}
				}
				
			} catch (\Pheanstalk\Exception\ServerException $e) {
				fwrite(STDERR, Console::ansiFormat($e . "\n", [Console::FG_RED]));
			}
			return Yii::$app->end();
		}
	}

}