<?php

namespace Prowebcraft\Telebot\Menu;

class MenuCollection implements \ArrayAccess, \Iterator, \Countable
{

    /** @var MenuItem[]  */
    protected array $items = [];

    public function __construct(array $items = [], protected ?string $parent = null) {
        foreach ($items as $item) {
            $this->add($item, $parent);
        }
    }

    public function getMenuButtons(): array
    {
        $menu = [];
        foreach ($this->items as $item) {
            if ($item->getLink()) {
                $menu[] = [
                    [
                        'text' => $item->title,
                        'url' => $item->getLink(),
                    ],
                ];
            } else {
                $menu[] = [
                    [
                        'text' => $item->title,
                        'callback_data' => $item->code,
                    ],
                ];
            }
        }
        return $menu;
    }

    /**
     * Add an item to the collection.
     *
     * @param MenuItem $item
     */
    public function add(MenuItem|array $item, ?string $parent = null): void
    {
        if (is_array($item)) {
            $this->items[] = new MenuItem(
                $item['code'],
                $item['title'],
                $item['body'] ?? '',
                $item['menu'] ?? [],
                $parent,
                $item['action'] ?? null,
                $item['link'] ?? null,
            );
        } else {
            $this->items[] = $item;
        }
    }

    // ArrayAccess methods
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->items[$offset]);
    }

    /**
     * @param mixed $offset
     * @return MenuItem|null
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (!$value instanceof MenuItem) {
            throw new \InvalidArgumentException('Value must be an instance of MenuItem');
        }
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }

    // Iterator methods
    private int $position = 0;

    /**
     * @return MenuItem
     */
    public function current(): mixed
    {
        return $this->items[$this->position];
    }

    public function next(): void
    {
        $this->position++;
    }

    public function key(): mixed
    {
        return $this->position;
    }

    public function valid(): bool
    {
        return isset($this->items[$this->position]);
    }

    public function rewind(): void
    {
        $this->position = 0;
    }

    // Countable method
    public function count(): int
    {
        return count($this->items);
    }
}