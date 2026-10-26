<?php

/** Test the real wrapper/autoloader/POST bridge without loading a CMS or DB. */

$mode = $argv[1] ?? 'default';
if ($mode === 'trusted') {
    define('DF_LIKE_TRUSTED_PROXIES', ['10.0.0.10', '2001:db8:abcd::/48']);
} elseif ($mode === 'invalid-type') {
    define('DF_LIKE_TRUSTED_PROXIES', '10.0.0.10');
} elseif ($mode === 'invalid-entry') {
    define('DF_LIKE_TRUSTED_PROXIES', ['10.0.0.10', 'bad']);
} elseif ($mode !== 'default') {
    throw new RuntimeException('Unknown test mode: ' . $mode);
}

// Only the CMS parent is replaced. Both the wrapper and plugin class are real.
class ACMS_POST
{
}
require_once dirname(__DIR__) . '/template/post/DFLikeToggle.php';

$post = new Acms\Custom\POST\DFLikeToggle();
$method = new ReflectionMethod(Acms\Plugins\DF_Like\POST\DFLikeToggle::class, 'clientIp');
$method->setAccessible(true);
$cases = [
    ['10.0.0.10', '198.51.100.8', $mode === 'trusted' ? '198.51.100.8' : '10.0.0.10'],
    ['198.51.100.8', '203.0.113.99', '198.51.100.8'],
    ['2001:db8:abcd::2', '198.51.100.8', $mode === 'trusted' ? '198.51.100.8' : '2001:db8:abcd::2'],
];
foreach ($cases as $case) {
    $_SERVER = ['REMOTE_ADDR' => $case[0], 'HTTP_X_FORWARDED_FOR' => $case[1]];
    if ($method->invoke($post) !== $case[2]) {
        throw new RuntimeException('POST clientIp bridge failed: ' . $mode);
    }
}
echo 'OK: wrapper/POST configuration (' . $mode . ", 3 checks)\n";
