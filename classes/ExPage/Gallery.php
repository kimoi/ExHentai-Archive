<?php

class ExPage_Gallery extends ExPage_Abstract {

	public function getName() {
		return $this->find('h1#gn')->text();
	}

	public function getOriginalName() {
		return $this->find('h1#gj')->text();
	}

	public function getType() {
		return $this->find('div#gdc img')->attr('alt');
	}

	public function getTags() {
		$ret = array();

		$tagRows = $this->find('#taglist tr');
		foreach($tagRows as $i => $tagRowElem) {
			$tagRow = $tagRows->eq($i);

			$tagNamespace = $tagRow->find('td:first-child')->text();
			$tagNamespace = trim($tagNamespace, ':');

			$tags = array();
			$tagLinks = $tagRow->find('a');
			foreach($tagLinks as $x => $tagLinkElem) {
				$tagLink = $tagLinks->eq($x);
				$tags[] = $tagLink->text();
			}

			$ret[$tagNamespace] = $tags;
		}

		return $ret;
	}

	public function getProperties() {
		$ret = array();

		$attrs = $this->find('td.gdt1');
		foreach($attrs as $i => $attrElem) {
			$attr = $attrs->eq($i);
			$propName = trim($attr->text(), ':');
			$propValue = $attr->next()->text();

			$ret[$propName] = $propValue;
		}

		return $ret;
	}

	public function getArchiverUrl() {
		$elem = $this->find('a[onclick*="archiver.php"]');
		if(count($elem) > 0) {
			preg_match("~(http://exhentai.org/archiver.php.*)'~", $elem->attr('onclick'), $matches);
			if(count($matches) >= 2) {
				return $matches[1];
			}
		}

		return false;
	}

}

?>