# Telegram Bot Framework
Telegram bot with dialogs support and session management

# PHP Telegram Bot Api

[![Latest Version on Packagist](https://img.shields.io/packagist/v/prowebcraft/telebot.svg?style=flat-square)](https://packagist.org/packages/prowebcraft/telebot)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)
[![Total Downloads](https://img.shields.io/packagist/dt/prowebcraft/telebot.svg?style=flat-square)](https://packagist.org/packages/prowebcraft/telebot)

Based on php wrapper for [Telegram Bot API](https://core.telegram.org/bots/api), telebot provides flexible diablogs system (inlines and buttons mode), ability to track responses. Telebot can work in daemon or webhook mode.

## Bots: An introduction for developers
>Bots are special Telegram accounts designed to handle messages automatically. Users can interact with bots by sending them command messages in private or group chats.

>You control your bots using HTTPS requests to [bot API](https://core.telegram.org/bots/api).

>The Bot API is an HTTP-based interface created for developers keen on building bots for Telegram.
To learn how to create and set up a bot, please consult [Introduction to Bots](https://core.telegram.org/bots) and [Bot FAQ](https://core.telegram.org/bots/faq).

## Install

Via Composer

``` bash
$ composer require prowebcraft/telebot
```

## Usage

See example [Telegram Id Bot](https://github.com/prowebcraft/telegram-id-bot). This bot in Telegram - [@identybot](https://t.me/identybot)

#### Create your bot YourBot.php class extended of \Prowebcraft\Telebot\Telebot
``` php
<?php

class YourBot extends \Prowebcraft\Telebot\Telebot
{

}
```

####Create some public methods with Command suffix
```php
/**
* Welcome message based on context
*/
public function hiCommand()
{
    if ($this->isChatGroup()) {
        $this->reply('Hey everybody in this chat!');
    } else {
        $this->reply('Hello, human!');
    }
}
```

####Run your bot in daemon mode. 
Create daemon.php
```php
<?php

require_once './vendor/autoload.php';
require_once "YourBot.php";

$config = [];
$bot = new YourBot('YourBotName', []);
$bot->start();
```
And run it in console
``` bash
$ php daemon.php
```

At first run data.json will be created with some template options:

``` json
{
  "config": {
    "api": "TELEGRAM_BOT_API_KEY",
    "globalAdmin": 70863438,
    "admins": [],
    "trust": [],
    "whiteGroups": []
  }
}

```

Set your bot token to *config.api*

Set yourself as global admin (you can get your id from [@identybot](https://t.me/identybot))

Send **/hi** to your bot

## Credits

- [Andrey Mistulov](https://github.com/prowebcraft)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
