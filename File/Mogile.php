<?php

/**
 * API for MogileFS
 *
 * This API for MogileFS is derived from code written for Mediawiki by
 * Domas Mituzas and Jens Frank.  That work is used here by permission.
 *
 * PHP version 5.1.0+
 *
 * LICENSE: This source file is subject to the New BSD license that is 
 * available through the world-wide-web at the following URI:
 * http://www.opensource.org/licenses/bsd-license.php
 *
 * @category  File
 * @package   File_Mogile
 * @author    Steve Williams <sbw@sbw.org>
 * @copyright 2007 Digg.com, Inc.
 * @license   http://www.opensource.org/licenses/bsd-license.php BSD
 * @version   CVS: $Id:$
 * @link      http://pear.php.net/package/File_Mogile
 */ 

require_once 'Validate.php';
require_once 'File/Mogile/Exception.php';

/**
 * An interface for accessing MogileFS.
 *
 * MogileFS is an open source distributed filesystem. MogileFS can be 
 * configured to provide high capacity, high availability, or a 
 * combination of both, as required by various classes of files.
 *
 * @category File
 * @package  File_Mogile
 * @author   Steve Williams <sbw@sbw.org>
 * @license  http://www.opensource.org/licenses/bsd-license.php BSD
 * @link     http://pear.php.net/package/File_Mogile
 */
class File_Mogile
{
    /**
     * Timeout for establishing socket connection to tracker.
     *
     * @var float $socketTimeout Timeout in seconds, default 0.01.
     */
    public static $socketTimeout = 0.01;

    /**
     * Timeout for each stream read from tracker.
     * Currently, this timeout is deactivated.  See below.
     *
     * @var float $streamTimeout Timeout in seconds, default 1.0.
     */
    public static $streamTimeout = 1.0;

    /**
     * Timeout for commands to MogileFS.
     * Currently used for CURL_TIMEOUT.
     *
     * @var int $commandTimeout Timeout in seconds, default 4.
     */
    public static $commandTimeout = 4;

    /**
     * The Mogile domain, or null if none.
     *
     * @var string $domain
     */
    private $_domain = null;

    /**
     * The socket connection to the tracker.
     *
     * @var resource $socket
     */
    private $_socket = false;

    /**
     * Constructor.
     *
     * @param array  $hosts   Array of trackers as 'hostname:port'
     * @param string $domain  The mogile domain.
     * @param array  $options Optional.  Array of option values.
     *
     * @throws File_Mogile_Exception
     */
    public function __construct(array $hosts, $domain = null, array $options = null)
    {
        if (is_array($options)) {
            foreach ($options as $name => $value) {
                switch ($name) {
                case 'socketTimeout':
                    self::$socketTimeout = floatval($value);
                    break;
                case 'streamTimeout':
                    self::$streamTimeout = floatval($value);
                    break;
                case 'commandTimeout':
                    self::$commandTimeout = intval($value);
                    break;
                default:
                    throw new File_Mogile_Exception('Unrecognized option');
                }
            }
        }

        $this->_domain = $domain;

        shuffle($hosts);
        foreach ($hosts as $host) {
            $host = explode(':', $host, 2);
            $ip   = reset($host);
            $port = next($host);
            $port = (false === $port) ? 7001 : $port;

            $this->socketConnect($ip,
                                 $port,
                                 $errorNumber,
                                 $error);

            if ($this->_socket) {
                break;
            }
        }

        if (!$this->_socket) {
            throw new File_Mogile_Exception(
                'Unable to connect to tracker: ' . $errorNumber . ', ' . $error
            );
        }
    }

    /**
     * Make a socket connection via fsockopen.  Abstracted for testing.
     * 
     * @param mixed $ip           Tracker IP address
     * @param mixed $port         Tracker Port number
     * @param mixed &$errorNumber Error Number Variable
     * @param mixed &$error       Error Message Variable
     * 
     * @return void
     */
    protected function socketConnect($ip, $port, &$errorNumber, &$error)
    {
        $this->_socket = fsockopen($ip, $port, $errorNumber, $error,
                                   self::$socketTimeout);
    }

    /**
     * Write a command to the socket.  Abstracted for testing.
     * 
     * @param string $command Command, which should include a trailing \n
     * 
     * @return mixed return of fwrite()
     */
    protected function socketWrite($command)
    {
        return fwrite($this->_socket, $command);
    }

    /**
     * Read from $this->_socket() via fgets().  Abstracted
     * for testing.
     * 
     * @return void
     */
    protected function socketRead()
    {
        return fgets($this->_socket);
    }

