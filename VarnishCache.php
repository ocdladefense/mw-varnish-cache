<?php
// http://svn.wikimedia.org/doc/
// http://blog.redwerks.org/2012/02/08/mediawiki-skinning-tutorial/
// https://libraryofdefense.ocdla.org/MediaWiki:Loginreqpagetext
// http://www.mediawiki.org/wiki/Manual:$wgAuth
// http://www.mediawiki.org/wiki/Manual:$wgHooks
// http://www.mediawiki.org/wiki/Manual:Hooks
// http://www.mediawiki.org/wiki/Thread:Project:Support_desk/Editing_%22Login_Required%22_Page.
// http://www.mediawiki.org/wiki/Manual:OutputPage.php
// http://www.mediawiki.org/wiki/Manual:System_message
// http://translatewiki.net/w/i.php?title=Special%3ATranslate&task=reviewall&group=core&language=qqq&limit=5000
// http://www.mediawiki.org/wiki/Category:Interface_messages
// http://svn.wikimedia.org/doc/classWebRequest.html#af2567c4449a340ed6dc1ef30c170c439
// http://meta.wikimedia.org/wiki/Help:Template
/**
 * Setup function--call this from LocalSettings.php
 * @author - Jose Bernal
 * @description - Ban a $title from the Varnish cache when the Article is saved
 * This ensures the most recent edit of this Article is always shown by first
 * banning it from the Varnish cache.
 */
function SetupVarnishCache() {
	global $wgHooks, $wgAuth, $wgUser;
	
	// http://www.mediawiki.org/wiki/Manual:Hooks/PageContentSave
	global $mwVersion;
	
	if( $mwVersion >= '1.21.0' ) {
		$wgHooks['PageContentSave'][] = 'VarnishCacheHooks::onPageContentSave';	
	} else {
		$wgHooks['ArticleSave'][] = 'VarnishCacheHooks::onArticleSave';
	}	
	
	// http://www.mediawiki.org/wiki/Manual:Hooks/ArticleDeleteComplete
	$wgHooks['ArticleDeleteComplete'][] = 'VarnishCacheHooks::onArticleDeleteComplete';
}


class VarnishCacheHooks {

	static $VARNISH_CACHE_ADDITIONAL_BANS = array(
		'^/Welcome_to_The_Library',
		'^/Blog:Main$',
		'^/Blog:Main',
		'^/Blog:Case_Reviews$',
		'^/Blog:Case_Reviews',
		'title=Blog:Main',
		'title=Blog:Case_Reviews',
	);

	public static function onArticleDeleteComplete( &$article, User &$user, $reason, $id, $content, $logEntry ) {
		self::clearTitleVariants($article->getTitle());
		return true;
	}
	
	// As of MediaWiki version 1.21.0 this function should be used:
	// public static function onPageContentSave( $wikiPage, $user, $content, $summary, $isMinor, $isWatch, $section, $flags, $status )
	
	// Before MediaWiki version 1.21.0 this function should be used:
	public static function onArticleSave( &$article, &$user, &$text, &$summary,
	$minor, $watchthis, $sectionanchor, &$flags, &$status ) {
		self::clearTitleVariants($article->getTitle());
		return true;
	}
	
	private static function clearTitleVariants($title) {
		if( !isset($title) ) return true;
		
		ini_set('display_errors','1');
		error_reporting(E_ALL);
		try {
			$varnishCache = new VarnishCache();
		}  catch (VarnishException $e) {
			print $e->getMessage();
			// throw new Exception($e->getMessage());
		}
		
		
		$vStatuses = array();


		// We store the title as the first possible URI that needs to be banned
		$candidates = array($title);
		
		// For now we deliberately drop double-quotes from any possible ban candidates.
		// Varnish has a difficult time discerning double-quotes within string
		// expressions.  Our strategy is to split the string by using the double-quotes
		// as a delimiter and then banning objects from the cache based on the resultings strings.
		// So we may ban more than our one desired candidate, but at least we won't have errors :)
		$candidates = VarnishCache::splitStringsByChar($candidates,'"');

		$candidates = array_merge( $candidates, self::$VARNISH_CACHE_ADDITIONAL_BANS);
		
		// Replace <spaces> with <underscores> as in real MediaWiki titles.
		array_walk($candidates, array('VarnishCache','mediaWikiUrlSpaceFormat'));
		
		// Prepare several special PCRE characters with a backslash literal.
		array_walk($candidates, array('VarnishCache','varnishRegexPrepare'));
		// print_r($candidates);exit;

		foreach($candidates as $ban){
			$vStatuses[$ban] = $varnishCache->ban('req.url ~ "'.$ban .'"');
		}

		foreach($vStatuses as $b=>$vStatus) {
			if (VARNISH_STATUS_OK != $vStatus) {
				if($wgUseVarnishExceptions) throw new VarnishException("Ban method returned $vStatus status\nRe is: {$b}");
			}
		}

		return true;
	}

}


class VarnishCache {
	public $va;
	
	
	static $VARNISH_REGEX_PREPARE_STRINGS = array(
		'.',
		':',
		'/',
		"'",
		"’",
		'=', 
	);
	
	public function __construct() {
		global $wgVarnishHost;
// print $wgVarnishHost;exit;
		$args = array(
				VARNISH_CONFIG_HOST    => $wgVarnishHost,
				VARNISH_CONFIG_PORT    => 6082,
				VARNISH_CONFIG_SECRET  => "91116794-c09b-44f9-9b77-724a5b398a2c",
				VARNISH_CONFIG_TIMEOUT => 300,
		);


		$this->va = new VarnishAdmin($args);

		if(!$this->va->connect()) {
			throw new VarnishException("Connection failed\n");
		}   

		if(!$this->va->auth()) {
			throw new VarnishException("Auth failed\n");
		}   
	}
	
	public function ban($str) {
		return $this->va->ban($str);
	}


	

	// return an array
	// the function has a name and takes named parameters
	// it has a return value
	// This function must always return an array
	// Even if the only member of the array is the original string
	public static function splitStringsByChar(Array $strings, $char='"') {
		if(!isset($split)) {
			static $split = array();
		}
		foreach($strings as $str) {
			// Create a case if $str is an array
			if( is_array($str) ) {
				self::splitStringsByChar($str,$char);
			}
			// This is a "clean" string so add it to $split
			else if( !is_array($str) && strpos($str, $char) === false ) {
				$split []= $str;
			} 
			// We found a messy character so split $str by that character and process
			// it recursively
			else {
				$tmp = explode($char,$str);
				foreach( $tmp as $key=>&$part ) {
					// remove empty strings
					if(!strlen($part)) unset($tmp[$key]);
					self::splitStringsByChar(array($part),$char);
				}
			}
		}
		return $split;
	}
	

	
	public static function mediaWikiUrlSpaceFormat(&$str, $key, $char='_') {
		if( strlen($str)===0 || strpos($str, ' ') === false ) return;
		$str = str_replace(' ','_',$str);
	}

	public static function varnishRegexPrepare(&$str) {
		foreach(self::$VARNISH_REGEX_PREPARE_STRINGS as $find) {
			$str = str_replace($find, '\\\\'.$find, $str);
		}
	}

}