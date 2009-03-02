--TEST--
File_Mogile Basic Test
--FILE--
<?php

require_once 'File/Mogile.php';

$hosts = array('host1.example.com:7001', 'host2.example.com:7001');
$domain = 'myMogileDomain';
$class = 'myMogileClass';
$key = 'myMogileKey';

try {
    $mogile = new File_Mogile($hosts, $domain,
                              array(
                                    'socketTimeout' => 0.01,
                                    'streamTimeout' => 1.0,
                                    'commandTimeout' => 4,
                                   )
                             );

    $mogile->storeData($key, $class, 'I am the walrus.' . "\n");

    $paths = $mogile->getPaths($key);

    foreach ($paths as $path) {
        $text = file_get_contents($path);
        if (false !== $text) {
            break;
        }
    }

    if (false === $text) {
        throw new Exception('Unable to read from ' . count($paths) . ' paths');
    }

    echo $text;
} catch (Exception $e) {
    echo $e;
}

?>
--EXPECT--
I am the walrus.
