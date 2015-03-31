<?php
/**
 * ownCloud - galleryplus
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Olivier Paroz <owncloud@interfasys.ch>
 *
 * @copyright Olivier Paroz 2014-2015
 */

namespace OCA\GalleryPlus\Service;

use OCP\Files\Folder;
use OCP\Files\File;
use OCP\Files\Node;

/**
 * Contains various methods which provide initial information about the
 * supported media types, the folder permissions and the images contained in
 * the system
 *
 * @package OCA\GalleryPlus\Service
 */
class FilesService extends Service {

	/**
	 * @type null|array<string,string|int>
	 */
	private $images = [];
	/**
	 * @type string[]
	 */
	private $supportedMediaTypes;

	/**
	 * This returns the list of all media files which can be shown starting from the given folder
	 *
	 * @param Folder $folder
	 * @param string[] $supportedMediaTypes
	 *
	 * @return array<string,string|int> all the images we could find
	 */
	public function getMediaFiles($folder, $supportedMediaTypes) {
		$this->supportedMediaTypes = $supportedMediaTypes;

		$this->searchFolder($folder);

		return $this->images;
	}

	/**
	 * Look for media files and folders in the given folder
	 *
	 * @param Folder $folder
	 * @param int $subDepth
	 *
	 * @return int
	 */
	private function searchFolder($folder, $subDepth = 0) {
		$albumImageCounter = 0;
		$subFolders = [];

		$nodes = $this->getNodes($folder, $subDepth);
		foreach ($nodes as $node) {
			//$this->logger->debug("Sub-Node path : {path}", ['path' => $node->getPath()]);
			$nodeType = $this->getNodeType($node);
			$subFolders = array_merge($subFolders, $this->allowedSubFolder($node, $nodeType));

			if ($nodeType === 'file') {
				$albumImageCounter = $albumImageCounter + (int)$this->isPreviewAvailable($node);
				if ($this->haveEnoughPictures($albumImageCounter, $subDepth)) {
					break;
				}
			}
		}
		$this->searchSubFolders($subFolders, $subDepth, $albumImageCounter);

		return $albumImageCounter;
	}

	/**
	 * Retrieves all files and sub-folders contained in a folder
	 *
	 * If we can't find anything in the current folder, we throw an exception as there is no point
	 * in doing any more work, but if we're looking at a sub-folder, we return an empty array so
	 * that it can be simply ignored
	 *
	 * @param Folder $folder
	 * @param int $subDepth
	 *
	 * @return array
	 *
	 * @throws NotFoundServiceException
	 */
	private function getNodes($folder, $subDepth) {
		$nodes = [];
		try {
			if ($folder->isReadable()
				&& $folder->getStorage()
						  ->isLocal()
			) {
				$nodes = $folder->getDirectoryListing();
			}
		} catch (\Exception $exception) {
			$nodes = $this->recoverFromGetNodesError($subDepth, $exception);
		}

		return $nodes;
	}

	/**
	 * Throws an exception if this problem occurs in the current folder, otherwise just ignores the
	 * sub-folder
	 *
	 * @param int $subDepth
	 * @param \Exception $exception
	 *
	 * @return array
	 * @throws NotFoundServiceException
	 */
	private function recoverFromGetNodesError($subDepth, $exception) {
		if ($subDepth === 0) {
			$this->logAndThrowNotFound($exception->getMessage());
		}

		return [];
	}

	/**
	 * Returns the node type, either 'dir' or 'file'
	 *
	 * If there is a problem, we return an empty string so that the node can be ignored
	 *
	 * @param Node $node
	 *
	 * @return string
	 */
	private function getNodeType($node) {
		try {
			$nodeType = $node->getType();
		} catch (\Exception $exception) {
			return '';
		}

		return $nodeType;
	}

	/**
	 * Returns the node if it's a folder we have access to
	 *
	 * @param Folder $node
	 * @param string $nodeType
	 *
	 * @return array|Folder
	 */
	private function allowedSubFolder($node, $nodeType) {
		if ($nodeType === 'dir') {
			/** @type Folder $node */
			if (!$node->nodeExists('.nomedia')) {
				return [$node];
			}
		}

		return [];
	}

	/**
	 * Checks if we've collected enough pictures to be able to build the view
	 *
	 * An album is full when we find max 4 pictures at the same level
	 *
	 * @param int $albumImageCounter
	 * @param int $subDepth
	 *
	 * @return bool
	 */
	private function haveEnoughPictures($albumImageCounter, $subDepth) {
		if ($subDepth === 0) {
			return false;
		}
		if ($albumImageCounter === 4) {
			return true;
		}

		return false;
	}

	/**
	 * Looks for pictures in sub-folders
	 *
	 * If we're at level 0, we need to look for pictures in sub-folders no matter what
	 * If we're at deeper levels, we only need to go further if we haven't managed to find one
	 * picture in the current folder
	 *
	 * @param array <Folder> $subFolders
	 * @param int $subDepth
	 * @param int $albumImageCounter
	 */
	private function searchSubFolders($subFolders, $subDepth, $albumImageCounter) {
		if ($this->folderNeedsToBeSearched($subFolders, $subDepth, $albumImageCounter)) {
			$subDepth++;
			foreach ($subFolders as $subFolder) {
				$count = $this->searchFolder($subFolder, $subDepth);
				if ($this->abortSearch($subDepth, $count)) {
					break;
				}
			}
		}
	}

	/**
	 * Checks if we need to look for media files in the specified folder
	 *
	 * @param array <Folder> $subFolders
	 * @param int $subDepth
	 * @param int $albumImageCounter
	 *
	 * @return bool
	 */
	private function folderNeedsToBeSearched($subFolders, $subDepth, $albumImageCounter) {
		if (!empty($subFolders) && ($subDepth === 0 || $albumImageCounter === 0)) {
			return true;
		}

		return false;
	}

	/**
	 * Returns true if there is no need to check any other sub-folder at the same depth level
	 *
	 * @param int $subDepth
	 * @param int $count
	 *
	 * @return bool
	 */
	private function abortSearch($subDepth, $count) {
		if ($subDepth > 1 && $count > 0) {
			return true;
		}

		return false;
	}

	/**
	 * Returns true if the file is of a supported media type and adds it to the array of items to
	 * return
	 *
	 * @todo We could potentially check if the file is readable ($file->stat() maybe) in order to
	 *     only return valid files, but this may slow down operations
	 *
	 * @param File $file the file to test
	 *
	 * @return bool
	 */
	private function isPreviewAvailable($file) {
		try {
			$mimeType = $file->getMimetype();
			$isLocal = $file->getStorage()
							->isLocal();
			if ($isLocal && in_array($mimeType, $this->supportedMediaTypes)) {
				$this->addFileToResults($file);

				return true;
			}
		} catch (\Exception $exception) {
			return false;
		}

		return false;
	}

	/**
	 * Adds various information about a file to the list of results
	 *
	 * @param File $file
	 */
	private function addFileToResults($file) {
		$imagePath = $this->environment->getPathFromVirtualRoot($file);
		$imageId = $file->getId();
		$mimeType = $file->getMimetype();
		$mTime = $file->getMTime();

		$imageData = [
			'path'     => $imagePath,
			'fileid'   => $imageId,
			'mimetype' => $mimeType,
			'mtime'    => $mTime
		];

		$this->images[] = $imageData;

		//$this->logger->debug("Image path : {path}", ['path' => $imagePath]);
	}

}