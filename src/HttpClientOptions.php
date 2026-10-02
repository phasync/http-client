<?php

namespace phasync\HttpClient;

/**
 * The options of an HTTP request, as public properties that map to cURL options.
 *
 * Every option defaults to `null`, meaning "not set": cURL's own default applies. The exception is
 * `followLocation`, which defaults to `true`. Give an instance, or an array keyed by property
 * name, to the {@see HttpClient} constructor for defaults, or to a request method for one request.
 *
 * ```php
 * $options = new HttpClientOptions(['timeoutMs' => 3000, 'userAgent' => 'my-app/1.0']);
 * $client  = new HttpClient($options);
 *
 * $client->get('https://example.com/slow', ['timeoutMs' => 30000]);
 * ```
 *
 * @see HttpClient
 * @see HttpClientOptions::overrideFrom
 */
class HttpClientOptions
{
    /**
     * The contents of the "User-Agent: " header to be used in a HTTP request.
     */
    public ?string $userAgent = null;

    /**
     * The offset, in bytes, to resume a transfer from.
     */
    public ?int $resumeFrom = null;

    /**
     * Allows an application to select what kind of IP addresses to use when
     * resolving host names. This is only interesting when using host names
     * that resolve addresses using more than one version of IP, possible
     * values are CURL_IPRESOLVE_WHATEVER, CURL_IPRESOLVE_V4, CURL_IPRESOLVE_V6,
     * by default CURL_IPRESOLVE_WHATEVER.
     */
    public ?int $ipResolve = null;

    /**
     * true to automatically set the Referer: field in requests where it follows a Location: redirect.
     */
    public ?bool $autoReferer = null;

    /**
     * true to convert Unix newlines to CRLF newlines on transfers.
     */
    public ?bool $crlf = null;

    /**
     * true to not allow URLs that include a username. Usernames are allowed by default (0).
     */
    public ?bool $disallowUsernameInUrl = null;

    /**
     * true to shuffle the order of all returned addresses so that they will be used in a
     * random order, when a name is resolved and more than one IP address is returned.
     * This may cause IPv4 to be used before IPv6 or vice versa.
     */
    public ?bool $dnsShuffleAddresses = null;

    /**
     * true to send an HAProxy PROXY protocol v1 header at the start of the connection.
     * The default action is not to send this header.
     */
    public ?bool $haProxyProtocol = null;

    /**
     * true to follow any "Location: " header that the server sends as part of the HTTP
     * header. See also {@see self::$maxRedirs}.
     */
    public ?bool $followLocation = true;

    /**
     * true to force the connection to explicitly close when it has finished processing,
     * and not be pooled for reuse.
     */
    public ?bool $forbidReuse = null;

    /**
     * true to force the use of a new connection instead of a cached one.
     */
    public ?bool $freshConnect = null;

    /**
     * true to disable TCP's Nagle algorithm, which tries to minimize the
     * number of small packets on the network.
     */
    public ?bool $tcpNoDelay = null;

    /**
     * true to tunnel through a given HTTP proxy.
     */
    public ?bool $httpProxyTunnel = null;

    /**
     * false to get the raw HTTP response body.
     */
    public ?bool $httpContentDecoding = null;

    /**
     * false to disable ALPN in the SSL handshake (if the SSL
     * backend libcurl is built to use supports it), which can
     * be used to negotiate http2.
     */
    public ?bool $sslEnableAlpn = null;

    /**
     * false to disable NPN in the SSL handshake (if the SSL
     * backend libcurl is built to use supports it), which
     * can be used to negotiate http2.
     */
    public ?bool $sslEnableNpn = null;

    /**
     * false to stop cURL from verifying the peer's certificate.
     * Alternate certificates to verify against can be specified
     * with the CURLOPT_CAINFO option or a certificate directory
     * can be specified with the CURLOPT_CAPATH option.
     */
    public ?bool $sslVerifyPeer = null;

    /**
     * false to stop cURL from verifying the HTTPS proxy's certificate.
     * When false, the verification succeeds regardless.
     */
    public ?bool $proxySslVerifyPeer = null;

    /**
     * false to accept the HTTPS proxy's certificate whatever names it
     * holds; by default cURL verifies them against the proxy name. Use
     * with caution.
     */
    public ?bool $proxySslVerifyHost = null;

    /**
     * The HTTP proxy to tunnel requests through.
     */
    public ?string $proxy = null;

    /**
     * The HTTP authentication method(s) to use for the proxy connection.
     * Use the same bitmasks as described in CURLOPT_HTTPAUTH. For proxy
     * authentication, only CURLAUTH_BASIC and CURLAUTH_NTLM are
     * currently supported.
     */
    public ?int $proxyAuth = null;

    /**
     * The port number of the proxy to connect to. This port number can
     * also be set in CURLOPT_PROXY.
     */
    public ?int $proxyPort = null;

    /**
     * Either CURLPROXY_HTTP (default), CURLPROXY_SOCKS4, CURLPROXY_SOCKS5,
     * CURLPROXY_SOCKS4A or CURLPROXY_SOCKS5_HOSTNAME.
     */
    public ?int $proxyType = null;

    /**
     * true to not handle dot dot sequences.
     */
    public ?bool $pathAsIs = null;

    /**
     * true to scan the ~/.netrc file to find a username and password
     * for the remote site that a connection is being established with.
     */
    public ?bool $netRc = null;

