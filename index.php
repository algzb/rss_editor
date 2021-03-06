<?php

define('DEBUG', false);
define('MIN_DOWNLOAD_DELAY', 0.12);
define('MAX_DOWNLOAD_DELAY', 0.94);
define('USE_WEBPAGE_CACHING', true);

libxml_use_internal_errors(true);

function allElementsAreArrays($array) {
    foreach ($array as $val) {
        if (!is_array($val)) {
            return false;
        }
    }
    return true;
}

function wrapInArray($arg) {
    if (!is_array($arg)) {
        return array($arg);
    } else {
        return $arg;
    }
}

function map($traversable, $fn)
{
    $result = array();
    foreach ($traversable as $element) {
        $result[] = $fn($element);
    }

    return $result;
}

function chainArrays($array) {
    $result = array();
    foreach ($array as $val) {
        if (is_array($val)) {
            $result = array_merge($result, chainArrays($val));
        } else {
            array_push($result, $val);
        }
    }
    return $result;
}

function display_xml_error($error) {
    $return = "";
    switch ($error->level) {
        case LIBXML_ERR_WARNING:
            $return .= "Warning $error->code: ";
            break;
        case LIBXML_ERR_ERROR:
            $return .= "Error $error->code: ";
            break;
        case LIBXML_ERR_FATAL:
            $return .= "Fatal Error $error->code: ";
            break;
    }
    $return .= trim($error->message);
    return $return;
}

class TagNotFoundException extends Exception {
    public function __construct($tag_name)
    {
        parent::__construct("No tags with name " . $tag_name, 400);
    }
}


class RSSEditor
{
    /**
     * @var string|null Plain source code of document
     */
    protected $plainText = null;
    /**
     * @var DOMDocument XML document as valid DOMDocument object
     */
    protected $xml;

    /**
     * Check pair matching of two objects.
     *
     * @param $firstObject mixed
     * @param $secondObject mixed
     *
     * @throws InvalidArgumentException   Only one of two arguments is array or arrays do not have same length
     */
    protected function checkPairArgumentsMatching($firstObject, $secondObject) {
        if (is_array($firstObject)) {
            if (!is_array($secondObject) || count($firstObject) !== count($secondObject)) {
                throw new InvalidArgumentException("both arrays must have equal lengths");
            }
        } else {
            if (is_array($secondObject)) {
                throw new InvalidArgumentException("both objects must be arrays or not at the same time");
            }
        }
    }

    /**
     * Apply action with passing two arguments.
     * If arguments are arrays with same length then apply action for each pair of these element by index.
     * Otherwise apply only single time with arguments.
     *
     * @param $action callable
     * @param $arguments mixed   Single argument or array of arguments for $action
     */
    protected function applyAction($action, $arguments) {
        if (is_array($arguments)) {
            if (allElementsAreArrays($arguments)) {
                foreach ($arguments as $args) {
                    call_user_func_array($action, $args);
                }
            } else {
                foreach ($arguments as $arg) {
                    call_user_func($action, $arg);
                }
            }
        } else {
            call_user_func($action, $arguments);
        }
    }

    /**
     * Rename all tags with given name (without attributes and children)
     *
     * @param $oldTagName string Current name of the tags
     * @param $newTagName string New name for the tags
     *
     * @throws TagNotFoundException   if no tags with $oldTagName are found
     */
    protected function renameUnifiedTags($oldTagName, $newTagName) {
        $nodes = iterator_to_array($this->xml->getElementsByTagName($oldTagName));

        if (count($nodes) == 0) {
            throw new TagNotFoundException($oldTagName);
        };
        foreach ($nodes as $node) {
            $newNode = $this->xml->createElement($newTagName);
            $newNode->nodeValue = htmlspecialchars($node->nodeValue);
            $node->parentNode->appendChild($newNode);
            $node->parentNode->removeChild($node);
        };
    }

    /**
     * Remove all tags with given name (without children)
     *
     * @param $tagName string|string[] Name of the tag
     *
     * @throws TagNotFoundException   if no tags with name $tagName are found
     */
    protected function removeUnifiedTags($tagName) {
        $nodes = iterator_to_array($this->xml->getElementsByTagName($tagName));
        if (count($nodes) == 0) {
            throw new TagNotFoundException($tagName);
        };
        foreach ($nodes as $node) {
            $node->parentNode->removeChild($node);
        }
    }

