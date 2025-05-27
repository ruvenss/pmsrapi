<?php
if (!defined('STDIN')) {
    header("HTTP/1.0 404 Not Found", true, 404);
    die();
}
if (file_exists(getcwd() . '/manifest.json')) {
    define("current_manifest", json_decode(file_get_contents(getcwd() . '/manifest.json'), true));
    define("current_version", current_manifest['version']);
    define("GITURL", "https://raw.githubusercontent.com/ruvenss/pmsrapi/master/");
    echo "🚦 Checking for updates above version " . current_version . "... \n";
    define("remote_manifest", json_decode(file_get_contents(GITURL . 'manifest.json'), true));
    define("remote_version", remote_manifest['version']);
    if (remote_version != current_version) {
        echo "🟢 New version " . remote_version . " found\n";
        echo "🚦 Updating the Microservice ...\n";
        define("UPDATABLE_FILES", remote_manifest['source-code']);
        for ($i = 0; $i < sizeof(UPDATABLE_FILES); $i++) {
            $file2update = UPDATABLE_FILES[$i];
            $local_dest = getcwd() . "/" . $file2update;
            $remote_source = GITURL . $file2update;
            $remote_content = file_get_contents($remote_source);
            verify_path($file2update);
            file_put_contents($local_dest, $remote_content);
            if (file_exists($local_dest)) {
                echo "🟢 " . $file2update . " updated\n";
            } else {
                echo "🔴 " . $file2update . " not updated\n";
            }
        }
        file_put_contents(getcwd() . '/manifest.json', json_encode(remote_manifest, JSON_PRETTY_PRINT));
        echo "🟢 Microservice updated to version " . remote_version . "\n";
    } else {
        echo "🟢 The Microservice is up to date\n";
    }
} else {
    die("\n\n🔴 The manifest.json file is missing\n");
}
function verify_path($thifile)
{
    $file_arr = explode("/", $thifile);
    if ($file_arr > 1) {
        $ffpath = "";
        for ($i = 0; $i < sizeof($file_arr); $i++) {
            $ffpath .= "/" . $file_arr[$i];
            if (!str_contains($ffpath, ".")) {
                $path2check = str_replace("//", "/", getcwd() . $ffpath);
                if (!file_exists($path2check)) {
                    echo "Directory missing: " . "$path2check\n";
                    mkdir($path2check);
                }
            }
        }
    }
}
