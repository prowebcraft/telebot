<?php

namespace Prowebcraft\Telebot;

use Prowebcraft\Dot;
use Prowebcraft\Telebot\Clients\Basic;
use Exception;
use ReflectionClass;
use ReflectionMethod;
use System_Daemon;
use TelegramBot\Api\BotApi;
use TelegramBot\Api\Client;
use TelegramBot\Api\HttpException;
use TelegramBot\Api\Types\CallbackQuery;
use TelegramBot\Api\Types\ForceReply;
use TelegramBot\Api\Types\Inline\InlineKeyboardMarkup;
use TelegramBot\Api\Types\Inline\InlineQuery;
use TelegramBot\Api\Types\Inline\QueryResult\AbstractInlineQueryResult;
use TelegramBot\Api\Types\Message;
use TelegramBot\Api\Types\ReplyKeyboardHide;
use TelegramBot\Api\Types\ReplyKeyboardMarkup;
use TelegramBot\Api\Types\Update;

class Telebot
{

    public $matches = [];
    public $cron = [
        'm' => [],
        'h' => [],
        'd' => []
    ];
    /** @var $telegram BotApi|Client|null */
    public $telegram = null;
    public $commandAlias = [];
    /** Runtime Settings */
    public $run = true;
    public $maxErrors = 10;
    public $currentErrors = 0;
    public $count = 1;
    public $stepDelay = 3;
    protected $runParams = [
        'daemon' => false,
        'help' => false,
        'write-initd' => false,
    ];
    /** @var Data|null */
    protected $db = null;
    /** @var Event $e Текущее сообщение */
    protected $e;
    /** @var Update $update */
    protected $update;
    protected $asks = [];
    protected $asksUsers = [];
    protected $asksAnswers = [];
    protected $inlineAnswers = [];
    private $lastUpdateId = null;

    public function __construct($appName, $description, $author, $email, $options = [])
    {
        if ($this->getRunArg('help')) {
            echo 'Usage: ' . $_SERVER['argv'][0] . ' [runmode]' . "\n";
            echo 'Available runtime options:' . "\n";
            foreach ($this->getRunArg() as $runmod => $val) {
                echo ' --' . $runmod . "\n";
            }
            die();
        }
        ini_set("default_charset", "UTF-8");

        $runtimeDir = getcwd() . DIRECTORY_SEPARATOR . 'runtime';

        if (!file_exists($runtimeDir))
            mkdir($runtimeDir, 0777, true);

        $this->fallbackSignalConstRegister();
        $options = array_merge(array(
            'appName' => $appName,
            'appDescription' => $description,
            'authorName' => $author,
            'authorEmail' => $email,
            'appDir' => realpath(__DIR__ . '/..'),
            'sysMaxExecutionTime' => '0',
            'sysMaxInputTime' => '0',
            'sysMemoryLimit' => '1024M',
            'logLocation' => $runtimeDir . DIRECTORY_SEPARATOR . 'bot.log'
        , $options));
        unset($options[0]);
        System_Daemon::setOptions($options);

        if ($this->getRunArg('write-initd')) {
            if (($initd_location = System_Daemon::writeAutoRun()) === false) {
                System_Daemon::notice('unable to write init.d script');
            } else {
                System_Daemon::info(
                    'sucessfully written startup script: %s',
                    $initd_location
                );
            }
            exit();
        }

        if ($this->getRunArg('daemon')) {
            System_Daemon::start();
        }

        $this->db = $db = new Data([
            'template' => realpath(__DIR__ . '/../files') . DIRECTORY_SEPARATOR . 'template.data.json'
        ]);
        $apiKey = $db->get('config.api');
        if (empty($apiKey) || $apiKey == 'TELEGRAM_BOT_API_KEY') throw new Exception('Please set config.api key in data.json config');

        /** @var BotApi|Client $bot */
        $bot = new Basic($db->get('config.api'));
        $this->telegram = $bot;
    }

    public function getRunArg($key = null)
    {

        if (isset($_SERVER['argv'])) {
            // Scan command line attributes for allowed arguments
            foreach ($_SERVER['argv'] as $k => $arg) {
                $this->runParams[$k] = $arg;
                if (substr($arg, 0, 2) == '--' && isset($this->runParams[substr($arg, 2)])) {
                    $this->runParams[substr($arg, 2)] = true;
                }
            }
        }

        return $key !== null ? (isset($this->runParams[$key]) ? $this->runParams[$key] : null) : $this->runParams;
    }

