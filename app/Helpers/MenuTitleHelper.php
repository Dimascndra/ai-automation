<?php

use Illuminate\Support\Facades\Request;

if (! function_exists('getPageTitle')) {
    function getPageTitle(): string
    {
        $configs = [
            'menus.custom',
            'menus.layouts',
            'menus.crud',
            'menus.features',
        ];

        // dapatkan current path (tanpa query string)
        $currentPath = '/' . ltrim(Request::path(), '/');

        foreach ($configs as $config) {
            $menus = config($config);
            if (is_array($menus)) {
                foreach ($menus as $items) {
                    $title = searchMenuTitle($items['submenu'] ?? [], $currentPath);
                    if ($title) {
                        return $title;
                    }
                }
            }
        }

        // fallback
        return config('app.name', 'Metronic 2020');
    }
}

if (! function_exists('searchMenuTitle')) {
    function searchMenuTitle(array $items, string $currentPath): ?string
    {
        foreach ($items as $item) {
            if (isset($item['route']) && $item['route'] === $currentPath) {
                return $item['title'] ?? null;
            }

            if (!empty($item['submenu'])) {
                $found = searchMenuTitle($item['submenu'], $currentPath);
                if ($found) {
                    return $found;
                }
            }
        }
        return null;
    }
}
