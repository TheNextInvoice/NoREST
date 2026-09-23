<?php

/**
NoREST: a super simple REST client for PHP
Copyright (C) 2018-2026  TheNextInvoice B.V.

This library is free software; you can redistribute it and/or
modify it under the terms of the GNU Lesser General Public
License as published by the Free Software Foundation; either
version 2.1 of the License, or (at your option) any later version.

This library is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
Lesser General Public License for more details.

You should have received a copy of the GNU Lesser General Public
License along with this library; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 */

declare(strict_types=1);

namespace TheNextInvoice\NoREST;

use CurlHandle;
use InvalidArgumentException;
use JsonException;
use LogicException;
use TheNextInvoice\NoREST\Exceptions\RequestFailedException;

/**
 * Class Client
 * @package TheNextInvoice\NoREST
 */
class Client
{
    public const NO_LOWERCASE_HEADERS = 'nolowercase';

    /**
     * Default connection timeout, in seconds: how long to wait for the TCP/TLS handshake.
     */
    public const DEFAULT_CONNECT_TIMEOUT = 10;

    /**
     * Default total request timeout, in seconds.
     */
    public const DEFAULT_TIMEOUT = 30;

    protected const CONTENT_TYPE_HEADER = 'content-type';

    protected const SUPPORTED_CONTENT_TYPES = [
        'application/json',
        'application/xml',
        'application/atom+xml',
        'text/plain',
        'application/x-www-form-urlencoded',
        'multipart/form-data',
    ];

    /**
     * @var int[]
     */
    protected array $jsonFlags;

    /**
     * Base URL for api.
     *
     * @var string $baseUrl
     */
    private string $baseUrl;

    /**
     * Currently active request headers. Always contains a 'content-type' entry.
     *
     * @var array<string, string> as per RFC 7230
     */
    private array $headers;

    /**
     * Currently cached curl headers
     * This is a cache created from calling Client::collapseHeaders and should not be used
     *
     * @internal
     * @var string[] as per RFC 7230
     */
    private array $curlHeaders = [];

    private int $timeout;

    private int $connectTimeout;

    /**
     * The headers received in the most recent response made by this instance, keyed by
     * lowercased header name with a list of values.
     *
     * RFC 7230 section 3.2.2 only allows a header field to repeat when its value is a
     * comma-separated list, or for well-known exceptions such as Set-Cookie; a compliant
     * server should otherwise send it at most once. We still capture every occurrence as a
     * list rather than assume compliance, since misconfigured servers and proxies do send
     * duplicates regardless.
     *
     * Null until a request has been made on this instance.
     *
     * @var array<string, string[]>|null
     */
    private ?array $lastResponseHeaders = null;

    /**
     * Client constructor.
     * If no 'content-type' header is passed, it defaults to application/json.
     * @param string $base The base URL for making requests
     * @param array<string, string>|null $headers
     * @param int[] $jsonFlags Options to pass to json_encode and json_decode. Used only if content-type is
     *                              'application/json'
     * @param int $timeout Total request timeout, in seconds
     * @param int $connectTimeout Connection timeout, in seconds
     */
    public function __construct(
        string $base,
        ?array $headers = null,
        array $jsonFlags = [],
        int $timeout = self::DEFAULT_TIMEOUT,
        int $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT
    ) {
        $this->baseUrl = $base;
        $headers ??= [];
        // No header provided, default to 'application/json'
        $headers += [self::CONTENT_TYPE_HEADER => 'application/json'];

        $this->headers = $headers;
        // No need to de-duplicate: reduceFlags() only ever bitwise-ORs these together, and
        // duplicate flags don't change the result of a bitwise OR.
        $this->jsonFlags = $jsonFlags;
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
    }

