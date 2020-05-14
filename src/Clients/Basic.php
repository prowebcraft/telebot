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

    protected $proxy = null;

    /**
     * Client constructor
     *
     * @param string $token Telegram Bot API token
     * @param string|null $trackerToken Yandex AppMetrica application api_key
     * @param string|null $proxy Custom api proxy entrance, such as https://your.host/bot
     */
    public function __construct($token, $trackerToken = null, $proxy = null)
    {
        $this->proxy = $proxy;
        parent::__construct($token, $trackerToken);
    }

    public function getUrl()
    {
        if ($this->proxy)
            return $this->proxy . $this->token;
        return parent::getUrl();
    }

}
