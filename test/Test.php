<?php
/**
 * @Description :
 *
 * @Date        : 2019-01-17 15:47
 * @Author      : Jade
 */

require_once '../vendor/autoload.php';

use PcmToWave\PcmToWave;

$pcm_file = './file/test.pcm';
$wav_file = './file/test.wav';

try {
    $data = PcmToWave::init($pcm_file, $wav_file);
} catch (\Exception $e) {
    var_dump($e);
}

var_dump($data);