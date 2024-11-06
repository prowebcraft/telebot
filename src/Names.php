<?php
/**
 * Created by PhpStorm.
 * User: Andrey Mistulov
 * Company: Aristos
 * Date: 21.08.2017
 * Time: 11:57
 */

namespace Prowebcraft\Telebot;


use Prowebcraft\Dot;
use TelegramBot\Api\Types\Message;

trait Names
{

    private $registry = [];

    /**
     * Add new user to registry (called automatically on incoming messages)
     * @param int $id
     * @param string $name
     */
    protected function addUser($id, $name)
    {
        if (!$this->getUserName($id)) {
            $this->info('Adding new user to registry - %s with id %s', $name, $id);
            /** @var Message $context */
            if ($context = $this->getContext()) {
                if ($context->getFrom() && $username = $context->getFrom()->getUsername()) {
                    $this->setUserConfig($id, 'username', $username);
                }
            }
            $this->setUserName($id, $name);
        }
    }

    /**
     * Set User's Name Alias
     * @param int $id
     * @param string $userName
     */
    protected function setUserName($id, $userName)
    {
        $this->setUserConfig($id, 'name', $userName);
    }

    /**
     * Retrieve current user config
     * @param string $key
     * @param mixed $default
     * Default value for config key
     * @return mixed
     */
    protected function getCurrentUserConfig($key, $default = null)
    {
        return $this->getUserConfig($this->getUserId(), $key, $default);
    }

    /**
     * Retrieve user config
     * @param $id
     * User Id
     * @param null|string $key
     * [optional] Config key. If not set - return array with user config
     * @param mixed $default
     * Default value for config key
     * @return mixed
     */
    protected function getUserConfig($id, $key = null, $default = null) {
        if (!isset($this->registry[$id])) {
            $this->registry[$id] = $this->getConfig("config.names.$id", []);
            if (is_string($this->registry[$id])) {
                //Migrate from simple registry
                $this->registry[$id] = [
                    'name' => $this->registry[$id]
                ];
                $this->setConfig("config.names.$id", $this->registry[$id]);
            }
        }
        if ($key !== null) {
            return Dot::getValue($this->registry[$id], $key, $default);
        }

        return $this->registry[$id];
    }

    /**
     * Set config value for current user
     * @param $key
     * Config key
     * @param $value
     * Config value
     */
    public function setCurrentUserConfig($key, $value)
    {
        return $this->setUserConfig($this->getUserId(), $key, $value);
    }
    
    /**
     * Set config value for user
     * @param $id
     * User Id
     * @param $key
     * Config key
     * @param $value
     * Config value
     */
    protected function setUserConfig($id, $key, $value) {
        $config = $this->getUserConfig($id);
        Dot::setValue($config, $key, $value);
        $this->registry[$id] = $config;
        $this->setConfig("config.names.$id", $config);
    }

    /**
     * Unset User Config
     * @param null|string $key
     * @param bool $save
     * @return mixed
     */
    protected function deleteUserConfig($id, $key = null, $save = true)
    {
        if ($key !== null) {
            $key = 'config.names.' . $id . '.' . $key;
        } else {
            $key = 'config.names.' . $id;
        }
        unset($this->registry[$id]);
        return $this->db->delete($key, $save);
    }

    /**
     * Get User Name or Alias
     * @param int $id
     * @param null $default
     * @return string
     */
    protected function getUserName($id, $default = null)
    {
        if ($name = $this->getUserConfig($id, 'name')) {
            return $name;
        }

        if ($first = $this->getUserConfig($id, 'info.first_name')) {
            return $first . (($last = $this->getUserConfig($id, 'info.last_name')) ? ' ' . $last : '');
        }

        return $default;
    }

    /**
     * Get Mention Link (html format)
     * @param $userId
     * @param string $format
     * markdown or html format
     * @return string
     */
    protected function getUserMention($userId, $format = 'html')
    {
        if ($format === 'markdown') {
            return sprintf('[%s](tg://user?id=%s)', $this->getUserName($userId), $userId);
        }

        return sprintf('<a href="tg://user?id=%s">%s</a>', $userId, $this->getUserName($userId));
    }

    /**
     * Change current user name
     * @hidden
     */
    public function nameCommand()
    {
        $userName = $this->getFromName();
        if (!empty($this->e->getParams())) {
            $newName = implode(' ', $this->e->getParams());
            $this->reply($userName . ' теперь известен как ' . $newName);
        } else {
            $newName = $userName;
            $this->reply($userName . ' теперь использует имя по-умолчанию');
        }
        $this->setUserName($this->getUserId(), $newName);
    }

}
