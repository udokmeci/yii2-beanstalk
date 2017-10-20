<?php
namespace udokmeci\yii2beanstalk;

use Pheanstalk\Exception\ConnectionException;
use Pheanstalk\Exception\ServerException;
use Yii;
use yii\base\Event;
use yii\base\InvalidConfigException;
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

    /**
     * @var Beanstalk|string
     */
    public $beanstalk = 'beanstalk';

    private $_lasttimereconnect = null;
    private $_inProgress = false;
    private $_willTerminate = false;
    private $_test = false;
    /**
     * Collection of tube name and action method name key value pair
     */
    private $tubeActions = [];

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
        if (is_string($this->beanstalk)) {
            $this->beanstalk = Yii::$app->get($this->beanstalk);
        }
        if (!$this->beanstalk instanceof Beanstalk) {
            throw new InvalidConfigException("Controller beanstalk must extend from \\udokmeci\\yii2beanstalk\\Beanstalk.");
        }
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
        return array_unique(array_merge((array)$this->beanstalk->listTubes(), $this->listenTubes()));
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
        $jobStats = $this->beanstalk->statsJob($job);
        $delay_job = $jobStats->releases + $jobStats->delay + static::DELAY_TIME;
        if ($jobStats->releases >= static::DELAY_MAX) {
            $this->beanstalk->delete($job);
            $this->stderr(Yii::t('udokmeci.beanstalkd', 'Decaying Job Deleted!') . "\n", Console::FG_RED);
        } else {
            $this->beanstalk->release($job, static::DELAY_PRIORITY, $delay_job);
        }
    }

    /**
     * Retry a job using exponential back off delay strategy
     *
     * @param $job
     */
    public function retryJobExponential($job)
    {
        $jobStats = $this->beanstalk->statsJob($job);

        if ($jobStats->releases == static::DELAY_RETRIES) {
            $this->beanstalk->delete($job);
            $this->stderr(Yii::t('udokmeci.beanstalkd',
                    'Retrying Job Deleted on retry ' . $jobStats->releases . '!') . "\n", Console::FG_RED);
        } else {
            $this->beanstalk->release(
                $job,
                static::DELAY_PRIORITY,
                intval( static::DELAY_TIME << $jobStats->releases + static::DELAY_TIME * rand(0, 1) )
            );
        }
    }

    /**
     * @return bool
     */
    public function registerSignalHandler()
    {
        if (!extension_loaded('pcntl')) {
            $this->stdout(Yii::t('udokmeci.beanstalkd',
                    "Warning: Process Control Extension is not loaded. Signal Handling Disabled! If process is interrupted, the reserved jobs will be hung. You may lose the job data.") . "\n",
                Console::FG_YELLOW);
            return null;
        }
        declare (ticks = 1);
        pcntl_signal(SIGTERM, [$this, 'signalHandler']);
        pcntl_signal(SIGINT, [$this, 'signalHandler']);
        pcntl_signal(SIGHUP, [$this, 'signalHandler']);
        $this->stdout(Yii::t('udokmeci.beanstalkd',
                "Process Control Extension is loaded. Signal Handling Registered!") . "\n", Console::FG_GREEN);
        return true;
    }

    /**
     * @param $signal
     *
     * @return bool
     */
    public function signalHandler($signal)
    {
        $this->stdout(Yii::t('udokmeci.beanstalkd', "Received signal {signal}.",
                ['signal' => $signal]) . "\n", Console::FG_YELLOW);

        switch ($signal) {
            case SIGTERM:
            case SIGINT:
            case SIGHUP:
                $this->stdout(Yii::t('udokmeci.beanstalkd', "Exiting") . "...\n", Console::FG_RED);
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
     * @return bool
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
     * Setup job before action
     *
     * @param \yii\base\Action $action
     *
     * @return bool
     * @throws \yii\base\InvalidConfigException
     */
    public function beforeAction($action)
    {
        if ($action->id == "index") {
            try {
                $this->registerSignalHandler();
                foreach ($this->getTubes() as $key => $tube) {
                    $methodName = 'action' . str_replace(' ', '', ucwords(implode(' ', explode('-', $tube))));
                    if ($this->hasMethod($methodName)) {
                        $this->tubeActions[$tube] = $methodName;
                        $this->stdout(Yii::t('udokmeci.beanstalkd', "Listening {tube} tube.",
                                ["tube" => $tube]) . "\n", Console::FG_GREEN);
                        $bean = $this->beanstalk->watch($tube);
                        if (!$bean) {
                            $this->stderr("Check beanstalkd!" . "\n", Console::FG_RED);
                        }
                    } else {
                        $this->stdout(Yii::t('udokmeci.beanstalkd',
                                "Not Listening {tube} tube since there is no action defined. {methodName}",
                                ["tube" => $tube, "methodName" => $methodName]) . "\n", Console::FG_YELLOW);
                    }
                }

                if (count($this->tubeActions) == 0) {
                    $this->stderr(Yii::t('udokmeci.beanstalkd', "No tube found to listen!") . "\n",
                        Console::FG_RED);
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

                            $job = $bean->reserve(1);
                            if (!$job) {
                                if ($this->beanstalk->sleep) {
                                    usleep($this->beanstalk->sleep);
                                }                                
                                continue;
                            }

                            $jobStats = $bean->statsJob($job);
                            $methodName = $this->getTubeAction($jobStats);

                            if (!$methodName) {
                                $this->stderr(Yii::t('udokmeci.beanstalkd',
                                        "No method found for job's tube!") . "\n", Console::FG_RED);
                                break;
                            }
                            $this->_inProgress = true;
                            $this->trigger(self::EVENT_BEFORE_JOB, new Event);
                            $this->executeJob($methodName, $job);
                        } catch (Yii\db\Exception $e) {
                            if (isset($job)) {
                                $this->decayJob($job);
                            }
                            $this->stderr($e->getMessage() . "\n", Console::FG_RED);
                            $this->stderr(Yii::t('udokmeci.beanstalkd', 'DB Error job is decaying.') . "\n",
                                Console::FG_RED);
                        } catch (Yii\base\ErrorException $e) {
                            $this->stderr($e->getMessage() . "\n", Console::FG_RED);
                        }
                        $this->_inProgress = false;
                        $this->trigger(self::EVENT_AFTER_JOB, new Event);
                        if ($this->beanstalk->sleep) {
                            usleep($this->beanstalk->sleep);
                        }
                    }
                }
            } catch (ServerException $e) {
                $this->trigger(self::EVENT_AFTER_JOB, new Event);
                $this->stderr($e . "\n", Console::FG_RED);
            }

            return $this->end(Controller::EXIT_CODE_NORMAL);
        }

        return true;
    }

    /**
     * Execute job and handle outcome
     *
     * @param $methodName
     * @param $job
     */
    protected function executeJob($methodName, $job)
    {
        switch (call_user_func_array(
            [$this, $methodName],
            ["job" => $job]
        )
        ) {
            case self::NO_ACTION:
                break;
            case self::RELEASE:
                $this->beanstalk->release($job);
                break;
            case self::BURY:
                $this->beanstalk->bury($job);
                break;
            case self::DECAY:
                $this->decayJob($job);
                break;
            case self::DELETE:
                $this->beanstalk->delete($job);
                break;
            case self::DELAY:
                $this->beanstalk->release($job, static::DELAY_PRIORITY, static::DELAY_TIME);
                break;
            case self::DELAY_EXPONENTIAL:
                $this->retryJobExponential($job);
                break;
            default:
                $this->beanstalk->bury($job);
                break;
        }

    }
}