    protected function fallbackSignalConstRegister()
    {
        if (!defined('SIGHUP')) {
            define('WNOHANG', 1);
            define('WUNTRACED', 2);
            define('SIG_IGN', 1);
            define('SIG_DFL', 0);
            define('SIG_ERR', -1);
            define('SIGHUP', 1);
            define('SIGINT', 2);
            define('SIGQUIT', 3);
            define('SIGILL', 4);
            define('SIGTRAP', 5);
            define('SIGABRT', 6);
            define('SIGIOT', 6);
            define('SIGBUS', 7);
            define('SIGFPE', 8);
            define('SIGKILL', 9);
            define('SIGUSR1', 10);
            define('SIGSEGV', 11);
            define('SIGUSR2', 12);
            define('SIGPIPE', 13);
            define('SIGALRM', 14);
            define('SIGTERM', 15);
            define('SIGSTKFLT', 16);
            define('SIGCLD', 17);
            define('SIGCHLD', 17);
            define('SIGCONT', 18);
            define('SIGSTOP', 19);
            define('SIGTSTP', 20);
            define('SIGTTIN', 21);
            define('SIGTTOU', 22);
            define('SIGURG', 23);
            define('SIGXCPU', 24);
            define('SIGXFSZ', 25);
            define('SIGVTALRM', 26);
            define('SIGPROF', 27);
            define('SIGWINCH', 28);
            define('SIGPOLL', 29);
            define('SIGIO', 29);
            define('SIGPWR', 30);
            define('SIGSYS', 31);
            define('SIGBABY', 31);
            define('PRIO_PGRP', 1);
            define('PRIO_USER', 2);
            define('PRIO_PROCESS', 0);
        }
    }

    /**
     * Method runs just before bot start
     */
    protected function beforeStart() {

    }

    /**
     * Starts the bot
     */
    public function start()
    {
        $bot = $this->telegram;
        $this->beforeStart();

        if (php_sapi_name() == "cli") {
            //Deamon mode
            while (!System_Daemon::isDying() && $this->run) {
                try {
                    $start = microtime(true);
                    $updates = $bot->getUpdates($this->lastUpdateId);
                    foreach ($updates as $update) {
                        $this->lastUpdateId = $update->getUpdateId() + 1;
                        $this->handleUpdate($update);
                    }
                    $processingTime = microtime(true) - $start;
                    $sleep = $this->stepDelay - $processingTime;
                    $this->count += $processingTime;

                    if ($sleep > 0) {
                        System_Daemon::iterate($sleep);
                        $this->count += $sleep;
                    }
                } catch (HttpException $e) {
                    System_Daemon::err("Http Telegram Exception while communicating with Telegram API: %s\nTrace: %s", $e->getMessage(), $e->getTraceAsString());
                    $this->checkErrorsCount();
                } catch (Exception $e) {
                    System_Daemon::err("General exception while handling update: %s\nTrace: %s", $e->getMessage(), $e->getTraceAsString());
                    $this->checkErrorsCount();
                }
            }
            System_Daemon::stop();
        } else {
            //Webhook Mode
            $request = file_get_contents("php://input");
            if (empty($request))
                $this->sendErrorResponse('Empty request');
            $update = json_decode($request, true);
            if (!$update)
                $this->sendErrorResponse('Invalid request');
            if (!Dot::getValue($update, 'update_id')) {
                System_Daemon::err("Invalid incoming request: %s", $request);
                $this->sendErrorResponse('Invalid request');
            }
            System_Daemon::info("Incoming Request: %s", $request);
            $update = Update::fromResponse($update);
            $this->handleUpdate($update);
        }
    }

