<?php

namespace Isolated\BlueMedia\Ilabs\Ilabs_Plugin\File_System;

use Exception;
use ZipArchive;
class File_Downloader
{
    public function __construct()
    {
        // Hook for deleting expired transients
        /*add_action( 'delete_expired_transients',
        		[ $this, 'cleanup_expired_transients' ] );*/
    }
    /**
     * @param File[] $files
     * @param int $validity
     *
     * @return string
     * @throws Exception
     */
    public function get_download_url(array $files, int $validity) : string
    {
        // Check if there's only one file
        if (\count($files) === 1) {
            $file = $files[0];
            // Ensure the file has a temporary path
            if (empty($file->get_temp_path())) {
                $file->save_as_temp();
            }
            // Generate a random MD5 hash
            $random_md5 = \md5(\uniqid(\rand(), \true));
            // Prepare the transient value
            $transient_key = 'autopay_download_' . $random_md5;
            $transient_value = ['name' => $file->get_name(), 'path' => $file->get_temp_path(), 'mime' => $file->get_mime_type()];
            // Save the transient with the specified validity period
            set_transient($transient_key, $transient_value, $validity);
            // Generate the download URL
            $site_url = get_site_url();
            $download_url = $site_url . '?autopay_download=' . $random_md5;
            return $download_url;
        } else {
            // Handle multiple files - create a zip
            $zip = new ZipArchive();
            $zip_name = 'autopay_' . \time() . '.zip';
            $zip_path = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . $zip_name;
            if ($zip->open($zip_path, ZipArchive::CREATE) !== \true) {
                throw new Exception("Cannot open <{$zip_path}>\n");
            }
            foreach ($files as $file) {
                if (empty($file->get_temp_path())) {
                    $file->save_as_temp();
                }
                $zip->addFile($file->get_temp_path(), $file->get_name());
            }
            $zip->close();
            // Create a new File object for the zip file
            $zip_file = new File($zip_name, null, \file_get_contents($zip_path));
            $zip_file->set_temp_path($zip_path);
            $zip_file->resolve_mime_type();
            // Recursively call get_download_url with the zip file
            return $this->get_download_url([$zip_file], $validity);
        }
    }
    public function handle() : void
    {
        if (isset($_GET['autopay_download'])) {
            $download_key = sanitize_text_field($_GET['autopay_download']);
            $transient_key = 'autopay_download_' . $download_key;
            $transient_value = get_transient($transient_key);
            if ($transient_value) {
                $file = new File($transient_value['name']);
                $file->set_temp_path($transient_value['path']);
                $file->set_mime_type($transient_value['mime']);


                if (\file_exists($file->get_temp_path())) {
                    \header('Content-Description: File Transfer');
                    \header('Content-Type: ' . $file->get_mime_type());
                    \header('Content-Disposition: attachment; filename="' . \basename($file->get_name()) . '"');
                    \header('Expires: 0');
                    \header('Cache-Control: must-revalidate');
                    \header('Pragma: public');
                    \header('Content-Length: ' . \filesize($file->get_temp_path()));
                    \readfile($file->get_temp_path());
                    // Delete the temporary file and transient after serving the file
                    \unlink($file->get_temp_path());
                    delete_transient($transient_key);
                    exit;
                }
            }
        }
    }
    public function cleanup_expired_transients()
    {
        global $wpdb;
        // Query for expired transients
        $expired_transients = $wpdb->get_results("\n            SELECT option_name FROM {$wpdb->options}\n            WHERE option_name LIKE '_transient_timeout_autopay_download_%'\n            AND option_value < UNIX_TIMESTAMP()\n        ");
        foreach ($expired_transients as $transient) {
            $transient_key = \str_replace('_transient_timeout_', '', $transient->option_name);
            $file_info = get_transient($transient_key);
            if ($file_info && isset($file_info['path'])) {
                $temp_path = $file_info['path'];
                // Delete the file if it exists
                if (\file_exists($temp_path)) {
                    \unlink($temp_path);
                }
            }
            // Delete the transient
            delete_transient($transient_key);
        }
    }
}
