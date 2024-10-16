<?php

namespace Glossary;

class PageHandler {

  protected $data;

  protected $otherMatearials;

  protected $isMainDomain;

  static public function init($glossaryCatalogs) {
    $a = \Cetera\Application::getInstance();
    $currentCatalogUrl = $a->getCatalog()->getUrl();
    for($i = 0; $i < count($glossaryCatalogs); $i++) {
      if($currentCatalogUrl === $glossaryCatalogs[$i]->getUrl())
        return;
    }
    $a->registerOutputHandler(new self());
  }

  public function __invoke(&$res) {
    $newHtml = $res;
    $htmlWithoutNoIndexContent = $this->replaceNoIndexContent($newHtml);

	$url = strtok($_SERVER["REQUEST_URI"], '?');
	$hashedUrl = hash('murmur3f', $url);
	$slot = new \Cetera\Cache\Slot\User(str_replace(["?", "=", "+", "%", "&", ")", "(", "$", "@", "!", "~", "[", "]", "\\"],"_",$hashedUrl));
	$cachedTerms = strpos(strtok('?'), 'query') === 0 ? false : $slot->load();
	
    if ($cachedTerms === false) {
		$this->data = $this->getContainsTermsData($htmlWithoutNoIndexContent, $url);
		$slot->save($this->data);
    } else {
		$this->data = $cachedTerms;
	}

	$a = \Cetera\Application::getInstance();
	$a->getServer()['id'] === 1 ? $this->isMainDomain = true : $this->isMainDomain = false;

    for($i = 0; $i < count($this->data); $i++) {
      $term = $this->data[$i];
      $findTerm = $this->findTermPos($htmlWithoutNoIndexContent, $newHtml, $term);
      if($findTerm !== false) {
        $wrappedTerm = $this->wrapTerm($findTerm['term'], $term['url']);
        $newHtml = substr_replace($newHtml, $wrappedTerm, $findTerm['start'], $findTerm['length']);
        $htmlWithoutNoIndexContent = substr_replace($htmlWithoutNoIndexContent, str_repeat('|', strlen($wrappedTerm)), $findTerm['start'], $findTerm['length']);
      } 
    }

    $res = $newHtml;
  }

  protected function replaceNoIndexContent($html) {
    $noIndexTags = 
    '<header.*?>.*?</header>|<footer.*?>.*?</footer>|<abbr.*?>.*?</abbr>|<a .*?>.*?</a>|<form.*?>.*?</form>|<script.*?>.*?</script>|<style.*?>.*?</style>|<title.*?>.*?</title>|<h1.*?>.*?</h1>|<!--.*?-->|<button.*?>.*?</button>|<head.*?>.*?</head>|<iframe.*?>.*?</iframe>|<embed.*?>.*?</embed>|<object.*?>.*?</object>|<audio.*?>.*?</audio>|<video.*?>.*?</video>|<source.*?>.*?</source>|<pre.*?>.*?</pre>|<nav.*?>.*?</nav>|<svg.*?>.*?</svg>|<code.*?>.*?</code>|<cite.*?>.*?</cite>|<canvas.*?>.*?</canvas>|<noscript.*?>.*?</noscript>|<option.*?>.*?</option>';
    $withoutNoIndexTags = mb_ereg_replace_callback($noIndexTags, fn($match) => str_repeat('|', strlen($match[0])), $html);
    $onlyText = mb_ereg_replace_callback("<.*?>", fn($match) => str_repeat('|', strlen($match[0])), $withoutNoIndexTags); 

    return $onlyText;
  }

