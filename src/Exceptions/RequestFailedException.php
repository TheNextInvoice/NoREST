<?php

declare(strict_types=1);
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

namespace TheNextInvoice\NoREST\Exceptions;

class RequestFailedException extends Exception
{
    private mixed $body;

    /**
     * @var array<string, string[]>
     */
    private array $responseHeaders;

    /**
     * @param array<string, string[]> $responseHeaders The headers of the response that caused this exception.
     * Empty when the request failed completely, like on a connection error
     */
    public function __construct(string $message = '', int $code = 0, mixed $body = null, array $responseHeaders = [])
    {
        parent::__construct($message, $code);
        $this->body = $body;
        $this->responseHeaders = $responseHeaders;
    }

    public function getBody(): mixed
    {
        return $this->body;
    }

    /**
     * @return array<string, string[]>
     */
    public function getResponseHeaders(): array
    {
        return $this->responseHeaders;
    }
}
