<?php
/*
  nws-load-opensearch : load an opensearch form

  This script is part of NWS
  https://github.com/xaccrocheur/nws/

*/
ini_set('display_errors', 'Off');
include('nws-favicon.php');

function get_opensearch_content($url) {
    $html_page = file_get_contents($url);
    if ($html_page === false) {
        echo 'Load error : '.$url.'<br />';
        return false;
    }
    
    $html_doc = new DOMDocument();
    $html_doc->strictErrorChecking = FALSE;
    @$html_doc->loadHTML($html_page);
    $html_links = $html_doc->getElementsByTagName('link');
    $link_opensearch = false;
    foreach ($html_links as $link) {
        $link_rel  = $link->getAttribute('rel');
        $link_type = $link->getAttribute('type');
        if (($link_rel == 'search') && ($link_type == 'application/opensearchdescription+xml')) {
            $link_opensearch = $link->getAttribute('href');
        }
    }
    if ($link_opensearch === false) {
        echo "Can't find any link to OpenSearch descriptor<br />\n";
        return false;
    }
    // check if link is absolute or relative
    if (!((substr($link_opensearch, 0, strlen('http://')) == 'http://') || (substr($link_opensearch, 0, strlen('https://')) == 'https://'))) {
        $url_parsed = parse_url($url);
        if (isset($url_parsed['scheme']))
            $link_opensearch = $url_parsed['scheme'].'://'.$url_parsed['host'].$link_opensearch;
        else
            $link_opensearch = 'http://'.$url_parsed['host'].$link_opensearch;
    }
    $xml_opensearch = file_get_contents($link_opensearch);
    
    if ($xml_opensearch === false) {
        echo 'Not OpenSearch compliant <br />';
        return false;
    }
    $opensearch_desc = simplexml_load_string($xml_opensearch);
    if ($opensearch_desc === false) {
        echo "Can't load XML<br />\n";
        return false;
    }
    $result['ShortName']  = $opensearch_desc->ShortName;  // always there
    $result['Description']= $opensearch_desc->Description;// always there
    foreach ($opensearch_desc->Url as $u) { // must appear one or more time
        $u_attr = $u->attributes();
        $type = $u_attr['type'];   // always there
        if ($type == 'text/html') {
            $result['url_html'] = $u_attr['template'];
        }
    }
    if (isset($opensearch_desc->Image))     // sometime there
        $result['favicon'] = $opensearch_desc->Image;
    if (isset($result['url_html'])) {
        $u = parse_url($result['url_html']);
        $prefix = '';
        $query_name = '';
        if (isset($u['scheme'])) $prefix = $u['scheme'].'://';
        if (isset($u['user'])) $prefix = $prefix.$u['user'];
        if (isset($u['pass'])) $prefix = $prefix.':'.$u['pass'];
        if (isset($u['user'])) $prefix = $prefix.'@';
        if (isset($u['host'])) $prefix = $prefix.$u['host'];
        if (isset($u['port'])) $prefix = $prefix.':'.$u['port'];
        if (isset($u['path'])) $prefix = $prefix.$u['path'];
        if (isset($u['query'])) {
            $queries = explode('&', $u['query']);
            foreach($queries as $query) {
                $query_parts = explode('=',$query);
                if ($query_parts[1] == '{searchTerms}') {
                    $query_name = $query_parts[0];
                } else {
                    $query_hidden[$query_parts[0]] = $query_parts[1];
                }
            }
        }
        $result['url_prefix'] = $prefix;
        $result['url_qname'] = $query_name;
        if (isset($query_hidden)) $result['url_qhidden'] = $query_hidden;
    }
    return $result;
}

function gen_opensearch_div($url) {
    $search_param = get_opensearch_content($url);
    if ($search_param === false) return false;
    
    if (!isset($search_param['url_html'])) return false;
    
    if (!isset($search_param['favicon'])) {
        $u = parse_url($search_param['url_html']);
        $search_param['favicon'] = get_favicon($u['host']); // not using cached version since we wil cache the result
    }
    $form_hidden_fields = '';
    if (isset($search_param['url_qhidden'])) {
        //$form_hidden_fields = '<!-- '.var_export($search_param['url_hidden'],true).' -->';
        foreach ($search_param['url_qhidden'] as $query_hidden_name => $query_hidden_value)
        {
            $form_hidden_fields = $form_hidden_fields.'<input type ="hidden" name="'.$query_hidden_name.'" value="'.$query_hidden_value.'">';
        }
    }
    $div_content = '
<div class="search" title ="'.$url.'">
    <div class="feedTitle">
        <span class="favicon">
            <a href="'.$url.'"><img src="'.$search_param['favicon'].'" /></a>&nbsp;<a href="'.$url.'" title="'.$search_param['Description'].'">'.$search_param['ShortName'].'</a>
        </span>
    </div>
    <form action="'.$search_param['url_prefix'].'" method="get">
        '.$form_hidden_fields.'<input type="text" name="'.$search_param['url_qname'].'" class="search"> <input type="submit" value="Search!">
    </form>
</div>
';
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
        $age = 0;
        $div_content = gen_opensearch_div($url);
        if ($div_content === false) die("Load error");
        return array($div_content, $age);
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
        $age = 0;
        if ($div_content !== false) {
            $fh = fopen($cache_file, 'w');
            if ($fh !== false) {
                fwrite($fh, $div_content);
                fclose($fh);
            }
        }
    }
    
    if ($div_content === false) {
        // can't get data from remote site
        // fallback to previous version if it exists
        if (file_exists($cache_file)) {
            // ok, we have an (old) version in cache
            $age = time() - filemtime($cache_file);
            $div_content = file_get_contents($cache_file) or die("Load / read error (cache)");
        } else {
            die("Load / read error");
        }
        
    }
    return array($div_content, $age);
}

if (isset($_GET['age']))
    $max_age = (int) $_GET['age'];
else
    $max_age = 30*24*3600; // 30 days since it is not supposed to change

list($content, $content_age) = get_opensearch_div($_GET['z'], $max_age);
echo $content;

?>
