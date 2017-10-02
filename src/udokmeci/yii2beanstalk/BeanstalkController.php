<?php
namespace udokmeci\yii2beanstalk;

use Pheanstalk\Exception\ConnectionException;
use Pheanstalk\Exception\ServerException;
use Yii;
use yii\base\Event;
use yii\console\Controller;
use yii\helpers\Console;

class BeanstalkController extends Controller
{
    const BURY = "bury";

    const DELETE = "delete";

    const DELAY = "delay";

    const DELAY_EXPONENTIAL = "delay_exponential";

    const DECAY = "decay";

    const RELEASE = "release";

    const NO_ACTION = "noAction";

    const DELAY_PRIORITY = "1000";

    const DELAY_TIME = 5;

    const DELAY_MAX = 3;

    const DELAY_RETRIES = 15;

    const EVENT_BEFORE_JOB = 'beforeJob';

    const EVENT_AFTER_JOB = 'afterJob';

    private $_lasttimereconnect = null;
    private $_inProgress = false;
    private $_willTerminate = false;
    private $_test = false;
    /**
     * Collection of tube name and action method name key value pair
     */
    private $tubeActions = [];

    /**
     * Returns the Beanstalk component.  Overriding this method allows you to use a component
     * with a name other than "beanstalk".
     *
     * @return Beanstalk
     */
    public function getBeanstalk()
    {
        return Yii::$app->beanstalk;
    }

