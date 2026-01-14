<?php
function resolve_upload_base_path($base_path)
{
    $base_path = trim($base_path);
    if ($base_path === '') {
        $base_path = 'uploads';
    }
    $base_path = rtrim($base_path, "/\\");
    if (preg_match('/^(?:[A-Za-z]:\\\\|\\\\\\\\|\\/)/', $base_path) === 1) {
        return $base_path;
    }
    return __DIR__ . '/..' . DIRECTORY_SEPARATOR . $base_path;
}

function store_uploaded_file($file, $base_path, $base_url)
{
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return [false, null, "Please select a file to upload."];
    }

    $base_fs_path = resolve_upload_base_path($base_path);
    if (!is_dir($base_fs_path) && !mkdir($base_fs_path, 0775, true)) {
        return [false, null, "Unable to create upload directory."];
    }

    $subdir = date('Y/m');
    $target_dir = $base_fs_path . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $subdir);
    if (!is_dir($target_dir) && !mkdir($target_dir, 0775, true)) {
        return [false, null, "Unable to create upload subdirectory."];
    }

    $original_name = basename($file['name']);
    $extension = pathinfo($original_name, PATHINFO_EXTENSION);
    $safe_name = uniqid('file_', true);
    if ($extension !== '') {
        $safe_name .= '.' . strtolower($extension);
    }

    $target_path = $target_dir . DIRECTORY_SEPARATOR . $safe_name;
    if (!move_uploaded_file($file['tmp_name'], $target_path)) {
        return [false, null, "Upload failed. Please try again."];
    }

    $relative_path = $subdir . '/' . $safe_name;
    $base_url = trim($base_url);
    $base_url = $base_url === '' ? '' : rtrim($base_url, '/');
    $storage_url = $base_url !== '' ? $base_url . '/' . $relative_path : null;

    $mime_type = null;
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime_type = finfo_file($finfo, $target_path);
            finfo_close($finfo);
        }
    }
    if ($mime_type === null) {
        $mime_type = $file['type'] ?? null;
    }

    return [true, [
        'original_name' => $original_name,
        'storage_path' => str_replace('\\', '/', $relative_path),
        'storage_url' => $storage_url,
        'mime_type' => $mime_type,
        'size_bytes' => (int) ($file['size'] ?? 0),
    ], null];
}
