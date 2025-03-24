<?php

namespace Prowebcraft\Telebot\Menu;


abstract class AbstractFactory
{
    protected static ?MenuCollection $collection = null;
    protected static array $index = [];

    abstract protected static function getData(): array;

    public static function getCollection(): MenuCollection
    {
        if (!self::$collection) {
            $items = static::getData();

            self::$collection = new MenuCollection($items, 'root');
            self::buildIndex();
        }

        return self::$collection;
    }

    public static function getMenuItem(string $code): ?MenuItem
    {
        if (!self::$index) {
            self::getCollection();
        }
        return self::$index[$code] ?? null;
    }

    protected static function buildIndex()
    {
        self::$index = [];
        foreach (self::$collection as $item) {
            $itemIndex = $item->getIndex();
            foreach ($itemIndex as $indexKey => $indexItem) {
                if (isset(self::$index[$indexKey])) {
                    throw new \RuntimeException("Index key $indexKey already exists");
                }
                self::$index[$indexKey] = $indexItem;
            }
        }
        return self::$index;
    }
}