<?php

namespace Elphin\LEClient;

use Elphin\LEClient\Exception\RuntimeException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * LetsEncrypt Connector class, containing the functions necessary to sign with JSON Web Key and Key ID, and perform
 * GET, POST and HEAD requests.
 *
 * PHP version 7.1.0
 *
 * MIT License
 *
 * Copyright (c) 2018 Youri van Weegberg
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * @author     Youri van Weegberg <youri@yourivw.nl>
 * @copyright  2018 Youri van Weegberg
 * @license    https://opensource.org/licenses/mit-license.php  MIT License
 * @version    1.1.0
 * @link       https://github.com/yourivw/LEClient
 * @since      Class available since Release 1.0.0
 */
class LEConnector
{
    public $baseURL;
    public $accountKeys;

    private $nonce;

    public $keyChange;
    public $newAccount;
    public $newNonce;
    public $newOrder;
    public $revokeCert;

    public $accountURL;
    public $accountDeactivated = false;

    /** @var LoggerInterface */
    private $log;

    /** @var ClientInterface */
    private $httpClient;

    /**
     * Initiates the LetsEncrypt Connector class.
     *
     * @param LoggerInterface $log
     * @param ClientInterface $httpClient
     * @param string $baseURL The LetsEncrypt server URL to make requests to.
     * @param array $accountKeys Array containing location of account keys files.
     */
    public function __construct(LoggerInterface $log, ClientInterface $httpClient, $baseURL, $accountKeys)
    {
        $this->baseURL = $baseURL;
        $this->accountKeys = $accountKeys;
        $this->log = $log;
        $this->httpClient = $httpClient;

        $this->getLEDirectory();
        $this->getNewNonce();
    }

    /**
     * Requests the LetsEncrypt Directory and stores the necessary URLs in this LetsEncrypt Connector instance.
     */
    private function getLEDirectory()
    {
        $req = $this->get('/directory');
        $this->keyChange = $req['body']['keyChange'];
        $this->newAccount = $req['body']['newAccount'];
        $this->newNonce = $req['body']['newNonce'];
        $this->newOrder = $req['body']['newOrder'];
        $this->revokeCert = $req['body']['revokeCert'];
    }

    /**
     * Requests a new nonce from the LetsEncrypt server and stores it in this LetsEncrypt Connector instance.
     */
    private function getNewNonce()
    {
        $result = $this->head($this->newNonce);

        if ($result['status'] !== 204) {
            throw new RuntimeException("No new nonce - fetched {$this->newNonce} got " . $result['header']);
        }
    }

    /**
     * Makes a Curl request.
     *
     * @param string $method The HTTP method to use. Accepting GET, POST and HEAD requests.
     * @param string $URL The URL or partial URL to make the request to.
     *                       If it is partial, the baseURL will be prepended.
     * @param string $data The body to attach to a POST request. Expected as a JSON encoded string.
     *
     * @return array Returns an array with the keys 'request', 'header' and 'body'.
     */
    private function request($method, $URL, $data = null)
    {
        if ($this->accountDeactivated) {
            throw new RuntimeException('The account was deactivated. No further requests can be made.');
        }

        $requestURL = preg_match('~^http~', $URL) ? $URL : $this->baseURL . $URL;

        $hdrs = ['Accept' => 'application/json'];
        if (!empty($data)) {
            $hdrs['Content-Type'] = 'application/json';
        }

        $request = new Request($method, $requestURL, $hdrs, $data);

        try {
            $response = $this->httpClient->send($request);
        } catch (BadResponseException $e) {
            $msg = "$method $URL failed";
            if ($e->hasResponse()) {
                $body = (string)$e->getResponse()->getBody();
                $json = json_decode($body, true);
                if (!empty($json) && isset($json['detail'])) {
                    $msg .= " ({$json['detail']})";
                }
            }
            throw new RuntimeException($msg, 0, $e);
        } catch (GuzzleException $e) {
            throw new RuntimeException("$method $URL failed", 0, $e);
        }
        //TestResponseGenerator::dumpTestSimulation($method, $requestURL, $response);

        $this->maintainNonce($method, $response);

        return $this->formatResponse($method, $requestURL, $response);
    }

    private function formatResponse($method, $requestURL, ResponseInterface $response)
    {
        $body = $response->getBody();

        $header = $response->getStatusCode() . ' ' . $response->getReasonPhrase() . "\n";
        $allHeaders = $response->getHeaders();
        foreach ($allHeaders as $name => $values) {
            foreach ($values as $value) {
                $header .= "$name: $value\n";
            }
        }

        $decoded = $body;
        if ($response->getHeaderLine('Content-Type') === 'application/json') {
            $decoded = json_decode($body, true);
            if (!$decoded) {
                throw new RuntimeException('Bad JSON received ' . $body);
            }
        }

        $jsonresponse = [
            'request' => $method . ' ' . $requestURL,
            'header' => $header,
            'body' => $decoded,
            'raw' => $body,
            'status' => $response->getStatusCode()
        ];

        //$this->log->debug('{request} got {status} header = {header} body = {raw}', $jsonresponse);

        return $jsonresponse;
    }

