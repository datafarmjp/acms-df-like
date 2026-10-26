<?php

/** Standalone resolver tests: php tests/LikeClientIpTest.php (no CMS or DB). */

use Acms\Plugins\DF_Like\Bootstrap;
use Acms\Plugins\DF_Like\Services\LikeClientIp;

require_once dirname(__DIR__) . '/Bootstrap.php';
Bootstrap::registerAutoloader();

error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$checks = 0;
function check_ip(string $name, array $server, array $trusted, string $expected): void
{
    global $checks;
    $actual = LikeClientIp::resolve($server, $trusted);
    if ($actual !== $expected) {
        throw new RuntimeException($name . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
    $checks++;
}

$peer = '10.0.0.10';
$client = '198.51.100.8';
$spoof = '203.0.113.99';
$trusted = [$peer, '10.1.2.0/24', '2001:db8:abcd::/48'];

check_ip('direct IPv4', ['REMOTE_ADDR' => $client], [], $client);
check_ip('direct IPv6 canonicalized', ['REMOTE_ADDR' => '2001:0DB8:0000:0:0:0:0:1'], [], '2001:db8::1');
check_ip('unconfigured proxy', ['REMOTE_ADDR' => $peer, 'HTTP_X_FORWARDED_FOR' => $client], [], $peer);
check_ip('untrusted peer cannot spoof', ['REMOTE_ADDR' => $client, 'HTTP_X_FORWARDED_FOR' => $spoof], $trusted, $client);
check_ip('untrusted malformed header ignored', ['REMOTE_ADDR' => $client, 'HTTP_X_FORWARDED_FOR' => []], $trusted, $client);
foreach (['10.9.0.1', '172.16.0.1', '192.168.0.1', '127.0.0.1', '::1', 'fc00::1', 'fe80::1'] as $private) {
    check_ip('no automatic trust: ' . $private, ['REMOTE_ADDR' => $private, 'HTTP_X_FORWARDED_FOR' => $spoof], $trusted, $private);
}
check_ip('trusted single proxy', ['REMOTE_ADDR' => $peer, 'HTTP_X_FORWARDED_FOR' => $client], $trusted, $client);
check_ip('trusted multi proxy', ['REMOTE_ADDR' => $peer, 'HTTP_X_FORWARDED_FOR' => $client . ', 10.1.2.3'], $trusted, $client);
check_ip('spoofed leftmost before real client', ['REMOTE_ADDR' => $peer, 'HTTP_X_FORWARDED_FOR' => $spoof . ', ' . $client . ', 10.1.2.3'], $trusted, $client);
check_ip('stop at untrusted intermediary', ['REMOTE_ADDR' => $peer, 'HTTP_X_FORWARDED_FOR' => $spoof . ', 192.0.2.4, 10.1.2.3'], $trusted, '192.0.2.4');
check_ip('all listed hops trusted: leftmost', ['REMOTE_ADDR' => $peer, 'HTTP_X_FORWARDED_FOR' => '10.1.2.4, 10.1.2.3'], $trusted, '10.1.2.4');
check_ip('header optional whitespace', ['REMOTE_ADDR' => $peer, 'HTTP_X_FORWARDED_FOR' => " \t" . $client . " \t,\t10.1.2.3 "], $trusted, $client);
check_ip('IPv6 client through IPv4', ['REMOTE_ADDR' => $peer, 'HTTP_X_FORWARDED_FOR' => '2001:0DB8:0:0:0:0:0:8'], $trusted, '2001:db8::8');
check_ip('IPv4 client through IPv6', ['REMOTE_ADDR' => '2001:db8:abcd::2', 'HTTP_X_FORWARDED_FOR' => $client], $trusted, $client);
check_ip('mixed IPv6/IPv4 chain', ['REMOTE_ADDR' => '2001:db8:abcd::2', 'HTTP_X_FORWARDED_FOR' => '2001:db8::8, 10.1.2.3'], $trusted, '2001:db8::8');
check_ip('exact IPv6 normalized', ['REMOTE_ADDR' => '2001:db8:abcd::2', 'HTTP_X_FORWARDED_FOR' => $client], ['2001:0DB8:ABCD:0:0:0:0:2'], $client);
check_ip('host bits in configured CIDR', ['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_FOR' => $client], ['10.1.2.123/24'], $client);
check_ip('IPv4 CIDR excludes adjacent subnet', ['REMOTE_ADDR' => '10.1.3.0', 'HTTP_X_FORWARDED_FOR' => $client], ['10.1.2.0/24'], '10.1.3.0');
check_ip('IPv6 CIDR excludes adjacent subnet', ['REMOTE_ADDR' => '2001:db8:abce::2', 'HTTP_X_FORWARDED_FOR' => $client], $trusted, '2001:db8:abce::2');
check_ip('mapped IPv6 does not match IPv4 trust', ['REMOTE_ADDR' => '::ffff:10.0.0.10', 'HTTP_X_FORWARDED_FOR' => $client], [$peer], '::ffff:10.0.0.10');
check_ip('mapped IPv6 explicit trust', ['REMOTE_ADDR' => '::ffff:10.0.0.10', 'HTTP_X_FORWARDED_FOR' => $client], ['::ffff:10.0.0.0/120'], $client);
check_ip('other forwarding headers ignored', ['REMOTE_ADDR' => $peer, 'HTTP_FORWARDED' => 'for=' . $client, 'HTTP_X_REAL_IP' => $client, 'HTTP_CF_CONNECTING_IP' => $client], $trusted, $peer);

foreach ([null, [], 123, '', ' ', 'unknown', 'localhost', '999.1.1.1', '198.51.100.008', '198.51.100.8:123', '[2001:db8::8]', 'fe80::1%eth0', '198.51.100.8,,10.1.2.3', ',198.51.100.8', '198.51.100.8,', 'unknown,198.51.100.8', "198.51.100.8\r\nX-Test: 1", "198.51.100.8\0", str_repeat('1', 8193)] as $index => $bad) {
    check_ip('malformed XFF ' . $index, ['REMOTE_ADDR' => $peer, 'HTTP_X_FORWARDED_FOR' => $bad], $trusted, $peer);
}
check_ip('missing XFF', ['REMOTE_ADDR' => $peer], $trusted, $peer);
check_ip('64 header hops accepted', ['REMOTE_ADDR' => $peer, 'HTTP_X_FORWARDED_FOR' => $client . str_repeat(',10.1.2.3', 63)], $trusted, $client);
check_ip('65 header hops rejected', ['REMOTE_ADDR' => $peer, 'HTTP_X_FORWARDED_FOR' => $client . str_repeat(',10.1.2.3', 64)], $trusted, $peer);
check_ip('8192 bytes accepted', ['REMOTE_ADDR' => $peer, 'HTTP_X_FORWARDED_FOR' => str_repeat(' ', 8192 - strlen($client)) . $client], $trusted, $client);
check_ip('8193 bytes rejected', ['REMOTE_ADDR' => $peer, 'HTTP_X_FORWARDED_FOR' => str_repeat(' ', 8193 - strlen($client)) . $client], $trusted, $peer);
check_ip('missing REMOTE_ADDR', ['HTTP_X_FORWARDED_FOR' => $client], $trusted, '');
foreach ([null, [], 123, '', 'unknown', '10.0.0.10:80', ' 10.0.0.10', "10.0.0.10\n", 'fe80::1%en0'] as $index => $bad) {
    check_ip('invalid REMOTE_ADDR ' . $index, ['REMOTE_ADDR' => $bad, 'HTTP_X_FORWARDED_FOR' => $client], $trusted, '');
}
foreach ([null, [], 123, '', '*', 'private_ranges', 'proxy.example', '10.0.0.0/33', '::/129', '10.0.0.0/-1', '10.0.0.0/+8', '10.0.0.0/08', '10.0.0.0/8.0', '10.0.0.0/8x', '10.0.0.0/', '10.0.0.0/8/9', "10.0.0.0/8\n"] as $index => $bad) {
    check_ip('entire invalid trust list fails closed ' . $index, ['REMOTE_ADDR' => $peer, 'HTTP_X_FORWARDED_FOR' => $client], [$peer, $bad], $peer);
}
check_ip('IPv6 trust does not match IPv4', ['REMOTE_ADDR' => $peer, 'HTTP_X_FORWARDED_FOR' => $client], ['::/0'], $peer);
check_ip('IPv4 trust does not match IPv6', ['REMOTE_ADDR' => '2001:db8::2', 'HTTP_X_FORWARDED_FOR' => $client], ['0.0.0.0/0'], '2001:db8::2');

// Exercise every prefix length, including non-byte boundaries and /0, /32,
// /128. The oracle flips one known network bit, without using resolver logic.
foreach (['10.171.205.239', '2001:db8:1234:5678:9abc:def0:1234:5678'] as $base) {
    $binary = inet_pton($base);
    $width = strlen($binary) * 8;
    for ($prefix = 0; $prefix <= $width; $prefix++) {
        $cidr = [$base . '/' . $prefix];
        check_ip('CIDR identical ' . $base . '/' . $prefix, ['REMOTE_ADDR' => $base, 'HTTP_X_FORWARDED_FOR' => $client], $cidr, $client);
        if ($prefix < $width) {
            $inside = $binary;
            $last = strlen($inside) - 1;
            $inside[$last] = chr(ord($inside[$last]) ^ 1);
            check_ip('CIDR host bit ' . $base . '/' . $prefix, ['REMOTE_ADDR' => inet_ntop($inside), 'HTTP_X_FORWARDED_FOR' => $client], $cidr, $client);
        }
        if ($prefix > 0) {
            $outside = $binary;
            $bit = $prefix - 1;
            $byte = intdiv($bit, 8);
            $outside[$byte] = chr(ord($outside[$byte]) ^ (1 << (7 - $bit % 8)));
            $outsideIp = inet_ntop($outside);
            check_ip('CIDR network bit ' . $base . '/' . $prefix, ['REMOTE_ADDR' => $outsideIp, 'HTTP_X_FORWARDED_FOR' => $client], $cidr, $outsideIp);
        }
    }
}

echo 'OK: LikeClientIp (' . $checks . " checks)\n";