    /**
     * Add a header to requests.
     * Returns a new, completely separate instance from the called instance.
     * Overwrites previously set base URL.
     *
     * - To set the content-type use setContentType instead.
     *
     * @param string $base Base URL
     * @return Client new Client instance with new base URL.
     * @see Client::setContentType
     */
    public function setBaseUrl(string $base): self
    {
        return new self($base, $this->headers, $this->jsonFlags, $this->timeout, $this->connectTimeout);
    }

    /**
     * Validates and set the proper content-type header.
     *
     * @param string $type Complete mime type to set content type to ('application/json')
     * @return Client
     */
    public function setContentType(string $type): self
    {
        $type = strtolower($type);
        if (!in_array($type, self::SUPPORTED_CONTENT_TYPES, true)) {
            throw new InvalidArgumentException("Unsupported content type: {$type}");
        }
        return $this->addHeader(self::CONTENT_TYPE_HEADER, $type);
    }

    /**
     * Add a header to requests.
     * Returns a new, completely separate instance from the called instance.
     * Overwrites previously set header.
     *
     * - To set the content-type use setContentType instead.
     *
     * @param string $header Header key
     * @param string $value Header value
     * @param array<string, bool> $options Key/Value options set
     *  - nolowercase: do not call strtolower on the header.
     *    Should only be used for API's that do not function without it, since it is in direct contravention
     *    of RFC 7230 section 3.2 which states that all HTTP header field names "[...] a case-insensitive field name."
     *
     * @return Client new Client instance with that header added.
     *
     * @see Client::setContentType
     */
    public function addHeader(string $header, string $value, array $options = []): self
    {
        $newHeaders = $this->headers;
        // Check if we should not lowercase our headers
        $noLowercaseHeaders = $options[self::NO_LOWERCASE_HEADERS] ?? false;

        if (!$noLowercaseHeaders) {
            $header = strtolower($header);
        }

        $newHeaders[$header] = $value;
        return new self($this->baseUrl, $newHeaders, $this->jsonFlags, $this->timeout, $this->connectTimeout);
    }

    /**
     * Set flags to be used for json_encode and json_decode. Returns a new instance
     *
     * @param int[] $flags Flags to be used with json_encode/json_decode
     * @return Client new instance with the new flags
     */
    public function setJsonFlags(array $flags): self
    {
        return new self($this->baseUrl, $this->headers, $flags, $this->timeout, $this->connectTimeout);
    }

    /**
     * Set the connection and total request timeouts. Returns a new instance.
     *
     * @param int $timeout Total request timeout, in seconds
     * @param int|null $connectTimeout Connection timeout, in seconds. Defaults to self::DEFAULT_CONNECT_TIMEOUT
     * @return Client new instance with the new timeouts
     */
    public function setTimeout(int $timeout, ?int $connectTimeout = null): self
    {
        return new self(
            $this->baseUrl,
            $this->headers,
            $this->jsonFlags,
            $timeout,
            $connectTimeout ?? $this->connectTimeout
        );
    }

    /**
     * Get the headers of the most recently received response for a request made on this instance.
     * Returns null if no request has been made on this instance yet.
     *
     * @return array<string, string[]>|null
     */
    public function getLastResponseHeaders(): ?array
    {
        return $this->lastResponseHeaders;
    }

    /**
     * Makes a GET request with the current client settings.
     *
     * @param string $url URL
     * @return mixed Decoded data according to what the server returns. Do not make any assumptions on this data
     * and be especially careful when handling strings, since PHP makes no difference between a well-formed string and
     * binary data returned such as with image data.
     * @throws RequestFailedException If the request fails for any reason
     * @throws JsonException Only if JSON decoding fails
     */
    public function get(string $url): mixed
    {
        return $this->sendRequest($url, 'GET');
    }

