<?php

namespace rot;

function get_immutable_directory()
{
    return realpath(dirname(__FILE__));
}

function get_project_configuration($immutable_directory)
{
    $path = $immutable_directory . '/project.json';
    $config_raw = file_get_contents($path);
    if (!$config_raw)
    {
        die('Rot: Missing project configuration: ' . $path);
    }
    return json_decode($config_raw);
}

function get_mutable_directory($machine_local_id)
{
    $hashed_path = hash('sha512', $machine_local_id);
    $machine_local_id = substr($hashed_path, 0, 64);

    // sys_get_temp_dir() probably returns /tmp, which isn't ideal. /var/tmp would
    // be more appropriate.
    // @TODO: Allow configuration (through environmental variable or some file) for
    //        the mutable directory.
    $mutable_dir = sys_get_temp_dir() . '/rot-' . $machine_local_id;

    if (!file_exists($mutable_dir))
    {
        $success = mkdir($mutable_dir, 0700, true);
        if (!$success)
        {
            die('Rot: Could not create mutable directory: ' . $mutable_dir);
        }
    }

    return $mutable_dir;
}

function get_manifest($project_configuration)
{
    // @TODO: Cache this in APCu.
    // @TODO: Cache this somewhere else if there's no APCu.

    $rot_url = $project_configuration->manifest_url;

    if (substr($rot_url, 0, 8) === 'https://')
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $rot_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $manifest_raw = curl_exec($ch);
        curl_close($ch);

        if (!$manifest_raw)
        {
            die('Rot: Failed to download manifest from URL: ' . $rot_url);
        }
    } else {
        $manifest_raw = file_get_contents($rot_url);
        if (!$manifest_raw)
        {
            die('Rot: Failed to load manifest from URL: ' . $rot_url);
        }
    }

    $manifest = json_decode($manifest_raw, true);
    return $manifest;
}

function recursive_rmdir($dir) {
    if (file_exists($dir))
    {
        // @TODO: Actually use the removal code.
        echo '(Re)moving: ' . $dir . PHP_EOL;
        $success = rename($dir, $dir . '.' . time());

        // if (is_dir($dir)) {
        //     $entries = scandir($dir);
        //     foreach ($entries as $entry) {
        //         if ($entry !== '.' && $entry !== '..') {
        //             if (is_dir($dir . '/' .$entry))
        //             {
        //                 recursive_rmdir($dir . '/' . $entry);
        //             } else {
        //                 unlink($dir . '/' . $entry);
        //             }
        //         }
        //     }
        //     rmdir($dir);
        // }
        return $success;
    }
    return true;
}

function download_and_extract($mutable_directory, $partition, $package_data)
{
    $prefix = str_replace('/', '-', $package_data['name']);
    $path_in_project = $mutable_directory . '/' . $partition . '/vendor/' . $package_data['name'];

    $archive_path = tempnam(sys_get_temp_dir(), $prefix) . '.' . $package_data['dist']['type'];
    $fp = fopen($archive_path, 'w+');
    //$fp = tmpfile();

    if (!$fp)
    {
        die('Rot: Failed to open file for download use: ' . $archive_path);
    }

    $dist_url = $package_data['dist']['url'];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $dist_url);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Rot/0.1');
    $success = curl_exec($ch);
    curl_close($ch);
    fclose($fp);

    if (!$success)
    {
        die('Rot: Failed to download package from distribution URL: ' . $dist_url);
    }

    $success = recursive_rmdir($path_in_project);
    if (!$success)
    {
        die('Rot: Failed to remove existing package from path: ' . $path_in_project);
    }

    // Ensure the parent directory is ready.
    $parent_dir = dirname($path_in_project);
    if (!file_exists($parent_dir))
    {
        $success = mkdir($parent_dir, 0700, true);
        if (!$success)
        {
            die('Rot: Could not create project directory: ' . $mutable_dir);
        }

    }

    if ($package_data['dist']['type'] === 'zip')
    {
        $zip = new \ZipArchive;
        if ($zip->open($archive_path) === TRUE) {
            $zip->extractTo($path_in_project);
            $zip->close();
        } else {
            die('Rot: Failed to open zip archive: ' . $archive_path);
        }
    } else {
        die('Rot: Unsupported package dist type: ' . $package_data['dist']['type']);
    }

    // Update the dist metadata.
    $dist_path = $path_in_project . '/dist.json';
    $dist_raw = json_encode($package_data['dist']);
    $dfp = fopen($dist_path, 'w+');
    if (!$dfp)
    {
        die('Rot: Failed to open dist metadata for writing: ' . $dist_path);
    }
    $bytes_written = fwrite($dfp, $dist_raw);
    if (!$bytes_written)
    {
        die('Rot: Failed to write dist metadata: ' . $dist_path);
    }
    fclose($dfp);

    return true;
}

