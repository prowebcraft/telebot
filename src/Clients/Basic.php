<?php
/**
 * Created by PhpStorm.
 * User: Andrey Mistulov
 * Company: Aristos
 * Date: 15.03.2017
 * Time: 7:58
 */

namespace Prowebcraft\Telebot\Clients;
use TelegramBot\Api\BotApi;
use TelegramBot\Api\Exception;

/**
 * Extended api from TelegramBot\Api\BotApi
 * @package Prowebcraft\Telebot\Clients
 * @inheritdoc
 */
class Basic extends BotApi
{
    /**
     * Client constructor
     *
     * @param string $token Telegram Bot API token
     * @param string|null $trackerToken Yandex AppMetrica application api_key
     */
    public function __construct($token, $trackerToken = null)
    {
        parent::__construct($token, $trackerToken);
    }

    /**
     * Call method
     *
     * @param string $method
     * @param array|null $data
     *
     * @return mixed
     * @throws \TelegramBot\Api\Exception
     * @throws \TelegramBot\Api\HttpException
     * @throws \TelegramBot\Api\InvalidJsonException
     */
    public function call($method, array $data = null)
    {
        $options = [
            CURLOPT_URL => $this->getUrl().'/'.$method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => null,
            CURLOPT_POSTFIELDS => null,
            CURLOPT_SSL_VERIFYPEER => false
        ];

        if ($data) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $data;
        }

        $response = self::jsonValidate($this->executeCurl($options), $this->returnArray);

        if ($this->returnArray) {
            if (!isset($response['ok']) || !$response['ok']) {
                throw new Exception($response['description'], $response['error_code']);
            }

            return $response['result'];
        }

        if (!$response->ok) {
            throw new Exception($response->description, $response->error_code);
        }

        return $response->result;
    }

}
