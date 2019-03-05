# PHP 实现PCM转WAV

[![License](https://img.shields.io/packagist/l/inhere/console.svg?style=flat-square)](LICENSE)
[![Php Version](https://img.shields.io/badge/php-%3E=5.4-brightgreen.svg?maxAge=2592000)](https://packagist.org/packages/jade/pcm-to-wav)
[![Latest Stable Version](http://img.shields.io/packagist/v/jade/pcm-to-wav.svg)](https://packagist.org/packages/jade/pcm-to-wav)

此扩展能快速将PCM格式的音波文件转换成WAV格式的音频文件，目前只为公司项目提供解决方案。

扩展参考于 [helviojunior/WaveGenerator](https://github.com/helviojunior/WaveGenerator) ，在此特别感谢！

## 安装

```bash
composer require jade/pcm-to-wav
```

## 使用

```bash
use PcmToWave\PcmToWave;

$input_file = './file/test.pcm'; // 准备输入的文件
$output_file = './file/test.wav'; // 预计输出的文件
$data = PcmToWave::init($pcm_file, $wav_file); // 调用转换

```

## 测试Demo使用

```bash
进入扩展包目录
cd vendor/jade/pcm-to-wav
composer install
cd test
php Test.php
```

## 原理介绍

### 什么是 `PCM` 和 `WAV` ？

 `PCM` ：PCM（Pulse Code Modulation----脉码调制录音)。所谓 `PCM` 录音就是将声音等模拟信号变成符号化的脉冲列，再予以记录。 `PCM` 信号是由 `1` 、 `0` 等符号构成的数字信号，而未经过任何编码和压缩处理。与模拟信号比，它不易受传送系统的杂波及失真的影响。动态范围宽，可得到音质相当好的影响效果。

 `WAV` ： `WAV` 是一种无损的音频文件格式， `WAV` 符合 PIFF(Resource Interchange File Format) 规范。所有的 `WAV` 都有一个文件头，这个文件头音频流的编码参数。WAV对音频流的编码没有硬性规定，除了 `PCM` 之外，还有几乎所有支持 `ACM` 规范的编码都可以为WAV的音频流进行编码。

###  `PCM` 和 `WAV` 的关系

简单地说,  `PCM` 是音频的原始数据,  `WAV` 则是一种封装音频数据的容器, 而且它的格式还很简单, 只是在数据开头添加一些和音频数据相关的头信息。


首先我们看一下WAV的格式规则, 如下图

![](https://uimg.jadert.com/15517554187085.jpg)

![](https://uimg.jadert.com/15517599631747.jpg)

了解这些规则后，我们就可以撸代码吧

1、 `ChunkID` 占4byte, 固定值"RIFF"

    $ChunkID = array(0x52, 0x49, 0x46, 0x46); // RIFF 16进制的0x52等于10进制中的82，82对应的ASCII码为R
    
2、 `ChunkSize` 占4byte, 值为4 + (8 + SubChunk1Size) + (8 + SubChunk2Size), 其中如果原始数据是PCM, 简化为36 + SubChunk2Size

    $ChunkSize = array(0x0, 0x0, 0x0, 0x0);
    $ChunkSize = self::getLittleEndianByteArray(36 + $dataSize);
    
3、 `Format` 占4byte, 固定值"WAVE"

    $FileFormat = array(0x57, 0x41, 0x56, 0x45); // WAVE
    
4、 `Subchunk1ID` 占4byte, 固定值"ftm "(注意空格补全4位)

    $Subchunk1ID = array(0x66, 0x6D, 0x74, 0x20); // fmt

5、 `Subchunk1Size` 占4byte, 数据为PCM时, 值为16

    $Subchunk1Size = array(0x10, 0x0, 0x0, 0x0); // 16 little endian

6、 `AudioFormat` 占2byte, 数据为PCM时, 值为1, 其他值表示数据进行过某种压缩

    $AudioFormat = array(0x1, 0x0); // PCM = 1 little endian
    
7、 `NumChannels` 占2byte, 对应AudioRecord中的channelConfig, 单声道Mono = 1, 立体声Stereo = 2

    if ($numchannels == 2) {
        $fmt->NumChannels = array(0x2, 0x0); // 立体声为2
    } else {
        $fmt->NumChannels = array(0x1, 0x0); // 单声道为1
    }

8、 `SampleRate` 占4byte, 对应AudioRecord中的sampleRateInHz, 即采样频率, 例如8000, 16000, 44100

    $SampleRate = self::getLittleEndianByteArray($samplerate);

9、 `ByteRate` 占4byte, 值为SampleRate * BlockAlign

    self::getLittleEndianByteArray($samplerate * $numchannels * ($bitspersample / 8));

10、 `BlockAlign` 占2byte, 值为NumChannels * BitsPerSample / 8

    self::getLittleEndianByteArray($numchannels * ($bitspersample / 8), true);

11、 `BitsPerSample` 占2byte, 对应AudioRecord中的audioFormat, 8bits = 8, 16bits = 16

    self::getLittleEndianByteArray($bitspersample, true);
    
12、 `Subchunk2ID` 占4byte, 固定值"data", 即

    $Subchunk2ID = array(0x64, 0x61, 0x74, 0x61); // data

13、 `Subchunk2Size` 占4byte, 描述音频数据的长度, 就是pcm文件大小

    self::getLittleEndianByteArray(filesize($filename));
    
14、 `Data` 占pcm文件大小个byte, 表示原始的PCM音频数据

 `getLittleEndianByteArray` 方法说明
 
 `getLittleEndianByteArray`主要是将传递过来的数进行处理已转换成需要使用的数据，站几字节，就返回多少长度的数组

    private static function getLittleEndianByteArray($lValue, $short = false)
        {
            $b = array(0, 0, 0, 0);
            $running = $lValue / pow(16, 6);
            $b[3] = floor($running);
            $running -= $b[3];
            $running *= 256;
            $b[2] = floor($running);
            $running -= $b[2];
            $running *= 256;
            $b[1] = floor($running);
            $running -= $b[1];
            $running *= 256;
            $b[0] = round($running);
    
            if ($short) { // 为 `true` 时返回长度为2的数组
                $tmp = array_slice($b, 0, 2);
                $b = $tmp;
            }
    
            return $b;
        }

整个文件的开头44字节信息也基本说明完了，下面就说下处理类文件的实现，这边处理的逻辑先临时创建一个只有44字节的文件，然后将 `PCM` 文件的数据追加进该文件，最终根据WAV的格式规则实际计算出真实的头部44字节信息并将文件修改指针指向文件开头，然后修改为新产生的数据
