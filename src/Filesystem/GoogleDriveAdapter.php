<?php

namespace Webteractive\GoogleDriveBackupManager\Filesystem;

use Google\Http\MediaFileUpload;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use GuzzleHttp\Psr7\StreamWrapper;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use Throwable;

/**
 * Minimal Flysystem v3 adapter for Google Drive, scoped to a single root folder.
 *
 * Paths are mapped onto Drive's parent/child folder structure. We auto-resolve
 * (and auto-create) any intermediate directories on write. File and folder
 * IDs are cached by path for the lifetime of the adapter instance.
 *
 * The adapter is intentionally tailored to the backup-upload workflow — large
 * file uploads go through resumable chunked uploads so multi-hundred-MB backup
 * zips stream rather than buffer.
 */
class GoogleDriveAdapter implements FilesystemAdapter
{
    private const FOLDER_MIME = 'application/vnd.google-apps.folder';

    private const RESUMABLE_THRESHOLD = 5 * 1024 * 1024; // 5MB

    private const CHUNK_SIZE = 1024 * 1024; // 1MB

    /** @var array<string, string> path => Drive file ID */
    private array $folderCache = [];

    public function __construct(
        private readonly Drive $service,
        private readonly string $rootId = 'root',
    ) {
        $this->folderCache[''] = $this->rootId;
    }

    public function fileExists(string $path): bool
    {
        return $this->findFile($path) !== null;
    }

    public function getDriveFileId(string $path): ?string
    {
        return $this->findFile($path)?->id;
    }

    public function directoryExists(string $path): bool
    {
        return $this->findFolderId($path) !== null;
    }

