<?php
/**
 * Created by PhpStorm.
 * User: Andrey Mistulov
 * Company: Aristos
 * Date: 15.03.2017
 * Time: 14:23
 */

namespace Prowebcraft\Telebot;

class Event implements \ArrayAccess
{

    protected $args;
    protected $params;
    protected $message;
    protected $background = false;

    /**
     * Полный список аргументов, включая название команды
     * @return array
     */
    public function getArgs()
    {
        return $this->args;
    }

    /**
     * Только аргументы
     * @return mixed
     */
    public function getParams()
    {
        return $this->params;
    }

    /**
     * Установить значение аргумента
     * @param $param
     * @param $value
     */
    public function setParam($param, $value) {
        $this->params[$param] = $value;
    }

    /**
     * Определяет будет ли команда выполнена в фоновом или в синхронном режиме
     * @return bool
     */
    public function isBackground()
    {
        return $this->background;
    }

    /**
     * @return \TelegramBot\Api\Types\Message
     */
    public function getMessage()
    {
        return $this->message;
    }

    public function setArgs(array $args)
    {
        $this->args = $args;
        $this->params = $this->parseArguments($args);
    }

    public function setMessage(\TelegramBot\Api\Types\Message $message)
    {
        $this->message = $message;
    }

    /**
     * Получить ID пользователя или группы из события
     * @return bool
     */
    public function getUserId() {
        return ($this->getMessage()) ? $this->getMessage()->getChat()->getId() : false;
    }

    /**
     * Проверяет наличие аргумента входящего сообщения и возвращает значение или false, если аргумент не передан
     * @param array|string $arguments
     * @param bool|string $default
     * @return bool|string
     */
    function getArgument($arguments, $default = false)
    {
        $opt = $this->getParams();
        if(!is_array($arguments)) $arguments = [ $arguments ];
        foreach($arguments as $k => $argument) {
            if(isset($opt[$argument])) {
                return ($opt[$argument] === false ? true : $opt[$argument]);
            }
            if(in_array($argument, $opt)) return true;
        }
        return $default;
    }

    /**
     * Кастомная функция парсинга аргументов входящего сообщения
     * @param $arguments
     * @return array
     */
    private function parseArguments($arguments)
    {
        array_shift($arguments);
        $out = array();
        foreach($arguments as $arg)
        {
            $arg = str_replace('—', '--', $arg);
            if(mb_substr($arg, 0, 2) == "--")
            {
                $eqPos = mb_strpos($arg, '=');
                if($eqPos === false)
                {
                    $key = mb_substr($arg, 2);
                    $out[$key] = isset($out[$key]) ? $out[$key] : true;
                }
                else
                {
                    $key = mb_substr($arg, 2, $eqPos - 2);
                    $out[$key] = mb_substr($arg, $eqPos + 1);
                }
            }
            else if(mb_substr($arg, 0, 1) == '-')
            {
                if(mb_substr($arg, 2, 1) == '=')
                {
                    $key = mb_substr($arg, 1, 1);
                    $out[$key] = mb_substr($arg, 3);
                }
                else
                {
                    $chars = str_split(mb_substr($arg, 1));
                    foreach($chars as $char)
                    {
                        $key = $char;
                        $out[$key] = isset($out[$key]) ? $out[$key] : true;
                    }
                }
            }
            else
            {
                $out[] = $arg;
            }
        }
        return $out;
    }


    /**
     * Whether a offset exists
     * @link http://php.net/manual/en/arrayaccess.offsetexists.php
     * @param mixed $offset <p>
     * An offset to check for.
     * </p>
     * @return boolean true on success or false on failure.
     * </p>
     * <p>
     * The return value will be casted to boolean if non-boolean was returned.
     * @since 5.0.0
     */
    public function offsetExists($offset)
    {
        return property_exists($this, $offset);
    }

    /**
     * Offset to retrieve
     * @link http://php.net/manual/en/arrayaccess.offsetget.php
     * @param mixed $offset <p>
     * The offset to retrieve.
     * </p>
     * @return mixed Can return all value types.
     * @since 5.0.0
     */
    public function offsetGet($offset)
    {
        if(property_exists($this, $offset)) return $this->$offset;
    }

    /**
     * Offset to set
     * @link http://php.net/manual/en/arrayaccess.offsetset.php
     * @param mixed $offset <p>
     * The offset to assign the value to.
     * </p>
     * @param mixed $value <p>
     * The value to set.
     * </p>
     * @return void
     * @since 5.0.0
     */
    public function offsetSet($offset, $value)
    {
        if(property_exists($this, $offset)) $this->$offset = $value;
    }

    /**
     * Offset to unset
     * @link http://php.net/manual/en/arrayaccess.offsetunset.php
     * @param mixed $offset <p>
     * The offset to unset.
     * </p>
     * @return void
     * @since 5.0.0
     */
    public function offsetUnset($offset)
    {
        //do nothing
    }
}