    /**
     * The maximum number of milliseconds to allow cURL functions
     * to execute. If libcurl is built to use the standard system
     * name resolver, that portion of the connect will still use
     * full-second resolution for timeouts with a minimum timeout
     * allowed of one second. A transfer that exceeds it fails with a
     * `\RuntimeException` when the response is read.
     */
    public ?int $timeoutMs = null;

    /**
     * The number of milliseconds to wait while trying to connect.
     * Use 0 to wait indefinitely. If libcurl is built to use the
     * standard system name resolver, that portion of the connect
     * will still use full-second resolution for timeouts with a
     * minimum timeout allowed of one second.
     */
    public ?int $connectTimeoutMs = null;

    /**
     * The maximum amount of HTTP redirections to follow. Use this
     * option alongside CURLOPT_FOLLOWLOCATION. Default value of 20
     * is set to prevent infinite redirects. Setting to -1 allows
     * infinite redirects, and 0 refuses all redirects.
     */
    public ?int $maxRedirs = null;

    /**
     * The contents of the "Cookie: " header to be used in the HTTP
     * request. Note that multiple cookies are separated with a
     * semicolon followed by a space (e.g., "fruit=apple; colour=red")
     */
    public ?string $cookie = null;

    /**
     * The name of the file containing the cookie data. The cookie
     * file can be in Netscape format, or just plain HTTP-style
     * headers dumped into a file. If the name is an empty string,
     * no cookies are loaded, but cookie handling is still enabled.
     */
    public ?string $cookieFile = null;

    /**
     * The name of a file to save all internal cookies to when the
     * handle is closed, e.g. after a call to curl_close.
     */
    public ?string $cookieJar = null;

    /**
     * The contents of the "Accept-Encoding: " header. This enables
     * decoding of the response. Supported encodings are "identity",
     * "deflate", and "gzip". If an empty string, "", is set, a
     * header containing all supported encoding types is sent.
     */
    public ?string $encoding = null;

    /**
     * The full data to post in a HTTP "POST" operation. This parameter
     * can either be passed as a urlencoded string like
     * 'para1=val1&para2=val2&...' or as an array with the field name
     * as key and field data as value. If value is an array, the
     * Content-Type header will be set to multipart/form-data. Files
     * can be sent using CURLFile or CURLStringFile, in which case
     * value must be an array.
     */
    public string|array|null $postFields = null;

    /**
     * The contents of the "Referer: " header to be used in a HTTP request.
     */
    public ?string $referer = null;

    /**
     * Range(s) of data to retrieve in the format "X-Y" where X or Y are
     * optional. HTTP transfers also support several intervals, separated
     * with commas in the format "X-Y,N-M".
     */
    public ?string $range = null;

    /**
     * The user name to use in authentication.
     */
    public ?string $username = null;

    /**
     * The password to use in authentication.
     */
    public ?string $password = null;

    /**
     * An array of HTTP header fields to set, in the format
     * array('Content-type: text/plain', 'Content-length: 100')
     *
     * @var string[]|null
     */
    public ?array $headers = null;

    /**
     * Provide a custom address for a specific host and port
     * pair. An array of hostname, port, and IP address strings,
     * each element separated by a colon. In the format:
     * array("example.com:80:127.0.0.1")
     */
    public ?array $resolve = null;

    /**
     * Create the options from an array of values keyed by property name.
     *
     * ```php
     * $options = new HttpClientOptions(['followLocation' => false, 'maxRedirs' => 0]);
     * ```
     *
     * @param array<string,mixed> $options Option values keyed by property name
     *
     * @throws \InvalidArgumentException for an option name that is not a property
     */
    public function __construct(array $options = [])
    {
        foreach ($options as $key => $value) {
            if (!\property_exists($this, $key)) {
                throw new \InvalidArgumentException("Unknown HttpClientOptions option '{$key}'");
            }
            $this->$key = $value;
        }
    }

    /**
     * Turn an array, an instance or null into an instance.
     *
     * @internal
     */
    public static function create(array|self|null $options): self
    {
        if ($options instanceof self) {
            return $options;
        }

        return new self($options ?? []);
    }

    /**
     * Return a copy of this instance with the non-null values of `$options` applied on top.
     *
     * A `null` value never overwrites a set option, so a default cannot be cleared this way. An
     * array value replaces the old array. `false` and `0` are values like any other.
     *
     * ```php
     * $defaults = new HttpClientOptions(['timeoutMs' => 5000, 'headers' => ['Accept: text/html']]);
     * $one      = $defaults->overrideFrom(['timeoutMs' => 100]);
     * // $one->timeoutMs is 100, $one->headers is still ['Accept: text/html']; $defaults is unchanged
     * ```
     *
     * @param array<string,mixed>|self|null $options The values to apply
     *
     * @throws \InvalidArgumentException for an option name that is not a property
     *
     * @see HttpClient::request
     */
    public function overrideFrom(array|self|null $options): self
    {
        $clone = clone $this;

        if (null === $options) {
            return $clone;
        }

        $overrides = $options instanceof self ? \get_object_vars($options) : $options;

        foreach ($overrides as $key => $value) {
            if (null === $value) {
                continue;
            }
            if (!\property_exists($clone, $key)) {
                throw new \InvalidArgumentException("Unknown HttpClientOptions option '{$key}'");
            }
            $clone->$key = $value;
        }

        return $clone;
    }
}
