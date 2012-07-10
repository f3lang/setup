<?php
namespace TYPO3\Setup\Core;

/*                                                                        *
 * This script belongs to the FLOW3 package "TYPO3.Setup".                *
 *                                                                        *
 * It is free software; you can redistribute it and/or modify it under    *
 * the terms of the GNU Lesser General Public License, either version 3   *
 * of the License, or (at your option) any later version.                 *
 *                                                                        *
 * The TYPO3 project - inspiring people to share!                         *
 *                                                                        */

use TYPO3\FLOW3\Annotations as FLOW3;
use TYPO3\FLOW3\Http\Request;
use TYPO3\FLOW3\Http\Response;

/**
 * A request handler which can handle HTTP requests.
 *
 * @FLOW3\Scope("singleton")
 */
class RequestHandler extends \TYPO3\FLOW3\Http\RequestHandler {

	/**
	 * @var \TYPO3\FLOW3\Http\Response
	 */
	protected $response;

	/**
	 * This request handler can handle any web request.
	 *
	 * @return boolean If the request is a web request, TRUE otherwise FALSE
	 */
	public function canHandleRequest() {
		return (PHP_SAPI !== 'cli' && ((strlen($_SERVER['REQUEST_URI']) === 6 && $_SERVER['REQUEST_URI'] === '/setup') || in_array(substr($_SERVER['REQUEST_URI'], 0, 7), array('/setup/', '/setup?'))));
	}

	/**
	 * Returns the priority - how eager the handler is to actually handle the
	 * request.
	 *
	 * @return integer The priority of the request handler.
	 */
	public function getPriority() {
		return 200;
	}

	/**
	 * Handles a HTTP request
	 *
	 * @return void
	 */
	public function handleRequest() {
			// Create the request very early so the Resource Management has a chance to grab it:
		$this->request = Request::createFromEnvironment();
		$this->response = new Response();

		$this->checkBasicRequirementsAndDisplayLoadingScreen();

		$this->boot();
		$this->resolveDependencies();
		$this->request->injectSettings($this->settings);

		$packageManager = $this->bootstrap->getEarlyInstance('TYPO3\FLOW3\Package\PackageManagerInterface');
		$configurationSource = $this->bootstrap->getObjectManager()->get('TYPO3\FLOW3\Configuration\Source\YamlSource');

		$this->router->setRoutesConfiguration($configurationSource->load($packageManager->getPackage('TYPO3.Setup')->getConfigurationPath() . \TYPO3\FLOW3\Configuration\ConfigurationManager::CONFIGURATION_TYPE_ROUTES));
		$actionRequest = $this->router->route($this->request);

		$this->securityContext->injectRequest($actionRequest);

		$this->dispatcher->dispatch($actionRequest, $this->response);

		$this->response->makeStandardsCompliant($this->request);
		$this->response->send();

		$this->bootstrap->shutdown('Runtime');
		$this->exit->__invoke();
	}

	/**
	 * Check the basic requirements, and display a loading screen on initial request.
	 *
	 * @return void
	 */
	protected function checkBasicRequirementsAndDisplayLoadingScreen() {
		$messageRenderer = new MessageRenderer($this->bootstrap);
		$basicRequirements = new BasicRequirements();
		$result = $basicRequirements->findError();
		if ($result instanceof \TYPO3\FLOW3\Error\Error) {
			$messageRenderer->showMessage($result);
		}

		$result = $this->checkAndSetPhpBinaryIfNeeded();
		if ($result instanceof \TYPO3\FLOW3\Error\Error) {
			$messageRenderer->showMessage($result);
		}

		$currentUri = substr($this->request->getUri(), strlen($this->request->getBaseUri()));
		if ($currentUri === 'setup' || $currentUri === 'setup/') {
			$redirectUri = ($currentUri === 'setup/' ? 'index': 'setup/index');
			$messageRenderer->showMessage(new \TYPO3\FLOW3\Error\Message('We are now redirecting you to the setup. <b>This might take 10-60 seconds on the first run,</b> as FLOW3 needs to build up various caches.', 0, array(), 'Your environment is suited for installing FLOW3!'), '<meta http-equiv="refresh" content="2;URL=\'' . $redirectUri . '\'">');
		}
	}