    private function maintainNonce($requestMethod, ResponseInterface $response)
    {
        if ($response->hasHeader('Replay-Nonce')) {
            $this->nonce = $response->getHeader('Replay-Nonce')[0];
            $this->log->debug("got new nonce " . $this->nonce);
        } elseif ($requestMethod == 'POST') {
            $this->getNewNonce(); // Not expecting a new nonce with GET and HEAD requests.
        }
    }

    /**
     * Makes a GET request.
     *
     * @param string $url The URL or partial URL to make the request to.
     *                    If it is partial, the baseURL will be prepended.
     *
     * @return array Returns an array with the keys 'request', 'header' and 'body'.
     */
    public function get($url)
    {
        return $this->request('GET', $url);
    }

    /**
     * Makes a POST request.
     *
     * @param string $url The URL or partial URL for the request to. If it is partial, the baseURL will be prepended.
     * @param string $data The body to attach to a POST request. Expected as a json string.
     *
     * @return array Returns an array with the keys 'request', 'header' and 'body'.
     */
    public function post($url, $data = null)
    {
        return $this->request('POST', $url, $data);
    }

    /**
     * Makes a HEAD request.
     *
     * @param string $url The URL or partial URL to make the request to.
     *                    If it is partial, the baseURL will be prepended.
     *
     * @return array Returns an array with the keys 'request', 'header' and 'body'.
     */
    public function head($url)
    {
        return $this->request('HEAD', $url);
    }

    /**
     * Generates a JSON Web Key signature to attach to the request.
     *
     * @param array|string $payload The payload to add to the signature.
     * @param string $url The URL to use in the signature.
     * @param string $privateKeyFile The private key to sign the request with. Defaults to 'private.pem'.
     *                               Defaults to accountKeys[private_key].
     *
     * @return string   Returns a JSON encoded string containing the signature.
     */
    public function signRequestJWK($payload, $url, $privateKeyFile = '')
    {
        if ($privateKeyFile == '') {
            $privateKeyFile = $this->accountKeys['private_key'];
        }
        $privateKey = openssl_pkey_get_private(file_get_contents($privateKeyFile));
        if ($privateKey === false) {
            throw new \RuntimeException('LEConnector::signRequestJWK failed to get private key');
        }

        $details = openssl_pkey_get_details($privateKey);

        $protected = [
            "alg" => "RS256",
            "jwk" => [
                "kty" => "RSA",
                "n" => LEFunctions::base64UrlSafeEncode($details["rsa"]["n"]),
                "e" => LEFunctions::base64UrlSafeEncode($details["rsa"]["e"]),
            ],
            "nonce" => $this->nonce,
            "url" => $url
        ];

        $payload64 = LEFunctions::base64UrlSafeEncode(
            str_replace('\\/', '/', is_array($payload) ? json_encode($payload) : $payload)
        );
        $protected64 = LEFunctions::base64UrlSafeEncode(json_encode($protected));

        openssl_sign($protected64 . '.' . $payload64, $signed, $privateKey, OPENSSL_ALGO_SHA256);
        $signed64 = LEFunctions::base64UrlSafeEncode($signed);

        $data = [
            'protected' => $protected64,
            'payload' => $payload64,
            'signature' => $signed64
        ];

        return json_encode($data);
    }

    /**
     * Generates a Key ID signature to attach to the request.
     *
     * @param array|string $payload The payload to add to the signature.
     * @param string $kid The Key ID to use in the signature.
     * @param string $url The URL to use in the signature.
     * @param string $privateKeyFile The private key to sign the request with. Defaults to 'private.pem'.
     *                               Defaults to accountKeys[private_key].
     *
     * @return string   Returns a JSON encoded string containing the signature.
     */
    public function signRequestKid($payload, $kid, $url, $privateKeyFile = '')
    {
        if ($privateKeyFile == '') {
            $privateKeyFile = $this->accountKeys['private_key'];
        }
        $privateKey = openssl_pkey_get_private(file_get_contents($privateKeyFile));
        //$details = openssl_pkey_get_details($privateKey);

        $protected = [
            "alg" => "RS256",
            "kid" => $kid,
            "nonce" => $this->nonce,
            "url" => $url
        ];

        $payload64 = LEFunctions::base64UrlSafeEncode(
            str_replace('\\/', '/', is_array($payload) ? json_encode($payload) : $payload)
        );
        $protected64 = LEFunctions::base64UrlSafeEncode(json_encode($protected));

        openssl_sign($protected64 . '.' . $payload64, $signed, $privateKey, OPENSSL_ALGO_SHA256);
        $signed64 = LEFunctions::base64UrlSafeEncode($signed);

        $data = [
            'protected' => $protected64,
            'payload' => $payload64,
            'signature' => $signed64
        ];

        return json_encode($data);
    }
}