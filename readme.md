`docker build -t slowpoke .`

`docker run --rm -v $PWD/payloads:/code/payloads slowpoke php slowpoke.php 8192 ssl://127.0.0.1:443 payload.raw`
