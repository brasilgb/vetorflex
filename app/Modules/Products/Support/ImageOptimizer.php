<?php

namespace App\Modules\Products\Support;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Recodifica a imagem em WebP, o que reduz o tamanho e descarta metadados (EXIF, GPS) e conteúdo embutido.
 */
class ImageOptimizer
{
    private const QUALITY = 82;

    /** @return array{contents: string, mime_type: string, extension: string, width: int, height: int} */
    public function optimize(UploadedFile $file): array
    {
        $source = (string) $file->getContent();

        if (! function_exists('imagewebp')) {
            return $this->original($file, $source);
        }

        $image = @imagecreatefromstring($source);

        if ($image === false) {
            throw $this->invalidImage();
        }

        $image = $this->applyOrientation($this->resize($image), $file);
        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        ob_start();
        $isEncoded = imagewebp($image, null, self::QUALITY);
        $contents = (string) ob_get_clean();

        if (! $isEncoded || $contents === '') {
            throw $this->invalidImage();
        }

        return ['contents' => $contents, 'mime_type' => 'image/webp', 'extension' => 'webp', 'width' => imagesx($image), 'height' => imagesy($image)];
    }

    /** @return array{contents: string, mime_type: string, extension: string, width: int, height: int} */
    private function original(UploadedFile $file, string $contents): array
    {
        $size = getimagesizefromstring($contents);
        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$size['mime'] ?? ''] ?? null;

        if ($size === false || $extension === null) {
            throw $this->invalidImage();
        }

        return ['contents' => $contents, 'mime_type' => $size['mime'], 'extension' => $extension, 'width' => $size[0], 'height' => $size[1]];
    }

    private function resize(GdImage $image): GdImage
    {
        $maximum = (int) config('products.images.maximum_dimension');
        $ratio = min(1, $maximum / max(imagesx($image), imagesy($image)));

        if ($ratio >= 1) {
            return $image;
        }

        $width = max(1, (int) round(imagesx($image) * $ratio));
        $height = max(1, (int) round(imagesy($image) * $ratio));
        $resized = imagecreatetruecolor($width, $height);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));

        return $resized;
    }

    private function applyOrientation(GdImage $image, UploadedFile $file): GdImage
    {
        if ($file->getMimeType() !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($file->getPathname());
        $angle = match ($exif['Orientation'] ?? 1) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        return $angle === 0 ? $image : (imagerotate($image, $angle, 0) ?: $image);
    }

    private function invalidImage(): ValidationException
    {
        return ValidationException::withMessages(['image' => 'O arquivo enviado não é uma imagem válida.']);
    }
}
