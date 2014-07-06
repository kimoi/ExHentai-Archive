<?php

use PHPImageWorkshop\ImageWorkshop;

class ApiHandler {

	protected $params;
	protected $start;

	protected function galleriesAction() {
		$data = array();

		$page = $this->getParam('page', 0);
		$pagesize = $this->getParam('pagesize', 24);
		$search = $this->getParam('search');
		$order = $this->getParam('order', 'posted');
		$seed = $this->getParam('seed', 0);

		$seed = (int)base_convert($seed, 36, 10);

		$search = trim($search);

		$ret = Model_Gallery::search($page, $pagesize, $search, $order, $seed);
		$result = $ret['result'];
		$data['meta'] = $ret['meta'];
		$data['galleries'] = array();

		$data['end'] = false;
		if(($page + 1) * $pagesize >= $ret['meta']->total) {
			$data['end'] = true;
		}

		$config = Config::get();

		$memcache = new Memcache();
		$cacheConnected = $memcache->addServer($config->memcache->host, $config->memcache->port);

		foreach($result as $row) {
			$galleryId = $row['id'];

			$export = null;

			if($cacheConnected) {
				$export = $memcache->get('gallery'.$galleryId);
				if($export) {
					$export['fromcache'] = true;
				}
			}
			
			if(!$export) {
				$gallery = R::load('gallery', $galleryId);
				$export = $gallery->export();

				$thumb = $gallery->getThumbnail(0, Model_Gallery::THUMB_LARGE, false); //don't generate a thumb now
				if($thumb) {
					$export['thumb'] = array(
						'url' => $thumb->getUrl(),
						'landscape' => ($thumb->width > $thumb->height)
					);
				}

				$tagLinks = $gallery->ownGalleryTag;

				R::preload($tagLinks, array('namespace' => 'tagnamespace'));

				$export['tags'] = $this->exportTags($gallery);

				$export['posted_formatted'] = date('d/m/Y', strtotime($gallery->posted));

				$export['fromcache'] = false;

				if($cacheConnected && $thumb) {
					$ret = $memcache->set('gallery'.$gallery->id, $export, MEMCACHE_COMPRESSED, 0);
				}
			}

			if(array_key_exists('ranked_weight', $row)) {
				$export['ranked_weight'] = $row['ranked_weight'];
			}

			$data['galleries'][] = $export;
		}

		if($cacheConnected) {
			$memcache->close();
		}

		$this->sendSuccess($data);
	}

	protected function galleryAction() {
		$id = $this->getParam('id');

		if($id) {
			$gallery = R::load('gallery', $id);
			if(!$gallery) {
				$this->sendFail('Invalid gallery');
			}

			$data = $gallery->export();

			$data['tags'] = $this->exportTags($gallery);

			$this->sendSuccess($data);
		}
		else {
			$this->sendFail('Invalid params');
		}
	}

	protected function exportTags($gallery) {
		$tagLinks = $gallery->ownGalleryTag;

		R::preload($tagLinks, array('namespace' => 'tagnamespace'));

		$export = array();
		foreach($tagLinks as $link) {
			if(!array_key_exists($link->namespace->name, $export)) {
				$export[$link->namespace->name] = array();
			}

			$export[$link->namespace->name][] = $link->tag->name;
		}

		return $export;
	}

	protected function archiveimageAction() {
		list($id, $index) = $this->getParams('id', 'index');
		$resize = $this->getParam('resize', false);

		if($id && $index >= 0) {
			$gallery = R::load('gallery', $id);
			if($gallery) {
				$imagePath = $gallery->getImageFilepath($index);
				if(file_exists($imagePath)) {
					header('Last-Modified: '.gmdate('D, d M Y H:i:s', filemtime($imagePath)).' GMT');
					header('Expires: '.gmdate('D, d M Y H:i:s', strtotime('+1 year')).' GMT');
					header('Cache-Control: public');

					if($resize && is_numeric($resize) && (int)$resize > 0) {
						header('Content-Type: image/jpeg');

						$layer = ImageWorkshop::initFromPath($imagePath);
						$layer->resizeInPixel($resize, null, true);
						$image = $layer->getResult();

						imagejpeg($image, null, 80);
					}
					else {
						$ext = pathinfo($imagePath, PATHINFO_EXTENSION);

						switch($ext) {
							case 'jpg':
							case 'jpeg':
								header('Content-Type: image/jpeg');
								break;
							case 'png':
								header('Content-Type: image/png');
								break;
						}
						
						readfile($imagePath);
					}


				}
				else {
					http_response_code(404);
				}
			}
			else {
				http_response_code(404);
			}
		}
		else {
			http_response_code(400);
		}

		exit;
	}

	protected function gallerythumbAction() {
		list($id, $index, $type) = $this->getParams('id', 'index', 'type');

		if($id && $index !== null && in_array($type, array(Model_Gallery::THUMB_LARGE, Model_Gallery::THUMB_SMALL))) {
			$gallery = R::load('gallery', $id);
			if($gallery) {
				$thumb = $gallery->getThumbnail($index, $type, true);
				if($thumb) {
					header('Location: '.$thumb->getUrl());
				}
				else {
					http_response_code(404);
				}
			}
			else {
				http_response_code(404);
			}
		}
		else {
			http_response_code(400);
		}

		exit;
	}

