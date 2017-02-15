<?php
namespace udokmeci\yii2beanstalk;

use Pheanstalk\Exception\ConnectionException;
use Pheanstalk\Job;
use Pheanstalk\Pheanstalk;
use Pheanstalk\PheanstalkInterface;
use Pheanstalk\Response;
use Yii;
use yii\base\Component;

/**
 * Class Beanstalk
 * @package udokmeci\yii2beanstalk
 *
 * @method Beanstalk useTube($tube)
 * @method Beanstalk watch($tube)
 * @method Job reserve($timeout = null)
 * @method Response statsJob($job)
 * @method void bury($job, $priority = Pheanstalk::DEFAULT_PRIORITY)
 * @method Beanstalk release($job, $priority = Pheanstalk::DEFAULT_PRIORITY, $delay = Pheanstalk::DEFAULT_DELAY)
 * @method Beanstalk delete($job)
 * @method Beanstalk listTubes()
 */
class Beanstalk extends Component
{
    /** @var  Pheanstalk */
    public $_beanstalk;
    public $host = "127.0.0.1";
    public $port = 11300;
    public $connectTimeout = 1;
    public $connected = false;
    public $sleep = false;

    public function init()
    {
        try {
            $this->_beanstalk = new Pheanstalk($this->host, $this->port, $this->connectTimeout);
        } catch (ConnectionException $e) {
            Yii::error($e);
        }
    }

    private function isJson($string)
    {
        json_decode($string);
        return (json_last_error() == JSON_ERROR_NONE);
    }

    /**
     * {@inheritDoc}
     */
    public function put(
        $data,
        $priority = PheanstalkInterface::DEFAULT_PRIORITY,
        $delay = PheanstalkInterface::DEFAULT_DELAY,
        $ttr = PheanstalkInterface::DEFAULT_TTR
    )
    {
        try {
            if (!is_string($data)) {
                $data = json_encode($data);
            }
            return $this->_beanstalk->put($data, $priority, $delay, $ttr);
        } catch (ConnectionException $e) {
            Yii::error($e);
            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function putInTube(
        $tube,
        $data,
        $priority = PheanstalkInterface::DEFAULT_PRIORITY,
        $delay = PheanstalkInterface::DEFAULT_DELAY,
        $ttr = PheanstalkInterface::DEFAULT_TTR
    ) {

        try {
            $this->_beanstalk->useTube($tube);
            return $this->put($data, $priority, $delay, $ttr);
        } catch (ConnectionException $e) {
            Yii::error($e);
            return false;
        }
    }

    public function __call($method, $args)
    {

        try {
            $result = call_user_func_array(array($this->_beanstalk, $method), $args);

            //Chaining.
            if ($result instanceof Pheanstalk) {
                return $this;
            }

            //Check for json data.
            if ($result instanceof Job) {
                if ($this->isJson($result->getData())) {
                    $result = new Job($result->getId(), json_decode($result->getData()));
                }
            }
            return $result;

        } catch (ConnectionException $e) {
            Yii::error($e);
            return false;
        }
    }
}