    /**
     * Send a raw request. This method is internal and may change in a breaking manner
     * without major version increases. Do not use this and instead use the dedicated request functions instead
     *
     * @param string $url URL
     * @param string $method HTTP method
     * @param mixed|null $payload Body
     * @return mixed Decoded data according to what the server returns. Do not make any assumptions on this data
     * and be especially careful when handling strings, since PHP makes no difference between a well-formed string and
     * binary data returned such as with image data.
     * @throws RequestFailedException|JsonException
     * @internal Please use Client::get(), Client::post() et al.
     */
    protected function sendRequest(string $url, string $method, mixed $payload = null): mixed
    {
        $method = strtoupper($method);
        $combinedUrl = $this->combineUrl($url);

        $curlOptions = [
            CURLOPT_URL => $combinedUrl,
            CURLOPT_HEADER => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
        ];

        if ($method === 'POST') {
            if (!is_array($payload) && !is_object($payload) && !is_string($payload)) {
                throw new InvalidArgumentException('Client: Missing or invalid payload');
            }

            // If we are sending multipart/form-data, don't encode our body. If the data
            // passed to cURL is an array the content type will be set to multipart/form-data
            // automatically and passed files will be automatically processed.
            if ($this->getContentType() === 'multipart/form-data') {
                if (!is_array($payload)) {
                    throw new InvalidArgumentException("Client: payload for multipart/form-data must be an array");
                }

                $data = $payload;
            } else {
                $data = $this->encodeBody($payload);
            }

            $curlOptions += [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_POSTFIELDS => $data,
            ];
        } elseif ($method === 'PUT' || $method === 'PATCH') {
            if (!is_array($payload) && !is_object($payload) && !is_string($payload)) {
                throw new InvalidArgumentException('Client: Missing or invalid payload');
            }

            $curlOptions += [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_POSTFIELDS => $this->encodeBody($payload),
            ];
        } elseif ($method === 'DELETE') {
            $curlOptions += [
                CURLOPT_CUSTOMREQUEST => $method,
            ];
        }

        $this->collapseHeaders();

        $responseHeaders = [];
        $curlOptions += [
            CURLOPT_HTTPHEADER => $this->curlHeaders,
            // save headers to see if we can determine what the response
            // content type is. Code taken from
            // https://stackoverflow.com/questions/9183178/can-php-curl-retrieve-response-headers-and-body-in-a-single-request#41135574
            CURLOPT_HEADERFUNCTION =>
                static function (CurlHandle $curl, string $header) use (&$responseHeaders): int {
                    $len = strlen($header);
                    $header = explode(':', $header, 2);
                    if (count($header) < 2) {
                        return $len;
                    }
                    // Be defensive in our handling of headers, we don't know how the server we're running on
                    // is configured and might give us weird duplicates.
                    $name = strtolower(trim($header[0]));
                    if (!array_key_exists($name, $responseHeaders)) {
                        $responseHeaders[$name] = [trim($header[1])];
                    } else {
                        $responseHeaders[$name][] = trim($header[1]);
                    }

                    return $len;
                }
        ];

        $curlHandle = curl_init();
        if ($curlHandle === false) {
            throw new RequestFailedException('Curl error: unable to initialize a cURL handle');
        }
        curl_setopt_array($curlHandle, $curlOptions);
        $responseBody = curl_exec($curlHandle);
        // curl_exec returns string on success or false on failure, as CURLOPT_RETURNTRANSFER is set to true.
        // The curl_errno check is intentionally redundant defense-in-depth: both (currently) read the same
        // underlying CURLcode of this curl_exec call, so they shouldn't disagree (verified empirically on PHP8.4,
        // incl. for CURLE_PARTIAL_FILE), but let's double-check to prevent decoding a broken or truncated response.
        if (!is_string($responseBody) || curl_errno($curlHandle) !== 0) {
            $this->handleCurlError($curlHandle);
        }
        $this->lastResponseHeaders = $responseHeaders;

        if (array_key_exists(self::CONTENT_TYPE_HEADER, $responseHeaders)) {
            $responseBody = $this->decodeBody($responseBody, $responseHeaders[self::CONTENT_TYPE_HEADER]);
        } else {
            $responseBody = $this->decodeBody($responseBody);
        }

        $responseCode = curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
        curl_close($curlHandle);

        if ($responseCode < 200 || $responseCode > 299) {
            $this->handleResponseError($responseCode, $responseBody, $responseHeaders);
        }
        return $responseBody;
    }

