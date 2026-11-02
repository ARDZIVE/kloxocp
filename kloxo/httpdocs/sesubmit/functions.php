<?php

function kloxo_ereg_pattern($pattern, $ignore_case = false)
{
	return '#' . str_replace('#', '\\#', (string) $pattern) . '#' . ($ignore_case ? 'i' : '');
}

function kloxo_ereg($pattern, $string)
{
	return @preg_match(kloxo_ereg_pattern($pattern), (string) $string) === 1;
}

function kloxo_eregi($pattern, $string)
{
	return @preg_match(kloxo_ereg_pattern($pattern, true), (string) $string) === 1;
}

function kloxo_ereg_replace($pattern, $replacement, $string)
{
	$out = @preg_replace(kloxo_ereg_pattern($pattern), $replacement, (string) $string);

	return is_string($out) ? $out : (string) $string;
}

function kloxo_eregi_replace($pattern, $replacement, $string)
{
	$out = @preg_replace(kloxo_ereg_pattern($pattern, true), $replacement, (string) $string);

	return is_string($out) ? $out : (string) $string;
}

function my_in_array($s, $a)
{
	for($t=0; $t<sizeof($a); $t++)
		if(!strcasecmp($s,$a[$t]))
			return true;

	return false;
}

function apply_correction($link, $path, $domain)
{
	$link = trim($link);

	if(kloxo_eregi("^(https://)",$link))
		return false;

	$path = kloxo_eregi_replace("/$","",$path);

	if(kloxo_eregi("^(http://)",$link)){
		if(!kloxo_ereg("(.*)($domain)(.*)",$link))
			return false;

		return kloxo_eregi_replace("(http://)(www\.)?($domain)","",$link);
	}
	else{
		if(!kloxo_ereg("(^(\.{2}))|(^/)",$link)){
			return $path."/".$link;
		}

		if(kloxo_ereg("^/",$link)){
			return $link;
		}

// time to assume that it begins with ../ or ..
		$link = kloxo_ereg_replace("^(\.\.)(/?)","",$link);
			$path = substr($path,0,strrpos($path,"/"));

			return apply_correction($link,$path,$domain);
	}

	return $link;
}

function get_links($page, $domain, &$links)
{
	global $ignoredir;
	global $getext;
	global $offset;
	global $max_results;

	if(sizeof($links) >= ($offset+$max_results) && $max_results)
		return true;

	$href_pos = -5;

	$file = strrchr($page,"/");
	if(!strlen($file)){
		$page__ = find_page("http://".$domain.$page);
	        if($page__ == false)
	                return;
		$path = substr($page,-1,1);
		$page = $page.$page__;
	}
	else
		$path = str_replace($file,"",$page);

	$links[] = "http://$domain$path$file";

	if(($page_ = @file("http://".$domain.$path.$file)) == false)
		return;

	$page_ = implode("",$page_);
	$_page = strtolower($page_);

	while(1){
		set_time_limit(1);

		if(sizeof($links) >= ($offset+$max_results) && $max_results)
			return true;

		$href_pos = @strpos($_page,"href=",$href_pos+5);

		if(@is_string($href_pos) && !$href_pos)
			return;

		$nc = substr($page_,$href_pos+5,1);

		if($nc == '"')
			$np = @strpos($page_,'"',$href_pos+6);
		else{
			if($nc == "'")
				$np = @strpos($page_,"'",$href_pos+6);
			else{
				$np = @strpos($page_," ",$href_pos+5);
				$nd = @strpos($page_,">",$href_pos+5);
				if($np > $nd)
					$np = $nd;
			}
		}

		if(@is_string($np) && !$np)
			continue;

		if($nc == "'" || $nc == '"')
			$link = @str_replace("'"," ",@str_replace('"'," ",@substr($page_,$href_pos+6,$np-$href_pos-6)));
		else
			$link = @str_replace("'"," ",@str_replace('"'," ",@substr($page_,$href_pos+5,$np-$href_pos-5)));

		if(kloxo_ereg("\?+",$link))
			$link = @substr($link,0,strpos($link,"?"));

		if(kloxo_ereg("^((javascript:)|(ftp://)|(mailto:))",$link))
			continue;

		if(($link_ = @stristr($link,$domain)) == false)
			if(($link_ = apply_correction($link, $path, $domain)) == false)
				continue;

		$link_ = kloxo_eregi_replace("^(www\.)?($domain)","",$link_);

		if(@my_in_array("http://$domain$link_",$links) == true)
			continue;

		if(kloxo_ereg("((\.)($getext))$",$link_) && !kloxo_ereg("(/)($ignoredir)(/)",$link_))
			get_links($link_,$domain,$links);
	}
}

// no index page was found
function no_index()
{
	Header("Location: noindex.html");
	exit();
}

function find_page($location)
{
        $pages = array(
                "index.html",
                "index.htm",
                "index.shtml",
                "index.cgi",
                "index.php3",
                "index.php",
                "index.pl",
                "index.asp",
                "index.dhtml"
        );

        for($_=0; $_<sizeof($pages); $_++){
                if($t_ = @fopen("http://".$domain."/".$pages[$_],"r")){
                        $page = $pages[$_];
                        break;
                }
        }

	@fclose($t_);

	if(!isset($page))
		return false;

	return $page;
}

function get_index($domain)
{
	$pages = array(
		"index.html",
		"index.htm",
		"index.shtml",
		"index.cgi",
		"index.php3",
		"index.php",
		"index.pl",
		"index.asp",
		"index.dhtml",
		"default.htm"
	);

	for($_=0; $_<sizeof($pages); $_++){
		if($t_ = @fopen("http://".$domain."/".$pages[$_],"r")){
			$page = $pages[$_];
			break;
		}
	}

	@fclose($t_);

	if(!isset($page))
		no_index();

	return "/".$page;
}

?>
