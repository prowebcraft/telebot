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

    private $userNames = [];

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
     * @param int $userId
     * @param string $userName
     */
    protected function setUserName($userId, $userName)
    {
        $this->userNames[$userId] = $userName;
        $this->setConfig("config.names.$userId", $userName);
    }
    
    /**
     * Get User Name or Alias
     * @param int $id
     * @return string
     */
    protected function getUserName($id, $default = null)
    {
        if (!isset($this->userNames[$id])) {
            $this->userNames[$id] = $this->getConfig("config.names.$id", $default);
        }
        return $this->userNames[$id];
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