    /**
     * Encodes the request body to the content type specified in the 'Content-Type' header.
     * A string payload is always considered already-encoded and is sent as-is, regardless of
     * content type. Any other payload is encoded according to the content type.
     *
     * @param mixed $body Body to encode
     * @return string encoded body
     * @throws JsonException
     */
    private function encodeBody(mixed $body): string
    {
        $contentType = $this->getContentType();

        switch ($contentType) {
            case 'application/json':
                if (is_string($body)) {
                    return $body;
                }
                return json_encode($body, JSON_THROW_ON_ERROR | $this->reduceFlags($this->jsonFlags), 512);
            case 'application/atom+xml':
            case 'application/xml':
            case 'text/plain':
                if (!is_string($body)) {
                    throw new InvalidArgumentException("Client: payload for {$contentType} must be a string");
                }
                return $body;
            case 'application/x-www-form-urlencoded':
                if (is_string($body)) {
                    return $body;
                }
                if (!is_array($body) && !is_object($body)) {
                    throw new InvalidArgumentException(
                        "Client: payload for {$contentType} must be a string, array or object"
                    );
                }
                return http_build_query($body);
            default:
                throw new InvalidArgumentException("Unsupported content type: {$contentType}");
        }
    }

    /**
     * Reduce configured json flags by performing bitwise OR on the flags.
     * The result may safely be used as the flags parameter for json_encode/json_decode
     *
     * @param int[] $flags
     * @return int
     */
    private function reduceFlags(array $flags): int
    {
        $result = 0;
        foreach ($flags as $flag) {
            $result |= $flag;
        }
        return $result;
    }

    /**
     * The currently active 'content-type' header.
     * This is always present, either set explicitly or defaulted to in the constructor.
     */
    private function getContentType(): string
    {
        return $this->headers[self::CONTENT_TYPE_HEADER]
            ?? throw new LogicException('Client: missing content-type header, this should be unreachable');
    }

    /**
     * Collapse our key/value pair headers into curl accepted strings.
     * Note that this function maintains an internal cache of these headers and
     * repeated calls do not update the cURL header set if this method was called before.
     *
     * This is done for performance reasons, but this is also why Client::setHeader returns a new Client
     * instance instead of modifying the original object.
     */
    private function collapseHeaders(): void
    {
        if ($this->curlHeaders) {
            return;
        }

        foreach ($this->headers as $k => $v) {
            $this->curlHeaders[] = "$k: $v";
        }
    }

    /**
     * Combines the Base URL and the URI fragment into a full URL.
     * If $uri is a full url itself, it returns $uri itself.
     * If $uri is only a path segment, it prepends the BaseUrl
     *
     * @param string $uri request URL, either partial or full
     * @return non-empty-string
     */
    private function combineUrl(string $uri): string
    {
        if ($uri === '') {
            throw new InvalidArgumentException('Client: URI must not be empty');
        }

        // Check if $uri is a full uri
        $parsed = parse_url($uri);
        if ($parsed !== false && isset($parsed['host'])) {
            return $uri;
        }

        // We seem to have only a segment, just append it to $baseUrl
        // Check if the fragment contains a slash prefix or not
        $sep = strlen($uri) > 1 && $uri[0] === '/' ? '' : '/';
        return $this->baseUrl . $sep . $uri;
    }

    /**
     * Generates a CURL error message if a request fails.
     *
     * @param CurlHandle $curlHandle CURL handle
     * @throws RequestFailedException The exception for the CURL error
     */
    private function handleCurlError(CurlHandle $curlHandle): never
    {
        $errorMessage = 'Curl error: ' . curl_error($curlHandle);
        throw new RequestFailedException($errorMessage, curl_errno($curlHandle));
    }