    /**
     * Controller specific tubes to listen if they do not exists.
     * @return array Collection of tube names to listen.
     */
    public function listenTubes()
    {
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function init()
    {
        try {
            if (!isset(Yii::$app->getI18n()->translations['udokmeci.beanstalkd'])) {
                Yii::$app->getI18n()->translations['udokmeci.beanstalkd'] = [
                    'class' => 'yii\i18n\PhpMessageSource',
                    'basePath' => '@app/messages', // if advanced application, set @frontend/messages
                    'sourceLanguage' => 'en',
                    'fileMap' => [
                        //'main' => 'main.php',
                    ],
                ];
            }
        } catch (ConnectionException $e) {
            Yii::error($e);
        }
        return parent::init();
    }

    /**
     * Returns the matching action method for the job.
     *
     * @param object $statsJob stats-job response from deamon.
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
    public function getTubes()
    {
        return array_unique(array_merge((array)$this->getBeanstalk()->listTubes(), $this->listenTubes()));
    }

    public function getDb()
    {
        return Yii::$app->db;
    }

    /**
     * {@inheritDoc}
     */
    public function actionIndex()
    {
    }

    public function setDBSessionTimeout()
    {
        try {
            $this->mysqlSessionTimeout();
        } catch (\Exception $e) {
            Yii::error(Yii::t('udokmeci.beanstalkd', "DB wait timeout did not succeeded."));
        }
    }

    /**
     *
     */
    public function mysqlSessionTimeout()
    {
        try {
            $command = $this->getDb()->createCommand('SET @@session.wait_timeout = 31536000');
            $command->execute();
        } catch (\Exception $e) {
            Yii::error(Yii::t('udokmeci.beanstalkd', "Mysql session.wait_timeout command did not succeeded."));
        }
    }

    /**
     * Decay a job with a fixed delay
     *
     * @param $job
     */
    public function decayJob($job)
    {
        $jobStats = $this->getBeanstalk()->statsJob($job);
        $delay_job = $jobStats->releases + $jobStats->delay + static::DELAY_TIME;
        if ($jobStats->releases >= static::DELAY_MAX) {
            $this->getBeanstalk()->delete($job);
            fwrite(STDERR,
                Console::ansiFormat(Yii::t('udokmeci.beanstalkd', 'Decaying Job Deleted!') . "\n", [Console::FG_RED]));
        } else {
            $this->getBeanstalk()->release($job, static::DELAY_PRIORITY, $delay_job);
        }
    }

    /**
     * Retry a job using exponential back off delay strategy
     *
     * @param $job
     */
    public function retryJobExponential($job)
    {
        $jobStats = $this->getBeanstalk()->statsJob($job);

        if ($jobStats->releases == static::DELAY_RETRIES) {
            $this->getBeanstalk()->delete($job);
            fwrite(STDERR, Console::ansiFormat(Yii::t('udokmeci.beanstalkd',
                    'Retrying Job Deleted on retry ' . $jobStats->releases . '!') . "\n", [Console::FG_RED]));
        } else {
            $this->getBeanstalk()->release(
                $job, 
                static::DELAY_PRIORITY, 
                intval( static::DELAY_TIME << $jobStats->releases + static::DELAY_TIME * rand(0, 1) ) 
            );
        }
    }

    /**
     * @return bool|void
     */
    public function registerSignalHandler()
    {
        if (!extension_loaded('pcntl')) {
            fwrite(STDOUT, Console::ansiFormat(Yii::t('udokmeci.beanstalkd',
                    "Warning: Process Control Extension is not loaded. Signal Handling Disabled! If process is interrupted, the reserved jobs will be hung. You may lose the job data.") . "\n",
                [Console::FG_YELLOW]));
            return null;
        }
        declare (ticks = 1);
        pcntl_signal(SIGTERM, [$this, 'signalHandler']);
        pcntl_signal(SIGINT, [$this, 'signalHandler']);
        fwrite(STDOUT, Console::ansiFormat(Yii::t('udokmeci.beanstalkd',
                "Process Control Extension is loaded. Signal Handling Registered!") . "\n", [Console::FG_GREEN]));
        return true;
    }

    /**
     * @param $signal
     *
     * @return bool|void
     */
    public function signalHandler($signal)
    {
        fwrite(STDOUT, Console::ansiFormat(Yii::t('udokmeci.beanstalkd', "Received signal {signal}.",
                ['signal' => $signal]) . "\n", [Console::FG_YELLOW]));

        switch ($signal) {
            case SIGTERM:
            case SIGINT:
                fwrite(STDOUT,
                    Console::ansiFormat(Yii::t('udokmeci.beanstalkd', "Exiting") . "...\n", [Console::FG_RED]));
                if (!$this->_inProgress) {
                    return $this->end();
                }
                $this->terminate();
                break;
            default:
                break;
        }

        return null;
    }

    /**
     * Terminate job
     */
    public function terminate()
    {
        $this->_willTerminate = true;
    }

    /**
     * Start test mode
     *
     * @return bool
     */
    public function setTestMode()
    {
        return $this->_test = true;
    }

    /**
     * End job
     *
     * @param int $status
     * @return bool|void
     * @throws \yii\base\ExitException
     */
    public function end($status = 0)
    {
        if ($this->_test) {
            return false;
        }
        return Yii::$app->end($status);
    }

    /**
     * Determines if the given action exists (basically the same as @see createAction(), but without
     * actually creating it.
     *
     * @param strign $id action ID
     * @return boolean whether or not the action exists
     */
    protected function hasAction($id)
    {
        $actionMap = $this->actions();
        if (isset($actionMap[$id])) {
            return true; 
        } 
        
        $methodName = 'action' . str_replace(' ', '', ucwords(implode(' ', explode('-', $id))));
        return method_exists($this, $methodName);
    }

    /**
     * Setup job before action
     *
     * @param \yii\base\Action $action
     *
     * @return bool|void
     * @throws \yii\base\InvalidConfigException
     */
    public function beforeAction($action)
    {
        if ($action->id == "index") {
            try {
                $this->registerSignalHandler();
                foreach ($this->getTubes() as $tube) {
                    $actionName = $tube; 
                    if ($this->hasAction($actionName)) {
                        $this->tubeActions[$tube] = $actionName;
                        fwrite(STDOUT, Console::ansiFormat(Yii::t('udokmeci.beanstalkd', "Listening {tube} tube.",
                                ["tube" => $tube]) . "\n", [Console::FG_GREEN]));
                        $bean = $this->getBeanstalk()->watch($tube);
                        if (!$bean) {
                            fwrite(STDERR, Console::ansiFormat("Check beanstalkd!" . "\n", [Console::FG_RED]));
                            return $this->end(Controller::EXIT_CODE_ERROR);
                        }
                    } else {
                        fwrite(STDOUT, Console::ansiFormat(Yii::t('udokmeci.beanstalkd',
                                "Not Listening {tube} tube since there is no action defined. {methodName}",
                                ["tube" => $tube, "methodName" => $actionName]) . "\n", [Console::FG_YELLOW]));
                    }
                }

                if (count($this->tubeActions) == 0) {
                    fwrite(STDERR, Console::ansiFormat(Yii::t('udokmeci.beanstalkd', "No tube found to listen!") . "\n",
                        [Console::FG_RED]));
                    return $this->end(Controller::EXIT_CODE_ERROR);
                }

                if (isset($bean)) {
                    while (!$this->_willTerminate) {
                        try {
                            if ($this->_lasttimereconnect == null) {
                                $this->_lasttimereconnect = time();
                                $this->setDBSessionTimeout();
                            }

                            if (time() - $this->_lasttimereconnect > 60 * 60) {
                                $this->getDb()->close();
                                $this->getDb()->open();
                                Yii::info(Yii::t('udokmeci.beanstalkd', "Reconnecting to the DB"));
                                $this->setDBSessionTimeout();
                                $this->_lasttimereconnect = time();
                            }

                            $job = $bean->reserve();
                            if (!$job) {
                                continue;
                            }

                            $jobStats = $bean->statsJob($job);
                            $actionName = $this->getTubeAction($jobStats);

                            if (!$actionName) {
                                fwrite(STDERR, Console::ansiFormat(Yii::t('udokmeci.beanstalkd',
                                        "No method found for job's tube!") . "\n", [Console::FG_RED]));
                                break;
                            }
                            $this->_inProgress = true;
                            $this->trigger(self::EVENT_BEFORE_JOB, new Event);
                            $this->executeJob($actionName, $job);
                        } catch (Yii\db\Exception $e) {
                            if (isset($job)) {
                                $this->decayJob($job);
                            }
                            fwrite(STDERR, Console::ansiFormat($e->getMessage() . "\n", [Console::FG_RED]));
                            fwrite(STDERR,
                                Console::ansiFormat(Yii::t('udokmeci.beanstalkd', 'DB Error job is decaying.') . "\n",
                                    [Console::FG_RED]));
                        } catch (Yii\base\ErrorException $e) {
                            fwrite(STDERR, Console::ansiFormat($e->getMessage() . "\n", [Console::FG_RED]));
                        }
                        $this->_inProgress = false;
                        $this->trigger(self::EVENT_AFTER_JOB, new Event);
                        if ($this->getBeanstalk()->sleep) {
                            usleep($this->getBeanstalk()->sleep);
                        }
                    }
                }
            } catch (ServerException $e) {
                $this->trigger(self::EVENT_AFTER_JOB, new Event);
                fwrite(STDERR, Console::ansiFormat($e . "\n", [Console::FG_RED]));
            }

            return $this->end(Controller::EXIT_CODE_NORMAL);
        }

        return true;
    }

    /**
     * Execute job and handle outcome
     *
     * @param string $actionName name of the action to run for the job
     * @param Job $job job to run
     */
    protected function executeJob($actionName, $job)
    {
        $action = $this->createAction($actionName);
        switch ($action->runWithParams(['job' => $job])) {
            case self::NO_ACTION:
                break;
            case self::RELEASE:
                $this->getBeanstalk()->release($job);
                break;
            case self::BURY:
                $this->getBeanstalk()->bury($job);
                break;
            case self::DECAY:
                $this->decayJob($job);
                break;
            case self::DELETE:
                $this->getBeanstalk()->delete($job);
                break;
            case self::DELAY:
                $this->getBeanstalk()->release($job, static::DELAY_PRIORITY, static::DELAY_TIME);
                break;
            case self::DELAY_EXPONENTIAL:
                $this->retryJobExponential($job);
                break;
            default:
                $this->getBeanstalk()->bury($job);
                break;
        }

        Yii::getLogger()->flush();
    }
}
