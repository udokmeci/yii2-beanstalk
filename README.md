yii2-beanstalk
==============

Yii2 [beanstalkd][1] web and console component which is an interface on the top of [pda/pheanstalk][2]. Thanks [Paul Annesley][3] for such a complete work. 

[1]: http://xph.us/software/beanstalkd/
[2]: https://github.com/pda/pheanstalk
[3]: http://paul.annesley.cc/


How to use?
==============
##Installation with Composer
Just add the line under `require` object in your `composer.json` file.
``` json
{
  "require": {
    "udokmeci/yii2-beanstalk" : "dev-master"
  }
}
```
then run 

``` console
$> composer update
```

##Configuration
Now add following in to your `main` and `console` configuration  under ```components``` 
``` php
'beanstalk'=>[
            'class' => 'udokmeci\yii2beanstalk',
            'host'=> "127.0.0.1", // default host
            'port'=>11300, //default port
            'connectTimeout'=> 1,
            'sleep' => false, // or int for usleep after every job 
        ],
```

Now add following in to your `console` configuration only.

``` php
'params' => $params
// add you controller with name and class name next to params.
'controllerMap' => [
        'worker'=>[
            'class' => 'app\commands\WorkerController',
        ]
       
    ],

```

##Producing
Now if everthing is ok. You run ```beandstalkd```
and access to controller like 
````` php 
\Yii::$app->beanstalk
        ->putInTube('tube', $mixedData ,$priority,$delay);

`````
`$mixedData` is added on v1.0 for complex usage. Anything else then `string` will be send as `json` format. So you can sent anything within it suppoted by `json`.

##Worker
for worker it also has a built in controller which runs an infinite loop and wait for new jobs. Most of the work is done in `BeanstalkController` . All you have to do is to create a controller and action like below.

###Controller
Create an controller under your `commands` folder. Give the name anything you want to it and `extend` your controller from `udokmeci\yii2beanstalk\BeanstalkController`
#####Example

``` php
<?php
namespace app\commands;

use udokmeci\yii2beanstalk\BeanstalkController;
use yii\helpers\Console;
use Yii;

class WorkerController extends BeanstalkController
{
  
  public function listenTubes(){
    return ["tube1","tube2"];
  }

}
```

#####Action
``` php
<?php
	public function actionTube($job){
	    $sentData = $job->getData();
	    try {
    	   // something useful here
           if($everthingIsAllRight == true){
                fwrite(STDOUT, Console::ansiFormat("- Everything is allright"."\n", [Console::FG_GREEN]));
                return self::DELETE; //Deletes the job from beanstalkd
           }
           fwrite(STDOUT, Console::ansiFormat("- Not everything is allright!!!"."\n", [Console::FG_GREEN]));
           return self::DELAY; //Delays the job for later try
           // if you return anything else job is released.
	    } catch (\Exception $e) {
            //If there is anything to do.
            fwrite(STDERR, Console::ansiFormat($e."\n", [Console::FG_RED]));
            return self::DELETE;
	    }
	}
```

#####Running Worker
Running console is the easiest. Run ./yii Your ```controller/action```
``` console
./yii worker/tube
```
Any forks are welcome.