function is_package_stale($mutable_directory, $partition, $package_data)
{
    // @TODO: Cache this. Maybe use PHP to exploit the opcache for this?

    $package_dist = $package_data['dist'];
    $current_dist = false;
    $dist_path = $mutable_directory . '/' . $partition . '/vendor/' . $package_data['name'] . '/dist.json';
    if (file_exists($dist_path))
    {
        $current_dist_raw = file_get_contents($dist_path);
        if ($current_dist_raw)
        {
            $current_dist = json_decode($current_dist_raw, true);
        }
    }

    if (!$current_dist || $current_dist !==  $package_dist)
    {
        return true;
    }

    return false;
}

function get_active_configuration($mutable_directory)
{
    $active_config_path = $mutable_directory . '/active.json';
    if (file_exists($active_config_path)) {
        $active_config_raw = file_get_contents($active_config_path);
        if ($active_config_raw)
        {
            return json_decode($active_config_raw);
        }
    }

    return false;
}

function set_active_configuration($mutable_directory, $config)
{
    $active_config_path = $mutable_directory . '/active.json';
    $fp = fopen($active_config_path, 'w+');
    if (!$fp)
    {
        die('Rot: Failed to open active configuration for writing: ' . $active_config_path);
    }

    $json = json_encode($config);
    $bytes_written = fwrite($fp, $json);

    if (!$bytes_written)
    {
        die('Rot: Failed to write active configuration to file: ' . $active_config_path);
    }
    fclose($fp);
    return true;
}

function freshen_up($mutable_directory, $partition, array $manifest, $max_to_freshen=false)
{
    $freshened = 0;
    $checked = 0;
    foreach($manifest['packages'] as $package_data)
    {
        ++$checked;
        echo 'Rot: [' . $checked . '/' . count($manifest['packages']) . '] Checking package: ' . $package_data['name'] . PHP_EOL;
        if (is_package_stale($mutable_directory, $partition, $package_data))
        {
            echo '  - Package is missing or stale.' . PHP_EOL;
            if (download_and_extract($mutable_directory, $partition, $package_data))
            {
                ++$freshened;
            } else {
                return false;
            }
        } else {
            echo '  - Package is fresh.' . PHP_EOL;
        }

        if ($max_to_freshen && $freshened >= $max_to_freshen)
        {
            return false;
        }
    }

    // If everything is fresh.
    return true;
}

function count_stale($mutable_directory, $partition, array $manifest)
{
    $stale = 0;
    foreach($manifest['packages'] as $package_data)
    {
        if (is_package_stale($mutable_directory, $partition, $package_data))
        {
            ++$stale;
        }
    }
    return $stale;
}
function get_other_partition($partition)
{
    if ($partition === 'a')
    {
        return 'b';
    } else if ($partition === 'b') {
        return 'a';
    }
    die('Rot: Unknown partition to switch from: ' . $partition);
}

$immutable_dir = get_immutable_directory();
$project_config = get_project_configuration($immutable_dir);
$manifest = get_manifest($project_config);
$mutable_dir = get_mutable_directory($immutable_dir);

$active_config = get_active_configuration($mutable_dir);
$initialized = true;
if (!$active_config)
{
    $active_config = new \stdClass();
    $active_config->partition = 'a';
    $initialized = false;
}

// Either freshen the current partition (if initializing) or the inactive one.
if ($initialized)
{
    $freshen_partition = get_other_partition($active_config->partition);
} else {
    $freshen_partition = $active_config->partition;
}

$totally_fresh = freshen_up($mutable_dir, $freshen_partition, $manifest, $initialized ? 1 : 8);
if ($totally_fresh)
{
    // If done initializing *or* the inactive partition is fresher, set the active one.
    if (!$initialized || count_stale($mutable_dir, $active_config->partition, $manifest))
    {
        // Write a configuration for the fresh one (current or other).
        $active_config = new \stdClass();
        $active_config->partition = $freshen_partition;
        $success = set_active_configuration($mutable_dir, $active_config);
        if (!$success)
        {
            die('Rot: Failed to switch active configuration following completed freshening.');
        }
    }
} else if (!$initialized) {
    die('Rot: Still initializing. Please reload.');
}

// Hand off control of the request to the active partition.
require $mutable_dir .'/'. $active_config->partition . '/web/index.php';

// @TODO: Convert the chainloader to simply including a PHP file (below).
// Using a PHP file as the "configuration" for the chainloader allows it to
// remain in the opcache and get replaced using opcache_invalidate().
//$chainloader = $mutable_directory . '/chainloader.php';
//if((@include $chainloader) === false)
//{
//    require dirname(__FILE__) . . '/initialize.php';
//}

