<?php
/**
 * Created by PhpStorm.
 * User: Andrey Mistulov
 * Company: Aristos
 * Date: 16.03.2017
 * Time: 8:38
 */

namespace Prowebcraft\Telebot;

use TelegramBot\Api\Types\Message;

class Answer
{

    protected $info = null;
    /**
     * @var null|Message
     */
    protected $message = null;
    protected $variant = -1;

    public function __construct($message, $info)
    {
        $this->setMessage($message);
        $this->setInfo($info);
    }

    /**
     * Return original ask message id
     * @return null
     */
    public function getAskMessageId()
    {
        $info = $this->getInfo();
        return $info['id'] ?: null;
    }

    /**
     * Get reply message plain text
     * @return string
     */
    public function getReplyText()
    {
        return $this->getMessage()->getText();
    }
    
    /**
     * Get Payload information
     * @return array
     */
    public function getInfo()
    {
        return $this->info;
    }

    /**
     * Payload information
     * @param array $info
     */
    public function setInfo($info)
    {
        $this->info = $info;
    }

    /**
     * Reply message Object
     * @return Message
     */
    public function getMessage()
    {
        return $this->message;
    }

    /**
     * @param null $message
     */
    public function setMessage($message)
    {
        $this->message = $message;
    }

    /**
     * Возвращает индекс варианта ответа на вопрос
     * @return int|null|false
     * Возвращает int, если дан явный ответ; false - если не выбран ни один из вариантов; null - если вариантов не было
     */
    public function getAnswerVariant() {
        if ($this->variant === -1) {
            $this->variant = $this->detectAnswerVariant();
        }
        return $this->variant;
    }

    /**
     * Получает индекс варианта ответа на вопрос
     * @return int|null|false
     */
    private function detectAnswerVariant() {
        $answer = $this->getMessage()->getText();
        $options = $this->getInfo()['answers'];
        if (!empty($options)) {
            $resId = 0;
            foreach ($options as $groupId => $group) {
                foreach ($group as $variantId => $option) {
                    $resId++;
                    if ($option == $answer) return $resId;
                }
            }
            return false;
        }
        return null;
    }

}
