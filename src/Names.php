<?php
/**
 * Created by PhpStorm.
 * User: Andrey Mistulov
 * Company: Aristos
 * Date: 21.08.2017
 * Time: 11:57
 */

namespace Prowebcraft\Telebot;


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
            \System_Daemon::info('Adding new user to registry - %s with id %s', $name, $id);
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
        if ($key !== null)
            return \ArrayHelper::getValue($this->registry[$id], $key, $default);
        return $this->registry[$id];
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
        $config[$key] = $value;
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
        return $this->getUserConfig($id, 'name', $default);
    }

    /**
     * Allow Users to change their alias
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