  protected function getContainsTermsData($html, $url) {
    $a = \Cetera\Application::getInstance();
    $s = $a->getServer();

    try {
      $othersMaterialsCatalog = $s->getChildByAlias('for_glossary');
      $this->getOtherMaterials( $othersMaterialsCatalog );
    } catch (\Cetera\Exception\CMS $e) {
      $this->otherMatearials = [];
    }

    $typeIdGlossary = \Cetera\ObjectDefinition::findByAlias('glossary')->getId();
    $glossaryMaterials = \Cetera\ObjectDefinition::findById($typeIdGlossary)->getMaterials();
    
	  $typeIdCatalog = \Cetera\ObjectDefinition::findByAlias('sale_products')->getId();
    $catalogMaterials = \Cetera\ObjectDefinition::findById($typeIdCatalog)->getMaterials();

    $othersMaterials = $this->otherMatearials;
    
	  $allMaterials = [...$catalogMaterials, ...$othersMaterials, ...$glossaryMaterials];
    $termsAndSynonyms = $this->createDataForReferences($allMaterials);
	  $html = str_replace("|", "", $html);
	  $lowerHtml = mb_strtolower($html);
	  str_ends_with($url, '/') ? $url = $url : $url = $url."/";
    $terms = array_reduce($termsAndSynonyms, function($result, $termData) use ($html, $lowerHtml, $url) {
		str_ends_with($termData['url'], 'index') ? $termData['url'] = substr($termData['url'], 0, -5) : $termData['url'] = $termData['url']."/";
		if ($url == $termData['url']) {
			return $result;
		}
		$finded = str_contains($lowerHtml, mb_strtolower($termData['term']));
		$finded ? $offset = preg_match($this->termFindRegExp($termData['term']), $html) : $offset = false;
		if(isset($offset) && $offset === 1) {
			$termData['isFinded'] = false;
			$result[] = $termData;
		}
		return $result;
    },[]);
    
    return $this->getOtherTermsContainsTerm($terms);
  }

  protected function getOtherMaterials($c) {
      foreach($c->getMaterials() as $m) {
        $this->otherMatearials[] = $m;
      }
      
      foreach ($c->getChildren() as $_c) if (!$_c->isHidden()) {
          $this->getOtherMaterials($_c);
      }    
  }

  protected function createDataForReferences($materials) {
    $data = [];
    for($i = 0; $i < count($materials); $i++) {
      $term = $materials[$i];
      $termsAndSynonyms = empty($term['synonyms']) ? [$term['name']] : [$term['name'], ...mb_split(", ?", $term['synonyms'])];
      $termsAndSynonymsData = array_map(fn($termName) => 
        ['term' => $termName, 'url' => $term->getUrl()], $termsAndSynonyms);
      $data = [...$data, ...$termsAndSynonymsData];
    }
    return $data;
  }

  protected function getOtherTermsContainsTerm($terms) {
    $newData = array_reduce($terms, function($result, $termData) use ($terms) {
      $references = [];
      foreach($terms as $termData2) {
        if($termData['url'] !== $termData2['url'] && preg_match($this->termFindRegExp($termData['term']), $termData2['term']) === 1) {
          $references[] = $termData2['term'];
        }
      }
      $termData['containsTerms'] = $references;
      $result[] = $termData;
      return $result;
    }, []);
    return $newData;
  }

  protected function findTermPos($htmlWithoutNoIndexContent, $newHtml, $term) {
    if($term['isFinded'] === false) {
      if(count($term['containsTerms']) !== 0) {
        foreach($term['containsTerms'] as $containingTerm) {
          $htmlWithoutNoIndexContent = mb_ereg_replace_callback($containingTerm, fn($match) => str_repeat('|', strlen($match[0])), $htmlWithoutNoIndexContent); 
        }
      }
      $regExp = $this->termFindRegExp($term['term']);
      $isHaveTerm = preg_match($regExp, $htmlWithoutNoIndexContent, $matches, PREG_OFFSET_CAPTURE);
    }

    if(isset($isHaveTerm) && $isHaveTerm === 1) {
      $this->termFinded($term);
      return ['start' => $matches[0][1] + 1, 'length' => strlen($term['term']), 'term' => mb_substr($matches[0][0], 1, mb_strlen($term['term']))];
    }

    return false;
  }

  public static function termFindRegExp($term) {
    return '/([^a-zа-яА-ЯЁё\.-]' . $term . '$|^' . $term . '[^a-zа-яА-ЯЁё\.-]|[^a-zа-яА-ЯЁё\.-]'. $term . '[^a-zа-яА-ЯЁё-])/ui';
  }

  protected function termFinded($term) {
    $data = $this->data;

    $updateData = array_map(function($termData) use ($term) {
      if($term['url'] === $termData['url'])
        $termData['isFinded'] = true;
      return $termData;
    }, $data);
    $this->data = $updateData;
  }

  protected function wrapTerm($text, $link) {
	$this->isMainDomain ?: $link = 'https://cetera.ru'.$link;
    return "<a href='$link' title='Определение термина &#171;$text&#187;'>$text</a>";
  }
}