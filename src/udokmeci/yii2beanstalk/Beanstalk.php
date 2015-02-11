<?php
namespace udokmeci\yii2beanstalk;
use Pheanstalk\Pheanstalk;

class Beanstalk extends \yii\base\Component
{
    public $_beanstalk;
    public $host="127.0.0.1";
    public $port=11300;
    public $connectTimeout=1;
    public $connected=false;
    public $sleep=false;
    
    public function init()
    {

        try{
            $this->_beanstalk=new Pheanstalk($this->host, $this->port, $this->connectTimeout);
            $connected=true;
        }catch (\Pheanstalk\Exception\ConnectionException $e){
            \Yii::error($e);
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
        $priority = \Pheanstalk\PheanstalkInterface::DEFAULT_PRIORITY,
        $delay = \Pheanstalk\PheanstalkInterface::DEFAULT_DELAY,
        $ttr = \Pheanstalk\PheanstalkInterface::DEFAULT_TTR
    ) {
        try{
            if(!is_string($data)) {
                $data=json_encode($data);
            }
            return $this->_beanstalk->put($data, $priority, $delay, $ttr);   
        } catch (\Pheanstalk\Exception\ConnectionException $e){
            \Yii::error($e);
            return false;
        }
    }
    /**
     * {@inheritDoc}
     */
    public function putInTube(
        $tube,
        $data,
        $priority = \Pheanstalk\PheanstalkInterface::DEFAULT_PRIORITY,
        $delay = \Pheanstalk\PheanstalkInterface::DEFAULT_DELAY,
        $ttr = \Pheanstalk\PheanstalkInterface::DEFAULT_TTR
    ) {
        try{
            $this->_beanstalk->useTube($tube);
            return $this->put($data, $priority, $delay, $ttr);
        } catch (\Pheanstalk\Exception\ConnectionException $e){
            \Yii::error($e);
            return false;
        }
    }


    public function __call($method, $args) 
    {

        try{
            $result = call_user_func_array(array($this->_beanstalk, $method), $args);
            if ($result instanceof \Pheanstalk\Pheanstalk) { return $this; 
            }
            if ($result instanceof \Pheanstalk\Job) {
                if($this->isJson($result->getData())) { $result=new \Pheanstalk\Job($result->getId(), json_decode($result->getData())); 
                } 
            }
            return $result;

        } catch (\Pheanstalk\Exception\ConnectionException $e){
            \Yii::error($e);
            return false;
            

        }
    }
}