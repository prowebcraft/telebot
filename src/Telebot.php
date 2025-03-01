<?php

namespace Prowebcraft\Telebot;

use Monolog\ErrorHandler;
use Monolog\Handler\LogEntriesHandler;
use Monolog\Handler\LogglyHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Prowebcraft\Dot;
use Prowebcraft\Telebot\Clients\Basic;
use Exception;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Translation\Loader\CsvFileLoader;
use Symfony\Component\Translation\Translator;
use TelegramBot\Api\BaseType;
use TelegramBot\Api\BotApi;
use TelegramBot\Api\Client;
use TelegramBot\Api\Http\HttpClientInterface;
use TelegramBot\Api\HttpException;
use TelegramBot\Api\InvalidArgumentException;
use TelegramBot\Api\Types\ArrayOfBotCommand;
use TelegramBot\Api\Types\ArrayOfPhotoSize;
use TelegramBot\Api\Types\BotCommand;
use TelegramBot\Api\Types\CallbackQuery;
use TelegramBot\Api\Types\Chat;
use TelegramBot\Api\Types\ChatMemberUpdated;
use TelegramBot\Api\Types\Document;
use TelegramBot\Api\Types\ForceReply;
use TelegramBot\Api\Types\Inline\InlineKeyboardMarkup;
use TelegramBot\Api\Types\Inline\InlineQuery;
use TelegramBot\Api\Types\Inline\QueryResult\AbstractInlineQueryResult;
use TelegramBot\Api\Types\Message;
use TelegramBot\Api\Types\PhotoSize;
use TelegramBot\Api\Types\ReplyKeyboardHide;
use TelegramBot\Api\Types\ReplyKeyboardMarkup;
use TelegramBot\Api\Types\ReplyParameters;
use TelegramBot\Api\Types\Update;
use TelegramBot\Api\Types\User;

class Telebot
{

    use Names;

    const MODE_WEBHOOK = 1;
    const MODE_DEAMON = 2;

    const PARSE_MARKDOWN_V2 = 'MarkdownV2';
    const PARSE_MARKDOWN_LEGACY = 'Markdown';
    const PARSE_MARKDOWN_HTML = 'HTML';

    public $matches = [];
    public $cron = [
        'm' => [],
        'h' => [],
        'd' => [],
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
    protected $runParams = [];
    /** @var Data|null */
    protected $db = null;
    /** @var Event $e Last Event */
    protected $e;
    /** @var Update $update Last Update */
    protected $update;
    protected $asks = [];
    protected $asksUsers = [];
    protected $asksAnswers = [];
    protected $inlineAnswers = [];
    private $lastUpdateId = null;
    protected $runMode = self::MODE_DEAMON;
    protected $options = [];
    protected $logger = null;
    /** @var null|Translator  */
    protected $translator = null;

    /**
     * Telebot constructor.
     * @param string $appName
     * Your bot name
     * @param string $description
     * @param string $author
     * @param string $email
     * @param array $options
     * @throws Exception
     */
    public function __construct($appName, $description = null, $author = null, $email = null, $options = [])
    {
        ini_set("default_charset", "UTF-8");

        $options = array_merge([
            'appName' => $appName,
            'appDescription' => $description,
            'authorName' => $author,
            'authorEmail' => $email,
            'appDir' => realpath(dirname(__FILE__) . '/../../../..'),
            'runtimeDir' => null,
            'logFile' => null,
            'dataFile' => null,
        ], $options);
        if ($options['runtimeDir'] === null) {
            $options['runtimeDir'] = $options['appDir'] . DIRECTORY_SEPARATOR . 'runtime';
        }
        $this->setOptions($options);
        $runtimeDir = $this->getOption('runtimeDir');
        if (!file_exists($runtimeDir))
            mkdir($runtimeDir, 0777, true);

        if (!$this->getOption('logFile')) {
            $this->setOption('logFile', 'bot.log');
        }
        if (!$this->getOption('dataFile')) {
            $this->setOption('dataFile', 'data.json');
        }

        //Init Logger
        $this->initLogger();
    }

    /**
     * Protects daemon by clearing statcache. Can optionally
     * be used as a replacement for sleep as well.
     *
     * @param integer $sleepSeconds
     * Optionally put your daemon to rest for X s.
     *
     * @return bool
     * @see start()
     * @see stop()
     */
    protected function iterate($sleepSeconds = 0)
    {
        if ($sleepSeconds >= 1) {
            sleep((int)$sleepSeconds);
        } else if (is_numeric($sleepSeconds)) {
            usleep((int)($sleepSeconds * 1000000));
        }

        clearstatcache();

        // Garbage Collection (PHP >= 5.3)
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }

        return true;
    }