    /**
     * Handle Update Message
     * @param Update $update
     */
    public function handleUpdate($update)
    {
        $this->update = $update;
        $message = $update->getMessage();
        $fromName = $this->getFromName($message, true, true);
        if ($update->getEditedMessage()) {
            System_Daemon::info('[%s][SKIP] Skipping edited message %s from %s', $update->getUpdateId(),
                $update->getEditedMessage()->getText(), $fromName);
            return;
        }
        if (method_exists($this, 'addUser'))
            call_user_func([$this, 'addUser'], $this->getUserId(), $this->getFromName());
        if ($inlineQuery = $update->getInlineQuery()) {
            System_Daemon::info('[%s][OK] Received inline query %s from user %s', $update->getUpdateId(),
                $inlineQuery->getQuery(), $fromName);
            if ($result = $this->handleInlineQuery($inlineQuery)) {
                $this->telegram->answerInlineQuery($inlineQuery->getId(), $result);
            }
        } elseif ($cbq = $update->getCallbackQuery()) {
            $replyForMessageId = $cbq->getMessage()->getMessageId();
            if ($cb = @$this->inlineAnswers[$replyForMessageId]) {
                System_Daemon::info('[%s][OK] Received inline answer for message %s with data %s from user %s', $update->getUpdateId(),
                    $replyForMessageId, $cbq->getData(), $fromName);
                call_user_func($cb, new AnswerInline($cbq, $this));
            }
        } elseif (($message = $update->getMessage()) && is_object($message)) {
            if ($message->getText()) {
                if (!$this->getConfig('config.protect', false) || $this->isMessageAllowed($message)) {
                    System_Daemon::info('[%s][OK] Received message %s from trusted user %s',
                        $update->getUpdateId(), $message->getText(), $fromName);
                    $this->handle($update->getMessage());
                } else {
                    System_Daemon::info('[%s][SKIP] Skipping message %s from untrusted user %s',
                        $update->getUpdateId(), $message->getText(), $fromName);
                }
            } else {
                System_Daemon::warning('[%s][WARN] Message with empty body: %s',
                    $update->getUpdateId(), json_encode($message, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
        } else {
            System_Daemon::err('[%s][ERROR] Cannot handle message. Update Info: %s',
                $update->getUpdateId(), json_encode($update, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * Получить наименование автора сообщения
     * @param $message
     * @param bool $username
     * @param bool $id
     * @return string
     */
    protected function getFromName($message = null, $username = false, $id = false)
    {
        if (!$message) {
            $message = $this->getContext();
        }
        if (!$message) {
            System_Daemon::warning('Cannot detect from name - unhandled message type %s', $this->update->toJson());
            return '';
        }
        $from = $message->getFrom();
        $fromName = $from->getFirstName()
            . ($from->getLastName() ? ' ' . $from->getLastName() : '');
        if ($username) $fromName .= ($from->getUsername() ? ' @' . $from->getUsername() : '');
        if ($id) $fromName .= ' (' . $from->getId() . ')';
        return $fromName;
    }

    /**
     * Get current update type Context
     * @return BaseType|Message|InlineQuery|CallbackQuery|null
     */
    protected function getContext()
    {
        $message = null;
        if ($this->update->getInlineQuery()) {
            $message = $this->update->getInlineQuery();
        } else if ($this->update->getCallbackQuery()) {
            $message = $this->update->getCallbackQuery();
        } else if ($this->update->getMessage()) {
            $message = $this->update->getMessage();
        } else if ($this->update->getEditedMessage()) {
            $message = $this->update->getEditedMessage();
        }
        return $message;
    }

    /**
     * Вернуть идентификатор текущего пользователя
     * @return int|null
     */
    protected function getUserId()
    {
        if (!$this->update)
            return null;
        $message = $this->getContext();
        return $message->getFrom()->getId();
    }

    /**
     * Handler for incoming inline queries
     * @param InlineQuery $inlineQuery
     * @return false|AbstractInlineQueryResult[]
     */
    protected function handleInlineQuery(InlineQuery $inlineQuery)
    {
        System_Daemon::warning('Inline query handler not implemented');
        return false;
    }

    /**
     * Проверяет право на прием сообщения
     * @param Message $message
     * @return bool
     */
    protected function isMessageAllowed(Message $message)
    {
        if (($whiteListGroups = $this->getConfig('config.whiteGroups', [])) && in_array($message->getChat()->getId(), $whiteListGroups))
            return true;
        $trustedUsers = array_merge([$this->getConfig('config.globalAdmin', [])],
            $this->getConfig('config.admins', []),
            $this->getConfig('config.trust', [])
        );
        if (in_array($this->getUserId(), $trustedUsers))
            return true;
        return false;
    }

    /**
     * Обертка для получения конфигурации
     * @param $key
     * @param $default
     * @return mixed
     */
    public function getConfig($key, $default = null)
    {
        return $this->db->get($key, $default);
    }

    /**
     * Обработка команды
     * @param Message $message
     */
    public function handle($message)
    {
        //Regular Message
        $command = $message->getText();
        $commandParts = explode(" ", $command);
        $this->e = $e = new Event();
        $e->setMessage($message);
        $e->setArgs($commandParts);

        $replyToId = null;
        $replyMatch = 'unknown';
        if ($message->getText()[0] != '/' && $message->getReplyToMessage()) {
            //Получен ответ на конкретное сообщение
            $replyToId = $message->getReplyToMessage()->getMessageId();
            $replyMatch = 'To Message Id';
        } elseif (isset($this->asksAnswers[$message->getText()])) {
            //Проверка на ожидание вариантов ответа
            $replyToId = $this->asksAnswers[$message->getText()];
            $replyMatch = 'By Text Answer';
        } elseif ($message->getText()[0] != '/' && $this->isChatPrivate() && isset($this->asksUsers[$this->getUserId()])) {
            //Проверка на ожидание сообщения конкретного пользователя
//            $replyToId = $this->asksUsers[$this->getUserId()];
//            $replyMatch = 'By Waiting User Input';
        }
        if ($replyToId && isset($this->asks[$replyToId])) {
            //ReplyTo Message
            System_Daemon::info('[REPLY] Got reply (%s) to message %s - %s', $replyMatch, $this->asks[$replyToId]['question'], $message->getText());
            $replyData = $this->asks[$replyToId];
            /* @var Event $e */
            $e = $replyData['e'];
            $replyText = trim($message->getText());
            $callback = $replyData['callback'];
            if (!$replyData['multiple']) {
                unset($this->asks[$replyToId]);
                unset($this->asksUsers[$this->getUserId()]);
                $this->asksAnswers = [];
            }
            if (is_callable($callback) || is_array($callback)) {
                call_user_func($callback, new Answer($message, $replyData));
            } else {
                $paramName = $replyData['paramName'];
                $newMessage = clone $e->getMessage();
                $newMessage->setText($newMessage->getText() . ' ' . ($paramName ? '--' . $paramName . '=' : '') . $replyText);
                $this->handle($newMessage);
            }
        } else {
            if ($command[0] == "/") {
                $command = mb_substr(array_shift($commandParts), 1);
                if (($atPos = mb_stripos($command, '@'))) $command = mb_substr($command, 0, $atPos);
                $command = $this->toCamel($command);
                $commandName = $command . 'Command';
                if (isset($this->commandAlias[$command])) $commandName = $this->commandAlias[$command];
                if ($this->commandExist($commandName) && $this->isCommandAllowed($commandName, $this->getUserId())) {
                    System_Daemon::info('[RUN] Running %s with %s arguments', $commandName, count($commandParts));
                    try {
                        call_user_func([$this, $commandName]);
                    } catch (\Exception $ex) {
                        $this->reply(sprintf('Ошибка выполнения команды: %s', $ex->getMessage()));
                    }
                }
            } else {
                foreach ($this->matches as $expression => $commandName) {
                    if (preg_match($expression, $command, $matches)) {
                        if ($this->commandExist($commandName)) {
                            System_Daemon::info('[RUN] Running %s matched by %s', $commandName, $expression);
                            try {
                                call_user_func_array([$this, $commandName], [$matches]);
                            } catch (\Exception $ex) {
                                $this->reply(sprintf('Ошибка выполнения команды: %s', $ex->getMessage()));
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Is chat is private
     * @return bool
     */
    protected function isChatPrivate()
    {
        return $this->getChatType() == 'private';
    }

    /**
     * Get current chat type
     * @return string
     * Type of chat, can be either “private”, “group”, “supergroup” or “channel”
     */
    protected function getChatType()
    {
        if (!$this->e) return null;
        return $this->e->getMessage()->getChat()->getType();
    }

    /**
     * Convert to camel
     * @param $input
     * @return mixed
     */
    protected function toCamel($input)
    {
        $camel = preg_replace_callback('/(_)([a-z])/', function ($m) {
            return strtoupper($m[2]);
        }, $input);
        $camel[0] = strtolower($camel[0]);
        return $camel;
    }

    private function commandExist($commandName)
    {
        $class = new ReflectionClass($this);
        return $class->hasMethod($commandName);
    }

    /**
     * Check for command allowance
     * @param $methodName
     * @param null $user
     * @return bool
     */
    protected function isCommandAllowed($methodName, $user = null)
    {
        if (!$user)
            $user = $this->getUserId();

        if (!$user) {
            System_Daemon::warning('[ACCESS][DENY] Deny Access for command %s - empty user', $methodName);
            return false;
        }

        $method = new ReflectionMethod($this, $methodName);
        $doc = $method->getDocComment();
        if (strpos($doc, '@global-admin') !== false && !$this->isGlobalAdmin()) {
            System_Daemon::warning('[ACCESS][DENY] Deny Access for command with global admin access level %s', $methodName);
            return false;
        }
        if (strpos($doc, '@admin') !== false && !$this->isAdmin()) {
            System_Daemon::warning('[ACCESS][DENY] Deny Access for command with admin access level %s', $methodName);
            return false;
        }
        if (strpos($doc, '@private') !== false && !$this->isChatPrivate()) {
            System_Daemon::warning('[ACCESS][DENY] Deny Access for private-command only %s', $methodName);
            return false;
        }
        if (in_array($methodName, ['addCron', 'cron', 'run', 'handle', '__construct'])) {
            System_Daemon::warning('[ACCESS][DENY] Deny Access for blacklisted command %s', $methodName);
            return false;
        }
        return true;
    }

    /**
     * Является или текущий запрос от суперадмина
     * @return bool
     */
    protected function isGlobalAdmin()
    {
        if (!$this->e) return false;
        return $this->getUserId() == $this->getConfig('config.globalAdmin');
    }

    /**
     * Является или текущий запрос от админа
     * @return bool
     */
    protected function isAdmin()
    {
        if (!$this->e) return false;
        return $this->isGlobalAdmin() || in_array($this->getUserId(), $this->getConfig('config.admins', []));
    }

    /**
     * Reply to user or chat
     * @param $text
     * @param Event|int $e
     * @param null|ReplyKeyboardMarkup|ForceReply|InlineKeyboardMarkup $markup
     * @return Message|false
     */
    public function reply($text, $e = null, $markup = null, $markdown = 'HTML')
    {
        System_Daemon::info('[REPLY] %s', $text);
        $target = $this->getTarget($e);
        if (!$target)
            return;
        return $this->sendMessage($target, $text, $markdown, false, null, $markup);
    }

    /**
     * Reply to last chat message
     * @param $text
     * @param null $markup
     * @param string $markdown
     */
    public function replyToLastMessage($text, $markup = null, $markdown = 'HTML')
    {
        return $this->reply($text, null, $markup, $markdown);
    }

    /**
     * Reply to last chat message with markdown parser
     * @param $text
     */
    public function replyToLastMessageWithMarkdown($text)
    {
        $this->replyToLastMessage($text, null, 'markdown');
    }

    /**
     * Use this method to send text messages. On success, the sent \TelegramBot\Api\Types\Message is returned.
     *
     * @param int|string $to
     * Target chat id
     * @param string $message
     * Your message
     * @param string|null $parseMode
     * How telegram will parse your message. Should be html or markdown
     * @param bool $disablePreview
     * Disable preview of links
     * @param int|null $replyToMessageId
     * Reply to message
     * @param Types\ReplyKeyboardMarkup|Types\ReplyKeyboardHide|Types\ForceReply|null $replyMarkup
     * Use reply markup
     * @param bool $disableNotification
     *
     * @return \TelegramBot\Api\Types\Message
     * Returns last sended message Object
     * @throws \TelegramBot\Api\InvalidArgumentException
     * @throws \TelegramBot\Api\Exception
     */
    public function sendMessage(
        $to,
        $message,
        $parse = 'HTML',
        $disablePreview = false,
        $replyToMessageId = null,
        $replyMarkup = null,
        $disableNotification = false,
        $allowChunks = true
    )
    {
        if (mb_strlen($message) > 4096) {
            if ($allowChunks) {
                $chunks = mb_split($message, 4096);
                $last = null;
                foreach ($chunks as $message) {
                    $last = $this->telegram->sendMessage($to, $message, $parse, $disablePreview, $replyToMessageId, $replyMarkup, $disableNotification);
                }
                return $last;
            } else {
                $message = mb_substr($message, 0, 4096);
            }
        }
        return $this->telegram->sendMessage($to, $message, $parse, $disablePreview, $replyToMessageId, $replyMarkup, $disableNotification);
    }

    /**
     * @param $e
     * @return bool|int|string
     */
    protected function getTarget($e = null)
    {
        if ($e === null) {
            if ($chatId = $this->getChatId())
                return $chatId;
            //Fallback to Event Data
            $e = $this->e;
        }
        if ($e instanceof Event) {
            $target = $e->getUserId();
        } elseif (is_numeric($e)) {
            $target = $e;
        } else {
            return false;
        }
        return $target;
    }

    /**
     * Check errors count and return if we must stop deamon execution
     * @return bool
     */
    protected function checkErrorsCount()
    {
        if (++$this->currentErrors >= $this->maxErrors)
            $this->run = false;
    }

    /**
     * @return \TelegramBot\Api\BotApi
     */
    public function getTelegram()
    {
        return $this->telegram;
    }

    /**
     * @param BotApi $telegram
     */
    public function setTelegram($telegram)
    {
        $this->telegram = $telegram;
    }

    /**
     * @param $command
     * @param string $type
     * Частота выполнения:
     * m - раз в минуту
     * h - раз в час
     * d - раз в день
     */
    public function addCron($command, $type = 'm')
    {
        $this->cron[$type][] = $command;
    }

    /**
     * Get config for chat scope
     * @param $key
     * @param null $default
     * @param null $chatId
     * @return mixed
     */
    public function getChatConfig($key, $default = null, $chatId = null)
    {
        if ($chatId === null) $chatId = $this->getChatId();
        $key = 'chat.' . $chatId . '.' . $key;
        return $this->db->get($key, $default);
    }

    /**
     * Вернуть идентификатор текущего чата
     * @return int|null
     */
    protected function getChatId()
    {
        if ($context = $this->getContext()) {
            if (method_exists($context, 'getMessage')) {
                return $context->getMessage()->getChat()->getId();
            } elseif (method_exists($context, 'getChat') && $context->getChat()) {
                return $context->getChat()->getId();
            } else {
                return null;
            }
        }
        return null;
    }

    /**
     * Обертка для установки конфигурации
     * @param $key
     * @param $value
     * @param bool $save
     * @return mixed
     */
    public function setConfig($key, $value, $save = true)
    {
        return $this->db->set($key, $value, $save);
    }

    /**
     * Обертка для установки конфигурации для текущего чата
     * @param $key
     * @param $value
     * @param bool $save
     * @return mixed
     */
    public function setChatConfig($key, $value, $save = true, $chatId = null)
    {
        if ($chatId === null) $chatId = $this->getChatId();
        $key = 'chat.' . $chatId . '.' . $key;
        return $this->db->set($key, $value, $save);
    }

    /**
     * Обертка для установки конфигурации на уровне чата
     * @param $key
     * @param $value
     * @param bool $save
     * @return mixed
     */
    public function addChatConfig($key, $value, $save = true, $chatId = null)
    {
        if ($chatId === null) $chatId = $this->getChatId();
        $key = 'chat.' . $chatId . '.' . $key;
        return $this->db->add($key, $value, false, $save);
    }

    /**
     * Обертка для удаления конфигурации
     * @param $key
     * @param bool $save
     * @return mixed
     */
    public function deleteConfig($key, $save = true)
    {
        return $this->db->delete($key, $save);
    }

    /**
     * Обертка для удаления конфигурации на уровне чата
     * @param $key
     * @param bool $save
     * @return mixed
     */
    public function deleteChatConfig($key, $save = true, $chatId = null)
    {
        if ($chatId === null) $chatId = $this->getChatId();
        $key = 'chat.' . $chatId . '.' . $key;
        return $this->db->delete($key, $save);
    }

    public function cron($type = 'm')
    {
        if (isset($this->cron[$type]) && is_array($this->cron[$type])) {
            foreach ($this->cron[$type] as $cronJob) {
                if ($this->commandExist($cronJob)) {
                    System_Daemon::info('[CRON][%s] Executing cron job %s', $type, $cronJob);
                    call_user_func([$this, $cronJob]);
                }
            }
        }
    }

    /**
     * Reply to user or chat and clear keyboard
     * @param $text
     * @return false|Message
     */
    public function replyAndHide($text)
    {
        $this->reply($text, null, new ReplyKeyboardHide());
    }

    /**
     * Update message by id
     * @param $id
     * @param $text
     * @param string $parse
     * @param bool $disablePreview
     * @param null $markup
     */
    public function updateMessage($id, $text, $parse = 'html', $disablePreview = true, $markup = null)
    {
        $target = $this->getTarget();
        if (!$target)
            return;
        try {
            $this->telegram->editMessageText($target, $id, $text, $parse, $disablePreview, $markup);
        } catch (HttpException $e) {
            if (($e->getCode() == 400 && $e->getMessage() == 'Bad Request: message is not modified')) {
                System_Daemon::info('Message %s was not modified', $id);
            } else {
                throw $e;
            }
        }
    }

    /**
     * Update message Reply Markup
     * @param $id
     * @param $markup
     */
    public function updateMessageReplyMarkup($id, $markup)
    {
        $target = $this->getTarget($e);
        if (!$target)
            return;
        $this->telegram->editMessageReplyMarkup($target, $id, $markup);
    }

    /**
     * @param null $chatId
     * @param string $file
     * @param Event $e
     */
    public function sendDocument($chatId = null, $file, $e = null)
    {
        System_Daemon::info('[SEND_DOCUMENT] chat_id: %s, document: %s', $chatId, $file);
        if (!$e) $e = $this->e;
        $chatId = !is_null($chatId) ? $chatId : $e->getUserId();
        $file = new \CURLFile(realpath($file));
        $this->telegram->sendDocument($chatId, $file);
    }

    /**
     * Запросить уточнение
     * @param string $text
     * Текст сообщения
     * @param array $answers
     * Варианты ответа
     * @param callable|null $callback
     * Имя параметра, с которым вернется
     * @param bool $multiple
     * Ожидать несколько ответов
     * @param bool $useReplyMarkup
     * Отобразить вопрос в виде ответа на сообщение
     * @return Message
     */
    public function ask($text, $answers = [], $callback = null, $multiple = false, $useReplyMarkup = false)
    {
        System_Daemon::info('[ASK] %s', $text . (!empty($answers) ? ' with answers: ' . var_export($answers, true) : ''));
        $e = $this->e;
        if ($answers instanceof ReplyKeyboardMarkup) {
            $rm = $answers;
            $answers = $rm->getKeyboard();
        } elseif (!empty($answers) && is_array($answers)) {
            if (!empty($answers) && is_array($answers) && !is_array($answers[0])) $answers = [$answers];
            $rm = new ReplyKeyboardMarkup($answers, true, true, true);
        } else {
            $rm = new ForceReply(true, false);
            $useReplyMarkup = true;
        }
        if ($multiple) $rm->setOneTimeKeyboard(false);
        $send = $this->sendMessage($e->getUserId(), $text, 'HTML', true, !empty($answers) || $useReplyMarkup ? $e->getMessage()->getMessageId() : null, $rm);
        $this->addWaitingReply($send->getMessageId(), $text, $answers, $callback, $multiple);
        return $send;
    }

    /**
     * Добавить ожидание ответа
     * @param string $text
     * Текст сообщения
     * @param array $answers
     * Варианты ответа
     * @param callable|null $callback
     * Имя параметра, с которым вернется
     * @param bool $multiple
     */
    protected function addWaitingReply($askMessageId, $text, $answers, $callback = null, $multiple = false)
    {
        $e = $this->e;
        $payload = [
            'id' => $askMessageId,
            'question' => $text,
            'callback' => $callback,
            'e' => $e,
            'user' => $e->getUserId(),
            'answers' => $answers,
            'multiple' => $multiple
        ];

        $this->asks[$askMessageId] = $payload;
        $this->asksUsers[$e->getUserId()] = $askMessageId;
        $this->asksAnswers = [];
        if (!empty($answers) && is_array($answers)) {
            foreach ($answers as $group) {
                foreach ($group as $answer) {
                    $this->asksAnswers[$answer] = $askMessageId;
                }
            }
        }
    }

    /**
     * Check if we got callback for messageId
     * @param $messageId
     * @return bool
     */
    protected function hasWaitingReply($messageId)
    {
        return isset($this->ask[$messageId]) || isset($this->inlineAnswers[$messageId]);
    }

    /**
     * Stop waiting for user input
     * @param Answer $answer
     */
    protected function stopWaitForReplyByAnswer(Answer $answer)
    {
        $this->stopWaitForReply($answer->getAskMessageId());
    }

    /**
     * Stop waiting for user input
     * @param $messageId
     */
    protected function stopWaitForReply($messageId)
    {
        unset($this->ask[$messageId]);
        if ($keys = array_keys($this->asksUsers, $messageId)) {
            foreach ($keys as $key)
                unset($this->asksUsers[$key]);
        }
        if ($keys = array_keys($this->asksAnswers, $messageId)) {
            foreach ($keys as $key)
                unset($this->asksAnswers[$key]);
        }
    }

    /**
     * Запросить уточнение путем размещения Inline кнопок под сообщением
     * @param string $text
     * Текст сообщения
     * @param array $answers
     * Варианты ответа
     * @param callable|array|null $callback
     * Имя параметра, с которым вернется
     * @return Message
     */
    public function askInline($text, $answers = [], $callback = null)
    {
        System_Daemon::info('[ASK] %s', $text . (!empty($answers) ? ' with answers: ' . json_encode($answers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : ''));
        $e = $this->e;
        if (is_array($answers)) {
            $answers = new InlineKeyboardMarkup($answers);
        }
        if (!($answers instanceof InlineKeyboardMarkup)) {
            throw new \InvalidArgumentException('Invalid type of inline markup: ' . var_export($answers, true));
        }
        $send = $this->telegram->sendMessage($e->getUserId(), $text, 'HTML', true, null, $answers);
        if ($callback) $this->inlineAnswers[$send->getMessageId()] = $callback;
        return $send;
    }


    /**
     * Update message with text and inline keyboard
     * @param $id
     * @param $text
     * @param $markup
     */
    public function updateInlineMessage($id, $text, $markup)
    {
        if (is_array($markup)) {
            $markup = new InlineKeyboardMarkup($markup);
        }
        if (!($markup instanceof InlineKeyboardMarkup)) {
            throw new \InvalidArgumentException('Invalid type of inline markup: ' . var_export($answers, true));
        }
        $this->updateMessage($id, $text, 'html', true, $markup);
    }
    
    /**
     * Add user to Trust list
     * @param null $e
     * @global-admin
     * @throws \Exception
     */
    public function trustCommand()
    {
        $args = $this->e->getArgs();
        if (!isset($args[1]) || !is_numeric($args[1])) throw new \Exception('Please provide user id');
        $user = $args[1];
        $this->db->add('config.trust', $user);
        $this->reply(sprintf('User %s now in trust list', $user));
    }

    /**
     * Allow bot to recieve messages from all users from this chat
     * @global-admin
     * @param Event $e
     * @param null $chatId
     */
    public function allowChatCommand($chatId = null)
    {
        if (!$chatId) $chatId = $this->getChatId();
        if ($this->isGlobalAdmin()) {
            $this->addConfig('config.whiteGroups', $chatId);
            $this->reply('Группа ' . $chatId . ' теперь доступна бота');
        }
    }

    /**
     * Обертка для установки конфигурации
     * @param $key
     * @param $value
     * @param bool $save
     * @return mixed
     */
    public function addConfig($key, $value, $save = true)
    {
        return $this->db->add($key, $value, false, $save);
    }

    /**
     * Remove user from trust list
     * @param null $e
     * @global-admin
     * @throws \Exception
     */
    public function untrustCommand()
    {
        $args = $this->e->getArgs();
        if (!isset($args[1]) || !is_numeric($args[1])) throw new \Exception('Please provide user id');
        $user = $args[1];
        foreach ($this->getConfig('config.trust', []) as $k => $trustedUser) {
            if ($trustedUser == $user) {
                unset($this->db['config']['trust'][$k]);
                $this->reply(sprintf('User %s has been removed from trust list', $user));
                $this->db->save();
                return;
            }
        }
        $this->reply(sprintf('User %s not found in trust list', $user));
    }

    /**
     * Описание доступных методов
     */
    public function startCommand()
    {
        $class = new ReflectionClass($this);
        $commands = [];
        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_FINAL) as $method) {
            if (strpos($method->name, 'Command') === false) continue;
            $doc = $method->getDocComment();
            if (!$this->isCommandAllowed($method->name, $this->getUserId())) continue;
            $botCommand = $this->deCamel(str_replace('Command', '', $method->name));
            $lines = explode("\n", $this->cleanDoc($doc));
            $commands[] = sprintf("/%s - <i>%s</i>", $botCommand, $lines[0]);
        }
        $this->reply(implode("\n", $commands));
    }

    /**
     * Convert from camel
     * @param $input
     * @return string
     */
    protected function deCamel($input)
    {
        preg_match_all('!([A-Z][A-Z0-9]*(?=$|[A-Z][a-z0-9])|[A-Za-z][a-z0-9]+)!', $input, $matches);
        $ret = $matches[0];
        foreach ($ret as &$match) {
            $match = $match == strtoupper($match) ? strtolower($match) : lcfirst($match);
        }
        return implode('_', $ret);
    }

    /**
     * Clean up PhpDoc comments
     * @param $doc
     * @return string
     */
    protected function cleanDoc($doc)
    {
        return trim(str_replace(['/*', '*/', '*'], '', $doc));
    }

    /**
     * Получить параметры из запроса
     * @param $e
     * @param bool $asArray
     * @return mixed
     */
    protected function getParams($e, $asArray = false)
    {
        $args = $e['args'];
        $params = implode(' ', array_slice($args, 1));
        $params = str_replace('—', '--', $params);
        if ($asArray) $params = explode(' ', $params);
        return $params;
    }

    /**
     * Is chat is group
     * @return bool
     */
    protected function isChatGroup()
    {
        return $this->getChatType() == 'group';
    }

    /**
     * Is chat is supergroup
     * @return bool
     */
    protected function isChatSuperGroup()
    {
        return $this->getChatType() == 'supergroup';
    }

    /**
     * Is chat is a channel
     * @return bool
     */
    protected function isChatChannel()
    {
        return $this->getChatType() == 'channel';
    }

    /**
     * Получить ID пользователя или группы из события
     * @param Event $e
     * @return bool
     */
    protected function getUserIdFromEvent($e = null)
    {
        if (!$e) $e = $this->e;
        if ($e) {
            System_Daemon::debug('user is %s', $e->getUserId());
            return $e->getUserId();
        } else {
            return false;
        }
    }

    /**
     * Получение аргумента текущего запроса
     * @param $key
     * @param bool $default
     * @return bool|string
     */
    protected function getArgument($key, $default = false)
    {
        return $this->e->getArgument($key, $default);
    }

    /**
     * Finish web request with error
     * @param $message
     * @param int $code
     */
    protected function sendErrorResponse($message, $code = 0) {
        $this->sendResponse(array_merge([
            'success' => false,
            'error' => $message,
            'code' => $code
        ]));
    }

    /**
     * Send web response
     * @param $result
     */
    protected function sendResponse($result = [])
    {
        header("Content-Type: application/json; charset=UTF-8", true);
        if (!is_array($result)) $result = [];
        $result = array_merge([
            'success' => true
        ], $result);
        $response = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        echo $response;
        exit();
    }

}