    /**
     * Split content of the tags with given name using <br> CDATA block (without attributes)
     *
     * @param $tagName string Name of the old tag
     *
     * @throws TagNotFoundException   if no tags with name $tagName are found
     */
    protected function insertBreaksIntoUnifiedTags($tagName) {
        $nodes = $this->xml->getElementsByTagName($tagName);
        if ($nodes->length == 0) {
            throw new TagNotFoundException($tagName);
        }
        foreach ($nodes as $node) {
            $result = preg_replace('/([\r\n]|&#x[Dd];|&#13;)+(\s+([\r\n]|&#x[Dd];|&#13;)+)*/u', '<br/>', $node->nodeValue);
            if ($node->childNodes->item(0)->nodeType === XML_CDATA_SECTION_NODE) {
                $node->replaceChild($this->xml->createCDATASection($result), $node->childNodes->item(0));
            } else {
                $node->nodeValue = htmlspecialchars($result);
            }
        }
    }


    /**
     * Mark content of the tags with given name as CDATA block (without attributes)
     *
     * @param $tagName string Name of the old tag
     *
     * @throws TagNotFoundException   if no tags with name $tagName are found
     */
    protected function cdataUnifiedTags($tagName) {
        $nodes = $this->xml->getElementsByTagName($tagName);
        if ($nodes->length == 0) {
            throw new TagNotFoundException($tagName);
        }
        foreach ($nodes as $node) {
            $CDATASection = $this->xml->createCDATASection($node->nodeValue);
            $node->nodeValue = '';
            $node->appendChild($CDATASection);
        }
    }


    /**
     * Replace each match of given regular expression using given replace rule (regular expression in PCRE notation).
     * Modifier 'u' is used for documents processing with UTF-8 encoding.
     * 
     * @param $replaceFrom string    Regex to find matches.
     * @param $replaceTo string    Regex to replace found matches.
     * @param $tagName string    Node name which regex is applied in.
     * @param $isCaseSensitive string    If false then modifier 'i' is applied (searching without case sensitive) else case sensitive searching.
     *
     * @throws TagNotFoundException   if no tags with name $tagName are found
     */
    protected function replaceContentInHomonymousTags($replaceFrom, $replaceTo, $tagName, $isCaseSensitive) {
        $nodes = $this->xml->getElementsByTagName($tagName);
        if ($nodes->length == 0) {
            throw new TagNotFoundException($tagName);
        }
        $replaceFrom = str_replace('/', '\/', $replaceFrom);

        foreach ($nodes as $node) {
            if ($node->childNodes->length <= 2) {
                foreach($node->childNodes as $childNode) {
                    if ($childNode->nodeType === XML_TEXT_NODE || $childNode->nodeType === XML_CDATA_SECTION_NODE) {
                        $result = preg_replace($isCaseSensitive ? "/$replaceFrom/u" : "/$replaceFrom/iu",
                                         $replaceTo, $childNode->nodeValue);
                        if ($childNode->nodeType === XML_TEXT_NODE) {
                            $childNode->nodeValue = htmlspecialchars($result);
                        } else {
                            $childNode->nodeValue = '';
                            $childNode->appendChild($this->xml->createCDATASection($result));
                        }
                    }
                }
            }
        }
    }

    /**
     * @param $url string
     * @param $context resource Stream context
     * @param $replaceAmpToSymbol boolean replace each '&amp;' to '&' symbol
     * @param $htmlEntitiesToNumeric boolean replace all HTML entities to its numeric representation
     * @param $addNamespace string  add additional RSS-namespace(s)
     * @param $need_webpage_caching boolean  whether the necessity to cache downloaded web-pages
     *
     * @throws Exception   if cannot download RSS feed or cannot generate valid XML from RSS feed
     */
    public function __construct($url, $context, $replaceAmpToSymbol,
                                $htmlEntitiesToNumeric, $addNamespace,
                                $need_webpage_caching = USE_WEBPAGE_CACHING) {
        $this->plainText = @file_get_contents($url, FILE_TEXT, $context);
        if (defined('DEBUG') && DEBUG) {
            file_put_contents('last_raw_rss.xml', join(PHP_EOL, $http_response_header) . PHP_EOL . $this->plainText);
        }
        if (!isset($http_response_header) || !preg_match('@HTTP/\d+.\d+ 200 OK@', $http_response_header[0])) {
            if (isset($http_response_header)) {
                $status = ": {$http_response_header[0]}";
            } else {
                $status = "";
            }
            throw new Exception("Cannot download RSS feed from URL '{$url}'{$status}", 500);
        }
        if (!$this->plainText) {
            throw new Exception("Cannot download RSS feed from URL '{$url}'", 500);
        }
        if ($replaceAmpToSymbol) {
            $this->plainText = preg_replace('/&amp;(?=[a-z]{2})/', "&", $this->plainText, FILE_TEXT);
        }

        // replace  & into code if it is not encoded symbol
        $this->plainText = preg_replace("/&(?!([a-z]+|#[0-9]+);)/", "&#38;", $this->plainText);

        if ($htmlEntitiesToNumeric) {
            $this->htmlEntitiesToNumeric();
        }
        if (!is_null($addNamespace)) {
            $this->plainText = str_replace('<rss', '<rss ' . $addNamespace, $this->plainText);
        }

        if (defined('DEBUG') && DEBUG) {
            file_put_contents('last_preprocessed_rss.xml', join(PHP_EOL, $http_response_header) . PHP_EOL . $this->plainText);
        }
        $this->xml = new DOMDocument();
        if (!$this->xml->loadXML($this->plainText)) {
            $errors = libxml_get_errors();
            throw new Exception("Cannot form valid XML: "
                                . join(PHP_EOL, array_map('display_xml_error', $errors)),
                                500);
        }

        $this->entry_webpages = array();
        $this->use_webpage_caching = $need_webpage_caching;

        header("Content-type: text/xml; charset={$this->xml->encoding}");
    }

