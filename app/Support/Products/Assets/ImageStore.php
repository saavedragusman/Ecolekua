<?php

namespace App\Support\Products\Assets;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Stores, replaces, removes and serves the image of an owner that has the `image_display_path` and
 * `image_thumb_path` columns (product, attribute value, detail location; design Decision 18).
 *
 * File and transaction ordering: the new files are written first, the transaction stores their
 * paths, and on any exception the new files are deleted; the replaced files are deleted with
 * `DB::afterCommit()`, so a rolled back change never loses the previous image. A crash between the
 * write and the commit can leave an orphan file in a private directory: it is never served, because
 * no row points to it (accepted in the design).
 */
final class ImageStore
{
    private const DISK = 'local';

    public function __construct(private readonly ImageProcessor $processor) {}

    /**
     * @param  string  $directory  directory on the private disk, e.g. `catalog/products/12`
     * @param  Closure(Model, string|null, string|null): mixed  $audit  receives the locked owner, the previous and the new display path; runs inside the transaction
     *
     * @throws ValidationException when the file is not an acceptable image
     */
    public function replace(Model $owner, string $directory, UploadedFile $file, Closure $audit): void
    {
        try {
            $variants = $this->processor->process($file->getContent());
        } catch (InvalidImageException $exception) {
            throw ValidationException::withMessages([
                'image' => __('validation.image_'.$exception->reason, ['side' => AssetLimits::MAX_SIDE]),
            ]);
        }

        $written = [];

        try {
            DB::transaction(function () use ($owner, $directory, $variants, $audit, &$written): void {
                $owner = $this->lock($owner);
                $previous = $this->paths($owner);

                $written = $this->write($directory, $variants);

                $owner->forceFill([
                    'image_display_path' => $written[ImageProcessor::DISPLAY],
                    'image_thumb_path' => $written[ImageProcessor::THUMB],
                ])->save();

                $audit($owner, $previous[ImageProcessor::DISPLAY] ?? null, $written[ImageProcessor::DISPLAY]);

                $this->deleteAfterCommit(array_values($previous));
            });
        } catch (Throwable $exception) {
            $this->disk()->delete(array_values($written));

            throw $exception;
        }
    }

    /**
     * Clears the image of the owner. Nothing happens, and nothing is audited, when it has none.
     *
     * @param  Closure(Model, string|null, string|null): mixed  $audit  receives the locked owner, the previous display path and null
     */
    public function remove(Model $owner, Closure $audit): void
    {
        DB::transaction(function () use ($owner, $audit): void {
            $owner = $this->lock($owner);
            $previous = $this->paths($owner);

            if ($previous === []) {
                return;
            }

            $owner->forceFill(['image_display_path' => null, 'image_thumb_path' => null])->save();

            $audit($owner, $previous[ImageProcessor::DISPLAY] ?? null, null);

            $this->deleteAfterCommit(array_values($previous));
        });
    }

    /**
     * Streams one variant of the owner with the headers the design requires (Decision 18): the
     * browser must not sniff the content, and only the user's own cache may keep it.
     *
     * @throws NotFoundHttpException when the owner has no such variant
     */
    public function response(Model $owner, string $variant): StreamedResponse
    {
        $path = $this->paths($owner)[$variant] ?? null;

        if ($path === null || ! $this->disk()->exists($path)) {
            abort(404);
        }

        return $this->disk()->response($path, null, [
            'Content-Type' => $this->disk()->mimeType($path) ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /**
     * Reads the owner again under a row lock, so two uploads for the same owner are serialized.
     */
    private function lock(Model $owner): Model
    {
        return $owner->newQuery()->lockForUpdate()->whereKey($owner->getKey())->firstOrFail();
    }

    /**
     * @return array<string, string> the stored path of each variant the owner has
     */
    private function paths(Model $owner): array
    {
        return array_filter([
            ImageProcessor::DISPLAY => $owner->getAttribute('image_display_path'),
            ImageProcessor::THUMB => $owner->getAttribute('image_thumb_path'),
        ], is_string(...));
    }

    /**
     * @param  array{display: string, thumb: string}  $variants
     * @return array{display: string, thumb: string} the stored path of each variant
     */
    private function write(string $directory, array $variants): array
    {
        $display = $this->put($directory, $variants[ImageProcessor::DISPLAY]);

        try {
            $thumb = $this->put($directory, $variants[ImageProcessor::THUMB]);
        } catch (Throwable $exception) {
            $this->disk()->delete($display);

            throw $exception;
        }

        return [ImageProcessor::DISPLAY => $display, ImageProcessor::THUMB => $thumb];
    }

    /**
     * Writes one variant under a random ULID name and returns its path.
     */
    private function put(string $directory, string $bytes): string
    {
        $path = $directory.'/'.Str::lower((string) Str::ulid()).'.webp';

        if (! $this->disk()->put($path, $bytes)) {
            throw new RuntimeException("The image could not be written to [{$path}].");
        }

        return $path;
    }

    /**
     * @param  list<string>  $paths
     */
    private function deleteAfterCommit(array $paths): void
    {
        if ($paths !== []) {
            DB::afterCommit(fn (): bool => $this->disk()->delete($paths));
        }
    }

    private function disk(): FilesystemAdapter
    {
        return Storage::disk(self::DISK);
    }
}
