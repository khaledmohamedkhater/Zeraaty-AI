<?php
declare(strict_types=1);

/** Internal file operations used by PlantImageStorage. */
interface PlantImageStorageWriter
{
    /** @return resource|false */
    public function openExclusive(string $path): mixed;
    public function isOpen(mixed $handle): bool;
    public function write(mixed $handle, string $bytes): int|false;
    public function flush(mixed $handle): bool;
    public function close(mixed $handle): bool;
    public function isFile(string $path): bool;
    public function size(string $path): int|false;
    public function mimeType(string $path): string|false;
    public function delete(string $path): bool;
}

/** Production implementation. Tests may provide an isolated fault-injecting implementation. */
final class NativePlantImageStorageWriter implements PlantImageStorageWriter
{
    public function openExclusive(string $path): mixed
    {
        return @fopen($path, 'xb');
    }

    public function isOpen(mixed $handle): bool
    {
        return is_resource($handle);
    }

    public function write(mixed $handle, string $bytes): int|false
    {
        return @fwrite($handle, $bytes);
    }

    public function flush(mixed $handle): bool
    {
        return @fflush($handle);
    }

    public function close(mixed $handle): bool
    {
        return @fclose($handle);
    }

    public function isFile(string $path): bool
    {
        return is_file($path) && !is_link($path);
    }

    public function size(string $path): int|false
    {
        clearstatcache(true, $path);
        return @filesize($path);
    }

    public function mimeType(string $path): string|false
    {
        return (new finfo(FILEINFO_MIME_TYPE))->file($path);
    }

    public function delete(string $path): bool
    {
        return !file_exists($path) || @unlink($path);
    }
}