    /**
     * Convert HTML entities to its numeric representation for supporting in XML
     */
    protected function htmlEntitiesToNumeric() {
        $htmlEntities = array('&quot;', '&lt;', '&gt;', '&OElig;', '&oelig;', '&Scaron;', '&scaron;', '&Yuml;', '&circ;', '&tilde;', '&ensp;', '&emsp;', '&thinsp;', '&zwnj;', '&zwj;', '&lrm;', '&rlm;', '&ndash;', '&mdash;', '&lsquo;', '&rsquo;', '&sbquo;', '&ldquo;', '&rdquo;', '&bdquo;', '&dagger;', '&Dagger;', '&permil;', '&lsaquo;', '&rsaquo;', '&euro;', '&fnof;', '&Alpha;', '&Beta;', '&Gamma;', '&Delta;', '&Epsilon;', '&Zeta;', '&Eta;', '&Theta;', '&Iota;', '&Kappa;', '&Lambda;', '&Mu;', '&Nu;', '&Xi;', '&Omicron;', '&Pi;', '&Rho;', '&Sigma;', '&Tau;', '&Upsilon;', '&Phi;', '&Chi;', '&Psi;', '&Omega;', '&alpha;', '&beta;', '&gamma;', '&delta;', '&epsilon;', '&zeta;', '&eta;', '&theta;', '&iota;', '&kappa;', '&lambda;', '&mu;', '&nu;', '&xi;', '&omicron;', '&pi;', '&rho;', '&sigmaf;', '&sigma;', '&tau;', '&upsilon;', '&phi;', '&chi;', '&psi;', '&omega;', '&thetasym;', '&upsih;', '&piv;', '&bull;', '&hellip;', '&prime;', '&Prime;', '&oline;', '&frasl;', '&weierp;', '&image;', '&real;', '&trade;', '&alefsym;', '&larr;', '&uarr;', '&rarr;', '&darr;', '&harr;', '&crarr;', '&lArr;', '&uArr;', '&rArr;', '&dArr;', '&hArr;', '&forall;', '&part;', '&exist;', '&empty;', '&nabla;', '&isin;', '&notin;', '&ni;', '&prod;', '&sum;', '&minus;', '&lowast;', '&radic;', '&prop;', '&infin;', '&ang;', '&and;', '&or;', '&cap;', '&cup;', '&int;', '&there4;', '&sim;', '&cong;', '&asymp;', '&ne;', '&equiv;', '&le;', '&ge;', '&sub;', '&sup;', '&nsub;', '&sube;', '&supe;', '&oplus;', '&otimes;', '&perp;', '&sdot;', '&lceil;', '&rceil;', '&lfloor;', '&rfloor;', '&lang;', '&rang;', '&loz;', '&spades;', '&clubs;', '&hearts;', '&diams;', '&nbsp;', '&iexcl;', '&cent;', '&pound;', '&curren;', '&yen;', '&brvbar;', '&sect;', '&uml;', '&copy;', '&ordf;', '&laquo;', '&not;', '&shy;', '&reg;', '&macr;', '&deg;', '&plusmn;', '&sup2;', '&sup3;', '&acute;', '&micro;', '&para;', '&middot;', '&cedil;', '&sup1;', '&ordm;', '&raquo;', '&frac14;', '&frac12;', '&frac34;', '&iquest;', '&Agrave;', '&Aacute;', '&Acirc;', '&Atilde;', '&Auml;', '&Aring;', '&AElig;', '&Ccedil;', '&Egrave;', '&Eacute;', '&Ecirc;', '&Euml;', '&Igrave;', '&Iacute;', '&Icirc;', '&Iuml;', '&ETH;', '&Ntilde;', '&Ograve;', '&Oacute;', '&Ocirc;', '&Otilde;', '&Ouml;', '&times;', '&Oslash;', '&Ugrave;', '&Uacute;', '&Ucirc;', '&Uuml;', '&Yacute;', '&THORN;', '&szlig;', '&agrave;', '&aacute;', '&acirc;', '&atilde;', '&auml;', '&aring;', '&aelig;', '&ccedil;', '&egrave;', '&eacute;', '&ecirc;', '&euml;', '&igrave;', '&iacute;', '&icirc;', '&iuml;', '&eth;', '&ntilde;', '&ograve;', '&oacute;', '&ocirc;', '&otilde;', '&ouml;', '&divide;', '&oslash;', '&ugrave;', '&uacute;', '&ucirc;', '&uuml;', '&yacute;', '&thorn;', '&yuml;');
        $htmlEntitiesCode = array('&#34;', '&#60;', '&#62;', '&#338;', '&#339;', '&#352;', '&#353;', '&#376;', '&#710;', '&#732;', '&#8194;', '&#8195;', '&#8201;', '&#8204;', '&#8205;', '&#8206;', '&#8207;', '&#8211;', '&#8212;', '&#8216;', '&#8217;', '&#8218;', '&#8220;', '&#8221;', '&#8222;', '&#8224;', '&#8225;', '&#8240;', '&#8249;', '&#8250;', '&#8364;', '&#402;', '&#913;', '&#914;', '&#915;', '&#916;', '&#917;', '&#918;', '&#919;', '&#920;', '&#921;', '&#922;', '&#923;', '&#924;', '&#925;', '&#926;', '&#927;', '&#928;', '&#929;', '&#931;', '&#932;', '&#933;', '&#934;', '&#935;', '&#936;', '&#937;', '&#945;', '&#946;', '&#947;', '&#948;', '&#949;', '&#950;', '&#951;', '&#952;', '&#953;', '&#954;', '&#955;', '&#956;', '&#957;', '&#958;', '&#959;', '&#960;', '&#961;', '&#962;', '&#963;', '&#964;', '&#965;', '&#966;', '&#967;', '&#968;', '&#969;', '&#977;', '&#978;', '&#982;', '&#8226;', '&#8230;', '&#8242;', '&#8243;', '&#8254;', '&#8260;', '&#8472;', '&#8465;', '&#8476;', '&#8482;', '&#8501;', '&#8592;', '&#8593;', '&#8594;', '&#8595;', '&#8596;', '&#8629;', '&#8656;', '&#8657;', '&#8658;', '&#8659;', '&#8660;', '&#8704;', '&#8706;', '&#8707;', '&#8709;', '&#8711;', '&#8712;', '&#8713;', '&#8715;', '&#8719;', '&#8721;', '&#8722;', '&#8727;', '&#8730;', '&#8733;', '&#8734;', '&#8736;', '&#8743;', '&#8744;', '&#8745;', '&#8746;', '&#8747;', '&#8756;', '&#8764;', '&#8773;', '&#8776;', '&#8800;', '&#8801;', '&#8804;', '&#8805;', '&#8834;', '&#8835;', '&#8836;', '&#8838;', '&#8839;', '&#8853;', '&#8855;', '&#8869;', '&#8901;', '&#8968;', '&#8969;', '&#8970;', '&#8971;', '&#9001;', '&#9002;', '&#9674;', '&#9824;', '&#9827;', '&#9829;', '&#9830;', '&#160;', '&#161;', '&#162;', '&#163;', '&#164;', '&#165;', '&#166;', '&#167;', '&#168;', '&#169;', '&#170;', '&#171;', '&#172;', '', '&#174;', '&#175;', '&#176;', '&#177;', '&#178;', '&#179;', '&#180;', '&#181;', '&#182;', '&#183;', '&#184;', '&#185;', '&#186;', '&#187;', '&#188;', '&#189;', '&#190;', '&#191;', '&#192;', '&#193;', '&#194;', '&#195;', '&#196;', '&#197;', '&#198;', '&#199;', '&#200;', '&#201;', '&#202;', '&#203;', '&#204;', '&#205;', '&#206;', '&#207;', '&#208;', '&#209;', '&#210;', '&#211;', '&#212;', '&#213;', '&#214;', '&#215;', '&#216;', '&#217;', '&#218;', '&#219;', '&#220;', '&#221;', '&#222;', '&#223;', '&#224;', '&#225;', '&#226;', '&#227;', '&#228;', '&#229;', '&#230;', '&#231;', '&#232;', '&#233;', '&#234;', '&#235;', '&#236;', '&#237;', '&#238;', '&#239;', '&#240;', '&#241;', '&#242;', '&#243;', '&#244;', '&#245;', '&#246;', '&#247;', '&#248;', '&#249;', '&#250;', '&#251;', '&#252;', '&#253;', '&#254;', '&#255;');

        $this->plainText = str_replace($htmlEntities, $htmlEntitiesCode, $this->plainText);
    }


