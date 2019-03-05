<?php
/**
 * @Description :
 *
 * @Date        : 2019-01-17 15:47
 * @Author      : Jade
 */

namespace PcmToWave;

class PcmToWave
{
    private static $HEADER_LENGTH = 44;    // 文件内容开头大小至少为44字节

    /**
     * @param $input
     * @param $output
     * @return bool
     * @throws \Exception
     */
    public static function init($input, $output)
    {
        if (is_file($output)) {
            @unlink($output);
        }
        @file_put_contents($output, 'Jade is an excellent and handsome young man.', FILE_APPEND);
        if (@file_put_contents($output, @file_get_contents($input), FILE_APPEND) === false) {
            throw new \Exception('write_file_fail');
        }
        return self::createPcmAudioFile($output);
    }

    /**
     * 将波形文件转成WAVE文件
     *
     * @param    string $filename 波形文件，文件路径
     * @param    int $numchannels （可选) 声道  (1 单声道, 2 立体声) eg:2
     * @param    int $samplerate (可选) 采样率 eg:8000
     * @param    int $bitspersample (可选) 采样点占用位数 (8、16、24、32)
     *
     * @return bool 读取文件并修改，当文件不存在或不可读时为false
     */
    public static function createPcmAudioFile($filename, $numchannels = 2, $samplerate = 8000, $bitspersample = 16)
    {
        if ($handle = fopen($filename, 'r+')) {
            $riff = new \stdClass();
            $dataSize = filesize($filename);

            $riff->ChunkID = array(0x52, 0x49, 0x46, 0x46);     // RIFF
            $riff->ChunkSize = array(0x0, 0x0, 0x0, 0x0);
            $riff->FileFormat = array(0x57, 0x41, 0x56, 0x45);  // WAVE
            $riff->ChunkSize = self::getLittleEndianByteArray(36 + $dataSize);

            $fmt = new \stdClass();
            $fmt->ID = array(0x66, 0x6D, 0x74, 0x20);       // fmt
            $fmt->ChunkSize = array(0x10, 0x0, 0x0, 0x0);  // 16 little endian
            $fmt->AudioFormat = array(0x1, 0x0);            // PCM = 1 little endian
            if ($numchannels == 2) {
                $fmt->NumChannels = array(0x2, 0x0);        // 立体声为2
            } else {
                $fmt->NumChannels = array(0x1, 0x0);        // 单声道为1
            }
            $fmt->SampleRate = self::getLittleEndianByteArray($samplerate);
            $fmt->BitsPerSample = self::getLittleEndianByteArray($bitspersample, true);
            $fmt->ByteRate = self::getLittleEndianByteArray($samplerate * $numchannels * ($bitspersample / 8));
            $fmt->BlockAlign = self::getLittleEndianByteArray($numchannels * ($bitspersample / 8), true);

            $data = new \stdClass();
            $data->ID = array(0x64, 0x61, 0x74, 0x61);  // data
            $data->Size = self::getLittleEndianByteArray($dataSize);

            //RIFF
            foreach ($riff->ChunkID as $val) {
                fwrite($handle, chr($val));
            }

            foreach ($riff->ChunkSize as $val) {
                fwrite($handle, chr($val));
            }
            foreach ($riff->FileFormat as $val) {
                fwrite($handle, chr($val));
            }

            //Format
            foreach ($fmt->ID as $val) {
                fwrite($handle, chr($val));
            }
            foreach ($fmt->ChunkSize as $val) {
                fwrite($handle, chr($val));
            }
            foreach ($fmt->AudioFormat as $val) {
                fwrite($handle, chr($val));
            }
            foreach ($fmt->NumChannels as $val) {
                fwrite($handle, chr($val));
            }
            foreach ($fmt->SampleRate as $val) {
                fwrite($handle, chr($val));
            }
            foreach ($fmt->ByteRate as $val) {
                fwrite($handle, chr($val));
            }
            foreach ($fmt->BlockAlign as $val) {
                fwrite($handle, chr($val));
            }
            foreach ($fmt->BitsPerSample as $val) {
                fwrite($handle, chr($val));
            }

            //Data
            foreach ($data->ID as $val) {
                fwrite($handle, chr($val));
            }
            foreach ($data->Size as $val) {
                fwrite($handle, chr($val));
            }

            fflush($handle);
            fclose($handle);

            return true;
        } else {
            return false;
        }
    }