	/**
	 * Checks if the configured PHP binary is executable and the same version as the one
	 * running the current (web server) PHP process. If not or if there is no binary configured,
	 * tries to find the correct one on the PATH.
	 *
	 * Once found, the binary will be written to the configuration, if it is not the default one
	 * (PHP_BINARY or in PHP_BINDIR).
	 *
	 * @return boolean|\TYPO3\FLOW3\Error\Error TRUE on success, otherwise an instance of \TYPO3\FLOW3\Error\Error
	 */
	protected function checkAndSetPhpBinaryIfNeeded() {
		$configurationSource = new \TYPO3\FLOW3\Configuration\Source\YamlSource();
		$distributionSettings = $configurationSource->load(FLOW3_PATH_CONFIGURATION . \TYPO3\FLOW3\Configuration\ConfigurationManager::CONFIGURATION_TYPE_SETTINGS);
		if (isset($distributionSettings['TYPO3']['FLOW3']['core']['phpBinaryPathAndFilename'])) {
			if ($this->checkPhpBinary($distributionSettings['TYPO3']['FLOW3']['core']['phpBinaryPathAndFilename']) === TRUE) {
				return TRUE;
			} else {
				return new \TYPO3\FLOW3\Error\Error('The specified path to your php binary (see Configuration/Settings.yaml) does not point to the same version as is currently running.', 1341839377);
			}
		}
		$phpBinaryPathAndFilename = $this->detectPhpBinaryPathAndFilename();
		if ($phpBinaryPathAndFilename !== NULL) {
			$defaultPhpBinaryPathAndFilename = PHP_BINDIR . '/php';
			if (DIRECTORY_SEPARATOR !== '/') {
				$defaultPhpBinaryPathAndFilename = str_replace('\\', '/', $defaultPhpBinaryPathAndFilename) . '.exe';
			}
			if ($phpBinaryPathAndFilename !== $defaultPhpBinaryPathAndFilename) {
				$distributionSettings = \TYPO3\FLOW3\Utility\Arrays::setValueByPath($distributionSettings, 'TYPO3.FLOW3.core.phpBinaryPathAndFilename', $phpBinaryPathAndFilename);
				$configurationSource->save(FLOW3_PATH_CONFIGURATION . \TYPO3\FLOW3\Configuration\ConfigurationManager::CONFIGURATION_TYPE_SETTINGS, $distributionSettings);
			}
			return TRUE;
		} else {
			return new \TYPO3\FLOW3\Error\Error('The path to your php binary could not be detected. Please set it manually in Configuration/Settings.yaml.', 1341499159);
		}
	}

	/**
	 * Checks if the given PHP binary is executable and of the same version as the currently running one.
	 *
	 * @param string $phpBinaryPathAndFilename
	 * @return boolean
	 */
	protected function checkPhpBinary($phpBinaryPathAndFilename) {
		if (file_exists($phpBinaryPathAndFilename) && is_file($phpBinaryPathAndFilename)) {
			$phpVersion = trim(exec(escapeshellcmd($phpBinaryPathAndFilename) . ' -r "echo PHP_VERSION;"'));
			if ($phpVersion === PHP_VERSION) {
				return TRUE;
			}
		}
		return FALSE;
	}

	/**
	 * Traverse the PATH locations and check for the existence of a valid PHP binary.
	 * If found, the path and filename are returned, if not NULL is returned.
	 *
	 * @return string
	 */
	protected function detectPhpBinaryPathAndFilename() {
		if (defined('PHP_BINARY')) {
			return PHP_BINARY;
		}
		$environmentPaths = explode(PATH_SEPARATOR, getenv('PATH'));
		foreach ($environmentPaths as $path) {
			$path = rtrim(str_replace('\\', '/', $path), '/');
			if (strlen($path) === 0) {
				continue;
			}
			$phpBinaryPathAndFilename = $path . '/php' . (DIRECTORY_SEPARATOR !== '/' ? '.exe' : '');
			if ($this->checkPhpBinary($phpBinaryPathAndFilename)) {
				return $phpBinaryPathAndFilename;
			}
		}
		return NULL;
	}
}
?>