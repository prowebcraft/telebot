<?php
/**
 * Created by PhpStorm.
 * User: Andrey Mistulov
 * Company: Aristos
 * Date: 22.08.2017
 * Time: 8:38
 */

namespace Prowebcraft\Telebot;

use Prowebcraft\Dot;
use TelegramBot\Api\Types\CallbackQuery;

class AnswerInline
{

    protected $callbackQuery = null;
    protected $bot = null;
    protected $payload = null;

    public function __construct(CallbackQuery $query, Telebot $bot, $payload)
    {
        $this->setCallbackQuery($query);
        $this->bot = $bot;
        $this->payload = $payload;
    }

    /**
     * @param string $text
     * Text of the notification. If not specified, nothing will be shown to the user
     * @param bool $showAlert
     * If true, an alert will be shown by the client instead of a notification at the top of the chat screen. Defaults to false.
     * @return bool
     */
    public function reply($text = null, $showAlert = false)
    {
        try {
            return $this->bot->telegram->answerCallbackQuery($this->getCallbackQuery()?->getId(), $text, $showAlert);
        } catch (\Throwable $e) {
            if (!str_contains($e->getMessage(), 'query is too old')) {
                throw $e;
            }
        }
        return false;
    }

    /**
     * Returns Callback Query Data
     * @return string
     */
    public function getData()
    {
        return $this->getCallbackQuery()->getData();
    }

    /**
     * Returns Callback Query Data
     * @return array
     */
    public function getJsonData()
    {
        return json_decode($this->getData(), true);
    }

    /**
     * Returns CallbackQuery of Answer
     * @return CallbackQuery
     */
    public function getCallbackQuery()
    {
        return $this->callbackQuery;
    }

    /**
     * @param CallbackQuery $callbackQuery
     */
    public function setCallbackQuery(CallbackQuery $callbackQuery)
    {
        $this->callbackQuery = $callbackQuery;
    }

    /**
     * Get extra data from payload
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getExtraData($key, $default = null)
    {
        $extra = @$this->payload['extra'];
        return Dot::getValue($extra, $key, $default);
    }

}
