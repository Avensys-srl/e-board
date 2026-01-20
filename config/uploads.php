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

function build_project_subdir($code, $version)
{
    $code = preg_replace('/[^A-Za-z0-9_-]+/', '_', strtoupper(trim($code)));
    $version = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim($version));
    if ($code === '' || $version === '') {
        return '';
    }
    return 'projects/' . $code . '/' . $version;
}

function store_uploaded_file($file, $base_path, $base_url, $project_subdir = '')
{
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return [false, null, "Please select a file to upload."];
    }

    $base_fs_path = resolve_upload_base_path($base_path);
    if (!is_dir($base_fs_path) && !mkdir($base_fs_path, 0775, true)) {
        return [false, null, "Unable to create upload directory."];
    }

    $project_subdir = trim($project_subdir, "/\\");
    $subdir = date('Y/m');
    if ($project_subdir !== '') {
        $subdir = $project_subdir . '/' . $subdir;
    }
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

function copy_existing_file($base_path, $base_url, $source_relative, $project_subdir = '')
{
    $base_fs_path = resolve_upload_base_path($base_path);
    $source_relative = str_replace('/', DIRECTORY_SEPARATOR, $source_relative);
    $source_path = $base_fs_path . DIRECTORY_SEPARATOR . $source_relative;
    if (!is_file($source_path)) {
        return [false, null, "Source file not found."];
    }

    $project_subdir = trim($project_subdir, "/\\");
    $subdir = date('Y/m');
    if ($project_subdir !== '') {
        $subdir = $project_subdir . '/' . $subdir;
    }
    $target_dir = $base_fs_path . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $subdir);
    if (!is_dir($target_dir) && !mkdir($target_dir, 0775, true)) {
        return [false, null, "Unable to create upload subdirectory."];
    }

    $extension = pathinfo($source_path, PATHINFO_EXTENSION);
    $safe_name = uniqid('file_', true);
    if ($extension !== '') {
        $safe_name .= '.' . strtolower($extension);
    }

    $target_path = $target_dir . DIRECTORY_SEPARATOR . $safe_name;
    if (!copy($source_path, $target_path)) {
        return [false, null, "Unable to copy file."];
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
        $mime_type = mime_content_type($target_path);
    }

    return [true, [
        'original_name' => basename($source_path),
        'storage_path' => str_replace('\\', '/', $relative_path),
        'storage_url' => $storage_url,
        'mime_type' => $mime_type,
        'size_bytes' => (int) filesize($target_path),
    ], null];
}