    /**
     * Handle Update Message
     * @param Update $update
     */
    public function handleUpdate($update)
    {
        $this->update = $update;
        $message = $update->getMessage();
        if ($update->getEditedChannelPost()) {
            $this->info('[%s][SKIP] Skipping edited channel post %s - %s', $update->getEditedChannelPost()->getChat()->getId(),
                $update->getEditedChannelPost()->getMessageId(), $update->getEditedChannelPost()->getText());
            return;
        }
        try {
            if ($message && $message->getFrom() && $message->getFrom()->getLanguageCode()) {
                $this->setLocale($message->getFrom()->getLanguageCode());
            }
        } catch (\Throwable $e) {
            $this->error('Error setting locale - %s', $e->getMessage());
        }

        $fromName = $this->getFromName($message, true, true);
        $chatId = $this->getChatId();
        if ($update->getEditedMessage()) {
            $this->info('[%s][SKIP] Skipping edited message %s from %s', $chatId,
                $update->getEditedMessage()->getText(), $fromName);
            return;
        }
        if (empty($this->getConfig('config.owner'))) {
            if (!($ownerId = $this->getConfig('config.globalAdmin'))) {
                $ownerId = $this->getUserId();
                $this->warning('[OWNER] Greeting new bot owner - %s', $fromName);
            }
            $this->setConfig('config.owner', $ownerId);
            $this->deleteConfig('config.globalAdmin');
        }
        if (!$this->isChannel() && $userId = $this->getUserId()) {
            if (method_exists($this, 'addUser')) {
                call_user_func([$this, 'addUser'], $userId, $this->getFromName());
            }
        }
        if ($inlineQuery = $update->getInlineQuery()) {
            $this->info('[%s][OK] Received inline query %s from user %s', $chatId,
                $inlineQuery->getQuery(), $fromName);
            if ($result = $this->handleInlineQuery($inlineQuery)) {
                $this->telegram->answerInlineQuery($inlineQuery->getId(), $result);
            }
        } elseif ($cbq = $update->getCallbackQuery()) {
            $replyForMessageId = $cbq->getMessage()->getMessageId();
            if ($callback = @$this->inlineAnswers[$chatId][$replyForMessageId]) {
                $this->info('[%s][OK] Received inline answer for message %s with data %s from user %s', $chatId,
                    $replyForMessageId, $cbq->getData(), $fromName);
                $payload = [];
                if (is_array($callback) && isset($callback['callback'])) {
                    //New Format
                    $payload = $callback;
                    $callback = $callback['callback'];
                }
                if (is_string($callback) && method_exists($this, $callback))
                    $callback = [$this, $callback];
                if (is_callable($callback) || is_array($callback)) {
                    call_user_func($callback, new AnswerInline($cbq, $this, $payload));
                }
            }
        } elseif ((($message = $update->getMessage()) || ($this->isChannel() && ($message = $update->getChannelPost()))) && is_object($message)) {
            // handle incoming new message
            if ($this->isChannel() && !$this->getConfig('chat.' . $chatId)) {
                $this->info('[%s][NEW] New Channel has been revealed %s',
                    $chatId, $message->getChat()->getTitle());
                $this->onChannelCreated();
            }
            if (!$this->getConfig('config.protect', false) || $this->isMessageAllowed($message)) {
                if (!($this->isChannel() && $this->getConfig('config.skip_channel_messages'))) {
                    $this->info('[%s][OK] Received message %s from %s',
                        $chatId, $message->getText(), $fromName);
                    $this->handle($message);
                }

                // specific events
                if ($newMembers = $message->getNewChatMembers()) {
                    /** @var User $member */
                    foreach ($newMembers as $member) {
                        if ($member->getId() === $this->getBotId()) {
                            $this->info('[%s][NEW] Bot has been invited to a new group chat %s by %s',
                                $chatId, $message->getChat()->getTitle(), $fromName);
                            $this->onJoinChat();
                        } else {
                            $this->info('[%s][INFO] New Chat Member %s',
                                $chatId, $member->toJson(true));
                            $this->onNewChatMember($member);
                        }
                    }
                } else if ($message->isGroupChatCreated()) {
                    $this->info('[%s][NEW] Bot has been invited to a new group chat %s by %s',
                        $chatId, $message->getChat()->getTitle(), $fromName);
                    $this->onGroupChatCreated();
                } else if ($message->isSupergroupChatCreated()) {
                    $this->info('[%s][NEW] Bot has been invited to a new supergroup chat %s by %s',
                        $chatId, $message->getChat()->getTitle(), $fromName);
                    $this->onSuperGroupChatCreated();
                } else if ($message->isChannelChatCreated()) {
                    $this->info('[%s][NEW] Bot has been invited to a new channel %s by %s',
                        $chatId, $message->getChat()->getTitle(), $fromName);
                    $this->onChannelCreated();
                } else if (($oldId = $message->getMigrateFromChatId())) {
                    $this->info('[%s][NEW] Chat has been migrated to super group with id %s',
                        $chatId, $oldId);
                    $this->onMigrateToSuperGroup($oldId);
                } else if ($message->getNewChatPhoto()) {
                    $this->info('[%s][INFO] New chat photo is set by %s',
                        $chatId, $fromName);
                } else if ($message->getSticker()) {
                    $this->info('[%s][INFO] Sticker post %s',
                        $chatId, $fromName);
                } else if ($message->getNewChatTitle()) {
                    $this->info('[%s][INFO] Chat title changed from %s to %s',
                        $chatId, $this->getChatConfig('info.title'), $message->getNewChatTitle());
                    $this->setChatConfig('info.title', $message->getNewChatTitle());
                } else if ($message->getLeftChatMember()) {
                    $this->info('[%s][INFO] Chat Member has been Left %s',
                        $chatId, $message->getLeftChatMember()->toJson(true));
                    $this->onChatMemberLeft($message->getLeftChatMember());
                } else if ($message->getContact()) {
                    $this->info('[%s][INFO] Shared contact %s',
                        $chatId, $message->getContact()->toJson(true));
                } else if ($message->getDocument()) {
                    $this->info('[%s][INFO] Shared file %s',
                        $chatId, $message->getDocument()->toJson(true));
                    $this->onDocumentShare($message->getDocument(), $message);
                }  else if ($photos = $message->getPhoto()) {
                    $this->info('[%s][INFO] Shared photo %s', $chatId, json_encode($photos));
                    $this->onPhotoShare($photos[1], $photos[0], $message);
                } elseif (!$message->getText()) {
                    $this->info('[%s][INFO] Unsopported type of message: %s',
                        $chatId, $update->toJson(true),
                    );
                }
            } else {
                if ($this->getConfig('config.auto_request_access_from_owner')) {
                    $requestMessage = [];
                    $requestMessage[] = $this->__('Request from untrusted user');
                    $requestMessage[] = $this->getUserMention($this->getUserId());
                    $requestMessage[] = $this->__('Allow access?');
                    $this->sendMessage(
                        $this->getConfig('config.owner'),
                        implode("\n", $requestMessage),
                        'HTML',
                        true,
                        null,
                        new ReplyKeyboardMarkup([[[
                            'text' => '/trust ' . $this->getUserId(),
                        ]]], true, true),
                    );
                }
                $this->info('[%s][SKIP] Skipping message %s from untrusted user %s',
                    $chatId, $message->getText(), $fromName);
            }

        } elseif ($botChatMember = $update->getMyChatMember()) {
            $oldStatus = $botChatMember->getOldChatMember()->getStatus();
            $newStatus = $botChatMember->getNewChatMember()->getStatus();
            $this->info('[%s][INFO] Bot %s member status has been changed: %s => %s',
                $chatId,
                $this->getChatInfo($botChatMember->getChat()),
                $oldStatus,
                $newStatus,
            );
            $this->onBotChatMemberStatusChange($botChatMember, $oldStatus, $newStatus);
            if ($newStatus === 'left') {
                $this->onBotKick($botChatMember->getChat());
            }
        } else {
            $this->debug('[%s][ERROR] Cannot handle message. Update Info: %s',
                $update->getUpdateId(), json_encode($update, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * Get option
     * @param string $key
     * @param mixed $default
     * @return mixed|null
     */
    public function getOption($key, $default = null)
    {
        return isset($this->options[$key]) ? $this->options[$key] : $default;
    }

    /**
     * Set all options
     * @param array $options
     * @return $this
     */
    public function setOptions($options)
    {
        $this->options = $options;
        return $this;
    }

    /**
     * Set option
     * @param $key
     * @param $value
     * @return $this
     */
    public function setOption($key, $value)
    {
        $this->options[$key] = $value;
        return $this;
    }

    public function processWebhook(): void
    {
        $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'];
        $uri = $_SERVER['REQUEST_URI'];
        $webhook = "https://{$host}{$uri}";
        if (!$this->getConfig('webhook_set') || $this->getConfig('webhook') !== $webhook) {
            $this->warning('Setting new webhook - %s', $webhook);
            $this->telegram->setWebhook($webhook);
            $this->setConfig('webhook', $webhook);
            $this->setConfig('webhook_set', time());
        }

        $this->runMode = self::MODE_WEBHOOK;
        $this->beforeStart();

        $request = file_get_contents("php://input");
        if (empty($request))
            $this->sendErrorResponse('Empty request');
        $update = json_decode($request, true);
        if (!$update)
            $this->sendErrorResponse('Invalid request');
        if (!Dot::getValue($update, 'update_id')) {
            $this->error("Invalid incoming request: %s", $request);
            $this->sendErrorResponse('Invalid request');
        }
        if (!$this->getConfig('config.skip_incoming_request_log')) {
            $this->info("Incoming Request: %s", $request);
        }
        $update = Update::fromResponse($update);
        $this->handleUpdate($update);
    }

    /**
     * Log debug [100/6] message (sprintf style)
     * @param string $format
     * @param mixed $args [optional]
     * @param mixed $_ [optional]
     * @return bool
     */
    public function debug($format, $args = null, $_ = null)
    {
        if (($message = $this->processLogBody(func_get_args()))) {
            return $this->log($message, Logger::DEBUG);
        }
        return false;
    }

    /**
     * Log info [200/5] message (sprintf style)
     * @param string $format
     * @param mixed $args [optional]
     * @param mixed $_ [optional]
     * @return bool
     */
    public function info($format, $args = null, $_ = null)
    {
        if (($message = $this->processLogBody(func_get_args()))) {
            return $this->log($message, Logger::INFO);
        }
        return false;
    }

    /**
     * Log notice [250/5] message (sprintf style)
     * @param string $format
     * @param mixed $args [optional]
     * @param mixed $_ [optional]
     * @return bool
     */
    public function notice($format, $args = null, $_ = null)
    {
        if (($message = $this->processLogBody(func_get_args()))) {
            return $this->log($message, Logger::NOTICE);
        }
        return false;
    }

    /**
     * Log warning [300/4] message (sprintf style)
     * @param string $format
     * @param mixed $args [optional]
     * @param mixed $_ [optional]
     * @return bool
     */
    public function warning($format, $args = null, $_ = null)
    {
        if (($message = $this->processLogBody(func_get_args()))) {
            return $this->log($message, Logger::WARNING);
        }
        return false;
    }

    /**
     * Log error [400/3] message (sprintf style)
     * @param string $format
     * @param mixed $args [optional]
     * @param mixed $_ [optional]
     * @return bool
     */
    public function error($format, $args = null, $_ = null)
    {
        if (($message = $this->processLogBody(func_get_args()))) {
            return $this->log($message, Logger::ERROR);
        }
        return false;
    }

    /**
     * Log critical [500/2] message (sprintf style)
     * @param string $format
     * @param mixed $args [optional]
     * @param mixed $_ [optional]
     * @return bool
     */
    public function critical($format, $args = null, $_ = null)
    {
        if (($message = $this->processLogBody(func_get_args()))) {
            return $this->log($message, Logger::CRITICAL);
        }
        return false;
    }

    /**
     * Log alert [550/1] message (sprintf style)
     * @param string $format
     * @param mixed $args [optional]
     * @param mixed $_ [optional]
     * @return bool
     */
    public function alert($format, $args = null, $_ = null)
    {
        if (($message = $this->processLogBody(func_get_args()))) {
            return $this->log($message, Logger::ALERT);
        }
        return false;
    }

    /**
     * Log emergency [600/0] message (sprintf style)
     * @param string $format
     * @param mixed $args [optional]
     * @param mixed $_ [optional]
     * @return bool
     */
    public function emergency($format, $args = null, $_ = null)
    {
        if (($message = $this->processLogBody(func_get_args()))) {
            return $this->log($message, Logger::EMERGENCY);
        }
        return false;
    }

    /**
     * Process params of log function
     * @param $args
     * @return null|string
     */
    private function processLogBody($args) {
        if (empty($args)) return null;
        if (count($args) == 1) {
            $message = $args[0];
        } else {
            $text = array_shift($args);
            $args = array_map(function ($v) {
                if (is_bool($v)) {
                    return $v ? 'true' : 'false';
                }
                if (is_object($v) && method_exists($v, '__toString')) {
                    return (string)$v;
                }
                if (is_array($v) || is_object($v)) {
                    return json_encode($v, JSON_UNESCAPED_UNICODE);
                }
                return $v;
            }, $args);
            $message = vsprintf($text, $args);
        }
        return $message;
    }

    /**
     * Add a log entry
     * @param $message
     * @param int $level
     * @param array $extra
     * @return bool
     */
    protected function log($message, $level = Logger::INFO, $extra = [])
    {
        return $this->logger->log($level, $message, $extra);
    }

    /**
     * Configure Translations for you bot
     * see https://symfony.com/doc/master/components/translation.html
     * @param Translator $translator
     */
    protected function configTranslations(Translator $translator)
    {

    }

    /**
     * Configure logger handlers and processors
     */
    protected function configLogger()
    {
        if ($loggly = $this->getConfig('config.loggly_api_key')) {
            $this->logger->pushHandler(new LogglyHandler($loggly, Logger::INFO));
        }
        if ($rapid = $this->getConfig('config.rapid_api_key')) {
            $this->logger->pushHandler(new LogEntriesHandler($rapid, true, Logger::INFO));
        }
        if ($sentry = $this->getConfig('config.sentry_client_endpoint')) {
            if (!class_exists('\Sentry\Monolog\Handler'))
                throw new \InvalidArgumentException('sentry client is not installed, please run composer require "sentry/sdk"');

            \Sentry\init(['dsn' => $sentry ]);
            $handler = new \Sentry\Monolog\Handler(\Sentry\SentrySdk::getCurrentHub());
            $handler->setFormatter(new \Monolog\Formatter\LineFormatter("%message% %context% %extra%\n"));
            $handler->setLevel(Logger::WARNING);
            $this->logger->pushHandler($handler);
        }
        //Adding Some Context
        $this->logger->pushProcessor(function ($record) {
            if ($this->update && is_object($this->update) && method_exists($this->update, 'toJson')) {
                $record['extra']['update'] = json_decode($this->update->toJson(), true);
            }
            return $record;
        });
        ErrorHandler::register($this->logger);
    }
    
    /**
     * Restore pending replies
     */
    protected function restoreReplies() {
        $this->inlineAnswers = $this->getConfig('replies.inline', []);
        $this->asksUsers = $this->getConfig('replies.asks_users', []);
        $this->asksAnswers = $this->getConfig('replies.asks_answers', []);
        $this->asks = $this->getConfig('replies.asks', []);
        $updated = false;
        foreach ($this->asks as $chat => $asks) {
            foreach ($asks as $k => $ask) {
                if (!empty($ask['time']) && $ask['time'] < time() - 60*60*24*30) {
                    unset($this->asks[$chat][$k]);
                    $updated = true;
                }
            }
        }
        if ($updated)
            $this->saveReplies();
    }

    protected function getRunArg($key = null)
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
    protected function beforeStart()
    {
        $this->getBotInfo();
    }

    /**
     * Get Bot Info
     * @param null $key
     * @return mixed
     * @throws \TelegramBot\Api\Exception
     * @throws \TelegramBot\Api\InvalidArgumentException
     */
    protected function getBotInfo($key = null)
    {
        if (!($bot = $this->getConfig('bot'))) {
            $bot = $this->telegram->getMe();
            $this->setConfig('bot', [
                'id' => $bot->getId(),
                'name' => $bot->getFirstName(),
                'username' => $bot->getUsername(),
            ]);
        }
        return Dot::getValue($bot, $key);
    }

    /**
     * Get Bot Id
     * @return mixed
     * @throws \TelegramBot\Api\Exception
     * @throws \TelegramBot\Api\InvalidArgumentException
     */
    protected function getBotId()
    {
        return $this->getBotInfo('id');
    }

    /**
     * Set webhook url and turn on webhook mode
     * @param $url
     * @throws \TelegramBot\Api\Exception
     * @return array
     */
    public function setWebhook($url)
    {
        $this->init();
        $reply = $this->telegram->setWebhook($url);
        $this->setConfig('webhook', $url, false);
        $this->setConfig('webhook_set', time());
        return $reply;
    }

    /**
     * Run bot in webhook mode
     * @throws Exception
     * @throws \TelegramBot\Api\Exception
     */
    public function webhook()
    {
        $this->init();
        //Check if webhook was set
        if ($this->isConsoleMode()) {
            (new Application('telebot', '1.0.0'))
                ->register('webhook')
                ->setDescription('Configure webhook of bot')
                ->addArgument('url', InputArgument::REQUIRED, 'Webhook url')
                ->setCode(function (InputInterface $input, OutputInterface $output) {
                    // output arguments and options
                    $url = $input->getArgument('url');
                    $reply = $this->setWebhook($url);
                    $output->writeln("<info>Webhook:</info> <comment>$url</comment> <info>was set</info> <comment>"
                        . json_encode($reply, JSON_PRETTY_PRINT) . "</comment>");
                })
                ->getApplication()
                ->run();
        } else {
            //Process webhook contents
            $this->processWebhook();
        }
    }

    /**
     * Starts the bot
     * @throws Exception
     */
    public function start()
    {
        $this->init();
        $bot = $this->telegram;
        $this->beforeStart();

        if ($this->isConsoleMode()) {
            if ($this->getConfig('webhook_set')) {
                $this->warning("Switching to daemon mode from Webhook");
                $this->disableWebhook();
            }

            $this->info('Bot is starting in daemon mode');

            if (function_exists('pcntl_signal')) {
                pcntl_async_signals(true);
                declare(ticks = 1);
                $closure = function (int $sig) {
                    $this->info("Terminated by signal " . $sig);
                    return $this->run = false;
                };
                pcntl_signal(SIGHUP,  $closure);
                pcntl_signal(SIGINT, $closure);
                pcntl_signal(SIGTERM, $closure);
                pcntl_signal(SIGUSR1,  $closure);
            }

            //Deamon mode
            while ($this->proceedRun()) {
                try {
                    $start = microtime(true);
                    $updates = $bot->getUpdates($this->lastUpdateId, 10);
                    foreach ($updates as $update) {
                        $this->lastUpdateId = $update->getUpdateId() + 1;
                        $this->handleUpdate($update);
                    }
                    $processingTime = microtime(true) - $start;
                    $sleep = $this->stepDelay - $processingTime;
                    $this->count += $processingTime;

                    if ($sleep > 0) {
                        $this->iterate($sleep);
                        $this->count += $sleep;
                    }
                } catch (HttpException $e) {
                    if ($e->getCode() == 409) {
                        $message = $e->getMessage();
                        if (stripos($message, 'terminated by other getUpdates request') > 0 ) {
                            $this->warning('Conflict: terminated by other getUpdates request; make sure that only one bot instance is running');
                            $this->run = false;
                        } else {
                            $this->warning('Webhook was set. Switched to console mode');
                            $this->disableWebhook();
                        }
                    } else {
                        $this->error("Http Telegram Exception while communicating with Telegram API: %s\nTrace: %s", $e->getMessage(), $e->getTraceAsString());
                    }
                    sleep(5);
                    $this->checkErrorsCount();
                } catch (\Throwable $e) {
                    $this->error("General exception while handling update: %s\nTrace: %s", $e->getMessage(), $e->getTraceAsString());
                    $this->checkErrorsCount();
                }
            }
            $this->info('Bot is stopping');
        } else {
            echo 'This method need to be run under console ' . PHP_EOL;
            exit();
        }
    }

    /**
     * Should we proceed bot run
     */
    protected function proceedRun(): bool
    {
        return $this->run;
    }

    /**
     * Set Chat Owner
     * @param $id
     */
    protected function setChatOwner($id = null)
    {
        if ($id === null) $id = $this->getUserId();
        $this->setChatConfig('owner', $id);
    }

    protected function addChatAdmin($id)
    {
        $this->addChatConfig('admins', $id);
    }

    /**
     * Then message has no valid handlers
     * @param Message $message
     */
    public function onUnhandledMessage(Message $message)
    {

    }
    
    /**
     * Bot has been added to a new group chat
     */
    protected function onJoinChat()
    {
        $this->updateChatInfo();
        $this->setChatOwner();
    }

    /**
     * New user has join the chat
     * @param User $user
     */
    protected function onNewChatMember(User $user)
    {
        $this->setUserConfig($user->getId(), 'info', $user->toJson(true));

    }

    /**
     * Bot has been added to a new group chat
     * @param User $user
     */
    protected function onChatMemberLeft(User $user)
    {

    }

    /**
     * Bot has received a document
     * @param Message $message
     */
    protected function onDocumentShare(Document $document, Message $message)
    {

    }

    /**
     * Bot has received a compressed photo
     * @param PhotoSize $photo
     * @param PhotoSize $thumb
     * @param Message $message
     */
    protected function onPhotoShare(PhotoSize $photo, PhotoSize $thumb, Message $message)
    {

    }

    /**
     * Bot has been kicked from chat
     * @param Chat $chat
     * @return void
     */
    protected function onBotKick(Chat $chat)
    {

    }

    /**
     * Bot chat status has been changed
     * @param ChatMemberUpdated $botChatMember
     * @param string $from
     * Possible values - member / left
     * @param string $to
     */
    protected function onBotChatMemberStatusChange(ChatMemberUpdated $botChatMember, string $from, string $to)
    {

    }

    /**
     * New Group Chat has been created
     */
    protected function onGroupChatCreated()
    {
        $this->updateChatInfo();
        $this->setChatOwner();
    }

    /**
     * New SuperGroup Chat has been created
     */
    protected function onSuperGroupChatCreated()
    {
        $this->onGroupChatCreated();
    }

    /**
     *  New Channel has been created
     */
    protected function onChannelCreated()
    {
        $this->onGroupChatCreated();
    }

    /**
     * Chat was converted to supergroup
     * @param int $oldId
     */
    protected function onMigrateToSuperGroup(int $oldId = null)
    {
        $this->deleteConfig("chat.{$oldId}", false);
        $this->updateChatInfo();
    }

    /**
     * Get Message author name
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
            $this->info('Cannot detect from name - unhandled message type %s', $this->update->toJson());
            return '';
        }
        if ($this->isChannel()) {
            $fromName = 'channel ' . $message->getChat()->getTitle();
        } else {
            $from = $message->getFrom();
            $fromName = $from->getFirstName()
                . ($from->getLastName() ? ' ' . $from->getLastName() : '');
            if ($username) $fromName .= ($from->getUsername() ? ' @' . $from->getUsername() : '');
            if ($id) $fromName .= ' (' . $from->getId() . ')';
        }

        return $fromName;
    }

    /**
     * Get chat info as string
     * @param Chat|null $chat
     * @return string
     */
    public function getChatInfo(Chat $chat = null): string
    {
        if (!$chat) {
            return '?';
        }

        return sprintf('[%s] %s (%s)', $chat->getId(), $chat->getTitle(), $chat->getType());
    }

    /**
     * Get current update type Context
     * @return BaseType|Message|InlineQuery|CallbackQuery|null
     */
    protected function getContext()
    {
        $message = null;
        if (!$this->update) {
            return null;
        }
        if ($this->update->getInlineQuery()) {
            $message = $this->update->getInlineQuery();
        } else if ($this->update->getCallbackQuery()) {
            $message = $this->update->getCallbackQuery();
        } else if ($this->update->getMessage()) {
            $message = $this->update->getMessage();
        } else if ($this->update->getEditedMessage()) {
            $message = $this->update->getEditedMessage();
        } else if ($this->update->getChannelPost()) {
            $message = $this->update->getChannelPost();
        } else if ($this->update->getMyChatMember()) {
            $message = $this->update->getMyChatMember();
        }
        return $message;
    }

    /**
     * Retrieve message from update context
     * @param BaseType|null $context
     * @return Message|null
     */
    protected function getMessageFromContext(BaseType $context = null): ?Message
    {
        if (!$context && (!$context = $this->getContext())) {
            return null;
        }
        if ($context instanceof Message) {
            return $context;
        }
        if (method_exists($context, 'getMessage')) {
            return $context->getMessage();
        }
        return null;
    }

    /**
     * Return current user id
     * @return int|null
     */
    protected function getUserId()
    {
        if (!$this->update) {
            return null;
        }

        if (!$message = $this->getContext()) {
            return null;
        }

        if ($this->isChannel()) {
            return -1;
        }

        return $message->getFrom() ? $message->getFrom()->getId() : null;
    }

    /**
     * Handler for incoming inline queries
     * @param InlineQuery $inlineQuery
     * @return false|AbstractInlineQueryResult[]
     */
    protected function handleInlineQuery(InlineQuery $inlineQuery)
    {
        $this->warning('Inline query handler not implemented');
        return false;
    }

    /**
     * Check access for sending messages
     * @param Message $message
     * @return bool
     */
    protected function isMessageAllowed(Message $message)
    {
        if (($whiteListGroups = $this->getConfig('config.whiteGroups', [])) && in_array($message->getChat()->getId(), $whiteListGroups))
            return true;
        if ($this->isGlobalAdmin())
            return true;
        if (!$this->isChatPrivate() && $this->isAdmin())
            return true;
//        if (!$this->isChatPrivate() && $message->getFrom())
//            return true;

        $trustedUsers = array_merge([$this->getConfig('config.globalAdmin', [])],
            $this->getConfig('config.admins', []),
            $this->getConfig('config.trust', []),
        );
        if (in_array($this->getUserId(), $trustedUsers))
            return true;
        return false;
    }

    /**
     * Get global configuration
     * @param $key
     * @param $default
     * @return mixed
     */
    public function getConfig($key, $default = null)
    {
        return $this->db->get($key, $default);
    }

    /**
     * Handle incoming message
     * @param Message $message
     */
    public function handle($message)
    {
        //Regular Message
        $replyText = $message->getText();
        $command = $replyText;
        $commandParts = $command ? explode(" ", $command) : [];
        $this->e = $e = new Event();
        $e->setMessage($message);
        $e->setArgs($commandParts);

        $replyToId = null;
        $replyMatch = 'unknown';
        $chatId = $this->getChatId();
        $userId = $this->getUserId();
        $guessReply = false;

        if ($message->getReplyToMessage() && Dot::getValue($this->asks, "$chatId.{$message->getReplyToMessage()->getMessageId()}")) {
            //Direct reply
            $replyToId = $message->getReplyToMessage()->getMessageId();
            $replyMatch = 'To Message Id';
        } elseif ($message->getContact() && $waitingContact = array_filter($this->asks[$chatId] ?? [], function ($ask) {
            return ($ask['contact_request'] ?? null) === true;
        })) {
            //Reply match by waiting contact
            $replyToId = array_shift($waitingContact)['id'];
            $replyMatch = 'By Text Answer';
        } elseif ($replyText && $id = Dot::getValue($this->asksAnswers, "$chatId.{$replyText}")) {
            //Reply match by answer variant
            $replyToId = $id;
            $replyMatch = 'By Text Answer';
        } elseif ($replyText && $replyText[0] != '/' && $this->isChatPrivate() && isset($this->asksUsers[$chatId][$userId])) {
            //Reply by waiting user input
            //Check question type
            if ($replyData = Dot::getValue($this->asks, "$chatId.{$this->asksUsers[$chatId][$userId]}")) {
                //If Reply has direct answer varians, check message text
                $guessReply = true;
                $replyToId = $this->asksUsers[$chatId][$userId];
                $replyMatch = 'By Waiting User Input';
                unset($this->asksUsers[$chatId][$userId]);
            }
        }
        if ($replyToId && $replyData = Dot::getValue($this->asks, "$chatId.$replyToId")) {
            //ReplyTo Message
            $this->info('[REPLY] Got reply (%s) to message %s - %s', $replyMatch, $replyData['question'], $replyText);
            $replyText = trim($replyText);
            if (!($replyData['contact_request'] && $message->getContact()) && !empty($replyData['answers']) && !$this->inDeepArray($replyData['answers'], $replyText) !== false) {
                $this->info('[REPLY] Reply (%s) not in answers list (%s), ignoring message', $replyText, json_encode($replyData['answers'], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
                $replyToId = null;
            } else {
                if (!$replyData['multiple']) {
                    unset($this->asks[$chatId][$replyToId]);
                    unset($this->asksUsers[$chatId][$userId]);
                    $this->asksAnswers[$chatId] = [];
                    $this->saveReplies();
                }
                if ($callback = $replyData['callback']) {
                    if (is_string($callback) && method_exists($this, $callback)) {
                        $callback = [ $this, $callback ];
                    }
                    if (is_callable($callback) || is_array($callback)) {
                        call_user_func($callback, new Answer($message, $replyData));
                    }
                } else {
                    $replyToId = null;
                }
            }
        }
        if (!$replyToId) {
            if ($command) {
                foreach ($this->matches as $expression => $commandName) {
                    if (preg_match($expression, $command, $matches)) {
                        if ($this->commandExist($commandName) && $this->isCommandAllowed($commandName)) {
                            $this->info('[RUN] Running %s matched by %s', $commandName, $expression);
                            try {
                                call_user_func_array([$this, $commandName], [$matches]);
                            } catch (\Exception $ex) {
                                $replyMessage = $this->__('Error running command') . ': ' . $ex->getMessage();
                                $this->reply($replyMessage);
                            }
                            return;
                        }
                    }
                }
            }
            if ($command && $command[0] == "/") {
                $command = mb_substr(array_shift($commandParts), 1);
                if (($atPos = mb_stripos($command, '@'))) {
                    $command = mb_substr($command, 0, $atPos);
                }
                if (empty($command)) {
                    return;
                }
                if (!$command = $this->toCamel($command)) {
                    $this->warning('Invalid command recieved %s', $replyText);
                    return;
                }
                $commandName = $command . 'Command';
                if (isset($this->commandAlias[$command])) {
                    $commandName = $this->commandAlias[$command];
                }
                if ($this->commandExist($commandName) && $this->isCommandAllowed($commandName, $userId)) {
                    $this->info('[RUN] Running %s with %s arguments', $commandName, count($commandParts));
                    try {
                        call_user_func_array([$this, $commandName], $commandParts);
                    } catch (\Exception $ex) {
                        $replyMessage = $this->__('Error running command') . ': ' . $ex->getMessage();
                        $this->reply($replyMessage);
                    }
                    return;
                }
            }
            $this->onUnhandledMessage($message);
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
        if ($context = $this->getContext()) {
            return $this->getMessageFromContext($context)?->getChat()->getType();
        }
        if (!$this->e) {
            return null;
        }
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
        if (empty($camel)) {
            return false;
        }
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
    protected function isCommandAllowed($methodName, $user = null, $log = true)
    {
        if (!$user) {
            $user = $this->getUserId();
        }

        if (!$user) {
            if ($log) {
                $this->warning('[ACCESS][DENY] Deny Access for command %s - empty user', $methodName);
            }
            return false;
        }

        $method = new ReflectionMethod($this, $methodName);
        $doc = $method->getDocComment();
        if (str_contains($doc, '@global-admin') && !$this->isGlobalAdmin()) {
            if ($log) {
                $this->warning('[ACCESS][DENY] Deny Access for command with global admin access level %s', $methodName);
            }
            return false;
        }
        if (str_contains($doc, '@admin') && !$this->isAdmin()) {
            if ($log) {
                $this->warning('[ACCESS][DENY] Deny Access for command with admin access level %s', $methodName);
            }
            return false;
        }
        if (str_contains($doc, '@private') && !$this->isChatPrivate()) {
            if ($log) {
                $this->warning('[ACCESS][DENY] Deny Access for private-command only %s', $methodName);
            }
            return false;
        }
        if (in_array($methodName, ['addCron', 'cron', 'run', 'handle', '__construct'])) {
            if ($log) {
                $this->warning('[ACCESS][DENY] Deny Access for blacklisted command %s', $methodName);
            }
            return false;
        }
        return true;
    }

    /**
     * Check if current user has global admin or chat owner privelleges
     * Alias for isGlobalAdmin
     * @return bool
     */
    protected function isOwner()
    {
        return $this->isGlobalAdmin();
    }

    /**
     * Check if current user is owner of bot
     * @return bool
     */
    protected function isGlobalAdmin()
    {
        $userId = $this->getUserId();
        if (!$this->isChatPrivate() && $this->getOwnerId() == $userId) {
            return true;
        }

        return $userId == $this->getConfig('config.owner');
    }
    
    /**
     * If current user has admin privelleges or chat owner privelleges
     * @return bool
     */
    protected function isAdmin()
    {
        if ($this->isGlobalAdmin()) {
            return true;
        }
        $userId = $this->getUserId();
        if (!$this->isChatPrivate() && $this->getChatConfig('owner') == $userId) {
            return true;
        }
        $admins = array_merge($this->getConfig('config.trust', []), $this->getChatConfig('admins', []));

        return in_array($userId, $admins);
    }

    /**
     * Reply to user or chat
     * @param $text
     * @param Event|int $e
     * @param null|ReplyKeyboardMarkup|ForceReply|InlineKeyboardMarkup $markup
     * @return Message|false
     */
    public function reply($text, $e = null, $replyKeyboardMarkup = null, $format = 'HTML', $replyToMessageId = null)
    {
        $this->info('[REPLY] %s', $text);
        $target = $this->getTarget($e);
        if (!$target) {
            return null;
        }

        return $this->sendMessage($target, $text, $format, false, $replyToMessageId, $replyKeyboardMarkup);
    }

    /**
     * Reply to chat message
     * @param $text
     * @param null $markup
     * @param string $format
     */
    public function replyToMessage($text, $message, $markup = null, $format = 'HTML')
    {
        return $this->reply($text, null, $markup, $format, $message);
    }

    /**
     * Reply to last chat message
     * @param $text
     * @param null $markup
     * @param string $format
     */
    public function replyToLastMessage($text, $markup = null, $format = 'HTML')
    {
        return $this->reply($text, null, $markup, $format);
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
     * @param ReplyParameters|int|null $replyToMessageId
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
        $parse = self::PARSE_MARKDOWN_HTML,
        $disablePreview = false,
        ?int $replyToMessageId = null,
        $replyMarkup = null,
        $disableNotification = false,
        $allowChunks = true,
        ?ReplyParameters $replyParameters = null,
    ) {
        if ($replyToMessageId) {
            $replyParameters = new ReplyParameters();
            $replyParameters->setMessageId($replyToMessageId);
            $replyToMessageId = null;
        }
        $size = mb_strlen($message);
        if ($size > 4096) {
            if ($allowChunks) {
                $chunks = [];
                for ($i = 0; $i < ceil($size / 4096); $i ++) {
                    $chunks[] = mb_substr($message, $i > 0 ? $i * 4096 : 0, 4096);
                }
                $last = null;
                foreach ($chunks as $message) {
                    $last = $this->telegram->sendMessage($to, $message, $parse, $disablePreview, $replyToMessageId, $replyMarkup, $disableNotification, replyParameters: $replyParameters);
                    $this->info('Send chunk %s: %s', $message, $last->toJson());
                }
                return $last;
            } else {
                $message = mb_substr($message, 0, 4096);
            }
        }
        
        return $this->telegram->sendMessage($to, $message, $parse, $disablePreview, $replyToMessageId, $replyMarkup, $disableNotification, replyParameters: $replyParameters);
    }

    /**
     * Send notification to bot owner
     * @param string $message
     * @param string $parse
     * @param false $disablePreview
     * @param null $replyToMessageId
     * @param null $replyMarkup
     * @param false $disableNotification
     * @param bool $allowChunks
     * @return Message|void|null
     * @throws \TelegramBot\Api\Exception
     * @throws \TelegramBot\Api\InvalidArgumentException
     */
    public function sendMessageToOwner(
        $message,
        $parse = 'HTML',
        $disablePreview = false,
        $replyToMessageId = null,
        $replyMarkup = null,
        $disableNotification = false,
        $sendToMyself = false,
    ) {
        if (!$userId = $this->getUserId()) {
            return null;
        }
        if (!$ownerId = $this->getOwnerId()) {
            return null;
        }

        if ($sendToMyself || $userId != $ownerId) {
            return $this->sendMessage($ownerId, $message, $parse, $disablePreview, $replyToMessageId, $replyMarkup, $disableNotification);
        }
    }

    /**
     * Sends a photo to a specified chat via Telegram API.
     *
     * @param \CURLFile|string $photo
     * The photo to send. Can be a CurlFile, local file path or a file ID.
     * @param string $caption
     * Optional caption for the photo. Default is an empty string.
     * @param int|null $replyToMessageId
     * Optional message ID to reply to. Default is null.
     * @param Types\ReplyKeyboardMarkup|Types\ReplyKeyboardHide|Types\ForceReply|array|null $replyMarkup
     * Optional reply markup (e.g., inline keyboard). Default is null.
     * @param bool $disableNotification
     * Whether to send the message silently. Default is false.
     * @param int|string|null $chatId
     * The target chat ID. If null, the default chat ID is used.
     * @param callable|array|null $callback
     * Optional callback to execute after sending the photo. Default is null.
     * @param array|null $extraData
     * Additional data to include in the reply payload. Default is null.
     * @param string $parseMode
     * Mode for parsing entities in the caption. Default is 'HTML'. Can be MarkdownV2|HTML|Markdown
     * @return Message
     * The result of the sendPhoto request.
     */
    public function sendPhoto(
        $photo,
        $caption = '',
        $replyToMessageId = null,
        $replyMarkup = null,
        $disableNotification = false,
        $chatId = null,
        $callback = null,
        $extraData = null,
        $parseMode = self::PARSE_MARKDOWN_HTML,
    ) {
        try {
            if ($chatId === null) {
                $chatId = $this->getChatId();
            }
            if (is_array($replyMarkup)) {
                $replyMarkup = new InlineKeyboardMarkup($replyMarkup);
            }
            $send = $this->telegram->sendPhoto(
                $chatId,
                $photo,
                $caption,
                $replyToMessageId,
                $replyMarkup,
                $disableNotification,
                $parseMode,
            );
            if ($callback) {
                $payload = [
                    'id' => $send->getMessageId(),
                    'time' => time(),
                    'callback' => $callback,
                    'owner' => $this->getUserId(),
                    'extra' => $extraData,
                ];
                Dot::setValue($this->inlineAnswers, "{$chatId}.{$send->getMessageId()}", $payload);
                $this->saveReplies();
            }
            return $send;
        } catch (Exception $e) {
            $this->error('Error sending photo %s - %s', $photo, $e->getMessage());
            return false;
        }
    }

    /**
     * Sends a chat action to indicate the bot is performing a specific action.
     *
     * @param string $action The type of action to broadcast. E.g., 'typing', 'upload_photo'.
     * @param int|null $chatId Optional. The unique identifier for the target chat. If not provided, the current chat ID is used.
     * @return mixed The result of the action submission or null in case of an error.
     */
    public function sendChatAction(string $action, int $chatId = null): bool
    {
        try {
            return $this->telegram->sendChatAction($chatId ?? $this->getChatId(), $action);
        } catch (Throwable $e) {
            $this->error('Error sending chat action %s - %s', $action, $e->getMessage());
        }
    }
    
    /**
     * Get target user id based on context
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
        if (++$this->currentErrors >= $this->maxErrors) {
            $this->error('Max errors count reached, terminating');
            $this->run = false;
        }
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
     * @deprecated
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
     * Current chat or group id
     * @return int|null
     */
    protected function getChatId()
    {
        if ($context = $this->getContext()) {
            if ($context instanceof CallbackQuery) {
                return $context->getMessage()->getChat()->getId();
            }
            if (method_exists($context, 'getMessage')) {
                return $context->getMessage()->getChat()->getId();
            }
            if (method_exists($context, 'getChat') && $context->getChat()) {
                return $context->getChat()->getId();
            }
        }
        return null;
    }

    /**
     * Current message id
     * @return int|null
     */
    protected function getMessageId()
    {
        if ($context = $this->getContext()) {
            if (method_exists($context, 'getMessageId') && $context->getMessageId()) {
                return $context->getMessageId();
            } else if (method_exists($context, 'getMessage') && $context->getMessage()) {
                return $context->getMessage()->getMessageId();
            }
        }
        return null;
    }

    /**
     * Set Global configuration
     * @param $key
     * @param $value
     * @param bool $save
     * Store configuration
     * @return mixed
     */
    public function setConfig($key, $value, $save = true)
    {
        return $this->db->set($key, $value, $save);
    }

    /**
     * Chat-scope configuration
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
     * Add item to chat-scope config
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
     * Remove config
     * @param $key
     * @param bool $save
     * @return mixed
     */
    public function deleteConfig($key, $save = true)
    {
        return $this->db->delete($key, $save);
    }

    /**
     * Delete chat-scope config item
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

    /**
     * @param string $type
     * @deprecated
     */
    public function cron($type = 'm')
    {
        if (isset($this->cron[$type]) && is_array($this->cron[$type])) {
            foreach ($this->cron[$type] as $cronJob) {
                if ($this->commandExist($cronJob)) {
                    $this->info('[CRON][%s] Executing cron job %s', $type, $cronJob);
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
                $this->info('Message %s was not modified', $id);
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
        $target = $this->getTarget();
        if (!$target)
            return;
        $this->telegram->editMessageReplyMarkup($target, $id, $markup);
    }

    /**
     * Send document
     * @param string $file
     * @param null $chatId
     * @param Event $e
     */
    public function sendDocument($file, $chatId = null, $e = null)
    {
        $this->info('[SEND_DOCUMENT] chat_id: %s, document: %s', $chatId, $file);
        if (!$e) $e = $this->e;
        $chatId = !is_null($chatId) ? $chatId : $e->getUserId();
        $file = new \CURLFile(realpath($file));
        $this->telegram->sendDocument($chatId, $file);
    }

    /**
     * Download file content
     * @param string $fileId
     * @return false|string
     * @throws InvalidArgumentException
     * @throws \TelegramBot\Api\Exception
     */
    public function getDocumentContent(Document $document)
    {
        $file = $this->telegram->getFile($document->getFileId());
        return file_get_contents(sprintf('https://api.telegram.org/file/bot%s/%s', $this->botToken, $file->getFilePath()));
    }

    /**
     * Ask user input
     * @param string $text
     * Question text
     * @param array|null $answers
     * Answers variant (null for free form)
     * @param callable|null $callback
     * Callback method name (can be closure in daemon mode)
     * @param bool $multiple
     * Wait for multiple replies
     * @param bool $useReplyMarkup
     * Show reply markup in question
     * @param array $extraData
     * Some extra data to pass with payload
     * @return Message
     */
    public function ask(
        $text,
        $answers = null,
        $callback = null,
        $multiple = false,
        $useReplyMarkup = false,
        $extraData = [],
        ?int $replyToMessageId = null,
        $selective = false,
        ?ReplyParameters $replyParameters = null,
    ) {
        $this->info('[ASK] %s', $text . (!empty($answers) ? ' with answers: ' . var_export($answers, true) : ''));
        $e = $this->e;
        if ($answers instanceof ReplyKeyboardMarkup) {
            $rm = $answers;
            $answers = $rm->getKeyboard();
        } elseif (!empty($answers) && is_array($answers)) {
            if (!empty($answers) && is_array($answers) && !is_array($answers[0])) $answers = [$answers];
            $rm = new ReplyKeyboardMarkup($answers, true, true, true);
        } else {
            $rm = new ForceReply(true, $selective);
            $useReplyMarkup = true;
        }
        if ($selective) {
            $rm->setSelective(true);
        }
        if ($multiple && !($rm instanceof ForceReply)) $rm->setOneTimeKeyboard(false);
        if ($replyToMessageId === null && (!empty($answers) || $useReplyMarkup)) {
            $replyToMessageId = $this->getMessageId();
        }

        if ($this->runMode == self::MODE_WEBHOOK && is_callable($callback)) {
            $error = 'Cannot use callable objects in webhook mode';
            $trace = @debug_backtrace();
            $this->error($error . (isset($trace[1]['function']) ? " at {$trace[2]['function']}@{$trace[0]['file']}:{$trace[0]['line']}" : ''));
            $this->sendErrorResponse($error, 601);
            return false;
        }

        $send = $this->sendMessage($this->getChatId(), $text, 'HTML', true, $replyToMessageId, $rm);
        $this->addWaitingReply($send->getMessageId(), $text, $answers, $callback, $multiple, $extraData);
        return $send;
    }

    /**
     * Store waiting reply
     * @param string $text
     * Text
     * @param array $answers
     * Anwers
     * @param callable|string|null $callback
     * Callback method name (can be closure in daemon mode)
     * @param bool $multiple
     */
    private function addWaitingReply($askMessageId, $text, $answers, $callback = null, $multiple = false, $extraData = [])
    {
        $e = $this->e;
        $contactRequest = false;
        if ($answers) {
            $contactRequest = !empty(array_filter($answers, function ($answerGroup) {
                if (is_array($answerGroup)) {
                    foreach ($answerGroup as $answer) {
                        if (isset($answer['request_contact']) && $answer['request_contact']) {
                            return true;
                        }
                    }
                }
                return false;
            }));
        }

        $payload = [
            'id' => $askMessageId,
            'question' => $text,
            'callback' => $callback,
            'user' => $this->getUserId(),
            'contact_request' => $contactRequest,
            'answers' => $answers,
            'multiple' => $multiple,
            'extra' => $extraData,
            'time' => time(),
        ];
        $chatId = $this->getChatId();
        Dot::setValue($this->asks, "{$chatId}.{$askMessageId}", $payload);
        if ($userId = $this->getUserId())
            Dot::setValue($this->asksUsers, "{$chatId}.{$userId}", $askMessageId);
        Dot::setValue($this->asksAnswers, "{$chatId}", []);
        if (!empty($answers) && is_array($answers)) {
            foreach ($answers as $group) {
                foreach ($group as $answer) {
                    if (is_array($answer)) {
                        if (isset($answer['text'])) {
                            $answer = $answer['text'];
                        } else {
                            continue;
                        }
                    }
                    Dot::setValue($this->asksAnswers, "{$chatId}.{$answer}", $askMessageId);
                }
            }
        }

        $this->saveReplies();
    }

    /**
     * Check if we got callback for messageId
     * @param $messageId
     * @return bool
     */
    protected function hasWaitingReply($messageId)
    {
        $chatId = $this->getChatId();
        return isset($this->asks[$chatId][$messageId]) || isset($this->inlineAnswers[$chatId][$messageId]);
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
        $chatId = $this->getChatId();
        unset($this->ask[$chatId][$messageId]);
        if ($keys = array_keys($this->asksUsers[$chatId], $messageId)) {
            foreach ($keys as $key)
                unset($this->asksUsers[$chatId][$key]);
        }
        $this->asksAnswers[$chatId] = [];
        $this->saveReplies();
    }

    /**
     * Ask question with inline buttons
     * @param string $text
     * Question text
     * @param array $answers
     * Array of buttons
     * @param callable|array|null $callback
     * Callback method name (string preffered)
     * @param array $extraData
     * @return Message|false
     */
    public function askInline($text, $answers = [], $callback = null, $extraData = [], $target = null)
    {
        if ($target === null) {
            $target = $this->getChatId();
        }

        $this->info('[ASK] %s', $text . (!empty($answers) ? ' with answers: ' . json_encode($answers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : ''));
        $e = $this->e;
        if (is_array($answers)) {
            $answers = new InlineKeyboardMarkup($answers);
        }
        if (!($answers instanceof InlineKeyboardMarkup)) {
            throw new \InvalidArgumentException('Invalid type of inline markup: ' . var_export($answers, true));
        }
        if ($this->runMode == self::MODE_WEBHOOK && is_callable($callback)) {
            $error = 'Cannot use callable objects in webhook mode';
            $trace = @debug_backtrace();
            $this->error($error . (isset($trace[1]['function']) ? " at {$trace[2]['function']}@{$trace[0]['file']}:{$trace[0]['line']}" : ''));
            $this->sendErrorResponse($error, 601);
            return false;
        }
        try {
            $send = $this->telegram->sendMessage($target, $text, 'HTML', true, null, $answers);
            if ($callback) {
                $payload = [
                    'id' => $send->getMessageId(),
                    'time' => time(),
                    'callback' => $callback,
                    'owner' => $this->getUserId(),
                    'extra' => $extraData,
                ];
                Dot::setValue($this->inlineAnswers, "{$target}.{$send->getMessageId()}", $payload);
                $this->saveReplies();
            }
            return $send;
        } catch (Exception $e) {
            $this->error("Error sending inline message: %s\nTrace: %s\n", $e->getMessage(), $e->getTraceAsString());
            return false;
        }

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
            throw new \InvalidArgumentException('Invalid type of inline markup: ' . var_export($markup, true));
        }
        $this->updateMessage($id, $text, 'html', true, $markup);
    }

    /**
     * Add user to Trust list (act as bot admin)
     * @param null $e
     * @global-admin
     * @throws \Exception
     */
    public function trustCommand()
    {
        $args = $this->e->getArgs();
        if (!isset($args[1]) || !is_numeric($args[1])) throw new \Exception('Please provide user id');
        $user = $args[1];
        $this->addConfig('config.trust', $user);
        $this->reply(sprintf($this->__('User %s now in trust list'), $user));
        $this->onUserTrust($user);
    }

    /**
     * Should protect bot from authorized access
     * @global-admin
     * @param bool $protect
     * @throws \TelegramBot\Api\Exception
     * @throws InvalidArgumentException
     */
    public function protectCommand(bool $protect = true)
    {
        $this->setConfig('config.protect', $protect);
        if ($protect) {
            $this->setConfig('config.auto_request_access_from_owner', true);
        }
        $this->reply(sprintf($this->__('Bot now %s untrusted users to access'), $this->__($protect ? 'disallow' : 'allow')));
    }

    /**
     * Event on user trust action
     * @param $user
     * @throws \TelegramBot\Api\Exception
     * @throws \TelegramBot\Api\InvalidArgumentException
     */
    protected function onUserTrust($user)
    {
        $this->sendMessage($user, $this->__('Greetings. Now you have access to all features of this bot'));
    }

    /**
     * Add user to Admins list
     * @param null $e
     * @global-admin
     * @throws \Exception
     */
    public function adminCommand()
    {
        $args = $this->e->getArgs();
        if (!isset($args[1]) || !is_numeric($args[1])) throw new \Exception('Please provide user id');
        $user = $args[1];
        if ($name = $this->getUserName($user)) {
            $this->addChatConfig('admins', $user);
            $this->reply(sprintf($this->__('User %s is admin now'), $name));
        } else {
            $this->reply(sprintf($this->__('User %s is unknown'), $user));
        }
    }

    /**
     * Allow bot to recieve messages from all users in this chat
     * @global-admin
     * @param Event $e
     * @param null $chatId
     */
    public function allowChatCommand($chatId = null)
    {
        if (!$chatId) $chatId = $this->getChatId();
        if ($this->isGlobalAdmin()) {
            $this->addConfig('config.whiteGroups', $chatId);
            $this->reply(sprintf($this->__('Group %s is allowed to anyone'), $chatId));
        }
    }

    /**
     * Add item to global config array
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
                $this->deleteConfig('config.trust.' . $k);
                $this->reply(sprintf($this->__('User %s has been removed from trust list'), $user));
                return;
            }
        }
        $this->reply(sprintf($this->__('User %s not found in trust list'), $user));
    }

    /**
     * List of available commands for current user
     * @return array
     */
    protected function getAvailableCommands(): array
    {
        $class = new ReflectionClass($this);
        $commands = [];
        $userId = $this->getUserId();
        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_FINAL) as $method) {
            if (!str_contains($method->name, 'Command')) {
                continue;
            }
            $doc = $method->getDocComment();
            if (str_contains($doc, '@hidden')) {
                continue;
            }
            if (!$this->isCommandAllowed($method->name, $userId, false)) {
                continue;
            }
            $botCommand = $this->deCamel(str_replace('Command', '', $method->name));
            $doc = str_replace("\r\n", "\n", $doc);
            $lines = explode("\n", $this->cleanDoc($doc));
            $description = $this->__($lines[0]);
            $commands[] = [$botCommand, $description];
        }

        return $commands;
    }

    /**
     * Show list of available commands
     */
    public function listCommand()
    {
        $commands = [
            '<b>' . $this->__('Available commands:') .' </b>'
        ];
        $list = $this->getAvailableCommands();
        foreach ($list as $command) {
            $commands[] = sprintf("/%s - <i>%s</i>", $command[0], $command[1]);
        }
        $this->reply(implode("\n", $commands));
    }

    /**
     * Update commands for currentUser
     * @param string $scope
     * @return HttpClientInterface
     * @throws HttpException
     * @throws \TelegramBot\Api\Exception
     * @throws \TelegramBot\Api\InvalidJsonException
     */
    protected function updateCommandsList(array $filter = [])
    {
        $list = $this->getAvailableCommands();
        $commands = [];
        foreach ($list as $item) {
            [$botCommand, $description] = $item;
            if (in_array($botCommand, $filter)) {
                continue;
            }
            $command = new BotCommand();
            $command->setCommand($botCommand);
            $command->setDescription($description ?: $botCommand . ' command');
            $commands[] = $command;
        }
        return $this->telegram->setMyCommands(new ArrayOfBotCommand($commands), json_encode([
            'type' => 'chat',
            'chat_id' => $this->getChatId(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Alias for list
     */
    public function startCommand()
    {
        $this->listCommand();
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
     * Get Param of request
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
        return in_array($this->getChatType(), ['group', 'supergroup']);
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
    protected function isChannel()
    {
        return $this->getChatType() == 'channel';
    }

    /**
     * Get's user id or group from event
     * @param Event $e
     * @return bool
     */
    protected function getUserIdFromEvent($e = null)
    {
        if (!$e) $e = $this->e;
        if ($e) {
            $this->debug('user is %s', $e->getUserId());
            return $e->getUserId();
        } else {
            return false;
        }
    }

    /**
     * Get argument of current request
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
    protected function sendErrorResponse($message, $code = 0)
    {
        $this->sendResponse(array_merge([
            'success' => false,
            'error' => $message,
            'code' => $code,
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
            'success' => true,
        ], $result);
        $response = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        echo $response;
        exit();
    }

    protected function saveReplies()
    {
        $this->setConfig('replies', [
            'asks' => $this->asks,
            'asks_users' => $this->asksUsers,
            'asks_answers' => $this->asksAnswers,
            'inline' => $this->inlineAnswers,
        ]);
    }

    /**
     * Search array value recursively
     * @param array $array
     * @param string $value
     * @return bool
     */
    protected function inDeepArray(array $array, $value)
    {
        foreach ($array as $k => $v) {
            $key = $k;
            if ($value === $v || (is_array($v) && $this->inDeepArray($v, $value))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check whenever bot run in console mode
     * @return bool
     */
    public function isConsoleMode()
    {
        return php_sapi_name() == "cli";
    }

    /**
     * Get location for storing data.json file
     * @return string|null
     */
    protected function getDataDirectory()
    {
        return $this->getOption('appDir');
    }

    /**
     * Get Runtime directory location for storing logs etc
     * @return string
     */
    protected function getRuntimeDirectory()
    {
        return $this->getOption('runtimeDir');
    }

    /**
     * Perform init (load database, restore replies, config loggers etc)
     * @throws Exception
     */
    public function init()
    {
        $filesDir = realpath(__DIR__ . '/../files');
        $this->db = $db = new Data([
            'template' => $filesDir . DIRECTORY_SEPARATOR . 'template.data.json',
            'dir' => $this->getDataDirectory(),
            'name' => $this->getOption('dataFile'),
        ]);
        $apiKey = $db->get('config.api');
        if (empty($apiKey) || $apiKey == 'TELEGRAM_BOT_API_KEY') {
            throw new Exception('Please set config.api key in config file ('.$this->db->db.')');
        }

        //Restore Waiting Messages
        $this->restoreReplies();

        /** @var BotApi|Client $bot */
        $bot = new Basic($this->getConfig('config.api'), null, $this->getConfig('config.proxy'));
        $this->telegram = $bot;

        //Init Translations
        $this->translator = new Translator('en_US');
        //Add Default Resourse
        $this->translator->addLoader('csv', new CsvFileLoader());
        $this->translator->addResource('csv', $filesDir . DIRECTORY_SEPARATOR . 'locale'
            . DIRECTORY_SEPARATOR . 'system.ru.csv', 'ru');
        $this->configTranslations($this->translator);

        //Config Logger
        $this->configLogger();
    }

    /**
     * Set current locale
     * @param $locale
     */
    public function setLocale($locale)
    {
        $this->translator->setLocale($locale);
    }

    /**
     * Get Translator Object
     * @return Translator|null
     */
    public function getTranslator()
    {
        return $this->translator;
    }

    /**
     * Translate the message
     * @param $message
     * @param array $args
     * @return string
     */
    public function __($message, $args = []) {
        return $this->translator->trans($message, $args);
    }

    /**
     * @param $bot
     */
    protected function disableWebhook()
    {
        $this->telegram->setWebhook();
        $this->deleteConfig('webhook_set');
    }

    /**
     * Update chat information in load db
     */
    protected function updateChatInfo()
    {
        $this->setChatConfig('info', $this->getContext()->getChat()->toJson(true));
    }

    /**
     * Init Logger
     * @param $appName
     */
    protected function initLogger(): void
    {
        $appName = $this->getOption('appName');
        $this->logger = new Logger('telebot-' . Utils::cleanIdentifier($appName));
        $this->logger->pushHandler(new RotatingFileHandler($this->getRuntimeDirectory() . DIRECTORY_SEPARATOR . 'bot.log', 30, Logger::INFO));
        if ($this->isConsoleMode()) {
            $this->logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));
        }
    }

    /**
     * Get bot owner id
     * @return mixed
     */
    protected function getOwnerId(): mixed
    {
        return $this->getConfig('config.owner');
    }

}
