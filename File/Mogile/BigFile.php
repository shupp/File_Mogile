<?php

/**
 * File_Mogile_BigFile 
 *
 * PHP version 5.1.0+
 *
 * LICENSE: This source file is subject to the New BSD license that is 
 * available through the world-wide-web at the following URI:
 * http://www.opensource.org/licenses/bsd-license.php. If you did not receive  
 * a copy of the New BSD License and are unable to obtain it through the web, 
 * please send a note to license@php.net so we can mail you a copy immediately.
 *
 * @category  File
 * @package   File_Mogile
 * @author    Chris Lea <chl@chrislea.com>
 * @copyright 2008 Chris Lea
 * @license   http://www.opensource.org/licenses/bsd-license.php BSD
 * @link      http://pear.php.net/package/File_Mogile
 */ 

require_once 'File/Mogile.php';

/**
 * Extended API for MogileFS
 *
 * This extends the Digg MogileFS API to handle big files.
 * 
 * @category  File
 * @uses      File_Mogile
 * @package   File_Mogile
 * @author    Chris Lea <chl@chrislea.com>
 * @copyright 2008 Chris Lea
 * @license   http://www.opensource.org/licenses/bsd-license.php BSD
 * @link      http://pear.php.net/package/File_Mogile
 */
class File_Mogile_BigFile extends File_Mogile
{
    /**
     * Integer value for what is considered 'big' in Megs
     *
     * @var int $bigsize integer value indicating how many megs is a big file
     */
    private $_bigsize = 64;

    /**
     * Integer value for the chunksize
     *
     * @var int $chunksize int value indicating the chunksize in megs
     */
    private $_chunksize = 64;

    /**
     * Integer value for time to wait for replication
     *
     * @var int $replication_wait value in seconds to wait for bigfile replication 
     *                            to finish
     */
    private $_replication_wait = 30;

    /**
     * __construct 
     * 
     * @param array  $hosts   Array of trackers as 'hostname:port'
     * @param string $domain  The mogile domain.
     * @param array  $options Optional.  Array of option values.
     * 
     * @access public
     * @return void
     */
    public function __construct(array $hosts, $domain = null, array $options = null)
    {
        // make sure chunksize is smaller than memory_limit
        $this->checkChunkSize($this->_chunksize);

        parent::__construct($hosts, $domain, $options);
    }

    /**
     * Nipped from the PHP website with the good [] used, gives us a value in
     * bytes
     *
     * @param string $size value like 32M or 1G
     *
     * @return int size in bytes
     */
    protected function getBytes($size)
    {
        $size = trim($size);
        $last = mb_strtolower($size[strlen($size)-1]);
        switch($last) {
        case 'g':
            $size *= 1024;
        case 'm':
            $size *= 1024;
        case 'k':
            $size *= 1024;
        }

        return $size;
    }

    /**
     * Sets the value for what is considered "big"
     *
     * @param int $bigsize Size in megs considered big
     *
     * @throws File_Mogile_Exception
     * @return void
     */
    public function setBigFileSize($bigsize)
    {
        if (false === is_int($bigsize)) {
            throw new File_Mogile_Exception(
                "Non integer value passed to setBigFileSize()."
            );
        }

        if ($bigsize < 64) {
            throw new File_Mogile_Exception(
                "Bigsize can't be smaller than 64M."
            );
        }
        $this->_bigsize = $bigsize;
    }

    /**
     * Sets the chunksize
     *
     * @param int $chunkSize Chunk Size in megs
     *
     * @throws File_Mogile_Exception
     * @return void
     */
    public function setChunkSize($chunkSize)
    {
        $this->checkChunkSize($chunkSize);
        $this->_chunksize = $chunkSize;
    }

