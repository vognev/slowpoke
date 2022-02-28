<?php require_once __DIR__ . '/functions.php';

$address = $argv[1] ?? 'google.com:443';
$address = @parse_url($address);
if (! isset($address['host']) || !isset($address['port'])) {
    fwrite(STDERR, 'Unable to parse address' . PHP_EOL);
    return;
}
$host = $address['host'];
$port = $address['port'];

$proxy = null; $pool = [];
do {
    if (count($pool) < 50) {
        if ($proxy = trim(@fgets(STDIN, 8192))) {
            fwrite(STDERR, sprintf('Trying %s:%s via socks4://%s' . PHP_EOL, $host, $port, $proxy));
            switch ($pid = pcntl_fork()) {
                case  0: exit(try_socks4($host, $port, $proxy) ? 0 : 1);
                case -1: die('Fork failed');
                default:
                    $pool[$pid] = ['ip' => $proxy, 'scheme' => 'socks4'];
            }

            fwrite(STDERR, sprintf('Trying %s:%s via socks5://%s' . PHP_EOL, $host, $port, $proxy));
            switch ($pid = pcntl_fork()) {
                case  0: exit(try_socks5($host, $port, $proxy) ? 0 : 1);
                case -1: die('Fork failed');
                default:
                    $pool[$pid] = ['ip' => $proxy, 'scheme' => 'socks5'];
            }
        }
    }

    while(($pid = pcntl_wait($status, WNOHANG)) > 0) {
        if (isset($pool[$pid])) {
            if (0 === pcntl_wexitstatus($status)) {
                fwrite(STDOUT, json_encode($pool[$pid]) . PHP_EOL);
            }
            unset($pool[$pid]);
        }
    }
} while($proxy && count($pool));

fwrite(STDERR, 'Done' . PHP_EOL);

function try_socks5($host, $port, $proxy): bool
{
    try {
        $client = socks5_connect($proxy, $host, $port, 5.0);
        return true;
    } catch (\ProxyConnectionError) {
        return false;
    } finally {
        isset($client) && @fclose($client);
    }
}

function try_socks4($host, $port, $proxy): bool
{
    try {
        $client = socks4_connect($proxy, $host, $port, 5.0);
        return true;
    } catch (ProxyConnectionError) {
        return false;
    } finally {
        isset($client) && @fclose($client);
    }
}
