<?php

declare(strict_types=1);

namespace App\Imports\FactoryStock;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Shrinks a sheet photo and stores it on the public disk.
 *
 * The sheet's photos are phone pictures of 1.5–3.5 MB each (364 MB in all);
 * they are scaled to fit MAX_SIDE and re-encoded as JPEG. Files are named
 * after the hash of the original bytes, so a photo shared by several rows is
 * stored once and re-running the import never duplicates files.
 */
final class ImageStore
{
    public const DIRECTORY = 'items';

    private const MAX_SIDE = 1000;

    private const JPEG_QUALITY = 80;

    public function pathFor(string $bytes): string
    {
        return self::DIRECTORY.'/'.sha1($bytes).'.jpg';
    }

    /**
     * Store the photo (if not already stored) and return its public-disk path.
     */
    public function store(string $bytes): string
    {
        $path = $this->pathFor($bytes);
        $disk = Storage::disk('public');

        if (! $disk->exists($path)) {
            $disk->put($path, $this->toJpeg($bytes));
        }

        return $path;
    }

    private function toJpeg(string $bytes): string
    {
        $source = @imagecreatefromstring($bytes);

        if ($source === false) {
            throw new RuntimeException('Unreadable image');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, self::MAX_SIDE / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        // White canvas: PNG transparency would otherwise turn black in JPEG.
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagejpeg($target, null, self::JPEG_QUALITY);
        $jpeg = (string) ob_get_clean();

        return $jpeg;
    }
}
