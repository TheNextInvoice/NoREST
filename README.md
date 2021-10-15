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
While RFC 2616 section 4.2 says that header field names should be treated in a case-insensitive manner, there are
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
