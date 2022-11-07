<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022 Louis Chmn <louis@chmn.me>
 *
 * @author Louis Chmn <louis@chmn.me>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Files\Controller;

use OC\AppFramework\Bootstrap\Coordinator;
use OCP\AppFramework\Http\ZipResponse;
use OCP\AppFramework\Controller;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IFileDownloadProvider;
use OCP\Files\Node;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class DownloadController extends Controller {
	private Coordinator $coordinator;
	private LoggerInterface $logger;

	public function __construct(
		string $appName,
		IRequest $request,
		Coordinator $coordinator,
		LoggerInterface $logger
	) {
		parent::__construct($appName, $request);

		$this->request = $request;
		$this->coordinator = $coordinator;
		$this->logger = $logger;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 */
	public function index(string $files): ZipResponse {
		$response = new ZipResponse($this->request, 'download');

		/** @var string[] */
		$files = json_decode($files);

		if (count($files) === 0) {
			return $response;
		}

		$commonPrefix = $files[0];
		foreach ($files as $filePath) {
			$commonPrefix = $this->getCommonPrefix($filePath, $commonPrefix);
		}

		$context = $this->coordinator->getRegistrationContext();
		if ($context === null) {
			throw new \Exception("Can't get download providers");
		}
		$providerRegistrations = $context->getFileDownloadProviders();

		foreach ($files as $filePath) {
			$node = null;

			foreach ($providerRegistrations as $registration) {
				try {
					/** @var IFileDownloadProvider */
					$provider = \OCP\Server::get($registration->getService());
					$node = $provider->getNode($filePath);
					if ($node !== null) {
						break;
					}
				} catch (\Throwable $ex) {
					$providerClass = $registration->getService();
					$this->logger->warning("Error while getting file content from $providerClass", ['exception' => $ex]);
				}
			}

			if ($node === null) {
				continue;
			}

			$this->addNode($response, $node, str_replace($commonPrefix, '', $filePath));
		}

		return $response;
	}

	private function getCommonPrefix(string $str1, string $str2): string {
		$mbStr1 = mb_str_split($str1);
		$mbStr2 = mb_str_split($str2);

		for ($i = 0; $i < count($mbStr1); $i++) {
			if ($mbStr1[$i] !== $mbStr2[$i]) {
				$i--;
				break;
			}
		}

		if ($i < 0) {
			return '';
		} else {
			return join(array_slice($mbStr1, 0, $i));
		}
	}

	private function addNode(ZipResponse $response, Node $node, string $path): void {
		if ($node instanceof File) {
			$response->addResource($node->fopen('r'), $path, $node->getSize());
		}

		if ($node instanceof Folder) {
			foreach ($node->getDirectoryListing() as $subnode) {
				$this->addNode($response, $subnode, $path.'/'.$subnode->getName());
			}
		}
	}
}
