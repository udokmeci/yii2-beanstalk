<?php
namespace tests\commands;

use udokmeci\yii2beanstalk\BeanstalkController;
use yii\helpers\Console;
use Yii;

class WorkerController extends BeanstalkController
{



  public function listenTubes(){
    return ["test"];
  }

  public function actionTest($job){
        $this->setTestMode();
        $sentData = $job->getData();

           // something useful here
          switch ($sentData->do) {
              case static::NO_ACTION:
                  break;
              case static::RELEASE:
                  Yii::$app->beanstalk->release($job);
                  break;
              case static::BURY:
                  Yii::$app->beanstalk->delete($job);
                  break;
              case static::DECAY:
                  $this->decayJob($job);
                  break;
              case static::DELETE:
                  Yii::$app->beanstalk->delete($job);
                  break;
              case static::DELAY:
                  Yii::$app->beanstalk->release($job, static::DELAY_PRIORITY, static::DELAY_TIME);
                  break;
        }

        $this->terminate();
    }
}