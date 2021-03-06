<?php

namespace Giftd;

use Giftd\Exceptions\ApiException;
use Giftd\Exceptions\NetworkException;

class ApiClient
{
    private $apiKey;
    private $baseUrl = "https://api.giftd.tech/v1/";

    /**
     * Milliseconds to wait before the connection will be closed
     * @var int $connectionTimeout
     */
    private $connectionTimeout = 0;

    const RESPONSE_TYPE_DATA = 'data';
    const RESPONSE_TYPE_ERROR = 'error';

    const ERROR_NETWORK_ERROR = "networkError";
    const ERROR_TOKEN_NOT_FOUND = "tokenNotFound";
    const ERROR_EXTERNAL_ID_NOT_FOUND = "externalIdNotFound";
    const ERROR_DUPLICATE_EXTERNAL_ID = "duplicateExternalId";
    const ERROR_TOKEN_ALREADY_USED = "tokenAlreadyUsed";
    const ERROR_YOUR_ACCOUNT_IS_BANNED = "yourAccountIsBanned";

    public function __construct($userId, $apiKey)
    {
        $this->userId = $userId;
        $this->apiKey = $apiKey;
    }

    /**
     * @return int
     */
    public function getConnectionTimeout()
    {
        return $this->connectionTimeout;
    }

    /**
     * @param int $connectionTimeout
     */
    public function setConnectionTimeout($connectionTimeout)
    {
        $this->connectionTimeout = $connectionTimeout;
    }

    private function httpPostCurl($url, array $params)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => $params,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_CONNECTTIMEOUT_MS => $this->connectionTimeout,
        ));
        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            throw new NetworkException("HTTP POST to $url failed: " . curl_error($ch));
        }
        return $result;
    }

    private function httpPostFileGetContents($url, array $params)
    {
        $opts = array('http' =>
            array(
                'method'  => 'POST',
                'header'  => 'Content-type: application/x-www-form-urlencoded',
                'content' => http_build_query($params)
            )
        );
        $context  = stream_context_create($opts);

        $result = @file_get_contents($url, false, $context);
        if (!$result) {
            throw new NetworkException("HTTP POST to $url failed or returned empty result");
        }
        return $result;
    }

    private function httpPost($url, array $params)
    {
        if (function_exists('curl_init')) {
            $rawResult = $this->httpPostCurl($url, $params);
        } else {
            $rawResult = $this->httpPostFileGetContents($url, $params);
        }

        if (!($result = json_decode($rawResult, true))) {
            throw new ApiException("Giftd API returned malformed JSON, unable to decode it");
        }

        return $result;
    }

    public function query($method, $params = array(), $suppressExceptions = false)
    {
        if (isset($_SERVER['REMOTE_ADDR'])) {
            $params['client_ip'] = $_SERVER['REMOTE_ADDR'];
        }

        $params['signature'] = $this->calculateSignature($method, $params);
        $params['user_id'] = $this->userId;

        $result = $this->httpPost($this->baseUrl . $method, $params);
        if (empty($result['type'])) {
            throw new ApiException("Giftd API returned response without type field, unable to decode it");
        }
        if (!$suppressExceptions && $result['type'] == static::RESPONSE_TYPE_ERROR) {
            $this->throwException($result);
        }
        return $result;
    }

    private function throwException(array $rawResponse)
    {
        throw new ApiException($rawResponse['data'], $rawResponse['code']);
    }

    private function constructGiftCard(array $rawData, $token)
    {
        $card = new Card();
        foreach ($rawData as $key => $value) {
            $card->$key = $value;
        }
        $card->token = $token;
        if ($card->charge_details) {
            $chargeDetails = new ChargeDetails();
            foreach ($card->charge_details as $key => $value) {
                $chargeDetails->$key = $value;
            }
            $card->charge_details = $chargeDetails;
        }
        return $card;
    }

    /**
     * @param null $token
     * @param null $external_id
     * @param float $amountTotal
     * @param string $client_ip
     * @return Card|null
     * @throws ApiException
     */
    public function check($token = null, $external_id = null, $amountTotal = null, $client_ip = null)
    {
        $response = $this->query('gift/check', array(
            'token' => $token,
            'external_id' => $external_id,
            'amount_total' => $amountTotal,
            'client_ip' => $client_ip
        ), true);
        switch ($response['type']) {
            case static::RESPONSE_TYPE_ERROR:
                switch ($response['code']) {
                    case static::ERROR_TOKEN_NOT_FOUND:
                    case static::ERROR_EXTERNAL_ID_NOT_FOUND:
                        return null;
                    default:
                        $this->throwException($response);
                }
                break;
            case static::RESPONSE_TYPE_DATA:
                return $this->constructGiftCard($response['data'], $token);
            default:
                throw new ApiException("Unknown response type {$response['type']}");
        }
    }

    /**
     * @param $externalId
     * @return Card|null
     * @throws ApiException
     */
    public function checkByExternalId($externalId)
    {
        return $this->check(null, $externalId);
    }

    /**
     * @param $token
     * @param $amountTotal
     * @return Card|null
     * @throws ApiException
     */
    public function checkByToken($token, $amountTotal = null)
    {
        return $this->check($token, null, $amountTotal);
    }

    /**
     * @param $token
     * @param $amount
     * @param null $amountTotal
     * @param null $externalId
     * @param null $comment
     * @param string $client_ip
     * @return Card
     * @throws Exception
     */
    public function charge($token, $amount, $amountTotal = null, $externalId = null, $comment = null, $client_ip = null)
    {
        $result = $this->query('gift/charge', array(
            'token' => $token,
            'amount' => $amount,
            'amount_total' => $amountTotal,
            'external_id' => $externalId,
            'comment' => $comment,
            'client_ip' => $client_ip
        ));

        return $this->constructGiftCard($result['data'], $token);
    }

    private function calculateSignature($method, array $params)
    {
        $signatureBase = $method . "," . $this->userId. ",";
        unset($params['user_id'], $params['signature'], $params['api_key']);
        ksort($params);
        foreach ($params as $key => $value) {
            $signatureBase .= $key . "=" . $value . ",";
        }
        $signatureBase .= $this->apiKey;
        return sha1($signatureBase);
    }
}


