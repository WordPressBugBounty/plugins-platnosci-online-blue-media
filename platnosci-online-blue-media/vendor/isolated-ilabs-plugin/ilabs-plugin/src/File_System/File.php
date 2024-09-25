<?php

namespace Isolated\BlueMedia\Ilabs\Ilabs_Plugin\File_System;

use finfo;
class File
{
    private string $name;
    private ?string $path;
    private ?string $content;
    private ?string $temp_path;
    private ?string $mime_type;
    public function __construct(string $name, ?string $path = null, ?string $content = null)
    {
        $this->name = $name;
        $this->path = $path;
        $this->content = $content;
        $this->temp_path = null;
        $this->mime_type = null;
    }
    public function get_name() : string
    {
        return $this->name;
    }
    public function get_path() : ?string
    {
        return $this->path;
    }
    public function get_content() : ?string
    {
        return $this->content;
    }
    public function get_temp_path() : ?string
    {
        return $this->temp_path;
    }
    public function get_mime_type() : ?string
    {
        return $this->mime_type;
    }
    public function set_name(string $name) : void
    {
        $this->name = $name;
    }
    public function set_path(?string $path) : void
    {
        $this->path = $path;
    }
    public function set_content(?string $content) : void
    {
        $this->content = $content;
    }
    public function set_temp_path(?string $temp_path) : void
    {
        $this->temp_path = $temp_path;
    }
    public function set_mime_type(?string $mime_type) : void
    {
        $this->mime_type = $mime_type;
    }
    public function save_to_file(string $directory) : void
    {
        if ($this->path === null) {
            $this->path = $directory . \DIRECTORY_SEPARATOR . $this->name;
        }
        \file_put_contents($this->path, $this->content);
    }
    public function load_from_file(string $path) : void
    {
        $this->content = \file_get_contents($path);
    }
    public function save_as_temp() : void
    {
        $tempDirectory = \sys_get_temp_dir();
        $tempFileName = \uniqid('file_', \true);
        $this->temp_path = $tempDirectory . \DIRECTORY_SEPARATOR . $tempFileName;
        if ($this->path !== null && \file_exists($this->path)) {
            // If path is provided, copy the file to temp_path
            \copy($this->path, $this->temp_path);
        } else {
            // Otherwise, save the content to temp_path
            \file_put_contents($this->temp_path, $this->content);
        }
    }
    public function resolve_mime_type() : void
    {
        $filePath = $this->path ?? $this->temp_path;
        if ($filePath && \file_exists($filePath)) {
            $finfo = new finfo(\FILEINFO_MIME_TYPE);
            $this->mime_type = $finfo->file($filePath);
        } else {
            $this->mime_type = null;
        }
    }
}