	protected function addgalleryAction() {
		list($gid, $hash) = $this->getParams('gid', 'token');

		if($gid && $hash) {
			$gallery = R::findOne('gallery', 'exhenid = ?', array($gid));
			if(!$gallery) {
				$gallery = R::dispense('gallery');
				$gallery->exhenid = $gid;
				$gallery->hash = $hash;
				R::store($gallery);
			}
			elseif($gallery->feed) {
				$gallery->feed = null;
				R::store($gallery);
			}

			$this->sendSuccess($gallery->export());
		}
		else {
			$this->sendFail('Invalid params');
		}
	}

	public function hasgalleryAction() {
		$gid = $this->getParam('gid');
		if($gid) {
			$gallery = R::findOne('gallery', 'feed_id is null and exhenid = ?', array($gid));
			if($gallery) {
				$this->sendSuccess(array('exists' => true, 'id' => $gallery->id, 'archived' => !!$gallery->archived));
			}
			else {
				$this->sendSuccess(array('exists' => false));
			}
		}
		else {
			$this->sendFail('Invalid params');
		}
	}

	public function hasgalleriesAction() {
		$gids = $this->getParam('gids');
		if($gids && is_array($gids)) {
			$result = R::getAll('select exhenid, archived from gallery where feed_id is null and exhenid in ('.R::genSlots($gids).')', $gids);

			$this->sendSuccess($result);
		}
		else {
			$this->sendFail('Invalid params');
		}
	}

	public function deletegalleryAction() {
		list($id, $key) = $this->getParams('id', 'key');

		if($key != Config::get()->accessKey) {
			$this->sendFail('Invalid access key');
		}
		else {
			$gallery = R::load('gallery', $id);
			if(!$gallery || $gallery->deleted) {
				$this->sendFail('Gallery not found or already deleted');
			}
			else {
				$gallery->deleted = true;
				R::store($gallery);
				
				$this->sendSuccess(true);
			}
		}
	}

	public function downloadAction()
	{
		$id = $this->getParam('id');
		if($id) {
			$gallery = R::load('gallery', $id);
			if($gallery) {
				$archive = $gallery->getArchiveFilepath();
				if(file_exists($archive)) {
					header('Content-Type: application/zip');
					header('Content-Disposition: attachment; filename="'.$gallery->name.'.zip"');
					header('Content-Length: '.filesize($archive));
					
					readfile($archive);
					exit;
				}
				else {
					http_response_code(404);
				}
			}
			else {
				http_response_code(404);
			}
		}
		else {
			http_response_code(400);
		}
	}

	public function suggestedAction() {
		$term = $this->getParam('term');
		$result = Suggested::search($term);
		$this->sendSuccess($result);
	}

	public function flushAction() {
		$config = Config::get();

		$memcache = new Memcache();
		$cacheConnected = $memcache->addServer($config->memcache->host, $config->memcache->port);
		if($cacheConnected) {
			$ret = $memcache->flush();
			$memcache->close();

			if($ret) {
				$this->sendSuccess(true);
			}
			else {
				$this->sendFail('flush() failed');
			}
		}
		else {
			$this->sendFail('cache not connected');
		}
	}

	public function indexerAction() {
		header('Content-Type: text/plain');

		$command = Config::get()->indexer->full;
		system($command);
		exit;
	}

	public function statsAction() {
		$stats = Model_Gallery::getStats();
		
		$this->sendSuccess($stats);
	}

	public function testAction() {
		$galleries = R::find('gallery', 'archived = 1');

		foreach($galleries as $gallery) {
			$gallery->updateSearch();
		}

		die();
	}

	protected function sendSuccess($data) {
		$data = array(
			'ret' => true,
			'data' => $data
		);

		$data['time'] = microtime(true) - $this->start;

		$this->sendResponse($data);
	}

	protected function sendFail($message) {
		$data = array(
			'ret' => false,
			'message' => $message
		);

		$this->sendResponse($data);
	}

	protected function sendResponse($resp) {
		$body = json_encode($resp);

		ob_end_clean();

		header('Access-Control-Allow-Origin: *');
		header('Content-Type: application/json');
		echo $body;
		exit;
	}

	protected function getParam($param, $default = null) {
		if(array_key_exists($param, $this->params)) {
			return $this->params[$param];
		}
		else {
			return $default;
		}
	}

	protected function getParams(/* params */) {
		$params = func_get_args();
		$values = array();

		foreach($params as $param) {
			$values[] = $this->getParam($param);
		}

		return $values;
	}

	public function handle($params) {
		if(!is_array($params) || !array_key_exists('action', $params)) {
			$this->sendFail('Invalid request');
		}

		$this->params = $params;

		$methodName = strtolower($params['action']).'Action';

		if(!method_exists($this, $methodName)) {
			$this->sendFail('Invalid action');
		}

		$this->start = microtime(true);

		$this->$methodName();

		$this->sendFail('Action fell through without sending a response');
	}

}

?>