<?php

/**
 * API for MogileFS
 *
 * This API for MogileFS is derived from code written for Mediawiki by
 * Domas Mituzas and Jens Frank.  That work is used here by permission.
 *
 * PHP version 5.2.0+
 *
 * LICENSE: This source file is subject to the New BSD license that is 
 * available through the world-wide-web at the following URI:
 * http://www.opensource.org/licenses/bsd-license.php
 *
 * @category  File
 * @package   File_Mogile
 * @author    Steve Williams <sbw@sbw.org>
 * @author    Bill Shupp <hostmaster@shupp.org>
 * @copyright 2010 Digg.com, Inc.
 * @license   http://www.opensource.org/licenses/bsd-license.php BSD
 * @version   CVS: $Id:$
 * @link      http://pear.php.net/package/File_Mogile
 */ 

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
 * @author   Bill Shupp <hostmaster@shupp.org>
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
     * Timeout for each stream read from tracker.  This is combined with
     * {$streamTimeoutMicroSeconds}
     *
     * @see stream_set_timeout(), $streamTimeoutMicroSeconds
     * @var int $streamTimeoutSeconds Stream timeout in seconds, default 1
     */
    public static $streamTimeoutSeconds = 1;

    /**
     * Timeout for each stream read from tracker in microseconds.
     * This is combined with {$streamTimeoutSeconds}
     *
     * @see stream_set_timeout(), $streamTimeoutSeconds
     * @var int $streamTimeoutMicroSeconds Stream timeout in microseconds, default 0
     */
    public static $streamTimeoutMicroSeconds = 0;

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
     * Tracker hosts.
     * 
     * @var array
     */
    protected $hosts = array();

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
                case 'streamTimeoutSeconds':
                    self::$streamTimeoutSeconds = (int) $value;
                    break;
                case 'streamTimeoutMicroSeconds':
                    self::$streamTimeoutMicroSeconds = (int) $value;
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
        $this->hosts = $hosts;

        $this->connect();
    }

    /**
     * Make a connection to a random host
     * 
     * @throws File_Mogile_Exception if no tracker connections succeed
     * @return void
     */
    public function connect()
    {
        if ($this->_socket) {
            return;
        }

        foreach ($this->hosts as $host) {
            $host = explode(':', $host, 2);
            $ip   = reset($host);
            $port = next($host);
            $port = (false === $port) ? 7001 : $port;

            $this->_socket = $this->socketConnect($ip, $port, $errorNumber, $error);

            if ($this->_socket) {
                // Set stream timeout
                stream_set_timeout($this->_socket,
                                   self::$streamTimeoutSeconds,
                                   self::$streamTimeoutMicroSeconds);
                return;
            }
        }

        if (!$this->_socket) {
            throw new File_Mogile_Exception(
                'Unable to connect to tracker: ' . $errorNumber . ', ' . $error
            );
        }
    }

    //@codeCoverageIgnoreStart
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
        return fsockopen($ip, $port, $errorNumber, $error,
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
        $result = fgets($this->_socket);
        $info   = stream_get_meta_data($this->_socket);
        if (!empty($info['timed_out'])) {
            throw new File_Mogile_Exception('Socket read timed out');
        }
        return $result;
    }

    /**
     * Close the local socket if it's open
     * 
     * @return void
     */
    protected function socketClose()
    {
        if ($this->_socket !== false) {
            fclose($this->_socket);
        }
    }
    //@codeCoverageIgnoreEnd

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
     * @throws File_Mogile_Exception on invalid $destination
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
        $this->sendReproxyHeader($paths);
    }

    /**
     * Sends the X-Reproxy-URL header and exists.  Abstracted for testing.
     * 
     * @param array $paths Array of paths
     * 
     * @return void
     */
    protected function sendReproxyHeader(array $paths)
    {
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
        if (!filter_var($response['path'], FILTER_VALIDATE_URL)) {
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

        $options = array(CURLOPT_PUT            => true,
                         CURLOPT_URL            => $response['path'],
                         CURLOPT_VERBOSE        => 0,
                         CURLOPT_INFILE         => $fin,
                         CURLOPT_INFILESIZE     => $fsize,
                         CURLOPT_TIMEOUT        => self::$commandTimeout,
                         CURLOPT_RETURNTRANSFER => false,
                         CURLOPT_HTTPHEADER     => array('Expect: '));

        $ch = curl_init();
        curl_setopt_array($ch, $options);
        $curlResult = $this->curlExec($ch);

        fclose($fin);

        if ($curlResult === false) {
            $message = 'CURL: ' . curl_error($ch);
            curl_close($ch);
            throw new File_Mogile_Exception($message);
        }

        curl_close($ch);

        $this->_request('CREATE_CLOSE',
                        array(
                              'key'   => $key,
                              'class' => $class,
                              'devid' => $response['devid'],
                              'fid'   => $response['fid'],
                              'path'  => urldecode($response['path']),
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
        ob_start();
        $result = curl_exec($ch);
        ob_end_clean();
        return $result;
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
        $this->socketClose();
    }
}

?>
