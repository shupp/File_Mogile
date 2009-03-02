<?php
/**
 * File_MogileTest 
 * 
 * PHP Version 5.2.0
 * 
 * @category  File
 * @package   File_Mogile
 * @author    Bill Shupp <hostmaster@shupp.org>
 * @copyright 2009 Digg.com, Inc.
 * @license   http://www.opensource.org/licenses/bsd-license.php BSD
 * @link      http://pear.php.net/package/File_Mogile
 */

require_once 'PHPUnit/Framework.php';
require_once 'File/MogileTest.php';
 
/**
 * File_MogileTest 
 * 
 * AllTests class
 * 
 * @category  File
 * @package   File_Mogile
 * @author    Bill Shupp <hostmaster@shupp.org>
 * @copyright 2009 Digg.com, Inc.
 * @license   http://www.opensource.org/licenses/bsd-license.php BSD
 * @link      http://pear.php.net/package/File_Mogile
 */
class AllTests
{
    /**
     * Creates a suite of tests and returns them
     * 
     * @return PHPUnit_Framework_TestSuite
     */
    public static function suite()
    {
        $suite = new PHPUnit_Framework_TestSuite('All File_Mogile Tests');
        $suite->addTestSuite('File_MogileTest');
        return $suite;
    }
}
?>
