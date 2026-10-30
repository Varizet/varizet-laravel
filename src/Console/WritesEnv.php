<?php

declare(strict_types=1);

namespace Varizet\Laravel\Console;

/** Writes `NAME=value` lines into the app's .env (replacing existing ones), never logging values. */
trait WritesEnv
{
    /** @param array<string, string> $values */
    private function writeEnv(array $values): void
    {
        $path = $this->laravel->environmentFilePath();
        $content = is_file($path) ? (string) file_get_contents($path) : '';
        foreach ($values as $name => $value) {
            $line = $name . '=' . $value;
            $pattern = '/^' . preg_quote($name, '/') . '=.*$/m';
            $content = preg_match($pattern, $content) === 1
                ? (string) preg_replace_callback($pattern, static fn (): string => $line, $content)
                : rtrim($content, "\n") . ($content === '' ? '' : "\n") . $line . "\n";
        }
        file_put_contents($path, $content);
        $this->info(sprintf(
            '%s در %s ذخیره شد. اگر config کش شده، php artisan config:clear بزنید.',
            implode(' و ', array_keys($values)),
            $path,
        ));
    }
}