    /**
     * Issue Mogile command, return response.
     *
     * @param string $command Mogile command string.
     * @param array  $args    Arguments to mogile command.
     *
     * @return array Response values from mogile.
     * @throws File_Mogile_Exception
     */
    private function _request($command, array $args = null)
    {
        $response = false;

        $params = array();

        if ($this->_domain) {
            $params[] = 'domain=' . urlencode($this->_domain);
        }

        if ($args) {
            foreach ($args as $key => $value) {
                $params[] = urlencode($key) . '=' . urlencode($value);
            }
        }

        if ($params) {
            $command .= ' ' . implode('&', $params);
        }

        if (false === $this->socketWrite($command . "\n")) {
            throw new File_Mogile_Exception('Error writing command');
        }

        $line = $this->socketRead();
        if (false === $line) {
            throw new File_Mogile_Exception('Error reading response');
        }

        $words = explode(' ', $line);

        if ($words[0] != 'OK') {
            throw new File_Mogile_Exception('mogilefs: ' . trim($line));
        }

        parse_str(trim($words[1]), $response);

        return $response;
    }

    /**
     * Get domains.
     *
     * @return array array('domain'=>array('class'=>N, ...), ...)
     * @throws File_Mogile_Exception
     */
    public function getDomains()
    {
        $domains = array();

        $response = $this->_request('GET_DOMAINS');
        foreach (range(1, $response['domains']) as $i) {
            $domain  = 'domain' . $i;
            $classes = array();
            foreach (range(1, $response[$domain . 'classes']) as $j) {
                $class                               = $domain . 'class' . $j;
                $classes[$response[$class . 'name']] =
                    $response[$class . 'mindevcount'];
            }
            $domains[$response[$domain]] = $classes;
        }

        return $domains;
    }

    /**
     * Get paths for key.
     *
     * @param string $key Mogile key.
     *
     * @return array paths
     * @throws File_Mogile_Exception
     */
    public function getPaths($key)
    {
        $response = $this->_request('GET_PATHS', array('key' => $key));
        unset($response['paths']);
        return $response;
    }

    /**
     * Delete by key.
     *
     * @param string $key Mogile key.
     *
     * @return void
     * @throws File_Mogile_Exception
     */
    public function delete($key)
    {
        $this->_request('DELETE', array('key' => $key));
    }

    /**
     * Rename by key.
     *
     * @param string $from The key of the file to be renamed.
     * @param string $to   The file's new key.
     *
     * @return void
     * @throws File_Mogile_Exception
     */
    public function rename($from, $to)
    {
        $this->_request('RENAME', array('from_key' => $from, 'to_key' => $to));
    }

    /**
     * List key.
     *
     * @param string $prefix List only keys matching this prefix.
     * @param string $after  The first key returned is the one after this,
     *                       or the first if null.
     * @param int    $limit  The maximum number of keys returned.
     *
     * @return array array({next after}, array({keys}))
     * @throws File_Mogile_Exception
     */
    public function listKeys($prefix = '', $after = null, $limit = 1000)
    {
        $keys = array();

        $args = array('prefix' => $prefix, 'limit' => $limit);
        if ($after) {
            $args['after'] = $after;
        }
        $response = $this->_request('LIST_KEYS', $args);

        if (!isset($response['key_count'])) {
            throw new File_Mogile_Exception('Unrecognized mogilefs response');
        }

        if (!isset($response['next_after'])) {
            throw new File_Mogile_Exception('Unrecognized mogilefs response');
        }

        foreach (range(1, $response['key_count']) as $i) {
            $index = 'key_' . $i;
            if (!isset($response[$index])) {
                throw new File_Mogile_Exception('Unrecognized mogilefs response');
            }
            $keys[] = $response[$index];
        }

        return array($response['next_after'], $keys);
    }

    /**
     * Reproxy by key or URL list.
     *
     * Sends headers and exits on success.
     * If the argument is a string, it is taken to be a key.
     * If the argument is an array, it is taken to be the paths (URLs).
     *
     * @param mixed $destination The mogile key or array of URLs.
     *
     * @return void
     */
    public function reproxy($destination)
    {
        if (is_array($destination)) {
            $paths = $destination;
        } elseif (is_string($destination)) {
            $paths = $this->getPaths($destination);
        } else {
            throw new File_Mogile_Exception('Invalid argument');
        }
        header('X-Reproxy-URL: ' . implode(' ', $paths));
        exit;
    }

