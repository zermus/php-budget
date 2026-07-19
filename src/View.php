<?php

declare(strict_types=1);

namespace App;

final class View
{
    /**
     * Render a template inside the shared layout.
     *
     * @param array<string, mixed> $data
     */
    public static function render(string $template, array $data = [], ?string $layout = 'layout'): string
    {
        $content = self::partial($template, $data);

        if ($layout === null) {
            return $content;
        }

        $data['content'] = $content;

        return self::partial($layout, $data);
    }

    /**
     * Render a template file with no layout.
     *
     * @param array<string, mixed> $data
     */
    public static function partial(string $template, array $data = []): string
    {
        $file = APP_ROOT . '/templates/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("Template not found: {$template}");
        }

        extract($data, EXTR_SKIP);
        ob_start();
        require $file;

        return (string) ob_get_clean();
    }
}
