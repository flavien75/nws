<?php
/*
  nws-load-opensearch : load an opensearch form

  This script is part of NWS
  https://github.com/xaccrocheur/nws/

*/
ini_set('display_errors', 'Off');
include('nws-favicon.php');

function gen_opensearch_div($url) {
    // !!! TEMPORARY CODE !!!
    $favicon = get_favicon($url); // not using cached version since we wil cache the result
    $div_content = '
<div class="search" title ="'.$url.'">
    <div class="feedTitle">
        <span class="favicon">
            <a href="'.$url.'"><img src="'.$favicon.'" /></a>&nbsp;<a href="'.$url.'" title="'.$url.'">'.$url.'</a>
        </span>
    </div>
    <form action="http://fr.wikipedia.org/w/index.php?title=Sp%C3%A9cial:Recherche" method="get">
        <input type="text" name="search"> <input type="submit" value="Search!">
    </form>
</div>
';
    // end of temporary code
    return $div_content;
}

function get_opensearch_div($url, $max_age) {
    $opensearch_cache_dir = "cache/opensearch/";
    $cache_ok = false;
    
    // check if cache directory exists
    if (file_exists($opensearch_cache_dir)) {
        $cache_ok = true;
    } else {
        $cache_ok = @mkdir($opensearch_cache_dir);
    }
    
    if (!$cache_ok) { // directory missing and unable to create it => abort cache feature
        return gen_opensearch_div($url);
    }

    $u = parse_url($url);
    $cache_file = $u['host']; // http://www.example.com/test_dir/test_page.html => www.example.com
    // no need to reencode the cache file, there's no forbidden char in the domain name
    $cache_file = $opensearch_cache_dir.$cache_file;
    
    $div_content = false;
    if (file_exists($cache_file)) {
        $age = time() - filemtime($cache_file);

        if ($age < $max_age)
            $div_content = file_get_contents($cache_file);
    }

    if ($div_content === false) { // either unreadable cache file, or cache file is too old
        $div_content = gen_opensearch_div($url);
        $fh = fopen($cache_file, 'w');
        if ($fh !== false) {
            fwrite($fh, $div_content);
            fclose($fh);
        }
    }
    return $div_content;
}

if (isset($_GET['age']))
    $max_age = (int) $_GET['age'];
else
    $max_age = 30*24*3600; // 30 days since it is not supposed to change

echo get_opensearch_div($_GET['z'], $max_age);

?>