    /**
     * ReadFile reads the headers (and optionally the data) from a wave file
     *
     * @param $file1
     * @param $file2
     * @param int $threshold
     * @return   mixed  Information parsed from the file; FALSE when file doesn't exists or not readable
     * @throws \Exception
     */
    public static function compareFiles($file1, $file2, $threshold = 0)
    {
        $wav1 = self::ReadFile($file1, true);
        $wav2 = self::ReadFile($file2, true);
        if ($wav1['subchunk1']['audioformat'] != $wav2['subchunk1']['audioformat']) {
            return false;
        }

        if ($wav1['subchunk1']['numchannels'] != $wav2['subchunk1']['numchannels']) {
            return false;
        }

        if ($wav1['subchunk1']['samplerate'] != $wav2['subchunk1']['samplerate']) {
            return false;
        }

        if ($wav1['subchunk1']['byterate'] != $wav2['subchunk1']['byterate']) {
            return false;
        }

        if ($wav1['subchunk1']['bitspersample'] != $wav2['subchunk1']['bitspersample']) {
            return false;
        }



        return self::calcSimilarity($wav1['subchunk2']['data'], $wav2['subchunk2']['data'], $threshold);
    }

    /**
     * ReadFile reads the headers (and optionally the data) from a wave file
     *
     * @param    string $filename The wave file to read the header from
     * @param    bool $readdata (OPTIONAL) Pass TRUE to read the actual audio data from the file; defaults to FALSE
     *
     * @return   mixed               Information parsed from the file; FALSE when file doesn't exists or not readable
     * @throws \Exception
     */
    public static function readFile($filename, $readdata = false)
    {
        //Make sure file is readable and exists
        if (is_readable($filename)) {
            $filesize = filesize($filename);

            //Make sure filesize is sane; e.g. at least the headers should be able to fit in it
            if ($filesize < self::$HEADER_LENGTH) {
                return false;
            }
            //Read the header and stuff all info in an array
            $handle = fopen($filename, 'rb');
            $riff = array(
                'chunkid'       => self::readString($handle, 4),
                'chunksize'     => self::readLong($handle),
                'format'        => self::readString($handle, 4)
            );

            $fmt = array(
                'id'            => self::readString($handle, 4),
                'size'          => self::readLong($handle),
                'audioformat'   => self::readWord($handle),
                'numchannels'   => self::readWord($handle),
                'samplerate'    => self::readLong($handle),
                'byterate'      => self::readLong($handle),
                'blockalign'    => self::readWord($handle),
                'bitspersample' => self::readWord($handle),
                'extraparamsize'=> 0,
                'extraparams'   => null
            );
            if ($fmt['audioformat'] != 1) {  //NON-PCM?
                $fmt['extraparamsize'] = self::readWord($handle);
                $fmt['extraparams'] = self::readString($handle, $fmt['extraparamsize']);
            }

            $data = array(
                'id'            => '',
                'size'          => 0,
                'data'          => null
            );
            //Realizxa a leitura das partes do arquivo até localizar os dados de audio
            while (!feof($handle) && (ftell($handle) + 12 <= $filesize)) {
                $tmpData = array(
                    'id'            => self::readString($handle, 4),
                    'size'          => self::readLong($handle),
                    'data'          => null
                );

                if (empty($tmpData['id']) || ($tmpData['size'] == null ) || ($tmpData['size'] == 0 )) {
                    break;
                }

                if ($readdata && ($tmpData['id'] == 'data')) {
                    $tmpData['data'] = fread($handle, $tmpData['size']);

                    //$tmpData['data'] = unpack('c', fread($handle, $tmpData['size']));

                    $data = $tmpData;

                    break;
                } else {
                    //Realiz a leitura para mudar o cursor no arquivo
                    fread($handle, $tmpData['size']);
                }

                if ($tmpData['id'] == 'data') {
                    $data = $tmpData;
                }
            }

            //...aaaaand we're done!
            fclose($handle);

            //Do some checks...
            if (($fmt['numchannels'] *  $fmt['samplerate'] * floor($fmt['bitspersample'] / 8)) != $fmt['byterate']) {
                throw new \Exception('Byterate field mismatch');
            }
            if (($fmt['numchannels'] *  floor($fmt['bitspersample'] / 8)) != $fmt['blockalign']) {
                throw new \Exception('Blockalign field mismatch');
            }

            if ($riff['chunkid'] != 'RIFF') {
                throw new \Exception('RIFF chunk ID invalid');
            }
            if ($fmt['id'] != 'fmt ') {
                throw new \Exception('SubChunk1 chunk ID invalid');
            }

            if ($data['id'] != 'data') {
                throw new \Exception('SubChunk2 chunk ID invalid');
            }
            return array('header' => $riff, 'subchunk1' => $fmt, 'subchunk2' => $data);
        }
        //File is not readable or doesn't exist
        return false;
    }