    /**
     * Consilidated chunk size checking
     * 
     * @param int $chunkSize Chunk Size in MB
     *
     * @throws File_Mogile_Exception on failure
     * @return bool true on success
     */
    protected function checkChunkSize($chunkSize)
    {
        if (false === is_int($chunkSize)) {
            throw new File_Mogile_Exception(
                'Non integer value passed to checkChunkSize().'
            );
        }

        if ($this->_bigsize < $chunkSize) {
            throw new File_Mogile_Exception(
                'Bigsize must be larger than chunksize.'
            );
        }

        // make sure chunksize is smaller than memory_limit
        $chunkSize   = $this->getBytes($chunkSize . 'M');
        $memoryLimit = $this->getBytes(ini_get('memory_limit'));
        if ($chunkSize > $memoryLimit) {
            throw new File_Mogile_Exception(
                "Chunksize can't be bigger than the PHP memory_limit."
            );
        }

        return true;
    }

    /**
     * Sets value in seconds to wait for replication to finish
     *
     * @param int $replication_wait Time to wait in seconds
     *
     * @throws File_Mogile_Exception
     * @return void
     */
    public function setReplicationWait($replication_wait = 30)
    {
        if (!is_int($replication_wait)) {
            throw new File_Mogile_Exception(
                "Non integer value passed to setReplicationWait()."
            );
        }
        $this->_replication_wait = $replication_wait;
    }

    /**
     * Parse description of data chunks for big file
     *
     * @param string $key Key for the BigFile
     *
     * @return array data
     */

    public function parseBigFileData($key)
    {

        // I'm assuming they're never going to change the formatting of the 
        // descriptor file too much, this indicates where the break is that 
        // signifies the start of the path / chunk info section

        // XXX: if the format of this file changes, this method will need to be 
        // redone or something to make it still work
        $PATH_INFO_START = 7;

        $datafile = array();
        $parsed   = array();
        $datafile = explode("\n", $this->getFileData("_big_info:$key"));

        for ($i=0 ; $i < ($PATH_INFO_START - 1) ; ++$i) {
            $break = mb_strpos($datafile[$i], " ");
            $key   = substr($datafile[$i], 0, $break);
            $value = substr($datafile[$i], ($break + 1), mb_strlen($datafile[$i]));

            $parsed[$key] = $value;
        }

        for ($i=$PATH_INFO_START ; $i < (count($datafile) - 1); ++$i) {
            $start = $end = 0;

            $linedata = substr($datafile[$i],
                               $PATH_INFO_START,
                               (strlen($datafile[$i]) - $PATH_INFO_START));

            // this gets the bytes out
            $start = (strpos($linedata, "=", $end) + 1);
            $end   = (strpos($linedata, " ", $start) + 1);

            $parsed["chunk".($i-$PATH_INFO_START)]["bytes"] = 
                substr($linedata, $start, ($end-$start));

            // this gets the md5 out
            $start = (strpos($linedata, "=", $end) + 1);
            $end   = (strpos($linedata, " ", $start) + 1);

            $parsed["chunk".($i-$PATH_INFO_START)]["md5"] = 
                substr($linedata, $start, ($end-$start));

            // somehow, this is even *more* annoying...
            $start = (strpos($linedata, " ", $end) + 1);
            $end   = mb_strlen($linedata);
            $path  = explode(", ", substr($linedata, $start, ($end-$start)));
            for ($j=0 ; $j < count($path); ++$j) {
                $parsed["chunk".($i-$PATH_INFO_START)]["path"][] = $path[$j];
            }
        }

        return $parsed;
    }