    /**
     * Rename all tags with given name (without attributes and children)
     *
     * @param $oldTagName string|string[] Current name of the tags
     * @param $newTagName string|string[] New name for the tags
     *
     * @throws InvalidArgumentException   if pair(s) of $oldTagName and $newTagName does not match
     */
    public function renameTags($oldTagName, $newTagName) {
        $oldTagName = wrapInArray($oldTagName);
        $newTagName = wrapInArray($newTagName);
        try {
            $this->checkPairArgumentsMatching($oldTagName, $newTagName);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException("Invalid rename_from/rename_to parameters: " . $e->getMessage(), 400);
        }
        $this->applyAction(array($this, 'renameUnifiedTags'),
            array_map(function($ind) use ($oldTagName, $newTagName)
                {return array($oldTagName[$ind], $newTagName[$ind]);},
                range(0, count($oldTagName)-1)));
    }

    /**
     * Remove tags with given name
     *
     * @param $tagName string
     */
    public function removeTags($tagName) {
        $this->applyAction(array($this, 'removeUnifiedTags'), $tagName);
    }

    /**
     * Insert <br/> instead of line breaks in the content of tags with given name
     *
     * @param $tagName string
     */
    public function insertBreaksIntoTags($tagName)
    {
        $this->applyAction(array($this, 'insertBreaksIntoUnifiedTags'), $tagName);
    }

