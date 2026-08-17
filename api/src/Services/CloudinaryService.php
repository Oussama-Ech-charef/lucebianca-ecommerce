<?php

namespace App\Services;

use App\Core\Env;
use Cloudinary\Api\Exception\ApiError as CloudinaryApiError;
use Cloudinary\Cloudinary;
use RuntimeException;

/**
 * CloudinaryService — thin wrapper around the official cloudinary_php SDK.
 *
 * Reads CLOUDINARY_CLOUD_NAME / CLOUDINARY_API_KEY / CLOUDINARY_API_SECRET
 * from .env (never hardcoded) and exposes exactly what the API needs:
 * upload a local file → secure URL, and best-effort delete by public id.
 *
 * We deliberately use the vendor SDK for the signed upload request instead
 * of hand-rolling it — signing is crypto-adjacent and a vetted library is
 * the safer choice (spec 3.1).
 */
final class CloudinaryService
{
    private Cloudinary $cloudinary;

    public function __construct()
    {
        $cloudName = (string) Env::get('CLOUDINARY_CLOUD_NAME', '');
        $apiKey    = (string) Env::get('CLOUDINARY_API_KEY', '');
        $apiSecret = (string) Env::get('CLOUDINARY_API_SECRET', '');

        if ($cloudName === '' || $apiKey === '' || $apiSecret === '') {
            throw new RuntimeException(
                'Cloudinary credentials are missing from .env (CLOUDINARY_CLOUD_NAME/API_KEY/API_SECRET).'
            );
        }

        $this->cloudinary = new Cloudinary([
            'cloud' => [
                'cloud_name' => $cloudName,
                'api_key'    => $apiKey,
                'api_secret' => $apiSecret,
            ],
            'url'   => ['secure' => true],
        ]);
    }

    /**
     * Uploads a local file to Cloudinary and returns its secure URL.
     *
     * @param string $filePath Absolute path of the file to upload.
     * @param string $folder   Cloudinary folder to store it under.
     *
     * @return string The secure (https) URL, ready to store in product_images.
     *
     * @throws RuntimeException When Cloudinary rejects the upload.
     */
    public function upload(string $filePath, string $folder = 'lucebianca/products'): string
    {
        try {
            $response = $this->cloudinary->uploadApi()->upload($filePath, ['folder' => $folder]);
            $url      = $response['secure_url'];
        } catch (CloudinaryApiError $e) {
            throw new RuntimeException('Cloudinary upload failed: ' . $e->getMessage(), 0, $e);
        }

        if (!is_string($url) || $url === '') {
            throw new RuntimeException('Cloudinary returned no image URL.');
        }

        return $url;
    }

    /**
     * Duration-deletes an image by its public id (best effort; used for
     * cleanup of test/uploaded images). Missing assets are ignored.
     */
    public function delete(string $publicId): void
    {
        try {
            $this->cloudinary->uploadApi()->destroy($publicId);
        } catch (CloudinaryApiError) {
            // Best-effort: nothing to do if it is already gone or unreachable.
        }
    }
}