    /**
     * Get BigFile and write to filesystem
     *
     * @param string $key  Key for the BigFile
     * @param string $path Path to write to on filesystem
     *
     * @throws File_Mogile_Exception
     * @return string path to file written out
     */
    public function getFile($key, $path = '.')
    {
        $filedata = $this->parseBigFileData($key);
        $filename = $filedata['filename'];
        $chunks   = $filedata['chunks'];

        if (strcmp($path[strlen($path) - 1], '/')) {
            $path .= '/';
        }
 
        $write_fh = fopen($path . $filename, 'wb');
        if (false === $write_fh) {
            throw new File_Mogile_Exception(
                "Could not open $path$filename for writing."
            );
        }

        // make sure it looks like there are enough chunks
        if (false === isset($filedata['chunk' . ($chunks - 1)])) {
            throw new File_Mogile_Exception(
                "Missing chunks for restoring $filename."
            );
        }

        // loop through paths and get the chunks
        for ($i=0 ; $i < $chunks ; ++$i) {
            $length = $filedata["chunk$i"]['bytes'];

            // make sure length is smaller than memory_limit
            if ($length > $this->getBytes(ini_get('memory_limit'))) {
                throw new File_Mogile_Exception(
                    "Chunk $i is bigger than the PHP memory_limit."
                );
            }

            $md5sum         = $filedata["chunk$i"]['md5'];
            $get_from_index = array_rand($filedata["chunk$i"]['path'], 1);
            $get_from       = $filedata["chunk$i"]['path'][$get_from_index];

            $fh = fopen($get_from, 'rb');
            if (false === $fh) {
                throw new File_Mogile_Exception(
                    "Could not get file handle for $get_from.\n"
                );
            }
            unset($data);
            while (!feof($fh)) {
                $data .= fread($fh, 8192);
            }
            fclose($fh);

            // check md5
            if (!strcasecmp($md5sum, md5($data))) {
                throw new File_Mogile_Exception(
                    'Mismatched md5 sum on chunk retreival.'
                );
            }

            fwrite($write_fh, $data, $length);

        }
        fclose($write_fh);

        return $path . $filename;
    }

    /**
     * Store BigFile to Mogile
     *
     * @param string $key   The mogile key of the new BigFile.
     * @param string $class The mogile class of the new BigFile.
     * @param string $file  The native filesystem path of the BigFile
     *                      to be stored.
     *
     * @throws File_Mogile_Exception
     * @return void
     */

    public function storeFile($key, $class, $file)
    {
        $chunksize    = $this->_chunksize * 1024 * 1024;
        $currentchunk = 1;

        // if file's not here or too small, get out
        if (false === is_readable($file)) {
            throw new File_Mogile_Exception(
                "File $file is not readable for injection."
            );
        }

        $filesize = filesize($file);
        if ($filesize <= ($this->_bigsize * 1024 * 1024)) {
            throw new File_Mogile_Exception(
                "File $file is too small to be stored as a BigFile."
            );
        }

        // I'm basically just doing a somewhat condensed version of
        // mogtool does here...

        // write a pre notice file
        $pre_data = 'starttime: ' . time();
        $this->storeData("_big_pre:$key", $class, $pre_data);

        $fh = fopen($file, 'rb');
        if (false === $fh) {
            throw new File_Mogile_Exception(
                "Could not get filehandle to read $file."
            );
        }

        // this is the heart of it. while we still have data, read off chunks up
        // to chunksize and send them to mogile
        while (!feof($fh)) {
            $data       = fread($fh, $chunksize);
            $chunkbytes = mb_strlen($data);
            $md5sum     = md5($data);
            $this->storeData("$key,$currentchunk", $class, $data);

            unset($data);

            $biginfo[$currentchunk]['md5']   = $md5sum;
            $biginfo[$currentchunk]['bytes'] = $chunkbytes;
            ++$currentchunk;
        }

        // now we have to write out the info file
        $info_file .= "des no description\n";
        $info_file .= "type file\n";
        $info_file .= "compressed 0\n";
        $info_file .= "filename " . basename($file) . "\n";
        $info_file .= "chunks " . ($currentchunk - 1) . "\n";
        $info_file .= "size $filesize\n";
        $info_file .= "\n";

        for ($i=1 ; $i <= count($biginfo); ++$i) {
            for ($j=0 ; $j < $this->_replication_wait ; ++$j) {
                $paths_array = $this->getPaths("$key,$i");
                if (count($paths_array) >= 2) {
                    // it's replicated
                    break;
                }
                sleep(1);
            }
            if ($this->_replication_wait == $j) {
                throw new File_Mogile_Exception(
                    "Chunk $i did not replicate, we waited " .
                    $this->_replication_wait . " seconds...\n"
                );
            }
            $paths      = implode(', ', $paths_array);
            $info_file .= "part $i bytes={$biginfo[$i]["bytes"]} ";
            $info_file .= "md5={$biginfo[$i]["md5"]} paths: $paths\n";
        }

        // put the _big_info file in
        $this->storeData("_big_info:$key", $class, $info_file);

        // and get rid of the _big_pre
        $this->delete("_big_pre:$key");
    }
}

?>
