<?php

declare(strict_types=1);


/*
|--------------------------------------------------------------------------
| Category Slug
|--------------------------------------------------------------------------
*/

function category_slugify(string $value): string
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
| Unique Category Slug
|--------------------------------------------------------------------------
*/

function generate_unique_category_slug(
    PDO $pdo,
    string $name,
    ?int $excludeId = null
): string {
    $baseSlug = category_slugify($name);

    if ($baseSlug === '') {
        $baseSlug = 'category';
    }

    $slug = $baseSlug;
    $counter = 2;

    while (true) {
        if ($excludeId !== null) {
            $statement = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM categories
                 WHERE slug = :slug
                 AND id != :id'
            );

            $statement->execute([
                'slug' => $slug,
                'id' => $excludeId,
            ]);
        } else {
            $statement = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM categories
                 WHERE slug = :slug'
            );

            $statement->execute([
                'slug' => $slug,
            ]);
        }

        if ((int) $statement->fetchColumn() === 0) {
            return $slug;
        }

        $slug = $baseSlug . '-' . $counter;

        $counter++;
    }
}


/*
|--------------------------------------------------------------------------
| Upload Category Image
|--------------------------------------------------------------------------
*/

function upload_category_image(array $file): string
{
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
            'Category image is required.'
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

    $maxSize = 5 * 1024 * 1024;

    if ((int) $file['size'] > $maxSize) {
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

    if (
        @getimagesize(
            $file['tmp_name']
        ) === false
    ) {
        throw new RuntimeException(
            'The uploaded file is not a valid image.'
        );
    }

    $finfo = new finfo(
        FILEINFO_MIME_TYPE
    );

    $mime = $finfo->file(
        $file['tmp_name']
    );

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($allowed[$mime])) {
        throw new RuntimeException(
            'Only JPG, PNG and WEBP images are allowed.'
        );
    }

    $directory =
        dirname(__DIR__) .
        '/uploads/categories';

    if (!is_dir($directory)) {
        mkdir(
            $directory,
            0755,
            true
        );
    }

    $filename =
        bin2hex(
            random_bytes(16)
        ) .
        '.' .
        $allowed[$mime];

    $destination =
        $directory .
        DIRECTORY_SEPARATOR .
        $filename;

    if (
        !move_uploaded_file(
            $file['tmp_name'],
            $destination
        )
    ) {
        throw new RuntimeException(
            'Unable to save category image.'
        );
    }

    return
        'uploads/categories/' .
        $filename;
}


/*
|--------------------------------------------------------------------------
| Delete Managed Category Image
|--------------------------------------------------------------------------
*/

function delete_category_image(
    ?string $imagePath
): void {
    if (!$imagePath) {
        return;
    }

    $imagePath =
        ltrim(
            $imagePath,
            '/'
        );

    if (
        !str_starts_with(
            $imagePath,
            'uploads/categories/'
        )
    ) {
        return;
    }

    $absolutePath =
        dirname(__DIR__) .
        '/' .
        $imagePath;

    if (is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}