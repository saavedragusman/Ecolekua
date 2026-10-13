<?php

namespace App\Support\Products\Assets;

use App\Models\ProductTemplate;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Files of the SVG templates on the private disk (design Decision 18): written under a random ULID
 * name, deleted after the commit that stops pointing at them, and streamed with the headers the SVG
 * needs. Only sanitized bytes are ever written here.
 */
final class TemplateStore
{
    private const DISK = 'local';

    /**
     * Writes sanitized bytes under `catalog/templates/{productId}/{ulid}.svg` and returns the path.
     */
    public function put(int $productId, string $sanitizedSvg): string
    {
        $path = "catalog/templates/{$productId}/".Str::lower((string) Str::ulid()).'.svg';

        if (! $this->disk()->put($path, $sanitizedSvg)) {
            throw new RuntimeException("The template could not be written to [{$path}].");
        }

        return $path;
    }

    /**
     * Deletes the file immediately. Used to undo a write whose transaction failed.
     */
    public function delete(string $path): void
    {
        $this->disk()->delete($path);
    }

    /**
     * Deletes the file once the surrounding transaction commits, so a rolled back change never loses
     * the file the database still points to.
     */
    public function deleteAfterCommit(string $path): void
    {
        DB::afterCommit(fn (): bool => $this->disk()->delete($path));
    }

    /**
     * Streams the template. The SVG policy blocks scripts and any request the document could make,
     * and the pages show it through `<img src>` only (scripts never run in an image context).
     *
     * @throws NotFoundHttpException when the file is missing
     */
    public function response(ProductTemplate $template): StreamedResponse
    {
        if (! $this->disk()->exists($template->svg_path)) {
            abort(404);
        }

        return $this->disk()->response($template->svg_path, null, [
            'Content-Type' => 'image/svg+xml',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:",
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    private function disk(): FilesystemAdapter
    {
        return Storage::disk(self::DISK);
    }
}
