<?php

/**
 * The File_Mogile Exception
 *
 * This API for MogileFS is derived from code written for Mediawiki by
 * Domas Mituzas and Jens Frank.  That work is used here by permission.
 *
 * PHP version 5.1.0+
 *
 * LICENSE: This source file is subject to the New BSD license that is 
 * available through the world-wide-web at the following URI:
 * http://www.opensource.org/licenses/bsd-license.php.
 *
 * @category  File
 * @package   File_Mogile
 * @author    Steve Williams <sbw@sbw.org>
 * @copyright 2007 Digg.com, Inc.
 * @license   http://www.opensource.org/licenses/bsd-license.php BSD
 * @version   CVS: $Id:$
 * @link      http://pear.php.net/package/File_Mogile
 */ 

require_once 'PEAR/Exception.php';

/**
 * File_Mogile_Exception
 *
 * Exception handler for File_Mogile.
 *
 * @category File
 * @package  File_Mogile
 * @author   Steve Williams <sbw@sbw.org>
 * @license  http://www.opensource.org/licenses/bsd-license.php BSD
 * @link     http://pear.php.net/package/File_Mogile
 */
class File_Mogile_Exception extends PEAR_Exception
{

}

?>
