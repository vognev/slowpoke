<?php

class ProxyConnectionError extends \Exception {
    public function __construct() {
        parent::__construct('Proxy connection failed');
    }
};

function socks4_connect($proxy, $host, $port, $timeout = 30.0)
{
    if (! ($client = direct_connect($proxy, $timeout))) {
        throw new ProxyConnectionError;
    }

    $ip = ip2long($host);
    $data = pack('C2nNC', 0x04, 0x01, $port, $ip === false ? 1 : $ip, 0x00);
    if ($ip === false) {
        $data .= $host . pack('C', 0x00);
    }

    fwrite($client, $data);

    if (!($data = client_read($client, 8))) {
        @fclose($client); return null;
    }

    $data = unpack('Cnull/Cstatus/nport/Nhost', $data);
    if ($data['null'] !== 0x00 || $data['status'] !== 0x5a) {
        @fclose($client); return null;
    }

    return $client;
}

function socks5_connect($proxy, $host, $port, $timeout = 30.0)
{
    if (! ($client = direct_connect($proxy, $timeout))) {
        throw new ProxyConnectionError;
    }

    fwrite($client, pack("C3", 0x05, 0x01, 0x00));

    if (! ($status = client_read($client, 3))) {
        @fclose($client); return null;
    }
    if ($status != pack("C2", 0x05, 0x00) ) {
        @fclose($client); return null;
    }

    fwrite($client, pack("C5", 0x05 , 0x01 , 0x00 , 0x03, strlen($host)) . $host . pack("n", $port) );

    if (! ($status = client_read($client, 10))) {
        @fclose($client); return null;
    }
    if ($status != pack("C10", 0x05, 0x00, 0x00, 0x01, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00 )) {
        @fclose($client); return null;
    }

    return $client;
}

function direct_connect($address, $timeout = 30.0)
{
    return @stream_socket_client($address, $errnum, $errmsg, $timeout);
}

function client_read($client, $amount, $attempts = 5): ?string
{
    $data = '';

    while(strlen($data) < $amount) {
        if (! $attempts) {
            return null;
        }

        $chunk = @fread($client, $amount - strlen($data));
        if ($chunk) {
            $data .= $chunk;
        }

        $attempts--;
        sleep(1);
    }

    return $data;
}
