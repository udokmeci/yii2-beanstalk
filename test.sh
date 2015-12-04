#!/bin/bash
sudo pkill beanstalkd
beanstalkd &
phpunit
