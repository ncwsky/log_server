#!/usr/bin/env php
<?php
require __DIR__ . '/common/LogClient.php';
require __DIR__ . '/common/LogPackage.php';

$module = ['api','chain'];
LogClient::$address = '192.168.0.245:11024';

$time_start = microtime(true);
$data = LogPackage::toEncode('api','index/info', $time_start,0,0,0,'REPORT_IP');
#var_dump($data); var_dump(LogPackage::input($data)); var_dump(LogPackage::decode($data));

echo strlen('my name is dz'),PHP_EOL;

$data = LogClient::log('my name is dz', 'test');
var_dump($data); var_dump(LogPackage::input($data)); var_dump(LogPackage::decode($data));

$data = LogClient::metric('title', 'my name is dz', 'test2');
var_dump($data); var_dump(LogPackage::input($data)); var_dump(LogPackage::decode($data));

$data = LogClient::ok('title', 0,'my name is dz', 'test3');
var_dump($data); var_dump(LogPackage::input($data)); var_dump(LogPackage::decode($data));

$data = LogClient::fail('title', 100,'my name is dz', 'test3');
var_dump($data); var_dump(LogPackage::input($data)); var_dump(LogPackage::decode($data));

die();
var_dump(LogWorkerClient::getLog('interface', 'backend', $name='goodszedition/normal-save', $start_time = 1624032046, $end_time = 1624096477, $code = '', $msg = '', $offset = 0, $count = 200));
die();