    /**
     * Decodes the response body to either the content type as given in the response header,
     * or the previously specified content type.
     * Only JSON (including vendor "+json" variants) and form-urlencoded bodies are actively
     * decoded; every other content type (xml, plain text, binary, ...) is returned as-is.
     *
     * @param string $body Response body
     * @param string[]|null $response_header the content-type header of the response
     * @return mixed decoded body
     * @throws JsonException
     */
    private function decodeBody(string $body, ?array $response_header = null): mixed
    {
        if (empty($body)) {
            return $body;
        }

        // Check if the response passed a return MIME type. If not, assume the
        // response MIME type is the exact same as our request MIME type
        if ($response_header !== null && count($response_header) === 1) {
            $header = $response_header[0];
        } else {
            $header = $this->getContentType();
        }
        // MIME types can have annoying additions such as text/json;encoding=utf-8
        // Strip such extensions from our MIME string
        $mime = strtolower(explode(';', $header, 2)[0]);

        if ($mime === 'application/x-www-form-urlencoded') {
            $result = [];
            parse_str($body, $result);
            return $result;
        }

        if ($mime === 'application/json' || $mime === 'text/json' || str_ends_with($mime, '+json')) {
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR | $this->reduceFlags($this->jsonFlags));
        }

        return $body;
    }

    /**
     * Generates a nice error message for when a request fails due to non-curl reasons
     *
     * @param int $responseCode HTTP response code
     * @param mixed $responseBody The body that was returned
     * @param array<string, string[]> $responseHeaders The headers that were returned
     * @throws RequestFailedException The exception for the response
     */
    private function handleResponseError(int $responseCode, mixed $responseBody, array $responseHeaders): void
    {
        $errorMessage = 'Unknown error: ' . $responseCode;
        throw new RequestFailedException($errorMessage, $responseCode, $responseBody, $responseHeaders);
    }

    /**
     * Makes a DELETE request.
     *
     * @param string $url URL
     * @return mixed Decoded data according to what the server returns. Do not make any assumptions on this data
     * and be especially careful when handling strings, since PHP makes no difference between a well-formed string and
     * binary data returned such as with image data.
     * @throws RequestFailedException If the request fails for any reason
     * @throws JsonException
     */
    public function delete(string $url): mixed
    {
        return $this->sendRequest($url, 'DELETE');
    }

    /**
     * Makes a PUT request.
     *
     * @param string $url URL
     * @param mixed $payload Body
     * @return mixed Decoded data according to what the server returns. Do not make any assumptions on this data
     * and be especially careful when handling strings, since PHP makes no difference between a well-formed string and
     * binary data returned such as with image data.
     * @throws RequestFailedException If the request fails for any reason
     * @throws JsonException
     */
    public function put(string $url, mixed $payload): mixed
    {
        return $this->sendRequest($url, 'PUT', $payload);
    }

    /**
     * Makes a POST request.
     *
     * @param string $url URL
     * @param mixed $payload Body
     * @return mixed Decoded data according to what the server returns. Do not make any assumptions on this data
     * and be especially careful when handling strings, since PHP makes no difference between a well-formed string and
     * binary data returned such as with image data.
     * @throws RequestFailedException If the request fails for any reason
     * @throws JsonException
     */
    public function post(string $url, mixed $payload): mixed
    {
        return $this->sendRequest($url, 'POST', $payload);
    }

    /**
     * Makes a PATCH request.
     *
     * @param string $url URL
     * @param mixed $payload Body
     * @return mixed Decoded data according to what the server returns. Do not make any assumptions on this data
     * and be especially careful when handling strings, since PHP makes no difference between a well-formed string and
     * binary data returned such as with image data.
     * @throws RequestFailedException If the request fails for any reason
     * @throws JsonException
     */
    public function patch(string $url, mixed $payload): mixed
    {
        return $this->sendRequest($url, 'PATCH', $payload);
    }
}