    public function write(string $path, string $contents, Config $config): void
    {
        try {
            $this->upload($path, $contents);
        } catch (Throwable $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        try {
            $this->upload($path, $contents);
        } catch (Throwable $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function read(string $path): string
    {
        $file = $this->findFile($path);

        if (! $file) {
            throw UnableToReadFile::fromLocation($path, 'File does not exist on Google Drive.');
        }

        try {
            $response = $this->service->files->get($file->id, ['alt' => 'media']);

            return (string) $response->getBody();
        } catch (Throwable $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }
    }

    public function readStream(string $path)
    {
        $file = $this->findFile($path);

        if (! $file) {
            throw UnableToReadFile::fromLocation($path, 'File does not exist on Google Drive.');
        }

        try {
            $response = $this->service->files->get($file->id, ['alt' => 'media']);

            // Wrap Guzzle's PSR-7 body as a php_stream resource — the
            // consumer (Laravel's response stream, Spatie's BackupDestination
            // delete loop, etc.) reads lazily instead of buffering the entire
            // file in php://temp.
            return StreamWrapper::getResource($response->getBody());
        } catch (Throwable $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }
    }

    public function delete(string $path): void
    {
        $file = $this->findFile($path);

        if (! $file) {
            return;
        }

        try {
            $this->service->files->delete($file->id);
        } catch (Throwable $e) {
            throw UnableToDeleteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function deleteDirectory(string $path): void
    {
        $folderId = $this->findFolderId($path);

        if (! $folderId || $folderId === $this->rootId) {
            return;
        }

        try {
            $this->service->files->delete($folderId);
        } catch (Throwable $e) {
            throw UnableToDeleteDirectory::atLocation($path, $e->getMessage(), $e);
        }

        $this->forgetFolderCacheBeneath($path);
    }

    public function createDirectory(string $path, Config $config): void
    {
        try {
            $this->ensureFolderPath($path);
        } catch (Throwable $e) {
            throw UnableToCreateDirectory::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function setVisibility(string $path, string $visibility): void
    {
        // Drive permissions don't map cleanly onto Flysystem's public/private
        // visibility model. Treat as a no-op; the user manages sharing in Drive.
    }

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path, null, Visibility::PRIVATE);
    }

    public function mimeType(string $path): FileAttributes
    {
        return $this->fileAttributes($path, FileAttributes::ATTRIBUTE_MIME_TYPE);
    }

    public function lastModified(string $path): FileAttributes
    {
        return $this->fileAttributes($path, FileAttributes::ATTRIBUTE_LAST_MODIFIED);
    }

    public function fileSize(string $path): FileAttributes
    {
        return $this->fileAttributes($path, FileAttributes::ATTRIBUTE_FILE_SIZE);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $folderId = $this->findFolderId($path);

        if (! $folderId) {
            return;
        }

        $pageToken = null;
        $normalizedBase = trim($path, '/');

        do {
            $response = $this->service->files->listFiles([
                'q' => "'{$folderId}' in parents and trashed = false",
                'fields' => 'nextPageToken, files(id, name, mimeType, size, modifiedTime)',
                'pageSize' => 1000,
                'pageToken' => $pageToken,
                'spaces' => 'drive',
            ]);

            foreach ($response->files as $file) {
                $childPath = $normalizedBase === '' ? $file->name : $normalizedBase.'/'.$file->name;

                if ($file->mimeType === self::FOLDER_MIME) {
                    $this->folderCache[$childPath] = $file->id;
                    yield new DirectoryAttributes($childPath);

                    if ($deep) {
                        yield from $this->listContents($childPath, true);
                    }
                } else {
                    yield $this->fileToAttributes($childPath, $file);
                }
            }

            $pageToken = $response->nextPageToken;
        } while ($pageToken);
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $file = $this->findFile($source);

        if (! $file) {
            throw UnableToMoveFile::because('Source file not found', $source, $destination);
        }

        try {
            [$newParentPath, $newName] = $this->splitPath($destination);
            $newParentId = $this->ensureFolderPath($newParentPath);

            $current = $this->service->files->get($file->id, ['fields' => 'parents']);
            $removeParents = implode(',', $current->parents ?? []);

            $this->service->files->update(
                $file->id,
                new DriveFile(['name' => $newName]),
                array_filter([
                    'addParents' => $newParentId,
                    'removeParents' => $removeParents !== '' ? $removeParents : null,
                ]),
            );
        } catch (Throwable $e) {
            throw UnableToMoveFile::because($e->getMessage(), $source, $destination);
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $file = $this->findFile($source);

        if (! $file) {
            throw UnableToCopyFile::because('Source file not found', $source, $destination);
        }

        try {
            [$newParentPath, $newName] = $this->splitPath($destination);
            $newParentId = $this->ensureFolderPath($newParentPath);

            $this->service->files->copy($file->id, new DriveFile([
                'name' => $newName,
                'parents' => [$newParentId],
            ]));
        } catch (Throwable $e) {
            throw UnableToCopyFile::because($e->getMessage(), $source, $destination);
        }
    }

    // -------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------

    /**
     * @param  string|resource  $contents
     */
    private function upload(string $path, $contents): void
    {
        [$parentPath, $name] = $this->splitPath($path);
        $parentId = $this->ensureFolderPath($parentPath);
        $existing = $this->findFileInFolder($parentId, $name);
        $mimeType = $this->guessMimeType($name);

        $size = $this->measureContents($contents);

        // Empty payloads can't drive a resumable session (nextChunk would
        // never advance) — route them through the simple multipart path,
        // which handles zero-byte files correctly.
        if ($size === 0) {
            $this->uploadSimple($existing?->id, $parentId, $name, $mimeType, '');

            return;
        }

        if ($size !== null && $size <= self::RESUMABLE_THRESHOLD && ! is_resource($contents)) {
            $this->uploadSimple($existing?->id, $parentId, $name, $mimeType, (string) $contents);

            return;
        }

        $this->uploadResumable($existing?->id, $parentId, $name, $mimeType, $contents, $size);
    }

    private function uploadSimple(?string $existingId, string $parentId, string $name, string $mimeType, string $contents): void
    {
        $params = [
            'data' => $contents,
            'mimeType' => $mimeType,
            'uploadType' => 'multipart',
            'fields' => 'id, name, size, mimeType, modifiedTime',
        ];

        if ($existingId) {
            $this->service->files->update($existingId, new DriveFile, $params);

            return;
        }

        $metadata = new DriveFile([
            'name' => $name,
            'parents' => [$parentId],
        ]);

        $this->service->files->create($metadata, $params);
    }

    /**
     * @param  string|resource  $contents
     */
    private function uploadResumable(?string $existingId, string $parentId, string $name, string $mimeType, $contents, ?int $size): void
    {
        $client = $this->service->getClient();
        $client->setDefer(true);

        try {
            if ($existingId) {
                $request = $this->service->files->update($existingId, new DriveFile, ['uploadType' => 'resumable']);
            } else {
                $request = $this->service->files->create(new DriveFile([
                    'name' => $name,
                    'parents' => [$parentId],
                ]), ['uploadType' => 'resumable']);
            }

            $media = new MediaFileUpload($client, $request, $mimeType, null, true, self::CHUNK_SIZE);

            if ($size !== null) {
                $media->setFileSize($size);
            }

            $handle = $this->asReadableStream($contents);

            try {
                $status = false;

                while (! $status && ! feof($handle)) {
                    $chunk = fread($handle, self::CHUNK_SIZE);

                    if ($chunk === false) {
                        throw new \RuntimeException('Failed to read upload chunk from stream.');
                    }

                    $status = $media->nextChunk($chunk);
                }
            } finally {
                if (is_resource($handle) && ! is_resource($contents)) {
                    fclose($handle);
                }
            }
        } finally {
            $client->setDefer(false);
        }
    }

    /**
     * @param  string|resource  $contents
     * @return resource
     */
    private function asReadableStream($contents)
    {
        if (is_resource($contents)) {
            return $contents;
        }

        $handle = fopen('php://temp', 'w+b');
        fwrite($handle, (string) $contents);
        rewind($handle);

        return $handle;
    }

    /**
     * @param  string|resource  $contents
     */
    private function measureContents($contents): ?int
    {
        if (is_string($contents)) {
            return strlen($contents);
        }

        if (is_resource($contents)) {
            $stat = @fstat($contents);

            return isset($stat['size']) && $stat['size'] > 0 ? (int) $stat['size'] : null;
        }

        return null;
    }

    private function fileAttributes(string $path, string $type): FileAttributes
    {
        $file = $this->findFile($path);

        if (! $file) {
            throw UnableToRetrieveMetadata::create($path, $type, 'File does not exist on Google Drive.');
        }

        return $this->fileToAttributes($path, $file);
    }

    private function fileToAttributes(string $path, DriveFile $file): FileAttributes
    {
        return new FileAttributes(
            $path,
            $file->size !== null ? (int) $file->size : null,
            Visibility::PRIVATE,
            $file->modifiedTime ? strtotime($file->modifiedTime) : null,
            $file->mimeType,
        );
    }

    private function findFile(string $path): ?DriveFile
    {
        $path = trim($path, '/');

        if ($path === '') {
            return null;
        }

        [$parentPath, $name] = $this->splitPath($path);
        $parentId = $this->findFolderId($parentPath);

        if (! $parentId) {
            return null;
        }

        return $this->findFileInFolder($parentId, $name);
    }

    private function findFileInFolder(string $parentId, string $name): ?DriveFile
    {
        $escaped = self::escapeQuery($name);

        $response = $this->service->files->listFiles([
            'q' => "'{$parentId}' in parents and name = '{$escaped}' and trashed = false and mimeType != '".self::FOLDER_MIME."'",
            'fields' => 'files(id, name, mimeType, size, modifiedTime)',
            'pageSize' => 1,
            'spaces' => 'drive',
        ]);

        return $response->files[0] ?? null;
    }

    private function findFolderId(string $path): ?string
    {
        $path = trim($path, '/');

        if (array_key_exists($path, $this->folderCache)) {
            return $this->folderCache[$path];
        }

        $segments = explode('/', $path);
        $parentId = $this->rootId;
        $accumulated = '';

        foreach ($segments as $segment) {
            $accumulated = $accumulated === '' ? $segment : $accumulated.'/'.$segment;

            if (array_key_exists($accumulated, $this->folderCache)) {
                $parentId = $this->folderCache[$accumulated];

                continue;
            }

            $escaped = self::escapeQuery($segment);

            $response = $this->service->files->listFiles([
                'q' => "'{$parentId}' in parents and name = '{$escaped}' and mimeType = '".self::FOLDER_MIME."' and trashed = false",
                'fields' => 'files(id)',
                'pageSize' => 1,
                'spaces' => 'drive',
            ]);

            if (count($response->files) === 0) {
                return null;
            }

            $parentId = $response->files[0]->id;
            $this->folderCache[$accumulated] = $parentId;
        }

        return $parentId;
    }

    private function ensureFolderPath(string $path): string
    {
        $path = trim($path, '/');

        if ($path === '') {
            return $this->rootId;
        }

        if (array_key_exists($path, $this->folderCache)) {
            return $this->folderCache[$path];
        }

        $segments = explode('/', $path);
        $parentId = $this->rootId;
        $accumulated = '';

        foreach ($segments as $segment) {
            $accumulated = $accumulated === '' ? $segment : $accumulated.'/'.$segment;

            if (array_key_exists($accumulated, $this->folderCache)) {
                $parentId = $this->folderCache[$accumulated];

                continue;
            }

            $escaped = self::escapeQuery($segment);

            $response = $this->service->files->listFiles([
                'q' => "'{$parentId}' in parents and name = '{$escaped}' and mimeType = '".self::FOLDER_MIME."' and trashed = false",
                'fields' => 'files(id)',
                'pageSize' => 1,
                'spaces' => 'drive',
            ]);

            if (count($response->files) > 0) {
                $parentId = $response->files[0]->id;
            } else {
                $created = $this->service->files->create(
                    new DriveFile([
                        'name' => $segment,
                        'parents' => [$parentId],
                        'mimeType' => self::FOLDER_MIME,
                    ]),
                    ['fields' => 'id'],
                );
                $parentId = $created->id;
            }

            $this->folderCache[$accumulated] = $parentId;
        }

        return $parentId;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitPath(string $path): array
    {
        $path = trim($path, '/');
        $pos = strrpos($path, '/');

        if ($pos === false) {
            return ['', $path];
        }

        return [substr($path, 0, $pos), substr($path, $pos + 1)];
    }

    private function forgetFolderCacheBeneath(string $path): void
    {
        $path = trim($path, '/');

        foreach (array_keys($this->folderCache) as $key) {
            if ($key === $path || str_starts_with($key, $path.'/')) {
                unset($this->folderCache[$key]);
            }
        }
    }

    /**
     * Escape a string for safe inclusion in a Drive v3 single-quoted query
     * literal. Public so other Drive callers (e.g. GoogleDriveConnection's
     * find-or-create) can reuse the same escaping rules.
     */
    public static function escapeQuery(string $value): string
    {
        // Strip control characters (NUL, newlines, etc.) that aren't valid
        // in Drive file names and could break the query parser, then escape
        // backslashes and single quotes for the single-quote-delimited
        // string literal.
        $value = preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '';

        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    private function guessMimeType(string $name): string
    {
        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'zip' => 'application/zip',
            'gz', 'gzip' => 'application/gzip',
            'tar' => 'application/x-tar',
            'sql' => 'application/sql',
            'json' => 'application/json',
            'txt', 'log' => 'text/plain',
            default => 'application/octet-stream',
        };
    }
}