    /**
     * Wrap content of the tags with given name using CDATA
     *
     * @param $tagName string
     */
    public function cdataTags($tagName)
    {
        $this->applyAction(array($this, 'cdataUnifiedTags'), $tagName);
    }

    /**
     * Split the content of the tags with given name into tags with the same name
     * that contains lower cased words from source content that is extracted using CamelCase rules
     *
     * Example:
     *   before
     *     <tag>CamelCaseSTRING</tag>
     *   after
     *     <tag>camel</tag><tag>case</tag><tag>string</tag>
     *
     * @param $tagName string
     *
     * @throws TagNotFoundException   if no tags with name $tagName are found
     */
    public function splitCamelCase($tagName) {
        $nodes = iterator_to_array($this->xml->getElementsByTagName($tagName));
        if (count($nodes) == 0) {
            throw new TagNotFoundException($tagName);
        }
        foreach ($nodes as $node) {
            $matches = array();
            preg_match_all("/((?:^|[A-ZА-Я])[a-zа-я]+|[A-ZА-Я]+(?=[А-Я]|$))/u", $node->nodeValue, $matches);
            foreach ($matches[0] as $match) {
                $newNode = $this->xml->createElement($tagName);
                $newNode->appendChild($this->xml->createCDATASection(mb_strtolower($match)));
                $node->parentNode->appendChild($newNode);
            }
            $node->parentNode->removeChild($node);
        }
    }

