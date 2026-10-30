<?php

declare(strict_types=1);


/*
|--------------------------------------------------------------------------
| Product Slug
|--------------------------------------------------------------------------
*/

function product_slugify(string $value): string
{
    $value = trim($value);

    $transliterated = iconv(
        'UTF-8',
        'ASCII//TRANSLIT//IGNORE',
        $value
    );

    if ($transliterated !== false) {
        $value = $transliterated;
    }

    $value = strtolower($value);

    $value = preg_replace(
        '/[^a-z0-9]+/',
        '-',
        $value
    ) ?? '';

    return trim($value, '-');
}


/*
|--------------------------------------------------------------------------
| Unique Product Slug
|--------------------------------------------------------------------------
*/

function generate_unique_product_slug(
    PDO $pdo,
    string $name,
    ?int $excludeProductId = null
): string {
    $baseSlug = product_slugify($name);

    if ($baseSlug === '') {
        $baseSlug = 'product';
    }

    $slug = $baseSlug;
    $counter = 2;

    while (true) {

        if ($excludeProductId !== null) {

            $statement = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM products
                 WHERE
                    slug = :slug
                    AND id != :id'
            );

            $statement->execute([
                'slug' => $slug,
                'id' => $excludeProductId,
            ]);

        } else {

            $statement = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM products
                 WHERE slug = :slug'
            );

            $statement->execute([
                'slug' => $slug,
            ]);
        }

        $exists = (int) $statement->fetchColumn();

        if ($exists === 0) {
            return $slug;
        }

        $slug =
            $baseSlug .
            '-' .
            $counter;

        $counter++;
    }
}


/*
|--------------------------------------------------------------------------
| SKU Part
|--------------------------------------------------------------------------
*/

function product_sku_part(string $value): string
{
    $value = trim($value);

    $transliterated = iconv(
        'UTF-8',
        'ASCII//TRANSLIT//IGNORE',
        $value
    );

    if ($transliterated !== false) {
        $value = $transliterated;
    }

    $value = strtoupper($value);

    $value = preg_replace(
        '/[^A-Z0-9]+/',
        '-',
        $value
    ) ?? '';

    return trim($value, '-');
}


/*
|--------------------------------------------------------------------------
| Upload Product Image
|--------------------------------------------------------------------------
*/

function upload_product_image(
    array $file
): string {
    if (
        !isset(
        $file['error'],
        $file['tmp_name'],
        $file['size']
    )
    ) {
        throw new RuntimeException(
            'Invalid image upload.'
        );
    }

    if (
        (int) $file['error'] ===
        UPLOAD_ERR_NO_FILE
    ) {
        throw new RuntimeException(
            'Product image is required.'
        );
    }

    if (
        (int) $file['error'] !==
        UPLOAD_ERR_OK
    ) {
        throw new RuntimeException(
            'The image could not be uploaded.'
        );
    }

    $maxFileSize =
        5 * 1024 * 1024;

    if (
        (int) $file['size'] >
        $maxFileSize
    ) {
        throw new RuntimeException(
            'The image must be smaller than 5 MB.'
        );
    }

    if (
        !is_uploaded_file(
            $file['tmp_name']
        )
    ) {
        throw new RuntimeException(
            'Invalid uploaded file.'
        );
    }

    $imageInfo =
        @getimagesize(
            $file['tmp_name']
        );

    if ($imageInfo === false) {
        throw new RuntimeException(
            'The uploaded file is not a valid image.'
        );
    }

    $finfo =
        new finfo(
            FILEINFO_MIME_TYPE
        );

    $mimeType =
        $finfo->file(
            $file['tmp_name']
        );

    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (
        !isset(
        $allowedTypes[$mimeType]
    )
    ) {
        throw new RuntimeException(
            'Only JPG, PNG and WEBP images are allowed.'
        );
    }

    $extension =
        $allowedTypes[$mimeType];

    $uploadDirectory =
        dirname(__DIR__) .
        '/uploads/products';

    if (
        !is_dir(
            $uploadDirectory
        )
    ) {
        if (
            !mkdir(
                $uploadDirectory,
                0755,
                true
            ) &&
            !is_dir(
                $uploadDirectory
            )
        ) {
            throw new RuntimeException(
                'Unable to create upload directory.'
            );
        }
    }

    $filename =
        bin2hex(
            random_bytes(16)
        ) .
        '.' .
        $extension;

    $destination =
        $uploadDirectory .
        DIRECTORY_SEPARATOR .
        $filename;

    if (
        !move_uploaded_file(
            $file['tmp_name'],
            $destination
        )
    ) {
        throw new RuntimeException(
            'Unable to save product image.'
        );
    }

    return
        'uploads/products/' .
        $filename;
}


/*
|--------------------------------------------------------------------------
| Product Image Absolute Path
|--------------------------------------------------------------------------
*/

function product_image_absolute_path(
    string $imagePath
): ?string {
    $imagePath =
        ltrim(
            trim($imagePath),
            '/'
        );

    /*
    |--------------------------------------------------------------------------
    | Only Allow Managed Uploads
    |--------------------------------------------------------------------------
    */

    if (
        !str_starts_with(
            $imagePath,
            'uploads/products/'
        )
    ) {
        return null;
    }

    return
        dirname(__DIR__) .
        '/' .
        $imagePath;
}


/*
|--------------------------------------------------------------------------
| Delete Product Image
|--------------------------------------------------------------------------
*/

function delete_product_image(
    ?string $imagePath
): void {
    if (
        $imagePath === null ||
        trim($imagePath) === ''
    ) {
        return;
    }

    $absolutePath =
        product_image_absolute_path(
            $imagePath
        );

    if ($absolutePath === null) {
        return;
    }

    if (
        is_file(
            $absolutePath
        )
    ) {
        @unlink(
            $absolutePath
        );
    }
}

/*
|--------------------------------------------------------------------------
| Normalize Multiple Uploads
|--------------------------------------------------------------------------
*/

function normalize_multiple_uploads(
    array $files
): array {
    if (
        !isset(
        $files['name'],
        $files['type'],
        $files['tmp_name'],
        $files['error'],
        $files['size']
    )
    ) {
        return [];
    }

    if (!is_array($files['name'])) {
        return [$files];
    }

    $normalizedFiles = [];

    foreach (
        $files['name']
        as
        $index => $name
    ) {
        $error =
            $files['error'][$index]
            ?? UPLOAD_ERR_NO_FILE;

        if (
            (int) $error ===
            UPLOAD_ERR_NO_FILE
        ) {
            continue;
        }

        $normalizedFiles[] = [
            'name' =>
                $name,

            'type' =>
                $files['type'][$index]
                ?? '',

            'tmp_name' =>
                $files['tmp_name'][$index]
                ?? '',

            'error' =>
                $error,

            'size' =>
                $files['size'][$index]
                ?? 0,
        ];
    }

    return $normalizedFiles;
}


/*
|--------------------------------------------------------------------------
| Upload Product Gallery Images
|--------------------------------------------------------------------------
*/

function upload_product_gallery_images(
    array $files
): array {
    $normalizedFiles =
        normalize_multiple_uploads(
            $files
        );

    $uploadedImages = [];

    try {

        foreach (
            $normalizedFiles
            as
            $file
        ) {
            $uploadedImages[] =
                upload_product_image(
                    $file
                );
        }

        return $uploadedImages;

    } catch (
        Throwable $exception
    ) {

        foreach (
            $uploadedImages
            as
            $imagePath
        ) {
            delete_product_image(
                $imagePath
            );
        }

        throw $exception;
    }
}