    /**
     * Get data by key.
     *
     * @param string $key The mogile key.
     *
     * @return string data
     * @throws File_Mogile_Exception
     */
    public function getFileData($key)
    {
        $data = false;

        foreach ($this->getPaths($key) as $path) {
            $fh = fopen($path, 'rb');
            if ($fh) {
                $data = '';
                while (!feof($fh)) {
                    $data .= fread($fh, 8192);
                }
                fclose($fh);
                break;
            }
        }

        if (false === $data) {
            throw new File_Mogile_Exception('Unable to open paths');
        }

        return $data;
    }

    /**
     * Stream data by key or URL list.
     *
     * @param mixed $destination The mogile key or array of URLs.
     *
     * @return void
     * @throws File_Mogile_Exception
     */
    public function passthru($destination)
    {
        $success = false;

        if (is_array($destination)) {
            $paths = $destination;
        } elseif (is_string($destination)) {
            $paths = $this->getPaths($destination);
        } else {
            throw new File_Mogile_Exception('Invalid argument');
        }

        foreach ($paths as $path) {
            $fh = fopen($path, 'rb');
            if ($fh) {
                $success = (false !== fpassthru($fh));
                fclose($fh);

                if (!$success) {
                    throw new File_Mogile_Exception('Passthru failed');
                }

                break;
            }
        }

        if (!$success) {
            throw new File_Mogile_Exception('Unable to open paths for passthru');
        }
    }

    /**
     * Stream data by key.
     *
     * @param string $key The mogile key.
     *
     * @return void
     * @throws File_Mogile_Exception
     */
    public function passthruFileData($key)
    {
        if (!is_string($key)) {
            throw new File_Mogile_Exception('Invalid argument');
        }
        return $this->passthru($this->getPaths($key));
    }

    /**
     * Write a file or variable to Mogile.
     *
     * @param string $key    The mogile key of the new file.
     * @param string $class  The mogile class of the new file.
     * @param string $data   The data or file system path of the file to be stored.
     * @param bool   $isFile True if $data is a file path, or false if data.
     *
     * @return void
     * @throws File_Mogile_Exception
     */
    private function _store($key, $class, $data, $isFile)
    {
        $response = $this->_request('CREATE_OPEN',
                                    array('key' => $key, 'class' => $class));
        if (!Validate::uri($response['path'])) {
            throw new File_Mogile_Exception('Unrecognized response from mogile');
        }

        if ($isFile) {
            $fin = fopen($data, 'rb');
            if (!$fin) {
                throw new File_Mogile_Exception('Unable to open "' . $data . '"');
            }
            $fsize = filesize($data);
        } else {
            $fin = fopen('php://temp', 'r+b');
            fwrite($fin, $data);
            rewind($fin);
            $fsize = mb_strlen($data, '8bit');
        }

        $ch = curl_init();
        curl_setopt_array($ch,
                          array(CURLOPT_PUT => true,
                                CURLOPT_URL => $response['path'],
                                CURLOPT_VERBOSE => 0,
                                CURLOPT_INFILE => $fin,
                                CURLOPT_INFILESIZE => $fsize,
                                CURLOPT_TIMEOUT => self::$commandTimeout,
                                CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_HTTPHEADER => array('Expect: '),
                               ));

        $curlResult = $this->curlExec($ch);

        fclose($fin);

        if (!$curlResult) {
            $message = 'CURL: ' . curl_error($ch);
            curl_close($ch);
            throw new File_Mogile_Exception($message);
        }

        curl_close($ch);

        $this->_request('CREATE_CLOSE',
                        array(
                              'key' => $key,
                              'class' => $class,
                              'devid' => $response['devid'],
                              'fid' => $response['fid'],
                              'path' => urldecode($response['path']),
                             ));
    }

    /**
     * curlExec 
     * 
     * @param resource $ch Curl resource created via curl_init()
     * 
     * @return mixed result of curl_exec()
     */
    protected function curlExec($ch)
    {
        return curl_exec($ch);
    }

    /**
     * Write a file to Mogile.
     *
     * @param string $key      The mogile key of the new file.
     * @param string $class    The mogile class of the new file.
     * @param string $filename The native file system path of the file to be
     *                         stored.
     *
     * @return void
     * @throws File_Mogile_Exception
     */
    public function storeFile($key, $class, $filename)
    {
        $this->_store($key, $class, $filename, true);
    }

    /**
     * Write a variable to Mogile.
     *
     * @param string $key   The mogile key of the new file.
     * @param string $class The mogile class of the new file.
     * @param string $data  The data to be stored.
     *
     * @return void
     * @throws File_Mogile_Exception
     */
    public function storeData($key, $class, $data)
    {
        $this->_store($key, $class, $data, false);
    }

    /**
     * Destructor, closes socket.
     *
     * @return void
     */
    public function __destruct()
    {
        if ($this->_socket !== false) {
            fclose($this->_socket);
        }
    }
}

?>