    /**
     * Content replacing based on regular expressions in PCRE notation.
     * Replace each match of given regular expression using given replace rule.
     * For documents with UTF-8 encoding.
     *
     * @param $replaceFrom string|string[]    Regex to find matches.
     * @param $replaceTo string|string[]    Regex to replace found matches.
     * @param $replaceInTag string|string[]    Node name which regex is applied in.
     * @param $isCaseSensitive string|string[]    If false then modifier 'i' is applied (searching without case sensitive) else case sensitive searching.
     *
     * @throws InvalidArgumentException   if pair(s) of $oldTagName and $newTagName does not match
     */
    public function replaceContent($replaceFrom, $replaceTo, $replaceInTag, $isCaseSensitive) {
        $replaceFrom = wrapInArray($replaceFrom);
        $replaceTo = wrapInArray($replaceTo);
        try {
            $this->checkPairArgumentsMatching($replaceFrom, $replaceTo);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException("Invalid replace_from/replace_to parameters: " . $e->getMessage(), 400);
        }
        if (is_array($replaceInTag)) {
            try {
                $this->checkPairArgumentsMatching($replaceFrom, $replaceInTag);
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException("Invalid replace_from/replace_to/replace_in parameters: " . $e->getMessage(), 400);
            }
        } else {
            $replaceInTag = array_fill(0, count($replaceFrom), $replaceInTag);
        }
        if (is_array($isCaseSensitive)) {
            try {
                $this->checkPairArgumentsMatching($replaceFrom, $isCaseSensitive);
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException("Invalid replace_from/replace_to/replace_sens parameters: " . $e->getMessage(), 400);
            }
        } else {
            $isCaseSensitive = array_fill(0, count($replaceFrom), $isCaseSensitive);
        }
        $this->applyAction(array($this, 'replaceContentInHomonymousTags'),
            array_map(function($ind) use ($replaceFrom, $replaceTo, $replaceInTag, $isCaseSensitive)
            { return array($replaceFrom[$ind], $replaceTo[$ind], $replaceInTag[$ind], $isCaseSensitive[$ind]);},
                range(0, count($replaceFrom)-1)));
    }

    public function filter($include=array(), $exclude=array()) {
        $include = wrapInArray($include);
        $exclude = wrapInArray($exclude);
        $items = iterator_to_array($this->xml->getElementsByTagName("item"));
        foreach ($items as $feed_item) {
            $title = $feed_item->getElementsByTagName('title');
            $title = ($title->length > 0) ? $title->item(0)->nodeValue : '';
            $description = $feed_item->getElementsByTagName('description');
            $description = ($description->length > 0) ? $description->item(0)->nodeValue : '';
            $categories = $feed_item->getElementsByTagName('category');
            if ($categories->length > 0) {
                $categories = join(' ', array_map(function ($cat) {return $cat->nodeValue;},
                                                  iterator_to_array($categories)));
            } else {
                $categories = '';
            }
            $leave = true;
            if ($include) {
                $leave = false;
                foreach ($include as $user_regex) {
                    $regex = '/' . preg_replace("/(?<!\\\)\//u", "\\/", $user_regex) . '/iu';
                    $title_matched = preg_match($regex, $title);
                    $description_matched = preg_match($regex, $description);
                    $categories_matched = preg_match($regex, $categories);
                    if ($title_matched === false || $description_matched === false || $categories_matched === false) {
                        throw new InvalidArgumentException("Invalid regular expression: {$user_regex}");
                    }
                    if ($title_matched || $description_matched || $categories_matched) {
                        $leave = true;
                        break;
                    }
                }
            }
            if ($exclude && $leave) {
                foreach ($exclude as $user_regex) {
                    $regex = '/' . preg_replace("/(?<!\\\)\//u", "\\/", $user_regex) . '/iu';
                    $title_matched = preg_match($regex, $title);
                    $description_matched = preg_match($regex, $description);
                    $categories_matched = preg_match($regex, $categories);
                    if ($title_matched === false || $description_matched === false || $categories_matched === false) {
                        throw new InvalidArgumentException("Invalid regular expression: {$user_regex}");
                    }
                    if ($title_matched || $description_matched || $categories_matched) {
                        $leave = false;
                        break;
                    }
                }
            }

            if (!$leave) {
                $feed_item->parentNode->removeChild($feed_item);
            }
        }
    }

    /**
     * Add categories to each feed item from its web-page ('link' element) using XPath expression
     * that extract list of category names from web-page.
     * If feed item does not have link then this item is ignored.
     *
     * @param $feedItem DOMNode   Feed item node
     * @param $xpath string   XPath 1.0 expression that extracts category names
     * @return DOMNodeList   Extracted data using XPath expression
     *
     * @throws TagNotFoundException   if feed item does not contain 'link' element
     * @throws InvalidArgumentException   if invalid XPath 1.0 expression is passed
     */
    protected function xpathQuery($feedItem, $xpath) {
        $entry_link = $feedItem->getElementsByTagName("link");
        if ($entry_link->length == 0) {
            throw new TagNotFoundException("link");
        }
        $entry_link = $entry_link->item(0)->nodeValue;
        if ($this->use_webpage_caching && isset($this->entry_webpages[$entry_link])) {
            $entry_doc = $this->entry_webpages[$entry_link];
        } else {
            $entry_page = new DOMDocument();
            @$entry_page->loadHTMLFile($entry_link);
            $entry_doc = new DOMXPath($entry_page);
            if ($this->use_webpage_caching) {
                $this->entry_webpages[$entry_link] = $entry_doc;
            }
        }
        $result = @($entry_doc->query($xpath));
        if ($result === false) {
            throw new InvalidArgumentException("Invalid XPath expression < {$xpath} >: " . error_get_last()['message']);
        }
        return $result;
    }

