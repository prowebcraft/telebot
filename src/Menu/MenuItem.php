<?php

namespace Prowebcraft\Telebot\Menu;

class MenuItem
{

    protected MenuCollection $menu;

    public function __construct(
        public string $code,
        public string $title,
        public ?string $body = null,
        array $menu = [],
        public ?string $parent = null,
        public ?string $action = null,
        public ?string $link = null,
    ){
        $this->menu = new MenuCollection($menu, $this->code);
    }

    public function getMessage(): string
    {
        return sprintf("<b>%s</b>\n\n%s", $this->title, $this->body);
    }

    public function getIndex(): array
    {
        $index = [
            $this->code => $this,
        ];
        foreach ($this->menu as $item) {
            if (isset($index[$item->code])) {
                throw new \RuntimeException("Index key $item->code already exists");
            }
            $index[$item->code] = $item;
        }
        return $index;
    }

    public function getMenu(): MenuCollection
    {
        return $this->menu;
    }

    public function getMenuButtons(): array
    {
        $menu = $this->menu->getMenuButtons();
        if ($this->parent) {
            $menu[] = [
                [
                    'text' => '🔙 Вернуться назад',
                    'callback_data' => $this->parent,
                ],
            ];
        }
        return $menu;
    }

    public function getAction(): ?string
    {
        return $this->action;
    }

    public function getLink(): ?string
    {
        return $this->link;
    }
}