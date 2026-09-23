# NoREST
A simple REST client built on ext-curl.

The best way to get started with NoREST is by simply trying some simple things: the API is intentionally
small to not overload you with complicated options you'll probably not need 99.999999% of the time.

Just create a client, add some settings to it and fire away!

The only thing to note is that setting options on the client instance does not change the options on that client:
instead, it returns a new client with that option added or changed.

```php
$client = new \TheNextInvoice\NoREST\Client('https://api.example.com')
    ->setContentType('text/plain')
    ->addHeader('X-Sent-By', 'James Bond');
try {
    $secret_endpoint = $client->get('/endpoint/secret');
    $response = $client->post($secret_endpoint, $my_secret_stuff);
} catch (\TheNextInvoice\NoREST\Exceptions\RequestFailedException $e) {
    echo 'oops, request failed: ' . $e->getMessage() . PHP_EOL;
    echo 'the response headers are' . $e->getResponseHeaders() . PHP_EOL;
    echo 'the response body was' . PHP_EOL;
    echo $e->getBody();
}

echo 'server response was:' . PHP_EOL
echo $response . PHP_EOL;
 ```

## Specialized options

Sometimes you need to interface with API's that aren't really playing by the rules. So to facilitate that we've made
a couple of helpers.

### Treat header names case-sensitive
While RFC 7230 section 3.2.2 says that header field names should be treated in a case-insensitive manner, there are
servers that do treat headers case-sensitive. If you want to make sure NoREST does not call `strtolower` on your header
key, do the following:
```php
$client = new \TheNextInvoice\NoREST\Client('https//api.example.com')
            ->addHeader('X-CaSeInSeNsItIvEiSsTuPiD', 'true', ['nolowercase' => true]);
```

### Empty POST request
Sometimes one needs to send an empty POST request. NoREST attempts to stick close to the HTTP spec, so
one cannot send a completely empty POST request. However, a POST request with an empty body is perfectly fine:
```php
// this won't work
$client->post('/endpoint');
// but this will
$client->post('/endpoint', []);
```

### JSON flags
When you're working with JSON payloads and have to deal with non-standard or just specific data,
NoREST has you covered by allowing you to specify JSON flags that NoREST will use when encoding and decoding
JSON payloads.
If you for example, don't want to escape slashes `/` and also want to convert tags `<`, `>` to unicode, you
should do this:
```php
// Construct client with JSON flags
$client = new \TheNextInvoice\NoREST\Client('https//api.example.com', [], [JSON_UNESCAPED_SLASHES, JSON_HEX_TAG]);
```
All requests made with a client constructed this way will have `JSON_UNESCAPED_SLASHES` and `JSON_HEX_TAG` JSON flags
applied when encoding AND decoding the request and response body respectively.

### Timeouts
Some servers are slower than others, and NoREST's default timeouts of 10s for TLS handshakes and 30s for overall request
time might be too strict. To change those -for instance to 120s total and 10s handshake, simply call:
```php
// Construct client with higher timeouts 
$client = new \TheNextInvoice\NoREST\Client('https//api.example.com', [], [], 120, 10);
// Or as a setter, returning a Client 
$client = $client->setTimeout(120, 10);
```

### Response headers
Sometimes the body alone isn't enough: maybe you need a `Location` header, a `Retry-After`, or you're just trying to
figure out why a response got decoded the way it did. NoREST keeps the headers of the most recently received response
around for you, keyed by lowercased header name:
```php
$client->get('/endpoint');
$headers = $client->getLastResponseHeaders();
// e.g. ['content-type' => ['application/json'], 'x-ratelimit-remaining' => ['42']]
```
Each header maps to a list of values rather than a single string, since some servers do send the same header more
than once (looking at you, `Set-Cookie`). This returns `null` until a request has actually been made on that
instance.

If the request fails, you don't need a separate call to see what the failing response looked like: the headers are
already on the exception.
```php
try {
    $client->get('/endpoint');
} catch (\TheNextInvoice\NoREST\Exceptions\RequestFailedException $e) {
    $headers = $e->getResponseHeaders();
}
```
This is empty when the request failed before a response was even received, such as a due to a connection error.
