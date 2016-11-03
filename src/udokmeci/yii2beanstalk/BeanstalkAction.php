<?php
namespace udokmeci\yii2beanstalk;

/**
 * Action class for bean workers.
 */
abstract class BeanstalkAction extends \yii\base\Action
{
	/**
	 * Runs the specified beanstalk job.
	 * @param Pheanstalk\Job $job
	 * @return string action to take (one of:
	 *      BeanstalkController::BURY
	 *      BeanstalkController::RELEASE
	 *      BeanstalkController::DELAY
	 *      BeanstalkController::DELETE
	 *      BeanstalkController::NO_ACTION
	 *      BeanstalkController::DECAY
	 * )
	 */
	public abstract function run(\Pheanstalk\Job $job);
}