    /**
     * Add categories to each feed item from its web-page ('link' element) using XPath expression
     * that extract list of category names from web-page.
     * If some feed item does not have link then this item is ignored.
     *
     * @param $xpath string   XPath 1.0 expression that extracts category names
     */
    public function addCategory($xpath) {
        foreach ($this->xml->getElementsByTagName("item") as $feed_item) {
            try {
                $categories = $this->xpathQuery($feed_item, $xpath);
                sleep(mt_rand() / mt_getrandmax() * (MAX_DOWNLOAD_DELAY - MIN_DOWNLOAD_DELAY) + MIN_DOWNLOAD_DELAY);
            } catch (TagNotFoundException $exc) {
                continue;
            }
            $entry_categories = map($feed_item->getElementsByTagName("category"),
                function($cat) {return trim($cat->nodeValue);});
            foreach ($categories as $category) {
                $category_name = trim($category->nodeValue, " \t\n\r\0\x0B,;. ");
                if (!in_array($category_name, $entry_categories)) {
                    $category_element = $feed_item->appendChild($this->xml->createElement("category"));
                    $category_element->appendChild($this->xml->createCDATASection($category_name));
                }
            }
        }
    }

    /**
     * Replace description of each feed item with its web-page content (with URL from 'link' element)
     * that extracted using XPath expression.
     * If some feed item does not have link then this item is ignored.
     *
     * @param $xpath string   XPath 1.0 expression that extracts category names
     */
    public function replaceDescription($xpath) {
        foreach ($this->xml->getElementsByTagName("item") as $feed_item) {
            try {
                $paragraphs = $this->xpathQuery($feed_item, $xpath);
                sleep(mt_rand() / mt_getrandmax() * (MAX_DOWNLOAD_DELAY - MIN_DOWNLOAD_DELAY) + MIN_DOWNLOAD_DELAY);
            } catch (TagNotFoundException $exc) {
                continue;
            }
            $description = implode(PHP_EOL, map($paragraphs, function ($par) {return trim($par->C14N());}));

            $description_elements = $feed_item->getElementsByTagName("description");
            foreach ($description_elements as $description_element) {
                $feed_item->removeChild($description_element);
            }
            $description_element = $feed_item->appendChild($this->xml->createElement("description"));
            $description_element->appendChild($this->xml->createCDATASection($description));
        }
    }


    /**
     * Extend description of each feed item with its web-page content (with URL from 'link' element)
     * that extracted using XPath expression.
     * If some feed item does not have link then this item is ignored.
     *
     * @param $xpath string   XPath 1.0 expression that extracts category names
     */
    public function extendDescription($xpath) {
        foreach ($this->xml->getElementsByTagName("item") as $feed_item) {
            try {
                $paragraphs = $this->xpathQuery($feed_item, $xpath);
            } catch (TagNotFoundException $exc) {
                continue;
            }
            $description = implode(PHP_EOL, map($paragraphs, function ($par) {return trim($par->C14N());}));

            $description_elements = $feed_item->getElementsByTagName("description");
            if ($description_elements->length > 0) {
                $description_element = $description_elements->item(0);
                $src_description = $description_elements->item(0)->nodeValue;
                $feed_item->removeChild($description_element);
                $description = $src_description . PHP_EOL . $description;
            }
            $description_element = $feed_item->appendChild($this->xml->createElement("description"));
            $description_element->appendChild($this->xml->createCDATASection($description));
        }
    }

    /**
     * Get current XML as a plain text
     *
     * @return string   current XML as a plain text
     */
    public function getXMLAsText()
    {
        return $this->xml->saveXML();
    }

}