    /**
     * Returns a string representation of an audioformat "id"
     *
     * @param    int     $audiofmtid The audioformat "id" to get the string representation of
     *
     * @return   string              The audioformat as a string (e.g. "PCM", "ALAW", "ULAW", ...), NULL when an
     *                               unknown audioformat "id" is passed
     */
    public static function getAudioFormat($audiofmtid)
    {
        switch ($audiofmtid) {
            case 1:
                return 'PCM';
            case 2:
                return 'Microsoft ADPCM';
            case 6:
                return 'ITU G.711 a-law';
            case 7:
                return 'ITU G.711 µ-law';
            case 17:
                return 'IMA ADPCM';
            case 20:
                return 'ITU G.723 ADPCM (Yamaha)';
            case 49:
                return 'GSM 6.10';
            case 64:
                return 'ITU G.721 ADPCM';
            case 80:
                return 'MPEG';
            case 65536:
                return 'Experimental';
            default:
                return null;
        }
    }

    /**
     * Reads a string from the specified file handle
     *
     * @param    int     $handle     The filehandle to read the string from
     * @param    int     $length     The number of bytes to read
     *
     * @return   string              The string read from the file
     */
    private static function readString($handle, $length)
    {
        return self::readUnpacked($handle, 'a*', $length);
    }
    /**
     * Reads a 32bit unsigned integer from the specified file handle
     *
     * @param    int     $handle     The filehandle to read the 32bit unsigned integer from
     *
     * @return   int                 The 32bit unsigned integer read from the file
     */
    private static function readLong($handle)
    {
        return self::readUnpacked($handle, 'V', 4);
    }
    /**
     * Reads a 16bit unsigned integer from the specified file handle
     *
     * @param    int     $handle     The filehandle to read the 16bit unsigned integer from
     *
     * @return   int                 The 16bit unsigned integer read from the file
     */
    private static function readWord($handle)
    {
        return self::readUnpacked($handle, 'v', 2);
    }

    /**
     * Reads the specified number of bytes from a specified file handle and unpacks it accoring to the specified type
     *
     * @param    int     $handle     The filterable to read the data from
     * @param    int     $type       The type of data being read (see PHP's Pack() documentation)
     * @param    int     $length     The number of bytes to read
     *
     * @return   mixed               The unpacked data read from the file
     */
    private static function readUnpacked($handle, $type, $length)
    {
        $r = unpack($type, fread($length, $handle));
        return array_pop($r);
    }

    /**
     * @param $a
     * @param $b
     * @param int $threshold
     * @return float
     */
    private static function calcSimilarity($a, $b, $threshold = 0)
    {
        $str1 = $a;
        $str2 = $b;

        $len1 = strlen($str1);
        $len2 = strlen($str2);

        $max = max($len1, $len2);
        $similarity = $i = $j = 0;

        while (($i < $len1) && isset($str2[$j])) {
            if (($str1[$i] >= $str2[$j] - $threshold) && ($str1[$i] <= $str2[$j] + $threshold)) {
                $similarity++;
                $i++;
                $j++;
            } elseif ($len1 < $len2) {
                $len1++;
                $j++;
            } elseif ($len1 > $len2) {
                $i++;
                $len1--;
            } else {
                $i++;
                $j++;
            }
        }

        return round(($similarity / $max) * 100, 2);
    }

    /**
     * @param $lValue
     * @param bool $short
     * @return array
     */
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

        if ($short) {
            $tmp = array_slice($b, 0, 2);
            $b = $tmp;
        }

        return $b;
    }
}