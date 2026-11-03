<?php

declare(strict_types=1);

namespace App\Core;

final class View
{
    private string $template;
    private array  $data;

    public function __construct(string $template, array $data = [])
    {
        $this->template = $template;
        $this->data     = $data;
    }

    public static function render(string $template, array $data = []): string
    {
        $view = new self($template, $data);
        return $view->compile();
    }

    public static function renderWithLayout(string $template, array $data = [], string $layout = 'layout/base'): void
    {
        $content = self::render($template, $data);
        $data['content'] = $content;
        echo self::render($layout, $data);
    }

    private function compile(): string
    {
        $templateFile = __DIR__ . '/../../app/Views/' . $this->template . '.php';

        if (!file_exists($templateFile)) {
            throw new \RuntimeException("View template not found: {$this->template}");
        }

        extract($this->data, EXTR_SKIP);

        ob_start();
        require $templateFile;
        return (string) ob_get_clean();
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