try {
    $opts = array(
        'http' => array(
            'user_agent' => 'Mozilla/5.0 (Windows NT 6.1; rv:21.0) Gecko/20130401 Firefox/21.0',
        )
    );
    $outer_encoding = mb_internal_encoding();
    if (!mb_internal_encoding("UTF-8")) {
        throw new Exception("Cannot set encoding UTF-8 for multibyte strings", 500);
    }
    $context = stream_context_create($opts);
    libxml_set_streams_context($context);

    $allowed_parameters = array(
        'url',
        'amp',
        'add_namespace',
        'remove',
        'break',
        'split',
        'cdata',
        'add_category',
        'replace_description',
        'extend_description',
        array('rename_from', 'rename_to'),
        array('replace_from', 'replace_to', 'replace_in'),
        'replace_sens',
        'include',
        'exclude'
    );

    $downloading_parameters = array(
        'add_category',
        'replace_description',
        'extend_description',
    );

    if (!isset($_GET['url'])) {
        throw new InvalidArgumentException("No url of RSS for processing", 400);
    }
    $url = $_GET['url'];
    $passed_params = array_keys($_GET);
    $matched_params = preg_grep('/index[._]php$/u', $passed_params);
    if (!empty($matched_params)) {
        unset($_GET[$matched_params[0]]);
    }
    $all_allowed_parameters = chainArrays($allowed_parameters);
    $unregistered_parameters = array_diff(array_keys($_GET), $all_allowed_parameters);
    if (!empty($unregistered_parameters)) {
        throw new Exception("Unknown parameters: " . implode(', ', $unregistered_parameters)
                            . ". Supported parameters: " . implode(', ', $all_allowed_parameters), 400);
    }
    if (!(isset($_GET['amp']) ||
            isset($_GET['add_namespace']) ||
            isset($_GET['remove']) ||
            isset($_GET['break']) ||
            isset($_GET['split']) ||
            isset($_GET['cdata']) ||
            isset($_GET['add_category']) ||
            isset($_GET['replace_description']) ||
            isset($_GET['extend_description'])) &&
        ((isset($_GET['rename_from']) || isset($_GET['rename_to'])) &&
            (!isset($_GET['rename_from']) || !isset($_GET['rename_to'])) ||
            (isset($_GET['replace_from']) || isset($_GET['replace_to']) || isset($_GET['replace_in'])) &&
            (!isset($_GET['replace_from']) || !isset($_GET['replace_to']) || !isset($_GET['replace_in'])))
    ) {
        throw new InvalidArgumentException("Incomplete parameters (must be rename_from and rename_to OR remove " .
                                           "OR replace_description OR extend_description OR break OR cdata " .
                                           "OR replace_from and replace_to and replace_in OR addCategory");
    }

    $feed = new RSSEditor($url, $context,
                          isset($_GET['amp']), true, @$_GET['add_namespace'],
                          USE_WEBPAGE_CACHING && count(array_intersect(array_keys($_GET), $downloading_parameters)) > 1);

    if (isset($_GET['remove'])) {
        $feed->removeTags($_GET['remove']);
    }

    if (isset($_GET['rename_from']) && isset($_GET['rename_to'])) {
        $feed->renameTags($_GET['rename_from'], $_GET['rename_to']);
    }
    if (isset($_GET['split'])) {
        $feed->splitCamelCase($_GET['split']);
    }

    if (isset($_GET['replace_description'])) {
        $feed->replaceDescription($_GET['replace_description']);
    }

    if (isset($_GET['extend_description'])) {
        $feed->extendDescription($_GET['extend_description']);
    }

    if (isset($_GET['break'])) {
        $feed->insertBreaksIntoTags($_GET['break']);
    }

    if (isset($_GET['cdata'])) {
        $feed->cdataTags($_GET['cdata']);
    }

    if (isset($_GET['replace_from']) && isset($_GET['replace_to']) && isset($_GET['replace_in'])) {
        $feed->replaceContent($_GET['replace_from'], $_GET['replace_to'],
                              $_GET['replace_in'], isset($_GET['replace_sens']));
    }

    if (isset($_GET['add_category'])) {
        $feed->addCategory($_GET['add_category']);
    }

    if (isset($_GET['include']) || isset($_GET['exclude'])) {
        $include = isset($_GET['include']) ? $_GET['include'] : array();
        $exclude= isset($_GET['exclude']) ? $_GET['exclude'] : array();
        $feed->filter($include, $exclude);
    }

    echo $feed->getXMLAsText();
    mb_internal_encoding($outer_encoding);
} catch (Exception $exc) {
    header("HTTP/1.1 {$exc->getCode()} {$exc->getMessage()}");
    header("Content-type: text/plain; charset=UTF-8");
    error_log($exc);
    die($exc->getMessage());
}
