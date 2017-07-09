<?php
/**
 * Created by PhpStorm.
 * User: Andrey Mistulov
 * Company: Aristos
 * Date: 14.03.2017
 * Time: 15:25
 */

namespace Prowebcraft\Telebot;
use AdBar\Dot; //https://github.com/adbario/php-dot-notation

/**
 * Class Data
 * @package Aristos
 */
class Data extends Dot
{
    protected $db = '';
    protected $data = null;
    protected $dir = null;

    public function __construct($dir = null)
    {
        if ($dir === null) $dir = getcwd();
        $this->dir = $dir;
        $this->loadData();
        parent::__construct();
    }

    /**
     * Set value or array of values to path
     *
     * @param mixed      $key   Path or array of paths and values
     * @param mixed|null $value Value to set if path is not an array
     * @param bool $save Сохранить данные в базу
     * @return $this
     */
    public function set($key, $value = null, $save = true)
    {
        if (is_string($key)) {
            // Iterate path
            $keys = explode('.', $key);
            $data = &$this->data;
            foreach ($keys as $key) {
                if (!isset($data[$key]) || !is_array($data[$key])) {
                    $data[$key] = [];
                }
                $data = &$data[$key];
            }
            // Set value to path
            $data = $value;
        } elseif (is_array($key)) {
            // Iterate array of paths and values
            foreach ($key as $k => $v) {
                $this->set($k, $v);
            }
        }
        if ($save) $this->save();
        return $this;
    }

    /**
     * Add value or array of values to path
     *
     * @param mixed $key Path or array of paths and values
     * @param mixed|null $value Value to set if path is not an array
     * @param boolean $pop Helper to pop out last key if value is an array
     * @param bool $save Сохранить данные в базу
     * @return $this
     */
    public function add($key, $value = null, $pop = false, $save = true)
    {
        if (is_string($key)) {
            // Iterate path
            $keys = explode('.', $key);
            $data = &$this->data;
            if ($pop === true) {
                array_pop($keys);
            }
            foreach ($keys as $key) {
                if (!isset($data[$key]) || !is_array($data[$key])) {
                    $data[$key] = [];
                }
                $data = &$data[$key];
            }
            // Add value to path
            $data[] = $value;
        } elseif (is_array($key)) {
            // Iterate array of paths and values
            foreach ($key as $k => $v) {
                $this->add($k, $v);
            }
        }
        if ($save) $this->save();
        return $this;
    }

    /**
     * Delete path or array of paths
     *
     * @param mixed $key Path or array of paths to delete
     * @param bool $save Сохранить данные в базу
     * @return $this
     */
    public function delete($key, $save = true)
    {
        parent::delete($key);
        if ($save) $this->save();
        return $this;
    }

    /**
     * Delete all data, data from path or array of paths and
     * optionally format path if it doesn't exist
     *
     * @param mixed|null $key Path or array of paths to clean
     * @param boolean $format Format option
     * @param bool $save Сохранить данные в базу
     * @return $this
     */
    public function clear($key = null, $format = false, $save = true)
    {
        parent::clear($key, $format);
        if ($save) $this->save();
        return $this;
    }


    /**
     * Загрузка локальной базы данных
     * @param bool $reload
     * Перезагрузить данные?
     * @return array|mixed|null
     */
    protected function loadData($reload = false) {
        if ($this->data === null || $reload) {
            $this->db = $this->dir . DIRECTORY_SEPARATOR . 'data.json';
            if (!file_exists($this->db)) {
                $templateFile = realpath(__DIR__ . '/../files') . DIRECTORY_SEPARATOR . 'template.' . $this->db;
                if (file_exists($templateFile)) {
                    copy($templateFile, $this->db);
                } else {
                    touch($this->db);
                }
            }
            $this->data = json_decode(file_get_contents($this->db), true);
            if (!$this->data) $this->data = [];
        }
        return $this->data;
    }

    /**
     * Сохранение в локальную базу
     */
    public function save() {
        file_put_contents($this->db, json_encode($this->data, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
    }


}
