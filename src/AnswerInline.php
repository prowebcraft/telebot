<?php
/**
 * Created by PhpStorm.
 * User: Andrey Mistulov
 * Company: Aristos
 * Date: 22.08.2017
 * Time: 8:38
 */

namespace Prowebcraft\Telebot;

use TelegramBot\Api\Types\CallbackQuery;

class AnswerInline
{

    protected $callbackQuery = null;
    protected $bot = null;

    public function __construct(CallbackQuery $query, Telebot $bot)
    {
        $this->setCallbackQuery($query);
        $this->bot = $bot;
    }

    /**
     * @param string $text
     * Text of the notification. If not specified, nothing will be shown to the user
     * @param bool $showAlert
     * If true, an alert will be shown by the client instead of a notification at the top of the chat screen. Defaults to false.
     * @return bool
     */
    public function reply($text, $showAlert = false)
    {
        return $this->bot->telegram->answerCallbackQuery($this->getCallbackQuery()->getId(), $text, $showAlert);
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


}
